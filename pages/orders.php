<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/authguard.php';
requireLogin();

$username = $_SESSION['username'] ?? 'User';
$role = $_SESSION['role'] ?? 'Staff';
$message = '';
$messageType = '';

// Handle POST actions: Quick Order, Batch Order Ticket, or Manual Deduction
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    validateCsrfToken();
    $action = $_POST['action'] ?? '';

    // ---------------------------------------------------------
    // 1. QUICK 1-CLICK COFFEE ORDER (SINGLE DRINK)
    // ---------------------------------------------------------
    if ($action === 'quick_order') {
        $menu_item_id = (int)($_POST['menu_item_id'] ?? 0);
        $order_qty = max(1, (int)($_POST['quantity'] ?? 1));

        try {
            $pdo->beginTransaction();

            $stmtDrink = $pdo->prepare("SELECT * FROM menu_items WHERE id = ? AND is_active = 1");
            $stmtDrink->execute([$menu_item_id]);
            $drink = $stmtDrink->fetch(PDO::FETCH_ASSOC);

            if (!$drink) {
                throw new Exception("Selected beverage is not available or inactive.");
            }

            // Fetch recipe ingredients
            $stmtIng = $pdo->prepare("
                SELECT mii.*, si.NAME AS stock_item_name, si.quantity AS current_stock, si.unit AS stock_unit
                FROM menu_item_ingredients mii
                JOIN stock_items si ON si.id = mii.stock_item_id
                WHERE mii.menu_item_id = ?
            ");
            $stmtIng->execute([$menu_item_id]);
            $ingredients = $stmtIng->fetchAll(PDO::FETCH_ASSOC);

            if (empty($ingredients)) {
                throw new Exception("No recipe ingredients defined for this drink.");
            }

            // Lock and verify each stock item
            $deductionSummaries = [];
            foreach ($ingredients as $ing) {
                $totalNeeded = round((float)$ing['quantity_required'] * $order_qty, 4);

                $stmtLock = $pdo->prepare("SELECT id, NAME, quantity, unit FROM stock_items WHERE id = ? FOR UPDATE");
                $stmtLock->execute([$ing['stock_item_id']]);
                $stockItem = $stmtLock->fetch(PDO::FETCH_ASSOC);

                if (!$stockItem) {
                    throw new Exception("Stock item #{$ing['stock_item_id']} not found.");
                }

                $prevStock = (float)$stockItem['quantity'];
                if ($prevStock < $totalNeeded) {
                    throw new Exception("Insufficient stock for '{$stockItem['NAME']}'. Needed: {$totalNeeded} {$stockItem['unit']}, Available: {$prevStock} {$stockItem['unit']}.");
                }

                $newStock = max(0, round($prevStock - $totalNeeded, 4));

                // Deduct stock
                $updStock = $pdo->prepare("UPDATE stock_items SET quantity = ? WHERE id = ?");
                $updStock->execute([$newStock, $ing['stock_item_id']]);

                // Record Audit Log
                $orderNum = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
                $auditReason = "Coffee Order {$orderNum}: {$order_qty}x {$drink['name']} (-{$totalNeeded} {$stockItem['unit']})";
                $stmtAudit = $pdo->prepare("
                    INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
                    VALUES (?, ?, ?, 'order_sale', ?, ?)
                ");
                $stmtAudit->execute([$ing['stock_item_id'], $prevStock, $newStock, $auditReason, $username]);

                $deductionSummaries[] = "-{$totalNeeded} {$stockItem['unit']} {$stockItem['NAME']}";
            }

            // Create Coffee Order Header
            $orderTotal = round((float)$drink['price'] * $order_qty, 2);
            $stmtOrder = $pdo->prepare("
                INSERT INTO coffee_orders (order_number, order_type, total_amount, items_count, notes, served_by)
                VALUES (?, 'coffee_drink', ?, ?, ?, ?)
            ");
            $orderNotes = "Quick Order: {$order_qty}x {$drink['name']}";
            $stmtOrder->execute([$orderNum, $orderTotal, $order_qty, $orderNotes, $username]);
            $orderId = (int)$pdo->lastInsertId();

            // Create Order Item
            $stmtOrderItem = $pdo->prepare("
                INSERT INTO coffee_order_items (order_id, menu_item_id, item_name, quantity, price, subtotal, deductions_json)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtOrderItem->execute([
                $orderId,
                $menu_item_id,
                $drink['name'],
                $order_qty,
                $drink['price'],
                $orderTotal,
                json_encode($deductionSummaries)
            ]);

            // Sync with sales_records for financial reporting
            $stmtSales = $pdo->prepare("
                INSERT INTO sales_records (sale_date, gross_sales, source, notes, recorded_by)
                VALUES (CURDATE(), ?, 'Coffee Orders POS', ?, ?)
            ");
            $stmtSales->execute([$orderTotal, "Order #{$orderNum} ({$order_qty}x {$drink['name']})", $username]);

            $pdo->commit();
            $message = "Order #{$orderNum} completed! Brewed {$order_qty}x {$drink['name']}. Deducted: " . implode(', ', $deductionSummaries) . ".";
            $messageType = "success";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "Order failed: " . $e->getMessage();
            $messageType = "error";
        }
    }

    // ---------------------------------------------------------
    // 2. COMPLETE MULTI-ITEM ORDER TICKET
    // ---------------------------------------------------------
    elseif ($action === 'complete_ticket') {
        $ticketJson = $_POST['ticket_data'] ?? '[]';
        $ticketItems = json_decode($ticketJson, true);

        if (!is_array($ticketItems) || empty($ticketItems)) {
            $message = "Your order ticket is empty. Please add at least one coffee beverage.";
            $messageType = "error";
        } else {
            try {
                $pdo->beginTransaction();

                $orderNum = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
                $orderTotal = 0;
                $totalCups = 0;
                $ingredientTotals = []; // [stock_item_id => ['name' => ..., 'needed' => ..., 'unit' => ...]]
                $orderLines = [];

                foreach ($ticketItems as $tItem) {
                    $menuId = (int)($tItem['id'] ?? 0);
                    $qty = max(1, (int)($tItem['qty'] ?? 1));

                    $stmtDrink = $pdo->prepare("SELECT * FROM menu_items WHERE id = ? AND is_active = 1");
                    $stmtDrink->execute([$menuId]);
                    $drink = $stmtDrink->fetch(PDO::FETCH_ASSOC);

                    if (!$drink) {
                        throw new Exception("Drink item ID #{$menuId} is not available.");
                    }

                    $subtotal = round((float)$drink['price'] * $qty, 2);
                    $orderTotal += $subtotal;
                    $totalCups += $qty;

                    // Ingredients
                    $stmtIng = $pdo->prepare("SELECT * FROM menu_item_ingredients WHERE menu_item_id = ?");
                    $stmtIng->execute([$menuId]);
                    $ings = $stmtIng->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($ings as $ing) {
                        $sId = (int)$ing['stock_item_id'];
                        $req = round((float)$ing['quantity_required'] * $qty, 4);

                        if (!isset($ingredientTotals[$sId])) {
                            $ingredientTotals[$sId] = [
                                'needed' => 0,
                                'unit' => $ing['unit']
                            ];
                        }
                        $ingredientTotals[$sId]['needed'] += $req;
                    }

                    $orderLines[] = [
                        'menu_item_id' => $menuId,
                        'name' => $drink['name'],
                        'quantity' => $qty,
                        'price' => (float)$drink['price'],
                        'subtotal' => $subtotal
                    ];
                }

                // Lock and deduct stock items
                $deductionSummaries = [];
                foreach ($ingredientTotals as $stockId => $data) {
                    $needed = $data['needed'];

                    $stmtLock = $pdo->prepare("SELECT id, NAME, quantity, unit FROM stock_items WHERE id = ? FOR UPDATE");
                    $stmtLock->execute([$stockId]);
                    $stockItem = $stmtLock->fetch(PDO::FETCH_ASSOC);

                    if (!$stockItem) {
                        throw new Exception("Stock item #{$stockId} not found.");
                    }

                    $prevStock = (float)$stockItem['quantity'];
                    if ($prevStock < $needed) {
                        throw new Exception("Insufficient stock for '{$stockItem['NAME']}'. Needed: {$needed} {$stockItem['unit']}, Available: {$prevStock} {$stockItem['unit']}.");
                    }

                    $newStock = max(0, round($prevStock - $needed, 4));

                    $updStock = $pdo->prepare("UPDATE stock_items SET quantity = ? WHERE id = ?");
                    $updStock->execute([$newStock, $stockId]);

                    $auditReason = "Order Ticket {$orderNum}: {$totalCups} cups (-{$needed} {$stockItem['unit']})";
                    $stmtAudit = $pdo->prepare("
                        INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
                        VALUES (?, ?, ?, 'order_sale', ?, ?)
                    ");
                    $stmtAudit->execute([$stockId, $prevStock, $newStock, $auditReason, $username]);

                    $deductionSummaries[] = "-{$needed} {$stockItem['unit']} {$stockItem['NAME']}";
                }

                // Insert Coffee Order
                $stmtOrder = $pdo->prepare("
                    INSERT INTO coffee_orders (order_number, order_type, total_amount, items_count, notes, served_by)
                    VALUES (?, 'coffee_drink', ?, ?, ?, ?)
                ");
                $ticketNotes = count($orderLines) . " items ticket (" . $totalCups . " cups)";
                $stmtOrder->execute([$orderNum, $orderTotal, $totalCups, $ticketNotes, $username]);
                $orderId = (int)$pdo->lastInsertId();

                // Insert items
                $stmtOrderItem = $pdo->prepare("
                    INSERT INTO coffee_order_items (order_id, menu_item_id, item_name, quantity, price, subtotal, deductions_json)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($orderLines as $line) {
                    $stmtOrderItem->execute([
                        $orderId,
                        $line['menu_item_id'],
                        $line['name'],
                        $line['quantity'],
                        $line['price'],
                        $line['subtotal'],
                        json_encode($deductionSummaries)
                    ]);
                }

                // Insert sales record
                $stmtSales = $pdo->prepare("
                    INSERT INTO sales_records (sale_date, gross_sales, source, notes, recorded_by)
                    VALUES (CURDATE(), ?, 'Coffee Orders POS Ticket', ?, ?)
                ");
                $stmtSales->execute([$orderTotal, "Order Ticket #{$orderNum}", $username]);

                $pdo->commit();
                $message = "Order Ticket #{$orderNum} fulfilled! Brewed {$totalCups} total cups. Deducted: " . implode(', ', $deductionSummaries) . ".";
                $messageType = "success";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = "Order ticket failed: " . $e->getMessage();
                $messageType = "error";
            }
        }
    }

    // ---------------------------------------------------------
    // 3. MANUAL STOCK DEDUCTION (IN THE SAME TAB)
    // ---------------------------------------------------------
    elseif ($action === 'manual_deduction') {
        $item_id = (int)($_POST['stock_item_id'] ?? 0);
        $deduct_qty = round((float)($_POST['quantity'] ?? 0), 4);
        $reason_preset = trim($_POST['reason_preset'] ?? 'Barista Shift Consumption');
        $reason_custom = trim($_POST['reason_custom'] ?? '');
        $full_reason = $reason_custom !== '' ? "{$reason_preset}: {$reason_custom}" : $reason_preset;

        if ($item_id <= 0 || $deduct_qty <= 0) {
            $message = "Please select a valid inventory item and enter a quantity greater than 0.";
            $messageType = "error";
        } else {
            try {
                $pdo->beginTransaction();

                $stmtLock = $pdo->prepare("SELECT id, NAME, quantity, unit FROM stock_items WHERE id = ? FOR UPDATE");
                $stmtLock->execute([$item_id]);
                $item = $stmtLock->fetch(PDO::FETCH_ASSOC);

                if (!$item) {
                    throw new Exception("Inventory item not found.");
                }

                $prevStock = (float)$item['quantity'];
                if ($prevStock < $deduct_qty) {
                    throw new Exception("Cannot deduct {$deduct_qty} {$item['unit']} from '{$item['NAME']}'. Available stock is only {$prevStock} {$item['unit']}.");
                }

                $newStock = max(0, round($prevStock - $deduct_qty, 4));

                // Deduct stock
                $updStock = $pdo->prepare("UPDATE stock_items SET quantity = ? WHERE id = ?");
                $updStock->execute([$newStock, $item_id]);

                // Create Order record for tracking
                $dedNum = 'MAN-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
                $stmtOrder = $pdo->prepare("
                    INSERT INTO coffee_orders (order_number, order_type, total_amount, items_count, notes, served_by)
                    VALUES (?, 'manual_deduction', 0.00, 1, ?, ?)
                ");
                $notes = "Manual Deduction: {$full_reason} (-{$deduct_qty} {$item['unit']})";
                $stmtOrder->execute([$dedNum, $notes, $username]);
                $orderId = (int)$pdo->lastInsertId();

                // Order Item record
                $stmtOrderItem = $pdo->prepare("
                    INSERT INTO coffee_order_items (order_id, item_name, quantity, price, subtotal, deductions_json)
                    VALUES (?, ?, 1, 0.00, 0.00, ?)
                ");
                $stmtOrderItem->execute([
                    $orderId,
                    $item['NAME'],
                    json_encode(["-{$deduct_qty} {$item['unit']} {$item['NAME']}"])
                ]);

                // Audit log entry
                $stmtAudit = $pdo->prepare("
                    INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
                    VALUES (?, ?, ?, 'manual_deduction', ?, ?)
                ");
                $stmtAudit->execute([$item_id, $prevStock, $newStock, $notes, $username]);

                $pdo->commit();
                $message = "Manual deduction confirmed for '{$item['NAME']}'. Deducted: {$deduct_qty} {$item['unit']}. New balance: {$newStock} {$item['unit']}.";
                $messageType = "success";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = "Manual deduction failed: " . $e->getMessage();
                $messageType = "error";
            }
        }
    }
}

// -------------------------------------------------------------
// FETCH DATA FOR DISPLAY
// -------------------------------------------------------------

// 1. Fetch Coffee Menu Items with their ingredients and current live stock status
$menuItems = $pdo->query("SELECT * FROM menu_items WHERE is_active = 1 ORDER BY category ASC, price ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch ingredients for all menu items
$menuIngredients = [];
$stmtAllIng = $pdo->query("
    SELECT mii.*, si.NAME AS stock_name, si.quantity AS current_stock, si.unit AS stock_unit, si.reorder_point
    FROM menu_item_ingredients mii
    JOIN stock_items si ON si.id = mii.stock_item_id
");
foreach ($stmtAllIng->fetchAll(PDO::FETCH_ASSOC) as $ing) {
    $menuIngredients[$ing['menu_item_id']][] = $ing;
}

// Attach ingredients and calculate stock status for each menu item
$menuCategories = [];
foreach ($menuItems as &$drink) {
    $drink['ingredients'] = $menuIngredients[$drink['id']] ?? [];
    $menuCategories[$drink['category']] = true;

    // Check availability
    $canMake = 999999;
    $isLow = false;
    foreach ($drink['ingredients'] as $ing) {
        $req = (float)$ing['quantity_required'];
        $curr = (float)$ing['current_stock'];
        $reorder = (float)$ing['reorder_point'];

        if ($req > 0) {
            $possible = floor($curr / $req);
            if ($possible < $canMake) {
                $canMake = $possible;
            }
        }
        if ($curr <= $reorder) {
            $isLow = true;
        }
    }
    $drink['cups_available'] = max(0, $canMake);
    $drink['is_low_stock'] = $isLow;
    $drink['is_out_of_stock'] = ($canMake <= 0);
}
unset($drink);
$categoriesList = array_keys($menuCategories);

// 2. Fetch all active stock items for Manual Stock Deduction
$stockItems = $pdo->query("SELECT id, NAME, category, quantity, unit, reorder_point FROM stock_items WHERE is_active = 1 ORDER BY category, NAME")->fetchAll(PDO::FETCH_ASSOC);

// 3. Compute Metrics for Today
$todayDate = date('Y-m-d');

$todayOrdersCount = (int)$pdo->query("
    SELECT COUNT(*) FROM coffee_orders 
    WHERE order_type = 'coffee_drink' AND DATE(created_at) = '{$todayDate}'
")->fetchColumn();

$todayCupsCount = (int)$pdo->query("
    SELECT COALESCE(SUM(items_count), 0) FROM coffee_orders 
    WHERE order_type = 'coffee_drink' AND DATE(created_at) = '{$todayDate}'
")->fetchColumn();

$todaySalesTotal = (float)$pdo->query("
    SELECT COALESCE(SUM(total_amount), 0) FROM coffee_orders 
    WHERE order_type = 'coffee_drink' AND DATE(created_at) = '{$todayDate}'
")->fetchColumn();

$todayManualDeductionsCount = (int)$pdo->query("
    SELECT COUNT(*) FROM coffee_orders 
    WHERE order_type = 'manual_deduction' AND DATE(created_at) = '{$todayDate}'
")->fetchColumn();

// 4. Fetch Recent Orders & Deductions Activity Log
$recentOrders = $pdo->query("
    SELECT co.*, 
           (SELECT GROUP_CONCAT(CONCAT(quantity, 'x ', item_name) SEPARATOR ', ') FROM coffee_order_items WHERE order_id = co.id) AS items_summary
    FROM coffee_orders co
    ORDER BY co.created_at DESC 
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Coffee Orders & Stock Deduction - Asterisko Inventory';
$activePage = 'orders';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/head.php'; ?>
    <style>
        .drink-card {
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .drink-card:hover {
            transform: translateY(-3px);
        }
    </style>
</head>
<body class="bg-[#F4F7FB] dark:bg-[#071526] text-slate-800 dark:text-slate-100 font-sans antialiased min-h-screen">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="lg:pl-64 flex flex-col min-h-screen">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>

        <main class="flex-1 p-4 sm:p-6 lg:p-8 space-y-6 max-w-7xl w-full mx-auto">
            
            <!-- Header Section -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-[#0C2340] dark:text-white tracking-tight flex items-center gap-2.5">
                        <span class="w-9 h-9 rounded-xl bg-[#F28C28]/15 border border-[#F28C28]/30 flex items-center justify-center text-[#F28C28]">
                            ☕
                        </span>
                        Coffee Orders & Stock Deduction
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                        Point-of-sale coffee drink ordering with automatic inventory consumption and manual stock deduction.
                    </p>
                </div>

                <!-- Navigation Tabs Switcher -->
                <div class="inline-flex p-1 bg-slate-200/80 dark:bg-[#0C2340] rounded-xl border border-slate-300/70 dark:border-[#173863] text-xs font-bold">
                    <button id="btn-tab-coffee" onclick="switchMainTab('coffee')" 
                            class="px-4 py-2 rounded-lg bg-[#F28C28] text-white shadow-sm transition-all flex items-center gap-2">
                        <span>☕ Coffee Drink Orders</span>
                    </button>
                    <button id="btn-tab-manual" onclick="switchMainTab('manual')" 
                            class="px-4 py-2 rounded-lg text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white transition-all flex items-center gap-2">
                        <span>⚖️ Manual Stock Deduction</span>
                    </button>
                </div>
            </div>

            <!-- Toast / Alerts -->
            <?php if ($message): ?>
                <div class="p-4 rounded-xl text-sm flex items-center justify-between shadow-sm <?= $messageType === 'success' ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800' : 'bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-300 border border-rose-200 dark:border-rose-800' ?>">
                    <div class="flex items-center gap-2.5">
                        <span class="text-base"><?= $messageType === 'success' ? '✓' : '⚠️' ?></span>
                        <span class="font-medium"><?= htmlspecialchars($message) ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="opacity-60 hover:opacity-100 font-bold ml-4">✕</button>
                </div>
            <?php endif; ?>

            <!-- Metric Cards (4 Stats) -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
                <!-- Today's Orders -->
                <div class="stat-card p-4 sm:p-5">
                    <div class="flex justify-between items-start">
                        <div>
                            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Today's Orders</span>
                            <h3 class="text-2xl font-black text-slate-900 dark:text-white mt-1"><?= number_format($todayOrdersCount) ?></h3>
                            <span class="text-[11px] text-emerald-600 dark:text-emerald-400 font-semibold">Live POS tickets</span>
                        </div>
                        <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 flex items-center justify-center font-bold text-lg">
                            🧾
                        </div>
                    </div>
                </div>

                <!-- Cups Brewed -->
                <div class="stat-card p-4 sm:p-5" style="--stat-accent: #0C2340;">
                    <div class="flex justify-between items-start">
                        <div>
                            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Cups Brewed Today</span>
                            <h3 class="text-2xl font-black text-slate-900 dark:text-white mt-1"><?= number_format($todayCupsCount) ?></h3>
                            <span class="text-[11px] text-slate-500 font-semibold">Drinks fulfilled</span>
                        </div>
                        <div class="w-10 h-10 rounded-xl bg-[#F28C28]/15 text-[#F28C28] flex items-center justify-center font-bold text-lg">
                            ☕
                        </div>
                    </div>
                </div>

                <!-- Gross Coffee Sales -->
                <div class="stat-card p-4 sm:p-5" style="--stat-accent: #15803D;">
                    <div class="flex justify-between items-start">
                        <div>
                            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Today's Coffee Sales</span>
                            <h3 class="text-2xl font-black text-slate-900 dark:text-white mt-1">₱<?= number_format($todaySalesTotal, 2) ?></h3>
                            <span class="text-[11px] text-emerald-600 dark:text-emerald-400 font-semibold">Gross sales generated</span>
                        </div>
                        <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 flex items-center justify-center font-bold text-lg">
                            ₱
                        </div>
                    </div>
                </div>

                <!-- Manual Deductions Today -->
                <div class="stat-card p-4 sm:p-5" style="--stat-accent: #C26100;">
                    <div class="flex justify-between items-start">
                        <div>
                            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Manual Deductions</span>
                            <h3 class="text-2xl font-black text-slate-900 dark:text-white mt-1"><?= number_format($todayManualDeductionsCount) ?></h3>
                            <span class="text-[11px] text-amber-600 dark:text-amber-400 font-semibold">Shift & calibration entries</span>
                        </div>
                        <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold text-lg">
                            ⚖️
                        </div>
                    </div>
                </div>
            </div>

            <!-- ======================================================== -->
            <!-- TAB 1: COFFEE DRINK ORDERS & AUTO-DEDUCTION -->
            <!-- ======================================================== -->
            <div id="section-coffee-orders" class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                    
                    <!-- Left: Coffee Menu Catalog (2 cols on large) -->
                    <div class="lg:col-span-2 space-y-4">
                        
                        <!-- Search & Filter Controls -->
                        <div class="bg-white dark:bg-[#0C2340] p-4 rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] flex flex-col sm:flex-row justify-between items-center gap-3">
                            <!-- Category Filter Chips -->
                            <div class="flex items-center gap-1.5 overflow-x-auto w-full sm:w-auto custom-scrollbar pb-1 sm:pb-0">
                                <button onclick="filterCategory('All')" 
                                        class="cat-chip active px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-[#F28C28] text-white">
                                    All Drinks
                                </button>
                                <?php foreach ($categoriesList as $cat): ?>
                                    <button onclick="filterCategory('<?= htmlspecialchars($cat) ?>')" 
                                            class="cat-chip px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-slate-100 dark:bg-[#132E52] text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white">
                                        <?= htmlspecialchars($cat) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>

                            <!-- Search Input -->
                            <div class="relative w-full sm:w-60">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                </div>
                                <input type="text" id="menu-search-input" onkeyup="filterMenuSearch()" placeholder="Search coffee menu..." 
                                       class="w-full !pl-10 !pr-4 py-1.5 bg-slate-50 dark:bg-[#07172C] text-xs rounded-xl border border-slate-200 dark:border-[#173863] focus:outline-none focus:border-[#F28C28] has-icon-left">
                            </div>
                        </div>

                        <!-- Beverage Cards Grid -->
                        <div id="drinks-grid" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <?php foreach ($menuItems as $drink): ?>
                                <div class="drink-card bg-white dark:bg-[#0C2340] rounded-2xl p-5 border border-slate-200/80 dark:border-[#15345C] shadow-sm flex flex-col justify-between"
                                     data-category="<?= htmlspecialchars($drink['category']) ?>"
                                     data-name="<?= htmlspecialchars(strtolower($drink['name'])) ?>">
                                    
                                    <div>
                                        <!-- Header: Name & Price -->
                                        <div class="flex justify-between items-start gap-2">
                                            <div>
                                                <div class="flex items-center gap-1.5">
                                                    <span class="text-xs px-2 py-0.5 rounded-md font-bold uppercase tracking-wider bg-slate-100 dark:bg-[#132E52] text-slate-600 dark:text-slate-300">
                                                        <?= htmlspecialchars($drink['category']) ?>
                                                    </span>
                                                </div>
                                                <h3 class="text-base font-bold text-slate-900 dark:text-white mt-1"><?= htmlspecialchars($drink['name']) ?></h3>
                                            </div>
                                            <span class="text-lg font-black text-[#F28C28]">₱<?= number_format($drink['price'], 2) ?></span>
                                        </div>

                                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 line-clamp-2"><?= htmlspecialchars($drink['description']) ?></p>

                                        <!-- Recipe Ingredients Breakdown -->
                                        <div class="mt-3 pt-3 border-t border-slate-100 dark:border-[#15345C]/70">
                                            <span class="text-[10px] uppercase font-bold tracking-wider text-slate-400 block mb-1">Recipe Auto-Deductions:</span>
                                            <div class="flex flex-wrap gap-1.5">
                                                <?php foreach ($drink['ingredients'] as $ing): ?>
                                                    <span class="text-[11px] px-2 py-0.5 rounded-md font-medium bg-slate-50 dark:bg-[#07172C] border border-slate-200 dark:border-[#173863] text-slate-600 dark:text-slate-300">
                                                        <?= ($ing['quantity_required'] * 1000 >= 1000 && $ing['unit'] === 'kg') ? ($ing['quantity_required'] * 1000) . 'g' : ($ing['quantity_required'] . ' ' . $ing['unit']) ?> 
                                                        <b><?= htmlspecialchars($ing['stock_name']) ?></b>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Bottom Action Bar & Availability -->
                                    <div class="mt-4 pt-3 border-t border-slate-100 dark:border-[#15345C]/70 flex items-center justify-between gap-2">
                                        <!-- Stock status pill -->
                                        <div>
                                            <?php if ($drink['is_out_of_stock']): ?>
                                                <span class="text-[11px] font-bold text-rose-600 dark:text-rose-400 flex items-center gap-1">
                                                    <span class="w-2 h-2 rounded-full bg-rose-500"></span> Out of Stock
                                                </span>
                                            <?php elseif ($drink['is_low_stock']): ?>
                                                <span class="text-[11px] font-bold text-amber-600 dark:text-amber-400 flex items-center gap-1">
                                                    <span class="w-2 h-2 rounded-full bg-amber-500"></span> Low (<?= $drink['cups_available'] ?> cups)
                                                </span>
                                            <?php else: ?>
                                                <span class="text-[11px] font-bold text-emerald-600 dark:text-emerald-400 flex items-center gap-1">
                                                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span> In Stock (<?= $drink['cups_available'] ?> cups)
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="flex items-center gap-1.5">
                                            <!-- Add to Ticket Cart Button -->
                                            <button type="button" 
                                                    onclick="addToTicket(<?= htmlspecialchars(json_encode($drink)) ?>)"
                                                    <?= $drink['is_out_of_stock'] ? 'disabled' : '' ?>
                                                    class="px-2.5 py-1.5 rounded-lg text-xs font-bold bg-slate-100 hover:bg-slate-200 dark:bg-[#132E52] dark:hover:bg-[#1C3E69] text-slate-700 dark:text-slate-200 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                                                    title="Add to active order ticket">
                                                + Ticket
                                            </button>

                                            <!-- 1-Click Quick Order & Deduct -->
                                            <form method="POST" onsubmit="return confirm('Brew and deduct stock for 1x <?= addslashes($drink['name']) ?>?')">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                                <input type="hidden" name="action" value="quick_order">
                                                <input type="hidden" name="menu_item_id" value="<?= $drink['id'] ?>">
                                                <input type="hidden" name="quantity" value="1">
                                                <button type="submit" 
                                                        <?= $drink['is_out_of_stock'] ? 'disabled' : '' ?>
                                                        class="px-3 py-1.5 rounded-lg text-xs font-bold bg-[#F28C28] hover:bg-[#E07B18] text-white shadow-sm disabled:opacity-40 disabled:cursor-not-allowed transition-all">
                                                    Brew 1x
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Right: Active Order Ticket Panel (Cart) -->
                    <div class="bg-white dark:bg-[#0C2340] rounded-2xl p-5 border border-slate-200/80 dark:border-[#15345C] shadow-lg sticky top-20">
                        <div class="flex justify-between items-center pb-3 border-b border-slate-200 dark:border-[#15345C]">
                            <div class="flex items-center gap-2">
                                <span class="w-7 h-7 rounded-lg bg-[#F28C28]/15 text-[#F28C28] flex items-center justify-center font-bold text-sm">
                                    🧾
                                </span>
                                <h3 class="font-extrabold text-slate-900 dark:text-white text-base">Current Ticket</h3>
                            </div>
                            <button type="button" onclick="clearTicket()" class="text-xs text-rose-500 hover:text-rose-700 font-semibold">
                                Clear
                            </button>
                        </div>

                        <!-- Ticket Items List -->
                        <div id="ticket-items-container" class="py-4 space-y-3 min-h-[160px] max-h-[320px] overflow-y-auto custom-scrollbar">
                            <div id="ticket-empty-msg" class="text-center py-10 text-slate-400">
                                <span class="text-3xl block mb-2 opacity-50">☕</span>
                                <p class="text-xs font-medium">Ticket is empty.</p>
                                <p class="text-[11px] text-slate-400 mt-0.5">Click <b>+ Ticket</b> on any beverage to build an order.</p>
                            </div>
                        </div>

                        <!-- Projected Stock Deductions Preview -->
                        <div id="ticket-deductions-box" class="hidden p-3 rounded-xl bg-slate-50 dark:bg-[#07172C] border border-slate-200 dark:border-[#173863] text-xs space-y-1 mb-4">
                            <span class="font-bold uppercase tracking-wider text-[10px] text-slate-400 block mb-1">Stock Items to Deduct:</span>
                            <div id="ticket-deductions-list" class="space-y-1 text-slate-600 dark:text-slate-300"></div>
                        </div>

                        <!-- Ticket Total & Checkout -->
                        <div class="pt-4 border-t border-slate-200 dark:border-[#15345C] space-y-3">
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-slate-500 dark:text-slate-400 font-medium">Total Items:</span>
                                <span id="ticket-total-cups" class="font-bold text-slate-900 dark:text-white">0 cups</span>
                            </div>
                            <div class="flex justify-between items-center text-base">
                                <span class="text-slate-900 dark:text-white font-extrabold">Total Amount:</span>
                                <span id="ticket-total-price" class="font-black text-xl text-[#F28C28]">₱0.00</span>
                            </div>

                            <form method="POST" id="ticket-submit-form" onsubmit="return validateTicketSubmit()">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="complete_ticket">
                                <input type="hidden" name="ticket_data" id="ticket-data-input" value="[]">

                                <button type="submit" id="btn-complete-ticket" disabled
                                        class="w-full py-3.5 rounded-xl bg-[#F28C28] hover:bg-[#E07B18] text-white font-extrabold text-sm shadow-lg shadow-[#F28C28]/25 hover:shadow-[#F28C28]/40 hover:-translate-y-0.5 disabled:opacity-40 disabled:cursor-not-allowed disabled:transform-none transition-all flex items-center justify-center gap-2">
                                    <span>Complete Order & Deduct Stock</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ======================================================== -->
            <!-- TAB 2: MANUAL STOCK DEDUCTION (IN THE SAME TAB) -->
            <!-- ======================================================== -->
            <div id="section-manual-deduction" class="hidden space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                    
                    <!-- Left: Manual Deduction Form Card (2 cols) -->
                    <div class="lg:col-span-2 bg-white dark:bg-[#0C2340] rounded-2xl p-6 sm:p-8 border border-slate-200/80 dark:border-[#15345C] shadow-sm">
                        <div class="flex items-center gap-3 pb-5 border-b border-slate-200 dark:border-[#15345C]">
                            <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold text-lg">
                                ⚖️
                            </div>
                            <div>
                                <h2 class="text-lg font-extrabold text-slate-900 dark:text-white">Manual Stock Deduction</h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Directly deduct raw ingredients for grinder dial-in, shift consumption, training, or counter spills.</p>
                            </div>
                        </div>

                        <form method="POST" class="mt-6 space-y-5" onsubmit="return confirmManualDeduction()">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                            <input type="hidden" name="action" value="manual_deduction">

                            <!-- Item Selector -->
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-2">
                                    Select Inventory Item <span class="text-rose-500">*</span>
                                </label>
                                <select name="stock_item_id" id="manual-item-select" required onchange="updateManualStockPreview()"
                                        class="w-full py-3 px-4 bg-slate-50 dark:bg-[#07172C] text-slate-800 dark:text-white border border-slate-200 dark:border-[#173863] rounded-xl text-sm focus:outline-none focus:border-[#F28C28]">
                                    <option value="" disabled selected>-- Select an item from stock --</option>
                                    <?php foreach ($stockItems as $sItem): ?>
                                        <option value="<?= $sItem['id'] ?>" 
                                                data-qty="<?= $sItem['quantity'] ?>" 
                                                data-unit="<?= htmlspecialchars($sItem['unit']) ?>"
                                                data-name="<?= htmlspecialchars($sItem['NAME']) ?>">
                                            <?= htmlspecialchars($sItem['NAME']) ?> (Current: <?= $sItem['quantity'] ?> <?= $sItem['unit'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Deduction Quantity & Quick Chips -->
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-2">
                                    Deduction Quantity <span class="text-rose-500">*</span>
                                </label>
                                <div class="flex gap-3">
                                    <input type="number" step="0.001" min="0.001" name="quantity" id="manual-qty-input" required 
                                           oninput="updateManualStockPreview()" placeholder="0.00"
                                           class="w-full py-3 px-4 bg-slate-50 dark:bg-[#07172C] text-slate-800 dark:text-white border border-slate-200 dark:border-[#173863] rounded-xl text-sm font-semibold focus:outline-none focus:border-[#F28C28]">
                                    <span id="manual-unit-label" class="inline-flex items-center px-4 py-3 bg-slate-100 dark:bg-[#132E52] text-slate-700 dark:text-slate-300 rounded-xl text-sm font-bold min-w-[70px] justify-center">
                                        Unit
                                    </span>
                                </div>

                                <!-- Quick Chips for fast barista entry -->
                                <div class="flex items-center gap-2 mt-2">
                                    <span class="text-[11px] text-slate-400 font-medium">Quick values:</span>
                                    <button type="button" onclick="setQuickQty(0.018)" class="px-2 py-1 text-xs rounded-lg bg-slate-100 dark:bg-[#132E52] text-slate-700 dark:text-slate-300 hover:bg-[#F28C28] hover:text-white transition-colors font-medium">18g (0.018)</button>
                                    <button type="button" onclick="setQuickQty(0.05)" class="px-2 py-1 text-xs rounded-lg bg-slate-100 dark:bg-[#132E52] text-slate-700 dark:text-slate-300 hover:bg-[#F28C28] hover:text-white transition-colors font-medium">50g (0.05)</button>
                                    <button type="button" onclick="setQuickQty(0.20)" class="px-2 py-1 text-xs rounded-lg bg-slate-100 dark:bg-[#132E52] text-slate-700 dark:text-slate-300 hover:bg-[#F28C28] hover:text-white transition-colors font-medium">200mL (0.20)</button>
                                    <button type="button" onclick="setQuickQty(0.50)" class="px-2 py-1 text-xs rounded-lg bg-slate-100 dark:bg-[#132E52] text-slate-700 dark:text-slate-300 hover:bg-[#F28C28] hover:text-white transition-colors font-medium">0.50</button>
                                    <button type="button" onclick="setQuickQty(1.00)" class="px-2 py-1 text-xs rounded-lg bg-slate-100 dark:bg-[#132E52] text-slate-700 dark:text-slate-300 hover:bg-[#F28C28] hover:text-white transition-colors font-medium">1.00</button>
                                </div>
                            </div>

                            <!-- Reason Presets -->
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-2">
                                    Deduction Reason <span class="text-rose-500">*</span>
                                </label>
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 text-xs">
                                    <label class="flex items-center gap-2 p-2.5 rounded-xl border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] cursor-pointer hover:border-[#F28C28] transition-colors">
                                        <input type="radio" name="reason_preset" value="Grinder Dial-in & Calibration" checked class="text-[#F28C28] focus:ring-[#F28C28]">
                                        <span class="font-medium text-slate-700 dark:text-slate-300">Dial-in & Calibration</span>
                                    </label>
                                    <label class="flex items-center gap-2 p-2.5 rounded-xl border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] cursor-pointer hover:border-[#F28C28] transition-colors">
                                        <input type="radio" name="reason_preset" value="Barista Shift Consumption" class="text-[#F28C28] focus:ring-[#F28C28]">
                                        <span class="font-medium text-slate-700 dark:text-slate-300">Barista Shift Drink</span>
                                    </label>
                                    <label class="flex items-center gap-2 p-2.5 rounded-xl border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] cursor-pointer hover:border-[#F28C28] transition-colors">
                                        <input type="radio" name="reason_preset" value="Counter Prep & Steaming Loss" class="text-[#F28C28] focus:ring-[#F28C28]">
                                        <span class="font-medium text-slate-700 dark:text-slate-300">Steaming & Prep Loss</span>
                                    </label>
                                    <label class="flex items-center gap-2 p-2.5 rounded-xl border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] cursor-pointer hover:border-[#F28C28] transition-colors">
                                        <input type="radio" name="reason_preset" value="Accidental Counter Spill" class="text-[#F28C28] focus:ring-[#F28C28]">
                                        <span class="font-medium text-slate-700 dark:text-slate-300">Counter Spill</span>
                                    </label>
                                    <label class="flex items-center gap-2 p-2.5 rounded-xl border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] cursor-pointer hover:border-[#F28C28] transition-colors">
                                        <input type="radio" name="reason_preset" value="Quality Tasting & Training" class="text-[#F28C28] focus:ring-[#F28C28]">
                                        <span class="font-medium text-slate-700 dark:text-slate-300">Tasting & Training</span>
                                    </label>
                                    <label class="flex items-center gap-2 p-2.5 rounded-xl border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] cursor-pointer hover:border-[#F28C28] transition-colors">
                                        <input type="radio" name="reason_preset" value="Other / Custom" class="text-[#F28C28] focus:ring-[#F28C28]">
                                        <span class="font-medium text-slate-700 dark:text-slate-300">Other / Custom</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Optional Custom Notes -->
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-2">
                                    Notes / Additional Details (Optional)
                                </label>
                                <textarea name="reason_custom" rows="2" placeholder="e.g. Purged 2 shots to calibrate grinder setting #4..."
                                          class="w-full py-2.5 px-4 bg-slate-50 dark:bg-[#07172C] text-slate-800 dark:text-white border border-slate-200 dark:border-[#173863] rounded-xl text-xs focus:outline-none focus:border-[#F28C28]"></textarea>
                            </div>

                            <button type="submit"
                                    class="w-full py-3.5 rounded-xl bg-[#F28C28] hover:bg-[#E07B18] text-white font-extrabold text-sm shadow-lg shadow-[#F28C28]/25 hover:shadow-[#F28C28]/40 hover:-translate-y-0.5 transition-all">
                                Confirm Manual Stock Deduction
                            </button>
                        </form>
                    </div>

                    <!-- Right: Live Deduction Preview Box -->
                    <div class="bg-white dark:bg-[#0C2340] rounded-2xl p-6 border border-slate-200/80 dark:border-[#15345C] shadow-sm space-y-4">
                        <h3 class="font-extrabold text-slate-900 dark:text-white text-base flex items-center gap-2 pb-3 border-b border-slate-200 dark:border-[#15345C]">
                            <span>📊 Stock Impact Preview</span>
                        </h3>

                        <div class="space-y-3 text-xs">
                            <div class="p-3 rounded-xl bg-slate-50 dark:bg-[#07172C] border border-slate-200 dark:border-[#173863]">
                                <span class="text-slate-400 block mb-1">Selected Item:</span>
                                <b id="preview-item-name" class="text-sm text-slate-800 dark:text-white block">None selected</b>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div class="p-3 rounded-xl bg-slate-50 dark:bg-[#07172C] border border-slate-200 dark:border-[#173863]">
                                    <span class="text-slate-400 block text-[11px]">Current Stock:</span>
                                    <b id="preview-current-stock" class="text-sm text-slate-800 dark:text-white font-mono">0.00</b>
                                </div>
                                <div class="p-3 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/40">
                                    <span class="text-amber-600 dark:text-amber-400 block text-[11px]">Deducting:</span>
                                    <b id="preview-deduct-qty" class="text-sm text-amber-700 dark:text-amber-300 font-mono">-0.00</b>
                                </div>
                            </div>

                            <div class="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-900/40">
                                <span class="text-emerald-700 dark:text-emerald-400 block text-xs font-bold uppercase tracking-wider">Projected New Balance:</span>
                                <b id="preview-new-stock" class="text-xl text-emerald-800 dark:text-emerald-300 font-black font-mono block mt-0.5">0.00</b>
                            </div>

                            <div class="p-3 rounded-xl bg-slate-50 dark:bg-[#07172C] text-[11px] text-slate-500 space-y-1">
                                <span class="font-bold text-slate-700 dark:text-slate-300 block">🔒 Integrity Guarantee:</span>
                                <p>Every manual deduction creates an audit entry in the inventory ledger with barista timestamp and reason.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ======================================================== -->
            <!-- SECTION 3: RECENT ORDERS & DEDUCTIONS ACTIVITY LOG -->
            <!-- ======================================================== -->
            <div class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] overflow-hidden">
                <div class="p-4 sm:p-5 border-b border-slate-200 dark:border-[#15345C] flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                    <div>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white">Recent Orders & Inventory Activity</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Live stream of coffee drink orders and manual adjustments</p>
                    </div>
                    <span class="text-xs px-3 py-1 rounded-full bg-slate-100 dark:bg-[#132E52] font-semibold text-slate-600 dark:text-slate-300">
                        Last 20 Activities
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left">
                        <thead>
                            <tr>
                                <th>Order Ref #</th>
                                <th>Activity Type</th>
                                <th>Items / Details</th>
                                <th>Served By</th>
                                <th>Amount</th>
                                <th>Timestamp</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentOrders)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-8 text-slate-400">
                                        No coffee orders or stock deductions recorded yet today.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentOrders as $ro): ?>
                                    <tr class="border-b border-slate-100 dark:border-[#15345C]/50 hover:bg-slate-50/50 dark:hover:bg-[#0C2340]/80">
                                        <td class="font-mono font-bold text-slate-800 dark:text-white">
                                            <?= htmlspecialchars($ro['order_number']) ?>
                                        </td>
                                        <td>
                                            <?php if ($ro['order_type'] === 'coffee_drink'): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-[#F28C28]/15 text-[#F28C28] border border-[#F28C28]/30">
                                                    ☕ Coffee Order
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300 border border-amber-300 dark:border-amber-800">
                                                    ⚖️ Manual Deduction
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="max-w-xs truncate text-slate-700 dark:text-slate-300">
                                            <?= htmlspecialchars($ro['items_summary'] ?: $ro['notes']) ?>
                                        </td>
                                        <td class="font-semibold text-slate-800 dark:text-slate-200">
                                            <?= htmlspecialchars($ro['served_by']) ?>
                                        </td>
                                        <td class="font-mono font-bold text-slate-900 dark:text-white">
                                            <?= (float)$ro['total_amount'] > 0 ? '₱' . number_format($ro['total_amount'], 2) : '—' ?>
                                        </td>
                                        <td class="text-slate-400 whitespace-nowrap">
                                            <?= date('M j, Y h:i A', strtotime($ro['created_at'])) ?>
                                        </td>
                                        <td>
                                            <span class="badge-good">
                                                Deducted
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <!-- Interactive Ticket Cart & Tab JavaScript -->
    <script>
        // -------------------------------------------------------------
        // MAIN TAB SWITCHER: Coffee Orders vs. Manual Deduction
        // -------------------------------------------------------------
        function switchMainTab(tab) {
            const secCoffee = document.getElementById('section-coffee-orders');
            const secManual = document.getElementById('section-manual-deduction');
            const btnCoffee = document.getElementById('btn-tab-coffee');
            const btnManual = document.getElementById('btn-tab-manual');

            if (tab === 'coffee') {
                secCoffee.classList.remove('hidden');
                secManual.classList.add('hidden');
                btnCoffee.className = "px-4 py-2 rounded-lg bg-[#F28C28] text-white shadow-sm transition-all flex items-center gap-2";
                btnManual.className = "px-4 py-2 rounded-lg text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white transition-all flex items-center gap-2";
            } else {
                secCoffee.classList.add('hidden');
                secManual.classList.remove('hidden');
                btnManual.className = "px-4 py-2 rounded-lg bg-[#F28C28] text-white shadow-sm transition-all flex items-center gap-2";
                btnCoffee.className = "px-4 py-2 rounded-lg text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white transition-all flex items-center gap-2";
            }
        }

        // -------------------------------------------------------------
        // CATEGORY & SEARCH FILTERING
        // -------------------------------------------------------------
        let currentCategory = 'All';

        function filterCategory(cat) {
            currentCategory = cat;
            document.querySelectorAll('.cat-chip').forEach(btn => {
                if (btn.textContent.trim().toLowerCase() === cat.toLowerCase() || (cat === 'All' && btn.textContent.trim() === 'All Drinks')) {
                    btn.className = "cat-chip active px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-[#F28C28] text-white";
                } else {
                    btn.className = "cat-chip px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-slate-100 dark:bg-[#132E52] text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white";
                }
            });
            applyMenuFilters();
        }

        function filterMenuSearch() {
            applyMenuFilters();
        }

        function applyMenuFilters() {
            const query = (document.getElementById('menu-search-input').value || '').trim().toLowerCase();
            const cards = document.querySelectorAll('.drink-card');

            cards.forEach(card => {
                const cat = card.getAttribute('data-category') || '';
                const name = card.getAttribute('data-name') || '';

                const matchesCat = (currentCategory === 'All' || cat.toLowerCase() === currentCategory.toLowerCase());
                const matchesQuery = (!query || name.includes(query) || cat.toLowerCase().includes(query));

                if (matchesCat && matchesQuery) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // -------------------------------------------------------------
        // ACTIVE ORDER TICKET (CART) STATE & ACTIONS
        // -------------------------------------------------------------
        let ticket = []; // [{ id, name, price, qty, ingredients }]

        function addToTicket(drink) {
            const existing = ticket.find(i => i.id === drink.id);
            if (existing) {
                existing.qty += 1;
            } else {
                ticket.push({
                    id: drink.id,
                    name: drink.name,
                    price: parseFloat(drink.price),
                    qty: 1,
                    ingredients: drink.ingredients || []
                });
            }
            renderTicket();
        }

        function updateTicketQty(drinkId, delta) {
            const item = ticket.find(i => i.id === drinkId);
            if (!item) return;

            item.qty += delta;
            if (item.qty <= 0) {
                ticket = ticket.filter(i => i.id !== drinkId);
            }
            renderTicket();
        }

        function removeTicketItem(drinkId) {
            ticket = ticket.filter(i => i.id !== drinkId);
            renderTicket();
        }

        function clearTicket() {
            ticket = [];
            renderTicket();
        }

        function renderTicket() {
            const container = document.getElementById('ticket-items-container');
            const totalCupsEl = document.getElementById('ticket-total-cups');
            const totalPriceEl = document.getElementById('ticket-total-price');
            const btnComplete = document.getElementById('btn-complete-ticket');
            const deductionsBox = document.getElementById('ticket-deductions-box');
            const deductionsList = document.getElementById('ticket-deductions-list');
            const ticketDataInput = document.getElementById('ticket-data-input');

            if (ticket.length === 0) {
                container.innerHTML = `
                    <div id="ticket-empty-msg" class="text-center py-10 text-slate-400">
                        <span class="text-3xl block mb-2 opacity-50">☕</span>
                        <p class="text-xs font-medium">Ticket is empty.</p>
                        <p class="text-[11px] text-slate-400 mt-0.5">Click <b>+ Ticket</b> on any beverage to build an order.</p>
                    </div>
                `;
                totalCupsEl.textContent = '0 cups';
                totalPriceEl.textContent = '₱0.00';
                btnComplete.disabled = true;
                deductionsBox.classList.add('hidden');
                ticketDataInput.value = '[]';
                return;
            }

            let html = '';
            let totalCups = 0;
            let totalPrice = 0;
            let combinedIngredients = {}; // { stock_name: { qty, unit } }

            ticket.forEach(item => {
                const subtotal = item.price * item.qty;
                totalCups += item.qty;
                totalPrice += subtotal;

                // Accumulate ingredients
                item.ingredients.forEach(ing => {
                    const req = parseFloat(ing.quantity_required) * item.qty;
                    if (!combinedIngredients[ing.stock_name]) {
                        combinedIngredients[ing.stock_name] = { qty: 0, unit: ing.stock_unit };
                    }
                    combinedIngredients[ing.stock_name].qty += req;
                });

                html += `
                    <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-[#07172C] border border-slate-200/80 dark:border-[#173863] flex items-center justify-between gap-2 text-xs">
                        <div class="flex-1 min-w-0">
                            <h4 class="font-bold text-slate-900 dark:text-white truncate">${item.name}</h4>
                            <span class="text-[11px] text-slate-400">₱${item.price.toFixed(2)} each</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" onclick="updateTicketQty(${item.id}, -1)" class="w-6 h-6 rounded-md bg-slate-200 dark:bg-[#132E52] text-slate-700 dark:text-slate-200 font-bold flex items-center justify-center hover:bg-slate-300">-</button>
                            <span class="font-mono font-bold text-slate-900 dark:text-white px-1">${item.qty}</span>
                            <button type="button" onclick="updateTicketQty(${item.id}, 1)" class="w-6 h-6 rounded-md bg-slate-200 dark:bg-[#132E52] text-slate-700 dark:text-slate-200 font-bold flex items-center justify-center hover:bg-slate-300">+</button>
                        </div>
                        <div class="text-right pl-2">
                            <span class="font-bold font-mono text-slate-900 dark:text-white block">₱${subtotal.toFixed(2)}</span>
                            <button type="button" onclick="removeTicketItem(${item.id})" class="text-[10px] text-rose-500 hover:underline">Remove</button>
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
            totalCupsEl.textContent = `${totalCups} ${totalCups === 1 ? 'cup' : 'cups'}`;
            totalPriceEl.textContent = `₱${totalPrice.toFixed(2)}`;
            btnComplete.disabled = false;
            ticketDataInput.value = JSON.stringify(ticket.map(i => ({ id: i.id, qty: i.qty })));

            // Render projected deductions
            let dedHtml = '';
            for (const [name, data] of Object.entries(combinedIngredients)) {
                const roundedQty = data.qty.toFixed(3);
                dedHtml += `
                    <div class="flex justify-between items-center text-[11px]">
                        <span>• ${name}:</span>
                        <b class="font-mono text-amber-600 dark:text-amber-400">-${roundedQty} ${data.unit}</b>
                    </div>
                `;
            }
            deductionsList.innerHTML = dedHtml;
            deductionsBox.classList.remove('hidden');
        }

        function validateTicketSubmit() {
            if (ticket.length === 0) {
                alert('Your order ticket is empty.');
                return false;
            }
            return confirm('Complete this order ticket and automatically deduct all required stock ingredients?');
        }

        // -------------------------------------------------------------
        // MANUAL DEDUCTION PREVIEW & HELPERS
        // -------------------------------------------------------------
        function setQuickQty(val) {
            document.getElementById('manual-qty-input').value = val;
            updateManualStockPreview();
        }

        function updateManualStockPreview() {
            const select = document.getElementById('manual-item-select');
            const qtyInput = document.getElementById('manual-qty-input');
            const unitLabel = document.getElementById('manual-unit-label');
            
            const pItemName = document.getElementById('preview-item-name');
            const pCurrentStock = document.getElementById('preview-current-stock');
            const pDeductQty = document.getElementById('preview-deduct-qty');
            const pNewStock = document.getElementById('preview-new-stock');

            const selectedOption = select.options[select.selectedIndex];
            if (!selectedOption || !selectedOption.value) {
                pItemName.textContent = 'None selected';
                pCurrentStock.textContent = '0.00';
                pDeductQty.textContent = '-0.00';
                pNewStock.textContent = '0.00';
                unitLabel.textContent = 'Unit';
                return;
            }

            const currentQty = parseFloat(selectedOption.getAttribute('data-qty') || '0');
            const unit = selectedOption.getAttribute('data-unit') || '';
            const name = selectedOption.getAttribute('data-name') || '';
            const deduct = parseFloat(qtyInput.value || '0');

            unitLabel.textContent = unit;
            pItemName.textContent = name;
            pCurrentStock.textContent = `${currentQty.toFixed(2)} ${unit}`;
            pDeductQty.textContent = `-${deduct.toFixed(3)} ${unit}`;

            const newBalance = Math.max(0, currentQty - deduct);
            pNewStock.textContent = `${newBalance.toFixed(3)} ${unit}`;

            if (deduct > currentQty) {
                pNewStock.classList.remove('text-emerald-800', 'dark:text-emerald-300');
                pNewStock.classList.add('text-rose-600', 'dark:text-rose-400');
            } else {
                pNewStock.classList.add('text-emerald-800', 'dark:text-emerald-300');
                pNewStock.classList.remove('text-rose-600', 'dark:text-rose-400');
            }
        }

        function confirmManualDeduction() {
            const select = document.getElementById('manual-item-select');
            const qtyInput = document.getElementById('manual-qty-input');
            const selectedOption = select.options[select.selectedIndex];

            if (!selectedOption || !selectedOption.value) {
                alert('Please select an item from stock.');
                return false;
            }
            const deduct = parseFloat(qtyInput.value || '0');
            if (deduct <= 0 || isNaN(deduct)) {
                alert('Please enter a valid deduction quantity greater than 0.');
                return false;
            }
            const currentQty = parseFloat(selectedOption.getAttribute('data-qty') || '0');
            const unit = selectedOption.getAttribute('data-unit') || '';
            const name = selectedOption.getAttribute('data-name') || '';

            if (deduct > currentQty) {
                alert(`Warning: Deduction quantity (${deduct} ${unit}) exceeds available stock (${currentQty} ${unit}). Please correct.`);
                return false;
            }

            return confirm(`Confirm manual stock deduction of ${deduct} ${unit} from '${name}'?`);
        }
    </script>
</body>
</html>
