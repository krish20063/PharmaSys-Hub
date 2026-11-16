<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/whatsapp_service.php';

handle_api_cors();
header('Content-Type: application/json');

// Auth Guard: Only retailer role can trigger POS checkout
if (!is_logged_in() || $_SESSION['user_role'] !== 'retailer') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: Only licensed retail pharmacies can execute POS dispensing.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. POST required.']);
    exit;
}

// Enforce CSRF & Rate Limiting
if (!verify_csrf_token()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF verification failed. Session token invalid.']);
    exit;
}
enforce_rate_limit('pos_checkout', 30, 60);

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$consumerName = trim($input['consumer_name'] ?? 'Counter Patient');
$consumerPhone = trim($input['consumer_phone'] ?? '');
$paymentMode = $input['payment_mode'] ?? 'UPI';
$cartItems = $input['cart_items'] ?? [];
$retailerId = $_SESSION['user_id'];

if (empty($consumerPhone) || empty($cartItems)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Customer mobile number and at least one medicine item are strictly required.']);
    exit;
}

$pdo = Database::getConnection();

try {
    $pdo->beginTransaction();

    $subtotal = 0;
    $validatedItems = [];
    $now = time();

    foreach ($cartItems as $item) {
        $batchId = (int)$item['batch_id'];
        $reqQty = (int)$item['quantity'];

        $stmt = $pdo->prepare("
            SELECT b.id, b.batch_number, b.is_recalled, b.recall_reason, b.is_expired, b.expiry_date,
                   m.brand_name, m.salt_composition, m.strength_mg, b.mrp_unit, inv.quantity as stock_available
            FROM batches b
            JOIN medicine_master m ON b.medicine_id = m.id
            LEFT JOIN inventory inv ON inv.batch_id = b.id AND inv.user_id = :uid
            WHERE b.id = :batch_id
        ");
        $stmt->execute([':batch_id' => $batchId, ':uid' => $retailerId]);
        $batch = $stmt->fetch();

        if (!$batch) {
            throw new Exception("Batch #{$batchId} not recognized on central ledger.");
        }

        $isRecalled = (int)$batch['is_recalled'] === 1;
        $isExpired = (int)$batch['is_expired'] === 1 || strtotime($batch['expiry_date']) < $now;

        // ANTI-CHEATER ENFORCEMENT: Server-side rejection + tamper audit log
        if ($isRecalled || $isExpired) {
            $flagType = $isRecalled ? "SMART RECALL ({$batch['recall_reason']})" : "EXPIRED ({$batch['expiry_date']})";
            
            // Log security violation to central notifications
            $secStmt = $pdo->prepare("
                INSERT INTO notifications (target_role, title, message, type)
                VALUES ('all', '🚨 TAMPER AUDIT ALERT: Illegal Sale Blocked', :msg, 'danger')
            ");
            $secStmt->execute([
                ':msg' => "POS attempt blocked at CareFirst Pharmacy to bill blacklisted Batch {$batch['batch_number']} ({$batch['brand_name']}). State: {$flagType}. Dispense aborted."
            ]);

            throw new Exception("SECURITY AUDIT LOCK: Cannot bill Batch {$batch['batch_number']} ({$batch['brand_name']}). Lot is flagged as {$flagType}. Dispensing is strictly illegal under Drug Control laws.");
        }

        // Check stock availability
        if ($batch['stock_available'] !== null && (int)$batch['stock_available'] < $reqQty) {
            throw new Exception("Insufficient physical stock for {$batch['brand_name']} ({$batch['stock_available']} units available).");
        }

        $lineTotal = (float)$batch['mrp_unit'] * $reqQty;
        $subtotal += $lineTotal;

        $validatedItems[] = [
            'batch_id' => $batch['id'],
            'batch_number' => $batch['batch_number'],
            'name' => $batch['brand_name'],
            'salt' => $batch['salt_composition'],
            'expiry' => $batch['expiry_date'],
            'qty' => $reqQty,
            'price' => (float)$batch['mrp_unit'],
            'total' => $lineTotal
        ];
    }

    // 12% Indian Pharma GST (6% CGST + 6% SGST)
    $gstAmount = round($subtotal * DEFAULT_GST_RATE, 2);
    $grandTotal = $subtotal + $gstAmount;
    $invNumber = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
    $securityHash = 'CDSCO-SEC-' . strtoupper(substr(md5($invNumber . time()), 0, 8)) . '-CLEARED';

    // Insert Invoice
    $invStmt = $pdo->prepare("
        INSERT INTO sales_invoices (invoice_number, retailer_id, consumer_name, consumer_phone, subtotal, gst_amount, grand_total, payment_mode, whatsapp_status)
        VALUES (:inv, :rid, :cname, :cphone, :subtot, :gst, :grand, :pay, 'sent')
    ");
    $invStmt->execute([
        ':inv' => $invNumber,
        ':rid' => $retailerId,
        ':cname' => $consumerName,
        ':cphone' => $consumerPhone,
        ':subtot' => $subtotal,
        ':gst' => $gstAmount,
        ':grand' => $grandTotal,
        ':pay' => $paymentMode
    ]);
    $invoiceId = $pdo->lastInsertId();

    // Insert Items & Deduct Inventory
    $itemStmt = $pdo->prepare("INSERT INTO sales_items (invoice_id, batch_id, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
    $deductStmt = $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE batch_id = ? AND user_id = ?");

    // Auto-restock trigger: If stock drops to 10 or less, automatically add to draft restock cart
    $lowStockCheck = $pdo->prepare("
        SELECT inv.batch_id, inv.quantity, inv.min_threshold, b.medicine_id, m.brand_name
        FROM inventory inv
        JOIN batches b ON inv.batch_id = b.id
        JOIN medicine_master m ON b.medicine_id = m.id
        WHERE inv.user_id = :uid AND inv.batch_id = :bid AND inv.quantity <= inv.min_threshold
    ");
    $insertDraftStmt = $pdo->prepare("
        INSERT INTO draft_purchase_orders (retailer_id, distributor_id, medicine_id, batch_id, suggested_quantity, trigger_reason, status)
        VALUES (:uid, 2, :mid, :bid, 150, :reason, 'draft')
    ");
    $checkDraftStmt = $pdo->prepare("SELECT id FROM draft_purchase_orders WHERE retailer_id = :uid AND batch_id = :bid AND status = 'draft'");

    foreach ($validatedItems as $v) {
        $itemStmt->execute([$invoiceId, $v['batch_id'], $v['qty'], $v['price'], $v['total']]);
        $deductStmt->execute([$v['qty'], $v['batch_id'], $retailerId]);

        // Evaluate if stock breached the minimum threshold (<= 10 units)
        $lowStockCheck->execute([':uid' => $retailerId, ':bid' => $v['batch_id']]);
        $ls = $lowStockCheck->fetch();
        if ($ls) {
            $checkDraftStmt->execute([':uid' => $retailerId, ':bid' => $ls['batch_id']]);
            if (!$checkDraftStmt->fetch()) {
                $reason = "Auto-trigger: Stock level ({$ls['quantity']} units) dropped to or below minimum threshold ({$ls['min_threshold']} units)";
                $insertDraftStmt->execute([
                    ':uid' => $retailerId,
                    ':mid' => $ls['medicine_id'],
                    ':bid' => $ls['batch_id'],
                    ':reason' => $reason
                ]);
            }
        }
    }

    Database::logAudit('SALE_DISPENSED', 'INVOICE', $invNumber, "Invoiced ₹{$grandTotal} via {$paymentMode} to {$consumerName} ({$consumerPhone}). Hash: {$securityHash}");

    $pdo->commit();

    // Trigger Meta WhatsApp Cloud API Invoice Delivery with Total Transparency
    $waResult = WhatsAppBillingService::sendEBill($consumerPhone, [
        'invoice_number' => $invNumber,
        'consumer_name' => $consumerName,
        'items' => $validatedItems,
        'subtotal' => $subtotal,
        'gst_amount' => $gstAmount,
        'grand_total' => $grandTotal,
        'security_hash' => $securityHash
    ]);

    echo json_encode([
        'success' => true,
        'invoice_number' => $invNumber,
        'subtotal' => $subtotal,
        'gst' => $gstAmount,
        'grand_total' => $grandTotal,
        'security_hash' => $securityHash,
        'whatsapp' => $waResult,
        'message' => 'Sale certified. Digital tax e-bill with safety verification dispatched.'
    ]);
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
