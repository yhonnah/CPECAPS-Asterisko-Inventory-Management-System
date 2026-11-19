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

    // Run the full Asterisko Menu Updater to ensure complete menu consistency
    require_once __DIR__ . '/update_menu_and_purge_audit.php';

} catch (Exception $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
