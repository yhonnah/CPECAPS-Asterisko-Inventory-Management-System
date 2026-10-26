<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/authguard.php';

// Strictly restricted to Admin/Manager and Owner
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

// 3. Inventory Stock Consumption & Waste Breakdown
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

// Canva Slide 8 Metrics
$totalTrackedItems = count($itemsBreakdown);
$totalLowStock = count(array_filter($itemsBreakdown, fn($i) => (float)$i['current_stock'] <= 5));
$totalWasteLogsCount = (int)$pdo->query("SELECT count(*) FROM audit_logs WHERE change_type = 'waste'")->fetchColumn();
$totalDeliveriesDone = (int)$pdo->query("SELECT count(*) FROM invoice_drafts WHERE status = 'confirmed'")->fetchColumn();

// Handle Excel / XLSX Export
if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    $filename = "Asterisko_Report_{$selectedYear}_{$selectedMonth}.xls";
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
            <Style ss:ID="Header"><Font ss:Bold="1" ss:Size="12"/><Interior ss:Color="#F28C28" ss:Pattern="Solid"/></Style>
            <Style ss:ID="Bold"><Font ss:Bold="1"/></Style>
            <Style ss:ID="Currency"><NumberFormat ss:Format="₱#,##0.00"/></Style>
        </Styles>
        <Worksheet ss:Name="Financial Summary">
            <Table>
                <Row><Cell ss:StyleID="Header"><Data ss:Type="String">Asterisko Inventory - Report for <?= $monthName ?></Data></Cell></Row>
                <Row><Cell><Data ss:Type="String">Gross Income / POS Sales</Data></Cell><Cell ss:StyleID="Currency"><Data ss:Type="Number"><?= $totalSales ?></Data></Cell></Row>
                <Row><Cell><Data ss:Type="String">Inventory Consumed (COGS)</Data></Cell><Cell ss:StyleID="Currency"><Data ss:Type="Number"><?= $totalConsumptionCost ?></Data></Cell></Row>
                <Row><Cell><Data ss:Type="String">Recorded Waste / Spoilage</Data></Cell><Cell ss:StyleID="Currency"><Data ss:Type="Number"><?= $totalWasteCost ?></Data></Cell></Row>
                <Row><Cell ss:StyleID="Bold"><Data ss:Type="String">Gross Operating Profit</Data></Cell><Cell ss:StyleID="Currency"><Data ss:Type="Number"><?= $grossProfit ?></Data></Cell></Row>
            </Table>
        </Worksheet>
    </Workbook>
    <?php
    exit;
}

