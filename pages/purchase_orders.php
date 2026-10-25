<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/authguard.php';

// Accessible by Admin and Owner
requireRole(['Admin', 'Owner'], $pdo);

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$message = '';
$messageType = '';
$viewPoId = !empty($_GET['view_po']) ? (int)$_GET['view_po'] : 0;

// Handle PO Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    validateCsrfToken();
    $action = $_POST['action'];

    if ($action === 'create_po') {
        $supplier_id = (int)($_POST['supplier_id'] ?? 0);
        $order_type = $_POST['order_type'] === 'pre_order' ? 'pre_order' : 'purchase_order';
        $notes = trim($_POST['notes'] ?? '');
        $itemsJson = $_POST['items_json'] ?? '[]';
        $items = json_decode($itemsJson, true) ?: [];

        if ($supplier_id <= 0 || empty($items)) {
            $message = "Please select a supplier and include at least one order line item.";
            $messageType = "error";
        } else {
            try {
                $pdo->beginTransaction();

                // Generate unique transaction ID: PO-YYYYMMDD-XXXX
                $prefix = $order_type === 'pre_order' ? 'PRE' : 'PO';
                $todayStr = date('Ymd');
                $countToday = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE DATE(created_at) = CURRENT_DATE()")->fetchColumn();
                $seq = str_pad((int)$countToday + 1, 4, '0', STR_PAD_LEFT);
                $poNumber = "{$prefix}-{$todayStr}-{$seq}";

                // Compute total
                $totalAmount = 0;
                foreach ($items as $it) {
                    $qty = max(0, (float)($it['quantity'] ?? 0));
                    $price = max(0, (float)($it['unit_price'] ?? 0));
                    $totalAmount += round($qty * $price, 2);
                }

                // Insert purchase_orders
                $stmt = $pdo->prepare("
                    INSERT INTO purchase_orders (po_number, supplier_id, status, order_type, total_amount, notes, created_by)
                    VALUES (?, ?, 'ordered', ?, ?, ?, ?)
                ");
                $stmt->execute([$poNumber, $supplier_id, $order_type, $totalAmount, $notes, $username]);
                $poId = (int)$pdo->lastInsertId();

                // Insert purchase_order_items
                $itemStmt = $pdo->prepare("
                    INSERT INTO purchase_order_items (po_id, item_id, quantity, unit, unit_price, subtotal)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                foreach ($items as $it) {
                    $itemId = (int)$it['item_id'];
                    $qty = max(0, (float)$it['quantity']);
                    $unit = $it['unit'] ?? 'units';
                    $price = max(0, (float)$it['unit_price']);
                    $subtotal = round($qty * $price, 2);

                    if ($itemId > 0 && $qty > 0) {
                        $itemStmt->execute([$poId, $itemId, $qty, $unit, $price, $subtotal]);
                    }
                }

                // Audit trail
                $audit = $pdo->prepare("
                    INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
                    VALUES (NULL, NULL, ?, 'po_generated', ?, ?)
                ");
                $audit->execute([$totalAmount, "Generated {$order_type} {$poNumber} totaling ₱{$totalAmount}", $username]);

                $pdo->commit();
                header("Location: purchase_orders.php?view_po={$poId}&created=1");
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = "Failed to generate order: " . $e->getMessage();
                $messageType = "error";
            }
        }
    } elseif ($action === 'update_status') {
        $poId = (int)($_POST['po_id'] ?? 0);
        $newStatus = $_POST['status'] ?? 'ordered';
        if ($poId > 0 && in_array($newStatus, ['ordered', 'received', 'cancelled'])) {
            $stmt = $pdo->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $poId]);
            $message = "Order status updated.";
            $messageType = "success";
        }
    }
}

// Fetch active suppliers
$suppliers = $pdo->query("SELECT id, NAME, NAME AS name, contact_details, lead_time_days FROM suppliers WHERE is_active = 1 ORDER BY NAME")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all active items with their supplier
$stockItems = $pdo->query("SELECT id, NAME, NAME AS name, category, quantity, unit, reorder_point, unit_cost, supplier_id FROM stock_items WHERE is_active = 1 ORDER BY category, NAME")->fetchAll(PDO::FETCH_ASSOC);

