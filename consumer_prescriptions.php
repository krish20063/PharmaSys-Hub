<?php
$pageTitle = 'Prescription Upload & Verification Tracker';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/prescription_generator.php';

require_role('consumer');

$pdo = Database::getConnection();
$message = null;

// Handle Prescription Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_rx') {
    // 1. Enforce CSRF & Rate Limiting
    if (!verify_csrf_token()) {
        $message = ['type' => 'danger', 'text' => 'Security Error: CSRF token validation failed. Please refresh and try again.'];
    } else {
        enforce_rate_limit('upload_prescription', 10, 60);

        $patientName = clean_string($_POST['patient_name'] ?? '');
        $doctorName = clean_string($_POST['doctor_name'] ?? '');
        $prescribedMeds = clean_string($_POST['prescribed_medicines'] ?? '');
        $notes = clean_string($_POST['notes'] ?? '');
        $retailerId = clean_int($_POST['retailer_id'] ?? 3, 3);
        if ($retailerId <= 0) $retailerId = 3;

        $uploadDir = __DIR__ . '/uploads/prescriptions';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0750, true);
        }

        $cleanPatient = preg_replace('/[^a-zA-Z0-9]/', '_', $patientName);
        $timeToken = date('Ymd_His') . '_' . bin2hex(random_bytes(4));

        // Default file name placeholder
        $hasPhysicalFile = false;
        $fileName = 'Rx_' . $cleanPatient . '_' . $timeToken . '.svg';

        // Handle physical uploaded file if provided
        if (isset($_FILES['rx_file']) && is_uploaded_file($_FILES['rx_file']['tmp_name'])) {
            $validation = validate_prescription_upload($_FILES['rx_file']);
            if (!$validation['valid']) {
                $message = ['type' => 'danger', 'text' => $validation['error']];
            } else {
                $origExt = $validation['extension'];
                $fileName = 'Rx_' . $cleanPatient . '_' . $timeToken . '.' . $origExt;
                $destPath = $uploadDir . '/' . $fileName;
                if (move_uploaded_file($_FILES['rx_file']['tmp_name'], $destPath)) {
                    $hasPhysicalFile = true;
                }
            }
        }

    // If no physical file was provided, create certified digital SVG slip
    if (!$hasPhysicalFile) {
        $tempData = [
            'id' => rand(100, 999),
            'patient_name' => $patientName,
            'doctor_name' => $doctorName,
            'prescribed_medicines' => $prescribedMeds ?: 'Augmentin 625 Duo, Pan-D 40',
            'notes' => $notes ?: 'Standard clinical regimen under medical supervision.',
            'created_at' => date('Y-m-d H:i:s')
        ];
        $svgDoc = generatePrescriptionSvg($tempData);
        file_put_contents($uploadDir . '/' . $fileName, $svgDoc);
    }

    if ($patientName && $doctorName) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO prescriptions (consumer_id, retailer_id, patient_name, doctor_name, file_name, prescribed_medicines, notes, status)
                VALUES (:cid, :rid, :pname, :dname, :fname, :pmeds, :notes, 'pending')
            ");
            $stmt->execute([
                ':cid' => $_SESSION['user_id'],
                ':rid' => $retailerId,
                ':pname' => $patientName,
                ':dname' => $doctorName,
                ':fname' => $fileName,
                ':pmeds' => $prescribedMeds ?: 'Augmentin 625 Duo, Pan-D 40',
                ':notes' => $notes
            ]);
            $newRxId = $pdo->lastInsertId();

            // Fetch target pharmacy name using prepared statement
            $phStmt = $pdo->prepare("SELECT name FROM users WHERE id = :rid");
            $phStmt->execute([':rid' => $retailerId]);
            $phName = $phStmt->fetchColumn() ?: 'Retail Pharmacy';

            // Broadcast real-time notification to Retailer
            $nStmt = $pdo->prepare("
                INSERT INTO notifications (target_role, title, message, type)
                VALUES ('retailer', '📋 New Prescription Awaiting Verification', :msg, 'warning')
            ");
            $nStmt->execute([
                ':msg' => "Patient {$patientName} uploaded prescription #RX-" . str_pad($newRxId, 5, '0', STR_PAD_LEFT) . " for [{$prescribedMeds}] to {$phName}."
            ]);

            $message = ['type' => 'success', 'text' => "Prescription #RX-" . str_pad($newRxId, 5, '0', STR_PAD_LEFT) . " submitted securely to {$phName}! The licensed pharmacist will audit and clear Schedule H drugs."];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Upload failed. Please verify document formatting and try again.'];
        }
    } else {
        $message = ['type' => 'danger', 'text' => 'Patient name and prescribing doctor are required.'];
    }
    } // End CSRF verification check
}

// Fetch all licensed retail pharmacies
$pharmacies = $pdo->query("SELECT id, name, address FROM users WHERE role = 'retailer' ORDER BY id ASC")->fetchAll();

