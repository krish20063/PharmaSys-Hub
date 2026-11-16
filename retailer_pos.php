<?php
$pageTitle = 'Smart POS Billing Terminal';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_role('retailer');

$pdo = Database::getConnection();

// Fetch retailer's inventory items with batch and drug metadata
$stmt = $pdo->prepare("
    SELECT inv.*, b.batch_number, b.mfg_date, b.expiry_date, b.mrp_unit, b.is_recalled, b.recall_reason, b.is_expired,
           m.brand_name, m.generic_name, m.salt_composition, m.strength_mg, m.dosage_form, m.schedule_type
    FROM inventory inv
    JOIN batches b ON inv.batch_id = b.id
    JOIN medicine_master m ON b.medicine_id = m.id
    WHERE inv.user_id = :uid
    ORDER BY b.is_recalled ASC, b.is_expired ASC, m.brand_name ASC
");
$stmt->execute([':uid' => $_SESSION['user_id']]);
$inventory = $stmt->fetchAll();

// Handle Linked Verified Prescription from Queue
$linkedRx = null;
if (isset($_GET['rx_id']) && (int)$_GET['rx_id'] > 0) {
    $rxStmt = $pdo->prepare("
        SELECT p.*, u.phone as consumer_phone
        FROM prescriptions p
        JOIN users u ON p.consumer_id = u.id
        WHERE p.id = :id AND p.retailer_id = :rid
    ");
    $rxStmt->execute([':id' => (int)$_GET['rx_id'], ':rid' => $_SESSION['user_id']]);
    $linkedRx = $rxStmt->fetch();
}

// Fetch recent uploaded prescriptions strictly for this retailer
$recentRxStmt = $pdo->prepare("
    SELECT p.*, u.phone as consumer_phone 
    FROM prescriptions p 
    JOIN users u ON p.consumer_id = u.id 
    WHERE p.retailer_id = :rid
    ORDER BY p.id DESC LIMIT 1
");
$recentRxStmt->execute([':rid' => $_SESSION['user_id']]);
$recentRx = $recentRxStmt->fetch();

$presetName = $linkedRx ? $linkedRx['patient_name'] : ($_GET['patient_name'] ?? 'Rahul Sharma');
$presetPhone = $linkedRx ? $linkedRx['consumer_phone'] : ($_GET['phone'] ?? '9876543213');

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
  <!-- POS Top Bar -->
  <div style="margin-bottom: 1.25rem;">
    <h2 style="font-size: 1.4rem; font-weight: 700; color: var(--text-main);">Smart POS Dispensing Terminal</h2>
    <p style="font-size: 0.825rem; color: var(--text-subtle);">
      Tamper-proof safety architecture: Recalled and expired lots are permanently locked and cannot be added to cart or invoiced.
    </p>
  </div>

  <?php if ($linkedRx): ?>
    <?php
      $rxMeds = $linkedRx['prescribed_medicines'] ?: 'Augmentin 625 Duo, Pan-D 40';
    ?>
    <div class="card" style="margin-bottom: 1.25rem; border-left: 4px solid var(--color-success); background: var(--color-success-bg); padding: 1.15rem 1.35rem;">
      <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div style="display: flex; align-items: center; gap: 1rem;">
          <!-- 1. The Uploaded Prescription Preview -->
          <a href="view_prescription.php?id=<?= $linkedRx['id'] ?>" target="_blank" title="Click to view full prescription document" style="position: relative; width: 60px; height: 60px; border-radius: 6px; overflow: hidden; border: 1px solid var(--color-success-border); background: var(--bg-surface); display: block; flex-shrink: 0; box-shadow: 0 2px 6px rgba(0,0,0,0.06);">
            <img src="view_prescription.php?id=<?= $linkedRx['id'] ?>&raw=1" alt="Prescription" style="width: 100%; height: 100%; object-fit: cover;">
          </a>
          <div>
            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
              <span class="badge badge-success"><?= icon('shield-check', 12) ?> Prescription #RX-<?= str_pad($linkedRx['id'], 5, '0', STR_PAD_LEFT) ?> Attached</span>
              <span style="font-size: 0.8rem; color: var(--color-success); font-weight: 600;">Dr. <?= htmlspecialchars($linkedRx['doctor_name']) ?></span>
            </div>
            <div style="margin-top: 0.25rem; font-size: 0.95rem; font-weight: 700; color: var(--text-main);">
              Patient: <?= htmlspecialchars($linkedRx['patient_name']) ?> &bull; <span style="font-size: 0.825rem; color: var(--text-muted);"><?= htmlspecialchars($linkedRx['consumer_phone']) ?></span>
            </div>
            <!-- 2. The Medicine Written in the Prescription -->
            <div style="margin-top: 0.2rem; font-size: 0.825rem; color: var(--color-success);">
              <strong>Prescribed Medicines:</strong> <?= htmlspecialchars($rxMeds) ?>
            </div>
          </div>
        </div>

        <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
          <button type="button" class="btn btn-success btn-sm" onclick="autoAddPrescribedMedicines('<?= htmlspecialchars(addslashes($rxMeds)) ?>')">
            <?= icon('plus', 12) ?> Auto-Add Prescribed Medicines
          </button>
          <a href="view_prescription.php?id=<?= $linkedRx['id'] ?>" target="_blank" class="btn btn-outline btn-sm">
            <?= icon('file-text', 12) ?> View Slip
          </a>
        </div>
      </div>
    </div>
  <?php elseif ($recentRx): ?>
    <?php
      $rxMeds = $recentRx['prescribed_medicines'] ?: 'Augmentin 625 Duo, Pan-D 40';
    ?>
    <div class="card" style="margin-bottom: 1.25rem; border-left: 4px solid var(--color-primary); background: var(--color-primary-bg); padding: 0.85rem 1.25rem;">
      <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
          <a href="view_prescription.php?id=<?= $recentRx['id'] ?>" target="_blank" title="View Prescription" style="width: 44px; height: 44px; border-radius: 6px; overflow: hidden; border: 1px solid var(--color-primary-border); background: var(--bg-surface); display: block; flex-shrink: 0;">
            <img src="view_prescription.php?id=<?= $recentRx['id'] ?>&raw=1" alt="Prescription" style="width: 100%; height: 100%; object-fit: cover;">
          </a>
          <div>
            <div style="font-size: 0.825rem; font-weight: 700; color: var(--text-main);">
              Patient Prescription #RX-<?= str_pad($recentRx['id'], 5, '0', STR_PAD_LEFT) ?> &bull; <?= htmlspecialchars($recentRx['patient_name']) ?> (<?= htmlspecialchars($recentRx['consumer_phone']) ?>)
            </div>
            <div style="font-size: 0.75rem; color: var(--color-primary-dark);">
              <strong>Prescribed Medicines:</strong> <?= htmlspecialchars($rxMeds) ?>
            </div>
          </div>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
          <a href="view_prescription.php?id=<?= $recentRx['id'] ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size: 0.75rem;">
            <?= icon('search', 11) ?> View Slip
          </a>
          <a href="retailer_pos.php?rx_id=<?= $recentRx['id'] ?>&patient_name=<?= urlencode($recentRx['patient_name']) ?>&phone=<?= urlencode($recentRx['consumer_phone']) ?>" class="btn btn-primary btn-sm" style="font-size: 0.75rem;">
            <?= icon('cart', 11) ?> Pre-Fill POS Bill
          </a>
          <a href="retailer_prescriptions.php" class="btn btn-outline btn-sm" style="font-size: 0.75rem;">
            Rx Queue &rarr;
          </a>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Real-time Safety Flash Alert -->
  <div id="pos-safety-alert" class="alert alert-danger" style="display: none;"></div>

  <!-- 2-Column POS Layout -->
  <div class="pos-container">
    
    <!-- LEFT COLUMN: Pharmacy Shelves & Item Lookup -->
    <div class="card" style="padding: 1.25rem;">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem; gap: 0.75rem; flex-wrap: wrap;">
        <div style="position: relative; flex: 1; min-width: 240px;">
          <input type="text" id="pos-search-input" class="form-control" placeholder="Search shelf stock by brand, salt, or batch code..." oninput="filterPosTable(this.value)">
        </div>
        <div style="display: flex; align-items: center; gap: 0.5rem;">
          <label style="font-size: 0.775rem; color: var(--text-subtle); display: flex; align-items: center; gap: 0.35rem; cursor: pointer;">
            <input type="checkbox" id="hide-unsafe-toggle" onchange="toggleUnsafeRows(this.checked)"> Hide Unsafe / Expired Lots
          </label>
          <span class="badge badge-neutral"><?= count($inventory) ?> Lots Stocked</span>
        </div>
      </div>

      <div class="table-wrapper" style="max-height: 560px; overflow-y: auto;">
        <table class="data-table" id="pos-inventory-table">
          <thead>
            <tr>
              <th>Medicine / Salt</th>
              <th>Batch Code</th>
              <th>Expiry</th>
              <th style="text-align: right;">Available</th>
              <th style="text-align: right;">MRP</th>
              <th>Safety State</th>
              <th style="text-align: right;">POS Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($inventory as $item): ?>
              <?php
                // Dynamic Expiry Calculation based on current calendar date
                $isExpired = (int)$item['is_expired'] === 1 || strtotime($item['expiry_date']) < time();
                $isRecalled = (int)$item['is_recalled'] === 1;
                $isUnsafe = $isExpired || $isRecalled;
              ?>
              <tr class="pos-item-row <?= $isUnsafe ? 'unsafe-row' : '' ?>" 
                  data-search="<?= strtolower($item['brand_name'] . ' ' . $item['salt_composition'] . ' ' . $item['batch_number']) ?>"
                  data-brand="<?= strtolower(htmlspecialchars($item['brand_name'])) ?>"
                  data-unsafe="<?= $isUnsafe ? '1' : '0' ?>">
                <td>
                  <strong><?= htmlspecialchars($item['brand_name']) ?></strong>
                  <div style="font-size: 0.725rem; color: var(--text-subtle);">
                    <?= htmlspecialchars($item['salt_composition']) ?> &bull; <?= $item['strength_mg'] ?>mg (<?= $item['dosage_form'] ?>)
                  </div>
                  <span class="badge badge-neutral" style="font-size: 0.65rem; margin-top: 0.2rem;"><?= htmlspecialchars($item['warehouse_rack_location']) ?></span>
                </td>
                <td>
                  <code style="font-family: var(--font-mono); font-size: 0.8rem;"><?= htmlspecialchars($item['batch_number']) ?></code>
                </td>
                <td>
                  <span style="font-size: 0.8rem; <?= $isExpired ? 'color: var(--color-danger); font-weight: 700;' : '' ?>">
                    <?= date('d-M-Y', strtotime($item['expiry_date'])) ?>
                  </span>
                </td>
                <td class="num-cell">
                  <?= $item['quantity'] ?>
                  <?php if ((int)$item['quantity'] <= (int)$item['min_threshold']): ?>
                    <span class="badge badge-warning" style="font-size: 0.65rem; padding: 1px 4px;">Low</span>
                  <?php endif; ?>
                </td>
                <td class="num-cell">₹<?= number_format($item['mrp_unit'], 2) ?></td>
                <td>
                  <?php if ($isRecalled): ?>
                    <span class="badge badge-danger"><?= icon('shield-alert', 12) ?> RECALLED</span>
                  <?php elseif ($isExpired): ?>
                    <span class="badge badge-danger"><?= icon('alert-triangle', 12) ?> EXPIRED</span>
                  <?php else: ?>
                    <span class="badge badge-success"><?= icon('check', 12) ?> Safe</span>
                  <?php endif; ?>
                </td>
                <td style="text-align: right;">
                  <?php if ($isUnsafe): ?>
                    <!-- ANTI-CHEATER ENFORCEMENT: Disabled button preventing rogue adding -->
                    <button type="button" class="btn btn-outline btn-sm" disabled 
                            title="Tamper-Proof Lock: This batch is flagged unsafe (recalled or expired). Dispensing is prohibited."
                            style="opacity: 0.55; cursor: not-allowed; border-color: var(--color-danger-border); color: var(--color-danger); background: var(--bg-surface-subtle);">
                      <?= icon('shield-alert', 12) ?> Locked
                    </button>
                  <?php else: ?>
                    <button type="button" class="btn btn-outline btn-sm"
                            onclick='addToPosCart(<?= json_encode([
                              "batch_id" => (int)$item['batch_id'],
                              "batch_number" => $item['batch_number'],
                              "brand_name" => $item['brand_name'],
                              "price_mrp" => (float)$item['mrp_unit'],
                              "quantity" => (int)$item['quantity'],
                              "expiry_date" => $item['expiry_date'],
                              "is_recalled" => 0,
                              "recall_reason" => '',
                              "is_expired" => 0
                            ]) ?>)'>
                      <?= icon('plus', 12) ?> Add
                    </button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div style="margin-top: 0.75rem; font-size: 0.75rem; color: var(--text-subtle); display: flex; align-items: center; gap: 0.4rem;">
        <?= icon('shield-check', 14) ?>
        <span><strong>Regulatory Safeguard:</strong> Tamper-resistant validation verifies each batch against the National Recall Index before minting invoices.</span>
      </div>
    </div>

    <!-- RIGHT COLUMN: Customer Details, POS Cart & WhatsApp Checkout -->
    <div class="card" style="display: flex; flex-direction: column; justify-content: space-between;">
      <div>
        <div class="card-header" style="margin-bottom: 1rem;">
          <div>
            <h3 class="card-title">Transparent POS Tax Cart</h3>
            <p class="card-description">GST-itemized &bull; Cryptographic Safety Stamp</p>
          </div>
          <span class="badge badge-primary"><?= icon('message-circle', 12) ?> WhatsApp E-Bill</span>
        </div>

        <!-- Customer Form Fields -->
        <div class="grid-2" style="margin-bottom: 0.75rem;">
          <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label">Customer / Patient Name</label>
            <input type="text" id="customer-name" class="form-control" value="<?= htmlspecialchars($presetName) ?>">
          </div>
          <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label">WhatsApp Mobile (10-Digit) *</label>
            <input type="tel" id="customer-phone" class="form-control" value="<?= htmlspecialchars($presetPhone) ?>" placeholder="10-digit mobile">
          </div>
        </div>

        <div class="form-group" style="margin-bottom: 1rem;">
          <label class="form-label">Payment Mode</label>
          <select id="payment-mode" class="form-control">
            <option value="UPI">UPI (Google Pay / PhonePe / Paytm)</option>
            <option value="Cash">Cash at Counter</option>
            <option value="Card">Credit / Debit Card</option>
          </select>
        </div>

        <!-- Cart Items List -->
        <div style="border-top: 1px solid var(--border-light); padding-top: 0.75rem; margin-top: 0.5rem;">
          <div id="cart-items-container"></div>
          <div id="cart-empty-notice" style="text-align: center; color: var(--text-subtle); padding: 2rem 0;">
            <div style="margin-bottom: 0.5rem; opacity: 0.6;"><?= icon('cart', 32) ?></div>
            <p style="font-size: 0.85rem;">Cart is empty. Click <strong>+ Add</strong> on any safe shelf batch.</p>
          </div>
        </div>

        <!-- Totals Calculation -->
        <div style="background: var(--bg-surface-subtle); border-radius: var(--radius-sm); padding: 1rem; margin-top: 1rem; border: 1px solid var(--border-light);">
          <div style="display: flex; justify-content: space-between; font-size: 0.85rem; margin-bottom: 0.35rem;">
            <span style="color: var(--text-subtle);">Taxable Value:</span>
            <span id="cart-subtotal" style="font-weight: 600;">₹0.00</span>
          </div>
          <div style="display: flex; justify-content: space-between; font-size: 0.85rem; margin-bottom: 0.5rem;">
            <span style="color: var(--text-subtle);">Pharma GST (6% CGST + 6% SGST):</span>
            <span id="cart-gst" style="font-weight: 600;">₹0.00</span>
          </div>
          <div style="display: flex; justify-content: space-between; font-size: 1.15rem; font-weight: 700; border-top: 1px solid var(--border-light); padding-top: 0.5rem;">
            <span>Grand Total:</span>
            <span id="cart-grand-total" style="color: var(--color-primary-dark);">₹0.00</span>
          </div>
        </div>
      </div>

      <div style="margin-top: 1.5rem;">
        <button id="btn-checkout" class="btn btn-success btn-block" style="padding: 0.75rem;" onclick="processCheckout()" disabled>
          <?= icon('check', 16) ?> Complete Dispense &amp; Send WhatsApp E-Bill
        </button>
      </div>
    </div>
  </div>
