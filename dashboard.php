<?php
require 'db.php';
require 'authguard.php';
requireLogin();

$role = $_SESSION['role'];
$username = $_SESSION['username'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $item_id = (int)$_POST['item_id'];
    $amount = (float)$_POST['amount'];
    $change_type = $_POST['action'] === 'waste' ? 'waste' : 'deduction'; 
    $reason = trim($_POST['reason'] ?? 'Shift usage');

    if ($amount <= 0) {
        $error = "Quantity must be greater than zero.";
    } else {
        try {
            // ACID Transaction
            $pdo->beginTransaction();

            // 1. Lock row and get current quantity
            $stmt = $pdo->prepare("SELECT quantity, name FROM stock_items WHERE id = ? FOR UPDATE");
            $stmt->execute([$item_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                throw new Exception("Item not found.");
            }

            $prev_qty = (float)$item['quantity'];
            $new_qty = max(0, $prev_qty - $amount); // Prevent negative stock

            // 2. Update stock_items table
            $updateStmt = $pdo->prepare("UPDATE stock_items SET quantity = ? WHERE id = ?");
            $updateStmt->execute([$new_qty, $item_id]);

            // 3. Insert immutable record into audit_logs
            $auditStmt = $pdo->prepare("
                INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $auditStmt->execute([$item_id, $prev_qty, $new_qty, $change_type, $reason, $username]);

            // Commit transaction
            $pdo->commit();
            $message = "Recorded {$change_type} of {$amount} for {$item['name']}.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Transaction failed: " . $e->getMessage();
        }
    }
}

// Fetch all active stock items
$items = $pdo->query("
    SELECT s.*, sup.name AS supplier_name 
    FROM stock_items s 
    LEFT JOIN suppliers sup ON s.supplier_id = sup.id 
    WHERE s.is_active = 1
    ORDER BY s.category, s.name
")->fetchAll(PDO::FETCH_ASSOC);

// Count low-stock items for top banner
$lowStockItems = array_filter($items, function($i) {
    return (float)$i['quantity'] <= (float)$i['reorder_point'];
});

// Fetch recent Audit Trail logs - No edit/delete buttons permitted!
$auditLogs = $pdo->query("
    SELECT a.*, s.name AS item_name, s.unit 
    FROM audit_logs a 
    LEFT JOIN stock_items s ON a.item_id = s.id 
    ORDER BY a.created_at DESC LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asterisko Inventory Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };

        // Runs before paint so there's no light-mode flash
        try {
            const saved = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (saved === 'dark' || (!saved && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {}
    </script>
</head>
<body class="bg-stone-100 dark:bg-stone-950 text-stone-800 dark:text-stone-200 min-h-screen transition-colors">

    <!-- Top Navigation Bar -->
    <nav class="bg-stone-900 dark:bg-black text-white px-4 py-3 flex justify-between items-center border-b border-stone-900 dark:border-stone-800">
        <div>
            <span class="font-bold text-lg"> Asterisko Inventory</span>
            <span class="ml-2 text-xs bg-amber-600 px-2 py-0.5 rounded uppercase"><?= htmlspecialchars($role) ?></span>
        </div>
        <div class="flex items-center gap-4 text-sm">
            <span>User: <b><?= htmlspecialchars($username) ?></b></span>
            <!-- Closing Shift Count -->
            <a href="count.php" class="bg-amber-600 hover:bg-amber-500 text-white text-xs px-2.5 py-1 rounded font-semibold transition">Closing Count</a>
            <!-- Test button direct URL protection -->
            <a href="financial_report.php" class="bg-amber-600 hover:bg-amber-500 text-white text-xs px-2.5 py-1 rounded font-semibold transition">Financials</a>

            <!-- Dark Mode Toggle -->
            <button id="theme-toggle" type="button" aria-label="Toggle dark mode"
                    class="p-1.5 rounded-full bg-stone-700 hover:bg-stone-600 text-amber-300 transition">
                <svg class="w-4 h-4 hidden dark:block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="4"/>
                    <path stroke-linecap="round" d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>
                </svg>
                <svg class="w-4 h-4 block dark:hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>
                </svg>
            </button>

            <a href="logout.php" class="bg-stone-700 hover:bg-stone-600 px-3 py-1 rounded">Logout</a>
        </div>
    </nav>

    <div class="max-w-5xl mx-auto p-4 space-y-6">

        <!-- Automated Low-Stock Alert Banner -->
        <?php if (count($lowStockItems) > 0): ?>
            <div class="bg-red-50 dark:bg-red-950/40 border-l-4 border-red-600 dark:border-red-500 p-4 rounded shadow-sm">
                <h2 class="text-red-800 dark:text-red-300 font-bold text-sm uppercase">⚠️ Low-Stock Alert</h2>
                <p class="text-red-700 dark:text-red-300 text-sm mt-1">
                    The following items are at or below their Reorder Point (ROP):
                    <?php foreach ($lowStockItems as $ls): ?>
                        <span class="inline-block bg-red-200 dark:bg-red-900 text-red-900 dark:text-red-100 text-xs font-semibold px-2 py-0.5 rounded mr-1 mt-1">
                            <?= htmlspecialchars($ls['NAME']) ?> (<?= $ls['quantity'] ?> <?= htmlspecialchars($ls['unit']) ?> / ROP: <?= $ls['reorder_point'] ?>)
                        </span>
                    <?php endforeach; ?>
                </p>
            </div>
        <?php endif; ?>

        <!-- Feedback Messages -->
        <?php if ($message): ?>
            <div class="bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-300 p-3 rounded text-sm font-medium"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-300 p-3 rounded text-sm font-medium"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Stock Level Table -->
        <div class="bg-white dark:bg-stone-900 rounded-xl shadow overflow-hidden border border-stone-200 dark:border-stone-800">
            <div class="p-4 border-b border-stone-200 dark:border-stone-800 flex justify-between items-center">
                <h2 class="font-bold text-lg">Current Stock Levels</h2>
                <span class="text-xs text-stone-500 dark:text-stone-400">
                    <?= $role === 'Staff' ? '🔒 Prices hidden' : '🔓 Full financial visibility' ?>
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-stone-50 dark:bg-stone-950 text-stone-600 dark:text-stone-400 uppercase text-xs border-b border-stone-200 dark:border-stone-800">
                        <tr>
                            <th class="p-3">Item</th>
                            <th class="p-3">Category</th>
                            <th class="p-3">In Stock</th>
                            <th class="p-3">ROP</th>
                            <th class="p-3">Status</th>
                            <!-- Only show Unit Cost to Admin and Owner -->
                            <?php if ($role !== 'Staff'): ?>
                                <th class="p-3">Unit Cost</th>
                            <?php endif; ?>
                            <th class="p-3">Quick Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200 dark:divide-stone-800">
                        <?php foreach ($items as $item): 
                            $isLow = (float)$item['quantity'] <= (float)$item['reorder_point'];
                        ?>
                        <tr class="<?= $isLow ? 'bg-red-50/40 dark:bg-red-950/30' : '' ?>">
                            <td class="p-3 font-medium"><?= htmlspecialchars($item['NAME']) ?></td>
                            <td class="p-3 text-stone-500 dark:text-stone-400"><?= htmlspecialchars($item['category']) ?></td>
                            <td class="p-3 font-bold"><?= $item['quantity'] ?> <?= htmlspecialchars($item['unit']) ?></td>
                            <td class="p-3 text-stone-500 dark:text-stone-400"><?= $item['reorder_point'] ?> <?= htmlspecialchars($item['unit']) ?></td>
                            <td class="p-3">
                                <?php if ($isLow): ?>
                                    <span class="bg-red-600 text-white text-xs px-2 py-0.5 rounded font-semibold">LOW STOCK</span>
                                <?php else: ?>
                                    <span class="bg-emerald-100 dark:bg-emerald-900/50 text-emerald-800 dark:text-emerald-300 text-xs px-2 py-0.5 rounded font-semibold">OK</span>
                                <?php endif; ?>
                            </td>

                            <!-- Role Guard on Price Column -->
                            <?php if ($role !== 'Staff'): ?>
                                <td class="p-3 font-mono">₱<?= number_format($item['unit_cost'], 2) ?></td>
                            <?php endif; ?>

                            <td class="p-3">
                                <form method="POST" class="flex flex-wrap items-center gap-1">
                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                    <input type="number" step="0.01" name="amount" placeholder="Qty" required
                                           class="w-16 p-1 border border-stone-300 dark:border-stone-600 rounded text-xs bg-white dark:bg-stone-800 text-stone-900 dark:text-stone-100 placeholder-stone-400 dark:placeholder-stone-500">
                                    <input type="text" name="reason" placeholder="Reason (for waste)" 
                                           class="w-32 p-1 border border-stone-300 dark:border-stone-600 rounded text-xs bg-white dark:bg-stone-800 text-stone-900 dark:text-stone-100 placeholder-stone-400 dark:placeholder-stone-500">
                                    <button type="submit" name="action" value="deduction" 
                                            class="bg-amber-700 text-white text-xs px-2 py-1 rounded hover:bg-amber-800">
                                        Deduct
                                    </button>
                                    <button type="submit" name="action" value="waste" 
                                            class="bg-rose-700 text-white text-xs px-2 py-1 rounded hover:bg-rose-800">
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

        <!-- Immutable Audit Trail -->
        <div class="bg-white dark:bg-stone-900 rounded-xl shadow overflow-hidden border border-stone-200 dark:border-stone-800">
            <div class="p-4 border-b border-stone-200 dark:border-stone-800">
                <h2 class="font-bold text-lg">Audit Trail</h2>
                <p class="text-xs text-stone-500 dark:text-stone-400">Read-only log of all stock adjustments and security events. Cannot be edited or deleted by any role.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-left">
                    <thead class="bg-stone-50 dark:bg-stone-950 text-stone-600 dark:text-stone-400 uppercase text-xs border-b border-stone-200 dark:border-stone-800">
                        <tr>
                            <th class="p-3">Timestamp</th>
                            <th class="p-3">User</th>
                            <th class="p-3">Type</th>
                            <th class="p-3">Item</th>
                            <th class="p-3">Prev &rarr; New</th>
                            <th class="p-3">Reason / Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200 dark:divide-stone-800">
                        <?php foreach ($auditLogs as $log): ?>
                        <tr>
                            <td class="p-3 text-stone-500 dark:text-stone-400"><?= htmlspecialchars($log['created_at']) ?></td>
                            <td class="p-3 font-semibold"><?= htmlspecialchars($log['acting_user']) ?></td>
                            <td class="p-3">
                                <span class="px-2 py-0.5 rounded font-semibold uppercase 
                                    <?= $log['change_type'] === 'waste' ? 'bg-rose-100 dark:bg-rose-900/50 text-rose-800 dark:text-rose-300' : 
                                       ($log['change_type'] === 'unauthorized_access' ? 'bg-red-600 text-white' : 'bg-stone-200 dark:bg-stone-700 text-stone-800 dark:text-stone-200') ?>">
                                    <?= htmlspecialchars($log['change_type']) ?>
                                </span>
                            </td>
                            <td class="p-3"><?= htmlspecialchars($log['item_name'] ?? 'System / Endpoint') ?></td>
                            <td class="p-3 font-mono">
                                <?php if ($log['previous_value'] !== null): ?>
                                    <?= $log['previous_value'] ?> &rarr; <b><?= $log['new_value'] ?></b> <?= htmlspecialchars($log['unit'] ?? '') ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="p-3 text-stone-600 dark:text-stone-300"><?= htmlspecialchars($log['reason']) ?></td>
                        </tr>
                        <?php endforeach; ?>
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