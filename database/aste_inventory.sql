-- Asterisko Inventory Management System SQL Dump
-- Generated: 2026-10-22 18:25:18
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO,ANSI_QUOTES";
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
  "is_active" tinyint(1) NOT NULL DEFAULT 1,
  "created_at" timestamp DEFAULT CURRENT_TIMESTAMP,
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
('1', 'Benguet Highlands Coffee Roasters', 'orders@benghighlandscoffee.ph | +63 917 555 1021', '3', '1'),
('2', 'Valley Fresh Dairy Farm', 'supply@valleydairy.ph | +63 918 555 3302', '1', '1'),
('3', 'Oatside & Plant Milk Distro', 'orders@oatsidedistro.ph | +63 920 555 8891', '2', '1'),
('4', 'Artisan Gourmet Flavors & Syrups', 'orders@artisangourmet.ph | +63 922 555 7714', '2', '1'),
('5', 'Kyoto Uji Matcha & Tea Imports', 'wholesale@kyototea.ph | +63 917 555 9901', '4', '1'),
('6', 'La Brioche Bakery & Commissary', 'dispatch@labriochepastry.ph | +63 919 555 4410', '1', '1'),
('7', 'EcoPack Cafe Disposables Co.', 'sales@ecopackmanila.ph | +63 925 555 6620', '3', '1');

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
('1', 'Houseblend Coffee Beans', 'Beans', '18.00', 'kg', '3.00', '850.00', '1', '1'),
('2', 'Fresh Whole Milk', 'Dairy', '30.00', 'L', '6.00', '105.00', '2', '1'),
('3', 'Barista Oat Milk', 'Dairy', '16.00', 'L', '3.00', '165.00', '3', '1'),
('4', 'Sweetened Condensed Milk', 'Dairy', '12.00', 'kg', '2.00', '90.00', '2', '1'),
('5', 'Pure Coconut Water', 'General', '12.00', 'L', '2.50', '85.00', '4', '1'),
('6', 'Valencia Orange Puree', 'Syrups', '6.00', 'L', '1.00', '260.00', '4', '1'),
('7', 'Gourmet Caramel Syrup', 'Syrups', '6.00', 'L', '1.00', '320.00', '4', '1'),
('8', 'White Chocolate Ganache Sauce', 'Syrups', '8.00', 'kg', '1.50', '380.00', '4', '1'),
('9', 'French Lavender Flower Extract', 'Syrups', '4.00', 'L', '0.80', '350.00', '4', '1'),
('10', 'Wild Strawberry Fruit Puree', 'Syrups', '8.00', 'L', '1.50', '280.00', '4', '1'),
('11', 'New York Cheesecake Sauce Base', 'Syrups', '8.00', 'kg', '1.50', '390.00', '4', '1'),
('12', 'Artisan Horchata Spiced Rice Base', 'General', '10.00', 'L', '2.00', '180.00', '4', '1'),
('13', 'Ceremonial Uji Matcha Powder', 'Tea', '4.00', 'kg', '0.50', '1350.00', '5', '1'),
('14', 'Roasted Shizuoka Hojicha Powder', 'Tea', '4.00', 'kg', '0.50', '1250.00', '5', '1'),
('15', 'Artisan Butter Croissant', 'Pastry', '30.00', 'pcs', '5.00', '55.00', '6', '1'),
('16', 'Pain Au Chocolat', 'Pastry', '30.00', 'pcs', '5.00', '65.00', '6', '1'),
('17', 'Sticky Glazed Cinnamon Roll', 'Pastry', '30.00', 'pcs', '5.00', '60.00', '6', '1'),
('18', 'Takeaway Cups (Dude 8oz)', 'Packaging', '300.00', 'pcs', '50.00', '3.50', '7', '1'),
('19', 'Takeaway Cups (Pare 12oz)', 'Packaging', '300.00', 'pcs', '50.00', '4.20', '7', '1'),
('20', 'Takeaway Cups (Chong 16oz)', 'Packaging', '300.00', 'pcs', '50.00', '4.80', '7', '1'),
('21', 'Universal Hot/Cold Sipper Lids', 'Packaging', '600.00', 'pcs', '100.00', '1.80', '7', '1');

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


