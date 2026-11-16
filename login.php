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

require_once __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width: 1040px; margin: 2rem auto; padding: 0 1rem;">
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
        <span style="font-family: var(--font-mono); font-size: 0.7rem; color: #64748B;">Level-4 RBAC Guarded</span>
      </div>
    </div>

    <!-- Right Modern Sign-In Card -->
    <div class="login-card-main">
      <div>
        <div style="margin-bottom: 1.4rem;">
          <div style="margin-bottom: 0.45rem;">
            <span style="display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary-dark); background: var(--color-primary-bg); border: 1px solid var(--color-primary-border); padding: 0.2rem 0.65rem; border-radius: 999px;">
              <?= icon('lock', 12) ?> Authorized Workspace Access
            </span>
          </div>
          <h2 style="font-size: 1.45rem; font-weight: 800; color: var(--text-main); margin: 0; letter-spacing: -0.02em;">
            Sign In to Workspace
          </h2>
          <p style="font-size: 0.825rem; color: var(--text-muted); margin-top: 0.35rem; margin-bottom: 0;">
            Select your operational role and authenticate with your registered credentials.
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

        <!-- Role Tabs -->
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
              <input type="email" id="login-email" name="email" class="form-control" placeholder="retailer@pharmasys.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" autocomplete="email">
            </div>
          </div>

          <div class="form-group" style="margin-bottom: 1.35rem;">
            <label class="form-label" for="login-password" style="font-size: 0.8rem; font-weight: 600;">Security Password</label>
            <div class="input-icon-group">
              <span class="input-icon-prefix"><?= icon('lock', 18) ?></span>
              <input type="password" id="login-password" name="password" class="form-control" placeholder="••••••••••••" required autocomplete="current-password">
              <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility()" title="Toggle password visibility" id="pwdToggleBtn">
                <?= icon('eye', 18) ?>
              </button>
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-block" style="padding: 0.8rem 1rem; font-weight: 700; font-size: 0.95rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem; box-shadow: 0 4px 14px rgba(2, 132, 199, 0.28);">
            <?= icon('check', 18) ?> Sign In to Workspace
          </button>
        </form>
      </div>

      <div style="margin-top: 2rem; padding-top: 1.25rem; border-top: 1px solid var(--border-light); font-size: 0.75rem; color: var(--text-subtle); display: flex; align-items: center; justify-content: space-between;">
        <span style="display: flex; align-items: center; gap: 0.35rem;">
          <?= icon('shield-check', 14) ?> 256-Bit TLS &amp; Encrypted Session
        </span>
        <span>CDSCO Rule 65 Compliant</span>
      </div>

    </div>
  </div>
</div>

<script>
let currentRole = 'retailer';

const rolePlaceholders = {
  retailer: 'retailer@pharmasys.com',
  consumer: 'consumer@pharmasys.com',
  distributor: 'distributor@pharmasys.com',
  manufacturer: 'manufacturer@pharmasys.com'
};

function selectRoleTab(role) {
  currentRole = role;
  document.querySelectorAll('.role-tab-btn').forEach(btn => btn.classList.remove('active'));
  const activeBtn = document.getElementById('tab-' + role);
  if (activeBtn) activeBtn.classList.add('active');

  const emailInput = document.getElementById('login-email');
  if (emailInput && !emailInput.value) {
    emailInput.placeholder = rolePlaceholders[role] || 'name@pharmasys.com';
  }
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
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
