<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/authguard.php';
require_once __DIR__ . '/../includes/cache_helper.php';
requireLogin();

$role = $_SESSION['role'];
$username = $_SESSION['username'];
$message = '';
$error = '';

// Handle Direct Quick Actions (Deduct / Waste / Update ROP)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    validateCsrfToken();
    $action = $_POST['action'];

    if ($action === 'update_rop') {
        if ($role === 'Staff') {
            $error = "Unauthorized. Only Owner and Manager can change Reorder Points.";
        } else {
            $item_id = (int)($_POST['item_id'] ?? 0);
            $reorder_point = max(0, (float)($_POST['reorder_point'] ?? 0));

            $curr = $pdo->prepare("SELECT * FROM stock_items WHERE id = ?");
            $curr->execute([$item_id]);
            $existing = $curr->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $oldRop = (float)$existing['reorder_point'];
                $unit = $existing['unit'];
                $name = $existing['NAME'] ?? $existing['name'];

                $stmt = $pdo->prepare("UPDATE stock_items SET reorder_point = ? WHERE id = ?");
                $stmt->execute([$reorder_point, $item_id]);

                if (abs($oldRop - $reorder_point) >= 0.001) {
                    $audit = $pdo->prepare("
                        INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
                        VALUES (?, ?, ?, 'rop_update', ?, ?)
                    ");
                    $reason = "Reorder Point (ROP) updated from {$oldRop} {$unit} to {$reorder_point} {$unit}";
                    $audit->execute([$item_id, $oldRop, $reorder_point, $reason, $username]);
                }

                bumpCatalogVersion($pdo);
                $message = "Reorder Point (ROP) for '{$name}' successfully updated to {$reorder_point} {$unit}!";
            }
        }
    } else {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $change_type = $action === 'waste' ? 'waste' : 'deduction'; 
        $reason = trim($_POST['reason'] ?? 'Shift quick action');

        if ($amount <= 0 || $item_id <= 0) {
            $error = "Valid item and quantity greater than zero are required.";
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT quantity, NAME, NAME AS name, unit FROM stock_items WHERE id = ? FOR UPDATE");
                $stmt->execute([$item_id]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$item) {
                    throw new Exception("Stock item not found.");
                }

                $prev_qty = (float)$item['quantity'];
                $new_qty = max(0, round($prev_qty - $amount, 2));

                // Update quantity
                $updateStmt = $pdo->prepare("UPDATE stock_items SET quantity = ? WHERE id = ?");
                $updateStmt->execute([$new_qty, $item_id]);

                // Insert audit log
                $auditStmt = $pdo->prepare("
                    INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $auditStmt->execute([$item_id, $prev_qty, $new_qty, $change_type, $reason, $username]);

                $pdo->commit();
                $itemName = $item['NAME'] ?? $item['name'] ?? 'Item';
                $message = "Recorded {$change_type} of {$amount} {$item['unit']} for {$itemName}.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Transaction failed: " . $e->getMessage();
            }
        }
    }
}