--
-- Dumping data for table "supplier_mappings"
--
INSERT INTO "supplier_mappings" ("supplier_id", "internal_item_id", "supplier_item_id", "supplier_item_name") VALUES
('1', '1', 'BH-BEANS-01', 'Houseblend Espresso Beans 1kg Bag'),
('2', '2', 'VF-MILK-WHOLE', 'Valley Fresh Whole Cow Milk 1L'),
('2', '4', 'VF-COND-CAN', 'Valley Sweetened Condensed Milk 1kg'),
('3', '3', 'OAT-BARISTA-1L', 'Oatside Barista Blend Oat Milk 1L'),
('4', '5', 'COCO-WATER-1L', 'Pure Chilled Coconut Water 1L Tetra'),
('4', '6', 'PUREE-ORANGE-1L', 'Valencia Orange Puree 1L Bottle'),
('4', '7', 'SYR-CARAMEL-1L', 'Artisan Gourmet Caramel Syrup 1L'),
('4', '8', 'SAUCE-WHITECHOC', 'White Chocolate Ganache Syrup 1kg'),
('4', '9', 'EXT-LAVENDER', 'French Lavender Flower Floral Extract 1L'),
('4', '10', 'PUREE-STRAWBERRY', 'Wild Strawberry Puree Syrup 1L'),
('4', '11', 'SAUCE-CHEESECAKE', 'New York Style Cheesecake Cream Sauce 1kg'),
('4', '12', 'BASE-HORCHATA-1L', 'Spiced Horchata Traditional Rice Extract 1L'),
('5', '13', 'KYOTO-MATCHA-CER', 'Kyoto Uji Ceremonial Grade Matcha 1kg'),
('5', '14', 'KYOTO-HOJICHA-01', 'Roasted Shizuoka Hojicha Fine Powder 1kg'),
('6', '15', 'BAKE-CROISSANT-FR', 'Fresh Butter Croissant Tray 10s'),
('6', '16', 'BAKE-PAIN-CHOCO', 'French Pain Au Chocolat Pastry Tray 10s'),
('6', '17', 'BAKE-CINN-ROLL', 'Sticky Glazed Cinnamon Rolls 10s'),
('7', '18', 'CUP-DUDE-8OZ', 'Asterisko Embossed Paper Cups 8oz (50s)'),
('7', '19', 'CUP-PARE-12OZ', 'Asterisko Embossed Paper Cups 12oz (50s)'),
('7', '20', 'CUP-CHONG-16OZ', 'Asterisko Embossed Paper Cups 16oz (50s)'),
('7', '21', 'LID-SIPPER-UNI', 'Universal Eco Sipper Lids 90mm (100s)');

-- --------------------------------------------------------

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
  KEY "idx_type_created" ("change_type","created_at"),
  KEY "idx_created" ("created_at"),
  CONSTRAINT "audit_logs_ibfk_1" FOREIGN KEY ("item_id") REFERENCES "stock_items" ("id")
);

-- Dumping data for table `audit_logs` (Initial clean state - all audit trails purged)
--

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
  KEY "sale_date" ("sale_date"),
  KEY "idx_date_source" ("sale_date","source")
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



-- --------------------------------------------------------
-- Table structure for table "menu_items"
--

CREATE TABLE "menu_items" (
  "id" int NOT NULL AUTO_INCREMENT,
  "name" varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  "category" varchar(50) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Espresso',
  "price" decimal(10,2) NOT NULL DEFAULT '0.00',
  "description" varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  "icon" varchar(50) COLLATE utf8mb4_general_ci DEFAULT 'coffee',
  "is_active" tinyint(1) NOT NULL DEFAULT '1',
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY ("id")
);

-- --------------------------------------------------------
-- Table structure for table "menu_item_ingredients"
--

