<?php
$pageTitle = 'Distributor Logistics Control';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_role('distributor');

$pdo = Database::getConnection();

// Fetch bulk inventory
$stmt = $pdo->prepare("
    SELECT inv.*, b.batch_number, b.expiry_date, b.distributor_price, b.is_recalled, b.is_expired,
           m.brand_name, m.generic_name, m.salt_composition, m.strength_mg, m.dosage_form
    FROM inventory inv
    JOIN batches b ON inv.batch_id = b.id
    JOIN medicine_master m ON b.medicine_id = m.id
    WHERE inv.user_id = :uid
");
$stmt->execute([':uid' => $_SESSION['user_id']]);
$inventory = $stmt->fetchAll();

// Fetch challans
$chStmt = $pdo->prepare("
    SELECT ch.*, u.name as retailer_name
    FROM dispatch_challans ch
    JOIN users u ON ch.retailer_id = u.id
    WHERE ch.distributor_id = :uid
    ORDER BY ch.id DESC
");
$chStmt->execute([':uid' => $_SESSION['user_id']]);
$challans = $chStmt->fetchAll();

$totalBulkUnits = array_sum(array_column($inventory, 'quantity'));
$coldChainCount = count(array_filter($inventory, fn($i) => str_contains($i['warehouse_rack_location'], 'ColdChain')));

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
  <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
      <h2 style="font-size: 1.4rem; font-weight: 700;">Nexus Healthcare Logistics Control Hub</h2>
      <p style="font-size: 0.825rem; color: var(--text-subtle); margin-top: 0.25rem;">
        Bulk pharmaceutical warehousing, cold chain telemetry, and GST-compliant B2B dispatch operations.
      </p>
    </div>
    <div style="display: flex; gap: 0.5rem;">
      <a href="distributor_buy.php" class="btn btn-primary btn-sm">
        <?= icon('cart', 14) ?> Procure Bulk Stock from Manufacturers
      </a>
      <a href="distributor_challans.php" class="btn btn-outline btn-sm">
        <?= icon('file-text', 14) ?> Dispatch Challans
      </a>
    </div>
  </div>

  <!-- KPI Cards -->
  <div class="stats-grid">
    <div class="stat-box">
      <div>
        <div class="stat-label">Bulk Warehouse Stock</div>
        <div class="stat-value"><?= number_format($totalBulkUnits) ?></div>
        <div class="stat-subtext">Active pharmaceutical units in transit/storage</div>
      </div>
      <div class="stat-icon-wrapper stat-icon-blue"><?= icon('truck', 20) ?></div>
    </div>

    <div class="stat-box">
      <div>
        <div class="stat-label">Cold-Chain Lots (2°C - 8°C)</div>
        <div class="stat-value"><?= $coldChainCount ?></div>
        <div class="stat-subtext">Biologicals &amp; antibiotic suspensions</div>
      </div>
      <div class="stat-icon-wrapper stat-icon-blue"><?= icon('shield-check', 20) ?></div>
    </div>

    <div class="stat-box">
      <div>
        <div class="stat-label">GST Challans Issued</div>
        <div class="stat-value"><?= count($challans) ?></div>
        <div class="stat-subtext">Legally compliant B2B tax dispatches</div>
      </div>
      <div class="stat-icon-wrapper stat-icon-green"><?= icon('file-text', 20) ?></div>
    </div>

    <div class="stat-box">
      <div>
        <div class="stat-label">Near-Expiry Return Claims</div>
        <div class="stat-value" style="color: var(--color-warning);">0</div>
        <div class="stat-subtext">Reverse logistics claims processed</div>
      </div>
      <div class="stat-icon-wrapper stat-icon-amber"><?= icon('alert-triangle', 20) ?></div>
    </div>
  </div>

  <!-- Recent Challans & Warehouse Distribution -->
  <div class="grid-2">
    <!-- Recent Challans -->
    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Recent GST Dispatches</h3>
          <p class="card-description">Dispatches to CareFirst and regional pharmacies</p>
        </div>
        <a href="distributor_challans.php" class="btn btn-outline btn-sm">All Challans &rarr;</a>
      </div>
      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <th>Challan No</th>
              <th>Retailer</th>
              <th style="text-align: right;">Total Amount</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($challans, 0, 4) as $ch): ?>
              <tr>
                <td><code style="font-family: var(--font-mono); font-size: 0.8rem;"><?= htmlspecialchars($ch['challan_number']) ?></code></td>
                <td><?= htmlspecialchars($ch['retailer_name']) ?></td>
                <td class="num-cell">₹<?= number_format($ch['total_amount'], 2) ?></td>
                <td>
                  <span class="badge <?= $ch['status'] === 'delivered' ? 'badge-success' : 'badge-primary' ?>">
                    <?= ucfirst(htmlspecialchars($ch['status'])) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Storage Zone Breakdown -->
    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Warehouse Zoning Allocation</h3>
          <p class="card-description">Cold-Chain vs. Ambient storage zones</p>
        </div>
        <a href="distributor_inventory.php" class="btn btn-outline btn-sm">Rack Mapping &rarr;</a>
      </div>
      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <th>Storage Zone</th>
              <th>Temperature Spec</th>
              <th style="text-align: right;">Batches</th>
              <th>Compliance</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><strong>Cold Storage Vault (Aisle C)</strong></td>
              <td>2°C to 8°C Monitored</td>
              <td class="num-cell"><?= $coldChainCount ?> lots</td>
              <td><span class="badge badge-success"><?= icon('check', 12) ?> Compliant</span></td>
            </tr>
            <tr>
              <td><strong>Ambient Dry Warehouse (Aisles A-B-D)</strong></td>
              <td>20°C to 25°C Controlled</td>
              <td class="num-cell"><?= count($inventory) - $coldChainCount ?> lots</td>
              <td><span class="badge badge-success"><?= icon('check', 12) ?> Compliant</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
