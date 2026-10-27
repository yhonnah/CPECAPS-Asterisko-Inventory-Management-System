<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/authguard.php';

// Accessible by Admin/Manager and Owner
requireRole(['Admin', 'Owner'], $pdo);

$username = $_SESSION['username'];
$role = $_SESSION['role'];

// Date range filters (default to current month)
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedItemId = !empty($_GET['item_id']) ? (int)$_GET['item_id'] : 0;

$query = "
    SELECT al.*, si.name AS item_name, si.unit, si.category
    FROM audit_logs al
    JOIN stock_items si ON si.id = al.item_id
    WHERE al.change_type = 'count_correction'
      AND (al.previous_value != al.new_value)
      AND DATE(al.created_at) BETWEEN ? AND ?
";
$params = [$startDate, $endDate];

if ($selectedItemId > 0) {
    $query .= " AND al.item_id = ?";
    $params[] = $selectedItemId;
}

$query .= " ORDER BY al.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$variances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch items for filter dropdown
$stockItems = $pdo->query("SELECT id, NAME, NAME AS name FROM stock_items ORDER BY NAME")->fetchAll(PDO::FETCH_ASSOC);

// Summary stats
$totalShortages = 0;
$totalSurpluses = 0;
foreach ($variances as $v) {
    $diff = (float)$v['new_value'] - (float)$v['previous_value'];
    if ($diff < 0) {
        $totalShortages++;
    } elseif ($diff > 0) {
        $totalSurpluses++;
    }
}

$activePage = 'reports';
$pageTitle = 'Variance Report - Asterisko Inventory';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>
<body class="bg-[#F4F7FB] dark:bg-[#071526] text-slate-800 dark:text-slate-100 min-h-screen">

    <!-- Left Sidebar -->
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <!-- Main Content Wrapper -->
    <div class="lg:pl-64 flex flex-col min-h-screen">
        
        <!-- Topbar -->
        <?php require_once __DIR__ . '/../includes/topbar.php'; ?>

        <!-- Canvas Body -->
        <main class="flex-1 p-4 sm:p-6 lg:p-8 space-y-6 max-w-7xl w-full mx-auto">

            <!-- Title & Quick Actions -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">Stock Count Variance Report</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Audit-tracked discrepancies between recorded book stock and physical barista counts.</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="count.php" class="btn-primary px-4 py-2.5 rounded-xl text-xs font-bold shadow-md">
                        + Perform Stock Count
                    </a>
                    <a href="financial_report.php" class="px-4 py-2.5 rounded-xl border border-slate-300 dark:border-[#173863] text-xs font-bold hover:bg-slate-100 dark:hover:bg-[#132E52]">
                        Financial Report &rarr;
                    </a>
                </div>
            </div>

            <!-- 3 Metric Cards (Canva Spec) -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6">
                <div class="stat-card p-5" style="--stat-accent: #0C2340;">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Total Discrepancies</span>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight"><?= sprintf('%02d', count($variances)) ?></span>
                        <p class="text-xs text-slate-400 mt-1">Variance events in period</p>
                    </div>
                </div>

                <div class="stat-card p-5" style="--stat-accent: #EF4444;">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Shortages (Deficit)</span>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-rose-600 dark:text-rose-400 tracking-tight"><?= sprintf('%02d', $totalShortages) ?></span>
                        <p class="text-xs text-rose-500 font-semibold mt-1">Physical count < recorded</p>
                    </div>
                </div>

                <div class="stat-card p-5" style="--stat-accent: #10B981;">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Surpluses (Excess)</span>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-emerald-600 dark:text-emerald-400 tracking-tight"><?= sprintf('%02d', $totalSurpluses) ?></span>
                        <p class="text-xs text-emerald-600 dark:text-emerald-400 font-semibold mt-1">Physical count > recorded</p>
                    </div>
                </div>
            </div>

            <!-- Filter Card -->
            <div class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] p-5">
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-4 gap-3 text-xs items-end">
                    <div>
                        <label class="block font-bold mb-1 text-slate-600 dark:text-slate-300">Filter by Item</label>
                        <select name="item_id" class="w-full">
                            <option value="0">All Items</option>
                            <?php foreach ($stockItems as $si): ?>
                                <option value="<?= $si['id'] ?>" <?= $selectedItemId == $si['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($si['NAME'] ?? $si['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold mb-1 text-slate-600 dark:text-slate-300">From Date</label>
                        <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="w-full">
                    </div>
                    <div>
                        <label class="block font-bold mb-1 text-slate-600 dark:text-slate-300">To Date</label>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="w-full">
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="btn-primary w-full py-2.5 rounded-xl font-bold text-xs shadow-md">Filter</button>
                        <a href="variance_report.php" class="px-4 py-2.5 border border-slate-300 dark:border-[#173863] rounded-xl text-center text-xs font-semibold hover:bg-slate-100 dark:hover:bg-[#132E52]">Reset</a>
                    </div>
                </form>
            </div>

            <!-- Variance Log Table -->
            <div class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] overflow-hidden">
                <div class="p-5 border-b border-slate-200 dark:border-[#15345C]">
                    <h2 class="font-bold text-base text-slate-900 dark:text-white">Audit-Tracked Physical Count Variances</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">All discrepancies auto-generate immutable audit logs upon shift count submission.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Stock Item</th>
                                <th>Recorded Book</th>
                                <th>Physical Count</th>
                                <th>Variance</th>
                                <th>Notes / Reason</th>
                                <th>Audited User</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($variances) === 0): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-8 text-slate-400">No count discrepancies recorded for this timeframe. Perfect count alignment!</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($variances as $var): 
                                    $diff = (float)$var['new_value'] - (float)$var['previous_value'];
                                    $isShort = $diff < 0;
                                ?>
                                <tr>
                                    <td class="font-mono text-slate-500 whitespace-nowrap"><?= htmlspecialchars($var['created_at']) ?></td>
                                    <td class="font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($var['item_name']) ?></td>
                                    <td class="font-mono"><?= number_format($var['previous_value'], 2) ?> <?= htmlspecialchars($var['unit']) ?></td>
                                    <td class="font-mono font-bold"><?= number_format($var['new_value'], 2) ?> <?= htmlspecialchars($var['unit']) ?></td>
                                    <td>
                                        <span class="inline-flex items-center gap-1 font-mono font-bold px-2.5 py-0.5 rounded-full text-xs <?= $isShort ? 'badge-danger' : 'badge-good' ?>">
                                            <?= $diff > 0 ? "+{$diff}" : "{$diff}" ?> <?= htmlspecialchars($var['unit']) ?>
                                        </span>
                                    </td>
                                    <td class="text-slate-600 dark:text-slate-300"><?= htmlspecialchars($var['reason']) ?></td>
                                    <td class="font-semibold text-slate-800 dark:text-slate-200"><?= htmlspecialchars($var['acting_user']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