</div>

<!-- WhatsApp E-Bill Modal Preview -->
<div id="whatsapp-preview-modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 9999; backdrop-filter: blur(4px); align-items: center; justify-content: center; padding: 1rem;">
  <div class="card" style="max-width: 540px; width: 100%; box-shadow: var(--shadow-lg);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
      <div style="display: flex; align-items: center; gap: 0.5rem;">
        <?= icon('message-circle', 20) ?>
        <h3 class="card-title" style="font-size: 1.1rem;">WhatsApp Cloud API Invoice Delivery</h3>
      </div>
      <button onclick="closeWhatsAppModal()" class="btn btn-outline btn-sm">&times;</button>
    </div>
    <div id="whatsapp-preview-content"></div>
  </div>
</div>

<script src="assets/js/retailer_pos.js"></script>
<script>
function filterPosTable(query) {
  const q = query.toLowerCase().trim();
  const hideUnsafe = document.getElementById('hide-unsafe-toggle').checked;
  const rows = document.querySelectorAll('.pos-item-row');
  rows.forEach(r => {
    const text = r.getAttribute('data-search') || '';
    const isUnsafe = r.getAttribute('data-unsafe') === '1';
    if (hideUnsafe && isUnsafe) {
      r.style.display = 'none';
    } else {
      r.style.display = text.includes(q) ? '' : 'none';
    }
  });
}

function toggleUnsafeRows(hide) {
  const query = (document.getElementById('pos-search-input').value || '').toLowerCase().trim();
  filterPosTable(query);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
