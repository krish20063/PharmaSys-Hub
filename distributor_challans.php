<?php
$pageTitle = 'GST Dispatch Challans & Consignments';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_role('distributor');

$pdo = Database::getConnection();
$message = null;

// Handle New Challan Generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_challan') {
    $retailerId = (int)$_POST['retailer_id'];
    $gstin = trim($_POST['gstin_number']);
    $taxable = (float)$_POST['taxable_amount'];
    $dispatchDate = $_POST['dispatch_date'];

    if ($retailerId && $gstin && $taxable > 0) {
        $cgst = round($taxable * 0.06, 2);
        $sgst = round($taxable * 0.06, 2);
        $total = $taxable + $cgst + $sgst;
        $challanNo = 'CHALLAN-MH-' . date('Y') . '-' . rand(2000, 9999);

        try {
            $stmt = $pdo->prepare("
                INSERT INTO dispatch_challans (challan_number, distributor_id, retailer_id, dispatch_date, gstin_number, taxable_amount, cgst_amount, sgst_amount, total_amount, status)
                VALUES (:cno, :did, :rid, :ddate, :gst, :tax, :cgst, :sgst, :tot, 'in_transit')
            ");
            $stmt->execute([
                ':cno' => $challanNo,
                ':did' => $_SESSION['user_id'],
                ':rid' => $retailerId,
                ':ddate' => $dispatchDate,
                ':gst' => $gstin,
                ':tax' => $taxable,
                ':cgst' => $cgst,
                ':sgst' => $sgst,
                ':tot' => $total
            ]);
            $message = ['type' => 'success', 'text' => "GST Tax Delivery Challan {$challanNo} minted and dispatched."];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => "Error generating challan: " . $e->getMessage()];
        }
    }
}

// Fetch all retail pharmacies
$retailers = $pdo->query("SELECT id, name, license_no FROM users WHERE role = 'retailer'")->fetchAll();

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

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
      <h2 style="font-size: 1.4rem; font-weight: 700;">GST Dispatch Challans &amp; B2B Delivery Manifests</h2>
      <p style="font-size: 0.825rem; color: var(--text-subtle);">
        Legally audited pharmaceutical transit documents with dual GST (6% CGST + 6% SGST) compliance.
      </p>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?= $message['type'] ?>">
      <?= icon('check', 16) ?>
      <div><?= htmlspecialchars($message['text']) ?></div>
    </div>
  <?php endif; ?>

  <!-- Challan Creation Card -->
  <div class="card" style="margin-bottom: 2rem;">
    <div class="card-header">
      <div>
        <h3 class="card-title">Generate New B2B GST Delivery Challan</h3>
        <p class="card-description">Dispatches from Nexus Warehouse to licensed retail pharmacies</p>
      </div>
      <span class="badge badge-primary"><?= icon('file-text', 12) ?> HSN 3004 Compliant</span>
    </div>

    <form method="POST" action="distributor_challans.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_challan">

      <div class="grid-4">
        <div class="form-group">
          <label class="form-label">Recipient Pharmacy *</label>
          <select name="retailer_id" class="form-control" required>
            <?php foreach ($retailers as $ret): ?>
              <option value="<?= $ret['id'] ?>"><?= htmlspecialchars($ret['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Recipient GSTIN *</label>
          <input type="text" name="gstin_number" class="form-control" value="27AABCU9603R1ZM" required>
        </div>

        <div class="form-group">
          <label class="form-label">Dispatch Date *</label>
          <input type="date" name="dispatch_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="form-group">
          <label class="form-label">Taxable Value (₹) *</label>
          <input type="number" name="taxable_amount" class="form-control" value="5400.00" step="10" required>
        </div>
      </div>

      <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border-light); padding-top: 1rem;">
        <div style="font-size: 0.8rem; color: var(--text-subtle);">
          Automatic 12% Pharma GST will calculate 6% CGST and 6% SGST on taxable subtotal.
        </div>
        <button type="submit" class="btn btn-primary">
          <?= icon('truck', 16) ?> Issue &amp; Dispatch Challan
        </button>
      </div>
    </form>
  </div>

  <!-- Challans Register -->
  <div class="card">
    <div class="card-header">
      <div>
        <h3 class="card-title">Dispatch Challan Registry</h3>
        <p class="card-description">All pharmaceutical consignments issued across the supply chain</p>
      </div>
      <span class="badge badge-neutral"><?= count($challans) ?> Consignments</span>
    </div>

    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr>
            <th>Challan Number</th>
            <th>Recipient Pharmacy</th>
            <th>GSTIN</th>
            <th>Date</th>
            <th style="text-align: right;">Taxable (₹)</th>
            <th style="text-align: right;">CGST (6%)</th>
            <th style="text-align: right;">SGST (6%)</th>
            <th style="text-align: right;">Total Amount</th>
            <th>Delivery Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($challans as $ch): ?>
            <tr>
              <td><code style="font-family: var(--font-mono); font-size: 0.8rem;"><?= htmlspecialchars($ch['challan_number']) ?></code></td>
              <td><strong><?= htmlspecialchars($ch['retailer_name']) ?></strong></td>
              <td><span style="font-family: var(--font-mono); font-size: 0.75rem;"><?= htmlspecialchars($ch['gstin_number']) ?></span></td>
              <td><?= date('d-M-Y', strtotime($ch['dispatch_date'])) ?></td>
              <td class="num-cell">₹<?= number_format($ch['taxable_amount'], 2) ?></td>
              <td class="num-cell">₹<?= number_format($ch['cgst_amount'], 2) ?></td>
              <td class="num-cell">₹<?= number_format($ch['sgst_amount'], 2) ?></td>
              <td class="num-cell" style="font-weight: 700; color: var(--color-primary-dark);">₹<?= number_format($ch['total_amount'], 2) ?></td>
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
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

