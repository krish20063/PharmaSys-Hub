<?php
$pageTitle = 'Live Database Explorer & Schema Telemetry';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_auth();

$pdo = Database::getConnection();
$driver = Database::getDriver();
$status = Database::getStatus();
$dbName = defined('DB_NAME') ? DB_NAME : 'pharmasys_hub';
$dbHost = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$dbPort = defined('DB_PORT') ? DB_PORT : '3306';

// Get all tables
$tables = [];
try {
    if ($driver === 'sqlite') {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC");
        $rawTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $stmt = $pdo->query("SHOW TABLES");
        $rawTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    foreach ($rawTables as $t) {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM `{$t}`");
        $count = $countStmt ? (int)$countStmt->fetchColumn() : 0;
        $tables[] = [
            'name' => $t,
            'count' => $count
        ];
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Active selected table
$selectedTable = clean_string($_GET['table'] ?? ($tables[0]['name'] ?? 'users'));
$activeTab = clean_string($_GET['tab'] ?? 'data'); // 'data', 'structure', 'query'

// Fetch table structure
$columns = [];
try {
    if ($driver === 'sqlite') {
        $colStmt = $pdo->query("PRAGMA table_info(`{$selectedTable}`)");
        while ($c = $colStmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = [
                'field' => $c['name'],
                'type' => $c['type'],
                'null' => $c['notnull'] ? 'NO' : 'YES',
                'key' => $c['pk'] ? 'PRI' : '',
                'default' => $c['dflt_value']
            ];
        }
    } else {
        $colStmt = $pdo->query("DESCRIBE `{$selectedTable}`");
        while ($c = $colStmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = [
                'field' => $c['Field'],
                'type' => $c['Type'],
                'null' => $c['Null'],
                'key' => $c['Key'],
                'default' => $c['Default']
            ];
        }
    }
} catch (Exception $e) {
    $structureError = $e->getMessage();
}

// Fetch table data (first 50 rows)
$rows = [];
$totalRows = 0;
try {
    $countQ = $pdo->query("SELECT COUNT(*) FROM `{$selectedTable}`");
    $totalRows = $countQ ? (int)$countQ->fetchColumn() : 0;

    $dataStmt = $pdo->query("SELECT * FROM `{$selectedTable}` ORDER BY 1 DESC LIMIT 50");
    if ($dataStmt) {
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $dataError = $e->getMessage();
}

// Custom SQL Query Runner
$queryResult = null;
$queryError = null;
$executedQuery = trim($_POST['sql_query'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($executedQuery)) {
    if (!verify_csrf_token()) {
        $queryError = 'Security Error: CSRF token validation failed.';
    } else {
        // Only permit read-only SELECT or EXPLAIN queries for safe exploration
        if (!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\s+/i', $executedQuery)) {
            $queryError = 'Security Restriction: Only read-only queries (SELECT, SHOW, DESCRIBE) can be executed in Database Explorer.';
        } else {
            try {
                $qStmt = $pdo->query($executedQuery);
                $queryResult = $qStmt->fetchAll(PDO::FETCH_ASSOC);
                $activeTab = 'query';
            } catch (Exception $e) {
                $queryError = $e->getMessage();
                $activeTab = 'query';
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width: 1200px; margin: 1.5rem auto;">
  <!-- Header Banner -->
  <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
      <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
        <span class="badge badge-primary"><?= icon('database', 14) ?> System Schema</span>
        <h2 style="font-size: 1.4rem; font-weight: 800; color: var(--text-main); margin: 0;">
          Live Database Explorer &amp; Architecture
        </h2>
      </div>
      <p style="font-size: 0.825rem; color: var(--text-subtle); margin: 0;">
        Inspect live relational tables, verify row state, review constraints, and explore enterprise database telemetry.
      </p>
    </div>

    <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
      <a href="https://tidbcloud.com/" target="_blank" class="btn btn-outline btn-sm" style="display: flex; align-items: center; gap: 0.4rem;">
        <?= icon('external-link', 14) ?> Open TiDB Cloud Console &rarr;
      </a>
      <a href="index.php" class="btn btn-primary btn-sm">
        <?= icon('home', 14) ?> Return to Workspace
      </a>
    </div>
  </div>

  <!-- Connection Status Banner -->
  <div class="card" style="margin-bottom: 1.5rem; padding: 1.25rem; background: var(--bg-surface-subtle); border: 1px solid var(--border-light);">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
      <div>
        <div style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-subtle); font-weight: 700;">Active Database Engine</div>
        <div style="font-size: 1rem; font-weight: 800; color: var(--text-main); margin-top: 0.2rem; display: flex; align-items: center; gap: 0.4rem;">
          <span style="width: 9px; height: 9px; border-radius: 50%; background: #10B981; display: inline-block; box-shadow: 0 0 8px #10B981;"></span>
          <?= htmlspecialchars($status) ?>
        </div>
      </div>

      <div>
        <div style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-subtle); font-weight: 700;">Database Schema</div>
        <div style="font-size: 1rem; font-weight: 800; color: var(--color-primary-dark); margin-top: 0.2rem;">
          <code><?= htmlspecialchars($dbName) ?></code>
        </div>
      </div>

      <div>
        <div style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-subtle); font-weight: 700;">Cloud Host &amp; Endpoint</div>
        <div style="font-size: 0.85rem; font-family: var(--font-mono); color: var(--text-muted); margin-top: 0.2rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
          <?= htmlspecialchars($dbHost) ?>:<?= htmlspecialchars($dbPort) ?>
        </div>
      </div>

      <div>
        <div style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-subtle); font-weight: 700;">Total Relational Tables</div>
        <div style="font-size: 1rem; font-weight: 800; color: #059669; margin-top: 0.2rem;">
          <?= count($tables) ?> Tables Active
        </div>
      </div>
    </div>
  </div>

  <!-- Explorer Main Grid -->
  <div style="display: grid; grid-template-columns: 280px 1fr; gap: 1.5rem; align-items: flex-start;">
    
    <!-- Left Tables List Sidebar -->
    <div class="card" style="padding: 0; overflow: hidden;">
      <div style="padding: 1rem; border-bottom: 1px solid var(--border-light); background: var(--bg-surface-subtle); display: flex; justify-content: space-between; align-items: center;">
        <span style="font-size: 0.85rem; font-weight: 700; color: var(--text-main);">Database Tables</span>
        <span class="badge badge-neutral" style="font-size: 0.7rem;"><?= count($tables) ?></span>
      </div>

      <div style="max-height: 600px; overflow-y: auto;">
        <?php foreach ($tables as $t): ?>
          <a href="db_explorer.php?table=<?= urlencode($t['name']) ?>&tab=<?= urlencode($activeTab) ?>" 
             style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; text-decoration: none; border-bottom: 1px solid var(--border-light); transition: background 0.15s; <?= $selectedTable === $t['name'] ? 'background: var(--color-primary-bg); font-weight: 700; border-left: 3px solid var(--color-primary);' : 'color: var(--text-main);' ?>">
            <span style="font-family: var(--font-mono); font-size: 0.825rem; color: <?= $selectedTable === $t['name'] ? 'var(--color-primary-dark)' : 'var(--text-main)' ?>;">
              <?= icon('table', 13) ?> <?= htmlspecialchars($t['name']) ?>
            </span>
            <span class="badge <?= $selectedTable === $t['name'] ? 'badge-primary' : 'badge-neutral' ?>" style="font-size: 0.7rem;">
              <?= number_format($t['count']) ?>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Right Content Area -->
    <div>
      <!-- Table Header & Action Tabs -->
      <div class="card" style="margin-bottom: 1.25rem; padding: 1.25rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
          <div>
            <h3 style="font-size: 1.2rem; font-weight: 800; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 0.4rem;">
              <code><?= htmlspecialchars($selectedTable) ?></code>
            </h3>
            <p style="font-size: 0.78rem; color: var(--text-subtle); margin: 0.2rem 0 0 0;">
              Total records: <strong><?= number_format($totalRows) ?></strong> &bull; Showing up to 50 latest rows
            </p>
          </div>

          <!-- Nav Pills -->
          <div style="display: flex; gap: 0.4rem;">
            <a href="db_explorer.php?table=<?= urlencode($selectedTable) ?>&tab=data" 
               class="btn btn-sm <?= $activeTab === 'data' ? 'btn-primary' : 'btn-outline' ?>">
              <?= icon('file-text', 13) ?> Browse Data
            </a>
            <a href="db_explorer.php?table=<?= urlencode($selectedTable) ?>&tab=structure" 
               class="btn btn-sm <?= $activeTab === 'structure' ? 'btn-primary' : 'btn-outline' ?>">
              <?= icon('layers', 13) ?> Structure
            </a>
            <a href="db_explorer.php?table=<?= urlencode($selectedTable) ?>&tab=query" 
               class="btn btn-sm <?= $activeTab === 'query' ? 'btn-primary' : 'btn-outline' ?>">
              <?= icon('terminal', 13) ?> Run Query
            </a>
          </div>
        </div>
      </div>

      <!-- Tab 1: Browse Data -->
      <?php if ($activeTab === 'data'): ?>
        <div class="card" style="padding: 0; overflow: hidden;">
          <?php if (empty($rows)): ?>
            <div style="padding: 3rem; text-align: center; color: var(--text-subtle);">
              <?= icon('inbox', 28) ?><br>
              <span style="display: block; margin-top: 0.5rem; font-size: 0.9rem;">Table <code><?= htmlspecialchars($selectedTable) ?></code> is currently empty (0 rows).</span>
            </div>
          <?php else: ?>
            <div class="table-wrapper" style="margin: 0; max-height: 540px; overflow: auto;">
              <table class="data-table" style="font-size: 0.8rem; margin: 0;">
                <thead style="position: sticky; top: 0; z-index: 2; background: var(--bg-surface);">
                  <tr>
                    <?php foreach (array_keys($rows[0]) as $col): ?>
                      <th><?= htmlspecialchars($col) ?></th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $r): ?>
                    <tr>
                      <?php foreach ($r as $colName => $val): ?>
                        <td style="max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                          <?php if ($val === null): ?>
                            <span style="color: var(--text-muted); font-style: italic;">NULL</span>
                          <?php elseif (strlen($val) > 80): ?>
                            <span title="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars(substr($val, 0, 80)) ?>...</span>
                          <?php else: ?>
                            <?= htmlspecialchars($val) ?>
                          <?php endif; ?>
                        </td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- Tab 2: Structure -->
      <?php if ($activeTab === 'structure'): ?>
        <div class="card" style="padding: 0; overflow: hidden;">
          <div class="table-wrapper" style="margin: 0;">
            <table class="data-table" style="font-size: 0.825rem; margin: 0;">
              <thead>
                <tr>
                  <th>Field / Column</th>
                  <th>Data Type</th>
                  <th>Nullable</th>
                  <th>Key</th>
                  <th>Default Value</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($columns as $c): ?>
                  <tr>
                    <td><strong style="color: var(--text-main);"><?= htmlspecialchars($c['field']) ?></strong></td>
                    <td><code><?= htmlspecialchars($c['type']) ?></code></td>
                    <td>
                      <?php if ($c['null'] === 'YES'): ?>
                        <span class="badge badge-neutral" style="font-size: 0.68rem;">Nullable</span>
                      <?php else: ?>
                        <span class="badge badge-warning" style="font-size: 0.68rem;">NOT NULL</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($c['key'] === 'PRI'): ?>
                        <span class="badge badge-success" style="font-size: 0.68rem;">Primary Key</span>
                      <?php elseif (!empty($c['key'])): ?>
                        <span class="badge badge-primary" style="font-size: 0.68rem;"><?= htmlspecialchars($c['key']) ?></span>
                      <?php else: ?>
                        <span style="color: var(--text-muted);">-</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span style="font-family: var(--font-mono); font-size: 0.75rem; color: var(--text-subtle);">
                        <?= $c['default'] !== null ? htmlspecialchars($c['default']) : '<span style="font-style:italic;">None</span>' ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

      <!-- Tab 3: Run SQL Query -->
      <?php if ($activeTab === 'query'): ?>
        <div class="card" style="margin-bottom: 1.5rem; padding: 1.25rem;">
          <form method="POST" action="db_explorer.php?table=<?= urlencode($selectedTable) ?>&tab=query">
            <?= csrf_field() ?>
            <div class="form-group" style="margin-bottom: 1rem;">
              <label class="form-label" style="font-size: 0.825rem; font-weight: 700; display: flex; justify-content: space-between;">
                <span>SQL Query Console (SELECT only)</span>
                <span style="font-size: 0.72rem; color: var(--text-subtle);">Example: SELECT * FROM medicine_master LIMIT 10</span>
              </label>
              <textarea name="sql_query" class="form-control" rows="4" style="font-family: var(--font-mono); font-size: 0.85rem;" placeholder="SELECT * FROM `<?= htmlspecialchars($selectedTable) ?>` LIMIT 20;"><?= htmlspecialchars($executedQuery ?: "SELECT * FROM `{$selectedTable}` LIMIT 20;") ?></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
              <button type="submit" class="btn btn-primary btn-sm" style="display: flex; align-items: center; gap: 0.35rem;">
                <?= icon('check', 14) ?> Execute Query
              </button>
            </div>
          </form>
        </div>

        <?php if ($queryError): ?>
          <div class="alert alert-danger" style="margin-bottom: 1.25rem;">
            <?= icon('alert-triangle', 16) ?>
            <div><strong>Query Execution Error:</strong> <?= htmlspecialchars($queryError) ?></div>
          </div>
        <?php endif; ?>

        <?php if ($queryResult !== null): ?>
          <div class="card" style="padding: 0; overflow: hidden;">
            <div style="padding: 0.85rem 1rem; background: var(--bg-surface-subtle); border-bottom: 1px solid var(--border-light); font-size: 0.8rem; font-weight: 700; color: var(--text-main);">
              Query Results (<?= count($queryResult) ?> rows returned)
            </div>
            <?php if (empty($queryResult)): ?>
              <div style="padding: 2rem; text-align: center; color: var(--text-subtle);">Query executed successfully. 0 rows matched.</div>
            <?php else: ?>
              <div class="table-wrapper" style="margin: 0; max-height: 480px; overflow: auto;">
                <table class="data-table" style="font-size: 0.8rem; margin: 0;">
                  <thead>
                    <tr>
                      <?php foreach (array_keys($queryResult[0]) as $col): ?>
                        <th><?= htmlspecialchars($col) ?></th>
                      <?php endforeach; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($queryResult as $qr): ?>
                      <tr>
                        <?php foreach ($qr as $v): ?>
                          <td><?= htmlspecialchars($v ?? 'NULL') ?></td>
                        <?php endforeach; ?>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>

    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
