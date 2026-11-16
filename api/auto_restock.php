<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

handle_api_cors();
header('Content-Type: application/json');

// Auth Guard: Only retailer role can access auto-restock draft cart
if (!is_logged_in() || $_SESSION['user_role'] !== 'retailer') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: Only authorized retail pharmacies can access auto-restock.']);
    exit;
}

enforce_rate_limit('auto_restock', 60, 60);

$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$retailerId = $_SESSION['user_id'];

if ($method === 'GET') {
    try {
        // Auto-detect and queue breached stock levels
        $lowStockQuery = $pdo->prepare("
            SELECT inv.batch_id, inv.quantity, inv.min_threshold, b.medicine_id, m.brand_name
            FROM inventory inv
            JOIN batches b ON inv.batch_id = b.id
            JOIN medicine_master m ON b.medicine_id = m.id
            WHERE inv.user_id = :uid AND inv.quantity <= inv.min_threshold
        ");
        $lowStockQuery->execute([':uid' => $retailerId]);
        $lowStockItems = $lowStockQuery->fetchAll();

        $checkStmt = $pdo->prepare("SELECT id FROM draft_purchase_orders WHERE retailer_id = :uid AND batch_id = :bid AND status = 'draft'");
        $insertDraft = $pdo->prepare("
            INSERT INTO draft_purchase_orders (retailer_id, distributor_id, medicine_id, batch_id, suggested_quantity, trigger_reason, status)
            VALUES (:uid, 2, :mid, :bid, 150, :reason, 'draft')
        ");

        foreach ($lowStockItems as $item) {
            $checkStmt->execute([':uid' => $retailerId, ':bid' => $item['batch_id']]);
            if (!$checkStmt->fetch()) {
                $reason = "Auto-trigger: Stock level ({$item['quantity']} units) dropped to or below minimum threshold ({$item['min_threshold']} units)";
                $insertDraft->execute([
                    ':uid' => $retailerId,
                    ':mid' => $item['medicine_id'],
                    ':bid' => $item['batch_id'],
                    ':reason' => $reason
                ]);
            }
        }

        // Fetch all active draft orders
        $stmt = $pdo->prepare("
            SELECT d.*, m.brand_name, m.generic_name, m.strength_mg, b.batch_number, u.name as distributor_name,
                   inv.quantity as current_stock, inv.min_threshold
            FROM draft_purchase_orders d
            JOIN medicine_master m ON d.medicine_id = m.id
            LEFT JOIN batches b ON d.batch_id = b.id
            JOIN users u ON d.distributor_id = u.id
            LEFT JOIN inventory inv ON inv.batch_id = b.id AND inv.user_id = d.retailer_id
            WHERE d.retailer_id = :uid AND d.status = 'draft'
            ORDER BY d.id DESC
        ");
        $stmt->execute([':uid' => $retailerId]);
        $drafts = $stmt->fetchAll();

        echo json_encode(['success' => true, 'drafts' => $drafts]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

if ($method === 'POST') {
    if (!verify_csrf_token()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'CSRF verification failed. Session token invalid.']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = $input['action'] ?? '';
    $draftId = (int)($input['draft_id'] ?? 0);

    try {
        if ($action === 'remove') {
            $stmt = $pdo->prepare("UPDATE draft_purchase_orders SET status = 'dismissed' WHERE id = :id AND retailer_id = :uid");
            $stmt->execute([':id' => $draftId, ':uid' => $retailerId]);
            echo json_encode(['success' => true, 'message' => 'Item dismissed from draft cart.']);
            exit;
        } elseif ($action === 'order_all') {
            $stmt = $pdo->prepare("UPDATE draft_purchase_orders SET status = 'ordered' WHERE retailer_id = :uid AND status = 'draft'");
            $stmt->execute([':uid' => $retailerId]);
            echo json_encode(['success' => true, 'message' => 'Official Purchase Order transmitted to Distributor!']);
            exit;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}
