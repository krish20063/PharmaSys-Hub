<?php
$pageTitle = 'Bulk Procurement & Manufacturer Orders';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_role('distributor');

$pdo = Database::getConnection();
$distributorId = $_SESSION['user_id'];
$message = null;
$newOrderReceipt = null;

// Handle Bulk Procurement PO Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'place_procurement_order') {
    if (!verify_csrf_token()) {
        $message = ['type' => 'danger', 'text' => 'Security Error: CSRF token validation failed. Please refresh and try again.'];
    } else {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $quantity = max(10, (int)($_POST['quantity'] ?? 100));
        $destRack = clean_string($_POST['destination_rack'] ?? 'Bay-A1-Ambient');
        $paymentMode = clean_string($_POST['payment_mode'] ?? 'B2B Credit (30 Days)');

    if ($batchId <= 0) {
        $message = ['type' => 'danger', 'text' => 'Please select a valid certified manufacturer batch.'];
    } else {
        try {
            // Verify batch availability
            $batchStmt = $pdo->prepare("
                SELECT b.*, m.brand_name, m.generic_name, m.dosage_form, u.name as manufacturer_name, u.license_no as mfr_license
                FROM batches b
                JOIN medicine_master m ON b.medicine_id = m.id
                JOIN users u ON b.manufacturer_id = u.id
                WHERE b.id = :bid AND b.is_recalled = 0 AND b.is_expired = 0
            ");
            $batchStmt->execute([':bid' => $batchId]);
            $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);

            if (!$batch) {
                $message = ['type' => 'danger', 'text' => 'The selected batch is either recalled, expired, or unavailable.'];
            } else {
                $unitPrice = (float)$batch['distributor_price'];
                $taxableAmount = round($quantity * $unitPrice, 2);
                $cgstAmount = round($taxableAmount * 0.06, 2);
                $sgstAmount = round($taxableAmount * 0.06, 2);
                $totalAmount = round($taxableAmount + $cgstAmount + $sgstAmount, 2);
                $poNumber = 'PO-B2B-' . date('Y') . '-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
                $orderDate = date('Y-m-d');

                // 1. Insert into distributor_orders
                $orderStmt = $pdo->prepare("
                    INSERT INTO distributor_orders (
                        po_number, distributor_id, manufacturer_id, batch_id, quantity,
                        unit_price, taxable_amount, cgst_amount, sgst_amount, total_amount,
                        destination_rack, payment_mode, status, order_date
                    ) VALUES (
                        :po, :did, :mid, :bid, :qty,
                        :uprice, :taxable, :cgst, :sgst, :total,
                        :rack, :pmode, 'received_in_warehouse', :odate
                    )
                ");
                $orderStmt->execute([
                    ':po' => $poNumber,
                    ':did' => $distributorId,
                    ':mid' => $batch['manufacturer_id'],
                    ':bid' => $batchId,
                    ':qty' => $quantity,
                    ':uprice' => $unitPrice,
                    ':taxable' => $taxableAmount,
                    ':cgst' => $cgstAmount,
                    ':sgst' => $sgstAmount,
                    ':total' => $totalAmount,
                    ':rack' => $destRack,
                    ':pmode' => $paymentMode,
                    ':odate' => $orderDate
                ]);

                // 2. Inward stock into distributor's inventory
                $invCheck = $pdo->prepare("SELECT id, quantity FROM inventory WHERE user_id = :uid AND batch_id = :bid");
                $invCheck->execute([':uid' => $distributorId, ':bid' => $batchId]);
                $existingInv = $invCheck->fetch(PDO::FETCH_ASSOC);

                if ($existingInv) {
                    $updInv = $pdo->prepare("UPDATE inventory SET quantity = quantity + :qty, warehouse_rack_location = :rack, last_updated = CURRENT_TIMESTAMP WHERE id = :id");
                    $updInv->execute([':qty' => $quantity, ':rack' => $destRack, ':id' => $existingInv['id']]);
                } else {
                    $insInv = $pdo->prepare("INSERT INTO inventory (user_id, batch_id, quantity, warehouse_rack_location, min_threshold) VALUES (:uid, :bid, :qty, :rack, 50)");
                    $insInv->execute([':uid' => $distributorId, ':bid' => $batchId, ':qty' => $quantity, ':rack' => $destRack]);
                }

                // 3. Deduct allocated units from factory batch
                $updBatch = $pdo->prepare("UPDATE batches SET current_quantity = MAX(0, current_quantity - :qty) WHERE id = :bid");
                $updBatch->execute([':qty' => $quantity, ':bid' => $batchId]);

                // 4. Send notification to Manufacturer
                $notifStmt = $pdo->prepare("INSERT INTO notifications (target_role, title, message, type) VALUES ('manufacturer', '📦 New Bulk Procurement PO', :msg, 'info')");
                $distName = $_SESSION['user_name'] ?? 'Authorized Logistics Distributor';
                $notifStmt->execute([':msg' => "{$distName} placed Purchase Order {$poNumber} for {$quantity} units of Batch {$batch['batch_number']} ({$batch['brand_name']})."]);

                $message = [
                    'type' => 'success',
                    'text' => "Purchase Order {$poNumber} successfully placed! {$quantity} units of {$batch['brand_name']} inwarded directly into warehouse location {$destRack}."
                ];

                $newOrderReceipt = [
                    'po_number' => $poNumber,
                    'date' => $orderDate,
                    'distributor_name' => $distName,
                    'manufacturer_name' => $batch['manufacturer_name'],
                    'mfr_license' => $batch['mfr_license'],
                    'brand_name' => $batch['brand_name'],
                    'batch_number' => $batch['batch_number'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'taxable_amount' => $taxableAmount,
                    'cgst' => $cgstAmount,
                    'sgst' => $sgstAmount,
                    'total_amount' => $totalAmount,
                    'destination_rack' => $destRack,
                    'payment_mode' => $paymentMode
                ];
            }
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Order processing failed: ' . $e->getMessage()];
        }
    }
    } // End CSRF check
}

// Fetch available factory batches for purchase
$filterMfr = (int)($_GET['mfr'] ?? 0);
$filterCat = trim($_GET['cat'] ?? '');
$searchQ = trim($_GET['q'] ?? '');

$sql = "
    SELECT b.*, m.brand_name, m.generic_name, m.salt_composition, m.strength_mg, m.dosage_form, m.category,
           u.name as manufacturer_name, u.license_no as mfr_license, u.address as mfr_address
    FROM batches b
    JOIN medicine_master m ON b.medicine_id = m.id
    JOIN users u ON b.manufacturer_id = u.id
    WHERE b.is_recalled = 0 AND b.is_expired = 0 AND b.current_quantity > 0
";
$params = [];

if ($filterMfr > 0) {
    $sql .= " AND b.manufacturer_id = :mid";
    $params[':mid'] = $filterMfr;
}
if (!empty($filterCat)) {
    $sql .= " AND m.category = :cat";
    $params[':cat'] = $filterCat;
}
if (!empty($searchQ)) {
    $sql .= " AND (m.brand_name LIKE :q OR m.generic_name LIKE :q OR b.batch_number LIKE :q OR m.salt_composition LIKE :q)";
    $params[':q'] = "%{$searchQ}%";
}

$sql .= " ORDER BY b.id DESC LIMIT 40";
$batchQuery = $pdo->prepare($sql);
$batchQuery->execute($params);
$availableBatches = $batchQuery->fetchAll(PDO::FETCH_ASSOC);

// Fetch all manufacturers for filter
$manufacturers = $pdo->query("SELECT id, name, license_no FROM users WHERE role = 'manufacturer' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch distinct categories
$categories = $pdo->query("SELECT DISTINCT category FROM medicine_master ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

// Fetch past procurement orders for this distributor
$pastOrdersStmt = $pdo->prepare("
    SELECT o.*, b.batch_number, m.brand_name, m.strength_mg, m.dosage_form, u.name as manufacturer_name
    FROM distributor_orders o
    JOIN batches b ON o.batch_id = b.id
    JOIN medicine_master m ON b.medicine_id = m.id
    JOIN users u ON o.manufacturer_id = u.id
    WHERE o.distributor_id = :did
    ORDER BY o.id DESC
    LIMIT 20
");
$pastOrdersStmt->execute([':did' => $distributorId]);
$pastOrders = $pastOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

// Summary metrics
$totalProcuredUnits = array_sum(array_column($pastOrders, 'quantity'));
$totalProcuredSpend = array_sum(array_column($pastOrders, 'total_amount'));

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
  <!-- Page Header -->
  <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
      <h2 style="font-size: 1.45rem; font-weight: 800; color: #0F172A; display: flex; align-items: center; gap: 0.5rem;">
        <?= icon('cart', 22) ?> Bulk Procurement &amp; Manufacturer Purchasing
      </h2>
      <p style="font-size: 0.825rem; color: var(--text-subtle); margin-top: 0.25rem;">
        Direct factory batch acquisition: Place official B2B purchase orders with CDSCO-licensed pharmaceutical manufacturers and inward inventory automatically.
      </p>
    </div>
    <div style="display: flex; gap: 0.5rem;">
      <a href="distributor_inventory.php" class="btn btn-outline btn-sm">
        <?= icon('map-pin', 14) ?> View Warehouse Racks
      </a>
      <a href="distributor_challans.php" class="btn btn-outline btn-sm">
        <?= icon('file-text', 14) ?> Retailer Challans
      </a>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?= $message['type'] ?>" style="margin-bottom: 1.25rem;">
      <?= icon($message['type'] === 'success' ? 'check' : 'alert-triangle', 18) ?>
      <div><?= htmlspecialchars($message['text']) ?></div>
    </div>
  <?php endif; ?>

  <!-- Modal / Goods Receipt Slip for Newly Placed Order -->
  <?php if ($newOrderReceipt): ?>
    <div class="card" style="margin-bottom: 2rem; border: 2px solid #22C55E; background: #F0FDF4;">
      <div class="card-header" style="border-bottom: 1px solid #BBF7D0; background: #DCFCE7;">
        <div>
          <h3 class="card-title" style="color: #14532D; font-size: 1.15rem; display: flex; align-items: center; gap: 0.4rem;">
            <?= icon('check', 18) ?> Official B2B Purchase Order &amp; Inward Goods Receipt (IGR)
          </h3>
          <p class="card-description" style="color: #166534;">
            GST Tax Invoice &bull; PO Number: <strong><?= htmlspecialchars($newOrderReceipt['po_number']) ?></strong> &bull; Date: <?= htmlspecialchars($newOrderReceipt['date']) ?>
          </p>
        </div>
        <button type="button" class="btn btn-sm btn-outline" onclick="window.print()">
          <?= icon('file-text', 14) ?> Print Goods Receipt
        </button>
      </div>
      <div style="padding: 1.25rem;">
        <div class="grid-3" style="margin-bottom: 1rem;">
          <div>
            <div style="font-size: 0.72rem; color: #166534; font-weight: 700; text-transform: uppercase;">Purchaser (Distributor)</div>
            <div style="font-size: 0.9rem; font-weight: 800; color: #14532D;"><?= htmlspecialchars($newOrderReceipt['distributor_name']) ?></div>
            <div style="font-size: 0.75rem; color: #15803D;">GSTIN: 27AABCN8819Q1ZU</div>
            <div style="font-size: 0.75rem; color: #15803D;">Destination Rack: <strong><?= htmlspecialchars($newOrderReceipt['destination_rack']) ?></strong></div>
          </div>
          <div>
            <div style="font-size: 0.72rem; color: #166534; font-weight: 700; text-transform: uppercase;">Supplier (Manufacturer)</div>
            <div style="font-size: 0.9rem; font-weight: 800; color: #14532D;"><?= htmlspecialchars($newOrderReceipt['manufacturer_name']) ?></div>
            <div style="font-size: 0.75rem; color: #15803D;">License: <?= htmlspecialchars($newOrderReceipt['mfr_license']) ?></div>
            <div style="font-size: 0.75rem; color: #15803D;">Payment: <?= htmlspecialchars($newOrderReceipt['payment_mode']) ?></div>
          </div>
          <div style="text-align: right;">
            <div style="font-size: 0.72rem; color: #166534; font-weight: 700; text-transform: uppercase;">Total Invoice Payable</div>
            <div style="font-size: 1.45rem; font-weight: 800; color: #14532D;">₹<?= number_format($newOrderReceipt['total_amount'], 2) ?></div>
            <div style="font-size: 0.72rem; color: #15803D;">Taxable: ₹<?= number_format($newOrderReceipt['taxable_amount'], 2) ?> | GST (12%): ₹<?= number_format($newOrderReceipt['cgst'] + $newOrderReceipt['sgst'], 2) ?></div>
            <span class="badge badge-success" style="margin-top: 0.35rem;">Status: Inward Stocked</span>
          </div>
        </div>

        <div style="background: #FFFFFF; border: 1px solid #BBF7D0; border-radius: 6px; padding: 0.85rem; font-size: 0.85rem;">
          <div style="display: flex; justify-content: space-between;">
            <div>
              <strong><?= htmlspecialchars($newOrderReceipt['brand_name']) ?></strong> &bull; Batch: <code><?= htmlspecialchars($newOrderReceipt['batch_number']) ?></code>
            </div>
            <div>
              <?= number_format($newOrderReceipt['quantity']) ?> Units &times; ₹<?= number_format($newOrderReceipt['unit_price'], 2) ?> = <strong>₹<?= number_format($newOrderReceipt['taxable_amount'], 2) ?></strong>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- KPI Cards -->
  <div class="stats-grid" style="margin-bottom: 1.75rem;">
    <div class="stat-box">
      <div>
        <div class="stat-label">Available Factory Batches</div>
        <div class="stat-value"><?= count($availableBatches) ?></div>
        <div class="stat-subtext">Certified production lots across 8 plants</div>
      </div>
      <div class="stat-icon-wrapper stat-icon-blue"><?= icon('boxes', 20) ?></div>
    </div>

    <div class="stat-box">
      <div>
        <div class="stat-label">Procured Bulk Stock</div>
        <div class="stat-value"><?= number_format($totalProcuredUnits) ?></div>
        <div class="stat-subtext">Total pharmaceutical units inwarded</div>
      </div>
      <div class="stat-icon-wrapper stat-icon-green"><?= icon('truck', 20) ?></div>
    </div>

    <div class="stat-box">
      <div>
        <div class="stat-label">Procurement Orders</div>
        <div class="stat-value"><?= count($pastOrders) ?></div>
        <div class="stat-subtext">Active B2B purchase contracts executed</div>
      </div>
      <div class="stat-icon-wrapper stat-icon-blue"><?= icon('file-text', 20) ?></div>
    </div>

    <div class="stat-box">
      <div>
        <div class="stat-label">Total Procurement Value</div>
        <div class="stat-value" style="color: var(--color-primary);">₹<?= number_format($totalProcuredSpend / 1000, 1) ?>k</div>
        <div class="stat-subtext">Wholesale inventory capital deployed</div>
      </div>
      <div class="stat-icon-wrapper stat-icon-green"><?= icon('activity', 20) ?></div>
    </div>
  </div>

  <!-- Search and Filter Bar -->
  <div class="card" style="margin-bottom: 1.75rem; padding: 1.25rem;">
    <form method="GET" action="distributor_buy.php" style="display: flex; gap: 0.85rem; flex-wrap: wrap; align-items: flex-end;">
      <div style="flex: 2; min-width: 220px;">
        <label class="form-label" style="font-size: 0.75rem; font-weight: 700;">Search Medicine / Batch</label>
        <input type="text" name="q" class="form-control" placeholder="Search brand, active salt, or batch #..." value="<?= htmlspecialchars($searchQ) ?>">
      </div>

      <div style="flex: 1.5; min-width: 200px;">
        <label class="form-label" style="font-size: 0.75rem; font-weight: 700;">Filter by Manufacturer</label>
        <select name="mfr" class="form-control">
          <option value="">-- All 8 Manufacturers --</option>
          <?php foreach ($manufacturers as $m): ?>
            <option value="<?= $m['id'] ?>" <?= $filterMfr == $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="flex: 1.5; min-width: 180px;">
        <label class="form-label" style="font-size: 0.75rem; font-weight: 700;">Therapeutic Class</label>
        <select name="cat" class="form-control">
          <option value="">-- All Categories --</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>" <?= $filterCat === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <button type="submit" class="btn btn-primary" style="padding: 0.55rem 1.15rem;">
          <?= icon('search', 14) ?> Filter Batches
        </button>
        <?php if ($filterMfr || !empty($filterCat) || !empty($searchQ)): ?>
          <a href="distributor_buy.php" class="btn btn-outline" style="padding: 0.55rem 0.85rem;">Reset</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- Available Manufacturer Batches Catalog -->
  <div class="card" style="margin-bottom: 2.25rem;">
    <div class="card-header">
      <div>
        <h3 class="card-title">Direct Manufacturer Factory Inventory</h3>
        <p class="card-description">Select batches to procure directly from licensed pharma plants at negotiated distributor prices.</p>
      </div>
      <span class="badge badge-primary"><?= count($availableBatches) ?> Available Lots</span>
    </div>

    <div class="table-container">
      <table class="data-table">
        <thead>
          <tr>
            <th>Medicine Formulation</th>
            <th>Licensed Manufacturer</th>
            <th>Batch &amp; Expiry</th>
            <th>Factory Stock</th>
            <th>Pricing &amp; Margins</th>
            <th>Storage Class</th>
            <th style="text-align: right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($availableBatches)): ?>
            <tr>
              <td colspan="7" style="text-align: center; color: var(--text-subtle); padding: 2rem;">
                No active factory batches match your search criteria. Try resetting filters.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($availableBatches as $b): ?>
              <?php
                $distPrice = (float)$b['distributor_price'];
                $mrp = (float)$b['mrp_unit'];
                $marginPercent = $mrp > 0 ? round((($mrp - $distPrice) / $mrp) * 100, 1) : 0;
                $isColdChain = str_contains(strtolower($b['category']), 'biological') || str_contains(strtolower($b['dosage_form']), 'injection');
              ?>
              <tr>
                <td>
                  <strong style="color: var(--text-main); font-size: 0.95rem;"><?= htmlspecialchars($b['brand_name']) ?></strong>
                  <div style="font-size: 0.75rem; color: var(--text-subtle);">
                    <?= htmlspecialchars($b['generic_name']) ?> &bull; <code><?= htmlspecialchars($b['salt_composition']) ?></code>
                  </div>
                  <div style="margin-top: 0.25rem;">
                    <span class="badge badge-neutral"><?= $b['strength_mg'] ?> mg</span>
                    <span class="badge badge-neutral"><?= htmlspecialchars($b['dosage_form']) ?></span>
                  </div>
                </td>
                <td>
                  <div style="font-weight: 700; color: #0F172A; font-size: 0.85rem;"><?= htmlspecialchars($b['manufacturer_name']) ?></div>
                  <div style="font-size: 0.72rem; color: var(--text-subtle); font-family: var(--font-mono);"><?= htmlspecialchars($b['mfr_license']) ?></div>
                  <div style="font-size: 0.7rem; color: var(--text-muted);"><?= htmlspecialchars($b['mfr_address']) ?></div>
                </td>
                <td>
                  <div style="font-family: var(--font-mono); font-weight: 700; color: var(--color-primary-dark); font-size: 0.85rem;">
                    <?= htmlspecialchars($b['batch_number']) ?>
                  </div>
                  <div style="font-size: 0.72rem; color: var(--text-subtle);">
                    Mfg: <?= htmlspecialchars($b['mfg_date']) ?>
                  </div>
                  <div style="font-size: 0.75rem; font-weight: 600; color: #16A34A;">
                    Exp: <?= htmlspecialchars($b['expiry_date']) ?>
                  </div>
                </td>
                <td>
                  <div style="font-size: 1.05rem; font-weight: 800; color: #0F172A;"><?= number_format($b['current_quantity']) ?></div>
                  <span style="font-size: 0.7rem; color: var(--text-subtle);">Units Available</span>
                </td>
                <td>
                  <div style="font-size: 1rem; font-weight: 800; color: var(--color-primary-dark);">
                    ₹<?= number_format($distPrice, 2) ?>
                  </div>
                  <div style="font-size: 0.72rem; color: var(--text-subtle);">
                    MRP: ₹<?= number_format($mrp, 2) ?>
                  </div>
                  <span class="badge badge-success" style="font-size: 0.65rem; margin-top: 0.15rem;">
                    +<?= $marginPercent ?>% Margin
                  </span>
                </td>
                <td>
                  <?php if ($isColdChain): ?>
                    <span class="badge badge-warning" style="display: inline-flex; align-items: center; gap: 0.25rem;">
                      ❄️ Cold-Chain (2°C - 8°C)
                    </span>
                  <?php else: ?>
                    <span class="badge badge-neutral">
                      🌡️ Ambient Warehouse
                    </span>
                  <?php endif; ?>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn btn-primary btn-sm" 
                          onclick="openBuyModal(<?= htmlspecialchars(json_encode([
                            'id' => $b['id'],
                            'brand_name' => $b['brand_name'],
                            'batch_number' => $b['batch_number'],
                            'manufacturer_name' => $b['manufacturer_name'],
                            'distributor_price' => $distPrice,
                            'mrp_unit' => $mrp,
                            'current_quantity' => $b['current_quantity'],
                            'is_cold_chain' => $isColdChain
                          ])) ?>)">
                    <?= icon('cart', 14) ?> Procure Bulk
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Past Procurement Orders Table -->
  <div class="card">
    <div class="card-header">
      <div>
        <h3 class="card-title">Procurement Order History &amp; Inward Receipts</h3>
        <p class="card-description">Audit record of manufacturer purchase orders executed by this logistics facility.</p>
      </div>
      <span class="badge badge-neutral"><?= count($pastOrders) ?> Executed POs</span>
    </div>

    <div class="table-container">
      <table class="data-table">
        <thead>
          <tr>
            <th>PO Number</th>
            <th>Order Date</th>
            <th>Manufacturer Plant</th>
            <th>Medicine &amp; Batch</th>
            <th>Quantity Inwarded</th>
            <th>Total Amount (GST Incl.)</th>
            <th>Warehouse Rack</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($pastOrders)): ?>
            <tr>
              <td colspan="8" style="text-align: center; color: var(--text-subtle); padding: 2rem;">
                No manufacturer purchase orders placed yet. Select any batch above to place your first bulk procurement order.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($pastOrders as $po): ?>
              <tr>
                <td>
                  <span style="font-family: var(--font-mono); font-weight: 700; color: var(--color-primary); font-size: 0.85rem;">
                    <?= htmlspecialchars($po['po_number']) ?>
                  </span>
                  <div style="font-size: 0.7rem; color: var(--text-subtle);"><?= htmlspecialchars($po['payment_mode']) ?></div>
                </td>
                <td><?= htmlspecialchars($po['order_date']) ?></td>
                <td>
                  <strong style="font-size: 0.825rem;"><?= htmlspecialchars($po['manufacturer_name']) ?></strong>
                </td>
                <td>
                  <strong><?= htmlspecialchars($po['brand_name']) ?></strong> (<?= $po['strength_mg'] ?> mg)
                  <div style="font-size: 0.72rem; color: var(--text-subtle); font-family: var(--font-mono);">
                    Batch: <?= htmlspecialchars($po['batch_number']) ?>
                  </div>
                </td>
                <td>
                  <strong><?= number_format($po['quantity']) ?></strong> units
                </td>
                <td>
                  <strong style="color: #0F172A;">₹<?= number_format($po['total_amount'], 2) ?></strong>
                  <div style="font-size: 0.7rem; color: var(--text-subtle);">Base: ₹<?= number_format($po['taxable_amount'], 2) ?></div>
                </td>
                <td>
                  <span class="badge badge-neutral" style="font-family: var(--font-mono);">
                    <?= htmlspecialchars($po['destination_rack']) ?>
                  </span>
                </td>
                <td>
                  <span class="badge badge-success">Stock Inwarded</span>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal Dialog: Bulk Procurement Purchase Order -->
