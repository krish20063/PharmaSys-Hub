/**
 * PharmaSys Hub - Clinical Composition Substitute Engine
 * Clean, Simple, Deduplicated Alternative Comparisons with Clear Dosage & Price Differences
 */

let searchDebounceTimer = null;
let currentSubstitutes = [];
let currentReferenceDrug = null;
let currentFilter = 'all';
let currentQuery = 'Paracetamol';
let activeRole = typeof currentUserRole !== 'undefined' ? currentUserRole : 'consumer';

function changeRoleView(role) {
  activeRole = role;
  renderFilteredSubstitutes(currentFilter);
  showToast(`Switched view to ${role.toUpperCase()}. Action buttons updated.`, 'info');
}

document.addEventListener('DOMContentLoaded', () => {
  const searchInput = document.getElementById('medicine-search-input');
  if (searchInput) {
    searchInput.addEventListener('input', (e) => {
      clearTimeout(searchDebounceTimer);
      searchDebounceTimer = setTimeout(() => {
        currentQuery = e.target.value.trim();
        performSubstituteSearch(currentQuery, 0);
      }, 250);
    });

    // Run initial search
    performSubstituteSearch('Paracetamol', 0);
  }
});

async function performSubstituteSearch(query, refId = 0) {
  const searchResultsArea = document.getElementById('search-results-area');
  const refBanner = document.getElementById('reference-drug-banner');
  const substitutesSection = document.getElementById('substitutes-section');
  const substitutesContainer = document.getElementById('substitutes-area');

  if (!searchResultsArea) return;

  if (!query && refId <= 0) {
    searchResultsArea.innerHTML = '<span style="color: var(--text-subtle); font-size: 0.85rem;">Type a brand name (e.g. Crocin, Dolo, Calpol, Augmentin, Allegra) or chemical salt.</span>';
    if (refBanner) refBanner.style.display = 'none';
    if (substitutesSection) substitutesSection.style.display = 'none';
    currentSubstitutes = [];
    currentReferenceDrug = null;
    return;
  }

  try {
    let url = `api/substitute_finder.php?q=${encodeURIComponent(query)}`;
    if (refId > 0) {
      url += `&ref_id=${refId}`;
    }

    const res = await fetch(url);
    const data = await res.json();

    if (!data.success) {
      showToast('Error: ' + (data.error || 'Failed to load medicines'), 'danger');
      return;
    }

    currentReferenceDrug = data.reference_drug || null;
    currentSubstitutes = data.substitutes || [];

    // 1. Render Matched Catalogue Formulations (Clickable selection chips)
    if (data.primary_results && data.primary_results.length > 0) {
      let chipsHtml = '';
      data.primary_results.forEach(drug => {
        const isSelected = currentReferenceDrug && (drug.id === currentReferenceDrug.id);
        const btnClass = isSelected ? 'btn btn-primary btn-sm' : 'btn btn-outline btn-sm';
        const iconCheck = isSelected ? '✓ ' : '';
        chipsHtml += `
          <button type="button" class="${btnClass}" style="border-radius: 20px; padding: 0.35rem 0.85rem; font-size: 0.8rem;" onclick="selectReferenceDrug(${drug.id})">
            ${iconCheck}<strong>${escapeHtml(drug.brand_name)}</strong> (${drug.strength_mg}mg &bull; ₹${parseFloat(drug.price_mrp).toFixed(2)})
          </button>
        `;
      });
      searchResultsArea.innerHTML = chipsHtml;
    } else {
      searchResultsArea.innerHTML = `<span style="color: var(--text-subtle); font-size: 0.85rem;">No medicines found matching "${escapeHtml(query)}".</span>`;
    }

    // 2. Render Active Reference Drug Banner
    if (refBanner && currentReferenceDrug) {
      document.getElementById('ref-brand-name').textContent = currentReferenceDrug.brand_name;
      document.getElementById('ref-salt').textContent = currentReferenceDrug.salt_composition;
      document.getElementById('ref-strength').textContent = `${currentReferenceDrug.strength_mg} mg`;
      document.getElementById('ref-form').textContent = currentReferenceDrug.dosage_form;
      document.getElementById('ref-price').textContent = `₹${parseFloat(currentReferenceDrug.price_mrp).toFixed(2)}`;
      refBanner.style.display = 'block';
    } else if (refBanner) {
      refBanner.style.display = 'none';
    }

    // 3. Render Substitutes Section
    if (substitutesSection) {
      if (currentReferenceDrug) {
        substitutesSection.style.display = 'block';
        document.getElementById('substitutes-count').textContent = currentSubstitutes.length;
        renderFilteredSubstitutes(currentFilter);
      } else {
        substitutesSection.style.display = 'none';
      }
    }

  } catch (err) {
    console.error('Substitute search failed:', err);
  }
}

function selectReferenceDrug(drugId) {
  performSubstituteSearch(currentQuery, drugId);
}

