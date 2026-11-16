<?php
$pageTitle = 'Batch Minting & Supply Limits';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_role('manufacturer');

$pdo = Database::getConnection();
$message = null;

// Handle New Batch Minting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mint_batch') {
    if (!verify_csrf_token()) {
        $message = ['type' => 'danger', 'text' => 'Security Error: CSRF token validation failed. Please refresh and try again.'];
    } else {
        $medId = clean_int($_POST['medicine_id'] ?? 0);
        $batchNo = clean_string($_POST['batch_number'] ?? '');
        $mfgDate = clean_string($_POST['mfg_date'] ?? '');
        $expDate = clean_string($_POST['expiry_date'] ?? '');
        $units = clean_int($_POST['quantity'] ?? 0);
        $mrp = clean_float($_POST['mrp_unit'] ?? 0.0);
        $distPrice = clean_float($_POST['distributor_price'] ?? 0.0);

        if ($medId && $batchNo && $mfgDate && $expDate && $units > 0) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO batches (medicine_id, manufacturer_id, batch_number, mfg_date, expiry_date, initial_quantity, current_quantity, mrp_unit, distributor_price, is_recalled, is_expired)
                    VALUES (:mid, :mfrid, :bno, :mfg, :exp, :init_qty, :curr_qty, :mrp, :dist, 0, 0)
                ");
                $stmt->execute([
                    ':mid' => $medId,
                    ':mfrid' => $_SESSION['user_id'],
                    ':bno' => $batchNo,
                    ':mfg' => $mfgDate,
                    ':exp' => $expDate,
                    ':init_qty' => $units,
                    ':curr_qty' => $units,
                    ':mrp' => $mrp,
                    ':dist' => $distPrice
                ]);

                Database::logAudit('BATCH_MINT', 'BATCH', $batchNo, "Minted new batch {$batchNo} ({$units} units) for medicine #{$medId}.");
                $message = ['type' => 'success', 'text' => "Batch {$batchNo} successfully minted and released to your manufacturing ledger."];
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => "Error minting batch: " . $e->getMessage()];
            }
        }
    }
}

// Fetch medicines for dropdown
$medicines = $pdo->query("SELECT id, brand_name, salt_composition, strength_mg, dosage_form, price_mrp FROM medicine_master ORDER BY brand_name ASC")->fetchAll();

// Fetch strictly THIS manufacturer's batches
$batchStmt = $pdo->prepare("
    SELECT b.*, m.brand_name, m.generic_name, m.salt_composition, m.strength_mg, m.dosage_form
    FROM batches b
    JOIN medicine_master m ON b.medicine_id = m.id
    WHERE b.manufacturer_id = :mid
    ORDER BY b.id DESC
");
$batchStmt->execute([':mid' => $_SESSION['user_id']]);
$batches = $batchStmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
      <h2 style="font-size: 1.4rem; font-weight: 700;">Batch Minting &amp; Physical Supply Registry</h2>
      <p style="font-size: 0.825rem; color: var(--text-subtle);">
        Release quality-assured pharmaceutical batches with cryptographically unique identifiers.
      </p>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?= $message['type'] ?>">
      <?= icon('check', 16) ?>
      <div><?= htmlspecialchars($message['text']) ?></div>
    </div>
  <?php endif; ?>

  <!-- Minting Form -->
  <div class="card" style="margin-bottom: 2rem;">
    <div class="card-header">
      <div>
        <h3 class="card-title">Mint &amp; Release New Production Batch</h3>
        <p class="card-description">All batches are registered on the central immutable ledger</p>
      </div>
      <span class="badge badge-primary"><?= icon('shield-check', 12) ?> QA Certified</span>
    </div>

    <form method="POST" action="manufacturer_batches.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="mint_batch">

      <div class="grid-3">
        <div class="form-group">
          <label class="form-label">Pharmaceutical Formulation *</label>
          <select name="medicine_id" class="form-control" required>
            <?php foreach ($medicines as $m): ?>
              <option value="<?= $m['id'] ?>">
                <?= htmlspecialchars($m['brand_name']) ?> (<?= htmlspecialchars($m['salt_composition']) ?> <?= $m['strength_mg'] ?>mg)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Batch Code Identifier *</label>
          <input type="text" name="batch_number" class="form-control" value="BATCH-<?= strtoupper(substr(uniqid(), -6)) ?>" required>
        </div>

        <div class="form-group">
          <label class="form-label">Production Quantity (Units) *</label>
          <input type="number" name="quantity" class="form-control" value="8000" min="500" step="100" required>
        </div>
      </div>

      <div class="grid-4">
        <div class="form-group">
          <label class="form-label">Manufacturing Date *</label>
          <input type="date" name="mfg_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="form-group">
          <label class="form-label">Expiry Date *</label>
          <input type="date" name="expiry_date" class="form-control" value="<?= date('Y-m-d', strtotime('+2 years')) ?>" required>
        </div>

        <div class="form-group">
          <label class="form-label">Consumer MRP (₹) *</label>
          <input type="number" name="mrp_unit" class="form-control" value="45.00" step="0.5" required>
        </div>

        <div class="form-group">
          <label class="form-label">Distributor B2B Rate (₹) *</label>
          <input type="number" name="distributor_price" class="form-control" value="32.00" step="0.5" required>
        </div>
      </div>

      <div style="text-align: right;">
        <button type="submit" class="btn btn-primary">
          <?= icon('plus', 16) ?> Mint &amp; Broadcast Batch
        </button>
      </div>
    </form>
  </div>

  <!-- Batches Registry -->
  <div class="card">
    <div class="card-header">
      <div>
        <h3 class="card-title">All Registered Production Batches</h3>
        <p class="card-description">Live inventory remaining across entire distribution chain</p>
      </div>
      <span class="badge badge-neutral"><?= count($batches) ?> Registered Batches</span>
    </div>

    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr>
            <th>Batch Code</th>
            <th>Medicine Name</th>
            <th>Salt &amp; Strength</th>
            <th>Mfg Date</th>
            <th>Expiry Date</th>
            <th style="text-align: right;">Minted Units</th>
            <th style="text-align: right;">In Circulation</th>
            <th style="text-align: right;">MRP (₹)</th>
            <th>State</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($batches as $b): ?>
            <?php
              $now = time();
              $isExpired = (int)$b['is_expired'] === 1 || strtotime($b['expiry_date']) < $now;
              $isRecalled = (int)$b['is_recalled'] === 1;
            ?>
            <tr>
              <td><code style="font-family: var(--font-mono); font-size: 0.8rem;"><?= htmlspecialchars($b['batch_number']) ?></code></td>
              <td><strong><?= htmlspecialchars($b['brand_name']) ?></strong></td>
              <td><?= htmlspecialchars($b['salt_composition']) ?> <?= $b['strength_mg'] ?>mg (<?= $b['dosage_form'] ?>)</td>
              <td><?= date('d-M-Y', strtotime($b['mfg_date'])) ?></td>
              <td>
                <span style="<?= $isExpired ? 'color: var(--color-danger); font-weight: 700;' : '' ?>">
                  <?= date('d-M-Y', strtotime($b['expiry_date'])) ?>
                </span>
              </td>
              <td class="num-cell"><?= number_format($b['initial_quantity']) ?></td>
              <td class="num-cell"><?= number_format($b['current_quantity']) ?></td>
              <td class="num-cell">₹<?= number_format($b['mrp_unit'], 2) ?></td>
              <td>
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>