CREATE TABLE "menu_item_ingredients" (
  "id" int NOT NULL AUTO_INCREMENT,
  "menu_item_id" int NOT NULL,
  "stock_item_id" int NOT NULL,
  "quantity_required" decimal(10,4) NOT NULL,
  "unit" varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY ("id"),
  KEY "idx_menu_item" ("menu_item_id"),
  KEY "idx_stock_item" ("stock_item_id"),
  CONSTRAINT "fk_mii_menu" FOREIGN KEY ("menu_item_id") REFERENCES "menu_items" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_mii_stock" FOREIGN KEY ("stock_item_id") REFERENCES "stock_items" ("id") ON DELETE CASCADE
);

-- --------------------------------------------------------
-- Table structure for table "coffee_orders"
--

CREATE TABLE "coffee_orders" (
  "id" int NOT NULL AUTO_INCREMENT,
  "order_number" varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  "order_type" enum('coffee_drink','manual_deduction') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'coffee_drink',
  "total_amount" decimal(10,2) NOT NULL DEFAULT '0.00',
  "items_count" int NOT NULL DEFAULT '1',
  "notes" text COLLATE utf8mb4_general_ci,
  "served_by" varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY ("id"),
  KEY "idx_created_at" ("created_at")
);

-- --------------------------------------------------------
-- Table structure for table "coffee_order_items"
--

CREATE TABLE "coffee_order_items" (
  "id" int NOT NULL AUTO_INCREMENT,
  "order_id" int NOT NULL,
  "menu_item_id" int DEFAULT NULL,
  "item_name" varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  "quantity" int NOT NULL DEFAULT '1',
  "price" decimal(10,2) NOT NULL DEFAULT '0.00',
  "subtotal" decimal(10,2) NOT NULL DEFAULT '0.00',
  "deductions_json" text COLLATE utf8mb4_general_ci,
  PRIMARY KEY ("id"),
  KEY "idx_order" ("order_id"),
  CONSTRAINT "fk_coi_order" FOREIGN KEY ("order_id") REFERENCES "coffee_orders" ("id") ON DELETE CASCADE
);

-- --------------------------------------------------------
-- Table structure for table "system_cache_meta"
--

CREATE TABLE "system_cache_meta" (
  "meta_key" varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  "meta_value" varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  "updated_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY ("meta_key")
);

INSERT INTO "system_cache_meta" ("meta_key", "meta_value") VALUES ('catalog_version', 'v_init')
ON DUPLICATE KEY UPDATE "meta_value" = VALUES("meta_value");

