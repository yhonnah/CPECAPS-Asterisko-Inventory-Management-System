<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/authguard.php';
requireLogin();

$role = $_SESSION['role'];
$username = $_SESSION['username'];
$message = '';
$error = '';

// Handle Direct Quick Actions (Deduct / Waste)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    validateCsrfToken();
    $item_id = (int)($_POST['item_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $change_type = $_POST['action'] === 'waste' ? 'waste' : 'deduction'; 
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

// Fetch all active stock items
$items = $pdo->query("
    SELECT s.*, s.NAME AS NAME, s.NAME AS name, sup.NAME AS supplier_name 
    FROM stock_items s 
    LEFT JOIN suppliers sup ON s.supplier_id = sup.id 
    WHERE s.is_active = 1
    ORDER BY s.category, s.NAME
")->fetchAll(PDO::FETCH_ASSOC);

// Count low-stock items for top banner
$lowStockItems = array_filter($items, function($i) {
    return (float)$i['quantity'] <= (float)$i['reorder_point'];
});

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asterisko Inventory Management System</title>
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
<body class="bg-stone-100 dark:bg-stone-950 text-stone-800 dark:text-stone-200 min-h-screen transition-colors">

    <!-- Top Navigation Bar -->
    <nav class="bg-stone-900 dark:bg-black text-white px-4 py-3 border-b border-stone-800 shadow">
        <div class="max-w-7xl mx-auto flex flex-col md:flex-row justify-between items-center gap-3">
            <div class="flex items-center gap-2">
                <span class="font-bold text-lg text-emerald-500">Asterisko</span>
                <span class="text-xs bg-stone-800 px-2 py-0.5 rounded text-stone-300">Inventory</span>
                <span class="text-xs bg-emerald-600 px-2 py-0.5 rounded uppercase font-semibold"><?= htmlspecialchars($role) ?></span>
            </div>

            <!-- Role-Based Navigation Links -->
            <div class="flex flex-wrap items-center gap-1.5 sm:gap-2 text-xs">
                <!-- Links accessible to all roles (Barista/Staff, Admin, Owner) -->
                <a href="dashboard.php" class="bg-emerald-600 text-white px-2.5 py-1.5 rounded font-semibold">Dashboard</a>
                <a href="delivery_intake.php" class="bg-stone-800 hover:bg-stone-700 text-stone-200 px-2.5 py-1.5 rounded transition">Intake (OCR)</a>
                <a href="waste.php" class="bg-stone-800 hover:bg-stone-700 text-stone-200 px-2.5 py-1.5 rounded transition">Waste Log</a>
                <a href="count.php" class="bg-stone-800 hover:bg-stone-700 text-stone-200 px-2.5 py-1.5 rounded transition">Stock Count</a>

                <!-- Links restricted to Managers & Owners -->
                <?php if ($role !== 'Staff'): ?>
                    <a href="purchase_orders.php" class="bg-stone-800 hover:bg-stone-700 text-stone-200 px-2.5 py-1.5 rounded transition">Orders (PO)</a>
                    <a href="variance_report.php" class="bg-stone-800 hover:bg-stone-700 text-stone-200 px-2.5 py-1.5 rounded transition">Variances</a>
                    <a href="financial_report.php" class="bg-emerald-700/80 hover:bg-emerald-600 text-white px-2.5 py-1.5 rounded font-semibold transition">Financials</a>
                    <a href="manage_items.php" class="bg-stone-800 hover:bg-stone-700 text-stone-200 px-2.5 py-1.5 rounded transition">Items</a>
                    <a href="manage_suppliers.php" class="bg-stone-800 hover:bg-stone-700 text-stone-200 px-2.5 py-1.5 rounded transition">Suppliers</a>
                    <?php if ($role === 'Owner'): ?>
                        <a href="backup.php" class="bg-stone-800 hover:bg-stone-700 text-stone-200 px-2.5 py-1.5 rounded transition">Backups</a>
                        <a href="manage_users.php" class="bg-stone-800 hover:bg-stone-700 text-stone-200 px-2.5 py-1.5 rounded transition">Users</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="flex items-center gap-3 text-xs">
                <span class="hidden sm:inline">User: <b><?= htmlspecialchars($username) ?></b></span>
                <button id="theme-toggle" type="button" aria-label="Toggle dark mode"
                        class="p-1.5 rounded-full bg-stone-700 hover:bg-stone-600 text-emerald-300 transition">
                    <svg class="w-4 h-4 hidden dark:block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                    <svg class="w-4 h-4 block dark:hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
                </button>
                <a href="logout.php" class="bg-stone-700 hover:bg-stone-600 px-3 py-1.5 rounded transition">Logout</a>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto p-4 space-y-6">

        <!-- Offline Queue Status Banner -->
        <div id="offline-bar" class="hidden rounded-xl p-3 border bg-amber-50 dark:bg-amber-950/40 border-amber-300 dark:border-amber-800 text-amber-900 dark:text-amber-200 text-xs flex justify-between items-center shadow-sm">
            <div class="flex items-center gap-2">
                <span class="inline-block w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
                <span><b>Offline Mode:</b> Quick deductions or counts will be saved locally and auto-synced when connection returns.</span>
            </div>
            <button id="btn-sync-now" type="button" onclick="triggerOfflineSync()" class="hidden px-2.5 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded font-semibold text-[11px]">
                Sync <span id="queue-count">0</span> Items Now
            </button>
        </div>

        <!-- Automated Low-Stock Alert Banner -->
        <?php if (count($lowStockItems) > 0): ?>
            <div class="bg-rose-50 dark:bg-rose-950/40 border-l-4 border-rose-600 p-4 rounded-xl shadow-sm">
                <h2 class="text-rose-800 dark:text-rose-300 font-bold text-xs uppercase flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    Automated Low-Stock Alert
                </h2>
                <p class="text-rose-700 dark:text-rose-300 text-xs mt-1">
                    The following items are at or below their defined Reorder Point (ROP):
                    <span class="block mt-1 space-x-1 space-y-1">
                        <?php foreach ($lowStockItems as $ls): ?>
                            <span class="inline-block bg-rose-200 dark:bg-rose-900 text-rose-900 dark:text-rose-100 text-[11px] font-semibold px-2 py-0.5 rounded">
                                <?= htmlspecialchars($ls['NAME'] ?? $ls['name']) ?>: <b><?= $ls['quantity'] ?></b> <?= htmlspecialchars($ls['unit']) ?> (ROP: <?= $ls['reorder_point'] ?>)
                            </span>
                        <?php endforeach; ?>
                    </span>
                </p>
            </div>
        <?php endif; ?>

        <!-- Feedback Alerts -->
        <?php if ($message): ?>
            <div class="bg-emerald-50 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-200 border border-emerald-300 dark:border-emerald-800 p-3 rounded-xl text-xs font-medium"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="bg-rose-50 dark:bg-rose-950/60 text-rose-800 dark:text-rose-200 border border-rose-300 dark:border-rose-800 p-3 rounded-xl text-xs font-medium"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Current Stock Levels Table -->
        <div class="bg-white dark:bg-stone-900 rounded-xl shadow overflow-hidden border border-stone-200 dark:border-stone-800">
            <div class="p-4 border-b border-stone-200 dark:border-stone-800 flex flex-col sm:flex-row justify-between items-center gap-3">
                <div>
                    <h2 class="font-bold text-base text-stone-900 dark:text-stone-100">Live Inventory Stock Levels</h2>
                    <p class="text-xs text-stone-500">Atomic inventory updates reflect across all terminals without stale cache states.</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs font-semibold px-2.5 py-1 rounded bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-300">
                        <?= $role === 'Staff' ? '🔒 Financials Masked' : '🔓 Full Financial Visibility' ?>
                    </span>
                    <input type="text" id="filter-stock-input" placeholder="Search catalog..."
                           class="text-xs p-1.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 w-44">
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-xs text-left" id="stock-table">
                    <thead class="bg-stone-50 dark:bg-stone-800 uppercase font-semibold text-stone-600 dark:text-stone-400 border-b border-stone-200 dark:border-stone-700 text-[11px]">
                        <tr>
                            <th class="p-3">Item Name</th>
                            <th class="p-3">Category</th>
                            <th class="p-3">In-Stock</th>
                            <th class="p-3">ROP</th>
                            <th class="p-3">Status</th>
                            <!-- Financial Restriction: Staff never sees unit costs -->
                            <?php if ($role !== 'Staff'): ?>
                                <th class="p-3">Unit Cost</th>
                            <?php endif; ?>
                            <th class="p-3">Quick Deduct / Spoilage Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200 dark:divide-stone-800">
                        <?php foreach ($items as $item): 
                            $isLow = (float)$item['quantity'] <= (float)$item['reorder_point'];
                        ?>
                        <tr class="hover:bg-stone-50 dark:hover:bg-stone-800/50 <?= $isLow ? 'bg-rose-50/40 dark:bg-rose-950/20' : '' ?>">
                            <td class="p-3 font-semibold text-stone-900 dark:text-stone-100">
                                <?= htmlspecialchars($item['NAME'] ?? $item['name']) ?>
                            </td>
                            <td class="p-3">
                                <span class="bg-stone-200 dark:bg-stone-800 px-2 py-0.5 rounded text-[11px]"><?= htmlspecialchars($item['category']) ?></span>
                            </td>
                            <td class="p-3 font-mono font-bold text-sm">
                                <?= number_format($item['quantity'], 2) ?> <?= htmlspecialchars($item['unit']) ?>
                            </td>
                            <td class="p-3 font-mono text-stone-500">
                                <?= number_format($item['reorder_point'], 2) ?> <?= htmlspecialchars($item['unit']) ?>
                            </td>
                            <td class="p-3">
                                <?php if ($isLow): ?>
                                    <span class="bg-rose-600 text-white text-[10px] px-2 py-0.5 rounded font-bold">LOW STOCK</span>
                                <?php else: ?>
                                    <span class="bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 text-[10px] px-2 py-0.5 rounded font-bold">OK</span>
                                <?php endif; ?>
                            </td>

                            <!-- Role Guard on Price Column -->
                            <?php if ($role !== 'Staff'): ?>
                                <td class="p-3 font-mono">₱<?= number_format($item['unit_cost'], 2) ?></td>
                            <?php endif; ?>

                            <td class="p-3">
                                <form method="POST" class="flex flex-wrap items-center gap-1 quick-action-form" data-item-id="<?= $item['id'] ?>" data-unit="<?= htmlspecialchars($item['unit']) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                    <input type="number" step="0.01" name="amount" placeholder="Qty" required
                                           class="w-16 p-1 border border-stone-300 dark:border-stone-700 rounded text-xs bg-white dark:bg-stone-800">
                                    <input type="text" name="reason" placeholder="Reason (e.g. shift usage)" 
                                           class="w-36 p-1 border border-stone-300 dark:border-stone-700 rounded text-xs bg-white dark:bg-stone-800">
                                    <button type="submit" name="action" value="deduction" 
                                            class="bg-emerald-700 text-white text-xs px-2.5 py-1 rounded hover:bg-emerald-800 font-semibold shadow-sm">
                                        Deduct
                                    </button>
                                    <button type="submit" name="action" value="waste" 
                                            class="bg-rose-700 text-white text-xs px-2 py-1 rounded hover:bg-rose-800 font-semibold shadow-sm">
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

        <!-- Immutable Audit Trail with Item & Date Filtering -->
        <div class="bg-white dark:bg-stone-900 rounded-xl shadow overflow-hidden border border-stone-200 dark:border-stone-800">
            <div class="p-4 border-b border-stone-200 dark:border-stone-800 flex flex-col sm:flex-row justify-between sm:items-center gap-3">
                <div>
                    <h2 class="font-bold text-base text-stone-900 dark:text-stone-100">Immutable Audit Trail</h2>
                    <p class="text-xs text-stone-500">Read-only ledger of stock movements and access controls. No edit or delete permitted by any role.</p>
                </div>
            </div>

            <!-- Audit Filter Form -->
            <div class="p-3 bg-stone-50 dark:bg-stone-800/60 border-b border-stone-200 dark:border-stone-700">
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-5 gap-2 text-xs items-end">
                    <div>
                        <label class="block font-semibold mb-1 text-[11px]">Filter by Item</label>
                        <select name="audit_item" class="w-full p-1.5 rounded border border-stone-300 dark:border-stone-700 bg-white dark:bg-stone-800">
                            <option value="0">All Items</option>
                            <?php foreach ($items as $si): ?>
                                <option value="<?= $si['id'] ?>" <?= $filterItem == $si['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($si['NAME'] ?? $si['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block font-semibold mb-1 text-[11px]">Change Type</label>
                        <select name="audit_type" class="w-full p-1.5 rounded border border-stone-300 dark:border-stone-700 bg-white dark:bg-stone-800">
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
                        <label class="block font-semibold mb-1 text-[11px]">From Date</label>
                        <input type="date" name="audit_start" value="<?= htmlspecialchars($filterStart) ?>"
                               class="w-full p-1.5 rounded border border-stone-300 dark:border-stone-700 bg-white dark:bg-stone-800">
                    </div>
                    <div>
                        <label class="block font-semibold mb-1 text-[11px]">To Date</label>
                        <input type="date" name="audit_end" value="<?= htmlspecialchars($filterEnd) ?>"
                               class="w-full p-1.5 rounded border border-stone-300 dark:border-stone-700 bg-white dark:bg-stone-800">
                    </div>
                    <div class="flex gap-1.5">
                        <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-1.5 rounded transition">Filter</button>
                        <a href="dashboard.php" class="px-2.5 py-1.5 border border-stone-300 dark:border-stone-700 rounded text-center">Reset</a>
                    </div>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-xs text-left">
                    <thead class="bg-stone-50 dark:bg-stone-800 uppercase text-[11px] font-semibold text-stone-600 dark:text-stone-400 border-b border-stone-200 dark:border-stone-700">
                        <tr>
                            <th class="p-3">Timestamp</th>
                            <th class="p-3">Acting User</th>
                            <th class="p-3">Change Type</th>
                            <th class="p-3">Stock Item</th>
                            <th class="p-3">Previous &rarr; New Value</th>
                            <th class="p-3">Reason / Audit Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200 dark:divide-stone-800">
                        <?php if (empty($auditLogs)): ?>
                            <tr><td colspan="6" class="p-4 text-center text-stone-500">No audit logs found matching criteria.</td></tr>
                        <?php else: ?>
                            <?php foreach ($auditLogs as $log): ?>
                            <tr class="hover:bg-stone-50 dark:hover:bg-stone-800/50">
                                <td class="p-3 text-stone-500 font-mono whitespace-nowrap"><?= date('M d, Y h:i A', strtotime($log['created_at'])) ?></td>
                                <td class="p-3 font-semibold text-stone-900 dark:text-stone-100"><?= htmlspecialchars($log['acting_user']) ?></td>
                                <td class="p-3">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase 
                                        <?= $log['change_type'] === 'waste' ? 'bg-rose-100 dark:bg-rose-950 text-rose-800 dark:text-rose-300' : 
                                           ($log['change_type'] === 'unauthorized_access' ? 'bg-rose-700 text-white' : 
                                           ($log['change_type'] === 'delivery' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 
                                           ($log['change_type'] === 'rop_update' ? 'bg-blue-100 dark:bg-blue-950 text-blue-800 dark:text-blue-300' : 
                                            'bg-stone-200 dark:bg-stone-700 text-stone-800 dark:text-stone-200'))) ?>">
                                        <?= htmlspecialchars(str_replace('_', ' ', $log['change_type'])) ?>
                                    </span>
                                </td>
                                <td class="p-3 font-medium"><?= htmlspecialchars($log['item_name'] ?? 'System Security / Event') ?></td>
                                <td class="p-3 font-mono">
                                    <?php if ($log['previous_value'] !== null): ?>
                                        <?= number_format($log['previous_value'], 2) ?> &rarr; <b><?= number_format($log['new_value'], 2) ?></b> <?= htmlspecialchars($log['unit'] ?? '') ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 text-stone-600 dark:text-stone-300 max-w-sm truncate" title="<?= htmlspecialchars($log['reason']) ?>">
                                    <?= htmlspecialchars($log['reason']) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- Offline Queue & Auto-Sync Scripts -->
    <script>
        const OFFLINE_QUEUE_KEY = 'asterisko_offline_sync_queue';

        function getQueue() {
            const q = localStorage.getItem(OFFLINE_QUEUE_KEY);
            return q ? JSON.parse(q) : [];
        }

        function saveQueue(q) {
            localStorage.setItem(OFFLINE_QUEUE_KEY, JSON.stringify(q));
            updateOfflineUI();
        }

        function updateOfflineUI() {
            const bar = document.getElementById('offline-bar');
            const syncBtn = document.getElementById('btn-sync-now');
            const countEl = document.getElementById('queue-count');
            const queue = getQueue();

            if (!navigator.onLine) {
                bar.classList.remove('hidden');
                syncBtn.classList.add('hidden');
            } else if (queue.length > 0) {
                bar.classList.remove('hidden');
                syncBtn.classList.remove('hidden');
                countEl.textContent = queue.length;
            } else {
                bar.classList.add('hidden');
            }
        }

        window.addEventListener('online', () => {
            updateOfflineUI();
            triggerOfflineSync();
        });
        window.addEventListener('offline', updateOfflineUI);
        updateOfflineUI();

        // Intercept quick action forms if offline
        document.querySelectorAll('.quick-action-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!navigator.onLine) {
                    e.preventDefault();
                    const itemId = parseInt(this.dataset.itemId, 10);
                    const amount = parseFloat(this.querySelector('[name="amount"]').value);
                    const reason = this.querySelector('[name="reason"]').value.trim();
                    const actionType = e.submitter ? e.submitter.value : 'deduction';

                    if (isNaN(amount) || amount <= 0) {
                        alert("Please enter a valid quantity.");
                        return;
                    }

                    const queue = getQueue();
                    queue.push({
                        acting_user: <?= json_encode($username) ?>,
                        reason: reason || 'Offline quick deduction',
                        items: [{
                            item_id: itemId,
                            change_type: actionType,
                            amount: amount,
                            reason: reason
                        }],
                        timestamp: new Date().toISOString()
                    });
                    saveQueue(queue);

                    alert(`Offline: ${actionType.toUpperCase()} of ${amount} queued for background sync.`);
                    this.reset();
                }
            });
        });

        const CSRF_TOKEN = '<?= htmlspecialchars(getCsrfToken()) ?>';

        async function triggerOfflineSync() {
            const queue = getQueue();
            if (queue.length === 0 || !navigator.onLine) return;

            const remaining = [];
            let synced = 0;

            for (const payload of queue) {
                try {
                    const res = await fetch('../api/sync.php', {
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': CSRF_TOKEN
                        },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    if (data.success) {
                        synced++;
                    } else {
                        remaining.push(payload);
                    }
                } catch (err) {
                    remaining.push(payload);
                }
            }

            saveQueue(remaining);
            if (synced > 0) {
                alert(`Successfully synchronized ${synced} offline action(s)! Refreshing page...`);
                window.location.reload();
            }
        }

        // Live stock search filter
        document.getElementById('filter-stock-input').addEventListener('input', function(e) {
            const query = e.target.value.toLowerCase();
            document.querySelectorAll('#stock-table tbody tr').forEach(row => {
                row.style.display = row.innerText.toLowerCase().includes(query) ? '' : 'none';
            });
        });

        // Theme toggle
        document.getElementById('theme-toggle').addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark');
            try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch (e) {}
        });
    </script>
</body>
</html>
