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
    $change_type = $_POST['action'] === 'waste' ? 'waste' : 'deduction'; // FR-11 vs FR-2
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

            // 2. Update stock_items table (FR-2 / FR-11)
            $updateStmt = $pdo->prepare("UPDATE stock_items SET quantity = ? WHERE id = ?");
            $updateStmt->execute([$new_qty, $item_id]);

            // 3. Insert immutable record into audit_logs (FR-12)
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

// Count low-stock items for top banner (FR-3)
$lowStockItems = array_filter($items, function($i) {
    return (float)$i['quantity'] <= (float)$i['reorder_point'];
});

// Fetch recent Audit Trail logs (FR-12) - No edit/delete buttons permitted!
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
</head>
<body class="bg-stone-100 text-stone-800 min-h-screen">

    <!-- Top Navigation Bar -->
    <nav class="bg-stone-900 text-white px-4 py-3 flex justify-between items-center">
        <div>
            <span class="font-bold text-lg">☕ Asterisko Inventory</span>
            <span class="ml-2 text-xs bg-amber-600 px-2 py-0.5 rounded uppercase"><?= htmlspecialchars($role) ?></span>
        </div>
        <div class="flex items-center gap-4 text-sm">
            <span>User: <b><?= htmlspecialchars($username) ?></b></span>
            <!-- Test button for FR-15 direct URL protection -->
            <a href="financial_report.php" class="text-amber-300 underline text-xs">Financials (FR-15 Test)</a>
            <a href="logout.php" class="bg-stone-700 hover:bg-stone-600 px-3 py-1 rounded">Logout</a>
        </div>
    </nav>

    <div class="max-w-5xl mx-auto p-4 space-y-6">

        <!-- FR-3: Automated Low-Stock Alert Banner -->
        <?php if (count($lowStockItems) > 0): ?>
            <div class="bg-red-50 border-l-4 border-red-600 p-4 rounded shadow-sm">
                <h2 class="text-red-800 font-bold text-sm uppercase">⚠️ Low-Stock Alert (FR-3)</h2>
                <p class="text-red-700 text-sm mt-1">
                    The following items are at or below their Reorder Point (ROP):
                    <?php foreach ($lowStockItems as $ls): ?>
                        <span class="inline-block bg-red-200 text-red-900 text-xs font-semibold px-2 py-0.5 rounded mr-1 mt-1">
                            <?= htmlspecialchars($ls['name']) ?> (<?= $ls['quantity'] ?> <?= $ls['unit'] ?> / ROP: <?= $ls['reorder_point'] ?>)
                        </span>
                    <?php endforeach; ?>
                </p>
            </div>
        <?php endif; ?>

        <!-- Feedback Messages -->
        <?php if ($message): ?>
            <div class="bg-green-100 text-green-800 p-3 rounded text-sm font-medium"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="bg-red-100 text-red-800 p-3 rounded text-sm font-medium"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- FR-2 & FR-3: Stock Level Table -->
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="p-4 border-b flex justify-between items-center">
                <h2 class="font-bold text-lg">Current Stock Levels (FR-2)</h2>
                <span class="text-xs text-stone-500">
                    <?= $role === 'Staff' ? '🔒 Prices hidden per FR-15' : '🔓 Full financial visibility' ?>
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-stone-50 text-stone-600 uppercase text-xs border-b">
                        <tr>
                            <th class="p-3">Item</th>
                            <th class="p-3">Category</th>
                            <th class="p-3">In Stock</th>
                            <th class="p-3">ROP</th>
                            <th class="p-3">Status (FR-3)</th>
                            <!-- FR-15: Only show Unit Cost to Admin and Owner -->
                            <?php if ($role !== 'Staff'): ?>
                                <th class="p-3">Unit Cost (FR-15)</th>
                            <?php endif; ?>
                            <th class="p-3">Quick Action (FR-2 / FR-11)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach ($items as $item): 
                            $isLow = (float)$item['quantity'] <= (float)$item['reorder_point'];
                        ?>
                        <tr class="<?= $isLow ? 'bg-red-50/40' : '' ?>">
                            <td class="p-3 font-medium"><?= htmlspecialchars($item['name']) ?></td>
                            <td class="p-3 text-stone-500"><?= htmlspecialchars($item['category']) ?></td>
                            <td class="p-3 font-bold"><?= $item['quantity'] ?> <?= htmlspecialchars($item['unit']) ?></td>
                            <td class="p-3 text-stone-500"><?= $item['reorder_point'] ?> <?= htmlspecialchars($item['unit']) ?></td>
                            <td class="p-3">
                                <?php if ($isLow): ?>
                                    <span class="bg-red-600 text-white text-xs px-2 py-0.5 rounded font-semibold">LOW STOCK</span>
                                <?php else: ?>
                                    <span class="bg-emerald-100 text-emerald-800 text-xs px-2 py-0.5 rounded font-semibold">OK</span>
                                <?php endif; ?>
                            </td>

                            <!-- FR-15 Role Guard on Price Column -->
                            <?php if ($role !== 'Staff'): ?>
                                <td class="p-3 font-mono">₱<?= number_format($item['unit_cost'], 2) ?></td>
                            <?php endif; ?>

                            <!-- FR-2 Deduction & FR-11 Waste Form -->
                            <td class="p-3">
                                <form method="POST" class="flex flex-wrap items-center gap-1">
                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                    <input type="number" step="0.01" name="amount" placeholder="Qty" required
                                           class="w-16 p-1 border rounded text-xs">
                                    <input type="text" name="reason" placeholder="Reason (for waste)" 
                                           class="w-32 p-1 border rounded text-xs">
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

        <!-- FR-12: Immutable Audit Trail -->
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="p-4 border-b">
                <h2 class="font-bold text-lg">Immutable Audit Trail (FR-12)</h2>
                <p class="text-xs text-stone-500">Read-only log of all stock adjustments and security events. Cannot be edited or deleted by any role.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-left">
                    <thead class="bg-stone-50 text-stone-600 uppercase border-b">
                        <tr>
                            <th class="p-3">Timestamp</th>
                            <th class="p-3">User</th>
                            <th class="p-3">Type</th>
                            <th class="p-3">Item</th>
                            <th class="p-3">Prev &rarr; New</th>
                            <th class="p-3">Reason / Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach ($auditLogs as $log): ?>
                        <tr>
                            <td class="p-3 text-stone-500"><?= htmlspecialchars($log['created_at']) ?></td>
                            <td class="p-3 font-semibold"><?= htmlspecialchars($log['acting_user']) ?></td>
                            <td class="p-3">
                                <span class="px-2 py-0.5 rounded font-semibold uppercase 
                                    <?= $log['change_type'] === 'waste' ? 'bg-rose-100 text-rose-800' : 
                                       ($log['change_type'] === 'unauthorized_access' ? 'bg-red-600 text-white' : 'bg-stone-200 text-stone-800') ?>">
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
                            <td class="p-3 text-stone-600"><?= htmlspecialchars($log['reason']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</body>
</html>