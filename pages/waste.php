<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/authguard.php';
requireLogin();

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$message = '';
$messageType = '';

// Handle Waste / Spoilage Deduction
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $item_id = (int)($_POST['item_id'] ?? 0);
    $waste_qty = round((float)($_POST['quantity'] ?? 0), 2);
    $reason_preset = trim($_POST['reason_preset'] ?? 'Spoilage');
    $reason_custom = trim($_POST['reason_custom'] ?? '');
    $full_reason = $reason_custom !== '' ? "{$reason_preset}: {$reason_custom}" : $reason_preset;

    if ($item_id <= 0 || $waste_qty <= 0) {
        $message = "Please select an item and enter a valid waste quantity greater than 0.";
        $messageType = "error";
    } elseif ($full_reason === '') {
        $message = "Please provide or select a reason for the waste/spoilage entry.";
        $messageType = "error";
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT id, NAME, NAME AS name, quantity, unit FROM stock_items WHERE id = ? FOR UPDATE");
            $stmt->execute([$item_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                throw new Exception("Stock item not found.");
            }

            $prevQty = (float)$item['quantity'];
            $newQty = max(0, round($prevQty - $waste_qty, 2));

            // 1. Deduct stock quantity
            $update = $pdo->prepare("UPDATE stock_items SET quantity = ? WHERE id = ?");
            $update->execute([$newQty, $item_id]);

            // 2. Insert into audit_logs (change_type = 'waste')
            $audit = $pdo->prepare("
                INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
                VALUES (?, ?, ?, 'waste', ?, ?)
            ");
            $auditReason = "{$full_reason} (-{$waste_qty} {$item['unit']})";
            $audit->execute([$item_id, $prevQty, $newQty, $auditReason, $username]);

            $pdo->commit();

            $itemName = $item['NAME'] ?? $item['name'] ?? 'Item';
            $message = "Logged waste of {$waste_qty} {$item['unit']} for '{$itemName}'. Balance updated to {$newQty} {$item['unit']}.";
            $messageType = "success";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "Failed to log waste: " . $e->getMessage();
            $messageType = "error";
        }
    }
}

// Fetch active stock items
$stockItems = $pdo->query("SELECT id, NAME, NAME AS name, category, quantity, unit FROM stock_items WHERE is_active = 1 ORDER BY category, NAME")->fetchAll(PDO::FETCH_ASSOC);

