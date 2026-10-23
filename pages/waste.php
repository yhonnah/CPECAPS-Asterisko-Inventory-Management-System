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
    $reason_preset = trim($_POST['reason_preset'] ?? '');
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
            $auditReason = "Wasted/Spoiled: -{$waste_qty} {$item['unit']} ({$full_reason})";
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

// Fetch today's waste logs for current shift view
$todayWasteLogs = $pdo->query("
    SELECT al.*, si.NAME AS item_name, si.unit
    FROM audit_logs al
    JOIN stock_items si ON si.id = al.item_id
    WHERE al.change_type = 'waste' AND DATE(al.created_at) = CURRENT_DATE()
    ORDER BY al.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$totalWasteEntriesToday = count($todayWasteLogs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waste & Spoilage Logging - Asterisko Inventory</title>
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
</head>
<body class="bg-stone-100 dark:bg-stone-950 text-stone-800 dark:text-stone-200 min-h-screen">

    <!-- Top Navigation Bar -->
    <nav class="bg-stone-900 dark:bg-black text-white px-4 py-3 flex justify-between items-center border-b border-stone-800">
        <div class="flex items-center gap-3">
            <a href="dashboard.php" class="text-emerald-400 hover:text-emerald-300 text-sm font-semibold flex items-center gap-1">
                &larr; Dashboard
            </a>
            <span class="text-stone-600">|</span>
            <span class="font-bold text-base sm:text-lg">Waste & Spoilage Log</span>
            <span class="text-xs bg-emerald-600 px-2 py-0.5 rounded uppercase font-semibold"><?= htmlspecialchars($role) ?></span>
        </div>
        <div class="flex items-center gap-3 text-sm">
            <span class="hidden sm:inline">User: <b><?= htmlspecialchars($username) ?></b></span>
            <button id="theme-toggle" type="button" aria-label="Toggle dark mode"
                    class="p-1.5 rounded-full bg-stone-700 hover:bg-stone-600 text-emerald-300 transition">
                <svg class="w-4 h-4 hidden dark:block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                <svg class="w-4 h-4 block dark:hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
            </button>
            <a href="logout.php" class="bg-stone-700 hover:bg-stone-600 px-3 py-1 rounded text-xs">Logout</a>
        </div>
    </nav>

    <div class="max-w-4xl mx-auto p-4 space-y-6">

        <!-- Offline Banner -->
        <div id="offline-banner" class="hidden rounded-xl p-3 border bg-amber-50 dark:bg-amber-950/40 border-amber-300 text-amber-900 dark:text-amber-200 text-xs flex justify-between items-center">
            <span>You are offline. Waste entries will be queued in local storage and synced automatically once connected.</span>
            <span id="queue-count-badge" class="font-bold bg-amber-400 text-stone-900 px-2 py-0.5 rounded">0 queued</span>
        </div>

        <?php if ($message): ?>
            <div class="p-3.5 rounded-xl text-sm font-medium border <?= $messageType === 'success' ? 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-200 border-emerald-300 dark:border-emerald-800' : 'bg-rose-50 dark:bg-rose-950/60 text-rose-800 dark:text-rose-200 border-rose-300 dark:border-rose-800' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Log Waste Entry Card -->
        <div class="bg-white dark:bg-stone-900 rounded-xl shadow p-5 border border-stone-200 dark:border-stone-800">
            <h2 class="text-lg font-bold text-stone-900 dark:text-stone-100 mb-1 flex items-center gap-2">
                <svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                Record Spoilage / Waste (FIFO Support)
            </h2>
            <p class="text-xs text-stone-500 mb-4">Replaces manual notebook tallies. Directly deducts from on-hand stock and appends to the immutable audit trail.</p>

            <form id="waste-form" method="POST" class="space-y-4 text-xs">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-semibold mb-1">Select Ingredient / Stock Item *</label>
                        <select name="item_id" id="item_id" required onchange="updateUnitLabel()"
                                class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100">
                            <option value="">-- Choose Item --</option>
                            <?php foreach ($stockItems as $item): ?>
                                <option value="<?= $item['id'] ?>" data-unit="<?= htmlspecialchars($item['unit']) ?>" data-stock="<?= $item['quantity'] ?>">
                                    <?= htmlspecialchars($item['NAME'] ?? $item['name']) ?> (Current: <?= $item['quantity'] ?> <?= htmlspecialchars($item['unit']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block font-semibold mb-1">Waste Quantity *</label>
                        <div class="flex items-center gap-2">
                            <input type="number" step="0.01" name="quantity" id="quantity" required placeholder="e.g., 1.50"
                                   class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100">
                            <span id="unit-label" class="text-xs font-semibold px-3 py-2.5 bg-stone-200 dark:bg-stone-800 rounded-lg">units</span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-semibold mb-1">Preset Spoilage Reason *</label>
                        <select name="reason_preset" id="reason_preset" required
                                class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100">
                            <option value="Spoiled / Past Expiry Date">Spoiled / Past Expiry Date (FIFO)</option>
                            <option value="Accidental Spillage / Dropped">Accidental Spillage / Dropped</option>
                            <option value="Espresso Dial-in / Grind Calibration">Espresso Dial-in / Grind Calibration</option>
                            <option value="Milk Steaming Waste / Overfill">Milk Steaming Waste / Overfill</option>
                            <option value="Contaminated / Bad Odor">Contaminated / Bad Odor</option>
                            <option value="Other">Other / Specific Incident</option>
                        </select>
                    </div>

                    <div>
                        <label class="block font-semibold mb-1">Custom Notes / Batch Code (Optional)</label>
                        <input type="text" name="reason_custom" id="reason_custom" placeholder="e.g., Sour batch expiry dated Oct 21"
                               class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100">
                    </div>
                </div>

                <div class="flex justify-end pt-2">
                    <button type="submit" id="btn-submit-waste" class="bg-rose-700 hover:bg-rose-800 text-white font-semibold px-6 py-2.5 rounded-lg transition shadow flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Confirm & Deduct Waste
                    </button>
                </div>
            </form>
        </div>

        <!-- Today's Shift Waste Tally -->
        <div class="bg-white dark:bg-stone-900 rounded-xl shadow overflow-hidden border border-stone-200 dark:border-stone-800">
            <div class="p-4 border-b border-stone-200 dark:border-stone-800 flex justify-between items-center">
                <div>
                    <h2 class="text-sm font-bold text-stone-900 dark:text-stone-100">Today's Waste Records (Shift Tally)</h2>
                    <p class="text-xs text-stone-500">Immutable ledger entries recorded today.</p>
                </div>
                <span class="bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300 font-bold px-2.5 py-1 rounded text-xs">
                    <?= $totalWasteEntriesToday ?> Recorded Today
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-stone-50 dark:bg-stone-800 uppercase font-semibold text-stone-600 dark:text-stone-400 border-b border-stone-200 dark:border-stone-700">
                        <tr>
                            <th class="p-3">Time</th>
                            <th class="p-3">Item</th>
                            <th class="p-3">Deduction</th>
                            <th class="p-3">Stock Before &rarr; After</th>
                            <th class="p-3">Reason</th>
                            <th class="p-3">Barista</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200 dark:divide-stone-800">
                        <?php if (empty($todayWasteLogs)): ?>
                            <tr>
                                <td colspan="6" class="p-4 text-center text-stone-500">No waste logged yet for today's shift. Clean operations!</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($todayWasteLogs as $w): ?>
                                <tr class="hover:bg-stone-50 dark:hover:bg-stone-800/50">
                                    <td class="p-3 font-mono text-stone-500">
                                        <?= date('h:i A', strtotime($w['created_at'])) ?>
                                    </td>
                                    <td class="p-3 font-semibold text-stone-900 dark:text-stone-100">
                                        <?= htmlspecialchars($w['item_name']) ?>
                                    </td>
                                    <td class="p-3 font-mono font-bold text-rose-600">
                                        -<?= number_format($w['previous_value'] - $w['new_value'], 2) ?> <?= htmlspecialchars($w['unit']) ?>
                                    </td>
                                    <td class="p-3 font-mono text-stone-600 dark:text-stone-400">
                                        <?= number_format($w['previous_value'], 2) ?> &rarr; <b class="text-stone-900 dark:text-stone-100"><?= number_format($w['new_value'], 2) ?></b>
                                    </td>
                                    <td class="p-3 text-stone-700 dark:text-stone-300">
                                        <?= htmlspecialchars($w['reason']) ?>
                                    </td>
                                    <td class="p-3 font-semibold text-stone-700 dark:text-stone-300">
                                        <?= htmlspecialchars($w['acting_user']) ?>
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
        function updateUnitLabel() {
            const select = document.getElementById('item_id');
            const selected = select.options[select.selectedIndex];
            const unit = selected.getAttribute('data-unit') || 'units';
            document.getElementById('unit-label').textContent = unit;
        }

        // Theme toggle
        document.getElementById('theme-toggle').addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark');
            try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch (e) {}
        });

        // Network check
        function checkNet() {
            const banner = document.getElementById('offline-banner');
            if (!navigator.onLine) {
                banner.classList.remove('hidden');
            } else {
                banner.classList.add('hidden');
            }
        }
        window.addEventListener('online', checkNet);
        window.addEventListener('offline', checkNet);
        checkNet();
    </script>
</body>
</html>
