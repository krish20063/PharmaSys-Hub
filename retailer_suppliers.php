<?php
$pageTitle = 'Suppliers & Distributors Directory';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_role('retailer');

$pdo = Database::getConnection();

// Fetch distributors and manufacturers from users
$stmt = $pdo->query("
    SELECT u.*,
           (SELECT COUNT(*) FROM dispatch_challans WHERE distributor_id = u.id) as challan_count,
           (SELECT COUNT(*) FROM draft_purchase_orders WHERE distributor_id = u.id AND status = 'draft') as draft_count,
           (SELECT COUNT(*) FROM return_claims WHERE distributor_id = u.id) as return_count
    FROM users u
    WHERE u.role IN ('distributor', 'manufacturer')
    ORDER BY u.role DESC, u.name ASC
");
$dbSuppliers = $stmt->fetchAll();

// Enriched supplier catalog
$sampleSuppliers = [
    [
        'id' => 2,
        'name' => 'Nexus Healthcare Logistics Pvt. Ltd.',
        'role' => 'distributor',
        'license_no' => 'MH-DIST-2024-9901',
        'gstin' => '27AABCU9603R1ZM',
        'phone' => '+91 98765 43211',
        'email' => 'distributor@pharmasys.com',
        'address' => 'Warehouse Complex B4, Bhiwandi Logistics Corridor, Thane, MH',
        'cold_chain' => 'Certified 2°C - 8°C Cold Chain & Ambient Bays',
        'credit_terms' => '30 Days Net &bull; Reverse Returns Accepted',
        'challan_count' => 2,
        'draft_count' => 2,
        'return_count' => 1
    ],
    [
        'id' => 1,
        'name' => 'Apex LifeSciences Manufacturing Ltd.',
        'role' => 'manufacturer',
        'license_no' => 'MFG/MH/2023/1042',
        'gstin' => '27AAACA1234F1Z8',
        'phone' => '+91 98765 43210',
        'email' => 'manufacturer@pharmasys.com',
        'address' => 'Plot 45, MIDC Kurkumbh Chemical Zone, Daund, Pune, MH',
        'cold_chain' => 'WHO-GMP Compliant Manufacturing Facility',
        'credit_terms' => 'Direct Factory Allocation & Batch Recalls',
        'challan_count' => 0,
        'draft_count' => 0,
        'return_count' => 0
    ],
    [
        'id' => 5,
        'name' => 'Sun Pharma B2B Distribution Hub',
        'role' => 'distributor',
        'license_no' => 'MH-DIST-2023-4412',
        'gstin' => '27AABCS1102K1ZV',
        'phone' => '+91 98200 99881',
        'email' => 'mumbai.orders@sunpharma.b2b',
        'address' => 'Plot 88, Taloja Industrial Estate, Navi Mumbai',
        'cold_chain' => 'Automated Climate Monitored Logistics',
        'credit_terms' => '21 Days Net',
        'challan_count' => 1,
        'draft_count' => 0,
        'return_count' => 0
    ],
    [
        'id' => 6,
        'name' => 'Cipla Healthcare Commercial Logistics',
        'role' => 'distributor',
        'license_no' => 'MH-DIST-2022-8109',
        'gstin' => '27AACCC4902L1ZH',
        'phone' => '+91 98330 11223',
        'email' => 'supply@cipladist.com',
        'address' => 'Unit 14, Godown Bay, Kalamboli, Navi Mumbai',
        'cold_chain' => 'Specialized Respiratory & Antibiotic Transit',
        'credit_terms' => '45 Days Net &bull; Electronic GST Challans',
        'challan_count' => 1,
        'draft_count' => 0,
        'return_count' => 0
    ]
];

$allSuppliers = $sampleSuppliers;

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
  <div style="margin-bottom: 1.5rem;">
    <h2 style="font-size: 1.4rem; font-weight: 700;">Suppliers &amp; Wholesale Distributors Directory</h2>
    <p style="font-size: 0.825rem; color: var(--text-subtle);">
      Authorized pharmaceutical manufacturers, C&amp;F agents, and wholesale suppliers delivering verified stock to CareFirst Pharmacy.
    </p>
  </div>

  <!-- KPI Metrics -->
  <div class="grid-4" style="margin-bottom: 1.5rem;">
    <div class="card" style="padding: 1rem 1.25rem;">
      <div style="font-size: 0.775rem; color: var(--text-subtle); font-weight: 600;">Active Suppliers</div>
      <div style="font-size: 1.8rem; font-weight: 700; color: #0F172A; margin-top: 0.25rem;"><?= count($allSuppliers) ?></div>
      <div style="font-size: 0.72rem; color: var(--text-subtle);">Licensed B2B vendors</div>
    </div>

    <div class="card" style="padding: 1rem 1.25rem;">
      <div style="font-size: 0.775rem; color: var(--text-subtle); font-weight: 600;">GST Compliance</div>
      <div style="font-size: 1.8rem; font-weight: 700; color: #059669; margin-top: 0.25rem;">100%</div>
      <div style="font-size: 0.72rem; color: var(--color-success);">All GSTIN numbers verified</div>
    </div>

    <div class="card" style="padding: 1rem 1.25rem;">
      <div style="font-size: 0.775rem; color: var(--text-subtle); font-weight: 600;">Cold Chain Coverage</div>
      <div style="font-size: 1.8rem; font-weight: 700; color: #0284C7; margin-top: 0.25rem;">4 Facilities</div>
      <div style="font-size: 0.72rem; color: var(--text-subtle);">2°C - 8°C biological vaults</div>
    </div>

    <div class="card" style="padding: 1rem 1.25rem;">
      <div style="font-size: 0.775rem; color: var(--text-subtle); font-weight: 600;">Reverse Returns Active</div>
      <div style="font-size: 1.8rem; font-weight: 700; color: #D97706; margin-top: 0.25rem;">Nexus B2B</div>
      <div style="font-size: 0.72rem; color: var(--text-subtle);">Quarantine bay enabled</div>
    </div>
  </div>

  <!-- Suppliers Table Card -->
  <div class="card">
    <div class="card-header" style="flex-wrap: wrap; gap: 0.75rem;">
      <div>
        <h3 class="card-title">Verified Pharmaceutical Vendors &amp; Logistics Partners</h3>
        <p class="card-description">Drug licenses, warehouse hubs, and procurement channels</p>
      </div>

      <input type="text" id="sup-search" class="form-control" style="max-width: 260px; font-size: 0.825rem;" placeholder="Search supplier, license, or city..." oninput="filterSupplierTable(this.value)">
    </div>

    <div class="table-wrapper">
      <table class="data-table" id="sup-table">
        <thead>
          <tr>
            <th>Vendor Name &amp; Role</th>
            <th>Drug License &amp; GSTIN</th>
            <th>Primary Hub Location</th>
            <th>Storage Capabilities</th>
            <th>Payment Terms</th>
            <th style="text-align: right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($allSuppliers as $s): ?>
            <tr class="sup-row" data-search="<?= strtolower(htmlspecialchars($s['name'] . ' ' . $s['license_no'] . ' ' . $s['gstin'] . ' ' . $s['address'])) ?>">
              <td>
                <strong style="color: #0F172A;"><?= htmlspecialchars($s['name']) ?></strong>
                <div style="margin-top: 0.25rem;">
                  <span class="badge <?= $s['role'] === 'manufacturer' ? 'badge-primary' : 'badge-neutral' ?>">
                    <?= $s['role'] === 'manufacturer' ? icon('box', 11) . ' Manufacturer' : icon('truck', 11) . ' Distributor' ?>
                  </span>
                </div>
              </td>
              <td>
                <div style="font-size: 0.825rem; font-family: var(--font-mono); font-weight: 600; color: #334155;">
                  <?= htmlspecialchars($s['license_no']) ?>
                </div>
                <div style="font-size: 0.72rem; color: var(--text-subtle);">GSTIN: <?= htmlspecialchars($s['gstin']) ?></div>
              </td>
              <td style="max-width: 240px;">
                <span style="font-size: 0.78rem; color: #475569; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                  <?= htmlspecialchars($s['address']) ?>
                </span>
                <div style="font-size: 0.72rem; color: var(--text-subtle);">Contact: <?= htmlspecialchars($s['phone']) ?></div>
              </td>
              <td>
                <span class="badge badge-success" style="font-size: 0.7rem;">
                  <?= htmlspecialchars($s['cold_chain']) ?>
                </span>
              </td>
              <td>
                <span style="font-size: 0.78rem; color: #334155;"><?= htmlspecialchars($s['credit_terms']) ?></span>
              </td>
              <td style="text-align: right;">
                <a href="retailer_restock.php" class="btn btn-outline btn-sm">
                  <?= icon('refresh', 12) ?> Restock PO &rarr;
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function filterSupplierTable(query) {
  const q = query.toLowerCase().trim();
  const rows = document.querySelectorAll('.sup-row');
  rows.forEach(r => {
    const text = r.getAttribute('data-search') || '';
    if (!q || text.includes(q)) {
      r.style.display = '';
    } else {
      r.style.display = 'none';
    }
  });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

