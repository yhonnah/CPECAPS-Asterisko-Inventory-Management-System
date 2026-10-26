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
                <div class="flex items-center gap-2.5">
                    <?php if ($role !== 'Staff'): ?>
                        <a href="manage_items.php" class="btn-primary inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold shadow-md">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            <span>+ Add Item</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Offline Queue Status Banner -->
            <div id="offline-bar" class="hidden rounded-xl p-3 border bg-amber-50 dark:bg-amber-950/40 border-amber-300 dark:border-amber-800 text-amber-900 dark:text-amber-200 text-xs flex justify-between items-center shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
                    <span><b>Offline Mode:</b> Quick deductions or counts will be saved locally and auto-synced when connection returns.</span>
                </div>
                <button id="btn-sync-now" type="button" onclick="triggerOfflineSync()" class="hidden px-2.5 py-1 bg-[#F28C28] hover:bg-[#E07B18] text-white rounded font-semibold text-[11px]">
                    Sync <span id="queue-count">0</span> Items Now
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
                        <a href="manage_items.php" class="text-xs font-bold text-[#F28C28] hover:text-[#E07B18] flex items-center gap-1">
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

            <!-- Live Inventory Table with Direct Quick Actions -->
            <div class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] overflow-hidden">
                <div class="p-5 border-b border-slate-200 dark:border-[#15345C] flex flex-col sm:flex-row justify-between items-center gap-3">
                    <div>
                        <h2 class="font-bold text-base text-slate-900 dark:text-white">Live Stock Management & Quick Actions</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Atomic inventory updates across all barista and management terminals.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <input type="text" id="filter-stock-input" placeholder="Search catalog..."
                               class="text-xs p-2 rounded-xl border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] w-48 focus:outline-none focus:border-[#F28C28]">
                    </div>
                </div>

                <div class="overflow-x-auto">
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
                                $isLow = (float)$item['quantity'] <= (float)$item['reorder_point'];
                            ?>
                            <tr class="<?= $isLow ? 'bg-rose-50/30 dark:bg-rose-950/20' : '' ?>">
                                <td class="font-bold text-slate-900 dark:text-white">
                                    <?= htmlspecialchars($item['NAME'] ?? $item['name']) ?>
                                </td>
                                <td>
                                    <span class="bg-slate-100 dark:bg-[#132E52] px-2 py-0.5 rounded text-[11px] text-slate-600 dark:text-slate-300">
                                        <?= htmlspecialchars($item['category']) ?>
                                    </span>
                                </td>
                                <td class="font-mono font-bold text-sm text-slate-800 dark:text-slate-100">
                                    <?= number_format($item['quantity'], 2) ?> <?= htmlspecialchars($item['unit']) ?>
                                </td>
                                <td class="font-mono text-slate-500">
                                    <div class="inline-flex items-center gap-1.5">
                                        <span class="font-semibold text-slate-700 dark:text-slate-300"><?= number_format($item['reorder_point'], 2) ?></span>
                                        <span class="text-[11px] text-slate-400"><?= htmlspecialchars($item['unit']) ?></span>
                                        <?php if ($role !== 'Staff'): ?>
                                            <button type="button" 
                                                    onclick='openQuickRopModal(<?= $item['id'] ?>, "<?= addslashes(htmlspecialchars($item['NAME'] ?? $item['name'])) ?>", <?= (float)$item['reorder_point'] ?>, "<?= addslashes(htmlspecialchars($item['unit'])) ?>")'
                                                    class="p-1 rounded text-slate-400 hover:text-[#F28C28] hover:bg-orange-50 dark:hover:bg-[#132E52] transition-colors" title="Change Reorder Point (ROP)">
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
                                    <form method="POST" class="flex flex-wrap items-center gap-1 quick-action-form" data-item-id="<?= $item['id'] ?>" data-unit="<?= htmlspecialchars($item['unit']) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                        <input type="number" step="0.01" name="amount" placeholder="Qty" required
                                               class="w-16 p-1.5 border border-slate-200 dark:border-[#173863] rounded-lg text-xs bg-slate-50 dark:bg-[#07172C]">
                                        <input type="text" name="reason" placeholder="Reason (e.g. usage)" 
                                               class="w-32 p-1.5 border border-slate-200 dark:border-[#173863] rounded-lg text-xs bg-slate-50 dark:bg-[#07172C]">
                                        <button type="submit" name="action" value="deduction" 
                                                class="bg-[#F28C28] hover:bg-[#E07B18] text-white text-xs px-2.5 py-1.5 rounded-lg font-bold shadow-sm">
                                            Deduct
                                        </button>
                                        <button type="submit" name="action" value="waste" 
                                                class="bg-rose-600 hover:bg-rose-700 text-white text-xs px-2 py-1.5 rounded-lg font-semibold shadow-sm">
                                            Waste
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
                    <form method="GET" class="grid grid-cols-1 sm:grid-cols-5 gap-3 text-xs items-end">
                        <div>
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
                        <div>
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
                        <div>
                            <label class="block font-bold mb-1 text-[11px] text-slate-600 dark:text-slate-300">From Date</label>
                            <input type="date" name="audit_start" value="<?= htmlspecialchars($filterStart) ?>"
                                   class="w-full p-2 rounded-xl border border-slate-200 dark:border-[#173863] bg-white dark:bg-[#07172C]">
                        </div>
                        <div>
                            <label class="block font-bold mb-1 text-[11px] text-slate-600 dark:text-slate-300">To Date</label>
                            <input type="date" name="audit_end" value="<?= htmlspecialchars($filterEnd) ?>"
                                   class="w-full p-2 rounded-xl border border-slate-200 dark:border-[#173863] bg-white dark:bg-[#07172C]">
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="w-full bg-[#0C2340] dark:bg-[#132E52] hover:bg-[#132E52] text-white font-bold py-2 rounded-xl transition text-xs shadow-sm">Filter</button>
                            <a href="dashboard.php" class="px-3 py-2 border border-slate-200 dark:border-[#173863] rounded-xl text-center text-xs hover:bg-slate-100 dark:hover:bg-[#132E52]">Reset</a>
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

    <!-- Script for Live Filter & Chart -->
    <script>
        // Catalog search filter
        document.getElementById('filter-stock-input').addEventListener('input', function() {
            const query = this.value.toLowerCase();
            document.querySelectorAll('#stock-table tbody tr').forEach(tr => {
                tr.style.display = tr.textContent.toLowerCase().includes(query) ? '' : 'none';
            });
        });

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

        // Initialize Chart.js matching Canva Slide 2 Overview
        const ctx = document.getElementById('overviewChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Beans', 'Dairy', 'Syrups', 'Cups', 'Pastry', 'Teas'],
                datasets: [
                    {
                        label: 'In Stock',
                        data: [75, 82, 86, 78, 65, 95],
                        backgroundColor: '#0C2340',
                        borderRadius: 6
                    },
                    {
                        label: 'Target',
                        data: [50, 60, 68, 70, 75, 55],
                        backgroundColor: '#F28C28',
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
                        labels: { boxWidth: 12, font: { size: 11, family: "'Plus Jakarta Sans', sans-serif" } }
                    }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(200, 200, 200, 0.15)' } },
                    x: { grid: { display: false } }
                }
            }
        });
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
                <button type="button" onclick="closeRopModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1">✕</button>
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

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-200 dark:border-[#15345C]">
                    <button type="button" onclick="closeRopModal()" class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-[#132E52]">
                        Cancel
                    </button>
                    <button type="submit" class="btn-primary px-5 py-2 rounded-xl text-xs font-bold shadow-md">
                        Update ROP
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</body>
</html>
