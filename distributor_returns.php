<?php
$pageTitle = 'Inbound Returns & Quarantine Reception';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_role('distributor');

$pdo = Database::getConnection();
$message = null;

// Handle Receiving / Credit Settlement of Returns
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token()) {
        $message = ['type' => 'danger', 'text' => 'Security Error: CSRF token validation failed. Please refresh and try again.'];
    } else {
        $claimId = clean_int($_POST['claim_id'] ?? 0);
        $act = clean_string($_POST['action']);

        if ($act === 'accept_quarantine') {
            $stmt = $pdo->prepare("UPDATE return_claims SET status = 'received_quarantine' WHERE id = :id AND distributor_id = :uid");
            $stmt->execute([':id' => $claimId, ':uid' => $_SESSION['user_id']]);

            // Notify retailer using parameterized query
            $infoStmt = $pdo->prepare("SELECT claim_number, batch_id FROM return_claims WHERE id = :id AND distributor_id = :uid");
            $infoStmt->execute([':id' => $claimId, ':uid' => $_SESSION['user_id']]);
            $claimInfo = $infoStmt->fetch();

            if ($claimInfo) {
                $nStmt = $pdo->prepare("INSERT INTO notifications (target_role, title, message, type) VALUES ('retailer', '📦 Return Received in Quarantine', :msg, 'info')");
                $nStmt->execute([':msg' => "Nexus Healthcare Logistics acknowledged receipt of Return Manifest {$claimInfo['claim_number']} into Quarantine Storage Bay. Verification underway."]);
            }
            $message = ['type' => 'info', 'text' => 'Consignment received and logged into Quarantine Storage Bay for scheduled safe destruction.'];
        } elseif ($act === 'settle_credit') {
            $stmt = $pdo->prepare("UPDATE return_claims SET status = 'credit_settled' WHERE id = :id AND distributor_id = :uid");
            $stmt->execute([':id' => $claimId, ':uid' => $_SESSION['user_id']]);

            // Notify retailer using parameterized query
            $infoStmt = $pdo->prepare("SELECT claim_number, credit_amount FROM return_claims WHERE id = :id AND distributor_id = :uid");
            $infoStmt->execute([':id' => $claimId, ':uid' => $_SESSION['user_id']]);
            $claimInfo = $infoStmt->fetch();

            if ($claimInfo) {
                $nStmt = $pdo->prepare("INSERT INTO notifications (target_role, title, message, type) VALUES ('retailer', '💰 Reverse GST Credit Note Issued', :msg, 'success')");
                $nStmt->execute([':msg' => "Reverse GST Credit Note for Return Manifest {$claimInfo['claim_number']} (₹" . number_format($claimInfo['credit_amount'], 2) . ") has been issued and settled."]);
            }
            $message = ['type' => 'success', 'text' => 'Reverse GST Credit Note issued and settled with retailer.'];
        }
    }
}

