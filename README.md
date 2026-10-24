# Asterisko Artisan Coffee - Inventory Management System

An offline-first, role-guarded web inventory management system developed for Asterisko Artisan Coffee. Built with PHP, MySQL, and Tailwind CSS, the platform supports real-time stock deductions, physical shift variance verification, client-side OCR delivery intake, FIFO waste logging, purchase order generation, and financial reporting.

---

## Table of Contents

- [Key Features](#key-features)
- [System Architecture](#system-architecture)
- [Getting Started](#getting-started)
  - [Prerequisites](#prerequisites)
  - [Environment Setup](#environment-setup)
  - [Running the Local Server](#running-the-local-server)
- [Cloud Deployment (Render)](#cloud-deployment-render)
  - [Deploying with Docker](#deploying-with-docker)
  - [Render Environment Variables](#render-environment-variables)
- [Cloud Database & Troubleshooting](#cloud-database--troubleshooting)
  - [Aiven MySQL Configuration](#aiven-mysql-configuration)
  - [Troubleshooting Connection Errors](#troubleshooting-connection-errors)
- [Demo Accounts](#demo-accounts)
- [Technology Stack](#technology-stack)

---

## Key Features

1. **Role-Based Access Control (RBAC) & Immutable Audit Trail**
   - Three operational roles: `Admin` (Manager), `Staff` (Barista), and `Owner`.
   - Sensitive financial metrics (supplier unit prices, gross margins) are strictly masked from staff.
   - Every stock movement, deduction, and setting change is logged to an immutable audit trail.

2. **Real-Time Live Stock Tracking & Quick Deductions**
   - Single-click stock deductions and waste logging directly from the dashboard.
   - Dynamic search filtering and visual low-stock / reorder point (ROP) alerts.

3. **Offline-First Synchronization & Conflict Detection**
   - Full functionality when internet drops: deductions and counts queue locally in browser `localStorage`.
   - Automatic background synchronization when connectivity resumes (`api/sync.php`), flagging conflict warnings if stock values changed remotely during the offline window.

4. **Physical Shift Inventory Counts & Discrepancy Reporting**
   - Structured count logging across Opening, Handover, and Closing shifts.
   - Visual variance reports highlighting surplus and deficit anomalies.

5. **Invoice Intake with Client-Side OCR & Supplier Mapping**
   - Take photos or upload scanned delivery receipts.
   - Integrated client-side Tesseract.js OCR engine extracts supplier details, line items, and quantities without requiring third-party cloud API keys.
   - Auto-matches supplier product codes with internal inventory catalog IDs (`supplier_mappings`).
   - Draft review workflow with confidence threshold verification before inventory is finalized.

6. **FIFO Waste & Spoilage Tracking**
   - Dedicated shift waste logging with categorized reason presets (expiry, spillage, grinder calibration).
   - Real-time daily shift waste tally.

7. **Purchase Orders & Pre-Order Generator**
   - Automated purchase order generation based on active supplier catalogs and low-stock items.
   - Clean, professional print stylesheet for delivery notes and supplier purchase slips.

8. **Monthly Financial Summary & Excel Export**
   - Summarizes gross sales, procurement costs, COGS, waste value, and gross profit.
   - Single-click `.xlsx` spreadsheet export.

9. **On-Demand SQL Database Backups**
   - Generate full timestamped SQL database dumps in < 1 minute with automatic 7-backup retention.

10. **Security Hardened**
    - Dynamic `.env` credential management.
    - CSRF protection across all state-changing forms and AJAX requests.
    - Session fixation mitigation via `session_regenerate_id(true)`.
    - Session authentication guards on background API endpoints.

---

## System Architecture

```text
├── .env                                # Protected database credentials (git-ignored)
├── .env.example                        # Template environment configuration
├── .gitignore                          # Git exclusions
├── index.php                           # Root entry router (redirects to login or dashboard)
│
├── config/
│   └── db.php                          # Database connection & .env loader
│
├── includes/
│   └── authguard.php                   # Authentication guards, role enforcement & CSRF helpers
│
├── api/
│   └── sync.php                        # Offline/online background synchronization endpoint
│
├── pages/
│   ├── login.php                       # Sign-in portal
│   ├── logout.php                      # Session sign-out handler
│   ├── dashboard.php                   # Stock management, quick actions & audit trail
│   ├── count.php                       # Physical stock counting interface
│   ├── waste.php                       # Spoilage & FIFO waste tracking
│   ├── delivery_intake.php             # Invoice OCR photo intake & item mapping
│   ├── purchase_orders.php             # Purchase order & pre-order generator
│   ├── variance_report.php             # Discrepancy analytics & variance reports
│   ├── financial_report.php            # Monthly financials & Excel export
│   ├── manage_items.php                # Master stock items catalog
│   ├── manage_suppliers.php            # Supplier profiles & lead times
│   ├── manage_users.php                # Owner user & staff credential administration
│   └── backup.php                      # Owner on-demand SQL backup utility
│
├── database/
│   └── aste_inventory.sql              # Database schema & initial seed data
├── uploads/
│   └── invoices/                       # Uploaded invoice scans
└── backups/                            # Stored database dump archives
```

---

## Getting Started

### Prerequisites
- **PHP 8.1+** (with `pdo_mysql` and `mbstring` extensions enabled)
- **MySQL 8.0+** or cloud database instance (e.g., Aiven MySQL)
- Web browser (Chrome, Edge, Firefox, or Safari)

### Environment Setup
1. Clone the repository:
   ```bash
   git clone https://github.com/yhonnah/CPECAPS-Asterisko-Inventory-Management-System.git
   cd CPECAPS-Asterisko-Inventory-Management-System
   ```
2. Create your `.env` configuration file from the template:
   ```bash
   cp .env.example .env
   ```
3. Open `.env` and fill in your database credentials (or use the sample cloud database credentials below):
   ```env
   DB_HOST=aste-inventory-aste-inventory-system.j.aivencloud.com
   DB_PORT=13670
   DB_NAME=aste-inventory
   DB_USER=avnadmin
   DB_PASS=AVNS_5-LQ3ixBakUHi93eYVK
   DB_SSL_MODE=require
   ```

#### Connecting via MySQL Workbench
1. Open MySQL Workbench and click the **+** icon to create a new connection.
2. Enter the sample database credentials:
   - **Host Name / IP:** `aste-inventory-aste-inventory-system.j.aivencloud.com`
   - **Port:** `13670`
   - **User Name:** `avnadmin`
   - **Default Schema:** `aste-inventory`
3. Click **Store in Vault...** for the password and enter:
   ```text
   AVNS_5-LQ3ixBakUHi93eYVK
   ```
4. Configuration review:

   ![correct login screen](assets/login.png)

5. Click **OK** to connect.
4. Import the database schema from `database/aste_inventory.sql` using MySQL Workbench or CLI.

### Running the Local Server
From the project root directory, run:
```bash
php -S localhost:8000
```

#### If `php` is not recognized / not in your system `PATH`:
Run using your direct PHP binary path depending on your local environment:

- **Windows (XAMPP):**
  ```powershell
  C:\xampp\php\php.exe -S localhost:8000
  ```
- **Windows (Laragon):**
  ```powershell
  C:\laragon\bin\php\php-8.2*\php.exe -S localhost:8000
  ```
- **Windows (WampServer):**
  ```powershell
  C:\wamp64\bin\php\php8.2*\php.exe -S localhost:8000
  ```
- **macOS (Homebrew):**
  ```bash
  /opt/homebrew/bin/php -S localhost:8000
  ```

> **Tip:** To temporarily add XAMPP PHP to your active PowerShell session:
> ```powershell
> $env:Path += ";C:\xampp\php"
> php -S localhost:8000
> ```

Open your browser and navigate to:
**[http://localhost:8000](http://localhost:8000)** (automatically directs to the login portal).

---

## Cloud Deployment (Render)

The project includes a ready-to-use [`Dockerfile`](Dockerfile) pre-configured with **PHP 8.2 Apache**, PDO MySQL extensions, and Apache `mod_rewrite`.

### Deploying with Docker

1. Log in to your [Render Dashboard](https://dashboard.render.com/) and click **New +** &rarr; **Web Service**.
2. Connect your GitHub repository: `CPECAPS-Asterisko-Inventory-Management-System`.
3. Configure the service parameters:
   - **Language / Runtime:** `Docker`
   - **Branch:** `main`
   - **Region:** Choose the region closest to your cloud database (e.g., Singapore or Oregon).
   - **Instance Type:** `Free`
4. Render automatically builds the container using [`Dockerfile`](Dockerfile) and listens on port `80`.

### Render Environment Variables

In your Render Web Service dashboard, navigate to **Environment** &rarr; **Add Environment Variable** and specify your database credentials:

| Key | Example Value | Description |
| :--- | :--- | :--- |
| `DB_HOST` | `mysql-xxxx.j.aivencloud.com` | Cloud MySQL hostname |
| `DB_PORT` | `13670` | Database connection port |
| `DB_NAME` | `aste-inventory` | Active database name |
| `DB_USER` | `avnadmin` | Default administrative database user |
| `DB_PASS` | `your_aiven_password` | Database user password |
| `DB_SSL_MODE` | `require` | Enforces SSL encryption for cloud database security |

---

## Cloud Database & Troubleshooting

### Aiven MySQL Configuration

1. Log in to [Aiven Console](https://console.aiven.io/) and create or select your MySQL service.
2. Under the service **Overview** tab, locate the **Connection Details** card.
3. Copy the **Host**, **Port**, **User**, **Password**, and **Database** values into your local `.env` and Render Environment Variables.
4. Import the initial database schema and seed data from [`database/aste_inventory.sql`](database/aste_inventory.sql) using MySQL Workbench or CLI.

### Troubleshooting Connection Errors

#### `SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo failed: Name or service not known`

- **Cause:** This is a DNS lookup failure. The web service cannot resolve the database hostname on the internet. In Aiven, this most commonly occurs when:
  - The database service is **Powered off** or **Suspended** due to inactivity or trial standby (Aiven unpublishes DNS records while a service is inactive).
  - The database service was rebuilt or recreated, resulting in a newly assigned host domain or port.
  - A typo or whitespace exists in the `DB_HOST` configuration.

- **Resolution Steps:**
  1. **Check Service State:** Open the [Aiven Console](https://console.aiven.io/) and ensure the service state displays **Running** (green indicator). If it is "Powered off" or "Standby", click **Power on** and allow 1–2 minutes for DNS propagation.
  2. **Verify Hostname:** Copy the exact host from the Aiven **Overview** tab.
  3. **Update Environment Variables:**
     - On **Render**: Go to Web Service &rarr; **Environment** &rarr; Update `DB_HOST`, `DB_PORT`, and `DB_PASS` &rarr; Click **Save Changes** (Render will automatically redeploy).
     - For **Local Development**: Update your `.env` file accordingly.
  4. **Verify DNS:** You can verify domain resolution from your terminal using:
     ```bash
     nslookup <your-aiven-hostname> 8.8.8.8
     ```

---

## Demo Accounts

All demo accounts use password: **`aste123`**

| Username | Role | Permissions |
| :--- | :--- | :--- |
| **`owner`** | Owner | Elevated Executive Access (User & Staff Administration, Database Backups, Financials, POs, Master Settings) |
| **`manager`** | Admin | Operational Management (Stock Tracking, Financials, POs, Item & Supplier Settings, Discrepancies) |
| **`barista`** | Staff | Floor Operations (Counts, Deductions, Waste, Delivery Intake; Prices & Admin Settings Masked) |

---

## Technology Stack

- **Backend:** PHP 8.2 (Vanilla, PDO, Session Guards, CSRF Defense)
- **Database:** MySQL 8.0 / Aiven Cloud MySQL
- **Frontend:** HTML5, Vanilla JavaScript (Async/Await, Fetch API, LocalStorage Queue)
- **Styling:** Tailwind CSS (Custom Dark Mode & Emerald Green Theme)
- **OCR Engine:** Tesseract.js (Client-side in-browser OCR)
