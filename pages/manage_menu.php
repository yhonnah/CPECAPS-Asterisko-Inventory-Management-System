<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/authguard.php';
require_once __DIR__ . '/../includes/cache_helper.php';

// Accessible by Admin/Manager and Owner
requireRole(['Admin', 'Owner'], $pdo);

$username = $_SESSION['username'] ?? 'User';
$role = $_SESSION['role'] ?? 'Admin';
$message = '';
$messageType = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['action'] ?? '';

    // 1. CREATE NEW MENU ITEM
    if ($action === 'create_menu_item') {
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? 'Espresso');
        $price = max(0, (float)($_POST['price'] ?? 0));
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $price <= 0) {
            $message = 'Drink name and a valid price are required.';
            $messageType = 'error';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO menu_items (name, category, price, description, is_active)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $category, $price, $description, $is_active]);
                $newId = (int)$pdo->lastInsertId();

                // Audit log
                $audit = $pdo->prepare("
                    INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
                    VALUES (NULL, NULL, ?, 'menu_create', ?, ?)
                ");
                $audit->execute([$price, "Created menu item '{$name}' (₱{$price})", $username]);

                bumpCatalogVersion($pdo);
                $message = "Menu item '{$name}' created successfully!";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = "Failed to create menu item: " . $e->getMessage();
                $messageType = 'error';
            }
        }
    }

    // 2. UPDATE MENU ITEM DETAILS
    elseif ($action === 'update_menu_item') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? 'Espresso');
        $price = max(0, (float)($_POST['price'] ?? 0));
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($id <= 0 || $name === '' || $price <= 0) {
            $message = 'Valid menu item ID, name, and price are required.';
            $messageType = 'error';
        } else {
            try {
                $stmtOld = $pdo->prepare("SELECT * FROM menu_items WHERE id = ?");
                $stmtOld->execute([$id]);
                $old = $stmtOld->fetch(PDO::FETCH_ASSOC);

                $stmt = $pdo->prepare("
                    UPDATE menu_items 
                    SET name = ?, category = ?, price = ?, description = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $category, $price, $description, $is_active, $id]);

                $oldPrice = $old ? (float)$old['price'] : 0;
                $audit = $pdo->prepare("
                    INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
                    VALUES (NULL, ?, ?, 'menu_update', ?, ?)
                ");
                $audit->execute([$oldPrice, $price, "Updated menu item '{$name}' details/pricing", $username]);

                bumpCatalogVersion($pdo);
                $message = "Updated '{$name}' successfully!";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = "Failed to update menu item: " . $e->getMessage();
                $messageType = 'error';
            }
        }
    }

    // 3. TOGGLE ACTIVE STATUS
    elseif ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE menu_items SET is_active = 1 - is_active WHERE id = ?");
            $stmt->execute([$id]);

            bumpCatalogVersion($pdo);
            $message = "Menu item status updated.";
            $messageType = 'success';
        }
    }

    // 4. SAVE RECIPE FORMULA FOR MENU ITEM
    elseif ($action === 'save_recipe') {
        $menu_id = (int)($_POST['menu_item_id'] ?? 0);
        $ingredientsJson = $_POST['ingredients_json'] ?? '[]';
        $ingredients = json_decode($ingredientsJson, true) ?: [];

        if ($menu_id <= 0) {
            $message = 'Invalid menu item selected.';
            $messageType = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                // Fetch menu item name
                $stmtMenu = $pdo->prepare("SELECT name FROM menu_items WHERE id = ?");
                $stmtMenu->execute([$menu_id]);
                $menuItemName = $stmtMenu->fetchColumn() ?: "Drink #{$menu_id}";

                // Delete current ingredients for this menu item
                $delStmt = $pdo->prepare("DELETE FROM menu_item_ingredients WHERE menu_item_id = ?");
                $delStmt->execute([$menu_id]);

                // Insert new ingredients
                $insStmt = $pdo->prepare("
                    INSERT INTO menu_item_ingredients (menu_item_id, stock_item_id, quantity_required, unit)
                    VALUES (?, ?, ?, ?)
                ");

                $savedCount = 0;
                foreach ($ingredients as $ing) {
                    $stockId = (int)($ing['stock_item_id'] ?? 0);
                    $qty = round((float)($ing['quantity_required'] ?? 0), 4);
                    $unit = trim($ing['unit'] ?? 'units');

                    if ($stockId > 0 && $qty > 0) {
                        $insStmt->execute([$menu_id, $stockId, $qty, $unit]);
                        $savedCount++;
                    }
                }

                // Audit recipe change
                $audit = $pdo->prepare("
                    INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
                    VALUES (NULL, NULL, ?, 'recipe_update', ?, ?)
                ");
                $audit->execute([$savedCount, "Configured {$savedCount} recipe deductions for '{$menuItemName}'", $username]);

                $pdo->commit();
                bumpCatalogVersion($pdo);

                $message = "Recipe formula for '{$menuItemName}' updated ({$savedCount} ingredients configured)!";
                $messageType = 'success';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = "Failed to save recipe: " . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Fetch all menu items
$menuItems = $pdo->query("SELECT * FROM menu_items ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all ingredients grouped by menu item
$allIngs = $pdo->query("
    SELECT mii.*, si.name AS stock_name, si.unit AS stock_unit, si.quantity AS stock_qty, si.unit_cost
    FROM menu_item_ingredients mii
    JOIN stock_items si ON si.id = mii.stock_item_id
    ORDER BY mii.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$ingredientsMap = [];
foreach ($allIngs as $ing) {
    $ingredientsMap[$ing['menu_item_id']][] = $ing;
}

// Fetch active stock items catalog for recipe assignment
$stockItems = $pdo->query("SELECT id, name, category, unit, quantity, unit_cost FROM stock_items WHERE is_active = 1 ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC);

// Categories
$categories = array_values(array_unique(array_filter(array_column($menuItems, 'category'))));
sort($categories);

$activePage = 'manage_menu';
$pageTitle = 'Menu & Recipes - Asterisko Inventory';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>
<body class="bg-[#F4F7FB] dark:bg-[#071526] text-slate-800 dark:text-slate-100 min-h-screen">

    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="lg:pl-64 flex flex-col min-h-screen">
        <?php require_once __DIR__ . '/../includes/topbar.php'; ?>

        <main class="flex-1 p-4 sm:p-6 lg:p-8 space-y-6 max-w-7xl w-full mx-auto pb-24">

            <!-- Alerts -->
            <?php if ($message): ?>
                <div class="p-4 rounded-xl text-sm flex items-center justify-between shadow-sm <?= $messageType === 'success' ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800' : 'bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-300 border border-rose-200 dark:border-rose-800' ?>">
                    <div class="flex items-center gap-2.5">
                        <span class="text-base"><?= $messageType === 'success' ? '✓' : '⚠️' ?></span>
                        <span class="font-medium"><?= htmlspecialchars($message) ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="w-8 h-8 rounded-lg opacity-60 hover:opacity-100 font-bold ml-4">✕</button>
                </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white">Coffee Menu & Recipe Manager</h1>
                    <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                        Configure drink catalog, retail pricing, and automated inventory recipe deduction formulas.
                    </p>
                </div>
                <button type="button" onclick="openCreateModal()" class="btn-primary min-h-[46px] h-11 px-5 py-2.5 rounded-xl font-bold text-xs sm:text-sm shadow-md inline-flex items-center gap-2 whitespace-nowrap shrink-0 self-start sm:self-auto">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span>+ Add New Drink</span>
                </button>
            </div>

            <!-- Search & Category Filters -->
            <div class="bg-white dark:bg-[#0C2340] p-4 rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] flex flex-col sm:flex-row items-center justify-between gap-3">
                <div class="relative w-full sm:w-80">
                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>
                    <input type="text" id="menu-search" onkeyup="filterMenu()" placeholder="Search menu items..." 
                           class="w-full h-11 !pl-10 !pr-4 !py-2 bg-slate-50 dark:bg-[#07172C] text-xs sm:text-sm rounded-xl border border-slate-200 dark:border-[#173863] focus:outline-none focus:border-[#F28C28]">
                </div>

                <div class="flex items-center gap-2 overflow-x-auto w-full sm:w-auto custom-scrollbar pb-1">
                    <button type="button" onclick="filterCat('All')" class="menu-cat-btn active px-3.5 py-2 rounded-xl text-xs font-bold bg-[#F28C28] text-white whitespace-nowrap">All (<?= count($menuItems) ?>)</button>
                    <?php foreach ($categories as $c): 
                        $count = count(array_filter($menuItems, fn($m) => $m['category'] === $c));
                    ?>
                        <button type="button" onclick="filterCat('<?= htmlspecialchars($c) ?>')" class="menu-cat-btn px-3.5 py-2 rounded-xl text-xs font-bold bg-slate-100 dark:bg-[#132E52] text-slate-600 dark:text-slate-300 hover:text-slate-900 whitespace-nowrap">
                            <?= htmlspecialchars($c) ?> (<?= $count ?>)
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Menu Catalog Grid -->
            <div id="menu-grid" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                <?php foreach ($menuItems as $item): 
                    $ings = $ingredientsMap[$item['id']] ?? [];
                    $totalRecipeCost = 0.0;
                    foreach ($ings as $i) {
                        $totalRecipeCost += ((float)$i['quantity_required'] * (float)$i['unit_cost']);
                    }
                    $margin = $item['price'] > 0 ? (($item['price'] - $totalRecipeCost) / $item['price']) * 100 : 0;
                ?>
                    <div class="menu-card bg-white dark:bg-[#0C2340] rounded-2xl p-5 border border-slate-200/80 dark:border-[#15345C] shadow-sm flex flex-col justify-between"
                         data-category="<?= htmlspecialchars($item['category']) ?>"
                         data-name="<?= htmlspecialchars(strtolower($item['name'])) ?>">
                        
                        <div>
                            <div class="flex justify-between items-start gap-2">
                                <div>
                                    <span class="text-[11px] px-2 py-0.5 rounded-md font-bold uppercase tracking-wider bg-slate-100 dark:bg-[#132E52] text-slate-600 dark:text-slate-300">
                                        <?= htmlspecialchars($item['category']) ?>
                                    </span>
                                    <h3 class="text-base font-bold text-slate-900 dark:text-white mt-1"><?= htmlspecialchars($item['name']) ?></h3>
                                </div>
                                <div class="text-right">
                                    <span class="text-lg font-black text-[#F28C28]">₱<?= number_format($item['price'], 2) ?></span>
                                    <span class="block text-[10px] font-semibold text-slate-400">Retail Price</span>
                                </div>
                            </div>

                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 line-clamp-2"><?= htmlspecialchars($item['description'] ?: 'No description provided.') ?></p>

                            <!-- Cost & Margin Indicator -->
                            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 dark:bg-[#07172C] border border-slate-200/80 dark:border-[#173863] flex items-center justify-between text-xs">
                                <div>
                                    <span class="text-slate-400 text-[10px] uppercase font-bold block">Recipe Cost (COGS)</span>
                                    <span class="font-mono font-bold text-slate-800 dark:text-slate-200">₱<?= number_format($totalRecipeCost, 2) ?></span>
                                </div>
                                <div class="text-right">
                                    <span class="text-slate-400 text-[10px] uppercase font-bold block">Gross Margin</span>
                                    <span class="font-mono font-bold <?= $margin >= 60 ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' ?>">
                                        <?= number_format($margin, 1) ?>%
                                    </span>
                                </div>
                            </div>

                            <!-- Recipe Deductions -->
                            <div class="mt-3">
                                <span class="text-[10px] uppercase font-bold tracking-wider text-slate-400 block mb-1">
                                    Recipe Ingredients (<?= count($ings) ?>):
                                </span>
                                <?php if (!empty($ings)): ?>
                                    <div class="flex flex-wrap gap-1.5 max-h-24 overflow-y-auto custom-scrollbar">
                                        <?php foreach ($ings as $ing): ?>
                                            <span class="text-[11px] px-2 py-0.5 rounded-md font-medium bg-slate-100 dark:bg-[#132E52] text-slate-700 dark:text-slate-300">
                                                <?= ($ing['quantity_required'] * 1000 >= 1000 && $ing['unit'] === 'kg') ? ($ing['quantity_required'] * 1000) . 'g' : ($ing['quantity_required'] . ' ' . $ing['unit']) ?> 
                                                <b><?= htmlspecialchars($ing['stock_name']) ?></b>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-xs text-rose-500 font-semibold italic">⚠️ No recipe configured yet. Stock will not auto-deduct.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Card Actions -->
                        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-[#15345C] flex items-center justify-between gap-2">
                            <div class="flex items-center gap-1.5">
                                <form method="POST" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                    <button type="submit" class="text-xs px-2.5 py-1 rounded-lg font-bold <?= $item['is_active'] ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800' : 'bg-slate-100 dark:bg-[#132E52] text-slate-500' ?>" title="Click to toggle active">
                                        <?= $item['is_active'] ? '● Active' : '○ Inactive' ?>
                                    </button>
                                </form>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" onclick='openRecipeModal(<?= json_encode($item) ?>, <?= json_encode($ings) ?>)' class="btn-secondary !min-h-[36px] h-9 px-3 py-1.5 rounded-xl text-xs font-bold inline-flex items-center gap-1 shadow-sm">
                                    <span>⚙️ Recipe</span>
                                </button>
                                <button type="button" onclick='openEditModal(<?= json_encode($item) ?>)' class="btn-primary !min-h-[36px] h-9 px-3 py-1.5 rounded-xl text-xs font-bold inline-flex items-center gap-1 shadow-sm">
                                    <span>Edit</span>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </main>
    </div>

    <!-- ------------------------------------------------------------- -->
    <!-- MODAL 1: CREATE / EDIT MENU ITEM -->
    <!-- ------------------------------------------------------------- -->
    <div id="item-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#0C2340] rounded-2xl max-w-md w-full p-6 border border-slate-200 dark:border-[#15345C] shadow-2xl space-y-4">
            <div class="flex justify-between items-center border-b border-slate-200 dark:border-[#15345C] pb-3">
                <h3 id="item-modal-title" class="font-bold text-base text-slate-900 dark:text-white">Add New Drink</h3>
                <button type="button" onclick="closeItemModal()" class="w-8 h-8 rounded-lg text-slate-400 hover:text-white font-bold">✕</button>
            </div>
            <form method="POST" class="space-y-3.5">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="action" id="item-modal-action" value="create_menu_item">
                <input type="hidden" name="id" id="item-modal-id" value="0">

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Drink Name *</label>
                    <input type="text" name="name" id="item-input-name" required placeholder="e.g. Spanish Latte (Chong)" class="w-full text-xs sm:text-sm p-2.5 rounded-xl border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C]">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Category *</label>
                        <input type="text" name="category" id="item-input-category" required list="cat-suggestions" placeholder="Espresso" class="w-full text-xs sm:text-sm p-2.5 rounded-xl border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C]">
                        <datalist id="cat-suggestions">
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= htmlspecialchars($c) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Retail Price (₱) *</label>
                        <input type="number" step="0.50" min="0" name="price" id="item-input-price" required placeholder="165.00" class="w-full text-xs sm:text-sm p-2.5 rounded-xl border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C]">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Description / Notes</label>
                    <textarea name="description" id="item-input-desc" rows="2" placeholder="Drink ingredients summary or serving size..." class="w-full text-xs sm:text-sm p-2.5 rounded-xl border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C]"></textarea>
                </div>

                <div class="flex items-center gap-2">
                    <input type="checkbox" name="is_active" id="item-input-active" value="1" checked class="rounded border-slate-300 text-[#F28C28] focus:ring-[#F28C28]">
                    <label for="item-input-active" class="text-xs font-bold text-slate-700 dark:text-slate-300">Active (Visible in POS catalog)</label>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-slate-200 dark:border-[#15345C]">
                    <button type="button" onclick="closeItemModal()" class="btn-outline !min-h-[40px] h-10 px-4 py-2 rounded-xl text-xs font-bold">Cancel</button>
                    <button type="submit" class="btn-primary !min-h-[40px] h-10 px-5 py-2 rounded-xl text-xs font-bold shadow-md">Save Item</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ------------------------------------------------------------- -->
    <!-- MODAL 2: CONFIGURE RECIPE DEDUCTIONS -->
    <!-- ------------------------------------------------------------- -->
    <div id="recipe-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#0C2340] rounded-2xl max-w-2xl w-full p-6 border border-slate-200 dark:border-[#15345C] shadow-2xl space-y-4 max-h-[90vh] flex flex-col">
            <div class="flex justify-between items-center border-b border-slate-200 dark:border-[#15345C] pb-3 shrink-0">
                <div>
                    <h3 id="recipe-modal-title" class="font-bold text-base text-slate-900 dark:text-white">Recipe Formula</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Set raw inventory items automatically deducted per cup brewed.</p>
                </div>
                <button type="button" onclick="closeRecipeModal()" class="w-8 h-8 rounded-lg text-slate-400 hover:text-white font-bold">✕</button>
            </div>

            <form method="POST" id="recipe-form" class="space-y-4 overflow-y-auto flex-1 custom-scrollbar pr-1">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="save_recipe">
                <input type="hidden" name="menu_item_id" id="recipe-menu-id" value="0">
                <input type="hidden" name="ingredients_json" id="recipe-ingredients-json" value="[]">

                <div class="flex justify-between items-center">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Configured Ingredients</span>
                    <button type="button" onclick="addRecipeRow()" class="btn-secondary !min-h-[34px] h-8 px-3 py-1 rounded-lg text-xs font-bold inline-flex items-center gap-1 shadow-sm">
                        + Add Ingredient
                    </button>
                </div>

                <div class="border border-slate-200 dark:border-[#15345C] rounded-xl overflow-hidden">
                    <table class="w-full text-xs text-left" id="recipe-table">
                        <thead class="bg-slate-50 dark:bg-[#07172C]">
                            <tr>
                                <th class="p-2.5">Stock Item</th>
                                <th class="p-2.5">Deduction Qty</th>
                                <th class="p-2.5">Unit</th>
                                <th class="p-2.5 text-center w-12">Action</th>
                            </tr>
                        </thead>
                        <tbody id="recipe-table-body" class="divide-y divide-slate-100 dark:divide-[#15345C]">
                            <!-- Rows inserted dynamically -->
                        </tbody>
                    </table>
                </div>

                <div class="flex justify-between items-center pt-2 border-t border-slate-200 dark:border-[#15345C] shrink-0">
                    <div id="recipe-cogs-preview" class="text-xs text-slate-500 dark:text-slate-400 font-medium">
                        Estimated Recipe Cost: <span id="recipe-cogs-val" class="font-bold font-mono text-slate-800 dark:text-slate-100">₱0.00</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="closeRecipeModal()" class="btn-outline !min-h-[40px] h-10 px-4 py-2 rounded-xl text-xs font-bold">Cancel</button>
                        <button type="button" onclick="submitRecipeForm()" class="btn-primary !min-h-[40px] h-10 px-5 py-2 rounded-xl text-xs font-bold shadow-md">Save Recipe</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        const stockCatalog = <?= json_encode($stockItems) ?>;
        let currentCat = 'All';

        function filterMenu() {
            const query = (document.getElementById('menu-search').value || '').trim().toLowerCase();
            document.querySelectorAll('.menu-card').forEach(card => {
                const name = card.getAttribute('data-name') || '';
                const cat = card.getAttribute('data-category') || '';
                const matchCat = (currentCat === 'All' || cat === currentCat);
                const matchQuery = (!query || name.includes(query) || cat.toLowerCase().includes(query));
                card.style.display = (matchCat && matchQuery) ? 'flex' : 'none';
            });
        }

        function filterCat(cat) {
            currentCat = cat;
            document.querySelectorAll('.menu-cat-btn').forEach(btn => {
                if (btn.textContent.includes(cat) || (cat === 'All' && btn.textContent.includes('All'))) {
                    btn.className = 'menu-cat-btn active px-3.5 py-2 rounded-xl text-xs font-bold bg-[#F28C28] text-white whitespace-nowrap';
                } else {
                    btn.className = 'menu-cat-btn px-3.5 py-2 rounded-xl text-xs font-bold bg-slate-100 dark:bg-[#132E52] text-slate-600 dark:text-slate-300 hover:text-slate-900 whitespace-nowrap';
                }
            });
            filterMenu();
        }

        // Modal 1: Item
        function openCreateModal() {
            document.getElementById('item-modal-title').textContent = 'Add New Drink';
            document.getElementById('item-modal-action').value = 'create_menu_item';
            document.getElementById('item-modal-id').value = '0';
            document.getElementById('item-input-name').value = '';
            document.getElementById('item-input-category').value = 'Espresso';
            document.getElementById('item-input-price').value = '';
            document.getElementById('item-input-desc').value = '';
            document.getElementById('item-input-active').checked = true;
            document.getElementById('item-modal').classList.remove('hidden');
        }

        function openEditModal(item) {
            document.getElementById('item-modal-title').textContent = 'Edit Menu Item';
            document.getElementById('item-modal-action').value = 'update_menu_item';
            document.getElementById('item-modal-id').value = item.id;
            document.getElementById('item-input-name').value = item.name;
            document.getElementById('item-input-category').value = item.category;
            document.getElementById('item-input-price').value = item.price;
            document.getElementById('item-input-desc').value = item.description || '';
            document.getElementById('item-input-active').checked = parseInt(item.is_active) === 1;
            document.getElementById('item-modal').classList.remove('hidden');
        }

        function closeItemModal() {
            document.getElementById('item-modal').classList.add('hidden');
        }

        // Modal 2: Recipe
        function openRecipeModal(item, ings) {
            document.getElementById('recipe-modal-title').textContent = `Recipe Formula: ${item.name}`;
            document.getElementById('recipe-menu-id').value = item.id;

            const tbody = document.getElementById('recipe-table-body');
            tbody.innerHTML = '';

            if (ings && ings.length > 0) {
                ings.forEach(ing => {
                    addRecipeRow(ing.stock_item_id, ing.quantity_required, ing.unit);
                });
            } else {
                addRecipeRow();
            }

            calcRecipeCogs();
            document.getElementById('recipe-modal').classList.remove('hidden');
        }

        function closeRecipeModal() {
            document.getElementById('recipe-modal').classList.add('hidden');
        }

        function addRecipeRow(selectedStockId = 0, defaultQty = 1, defaultUnit = '') {
            const tbody = document.getElementById('recipe-table-body');
            const tr = document.createElement('tr');

            tr.innerHTML = `
                <td class="p-2.5">
                    <select class="w-full text-xs p-2 rounded-lg border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] recipe-stock-select" onchange="onRecipeStockChange(this)">
                        <option value="0">-- Select Stock Item --</option>
                        ${stockCatalog.map(s => `
                            <option value="${s.id}" data-unit="${s.unit}" data-cost="${s.unit_cost}" ${parseInt(selectedStockId) === parseInt(s.id) ? 'selected' : ''}>
                                ${escapeHtml(s.name)} (${s.unit})
                            </option>
                        `).join('')}
                    </select>
                </td>
                <td class="p-2.5">
                    <input type="number" step="0.0001" min="0.0001" value="${defaultQty}" class="w-24 text-xs p-2 rounded-lg border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] recipe-qty-input" oninput="calcRecipeCogs()">
                </td>
                <td class="p-2.5">
                    <input type="text" value="${defaultUnit}" class="w-20 text-xs p-2 rounded-lg border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] recipe-unit-input" placeholder="unit">
                </td>
                <td class="p-2.5 text-center">
                    <button type="button" onclick="this.closest('tr').remove(); calcRecipeCogs();" class="text-rose-500 hover:text-rose-700 font-bold text-base">✕</button>
                </td>
            `;

            tbody.appendChild(tr);
            if (selectedStockId > 0 && !defaultUnit) {
                const sel = tr.querySelector('.recipe-stock-select');
                onRecipeStockChange(sel);
            }
        }

        function onRecipeStockChange(sel) {
            const tr = sel.closest('tr');
            const opt = sel.options[sel.selectedIndex];
            const unit = opt.getAttribute('data-unit') || '';
            const unitInput = tr.querySelector('.recipe-unit-input');
            if (unit && unitInput) {
                unitInput.value = unit;
            }
            calcRecipeCogs();
        }

        function calcRecipeCogs() {
            let total = 0;
            document.querySelectorAll('#recipe-table-body tr').forEach(tr => {
                const sel = tr.querySelector('.recipe-stock-select');
                const opt = sel ? sel.options[sel.selectedIndex] : null;
                const cost = opt ? (parseFloat(opt.getAttribute('data-cost')) || 0) : 0;
                const qty = parseFloat(tr.querySelector('.recipe-qty-input')?.value) || 0;
                total += (cost * qty);
            });
            document.getElementById('recipe-cogs-val').textContent = '₱' + total.toFixed(2);
        }

        function submitRecipeForm() {
            const items = [];
            document.querySelectorAll('#recipe-table-body tr').forEach(tr => {
                const sel = tr.querySelector('.recipe-stock-select');
                const sId = parseInt(sel?.value) || 0;
                const qty = parseFloat(tr.querySelector('.recipe-qty-input')?.value) || 0;
                const unit = tr.querySelector('.recipe-unit-input')?.value.trim() || 'units';

                if (sId > 0 && qty > 0) {
                    items.push({ stock_item_id: sId, quantity_required: qty, unit: unit });
                }
            });

            document.getElementById('recipe-ingredients-json').value = JSON.stringify(items);
            document.getElementById('recipe-form').submit();
        }

        function escapeHtml(str) {
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
    </script>
</body>
</html>