<?php
/**
 * PharmaSys Hub - Enterprise Live Database Connector
 * Dual-engine architecture:
 * 1. Live MySQL (when XAMPP is active)
 * 2. Live File-based SQLite Database (Zero-config, real persistent disk storage)
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die("Direct access forbidden.");
}
require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $pdo = null;
    private static string $driver = '';
    private static string $status = 'disconnected';
    private static string $errorMessage = '';

    public static function getConnection(): PDO {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        // Try MySQL first if configured
        $attemptMysql = (DB_TYPE === 'mysql');
        if (DB_TYPE === 'auto') {
            if (DB_HOST !== '127.0.0.1' && DB_HOST !== 'localhost') {
                $attemptMysql = true;
            } else {
                // Quick-probe port to prevent 5000ms blocking timeouts when local MySQL is not running
                $probe = @fsockopen(DB_HOST, (int)DB_PORT, $errno, $errstr, 0.2);
                if ($probe) {
                    $attemptMysql = true;
                    fclose($probe);
                }
            }
        }

        if ($attemptMysql) {
            try {
                $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => true,
                    PDO::ATTR_TIMEOUT => 5,
                    PDO::ATTR_PERSISTENT => true
                ];

                // Check CA certificate bundle for Cloud TLS/SSL (TiDB Cloud, Aiven, PlanetScale, AWS RDS)
                $caCandidates = [
                    __DIR__ . '/cacert.pem',
                    '/etc/ssl/certs/ca-certificates.crt',
                    '/etc/pki/tls/certs/ca-bundle.crt',
                    ini_get('openssl.cafile') ?: '',
                    ini_get('curl.cainfo') ?: ''
                ];
                foreach ($caCandidates as $ca) {
                    if (!empty($ca) && file_exists($ca)) {
                        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
                        break;
                    }
                }
                if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                }
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                self::$driver = 'mysql';
                self::$status = 'MySQL Live (Cloud)';

                // Auto-bootstrap schema if connecting to an uninitialized database
                // Optimized: Cached in session to eliminate redundant table inspection queries on every page load
                if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['db_bootstrapped'])) {
                    try {
                        $testTable = self::$pdo->query("SHOW TABLES LIKE 'users'")->fetch();
                        if (!$testTable) {
                            $sqlPath = __DIR__ . '/../database/schema.sql';
                            if (file_exists($sqlPath)) {
                                $rawSql = file_get_contents($sqlPath);
                                $queries = array_filter(array_map('trim', explode(';', $rawSql)));
                                foreach ($queries as $q) {
                                    if (empty($q) || stripos($q, 'DELIMITER') !== false) continue;
                                    try { self::$pdo->exec($q); } catch (Exception $e) {}
                                }
                            }
                        }
                        $_SESSION['db_bootstrapped'] = true;
                    } catch (Exception $ignored) {}
                }

                self::runExpiryDaemon(self::$pdo);
                return self::$pdo;
            } catch (Exception $e) {
                if (DB_TYPE === 'mysql') {
                    throw new Exception("MySQL Connection Failed: " . $e->getMessage());
                }
            }
        }

        // Fallback: Persistent Live SQLite Database on disk
        try {
            $dbDir = dirname(DB_SQLITE_PATH);
            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0777, true);
            }

            $isNewDb = !file_exists(DB_SQLITE_PATH) || filesize(DB_SQLITE_PATH) === 0;

            self::$pdo = new PDO('sqlite:' . DB_SQLITE_PATH, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            self::$driver = 'sqlite';
            self::$status = 'SQLite Live DB (File-Backed)';

            self::$pdo->exec('PRAGMA foreign_keys = ON;');

            // Check if return_claims table exists, if not bootstrap
            $checkClaims = self::$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='return_claims'")->fetch();
            if ($isNewDb || !$checkClaims) {
                self::bootstrapDatabase(self::$pdo);
            }

            // Ensure audit_logs table exists
            self::$pdo->exec("
                CREATE TABLE IF NOT EXISTS audit_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NULL,
                    user_role TEXT DEFAULT 'system',
                    action_type TEXT NOT NULL,
                    entity_type TEXT NOT NULL,
                    entity_id TEXT NOT NULL,
                    description TEXT NOT NULL,
                    ip_address TEXT DEFAULT '127.0.0.1',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");

            self::runExpiryDaemon(self::$pdo);
            return self::$pdo;
        } catch (Exception $e) {
            self::$errorMessage = $e->getMessage();
            throw new Exception("Database Initialization Error: " . $e->getMessage());
        }
    }

    public static function getDriver(): string {
        if (self::$pdo === null) self::getConnection();
        return self::$driver;
    }

    public static function getStatus(): string {
        if (self::$pdo === null) {
            try { self::getConnection(); } catch (Exception $e) {}
        }
        return self::$status;
    }

    /**
     * Dynamic Calendar Expiry Daemon:
     * Evaluates current real-world date against batches and dynamically marks expired stock.
     * Throttled to run at most once per hour (3600s) to avoid redundant write locks on every request.
     */
    public static function runExpiryDaemon(PDO $pdo): void {
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['last_expiry_daemon_run'])) {
            if (time() - $_SESSION['last_expiry_daemon_run'] < 3600) {
                return;
            }
        }

        $today = date('Y-m-d');
        try {
            $stmt = $pdo->prepare("UPDATE batches SET is_expired = 1 WHERE expiry_date < :today AND is_expired = 0");
            $stmt->execute([':today' => $today]);
            
            // Also ensure future batches are marked unexpired
            $stmtRev = $pdo->prepare("UPDATE batches SET is_expired = 0 WHERE expiry_date >= :today AND is_expired = 1");
            $stmtRev->execute([':today' => $today]);

            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['last_expiry_daemon_run'] = time();
            }
        } catch (Exception $e) {
            // Silently continue if tables not yet ready
        }
    }

    /**
     * Immutable Audit Logger for CDSCO & Pharmacovigilance tracking
     */
    public static function logAudit(string $actionType, string $entityType, string $entityId, string $description, ?int $userId = null, ?string $userRole = null): void {
        try {
            $pdo = self::getConnection();
            $uid = $userId ?? ($_SESSION['user_id'] ?? null);
            $role = $userRole ?? ($_SESSION['user_role'] ?? 'system');
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, user_role, action_type, entity_type, entity_id, description, ip_address)
                VALUES (:uid, :role, :act, :ent, :eid, :desc, :ip)
            ");
            $stmt->execute([
                ':uid' => $uid,
                ':role' => $role,
                ':act' => $actionType,
                ':ent' => $entityType,
                ':eid' => $entityId,
                ':desc' => $description,
                ':ip' => $ip
            ]);
        } catch (Exception $e) {}
    }

    /**
     * Bootstraps schema & comprehensive pharmaceutical seed dataset with RELATIVE DATES
     */
    public static function bootstrapDatabase(PDO $pdo): void {
        // Drop existing to refresh with correct dates and new return_claims table
        $pdo->exec("
            DROP TABLE IF EXISTS return_claims;
            DROP TABLE IF EXISTS notifications;
            DROP TABLE IF EXISTS sales_items;
            DROP TABLE IF EXISTS sales_invoices;
            DROP TABLE IF EXISTS dispatch_challans;
            DROP TABLE IF EXISTS draft_purchase_orders;
            DROP TABLE IF EXISTS inventory;
            DROP TABLE IF EXISTS prescriptions;
            DROP TABLE IF EXISTS batches;
            DROP TABLE IF EXISTS medicine_master;
            DROP TABLE IF EXISTS users;
        ");

        // Table: users
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL,
                role TEXT NOT NULL CHECK(role IN ('manufacturer', 'distributor', 'retailer', 'consumer')),
                phone TEXT NOT NULL,
                license_no TEXT DEFAULT NULL,
                address TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // Table: medicine_master
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS medicine_master (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                brand_name TEXT NOT NULL,
                generic_name TEXT NOT NULL,
                salt_composition TEXT NOT NULL,
                strength_mg INTEGER NOT NULL,
                dosage_form TEXT NOT NULL DEFAULT 'Tablet',
                category TEXT NOT NULL,
                schedule_type TEXT NOT NULL DEFAULT 'General OTC',
                price_mrp REAL NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // Table: batches
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS batches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                medicine_id INTEGER NOT NULL,
                manufacturer_id INTEGER NOT NULL DEFAULT 1,
                batch_number TEXT NOT NULL UNIQUE,
                mfg_date DATE NOT NULL,
                expiry_date DATE NOT NULL,
                initial_quantity INTEGER NOT NULL,
                current_quantity INTEGER NOT NULL,
                mrp_unit REAL NOT NULL,
                distributor_price REAL NOT NULL,
                is_recalled INTEGER NOT NULL DEFAULT 0,
                recall_reason TEXT DEFAULT NULL,
                is_expired INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (medicine_id) REFERENCES medicine_master(id) ON DELETE CASCADE
            );
        ");

        // Table: inventory
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS inventory (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                batch_id INTEGER NOT NULL,
                quantity INTEGER NOT NULL DEFAULT 0,
                warehouse_rack_location TEXT DEFAULT 'Shelf-A1',
                min_threshold INTEGER NOT NULL DEFAULT 10,
                last_updated DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
            );
        ");

        // Table: draft_purchase_orders (Auto-Restock)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS draft_purchase_orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                retailer_id INTEGER NOT NULL,
                distributor_id INTEGER NOT NULL,
                medicine_id INTEGER NOT NULL,
                batch_id INTEGER DEFAULT NULL,
                suggested_quantity INTEGER NOT NULL DEFAULT 150,
                trigger_reason TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'ordered', 'dismissed')),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (retailer_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (distributor_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (medicine_id) REFERENCES medicine_master(id) ON DELETE CASCADE
            );
        ");

        // Table: return_claims (Reverse Logistics: Retailer -> Distributor)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS return_claims (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                claim_number TEXT NOT NULL UNIQUE,
                retailer_id INTEGER NOT NULL,
                distributor_id INTEGER NOT NULL,
                batch_id INTEGER NOT NULL,
                quantity INTEGER NOT NULL,
                reason TEXT NOT NULL,
                credit_amount REAL NOT NULL,
                status TEXT NOT NULL DEFAULT 'dispatched' CHECK(status IN ('dispatched', 'received_quarantine', 'credit_settled')),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (retailer_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (distributor_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
            );
        ");

        // Table: dispatch_challans (GST Challan Distributor -> Retailer)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS dispatch_challans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                challan_number TEXT NOT NULL UNIQUE,
                distributor_id INTEGER NOT NULL,
                retailer_id INTEGER NOT NULL,
                dispatch_date DATE NOT NULL,
                gstin_number TEXT NOT NULL,
                taxable_amount REAL NOT NULL,
                cgst_amount REAL NOT NULL,
                sgst_amount REAL NOT NULL,
                total_amount REAL NOT NULL,
                status TEXT NOT NULL DEFAULT 'delivered' CHECK(status IN ('in_transit', 'delivered', 'cancelled')),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (distributor_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (retailer_id) REFERENCES users(id) ON DELETE CASCADE
            );
        ");

        // Table: sales_invoices (Retailer POS)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sales_invoices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                invoice_number TEXT NOT NULL UNIQUE,
                retailer_id INTEGER NOT NULL,
                consumer_name TEXT NOT NULL,
                consumer_phone TEXT NOT NULL,
                subtotal REAL NOT NULL,
                gst_amount REAL NOT NULL,
                grand_total REAL NOT NULL,
                payment_mode TEXT NOT NULL DEFAULT 'UPI',
                whatsapp_status TEXT NOT NULL DEFAULT 'sent',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (retailer_id) REFERENCES users(id) ON DELETE CASCADE
            );
        ");

        // Table: sales_items
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sales_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                invoice_id INTEGER NOT NULL,
                batch_id INTEGER NOT NULL,
                quantity INTEGER NOT NULL,
                unit_price REAL NOT NULL,
                total_price REAL NOT NULL,
                FOREIGN KEY (invoice_id) REFERENCES sales_invoices(id) ON DELETE CASCADE,
                FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
            );
        ");

        // Table: prescriptions
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS prescriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                consumer_id INTEGER NOT NULL,
                retailer_id INTEGER NOT NULL DEFAULT 3,
                patient_name TEXT NOT NULL,
                doctor_name TEXT NOT NULL,
                file_name TEXT NOT NULL,
                file_data BLOB DEFAULT NULL,
                file_mime TEXT DEFAULT NULL,
                prescribed_medicines TEXT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending', 'verified', 'rejected')),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (consumer_id) REFERENCES users(id) ON DELETE CASCADE
            );
        ");

        // Table: notifications
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                target_role TEXT NOT NULL DEFAULT 'all',
                title TEXT NOT NULL,
                message TEXT NOT NULL,
                type TEXT NOT NULL DEFAULT 'info',
                is_read INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // Seed Users
        $hashedPass = password_hash('password123', PASSWORD_BCRYPT);
        $userStmt = $pdo->prepare("
            INSERT INTO users (id, name, email, password, role, phone, license_no, address) VALUES
            (1, 'Apex LifeSciences Manufacturing Ltd.', 'manufacturer@pharmasys.com', :p, 'manufacturer', '+91 98201 10022', 'MFG/MH/2023/1042', 'MIDC Pharma Tech Zone, Tarapur, Mumbai'),
            (2, 'Nexus Healthcare Logistics Pvt. Ltd.', 'distributor@pharmasys.com', :p, 'distributor', '+91 98202 20033', 'DIST-MH-2022-8819', 'Warehouse Complex B4, Bhiwandi, Thane'),
            (3, 'CareFirst Community Pharmacy', 'retailer@pharmasys.com', :p, 'retailer', '+91 98203 30044', 'DL-20B/21B-MH-49201', 'Shop 4, Sunrise Heights, Bandra West, Mumbai'),
            (4, 'Rahul Sharma (Patient)', 'consumer@pharmasys.com', :p, 'consumer', '+91 98765 43213', NULL, '402 Palm Breeze, Linking Road, Mumbai');
        ");
        $userStmt->execute([':p' => $hashedPass]);

        // Seed Medicine Master
        $pdo->exec("
            INSERT INTO medicine_master (id, brand_name, generic_name, salt_composition, strength_mg, dosage_form, category, schedule_type, price_mrp) VALUES
            (1, 'Crocin Advance', 'Paracetamol Fast Release', 'Paracetamol IP', 500, 'Tablet', 'Analgesic & Antipyretic', 'OTC', 32.50),
            (2, 'Dolo 650', 'Paracetamol High Potency', 'Paracetamol IP', 650, 'Tablet', 'Analgesic & Antipyretic', 'OTC', 34.00),
            (3, 'Calpol 500', 'Paracetamol Paediatric / Standard', 'Paracetamol IP', 500, 'Tablet', 'Analgesic & Antipyretic', 'OTC', 31.00),
            (4, 'Pacimol 650', 'Paracetamol Extended Release', 'Paracetamol IP', 650, 'Tablet', 'Analgesic & Antipyretic', 'OTC', 33.50),
            (5, 'Combiflam', 'Ibuprofen + Paracetamol Combination', 'Ibuprofen + Paracetamol', 400, 'Tablet', 'Analgesic / Anti-inflammatory', 'Schedule H', 44.00),
            (6, 'Brufen 400', 'Ibuprofen Pure Formulation', 'Ibuprofen IP', 400, 'Tablet', 'NSAID / Anti-inflammatory', 'Schedule H', 28.50),
            (7, 'Augmentin 625 Duo', 'Amoxicillin & Potassium Clavulanate', 'Amoxicillin + Clavulanic Acid', 625, 'Tablet', 'Antibacterial / Penicillin', 'Schedule H1', 204.50),
            (8, 'Moxikind-CV 625', 'Amoxicillin & Clavulanic Acid Generic', 'Amoxicillin + Clavulanic Acid', 625, 'Tablet', 'Antibacterial / Penicillin', 'Schedule H1', 178.00),
            (9, 'Clavam 625', 'Amox-Clav Formulation', 'Amoxicillin + Clavulanic Acid', 625, 'Tablet', 'Antibacterial / Penicillin', 'Schedule H1', 192.00),
            (10, 'Azithral 500', 'Azithromycin Monohydrate', 'Azithromycin IP', 500, 'Tablet', 'Macrolide Antibiotic', 'Schedule H1', 132.00),
            (11, 'Azee 500', 'Azithromycin Tablets IP', 'Azithromycin IP', 500, 'Tablet', 'Macrolide Antibiotic', 'Schedule H1', 125.00),
            (12, 'Zithrox 250', 'Azithromycin Paediatric', 'Azithromycin IP', 250, 'Tablet', 'Macrolide Antibiotic', 'Schedule H1', 75.00),
            (13, 'Pan-D', 'Pantoprazole Gastro-resistant & Domperidone', 'Pantoprazole + Domperidone', 40, 'Capsule', 'Gastroprokinetic / PPI', 'Schedule H', 145.00),
            (14, 'Pantocid DSR', 'Pantoprazole Sustained Release + Domperidone', 'Pantoprazole + Domperidone', 40, 'Capsule', 'Gastroprokinetic / PPI', 'Schedule H', 152.00),
            (15, 'Omez 20', 'Omeprazole Delayed Release', 'Omeprazole IP', 20, 'Capsule', 'Proton Pump Inhibitor', 'OTC', 56.00),
            (16, 'Razo 20', 'Rabeprazole Sodium Gastro-resistant', 'Rabeprazole IP', 20, 'Tablet', 'Proton Pump Inhibitor', 'Schedule H', 110.00),
            (17, 'Allegra 120', 'Fexofenadine Hydrochloride', 'Fexofenadine HCl', 120, 'Tablet', 'Non-sedating Antihistamine', 'OTC', 188.00),
            (18, 'Allegra 180', 'Fexofenadine High Strength', 'Fexofenadine HCl', 180, 'Tablet', 'Non-sedating Antihistamine', 'OTC', 240.00),
            (19, 'Fexova 120', 'Fexofenadine Bioequivalent', 'Fexofenadine HCl', 120, 'Tablet', 'Non-sedating Antihistamine', 'OTC', 165.00),
            (20, 'Cetcip 10', 'Cetirizine Hydrochloride', 'Cetirizine HCl', 10, 'Tablet', 'Antiallergic', 'OTC', 22.00),
            (21, 'Levocet 5', 'Levocetirizine Dihydrochloride', 'Levocetirizine', 5, 'Tablet', 'Antiallergic', 'Schedule H', 48.00),
            (22, 'Telma 40', 'Telmisartan Tablets IP', 'Telmisartan IP', 40, 'Tablet', 'Antihypertensive', 'Schedule H', 128.00),
            (23, 'Telmikind 40', 'Telmisartan Generic', 'Telmisartan IP', 40, 'Tablet', 'Antihypertensive', 'Schedule H', 85.00),
            (24, 'Glycomet 500', 'Metformin Hydrochloride Prolonged Release', 'Metformin HCl', 500, 'Tablet', 'Oral Antidiabetic', 'Schedule H', 42.00),
            (25, 'Glycomet SR 1000', 'Metformin High Dose Extended Release', 'Metformin HCl', 1000, 'Tablet', 'Oral Antidiabetic', 'Schedule H', 68.00);
        ");

        // Seed Batches WITH DYNAMIC RELATIVE DATES
        // 90% are FRESH (expiring in 2028-2029).
        // Batch 6 (Calpol 500): Flagged as RECALLED by QA assay.
        // Batch 13 (Pan-D): Explicitly EXPIRED (-45 days) to test the Return Channel and POS lockout!
        // Batch 4 (Dolo 650): NEAR EXPIRY (+45 days) to test early returns!
        $dNow = date('Y-m-d');
        $dExpSafe1 = date('Y-m-d', strtotime('+2 years'));
        $dExpSafe2 = date('Y-m-d', strtotime('+30 months'));
        $dExpSafe3 = date('Y-m-d', strtotime('+18 months'));
        $dExpNear  = date('Y-m-d', strtotime('+45 days'));
        $dExpPast  = date('Y-m-d', strtotime('-45 days'));
        $dMfg      = date('Y-m-d', strtotime('-3 months'));

        $batchStmt = $pdo->prepare("
            INSERT INTO batches (id, medicine_id, batch_number, mfg_date, expiry_date, initial_quantity, current_quantity, mrp_unit, distributor_price, is_recalled, recall_reason, is_expired) VALUES
            (1, 1, 'BATCH-CR-2026A', :mfg, :exp1, 10000, 3200, 32.50, 24.00, 0, NULL, 0),
            (2, 1, 'BATCH-CR-2026B', :mfg, :exp2, 8000, 450, 32.50, 24.00, 0, NULL, 0),
            (3, 2, 'BATCH-DL-9901X', :mfg, :exp1, 12000, 410, 34.00, 25.50, 0, NULL, 0),
            (4, 2, 'BATCH-DL-NEAR', :mfg, :expNear, 5000, 80, 34.00, 25.50, 0, NULL, 0),
            (5, 3, 'BATCH-CL-SAFE1', :mfg, :exp2, 6000, 1800, 31.00, 22.00, 0, NULL, 0),
            (6, 3, 'BATCH-CL-BAD01', :mfg, :exp3, 5000, 200, 31.00, 22.00, 1, 'Chemical impurity exceeded permissible threshold in QA stability test', 0),
            (7, 7, 'BATCH-AUG-8821', :mfg, :exp1, 4000, 1500, 204.50, 160.00, 0, NULL, 0),
            (8, 8, 'BATCH-MOX-3042', :mfg, :exp2, 5000, 2200, 178.00, 138.00, 0, NULL, 0),
            (9, 9, 'BATCH-CLV-5011', :mfg, :exp3, 3500, 1100, 192.00, 148.00, 0, NULL, 0),
            (10, 10, 'BATCH-AZT-1011', :mfg, :exp1, 7000, 3100, 132.00, 98.00, 0, NULL, 0),
            (11, 11, 'BATCH-AZE-9021', :mfg, :exp2, 6000, 2800, 125.00, 92.00, 0, NULL, 0),
            (12, 13, 'BATCH-PAND-FRESH', :mfg, :exp1, 5000, 1950, 145.00, 110.00, 0, NULL, 0),
            (13, 13, 'BATCH-PAND-EXPIRED', :mfg, :expPast, 3000, 150, 145.00, 110.00, 0, NULL, 1),
            (14, 17, 'BATCH-ALG-4410', :mfg, :exp2, 4500, 1400, 188.00, 142.00, 0, NULL, 0),
            (15, 18, 'BATCH-ALG-8810', :mfg, :exp3, 3000, 950, 240.00, 185.00, 0, NULL, 0),
            (16, 20, 'BATCH-CET-7712', :mfg, :exp1, 8000, 4200, 22.00, 15.00, 0, NULL, 0),
            (17, 22, 'BATCH-TEL-3319', :mfg, :exp2, 5500, 2100, 128.00, 96.00, 0, NULL, 0),
            (18, 24, 'BATCH-GLY-6620', :mfg, :exp1, 9000, 3800, 42.00, 29.00, 0, NULL, 0),
            (19, 25, 'BATCH-GLY-SR100', :mfg, :exp3, 6000, 2400, 68.00, 48.00, 0, NULL, 0);
        ");
        $batchStmt->execute([
            ':mfg' => $dMfg,
            ':exp1' => $dExpSafe1,
            ':exp2' => $dExpSafe2,
            ':exp3' => $dExpSafe3,
            ':expNear' => $dExpNear,
            ':expPast' => $dExpPast
        ]);

        // Seed Retailer Inventory (User 3 = CareFirst)
        $pdo->exec("
            INSERT INTO inventory (user_id, batch_id, quantity, warehouse_rack_location, min_threshold) VALUES
            (3, 1, 85, 'Shelf-A-Rack01', 30),     -- Crocin Advance (Fresh, 85 units)
            (3, 3, 8, 'Shelf-A-Rack02', 30),      -- Dolo 650 (CRITICAL LOW: 8 units -> Draft Cart)
            (3, 6, 40, 'Quarantine-Zone-Q1', 20), -- Calpol 500 (RECALLED Batch -> POS Blocks)
            (3, 7, 35, 'Shelf-B-Rack01', 25),     -- Augmentin 625 (Fresh, 35 units)
            (3, 10, 50, 'Shelf-B-Rack03', 25),    -- Azithral 500 (Fresh, 50 units)
            (3, 12, 60, 'Shelf-C-Rack01', 30),    -- Pan-D Fresh (Fresh, 60 units)
            (3, 13, 22, 'Expiry-Hold-Bin', 20),   -- Pan-D Expired (Date Expired -> Return Candidate)
            (3, 14, 45, 'Shelf-D-Rack01', 20),    -- Allegra 120 (Fresh, 45 units)
            (3, 17, 40, 'Shelf-E-Rack01', 25),    -- Telma 40 (Fresh, 40 units)
            (3, 18, 9, 'Shelf-E-Rack02', 30);     -- Glycomet 500 (CRITICAL LOW: 9 units -> Draft Cart)
        ");

        // Seed Distributor Inventory (User 2 = Nexus)
        $pdo->exec("
            INSERT INTO inventory (user_id, batch_id, quantity, warehouse_rack_location, min_threshold) VALUES
            (2, 1, 3200, 'Warehouse-Ambient-Rack-A04', 100),
            (2, 3, 410, 'Warehouse-Ambient-Rack-B12', 100),
            (2, 7, 1500, 'ColdChain-2C-8C-Zone01', 50),
            (2, 8, 2200, 'ColdChain-2C-8C-Zone02', 50),
            (2, 10, 3100, 'Warehouse-Ambient-Rack-C08', 100),
            (2, 12, 1950, 'Warehouse-Ambient-Rack-D01', 100),
            (2, 14, 1400, 'Warehouse-Ambient-Rack-D15', 50),
            (2, 17, 2100, 'Warehouse-Ambient-Rack-E03', 50);
        ");

        // Seed Return Claims (Historical settled record so active batches 6 and 13 are ready for testing)
        $pdo->exec("
            INSERT INTO return_claims (claim_number, retailer_id, distributor_id, batch_id, quantity, reason, credit_amount, status) VALUES
            ('RET-MH-2026-0012', 3, 2, 19, 10, 'Archived Settled Return (Shelf-life completion)', 480.00, 'credit_settled');
        ");

        // Seed Draft Restock Orders
        $pdo->exec("
            INSERT INTO draft_purchase_orders (retailer_id, distributor_id, medicine_id, batch_id, suggested_quantity, trigger_reason, status) VALUES
            (3, 2, 2, 3, 150, 'Current stock (8 units) breached safe safety threshold (30 units)', 'draft'),
            (3, 2, 24, 18, 120, 'Current stock (9 units) breached safe safety threshold (30 units)', 'draft');
        ");

        // Seed Dispatch Challans
        $pdo->exec("
            INSERT INTO dispatch_challans (challan_number, distributor_id, retailer_id, dispatch_date, gstin_number, taxable_amount, cgst_amount, sgst_amount, total_amount, status) VALUES
            ('CHALLAN-MH-2026-1049', 2, 3, '{$dNow}', '27AABCU9603R1ZM', 4800.00, 288.00, 288.00, 5376.00, 'delivered'),
            ('CHALLAN-MH-2026-1188', 2, 3, '{$dNow}', '27AABCU9603R1ZM', 7200.00, 432.00, 432.00, 8064.00, 'in_transit');
        ");

        // Seed Past Sales Invoices
        $pdo->exec("
            INSERT INTO sales_invoices (invoice_number, retailer_id, consumer_name, consumer_phone, subtotal, gst_amount, grand_total, payment_mode, whatsapp_status) VALUES
            ('INV-2026-0091', 3, 'Rahul Sharma', '9876543213', 130.00, 15.60, 145.60, 'UPI', 'sent');
        ");

        // Seed Notifications
        $pdo->exec("
            INSERT INTO notifications (target_role, title, message, type) VALUES
            ('all', '🚨 Batch Recall Order: Calpol 500', 'Apex LifeSciences has blacklisted Batch BATCH-CL-BAD01 due to chemical impurity assay variation. POS lock active.', 'danger'),
            ('retailer', '⚠️ Low Stock Auto-Restock Triggered', 'Dolo 650 and Glycomet 500 breached minimum threshold. Draft Purchase Orders queued for review.', 'warning');
        ");

        // Performance & Low-Latency Query Indexes
        $pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_rx_ret_status ON prescriptions(retailer_id, status);
            CREATE INDEX IF NOT EXISTS idx_med_brand ON medicine_master(brand_name);
            CREATE INDEX IF NOT EXISTS idx_med_cat ON medicine_master(category);
            CREATE INDEX IF NOT EXISTS idx_med_salt ON medicine_master(salt_composition);
            CREATE INDEX IF NOT EXISTS idx_batches_expiry ON batches(expiry_date, is_expired);
            CREATE INDEX IF NOT EXISTS idx_batches_recalled ON batches(is_recalled);
            CREATE INDEX IF NOT EXISTS idx_batches_mfg ON batches(manufacturer_id);
            CREATE INDEX IF NOT EXISTS idx_inv_user_batch ON inventory(user_id, batch_id);
            CREATE INDEX IF NOT EXISTS idx_ret_claims_ret ON return_claims(retailer_id, status);
            CREATE INDEX IF NOT EXISTS idx_ret_claims_dist ON return_claims(distributor_id, status);
            CREATE INDEX IF NOT EXISTS idx_dpo_ret ON draft_purchase_orders(retailer_id, status);
            CREATE INDEX IF NOT EXISTS idx_chal_dist ON dispatch_challans(distributor_id, status);
            CREATE INDEX IF NOT EXISTS idx_chal_ret ON dispatch_challans(retailer_id, status);
        ");
    }
}