// If viewing a specific PO
$poData = null;
$poItems = [];
if ($viewPoId > 0) {
    $poStmt = $pdo->prepare("
        SELECT po.*, s.name AS supplier_name, s.contact_details, s.lead_time_days
        FROM purchase_orders po
        JOIN suppliers s ON s.id = po.supplier_id
        WHERE po.id = ?
    ");
    $poStmt->execute([$viewPoId]);
    $poData = $poStmt->fetch(PDO::FETCH_ASSOC);

    if ($poData) {
        $poiStmt = $pdo->prepare("
            SELECT poi.*, si.name AS item_name
            FROM purchase_order_items poi
            JOIN stock_items si ON si.id = poi.item_id
            WHERE poi.po_id = ?
        ");
        $poiStmt->execute([$viewPoId]);
        $poItems = $poiStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Fetch recent POs
$recentOrders = $pdo->query("
    SELECT po.*, s.name AS supplier_name, COUNT(poi.id) AS total_lines
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    LEFT JOIN purchase_order_items poi ON poi.po_id = po.id
    GROUP BY po.id
    ORDER BY po.created_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders & Pre-Orders - Asterisko</title>
    <script>
        try {
            const saved = localStorage.getItem('theme');
            if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {}
    </script>
    <style>
        @media print {
            nav, #create-po-card, #recent-orders-card, .no-print { display: none !important; }
            body { background: white !important; color: black !important; font-size: 12pt; }
            #printable-invoice { display: block !important; border: none !important; box-shadow: none !important; margin: 0; padding: 0; }
        }
    </style>

<link rel="stylesheet" href="../assets/app-enhancements.css">

</head>
<body class="bg-stone-100 dark:bg-stone-950 text-stone-800 dark:text-stone-200 min-h-screen">

    <!-- Top Navbar -->
    <nav class="bg-stone-900 dark:bg-black text-white px-4 py-3 flex justify-between items-center border-b border-stone-800 no-print">
        <div class="flex items-center gap-3">
            <a href="dashboard.php" class="text-emerald-400 hover:text-emerald-300 text-sm font-semibold flex items-center gap-1">
                &larr; Dashboard
            </a>
            <span class="text-stone-600">|</span>
            <span class="font-bold text-base sm:text-lg">Purchase Order & Pre-Order Generator</span>
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

    <div class="max-w-5xl mx-auto p-4 space-y-6">

        <?php if ($message): ?>
            <div class="p-3.5 rounded-xl text-sm font-medium border <?= $messageType === 'success' ? 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-200 border-emerald-300 dark:border-emerald-800' : 'bg-rose-50 dark:bg-rose-950/60 text-rose-800 dark:text-rose-200 border-rose-300 dark:border-rose-800' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Printable PO / Invoice View if Selected -->
        <?php if ($poData): ?>
            <div id="printable-invoice" class="bg-white dark:bg-stone-900 rounded-xl shadow-lg p-6 sm:p-8 border border-stone-200 dark:border-stone-800">
                <div class="flex justify-between items-start border-b border-stone-200 dark:border-stone-800 pb-5 mb-5">
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-emerald-900 dark:text-emerald-500">Asterisko Artisan Coffee</h1>
                        <p class="text-xs text-stone-500 mt-0.5">Inventory Operations & Procurement System</p>
                        <div class="mt-3 text-xs">
                            <span class="font-bold text-stone-700 dark:text-stone-300 uppercase">Vendor / Supplier:</span>
                            <p class="text-sm font-semibold text-stone-900 dark:text-stone-100"><?= htmlspecialchars($poData['supplier_name']) ?></p>
                            <p class="text-stone-500"><?= htmlspecialchars($poData['contact_details'] ?? 'No contact info') ?></p>
                            <p class="text-[11px] text-stone-400">Lead Time: <?= (int)$poData['lead_time_days'] ?> Days</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <span class="inline-block bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 text-xs font-bold px-3 py-1 rounded uppercase tracking-wider mb-2">
                            <?= htmlspecialchars(strtoupper(str_replace('_', ' ', $poData['order_type']))) ?>
                        </span>
                        <h2 class="text-xl font-mono font-bold text-stone-900 dark:text-stone-100"><?= htmlspecialchars($poData['po_number']) ?></h2>
                        <p class="text-xs text-stone-500 mt-1">Date: <?= date('M d, Y h:i A', strtotime($poData['created_at'])) ?></p>
                        <p class="text-xs text-stone-500">Created by: <b><?= htmlspecialchars($poData['created_by']) ?></b></p>
                    </div>
                </div>

                <!-- PO Items Table -->
                <table class="w-full text-left text-xs mb-6">
                    <thead class="bg-stone-100 dark:bg-stone-800 uppercase font-semibold text-stone-600 dark:text-stone-300 border-y border-stone-200 dark:border-stone-700">
                        <tr>
                            <th class="p-2.5">#</th>
                            <th class="p-2.5">Item Description</th>
                            <th class="p-2.5 text-right">Order Qty</th>
                            <th class="p-2.5 text-right">Unit Price</th>
                            <th class="p-2.5 text-right">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200 dark:divide-stone-800">
                        <?php foreach ($poItems as $idx => $it): ?>
                            <tr>
                                <td class="p-2.5 text-stone-400 font-mono"><?= $idx + 1 ?></td>
                                <td class="p-2.5 font-semibold text-stone-900 dark:text-stone-100"><?= htmlspecialchars($it['item_name']) ?></td>
                                <td class="p-2.5 text-right font-mono font-bold"><?= number_format($it['quantity'], 2) ?> <?= htmlspecialchars($it['unit']) ?></td>
                                <td class="p-2.5 text-right font-mono">₱<?= number_format($it['unit_price'], 2) ?></td>
                                <td class="p-2.5 text-right font-mono font-bold">₱<?= number_format($it['subtotal'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="border-t-2 border-stone-300 dark:border-stone-700 font-bold">
                        <tr>
                            <td colspan="4" class="p-3 text-right uppercase text-xs">Total Order Value:</td>
                            <td class="p-3 text-right font-mono text-base text-emerald-700 dark:text-emerald-400">₱<?= number_format($poData['total_amount'], 2) ?></td>
                        </tr>
                    </tfoot>
                </table>

                <?php if (!empty($poData['notes'])): ?>
                    <div class="mb-6 p-3 bg-stone-50 dark:bg-stone-800 rounded-lg text-xs text-stone-600 dark:text-stone-300 border border-stone-200 dark:border-stone-700">
                        <b>Order Instructions / Notes:</b> <?= htmlspecialchars($poData['notes']) ?>
                    </div>
                <?php endif; ?>

                <!-- Signatures -->
                <div class="grid grid-cols-2 gap-8 pt-8 mt-6 border-t border-stone-200 dark:border-stone-800 text-xs">
                    <div>
                        <p class="text-stone-400 mb-8">Authorized Store Signature:</p>
                        <div class="border-b border-stone-400 w-48"></div>
                        <p class="font-semibold text-stone-800 dark:text-stone-200 mt-1"><?= htmlspecialchars($poData['created_by']) ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-stone-400 mb-8">Supplier Acknowledgment & Delivery Date:</p>
                        <div class="border-b border-stone-400 w-48 ml-auto"></div>
                        <p class="text-stone-500 mt-1">Received By / Date</p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3 no-print">
                    <button onclick="window.print()" class="px-5 py-2 bg-stone-800 hover:bg-black text-white rounded-lg text-xs font-semibold flex items-center gap-1 shadow">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                        Print Invoice / Delivery Note
                    </button>
                    <a href="purchase_orders.php" class="px-4 py-2 border border-stone-300 dark:border-stone-700 rounded-lg text-xs font-semibold text-stone-700 dark:text-stone-300 hover:bg-stone-100 dark:hover:bg-stone-800">
                        Create Another PO
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Create PO Form Card -->
        <div id="create-po-card" class="bg-white dark:bg-stone-900 rounded-xl shadow p-5 border border-stone-200 dark:border-stone-800">
            <h2 class="text-base font-bold text-stone-900 dark:text-stone-100 mb-1 flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Generate New Pre-Order or Purchase Order
            </h2>
            <p class="text-xs text-stone-500 mb-4">Construct orders addressed to registered suppliers. Automatically calculates line totals and formats printable invoices.</p>

            <form id="po-form" method="POST" class="space-y-4 text-xs">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="create_po">
                <input type="hidden" name="items_json" id="po_items_json" value="[]">

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-semibold mb-1">Select Supplier *</label>
                        <select name="supplier_id" id="supplier-select" required onchange="handleSupplierChange()"
                                class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 font-medium">
                            <option value="">-- Choose Supplier --</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['NAME'] ?? $s['name']) ?> (<?= $s['lead_time_days'] ?>d lead)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block font-semibold mb-1">Order Document Type *</label>
                        <select name="order_type" class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 font-medium">
                            <option value="purchase_order">Standard Purchase Order</option>
                            <option value="pre_order">Pre-Order (Advance Booking)</option>
                        </select>
                    </div>

                    <div>
                        <label class="block font-semibold mb-1">Order Instructions / Notes</label>
                        <input type="text" name="notes" placeholder="e.g., Deliver before 9AM, call barista upon arrival"
                               class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800">
                    </div>
                </div>

                <!-- Supplier Items Quick Add Helper -->
                <div id="quick-add-container" class="hidden p-3 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-xl space-y-2">
                    <div class="flex justify-between items-center">
                        <span class="font-bold text-emerald-900 dark:text-emerald-200 text-xs">Supplier Item Catalog & Low-Stock Auto-Detect:</span>
                        <button type="button" onclick="autoAddLowStock()" class="bg-emerald-700 hover:bg-emerald-800 text-white px-2.5 py-1 rounded text-[11px] font-semibold">
                            ⚡ Auto-Add Items Below ROP
                        </button>
                    </div>
                    <div id="supplier-chips" class="flex flex-wrap gap-1.5 pt-1"></div>
                </div>

                <!-- Order Line Items Table -->
                <div class="border border-stone-200 dark:border-stone-800 rounded-xl overflow-hidden">
                    <div class="p-3 bg-stone-50 dark:bg-stone-800 border-b border-stone-200 dark:border-stone-700 flex justify-between items-center">
                        <span class="font-bold uppercase tracking-wider text-[11px] text-stone-600 dark:text-stone-300">Order Line Items</span>
                        <button type="button" onclick="addCustomRow()" class="text-xs font-semibold text-emerald-600 hover:underline">+ Add Custom Line</button>
                    </div>
                    <table class="w-full text-left">
                        <thead class="bg-stone-100 dark:bg-stone-800/80 uppercase font-semibold text-stone-500 text-[10px]">
                            <tr>
                                <th class="p-2.5">Stock Item</th>
                                <th class="p-2.5 w-24">Order Qty</th>
                                <th class="p-2.5 w-20">Unit</th>
                                <th class="p-2.5 w-28">Unit Price (₱)</th>
                                <th class="p-2.5 w-28 text-right">Subtotal</th>
                                <th class="p-2.5 w-10 text-center">Del</th>
                            </tr>
                        </thead>
                        <tbody id="po-lines-tbody" class="divide-y divide-stone-200 dark:divide-stone-800">
                            <!-- Populated dynamically -->
                        </tbody>
                        <tfoot class="bg-stone-50 dark:bg-stone-800/50 font-bold border-t border-stone-200 dark:border-stone-700">
                            <tr>
                                <td colspan="4" class="p-3 text-right uppercase text-xs">Grand Total (₱):</td>
                                <td id="po-grand-total" class="p-3 text-right font-mono text-base text-emerald-700 dark:text-emerald-400">₱0.00</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" onclick="submitPoForm()" class="bg-emerald-700 hover:bg-emerald-800 text-white font-bold px-6 py-2.5 rounded-lg transition shadow flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                        Finalize & Generate Order Invoice
                    </button>
                </div>
            </form>
        </div>

        <!-- Recent Purchase Orders Table -->
        <div id="recent-orders-card" class="bg-white dark:bg-stone-900 rounded-xl shadow overflow-hidden border border-stone-200 dark:border-stone-800">
            <div class="p-4 border-b border-stone-200 dark:border-stone-800">
                <h3 class="text-sm font-bold text-stone-900 dark:text-stone-100">Recent Purchase Orders & Invoices</h3>
                <p class="text-xs text-stone-500">Historical records of generated supplier orders.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-stone-50 dark:bg-stone-800 uppercase font-semibold text-stone-500 border-b border-stone-200 dark:border-stone-700">
                        <tr>
                            <th class="p-3">Order Number</th>
                            <th class="p-3">Date</th>
                            <th class="p-3">Supplier</th>
                            <th class="p-3">Lines</th>
                            <th class="p-3">Total Amount</th>
                            <th class="p-3">Status</th>
                            <th class="p-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200 dark:divide-stone-800">
                        <?php if (empty($recentOrders)): ?>
                            <tr><td colspan="7" class="p-4 text-center text-stone-500">No purchase orders created yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentOrders as $ro): ?>
                                <tr class="hover:bg-stone-50 dark:hover:bg-stone-800/50">
                                    <td class="p-3 font-mono font-bold text-stone-900 dark:text-stone-100">
                                        <?= htmlspecialchars($ro['po_number']) ?>
                                    </td>
                                    <td class="p-3 text-stone-500">
                                        <?= date('M d, Y', strtotime($ro['created_at'])) ?>
                                    </td>
                                    <td class="p-3 font-semibold text-stone-800 dark:text-stone-200">
                                        <?= htmlspecialchars($ro['supplier_name']) ?>
                                    </td>
                                    <td class="p-3 font-mono">
                                        <?= (int)$ro['total_lines'] ?> items
                                    </td>
                                    <td class="p-3 font-mono font-bold text-emerald-700 dark:text-emerald-400">
                                        ₱<?= number_format($ro['total_amount'], 2) ?>
                                    </td>
                                    <td class="p-3">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase
                                            <?= $ro['status'] === 'received' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300' ?>">
                                            <?= htmlspecialchars($ro['status']) ?>
                                        </span>
                                    </td>
                                    <td class="p-3 text-right space-x-2">
                                        <a href="purchase_orders.php?view_po=<?= $ro['id'] ?>" class="text-emerald-600 hover:underline font-semibold">View / Print</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- JavaScript for Dynamic Order Building -->
    <script>
        const ALL_STOCK_ITEMS = <?= json_encode($stockItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const tbody = document.getElementById('po-lines-tbody');
        const grandTotalEl = document.getElementById('po-grand-total');

        function handleSupplierChange() {
            const supplierId = parseInt(document.getElementById('supplier-select').value, 10);
            const container = document.getElementById('quick-add-container');
            const chips = document.getElementById('supplier-chips');
            chips.innerHTML = '';

            if (!supplierId) {
                container.classList.add('hidden');
                return;
            }

            const supplierItems = ALL_STOCK_ITEMS.filter(it => it.supplier_id == supplierId);
            if (supplierItems.length > 0) {
                container.classList.remove('hidden');
                supplierItems.forEach(it => {
                    const isLow = parseFloat(it.quantity) <= parseFloat(it.reorder_point);
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = `px-2.5 py-1 rounded text-xs border font-medium flex items-center gap-1 ${isLow ? 'bg-rose-100 dark:bg-rose-950 text-rose-800 dark:text-rose-200 border-rose-300' : 'bg-white dark:bg-stone-800 text-stone-700 dark:text-stone-300 border-stone-300 dark:border-stone-700'}`;
                    btn.innerHTML = `+ ${escapeHtml(it.name)} ${isLow ? '<b class="text-[10px] text-rose-600">(LOW)</b>' : ''}`;
                    btn.onclick = () => addStockItemRow(it);
                    chips.appendChild(btn);
                });
            } else {
                container.classList.add('hidden');
            }
        }

        function autoAddLowStock() {
            const supplierId = parseInt(document.getElementById('supplier-select').value, 10);
            const supplierItems = ALL_STOCK_ITEMS.filter(it => it.supplier_id == supplierId && parseFloat(it.quantity) <= parseFloat(it.reorder_point));
            
            if (supplierItems.length === 0) {
                alert("No items from this supplier are currently below their Reorder Point.");
                return;
            }

            supplierItems.forEach(it => {
                // Check if already in table
                const exists = Array.from(tbody.querySelectorAll('.item-select')).some(sel => sel.value == it.id);
                if (!exists) {
                    addStockItemRow(it);
                }
            });
        }

        function addStockItemRow(item) {
            const tr = document.createElement('tr');
            tr.className = "hover:bg-stone-50 dark:hover:bg-stone-800/50";
            tr.innerHTML = `
                <td class="p-2.5">
                    <select class="item-select w-full p-1.5 border border-stone-300 dark:border-stone-700 rounded bg-white dark:bg-stone-800 font-semibold text-stone-800 dark:text-stone-200" onchange="onRowItemChange(this)">
                        ${ALL_STOCK_ITEMS.map(si => `
                            <option value="${si.id}" ${si.id == item.id ? 'selected' : ''} data-unit="${si.unit}" data-cost="${si.unit_cost}">
                                ${escapeHtml(si.name)} (Stock: ${si.quantity} ${si.unit})
                            </option>
                        `).join('')}
                    </select>
                </td>
                <td class="p-2.5">
                    <input type="number" step="0.01" class="item-qty w-full p-1.5 border border-stone-300 dark:border-stone-700 rounded bg-white dark:bg-stone-800 font-mono font-bold"
                           value="1.00" oninput="calculateSubtotal(this)">
                </td>
                <td class="p-2.5">
                    <span class="item-unit text-stone-500 font-mono text-xs">${item.unit}</span>
                </td>
                <td class="p-2.5">
                    <input type="number" step="0.01" class="item-price w-full p-1.5 border border-stone-300 dark:border-stone-700 rounded bg-white dark:bg-stone-800 font-mono"
                           value="${item.unit_cost || 0.00}" oninput="calculateSubtotal(this)">
                </td>
                <td class="p-2.5 text-right font-mono font-bold item-subtotal">
                    ₱${parseFloat(item.unit_cost || 0).toFixed(2)}
                </td>
                <td class="p-2.5 text-center">
                    <button type="button" onclick="this.closest('tr').remove(); recalculateGrandTotal();" class="text-rose-600 hover:text-rose-700 font-bold text-sm">&times;</button>
                </td>
            `;
            tbody.appendChild(tr);
            recalculateGrandTotal();
        }

        function addCustomRow() {
            if (ALL_STOCK_ITEMS.length > 0) {
                addStockItemRow(ALL_STOCK_ITEMS[0]);
            }
        }

        function onRowItemChange(selectEl) {
            const tr = selectEl.closest('tr');
            const opt = selectEl.options[selectEl.selectedIndex];
            tr.querySelector('.item-unit').textContent = opt.dataset.unit || 'units';
            tr.querySelector('.item-price').value = opt.dataset.cost || 0.00;
            calculateSubtotal(tr.querySelector('.item-qty'));
        }

        function calculateSubtotal(inputEl) {
            const tr = inputEl.closest('tr');
            const qty = parseFloat(tr.querySelector('.item-qty').value) || 0;
            const price = parseFloat(tr.querySelector('.item-price').value) || 0;
            const subtotal = qty * price;
            tr.querySelector('.item-subtotal').textContent = `₱${subtotal.toFixed(2)}`;
            recalculateGrandTotal();
        }

        function recalculateGrandTotal() {
            let sum = 0;
            tbody.querySelectorAll('tr').forEach(tr => {
                const qty = parseFloat(tr.querySelector('.item-qty').value) || 0;
                const price = parseFloat(tr.querySelector('.item-price').value) || 0;
                sum += (qty * price);
            });
            grandTotalEl.textContent = `₱${sum.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        }

        function submitPoForm() {
            const rows = tbody.querySelectorAll('tr');
            const items = [];

            rows.forEach(tr => {
                const itemId = parseInt(tr.querySelector('.item-select').value, 10);
                const qty = parseFloat(tr.querySelector('.item-qty').value) || 0;
                const unit = tr.querySelector('.item-unit').textContent.trim();
                const price = parseFloat(tr.querySelector('.item-price').value) || 0;

                if (itemId > 0 && qty > 0) {
                    items.push({ item_id: itemId, quantity: qty, unit: unit, unit_price: price });
                }
            });

            if (items.length === 0) {
                alert("Please add at least one line item to the order.");
                return;
            }

            document.getElementById('po_items_json').value = JSON.stringify(items);
            document.getElementById('po-form').submit();
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        document.getElementById('theme-toggle').addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark');
            try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch (e) {}
        });
    </script>
</body>
</html>
