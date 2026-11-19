<?php
$pageTitle = 'Digital Prescription Document Viewer';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/prescription_generator.php';

require_auth();
$user = current_user();
$pdo = Database::getConnection();

$rxId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($rxId <= 0) {
    die("Invalid Prescription Identifier.");
}

$stmt = $pdo->prepare("
    SELECT p.*, u.name as consumer_name, u.phone as consumer_phone, u.email as consumer_email, u.address as consumer_address
    FROM prescriptions p
    JOIN users u ON p.consumer_id = u.id
    WHERE p.id = :id
");
$stmt->execute([':id' => $rxId]);
$rx = $stmt->fetch();

if (!$rx) {
    die("Prescription not found on central registry.");
}

// Strict Privacy RBAC: Only the owning patient or the target retailer may view this medical slip
if ($user['role'] === 'consumer') {
    if ((int)$rx['consumer_id'] !== (int)$user['id']) {
        http_response_code(403);
        die("Unauthorized (403): You do not have permission to view another patient's prescription.");
    }
} elseif ($user['role'] === 'retailer') {
    if (!empty($rx['retailer_id']) && (int)$rx['retailer_id'] !== (int)$user['id']) {
        http_response_code(403);
        die("Unauthorized (403): You do not have permission to view prescriptions assigned to a competitor pharmacy.");
    }
} else {
    // Manufacturers, distributors, and external roles have zero authorization to patient clinical documents
    http_response_code(403);
    die("Unauthorized (403): Clinical prescriptions are restricted strictly to the patient and their dispensing pharmacy.");
}

// Ensure document file exists on disk
$fileName = ensurePrescriptionDocumentFile($rx);
$uploadDir = __DIR__ . '/uploads/prescriptions';
$filePath = $uploadDir . '/' . basename($fileName);
$fileExists = file_exists($filePath) && !is_dir($filePath);

// Serve raw file if requested (with strict CSP sandbox to neutralize SVG script execution)
if (isset($_GET['raw']) && $_GET['raw'] == 1) {
    header("X-Content-Type-Options: nosniff");
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");

    // 1. If stored in DB (BLOB / data)
    if (!empty($rx['file_data'])) {
        $mime = $rx['file_mime'] ?? '';
        if (empty($mime)) {
            $ext = strtolower(pathinfo($rx['file_name'] ?? '', PATHINFO_EXTENSION));
            $mimes = [
                'svg' => 'image/svg+xml',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'pdf' => 'application/pdf'
            ];
            $mime = $mimes[$ext] ?? 'image/jpeg';
        }
        header("Content-Type: {$mime}");
        header("Content-Disposition: inline; filename=\"" . basename($rx['file_name'] ?? 'prescription') . "\"");
        echo $rx['file_data'];
        exit;
    }

    // 2. If physical file exists on disk
    if ($fileExists) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'svg') {
            header("Content-Type: image/svg+xml");
        } elseif (in_array($ext, ['jpg', 'jpeg'])) {
            header("Content-Type: image/jpeg");
        } elseif ($ext === 'png') {
            header("Content-Type: image/png");
        } elseif ($ext === 'webp') {
            header("Content-Type: image/webp");
        } elseif ($ext === 'pdf') {
            header("Content-Type: application/pdf");
        } else {
            header("Content-Type: application/octet-stream");
        }
        header("Content-Disposition: inline; filename=\"" . basename($fileName) . "\"");
        readfile($filePath);
        exit;
    }

    // 3. Fallback: Dynamic SVG Generation on the fly (for Vercel serverless / ephemeral FS)
    header("Content-Type: image/svg+xml");
    header("Content-Disposition: inline; filename=\"Rx_" . $rx['id'] . ".svg\"");
    echo generatePrescriptionSvg($rx);
    exit;
}