--
-- Dumping data for table "menu_items"
--
INSERT INTO "menu_items" ("id", "name", "category", "price", "description", "icon", "is_active") VALUES
('1', 'Americano (Dude)', 'Americano Series', '140.00', 'Double espresso balanced with hot or chilled filtered water (8 oz)', 'coffee', '1'),
('2', 'Americano (Pare)', 'Americano Series', '150.00', 'Rich double espresso balanced with hot or chilled filtered water (12 oz)', 'coffee', '1'),
('3', 'Americano (Chong)', 'Americano Series', '165.00', 'Extra-shot espresso balanced with hot or chilled filtered water (16 oz)', 'coffee', '1'),
('4', 'Coco Americano (Dude)', 'Americano Series', '165.00', 'Refreshing coconut water topped with espresso (8 oz)', 'ice-coffee', '1'),
('5', 'Coco Americano (Pare)', 'Americano Series', '175.00', 'Refreshing coconut water topped with espresso (12 oz)', 'ice-coffee', '1'),
('6', 'Coco Americano (Chong)', 'Americano Series', '185.00', 'Refreshing coconut water topped with espresso (16 oz)', 'ice-coffee', '1'),
('7', 'Orange Americano (Dude)', 'Americano Series', '165.00', 'Tangy orange puree layered with fresh espresso (8 oz)', 'ice-coffee', '1'),
('8', 'Orange Americano (Pare)', 'Americano Series', '175.00', 'Tangy orange puree layered with fresh espresso (12 oz)', 'ice-coffee', '1'),
('9', 'Orange Americano (Chong)', 'Americano Series', '185.00', 'Tangy orange puree layered with fresh espresso (16 oz)', 'ice-coffee', '1'),
('10', 'Latte (Dude)', 'Classics', '160.00', 'Espresso with silky textured milk (8 oz)', 'latte', '1'),
('11', 'Latte (Pare)', 'Classics', '170.00', 'Espresso with silky textured milk (12 oz)', 'latte', '1'),
('12', 'Latte (Chong)', 'Classics', '180.00', 'Espresso with silky textured milk (16 oz)', 'latte', '1'),
('13', 'Caramel Latte (Dude)', 'Classics', '175.00', 'Espresso & silky milk infused with caramel (8 oz)', 'latte', '1'),
('14', 'Caramel Latte (Pare)', 'Classics', '185.00', 'Espresso & silky milk infused with caramel (12 oz)', 'latte', '1'),
('15', 'Caramel Latte (Chong)', 'Classics', '195.00', 'Espresso & silky milk infused with caramel (16 oz)', 'latte', '1'),
('16', 'White Mocha (Dude)', 'Classics', '195.00', 'Decadent white chocolate sauce blended with espresso & milk (8 oz)', 'latte', '1'),
('17', 'White Mocha (Pare)', 'Classics', '205.00', 'Decadent white chocolate sauce blended with espresso & milk (12 oz)', 'latte', '1'),
('18', 'White Mocha (Chong)', 'Classics', '215.00', 'Decadent white chocolate sauce blended with espresso & milk (16 oz)', 'latte', '1'),
('19', 'Spanish Latte (Dude)', 'Classics', '170.00', 'Sweetened condensed milk blend with rich espresso (8 oz)', 'latte', '1'),
('20', 'Spanish Latte (Pare)', 'Classics', '180.00', 'Sweetened condensed milk blend with rich espresso (12 oz)', 'latte', '1'),
('21', 'Spanish Latte (Chong)', 'Classics', '190.00', 'Sweetened condensed milk blend with rich espresso (16 oz)', 'latte', '1'),
('22', 'Lavender Latte (Dude)', 'Signature Lattes', '170.00', 'Floral lavender tea, espresso & milk (8 oz)', 'latte', '1'),
('23', 'Lavender Latte (Pare)', 'Signature Lattes', '180.00', 'Floral lavender tea, espresso & milk (12 oz)', 'latte', '1'),
('24', 'Lavender Latte (Chong)', 'Signature Lattes', '190.00', 'Floral lavender tea, espresso & milk (16 oz)', 'latte', '1'),
('25', 'Meiji Latte (Dude)', 'Signature Lattes', '190.00', 'Japanese strawberry, espresso & milk (8 oz)', 'latte', '1'),
('26', 'Meiji Latte (Pare)', 'Signature Lattes', '200.00', 'Japanese strawberry, espresso & milk (12 oz)', 'latte', '1'),
('27', 'Meiji Latte (Chong)', 'Signature Lattes', '210.00', 'Japanese strawberry, espresso & milk (16 oz)', 'latte', '1'),
('28', 'Cinnamon Bun Latte (Dude)', 'Signature Lattes', '195.00', 'White choco, cinnamon spice, espresso & milk (8 oz)', 'latte', '1'),
('29', 'Cinnamon Bun Latte (Pare)', 'Signature Lattes', '205.00', 'White choco, cinnamon spice, espresso & milk (12 oz)', 'latte', '1'),
('30', 'Cinnamon Bun Latte (Chong)', 'Signature Lattes', '215.00', 'White choco, cinnamon spice, espresso & milk (16 oz)', 'latte', '1'),
('31', 'Sheeshcake Latte (Dude)', 'Signature Lattes', '175.00', 'Cheesecake infusion, espresso & milk (8 oz)', 'latte', '1'),
('32', 'Sheeshcake Latte (Pare)', 'Signature Lattes', '185.00', 'Cheesecake infusion, espresso & milk (12 oz)', 'latte', '1'),
('33', 'Sheeshcake Latte (Chong)', 'Signature Lattes', '195.00', 'Cheesecake infusion, espresso & milk (16 oz)', 'latte', '1'),
('34', 'Dirty Horchata (Dude)', 'Signature Lattes', '180.00', 'Spiced horchata base topped with espresso (8 oz)', 'latte', '1'),
('35', 'Dirty Horchata (Pare)', 'Signature Lattes', '190.00', 'Spiced horchata base topped with espresso (12 oz)', 'latte', '1'),
('36', 'Dirty Horchata (Chong)', 'Signature Lattes', '200.00', 'Spiced horchata base topped with espresso (16 oz)', 'latte', '1'),
('37', 'Oatchata (Dude)', 'Non-Coffee', '160.00', 'Creamy oat milk horchata with cinnamon (8 oz)', 'ice-coffee', '1'),
('38', 'Oatchata (Pare)', 'Non-Coffee', '170.00', 'Creamy oat milk horchata with cinnamon (12 oz)', 'ice-coffee', '1'),
('39', 'Oatchata (Chong)', 'Non-Coffee', '180.00', 'Creamy oat milk horchata with cinnamon (16 oz)', 'ice-coffee', '1'),
('40', 'White Chocolate (Dude)', 'Non-Coffee', '170.00', 'Steamed or iced velvety white chocolate with milk (8 oz)', 'latte', '1'),
('41', 'White Chocolate (Pare)', 'Non-Coffee', '180.00', 'Steamed or iced velvety white chocolate with milk (12 oz)', 'latte', '1'),
('42', 'White Chocolate (Chong)', 'Non-Coffee', '190.00', 'Steamed or iced velvety white chocolate with milk (16 oz)', 'latte', '1'),
('43', 'Strawberry Cheesecake (Dude)', 'Non-Coffee', '185.00', 'Strawberry puree with creamy cheesecake milk (8 oz)', 'ice-coffee', '1'),
('44', 'Strawberry Cheesecake (Pare)', 'Non-Coffee', '195.00', 'Strawberry puree with creamy cheesecake milk (12 oz)', 'ice-coffee', '1'),
('45', 'Strawberry Cheesecake (Chong)', 'Non-Coffee', '205.00', 'Strawberry puree with creamy cheesecake milk (16 oz)', 'ice-coffee', '1'),
('46', 'Matcha (Dude)', 'Matcha & Hojicha', '185.00', 'Uji ceremonial green tea matcha with milk (8 oz)', 'latte', '1'),
('47', 'Matcha (Pare)', 'Matcha & Hojicha', '195.00', 'Uji ceremonial green tea matcha with milk (12 oz)', 'latte', '1'),
('48', 'Strawberry Matcha (Dude)', 'Matcha & Hojicha', '210.00', 'Layered strawberry puree, milk, and rich matcha (8 oz)', 'ice-coffee', '1'),
('49', 'Strawberry Matcha (Pare)', 'Matcha & Hojicha', '220.00', 'Layered strawberry puree, milk, and rich matcha (12 oz)', 'ice-coffee', '1'),
('50', 'Hojicha (Dude)', 'Matcha & Hojicha', '185.00', 'Roasted Japanese green tea latte with nutty aroma (8 oz)', 'latte', '1'),
('51', 'Hojicha (Pare)', 'Matcha & Hojicha', '195.00', 'Roasted Japanese green tea latte with nutty aroma (12 oz)', 'latte', '1'),
('52', 'Croissant', 'Pastries', '120.00', 'Flaky, butter-layered golden French croissant baked daily', 'coffee', '1'),
('53', 'Pain Au Chocolat', 'Pastries', '135.00', 'Traditional French pastry filled with dark chocolate', 'coffee', '1'),
('54', 'Sticky Cinnamon Roll', 'Pastries', '130.00', 'Warm spiced roll drizzled with sweet sticky glaze', 'coffee', '1'),
('55', 'Sub-Oat Milk (Add-on)', 'Add-ons', '20.00', 'Substitute dairy milk with barista oat milk', 'coffee', '1'),
('56', 'Extra Espresso Shot (Add-on)', 'Add-ons', '20.00', 'Additional shot of rich houseblend espresso', 'coffee', '1');

