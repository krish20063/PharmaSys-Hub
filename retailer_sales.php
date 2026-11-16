<?php
$pageTitle = 'Sales Register & WhatsApp Invoices';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_role('retailer');

$pdo = Database::getConnection();

// Fetch sales invoices for this retailer
$stmt = $pdo->prepare("
    SELECT inv.*,
           (SELECT COUNT(*) FROM sales_items WHERE invoice_id = inv.id) as item_count
    FROM sales_invoices inv
    WHERE inv.retailer_id = :uid
    ORDER BY inv.id DESC
");
$stmt->execute([':uid' => $_SESSION['user_id']]);
$invoices = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
  <div style="margin-bottom: 1.5rem;">
    <h2 style="font-size: 1.4rem; font-weight: 700;">Sales Register &amp; WhatsApp Transmission Ledger</h2>
    <p style="font-size: 0.825rem; color: var(--text-subtle);">
      Audited digital tax invoices and Meta WhatsApp Cloud API electronic delivery status.
    </p>
  </div>

  <div class="card">
    <div class="card-header">
      <div>
        <h3 class="card-title">Completed Counter Transactions</h3>
        <p class="card-description">All transactions are validated against National Recall Registry before minting</p>
      </div>
      <span class="badge badge-success"><?= count($invoices) ?> Total Invoices</span>
    </div>

    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr>
            <th>Invoice Reference</th>
            <th>Customer Name</th>
            <th>WhatsApp Mobile</th>
            <th style="text-align: right;">Line Items</th>
            <th style="text-align: right;">Taxable (₹)</th>
            <th style="text-align: right;">GST 12% (₹)</th>
            <th style="text-align: right;">Grand Total (₹)</th>
            <th>Payment Mode</th>
            <th>WhatsApp Cloud API</th>
            <th>Timestamp</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($invoices)): ?>
            <tr>
              <td colspan="10" style="text-align: center; color: var(--text-subtle); padding: 2rem;">No sales records logged yet. Process a sale on the POS terminal.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($invoices as $inv): ?>
              <tr>
                <td>
                  <strong style="font-family: var(--font-mono); font-size: 0.825rem;"><?= htmlspecialchars($inv['invoice_number']) ?></strong>
                </td>
                <td><?= htmlspecialchars($inv['consumer_name']) ?></td>
                <td>
                  <span style="font-family: var(--font-mono); font-size: 0.8rem;">+91 <?= htmlspecialchars($inv['consumer_phone']) ?></span>
                </td>
                <td class="num-cell"><?= $inv['item_count'] ?> items</td>
                <td class="num-cell">₹<?= number_format($inv['subtotal'], 2) ?></td>
                <td class="num-cell">₹<?= number_format($inv['gst_amount'], 2) ?></td>
                <td class="num-cell" style="font-weight: 700; color: var(--color-primary-dark);">
                  ₹<?= number_format($inv['grand_total'], 2) ?>
                </td>
                <td>
                  <span class="badge badge-neutral"><?= htmlspecialchars($inv['payment_mode']) ?></span>
                </td>
                <td>
                  <span class="badge badge-success"><?= icon('check', 12) ?> Dispatched</span>
                </td>
                <td style="font-size: 0.775rem; color: var(--text-subtle);">
                  <?= date('d-M-Y H:i', strtotime($inv['created_at'])) ?>
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

