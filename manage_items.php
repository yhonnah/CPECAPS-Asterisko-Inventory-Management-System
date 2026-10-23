<?php
require 'db.php';
require 'authguard.php';

// Accessible only to Admin and Owner
requireRole(['Admin', 'Owner'], $pdo);

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$message = '';
$messageType = '';

// Handle Create / Update / Deactivate
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_item') {
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? 'General');
        $quantity = max(0, (float)($_POST['quantity'] ?? 0));
        $unit = trim($_POST['unit'] ?? 'units');
        $reorder_point = max(0, (float)($_POST['reorder_point'] ?? 0));
        $unit_cost = max(0, (float)($_POST['unit_cost'] ?? 0));
        $supplier_id = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;

        if ($name === '' || $unit === '') {
            $message = 'Item name and unit of measure are required.';
            $messageType = 'error';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO stock_items (name, category, quantity, unit, reorder_point, unit_cost, supplier_id, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$name, $category, $quantity, $unit, $reorder_point, $unit_cost, $supplier_id]);
            $newItemId = $pdo->lastInsertId();

            // Audit initial inventory
            $audit = $pdo->prepare("
                INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
                VALUES (?, 0.00, ?, 'delivery', 'Initial stock item created', ?)
            ");
            $audit->execute([$newItemId, $quantity, $username]);

            $message = "Stock item '{$name}' created successfully!";
            $messageType = 'success';
        }
    } elseif ($action === 'update_item') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? 'General');
        $unit = trim($_POST['unit'] ?? 'units');
        $reorder_point = max(0, (float)($_POST['reorder_point'] ?? 0));
        $unit_cost = max(0, (float)($_POST['unit_cost'] ?? 0));
        $supplier_id = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;

        // Fetch current item to check ROP changes
        $curr = $pdo->prepare("SELECT * FROM stock_items WHERE id = ?");
        $curr->execute([$id]);
        $existing = $curr->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $oldRop = (float)$existing['reorder_point'];

            // Update item details
            $stmt = $pdo->prepare("
                UPDATE stock_items 
                SET name = ?, category = ?, unit = ?, reorder_point = ?, unit_cost = ?, supplier_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $category, $unit, $reorder_point, $unit_cost, $supplier_id, $id]);

            // If ROP changed, audit log the change
            if (abs($oldRop - $reorder_point) >= 0.001) {
                $audit = $pdo->prepare("
                    INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
                    VALUES (?, ?, ?, 'rop_update', ?, ?)
                ");
                $reason = "Reorder Point changed from {$oldRop} {$unit} to {$reorder_point} {$unit}";
                $audit->execute([$id, $oldRop, $reorder_point, $reason, $username]);
            }

            $message = "Stock item '{$name}' updated successfully!";
            $messageType = 'success';
        }
    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE stock_items SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$id]);
        $message = "Item status updated.";
        $messageType = 'success';
    }
}

