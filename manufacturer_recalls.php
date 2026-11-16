<?php
$pageTitle = 'Smart Batch Recall Command';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_role('manufacturer');

$pdo = Database::getConnection();

// Fetch strictly THIS manufacturer's batches
$bStmt = $pdo->prepare("
    SELECT b.*, m.brand_name, m.generic_name, m.salt_composition, m.strength_mg, m.dosage_form
    FROM batches b
    JOIN medicine_master m ON b.medicine_id = m.id
    WHERE b.manufacturer_id = :mid
    ORDER BY b.is_recalled DESC, b.id DESC
");
$bStmt->execute([':mid' => $_SESSION['user_id']]);
$batches = $bStmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
      <h2 style="font-size: 1.4rem; font-weight: 700;">Smart Batch Recall Command &amp; Network Alert Center</h2>
      <p style="font-size: 0.825rem; color: var(--text-subtle);">
        Emergency Quality Assurance protocol: Toggling a recall instantly flags the batch (<code>is_recalled = 1</code>) and blocks it from all downstream Retailer POS carts.
      </p>
    </div>
  </div>

  <!-- Operational Protocol Banner -->
  <div class="alert alert-danger" style="margin-bottom: 1.5rem;">
    <?= icon('shield-alert', 20) ?>
    <div>
      <strong>Strict Enforcement Rule:</strong> When a batch is blacklisted, an immutable security flag is written to the central database. Any retail pharmacist attempting to dispense this batch will receive an immediate lockout alarm, and checkout will be aborted.
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <div>
        <h3 class="card-title">Live Batch Integrity Register</h3>
        <p class="card-description">Trigger or revoke emergency recalls with QA assay justification</p>
      </div>
      <span class="badge badge-neutral"><?= count($batches) ?> Total Batches Monitored</span>
    </div>

    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr>
            <th>Batch Code</th>
            <th>Medicine Formulation</th>
            <th>Active Chemical Salt</th>
            <th>Expiry Date</th>
            <th style="text-align: right;">Circulating Stock</th>
            <th>Safety State</th>
            <th style="text-align: right;">Emergency Command</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($batches as $b): ?>
            <?php
              $isRecalled = (int)$b['is_recalled'] === 1;
              $isExpired = (int)$b['is_expired'] === 1 || strtotime($b['expiry_date']) < time();
            ?>
            <tr>
              <td>
                <code style="font-family: var(--font-mono); font-size: 0.825rem; font-weight: 700;"><?= htmlspecialchars($b['batch_number']) ?></code>
              </td>
              <td>
                <strong><?= htmlspecialchars($b['brand_name']) ?></strong>
                <div style="font-size: 0.725rem; color: var(--text-subtle);"><?= $b['strength_mg'] ?>mg &bull; <?= $b['dosage_form'] ?></div>
              </td>
              <td><?= htmlspecialchars($b['salt_composition']) ?></td>
              <td><?= date('d-M-Y', strtotime($b['expiry_date'])) ?></td>
              <td class="num-cell"><?= number_format($b['current_quantity']) ?> units</td>
              <td>
                <?php if ($isRecalled): ?>
                  <span class="badge badge-danger"><?= icon('shield-alert', 12) ?> RECALLED</span>
                  <?php if ($b['recall_reason']): ?>
                    <div style="font-size: 0.7rem; color: var(--color-danger); max-width: 220px; margin-top: 0.2rem; line-height: 1.3;">
                      <em><?= htmlspecialchars($b['recall_reason']) ?></em>
                    </div>
                  <?php endif; ?>
                <?php elseif ($isExpired): ?>
                  <span class="badge badge-danger"><?= icon('clock', 12) ?> EXPIRED</span>
                <?php else: ?>
                  <span class="badge badge-success"><?= icon('check', 12) ?> Optimal (Safe)</span>
                <?php endif; ?>
              </td>
              <td style="text-align: right;">
                <?php if ($isRecalled): ?>
                  <button type="button" class="btn btn-outline btn-sm" onclick="toggleBatchRecall(<?= $b['id'] ?>, 1, '<?= htmlspecialchars($b['batch_number']) ?>', '<?= htmlspecialchars($b['brand_name']) ?>')">
                    <?= icon('refresh', 12) ?> Lift Recall
                  </button>
                <?php else: ?>
                  <button type="button" class="btn btn-danger btn-sm" onclick="toggleBatchRecall(<?= $b['id'] ?>, 0, '<?= htmlspecialchars($b['batch_number']) ?>', '<?= htmlspecialchars($b['brand_name']) ?>')">
                    <?= icon('shield-alert', 12) ?> Trigger Recall
                  </button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="assets/js/manufacturer.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

