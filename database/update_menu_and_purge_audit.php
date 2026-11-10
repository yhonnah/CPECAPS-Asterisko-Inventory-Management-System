<?php
/**
 * Asterisko Artisan Coffee - Menu, Ingredients, Suppliers & Recipes Master Synchronizer
 * Reference: astemenu.jpg
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/cache_helper.php';

try {
    echo "========================================================\n";
    echo " Asterisko Inventory & POS Master Synchronizer\n";
    echo " Reference: astemenu.jpg\n";
    echo "========================================================\n\n";

    $pdo = ensureDbConnection();

    // ---------------------------------------------------------
    // 1. PURGE AUDIT TRAILS
    // ---------------------------------------------------------
    echo "1. Purging all audit logs...";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo->exec("TRUNCATE TABLE audit_logs;");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo " [DONE] Audit trail reset.\n";

    // ---------------------------------------------------------
    // 2. VERIFY / CREATE CORE TABLES
    // ---------------------------------------------------------
    echo "2. Ensuring database tables exist...";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            contact_details VARCHAR(150) DEFAULT NULL,
            lead_time_days INT DEFAULT 3,
            is_active TINYINT(1) DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            category VARCHAR(50) NOT NULL,
            quantity DECIMAL(10,2) NOT NULL DEFAULT '0.00',
            unit VARCHAR(20) NOT NULL,
            reorder_point DECIMAL(10,2) NOT NULL,
            unit_cost DECIMAL(10,2) NOT NULL,
            supplier_id INT DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            KEY supplier_id (supplier_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS supplier_mappings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_id INT NOT NULL,
            internal_item_id INT NOT NULL,
            supplier_item_id VARCHAR(100) NOT NULL,
            supplier_item_name VARCHAR(150) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_supplier_item (supplier_id, supplier_item_id),
            KEY idx_internal_item (internal_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS menu_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            category VARCHAR(50) NOT NULL DEFAULT 'Espresso',
            price DECIMAL(10,2) NOT NULL DEFAULT '0.00',
            description VARCHAR(255) DEFAULT NULL,
            icon VARCHAR(50) DEFAULT 'coffee',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS menu_item_ingredients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            menu_item_id INT NOT NULL,
            stock_item_id INT NOT NULL,
            quantity_required DECIMAL(10,4) NOT NULL,
            unit VARCHAR(20) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_menu_item (menu_item_id),
            KEY idx_stock_item (stock_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo " [DONE]\n";

    // ---------------------------------------------------------
    // 3. SEED PROFESSIONAL ASTERISKO SUPPLIERS
    // ---------------------------------------------------------
    echo "3. Seeding professional cafe suppliers...";
    $suppliersData = [
        1 => ['name' => 'Benguet Highlands Coffee Roasters', 'contact' => 'orders@benghighlandscoffee.ph | +63 917 555 1021', 'lead' => 3],
        2 => ['name' => 'Valley Fresh Dairy Farm', 'contact' => 'supply@valleydairy.ph | +63 918 555 3302', 'lead' => 1],
        3 => ['name' => 'Oatside & Plant Milk Distro', 'contact' => 'orders@oatsidedistro.ph | +63 920 555 8891', 'lead' => 2],
        4 => ['name' => 'Artisan Gourmet Flavors & Syrups', 'contact' => 'orders@artisangourmet.ph | +63 922 555 7714', 'lead' => 2],
        5 => ['name' => 'Kyoto Uji Matcha & Tea Imports', 'contact' => 'wholesale@kyototea.ph | +63 917 555 9901', 'lead' => 4],
        6 => ['name' => 'La Brioche Bakery & Commissary', 'contact' => 'dispatch@labriochepastry.ph | +63 919 555 4410', 'lead' => 1],
        7 => ['name' => 'EcoPack Cafe Disposables Co.', 'contact' => 'sales@ecopackmanila.ph | +63 925 555 6620', 'lead' => 3]
    ];

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo->exec("TRUNCATE TABLE suppliers;");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    $insSup = $pdo->prepare("INSERT INTO suppliers (id, name, contact_details, lead_time_days, is_active) VALUES (?, ?, ?, ?, 1)");
    foreach ($suppliersData as $id => $s) {
        $insSup->execute([$id, $s['name'], $s['contact'], $s['lead']]);
    }
    echo " [DONE] Seeded " . count($suppliersData) . " suppliers.\n";

    // ---------------------------------------------------------
    // 4. SEED DETAILED RAW STOCK ITEMS WITH REALISTIC INGREDIENT COSTS
    // ---------------------------------------------------------
    echo "4. Seeding ingredient inventory catalog & unit costs...";
    $stockItemsData = [
        1  => ['name' => 'Houseblend Coffee Beans', 'cat' => 'Beans', 'qty' => 18.00, 'unit' => 'kg', 'rop' => 3.00, 'cost' => 850.00, 'sup' => 1],
        2  => ['name' => 'Fresh Whole Milk', 'cat' => 'Dairy', 'qty' => 30.00, 'unit' => 'L', 'rop' => 6.00, 'cost' => 105.00, 'sup' => 2],
        3  => ['name' => 'Barista Oat Milk', 'cat' => 'Dairy', 'qty' => 16.00, 'unit' => 'L', 'rop' => 3.00, 'cost' => 165.00, 'sup' => 3],
        4  => ['name' => 'Sweetened Condensed Milk', 'cat' => 'Dairy', 'qty' => 12.00, 'unit' => 'kg', 'rop' => 2.00, 'cost' => 90.00, 'sup' => 2],
        5  => ['name' => 'Pure Coconut Water', 'cat' => 'General', 'qty' => 12.00, 'unit' => 'L', 'rop' => 2.50, 'cost' => 85.00, 'sup' => 4],
        6  => ['name' => 'Valencia Orange Puree', 'cat' => 'Syrups', 'qty' => 6.00, 'unit' => 'L', 'rop' => 1.00, 'cost' => 260.00, 'sup' => 4],
        7  => ['name' => 'Gourmet Caramel Syrup', 'cat' => 'Syrups', 'qty' => 6.00, 'unit' => 'L', 'rop' => 1.00, 'cost' => 320.00, 'sup' => 4],
        8  => ['name' => 'White Chocolate Ganache Sauce', 'cat' => 'Syrups', 'qty' => 8.00, 'unit' => 'kg', 'rop' => 1.50, 'cost' => 380.00, 'sup' => 4],
        9  => ['name' => 'French Lavender Flower Extract', 'cat' => 'Syrups', 'qty' => 4.00, 'unit' => 'L', 'rop' => 0.80, 'cost' => 350.00, 'sup' => 4],
        10 => ['name' => 'Wild Strawberry Fruit Puree', 'cat' => 'Syrups', 'qty' => 8.00, 'unit' => 'L', 'rop' => 1.50, 'cost' => 280.00, 'sup' => 4],
        11 => ['name' => 'New York Cheesecake Sauce Base', 'cat' => 'Syrups', 'qty' => 8.00, 'unit' => 'kg', 'rop' => 1.50, 'cost' => 390.00, 'sup' => 4],
        12 => ['name' => 'Artisan Horchata Spiced Rice Base', 'cat' => 'General', 'qty' => 10.00, 'unit' => 'L', 'rop' => 2.00, 'cost' => 180.00, 'sup' => 4],
        13 => ['name' => 'Ceremonial Uji Matcha Powder', 'cat' => 'Tea', 'qty' => 4.00, 'unit' => 'kg', 'rop' => 0.50, 'cost' => 1350.00, 'sup' => 5],
        14 => ['name' => 'Roasted Shizuoka Hojicha Powder', 'cat' => 'Tea', 'qty' => 4.00, 'unit' => 'kg', 'rop' => 0.50, 'cost' => 1250.00, 'sup' => 5],
        15 => ['name' => 'Artisan Butter Croissant', 'cat' => 'Pastry', 'qty' => 30.00, 'unit' => 'pcs', 'rop' => 5.00, 'cost' => 55.00, 'sup' => 6],
        16 => ['name' => 'Pain Au Chocolat', 'cat' => 'Pastry', 'qty' => 30.00, 'unit' => 'pcs', 'rop' => 5.00, 'cost' => 65.00, 'sup' => 6],
        17 => ['name' => 'Sticky Glazed Cinnamon Roll', 'cat' => 'Pastry', 'qty' => 30.00, 'unit' => 'pcs', 'rop' => 5.00, 'cost' => 60.00, 'sup' => 6],
        18 => ['name' => 'Takeaway Cups (Dude 8oz)', 'cat' => 'Packaging', 'qty' => 300.00, 'unit' => 'pcs', 'rop' => 50.00, 'cost' => 3.50, 'sup' => 7],
        19 => ['name' => 'Takeaway Cups (Pare 12oz)', 'cat' => 'Packaging', 'qty' => 300.00, 'unit' => 'pcs', 'rop' => 50.00, 'cost' => 4.20, 'sup' => 7],
        20 => ['name' => 'Takeaway Cups (Chong 16oz)', 'cat' => 'Packaging', 'qty' => 300.00, 'unit' => 'pcs', 'rop' => 50.00, 'cost' => 4.80, 'sup' => 7],
        21 => ['name' => 'Universal Hot/Cold Sipper Lids', 'cat' => 'Packaging', 'qty' => 600.00, 'unit' => 'pcs', 'rop' => 100.00, 'cost' => 1.80, 'sup' => 7]
    ];

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo->exec("TRUNCATE TABLE stock_items;");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    $insStock = $pdo->prepare("
        INSERT INTO stock_items (id, name, category, quantity, unit, reorder_point, unit_cost, supplier_id, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");
    foreach ($stockItemsData as $id => $si) {
        $insStock->execute([$id, $si['name'], $si['cat'], $si['qty'], $si['unit'], $si['rop'], $si['cost'], $si['sup']]);
    }
    echo " [DONE] Seeded " . count($stockItemsData) . " raw ingredients & supplies.\n";

    // ---------------------------------------------------------
    // 5. SEED SUPPLIER OCR SKU MAPPINGS
    // ---------------------------------------------------------
    echo "5. Seeding supplier OCR barcode & invoice SKU mappings...";
    $supplierMappingsData = [
        ['sup' => 1, 'item' => 1,  'sku' => 'BH-BEANS-01',        'desc' => 'Houseblend Espresso Beans 1kg Bag'],
        ['sup' => 2, 'item' => 2,  'sku' => 'VF-MILK-WHOLE',      'desc' => 'Valley Fresh Whole Cow Milk 1L'],
        ['sup' => 2, 'item' => 4,  'sku' => 'VF-COND-CAN',        'desc' => 'Valley Sweetened Condensed Milk 1kg'],
        ['sup' => 3, 'item' => 3,  'sku' => 'OAT-BARISTA-1L',     'desc' => 'Oatside Barista Blend Oat Milk 1L'],
        ['sup' => 4, 'item' => 5,  'sku' => 'COCO-WATER-1L',      'desc' => 'Pure Chilled Coconut Water 1L Tetra'],
        ['sup' => 4, 'item' => 6,  'sku' => 'PUREE-ORANGE-1L',    'desc' => 'Valencia Orange Puree 1L Bottle'],
        ['sup' => 4, 'item' => 7,  'sku' => 'SYR-CARAMEL-1L',     'desc' => 'Artisan Gourmet Caramel Syrup 1L'],
        ['sup' => 4, 'item' => 8,  'sku' => 'SAUCE-WHITECHOC',    'desc' => 'White Chocolate Ganache Syrup 1kg'],
        ['sup' => 4, 'item' => 9,  'sku' => 'EXT-LAVENDER',       'desc' => 'French Lavender Flower Floral Extract 1L'],
        ['sup' => 4, 'item' => 10, 'sku' => 'PUREE-STRAWBERRY',   'desc' => 'Wild Strawberry Puree Syrup 1L'],
        ['sup' => 4, 'item' => 11, 'sku' => 'SAUCE-CHEESECAKE',   'desc' => 'New York Style Cheesecake Cream Sauce 1kg'],
        ['sup' => 4, 'item' => 12, 'sku' => 'BASE-HORCHATA-1L',   'desc' => 'Spiced Horchata Traditional Rice Extract 1L'],
        ['sup' => 5, 'item' => 13, 'sku' => 'KYOTO-MATCHA-CER',   'desc' => 'Kyoto Uji Ceremonial Grade Matcha 1kg'],
        ['sup' => 5, 'item' => 14, 'sku' => 'KYOTO-HOJICHA-01',   'desc' => 'Roasted Shizuoka Hojicha Fine Powder 1kg'],
        ['sup' => 6, 'item' => 15, 'sku' => 'BAKE-CROISSANT-FR',  'desc' => 'Fresh Butter Croissant Tray 10s'],
        ['sup' => 6, 'item' => 16, 'sku' => 'BAKE-PAIN-CHOCO',    'desc' => 'French Pain Au Chocolat Pastry Tray 10s'],
        ['sup' => 6, 'item' => 17, 'sku' => 'BAKE-CINN-ROLL',     'desc' => 'Sticky Glazed Cinnamon Rolls 10s'],
        ['sup' => 7, 'item' => 18, 'sku' => 'CUP-DUDE-8OZ',       'desc' => 'Asterisko Embossed Paper Cups 8oz (50s)'],
        ['sup' => 7, 'item' => 19, 'sku' => 'CUP-PARE-12OZ',      'desc' => 'Asterisko Embossed Paper Cups 12oz (50s)'],
        ['sup' => 7, 'item' => 20, 'sku' => 'CUP-CHONG-16OZ',     'desc' => 'Asterisko Embossed Paper Cups 16oz (50s)'],
        ['sup' => 7, 'item' => 21, 'sku' => 'LID-SIPPER-UNI',     'desc' => 'Universal Eco Sipper Lids 90mm (100s)']
    ];

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo->exec("TRUNCATE TABLE supplier_mappings;");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    $insMap = $pdo->prepare("
        INSERT INTO supplier_mappings (supplier_id, internal_item_id, supplier_item_id, supplier_item_name)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($supplierMappingsData as $m) {
        $insMap->execute([$m['sup'], $m['item'], $m['sku'], $m['desc']]);
    }
    echo " [DONE] Seeded " . count($supplierMappingsData) . " invoice OCR mappings.\n";

    // ---------------------------------------------------------
    // 6. SEED EXACT MENU ITEMS (astemenu.jpg) WITH RECIPE DEDUCTIONS
    // ---------------------------------------------------------
    echo "6. Seeding Asterisko Menu Items & Automated Recipe Deductions...";

    // IDs reference:
    // 1: Beans, 2: Fresh Milk, 3: Oat Milk, 4: Condensed Milk, 5: Coco Water, 6: Orange Puree, 7: Caramel, 8: White Choco,
    // 9: Lavender, 10: Strawberry, 11: Cheesecake, 12: Horchata, 13: Matcha, 14: Hojicha, 15: Croissant, 16: Pain Choco, 17: Cinn Roll,
    // 18: Cup 8oz, 19: Cup 12oz, 20: Cup 16oz, 21: Sipper Lid
    $c8  = 18; $c12 = 19; $c16 = 20; $lid = 21;

    $menuData = [
        // AMERICANO SERIES
        ['name' => 'Americano (Dude)', 'cat' => 'Americano Series', 'price' => 140.00, 'desc' => 'Double espresso balanced with hot or chilled filtered water (8 oz)', 'icon' => 'coffee',
         'ings' => [[1, 0.018, 'kg'], [$c8, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Americano (Pare)', 'cat' => 'Americano Series', 'price' => 150.00, 'desc' => 'Rich double espresso balanced with hot or chilled filtered water (12 oz)', 'icon' => 'coffee',
         'ings' => [[1, 0.020, 'kg'], [$c12, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Americano (Chong)', 'cat' => 'Americano Series', 'price' => 165.00, 'desc' => 'Extra-shot espresso balanced with hot or chilled filtered water (16 oz)', 'icon' => 'coffee',
         'ings' => [[1, 0.024, 'kg'], [$c16, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],

        ['name' => 'Coco Americano (Dude)', 'cat' => 'Americano Series', 'price' => 165.00, 'desc' => 'Refreshing coconut water topped with espresso (8 oz)', 'icon' => 'ice-coffee',
         'ings' => [[1, 0.018, 'kg'], [5, 0.15, 'L'], [$c8, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Coco Americano (Pare)', 'cat' => 'Americano Series', 'price' => 175.00, 'desc' => 'Refreshing coconut water topped with espresso (12 oz)', 'icon' => 'ice-coffee',
         'ings' => [[1, 0.020, 'kg'], [5, 0.20, 'L'], [$c12, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Coco Americano (Chong)', 'cat' => 'Americano Series', 'price' => 185.00, 'desc' => 'Refreshing coconut water topped with espresso (16 oz)', 'icon' => 'ice-coffee',
         'ings' => [[1, 0.024, 'kg'], [5, 0.25, 'L'], [$c16, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],

        ['name' => 'Orange Americano (Dude)', 'cat' => 'Americano Series', 'price' => 165.00, 'desc' => 'Tangy orange puree layered with fresh espresso (8 oz)', 'icon' => 'ice-coffee',
         'ings' => [[1, 0.018, 'kg'], [6, 0.03, 'L'], [$c8, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Orange Americano (Pare)', 'cat' => 'Americano Series', 'price' => 175.00, 'desc' => 'Tangy orange puree layered with fresh espresso (12 oz)', 'icon' => 'ice-coffee',
         'ings' => [[1, 0.020, 'kg'], [6, 0.04, 'L'], [$c12, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Orange Americano (Chong)', 'cat' => 'Americano Series', 'price' => 185.00, 'desc' => 'Tangy orange puree layered with fresh espresso (16 oz)', 'icon' => 'ice-coffee',
         'ings' => [[1, 0.024, 'kg'], [6, 0.05, 'L'], [$c16, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],

        // CLASSICS
        ['name' => 'Latte (Dude)', 'cat' => 'Classics', 'price' => 160.00, 'desc' => 'Espresso with silky textured milk (8 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.018, 'kg'], [2, 0.16, 'L'], [$c8, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Latte (Pare)', 'cat' => 'Classics', 'price' => 170.00, 'desc' => 'Espresso with silky textured milk (12 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.020, 'kg'], [2, 0.22, 'L'], [$c12, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Latte (Chong)', 'cat' => 'Classics', 'price' => 180.00, 'desc' => 'Espresso with silky textured milk (16 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.024, 'kg'], [2, 0.28, 'L'], [$c16, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],

        ['name' => 'Caramel Latte (Dude)', 'cat' => 'Classics', 'price' => 175.00, 'desc' => 'Espresso & silky milk infused with caramel (8 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.018, 'kg'], [2, 0.16, 'L'], [7, 0.02, 'L'], [$c8, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Caramel Latte (Pare)', 'cat' => 'Classics', 'price' => 185.00, 'desc' => 'Espresso & silky milk infused with caramel (12 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.020, 'kg'], [2, 0.22, 'L'], [7, 0.03, 'L'], [$c12, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Caramel Latte (Chong)', 'cat' => 'Classics', 'price' => 195.00, 'desc' => 'Espresso & silky milk infused with caramel (16 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.024, 'kg'], [2, 0.28, 'L'], [7, 0.04, 'L'], [$c16, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],

        ['name' => 'White Mocha (Dude)', 'cat' => 'Classics', 'price' => 195.00, 'desc' => 'Decadent white chocolate sauce blended with espresso & milk (8 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.018, 'kg'], [2, 0.16, 'L'], [8, 0.025, 'kg'], [$c8, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'White Mocha (Pare)', 'cat' => 'Classics', 'price' => 205.00, 'desc' => 'Decadent white chocolate sauce blended with espresso & milk (12 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.020, 'kg'], [2, 0.22, 'L'], [8, 0.035, 'kg'], [$c12, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'White Mocha (Chong)', 'cat' => 'Classics', 'price' => 215.00, 'desc' => 'Decadent white chocolate sauce blended with espresso & milk (16 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.024, 'kg'], [2, 0.28, 'L'], [8, 0.045, 'kg'], [$c16, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],

        ['name' => 'Spanish Latte (Dude)', 'cat' => 'Classics', 'price' => 170.00, 'desc' => 'Sweetened condensed milk blend with rich espresso (8 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.018, 'kg'], [2, 0.14, 'L'], [4, 0.025, 'kg'], [$c8, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Spanish Latte (Pare)', 'cat' => 'Classics', 'price' => 180.00, 'desc' => 'Sweetened condensed milk blend with rich espresso (12 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.020, 'kg'], [2, 0.20, 'L'], [4, 0.035, 'kg'], [$c12, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Spanish Latte (Chong)', 'cat' => 'Classics', 'price' => 190.00, 'desc' => 'Sweetened condensed milk blend with rich espresso (16 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.024, 'kg'], [2, 0.25, 'L'], [4, 0.045, 'kg'], [$c16, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],

        // SIGNATURE LATTE SERIES
        ['name' => 'Lavender Latte (Dude)', 'cat' => 'Signature Lattes', 'price' => 170.00, 'desc' => 'Floral lavender tea, espresso & milk (8 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.018, 'kg'], [2, 0.15, 'L'], [9, 0.02, 'L'], [$c8, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Lavender Latte (Pare)', 'cat' => 'Signature Lattes', 'price' => 180.00, 'desc' => 'Floral lavender tea, espresso & milk (12 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.020, 'kg'], [2, 0.20, 'L'], [9, 0.03, 'L'], [$c12, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Lavender Latte (Chong)', 'cat' => 'Signature Lattes', 'price' => 190.00, 'desc' => 'Floral lavender tea, espresso & milk (16 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.024, 'kg'], [2, 0.25, 'L'], [9, 0.04, 'L'], [$c16, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],

        ['name' => 'Meiji Latte (Dude)', 'cat' => 'Signature Lattes', 'price' => 190.00, 'desc' => 'Japanese strawberry, espresso & milk (8 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.018, 'kg'], [2, 0.15, 'L'], [10, 0.025, 'L'], [$c8, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Meiji Latte (Pare)', 'cat' => 'Signature Lattes', 'price' => 200.00, 'desc' => 'Japanese strawberry, espresso & milk (12 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.020, 'kg'], [2, 0.20, 'L'], [10, 0.035, 'L'], [$c12, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Meiji Latte (Chong)', 'cat' => 'Signature Lattes', 'price' => 210.00, 'desc' => 'Japanese strawberry, espresso & milk (16 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.024, 'kg'], [2, 0.25, 'L'], [10, 0.045, 'L'], [$c16, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],

        ['name' => 'Cinnamon Bun Latte (Dude)', 'cat' => 'Signature Lattes', 'price' => 195.00, 'desc' => 'White choco, cinnamon spice, espresso & milk (8 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.018, 'kg'], [2, 0.15, 'L'], [8, 0.025, 'kg'], [$c8, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Cinnamon Bun Latte (Pare)', 'cat' => 'Signature Lattes', 'price' => 205.00, 'desc' => 'White choco, cinnamon spice, espresso & milk (12 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.020, 'kg'], [2, 0.20, 'L'], [8, 0.035, 'kg'], [$c12, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Cinnamon Bun Latte (Chong)', 'cat' => 'Signature Lattes', 'price' => 215.00, 'desc' => 'White choco, cinnamon spice, espresso & milk (16 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.024, 'kg'], [2, 0.25, 'L'], [8, 0.045, 'kg'], [$c16, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],

        ['name' => 'Sheeshcake Latte (Dude)', 'cat' => 'Signature Lattes', 'price' => 175.00, 'desc' => 'Cheesecake infusion, espresso & milk (8 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.018, 'kg'], [2, 0.15, 'L'], [11, 0.025, 'kg'], [$c8, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Sheeshcake Latte (Pare)', 'cat' => 'Signature Lattes', 'price' => 185.00, 'desc' => 'Cheesecake infusion, espresso & milk (12 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.020, 'kg'], [2, 0.20, 'L'], [11, 0.035, 'kg'], [$c12, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Sheeshcake Latte (Chong)', 'cat' => 'Signature Lattes', 'price' => 195.00, 'desc' => 'Cheesecake infusion, espresso & milk (16 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.024, 'kg'], [2, 0.25, 'L'], [11, 0.045, 'kg'], [$c16, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],

        ['name' => 'Dirty Horchata (Dude)', 'cat' => 'Signature Lattes', 'price' => 180.00, 'desc' => 'Spiced horchata base topped with espresso (8 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.018, 'kg'], [12, 0.15, 'L'], [$c8, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Dirty Horchata (Pare)', 'cat' => 'Signature Lattes', 'price' => 190.00, 'desc' => 'Spiced horchata base topped with espresso (12 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.020, 'kg'], [12, 0.20, 'L'], [$c12, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Dirty Horchata (Chong)', 'cat' => 'Signature Lattes', 'price' => 200.00, 'desc' => 'Spiced horchata base topped with espresso (16 oz)', 'icon' => 'latte',
         'ings' => [[1, 0.024, 'kg'], [12, 0.25, 'L'], [$c16, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],

        // NON-COFFEE SERIES
        ['name' => 'Oatchata (Dude)', 'cat' => 'Non-Coffee', 'price' => 160.00, 'desc' => 'Creamy oat milk horchata with cinnamon (8 oz)', 'icon' => 'ice-coffee',
         'ings' => [[3, 0.16, 'L'], [12, 0.05, 'L'], [$c8, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Oatchata (Pare)', 'cat' => 'Non-Coffee', 'price' => 170.00, 'desc' => 'Creamy oat milk horchata with cinnamon (12 oz)', 'icon' => 'ice-coffee',
         'ings' => [[3, 0.22, 'L'], [12, 0.07, 'L'], [$c12, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Oatchata (Chong)', 'cat' => 'Non-Coffee', 'price' => 180.00, 'desc' => 'Creamy oat milk horchata with cinnamon (16 oz)', 'icon' => 'ice-coffee',
         'ings' => [[3, 0.28, 'L'], [12, 0.09, 'L'], [$c16, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],

        ['name' => 'White Chocolate (Dude)', 'cat' => 'Non-Coffee', 'price' => 170.00, 'desc' => 'Steamed or iced velvety white chocolate with fresh milk (8 oz)', 'icon' => 'latte',
         'ings' => [[2, 0.16, 'L'], [8, 0.03, 'kg'], [$c8, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'White Chocolate (Pare)', 'cat' => 'Non-Coffee', 'price' => 180.00, 'desc' => 'Steamed or iced velvety white chocolate with fresh milk (12 oz)', 'icon' => 'latte',
         'ings' => [[2, 0.22, 'L'], [8, 0.04, 'kg'], [$c12, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'White Chocolate (Chong)', 'cat' => 'Non-Coffee', 'price' => 190.00, 'desc' => 'Steamed or iced velvety white chocolate with fresh milk (16 oz)', 'icon' => 'latte',
         'ings' => [[2, 0.28, 'L'], [8, 0.05, 'kg'], [$c16, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],

        ['name' => 'Strawberry Cheesecake (Dude)', 'cat' => 'Non-Coffee', 'price' => 185.00, 'desc' => 'Strawberry puree layered with creamy cheesecake milk (8 oz)', 'icon' => 'ice-coffee',
         'ings' => [[2, 0.15, 'L'], [10, 0.03, 'L'], [11, 0.025, 'kg'], [$c8, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Strawberry Cheesecake (Pare)', 'cat' => 'Non-Coffee', 'price' => 195.00, 'desc' => 'Strawberry puree layered with creamy cheesecake milk (12 oz)', 'icon' => 'ice-coffee',
         'ings' => [[2, 0.20, 'L'], [10, 0.04, 'L'], [11, 0.035, 'kg'], [$c12, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Strawberry Cheesecake (Chong)', 'cat' => 'Non-Coffee', 'price' => 205.00, 'desc' => 'Strawberry puree layered with creamy cheesecake milk (16 oz)', 'icon' => 'ice-coffee',
         'ings' => [[2, 0.25, 'L'], [10, 0.05, 'L'], [11, 0.045, 'kg'], [$c16, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],

        // MATCHA & HOJICHA SERIES
        ['name' => 'Matcha (Dude)', 'cat' => 'Matcha & Hojicha', 'price' => 185.00, 'desc' => 'Uji ceremonial green tea matcha with textured milk (8 oz)', 'icon' => 'latte',
         'ings' => [[13, 0.005, 'kg'], [2, 0.16, 'L'], [$c8, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Matcha (Pare)', 'cat' => 'Matcha & Hojicha', 'price' => 195.00, 'desc' => 'Uji ceremonial green tea matcha with textured milk (12 oz)', 'icon' => 'latte',
         'ings' => [[13, 0.007, 'kg'], [2, 0.22, 'L'], [$c12, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],

        ['name' => 'Strawberry Matcha (Dude)', 'cat' => 'Matcha & Hojicha', 'price' => 210.00, 'desc' => 'Layered strawberry puree, milk, and rich green tea matcha (8 oz)', 'icon' => 'ice-coffee',
         'ings' => [[13, 0.005, 'kg'], [2, 0.14, 'L'], [10, 0.03, 'L'], [$c8, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Strawberry Matcha (Pare)', 'cat' => 'Matcha & Hojicha', 'price' => 220.00, 'desc' => 'Layered strawberry puree, milk, and rich green tea matcha (12 oz)', 'icon' => 'ice-coffee',
         'ings' => [[13, 0.007, 'kg'], [2, 0.20, 'L'], [10, 0.04, 'L'], [$c12, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],

        ['name' => 'Hojicha (Dude)', 'cat' => 'Matcha & Hojicha', 'price' => 185.00, 'desc' => 'Roasted Japanese green tea latte with warm nutty aroma (8 oz)', 'icon' => 'latte',
         'ings' => [[14, 0.005, 'kg'], [2, 0.16, 'L'], [$c8, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],
        ['name' => 'Hojicha (Pare)', 'cat' => 'Matcha & Hojicha', 'price' => 195.00, 'desc' => 'Roasted Japanese green tea latte with warm nutty aroma (12 oz)', 'icon' => 'latte',
         'ings' => [[14, 0.007, 'kg'], [2, 0.22, 'L'], [$c12, 1.0, 'pcs'], [$lid, 1.0, 'pcs']]],

        // PASTRIES
        ['name' => 'Croissant', 'cat' => 'Pastries', 'price' => 120.00, 'desc' => 'Flaky, butter-layered golden French croissant baked daily', 'icon' => 'coffee',
         'ings' => [[15, 1.0, 'pcs']]],
        ['name' => 'Pain Au Chocolat', 'cat' => 'Pastries', 'price' => 135.00, 'desc' => 'Traditional French pastry filled with rich dark chocolate batons', 'icon' => 'coffee',
         'ings' => [[16, 1.0, 'pcs']]],
        ['name' => 'Sticky Cinnamon Roll', 'cat' => 'Pastries', 'price' => 130.00, 'desc' => 'Warm spiced roll drizzled with sweet sticky glaze', 'icon' => 'coffee',
         'ings' => [[17, 1.0, 'pcs']]],

        // ADD-ONS
        ['name' => 'Sub-Oat Milk (Add-on)', 'cat' => 'Add-ons', 'price' => 20.00, 'desc' => 'Substitute dairy milk with creamy barista oat milk', 'icon' => 'coffee',
         'ings' => [[3, 0.18, 'L']]],
        ['name' => 'Extra Espresso Shot (Add-on)', 'cat' => 'Add-ons', 'price' => 20.00, 'desc' => 'Additional shot of rich houseblend espresso', 'icon' => 'coffee',
         'ings' => [[1, 0.010, 'kg']]]
    ];

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo->exec("TRUNCATE TABLE menu_item_ingredients;");
    $pdo->exec("TRUNCATE TABLE menu_items;");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    $insMenu = $pdo->prepare("INSERT INTO menu_items (id, name, category, price, description, icon, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
    $insIng = $pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id, stock_item_id, quantity_required, unit) VALUES (?, ?, ?, ?)");

    $mId = 1;
    foreach ($menuData as $m) {
        $insMenu->execute([$mId, $m['name'], $m['cat'], $m['price'], $m['desc'], $m['icon']]);
        foreach ($m['ings'] as $ing) {
            $insIng->execute([$mId, $ing[0], $ing[1], $ing[2]]);
        }
        $mId++;
    }
    echo " [DONE] Seeded " . count($menuData) . " menu drinks & pastries with recipes.\n";

    // ---------------------------------------------------------
    // 7. INVALIDATE CACHE TO REFRESH IMMEDIATELY ON TABLET
    // ---------------------------------------------------------
    echo "7. Invalidating catalog cache meta...";
    bumpCatalogVersion($pdo);
    echo " [DONE] Catalog cache bumped.\n\n";

    echo "========================================================\n";
    echo " SUCCESS: Asterisko Menu, Ingredients & Suppliers Synchronized!\n";
    echo "========================================================\n";

} catch (Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