// Fetch all stock items with supplier info
$items = $pdo->query("
    SELECT si.*, si.NAME AS NAME, si.NAME AS name, s.NAME AS supplier_name 
    FROM stock_items si
    LEFT JOIN suppliers s ON s.id = si.supplier_id
    ORDER BY si.is_active DESC, si.category, si.NAME
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch active suppliers for dropdown
$suppliers = $pdo->query("SELECT id, NAME, NAME AS name FROM suppliers WHERE is_active = 1 ORDER BY NAME")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Stock Items - Asterisko Inventory</title>
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

    <!-- Top Navbar -->
    <nav class="bg-stone-900 dark:bg-black text-white px-4 py-3 flex justify-between items-center border-b border-stone-800">
        <div class="flex items-center gap-3">
            <a href="dashboard.php" class="text-emerald-400 hover:text-emerald-300 text-sm font-semibold flex items-center gap-1">
                &larr; Dashboard
            </a>
            <span class="text-stone-600">|</span>
            <span class="font-bold text-base sm:text-lg">Item Master Data</span>
            <span class="text-xs bg-emerald-600 px-2 py-0.5 rounded uppercase font-semibold"><?= htmlspecialchars($role) ?></span>
        </div>
        <div class="flex items-center gap-3 text-sm">
            <a href="manage_suppliers.php" class="bg-stone-700 hover:bg-stone-600 text-xs px-2.5 py-1 rounded transition">Suppliers</a>
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

        <!-- Create New Stock Item Card -->
        <div class="bg-white dark:bg-stone-900 rounded-xl shadow p-5 border border-stone-200 dark:border-stone-800">
            <h2 class="text-lg font-bold text-stone-900 dark:text-stone-100 mb-3 flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                Create New Stock Item Record
            </h2>
            <form method="POST" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 text-xs">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="create_item">
                <div>
                    <label class="block font-semibold mb-1">Item Name *</label>
                    <input type="text" name="name" required placeholder="e.g., Oat Milk Barista Edition"
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Category</label>
                    <input type="text" name="category" placeholder="e.g., Dairy, Beans, Syrups, Packaging"
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Unit of Measure *</label>
                    <input type="text" name="unit" required placeholder="e.g., kg, L, pcs, cans"
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Initial Quantity</label>
                    <input type="number" step="0.01" name="quantity" value="0.00"
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Reorder Point (ROP) *</label>
                    <input type="number" step="0.01" name="reorder_point" required placeholder="e.g., 2.50"
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Unit Cost (₱) *</label>
                    <input type="number" step="0.01" name="unit_cost" required placeholder="e.g., 120.00"
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Default Supplier</label>
                    <select name="supplier_id" class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100">
                        <option value="">-- None --</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['NAME'] ?? $s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full bg-emerald-700 hover:bg-emerald-800 text-white font-semibold py-2.5 rounded-lg transition shadow">
                        Save Item Record
                    </button>
                </div>
            </form>
        </div>

        <!-- Master Items Table -->
        <div class="bg-white dark:bg-stone-900 rounded-xl shadow overflow-hidden border border-stone-200 dark:border-stone-800">
            <div class="p-4 border-b border-stone-200 dark:border-stone-800 flex flex-col sm:flex-row justify-between items-center gap-3">
                <div>
                    <h2 class="text-base font-bold text-stone-900 dark:text-stone-100">Stock Items Master Catalog</h2>
                    <p class="text-xs text-stone-500">Deactivation preserves all transaction history and audit trail logs.</p>
                </div>
                <input type="text" id="search-input" placeholder="Search item or category..."
                       class="text-xs p-2 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 w-full sm:w-64">
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs" id="items-table">
                    <thead class="bg-stone-50 dark:bg-stone-800 uppercase font-semibold text-stone-600 dark:text-stone-400 border-b border-stone-200 dark:border-stone-700">
                        <tr>
                            <th class="p-3">Item Name</th>
                            <th class="p-3">Category</th>
                            <th class="p-3">Stock Qty</th>
                            <th class="p-3">ROP</th>
                            <th class="p-3">Unit Cost</th>
                            <th class="p-3">Default Supplier</th>
                            <th class="p-3">Status</th>
                            <th class="p-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200 dark:divide-stone-800">
                        <?php foreach ($items as $item): ?>
                            <tr class="hover:bg-stone-50 dark:hover:bg-stone-800/50 <?= !$item['is_active'] ? 'opacity-50 bg-stone-100/50 dark:bg-stone-900/50' : '' ?>">
                                <td class="p-3 font-semibold text-stone-900 dark:text-stone-100">
                                    <?= htmlspecialchars($item['NAME'] ?? $item['name']) ?>
                                </td>
                                <td class="p-3">
                                    <span class="bg-stone-200 dark:bg-stone-800 px-2 py-0.5 rounded text-[11px]"><?= htmlspecialchars($item['category']) ?></span>
                                </td>
                                <td class="p-3 font-mono font-semibold">
                                    <?= number_format($item['quantity'], 2) ?> <?= htmlspecialchars($item['unit']) ?>
                                </td>
                                <td class="p-3 font-mono">
                                    <?= number_format($item['reorder_point'], 2) ?> <?= htmlspecialchars($item['unit']) ?>
                                </td>
                                <td class="p-3 font-mono">
                                    ₱<?= number_format($item['unit_cost'], 2) ?>
                                </td>
                                <td class="p-3 text-stone-600 dark:text-stone-400">
                                    <?= htmlspecialchars($item['supplier_name'] ?? '—') ?>
                                </td>
                                <td class="p-3">
                                    <?php if ($item['is_active']): ?>
                                        <span class="bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 px-2 py-0.5 rounded text-[10px] font-bold">ACTIVE</span>
                                    <?php else: ?>
                                        <span class="bg-stone-300 dark:bg-stone-700 text-stone-700 dark:text-stone-300 px-2 py-0.5 rounded text-[10px] font-bold">DEACTIVATED</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 text-right space-x-2">
                                    <button type="button" 
                                            onclick='openEditModal(<?= json_encode($item) ?>)'
                                            class="text-emerald-600 hover:text-emerald-500 font-semibold underline text-xs">
                                        Edit
                                    </button>
                                    <form method="POST" class="inline" onsubmit="return confirm('Change active status for this item?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                        <button type="submit" class="text-xs font-semibold <?= $item['is_active'] ? 'text-rose-600 hover:underline' : 'text-emerald-600 hover:underline' ?>">
                                            <?= $item['is_active'] ? 'Deactivate' : 'Reactivate' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- Edit Item Modal -->
    <div id="edit-modal" class="fixed inset-0 bg-black/60 hidden items-center justify-center p-4 z-50">
        <div class="bg-white dark:bg-stone-900 rounded-xl max-w-lg w-full p-5 border border-stone-300 dark:border-stone-700 shadow-2xl">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-base font-bold text-stone-900 dark:text-stone-100">Edit Item & Reorder Point</h3>
                <button type="button" onclick="closeEditModal()" class="text-stone-400 hover:text-stone-600 text-lg">&times;</button>
            </div>
            <form method="POST" class="space-y-3 text-xs">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="update_item">
                <input type="hidden" name="id" id="edit-id">

                <div>
                    <label class="block font-semibold mb-1">Item Name</label>
                    <input type="text" name="name" id="edit-name" required
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold mb-1">Category</label>
                        <input type="text" name="category" id="edit-category"
                               class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800">
                    </div>
                    <div>
                        <label class="block font-semibold mb-1">Unit of Measure</label>
                        <input type="text" name="unit" id="edit-unit" required
                               class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold mb-1">Reorder Point (ROP) *</label>
                        <input type="number" step="0.01" name="reorder_point" id="edit-rop" required
                               class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800">
                        <span class="text-[10px] text-stone-500">ROP change logs to audit trail</span>
                    </div>
                    <div>
                        <label class="block font-semibold mb-1">Unit Cost (₱) *</label>
                        <input type="number" step="0.01" name="unit_cost" id="edit-cost" required
                               class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800">
                    </div>
                </div>
                <div>
                    <label class="block font-semibold mb-1">Default Supplier</label>
                    <select name="supplier_id" id="edit-supplier" class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800">
                        <option value="">-- None --</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['NAME'] ?? $s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex justify-end gap-2 pt-3 border-t border-stone-200 dark:border-stone-800">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 border border-stone-300 dark:border-stone-700 rounded-lg text-stone-700 dark:text-stone-300">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg font-semibold">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEditModal(item) {
            document.getElementById('edit-id').value = item.id;
            document.getElementById('edit-name').value = item.NAME || item.name || '';
            document.getElementById('edit-category').value = item.category;
            document.getElementById('edit-unit').value = item.unit;
            document.getElementById('edit-rop').value = item.reorder_point;
            document.getElementById('edit-cost').value = item.unit_cost;
            document.getElementById('edit-supplier').value = item.supplier_id || '';
            const modal = document.getElementById('edit-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeEditModal() {
            const modal = document.getElementById('edit-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // Live table filter
        document.getElementById('search-input').addEventListener('input', function(e) {
            const query = e.target.value.toLowerCase();
            document.querySelectorAll('#items-table tbody tr').forEach(row => {
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
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