// Fetch all inbound return claims from retailers
$claimStmt = $pdo->prepare("
    SELECT rc.*, b.batch_number, b.expiry_date, b.is_recalled, b.is_expired,
           m.brand_name, m.salt_composition, m.strength_mg, m.dosage_form,
           u.name as retailer_name, u.license_no as retailer_license
    FROM return_claims rc
    JOIN batches b ON rc.batch_id = b.id
    JOIN medicine_master m ON b.medicine_id = m.id
    JOIN users u ON rc.retailer_id = u.id
    WHERE rc.distributor_id = :uid
    ORDER BY rc.id DESC
");
$claimStmt->execute([':uid' => $_SESSION['user_id']]);
$receivedClaims = $claimStmt->fetchAll();

// Fetch batches in THIS distributor's warehouse nearing expiry (< 90 days)
$now = time();
$ninetyDays = time() + (90 * 86400);

$stmt = $pdo->prepare("
    SELECT b.*, m.brand_name, m.generic_name, m.salt_composition, m.strength_mg, m.dosage_form, inv.quantity as warehouse_stock
    FROM inventory inv
    JOIN batches b ON inv.batch_id = b.id
    JOIN medicine_master m ON b.medicine_id = m.id
    WHERE inv.user_id = :uid AND inv.quantity > 0
    ORDER BY b.expiry_date ASC
");
$stmt->execute([':uid' => $_SESSION['user_id']]);
$allBatches = $stmt->fetchAll();

$nearExpiryBatches = [];
foreach ($allBatches as $b) {
    $expTime = strtotime($b['expiry_date']);
    if ($expTime < $ninetyDays) {
        $b['is_past_expiry'] = $expTime < $now;
        $nearExpiryBatches[] = $b;
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
      <h2 style="font-size: 1.4rem; font-weight: 700;">Inbound Returns &amp; Reverse Supply Chain Quarantine</h2>
      <p style="font-size: 0.825rem; color: var(--text-subtle);">
        Nexus Healthcare Logistics &bull; Receive expired and blacklisted stock returned from retail pharmacies into secure quarantine bays.
      </p>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?= $message['type'] ?>">
      <?= icon('check', 16) ?>
      <div><?= htmlspecialchars($message['text']) ?></div>
    </div>
  <?php endif; ?>

  <!-- Inbound Returns Register from Retailers -->
  <div class="card" style="margin-bottom: 2rem;">
    <div class="card-header">
      <div>
        <h3 class="card-title">Inbound Return Shipments from Retail Pharmacies</h3>
        <p class="card-description">Acknowledge receiving expired stock, move to quarantine, and issue credit settlements</p>
      </div>
      <span class="badge badge-primary"><?= count($receivedClaims) ?> Active Return Manifests</span>
    </div>

    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr>
            <th>Manifest No</th>
            <th>Originating Retailer</th>
            <th>Medicine Formulation</th>
            <th>Batch Code</th>
            <th>Expiry Date</th>
            <th style="text-align: right;">Returned Qty</th>
            <th style="text-align: right;">Credit Value (₹)</th>
            <th>Return Justification</th>
            <th>Quarantine State</th>
            <th style="text-align: right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($receivedClaims)): ?>
            <tr>
              <td colspan="10" style="text-align: center; color: var(--text-subtle); padding: 2.5rem 1rem;">
                No return claims currently dispatched from retailers.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($receivedClaims as $rc): ?>
              <tr>
                <td><code style="font-family: var(--font-mono); font-size: 0.8rem;"><?= htmlspecialchars($rc['claim_number']) ?></code></td>
                <td>
                  <strong><?= htmlspecialchars($rc['retailer_name']) ?></strong>
                  <div style="font-size: 0.72rem; color: var(--text-subtle);"><?= htmlspecialchars($rc['retailer_license']) ?></div>
                </td>
                <td>
                  <strong><?= htmlspecialchars($rc['brand_name']) ?></strong>
                  <div style="font-size: 0.72rem; color: var(--text-subtle);"><?= htmlspecialchars($rc['salt_composition']) ?> <?= $rc['strength_mg'] ?>mg</div>
                </td>
                <td><code style="font-family: var(--font-mono); font-size: 0.8rem;"><?= htmlspecialchars($rc['batch_number']) ?></code></td>
                <td><?= date('d-M-Y', strtotime($rc['expiry_date'])) ?></td>
                <td class="num-cell" style="font-weight: 700; color: var(--color-danger);"><?= number_format($rc['quantity']) ?> units</td>
                <td class="num-cell" style="font-weight: 700; color: var(--color-primary-dark);">₹<?= number_format($rc['credit_amount'], 2) ?></td>
                <td><span style="font-size: 0.8rem; color: var(--text-subtle);"><?= htmlspecialchars($rc['reason']) ?></span></td>
                <td>
                  <?php if ($rc['status'] === 'credit_settled'): ?>
                    <span class="badge badge-success"><?= icon('check', 12) ?> Credit Settled</span>
                  <?php elseif ($rc['status'] === 'received_quarantine'): ?>
                    <span class="badge badge-primary"><?= icon('shield-check', 12) ?> In Quarantine Vault</span>
                  <?php else: ?>
                    <span class="badge badge-warning"><?= icon('truck', 12) ?> Dispatched (In Transit)</span>
                  <?php endif; ?>
                </td>
                <td style="text-align: right;">
                  <?php if ($rc['status'] === 'dispatched'): ?>
                    <form method="POST" action="distributor_returns.php" style="display: inline;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="accept_quarantine">
                      <input type="hidden" name="claim_id" value="<?= $rc['id'] ?>">
                      <button type="submit" class="btn btn-primary btn-sm">
                        <?= icon('check', 12) ?> Receive in Quarantine
                      </button>
                    </form>
                  <?php elseif ($rc['status'] === 'received_quarantine'): ?>
                    <form method="POST" action="distributor_returns.php" style="display: inline;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="settle_credit">
                      <input type="hidden" name="claim_id" value="<?= $rc['id'] ?>">
                      <button type="submit" class="btn btn-success btn-sm">
                        <?= icon('file-text', 12) ?> Issue Credit Note
                      </button>
                    </form>
                  <?php else: ?>
                    <span style="font-size: 0.75rem; color: var(--color-success); font-weight: 600;">Settled &amp; Archived</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Near-Expiry Surveillance List -->
  <div class="card">
    <div class="card-header">
      <div>
        <h3 class="card-title">Early Warning Surveillance: Batches &lt; 90 Days Shelf-Life</h3>
        <p class="card-description">Proactively halt outbound fulfillment and arrange reverse return logistics</p>
      </div>
      <span class="badge badge-warning"><?= count($nearExpiryBatches) ?> Lots Near Expiry</span>
    </div>

    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr>
            <th>Batch Code</th>
            <th>Medicine Name</th>
            <th>Active Composition</th>
            <th>Expiry Date</th>
            <th style="text-align: right;">Remaining Units</th>
            <th>State</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($nearExpiryBatches)): ?>
            <tr>
              <td colspan="6" style="text-align: center; color: var(--text-subtle); padding: 1.5rem;">No warehouse batches within 90 days of expiry.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($nearExpiryBatches as $b): ?>
              <tr class="<?= $b['is_past_expiry'] ? 'unsafe-row' : '' ?>">
                <td><code style="font-family: var(--font-mono); font-size: 0.8rem;"><?= htmlspecialchars($b['batch_number']) ?></code></td>
                <td><strong><?= htmlspecialchars($b['brand_name']) ?></strong></td>
                <td><?= htmlspecialchars($b['salt_composition']) ?></td>
                <td>
                  <span style="<?= $b['is_past_expiry'] ? 'color: var(--color-danger); font-weight: 700;' : 'color: var(--color-warning); font-weight: 600;' ?>">
                    <?= date('d-M-Y', strtotime($b['expiry_date'])) ?>
                  </span>
                </td>
                <td class="num-cell"><?= number_format($b['current_quantity']) ?> units</td>
                <td>
                  <?php if ($b['is_past_expiry']): ?>
                    <span class="badge badge-danger"><?= icon('alert-triangle', 12) ?> Past Expiry</span>
                  <?php else: ?>
                    <span class="badge badge-warning"><?= icon('clock', 12) ?> Near Expiry (&lt; 90 Days)</span>
                  <?php endif; ?>
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
