<?php
$pageTitle = 'Expired & Recalled Stock Return Channel';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_role('retailer');

$pdo = Database::getConnection();
$retailerId = $_SESSION['user_id'];
$message = null;

// Handle Return Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_return') {
    if (!verify_csrf_token()) {
        $message = ['type' => 'danger', 'text' => 'Security Error: CSRF token validation failed. Please refresh and try again.'];
    } else {
        $invId = clean_int($_POST['inventory_id'] ?? 0);
        $returnQty = clean_int($_POST['quantity'] ?? 0);
        $reason = clean_string($_POST['reason'] ?? '');

    if ($invId && $reason) {
        try {
            $pdo->beginTransaction();

            // Fetch inventory record
            $invStmt = $pdo->prepare("
                SELECT inv.*, b.id as batch_id, b.batch_number, b.distributor_price, b.is_recalled, b.is_expired, b.expiry_date,
                       m.brand_name
                FROM inventory inv
                JOIN batches b ON inv.batch_id = b.id
                JOIN medicine_master m ON b.medicine_id = m.id
                WHERE inv.id = :id AND inv.user_id = :uid
            ");
            $invStmt->execute([':id' => $invId, ':uid' => $retailerId]);
            $inv = $invStmt->fetch();

            if (!$inv) {
                throw new Exception("Inventory lot not found.");
            }

            // 1. Check if lot has already been zeroed out
            if ((int)$inv['quantity'] <= 0) {
                throw new Exception("Batch {$inv['batch_number']} ({$inv['brand_name']}) has already been completely removed from shelf (0 units remaining). Duplicate return submission is strictly blocked.");
            }

            // 2. Anti-Duplicate Return Check: Check if an active return claim exists for this batch
            $activeClaimCheck = $pdo->prepare("
                SELECT id, claim_number, status, quantity FROM return_claims 
                WHERE retailer_id = :rid AND batch_id = :bid AND status IN ('dispatched', 'received_quarantine')
            ");
            $activeClaimCheck->execute([':rid' => $retailerId, ':bid' => $inv['batch_id']]);
            $existingActiveClaim = $activeClaimCheck->fetch();

            if ($existingActiveClaim) {
                $stName = $existingActiveClaim['status'] === 'dispatched' ? 'In Reverse Transit' : 'In Quarantine Vault';
                throw new Exception("Anti-Duplicate Security Lock: Batch {$inv['batch_number']} is ALREADY recorded in active Return Manifest {$existingActiveClaim['claim_number']} (Status: {$stName}). You cannot submit a duplicate return for this batch.");
            }

            $now = time();
            $isExpired = (int)$inv['is_expired'] === 1 || strtotime($inv['expiry_date']) < $now;
            $isRecalled = (int)$inv['is_recalled'] === 1;

            // For regulatory compliance (CDSCO Sec 18), expired or recalled lots must be returned in full
            if ($isExpired || $isRecalled) {
                $returnQty = (int)$inv['quantity'];
            } elseif ($returnQty <= 0 || $returnQty > (int)$inv['quantity']) {
                throw new Exception("Cannot return more units than currently held on shelf ({$inv['quantity']} units).");
            }

            $creditAmt = round($returnQty * (float)$inv['distributor_price'], 2);
            $claimNo = 'RET-MH-' . date('Y') . '-' . rand(3000, 9999);

            // Insert into return_claims
            $claimStmt = $pdo->prepare("
                INSERT INTO return_claims (claim_number, retailer_id, distributor_id, batch_id, quantity, reason, credit_amount, status)
                VALUES (:cno, :rid, 2, :bid, :qty, :reason, :credit, 'dispatched')
            ");
            $claimStmt->execute([
                ':cno' => $claimNo,
                ':rid' => $retailerId,
                ':bid' => $inv['batch_id'],
                ':qty' => $returnQty,
                ':reason' => $reason,
                ':credit' => $creditAmt
            ]);

            // Deduct stock from retailer shelf: set quantity to 0 for full lot returns
            $deductStmt = $pdo->prepare("UPDATE inventory SET quantity = quantity - :qty WHERE id = :id AND user_id = :uid");
            $deductStmt->execute([':qty' => $returnQty, ':id' => $invId, ':uid' => $retailerId]);

            // Broadcast notification to distributor
            $nStmt = $pdo->prepare("
                INSERT INTO notifications (target_role, title, message, type)
                VALUES ('distributor', '📦 Inbound Stock Return Dispatched', :msg, 'warning')
            ");
            $nStmt->execute([
                ':msg' => "CareFirst Pharmacy has initiated reverse return {$claimNo} for {$returnQty} units of {$inv['brand_name']} (Batch {$inv['batch_number']}). Reason: {$reason}."
            ]);

            $pdo->commit();
            $message = ['type' => 'success', 'text' => "Return claim {$claimNo} successfully dispatched to Nexus Healthcare Logistics! Batch {$inv['batch_number']} cleared from shelf and locked from duplicate returns."];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = ['type' => 'danger', 'text' => "Error processing return: " . $e->getMessage()];
        }
    }
    } // End CSRF check
}

// Fetch all shelf inventory available for return, STRICTLY EXCLUDING batches with active/pending returns
$shelfStmt = $pdo->prepare("
    SELECT inv.*, b.id as batch_id, b.batch_number, b.expiry_date, b.is_recalled, b.recall_reason, b.is_expired, b.distributor_price,
           m.brand_name, m.salt_composition, m.strength_mg, m.dosage_form
    FROM inventory inv
    JOIN batches b ON inv.batch_id = b.id
    JOIN medicine_master m ON b.medicine_id = m.id
    WHERE inv.user_id = :uid 
      AND inv.quantity > 0
      AND b.id NOT IN (
          SELECT batch_id FROM return_claims 
          WHERE retailer_id = :uid AND status IN ('dispatched', 'received_quarantine')
      )
    ORDER BY b.is_expired DESC, b.is_recalled DESC, b.expiry_date ASC
");
$shelfStmt->execute([':uid' => $retailerId]);
$shelfItems = $shelfStmt->fetchAll();

// Fetch past return claims
$claimStmt = $pdo->prepare("
    SELECT rc.*, b.batch_number, b.expiry_date, m.brand_name, m.salt_composition, u.name as distributor_name
    FROM return_claims rc
    JOIN batches b ON rc.batch_id = b.id
    JOIN medicine_master m ON b.medicine_id = m.id
    JOIN users u ON rc.distributor_id = u.id
    WHERE rc.retailer_id = :uid
    ORDER BY rc.id DESC
");
$claimStmt->execute([':uid' => $retailerId]);
$claims = $claimStmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
  <div style="margin-bottom: 1.5rem;">
    <h2 style="font-size: 1.4rem; font-weight: 700;">Expired &amp; Recalled Stock Return Channel</h2>
    <p style="font-size: 0.825rem; color: var(--text-subtle);">
      Reverse pharmaceutical logistics: Return date-expired batches and quarantined recall lots back to Nexus Logistics for distributor credit settlement.
    </p>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?= $message['type'] ?>">
      <?= icon('check', 16) ?>
      <div><?= htmlspecialchars($message['text']) ?></div>
    </div>
  <?php endif; ?>

  <!-- Initiation Form -->
  <div class="card" style="margin-bottom: 2rem;">
    <div class="card-header">
      <div>
        <h3 class="card-title">Initiate Reverse Supply Return Consignment</h3>
        <p class="card-description">Select stock from pharmacy shelves to dispatch back to the distributor</p>
      </div>
      <span class="badge badge-warning"><?= icon('truck', 12) ?> Reverse Logistics</span>
    </div>

    <?php if (empty($shelfItems)): ?>
      <div class="alert alert-success" style="margin-bottom: 0;">
        <?= icon('shield-check', 18) ?>
        <div>
          <strong>Zero Quarantined Stock Remaining:</strong> All expired and recalled batches have been successfully dispatched back to the distributor. No hazardous or invalid medicines remain on your pharmacy shelves.
        </div>
      </div>
    <?php else: ?>
      <form method="POST" action="retailer_returns.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_return">

        <div class="grid-3">
          <div class="form-group">
            <label class="form-label">Select Shelf Stock Lot *</label>
            <select name="inventory_id" class="form-control" required onchange="handleStockSelection(this)">
              <option value="">-- Choose shelf lot to return --</option>
              <?php foreach ($shelfItems as $si): ?>
                <?php
                  $now = time();
                  $isExp = (int)$si['is_expired'] === 1 || strtotime($si['expiry_date']) < $now;
                  $isRec = (int)$si['is_recalled'] === 1;
                  $tag = $isExp ? '[EXPIRED] ' : ($isRec ? '[RECALLED] ' : '');
                ?>
                <option value="<?= $si['id'] ?>" data-max="<?= $si['quantity'] ?>" data-price="<?= $si['distributor_price'] ?>" data-locked="<?= ($isExp || $isRec) ? '1' : '0' ?>" data-reason="<?= $isExp ? 'Expired Shelf Removal' : ($isRec ? 'Manufacturer Quality Recall Return' : 'Surplus / Near-Expiry Removal') ?>">
                  <?= $tag ?><?= htmlspecialchars($si['brand_name']) ?> (Batch: <?= htmlspecialchars($si['batch_number']) ?>) &bull; <?= $si['quantity'] ?> units on shelf
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Return Quantity (Units) *</label>
            <input type="number" id="return-qty" name="quantity" class="form-control" value="10" min="1" required>
            <small id="qty-help" style="font-size: 0.72rem; color: var(--text-subtle); display: block; margin-top: 0.25rem;">Full lot removal enforced for expired / recalled drugs.</small>
          </div>

          <div class="form-group">
            <label class="form-label">Return Reason / Justification *</label>
            <input type="text" id="return-reason" name="reason" class="form-control" value="Expired Batch Shelf Removal" required>
          </div>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border-light); padding-top: 1rem; flex-wrap: wrap; gap: 0.75rem;">
          <div style="font-size: 0.8rem; color: var(--text-subtle);">
            Consignment recipient: <strong>Nexus Healthcare Logistics Pvt. Ltd. (Warehouse Complex B4, Bhiwandi)</strong>
          </div>
          <button type="submit" class="btn btn-danger">
            <?= icon('truck', 16) ?> Dispatch Return Consignment to Distributor
          </button>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <!-- Dispatched Returns Register -->
  <div class="card">
    <div class="card-header">
      <div>
        <h3 class="card-title">Dispatched Return Manifests &amp; Credit Ledger</h3>
        <p class="card-description">Track reverse transit, distributor quarantine receipts, and credit notes</p>
      </div>
      <span class="badge badge-neutral"><?= count($claims) ?> Return Manifests</span>
    </div>

    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr>
            <th>Manifest No</th>
            <th>Medicine Formulation</th>
            <th>Batch Code</th>
            <th>Expiry Date</th>
            <th style="text-align: right;">Returned Units</th>
            <th style="text-align: right;">Credit Value (₹)</th>
            <th>Reason</th>
            <th>Distributor Status</th>
            <th>Dispatched Date</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($claims)): ?>
            <tr>
              <td colspan="9" style="text-align: center; color: var(--text-subtle); padding: 2rem;">No reverse returns logged yet.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($claims as $c): ?>
              <tr>
                <td><code style="font-family: var(--font-mono); font-size: 0.8rem;"><?= htmlspecialchars($c['claim_number']) ?></code></td>
                <td><strong><?= htmlspecialchars($c['brand_name']) ?></strong></td>
                <td><code style="font-family: var(--font-mono); font-size: 0.8rem;"><?= htmlspecialchars($c['batch_number']) ?></code></td>
                <td><?= date('d-M-Y', strtotime($c['expiry_date'])) ?></td>
                <td class="num-cell"><?= number_format($c['quantity']) ?> units</td>
                <td class="num-cell" style="font-weight: 700; color: var(--color-primary-dark);">₹<?= number_format($c['credit_amount'], 2) ?></td>
                <td><span style="font-size: 0.8rem; color: var(--text-subtle);"><?= htmlspecialchars($c['reason']) ?></span></td>
                <td>
                  <?php if ($c['status'] === 'credit_settled'): ?>
                    <span class="badge badge-success"><?= icon('check', 12) ?> Credit Settled</span>
                  <?php elseif ($c['status'] === 'received_quarantine'): ?>
                    <span class="badge badge-primary"><?= icon('shield-check', 12) ?> In Quarantine Bay</span>
                  <?php else: ?>
                    <span class="badge badge-warning"><?= icon('truck', 12) ?> In Reverse Transit</span>
                  <?php endif; ?>
                </td>
                <td style="font-size: 0.775rem; color: var(--text-subtle);"><?= date('d-M-Y', strtotime($c['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function handleStockSelection(select) {
  const opt = select.options[select.selectedIndex];
  if (!opt || !opt.value) return;
  const max = opt.getAttribute('data-max') || 10;
  const reason = opt.getAttribute('data-reason') || 'Stock Removal';
  const isLocked = opt.getAttribute('data-locked') === '1';

  const qtyInput = document.getElementById('return-qty');
  const helpText = document.getElementById('qty-help');

  qtyInput.value = max;
  qtyInput.max = max;
  document.getElementById('return-reason').value = reason;

  if (isLocked) {
    qtyInput.readOnly = true;
    qtyInput.style.backgroundColor = '#F1F5F9';
    if (helpText) helpText.innerText = 'CDSCO Mandate: All ' + max + ' units of this unsafe/expired lot must be completely removed from shelf.';
  } else {
    qtyInput.readOnly = false;
    qtyInput.style.backgroundColor = '';
    if (helpText) helpText.innerText = 'Enter quantity up to maximum shelf hold (' + max + ' units).';
  }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

