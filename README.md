# PharmaSys Hub 💊
### Enterprise Pharmaceutical Supply Chain Operating System

---

## 1. Major Architectural Updates in v2.0

1. **Dual Live SQL Database Engine (Zero-Config Persistent Storage):**
   - Automatically connects to **Live SQLite database** (`database/pharmasys_live.db`) on disk, ensuring 100% persistent SQL queries, relational joins, transactions, and mutations right out of the box without needing external software.
   - Also fully compatible with **XAMPP MySQL**; if MySQL is active, it automatically connects to `pharmasys_hub` on port `3306`.
2. **Strict Role-Based Authentication & Guard:**
   - **No cross-dashboard visibility:** Unauthenticated visitors cannot access any dashboard. Direct URL requests automatically redirect to `login.php`.
   - **Role authorization check (`require_role`):** If a Retailer attempts to access the Manufacturer or Distributor control room, an HTTP 403 Forbidden Access Denied page blocks them.
3. **Dynamic Calendar Expiry Matching (No Hardcoded Expired Flags):**
   - Batches store real-world manufacturing and expiry dates.
   - The system dynamically compares `expiry_date < CURRENT_DATE()` on every query, automatically labeling and strictly locking expired medicines from retail dispensing.
4. **Expanded 25+ Medicine Database:**
   - Spans 6 clinical categories: Analgesics, Antibiotics, Gastrointestinal/PPIs, Antihistamines, Cardiovascular, and Antidiabetics.
   - Multiple brands sharing active generic salt compositions (e.g. *Paracetamol IP*, *Amoxicillin + Clavulanic Acid*, *Fexofenadine HCl*, *Pantoprazole + Domperidone*) with varying milligram strengths.
5. **Dedicated Modular Pages (No Page Clutter):**
   - Features are organized into clean, dedicated, purpose-built sub-pages with professional sub-navigation.
6. **Enterprise HealthTech Design System:**
   - Replaced generic AI glassmorphic gradients and emojis with crisp, high-definition SVG vector icons, clean typography, tabular data formatting, and enterprise color coding.

---

## 2. Dedicated Role Portals & Modular Pages

### 🏪 Retail Pharmacy (CareFirst Pharmacy)
*   **[Smart POS Billing Terminal](retailer_pos.php):** Real-time medicine lookup, cart management, 12% GST calculation, customer 10-digit mobile capture, and **STRICT LOCKOUTS** preventing sales of recalled or expired batches.
*   **[Shelf Inventory & Batches](retailer_inventory.php):** Audit pharmacy stock, view assigned shelf racks (`Shelf-A-Rack01`), and edit bin locations.
*   **[Auto-Restock Draft Cart](retailer_restock.php):** Review auto-queued restock items where stock dropped below safety limits. Adjust quantities, dismiss items, and dispatch official POs to distributors.
*   **[Sales Register & WhatsApp Logs](retailer_sales.php):** Track all past retail invoices and Meta WhatsApp delivery statuses.

### 🏭 Manufacturer (Apex LifeSciences Ltd.)
*   **[Production Overview](manufacturer.php):** High-level KPIs, batch outputs, and recent releases.
*   **[Batch Minting & Physical Supply Registry](manufacturer_batches.php):** Issue and release new pharmaceutical batches with batch codes, QA assays, and expiry dates.
*   **[Smart Recall Command Center](manufacturer_recalls.php):** Emergency recall controls: Flagging `is_recalled = 1` broadcasts an alert and instantly blocks the batch from retail POS sales.
*   **[Distribution Telemetry](manufacturer_analytics.php):** Chart.js production vs. distributor fulfillment analytics.

### 🚚 Distributor (Nexus Healthcare Logistics)
*   **[Logistics Dashboard](distributor.php):** Bulk warehousing inventory, temperature zoning, and dispatch overviews.
*   **[Warehouse Spatial Rack Mapping](distributor_inventory.php):** Manage stock across Ambient and Cold-Chain (2°C - 8°C) vaults with instant rack reassignment.
*   **[GST Dispatch Challans](distributor_challans.php):** Issue legally compliant B2B tax delivery challans to pharmacies with automated 6% CGST + 6% SGST computation.
*   **[Near-Expiry Returns](distributor_returns.php):** Monitor batches nearing expiration (< 90 days) for reverse logistics and credit claims.

### 👤 Patient / Consumer (Rahul Sharma)
*   **[Composition Substitute Finder](consumer.php):** Search brand names or active salts to discover generic bioequivalents with highlighted milligram differences (`+150mg Higher Potency`, `Exact Match`) and per-strip cost savings.
*   **[Prescription Upload & Tracker](consumer_prescriptions.php):** Upload doctor prescriptions for Schedule H pharmacist verification.
*   **[Digital WhatsApp E-Bills](consumer_bills.php):** Review digital tax invoices delivered to your WhatsApp mobile (+91 98765 43213).

---

## 3. Demo Credentials

| Role | Registered Email | Password | Default Home Portal |
|---|---|---|---|
| **Retailer** | `retailer@pharmasys.com` | `password123` | `retailer_pos.php` |
| **Manufacturer** | `manufacturer@pharmasys.com` | `password123` | `manufacturer.php` |
| **Distributor** | `distributor@pharmasys.com` | `password123` | `distributor.php` |
| **Consumer** | `consumer@pharmasys.com` | `password123` | `consumer.php` |

*(Quick 1-click "Auto-Fill" demo chips are available directly on the login screen for testing).*

---

## 4. Local Execution

*   **Local Server Address:** [http://127.0.0.1:8000/login.php](http://127.0.0.1:8000/login.php)
*   **Database Management:** Locked to authorized CLI administration only. All tables, medicines, and accounts are pre-seeded and secured.