// Fetch strictly THIS consumer's prescriptions
$rxStmt = $pdo->prepare("
    SELECT p.*, r.name as pharmacy_name
    FROM prescriptions p
    LEFT JOIN users r ON p.retailer_id = r.id
    WHERE p.consumer_id = :cid
    ORDER BY p.id DESC
");
$rxStmt->execute([':cid' => $_SESSION['user_id']]);
$prescriptions = $rxStmt->fetchAll();

// Ensure documents exist on disk for each
foreach ($prescriptions as &$p) {
    ensurePrescriptionDocumentFile($p);
}
unset($p);

// Fetch popular drugs for quick chip selection
$drugsStmt = $pdo->query("SELECT brand_name, strength_mg, dosage_form, schedule_type FROM medicine_master LIMIT 10");
$popularDrugs = $drugsStmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
  <div style="margin-bottom: 1.5rem;">
    <h2 style="font-size: 1.4rem; font-weight: 700;">Prescription Upload &amp; Pharmacist Review Hub</h2>
    <p style="font-size: 0.825rem; color: var(--text-subtle);">
      Submit your doctor's prescription with the prescribed medicines for Schedule H / H1 antibiotic verification and pharmacy fulfillment.
    </p>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?= $message['type'] ?>">
      <?= icon('check', 16) ?>
      <div><?= htmlspecialchars($message['text']) ?></div>
    </div>
  <?php endif; ?>

  <div class="grid-2">
    <!-- Upload Card -->
    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Upload Doctor's Prescription</h3>
          <p class="card-description">Upload slip photo or document, then specify the medicines written</p>
        </div>
        <span class="badge badge-primary"><?= icon('file-text', 12) ?> Schedule H Mandate</span>
      </div>

      <form method="POST" action="consumer_prescriptions.php" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_rx">

        <div class="grid-2" style="margin-bottom: 0;">
          <div class="form-group">
            <label class="form-label">Patient Full Name *</label>
            <input type="text" name="patient_name" class="form-control" value="<?= htmlspecialchars($_SESSION['user_name']) ?>" required>
          </div>

          <div class="form-group">
            <label class="form-label">Prescribing Physician *</label>
            <input type="text" name="doctor_name" class="form-control" placeholder="Dr. V. K. Mehta, MD" value="Dr. V. K. Mehta, MD" required>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Target Dispensing Retail Pharmacy *</label>
          <select name="retailer_id" class="form-control" required>
            <?php foreach ($pharmacies as $ph): ?>
              <option value="<?= $ph['id'] ?>">
                <?= htmlspecialchars($ph['name']) ?> &bull; <?= htmlspecialchars($ph['address']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 0.25rem;">
            Only the pharmacy selected above will have authorized clinical access to audit and dispense this prescription.
          </div>
        </div>

        <!-- Prescribed Medicines Section -->
        <div class="form-group">
          <label class="form-label" style="display: flex; justify-content: space-between; align-items: center;">
            <span>Medicines Written in Prescription *</span>
            <span style="font-size: 0.72rem; color: var(--color-primary); font-weight: normal;">Click tags below to quick-add</span>
          </label>
          <textarea id="prescribed-meds-input" name="prescribed_medicines" class="form-control" rows="3" placeholder="e.g. Augmentin 625 Duo (1 Tab twice daily • 5 Days), Pan-D 40 (1 Cap daily morning)" required>Augmentin 625 Duo (1 Tab twice daily • 5 Days), Pan-D 40 (1 Cap daily morning)</textarea>
          
          <!-- Quick Add Chips -->
          <div style="margin-top: 0.5rem; display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center;">
            <span style="font-size: 0.72rem; color: var(--text-subtle); margin-right: 0.2rem;">Quick Suggestions:</span>
            <?php foreach ($popularDrugs as $d): ?>
              <button type="button" class="btn btn-outline btn-sm" style="font-size: 0.72rem; padding: 0.15rem 0.5rem; border-radius: 9999px; background: var(--bg-surface-subtle); color: var(--text-main); border-color: var(--border-light);" onclick="appendMedicine('<?= htmlspecialchars(addslashes($d['brand_name'])) ?> <?= $d['strength_mg'] ?>mg')">
                + <?= htmlspecialchars($d['brand_name']) ?>
              </button>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Document Attachment -->
        <div class="form-group">
          <label class="form-label">Select Prescription Slip / Photo (Optional if entering digitally)</label>
          <input type="file" name="rx_file" id="rx_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp" onchange="previewRxFile(event)">
          <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 0.35rem;">
            Accepted formats: JPG, PNG, WEBP, PDF (Max 10MB). If left blank, a digital clinical certificate will be generated automatically.
          </div>

          <!-- Real-time File Preview Box -->
          <div id="file-preview-box" style="display: none; margin-top: 0.75rem; padding: 0.75rem; background: var(--bg-surface-subtle); border: 1px dashed var(--border-light); border-radius: 6px; text-align: center;">
            <div style="font-size: 0.75rem; color: var(--text-subtle); margin-bottom: 0.35rem;" id="preview-filename"></div>
            <img id="image-preview" src="#" alt="Prescription preview" style="max-height: 180px; max-width: 100%; border-radius: 4px; display: none; margin: 0 auto;">
            <div id="pdf-preview-indicator" style="display: none; font-size: 0.85rem; font-weight: 600; color: #0284C7; padding: 0.5rem;">
              <?= icon('file-text', 18) ?> PDF Document Selected Ready for Upload
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Clinical Notes / Special Instructions</label>
          <textarea name="notes" class="form-control" rows="2" placeholder="e.g. 5-day fever and throat infection; allergic to sulfa drugs."></textarea>
        </div>

        <button type="submit" class="btn btn-primary btn-block">
          <?= icon('check', 16) ?> Submit Prescription with Medicines
        </button>
      </form>
    </div>

    <!-- Uploaded List -->
    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">My Prescription History</h3>
          <p class="card-description">Review status and medicines approved by pharmacist</p>
        </div>
        <span class="badge badge-neutral"><?= count($prescriptions) ?> Records</span>
      </div>

      <div class="table-wrapper">
        <table class="data-table" id="consumer-rx-table">
          <thead>
            <tr>
              <th>Document &amp; Token</th>
              <th>Medicines Prescribed</th>
              <th>Doctor</th>
              <th>Status</th>
              <th style="text-align: right;">Slip</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($prescriptions)): ?>
              <tr>
                <td colspan="5" style="text-align: center; color: var(--text-subtle); padding: 2rem;">No prescriptions uploaded yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($prescriptions as $rx): ?>
                <tr class="rx-consumer-row">
                  <td>
                    <code style="font-family: var(--font-mono); font-size: 0.8rem; font-weight: 700; color: var(--color-primary-dark);">
                      #RX-<?= str_pad($rx['id'], 5, '0', STR_PAD_LEFT) ?>
                    </code>
                    <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 0.2rem;">
                      <?= date('d-M-Y', strtotime($rx['created_at'])) ?>
                    </div>
                  </td>
                  <td>
                    <div style="font-size: 0.825rem; font-weight: 600; color: var(--text-main); max-width: 220px; line-height: 1.35;">
                      <?= htmlspecialchars($rx['prescribed_medicines'] ?: 'Augmentin 625 Duo, Pan-D 40') ?>
                    </div>
                    <?php if (!empty($rx['notes'])): ?>
                      <div style="font-size: 0.7rem; color: #64748B; margin-top: 0.25rem; font-style: italic;">
                        Notes: "<?= htmlspecialchars($rx['notes']) ?>"
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <strong><?= htmlspecialchars($rx['doctor_name']) ?></strong>
                    <div style="font-size: 0.72rem; color: var(--color-primary); font-weight: 600; margin-top: 0.15rem;">
                      <?= icon('truck', 10) ?> <?= htmlspecialchars($rx['pharmacy_name'] ?? 'CareFirst Pharmacy') ?>
                    </div>
                  </td>
                  <td>
                    <?php if ($rx['status'] === 'verified'): ?>
                      <span class="badge badge-success"><?= icon('check', 12) ?> Approved</span>
                      <div style="font-size: 0.68rem; color: #059669; margin-top: 0.2rem;">Cleared for POS</div>
                    <?php elseif ($rx['status'] === 'rejected'): ?>
                      <span class="badge badge-danger"><?= icon('x', 12) ?> Rejected</span>
                    <?php else: ?>
                      <span class="badge badge-warning"><?= icon('clock', 12) ?> Under Audit</span>
                    <?php endif; ?>
                  </td>
                  <td style="text-align: right;">
                    <a href="view_prescription.php?id=<?= $rx['id'] ?>" target="_blank" class="btn btn-outline btn-sm">
                      <?= icon('file-text', 12) ?> View
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
function appendMedicine(drugName) {
  const textarea = document.getElementById('prescribed-meds-input');
  const current = textarea.value.trim();
  if (!current) {
    textarea.value = drugName + ' (1 Tab twice daily • 5 Days)';
  } else if (!current.includes(drugName)) {
    textarea.value = current + ', ' + drugName + ' (1 Tab twice daily)';
  }
  textarea.focus();
}

function previewRxFile(event) {
  const input = event.target;
  const previewBox = document.getElementById('file-preview-box');
  const imgPreview = document.getElementById('image-preview');
  const pdfIndicator = document.getElementById('pdf-preview-indicator');
  const filenameText = document.getElementById('preview-filename');

  if (input.files && input.files[0]) {
    const file = input.files[0];
    previewBox.style.display = 'block';
    filenameText.textContent = `Selected: ${file.name} (${Math.round(file.size / 1024)} KB)`;

    if (file.type.startsWith('image/')) {
      const reader = new FileReader();
      reader.onload = function(e) {
        imgPreview.src = e.target.result;
        imgPreview.style.display = 'block';
        pdfIndicator.style.display = 'none';
      };
      reader.readAsDataURL(file);
    } else {
      imgPreview.style.display = 'none';
      pdfIndicator.style.display = 'block';
    }
  } else {
    previewBox.style.display = 'none';
  }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
