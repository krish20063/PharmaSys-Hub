<?php
$pageTitle = 'Manufacturer Production Overview';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_role('manufacturer');

$pdo = Database::getConnection();

// Fetch strictly THIS manufacturer's batches
$batchStmt = $pdo->prepare("
    SELECT b.*, m.brand_name, m.generic_name, m.strength_mg, m.dosage_form, m.category
    FROM batches b
    JOIN medicine_master m ON b.medicine_id = m.id
    WHERE b.manufacturer_id = :mid
    ORDER BY b.id DESC
");
$batchStmt->execute([':mid' => $_SESSION['user_id']]);
$batches = $batchStmt->fetchAll();

$now = time();
$totalBatches = count($batches);
$recalledCount = 0;
$expiredCount = 0;
$totalProduced = 0;
$totalInMarket = 0;

foreach ($batches as $b) {
    if ((int)$b['is_recalled'] === 1) $recalledCount++;
    if ((int)$b['is_expired'] === 1 || strtotime($b['expiry_date']) < $now) $expiredCount++;
    $totalProduced += (int)$b['initial_quantity'];
    $totalInMarket += (int)$b['current_quantity'];
}
$safeBatches = $totalBatches - $recalledCount - $expiredCount;

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
  <div style="margin-bottom: 1.5rem;">
    <h2 style="font-size: 1.4rem; font-weight: 700;">Manufacturing Operations &amp; Quality Telemetry</h2>
    <p style="font-size: 0.825rem; color: var(--text-subtle);">
      <?= htmlspecialchars($_SESSION['user_name']) ?> &bull; Plant License: <code><?= htmlspecialchars($_SESSION['user_license'] ?: 'MFG-CDSCO-REGISTERED') ?></code>
    </p>
  </div>

  <!-- KPI Cards -->
  <div class="stats-grid">
    <div class="stat-box">
      <div>
        <div class="stat-label">Active Market Batches</div>
        <div class="stat-value"><?= $safeBatches ?></div>
        <div class="stat-subtext">Verified safe &bull; In circulation</div>
      </div>
      <div class="stat-icon-wrapper stat-icon-blue"><?= icon('box', 20) ?></div>
    </div>

    <div class="stat-box">
      <div>
        <div class="stat-label">Blacklisted / Recalled</div>
        <div class="stat-value" style="color: var(--color-danger);"><?= $recalledCount ?></div>
        <div class="stat-subtext">Strictly blocked across retail POS</div>
      </div>
      <div class="stat-icon-wrapper stat-icon-red"><?= icon('shield-alert', 20) ?></div>
    </div>

    <div class="stat-box">
      <div>
        <div class="stat-label">Expired (Date Matched)</div>
        <div class="stat-value" style="color: var(--color-warning);"><?= $expiredCount ?></div>
        <div class="stat-subtext">Automated background expiry daemon</div>
      </div>
      <div class="stat-icon-wrapper stat-icon-amber"><?= icon('clock', 20) ?></div>
    </div>

    <div class="stat-box">
      <div>
        <div class="stat-label">Cumulative Volume</div>
        <div class="stat-value"><?= number_format($totalProduced) ?></div>
        <div class="stat-subtext">Finished pharmaceutical units minted</div>
      </div>
      <div class="stat-icon-wrapper stat-icon-green"><?= icon('activity', 20) ?></div>
    </div>
  </div>

  <!-- Quick Telemetry & Recent Releases -->
  <div class="grid-2">
    <!-- Production Output Trajectory Chart -->
    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Monthly Output &amp; Capacity Trajectory</h3>
          <p class="card-description">Plant production velocity (Units in 1,000s) &bull; 6-Month rolling trend</p>
        </div>
        <span class="badge badge-success"><?= icon('activity', 12) ?> 98.4% QA Yield</span>
      </div>
      <div style="height: 250px; position: relative;">
        <canvas id="monthlyOutputChart"></canvas>
      </div>
    </div>

    <!-- Recent Batches Ledger -->
    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Latest Released Batches</h3>
          <p class="card-description">Quality certified manufacturing lots</p>
        </div>
        <a href="manufacturer_batches.php" class="btn btn-outline btn-sm">View All &rarr;</a>
      </div>
      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <th>Batch Code</th>
              <th>Medicine</th>
              <th>Expiry</th>
              <th style="text-align: right;">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($batches, 0, 5) as $b): ?>
              <?php
                $isExpired = (int)$b['is_expired'] === 1 || strtotime($b['expiry_date']) < $now;
                $isRecalled = (int)$b['is_recalled'] === 1;
              ?>
              <tr class="<?= ($isExpired || $isRecalled) ? 'unsafe-row' : '' ?>">
                <td><code style="font-family: var(--font-mono); font-size: 0.8rem;"><?= htmlspecialchars($b['batch_number']) ?></code></td>
                <td>
                  <strong><?= htmlspecialchars($b['brand_name']) ?></strong>
                  <div style="font-size: 0.7rem; color: var(--text-subtle);"><?= $b['strength_mg'] ?>mg &bull; <?= $b['dosage_form'] ?></div>
                </td>
                <td><?= date('d-M-Y', strtotime($b['expiry_date'])) ?></td>
                <td style="text-align: right;">
                  <?php if ($isRecalled): ?>
                    <span class="badge badge-danger"><?= icon('shield-alert', 12) ?> RECALLED</span>
                  <?php elseif ($isExpired): ?>
                    <span class="badge badge-danger"><?= icon('clock', 12) ?> EXPIRED</span>
                  <?php else: ?>
                    <span class="badge badge-success"><?= icon('check', 12) ?> Active</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="assets/js/manufacturer.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