<div id="buy-order-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1050; align-items: center; justify-content: center; padding: 1rem;">
  <div class="card" style="max-width: 520px; width: 100%; box-shadow: 0 20px 45px rgba(0,0,0,0.25); animation: modalIn 0.2s ease-out;">
    <div class="card-header" style="background: var(--bg-surface); border-bottom: 1px solid var(--border-light);">
      <div>
        <h3 class="card-title" style="font-size: 1.15rem; display: flex; align-items: center; gap: 0.4rem;">
          <?= icon('cart', 18) ?> Bulk Procurement Purchase Order
        </h3>
        <p class="card-description" id="modal-drug-subtitle">Acquire factory batch directly into warehouse inventory</p>
      </div>
      <button type="button" class="btn btn-outline btn-sm" onclick="closeBuyModal()" style="padding: 0.25rem 0.5rem;">✕</button>
    </div>

    <form method="POST" action="distributor_buy.php" style="padding: 1.5rem;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="place_procurement_order">
      <input type="hidden" name="batch_id" id="modal-batch-id" value="">

      <!-- Drug Summary Callout -->
      <div style="background: #F8FAFC; border: 1px solid var(--border-light); border-radius: 6px; padding: 0.85rem; margin-bottom: 1.25rem;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
          <div>
            <h4 id="modal-drug-name" style="font-size: 1.05rem; font-weight: 800; color: #0F172A; margin: 0;"></h4>
            <div id="modal-mfr-name" style="font-size: 0.78rem; color: var(--text-subtle); margin-top: 0.15rem;"></div>
            <div id="modal-batch-no" style="font-size: 0.75rem; font-family: var(--font-mono); color: var(--color-primary); margin-top: 0.25rem;"></div>
          </div>
          <div style="text-align: right;">
            <div style="font-size: 0.75rem; color: var(--text-subtle);">Distributor Rate:</div>
            <div id="modal-unit-price" style="font-size: 1.2rem; font-weight: 800; color: #16A34A;"></div>
            <div id="modal-mrp-price" style="font-size: 0.72rem; color: var(--text-muted);"></div>
          </div>
        </div>
      </div>

      <!-- Quantity Selector -->
      <div class="form-group" style="margin-bottom: 1rem;">
        <label class="form-label" for="order-quantity" style="display: flex; justify-content: space-between;">
          <span>Procurement Quantity (Units)</span>
          <span style="font-size: 0.75rem; color: var(--text-subtle);" id="modal-max-qty">Max: 10,000 units</span>
        </label>
        <div style="display: flex; gap: 0.5rem;">
          <input type="number" id="order-quantity" name="quantity" class="form-control" min="1" max="25000" step="1" value="500" required oninput="calculateOrderTotal()">
          <button type="button" class="btn btn-outline btn-sm" onclick="setQty(250)">250</button>
          <button type="button" class="btn btn-outline btn-sm" onclick="setQty(500)">500</button>
          <button type="button" class="btn btn-outline btn-sm" onclick="setQty(1000)">1,000</button>
          <button type="button" class="btn btn-outline btn-sm" onclick="setQty(2500)">2,500</button>
        </div>
      </div>

      <!-- Destination Warehouse Rack -->
      <div class="form-group" style="margin-bottom: 1rem;">
        <label class="form-label" for="dest-rack">Inward Destination Rack / Bay</label>
        <select id="dest-rack" name="destination_rack" class="form-control" required>
          <option value="Bay-A1-Ambient">Bay-A1-Ambient (Standard Oral Formulations)</option>
          <option value="Bay-A2-Ambient">Bay-A2-Ambient (High Capacity Pallet Zone)</option>
          <option value="Bay-B1-Secured">Bay-B1-Secured (Schedule H1 Controlled Storage)</option>
          <option value="ColdChain-Vault-01">ColdChain-Vault-01 (Biologicals &amp; Vaccines 2°C - 8°C)</option>
          <option value="ColdChain-Freezer-02">ColdChain-Freezer-02 (Low Temperature Deep Storage)</option>
          <option value="Depot-Zone-East">Depot-Zone-East (Fast-Moving B2B Staging)</option>
        </select>
      </div>

      <!-- B2B Payment Mode -->
      <div class="form-group" style="margin-bottom: 1.25rem;">
        <label class="form-label" for="pay-mode">B2B Commercial Payment Terms</label>
        <select id="pay-mode" name="payment_mode" class="form-control">
          <option value="B2B Credit (30 Days)">B2B Credit (30 Days Interest-Free Term)</option>
          <option value="RTGS / NEFT Direct Bank Transfer">RTGS / NEFT Direct Bank Transfer</option>
          <option value="Corporate UPI Autopay">Corporate UPI Autopay</option>
          <option value="Letter of Credit (LC)">Letter of Credit (LC) Secured</option>
        </select>
      </div>

      <!-- Live Calculation Breakdown -->
      <div style="background: #F1F5F9; border-radius: 6px; padding: 0.85rem; margin-bottom: 1.25rem; font-size: 0.825rem;">
        <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
          <span style="color: var(--text-subtle);">Taxable Value:</span>
          <span id="calc-taxable" style="font-weight: 700;">₹0.00</span>
        </div>
        <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
          <span style="color: var(--text-subtle);">CGST (6%):</span>
          <span id="calc-cgst" style="font-weight: 700;">₹0.00</span>
        </div>
        <div style="display: flex; justify-content: space-between; margin-bottom: 0.4rem;">
          <span style="color: var(--text-subtle);">SGST (6%):</span>
          <span id="calc-sgst" style="font-weight: 700;">₹0.00</span>
        </div>
        <div style="border-top: 1px solid #CBD5E1; padding-top: 0.4rem; display: flex; justify-content: space-between; font-size: 0.95rem;">
          <span style="font-weight: 800; color: #0F172A;">Net Invoice Payable:</span>
          <span id="calc-total" style="font-weight: 800; color: #16A34A;">₹0.00</span>
        </div>
      </div>

      <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
        <button type="button" class="btn btn-outline" onclick="closeBuyModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" style="padding: 0.65rem 1.25rem;">
          <?= icon('check', 16) ?> Submit Purchase Order &amp; Inward Stock
        </button>
      </div>
    </form>
  </div>
