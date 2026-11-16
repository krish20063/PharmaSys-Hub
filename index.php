<?php
$pageTitle = 'Enterprise Pharmaceutical Supply Chain Platform';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

// If user is already logged in, redirect straight to their authorized portal
if (is_logged_in()) {
    $roleHomes = [
        'manufacturer' => 'manufacturer.php',
        'distributor' => 'distributor.php',
        'retailer' => 'retailer_pos.php',
        'consumer' => 'consumer.php'
    ];
    header('Location: ' . ($roleHomes[$_SESSION['user_role']] ?? 'login.php'));
    exit;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width: 1040px; margin: 2rem auto;">
  <!-- Hero Section -->
  <div class="card hero-card" style="padding: 3rem 2.5rem; text-align: center; margin-bottom: 2rem;">
    <div style="display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.3rem 0.85rem; border-radius: var(--radius-pill); background: var(--color-primary-bg); color: var(--color-primary-dark); font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1.25rem;">
      <?= icon('shield-check', 14) ?> Indian CDSCO &amp; Drug Control Compliant Ledger
    </div>

    <h1 style="font-size: 2.25rem; font-weight: 800; color: var(--text-main); letter-spacing: -0.02em; line-height: 1.25; margin-bottom: 0.75rem;">
      End-to-End Pharmaceutical Supply Chain Operating System
    </h1>

    <p style="font-size: 1.05rem; color: var(--text-muted); max-width: 780px; margin: 0 auto 2rem; line-height: 1.6;">
      <strong>PharmaSys Hub</strong> unites Manufacturers, Logistics Distributors, Retail Pharmacies, and Consumers on a single verifiable ledger featuring real-time <strong>Smart Batch Recalls</strong>, <strong>Expiry Automation</strong>, and <strong>Meta WhatsApp Cloud Invoicing</strong>.
    </p>

    <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
      <a href="login.php" class="btn btn-primary hero-btn" style="padding: 0.8rem 1.75rem; font-size: 0.95rem;">
        <?= icon('user', 16) ?> Access Your Authorized Workspace &rarr;
      </a>
    </div>
  </div>

  <!-- Role Gateway Grid -->
  <div style="margin-bottom: 2rem;">
    <div style="text-align: center; margin-bottom: 1.5rem;">
      <h2 style="font-size: 1.35rem; font-weight: 700;">Role-Based Operational Workspaces</h2>
      <p style="font-size: 0.825rem; color: var(--text-subtle);">Strict Level-4 RBAC guarantees cross-participant data segregation</p>
    </div>

    <div class="grid-4">
      <!-- Manufacturer -->
      <div class="card" style="display: flex; flex-direction: column; justify-content: space-between; padding: 1.5rem 1.25rem;">
        <div>
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
            <span class="badge badge-primary">Manufacturer</span>
            <?= icon('activity', 18) ?>
          </div>
          <h3 style="font-size: 1.05rem; font-weight: 700; margin-bottom: 0.35rem;">Apex LifeSciences</h3>
          <p style="font-size: 0.78rem; color: var(--text-subtle); line-height: 1.5; margin-bottom: 1.25rem;">
            Batch minting, production yield telemetry, and instant network-wide <strong>Smart Batch Recalls</strong>.
          </p>
        </div>
        <a href="login.php" class="btn btn-outline btn-sm btn-block">Sign In &rarr;</a>
      </div>

      <!-- Distributor -->
      <div class="card" style="display: flex; flex-direction: column; justify-content: space-between; padding: 1.5rem 1.25rem;">
        <div>
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
            <span class="badge badge-primary">Distributor</span>
            <?= icon('truck', 18) ?>
          </div>
          <h3 style="font-size: 1.05rem; font-weight: 700; margin-bottom: 0.35rem;">Nexus Logistics</h3>
          <p style="font-size: 0.78rem; color: var(--text-subtle); line-height: 1.5; margin-bottom: 1.25rem;">
            Bulk warehouse storage, <strong>Spatial Rack Mapping</strong> (Cold vs. Ambient), and GST tax delivery challans.
          </p>
        </div>
        <a href="login.php" class="btn btn-outline btn-sm btn-block">Sign In &rarr;</a>
      </div>

      <!-- Retailer -->
      <div class="card" style="display: flex; flex-direction: column; justify-content: space-between; padding: 1.5rem 1.25rem;">
        <div>
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
            <span class="badge badge-success">Retail Pharmacy</span>
            <?= icon('cart', 18) ?>
          </div>
          <h3 style="font-size: 1.05rem; font-weight: 700; margin-bottom: 0.35rem;">CareFirst Pharmacy</h3>
          <p style="font-size: 0.78rem; color: var(--text-subtle); line-height: 1.5; margin-bottom: 1.25rem;">
            <strong>Smart POS Terminal</strong> with automatic safety locks on recalled/expired stock and an <strong>Auto-Restock Draft Cart</strong>.
          </p>
        </div>
        <a href="login.php" class="btn btn-outline btn-sm btn-block">Sign In &rarr;</a>
      </div>

      <!-- Consumer -->
      <div class="card" style="display: flex; flex-direction: column; justify-content: space-between; padding: 1.5rem 1.25rem;">
        <div>
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
            <span class="badge badge-warning">Patient Portal</span>
            <?= icon('user', 18) ?>
          </div>
          <h3 style="font-size: 1.05rem; font-weight: 700; margin-bottom: 0.35rem;">Consumer Access</h3>
          <p style="font-size: 0.78rem; color: var(--text-subtle); line-height: 1.5; margin-bottom: 1.25rem;">
            <strong>Composition Substitute Finder</strong> with mg potency difference alerts, Rx verification, and WhatsApp e-bills.
          </p>
        </div>
        <a href="login.php" class="btn btn-outline btn-sm btn-block">Sign In &rarr;</a>
      </div>
    </div>
  </div>

  <!-- 5 Innovative Architectural Pillars -->
  <div class="card" style="padding: 2rem;">
    <div class="card-header" style="margin-bottom: 1.25rem;">
      <div>
        <h3 class="card-title">5 Core Architectural Innovations</h3>
        <p class="card-description">Strict business logic enforced at the database and application levels</p>
      </div>
    </div>

    <div class="grid-3">
      <div style="padding: 1rem; border: 1px solid var(--border-light); border-radius: var(--radius-sm); background: var(--bg-surface-subtle);">
        <div style="color: var(--color-danger); margin-bottom: 0.5rem;"><?= icon('shield-alert', 22) ?></div>
        <h4 style="font-size: 0.9rem; font-weight: 700; margin-bottom: 0.25rem;">1. Smart Batch Recall</h4>
        <p style="font-size: 0.78rem; color: var(--text-subtle); line-height: 1.5;">
          When a manufacturer blacklists a batch, <code>is_recalled = 1</code> is flagged across the central ledger. Downstream Retailer POS terminals immediately lock the batch from checkout.
        </p>
      </div>

      <div style="padding: 1rem; border: 1px solid var(--border-light); border-radius: var(--radius-sm); background: var(--bg-surface-subtle);">
        <div style="color: var(--color-warning); margin-bottom: 0.5rem;"><?= icon('clock', 22) ?></div>
        <h4 style="font-size: 0.9rem; font-weight: 700; margin-bottom: 0.25rem;">2. Automated Expiry Matching</h4>
        <p style="font-size: 0.78rem; color: var(--text-subtle); line-height: 1.5;">
          No expired medicine is pre-flagged. The system dynamically matches <code>expiry_date &lt; current_date</code> to label and permanently block expired drugs from counter sale.
        </p>
      </div>

      <div style="padding: 1rem; border: 1px solid var(--border-light); border-radius: var(--radius-sm); background: var(--bg-surface-subtle);">
        <div style="color: var(--color-primary); margin-bottom: 0.5rem;"><?= icon('refresh', 22) ?></div>
        <h4 style="font-size: 0.9rem; font-weight: 700; margin-bottom: 0.25rem;">3. Auto-Restock Draft Cart</h4>
        <p style="font-size: 0.78rem; color: var(--text-subtle); line-height: 1.5;">
          Stock breaching <code>min_threshold</code> auto-queues in a draft purchase order. The pharmacist can review, adjust, or manually remove items before issuing official orders.
        </p>
      </div>

      <div style="padding: 1rem; border: 1px solid var(--border-light); border-radius: var(--radius-sm); background: var(--bg-surface-subtle);">
        <div style="color: var(--color-success); margin-bottom: 0.5rem;"><?= icon('message-circle', 22) ?></div>
        <h4 style="font-size: 0.9rem; font-weight: 700; margin-bottom: 0.25rem;">4. Meta WhatsApp E-Billing</h4>
        <p style="font-size: 0.78rem; color: var(--text-subtle); line-height: 1.5;">
          Upon POS sale, PHP invokes Meta's WhatsApp Cloud API via cURL to transmit an itemized 12% GST invoice directly to the customer's 10-digit mobile number.
        </p>
      </div>

      <div style="padding: 1rem; border: 1px solid var(--border-light); border-radius: var(--radius-sm); background: var(--bg-surface-subtle);">
        <div style="color: var(--color-primary); margin-bottom: 0.5rem;"><?= icon('search', 22) ?></div>
        <h4 style="font-size: 0.9rem; font-weight: 700; margin-bottom: 0.25rem;">5. Composition Substitute Finder</h4>
        <p style="font-size: 0.78rem; color: var(--text-subtle); line-height: 1.5;">
          Matches active chemical salt compositions (e.g. <em>Paracetamol IP</em>) and flags exact milligram differences (e.g. +150mg higher dosage) to ensure clinical bioequivalence.
        </p>
      </div>

      <div style="padding: 1rem; border: 1px solid var(--border-light); border-radius: var(--radius-sm); background: var(--bg-surface-subtle);">
        <div style="color: var(--text-muted); margin-bottom: 0.5rem;"><?= icon('database', 22) ?></div>
        <h4 style="font-size: 0.9rem; font-weight: 700; margin-bottom: 0.25rem;">Dual Live SQL Database Engine</h4>
        <p style="font-size: 0.78rem; color: var(--text-subtle); line-height: 1.5;">
          Persistent file-backed relational SQLite engine out of the box with zero configuration, and seamless plug-and-play MySQL (XAMPP) connector.
        </p>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
