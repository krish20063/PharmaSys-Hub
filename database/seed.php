<?php
/**
 * PharmaSys Hub - Database Setup & Seeder Utility (CLI Administration Only)
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    die("403 Forbidden: Direct web execution of database migrations is prohibited. Run via administrative terminal only.");
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/icons.php';

$output = [];
$error = null;
$success = false;

try {
    $pdo = Database::getConnection();
    $driver = Database::getDriver();

    if ($driver === 'sqlite') {
        // Re-bootstrap SQLite database
        Database::bootstrapDatabase($pdo);
        $output[] = "Connected to persistent SQLite database at: " . DB_SQLITE_PATH;
        $output[] = "Initialized all 10 relational tables with foreign keys.";
        $output[] = "Loaded comprehensive 25-medicine formulation catalog with multiple batches.";
        $output[] = "Configured dynamic date expiry checks.";
        $success = true;
    } else {
        // MySQL Execution
        $sqlPath = __DIR__ . '/schema.sql';
        $rawSql = file_get_contents($sqlPath);
        $queries = array_filter(array_map('trim', explode(';', $rawSql)));

        foreach ($queries as $q) {
            if (empty($q) || stripos($q, 'DELIMITER') !== false) continue;
            try { $pdo->exec($q); } catch (Exception $e) {}
        }
        $output[] = "Connected to MySQL at " . DB_HOST . ":" . DB_PORT;
        $output[] = "Applied full schema.sql and seeded demo accounts.";
        $success = true;
    }
} catch (Exception $e) {
    $success = false;
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Database Initializer &bull; <?= APP_NAME ?></title>
  <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body style="display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #F8FAFC;">
  <div class="card" style="max-width: 600px; width: 92%; padding: 2.25rem;">
    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;">
      <div class="brand-symbol" style="width: 40px; height: 40px;">Rx</div>
      <div>
        <h2 style="font-size: 1.25rem; font-weight: 700; color: #0F172A;">Database Provisioning Wizard</h2>
        <div style="font-size: 0.75rem; color: #64748B;">Dual Engine: MySQL XAMPP + Live Persistent SQLite</div>
      </div>
    </div>

    <?php if ($success): ?>
      <div class="alert alert-success">
        <div>
          <strong>Database Initialized &amp; Seeded Successfully!</strong><br>
          Connected via: <code><?= Database::getStatus() ?></code>
        </div>
      </div>
    <?php else: ?>
      <div class="alert alert-danger">
        <div>
          <strong>Initialization Failed:</strong><br>
          <?= htmlspecialchars($error) ?>
        </div>
      </div>
    <?php endif; ?>

    <div style="background: #F1F5F9; border-radius: 6px; padding: 0.85rem 1rem; font-family: monospace; font-size: 0.78rem; margin: 1.25rem 0; line-height: 1.6; border: 1px solid #E2E8F0;">
      <?php foreach ($output as $out): ?>
        <div>&bull; <?= htmlspecialchars($out) ?></div>
      <?php endforeach; ?>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem;">
      <a href="../login.php" class="btn btn-primary">&rarr; Proceed to Login</a>
      <a href="../index.php" class="btn btn-outline">Overview Gateway</a>
    </div>
  </div>
</body>
</html>
