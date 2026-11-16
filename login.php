<?php
$pageTitle = 'Secure Workspace Sign In';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

// If already authenticated, redirect to their home portal
if (is_logged_in()) {
    $roleHomes = [
        'manufacturer' => 'manufacturer.php',
        'distributor' => 'distributor.php',
        'retailer' => 'retailer_pos.php',
        'consumer' => 'consumer.php'
    ];
    header('Location: ' . ($roleHomes[$_SESSION['user_role']] ?? 'index.php'));
    exit;
}

$error = null;
$redirect = $_GET['redirect'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verify CSRF Token
    if (!verify_csrf_token()) {
        $error = 'Security Error: CSRF token validation failed. Please refresh the page.';
    } else {
        // 2. Enforce Rate Limiting (5 failed attempts per 15 mins)
        $rateLimit = check_rate_limit('login_attempt', 5, 900);
        if (!$rateLimit['allowed']) {
            $error = "Too many failed attempts. For security, please wait {$rateLimit['retry_after']} seconds before retrying.";
        } else {
            $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
            $password = trim($_POST['password'] ?? '');

            if (empty($email) || empty($password)) {
                $error = 'Both registered email and security password are required.';
            } else {
                try {
                    $pdo = Database::getConnection();
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
                    $stmt->execute([':email' => $email]);
                    $user = $stmt->fetch();

                    // Strict cryptographic password verification (No backdoor shortcuts)
                    if ($user && password_verify($password, $user['password'])) {
                        // Prevent Session Fixation Attack
                        session_regenerate_id(true);

                        // Reset rate limit on successful authentication
                        unset($_SESSION['rate_' . md5('login_attempt_' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'))]);

                        // Successful Authentication Session State
                        $_SESSION['user_id'] = (int)$user['id'];
                        $_SESSION['user_role'] = $user['role'];
                        $_SESSION['user_name'] = $user['name'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['user_license'] = $user['license_no'];
                        $_SESSION['user_phone'] = $user['phone'];

                        // Rehash if algorithm needs upgrading
                        if (password_needs_rehash($user['password'], PASSWORD_BCRYPT)) {
                            $newHash = password_hash($password, PASSWORD_BCRYPT);
                            $updHash = $pdo->prepare("UPDATE users SET password = :pwd WHERE id = :id");
                            $updHash->execute([':pwd' => $newHash, ':id' => $user['id']]);
                        }

                        // Audit Log Successful Login
                        Database::logAudit(
                            'AUTH_LOGIN_SUCCESS',
                            'USER',
                            (string)$user['id'],
                            "Authorized user {$user['name']} ({$user['role']}) signed in successfully.",
                            (int)$user['id'],
                            $user['role']
                        );

                        // Handle Redirect
                        if (!empty($redirect) && strpos($redirect, 'login.php') === false && strpos($redirect, 'logout.php') === false) {
                            header('Location: ' . $redirect);
                            exit;
                        }

                        $roleHomes = [
                            'manufacturer' => 'manufacturer.php',
                            'distributor' => 'distributor.php',
                            'retailer' => 'retailer_pos.php',
                            'consumer' => 'consumer.php'
                        ];
                        header('Location: ' . ($roleHomes[$user['role']] ?? 'index.php'));
                        exit;
                    } else {
                        // Audit Log Failed Login
                        Database::logAudit(
                            'AUTH_LOGIN_FAILED',
                            'SECURITY',
                            $email,
                            "Failed authentication attempt for email: {$email}."
                        );
                        $error = 'Invalid credentials. Please verify your email and security password.';
                    }
                } catch (Exception $e) {
                    error_log("Login Exception: " . $e->getMessage());
                    $error = 'Authentication system error. Please contact security administrator.';
                }
            }
        }
    }
}

$pdo = Database::getConnection();
$mfrUsers = $pdo->query("SELECT id, name, email, role FROM users WHERE role = 'manufacturer' ORDER BY name ASC")->fetchAll();
$distUsers = $pdo->query("SELECT id, name, email, role FROM users WHERE role = 'distributor' ORDER BY name ASC")->fetchAll();
$retUsers = $pdo->query("SELECT id, name, email, role FROM users WHERE role = 'retailer' ORDER BY name ASC")->fetchAll();
$consUsers = $pdo->query("SELECT id, name, email, role FROM users WHERE role = 'consumer' ORDER BY name ASC")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width: 1100px; margin: 2rem auto; padding: 0 1rem;">
  <div class="login-split-wrapper">
    
    <!-- Left Enterprise Showcase Panel -->
    <div class="login-showcase-panel">
      <div>
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;">
          <div class="brand-symbol" style="width: 44px; height: 44px; font-size: 1.2rem; background: linear-gradient(135deg, #0284C7, #0369A1); color: #FFFFFF; border: none; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.4);">Rx</div>
          <div>
            <div style="font-weight: 800; font-size: 1.25rem; letter-spacing: -0.02em; color: #FFFFFF; line-height: 1.2;">PharmaSys Hub</div>
            <div style="font-size: 0.72rem; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600;">Operating System</div>
          </div>
        </div>

        <div style="display: inline-flex; align-items: center; gap: 0.4rem; background: rgba(2, 132, 199, 0.15); border: 1px solid rgba(56, 189, 248, 0.3); border-radius: 999px; padding: 0.3rem 0.75rem; font-size: 0.72rem; color: #38BDF8; font-weight: 600; margin-bottom: 1.25rem;">
          <?= icon('shield-check', 14) ?> CDSCO G.S.R. 1337(E) &amp; GS1 Compliant
        </div>

        <h1 style="font-size: 1.55rem; font-weight: 800; line-height: 1.3; color: #F8FAFC; margin-bottom: 0.75rem; letter-spacing: -0.01em;">
          Cryptographic Integrity &amp; Supply Chain Ledger
        </h1>

        <p style="font-size: 0.85rem; color: #94A3B8; line-height: 1.55; margin-bottom: 0;">
          Uniting licensed manufacturers, logistics distributors, retail pharmacies, and consumers on a unified verifiable network.
        </p>
      </div>

      <!-- Live Feature Showcase Cards -->
      <div class="showcase-features">
        <div class="showcase-feature-item">
          <div class="showcase-icon-box">
            <?= icon('activity', 18) ?>
          </div>
          <div>
            <div style="font-weight: 700; font-size: 0.825rem; color: #F1F5F9;">Smart Cascade Recalls</div>
            <div style="font-size: 0.735rem; color: #94A3B8; margin-top: 0.15rem; line-height: 1.4;">Zero-latency batch freezing across all downstream pharmacy POS terminals.</div>
          </div>
        </div>

        <div class="showcase-feature-item">
          <div class="showcase-icon-box">
            <?= icon('message-circle', 18) ?>
          </div>
          <div>
            <div style="font-weight: 700; font-size: 0.825rem; color: #F1F5F9;">Meta WhatsApp Cloud Invoicing</div>
            <div style="font-size: 0.735rem; color: #94A3B8; margin-top: 0.15rem; line-height: 1.4;">Automated tax invoices and prescription dispatch alerts sent directly to patient phones.</div>
          </div>
        </div>

        <div class="showcase-feature-item">
          <div class="showcase-icon-box">
            <?= icon('shield-check', 18) ?>
          </div>
          <div>
            <div style="font-weight: 700; font-size: 0.825rem; color: #F1F5F9;">Zero-Trust Hardened Security</div>
            <div style="font-size: 0.735rem; color: #94A3B8; margin-top: 0.15rem; line-height: 1.4;">Bcrypt password hashing, anti-session fixation, CSRF validation &amp; rate limiting.</div>
          </div>
        </div>
      </div>

      <!-- Live Ledger Status Pill -->
      <div style="display: flex; align-items: center; justify-content: space-between; padding-top: 1.25rem; border-top: 1px solid rgba(255, 255, 255, 0.08); font-size: 0.75rem; color: #94A3B8;">
        <span style="display: flex; align-items: center; gap: 0.45rem;">
          <span style="width: 8px; height: 8px; border-radius: 50%; background: #10B981; display: inline-block; box-shadow: 0 0 8px #10B981;"></span>
          Ledger Node: <strong>Active &amp; In Sync</strong>
        </span>
        <span style="font-family: var(--font-mono); font-size: 0.7rem; color: #64748B;">144 Demo Accounts</span>
      </div>
    </div>

    <!-- Right Modern Sign-In Card -->
    <div class="login-card-main">
      <div>
        <div style="margin-bottom: 1.25rem;">
          <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.4rem;">
            <span style="display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); background: var(--color-primary-light); padding: 0.2rem 0.6rem; border-radius: 999px;">
              <?= icon('lock', 12) ?> Authorized Access
            </span>
            <span style="font-size: 0.75rem; color: var(--text-subtle);">Demo PWD: <code>password123</code></span>
          </div>
          <h2 style="font-size: 1.4rem; font-weight: 800; color: var(--text-main); margin: 0; letter-spacing: -0.02em;">
            Sign In to Workspace
          </h2>
          <p style="font-size: 0.825rem; color: var(--text-muted); margin-top: 0.35rem; margin-bottom: 0;">
            Select your operational role below or sign in with your email.
          </p>
        </div>

        <?php if (isset($_GET['error']) && $_GET['error'] === 'auth_required'): ?>
          <div class="alert alert-warning" style="margin-bottom: 1.25rem;">
            <?= icon('shield-alert', 16) ?>
            <div><strong>Authentication Required:</strong> Please sign in to access network portals and protected tools.</div>
          </div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="alert alert-danger" style="margin-bottom: 1.25rem;">
            <?= icon('alert-triangle', 16) ?>
            <div><?= htmlspecialchars($error) ?></div>
          </div>
        <?php endif; ?>

        <!-- Role Quick-Switcher Tabs -->
        <div class="role-tabs-bar" role="tablist">
          <button type="button" class="role-tab-btn active" id="tab-retailer" onclick="selectRoleTab('retailer')">
            <span style="font-size: 1.15rem; line-height: 1;">🏪</span>
            <span>Retail Pharmacy</span>
          </button>
          <button type="button" class="role-tab-btn" id="tab-consumer" onclick="selectRoleTab('consumer')">
            <span style="font-size: 1.15rem; line-height: 1;">👤</span>
            <span>Patient / User</span>
          </button>
          <button type="button" class="role-tab-btn" id="tab-distributor" onclick="selectRoleTab('distributor')">
            <span style="font-size: 1.15rem; line-height: 1;">🚚</span>
            <span>Distributor</span>
          </button>
          <button type="button" class="role-tab-btn" id="tab-manufacturer" onclick="selectRoleTab('manufacturer')">
            <span style="font-size: 1.15rem; line-height: 1;">🏭</span>
            <span>Manufacturer</span>
          </button>
        </div>

        <!-- Credentials Form -->
        <form method="POST" action="login.php<?= !empty($redirect) ? '?redirect=' . urlencode($redirect) : '' ?>" id="loginForm">
          <?= csrf_field() ?>
          
          <div class="form-group" style="margin-bottom: 1.15rem;">
            <label class="form-label" for="login-email" style="font-size: 0.8rem; font-weight: 600;">Registered Email Address</label>
            <div class="input-icon-group">
              <span class="input-icon-prefix"><?= icon('mail', 18) ?></span>
              <input type="email" id="login-email" name="email" class="form-control" placeholder="name@pharmasys.com" required value="<?= htmlspecialchars($_POST['email'] ?? 'retailer@pharmasys.com') ?>">
            </div>
          </div>

          <div class="form-group" style="margin-bottom: 1.25rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
              <label class="form-label" for="login-password" style="font-size: 0.8rem; font-weight: 600; margin-bottom: 0;">Security Password</label>
              <span style="font-size: 0.72rem; color: var(--color-primary); cursor: pointer;" onclick="document.getElementById('login-password').value='password123'; showToast('Demo password filled: password123', 'info');">Fill Demo Password</span>
            </div>
            <div class="input-icon-group">
              <span class="input-icon-prefix"><?= icon('lock', 18) ?></span>
              <input type="password" id="login-password" name="password" class="form-control" placeholder="••••••••••••" required value="password123">
              <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility()" title="Toggle password visibility" id="pwdToggleBtn">
                <?= icon('eye', 18) ?>
              </button>
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-block" style="padding: 0.75rem 1rem; font-weight: 700; font-size: 0.95rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem; box-shadow: 0 4px 14px rgba(2, 132, 199, 0.28);">
            <?= icon('check', 18) ?> Sign In to Workspace
          </button>
        </form>
      </div>

      <!-- Quick Auto-Fill Account Selector Drawer -->
      <div class="quick-accounts-box">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.65rem;">
          <span style="font-size: 0.775rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 0.4rem;">
            <?= icon('search', 14) ?> Instant Account Finder
          </span>
          <span class="badge badge-neutral" style="font-size: 0.68rem;">144 Active Demo Profiles</span>
        </div>

        <!-- Instant Live Search Bar -->
        <div style="position: relative; margin-bottom: 0.65rem;">
          <input type="text" id="account-search-input" class="form-control" 
                 placeholder="Search by name (e.g. CareFirst, Rahul, Apex, Nexus)..." 
                 style="font-size: 0.825rem; padding: 0.45rem 0.75rem; height: 36px;" 
                 autocomplete="off"
                 oninput="handleSearchInput(this.value)"
                 onfocus="handleSearchFocus()">

          <!-- Floating Suggestions -->
          <div id="search-results-dropdown" style="display: none; position: absolute; left: 0; right: 0; top: calc(100% + 4px); background: var(--bg-surface); border: 1px solid var(--border-light); border-radius: 8px; box-shadow: var(--shadow-lg); max-height: 220px; overflow-y: auto; z-index: 1000;"></div>
        </div>

        <!-- Filter Dropdown by Selected Role -->
        <div>
          <select id="account-dropdown-select" class="form-control" style="font-size: 0.8rem; padding: 0.45rem 0.65rem; height: 36px;" onchange="handleAccountDropdownSelect(this)">
            <!-- Populated dynamically via JS -->
          </select>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
const accountsByRole = {
  consumer: <?= json_encode(array_values($consUsers)) ?>,
  retailer: <?= json_encode(array_values($retUsers)) ?>,
  distributor: <?= json_encode(array_values($distUsers)) ?>,
  manufacturer: <?= json_encode(array_values($mfrUsers)) ?>
};

const defaultRoleAccounts = {
  retailer: 'retailer@pharmasys.com',
  consumer: 'consumer@pharmasys.com',
  distributor: 'distributor@pharmasys.com',
  manufacturer: 'manufacturer@pharmasys.com'
};

const allAccounts = <?= json_encode(array_values(array_merge($consUsers, $retUsers, $mfrUsers, $distUsers))) ?>;

let currentRole = 'retailer';

function selectRoleTab(role) {
  currentRole = role;
  
  // Update Tab UI classes
  document.querySelectorAll('.role-tab-btn').forEach(btn => btn.classList.remove('active'));
  const activeBtn = document.getElementById('tab-' + role);
  if (activeBtn) activeBtn.classList.add('active');

  // Populate Default Email for this role
  const defaultEmail = defaultRoleAccounts[role] || (accountsByRole[role][0] ? accountsByRole[role][0].email : '');
  if (defaultEmail) {
    document.getElementById('login-email').value = defaultEmail;
    document.getElementById('login-password').value = 'password123';
  }

  // Update cascading dropdown list
  updateAccountDropdown(role);
}

function updateAccountDropdown(role) {
  const select = document.getElementById('account-dropdown-select');
  if (!select) return;

  const list = accountsByRole[role] || [];
  const roleNames = {
    consumer: 'Patients',
    retailer: 'Pharmacies',
    distributor: 'Distributors',
    manufacturer: 'Manufacturers'
  };

  let html = `<option value="">-- Choose from ${list.length} ${roleNames[role]} --</option>`;
  list.forEach(acc => {
    const isSelected = acc.email === document.getElementById('login-email').value ? 'selected' : '';
    html += `<option value="${escapeHtml(acc.email)}" data-name="${escapeHtml(acc.name)}" ${isSelected}>${escapeHtml(acc.name)} (${escapeHtml(acc.email)})</option>`;
  });
  select.innerHTML = html;
}

function handleAccountDropdownSelect(selectElem) {
  const email = selectElem.value;
  if (!email) return;
  const opt = selectElem.options[selectElem.selectedIndex];
  const name = opt.getAttribute('data-name') || opt.text;
  document.getElementById('account-search-input').value = name;
  setDemo(email, name);
}

function setDemo(email, label) {
  document.getElementById('login-email').value = email;
  document.getElementById('login-password').value = 'password123';
  document.getElementById('search-results-dropdown').style.display = 'none';
  showToast('Loaded ' + label + '. Ready to sign in.', 'info');
}

function togglePasswordVisibility() {
  const pwdInput = document.getElementById('login-password');
  const btn = document.getElementById('pwdToggleBtn');
  if (pwdInput.type === 'password') {
    pwdInput.type = 'text';
    btn.innerHTML = `<?= icon('eye-off', 18) ?>`;
  } else {
    pwdInput.type = 'password';
    btn.innerHTML = `<?= icon('eye', 18) ?>`;
  }
}

function handleSearchInput(query) {
  const q = query.toLowerCase().trim();
  const dropdown = document.getElementById('search-results-dropdown');
  
  if (!q) {
    dropdown.style.display = 'none';
    return;
  }

  const matches = allAccounts.filter(acc => 
    (acc.name && acc.name.toLowerCase().includes(q)) || 
    (acc.email && acc.email.toLowerCase().includes(q))
  );

  if (matches.length === 0) {
    dropdown.innerHTML = '<div style="padding: 0.75rem; text-align: center; color: var(--text-subtle); font-size: 0.8rem;">No matching accounts found.</div>';
    dropdown.style.display = 'block';
    return;
  }

  let html = '';
  matches.slice(0, 12).forEach(m => {
    const role = m.role || 'consumer';
    const roleIcon = role === 'consumer' ? '👤' : (role === 'retailer' ? '🏪' : (role === 'manufacturer' ? '🏭' : '🚚'));
    const roleBadge = role === 'consumer' ? 'Patient' : role.charAt(0).toUpperCase() + role.slice(1);
    
    html += `
      <div onclick="selectSearchResult('${escapeHtml(m.email)}', '${escapeHtml(m.name)}', '${escapeHtml(role)}')" 
           style="padding: 0.55rem 0.85rem; border-bottom: 1px solid var(--border-light); cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: background 0.15s;" 
           onmouseover="this.style.background='var(--bg-surface-active)'" 
           onmouseout="this.style.background='var(--bg-surface)'">
        <div>
          <div style="font-size: 0.825rem; font-weight: 700; color: var(--text-main);">
            ${roleIcon} ${escapeHtml(m.name)}
          </div>
          <div style="font-size: 0.72rem; color: var(--text-subtle); font-family: var(--font-mono);">
            ${escapeHtml(m.email)}
          </div>
        </div>
        <span class="badge badge-neutral" style="font-size: 0.65rem;">${roleBadge}</span>
      </div>
    `;
  });

  if (matches.length > 12) {
    html += `<div style="padding: 0.4rem; text-align: center; font-size: 0.7rem; color: var(--text-subtle); background: var(--bg-surface-subtle);">+ ${matches.length - 12} more matches.</div>`;
  }

  dropdown.innerHTML = html;
  dropdown.style.display = 'block';
}

function handleSearchFocus() {
  const input = document.getElementById('account-search-input');
  if (input.value.trim().length > 0) {
    handleSearchInput(input.value);
  }
}

function selectSearchResult(email, name, role) {
  document.getElementById('account-search-input').value = name;
  if (role) {
    selectRoleTab(role);
  }
  setDemo(email, name);
  const select = document.getElementById('account-dropdown-select');
  if (select) select.value = email;
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/[&<>'"]/g, 
    tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag] || tag)
  );
}

// Close search dropdown on click outside
document.addEventListener('click', (e) => {
  const searchBox = document.getElementById('account-search-input');
  const dropdown = document.getElementById('search-results-dropdown');
  if (dropdown && !dropdown.contains(e.target) && e.target !== searchBox) {
    dropdown.style.display = 'none';
  }
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
  updateAccountDropdown(currentRole);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
