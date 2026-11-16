<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

handle_api_cors();
header('Content-Type: application/json');

if (!is_logged_in() || $_SESSION['user_role'] !== 'retailer') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: Only authorized retail pharmacies can adjust inventory.']);
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
enforce_rate_limit('adjust_stock', 30, 60);

$invId = (int)($_POST['inventory_id'] ?? 0);
$adjType = trim($_POST['adjustment_type'] ?? 'damage');
$qty = (int)($_POST['quantity'] ?? 0);
$reason = trim($_POST['reason'] ?? 'Routine Stock Count Adjustment');

if ($invId <= 0 || $qty <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid inventory lot and adjustment quantity are required.']);
    exit;
}

$pdo = Database::getConnection();

try {
    $stmt = $pdo->prepare("
        SELECT inv.*, b.batch_number, m.brand_name 
        FROM inventory inv
        JOIN batches b ON inv.batch_id = b.id
        JOIN medicine_master m ON b.medicine_id = m.id
        WHERE inv.id = :id AND inv.user_id = :uid
    ");
    $stmt->execute([':id' => $invId, ':uid' => $_SESSION['user_id']]);
    $inv = $stmt->fetch();

    if (!$inv) {
        throw new Exception("Inventory lot not found.");
    }

    if ($qty > (int)$inv['quantity']) {
        throw new Exception("Cannot adjust more than currently available on shelf ({$inv['quantity']} units).");
    }

    $pdo->beginTransaction();

    $upd = $pdo->prepare("UPDATE inventory SET quantity = quantity - :qty WHERE id = :id AND user_id = :uid");
    $upd->execute([':qty' => $qty, ':id' => $invId, ':uid' => $_SESSION['user_id']]);

    // Record in Audit Trail
    Database::logAudit(
        'STOCK_ADJUSTMENT',
        'INVENTORY',
        $inv['batch_number'],
        "Stock adjustment: -{$qty} units of {$inv['brand_name']} ({$inv['batch_number']}) due to {$adjType}. Notes: {$reason}."
    );

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Successfully adjusted -{$qty} units of {$inv['brand_name']} ({$inv['batch_number']}). Logged in central audit trail."
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

