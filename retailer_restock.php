<?php
$pageTitle = 'Auto-Restock Draft Cart & PO Dispatch';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_role('retailer');

$pdo = Database::getConnection();
$retailerId = $_SESSION['user_id'];
$message = null;

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $message = ['type' => 'danger', 'text' => 'Security Error: CSRF token validation failed. Please refresh.'];
    } else {
        $action = clean_string($_POST['action'] ?? '');

        if ($action === 'dismiss_item') {
            $draftId = clean_int($_POST['draft_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE draft_purchase_orders SET status = 'dismissed' WHERE id = :id AND retailer_id = :uid");
            $stmt->execute([':id' => $draftId, ':uid' => $retailerId]);
            $message = ['type' => 'info', 'text' => 'Item removed from active draft restock cart.'];
        } elseif ($action === 'update_qty') {
            $draftId = clean_int($_POST['draft_id'] ?? 0);
            $newQty = max(1, clean_int($_POST['quantity'] ?? 1));
            $stmt = $pdo->prepare("UPDATE draft_purchase_orders SET suggested_quantity = :qty WHERE id = :id AND retailer_id = :uid");
            $stmt->execute([':qty' => $newQty, ':id' => $draftId, ':uid' => $retailerId]);
            $message = ['type' => 'success', 'text' => 'Order quantity updated.'];
        } elseif ($action === 'place_official_po') {
            $stmt = $pdo->prepare("UPDATE draft_purchase_orders SET status = 'ordered' WHERE retailer_id = :uid AND status = 'draft'");
            $stmt->execute([':uid' => $retailerId]);

            // Insert notification for distributor
            $nStmt = $pdo->prepare("INSERT INTO notifications (target_role, title, message, type) VALUES ('distributor', '📦 New Purchase Order Placed', 'CareFirst Pharmacy has submitted an official purchase order from their draft restock cart.', 'info')");
            $nStmt->execute();

            $message = ['type' => 'success', 'text' => 'Official Purchase Order transmitted to Nexus Health Logistics (Distributor)!'];
        }
    }
}

// Auto-scan inventory and insert any breached thresholds not yet queued
$lowStockQuery = $pdo->prepare("
    SELECT inv.batch_id, inv.quantity, inv.min_threshold, b.medicine_id, m.brand_name
    FROM inventory inv
    JOIN batches b ON inv.batch_id = b.id
    JOIN medicine_master m ON b.medicine_id = m.id
    WHERE inv.user_id = :uid AND inv.quantity <= inv.min_threshold
");
$lowStockQuery->execute([':uid' => $retailerId]);
$lowStock = $lowStockQuery->fetchAll();

$checkStmt = $pdo->prepare("SELECT id FROM draft_purchase_orders WHERE retailer_id = :uid AND batch_id = :bid AND status = 'draft'");
$insertDraftStmt = $pdo->prepare("
    INSERT INTO draft_purchase_orders (retailer_id, distributor_id, medicine_id, batch_id, suggested_quantity, trigger_reason, status)
    VALUES (:uid, 2, :mid, :bid, 150, :reason, 'draft')
");

foreach ($lowStock as $ls) {
    $checkStmt->execute([':uid' => $retailerId, ':bid' => $ls['batch_id']]);
    if (!$checkStmt->fetch()) {
        $reason = "Auto-trigger: Stock level ({$ls['quantity']} units) dropped to or below minimum threshold ({$ls['min_threshold']} units)";
        $insertDraftStmt->execute([
            ':uid' => $retailerId,
            ':mid' => $ls['medicine_id'],
            ':bid' => $ls['batch_id'],
            ':reason' => $reason
        ]);
    }
}

// Fetch active draft purchase orders
$draftStmt = $pdo->prepare("
    SELECT d.*, m.brand_name, m.generic_name, m.salt_composition, m.strength_mg, m.dosage_form,
           b.batch_number, u.name as distributor_name, inv.quantity as current_stock, inv.min_threshold
    FROM draft_purchase_orders d
    JOIN medicine_master m ON d.medicine_id = m.id
    LEFT JOIN batches b ON d.batch_id = b.id
    JOIN users u ON d.distributor_id = u.id
    LEFT JOIN inventory inv ON inv.batch_id = b.id AND inv.user_id = d.retailer_id
    WHERE d.retailer_id = :uid AND d.status = 'draft'
    ORDER BY d.id DESC
");
$draftStmt->execute([':uid' => $retailerId]);
$drafts = $draftStmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
  <div style="margin-bottom: 1.5rem;">
    <h2 style="font-size: 1.4rem; font-weight: 700;">Auto-Restock Draft Cart &amp; Procurement Hub</h2>
    <p style="font-size: 0.825rem; color: var(--text-subtle);">
      Automated threshold breach monitor: Review, adjust quantities, dismiss unwanted items, and dispatch official orders to distributors.
    </p>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?= $message['type'] ?>">
      <?= icon('check', 16) ?>
      <div><?= htmlspecialchars($message['text']) ?></div>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">
      <div>
        <h3 class="card-title">Pending Draft Restock Orders</h3>
        <p class="card-description">Items automatically generated by the threshold detection engine</p>
      </div>
      <?php if (!empty($drafts)): ?>
        <form method="POST" action="retailer_restock.php" onsubmit="return confirm('Transmit official Purchase Order to Nexus Health Logistics?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="place_official_po">
          <button type="submit" class="btn btn-primary">
            <?= icon('truck', 16) ?> Dispatch Purchase Order to Distributor
          </button>
        </form>
      <?php endif; ?>
    </div>

    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr>
            <th>Medicine &amp; Formula</th>
            <th>Batch Reference</th>
            <th>Current Level vs Safe Limit</th>
            <th>Suggested Restock Units</th>
            <th>Auto-Trigger Reason</th>
            <th>Designated Distributor</th>
            <th style="text-align: right;">Manual Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($drafts)): ?>
            <tr>
              <td colspan="7" style="text-align: center; color: var(--text-subtle); padding: 2.5rem 1rem;">
                <div style="margin-bottom: 0.5rem;"><?= icon('check', 28) ?></div>
                <strong>Draft restock cart is currently empty.</strong><br>
                All retail medicine shelf levels meet or exceed their configured safety limits.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($drafts as $d): ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars($d['brand_name']) ?></strong>
                  <div style="font-size: 0.725rem; color: var(--text-subtle);">
                    <?= htmlspecialchars($d['salt_composition']) ?> &bull; <?= $d['strength_mg'] ?>mg (<?= $d['dosage_form'] ?>)
                  </div>
                </td>
                <td>
                  <code style="font-family: var(--font-mono); font-size: 0.8rem;"><?= htmlspecialchars($d['batch_number'] ?? 'Auto-Allocated') ?></code>
                </td>
                <td>
                  <span class="badge badge-danger"><?= $d['current_stock'] ?? 'Critical' ?> units</span>
                  <span style="font-size: 0.75rem; color: var(--text-subtle);"> (Threshold: <?= $d['min_threshold'] ?? 10 ?> units)</span>
                </td>
                <td>
                  <form method="POST" action="retailer_restock.php" style="display: inline-flex; align-items: center; gap: 0.35rem;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_qty">
                    <input type="hidden" name="draft_id" value="<?= $d['id'] ?>">
                    <input type="number" name="quantity" class="form-control" style="width: 85px; padding: 0.25rem 0.5rem; font-size: 0.8rem;" value="<?= $d['suggested_quantity'] ?>" min="1" step="1">
                    <button type="submit" class="btn btn-outline btn-sm">Update</button>
                  </form>
                </td>
                <td>
                  <span style="font-size: 0.8rem; color: var(--text-subtle);"><?= htmlspecialchars($d['trigger_reason']) ?></span>
                </td>
                <td>
                  <span class="badge badge-primary"><?= icon('truck', 12) ?> <?= htmlspecialchars($d['distributor_name']) ?></span>
                </td>
                <td style="text-align: right;">
                  <form method="POST" action="retailer_restock.php" onsubmit="return confirm('Remove <?= htmlspecialchars($d['brand_name']) ?> from draft restock cart?');" style="display: inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="dismiss_item">
                    <input type="hidden" name="draft_id" value="<?= $d['id'] ?>">
                    <button type="submit" class="btn btn-outline btn-sm" style="color: var(--color-danger); border-color: var(--color-danger-border);">
                      <?= icon('x', 12) ?> Remove
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

