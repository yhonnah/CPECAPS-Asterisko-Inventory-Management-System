<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/authguard.php';

// Accessible by Admin, Owner (and Staff if authorized)
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Physical Count Variance Report - Asterisko</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
        try {
            const saved = localStorage.getItem('theme');
            if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {}
    </script>

<link rel="stylesheet" href="../assets/app-enhancements.css">

</head>
<body class="bg-stone-100 dark:bg-stone-950 text-stone-800 dark:text-stone-200 min-h-screen">

    <nav class="bg-stone-900 dark:bg-black text-white px-4 py-3 flex justify-between items-center border-b border-stone-800">
        <div class="flex items-center gap-3">
            <a href="dashboard.php" class="text-emerald-400 hover:text-emerald-300 text-sm font-semibold flex items-center gap-1">
                &larr; Dashboard
            </a>
            <span class="text-stone-600">|</span>
            <span class="font-bold text-base sm:text-lg">Physical Count Variance Report</span>
            <span class="text-xs bg-emerald-600 px-2 py-0.5 rounded uppercase font-semibold"><?= htmlspecialchars($role) ?></span>
        </div>
        <div class="flex items-center gap-3 text-sm">
            <a href="count.php" class="bg-emerald-600 hover:bg-emerald-500 text-white text-xs px-2.5 py-1 rounded font-semibold transition">Perform Stock Count</a>
            <button id="theme-toggle" type="button" aria-label="Toggle dark mode"
                    class="p-1.5 rounded-full bg-stone-700 hover:bg-stone-600 text-emerald-300 transition">
                <svg class="w-4 h-4 hidden dark:block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                <svg class="w-4 h-4 block dark:hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
            </button>
            <a href="logout.php" class="bg-stone-700 hover:bg-stone-600 px-3 py-1 rounded text-xs">Logout</a>
        </div>
    </nav>

    <div class="max-w-6xl mx-auto p-4 space-y-6">

        <!-- Filter Form -->
        <div class="bg-white dark:bg-stone-900 rounded-xl shadow p-4 border border-stone-200 dark:border-stone-800">
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-4 gap-3 text-xs items-end">
                <div>
                    <label class="block font-semibold mb-1">Start Date</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>"
                           class="w-full p-2 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1">End Date</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>"
                           class="w-full p-2 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Filter by Item</label>
                    <select name="item_id" class="w-full p-2 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800">
                        <option value="0">All Items</option>
                        <?php foreach ($stockItems as $si): ?>
                            <option value="<?= $si['id'] ?>" <?= $selectedItemId == $si['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($si['NAME'] ?? $si['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="w-full bg-emerald-700 hover:bg-emerald-800 text-white font-semibold py-2 rounded-lg transition shadow">
                        Filter Report
                    </button>
                    <a href="variance_report.php" class="px-3 py-2 border border-stone-300 dark:border-stone-700 rounded-lg text-stone-600 dark:text-stone-400 hover:bg-stone-100 dark:hover:bg-stone-800 text-center">
                        Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Metric Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="bg-white dark:bg-stone-900 rounded-xl p-4 border border-stone-200 dark:border-stone-800 shadow-sm">
                <span class="text-xs uppercase text-stone-500 font-bold">Total Discrepancies</span>
                <p class="text-2xl font-bold font-mono text-stone-900 dark:text-stone-100 mt-1"><?= count($variances) ?></p>
                <p class="text-[11px] text-stone-500 mt-0.5">Physical counts deviating from system</p>
            </div>
            <div class="bg-white dark:bg-stone-900 rounded-xl p-4 border border-stone-200 dark:border-stone-800 shadow-sm">
                <span class="text-xs uppercase text-rose-600 font-bold">Shortages (Deficits)</span>
                <p class="text-2xl font-bold font-mono text-rose-600 mt-1"><?= $totalShortages ?></p>
                <p class="text-[11px] text-stone-500 mt-0.5">Physical count &lt; recorded stock</p>
            </div>
            <div class="bg-white dark:bg-stone-900 rounded-xl p-4 border border-stone-200 dark:border-stone-800 shadow-sm">
                <span class="text-xs uppercase text-emerald-600 font-bold">Surpluses (Excess)</span>
                <p class="text-2xl font-bold font-mono text-emerald-600 mt-1"><?= $totalSurpluses ?></p>
                <p class="text-[11px] text-stone-500 mt-0.5">Physical count &gt; recorded stock</p>
            </div>
        </div>

        <!-- Variance Table -->
        <div class="bg-white dark:bg-stone-900 rounded-xl shadow overflow-hidden border border-stone-200 dark:border-stone-800">
            <div class="p-4 border-b border-stone-200 dark:border-stone-800 flex justify-between items-center">
                <div>
                    <h2 class="text-sm font-bold text-stone-900 dark:text-stone-100">Non-Zero Physical Count Variances</h2>
                    <p class="text-xs text-stone-500">Historical records of discrepancies submitted by on-shift staff.</p>
                </div>
                <button onclick="window.print()" class="text-xs border border-stone-300 dark:border-stone-700 px-3 py-1.5 rounded-lg hover:bg-stone-100 dark:hover:bg-stone-800 font-medium">
                    Print / Export
                </button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-stone-50 dark:bg-stone-800 uppercase font-semibold text-stone-600 dark:text-stone-400 border-b border-stone-200 dark:border-stone-700">
                        <tr>
                            <th class="p-3">Date & Time</th>
                            <th class="p-3">Item Description</th>
                            <th class="p-3">System Recorded</th>
                            <th class="p-3">Actual Count</th>
                            <th class="p-3">Variance</th>
                            <th class="p-3">Reason Provided</th>
                            <th class="p-3">Staff / Barista</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200 dark:divide-stone-800">
                        <?php if (empty($variances)): ?>
                            <tr>
                                <td colspan="7" class="p-6 text-center text-stone-500">
                                    No count variances recorded for the selected date range. All physical counts matched system stock!
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($variances as $v): 
                                $diff = (float)$v['new_value'] - (float)$v['previous_value'];
                                $isShort = $diff < 0;
                            ?>
                                <tr class="hover:bg-stone-50 dark:hover:bg-stone-800/50">
                                    <td class="p-3 font-mono text-stone-500 whitespace-nowrap">
                                        <?= date('M d, Y h:i A', strtotime($v['created_at'])) ?>
                                    </td>
                                    <td class="p-3 font-semibold text-stone-900 dark:text-stone-100">
                                        <?= htmlspecialchars($v['item_name']) ?>
                                        <span class="block text-[10px] text-stone-500 font-normal"><?= htmlspecialchars($v['category']) ?></span>
                                    </td>
                                    <td class="p-3 font-mono">
                                        <?= number_format($v['previous_value'], 2) ?> <?= htmlspecialchars($v['unit']) ?>
                                    </td>
                                    <td class="p-3 font-mono font-semibold">
                                        <?= number_format($v['new_value'], 2) ?> <?= htmlspecialchars($v['unit']) ?>
                                    </td>
                                    <td class="p-3 font-mono font-bold whitespace-nowrap">
                                        <?php if ($isShort): ?>
                                            <span class="text-rose-600 bg-rose-50 dark:bg-rose-950/60 px-2 py-0.5 rounded border border-rose-200 dark:border-rose-900">
                                                <?= number_format($diff, 2) ?> <?= htmlspecialchars($v['unit']) ?> (Short)
                                            </span>
                                        <?php else: ?>
                                            <span class="text-emerald-600 bg-emerald-50 dark:bg-emerald-950/60 px-2 py-0.5 rounded border border-emerald-200 dark:border-emerald-900">
                                                +<?= number_format($diff, 2) ?> <?= htmlspecialchars($v['unit']) ?> (Surplus)
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3 text-stone-700 dark:text-stone-300 max-w-xs truncate" title="<?= htmlspecialchars($v['reason']) ?>">
                                        <?= htmlspecialchars($v['reason']) ?>
                                    </td>
                                    <td class="p-3 font-semibold text-stone-700 dark:text-stone-300">
                                        <?= htmlspecialchars($v['acting_user']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script>
        document.getElementById('theme-toggle').addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark');
            try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch (e) {}
        });
    </script>
</body>
</html>
