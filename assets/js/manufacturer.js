/**
 * PharmaSys Hub - Manufacturer Analytics & Smart Batch Recall
 * Distinct, non-duplicated visual charts for operations & telemetry.
 */

document.addEventListener('DOMContentLoaded', () => {
  initMonthlyOutputChart();
  initAbsorptionChart();
  initCategoryYieldChart();
});

// Chart 1: Monthly Production Output Trajectory (Used on manufacturer.php)
function initMonthlyOutputChart() {
  const ctx = document.getElementById('monthlyOutputChart');
  if (!ctx) return;

  new Chart(ctx, {
    type: 'line',
    data: {
      labels: ['Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026', 'Oct 2026', 'Nov 2026'],
      datasets: [{
        label: 'Finished Formulations (in 1,000s units)',
        data: [42, 51, 58, 54, 69, 65],
        borderColor: '#0284C7',
        backgroundColor: 'rgba(2, 132, 199, 0.08)',
        fill: true,
        tension: 0.35,
        borderWidth: 2.5,
        pointRadius: 4,
        pointBackgroundColor: '#0284C7'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#0F172A',
          callbacks: {
            label: (ctx) => `${ctx.parsed.y},000 Units Released`
          }
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          grid: { color: '#E2E8F0' },
          ticks: { callback: (val) => val + 'k' }
        },
        x: {
          grid: { display: false }
        }
      }
    }
  });
}

// Chart 2: Product Absorption vs Dispatch Comparison (Used on manufacturer_analytics.php)
function initAbsorptionChart() {
  const ctx = document.getElementById('absorptionChart');
  if (!ctx) return;

  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: ['Crocin 500', 'Dolo 650', 'Calpol 500', 'Augmentin 625', 'Azithral 500', 'Pan-D 40', 'Allegra 120', 'Telma 40'],
      datasets: [
        {
          label: 'Produced Units',
          data: [10000, 12000, 6000, 4000, 7000, 5000, 4500, 5500],
          backgroundColor: 'rgba(2, 132, 199, 0.85)',
          borderColor: '#0284C7',
          borderWidth: 1,
          borderRadius: 4
        },
        {
          label: 'Distributor Absorbed',
          data: [6800, 11590, 5800, 2500, 3900, 3050, 3100, 3400],
          backgroundColor: 'rgba(5, 150, 105, 0.85)',
          borderColor: '#059669',
          borderWidth: 1,
          borderRadius: 4
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'top',
          labels: { font: { size: 12, weight: '600' }, color: '#334155' }
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          grid: { color: '#E2E8F0' },
          ticks: { font: { size: 11 }, color: '#64748B' }
        },
        x: {
          grid: { display: false },
          ticks: { font: { size: 11 }, color: '#64748B' }
        }
      }
    }
  });
}

// Chart 3: Therapeutic Category Share (Used on manufacturer_analytics.php)
function initCategoryYieldChart() {
  const ctx = document.getElementById('categoryYieldChart');
  if (!ctx) return;

  new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: ['Antipyretics', 'Antibiotics', 'Gastro & Antacids', 'Antihistamines', 'Cardiovascular', 'Antidiabetics'],
      datasets: [{
        data: [28000, 11000, 8000, 4500, 5500, 3800],
        backgroundColor: ['#0284C7', '#10B981', '#F59E0B', '#8B5CF6', '#EC4899', '#64748B']
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } }
      }
    }
  });
}

async function toggleBatchRecall(batchId, currentStatus, batchNumber, drugName) {
  const willRecall = currentStatus === 0;

  let reason = '';
  if (willRecall) {
    reason = prompt(`Enter mandatory QA assay reason for recalling Batch ${batchNumber} (${drugName}):`, 'Stability assay chemical impurity threshold variation');
    if (!reason) return;
  } else {
    if (!confirm(`Are you sure you want to lift the emergency recall on Batch ${batchNumber}?`)) return;
  }

  try {
    const res = await fetch('api/recall_batch.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        batch_id: batchId,
        action: willRecall ? 'recall' : 'unrecall',
        reason: reason
      })
    });

    const data = await res.json();
    if (data.success) {
      showToast(willRecall ? `Batch ${batchNumber} blacklisted! POS terminals locked.` : `Batch ${batchNumber} reinstated.`, willRecall ? 'danger' : 'success');
      setTimeout(() => location.reload(), 800);
    } else {
      showToast(data.error || 'Recall command rejected', 'danger');
    }
  } catch (err) {
    showToast('Network communication error', 'danger');
  }
}

window.toggleBatchRecall = toggleBatchRecall;
