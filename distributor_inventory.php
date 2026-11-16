<?php
$pageTitle = 'Warehouse Spatial Rack Index';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_role('distributor');

$pdo = Database::getConnection();
$message = null;

// Handle Rack Reassignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reassign_rack') {
    $invId = (int)$_POST['inventory_id'];
    $newRack = trim($_POST['rack_location']);
    if ($invId && $newRack) {
        $stmt = $pdo->prepare("UPDATE inventory SET warehouse_rack_location = :rack WHERE id = :id AND user_id = :uid");
        $stmt->execute([':rack' => $newRack, ':id' => $invId, ':uid' => $_SESSION['user_id']]);
        $message = ['type' => 'success', 'text' => "Rack location successfully updated to '{$newRack}'."];
    }
}

// Fetch all inventory items
$stmt = $pdo->prepare("
    SELECT inv.*, b.batch_number, b.expiry_date, b.distributor_price, b.is_recalled, b.is_expired,
           m.brand_name, m.generic_name, m.salt_composition, m.strength_mg, m.dosage_form, m.category
    FROM inventory inv
    JOIN batches b ON inv.batch_id = b.id
    JOIN medicine_master m ON b.medicine_id = m.id
    WHERE inv.user_id = :uid
    ORDER BY inv.warehouse_rack_location ASC
");
$stmt->execute([':uid' => $_SESSION['user_id']]);
$items = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
  <div style="margin-bottom: 1.5rem;">
    <h2 style="font-size: 1.4rem; font-weight: 700;">Warehouse Spatial Rack Mapping &amp; Inventory</h2>
    <p style="font-size: 0.825rem; color: var(--text-subtle);">
      Spatial tracking across temperature-controlled cold vault bays and dry warehouse shelving.
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
        <h3 class="card-title">Warehouse Aisle &amp; Bay Inventory Register</h3>
        <p class="card-description">Locate, audit, and reallocate stock for high-velocity dispatching</p>
      </div>
      <span class="badge badge-neutral"><?= count($items) ?> Mapped Storage Locations</span>
    </div>

    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr>
            <th>Medicine Formulation</th>
            <th>Batch Number</th>
            <th>Physical Warehouse Rack</th>
            <th>Storage Zone &amp; Temp</th>
            <th style="text-align: right;">Bulk Quantity</th>
            <th>Expiry Date</th>
            <th style="text-align: right;">Reallocate Storage Bay</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <?php
              $isCold = str_contains($item['warehouse_rack_location'], 'ColdChain');
              $isExpired = (int)$item['is_expired'] === 1 || strtotime($item['expiry_date']) < time();
            ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($item['brand_name']) ?></strong>
                <div style="font-size: 0.725rem; color: var(--text-subtle);">
                  <?= htmlspecialchars($item['salt_composition']) ?> &bull; <?= $item['strength_mg'] ?>mg (<?= $item['dosage_form'] ?>)
                </div>
              </td>
              <td><code style="font-family: var(--font-mono); font-size: 0.8rem;"><?= htmlspecialchars($item['batch_number']) ?></code></td>
              <td>
                <span class="badge <?= $isCold ? 'badge-primary' : 'badge-neutral' ?>">
                  <?= icon('map-pin', 12) ?> <?= htmlspecialchars($item['warehouse_rack_location']) ?>
                </span>
              </td>
              <td>
                <?php if ($isCold): ?>
                  <span class="badge badge-primary">❄️ Cold-Chain (2°C - 8°C)</span>
                <?php else: ?>
                  <span class="badge badge-neutral">🌡️ Ambient (20°C - 25°C)</span>
                <?php endif; ?>
              </td>
              <td class="num-cell"><?= number_format($item['quantity']) ?> units</td>
              <td>
                <span style="<?= $isExpired ? 'color: var(--color-danger); font-weight: 700;' : '' ?>">
                  <?= date('d-M-Y', strtotime($item['expiry_date'])) ?>
                </span>
              </td>
              <td style="text-align: right;">
                <form method="POST" action="distributor_inventory.php" style="display: inline-flex; gap: 0.35rem;">
                  <input type="hidden" name="action" value="reassign_rack">
                  <input type="hidden" name="inventory_id" value="<?= $item['id'] ?>">
                  <input type="text" name="rack_location" class="form-control" style="width: 170px; padding: 0.25rem 0.5rem; font-size: 0.75rem;" value="<?= htmlspecialchars($item['warehouse_rack_location']) ?>">
                  <button type="submit" class="btn btn-outline btn-sm">Save</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

