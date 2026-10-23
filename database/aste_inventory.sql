-- Asterisko Inventory Management System SQL Dump
-- Generated: 2026-10-22 18:25:18
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- --------------------------------------------------------
-- Table structure for table `users`
--

CREATE TABLE "users" (
  "id" int NOT NULL AUTO_INCREMENT,
  "username" varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  "password_hash" varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  "role" enum('Admin','Staff','Owner') COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY ("id"),
  UNIQUE KEY "username" ("username")
);

-- Dumping data for table `users`
--
INSERT INTO `users` (`id`, `username`, `password_hash`, `role`) VALUES
('1', 'manager', '$2a$12$rh5Ot1hboO7KAvPKWK0XHuigXeDN0y/CcyxHivM1cyHI0GdG02zJ2', 'Admin'),
('2', 'barista', '$2a$12$rh5Ot1hboO7KAvPKWK0XHuigXeDN0y/CcyxHivM1cyHI0GdG02zJ2', 'Staff'),
('3', 'owner', '$2a$12$rh5Ot1hboO7KAvPKWK0XHuigXeDN0y/CcyxHivM1cyHI0GdG02zJ2', 'Owner');

-- --------------------------------------------------------
-- Table structure for table `suppliers`
--

CREATE TABLE "suppliers" (
  "id" int NOT NULL AUTO_INCREMENT,
  "NAME" varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  "contact_details" varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  "lead_time_days" int DEFAULT '3',
  "is_active" tinyint(1) DEFAULT '1',
  PRIMARY KEY ("id")
);

-- Dumping data for table `suppliers`
--
INSERT INTO `suppliers` (`id`, `NAME`, `contact_details`, `lead_time_days`, `is_active`) VALUES
('1', 'sample-supplier-1', 'sample1@gmail.com', '3', '1'),
('2', 'sample-supplier-2', 'sample2@gmail.com', '2', '1');

-- --------------------------------------------------------
-- Table structure for table `stock_items`
--

CREATE TABLE "stock_items" (
  "id" int NOT NULL AUTO_INCREMENT,
  "NAME" varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  "category" varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  "quantity" decimal(10,2) NOT NULL DEFAULT '0.00',
  "unit" varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  "reorder_point" decimal(10,2) NOT NULL,
  "unit_cost" decimal(10,2) NOT NULL,
  "supplier_id" int DEFAULT NULL,
  "is_active" tinyint(1) DEFAULT '1',
  PRIMARY KEY ("id"),
  KEY "supplier_id" ("supplier_id"),
  CONSTRAINT "stock_items_ibfk_1" FOREIGN KEY ("supplier_id") REFERENCES "suppliers" ("id")
);

-- Dumping data for table `stock_items`
--
INSERT INTO `stock_items` (`id`, `NAME`, `category`, `quantity`, `unit`, `reorder_point`, `unit_cost`, `supplier_id`, `is_active`) VALUES
('1', 'Houseblend Coffee Beans', 'Beans', '10.00', 'kg', '0.70', '850.00', '1', '1'),
('2', 'Fresh Milk', 'Dairy', '12.00', 'L', '3.45', '110.00', '2', '1');

-- --------------------------------------------------------
-- Table structure for table `supplier_mappings`
--

CREATE TABLE "supplier_mappings" (
  "id" int NOT NULL AUTO_INCREMENT,
  "supplier_id" int NOT NULL,
  "internal_item_id" int NOT NULL,
  "supplier_item_id" varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  "supplier_item_name" varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "updated_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY ("id"),
  UNIQUE KEY "uq_supplier_item" ("supplier_id","supplier_item_id"),
  KEY "idx_internal_item" ("internal_item_id"),
  CONSTRAINT "fk_sm_internal_item" FOREIGN KEY ("internal_item_id") REFERENCES "stock_items" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_sm_supplier" FOREIGN KEY ("supplier_id") REFERENCES "suppliers" ("id") ON DELETE CASCADE
);

-- --------------------------------------------------------
-- Table structure for table `audit_logs`
--

