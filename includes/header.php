<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/icons.php';

$user = current_user();
$dbStatus = Database::getStatus();
$currentPage = basename($_SERVER['PHP_SELF']);

$pendingRxCount = 0;
if ($user && $user['role'] === 'retailer') {
    if (isset($_SESSION['cached_rx_count']) && isset($_SESSION['cached_rx_time']) && (time() - (int)$_SESSION['cached_rx_time'] < 30)) {
        $pendingRxCount = (int)$_SESSION['cached_rx_count'];
    } else {
        try {
            $pdoHead = Database::getConnection();
            $cntStmt = $pdoHead->query("SELECT COUNT(*) FROM prescriptions WHERE status = 'pending'");
            $pendingRxCount = (int)$cntStmt->fetchColumn();
            $_SESSION['cached_rx_count'] = $pendingRxCount;
            $_SESSION['cached_rx_time'] = time();
        } catch (Exception $e) {}
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= csrf_token() ?>">
  <title><?= htmlspecialchars($pageTitle ?? 'Enterprise Platform') ?> &bull; <?= APP_NAME ?></title>
  <script>
    (function() {
      const savedTheme = localStorage.getItem('pharmasys_theme');
      const theme = (savedTheme === 'light') ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', theme);
    })();
  </script>
  <link rel="stylesheet" href="assets/css/style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
</head>
<body>

  <!-- Top Application Bar -->
  <header class="app-header">
    <div class="container header-inner">
      <a href="index.php" class="brand-wrapper">
        <div class="brand-symbol">Rx</div>
        <div>
          <div class="brand-title">PharmaSys Hub</div>
          <div class="brand-subtitle">Supply Chain Ledger</div>
        </div>
      </a>

      <!-- Right Header Elements -->
      <div class="header-right-actions">
        <!-- Live Database Badge -->
        <span class="badge badge-success db-badge-header" title="Real-time SQL database active: <?= htmlspecialchars($dbStatus) ?>">
          <?= icon('database', 13) ?> <span class="db-badge-text"><?= htmlspecialchars($dbStatus) ?></span>
        </span>

        <!-- Theme Mode Switcher -->
        <button type="button" id="theme-toggle-btn" class="theme-toggle-btn" onclick="toggleTheme()" title="Toggle Dark / Light Theme" aria-label="Toggle Theme">
          <span class="theme-icon-sun"><?= icon('sun', 15) ?></span>
          <span class="theme-icon-moon"><?= icon('moon', 15) ?></span>
          <span class="theme-toggle-label" id="theme-toggle-label">Theme</span>
        </button>

        <?php if ($user): ?>
          <div class="user-menu">
            <div class="user-meta">
              <div class="user-meta-name"><?= htmlspecialchars($user['name']) ?></div>
              <div class="user-meta-role">
                <span class="badge badge-primary"><?= ucfirst(htmlspecialchars($user['role'])) ?></span>
                <?php if ($user['license_no']): ?>
                  <span class="user-meta-license">&bull; <span style="font-family: var(--font-mono); font-size: 0.7rem; color: var(--text-subtle);"><?= htmlspecialchars($user['license_no']) ?></span></span>
                <?php endif; ?>
              </div>
            </div>
            <a href="logout.php" class="btn btn-outline btn-sm logout-btn" title="End Session">
              <?= icon('log-out', 14) ?> <span class="logout-btn-text">Sign Out</span>
            </a>
          </div>
        <?php else: ?>
          <?php if ($currentPage !== 'login.php'): ?>
            <a href="login.php" class="btn btn-primary btn-sm login-btn-header">
              <?= icon('user', 14) ?> <span>Sign In</span>
            </a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <!-- Role Sub-Navigation (Dedicated Modular Sections) -->
  <?php if ($user): ?>
    <nav class="sub-nav">
      <div class="container sub-nav-inner">
        <?php if ($user['role'] === 'retailer'): ?>
          <a href="retailer_pos.php" class="sub-nav-item <?= $currentPage === 'retailer_pos.php' ? 'active' : '' ?>">
            <?= icon('cart', 16) ?> Smart POS Billing
          </a>
          <a href="consumer.php" class="sub-nav-item <?= $currentPage === 'consumer.php' ? 'active' : '' ?>">
            <?= icon('search', 16) ?> Substitute Finder
          </a>
          <a href="retailer_inventory.php" class="sub-nav-item <?= $currentPage === 'retailer_inventory.php' ? 'active' : '' ?>">
            <?= icon('boxes', 16) ?> Shelf Inventory &amp; Batches
          </a>
          <a href="retailer_prescriptions.php" class="sub-nav-item <?= $currentPage === 'retailer_prescriptions.php' ? 'active' : '' ?>">
            <?= icon('file-text', 16) ?> Rx Verification
            <?php if ($pendingRxCount > 0): ?>
              <span class="badge badge-warning" style="margin-left: 0.35rem; padding: 0.1rem 0.4rem; font-size: 0.68rem; border-radius: 9999px;"><?= $pendingRxCount ?></span>
            <?php endif; ?>
          </a>
          <a href="schedule_h1.php" class="sub-nav-item <?= $currentPage === 'schedule_h1.php' ? 'active' : '' ?>">
            <?= icon('clipboard', 16) ?> Schedule H1 Register
          </a>
          <a href="retailer_returns.php" class="sub-nav-item <?= $currentPage === 'retailer_returns.php' ? 'active' : '' ?>">
            <?= icon('truck', 16) ?> Return Expired Stock
          </a>
          <a href="retailer_restock.php" class="sub-nav-item <?= $currentPage === 'retailer_restock.php' ? 'active' : '' ?>">
            <?= icon('refresh', 16) ?> Restock Orders
          </a>
          <a href="retailer_customers.php" class="sub-nav-item <?= $currentPage === 'retailer_customers.php' ? 'active' : '' ?>">
            <?= icon('users', 16) ?> Customers
          </a>
          <a href="retailer_suppliers.php" class="sub-nav-item <?= $currentPage === 'retailer_suppliers.php' ? 'active' : '' ?>">
            <?= icon('truck', 16) ?> Suppliers
          </a>
          <a href="retailer_reports.php" class="sub-nav-item <?= $currentPage === 'retailer_reports.php' ? 'active' : '' ?>">
            <?= icon('bar-chart', 16) ?> Reports &amp; Valuation
          </a>
        <?php elseif ($user['role'] === 'manufacturer'): ?>
          <a href="manufacturer.php" class="sub-nav-item <?= $currentPage === 'manufacturer.php' ? 'active' : '' ?>">
            <?= icon('activity', 16) ?> Production Overview
          </a>
          <a href="manufacturer_batches.php" class="sub-nav-item <?= $currentPage === 'manufacturer_batches.php' ? 'active' : '' ?>">
            <?= icon('box', 16) ?> Batch Minting &amp; Limits
          </a>
          <a href="manufacturer_recalls.php" class="sub-nav-item <?= $currentPage === 'manufacturer_recalls.php' ? 'active' : '' ?>">
            <?= icon('shield-alert', 16) ?> Smart Recall Command
          </a>
          <a href="manufacturer_analytics.php" class="sub-nav-item <?= $currentPage === 'manufacturer_analytics.php' ? 'active' : '' ?>">
            <?= icon('layers', 16) ?> Distribution Telemetry
          </a>
        <?php elseif ($user['role'] === 'distributor'): ?>
          <a href="distributor.php" class="sub-nav-item <?= $currentPage === 'distributor.php' ? 'active' : '' ?>">
            <?= icon('truck', 16) ?> Logistics Dashboard
          </a>
          <a href="distributor_buy.php" class="sub-nav-item <?= $currentPage === 'distributor_buy.php' ? 'active' : '' ?>">
            <?= icon('cart', 16) ?> Buy from Manufacturers
          </a>
          <a href="distributor_inventory.php" class="sub-nav-item <?= $currentPage === 'distributor_inventory.php' ? 'active' : '' ?>">
            <?= icon('map-pin', 16) ?> Warehouse Rack Mapping
          </a>
          <a href="distributor_challans.php" class="sub-nav-item <?= $currentPage === 'distributor_challans.php' ? 'active' : '' ?>">
            <?= icon('file-text', 16) ?> GST Dispatch Challans
          </a>
          <a href="distributor_returns.php" class="sub-nav-item <?= $currentPage === 'distributor_returns.php' ? 'active' : '' ?>">
            <?= icon('alert-triangle', 16) ?> Near-Expiry Returns
          </a>
        <?php elseif ($user['role'] === 'consumer'): ?>
          <a href="consumer.php" class="sub-nav-item <?= $currentPage === 'consumer.php' ? 'active' : '' ?>">
            <?= icon('search', 16) ?> Substitute Finder
          </a>
          <a href="consumer_prescriptions.php" class="sub-nav-item <?= $currentPage === 'consumer_prescriptions.php' ? 'active' : '' ?>">
            <?= icon('file-text', 16) ?> My Prescriptions
          </a>
          <a href="consumer_bills.php" class="sub-nav-item <?= $currentPage === 'consumer_bills.php' ? 'active' : '' ?>">
            <?= icon('message-circle', 16) ?> WhatsApp E-Bills
          </a>
        <?php endif; ?>
      </div>
    </nav>
  <?php endif; ?>

  <main style="padding: 1.75rem 0; flex: 1;">
