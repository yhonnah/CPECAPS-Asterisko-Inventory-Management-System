<?php
require_once __DIR__ . '/../config/db.php';

try {
    echo "Running Order & Deduction schema migration...\n";

    // 1. Menu Items Table
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
    echo "✓ menu_items table verified\n";

    // 2. Menu Item Ingredients Table
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
    echo "✓ menu_item_ingredients table verified\n";

    // 3. Coffee Orders Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS coffee_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_number VARCHAR(50) NOT NULL,
            order_type ENUM('coffee_drink', 'manual_deduction') NOT NULL DEFAULT 'coffee_drink',
            total_amount DECIMAL(10,2) NOT NULL DEFAULT '0.00',
            items_count INT NOT NULL DEFAULT 1,
            notes TEXT DEFAULT NULL,
            served_by VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✓ coffee_orders table verified\n";

    // 4. Coffee Order Items Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS coffee_order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            menu_item_id INT DEFAULT NULL,
            item_name VARCHAR(100) NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            price DECIMAL(10,2) NOT NULL DEFAULT '0.00',
            subtotal DECIMAL(10,2) NOT NULL DEFAULT '0.00',
            deductions_json TEXT DEFAULT NULL,
            KEY idx_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✓ coffee_order_items table verified\n";

    // Detect existing stock items to link recipes to
    $beansItem = $pdo->query("SELECT id, unit FROM stock_items WHERE category = 'Beans' OR NAME LIKE '%Bean%' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $milkItem = $pdo->query("SELECT id, unit FROM stock_items WHERE category = 'Dairy' OR NAME LIKE '%Milk%' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    $beansId = $beansItem ? (int)$beansItem['id'] : 1;
    $milkId = $milkItem ? (int)$milkItem['id'] : 2;

    // Check if menu_items is empty, if so, seed standard coffee menu
    $menuCount = (int)$pdo->query("SELECT count(*) FROM menu_items")->fetchColumn();
    if ($menuCount === 0) {
        $defaultDrinks = [
            [
                'name' => 'Espresso',
                'category' => 'Espresso',
                'price' => 120.00,
                'description' => 'Double shot rich espresso with thick crema',
                'icon' => 'coffee',
                'ingredients' => [
                    ['stock_id' => $beansId, 'qty' => 0.018, 'unit' => 'kg']
                ]
            ],
            [
                'name' => 'Americano',
                'category' => 'Espresso',
                'price' => 135.00,
                'description' => 'Double espresso balanced with hot water',
                'icon' => 'coffee',
                'ingredients' => [
                    ['stock_id' => $beansId, 'qty' => 0.018, 'unit' => 'kg']
                ]
            ],
            [
                'name' => 'Iced Americano',
                'category' => 'Iced Drinks',
                'price' => 145.00,
                'description' => 'Crisp chilled espresso over filtered water and ice',
                'icon' => 'ice-coffee',
                'ingredients' => [
                    ['stock_id' => $beansId, 'qty' => 0.018, 'unit' => 'kg']
                ]
            ],
            [
                'name' => 'Caffè Latte',
                'category' => 'Milk & Espresso',
                'price' => 165.00,
                'description' => 'Espresso with silky textured whole milk',
                'icon' => 'latte',
                'ingredients' => [
                    ['stock_id' => $beansId, 'qty' => 0.018, 'unit' => 'kg'],
                    ['stock_id' => $milkId, 'qty' => 0.22, 'unit' => 'L']
                ]
            ],
            [
                'name' => 'Iced Caffè Latte',
                'category' => 'Iced Drinks',
                'price' => 175.00,
                'description' => 'Chilled fresh milk and espresso over ice',
                'icon' => 'ice-coffee',
                'ingredients' => [
                    ['stock_id' => $beansId, 'qty' => 0.018, 'unit' => 'kg'],
                    ['stock_id' => $milkId, 'qty' => 0.24, 'unit' => 'L']
                ]
            ],
            [
                'name' => 'Cappuccino',
                'category' => 'Milk & Espresso',
                'price' => 165.00,
                'description' => 'Balanced espresso with rich dense milk foam',
                'icon' => 'cappuccino',
                'ingredients' => [
                    ['stock_id' => $beansId, 'qty' => 0.018, 'unit' => 'kg'],
                    ['stock_id' => $milkId, 'qty' => 0.18, 'unit' => 'L']
                ]
            ],
            [
                'name' => 'Flat White',
                'category' => 'Milk & Espresso',
                'price' => 175.00,
                'description' => 'Double ristretto with micro-textured velvety milk',
                'icon' => 'latte',
                'ingredients' => [
                    ['stock_id' => $beansId, 'qty' => 0.020, 'unit' => 'kg'],
                    ['stock_id' => $milkId, 'qty' => 0.16, 'unit' => 'L']
                ]
            ],
            [
                'name' => 'Spanish Latte',
                'category' => 'Specialty',
                'price' => 185.00,
                'description' => 'Sweetened milk blend topped with double espresso',
                'icon' => 'latte',
                'ingredients' => [
                    ['stock_id' => $beansId, 'qty' => 0.018, 'unit' => 'kg'],
                    ['stock_id' => $milkId, 'qty' => 0.20, 'unit' => 'L']
                ]
            ],
            [
                'name' => 'Cold Brew',
                'category' => 'Cold Brews',
                'price' => 155.00,
                'description' => 'Slow steep 18-hour single origin cold coffee',
                'icon' => 'cold-brew',
                'ingredients' => [
                    ['stock_id' => $beansId, 'qty' => 0.025, 'unit' => 'kg']
                ]
            ],
            [
                'name' => 'Cortado',
                'category' => 'Espresso',
                'price' => 140.00,
                'description' => 'Equal parts intense espresso and warm milk',
                'icon' => 'coffee',
                'ingredients' => [
                    ['stock_id' => $beansId, 'qty' => 0.018, 'unit' => 'kg'],
                    ['stock_id' => $milkId, 'qty' => 0.08, 'unit' => 'L']
                ]
            ]
        ];

        $insMenu = $pdo->prepare("INSERT INTO menu_items (name, category, price, description, icon) VALUES (?, ?, ?, ?, ?)");
        $insIng = $pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id, stock_item_id, quantity_required, unit) VALUES (?, ?, ?, ?)");

        foreach ($defaultDrinks as $drink) {
            $insMenu->execute([
                $drink['name'],
                $drink['category'],
                $drink['price'],
                $drink['description'],
                $drink['icon']
            ]);
            $menuId = (int)$pdo->lastInsertId();

            foreach ($drink['ingredients'] as $ing) {
                $insIng->execute([
                    $menuId,
                    $ing['stock_id'],
                    $ing['qty'],
                    $ing['unit']
                ]);
            }
        }
        echo "✓ Seeded 10 default coffee menu drinks with recipe ingredient mappings\n";
    } else {
        echo "✓ menu_items already contains {$menuCount} entries\n";
    }

    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