--
-- Dumping data for table "menu_item_ingredients"
--
INSERT INTO "menu_item_ingredients" ("menu_item_id", "stock_item_id", "quantity_required", "unit") VALUES
('1', '1', '0.0180', 'kg'),
('1', '18', '1.0000', 'pcs'),
('1', '21', '1.0000', 'pcs'),
('2', '1', '0.0200', 'kg'),
('2', '19', '1.0000', 'pcs'),
('2', '21', '1.0000', 'pcs'),
('3', '1', '0.0240', 'kg'),
('3', '20', '1.0000', 'pcs'),
('3', '21', '1.0000', 'pcs'),
('4', '1', '0.0180', 'kg'),
('4', '5', '0.1500', 'L'),
('4', '18', '1.0000', 'pcs'),
('4', '21', '1.0000', 'pcs'),
('5', '1', '0.0200', 'kg'),
('5', '5', '0.2000', 'L'),
('5', '19', '1.0000', 'pcs'),
('5', '21', '1.0000', 'pcs'),
('6', '1', '0.0240', 'kg'),
('6', '5', '0.2500', 'L'),
('6', '20', '1.0000', 'pcs'),
('6', '21', '1.0000', 'pcs'),
('7', '1', '0.0180', 'kg'),
('7', '6', '0.0300', 'L'),
('7', '18', '1.0000', 'pcs'),
('7', '21', '1.0000', 'pcs'),
('8', '1', '0.0200', 'kg'),
('8', '6', '0.0400', 'L'),
('8', '19', '1.0000', 'pcs'),
('8', '21', '1.0000', 'pcs'),
('9', '1', '0.0240', 'kg'),
('9', '6', '0.0500', 'L'),
('9', '20', '1.0000', 'pcs'),
('9', '21', '1.0000', 'pcs'),
('10', '1', '0.0180', 'kg'),
('10', '2', '0.1600', 'L'),
('10', '18', '1.0000', 'pcs'),
('10', '21', '1.0000', 'pcs'),
('11', '1', '0.0200', 'kg'),
('11', '2', '0.2200', 'L'),
('11', '19', '1.0000', 'pcs'),
('11', '21', '1.0000', 'pcs'),
('12', '1', '0.0240', 'kg'),
('12', '2', '0.2800', 'L'),
('12', '20', '1.0000', 'pcs'),
('12', '21', '1.0000', 'pcs'),
('13', '1', '0.0180', 'kg'),
('13', '2', '0.1600', 'L'),
('13', '7', '0.0200', 'L'),
('13', '18', '1.0000', 'pcs'),
('13', '21', '1.0000', 'pcs'),
('14', '1', '0.0200', 'kg'),
('14', '2', '0.2200', 'L'),
('14', '7', '0.0300', 'L'),
('14', '19', '1.0000', 'pcs'),
('14', '21', '1.0000', 'pcs'),
('15', '1', '0.0240', 'kg'),
('15', '2', '0.2800', 'L'),
('15', '7', '0.0400', 'L'),
('15', '20', '1.0000', 'pcs'),
('15', '21', '1.0000', 'pcs'),
('16', '1', '0.0180', 'kg'),
('16', '2', '0.1600', 'L'),
('16', '8', '0.0250', 'kg'),
('16', '18', '1.0000', 'pcs'),
('16', '21', '1.0000', 'pcs'),
('17', '1', '0.0200', 'kg'),
('17', '2', '0.2200', 'L'),
('17', '8', '0.0350', 'kg'),
('17', '19', '1.0000', 'pcs'),
('17', '21', '1.0000', 'pcs'),
('18', '1', '0.0240', 'kg'),
('18', '2', '0.2800', 'L'),
('18', '8', '0.0450', 'kg'),
('18', '20', '1.0000', 'pcs'),
('18', '21', '1.0000', 'pcs'),
('19', '1', '0.0180', 'kg'),
('19', '2', '0.1400', 'L'),
('19', '4', '0.0250', 'kg'),
('19', '18', '1.0000', 'pcs'),
('19', '21', '1.0000', 'pcs'),
('20', '1', '0.0200', 'kg'),
('20', '2', '0.2000', 'L'),
('20', '4', '0.0350', 'kg'),
('20', '19', '1.0000', 'pcs'),
('20', '21', '1.0000', 'pcs'),
('21', '1', '0.0240', 'kg'),
('21', '2', '0.2500', 'L'),
('21', '4', '0.0450', 'kg'),
('21', '20', '1.0000', 'pcs'),
('21', '21', '1.0000', 'pcs'),
('22', '1', '0.0180', 'kg'),
('22', '2', '0.1500', 'L'),
('22', '9', '0.0200', 'L'),
('22', '18', '1.0000', 'pcs'),
('22', '21', '1.0000', 'pcs'),
('23', '1', '0.0200', 'kg'),
('23', '2', '0.2000', 'L'),
('23', '9', '0.0300', 'L'),
('23', '19', '1.0000', 'pcs'),
('23', '21', '1.0000', 'pcs'),
('24', '1', '0.0240', 'kg'),
('24', '2', '0.2500', 'L'),
('24', '9', '0.0400', 'L'),
('24', '20', '1.0000', 'pcs'),
('24', '21', '1.0000', 'pcs'),
('25', '1', '0.0180', 'kg'),
('25', '2', '0.1500', 'L'),
('25', '10', '0.0250', 'L'),
('25', '18', '1.0000', 'pcs'),
('25', '21', '1.0000', 'pcs'),
('26', '1', '0.0200', 'kg'),
('26', '2', '0.2000', 'L'),
('26', '10', '0.0350', 'L'),
('26', '19', '1.0000', 'pcs'),
('26', '21', '1.0000', 'pcs'),
('27', '1', '0.0240', 'kg'),
('27', '2', '0.2500', 'L'),
('27', '10', '0.0450', 'L'),
('27', '20', '1.0000', 'pcs'),
('27', '21', '1.0000', 'pcs'),
('28', '1', '0.0180', 'kg'),
('28', '2', '0.1500', 'L'),
('28', '8', '0.0250', 'kg'),
('28', '18', '1.0000', 'pcs'),
('28', '21', '1.0000', 'pcs'),
('29', '1', '0.0200', 'kg'),
('29', '2', '0.2000', 'L'),
('29', '8', '0.0350', 'kg'),
('29', '19', '1.0000', 'pcs'),
('29', '21', '1.0000', 'pcs'),
('30', '1', '0.0240', 'kg'),
('30', '2', '0.2500', 'L'),
('30', '8', '0.0450', 'kg'),
('30', '20', '1.0000', 'pcs'),
('30', '21', '1.0000', 'pcs'),
('31', '1', '0.0180', 'kg'),
('31', '2', '0.1500', 'L'),
('31', '11', '0.0250', 'kg'),
('31', '18', '1.0000', 'pcs'),
('31', '21', '1.0000', 'pcs'),
('32', '1', '0.0200', 'kg'),
('32', '2', '0.2000', 'L'),
('32', '11', '0.0350', 'kg'),
('32', '19', '1.0000', 'pcs'),
('32', '21', '1.0000', 'pcs'),
('33', '1', '0.0240', 'kg'),
('33', '2', '0.2500', 'L'),
('33', '11', '0.0450', 'kg'),
('33', '20', '1.0000', 'pcs'),
('33', '21', '1.0000', 'pcs'),
('34', '1', '0.0180', 'kg'),
('34', '12', '0.1500', 'L'),
('34', '18', '1.0000', 'pcs'),
('34', '21', '1.0000', 'pcs'),
('35', '1', '0.0200', 'kg'),
('35', '12', '0.2000', 'L'),
('35', '19', '1.0000', 'pcs'),
('35', '21', '1.0000', 'pcs'),
('36', '1', '0.0240', 'kg'),
('36', '12', '0.2500', 'L'),
('36', '20', '1.0000', 'pcs'),
('36', '21', '1.0000', 'pcs'),
('37', '3', '0.1600', 'L'),
('37', '12', '0.0500', 'L'),
('37', '18', '1.0000', 'pcs'),
('37', '21', '1.0000', 'pcs'),
('38', '3', '0.2200', 'L'),
('38', '12', '0.0700', 'L'),
('38', '19', '1.0000', 'pcs'),
('38', '21', '1.0000', 'pcs'),
('39', '3', '0.2800', 'L'),
('39', '12', '0.0900', 'L'),
('39', '20', '1.0000', 'pcs'),
('39', '21', '1.0000', 'pcs'),
('40', '2', '0.1600', 'L'),
('40', '8', '0.0300', 'kg'),
('40', '18', '1.0000', 'pcs'),
('40', '21', '1.0000', 'pcs'),
('41', '2', '0.2200', 'L'),
('41', '8', '0.0400', 'kg'),
('41', '19', '1.0000', 'pcs'),
('41', '21', '1.0000', 'pcs'),
('42', '2', '0.2800', 'L'),
('42', '8', '0.0500', 'kg'),
('42', '20', '1.0000', 'pcs'),
('42', '21', '1.0000', 'pcs'),
('43', '2', '0.1500', 'L'),
('43', '10', '0.0300', 'L'),
('43', '11', '0.0250', 'kg'),
('43', '18', '1.0000', 'pcs'),
('43', '21', '1.0000', 'pcs'),
('44', '2', '0.2000', 'L'),
('44', '10', '0.0400', 'L'),
('44', '11', '0.0350', 'kg'),
('44', '19', '1.0000', 'pcs'),
('44', '21', '1.0000', 'pcs'),
('45', '2', '0.2500', 'L'),
('45', '10', '0.0500', 'L'),
('45', '11', '0.0450', 'kg'),
('45', '20', '1.0000', 'pcs'),
('45', '21', '1.0000', 'pcs'),
('46', '13', '0.0050', 'kg'),
('46', '2', '0.1600', 'L'),
('46', '18', '1.0000', 'pcs'),
('46', '21', '1.0000', 'pcs'),
('47', '13', '0.0070', 'kg'),
('47', '2', '0.2200', 'L'),
('47', '19', '1.0000', 'pcs'),
('47', '21', '1.0000', 'pcs'),
('48', '13', '0.0050', 'kg'),
('48', '2', '0.1400', 'L'),
('48', '10', '0.0300', 'L'),
('48', '18', '1.0000', 'pcs'),
('48', '21', '1.0000', 'pcs'),
('49', '13', '0.0070', 'kg'),
('49', '2', '0.2000', 'L'),
('49', '10', '0.0400', 'L'),
('49', '19', '1.0000', 'pcs'),
('49', '21', '1.0000', 'pcs'),
('50', '14', '0.0050', 'kg'),
('50', '2', '0.1600', 'L'),
('50', '18', '1.0000', 'pcs'),
('50', '21', '1.0000', 'pcs'),
('51', '14', '0.0070', 'kg'),
('51', '2', '0.2200', 'L'),
('51', '19', '1.0000', 'pcs'),
('51', '21', '1.0000', 'pcs'),
('52', '15', '1.0000', 'pcs'),
('53', '16', '1.0000', 'pcs'),
('54', '17', '1.0000', 'pcs'),
('55', '3', '0.1800', 'L'),
('56', '1', '0.0100', 'kg');

COMMIT;
