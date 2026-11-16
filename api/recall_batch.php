<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

handle_api_cors();
header('Content-Type: application/json');

// Auth Guard: Only Manufacturer can recall batches
if (!is_logged_in() || $_SESSION['user_role'] !== 'manufacturer') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: Only licensed drug manufacturers can issue or revoke recall orders.']);
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
enforce_rate_limit('recall_batch', 30, 60);

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$batchId = (int)($input['batch_id'] ?? 0);
$action = $input['action'] ?? 'recall';
$reason = trim($input['reason'] ?? 'Stability assay variation detected by QA');

if ($batchId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid batch identifier']);
    exit;
}

$pdo = Database::getConnection();

try {
    // Competitor Guard: Verify this manufacturer owns this batch
    $chk = $pdo->prepare("SELECT id FROM batches WHERE id = :id AND manufacturer_id = :mid");
    $chk->execute([':id' => $batchId, ':mid' => $_SESSION['user_id']]);
    if (!$chk->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized: You can only issue recalls for batches minted by your manufacturing facility.']);
        exit;
    }

    $isRecalled = ($action === 'recall') ? 1 : 0;
    $recallReason = ($action === 'recall') ? $reason : null;

    $stmt = $pdo->prepare("UPDATE batches SET is_recalled = :recalled, recall_reason = :reason WHERE id = :id AND manufacturer_id = :mid");
    $stmt->execute([
        ':recalled' => $isRecalled,
        ':reason' => $recallReason,
        ':id' => $batchId,
        ':mid' => $_SESSION['user_id']
    ]);

    // Fetch batch details for notification broadcast
    $bStmt = $pdo->prepare("SELECT b.batch_number, m.brand_name FROM batches b JOIN medicine_master m ON b.medicine_id = m.id WHERE b.id = :id");
    $bStmt->execute([':id' => $batchId]);
    $batch = $bStmt->fetch();

    if ($batch) {
        $title = ($action === 'recall') 
            ? "🚨 RECALL NOTICE: {$batch['brand_name']} (Batch {$batch['batch_number']})" 
            : "✅ RECALL LIFTED: {$batch['brand_name']} (Batch {$batch['batch_number']})";
        
        $msg = ($action === 'recall')
            ? "Manufacturer has blacklisted Batch {$batch['batch_number']} due to: {$recallReason}. POS terminals have locked this batch."
            : "Recall for Batch {$batch['batch_number']} has been officially lifted after safety re-certification.";

        $nStmt = $pdo->prepare("INSERT INTO notifications (target_role, title, message, type) VALUES ('all', :t, :m, :type)");
        $nStmt->execute([
            ':t' => $title,
            ':m' => $msg,
            ':type' => ($action === 'recall') ? 'danger' : 'success'
        ]);
    }

    echo json_encode([
        'success' => true,
        'action' => $action,
        'batch_id' => $batchId,
        'is_recalled' => $isRecalled,
        'message' => "Batch status updated across central ledger."
    ]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
