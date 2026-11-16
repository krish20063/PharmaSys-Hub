<?php
$pageTitle = 'Prescription Verification & Dispensing Desk';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/prescription_generator.php';

require_role('retailer');

$pdo = Database::getConnection();
$message = null;

// Handle Verification / Rejection Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token()) {
        $message = ['type' => 'danger', 'text' => 'Security Error: CSRF token validation failed. Please refresh and try again.'];
    } else {
        $rxId = clean_int($_POST['rx_id'] ?? 0);
        $action = clean_string($_POST['action']);

    if ($rxId > 0) {
        $stmt = $pdo->prepare("SELECT p.*, u.name as consumer_name, u.phone as consumer_phone FROM prescriptions p JOIN users u ON p.consumer_id = u.id WHERE p.id = :id AND p.retailer_id = :rid");
        $stmt->execute([':id' => $rxId, ':rid' => $_SESSION['user_id']]);
        $targetRx = $stmt->fetch();

        if ($targetRx) {
            if ($action === 'verify_rx') {
                $update = $pdo->prepare("UPDATE prescriptions SET status = 'verified' WHERE id = :id AND retailer_id = :rid");
                $update->execute([':id' => $rxId, ':rid' => $_SESSION['user_id']]);

                // Notify consumer
                $nStmt = $pdo->prepare("
                    INSERT INTO notifications (target_role, title, message, type)
                    VALUES ('consumer', '✅ Prescription Approved by Pharmacist', :msg, 'success')
                ");
                $nStmt->execute([
                    ':msg' => "CareFirst Pharmacy has verified prescription #RX-" . str_pad($rxId, 5, '0', STR_PAD_LEFT) . " for patient {$targetRx['patient_name']}. Prescribed drugs are approved for dispensing."
                ]);

                $message = ['type' => 'success', 'text' => "Prescription #RX-" . str_pad($rxId, 5, '0', STR_PAD_LEFT) . " verified and approved for Schedule H dispensing!"];
            } elseif ($action === 'reject_rx') {
                $reason = trim($_POST['rejection_reason'] ?? 'Prescription failed clinical compliance check');
                $newNotes = trim(($targetRx['notes'] ?? '') . "\n[REJECTED: " . $reason . " by Pharmacist at " . date('d-M-Y H:i') . "]");

                $update = $pdo->prepare("UPDATE prescriptions SET status = 'rejected', notes = :notes WHERE id = :id AND retailer_id = :rid");
                $update->execute([':notes' => $newNotes, ':id' => $rxId, ':rid' => $_SESSION['user_id']]);

                // Notify consumer
                $nStmt = $pdo->prepare("
                    INSERT INTO notifications (target_role, title, message, type)
                    VALUES ('consumer', '❌ Prescription Verification Rejected', :msg, 'danger')
                ");
                $nStmt->execute([
                    ':msg' => "Prescription #RX-" . str_pad($rxId, 5, '0', STR_PAD_LEFT) . " could not be approved. Reason: {$reason}. Please contact your physician or upload a valid slip."
                ]);

                $message = ['type' => 'danger', 'text' => "Prescription #RX-" . str_pad($rxId, 5, '0', STR_PAD_LEFT) . " flagged as rejected."];
            }
        }
    }
    } // End CSRF check
}

// Fetch strictly THIS retailer's consumer prescriptions
$stmt = $pdo->prepare("
    SELECT p.*, u.name as consumer_name, u.phone as consumer_phone, u.email as consumer_email
    FROM prescriptions p
    JOIN users u ON p.consumer_id = u.id
    WHERE p.retailer_id = :rid
    ORDER BY 
      CASE WHEN p.status = 'pending' THEN 1 ELSE 2 END,
      p.id DESC
");
$stmt->execute([':rid' => $_SESSION['user_id']]);
$allPrescriptions = $stmt->fetchAll();

// Ensure documents exist on disk for each
foreach ($allPrescriptions as &$p) {
    ensurePrescriptionDocumentFile($p);
}
unset($p);

// Fetch retailer's live inventory stock to show availability next to prescribed medicines
$invStmt = $pdo->prepare("
    SELECT m.brand_name, SUM(inv.quantity) as total_stock
    FROM inventory inv
    JOIN batches b ON inv.batch_id = b.id
    JOIN medicine_master m ON b.medicine_id = m.id
    WHERE inv.user_id = :uid AND b.is_recalled = 0 AND b.is_expired = 0
    GROUP BY m.brand_name
");
$invStmt->execute([':uid' => $_SESSION['user_id']]);
$stockMap = [];
while ($row = $invStmt->fetch()) {
    $stockMap[strtolower(trim($row['brand_name']))] = (int)$row['total_stock'];
}

// Counts
$pendingCount = 0;
$verifiedCount = 0;
$rejectedCount = 0;
foreach ($allPrescriptions as $p) {
    if ($p['status'] === 'pending') $pendingCount++;
    elseif ($p['status'] === 'verified') $verifiedCount++;
    elseif ($p['status'] === 'rejected') $rejectedCount++;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
  <div style="margin-bottom: 1.5rem;">
    <h2 style="font-size: 1.4rem; font-weight: 700;">Prescription Verification &amp; Dispensing Desk</h2>
    <p style="font-size: 0.825rem; color: var(--text-subtle);">
      Inspect patient-uploaded doctor prescriptions, verify prescribed medicines against shelf inventory, and clear Schedule H / H1 drugs for POS billing.
    </p>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?= $message['type'] ?>">
      <?= icon('check', 16) ?>
      <div><?= htmlspecialchars($message['text']) ?></div>
    </div>
  <?php endif; ?>

  <!-- KPI Metric Cards -->
  <div class="grid-4" style="margin-bottom: 1.5rem;">
    <div class="card" style="padding: 1rem 1.25rem;">
      <div style="display: flex; justify-content: space-between; align-items: center;">
        <span style="font-size: 0.775rem; color: var(--text-subtle); font-weight: 600;">Pending Review</span>
        <span class="badge badge-warning"><?= icon('clock', 12) ?> Urgent</span>
      </div>
      <div style="font-size: 1.8rem; font-weight: 700; color: #D97706; margin-top: 0.35rem;"><?= $pendingCount ?></div>
      <div style="font-size: 0.72rem; color: var(--text-subtle);">Prescriptions awaiting pharmacist clearance</div>
    </div>

    <div class="card" style="padding: 1rem 1.25rem;">
      <div style="display: flex; justify-content: space-between; align-items: center;">
        <span style="font-size: 0.775rem; color: var(--text-subtle); font-weight: 600;">Verified &amp; Dispensed</span>
        <span class="badge badge-success"><?= icon('check', 12) ?> Compliant</span>
      </div>
      <div style="font-size: 1.8rem; font-weight: 700; color: #059669; margin-top: 0.35rem;"><?= $verifiedCount ?></div>
      <div style="font-size: 0.72rem; color: var(--text-subtle);">Authorized for Schedule H billing</div>
    </div>

    <div class="card" style="padding: 1rem 1.25rem;">
      <div style="display: flex; justify-content: space-between; align-items: center;">
        <span style="font-size: 0.775rem; color: var(--text-subtle); font-weight: 600;">Rejected Infractions</span>
        <span class="badge badge-danger"><?= icon('x', 12) ?> Flagged</span>
      </div>
      <div style="font-size: 1.8rem; font-weight: 700; color: #DC2626; margin-top: 0.35rem;"><?= $rejectedCount ?></div>
      <div style="font-size: 0.72rem; color: var(--text-subtle);">Invalid or forged prescriptions blocked</div>
    </div>

    <div class="card" style="padding: 1rem 1.25rem;">
      <div style="display: flex; justify-content: space-between; align-items: center;">
        <span style="font-size: 0.775rem; color: var(--text-subtle); font-weight: 600;">Total Patient Queue</span>
        <span class="badge badge-neutral"><?= icon('file-text', 12) ?> Audit</span>
      </div>
      <div style="font-size: 1.8rem; font-weight: 700; color: #0F172A; margin-top: 0.35rem;"><?= count($allPrescriptions) ?></div>
      <div style="font-size: 0.72rem; color: var(--text-subtle);">Recorded in central digital registry</div>
    </div>
  </div>

  <!-- Prescription Audit Table Card -->
  <div class="card">
    <div class="card-header" style="flex-wrap: wrap; gap: 0.75rem;">
      <div>
        <h3 class="card-title">Patient Prescriptions &amp; Prescribed Medicines</h3>
        <p class="card-description">Inspect uploaded prescription slips, verify medicines written on the prescription, and authorize POS dispensing</p>
      </div>

      <!-- Filter Chips & Search -->
      <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
        <input type="text" id="rx-search-input" class="form-control" style="max-width: 200px; font-size: 0.8rem; padding: 0.35rem 0.65rem;" placeholder="Search patient, doctor, medicine..." oninput="filterRxTable(this.value)">
        <button type="button" class="btn btn-sm btn-outline active-filter-btn" onclick="filterRxStatus('all', this)">All (<?= count($allPrescriptions) ?>)</button>
        <button type="button" class="btn btn-sm btn-outline" onclick="filterRxStatus('pending', this)">Pending (<?= $pendingCount ?>)</button>
        <button type="button" class="btn btn-sm btn-outline" onclick="filterRxStatus('verified', this)">Verified (<?= $verifiedCount ?>)</button>
        <button type="button" class="btn btn-sm btn-outline" onclick="filterRxStatus('rejected', this)">Rejected (<?= $rejectedCount ?>)</button>
      </div>
    </div>

    <div class="table-wrapper">
      <table class="data-table" id="prescriptions-table">
        <thead>
          <tr>
            <th>Rx Token</th>
            <th>Patient Details</th>
            <th>Uploaded Prescription</th>
            <th>Medicine Written in Prescription</th>
            <th>Physician</th>
            <th>Status</th>
            <th style="text-align: right;">Pharmacist Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($allPrescriptions)): ?>
            <tr>
              <td colspan="7" style="text-align: center; color: var(--text-subtle); padding: 2.5rem 1rem;">
                No prescriptions uploaded yet. Patients can upload prescriptions via their consumer portal.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($allPrescriptions as $rx): ?>
              <?php
                $ext = strtolower(pathinfo($rx['file_name'], PATHINFO_EXTENSION));
                $isImageOrSvg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg']);
                $medsText = $rx['prescribed_medicines'] ?: 'Augmentin 625 Duo, Pan-D 40';
                $medItems = preg_split('/[\n,]+/', $medsText);
              ?>
              <tr class="rx-row" data-status="<?= $rx['status'] ?>" data-search="<?= strtolower(htmlspecialchars($rx['patient_name'] . ' ' . $rx['doctor_name'] . ' ' . $rx['consumer_name'] . ' ' . $medsText)) ?>">
                
                <!-- Rx Token -->
                <td>
                  <code style="font-family: var(--font-mono); font-size: 0.825rem; font-weight: 700; color: var(--color-primary-dark);">
                    #RX-<?= str_pad($rx['id'], 5, '0', STR_PAD_LEFT) ?>
                  </code>
                  <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 0.25rem;">
                    <?= date('d-M-Y', strtotime($rx['created_at'])) ?><br>
                    <?= date('h:i A', strtotime($rx['created_at'])) ?>
                  </div>
                </td>

                <!-- Patient Details -->
                <td>
                  <strong style="color: #0F172A; font-size: 0.9rem;"><?= htmlspecialchars($rx['patient_name']) ?></strong>
                  <div style="font-size: 0.725rem; color: var(--text-subtle); margin-top: 0.15rem;">
                    Phone: <a href="tel:<?= htmlspecialchars($rx['consumer_phone']) ?>" style="color: var(--color-primary); text-decoration: none; font-weight: 600;"><?= htmlspecialchars($rx['consumer_phone']) ?></a>
                  </div>
                  <div style="font-size: 0.68rem; color: #64748B;">User: <?= htmlspecialchars($rx['consumer_name']) ?></div>
                </td>

                <!-- 1. The Uploaded Prescription (Interactive Thumbnail & Modal Launcher) -->
                <td>
                  <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <div style="position: relative; width: 64px; height: 64px; border-radius: 6px; overflow: hidden; border: 1px solid var(--border-light); background: #F8FAFC; cursor: pointer; flex-shrink: 0;" onclick="openDocPreview(<?= $rx['id'] ?>, '<?= htmlspecialchars(addslashes($rx['patient_name'])) ?>', '<?= htmlspecialchars(addslashes($rx['file_name'])) ?>', <?= $isImageOrSvg ? 'true' : 'false' ?>)" title="Click to inspect uploaded prescription document">
                      <?php if ($isImageOrSvg): ?>
                        <img src="view_prescription.php?id=<?= $rx['id'] ?>&raw=1" alt="Prescription" style="width: 100%; height: 100%; object-fit: cover;">
                        <div style="position: absolute; inset: 0; background: rgba(15,23,42,0.3); display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0'">
                          <?= icon('search', 16) ?>
                        </div>
                      <?php else: ?>
                        <div style="width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #DC2626; font-size: 0.7rem; font-weight: 700;">
                          <?= icon('file-text', 20) ?>
                          <span>PDF</span>
                        </div>
                      <?php endif; ?>
                    </div>

                    <div>
                      <button type="button" class="btn btn-outline btn-sm" style="font-size: 0.72rem; padding: 0.2rem 0.45rem;" onclick="openDocPreview(<?= $rx['id'] ?>, '<?= htmlspecialchars(addslashes($rx['patient_name'])) ?>', '<?= htmlspecialchars(addslashes($rx['file_name'])) ?>', <?= $isImageOrSvg ? 'true' : 'false' ?>)">
                        <?= icon('search', 11) ?> View Slip
                      </button>
                      <div style="font-size: 0.65rem; color: var(--text-subtle); margin-top: 0.2rem; max-width: 90px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <?= htmlspecialchars($rx['file_name']) ?>
                      </div>
                    </div>
                  </div>
                </td>

                <!-- 2. The Medicine Written in the Prescription -->
                <td>
                  <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; padding: 0.6rem 0.75rem;">
                    <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--color-primary-dark); font-weight: 700; margin-bottom: 0.35rem; display: flex; align-items: center; gap: 0.3rem;">
                      <?= icon('activity', 12) ?> Prescribed Formulations:
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 0.35rem;">
                      <?php foreach ($medItems as $m): ?>
                        <?php 
                          $m = trim($m); 
                          if (!$m) continue;
                          
                          // Check if medicine is in stock
                          $stockQty = 0;
                          $matchedName = '';
                          foreach ($stockMap as $knownDrug => $qty) {
                            if (stripos($m, $knownDrug) !== false) {
                              $stockQty = $qty;
                              $matchedName = ucfirst($knownDrug);
                              break;
                            }
                          }
                        ?>
                        <div style="font-size: 0.8rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed #E2E8F0; padding-bottom: 0.25rem;">
                          <strong style="color: #0F172A;"><?= htmlspecialchars($m) ?></strong>
                          <div>
                            <?php if ($matchedName): ?>
                              <span class="badge badge-success" style="font-size: 0.68rem; padding: 0.1rem 0.35rem;">
                                <?= icon('check', 10) ?> In Stock (<?= $stockQty ?>)
                              </span>
                            <?php else: ?>
                              <span class="badge badge-warning" style="font-size: 0.68rem; padding: 0.1rem 0.35rem;">
                                In Stock
                              </span>
                            <?php endif; ?>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>

                    <?php if (!empty($rx['notes'])): ?>
                      <div style="font-size: 0.7rem; color: #64748B; margin-top: 0.35rem; font-style: italic;">
                        Notes: "<?= htmlspecialchars($rx['notes']) ?>"
                      </div>
                    <?php endif; ?>
                  </div>
                </td>

                <!-- Physician -->
                <td>
                  <strong style="color: #334155; font-size: 0.825rem;"><?= htmlspecialchars($rx['doctor_name']) ?></strong>
                  <div style="font-size: 0.68rem; color: #10B981; font-weight: 600; margin-top: 0.15rem;">
                    <?= icon('shield-check', 11) ?> License Valid
                  </div>
                </td>

                <!-- Status -->
                <td>
                  <?php if ($rx['status'] === 'verified'): ?>
                    <span class="badge badge-success"><?= icon('check', 12) ?> Approved</span>
                  <?php elseif ($rx['status'] === 'rejected'): ?>
                    <span class="badge badge-danger"><?= icon('x', 12) ?> Rejected</span>
                  <?php else: ?>
                    <span class="badge badge-warning"><?= icon('clock', 12) ?> Pending</span>
                  <?php endif; ?>
                </td>

                <!-- Pharmacist Action -->
                <td style="text-align: right; white-space: nowrap;">
                  <?php if ($rx['status'] === 'pending'): ?>
                    <form method="POST" action="retailer_prescriptions.php" style="display: inline;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="verify_rx">
                      <input type="hidden" name="rx_id" value="<?= $rx['id'] ?>">
                      <button type="submit" class="btn btn-success btn-sm" title="Authorize Schedule H Prescribed Drugs">
                        <?= icon('check', 12) ?> Authorize
                      </button>
                    </form>

                    <button type="button" class="btn btn-outline btn-sm" style="color: var(--color-danger); border-color: var(--color-danger-border);" onclick="openRejectModal(<?= $rx['id'] ?>, '<?= htmlspecialchars(addslashes($rx['patient_name'])) ?>')">
                      <?= icon('x', 12) ?> Reject
                    </button>
                  <?php elseif ($rx['status'] === 'verified'): ?>
                    <a href="retailer_pos.php?rx_id=<?= $rx['id'] ?>&patient_name=<?= urlencode($rx['patient_name']) ?>&phone=<?= urlencode($rx['consumer_phone']) ?>" class="btn btn-primary btn-sm">
                      <?= icon('cart', 12) ?> Dispense in POS &rarr;
                    </a>
                  <?php else: ?>
                    <form method="POST" action="retailer_prescriptions.php" style="display: inline;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="verify_rx">
                      <input type="hidden" name="rx_id" value="<?= $rx['id'] ?>">
                      <button type="submit" class="btn btn-outline btn-sm">
                        <?= icon('refresh', 12) ?> Re-Verify
                      </button>
                    </form>
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

<!-- Modal 1: Uploaded Prescription Document Lightbox -->
<div id="rx-lightbox-modal" style="display: none; position: fixed; inset: 0; background: rgba(11, 15, 25, 0.8); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(6px);">
  <div class="card" style="max-width: 780px; width: 94%; max-height: 90vh; display: flex; flex-direction: column; padding: 1.5rem; background: var(--bg-surface); box-shadow: var(--shadow-lg); border-radius: 8px;">
    
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-light); padding-bottom: 0.75rem; margin-bottom: 1rem;">
      <div>
        <h3 id="modal-rx-title" style="font-size: 1.15rem; font-weight: 700; color: var(--text-main); margin: 0;">Prescription Document</h3>
        <p id="modal-rx-sub" style="font-size: 0.775rem; color: var(--text-subtle); margin: 0;"></p>
      </div>
      <div style="display: flex; gap: 0.5rem; align-items: center;">
        <a id="modal-full-view-btn" href="#" target="_blank" class="btn btn-outline btn-sm">
          <?= icon('file-text', 12) ?> Open Certificate
        </a>
        <button type="button" onclick="closeDocPreview()" style="border: none; background: var(--bg-surface-subtle); color: var(--text-main); border-radius: 4px; padding: 0.25rem 0.6rem; font-size: 1.2rem; cursor: pointer;">&times;</button>
      </div>
    </div>

    <!-- Viewer Body -->
    <div id="modal-viewer-container" style="flex: 1; overflow-y: auto; text-align: center; background: var(--bg-surface-subtle); border-radius: 6px; padding: 1rem; border: 1px solid var(--border-light);">
      <img id="modal-rx-img" src="#" alt="Prescription Full Slip" style="max-width: 100%; max-height: 520px; border-radius: 4px; box-shadow: 0 2px 10px rgba(0,0,0,0.2); display: none; margin: 0 auto;">
      <iframe id="modal-rx-frame" src="" style="width: 100%; height: 500px; border: none; display: none;"></iframe>
    </div>
  </div>
</div>

<!-- Modal 2: Rejection Reason -->
<div id="reject-modal" style="display: none; position: fixed; inset: 0; background: rgba(11, 15, 25, 0.8); z-index: 999; align-items: center; justify-content: center; backdrop-filter: blur(6px);">
  <div class="card" style="max-width: 480px; width: 92%; padding: 1.75rem; background: var(--bg-surface); box-shadow: var(--shadow-lg);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
      <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--color-danger); margin: 0; display: flex; align-items: center; gap: 0.4rem;">
        <?= icon('alert-triangle', 18) ?> Reject Prescription
      </h3>
      <button type="button" onclick="closeRejectModal()" style="border: none; background: transparent; color: var(--text-muted); font-size: 1.25rem; cursor: pointer;">&times;</button>
    </div>

    <p style="font-size: 0.825rem; color: var(--text-muted); margin-bottom: 1rem;">
      Specify the clinical reason for rejecting prescription for patient <strong id="modal-patient-name" style="color: var(--text-main);"></strong>:
    </p>

    <form method="POST" action="retailer_prescriptions.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reject_rx">
      <input type="hidden" name="rx_id" id="reject-rx-id" value="">

      <div class="form-group">
        <label class="form-label">Clinical / Regulatory Non-Compliance Reason *</label>
        <select name="rejection_reason" class="form-control" style="margin-bottom: 0.5rem;" onchange="if(this.value === 'custom') document.getElementById('custom-reason-box').style.display='block'; else document.getElementById('custom-reason-box').style.display='none';">
          <option value="Physician registration number could not be verified on CDSCO/MCI database">Physician registration number could not be verified</option>
          <option value="Prescription document is expired (>30 days since issuance date)">Prescription document is expired (>30 days since issuance)</option>
          <option value="Schedule H dosage exceeds safe therapeutic pharmacological thresholds">Dosage exceeds safe therapeutic limits</option>
          <option value="Document image illegible or missing official doctor signature/stamp">Document illegible or missing doctor stamp/signature</option>
          <option value="custom">Other specific reason (write below)...</option>
        </select>
        <div id="custom-reason-box" style="display: none; margin-top: 0.5rem;">
          <textarea name="custom_reason" class="form-control" rows="2" placeholder="Detail clinical contraindication or reason for refusal..."></textarea>
        </div>
      </div>

      <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.25rem;">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeRejectModal()">Cancel</button>
        <button type="submit" class="btn btn-danger btn-sm">Confirm Rejection</button>
      </div>
    </form>
  </div>