$activePage = 'reports';
$pageTitle = 'Reports & Analytics - Asterisko Inventory';
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

            <!-- Title & Top Actions (Canva Slide 8 Spec) -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">Reports & Analytics</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Generate inventory, waste, and expenditure reports for selected dates.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="document.getElementById('sales-modal').classList.remove('hidden')" class="btn-secondary px-4 py-2.5 rounded-xl text-xs font-bold shadow-sm">
                        + Log POS Sales
                    </button>
                    <a href="variance_report.php" class="px-4 py-2.5 rounded-xl border border-slate-300 dark:border-[#173863] text-xs font-bold hover:bg-slate-100 dark:hover:bg-[#132E52] transition-colors">
                        Physical Variances &rarr;
                    </a>
                </div>
            </div>

            <!-- Messages Feedback -->
            <?php if ($message): ?>
                <div class="p-4 rounded-xl text-sm font-medium flex items-center gap-2 <?= $messageType === 'success' ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-200 border border-emerald-300 dark:border-emerald-800' : 'bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-200 border border-rose-300 dark:border-rose-800' ?>">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $messageType === 'success' ? 'M5 13l4 4L19 7' : 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z' ?>"/></svg>
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
            <?php endif; ?>

            <!-- Report Filter Card (Canva Slide 8 Spec) -->
            <div class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] p-5">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-3">Report Filters</h3>
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-4 gap-3 text-xs items-end">
                    <div>
                        <label class="block font-bold mb-1 text-slate-600 dark:text-slate-300">Reporting Month</label>
                        <select name="month" class="w-full">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $selectedMonth === $m ? 'selected' : '' ?>>
                                    <?= date('F', mktime(0, 0, 0, $m, 10)) ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold mb-1 text-slate-600 dark:text-slate-300">Reporting Year</label>
                        <select name="year" class="w-full">
                            <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                                <option value="<?= $y ?>" <?= $selectedYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="btn-primary w-full py-2.5 rounded-xl font-bold text-xs shadow-md">
                            Generate Report
                        </button>
                    </div>
                    <div>
                        <a href="?month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>&export=xlsx" class="btn-secondary w-full py-2.5 rounded-xl font-bold text-xs shadow-sm flex items-center justify-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            <span>Export Excel (.xls)</span>
                        </a>
                    </div>
                </form>
            </div>

            <!-- 4 Metric Cards (Canva Slide 8 Spec) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
                <!-- Card 1: Items Tracked -->
                <div class="stat-card p-5" style="--stat-accent: #0C2340;">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Items Tracked</span>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight"><?= sprintf('%02d', $totalTrackedItems) ?></span>
                        <p class="text-xs text-slate-400 mt-1">Active inventory lines</p>
                    </div>
                </div>

                <!-- Card 2: Low Stock Items -->
                <div class="stat-card p-5" style="--stat-accent: #EF4444;">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Low Stock Items</span>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-rose-600 dark:text-rose-400 tracking-tight"><?= sprintf('%02d', $totalLowStock) ?></span>
                        <p class="text-xs text-rose-500 font-semibold mt-1">Below threshold</p>
                    </div>
                </div>

                <!-- Card 3: Waste Entries -->
                <div class="stat-card p-5" style="--stat-accent: #F28C28;">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Waste Entries</span>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-[#F28C28] tracking-tight"><?= sprintf('%02d', $totalWasteLogsCount) ?></span>
                        <p class="text-xs text-slate-400 mt-1">Recorded spoilage logs</p>
                    </div>
                </div>

                <!-- Card 4: Deliveries -->
                <div class="stat-card p-5" style="--stat-accent: #10B981;">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Deliveries</span>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-emerald-600 dark:text-emerald-400 tracking-tight"><?= sprintf('%02d', $totalDeliveriesDone) ?></span>
                        <p class="text-xs text-emerald-600 dark:text-emerald-400 font-semibold mt-1">Confirmed intakes</p>
                    </div>
                </div>
            </div>

            <!-- Financial Profitability Summary -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="p-5 rounded-2xl bg-white dark:bg-[#0C2340] border border-slate-200/80 dark:border-[#15345C] shadow-sm">
                    <span class="text-xs font-bold text-slate-400 uppercase">Gross Sales (Income)</span>
                    <h3 class="text-2xl font-black text-emerald-600 dark:text-emerald-400 mt-1">₱<?= number_format($totalSales, 2) ?></h3>
                </div>
                <div class="p-5 rounded-2xl bg-white dark:bg-[#0C2340] border border-slate-200/80 dark:border-[#15345C] shadow-sm">
                    <span class="text-xs font-bold text-slate-400 uppercase">Cost of Goods (Consumed + Waste)</span>
                    <h3 class="text-2xl font-black text-rose-600 dark:text-rose-400 mt-1">₱<?= number_format($totalCostOfGoods, 2) ?></h3>
                </div>
                <div class="p-5 rounded-2xl bg-white dark:bg-[#0C2340] border border-slate-200/80 dark:border-[#15345C] shadow-sm">
                    <span class="text-xs font-bold text-slate-400 uppercase">Gross Operating Profit</span>
                    <h3 class="text-2xl font-black text-slate-900 dark:text-white mt-1">₱<?= number_format($grossProfit, 2) ?> <span class="text-xs font-bold text-[#F28C28]">(<?= round($profitMargin, 1) ?>%)</span></h3>
                </div>
            </div>

            <!-- Material & Inventory Consumption Table -->
            <div class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] overflow-hidden">
                <div class="p-5 border-b border-slate-200 dark:border-[#15345C]">
                    <h2 class="font-bold text-base text-slate-900 dark:text-white">Material & Consumption Breakdown for <?= $monthName ?></h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Detailed item-by-item accounting of intake, barista deductions, and spoilage values.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left">
                        <thead>
                            <tr>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Unit Cost</th>
                                <th>Received</th>
                                <th>Consumed</th>
                                <th>Consumed Val</th>
                                <th>Wasted</th>
                                <th>Wasted Val</th>
                                <th>On-Hand</th>
                                <th>Valuation</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($itemsBreakdown as $ib): ?>
                            <tr>
                                <td class="font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($ib['NAME'] ?? $ib['name']) ?></td>
                                <td><span class="px-2 py-0.5 rounded bg-slate-100 dark:bg-[#132E52] text-[11px]"><?= htmlspecialchars($ib['category']) ?></span></td>
                                <td class="font-mono">₱<?= number_format($ib['unit_cost'], 2) ?></td>
                                <td class="font-mono"><?= number_format($ib['total_received'], 1) ?> <?= htmlspecialchars($ib['unit']) ?></td>
                                <td class="font-mono"><?= number_format($ib['total_deducted'], 1) ?> <?= htmlspecialchars($ib['unit']) ?></td>
                                <td class="font-mono font-semibold">₱<?= number_format($ib['deducted_cost'], 2) ?></td>
                                <td class="font-mono text-rose-500"><?= number_format($ib['total_wasted'], 1) ?> <?= htmlspecialchars($ib['unit']) ?></td>
                                <td class="font-mono font-semibold text-rose-500">₱<?= number_format($ib['wasted_cost'], 2) ?></td>
                                <td class="font-mono font-bold"><?= number_format($ib['current_stock'], 1) ?> <?= htmlspecialchars($ib['unit']) ?></td>
                                <td class="font-mono font-bold text-slate-900 dark:text-white">₱<?= number_format($ib['current_valuation'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-50 dark:bg-[#07172C] font-bold">
                                <td colspan="5" class="text-right py-3 px-4 uppercase text-slate-500">Totals:</td>
                                <td class="py-3 px-4 font-mono text-slate-900 dark:text-white">₱<?= number_format($totalConsumptionCost, 2) ?></td>
                                <td></td>
                                <td class="py-3 px-4 font-mono text-rose-600 dark:text-rose-400">₱<?= number_format($totalWasteCost, 2) ?></td>
                                <td></td>
                                <td class="py-3 px-4 font-mono text-[#F28C28]">₱<?= number_format($totalStockValuation, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal: Add Sales Income -->
    <div id="sales-modal" class="fixed inset-0 modal-overlay z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#0C2340] rounded-2xl max-w-md w-full p-6 sm:p-8 shadow-2xl border border-slate-200 dark:border-[#15345C] relative">
            <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-[#15345C] mb-5">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white">Record Daily POS Sales</h3>
                <button type="button" onclick="document.getElementById('sales-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="add_sales">

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Sale Date *</label>
                    <input type="date" name="sale_date" value="<?= date('Y-m-d') ?>" required class="w-full">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Gross POS Revenue (₱) *</label>
                    <input type="number" step="0.01" min="0.01" name="gross_sales" required placeholder="e.g. 15450.00" class="w-full font-mono">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Sales Source</label>
                    <select name="source" class="w-full">
                        <option value="Front Counter POS">Front Counter POS</option>
                        <option value="Online / Delivery Aggregator">Online / Delivery Aggregator</option>
                        <option value="Catering / Event">Catering / Event</option>
                        <option value="Manual Entry">Manual Entry</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Notes</label>
                    <input type="text" name="notes" placeholder="Optional shift notes..." class="w-full">
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200 dark:border-[#15345C] mt-6">
                    <button type="button" onclick="document.getElementById('sales-modal').classList.add('hidden')" class="px-5 py-2.5 rounded-xl border border-slate-300 dark:border-[#173863] text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-[#132E52]">
                        Cancel
                    </button>
                    <button type="submit" class="btn-primary px-6 py-2.5 rounded-xl text-sm font-bold shadow-md">
                        Save Sales Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