function renderFilteredSubstitutes(filterType) {
  currentFilter = filterType;
  const container = document.getElementById('substitutes-area');
  if (!container) return;

  let list = [...currentSubstitutes];

  if (filterType === 'min_diff') {
    list = list.filter(s => s.is_best_match || s.is_min_difference);
  } else if (filterType === 'exact') {
    list = list.filter(s => s.is_exact_strength);
  } else if (filterType === 'lowest_price') {
    list.sort((a, b) => a.price_mrp - b.price_mrp);
  } else {
    // Default sorting: Minimum dosage difference first, then price
    list.sort((a, b) => {
      if (a.abs_mg_difference !== b.abs_mg_difference) return a.abs_mg_difference - b.abs_mg_difference;
      return a.price_mrp - b.price_mrp;
    });
  }

  if (list.length === 0) {
    container.innerHTML = `
      <div class="card" style="padding: 2rem; text-align: center; color: var(--text-subtle);">
        <p style="font-size: 0.95rem; margin: 0;">No alternative medicines match this filter for the selected medicine.</p>
      </div>
    `;
    return;
  }

  let html = '';
  list.forEach(sub => {
    // Determine Dosage Diff Badge
    let dosageBadge = '';
    if (sub.is_exact_strength) {
      dosageBadge = `<span class="badge badge-success" style="font-size: 0.75rem; font-weight: 600;">Dosage: Exact Match (0 mg Diff)</span>`;
    } else if (sub.mg_difference > 0) {
      dosageBadge = `<span class="badge badge-warning" style="font-size: 0.75rem; font-weight: 600;">Dosage: +${sub.mg_difference} mg Higher</span>`;
    } else {
      dosageBadge = `<span class="badge badge-danger" style="font-size: 0.75rem; font-weight: 600;">Dosage: ${sub.mg_difference} mg Lower</span>`;
    }

    // Determine Price & Savings Badge
    let priceBadge = '';
    if (sub.is_cheaper) {
      priceBadge = `<span class="badge badge-success" style="font-size: 0.75rem; font-weight: 700;">Save ₹${sub.price_diff.toFixed(2)} (${sub.saving_percent}% Cheaper)</span>`;
    } else if (sub.price_diff === 0) {
      priceBadge = `<span class="badge badge-neutral" style="font-size: 0.75rem;">Same Price</span>`;
    } else {
      priceBadge = `<span class="badge badge-neutral" style="font-size: 0.75rem;">+₹${Math.abs(sub.price_diff).toFixed(2)} Higher</span>`;
    }

    // Best Match Pill (Clean, only on the true minimum difference / lowest price match)
    const bestMatchBadge = sub.is_best_match 
      ? `<span class="badge badge-success" style="background: #16A34A; color: #FFFFFF; font-weight: 700; padding: 0.25rem 0.6rem; font-size: 0.72rem;">★ Minimum Difference Match</span>` 
      : '';

    // Action button by user role
    let actionBtn = '';
    if (activeRole === 'distributor') {
      actionBtn = `<a href="distributor_buy.php" class="btn btn-primary btn-sm" style="white-space: nowrap;">📦 Buy Stock (Distributor)</a>`;
    } else if (activeRole === 'retailer') {
      actionBtn = `<a href="retailer_restock.php" class="btn btn-primary btn-sm" style="white-space: nowrap;">🛒 Restock Item</a>`;
    } else {
      actionBtn = `<button type="button" class="btn btn-outline btn-sm" style="white-space: nowrap;" onclick="selectSubstitute('${escapeHtml(sub.brand_name)}', ${sub.price_mrp})">Select Substitute</button>`;
    }

    html += `
      <div class="card substitute-card" style="padding: 1.15rem 1.25rem; margin-bottom: 0.75rem; border-left: 4px solid ${sub.is_best_match ? 'var(--color-success)' : 'var(--border-light)'};">
        <div class="sub-card-inner" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
          
          <!-- Left: Alternative Medicine Info & Differences -->
          <div style="flex: 1; min-width: 220px;">
            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.35rem;">
              <h4 style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin: 0;">${escapeHtml(sub.brand_name)}</h4>
              ${bestMatchBadge}
            </div>

            <div style="font-size: 0.78rem; color: var(--text-subtle); margin-bottom: 0.5rem;">
              ${escapeHtml(sub.generic_name)} &bull; Form: <strong>${escapeHtml(sub.dosage_form)}</strong> &bull; Strength: <strong>${sub.strength_mg} mg</strong>
            </div>

            <!-- Clear Difference Indicators -->
            <div style="display: flex; align-items: center; gap: 0.45rem; flex-wrap: wrap;">
              ${dosageBadge}
              ${priceBadge}
            </div>
          </div>

          <!-- Right: Price & Dynamic Action Button -->
          <div class="sub-card-actions" style="display: flex; align-items: center; gap: 1.25rem;">
            <div style="text-align: right;">
              <div style="font-size: 1.25rem; font-weight: 800; color: var(--text-main);">₹${sub.price_mrp.toFixed(2)}</div>
              <div style="font-size: 0.7rem; color: var(--text-subtle);">MRP per unit</div>
            </div>
            <div>
              ${actionBtn}
            </div>
          </div>

        </div>
      </div>
    `;
  });

  container.innerHTML = html;
}

function setSubstituteFilter(filterType, btn) {
  document.querySelectorAll('.sub-filter-btn').forEach(b => {
    b.classList.remove('btn-primary');
    b.classList.add('btn-outline');
  });
  if (btn) {
    btn.classList.remove('btn-outline');
    btn.classList.add('btn-primary');
  }
  renderFilteredSubstitutes(filterType);
}

function selectSubstitute(name, price) {
  showToast(`Selected ${name} (Auto-set to minimum: 10 units • ₹${(parseFloat(price) * 10).toFixed(2)}). Ready for prescription or inquiry.`, 'success');
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/[&<>'"]/g, 
    tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag] || tag)
  );
}

window.performSubstituteSearch = performSubstituteSearch;
window.selectReferenceDrug = selectReferenceDrug;
window.setSubstituteFilter = setSubstituteFilter;
window.selectSubstitute = selectSubstitute;
window.changeRoleView = changeRoleView;
