<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/authguard.php';

// Accessible only to Admin/Manager and Owner
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

$activePage = 'manage_items';
$pageTitle = 'Manage Items - Asterisko Inventory';
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

            <!-- Title & Add Item Action (Canva Slide 3 Spec) -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">Manage Items</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">View, add, edit and manage your inventory items</p>
                </div>
                <button onclick="openCreateModal()" class="btn-primary inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold shadow-md">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span>+ Add Item</span>
                </button>
            </div>

            <!-- Messages Feedback -->
            <?php if ($message): ?>
                <div class="p-4 rounded-xl text-sm font-medium flex items-center gap-2 <?= $messageType === 'success' ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-200 border border-emerald-300 dark:border-emerald-800' : 'bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-200 border border-rose-300 dark:border-rose-800' ?>">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $messageType === 'success' ? 'M5 13l4 4L19 7' : 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z' ?>"/></svg>
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
            <?php endif; ?>

            <!-- Table Card (Canva Slide 3 Spec) -->
            <div class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] overflow-hidden">
                <div class="p-4 sm:p-5 border-b border-slate-200 dark:border-[#15345C] flex flex-col sm:flex-row justify-between items-center gap-3">
                    <div class="relative w-full sm:w-72">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        </div>
                        <input type="text" id="item-search" placeholder="Search catalog..." 
                               class="w-full !pl-10 !pr-4 py-2 bg-slate-50 dark:bg-[#07172C] text-xs rounded-xl border border-slate-200 dark:border-[#173863] focus:outline-none focus:border-[#F28C28] has-icon-left">
                    </div>
                    <span class="text-xs text-slate-400">Total Items: <b><?= count($items) ?></b></span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left" id="items-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Stock</th>
                                <th>Unit Cost</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): 
                                $isLow = (float)$item['quantity'] <= (float)$item['reorder_point'];
                            ?>
                            <tr class="<?= !$item['is_active'] ? 'opacity-50 bg-slate-50 dark:bg-slate-900/30' : '' ?>">
                                <td class="font-mono text-slate-400">#<?= $item['id'] ?></td>
                                <td class="font-bold text-slate-900 dark:text-white">
                                    <div class="flex items-center gap-2">
                                        <span><?= htmlspecialchars($item['NAME'] ?? $item['name']) ?></span>
                                        <?php if (!empty($item['supplier_name'])): ?>
                                            <span class="text-[10px] text-slate-400 font-normal">(<?= htmlspecialchars($item['supplier_name']) ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-[#132E52] text-slate-600 dark:text-slate-300 text-[11px] font-medium">
                                        <?= htmlspecialchars($item['category']) ?>
                                    </span>
                                </td>
                                <td class="font-mono font-bold text-sm">
                                    <?= number_format($item['quantity'], 2) ?> <span class="text-xs font-normal text-slate-400"><?= htmlspecialchars($item['unit']) ?></span>
                                </td>
                                <td class="font-mono font-semibold">
                                    ₱<?= number_format($item['unit_cost'], 2) ?>
                                </td>
                                <td>
                                    <?php if (!$item['is_active']): ?>
                                        <span class="badge-danger">Inactive</span>
                                    <?php elseif ($isLow): ?>
                                        <span class="badge-low">Low Stock</span>
                                    <?php else: ?>
                                        <span class="badge-good">Good</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right">
                                    <div class="inline-flex items-center gap-1.5">
                                        <!-- Edit Action (Canva Slide 3 Spec) -->
                                        <button type="button" 
                                                onclick='openEditModal(<?= json_encode($item) ?>)'
                                                class="p-1.5 rounded-lg text-slate-500 hover:text-[#F28C28] hover:bg-orange-50 dark:hover:bg-[#132E52] transition-colors" title="Edit Item">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                        </button>

                                        <!-- Deactivate / Toggle Action (Canva Slide 3 Spec) -->
                                        <form method="POST" class="inline" onsubmit="return confirm('Change active status for this item?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                            <button type="submit" class="p-1.5 rounded-lg text-slate-400 hover:text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition-colors" title="<?= $item['is_active'] ? 'Deactivate' : 'Reactivate' ?>">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="p-4 border-t border-slate-200 dark:border-[#15345C] flex flex-col sm:flex-row justify-between items-center text-xs text-slate-500 gap-2">
                    <span>Showing 1-<?= count($items) ?> of <?= count($items) ?> items</span>
                    <div class="inline-flex items-center gap-1">
                        <button class="px-2.5 py-1 rounded-lg border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] disabled:opacity-50" disabled>&larr;</button>
                        <span class="px-3 py-1 rounded-lg bg-[#0C2340] dark:bg-[#132E52] text-white font-bold">1</span>
                        <button class="px-2.5 py-1 rounded-lg border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] disabled:opacity-50" disabled>&rarr;</button>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal Dialog: Add / Edit Item (Exact Canva Slide 4 Spec) -->
    <div id="item-modal" class="fixed inset-0 modal-overlay z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#0C2340] rounded-2xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200 dark:border-[#15345C] relative animate-fade-in">
            <!-- Modal Header -->
            <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-[#15345C] mb-5">
                <h3 id="modal-title" class="text-lg font-bold text-slate-900 dark:text-white">Add New Item</h3>
                <button type="button" onclick="closeModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <!-- Modal Form (Canva Slide 4 2-Column Grid) -->
            <form method="POST" id="item-form" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="action" id="modal-action" value="create_item">
                <input type="hidden" name="id" id="modal-item-id" value="">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Item Name -->
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Item Name *</label>
                        <input type="text" name="name" id="modal-name" required placeholder="e.g. Arabica Coffee Beans"
                               class="w-full">
                    </div>

                    <!-- Category -->
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Category *</label>
                        <select name="category" id="modal-category" required class="w-full">
                            <option value="Beans">Beans</option>
                            <option value="Dairy">Dairy</option>
                            <option value="Syrups">Syrups</option>
                            <option value="Packaging">Packaging</option>
                            <option value="Pastry">Pastry</option>
                            <option value="Tea">Tea</option>
                            <option value="General">General</option>
                        </select>
                    </div>

                    <!-- Unit of Measure -->
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Unit of Measure *</label>
                        <select name="unit" id="modal-unit" required class="w-full">
                            <option value="kg">kg (Kilogram)</option>
                            <option value="g">g (Grams)</option>
                            <option value="L">L (Liters)</option>
                            <option value="ml">ml (Milliliters)</option>
                            <option value="pcs">pcs (Pieces)</option>
                            <option value="boxes">boxes</option>
                            <option value="packs">packs</option>
                        </select>
                    </div>

                    <!-- Initial Quantity (visible for create) -->
                    <div id="initial-stock-group">
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Initial Stock *</label>
                        <input type="number" step="0.01" name="quantity" id="modal-quantity" placeholder="e.g. 10.0"
                               class="w-full">
                    </div>

                    <!-- Reorder Point (ROP) -->
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Reorder Point (ROP)</label>
                        <input type="number" step="0.01" name="reorder_point" id="modal-rop" placeholder="e.g. 5.0"
                               class="w-full">
                    </div>

                    <!-- Unit Cost -->
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Unit Cost (₱)</label>
                        <input type="number" step="0.01" name="unit_cost" id="modal-cost" placeholder="0.00"
                               class="w-full">
                    </div>

                    <!-- Supplier -->
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Preferred Supplier</label>
                        <select name="supplier_id" id="modal-supplier" class="w-full">
                            <option value="">No Supplier Assigned</option>
                            <?php foreach ($suppliers as $sup): ?>
                                <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['NAME'] ?? $sup['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Footer Action Buttons (Canva Slide 4 Spec) -->
                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200 dark:border-[#15345C] mt-6">
                    <button type="button" onclick="closeModal()" 
                            class="px-5 py-2.5 rounded-xl border border-slate-300 dark:border-[#173863] text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-[#132E52] transition-colors">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="btn-primary px-6 py-2.5 rounded-xl text-sm font-bold shadow-md">
                        Save Item
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal & Search Logic -->
    <script>
        document.getElementById('item-search').addEventListener('input', function() {
            const query = this.value.toLowerCase();
            document.querySelectorAll('#items-table tbody tr').forEach(tr => {
                tr.style.display = tr.textContent.toLowerCase().includes(query) ? '' : 'none';
            });
        });

        function openCreateModal() {
            document.getElementById('modal-title').textContent = 'Add New Item';
            document.getElementById('modal-action').value = 'create_item';
            document.getElementById('modal-item-id').value = '';
            document.getElementById('item-form').reset();
            document.getElementById('initial-stock-group').style.display = 'block';
            document.getElementById('item-modal').classList.remove('hidden');
        }

        function openEditModal(item) {
            document.getElementById('modal-title').textContent = 'Edit Item Details';
            document.getElementById('modal-action').value = 'update_item';
            document.getElementById('modal-item-id').value = item.id;
            document.getElementById('modal-name').value = item.NAME || item.name || '';
            document.getElementById('modal-category').value = item.category || 'General';
            document.getElementById('modal-unit').value = item.unit || 'units';
            document.getElementById('modal-rop').value = item.reorder_point || 0;
            document.getElementById('modal-cost').value = item.unit_cost || 0;
            document.getElementById('modal-supplier').value = item.supplier_id || '';
            document.getElementById('initial-stock-group').style.display = 'none';
            document.getElementById('item-modal').classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('item-modal').classList.add('hidden');
        }
    </script>
</body>
</html>
