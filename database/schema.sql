-- =====================================================================
-- PharmaSys Hub - Enterprise Relational Database Schema & Seed Data
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `pharmasys_hub` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `pharmasys_hub`;

SET GLOBAL event_scheduler = ON;

DROP TABLE IF EXISTS `return_claims`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `sales_items`;
DROP TABLE IF EXISTS `sales_invoices`;
DROP TABLE IF EXISTS `dispatch_challans`;
DROP TABLE IF EXISTS `draft_purchase_orders`;
DROP TABLE IF EXISTS `inventory`;
DROP TABLE IF EXISTS `prescriptions`;
DROP TABLE IF EXISTS `batches`;
DROP TABLE IF EXISTS `medicine_master`;
DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('manufacturer', 'distributor', 'retailer', 'consumer') NOT NULL,
  `phone` VARCHAR(25) NOT NULL,
  `license_no` VARCHAR(80) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE `medicine_master` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `brand_name` VARCHAR(150) NOT NULL,
  `generic_name` VARCHAR(150) NOT NULL,
  `salt_composition` VARCHAR(255) NOT NULL,
  `strength_mg` INT NOT NULL,
  `dosage_form` ENUM('Tablet', 'Capsule', 'Syrup', 'Injection', 'Ointment') NOT NULL DEFAULT 'Tablet',
  `category` VARCHAR(100) NOT NULL,
  `schedule_type` VARCHAR(50) NOT NULL DEFAULT 'General OTC',
  `price_mrp` DECIMAL(10,2) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE `batches` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `medicine_id` INT NOT NULL,
  `manufacturer_id` INT NOT NULL DEFAULT 1,
  `batch_number` VARCHAR(50) NOT NULL UNIQUE,
  `mfg_date` DATE NOT NULL,
  `expiry_date` DATE NOT NULL,
  `initial_quantity` INT NOT NULL,
  `current_quantity` INT NOT NULL,
  `mrp_unit` DECIMAL(10,2) NOT NULL,
  `distributor_price` DECIMAL(10,2) NOT NULL,
  `is_recalled` TINYINT(1) NOT NULL DEFAULT 0,
  `recall_reason` VARCHAR(255) DEFAULT NULL,
  `is_expired` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`medicine_id`) REFERENCES `medicine_master`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `inventory` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `batch_id` INT NOT NULL,
  `quantity` INT NOT NULL DEFAULT 0,
  `warehouse_rack_location` VARCHAR(80) DEFAULT 'Shelf-A1',
  `min_threshold` INT NOT NULL DEFAULT 30,
  `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`batch_id`) REFERENCES `batches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `draft_purchase_orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `retailer_id` INT NOT NULL,
  `distributor_id` INT NOT NULL,
  `medicine_id` INT NOT NULL,
  `batch_id` INT DEFAULT NULL,
  `suggested_quantity` INT NOT NULL DEFAULT 150,
  `trigger_reason` VARCHAR(255) NOT NULL,
  `status` ENUM('draft', 'ordered', 'dismissed') NOT NULL DEFAULT 'draft',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`retailer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`distributor_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`medicine_id`) REFERENCES `medicine_master`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `return_claims` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `claim_number` VARCHAR(50) NOT NULL UNIQUE,
  `retailer_id` INT NOT NULL,
  `distributor_id` INT NOT NULL,
  `batch_id` INT NOT NULL,
  `quantity` INT NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `credit_amount` DECIMAL(10,2) NOT NULL,
  `status` ENUM('dispatched', 'received_quarantine', 'credit_settled') NOT NULL DEFAULT 'dispatched',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`retailer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`distributor_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`batch_id`) REFERENCES `batches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `dispatch_challans` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `challan_number` VARCHAR(50) NOT NULL UNIQUE,
  `distributor_id` INT NOT NULL,
  `retailer_id` INT NOT NULL,
  `dispatch_date` DATE NOT NULL,
  `gstin_number` VARCHAR(30) NOT NULL,
  `taxable_amount` DECIMAL(10,2) NOT NULL,
  `cgst_amount` DECIMAL(10,2) NOT NULL,
  `sgst_amount` DECIMAL(10,2) NOT NULL,
  `total_amount` DECIMAL(10,2) NOT NULL,
  `status` ENUM('in_transit', 'delivered', 'cancelled') NOT NULL DEFAULT 'in_transit',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`distributor_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`retailer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `sales_invoices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
  `retailer_id` INT NOT NULL,
  `consumer_name` VARCHAR(150) NOT NULL,
  `consumer_phone` VARCHAR(25) NOT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL,
  `gst_amount` DECIMAL(10,2) NOT NULL,
  `grand_total` DECIMAL(10,2) NOT NULL,
  `payment_mode` VARCHAR(50) NOT NULL DEFAULT 'UPI',
  `whatsapp_status` VARCHAR(50) NOT NULL DEFAULT 'sent',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`retailer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `sales_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_id` INT NOT NULL,
  `batch_id` INT NOT NULL,
  `quantity` INT NOT NULL,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `total_price` DECIMAL(10,2) NOT NULL,
  FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`batch_id`) REFERENCES `batches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `prescriptions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `consumer_id` INT NOT NULL,
  `retailer_id` INT NOT NULL DEFAULT 3,
  `patient_name` VARCHAR(150) NOT NULL,
  `doctor_name` VARCHAR(150) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `prescribed_medicines` TEXT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`consumer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `target_role` VARCHAR(50) NOT NULL DEFAULT 'all',
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `type` VARCHAR(50) NOT NULL DEFAULT 'info',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- MySQL Expiry Automation Event
DROP EVENT IF EXISTS `daily_batch_expiry_check`;
DELIMITER $$
CREATE EVENT `daily_batch_expiry_check`
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
BEGIN
  UPDATE `batches` SET `is_expired` = 1 WHERE `expiry_date` < CURDATE() AND `is_expired` = 0;
END $$
DELIMITER ;

-- Seed Users (Password for all: password123)
INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `phone`, `license_no`, `address`) VALUES
(1, 'Apex LifeSciences Manufacturing Ltd.', 'manufacturer@pharmasys.com', '$2y$10$MSdqMCagLkMLZjp.EC9JseS0tECe5hz9ZYVZ7BdeIq/hYATps.CcW', 'manufacturer', '+91 98201 10022', 'MFG/MH/2023/1042', 'MIDC Pharma Tech Zone, Tarapur, Mumbai'),
(2, 'Nexus Healthcare Logistics Pvt. Ltd.', 'distributor@pharmasys.com', '$2y$10$MSdqMCagLkMLZjp.EC9JseS0tECe5hz9ZYVZ7BdeIq/hYATps.CcW', 'distributor', '+91 98202 20033', 'DIST-MH-2022-8819', 'Warehouse Complex B4, Bhiwandi, Thane'),
(3, 'CareFirst Community Pharmacy', 'retailer@pharmasys.com', '$2y$10$MSdqMCagLkMLZjp.EC9JseS0tECe5hz9ZYVZ7BdeIq/hYATps.CcW', 'retailer', '+91 98203 30044', 'DL-20B/21B-MH-49201', 'Shop 4, Sunrise Heights, Bandra West, Mumbai'),
(4, 'Rahul Sharma (Patient)', 'consumer@pharmasys.com', '$2y$10$MSdqMCagLkMLZjp.EC9JseS0tECe5hz9ZYVZ7BdeIq/hYATps.CcW', 'consumer', '+91 98765 43213', NULL, '402 Palm Breeze, Linking Road, Mumbai');

-- Seed Large Medicine Catalog
INSERT INTO `medicine_master` (`id`, `brand_name`, `generic_name`, `salt_composition`, `strength_mg`, `dosage_form`, `category`, `schedule_type`, `price_mrp`) VALUES
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

-- Seed Batches (Fresh 2028-2029 dates, with 1 recalled and 1 expired for testing)
INSERT INTO `batches` (`id`, `medicine_id`, `batch_number`, `mfg_date`, `expiry_date`, `initial_quantity`, `current_quantity`, `mrp_unit`, `distributor_price`, `is_recalled`, `recall_reason`, `is_expired`) VALUES
(1, 1, 'BATCH-CR-2026A', CURDATE() - INTERVAL 90 DAY, CURDATE() + INTERVAL 2 YEAR, 10000, 3200, 32.50, 24.00, 0, NULL, 0),
(2, 1, 'BATCH-CR-2026B', CURDATE() - INTERVAL 90 DAY, CURDATE() + INTERVAL 30 MONTH, 8000, 450, 32.50, 24.00, 0, NULL, 0),
(3, 2, 'BATCH-DL-9901X', CURDATE() - INTERVAL 90 DAY, CURDATE() + INTERVAL 2 YEAR, 12000, 410, 34.00, 25.50, 0, NULL, 0),
(4, 2, 'BATCH-DL-NEAR', CURDATE() - INTERVAL 90 DAY, CURDATE() + INTERVAL 45 DAY, 5000, 80, 34.00, 25.50, 0, NULL, 0),
(5, 3, 'BATCH-CL-SAFE1', CURDATE() - INTERVAL 90 DAY, CURDATE() + INTERVAL 30 MONTH, 6000, 1800, 31.00, 22.00, 0, NULL, 0),
(6, 3, 'BATCH-CL-BAD01', CURDATE() - INTERVAL 90 DAY, CURDATE() + INTERVAL 18 MONTH, 5000, 200, 31.00, 22.00, 1, 'Chemical impurity exceeded permissible threshold in QA stability test', 0),
(7, 7, 'BATCH-AUG-8821', CURDATE() - INTERVAL 90 DAY, CURDATE() + INTERVAL 2 YEAR, 4000, 1500, 204.50, 160.00, 0, NULL, 0),
(8, 8, 'BATCH-MOX-3042', CURDATE() - INTERVAL 90 DAY, CURDATE() + INTERVAL 30 MONTH, 5000, 2200, 178.00, 138.00, 0, NULL, 0),
(9, 9, 'BATCH-CLV-5011', CURDATE() - INTERVAL 90 DAY, CURDATE() + INTERVAL 18 MONTH, 3500, 1100, 192.00, 148.00, 0, NULL, 0),
(10, 10, 'BATCH-AZT-1011', CURDATE() - INTERVAL 90 DAY, CURDATE() + INTERVAL 2 YEAR, 7000, 3100, 132.00, 98.00, 0, NULL, 0),
(11, 11, 'BATCH-AZE-9021', CURDATE() - INTERVAL 90 DAY, CURDATE() + INTERVAL 30 MONTH, 6000, 2800, 125.00, 92.00, 0, NULL, 0),
(12, 13, 'BATCH-PAND-FRESH', CURDATE() - INTERVAL 90 DAY, CURDATE() + INTERVAL 2 YEAR, 5000, 1950, 145.00, 110.00, 0, NULL, 0),
(13, 13, 'BATCH-PAND-EXPIRED', CURDATE() - INTERVAL 2 YEAR, CURDATE() - INTERVAL 45 DAY, 3000, 150, 145.00, 110.00, 0, NULL, 1),
(14, 17, 'BATCH-ALG-4410', CURDATE() - INTERVAL 90 DAY, CURDATE() + INTERVAL 30 MONTH, 4500, 1400, 188.00, 142.00, 0, NULL, 0),
(15, 18, 'BATCH-ALG-8810', CURDATE() - INTERVAL 90 DAY, CURDATE() + INTERVAL 18 MONTH, 3000, 950, 240.00, 185.00, 0, NULL, 0),
(16, 20, 'BATCH-CET-7712', CURDATE() - INTERVAL 90 DAY, CURDATE() + INTERVAL 2 YEAR, 8000, 4200, 22.00, 15.00, 0, NULL, 0),
(17, 22, 'BATCH-TEL-3319', CURDATE() - INTERVAL 90 DAY, CURDATE() + INTERVAL 30 MONTH, 5500, 2100, 128.00, 96.00, 0, NULL, 0),
(18, 24, 'BATCH-GLY-6620', CURDATE() - INTERVAL 90 DAY, CURDATE() + INTERVAL 2 YEAR, 9000, 3800, 42.00, 29.00, 0, NULL, 0),
(19, 25, 'BATCH-GLY-SR100', CURDATE() - INTERVAL 90 DAY, CURDATE() + INTERVAL 18 MONTH, 6000, 2400, 68.00, 48.00, 0, NULL, 0);

-- Seed Retailer Inventory (CareFirst Pharmacy)
INSERT INTO `inventory` (`user_id`, `batch_id`, `quantity`, `warehouse_rack_location`, `min_threshold`) VALUES
(3, 1, 85, 'Shelf-A-Rack01', 30),
(3, 3, 8, 'Shelf-A-Rack02', 30),
(3, 6, 40, 'Quarantine-Zone-Q1', 20),
(3, 7, 35, 'Shelf-B-Rack01', 25),
(3, 10, 50, 'Shelf-B-Rack03', 25),
(3, 12, 60, 'Shelf-C-Rack01', 30),
(3, 13, 22, 'Expiry-Hold-Bin', 20),
(3, 14, 45, 'Shelf-D-Rack01', 20),
(3, 17, 40, 'Shelf-E-Rack01', 25),
(3, 18, 9, 'Shelf-E-Rack02', 30);

-- Seed Distributor Inventory
INSERT INTO `inventory` (`user_id`, `batch_id`, `quantity`, `warehouse_rack_location`, `min_threshold`) VALUES
(2, 1, 3200, 'Warehouse-Ambient-Rack-A04', 100),
(2, 3, 410, 'Warehouse-Ambient-Rack-B12', 100),
(2, 7, 1500, 'ColdChain-2C-8C-Zone01', 50),
(2, 8, 2200, 'ColdChain-2C-8C-Zone02', 50),
(2, 10, 3100, 'Warehouse-Ambient-Rack-C08', 100),
(2, 12, 1950, 'Warehouse-Ambient-Rack-D01', 100),
(2, 14, 1400, 'Warehouse-Ambient-Rack-D15', 50),
(2, 17, 2100, 'Warehouse-Ambient-Rack-E03', 50);

-- Seed Return Claims (Historical settled record so active batches 6 and 13 are ready for testing)
INSERT INTO `return_claims` (`claim_number`, `retailer_id`, `distributor_id`, `batch_id`, `quantity`, `reason`, `credit_amount`, `status`) VALUES
('RET-MH-2026-0012', 3, 2, 19, 10, 'Archived Settled Return (Shelf-life completion)', 480.00, 'credit_settled');

-- Seed Draft Restock Orders
INSERT INTO `draft_purchase_orders` (`retailer_id`, `distributor_id`, `medicine_id`, `batch_id`, `suggested_quantity`, `trigger_reason`, `status`) VALUES
(3, 2, 2, 3, 150, 'Current stock (8 units) breached safe safety threshold (30 units)', 'draft'),
(3, 2, 24, 18, 120, 'Current stock (9 units) breached safe safety threshold (30 units)', 'draft');

-- Seed Dispatch Challans
INSERT INTO `dispatch_challans` (`challan_number`, `distributor_id`, `retailer_id`, `dispatch_date`, `gstin_number`, `taxable_amount`, `cgst_amount`, `sgst_amount`, `total_amount`, `status`) VALUES
('CHALLAN-MH-2026-1049', 2, 3, CURDATE() - INTERVAL 10 DAY, '27AABCU9603R1ZM', 4800.00, 288.00, 288.00, 5376.00, 'delivered'),
('CHALLAN-MH-2026-1188', 2, 3, CURDATE() - INTERVAL 2 DAY, '27AABCU9603R1ZM', 7200.00, 432.00, 432.00, 8064.00, 'in_transit');

-- Seed Past Sales Invoices
INSERT INTO `sales_invoices` (`invoice_number`, `retailer_id`, `consumer_name`, `consumer_phone`, `subtotal`, `gst_amount`, `grand_total`, `payment_mode`, `whatsapp_status`) VALUES
('INV-2026-0091', 3, 'Rahul Sharma', '9876543213', 130.00, 15.60, 145.60, 'UPI', 'sent');

-- Seed Notifications
INSERT INTO `notifications` (`target_role`, `title`, `message`, `type`) VALUES
('all', '🚨 Batch Recall Order: Calpol 500', 'Apex LifeSciences has blacklisted Batch BATCH-CL-BAD01 due to chemical impurity assay variation. POS lock active.', 'danger'),
('retailer', '⚠️ Low Stock Auto-Restock Triggered', 'Dolo 650 and Glycomet 500 breached minimum threshold. Draft Purchase Orders queued for review.', 'warning');

-- =====================================================================
-- Performance & Low-Latency Query Indexes
-- =====================================================================
CREATE INDEX idx_rx_ret_status ON prescriptions(retailer_id, status);
CREATE INDEX idx_med_brand ON medicine_master(brand_name);
CREATE INDEX idx_med_cat ON medicine_master(category);
CREATE INDEX idx_med_salt ON medicine_master(salt_composition);
CREATE INDEX idx_batches_expiry ON batches(expiry_date, is_expired);
CREATE INDEX idx_batches_recalled ON batches(is_recalled);
CREATE INDEX idx_batches_mfg ON batches(manufacturer_id);
CREATE INDEX idx_inv_user_batch ON inventory(user_id, batch_id);
CREATE INDEX idx_ret_claims_ret ON return_claims(retailer_id, status);
CREATE INDEX idx_ret_claims_dist ON return_claims(distributor_id, status);
CREATE INDEX idx_dpo_ret ON draft_purchase_orders(retailer_id, status);
CREATE INDEX idx_chal_dist ON dispatch_challans(distributor_id, status);
CREATE INDEX idx_chal_ret ON dispatch_challans(retailer_id, status);

