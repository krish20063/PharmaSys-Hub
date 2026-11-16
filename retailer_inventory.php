<?php
$pageTitle = 'Pharmacy Shelf Stock & Batch Audit';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_role('retailer');

$pdo = Database::getConnection();

// Handle Rack Location Update if submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_rack') {
    $invId = (int)$_POST['inventory_id'];
    $newRack = trim($_POST['rack_location']);
    $stmt = $pdo->prepare("UPDATE inventory SET warehouse_rack_location = :rack WHERE id = :id AND user_id = :uid");
    $stmt->execute([':rack' => $newRack, ':id' => $invId, ':uid' => $_SESSION['user_id']]);
    header('Location: retailer_inventory.php?updated=1');
    exit;
}

// Fetch all shelf inventory
$stmt = $pdo->prepare("
    SELECT inv.*, b.batch_number, b.mfg_date, b.expiry_date, b.mrp_unit, b.distributor_price, b.is_recalled, b.recall_reason, b.is_expired,
           m.brand_name, m.generic_name, m.salt_composition, m.strength_mg, m.dosage_form, m.category, m.schedule_type
    FROM inventory inv
    JOIN batches b ON inv.batch_id = b.id
    JOIN medicine_master m ON b.medicine_id = m.id
    WHERE inv.user_id = :uid
    ORDER BY inv.quantity ASC
");
$stmt->execute([':uid' => $_SESSION['user_id']]);
$items = $stmt->fetchAll();

// Calculate metrics
$totalItems = count($items);
$lowStockCount = 0;
$recalledCount = 0;
$expiredCount = 0;
$now = time();

foreach ($items as $item) {
    if ((int)$item['quantity'] <= (int)$item['min_threshold']) $lowStockCount++;
    if ((int)$item['is_recalled'] === 1) $recalledCount++;
    if ((int)$item['is_expired'] === 1 || strtotime($item['expiry_date']) < $now) $expiredCount++;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
  <div style="margin-bottom: 1.5rem;">
    <h2 style="font-size: 1.4rem; font-weight: 700;">Pharmacy Shelf Inventory &amp; Batch Ledger</h2>
    <p style="font-size: 0.825rem; color: var(--text-subtle);">
      Comprehensive shelf stock audit, rack mapping, and batch safety telemetry.
    </p>
  </div>

  <!-- KPI Stat Boxes -->
  <div class="stats-grid">
    <div class="stat-box">
      <div>
        <div class="stat-label">Total Stocked SKUs</div>
        <div class="stat-value"><?= $totalItems ?></div>
        <div class="stat-subtext">Active pharmacy catalogue</div>
      </div>
      <div class="stat-icon-wrapper stat-icon-blue"><?= icon('boxes', 20) ?></div>
    </div>

    <div class="stat-box">
      <div>
        <div class="stat-label">Low Stock Alerts</div>
        <div class="stat-value" style="color: var(--color-warning);"><?= $lowStockCount ?></div>
        <div class="stat-subtext">Below safety threshold &rarr; queued in draft</div>
      </div>
      <div class="stat-icon-wrapper stat-icon-amber"><?= icon('alert-triangle', 20) ?></div>
    </div>

    <div class="stat-box">
      <div>
        <div class="stat-label">Recalled / Quarantined</div>
        <div class="stat-value" style="color: var(--color-danger);"><?= $recalledCount ?></div>
        <div class="stat-subtext">Blocked by manufacturer QA</div>
      </div>
      <div class="stat-icon-wrapper stat-icon-red"><?= icon('shield-alert', 20) ?></div>
    </div>

    <div class="stat-box">
      <div>
        <div class="stat-label">Expired (Date Matched)</div>
        <div class="stat-value" style="color: var(--color-danger);"><?= $expiredCount ?></div>
        <div class="stat-subtext">Past expiry date &bull; blocked from sale</div>
      </div>
      <div class="stat-icon-wrapper stat-icon-red"><?= icon('clock', 20) ?></div>
    </div>
  </div>

  <!-- Table with Filter Controls -->
  <div class="card">
    <div class="card-header">
      <div>
        <h3 class="card-title">Shelf Batches &amp; Rack Allocations</h3>
        <p class="card-description">Filter by safety compliance, rack position, or stock thresholds</p>
      </div>
      <div style="display: flex; gap: 0.5rem; align-items: center;">
        <input type="text" id="inventory-search" class="form-control" style="width: 260px;" placeholder="Filter by brand, salt, or rack..." oninput="filterInventory(this.value)">
      </div>
    </div>

    <div class="table-wrapper">
      <table class="data-table" id="inventory-table">
        <thead>
          <tr>
            <th>Medicine &amp; Strength</th>
            <th>Batch Number</th>
            <th>Rack / Bin Location</th>
            <th style="text-align: right;">Current Stock</th>
            <th style="text-align: right;">Min Safe Limit</th>
            <th>Expiry Date</th>
            <th>Safety State</th>
            <th style="text-align: right;">Rack Location Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <?php
              $isExpired = (int)$item['is_expired'] === 1 || strtotime($item['expiry_date']) < $now;
              $isRecalled = (int)$item['is_recalled'] === 1;
              $isLow = (int)$item['quantity'] <= (int)$item['min_threshold'];
            ?>
            <tr class="inv-row" data-search="<?= strtolower($item['brand_name'] . ' ' . $item['salt_composition'] . ' ' . $item['batch_number'] . ' ' . $item['warehouse_rack_location']) ?>">
              <td>
                <strong><?= htmlspecialchars($item['brand_name']) ?></strong>
                <div style="font-size: 0.725rem; color: var(--text-subtle);">
                  <?= htmlspecialchars($item['salt_composition']) ?> &bull; <?= $item['strength_mg'] ?>mg &bull; <?= htmlspecialchars($item['dosage_form']) ?>
                </div>
              </td>
              <td>
                <code style="font-family: var(--font-mono); font-size: 0.8rem;"><?= htmlspecialchars($item['batch_number']) ?></code>
              </td>
              <td>
                <span class="badge badge-neutral"><?= icon('map-pin', 12) ?> <?= htmlspecialchars($item['warehouse_rack_location']) ?></span>
              </td>
              <td class="num-cell" style="<?= $isLow ? 'color: var(--color-danger); font-weight: 700;' : '' ?>">
                <?= $item['quantity'] ?> units
              </td>
              <td class="num-cell"><?= $item['min_threshold'] ?> units</td>
              <td>
                <span style="<?= $isExpired ? 'color: var(--color-danger); font-weight: 700;' : '' ?>">
                  <?= date('d-M-Y', strtotime($item['expiry_date'])) ?>
                </span>
              </td>
              <td>
                <?php if ($isRecalled): ?>
                  <span class="badge badge-danger"><?= icon('shield-alert', 12) ?> RECALLED</span>
                  <?php if ($item['recall_reason']): ?>
                    <div style="font-size: 0.675rem; color: var(--color-danger); max-width: 160px; margin-top: 0.15rem;">
                      <?= htmlspecialchars($item['recall_reason']) ?>
                    </div>
                  <?php endif; ?>
                <?php elseif ($isExpired): ?>
                  <span class="badge badge-danger"><?= icon('alert-triangle', 12) ?> EXPIRED</span>
                <?php elseif ($isLow): ?>
                  <span class="badge badge-warning"><?= icon('refresh', 12) ?> Low Stock</span>
                <?php else: ?>
                  <span class="badge badge-success"><?= icon('check', 12) ?> Optimal</span>
                <?php endif; ?>
              </td>
              <td style="text-align: right; white-space: nowrap;">
                <button type="button" class="btn btn-outline btn-sm" onclick="openAdjustModal(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['brand_name'])) ?>', '<?= htmlspecialchars(addslashes($item['batch_number'])) ?>', <?= (int)$item['quantity'] ?>)" title="Stock Adjustment (Damage / Count / Audit)">
                  <?= icon('sliders', 12) ?> Adjust
                </button>
                <form method="POST" action="retailer_inventory.php" style="display: inline-flex; gap: 0.35rem; margin-left: 0.35rem;">
                  <input type="hidden" name="action" value="update_rack">
                  <input type="hidden" name="inventory_id" value="<?= $item['id'] ?>">
                  <input type="text" name="rack_location" class="form-control" style="width: 105px; padding: 0.25rem 0.5rem; font-size: 0.75rem;" value="<?= htmlspecialchars($item['warehouse_rack_location']) ?>" title="Shelf Rack ID">
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

<!-- Stock Adjustment Modal -->
<div id="adjust-modal" style="display: none; position: fixed; inset: 0; background: rgba(11, 15, 25, 0.8); z-index: 999; align-items: center; justify-content: center; backdrop-filter: blur(6px);">
  <div class="card" style="max-width: 480px; width: 92%; padding: 1.75rem; background: var(--bg-surface); box-shadow: var(--shadow-lg);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
      <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 0.4rem;">
        <?= icon('sliders', 18) ?> Stock Adjustment &amp; Audit Movement
      </h3>
      <button type="button" onclick="closeAdjustModal()" style="border: none; background: transparent; color: var(--text-muted); font-size: 1.25rem; cursor: pointer;">&times;</button>
    </div>

    <div id="adjust-alert" style="display: none;" class="alert"></div>

    <form id="adjust-form" onsubmit="submitAdjustment(event)">
      <input type="hidden" id="adj-inv-id" name="inventory_id" value="">

      <div style="background: var(--bg-surface-subtle); border: 1px solid var(--border-light); border-radius: 6px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.825rem;">
        <div>Item: <strong id="adj-item-name" style="color: var(--text-main);"></strong></div>
        <div>Batch: <code id="adj-batch-no" style="font-family: var(--font-mono);"></code> &bull; Available: <strong id="adj-avail-qty"></strong> units</div>
      </div>

      <div class="form-group">
        <label class="form-label">Adjustment Reason Category *</label>
        <select id="adj-type" name="adjustment_type" class="form-control" required>
          <option value="Damage / Breakage">Damage / Packaging Breakage</option>
          <option value="Physical Count Discrepancy">Physical Shelf Audit Discrepancy</option>
          <option value="Laboratory QC Sampling">Laboratory Quality Control Sampling</option>
          <option value="Clinical Disposal">Quarantine Safe Disposal</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Quantity to Deduct (Units) *</label>
        <input type="number" id="adj-qty" name="quantity" class="form-control" min="1" value="1" required>
      </div>

      <div class="form-group">
        <label class="form-label">Pharmacist Clinical Notes *</label>
        <input type="text" id="adj-reason" name="reason" class="form-control" placeholder="e.g. Broken blister pack found during audit check" required>
      </div>

      <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.25rem;">
        <button type="button" onclick="closeAdjustModal()" class="btn btn-outline">Cancel</button>
        <button type="submit" id="adj-submit-btn" class="btn btn-primary">Confirm Adjustment &amp; Log Audit</button>
      </div>
    </form>
  </div>
</div>

<script>
function filterInventory(q) {
  const query = q.toLowerCase().trim();
  document.querySelectorAll('.inv-row').forEach(row => {
    const text = row.getAttribute('data-search') || '';
    row.style.display = text.includes(query) ? '' : 'none';
  });
}

function openAdjustModal(id, name, batch, qty) {
  document.getElementById('adj-inv-id').value = id;
  document.getElementById('adj-item-name').innerText = name;
  document.getElementById('adj-batch-no').innerText = batch;
  document.getElementById('adj-avail-qty').innerText = qty;
  document.getElementById('adj-qty').max = qty;
  document.getElementById('adjust-alert').style.display = 'none';
  document.getElementById('adjust-modal').style.display = 'flex';
}

function closeAdjustModal() {
  document.getElementById('adjust-modal').style.display = 'none';
}

async function submitAdjustment(e) {
  e.preventDefault();
  const btn = document.getElementById('adj-submit-btn');
  btn.disabled = true;
  btn.innerText = 'Logging...';

  const formData = new FormData(document.getElementById('adjust-form'));
  try {
    const res = await fetch('api/adjust_stock.php', {
      method: 'POST',
      body: formData
    });
    const data = await res.json();
    const alertBox = document.getElementById('adjust-alert');
    alertBox.style.display = 'block';

    if (data.success) {
      alertBox.className = 'alert alert-success';
      alertBox.innerText = data.message;
      setTimeout(() => {
        window.location.reload();
      }, 1200);
    } else {
      alertBox.className = 'alert alert-danger';
      alertBox.innerText = data.error || 'Adjustment failed.';
      btn.disabled = false;
      btn.innerText = 'Confirm Adjustment & Log Audit';
    }
  } catch (err) {
    alert('Network error: ' + err.message);
    btn.disabled = false;
    btn.innerText = 'Confirm Adjustment & Log Audit';
  }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