CREATE TABLE "audit_logs" (
  "id" int NOT NULL AUTO_INCREMENT,
  "item_id" int DEFAULT NULL,
  "previous_value" decimal(10,2) DEFAULT NULL,
  "new_value" decimal(10,2) DEFAULT NULL,
  "change_type" varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  "reason" varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  "acting_user" varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY ("id"),
  KEY "item_id" ("item_id"),
  CONSTRAINT "audit_logs_ibfk_1" FOREIGN KEY ("item_id") REFERENCES "stock_items" ("id")
);

-- Dumping data for table `audit_logs`
--
INSERT INTO `audit_logs` (`id`, `item_id`, `previous_value`, `new_value`, `change_type`, `reason`, `acting_user`, `created_at`) VALUES
('1', NULL, NULL, NULL, 'unauthorized_access', 'Blocked access to restricted endpoint: /financial_report.php', 'barista', '2026-10-22 16:25:00');

-- --------------------------------------------------------
-- Table structure for table `purchase_orders`
--

CREATE TABLE "purchase_orders" (
  "id" int NOT NULL AUTO_INCREMENT,
  "po_number" varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  "supplier_id" int NOT NULL,
  "status" enum('draft','ordered','received','cancelled') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'draft',
  "order_type" enum('pre_order','purchase_order') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'purchase_order',
  "total_amount" decimal(10,2) NOT NULL DEFAULT '0.00',
  "notes" text COLLATE utf8mb4_general_ci,
  "created_by" varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY ("id"),
  UNIQUE KEY "uq_po_number" ("po_number"),
  KEY "supplier_id" ("supplier_id"),
  CONSTRAINT "fk_po_supplier" FOREIGN KEY ("supplier_id") REFERENCES "suppliers" ("id") ON DELETE RESTRICT
);

-- --------------------------------------------------------
-- Table structure for table `purchase_order_items`
--

CREATE TABLE "purchase_order_items" (
  "id" int NOT NULL AUTO_INCREMENT,
  "po_id" int NOT NULL,
  "item_id" int NOT NULL,
  "quantity" decimal(10,2) NOT NULL,
  "unit" varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  "unit_price" decimal(10,2) NOT NULL DEFAULT '0.00',
  "subtotal" decimal(10,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY ("id"),
  KEY "po_id" ("po_id"),
  KEY "item_id" ("item_id"),
  CONSTRAINT "fk_poi_item" FOREIGN KEY ("item_id") REFERENCES "stock_items" ("id") ON DELETE RESTRICT,
  CONSTRAINT "fk_poi_po" FOREIGN KEY ("po_id") REFERENCES "purchase_orders" ("id") ON DELETE CASCADE
);

-- --------------------------------------------------------
-- Table structure for table `invoice_drafts`
--

CREATE TABLE "invoice_drafts" (
  "id" int NOT NULL AUTO_INCREMENT,
  "supplier_id" int DEFAULT NULL,
  "supplier_name_raw" varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  "invoice_number" varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  "invoice_date" date DEFAULT NULL,
  "image_path" varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  "status" enum('draft_pending_review','confirmed','discarded') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'draft_pending_review',
  "extracted_data_json" longtext COLLATE utf8mb4_general_ci NOT NULL,
  "created_by" varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  "confirmed_by" varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  "confirmed_at" datetime DEFAULT NULL,
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY ("id"),
  KEY "supplier_id" ("supplier_id"),
  KEY "status" ("status"),
  CONSTRAINT "fk_id_supplier" FOREIGN KEY ("supplier_id") REFERENCES "suppliers" ("id") ON DELETE SET NULL
);

-- --------------------------------------------------------
-- Table structure for table `sales_records`
--

CREATE TABLE "sales_records" (
  "id" int NOT NULL AUTO_INCREMENT,
  "sale_date" date NOT NULL,
  "gross_sales" decimal(10,2) NOT NULL DEFAULT '0.00',
  "source" varchar(50) COLLATE utf8mb4_general_ci DEFAULT 'Manual / POS Export',
  "notes" varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  "recorded_by" varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY ("id"),
  KEY "sale_date" ("sale_date")
);

-- --------------------------------------------------------
-- Table structure for table `database_backups`
--

CREATE TABLE "database_backups" (
  "id" int NOT NULL AUTO_INCREMENT,
  "filename" varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  "file_size_bytes" bigint NOT NULL DEFAULT '0',
  "created_by" varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY ("id"),
  KEY "created_at" ("created_at")
);

COMMIT;
