<?php
$pageTitle = 'Production Analytics & Distribution Telemetry';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_role('manufacturer');

$pdo = Database::getConnection();

// Fetch category distribution strictly for THIS manufacturer
$catStmt = $pdo->prepare("
    SELECT m.category, COUNT(b.id) as batch_count, SUM(b.initial_quantity) as total_units
    FROM batches b
    JOIN medicine_master m ON b.medicine_id = m.id
    WHERE b.manufacturer_id = :mid
    GROUP BY m.category
    ORDER BY total_units DESC
");
$catStmt->execute([':mid' => $_SESSION['user_id']]);
$categories = $catStmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
  <div style="margin-bottom: 1.5rem;">
    <h2 style="font-size: 1.4rem; font-weight: 700;">Production &amp; Distribution Telemetry</h2>
    <p style="font-size: 0.825rem; color: var(--text-subtle);">
      Granular yield analysis, downstream logistics absorption, and clinical category allocation.
    </p>
  </div>

  <!-- Primary Comparison Bar Chart -->
  <div class="card" style="margin-bottom: 2rem;">
    <div class="card-header">
      <div>
        <h3 class="card-title">Batch Production vs. Distributor Absorption</h3>
        <p class="card-description">Total units manufactured compared to downstream dispatches absorbed by Nexus Logistics</p>
      </div>
      <span class="badge badge-primary"><?= icon('activity', 12) ?> Downstream Telemetry</span>
    </div>
    <div style="height: 280px; position: relative;">
      <canvas id="absorptionChart"></canvas>
    </div>
  </div>

  <div class="grid-2" style="margin-bottom: 2rem;">
    <!-- Category Doughnut Chart -->
    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Therapeutic Category Share</h3>
          <p class="card-description">Allocation of manufacturing capacity across medical classes</p>
        </div>
        <span class="badge badge-neutral"><?= icon('layers', 12) ?> Class Share</span>
      </div>
      <div style="height: 260px; position: relative;">
        <canvas id="categoryYieldChart"></canvas>
      </div>
    </div>

    <!-- Category Distribution Table -->
    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Therapeutic Category Yields</h3>
          <p class="card-description">Volume minted by clinical classification</p>
        </div>
        <span class="badge badge-neutral"><?= count($categories) ?> Classes</span>
      </div>
      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <th>Therapeutic Class</th>
              <th style="text-align: right;">Batches</th>
              <th style="text-align: right;">Total Units</th>
              <th style="text-align: right;">Yield Share</th>
            </tr>
          </thead>
          <tbody>
            <?php 
              $overallUnits = array_sum(array_column($categories, 'total_units'));
              foreach ($categories as $cat): 
                $pct = $overallUnits > 0 ? round(($cat['total_units'] / $overallUnits) * 100, 1) : 0;
            ?>
              <tr>
                <td><strong><?= htmlspecialchars($cat['category']) ?></strong></td>
                <td class="num-cell"><?= $cat['batch_count'] ?></td>
                <td class="num-cell"><?= number_format($cat['total_units']) ?></td>
                <td class="num-cell">
                  <span class="badge badge-primary"><?= $pct ?>%</span>
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

