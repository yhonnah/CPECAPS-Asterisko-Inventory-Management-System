<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/authguard.php';

// Strictly restricted to Admin and Owner
requireRole(['Admin', 'Owner'], $pdo);

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$message = '';
$messageType = '';

// Handle POS / Sales Record Insertion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    validateCsrfToken();
    if ($_POST['action'] === 'add_sales') {
        $sale_date = $_POST['sale_date'] ?? date('Y-m-d');
        $gross_sales = max(0, (float)($_POST['gross_sales'] ?? 0));
        $source = trim($_POST['source'] ?? 'Manual POS Entry');
        $notes = trim($_POST['notes'] ?? '');

        if ($gross_sales > 0) {
            $stmt = $pdo->prepare("INSERT INTO sales_records (sale_date, gross_sales, source, notes, recorded_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$sale_date, $gross_sales, $source, $notes, $username]);
            $message = "Recorded sales income of ₱" . number_format($gross_sales, 2) . " for {$sale_date}.";
            $messageType = 'success';
        }
    }
}

// Selected Month and Year
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$startDate = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
$endDate = date('Y-m-t', strtotime($startDate));
$monthName = date('F Y', strtotime($startDate));

// 1. Total Sales Income
$salesStmt = $pdo->prepare("SELECT COALESCE(SUM(gross_sales), 0) FROM sales_records WHERE sale_date BETWEEN ? AND ?");
$salesStmt->execute([$startDate, $endDate]);
$totalSales = (float)$salesStmt->fetchColumn();

// 2. Total Ordering Expenditures (from Purchase Orders)
$poStmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount), 0) 
    FROM purchase_orders 
    WHERE DATE(created_at) BETWEEN ? AND ? 
      AND status != 'cancelled'
");
$poStmt->execute([$startDate, $endDate]);
$totalProcurement = (float)$poStmt->fetchColumn();