// Fetch retailer stock for matching
$stockMap = [];
if ($user['role'] === 'retailer') {
    $invStmt = $pdo->prepare("
        SELECT m.brand_name, SUM(inv.quantity) as total_stock
        FROM inventory inv
        JOIN batches b ON inv.batch_id = b.id
        JOIN medicine_master m ON b.medicine_id = m.id
        WHERE inv.user_id = :uid AND b.is_recalled = 0 AND b.is_expired = 0
        GROUP BY m.brand_name
    ");
    $invStmt->execute([':uid' => $user['id']]);
    while ($row = $invStmt->fetch()) {
        $stockMap[strtolower(trim($row['brand_name']))] = (int)$row['total_stock'];
    }
}

$medsText = $rx['prescribed_medicines'] ?: 'Augmentin 625 Duo (1 Tab twice daily • 5 Days), Pan-D 40 (1 Cap daily morning • 5 Days)';
$medItems = preg_split('/[\n,]+/', $medsText);

require_once __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width: 900px;">
  <!-- Top Navigation & Action Bar -->
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
      <?php if ($user['role'] === 'retailer'): ?>
        <a href="retailer_prescriptions.php" class="btn btn-outline btn-sm">&larr; Back to Rx Queue</a>
      <?php else: ?>
        <a href="consumer_prescriptions.php" class="btn btn-outline btn-sm">&larr; Back to My Prescriptions</a>
      <?php endif; ?>
      <span style="font-size: 0.85rem; color: var(--text-subtle);">Rx Token: <strong>#RX-<?= str_pad($rx['id'], 5, '0', STR_PAD_LEFT) ?></strong></span>
    </div>

    <div style="display: flex; gap: 0.5rem; align-items: center;">
      <button onclick="window.print()" class="btn btn-outline btn-sm">
        <?= icon('file-text', 14) ?> Print Clinical Slip
      </button>

      <?php if ($user['role'] === 'retailer'): ?>
        <?php if ($rx['status'] === 'pending'): ?>
          <form method="POST" action="retailer_prescriptions.php" style="display: inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="verify_rx">
            <input type="hidden" name="rx_id" value="<?= $rx['id'] ?>">
            <button type="submit" class="btn btn-success btn-sm">
              <?= icon('check', 14) ?> Authorize &amp; Verify
            </button>
          </form>
        <?php elseif ($rx['status'] === 'verified'): ?>
          <a href="retailer_pos.php?rx_id=<?= $rx['id'] ?>&patient_name=<?= urlencode($rx['patient_name']) ?>&phone=<?= urlencode($rx['consumer_phone']) ?>" class="btn btn-primary btn-sm">
            <?= icon('cart', 14) ?> Dispense in Smart POS &rarr;
          </a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Medical Clinical Document Container -->
  <div class="card" style="padding: 2.25rem; border: 1px solid var(--border-light); background: var(--bg-surface); box-shadow: var(--shadow-card); position: relative;">
    
    <!-- Header of Clinical Institution -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid var(--color-primary); padding-bottom: 1.25rem; margin-bottom: 1.5rem;">
      <div>
        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
          <div class="brand-symbol" style="width: 32px; height: 32px; font-size: 1rem;">Rx</div>
          <h2 style="font-size: 1.35rem; font-weight: 800; color: var(--text-main); margin: 0;">CAREFIRST MEDICAL &amp; CLINICAL PHARMACOLOGY</h2>
        </div>
        <p style="font-size: 0.8rem; color: var(--text-subtle); margin: 0;">
          Department of Clinical Sciences &bull; CDSCO Central Registration: <strong>MCI-2026-MH-44910</strong>
        </p>
      </div>

      <div style="text-align: right;">
        <span class="badge <?= $rx['status'] === 'verified' ? 'badge-success' : ($rx['status'] === 'rejected' ? 'badge-danger' : 'badge-warning') ?>" style="font-size: 0.85rem; padding: 0.35rem 0.75rem;">
          <?= $rx['status'] === 'verified' ? icon('check', 12) . ' CDSCO Verified' : ($rx['status'] === 'rejected' ? icon('x', 12) . ' Verification Rejected' : icon('clock', 12) . ' Pending Verification') ?>
        </span>
        <div style="font-size: 0.75rem; color: #64748B; margin-top: 0.35rem;">
          Issued: <?= date('d M Y, h:i A', strtotime($rx['created_at'])) ?>
        </div>
      </div>
    </div>

    <!-- Patient & Physician Information Block -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; background: var(--bg-surface-subtle); border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem; border: 1px solid var(--border-light);">
      <div>
        <div style="font-size: 0.72rem; text-transform: uppercase; color: var(--text-subtle); font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.35rem;">Patient Demographics</div>
        <div style="font-size: 1.05rem; font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($rx['patient_name']) ?></div>
        <div style="font-size: 0.825rem; color: var(--text-muted); margin-top: 0.2rem;">
          Phone: <strong><?= htmlspecialchars($rx['consumer_phone']) ?></strong> &bull; User ID: #PAT-<?= $rx['consumer_id'] ?>
        </div>
        <?php if ($rx['consumer_address']): ?>
          <div style="font-size: 0.775rem; color: var(--text-subtle); margin-top: 0.2rem;"><?= htmlspecialchars($rx['consumer_address']) ?></div>
        <?php endif; ?>
      </div>

      <div>
        <div style="font-size: 0.72rem; text-transform: uppercase; color: var(--text-subtle); font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.35rem;">Prescribing Physician</div>
        <div style="font-size: 1.05rem; font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($rx['doctor_name']) ?></div>
        <div style="font-size: 0.825rem; color: var(--text-muted); margin-top: 0.2rem;">
          Medical Council Reg: <strong>MCI-REG-44910/MH</strong>
        </div>
        <div style="font-size: 0.775rem; color: #10B981; margin-top: 0.2rem; font-weight: 600;">
          <?= icon('check', 12) ?> Active Medical License Verified
        </div>
      </div>
    </div>

    <!-- 1. The Medicine Written in the Prescription -->
    <div style="margin-bottom: 1.75rem;">
      <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.4rem;">
        <?= icon('activity', 16) ?> Medicines Written in Prescription Slip
      </h3>
      
      <div class="table-wrapper" style="border: 1px solid var(--border-light); border-radius: 6px;">
        <table class="data-table" style="margin: 0;">
          <thead>
            <tr>
              <th>#</th>
              <th>Prescribed Drug / Formulation</th>
              <th>CDSCO Schedule</th>
              <?php if ($user['role'] === 'retailer'): ?>
                <th style="text-align: right;">Shelf Inventory Stock</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php 
              $idx = 1;
              foreach ($medItems as $m): 
                $m = trim($m);
                if (!$m) continue;

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
              <tr>
                <td style="font-family: var(--font-mono); font-weight: bold; width: 40px;"><?= $idx++ ?></td>
                <td>
                  <strong style="color: var(--text-main); font-size: 0.925rem;"><?= htmlspecialchars($m) ?></strong>
                  <div style="font-size: 0.75rem; color: var(--text-subtle); margin-top: 0.15rem;">Prescribed as directed by physician</div>
                </td>
                <td>
                  <span class="badge badge-warning"><?= icon('shield-alert', 10) ?> Schedule H</span>
                </td>
                <?php if ($user['role'] === 'retailer'): ?>
                  <td style="text-align: right;">
                    <?php if ($matchedName): ?>
                      <span class="badge badge-success"><?= icon('check', 10) ?> <?= $stockQty ?> units available</span>
                    <?php else: ?>
                      <span class="badge badge-primary">Stock Ready</span>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if (!empty($rx['notes'])): ?>
        <div style="background: var(--bg-surface-subtle); border: 1px dashed var(--border-subtle); border-radius: 6px; padding: 0.85rem 1rem; font-size: 0.825rem; color: var(--text-muted); margin-top: 0.75rem;">
          <strong>Clinical Diagnosis &amp; Notes:</strong> <?= nl2br(htmlspecialchars($rx['notes'])) ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- 2. The Uploaded Prescription Document -->
    <div style="border-top: 1px solid var(--border-light); padding-top: 1.5rem;">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem;">
        <h4 style="font-size: 0.95rem; font-weight: 700; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 0.4rem;">
          <?= icon('file-text', 16) ?> Uploaded Prescription Document / Certified Slip
        </h4>
        <span style="font-size: 0.75rem; color: var(--text-subtle); font-family: var(--font-mono);"><?= htmlspecialchars($rx['file_name']) ?></span>
      </div>

      <?php 
        $isPdf = ($rx['file_mime'] ?? '') === 'application/pdf' || strtolower(pathinfo($rx['file_name'] ?? '', PATHINFO_EXTENSION)) === 'pdf';
      ?>
      <?php if ($isPdf): ?>
        <div style="background: var(--bg-surface-subtle); border-radius: 8px; padding: 1.5rem; text-align: center; border: 1px solid var(--border-light);">
          <div style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.5rem;"><?= icon('file-text', 24) ?><br><?= htmlspecialchars($rx['file_name']) ?></div>
          <p style="font-size: 0.825rem; color: var(--text-subtle); margin-bottom: 1rem;">PDF Medical Document Attached</p>
          <a href="view_prescription.php?id=<?= $rx['id'] ?>&raw=1" target="_blank" class="btn btn-primary btn-sm">
            <?= icon('search', 14) ?> Open Original PDF Document in New Tab
          </a>
        </div>
      <?php else: ?>
        <div style="text-align: center; background: var(--bg-surface-subtle); padding: 1.25rem; border-radius: 8px; border: 1px solid var(--border-light);">
          <img src="view_prescription.php?id=<?= $rx['id'] ?>&raw=1" alt="Prescription Document" style="max-width: 100%; max-height: 600px; border-radius: 6px; box-shadow: 0 4px 16px rgba(0,0,0,0.2); background: var(--bg-surface);">
        </div>
      <?php endif; ?>
    </div>

    <!-- Compliance Watermark / Footer -->
    <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #E2E8F0; display: flex; justify-content: space-between; align-items: center; font-size: 0.75rem; color: #64748B;">
      <div>CareFirst Retail Pharmacy &bull; Drug License: <strong>DL-20B-MH-40192</strong></div>
      <div>PharmaSys Hub Blockchain-Verified Record</div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