// Fetch waste logs for history
$wasteLogs = $pdo->query("
    SELECT al.*, si.NAME AS item_name, si.unit
    FROM audit_logs al
    JOIN stock_items si ON si.id = al.item_id
    WHERE al.change_type = 'waste'
    ORDER BY al.created_at DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

// Calculate Canva Slide 7 Metrics
$todayWasteLogs = array_filter($wasteLogs, fn($l) => substr($l['created_at'], 0, 10) === date('Y-m-d'));
$totalEntriesToday = count($todayWasteLogs);

$totalQtyWastedToday = 0;
foreach ($todayWasteLogs as $twl) {
    $diff = max(0, (float)$twl['previous_value'] - (float)$twl['new_value']);
    $totalQtyWastedToday += $diff;
}

$commonReasonQuery = $pdo->query("
    SELECT reason, count(*) as cnt 
    FROM audit_logs 
    WHERE change_type = 'waste' 
    GROUP BY reason 
    ORDER BY cnt DESC 
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
$mostCommonReason = 'Spoilage';
if ($commonReasonQuery && !empty($commonReasonQuery['reason'])) {
    $parts = explode('(', $commonReasonQuery['reason']);
    $mostCommonReason = trim($parts[0]);
}

$activePage = 'waste';
$pageTitle = 'Waste & Spoilage - Asterisko Inventory';
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

            <!-- Title & Top Action (Canva Slide 7 Spec) -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">Waste & Spoilage</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Record discarded stock, select a reason, and review waste history.</p>
                </div>
                <button onclick="openWasteModal()" class="btn-primary inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold shadow-md">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span>+ Log Waste</span>
                </button>
            </div>

            <!-- Messages Feedback -->
            <?php if ($message): ?>
                <div class="p-4 rounded-xl text-sm font-medium flex items-center gap-2 <?= $messageType === 'success' ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-200 border border-emerald-300 dark:border-emerald-800' : 'bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-200 border border-rose-300 dark:border-rose-800' ?>">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $messageType === 'success' ? 'M5 13l4 4L19 7' : 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z' ?>"/></svg>
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
            <?php endif; ?>

            <!-- 3 Metric Cards (Exact Canva Slide 7 Spec) -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6">
                <!-- Card 1: Entries Today -->
                <div class="stat-card p-5" style="--stat-accent: #0C2340;">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Entries Today</span>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight"><?= sprintf('%02d', $totalEntriesToday) ?></span>
                        <p class="text-xs text-slate-400 mt-1">Logged across all shifts</p>
                    </div>
                </div>

                <!-- Card 2: Quantity Wasted -->
                <div class="stat-card p-5" style="--stat-accent: #EF4444;">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Quantity Wasted</span>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-rose-600 dark:text-rose-400 tracking-tight"><?= number_format($totalQtyWastedToday, 1) ?> units</span>
                        <p class="text-xs text-rose-500 font-semibold mt-1">Recorded today</p>
                    </div>
                </div>

                <!-- Card 3: Most Common Reason -->
                <div class="stat-card p-5" style="--stat-accent: #F28C28;">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Most Common Reason</span>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-[#F28C28] tracking-tight"><?= htmlspecialchars($mostCommonReason) ?></span>
                        <p class="text-xs text-slate-400 mt-1">Primary waste cause this month</p>
                    </div>
                </div>
            </div>

            <!-- Waste Log Table (Canva Slide 7 Spec) -->
            <div class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] overflow-hidden">
                <div class="p-5 border-b border-slate-200 dark:border-[#15345C] flex flex-col sm:flex-row justify-between items-center gap-3">
                    <div>
                        <h2 class="font-bold text-base text-slate-900 dark:text-white">Waste Log</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Audit-backed history of discarded stock and operational waste</p>
                    </div>
                    <div class="flex items-center gap-2 w-full sm:w-auto">
                        <input type="text" id="waste-search" placeholder="Search item or reason..." 
                               class="text-xs p-2 rounded-xl border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] w-full sm:w-56 focus:outline-none focus:border-[#F28C28]">
                        <select id="timeframe-filter" class="text-xs p-2 rounded-xl border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C]">
                            <option value="all">All Time</option>
                            <option value="today">Today</option>
                        </select>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left" id="waste-table">
                        <thead>
                            <tr>
                                <th>Date / Shift</th>
                                <th>Stock Item</th>
                                <th>Quantity</th>
                                <th>Reason</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($wasteLogs) === 0): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-8 text-slate-400">No waste or spoilage records logged yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($wasteLogs as $log): 
                                    $diff = max(0, (float)$log['previous_value'] - (float)$log['new_value']);
                                    $reasonText = htmlspecialchars($log['reason'] ?? 'Waste');
                                    $isSpoilage = stripos($reasonText, 'spoil') !== false;
                                    $isExpired = stripos($reasonText, 'expir') !== false;
                                    $isDamage = stripos($reasonText, 'damage') !== false;
                                ?>
                                <tr data-date="<?= substr($log['created_at'], 0, 10) ?>">
                                    <td class="font-mono text-slate-500 whitespace-nowrap">
                                        <?= date('M d, Y • h:i A', strtotime($log['created_at'])) ?>
                                    </td>
                                    <td class="font-bold text-slate-900 dark:text-white">
                                        <?= htmlspecialchars($log['item_name']) ?>
                                    </td>
                                    <td class="font-mono font-bold text-rose-600 dark:text-rose-400">
                                        -<?= number_format($diff, 2) ?> <?= htmlspecialchars($log['unit']) ?>
                                    </td>
                                    <td>
                                        <?php if ($isSpoilage || $isExpired): ?>
                                            <span class="badge-danger"><?= $isExpired ? 'Expired' : 'Spoilage' ?></span>
                                        <?php elseif ($isDamage): ?>
                                            <span class="badge-low">Damaged</span>
                                        <?php else: ?>
                                            <span class="badge-low">Shift Waste</span>
                                        <?php endif; ?>
                                        <span class="text-xs text-slate-500 ml-1"><?= $reasonText ?></span>
                                    </td>
                                    <td class="font-semibold text-slate-800 dark:text-slate-200">
                                        <?= htmlspecialchars($log['acting_user']) ?>
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

    <!-- Modal Dialog: Log Waste (Canva Slide 7 Spec) -->
    <div id="waste-modal" class="fixed inset-0 modal-overlay z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#0C2340] rounded-2xl max-w-md w-full p-6 sm:p-8 shadow-2xl border border-slate-200 dark:border-[#15345C] relative">
            <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-[#15345C] mb-5">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white">Log Waste & Spoilage</h3>
                <button type="button" onclick="closeWasteModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Select Stock Item *</label>
                    <select name="item_id" id="waste-item" required class="w-full">
                        <option value="">-- Choose Item --</option>
                        <?php foreach ($stockItems as $si): ?>
                            <option value="<?= $si['id'] ?>" data-unit="<?= htmlspecialchars($si['unit']) ?>">
                                <?= htmlspecialchars($si['NAME'] ?? $si['name']) ?> (Current: <?= $si['quantity'] ?> <?= $si['unit'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Quantity Discarded *</label>
                    <div class="relative">
                        <input type="number" step="0.01" name="quantity" required placeholder="0.00" class="w-full pr-12">
                        <span id="unit-label" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-xs text-slate-400 font-bold pointer-events-none">units</span>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Reason Category *</label>
                    <select name="reason_preset" required class="w-full">
                        <option value="Spoilage">Spoilage / Curdled / Sour</option>
                        <option value="Expired">Past Expiration Date</option>
                        <option value="Damaged">Damaged / Broken Packaging</option>
                        <option value="Spill/Barista Error">Spill / Preparation Error</option>
                        <option value="Quality Inspection">Failed Quality Check</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Detailed Notes (Optional)</label>
                    <textarea name="reason_custom" rows="2" placeholder="e.g. Milk carton dropped during morning rush..." class="w-full"></textarea>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200 dark:border-[#15345C] mt-6">
                    <button type="button" onclick="closeWasteModal()" class="px-5 py-2.5 rounded-xl border border-slate-300 dark:border-[#173863] text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-[#132E52]">
                        Cancel
                    </button>
                    <button type="submit" class="btn-primary px-6 py-2.5 rounded-xl text-sm font-bold shadow-md">
                        Record Waste
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('waste-item').addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            const unit = opt.getAttribute('data-unit') || 'units';
            document.getElementById('unit-label').textContent = unit;
        });

        document.getElementById('waste-search').addEventListener('input', filterTable);
        document.getElementById('timeframe-filter').addEventListener('change', filterTable);

        function filterTable() {
            const query = document.getElementById('waste-search').value.toLowerCase();
            const timeframe = document.getElementById('timeframe-filter').value;
            const todayStr = '<?= date('Y-m-d') ?>';

            document.querySelectorAll('#waste-table tbody tr').forEach(tr => {
                const textMatch = tr.textContent.toLowerCase().includes(query);
                const dateMatch = (timeframe === 'all') || (tr.getAttribute('data-date') === todayStr);
                tr.style.display = (textMatch && dateMatch) ? '' : 'none';
            });
        }

        function openWasteModal() {
            document.getElementById('waste-modal').classList.remove('hidden');
        }

        function closeWasteModal() {
            document.getElementById('waste-modal').classList.add('hidden');
        }
    </script>
</body>
</html>