// 3. Inventory Stock Consumption & Waste Breakdown (from Audit Logs)
$consumptionStmt = $pdo->prepare("
    SELECT 
        si.id,
        si.NAME AS NAME,
        si.NAME AS name,
        si.category,
        si.unit,
        si.unit_cost,
        si.quantity AS current_stock,
        COALESCE(SUM(CASE WHEN al.change_type = 'deduction' THEN (al.previous_value - al.new_value) ELSE 0 END), 0) AS total_deducted,
        COALESCE(SUM(CASE WHEN al.change_type = 'waste' THEN (al.previous_value - al.new_value) ELSE 0 END), 0) AS total_wasted,
        COALESCE(SUM(CASE WHEN al.change_type = 'delivery' THEN (al.new_value - al.previous_value) ELSE 0 END), 0) AS total_received
    FROM stock_items si
    LEFT JOIN audit_logs al ON al.item_id = si.id AND DATE(al.created_at) BETWEEN ? AND ?
    GROUP BY si.id
    ORDER BY si.category, si.NAME
");
$consumptionStmt->execute([$startDate, $endDate]);
$itemsBreakdown = $consumptionStmt->fetchAll(PDO::FETCH_ASSOC);

// Totals calculations
$totalConsumptionCost = 0.00;
$totalWasteCost = 0.00;
$totalStockValuation = 0.00;

foreach ($itemsBreakdown as &$item) {
    $item['deducted_cost'] = round($item['total_deducted'] * $item['unit_cost'], 2);
    $item['wasted_cost'] = round($item['total_wasted'] * $item['unit_cost'], 2);
    $item['current_valuation'] = round($item['current_stock'] * $item['unit_cost'], 2);

    $totalConsumptionCost += $item['deducted_cost'];
    $totalWasteCost += $item['wasted_cost'];
    $totalStockValuation += $item['current_valuation'];
}
unset($item);

$totalCostOfGoods = $totalConsumptionCost + $totalWasteCost;
$grossProfit = $totalSales - $totalCostOfGoods;
$profitMargin = $totalSales > 0 ? ($grossProfit / $totalSales) * 100 : 0;

// Handle Excel / XLSX Export
if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    $filename = "Asterisko_Financial_Report_{$selectedYear}_{$selectedMonth}.xls";
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    ?>
    <Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
              xmlns:x="urn:schemas-microsoft-com:office:excel"
              xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
        <Styles>
            <Style ss:ID="Header"><Font ss:Bold="1" ss:Size="12"/><Interior ss:Color="#D97706" ss:Pattern="Solid"/></Style>
            <Style ss:ID="Bold"><Font ss:Bold="1"/></Style>
            <Style ss:ID="Currency"><NumberFormat ss:Format="₱#,##0.00"/></Style>
        </Styles>
        <Worksheet ss:Name="Financial Summary">
            <Table>
                <Row><Cell ss:StyleID="Header"><Data ss:Type="String">Asterisko Artisan Coffee - Monthly Financial Summary</Data></Cell></Row>
                <Row><Cell><Data ss:Type="String">Period: <?= $monthName ?></Data></Cell></Row>
                <Row><Cell><Data ss:Type="String">Generated By: <?= htmlspecialchars($username) ?> on <?= date('Y-m-d H:i') ?></Data></Cell></Row>
                <Row></Row>
                <Row><Cell ss:StyleID="Bold"><Data ss:Type="String">Financial Metric</Data></Cell><Cell ss:StyleID="Bold"><Data ss:Type="String">Amount (PHP)</Data></Cell></Row>
                <Row><Cell><Data ss:Type="String">Gross Income / POS Sales</Data></Cell><Cell ss:StyleID="Currency"><Data ss:Type="Number"><?= $totalSales ?></Data></Cell></Row>
                <Row><Cell><Data ss:Type="String">Inventory Consumed (COGS)</Data></Cell><Cell ss:StyleID="Currency"><Data ss:Type="Number"><?= $totalConsumptionCost ?></Data></Cell></Row>
                <Row><Cell><Data ss:Type="String">Recorded Waste / Spoilage</Data></Cell><Cell ss:StyleID="Currency"><Data ss:Type="Number"><?= $totalWasteCost ?></Data></Cell></Row>
                <Row><Cell ss:StyleID="Bold"><Data ss:Type="String">Gross Operating Profit</Data></Cell><Cell ss:StyleID="Currency"><Data ss:Type="Number"><?= $grossProfit ?></Data></Cell></Row>
                <Row><Cell><Data ss:Type="String">Profit Margin (%)</Data></Cell><Cell><Data ss:Type="Number"><?= round($profitMargin, 2) ?></Data></Cell></Row>
                <Row><Cell><Data ss:Type="String">Total Procurement / POs Placed</Data></Cell><Cell ss:StyleID="Currency"><Data ss:Type="Number"><?= $totalProcurement ?></Data></Cell></Row>
                <Row><Cell><Data ss:Type="String">Current Total Stock Valuation</Data></Cell><Cell ss:StyleID="Currency"><Data ss:Type="Number"><?= $totalStockValuation ?></Data></Cell></Row>
                <Row></Row>
                <Row><Cell ss:StyleID="Header"><Data ss:Type="String">Material & Inventory Consumption Breakdown</Data></Cell></Row>
                <Row>
                    <Cell ss:StyleID="Bold"><Data ss:Type="String">Item Name</Data></Cell>
                    <Cell ss:StyleID="Bold"><Data ss:Type="String">Category</Data></Cell>
                    <Cell ss:StyleID="Bold"><Data ss:Type="String">Unit Cost</Data></Cell>
                    <Cell ss:StyleID="Bold"><Data ss:Type="String">Received Qty</Data></Cell>
                    <Cell ss:StyleID="Bold"><Data ss:Type="String">Consumed Qty</Data></Cell>
                    <Cell ss:StyleID="Bold"><Data ss:Type="String">Consumed Value</Data></Cell>
                    <Cell ss:StyleID="Bold"><Data ss:Type="String">Wasted Qty</Data></Cell>
                    <Cell ss:StyleID="Bold"><Data ss:Type="String">Wasted Value</Data></Cell>
                    <Cell ss:StyleID="Bold"><Data ss:Type="String">On-Hand Stock</Data></Cell>
                    <Cell ss:StyleID="Bold"><Data ss:Type="String">Current Valuation</Data></Cell>
                </Row>
                <?php foreach ($itemsBreakdown as $ib): ?>
                    <Row>
                        <Cell><Data ss:Type="String"><?= htmlspecialchars($ib['NAME'] ?? $ib['name']) ?></Data></Cell>
                        <Cell><Data ss:Type="String"><?= htmlspecialchars($ib['category']) ?></Data></Cell>
                        <Cell ss:StyleID="Currency"><Data ss:Type="Number"><?= $ib['unit_cost'] ?></Data></Cell>
                        <Cell><Data ss:Type="Number"><?= $ib['total_received'] ?></Data></Cell>
                        <Cell><Data ss:Type="Number"><?= $ib['total_deducted'] ?></Data></Cell>
                        <Cell ss:StyleID="Currency"><Data ss:Type="Number"><?= $ib['deducted_cost'] ?></Data></Cell>
                        <Cell><Data ss:Type="Number"><?= $ib['total_wasted'] ?></Data></Cell>
                        <Cell ss:StyleID="Currency"><Data ss:Type="Number"><?= $ib['wasted_cost'] ?></Data></Cell>
                        <Cell><Data ss:Type="Number"><?= $ib['current_stock'] ?></Data></Cell>
                        <Cell ss:StyleID="Currency"><Data ss:Type="Number"><?= $ib['current_valuation'] ?></Data></Cell>
                    </Row>
                <?php endforeach; ?>
            </Table>
        </Worksheet>
    </Workbook>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial & Consumption Report - Asterisko</title>
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

    <!-- Top Navigation -->
    <nav class="bg-stone-900 dark:bg-black text-white px-4 py-3 flex justify-between items-center border-b border-stone-800">
        <div class="flex items-center gap-3">
            <a href="dashboard.php" class="text-emerald-400 hover:text-emerald-300 text-sm font-semibold flex items-center gap-1">
                &larr; Dashboard
            </a>
            <span class="text-stone-600">|</span>
            <span class="font-bold text-base sm:text-lg">Financial & Consumption Reporting</span>
            <span class="text-xs bg-emerald-600 px-2 py-0.5 rounded uppercase font-semibold"><?= htmlspecialchars($role) ?></span>
        </div>
        <div class="flex items-center gap-3 text-sm">
            <button id="theme-toggle" type="button" aria-label="Toggle dark mode"
                    class="p-1.5 rounded-full bg-stone-700 hover:bg-stone-600 text-emerald-300 transition">
                <svg class="w-4 h-4 hidden dark:block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                <svg class="w-4 h-4 block dark:hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
            </button>
            <a href="logout.php" class="bg-stone-700 hover:bg-stone-600 px-3 py-1 rounded text-xs">Logout</a>
        </div>
    </nav>

    <div class="max-w-6xl mx-auto p-4 space-y-6">

        <?php if ($message): ?>
            <div class="p-3.5 rounded-xl text-sm font-medium border <?= $messageType === 'success' ? 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-200 border-emerald-300 dark:border-emerald-800' : 'bg-rose-50 dark:bg-rose-950/60 text-rose-800 dark:text-rose-200 border-rose-300 dark:border-rose-800' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Month Filter Bar & Export -->
        <div class="bg-white dark:bg-stone-900 rounded-xl shadow p-4 border border-stone-200 dark:border-stone-800 flex flex-col sm:flex-row justify-between items-center gap-4">
            <form method="GET" class="flex flex-wrap items-center gap-3 text-xs w-full sm:w-auto">
                <label class="font-bold uppercase text-stone-500">Report Period:</label>
                <select name="month" class="p-2 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 font-medium">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $selectedMonth == $m ? 'selected' : '' ?>>
                            <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <select name="year" class="p-2 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 font-medium">
                    <?php for ($y = 2024; $y <= 2028; $y++): ?>
                        <option value="<?= $y ?>" <?= $selectedYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
                <button type="submit" class="bg-emerald-700 hover:bg-emerald-800 text-white px-4 py-2 rounded-lg font-semibold shadow">
                    Create Financial Report
                </button>
            </form>

            <div class="flex items-center gap-2 w-full sm:w-auto justify-end">
                <button type="button" onclick="openSalesModal()" class="bg-stone-100 hover:bg-stone-200 dark:bg-stone-800 dark:hover:bg-stone-700 text-stone-800 dark:text-stone-200 text-xs font-semibold px-3 py-2 rounded-lg border border-stone-300 dark:border-stone-700 transition">
                    + Log POS / Sales Income
                </button>
                <a href="financial_report.php?month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>&export=xlsx"
                   class="bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-semibold px-4 py-2 rounded-lg transition shadow flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Export to Excel (.xlsx)
                </a>
            </div>
        </div>

        <!-- High-Level Financial Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white dark:bg-stone-900 rounded-xl p-4 border border-stone-200 dark:border-stone-800 shadow-sm">
                <span class="text-xs uppercase text-emerald-600 font-bold">Gross Income (POS)</span>
                <p class="text-2xl font-bold font-mono text-stone-900 dark:text-stone-100 mt-1">₱<?= number_format($totalSales, 2) ?></p>
                <p class="text-[11px] text-stone-500 mt-0.5">Recorded store revenues in <?= $monthName ?></p>
            </div>
            <div class="bg-white dark:bg-stone-900 rounded-xl p-4 border border-stone-200 dark:border-stone-800 shadow-sm">
                <span class="text-xs uppercase text-stone-500 font-bold">Cost of Goods (COGS)</span>
                <p class="text-2xl font-bold font-mono text-stone-900 dark:text-stone-100 mt-1">₱<?= number_format($totalCostOfGoods, 2) ?></p>
                <p class="text-[11px] text-stone-500 mt-0.5">Consumption + Waste value</p>
            </div>
            <div class="bg-white dark:bg-stone-900 rounded-xl p-4 border border-stone-200 dark:border-stone-800 shadow-sm">
                <span class="text-xs uppercase text-emerald-600 font-bold">Gross Operating Profit</span>
                <p class="text-2xl font-bold font-mono <?= $grossProfit >= 0 ? 'text-emerald-600' : 'text-rose-600' ?> mt-1">
                    ₱<?= number_format($grossProfit, 2) ?>
                </p>
                <p class="text-[11px] text-stone-500 mt-0.5">Margin: <?= number_format($profitMargin, 1) ?>%</p>
            </div>
            <div class="bg-white dark:bg-stone-900 rounded-xl p-4 border border-stone-200 dark:border-stone-800 shadow-sm">
                <span class="text-xs uppercase text-stone-500 font-bold">Procurement Orders</span>
                <p class="text-2xl font-bold font-mono text-stone-900 dark:text-stone-100 mt-1">₱<?= number_format($totalProcurement, 2) ?></p>
                <p class="text-[11px] text-stone-500 mt-0.5">Total POs issued in <?= $monthName ?></p>
            </div>
        </div>

        <!-- Inventory Consumption & Spoilage Table -->
        <div class="bg-white dark:bg-stone-900 rounded-xl shadow overflow-hidden border border-stone-200 dark:border-stone-800">
            <div class="p-4 border-b border-stone-200 dark:border-stone-800 flex justify-between items-center">
                <div>
                    <h2 class="text-sm font-bold text-stone-900 dark:text-stone-100">Monthly Stock Consumption & Material Valuation</h2>
                    <p class="text-xs text-stone-500">Calculated from immutable audit trail deductions, intake deliveries, and spoilage logs.</p>
                </div>
                <span class="text-xs font-mono font-bold bg-stone-100 dark:bg-stone-800 px-3 py-1 rounded">
                    Total Warehouse Valuation: ₱<?= number_format($totalStockValuation, 2) ?>
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-stone-50 dark:bg-stone-800 uppercase font-semibold text-stone-600 dark:text-stone-400 border-b border-stone-200 dark:border-stone-700 text-[11px]">
                        <tr>
                            <th class="p-3">Stock Item</th>
                            <th class="p-3">Unit Cost</th>
                            <th class="p-3">Deliveries In</th>
                            <th class="p-3">Consumed Qty</th>
                            <th class="p-3">Consumption Value</th>
                            <th class="p-3">Wasted Qty</th>
                            <th class="p-3">Waste Cost</th>
                            <th class="p-3 text-right">Current Valuation</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200 dark:divide-stone-800">
                        <?php foreach ($itemsBreakdown as $ib): ?>
                            <tr class="hover:bg-stone-50 dark:hover:bg-stone-800/50">
                                <td class="p-3 font-semibold text-stone-900 dark:text-stone-100">
                                    <?= htmlspecialchars($ib['NAME'] ?? $ib['name']) ?>
                                    <span class="block text-[10px] text-stone-500 font-normal"><?= htmlspecialchars($ib['category']) ?></span>
                                </td>
                                <td class="p-3 font-mono">₱<?= number_format($ib['unit_cost'], 2) ?></td>
                                <td class="p-3 font-mono text-emerald-600 font-semibold">+<?= number_format($ib['total_received'], 2) ?> <?= htmlspecialchars($ib['unit']) ?></td>
                                <td class="p-3 font-mono"><?= number_format($ib['total_deducted'], 2) ?> <?= htmlspecialchars($ib['unit']) ?></td>
                                <td class="p-3 font-mono font-semibold">₱<?= number_format($ib['deducted_cost'], 2) ?></td>
                                <td class="p-3 font-mono text-rose-600"><?= number_format($ib['total_wasted'], 2) ?> <?= htmlspecialchars($ib['unit']) ?></td>
                                <td class="p-3 font-mono text-rose-600">₱<?= number_format($ib['wasted_cost'], 2) ?></td>
                                <td class="p-3 text-right font-mono font-bold text-stone-900 dark:text-stone-100">
                                    ₱<?= number_format($ib['current_valuation'], 2) ?>
                                    <span class="block text-[10px] text-stone-500 font-normal"><?= number_format($ib['current_stock'], 2) ?> <?= htmlspecialchars($ib['unit']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-stone-50 dark:bg-stone-800/50 font-bold border-t-2 border-stone-300 dark:border-stone-700">
                        <tr>
                            <td colspan="4" class="p-3 text-right uppercase">Period Totals:</td>
                            <td class="p-3 font-mono text-stone-900 dark:text-stone-100">₱<?= number_format($totalConsumptionCost, 2) ?></td>
                            <td></td>
                            <td class="p-3 font-mono text-rose-600">₱<?= number_format($totalWasteCost, 2) ?></td>
                            <td class="p-3 text-right font-mono text-emerald-700 dark:text-emerald-400">₱<?= number_format($totalStockValuation, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

    </div>

    <!-- Log Sales Income Modal -->
    <div id="sales-modal" class="fixed inset-0 bg-black/60 hidden items-center justify-center p-4 z-50">
        <div class="bg-white dark:bg-stone-900 rounded-xl max-w-md w-full p-5 border border-stone-300 dark:border-stone-700 shadow-2xl">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-base font-bold text-stone-900 dark:text-stone-100">Record Store Gross Revenue (POS Entry)</h3>
                <button type="button" onclick="closeSalesModal()" class="text-stone-400 hover:text-stone-600 text-lg">&times;</button>
            </div>
            <form method="POST" class="space-y-3 text-xs">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="add_sales">

                <div>
                    <label class="block font-semibold mb-1">Date of Sales *</label>
                    <input type="date" name="sale_date" value="<?= date('Y-m-d') ?>" required
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Gross Sales Amount (₱) *</label>
                    <input type="number" step="0.01" name="gross_sales" required placeholder="e.g., 24500.00"
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 font-mono">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Source / Register</label>
                    <input type="text" name="source" value="Store POS Terminal 1"
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Notes (Optional)</label>
                    <input type="text" name="notes" placeholder="e.g., Saturday peak shift"
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800">
                </div>
                <div class="flex justify-end gap-2 pt-3 border-t border-stone-200 dark:border-stone-800">
                    <button type="button" onclick="closeSalesModal()" class="px-4 py-2 border border-stone-300 dark:border-stone-700 rounded-lg">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg font-semibold">Save Sales Record</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openSalesModal() {
            const m = document.getElementById('sales-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }
        function closeSalesModal() {
            const m = document.getElementById('sales-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }
        document.getElementById('theme-toggle').addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark');
            try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch (e) {}
        });
    </script>
</body>
</html>