// Fetch all active stock items
$items = $pdo->query("
    SELECT s.*, s.NAME AS NAME, s.NAME AS name, sup.NAME AS supplier_name 
    FROM stock_items s 
    LEFT JOIN suppliers sup ON s.supplier_id = sup.id 
    WHERE s.is_active = 1
    ORDER BY s.category, s.NAME
")->fetchAll(PDO::FETCH_ASSOC);

// Count low-stock items
$lowStockItems = array_filter($items, function($i) {
    return (float)$i['quantity'] <= (float)$i['reorder_point'];
});

// Unique sorted categories for touch filters
$stockCategories = array_values(array_unique(array_filter(array_column($items, 'category'))));
sort($stockCategories);

// Consolidated Metric Counts for Cards (Single Aggregated SELECT over cloud SSL)
$totalItemsCount = count($items);
$lowStockCount = count($lowStockItems);
$totalDeliveriesCount = 0;
$totalSuppliersCount = 0;
try {
    $dashAgg = $pdo->query("
        SELECT 
            (SELECT COUNT(*) FROM invoice_drafts) AS total_deliveries,
            (SELECT COUNT(*) FROM suppliers) AS total_suppliers
    ")->fetch(PDO::FETCH_ASSOC);
    if ($dashAgg) {
        $totalDeliveriesCount = (int)($dashAgg['total_deliveries'] ?? 0);
        $totalSuppliersCount = (int)($dashAgg['total_suppliers'] ?? 0);
    }
} catch (Exception $e) {}

// Audit Trail Filtering
$filterItem = !empty($_GET['audit_item']) ? (int)$_GET['audit_item'] : 0;
$filterType = !empty($_GET['audit_type']) ? trim($_GET['audit_type']) : '';
$filterStart = !empty($_GET['audit_start']) ? trim($_GET['audit_start']) : '';
$filterEnd = !empty($_GET['audit_end']) ? trim($_GET['audit_end']) : '';

$auditQuery = "
    SELECT a.*, s.NAME AS item_name, s.unit 
    FROM audit_logs a 
    LEFT JOIN stock_items s ON a.item_id = s.id 
    WHERE 1=1
";
$auditParams = [];

if ($filterItem > 0) {
    $auditQuery .= " AND a.item_id = ?";
    $auditParams[] = $filterItem;
}
if ($filterType !== '') {
    $auditQuery .= " AND a.change_type = ?";
    $auditParams[] = $filterType;
}
if ($filterStart !== '') {
    $auditQuery .= " AND DATE(a.created_at) >= ?";
    $auditParams[] = $filterStart;
}
if ($filterEnd !== '') {
    $auditQuery .= " AND DATE(a.created_at) <= ?";
    $auditParams[] = $filterEnd;
}

$auditQuery .= " ORDER BY a.created_at DESC LIMIT 30";
$auditStmt = $pdo->prepare($auditQuery);
$auditStmt->execute($auditParams);
$auditLogs = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

$activePage = 'dashboard';
$pageTitle = 'Dashboard - Asterisko Inventory';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/../includes/head.php'; ?>
    <!-- Chart.js for Canva Slide 2 Inventory Overview Chart -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-[#F4F7FB] dark:bg-[#071526] text-slate-800 dark:text-slate-100 min-h-screen">

    <!-- Left Sidebar (Matching Canva Design) -->
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <!-- Main Content Wrapper (Shifted for Sidebar on Desktop) -->
    <div class="lg:pl-64 flex flex-col min-h-screen">
        
        <!-- Top Navigation Bar -->
        <?php require_once __DIR__ . '/../includes/topbar.php'; ?>

        <!-- Page Canvas Body -->
        <main class="flex-1 p-4 sm:p-6 lg:p-8 space-y-6 max-w-7xl w-full mx-auto">

            <!-- Title & Subtitle Banner -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">Dashboard</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Overview of your inventory and activities</p>
                </div>
                <div class="flex items-center gap-2.5 shrink-0">
                    <?php if ($role !== 'Staff'): ?>
                        <a href="manage_items.php" class="btn-primary inline-flex items-center justify-center gap-2 min-h-[46px] px-6 py-3 rounded-xl text-sm font-bold shadow-md whitespace-nowrap shrink-0">
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            <span>+ Add Item</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Offline Queue Status Banner -->
            <div id="offline-bar" class="hidden rounded-xl p-3 border bg-amber-50 dark:bg-amber-950/40 border-amber-300 dark:border-amber-800 text-amber-900 dark:text-amber-200 text-xs flex justify-between items-center gap-3 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse shrink-0"></span>
                    <span><b>Offline Mode:</b> Quick deductions or counts will be saved locally and auto-synced when connection returns.</span>
                </div>
                <button id="btn-sync-now" type="button" onclick="triggerOfflineSync()" class="hidden inline-flex items-center justify-center min-h-[40px] px-4 py-2 bg-[#F28C28] hover:bg-[#E07B18] text-white rounded-xl font-semibold text-xs sm:text-sm whitespace-nowrap shrink-0">
                    Sync <span id="queue-count" class="mx-1">0</span> Items Now
                </button>
            </div>

            <!-- Feedback Messages -->
            <?php if ($message): ?>
                <div class="bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-200 border border-emerald-300 dark:border-emerald-800 p-4 rounded-xl text-sm font-medium flex items-center gap-2">
                    <svg class="w-5 h-5 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-200 border border-rose-300 dark:border-rose-800 p-4 rounded-xl text-sm font-medium flex items-center gap-2">
                    <svg class="w-5 h-5 text-rose-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Automated Low-Stock Alert Banner -->
            <?php if (count($lowStockItems) > 0): ?>
                <div class="bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 p-4 rounded-2xl shadow-sm flex items-start gap-3">
                    <div class="w-9 h-9 rounded-xl bg-rose-500/10 flex items-center justify-center flex-shrink-0 text-rose-600 dark:text-rose-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                    <div class="flex-1">
                        <h2 class="text-rose-900 dark:text-rose-200 font-bold text-sm">Low-Stock Alert (Action Needed)</h2>
                        <p class="text-rose-700 dark:text-rose-300 text-xs mt-0.5">
                            The following items are at or below their Reorder Point (ROP):
                        </p>
                        <div class="flex flex-wrap gap-1.5 mt-2">
                            <?php foreach ($lowStockItems as $ls): ?>
                                <span class="inline-flex items-center gap-1.5 bg-rose-100 dark:bg-rose-900/50 text-rose-900 dark:text-rose-200 text-xs font-semibold px-2.5 py-1 rounded-lg">
                                    <span><?= htmlspecialchars($ls['NAME'] ?? $ls['name']) ?>:</span>
                                    <span class="font-bold"><?= $ls['quantity'] ?> <?= htmlspecialchars($ls['unit']) ?></span>
                                    <span class="text-[10px] opacity-75">(ROP: <?= $ls['reorder_point'] ?>)</span>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 4 Top Metric Cards (Exact Canva Slide 2 Spec) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
                <!-- Card 1: Total Items -->
                <div class="stat-card p-5" style="--stat-accent: #F28C28;">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Total Items</span>
                        <div class="w-10 h-10 rounded-xl bg-orange-50 dark:bg-orange-950/40 text-[#F28C28] flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                        </div>
                    </div>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight"><?= sprintf('%02d', $totalItemsCount) ?></span>
                        <p class="text-xs text-slate-400 mt-1">Active inventory lines</p>
                    </div>
                </div>

                <!-- Card 2: Low Stock -->
                <div class="stat-card p-5" style="--stat-accent: #EF4444;">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Low Stock</span>
                        <div class="w-10 h-10 rounded-xl bg-rose-50 dark:bg-rose-950/40 text-rose-500 flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        </div>
                    </div>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight"><?= sprintf('%02d', $lowStockCount) ?></span>
                        <p class="text-xs text-rose-500 font-medium mt-1">Needs attention</p>
                    </div>
                </div>

                <!-- Card 3: Total Deliveries -->
                <div class="stat-card p-5" style="--stat-accent: #10B981;">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Total Deliveries</span>
                        <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-500 flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
                        </div>
                    </div>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight"><?= sprintf('%02d', $totalDeliveriesCount) ?></span>
                        <p class="text-xs text-emerald-600 dark:text-emerald-400 font-medium mt-1">Intake logs processed</p>
                    </div>
                </div>

                <!-- Card 4: Total Suppliers -->
                <div class="stat-card p-5" style="--stat-accent: #3B82F6;">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Total Suppliers</span>
                        <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-950/40 text-blue-500 flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        </div>
                    </div>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight"><?= sprintf('%02d', $totalSuppliersCount) ?></span>
                        <p class="text-xs text-blue-600 dark:text-blue-400 font-medium mt-1">Active partner vendors</p>
                    </div>
                </div>
            </div>

            <!-- Canva Slide 2 Grid Section: Inventory Overview (Chart) & Recent Inventory (Table) -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                <!-- Left: Inventory Overview Chart (Canva Slide 2) -->
                <div class="lg:col-span-5 bg-white dark:bg-[#0C2340] p-6 rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] flex flex-col justify-between">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h2 class="text-base font-bold text-slate-900 dark:text-white">Inventory Overview</h2>
                            <p class="text-xs text-slate-400">Stock movement by category</p>
                        </div>
                        <span class="text-xs font-semibold px-2.5 py-1 bg-slate-100 dark:bg-[#132E52] rounded-lg text-slate-600 dark:text-slate-300">Live</span>
                    </div>
                    <div class="relative h-64 w-full">
                        <canvas id="overviewChart"></canvas>
                    </div>
                </div>

                <!-- Right: Recent Inventory Table (Canva Slide 2) -->
                <div class="lg:col-span-7 bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] overflow-hidden flex flex-col justify-between">
                    <div class="p-5 border-b border-slate-200 dark:border-[#15345C] flex items-center justify-between">
                        <div>
                            <h2 class="text-base font-bold text-slate-900 dark:text-white">Recent Inventory</h2>
                            <p class="text-xs text-slate-400">Latest active item catalog</p>
                        </div>
                        <a href="manage_items.php" class="btn-link text-xs sm:text-sm font-bold text-[#F28C28] hover:text-[#E07B18] hover:bg-orange-50 dark:hover:bg-[#132E52] inline-flex items-center gap-1 whitespace-nowrap shrink-0">
                            View All <span aria-hidden="true">&rarr;</span>
                        </a>
                    </div>
                    <div class="overflow-x-auto flex-1">
                        <table class="w-full text-xs text-left">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Category</th>
                                    <th>Stock</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $previewItems = array_slice($items, 0, 5);
                                foreach ($previewItems as $pi): 
                                    $isLow = (float)$pi['quantity'] <= (float)$pi['reorder_point'];
                                ?>
                                <tr>
                                    <td class="font-bold text-slate-900 dark:text-white">
                                        <?= htmlspecialchars($pi['NAME'] ?? $pi['name']) ?>
                                    </td>
                                    <td>
                                        <span class="px-2 py-0.5 rounded-md bg-slate-100 dark:bg-[#132E52] text-slate-600 dark:text-slate-300 text-[11px]">
                                            <?= htmlspecialchars($pi['category']) ?>
                                        </span>
                                    </td>
                                    <td class="font-bold font-mono">
                                        <?= number_format($pi['quantity'], 1) ?> <?= htmlspecialchars($pi['unit']) ?>
                                    </td>
                                    <td>
                                        <?php if ($isLow): ?>
                                            <span class="badge-low">Low Stock</span>
                                        <?php else: ?>
                                            <span class="badge-good">Good</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Live Stock Management & Quick Actions (Touch & Tablet Optimized) -->
            <div class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] overflow-hidden">
                <!-- Section Header -->
                <div class="p-4 sm:p-5 border-b border-slate-200 dark:border-[#15345C] flex flex-col md:flex-row justify-between md:items-center gap-3">
                    <div class="flex items-center gap-3">
                        <span class="w-10 h-10 rounded-xl bg-[#F28C28]/15 border border-[#F28C28]/30 flex items-center justify-center text-[#F28C28] text-lg shrink-0">
                            📦
                        </span>
                        <div>
                            <h2 class="font-extrabold text-base sm:text-lg text-slate-900 dark:text-white tracking-tight">
                                Live Stock Management & Quick Actions
                            </h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                Touch-first inventory monitoring and instant shift deductions.
                            </p>
                        </div>
                    </div>
                    <!-- Right Controls: Search + View Switcher -->
                    <div class="flex items-center gap-2 w-full md:w-auto">
                        <!-- Search Box with Clear Button -->
                        <div class="relative flex-1 md:w-56">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            </div>
                            <input type="text" id="filter-stock-input" placeholder="Search catalog..."
                                   class="w-full text-xs pl-9 pr-8 py-2.5 rounded-xl border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] text-slate-900 dark:text-white focus:outline-none focus:border-[#F28C28]">
                            <button type="button" id="clear-stock-search" onclick="clearStockSearch()" class="hidden absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600">
                                ✕
                            </button>
                        </div>

                        <!-- View Switcher (Cards vs Table) -->
                        <div class="inline-flex p-1 bg-slate-100 dark:bg-[#07172C] rounded-xl border border-slate-200 dark:border-[#173863] shrink-0">
                            <button type="button" id="btn-view-cards" onclick="switchStockView('cards')"
                                    class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-[#F28C28] text-white shadow-sm flex items-center gap-1.5"
                                    title="Touch Cards (Recommended for Tablet & Mobile)">
                                <span>📱</span>
                                <span class="hidden sm:inline">Cards</span>
                            </button>
                            <button type="button" id="btn-view-table" onclick="switchStockView('table')"
                                    class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white flex items-center gap-1.5"
                                    title="Compact Table (Desktop)">
                                <span>📄</span>
                                <span class="hidden sm:inline">Table</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Touch-Swipeable Category & Status Ribbon -->
                <div class="px-4 sm:px-5 py-3 bg-slate-50/70 dark:bg-[#07172C]/60 border-b border-slate-200/80 dark:border-[#15345C] flex items-center gap-2 overflow-x-auto scrollbar-none touch-pan-x">
                    <button type="button" onclick="filterStockCategory('all')" 
                            class="stock-cat-pill active px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all shrink-0 bg-[#F28C28] text-white shadow-sm">
                        All Items (<?= $totalItemsCount ?>)
                    </button>
                    <button type="button" onclick="filterStockCategory('low')" 
                            class="stock-cat-pill px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all shrink-0 <?= $lowStockCount > 0 ? 'bg-rose-100 dark:bg-rose-950/60 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-900/40 hover:bg-rose-200' : 'bg-slate-100 dark:bg-[#132E52] text-slate-600 dark:text-slate-300' ?>">
                        ⚠️ Low Stock (<?= $lowStockCount ?>)
                    </button>
                    <?php foreach ($stockCategories as $cat): 
                        $catCount = count(array_filter($items, fn($i) => ($i['category'] ?? '') === $cat));
                    ?>
                        <button type="button" onclick="filterStockCategory('<?= htmlspecialchars($cat) ?>')" 
                                class="stock-cat-pill px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all shrink-0 bg-slate-100 dark:bg-[#132E52] text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white">
                            <?= htmlspecialchars($cat) ?> (<?= $catCount ?>)
                        </button>
                    <?php endforeach; ?>
                </div>

                <!-- VIEW 1: TOUCH CARDS GRID (PRIMARY FOR TABLET & MOBILE) -->
                <div id="stock-cards-view" class="p-4 sm:p-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3.5" id="stock-cards-grid">
                        <?php foreach ($items as $item): 
                            $qty = (float)$item['quantity'];
                            $rop = (float)$item['reorder_point'];
                            $isLow = $qty <= $rop;
                            $targetMax = max($rop * 2.5, 10.0);
                            $pct = min(100, max(5, round(($qty / $targetMax) * 100)));
                            $name = $item['NAME'] ?? $item['name'];
                            $category = $item['category'] ?? 'General';
                            $unit = $item['unit'] ?? 'units';
                            $supplierName = $item['supplier_name'] ?? 'Supplier';
                        ?>
                        <div class="stock-card-item bg-white dark:bg-[#0C2340] rounded-2xl p-4 border <?= $isLow ? 'border-rose-300 dark:border-rose-900/60 shadow-sm shadow-rose-100 dark:shadow-none bg-rose-50/20 dark:bg-rose-950/10' : 'border-slate-200/80 dark:border-[#15345C] hover:border-slate-300 dark:hover:border-[#1C3E69]' ?> shadow-sm flex flex-col justify-between transition-all"
                             data-category="<?= htmlspecialchars(strtolower($category)) ?>"
                             data-name="<?= htmlspecialchars(strtolower($name)) ?>"
                             data-low="<?= $isLow ? '1' : '0' ?>">
                            
                            <!-- Card Top Bar: Category Pill & Status Badge -->
                            <div class="flex items-center justify-between gap-2 mb-2">
                                <span class="px-2.5 py-0.5 rounded-lg text-[11px] font-extrabold uppercase tracking-wide bg-slate-100 dark:bg-[#132E52] text-slate-700 dark:text-slate-300">
                                    <?= htmlspecialchars($category) ?>
                                </span>
                                <?php if ($isLow): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-black bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300 border border-rose-300 dark:border-rose-800 animate-pulse">
                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                                        LOW STOCK
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300 border border-emerald-200/80 dark:border-emerald-800/40">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                        In Stock
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Card Middle: Title, Supplier & Live Stock Numbers -->
                            <div class="mb-3">
                                <h3 class="font-extrabold text-base text-slate-900 dark:text-white leading-tight line-clamp-1" title="<?= htmlspecialchars($name) ?>">
                                    <?= htmlspecialchars($name) ?>
                                </h3>
                                <p class="text-[11px] text-slate-400 truncate mt-0.5 flex items-center gap-1">
                                    <span>🚚</span> <?= htmlspecialchars($supplierName) ?>
                                </p>

                                <!-- Big Live In-Stock Metric -->
                                <div class="mt-3 flex items-baseline justify-between">
                                    <div>
                                        <span class="text-2xl sm:text-3xl font-black font-mono tracking-tight <?= $isLow ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' ?>">
                                            <?= number_format($qty, 2) ?>
                                        </span>
                                        <span class="text-xs font-extrabold text-slate-400 uppercase ml-0.5">
                                            <?= htmlspecialchars($unit) ?>
                                        </span>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-[11px] text-slate-400">Reorder Point</div>
                                        <div class="font-mono text-xs font-bold text-slate-700 dark:text-slate-300 flex items-center justify-end gap-1">
                                            <span><?= number_format($rop, 2) ?> <?= htmlspecialchars($unit) ?></span>
                                            <?php if ($role !== 'Staff'): ?>
                                                <button type="button" 
                                                        onclick='openQuickRopModal(<?= $item['id'] ?>, "<?= addslashes(htmlspecialchars($name)) ?>", <?= (float)$rop ?>, "<?= addslashes(htmlspecialchars($unit)) ?>")'
                                                        class="p-1 rounded text-slate-400 hover:text-[#F28C28] hover:bg-orange-50 dark:hover:bg-[#132E52] transition-colors" title="Change Reorder Point">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Visual Stock Bar -->
                                <div class="w-full bg-slate-100 dark:bg-[#07172C] rounded-full h-2 mt-2 overflow-hidden border border-slate-200/60 dark:border-[#173863]">
                                    <div class="h-full rounded-full transition-all duration-300 <?= $isLow ? 'bg-gradient-to-r from-rose-500 to-amber-500' : 'bg-gradient-to-r from-emerald-500 to-[#F28C28]' ?>"
                                         style="width: <?= $pct ?>%;"></div>
                                </div>

                                <?php if ($role !== 'Staff'): ?>
                                    <div class="mt-2 text-[11px] text-slate-400 font-mono">
                                        Unit Cost: <b class="text-slate-700 dark:text-slate-300">₱<?= number_format((float)$item['unit_cost'], 2) ?></b>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Card Bottom: Touch-Optimized Quick Action Buttons -->
                            <div class="pt-3 border-t border-slate-100 dark:border-[#15345C] grid grid-cols-2 gap-2">
                                <button type="button"
                                        onclick='openTouchQuickActionModal(<?= $item['id'] ?>, "<?= addslashes(htmlspecialchars($name)) ?>", "<?= addslashes(htmlspecialchars($category)) ?>", <?= (float)$qty ?>, "<?= addslashes(htmlspecialchars($unit)) ?>", "deduction")'
                                        class="w-full py-2.5 px-3 rounded-xl bg-[#F28C28] hover:bg-[#E07B18] active:scale-[0.98] text-white text-xs font-bold shadow-sm transition-all flex items-center justify-center gap-1.5">
                                    <span>⚡</span>
                                    <span>Deduct</span>
                                </button>
                                <button type="button"
                                        onclick='openTouchQuickActionModal(<?= $item['id'] ?>, "<?= addslashes(htmlspecialchars($name)) ?>", "<?= addslashes(htmlspecialchars($category)) ?>", <?= (float)$qty ?>, "<?= addslashes(htmlspecialchars($unit)) ?>", "waste")'
                                        class="w-full py-2.5 px-3 rounded-xl bg-rose-50 hover:bg-rose-100 dark:bg-rose-950/40 dark:hover:bg-rose-900/60 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-900/50 active:scale-[0.98] text-xs font-bold transition-all flex items-center justify-center gap-1.5">
                                    <span>🗑️</span>
                                    <span>Waste</span>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- VIEW 2: COMPACT TABLE VIEW (TOGGLEABLE FOR DESKTOP / SPREADSHEET USERS) -->
                <div id="stock-table-view" class="hidden overflow-x-auto">
                    <table class="w-full text-xs text-left" id="stock-table">
                        <thead>
                            <tr>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>In-Stock</th>
                                <th>ROP</th>
                                <th>Status</th>
                                <?php if ($role !== 'Staff'): ?>
                                    <th>Unit Cost</th>
                                <?php endif; ?>
                                <th>Quick Shift Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): 
                                $qty = (float)$item['quantity'];
                                $rop = (float)$item['reorder_point'];
                                $isLow = $qty <= $rop;
                                $name = $item['NAME'] ?? $item['name'];
                                $category = $item['category'] ?? 'General';
                                $unit = $item['unit'] ?? 'units';
                            ?>
                            <tr class="stock-table-row <?= $isLow ? 'bg-rose-50/30 dark:bg-rose-950/20' : '' ?>"
                                data-category="<?= htmlspecialchars(strtolower($category)) ?>"
                                data-name="<?= htmlspecialchars(strtolower($name)) ?>"
                                data-low="<?= $isLow ? '1' : '0' ?>">
                                <td class="font-bold text-slate-900 dark:text-white">
                                    <div><?= htmlspecialchars($name) ?></div>
                                    <div class="text-[10px] text-slate-400 font-normal"><?= htmlspecialchars($item['supplier_name'] ?? '') ?></div>
                                </td>
                                <td>
                                    <span class="bg-slate-100 dark:bg-[#132E52] px-2 py-0.5 rounded text-[11px] text-slate-600 dark:text-slate-300">
                                        <?= htmlspecialchars($category) ?>
                                    </span>
                                </td>
                                <td class="font-mono font-bold text-sm text-slate-800 dark:text-slate-100">
                                    <?= number_format($qty, 2) ?> <?= htmlspecialchars($unit) ?>
                                </td>
                                <td class="font-mono text-slate-500">
                                    <div class="inline-flex items-center gap-1.5">
                                        <span class="font-semibold text-slate-700 dark:text-slate-300"><?= number_format($rop, 2) ?></span>
                                        <span class="text-[11px] text-slate-400"><?= htmlspecialchars($unit) ?></span>
                                        <?php if ($role !== 'Staff'): ?>
                                            <button type="button" 
                                                    onclick='openQuickRopModal(<?= $item['id'] ?>, "<?= addslashes(htmlspecialchars($name)) ?>", <?= (float)$rop ?>, "<?= addslashes(htmlspecialchars($unit)) ?>")'
                                                    class="btn-icon w-8 h-8 rounded-lg text-slate-400 hover:text-[#F28C28] hover:bg-orange-50 dark:hover:bg-[#132E52] transition-colors shrink-0 flex items-center justify-center" title="Change Reorder Point">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($isLow): ?>
                                        <span class="badge-low">LOW STOCK</span>
                                    <?php else: ?>
                                        <span class="badge-good">GOOD</span>
                                    <?php endif; ?>
                                </td>

                                <?php if ($role !== 'Staff'): ?>
                                    <td class="font-mono font-semibold">₱<?= number_format($item['unit_cost'], 2) ?></td>
                                <?php endif; ?>

                                <td>
                                    <div class="flex items-center gap-1.5">
                                        <button type="button"
                                                onclick='openTouchQuickActionModal(<?= $item['id'] ?>, "<?= addslashes(htmlspecialchars($name)) ?>", "<?= addslashes(htmlspecialchars($category)) ?>", <?= (float)$qty ?>, "<?= addslashes(htmlspecialchars($unit)) ?>", "deduction")'
                                                class="px-2.5 py-1.5 rounded-lg bg-[#F28C28] hover:bg-[#E07B18] text-white text-xs font-bold transition-all shadow-sm">
                                            ⚡ Deduct
                                        </button>
                                        <button type="button"
                                                onclick='openTouchQuickActionModal(<?= $item['id'] ?>, "<?= addslashes(htmlspecialchars($name)) ?>", "<?= addslashes(htmlspecialchars($category)) ?>", <?= (float)$qty ?>, "<?= addslashes(htmlspecialchars($unit)) ?>", "waste")'
                                                class="px-2.5 py-1.5 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold transition-all shadow-sm">
                                            Waste
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Empty Search / Filter State -->
                <div id="stock-empty-state" class="hidden p-12 text-center">
                    <span class="text-3xl block mb-2">🔍</span>
                    <h4 class="font-bold text-slate-800 dark:text-white text-sm">No inventory items found</h4>
                    <p class="text-xs text-slate-400 mt-1">Try selecting another category or clearing your search term.</p>
                    <button type="button" onclick="resetStockFilters()" class="mt-3 px-3.5 py-1.5 rounded-xl bg-slate-100 dark:bg-[#132E52] text-xs font-bold text-slate-700 dark:text-slate-200">
                        Reset Filters
                    </button>
                </div>
            </div>

            <!-- Immutable Audit Trail Section -->
            <div class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] overflow-hidden">
                <div class="p-5 border-b border-slate-200 dark:border-[#15345C] flex flex-col sm:flex-row justify-between sm:items-center gap-3">
                    <div>
                        <h2 class="font-bold text-base text-slate-900 dark:text-white">Immutable Audit Trail</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Append-only historical ledger of stock movements and access controls.</p>
                    </div>
                </div>

                <!-- Audit Filter Form -->
                <div class="p-4 bg-slate-50/70 dark:bg-[#091B33] border-b border-slate-200 dark:border-[#15345C]">
                    <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-3 text-xs items-end">
                        <div class="min-w-0 w-full">
                            <label class="block font-bold mb-1 text-[11px] text-slate-600 dark:text-slate-300">Filter by Item</label>
                            <select name="audit_item" class="w-full p-2 rounded-xl border border-slate-200 dark:border-[#173863] bg-white dark:bg-[#07172C]">
                                <option value="0">All Items</option>
                                <?php foreach ($items as $si): ?>
                                    <option value="<?= $si['id'] ?>" <?= $filterItem == $si['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($si['NAME'] ?? $si['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="min-w-0 w-full">
                            <label class="block font-bold mb-1 text-[11px] text-slate-600 dark:text-slate-300">Change Type</label>
                            <select name="audit_type" class="w-full p-2 rounded-xl border border-slate-200 dark:border-[#173863] bg-white dark:bg-[#07172C]">
                                <option value="">All Types</option>
                                <option value="deduction" <?= $filterType === 'deduction' ? 'selected' : '' ?>>Deduction</option>
                                <option value="delivery" <?= $filterType === 'delivery' ? 'selected' : '' ?>>Delivery</option>
                                <option value="waste" <?= $filterType === 'waste' ? 'selected' : '' ?>>Waste</option>
                                <option value="count_correction" <?= $filterType === 'count_correction' ? 'selected' : '' ?>>Count Correction</option>
                                <option value="rop_update" <?= $filterType === 'rop_update' ? 'selected' : '' ?>>ROP Update</option>
                                <option value="unauthorized_access" <?= $filterType === 'unauthorized_access' ? 'selected' : '' ?>>Unauthorized Access</option>
                            </select>
                        </div>
                        <div class="min-w-0 w-full">
                            <label class="block font-bold mb-1 text-[11px] text-slate-600 dark:text-slate-300">From Date</label>
                            <div class="flex w-full min-w-0">
                                <input type="date" name="audit_start" value="<?= htmlspecialchars($filterStart) ?>"
                                       class="flex-1 min-w-0 w-0 p-2 rounded-xl border border-slate-200 dark:border-[#173863] bg-white dark:bg-[#07172C]">
                            </div>
                        </div>
                        <div class="min-w-0 w-full">
                            <label class="block font-bold mb-1 text-[11px] text-slate-600 dark:text-slate-300">To Date</label>
                            <div class="flex w-full min-w-0">
                                <input type="date" name="audit_end" value="<?= htmlspecialchars($filterEnd) ?>"
                                       class="flex-1 min-w-0 w-0 p-2 rounded-xl border border-slate-200 dark:border-[#173863] bg-white dark:bg-[#07172C]">
                            </div>
                        </div>
                        <div class="sm:col-span-2 xl:col-span-1 flex items-center gap-2 min-w-0 w-full">
                            <button type="submit" class="btn-secondary flex-1 h-[46px] !px-4 py-2.5 rounded-xl font-bold text-sm shadow-sm inline-flex items-center justify-center whitespace-nowrap">Filter</button>
                            <a href="dashboard.php" class="btn-outline flex-1 h-[46px] !px-4 py-2.5 rounded-xl text-sm font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-[#132E52] inline-flex items-center justify-center whitespace-nowrap">Reset</a>
                        </div>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Item</th>
                                <th>Previous</th>
                                <th>New</th>
                                <th>Action Type</th>
                                <th>Reason</th>
                                <th>User</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($auditLogs) === 0): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-6 text-slate-400">No audit log records found for this query filter.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($auditLogs as $log): ?>
                                <tr>
                                    <td class="font-mono text-slate-500 whitespace-nowrap"><?= htmlspecialchars($log['created_at']) ?></td>
                                    <td class="font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($log['item_name'] ?? 'System/Security') ?></td>
                                    <td class="font-mono"><?= $log['previous_value'] !== null ? htmlspecialchars($log['previous_value']) : '—' ?></td>
                                    <td class="font-mono font-bold"><?= $log['new_value'] !== null ? htmlspecialchars($log['new_value']) : '—' ?></td>
                                    <td>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase
                                            <?= $log['change_type'] === 'deduction' ? 'bg-orange-100 dark:bg-orange-950/40 text-orange-800 dark:text-orange-300' : '' ?>
                                            <?= $log['change_type'] === 'waste' ? 'bg-rose-100 dark:bg-rose-950/40 text-rose-800 dark:text-rose-300' : '' ?>
                                            <?= $log['change_type'] === 'delivery' ? 'bg-emerald-100 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300' : '' ?>
                                            <?= $log['change_type'] === 'count_correction' ? 'bg-blue-100 dark:bg-blue-950/40 text-blue-800 dark:text-blue-300' : '' ?>
                                            <?= $log['change_type'] === 'unauthorized_access' ? 'bg-red-600 text-white' : '' ?>
                                        ">
                                            <?= htmlspecialchars($log['change_type']) ?>
                                        </span>
                                    </td>
                                    <td class="text-slate-600 dark:text-slate-300"><?= htmlspecialchars($log['reason'] ?? '') ?></td>
                                    <td class="font-semibold text-slate-800 dark:text-slate-200"><?= htmlspecialchars($log['acting_user']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Dedicated Touch-First Quick Action Modal (Tablet & Mobile Optimized) -->
    <div id="quick-action-modal" class="fixed inset-0 modal-overlay z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#0C2340] rounded-3xl max-w-md w-full p-5 sm:p-6 shadow-2xl border border-slate-200 dark:border-[#15345C] relative animate-fade-in">
            
            <!-- Modal Header -->
            <div class="flex items-start justify-between pb-3 border-b border-slate-200 dark:border-[#15345C] mb-4">
                <div>
                    <span id="qa-category-badge" class="px-2.5 py-0.5 rounded-lg text-[10px] font-extrabold uppercase tracking-wider bg-slate-100 dark:bg-[#132E52] text-slate-700 dark:text-slate-300">
                        Category
                    </span>
                    <h3 id="qa-item-name" class="text-lg font-black text-slate-900 dark:text-white mt-1 leading-snug">
                        Item Name
                    </h3>
                    <div class="flex items-center gap-2 mt-1 text-xs text-slate-500 dark:text-slate-400">
                        <span>Current Stock:</span>
                        <b id="qa-current-stock" class="font-mono text-slate-900 dark:text-white font-extrabold">0.00 kg</b>
                        <span class="text-slate-300 dark:text-slate-600">•</span>
                        <span id="qa-rop-info" class="text-[11px] font-semibold">ROP: 0.00</span>
                    </div>
                </div>
                <button type="button" onclick="closeTouchQuickActionModal()" 
                        class="w-8 h-8 rounded-full bg-slate-100 dark:bg-[#132E52] text-slate-400 hover:text-slate-700 dark:hover:text-white flex items-center justify-center transition-colors">
                    ✕
                </button>
            </div>

            <!-- Action Mode Tabs (Large Touch Segmented Control) -->
            <div class="grid grid-cols-2 p-1 bg-slate-100 dark:bg-[#07172C] rounded-2xl border border-slate-200 dark:border-[#173863] mb-4">
                <button type="button" id="qa-tab-deduct" onclick="setTouchActionTab('deduction')"
                        class="py-2.5 rounded-xl text-xs font-bold transition-all bg-[#F28C28] text-white shadow-sm flex items-center justify-center gap-1.5">
                    <span>📉</span>
                    <span>Stock Deduction</span>
                </button>
                <button type="button" id="qa-tab-waste" onclick="setTouchActionTab('waste')"
                        class="py-2.5 rounded-xl text-xs font-bold transition-all text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white flex items-center justify-center gap-1.5">
                    <span>🗑️</span>
                    <span>Log Waste</span>
                </button>
            </div>

            <form method="POST" id="touch-quick-action-form" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="action" id="qa-action-input" value="deduction">
                <input type="hidden" name="item_id" id="qa-item-id" value="">

                <!-- Quantity Stepper & Big Numeric Input -->
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300">Quantity to Deduct *</label>
                        <span id="qa-max-hint" class="text-[11px] text-slate-400"></span>
                    </div>
                    
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="adjustQaAmount(-1)" 
                                class="w-12 h-12 rounded-2xl bg-slate-100 dark:bg-[#132E52] hover:bg-slate-200 dark:hover:bg-[#1C3E69] text-slate-800 dark:text-white font-black text-2xl flex items-center justify-center active:scale-95 transition-all select-none shrink-0 shadow-sm">
                            −
                        </button>
                        <div class="relative flex-1">
                            <input type="number" step="0.01" min="0.01" name="amount" id="qa-amount-input" required placeholder="0.00"
                                   class="w-full text-center text-2xl font-black font-mono py-2 bg-slate-50 dark:bg-[#07172C] text-slate-900 dark:text-white border border-slate-200 dark:border-[#173863] rounded-2xl focus:outline-none focus:border-[#F28C28]">
                            <span id="qa-unit-badge" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-400 uppercase pointer-events-none">
                                kg
                            </span>
                        </div>
                        <button type="button" onclick="adjustQaAmount(+1)" 
                                class="w-12 h-12 rounded-2xl bg-slate-100 dark:bg-[#132E52] hover:bg-slate-200 dark:hover:bg-[#1C3E69] text-slate-800 dark:text-white font-black text-2xl flex items-center justify-center active:scale-95 transition-all select-none shrink-0 shadow-sm">
                            +
                        </button>
                    </div>

                    <!-- Fast Amount Presets (Touch Pills) -->
                    <div class="flex items-center gap-1.5 flex-wrap mt-2.5" id="qa-presets-container">
                        <!-- Populated dynamically based on unit (kg, L, pcs) -->
                    </div>
                </div>

                <!-- Reason Section with Quick Presets -->
                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1.5">Reason / Operation Note</label>
                    
                    <!-- Quick Reason Tap Buttons -->
                    <div class="flex items-center gap-1.5 flex-wrap mb-2" id="qa-reason-presets">
                        <!-- Populated dynamically depending on Deduct vs Waste -->
                    </div>

                    <input type="text" name="reason" id="qa-reason-input" placeholder="Custom note or specify batch..." 
                           class="w-full text-xs p-2.5 rounded-xl border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] text-slate-900 dark:text-white focus:outline-none focus:border-[#F28C28]">
                </div>

                <!-- Submit & Cancel Buttons -->
                <div class="pt-3 border-t border-slate-200 dark:border-[#15345C] flex items-center justify-end gap-2">
                    <button type="button" onclick="closeTouchQuickActionModal()" 
                            class="px-4 py-3 rounded-2xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-[#132E52] transition-colors">
                        Cancel
                    </button>
                    <button type="submit" id="qa-submit-btn" 
                            class="flex-1 py-3 px-5 rounded-2xl bg-[#F28C28] hover:bg-[#E07B18] active:scale-[0.98] text-white text-xs font-extrabold shadow-lg shadow-orange-500/20 transition-all flex items-center justify-center gap-1.5">
                        <span>⚡</span>
                        <span id="qa-submit-text">Confirm Deduction</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Script for Live Filter, View Switcher & Touch Quick Actions -->
    <script>
        // -------------------------------------------------------------
        // 1. TOUCH & SEARCH FILTERING (CARDS + TABLE)
        // -------------------------------------------------------------
        let currentStockCategory = 'all';

        const stockSearchInput = document.getElementById('filter-stock-input');
        const clearStockBtn = document.getElementById('clear-stock-search');

        if (stockSearchInput) {
            stockSearchInput.addEventListener('input', function() {
                if (clearStockBtn) {
                    clearStockBtn.classList.toggle('hidden', this.value.trim() === '');
                }
                applyStockFilters();
            });
        }

        function clearStockSearch() {
            if (stockSearchInput) {
                stockSearchInput.value = '';
                if (clearStockBtn) clearStockBtn.classList.add('hidden');
                applyStockFilters();
            }
        }

        function filterStockCategory(cat) {
            currentStockCategory = cat.toLowerCase();

            // Highlight active category pill
            document.querySelectorAll('.stock-cat-pill').forEach(btn => {
                const text = btn.textContent.toLowerCase();
                let matches = false;
                if (currentStockCategory === 'all' && text.includes('all items')) matches = true;
                else if (currentStockCategory === 'low' && text.includes('low stock')) matches = true;
                else if (text.startsWith(currentStockCategory)) matches = true;

                if (matches) {
                    btn.className = "stock-cat-pill active px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all shrink-0 bg-[#F28C28] text-white shadow-sm";
                } else {
                    btn.className = "stock-cat-pill px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all shrink-0 bg-slate-100 dark:bg-[#132E52] text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white";
                }
            });

            applyStockFilters();
        }

        function resetStockFilters() {
            clearStockSearch();
            filterStockCategory('all');
        }

        function applyStockFilters() {
            const query = (stockSearchInput ? stockSearchInput.value : '').trim().toLowerCase();
            let visibleCount = 0;

            // Filter Cards
            document.querySelectorAll('.stock-card-item').forEach(card => {
                const name = (card.getAttribute('data-name') || '').toLowerCase();
                const cat = (card.getAttribute('data-category') || '').toLowerCase();
                const isLow = card.getAttribute('data-low') === '1';

                let matchCat = true;
                if (currentStockCategory === 'low') {
                    matchCat = isLow;
                } else if (currentStockCategory !== 'all') {
                    matchCat = (cat === currentStockCategory);
                }

                const matchQuery = (!query || name.includes(query) || cat.includes(query));

                if (matchCat && matchQuery) {
                    card.style.display = 'flex';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            // Filter Table Rows
            document.querySelectorAll('.stock-table-row').forEach(row => {
                const name = (row.getAttribute('data-name') || '').toLowerCase();
                const cat = (row.getAttribute('data-category') || '').toLowerCase();
                const isLow = row.getAttribute('data-low') === '1';

                let matchCat = true;
                if (currentStockCategory === 'low') {
                    matchCat = isLow;
                } else if (currentStockCategory !== 'all') {
                    matchCat = (cat === currentStockCategory);
                }

                const matchQuery = (!query || name.includes(query) || cat.includes(query));

                if (matchCat && matchQuery) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });

            // Empty state feedback
            const emptyEl = document.getElementById('stock-empty-state');
            if (emptyEl) {
                emptyEl.classList.toggle('hidden', visibleCount > 0);
            }
        }

        // -------------------------------------------------------------
        // 2. VIEW SWITCHER (TOUCH CARDS VS TABLE)
        // -------------------------------------------------------------
        function switchStockView(view) {
            const cardsView = document.getElementById('stock-cards-view');
            const tableView = document.getElementById('stock-table-view');
            const btnCards = document.getElementById('btn-view-cards');
            const btnTable = document.getElementById('btn-view-table');

            if (view === 'table') {
                if (cardsView) cardsView.classList.add('hidden');
                if (tableView) tableView.classList.remove('hidden');
                if (btnTable) btnTable.className = "px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-[#F28C28] text-white shadow-sm flex items-center gap-1.5";
                if (btnCards) btnCards.className = "px-3 py-1.5 rounded-lg text-xs font-bold transition-all text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white flex items-center gap-1.5";
                localStorage.setItem('asterisko_stock_view', 'table');
            } else {
                if (cardsView) cardsView.classList.remove('hidden');
                if (tableView) tableView.classList.add('hidden');
                if (btnCards) btnCards.className = "px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-[#F28C28] text-white shadow-sm flex items-center gap-1.5";
                if (btnTable) btnTable.className = "px-3 py-1.5 rounded-lg text-xs font-bold transition-all text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white flex items-center gap-1.5";
                localStorage.setItem('asterisko_stock_view', 'cards');
            }
        }

        // Restore user view preference (Defaults to Cards for mobile/tablets)
        document.addEventListener('DOMContentLoaded', () => {
            const savedView = localStorage.getItem('asterisko_stock_view');
            if (savedView === 'table' && window.innerWidth >= 1024) {
                switchStockView('table');
            } else {
                switchStockView('cards');
            }
        });

        // -------------------------------------------------------------
        // 3. TOUCH QUICK ACTION MODAL LOGIC
        // -------------------------------------------------------------
        let currentQaItem = null;
        let currentQaAction = 'deduction';

        function openTouchQuickActionModal(id, name, category, currentStock, unit, defaultAction = 'deduction') {
            currentQaItem = { id, name, category, currentStock: parseFloat(currentStock), unit };
            currentQaAction = defaultAction;

            const modal = document.getElementById('quick-action-modal');
            if (!modal) return;

            // Fill header info
            document.getElementById('qa-item-id').value = id;
            document.getElementById('qa-item-name').textContent = name;
            document.getElementById('qa-category-badge').textContent = category;
            document.getElementById('qa-current-stock').textContent = currentStock.toFixed(2) + ' ' + unit;
            document.getElementById('qa-unit-badge').textContent = unit;
            document.getElementById('qa-max-hint').textContent = 'Max available: ' + currentStock.toFixed(2) + ' ' + unit;

            // Default amount
            const defaultAmt = unit === 'pcs' ? '1' : (currentStock > 1 ? '0.50' : '0.10');
            document.getElementById('qa-amount-input').value = defaultAmt;
            document.getElementById('qa-reason-input').value = '';

            // Render fast amount presets
            renderQaPresets(unit);

            // Switch to requested action tab
            setTouchActionTab(defaultAction);

            modal.classList.remove('hidden');
        }

        function closeTouchQuickActionModal() {
            const modal = document.getElementById('quick-action-modal');
            if (modal) modal.classList.add('hidden');
            currentQaItem = null;
        }

        function setTouchActionTab(action) {
            currentQaAction = action;
            document.getElementById('qa-action-input').value = action;

            const tabDeduct = document.getElementById('qa-tab-deduct');
            const tabWaste = document.getElementById('qa-tab-waste');
            const submitBtn = document.getElementById('qa-submit-btn');
            const submitText = document.getElementById('qa-submit-text');

            if (action === 'waste') {
                tabWaste.className = "py-2.5 rounded-xl text-xs font-bold transition-all bg-rose-600 text-white shadow-sm flex items-center justify-center gap-1.5";
                tabDeduct.className = "py-2.5 rounded-xl text-xs font-bold transition-all text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white flex items-center justify-center gap-1.5";
                submitBtn.className = "flex-1 py-3 px-5 rounded-2xl bg-rose-600 hover:bg-rose-700 active:scale-[0.98] text-white text-xs font-extrabold shadow-lg shadow-rose-600/20 transition-all flex items-center justify-center gap-1.5";
                submitText.textContent = "Confirm Waste Entry";
            } else {
                tabDeduct.className = "py-2.5 rounded-xl text-xs font-bold transition-all bg-[#F28C28] text-white shadow-sm flex items-center justify-center gap-1.5";
                tabWaste.className = "py-2.5 rounded-xl text-xs font-bold transition-all text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white flex items-center justify-center gap-1.5";
                submitBtn.className = "flex-1 py-3 px-5 rounded-2xl bg-[#F28C28] hover:bg-[#E07B18] active:scale-[0.98] text-white text-xs font-extrabold shadow-lg shadow-orange-500/20 transition-all flex items-center justify-center gap-1.5";
                submitText.textContent = "Confirm Stock Deduction";
            }

            renderQaReasonPresets(action);
        }

        function renderQaPresets(unit) {
            const container = document.getElementById('qa-presets-container');
            if (!container) return;
            container.innerHTML = '';

            let presets = [];
            if (unit === 'pcs' || unit === 'boxes' || unit === 'packs') {
                presets = [1, 2, 5, 10, 20];
            } else {
                presets = [0.05, 0.1, 0.25, 0.5, 1.0, 2.0];
            }

            presets.forEach(p => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = "px-2.5 py-1 rounded-lg text-[11px] font-bold bg-slate-100 hover:bg-slate-200 dark:bg-[#132E52] dark:hover:bg-[#1C3E69] text-slate-700 dark:text-slate-200 transition-colors";
                btn.textContent = '+' + p;
                btn.onclick = () => {
                    const inp = document.getElementById('qa-amount-input');
                    const curr = parseFloat(inp.value) || 0;
                    inp.value = (curr + p).toFixed(p < 1 ? 2 : (p % 1 === 0 ? 0 : 2));
                };
                container.appendChild(btn);
            });

            // Set Exact 1-Tap Reset
            const resetBtn = document.createElement('button');
            resetBtn.type = 'button';
            resetBtn.className = "px-2.5 py-1 rounded-lg text-[11px] font-bold bg-slate-200/60 dark:bg-[#07172C] text-slate-500 dark:text-slate-400 hover:text-slate-700";
            resetBtn.textContent = 'Clear';
            resetBtn.onclick = () => {
                document.getElementById('qa-amount-input').value = '';
            };
            container.appendChild(resetBtn);
        }

        function renderQaReasonPresets(action) {
            const container = document.getElementById('qa-reason-presets');
            if (!container) return;
            container.innerHTML = '';

            let reasons = [];
            if (action === 'waste') {
                reasons = [
                    'Grinder Calibration Spoilage',
                    'Milk Steaming Spillage',
                    'Expired / Sour Product',
                    'Damaged Goods'
                ];
            } else {
                reasons = [
                    'Shift Barista Usage',
                    'Recipe Formulation Test',
                    'Staff Beverage Duty',
                    'Shift Handover Adj'
                ];
            }

            reasons.forEach(r => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = "px-2.5 py-1 rounded-lg text-[10px] font-bold bg-slate-100 hover:bg-slate-200 dark:bg-[#132E52] dark:hover:bg-[#1C3E69] text-slate-700 dark:text-slate-200 transition-colors";
                btn.textContent = r;
                btn.onclick = () => {
                    document.getElementById('qa-reason-input').value = r;
                };
                container.appendChild(btn);
            });
        }

        function adjustQaAmount(delta) {
            const inp = document.getElementById('qa-amount-input');
            if (!inp) return;
            let val = parseFloat(inp.value) || 0;
            const step = (currentQaItem && currentQaItem.unit === 'pcs') ? 1 : (val >= 1 ? 1 : 0.1);
            val = Math.max(0, val + (delta * step));
            inp.value = (val % 1 === 0) ? val.toFixed(0) : val.toFixed(2);
        }

        // -------------------------------------------------------------
        // 4. MANAGER QUICK ROP MODAL
        // -------------------------------------------------------------
        function openQuickRopModal(id, name, currentRop, unit) {
            const modal = document.getElementById('rop-modal');
            if (!modal) return;
            document.getElementById('rop-item-id').value = id;
            document.getElementById('rop-item-name').textContent = name;
            document.getElementById('rop-input').value = currentRop;
            document.getElementById('rop-unit-label').textContent = unit;
            modal.classList.remove('hidden');
        }

        function closeRopModal() {
            const modal = document.getElementById('rop-modal');
            if (modal) modal.classList.add('hidden');
        }

        // -------------------------------------------------------------
        // 5. CHART.JS OVERVIEW (Theme-Aware for Light & Dark Mode)
        // -------------------------------------------------------------
        const ctx = document.getElementById('overviewChart').getContext('2d');
        function getChartThemePalette() {
            const isDark = document.documentElement.classList.contains('dark');
            return {
                inStockColor: isDark ? '#38BDF8' : '#0C2340',
                inStockHover: isDark ? '#7DD3FC' : '#132E52',
                targetColor: '#F28C28',
                targetHover: '#FFA347',
                tickColor: isDark ? '#CBD5E1' : '#64748B',
                legendColor: isDark ? '#E2E8F0' : '#475569',
                gridColor: isDark ? 'rgba(148, 163, 184, 0.18)' : 'rgba(148, 163, 184, 0.22)'
            };
        }

        const initialPalette = getChartThemePalette();
        const overviewChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Beans', 'Dairy', 'Syrups', 'Cups', 'Pastry', 'Teas'],
                datasets: [
                    {
                        label: 'In Stock',
                        data: [75, 82, 86, 78, 65, 95],
                        backgroundColor: initialPalette.inStockColor,
                        hoverBackgroundColor: initialPalette.inStockHover,
                        borderRadius: 6
                    },
                    {
                        label: 'Target',
                        data: [50, 60, 68, 70, 75, 55],
                        backgroundColor: initialPalette.targetColor,
                        hoverBackgroundColor: initialPalette.targetHover,
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: initialPalette.legendColor,
                            boxWidth: 12,
                            font: { size: 11, family: "'Plus Jakarta Sans', sans-serif" }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { color: initialPalette.tickColor },
                        grid: { color: initialPalette.gridColor }
                    },
                    x: {
                        ticks: { color: initialPalette.tickColor },
                        grid: { display: false }
                    }
                }
            }
        });

        // Dynamically update chart colors when switching between Dark and Light mode
        const themeObserver = new MutationObserver(() => {
            const palette = getChartThemePalette();
            overviewChart.data.datasets[0].backgroundColor = palette.inStockColor;
            overviewChart.data.datasets[0].hoverBackgroundColor = palette.inStockHover;
            overviewChart.options.plugins.legend.labels.color = palette.legendColor;
            overviewChart.options.scales.y.ticks.color = palette.tickColor;
            overviewChart.options.scales.y.grid.color = palette.gridColor;
            overviewChart.options.scales.x.ticks.color = palette.tickColor;
            overviewChart.update();
        });
        themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    </script>

    <?php if ($role !== 'Staff'): ?>
    <!-- Quick ROP Update Modal (Dedicated for Manager & Owner) -->
    <div id="rop-modal" class="fixed inset-0 modal-overlay z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#0C2340] rounded-2xl max-w-sm w-full p-6 shadow-2xl border border-slate-200 dark:border-[#15345C] relative animate-fade-in">
            <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-[#15345C] mb-4">
                <div class="flex items-center gap-2">
                    <span class="w-7 h-7 rounded-lg bg-[#F28C28]/15 text-[#F28C28] flex items-center justify-center font-bold text-sm">⚙️</span>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">Change Reorder Point</h3>
                </div>
                <button type="button" onclick="closeRopModal()" class="w-9 h-9 inline-flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-[#132E52]">✕</button>
            </div>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="update_rop">
                <input type="hidden" name="item_id" id="rop-item-id" value="">

                <div>
                    <span class="text-xs text-slate-400 block mb-0.5">Stock Item:</span>
                    <h4 id="rop-item-name" class="font-extrabold text-sm text-slate-900 dark:text-white">Item Name</h4>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1.5">New Reorder Point (ROP) *</label>
                    <div class="flex gap-2">
                        <input type="number" step="0.01" min="0" name="reorder_point" id="rop-input" required
                               class="w-full py-2.5 px-3 bg-slate-50 dark:bg-[#07172C] text-sm font-bold text-slate-900 dark:text-white border border-slate-200 dark:border-[#173863] rounded-xl focus:outline-none focus:border-[#F28C28]">
                        <span id="rop-unit-label" class="inline-flex items-center px-3 py-2.5 bg-slate-100 dark:bg-[#132E52] text-slate-700 dark:text-slate-300 rounded-xl text-xs font-bold">
                            kg
                        </span>
                    </div>
                    <span class="text-[11px] text-slate-400 mt-1 block">When inventory drops to or below this threshold, Low Stock alerts and purchase reorder recommendations are triggered.</span>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-slate-200 dark:border-[#15345C]">
                    <button type="button" onclick="closeRopModal()" class="btn-outline min-h-[46px] h-11 px-5 py-2.5 rounded-xl text-sm font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-[#132E52]">
                        Cancel
                    </button>
                    <button type="submit" class="btn-primary min-h-[46px] h-11 px-5 py-2.5 rounded-xl text-sm font-bold shadow-md">
                        Update ROP
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</body>
</html>