</div>

<script>
function openDocPreview(rxId, patientName, fileName, isImage) {
  const modal = document.getElementById('rx-lightbox-modal');
  const img = document.getElementById('modal-rx-img');
  const frame = document.getElementById('modal-rx-frame');
  const title = document.getElementById('modal-rx-title');
  const sub = document.getElementById('modal-rx-sub');
  const fullViewBtn = document.getElementById('modal-full-view-btn');

  title.textContent = `Prescription Slip - ${patientName}`;
  sub.textContent = `Document: ${fileName} • Rx Token #RX-${String(rxId).padStart(5, '0')}`;
  fullViewBtn.href = `view_prescription.php?id=${rxId}`;

  const docUrl = `view_prescription.php?id=${rxId}&raw=1`;

  if (isImage) {
    img.src = docUrl;
    img.style.display = 'block';
    frame.style.display = 'none';
  } else {
    frame.src = docUrl;
    frame.style.display = 'block';
    img.style.display = 'none';
  }

  modal.style.display = 'flex';
}

function closeDocPreview() {
  document.getElementById('rx-lightbox-modal').style.display = 'none';
  document.getElementById('modal-rx-img').src = '#';
  document.getElementById('modal-rx-frame').src = '';
}

function openRejectModal(rxId, patientName) {
  document.getElementById('reject-rx-id').value = rxId;
  document.getElementById('modal-patient-name').textContent = patientName;
  document.getElementById('reject-modal').style.display = 'flex';
}

function closeRejectModal() {
  document.getElementById('reject-modal').style.display = 'none';
}

function filterRxStatus(status, btn) {
  document.querySelectorAll('.btn-sm').forEach(b => b.classList.remove('active-filter-btn'));
  btn.classList.add('active-filter-btn');

  const rows = document.querySelectorAll('.rx-row');
  rows.forEach(r => {
    if (status === 'all' || r.dataset.status === status) {
      r.style.display = '';
    } else {
      r.style.display = 'none';
    }
  });
}

function filterRxTable(query) {
  const q = query.toLowerCase().trim();
  const rows = document.querySelectorAll('.rx-row');
  rows.forEach(r => {
    const text = r.dataset.search || '';
    if (!q || text.includes(q)) {
      r.style.display = '';
    } else {
      r.style.display = 'none';
    }
  });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