</div>

<script>
let currentUnitPrice = 0;

function openBuyModal(batch) {
  currentUnitPrice = batch.distributor_price;
  document.getElementById('modal-batch-id').value = batch.id;
  document.getElementById('modal-drug-name').textContent = batch.brand_name;
  document.getElementById('modal-mfr-name').textContent = 'Manufacturer: ' + batch.manufacturer_name;
  document.getElementById('modal-batch-no').textContent = 'Batch Lot: #' + batch.batch_number;
  document.getElementById('modal-unit-price').textContent = '₹' + batch.distributor_price.toFixed(2);
  document.getElementById('modal-mrp-price').textContent = 'Retail MRP: ₹' + batch.mrp_unit.toFixed(2);
  document.getElementById('modal-max-qty').textContent = 'Factory Stock: ' + batch.current_quantity.toLocaleString() + ' units';

  // Suggest cold chain rack if biological/injection
  const rackSelect = document.getElementById('dest-rack');
  if (batch.is_cold_chain) {
    rackSelect.value = 'ColdChain-Vault-01';
  } else {
    rackSelect.value = 'Bay-A1-Ambient';
  }

  calculateOrderTotal();
  const modal = document.getElementById('buy-order-modal');
  modal.style.display = 'flex';
}

function closeBuyModal() {
  document.getElementById('buy-order-modal').style.display = 'none';
}

function setQty(val) {
  document.getElementById('order-quantity').value = val;
  calculateOrderTotal();
}

function calculateOrderTotal() {
  const qty = parseInt(document.getElementById('order-quantity').value) || 0;
  const taxable = qty * currentUnitPrice;
  const cgst = taxable * 0.06;
  const sgst = taxable * 0.06;
  const total = taxable + cgst + sgst;

  document.getElementById('calc-taxable').textContent = '₹' + taxable.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  document.getElementById('calc-cgst').textContent = '₹' + cgst.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  document.getElementById('calc-sgst').textContent = '₹' + sgst.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  document.getElementById('calc-total').textContent = '₹' + total.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

// Close on backdrop click
document.getElementById('buy-order-modal').addEventListener('click', function(e) {
  if (e.target === this) {
    closeBuyModal();
  }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

