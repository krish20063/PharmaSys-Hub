<?php
$pageTitle = 'Medicine Search & Composition Substitute Finder';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

// Allow any authenticated user (patients, retailers, distributors, manufacturers) to use the substitute finder
require_auth();

$userRole = $_SESSION['user_role'] ?? 'consumer';
$userName = $_SESSION['user_name'] ?? 'User';

require_once __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width: 1040px; margin: 1.5rem auto;">
  <div class="page-header-flex" style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem;">
    <div>
      <h2 style="font-size: 1.35rem; font-weight: 700; color: #0F172A; margin: 0 0 0.25rem 0;">
        Medicine Search &amp; Composition Substitute Finder
      </h2>
      <p style="font-size: 0.825rem; color: var(--text-subtle); margin: 0;">
        Find branded and generic medicines sharing active chemical salts, and compare exact dosage differences and price savings.
      </p>
    </div>
    <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
      <span style="font-size: 0.78rem; color: var(--text-subtle); font-weight: 600;">Role View:</span>
      <select id="role-view-selector" class="form-control" style="font-size: 0.8rem; padding: 0.35rem 0.65rem; width: auto;" onchange="changeRoleView(this.value)">
        <option value="consumer" <?= $userRole === 'consumer' ? 'selected' : '' ?>>👤 Consumer / Patient View</option>
        <option value="distributor" <?= $userRole === 'distributor' ? 'selected' : '' ?>>🚚 Distributor (Buying Option)</option>
        <option value="retailer" <?= $userRole === 'retailer' ? 'selected' : '' ?>>🏪 Retail Pharmacy View</option>
        <option value="manufacturer" <?= $userRole === 'manufacturer' ? 'selected' : '' ?>>🏭 Manufacturer View</option>
      </select>
    </div>
  </div>

  <!-- Search Card -->
  <div class="card" style="padding: 1.25rem; margin-bottom: 1.5rem;">
    <div style="position: relative;">
      <label for="medicine-search-input" style="font-size: 0.8rem; font-weight: 700; color: var(--text-main); display: block; margin-bottom: 0.4rem;">
        <?= icon('search', 14) ?> Search Medicine or Active Salt Composition
      </label>
      <input type="text" id="medicine-search-input" class="form-control" 
             style="font-size: 0.95rem; padding: 0.7rem 1rem;" 
             value="Paracetamol" 
             placeholder="Type a medicine name (e.g. Crocin, Dolo, Calpol, Augmentin, Allegra, Pan-D) or chemical salt...">
    </div>

    <!-- Matched Medicines from Catalog (Click to select reference medicine) -->
    <div style="margin-top: 1.25rem;">
      <div style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-subtle); margin-bottom: 0.5rem;">
        Click Any Medicine Below to Compare Its Generic Alternatives:
      </div>
      <div id="search-results-area" style="display: flex; gap: 0.6rem; flex-wrap: wrap;">
        <span style="color: var(--text-subtle); font-size: 0.85rem;">Searching medicines...</span>
      </div>
    </div>
  </div>

  <!-- Active Reference Medicine Banner -->
  <div id="reference-drug-banner" style="display: none; background: var(--bg-surface-subtle); border: 1px solid var(--border-light); border-radius: 8px; padding: 1rem 1.25rem; margin-bottom: 1.5rem;">
    <div class="ref-drug-inner" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
      <div>
        <div style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-subtle);">
          Selected Reference Medicine
        </div>
        <h3 id="ref-brand-name" style="font-size: 1.2rem; font-weight: 800; color: var(--text-main); margin: 0.15rem 0;"></h3>
        <div style="font-size: 0.8rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.25rem;">
          <span>Active Salt: <strong id="ref-salt" style="color: var(--text-main);"></strong></span>
          <span>&bull;</span>
          <span>Strength: <strong id="ref-strength"></strong></span>
          <span>&bull;</span>
          <span>Form: <span id="ref-form"></span></span>
        </div>
      </div>
      <div style="text-align: right;">
        <div id="ref-price" style="font-size: 1.35rem; font-weight: 800; color: var(--text-main);"></div>
        <div style="font-size: 0.72rem; color: var(--text-subtle);">MRP (Reference Price)</div>
      </div>
    </div>
  </div>

  <!-- Substitutes Container -->
  <div id="substitutes-section" style="display: none;">
    <!-- Filter Toolbar & Summary -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1rem;">
      <div>
        <h3 style="font-size: 1.05rem; font-weight: 700; color: var(--text-main); margin: 0;">
          All Alternative Medicines (<span id="substitutes-count">0</span> Found)
        </h3>
        <p style="font-size: 0.75rem; color: var(--text-subtle); margin: 0.15rem 0 0 0;">
          Showing bioequivalent medicines sharing the exact active salt composition with calculated dosage and price differences.
        </p>
      </div>

      <!-- Simple Filter Pills -->
      <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
        <button type="button" class="btn btn-sm btn-primary sub-filter-btn" onclick="setSubstituteFilter('all', this)">
          All Alternatives
        </button>
        <button type="button" class="btn btn-sm btn-outline sub-filter-btn" onclick="setSubstituteFilter('min_diff', this)">
          ★ Minimum Difference Match
        </button>
        <button type="button" class="btn btn-sm btn-outline sub-filter-btn" onclick="setSubstituteFilter('exact', this)">
          Exact Strength (0 mg)
        </button>
        <button type="button" class="btn btn-sm btn-outline sub-filter-btn" onclick="setSubstituteFilter('lowest_price', this)">
          Lowest Price First
        </button>
      </div>
    </div>

    <!-- Substitutes Cards List -->
    <div id="substitutes-area"></div>
  </div>

</div>

<script>
  const currentUserRole = <?= json_encode($userRole) ?>;
</script>
<script src="assets/js/consumer.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
