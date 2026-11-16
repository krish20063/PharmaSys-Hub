/**
 * PharmaSys Hub - Global Client Interactions & Security Interceptor
 */

function getCsrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute('content') : '';
}

// Global Fetch Interceptor: Automatically attaches X-CSRF-Token header to all mutation requests
const originalFetch = window.fetch;
window.fetch = function(url, options = {}) {
  options = options || {};
  options.headers = options.headers || {};
  const method = (options.method || 'GET').toUpperCase();
  
  if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
    const token = getCsrfToken();
    if (token) {
      if (options.headers instanceof Headers) {
        if (!options.headers.has('X-CSRF-Token')) {
          options.headers.set('X-CSRF-Token', token);
        }
      } else if (Array.isArray(options.headers)) {
        options.headers.push(['X-CSRF-Token', token]);
      } else {
        if (!options.headers['X-CSRF-Token']) {
          options.headers['X-CSRF-Token'] = token;
        }
      }
    }
  }
  return originalFetch.call(this, url, options);
};

function showToast(message, type = 'info') {
  const container = document.getElementById('toast-container');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = 'toast';

  let color = '#38BDF8';
  if (type === 'success') color = '#34D399';
  if (type === 'danger') color = '#F87171';
  if (type === 'warning') color = '#FBBF24';

  toast.innerHTML = `<span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background-color: ${color};"></span><span>${message}</span>`;
  container.appendChild(toast);

  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(10px)';
    toast.style.transition = 'all 0.2s ease-out';
    setTimeout(() => toast.remove(), 250);
  }, 4000);
}

window.showToast = showToast;
window.getCsrfToken = getCsrfToken;

// =====================================================================
// Theme Manager: Dark / Light Mode Engine
// =====================================================================
function getTheme() {
  return document.documentElement.getAttribute('data-theme') || 'dark';
}

function setTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  try {
    localStorage.setItem('pharmasys_theme', theme);
  } catch (e) {}
  updateThemeToggleUI(theme);
}

function toggleTheme() {
  const current = getTheme();
  const target = current === 'dark' ? 'light' : 'dark';
  setTheme(target);
  showToast(`Switched to ${target === 'dark' ? 'Dark Mode 🌙' : 'Light Mode ☀️'}`, 'info');
}

function updateThemeToggleUI(theme) {
  const label = document.getElementById('theme-toggle-label');
  const btn = document.getElementById('theme-toggle-btn');
  if (label) {
    label.textContent = theme === 'dark' ? 'Dark' : 'Light';
  }
  if (btn) {
    btn.setAttribute('title', theme === 'dark' ? 'Switch to Light Theme' : 'Switch to Dark Theme');
    btn.setAttribute('aria-label', theme === 'dark' ? 'Switch to Light Theme' : 'Switch to Dark Theme');
  }
}

document.addEventListener('DOMContentLoaded', () => {
  updateThemeToggleUI(getTheme());
});

window.toggleTheme = toggleTheme;
window.setTheme = setTheme;
window.getTheme = getTheme;
