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

$totalOrdersCount = count($recentOrders);
$orderedPendingCount = count(array_filter($recentOrders, fn($o) => $o['status'] === 'ordered'));
$totalPoSpend = array_sum(array_column($recentOrders, 'total_amount'));

$activePage = 'purchase_orders';
$pageTitle = 'Purchase Orders - Asterisko Inventory';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/../includes/head.php'; ?>
    <style>
        @media print {
            aside, header, #sidebar-backdrop, .no-print { display: none !important; }
            .lg\:pl-64 { padding-left: 0 !important; }
            body { background: white !important; color: black !important; font-size: 11pt; }
            #printable-invoice { display: block !important; border: none !important; box-shadow: none !important; margin: 0; padding: 0; }
        }
    </style>
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

            <!-- Title & Top Actions -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 no-print">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">Purchase Orders & Pre-Orders</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Generate formal purchase orders, track vendor lead times, and export printable documents.</p>
                </div>
                <button onclick="document.getElementById('create-po-section').scrollIntoView({behavior: 'smooth'})" class="btn-primary inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold shadow-md">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span>+ New Order</span>
                </button>
            </div>

            <!-- Messages Feedback -->
            <?php if ($message): ?>
                <div class="no-print p-4 rounded-xl text-sm font-medium flex items-center gap-2 <?= $messageType === 'success' ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-200 border border-emerald-300 dark:border-emerald-800' : 'bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-200 border border-rose-300 dark:border-rose-800' ?>">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $messageType === 'success' ? 'M5 13l4 4L19 7' : 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z' ?>"/></svg>
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
            <?php endif; ?>

            <!-- Printable Formal Invoice / PO View (When view_po is clicked) -->
            <?php if ($poData): ?>
            <div id="printable-invoice" class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-xl border border-slate-200/80 dark:border-[#15345C] p-6 sm:p-10 mb-8 space-y-6">
                <div class="flex justify-between items-start pb-6 border-b border-slate-200 dark:border-[#15345C]">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-2xl bg-[#F28C28]/15 border border-[#F28C28]/30 flex items-center justify-center">
                            <svg class="w-7 h-7 text-[#F28C28]" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2a1.25 1.25 0 0 1 1.25 1.25v3.3a1.25 1.25 0 1 1-2.5 0v-3.3A1.25 1.25 0 0 1 12 2zm0 14.2a1.25 1.25 0 0 1 1.25 1.25v3.3a1.25 1.25 0 1 1-2.5 0v-3.3A1.25 1.25 0 0 1 12 16.2zM20.75 10.75a1.25 1.25 0 0 1 0 2.5h-3.3a1.25 1.25 0 1 1 0-2.5h3.3zm-14.2 0a1.25 1.25 0 0 1 0 2.5h-3.3a1.25 1.25 0 1 1 0-2.5h3.3zm11.66-5.83a1.25 1.25 0 0 1 0 1.77l-2.33 2.33a1.25 1.25 0 1 1-1.77-1.77l2.33-2.33a1.25 1.25 0 0 1 1.77 0zm-10.05 10.05a1.25 1.25 0 0 1 0 1.77l-2.33 2.33a1.25 1.25 0 1 1-1.77-1.77l2.33-2.33a1.25 1.25 0 0 1 1.77 0zm10.05 0a1.25 1.25 0 0 1 1.77 1.77l-2.33 2.33a1.25 1.25 0 1 1-1.77-1.77l2.33-2.33zm-10.05-10.05a1.25 1.25 0 0 1 1.77-1.77l2.33 2.33a1.25 1.25 0 1 1-1.77 1.77l-2.33-2.33z"/>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-2xl font-black tracking-wider uppercase text-slate-900 dark:text-white">ASTERISKO</h2>
                            <p class="text-xs text-slate-500 font-medium">Inventory & Supply Management</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <span class="inline-block px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider mb-1 <?= $poData['order_type'] === 'pre_order' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800' ?>">
                            <?= $poData['order_type'] === 'pre_order' ? 'PRE-ORDER NOTICE' : 'OFFICIAL PURCHASE ORDER' ?>
                        </span>
                        <h3 class="text-xl font-mono font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($poData['po_number']) ?></h3>
                        <p class="text-xs text-slate-400">Date: <?= date('F j, Y', strtotime($poData['created_at'])) ?></p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-8 text-xs">
                    <div>
                        <span class="font-bold uppercase tracking-wider text-slate-400 block mb-1">Vendor / Supplier:</span>
                        <h4 class="font-bold text-sm text-slate-900 dark:text-white"><?= htmlspecialchars($poData['supplier_name']) ?></h4>
                        <p class="text-slate-500 mt-0.5 whitespace-pre-line"><?= htmlspecialchars($poData['contact_details']) ?></p>
                        <p class="text-slate-500 mt-1">Lead Time: <b><?= $poData['lead_time_days'] ?> days</b></p>
                    </div>
                    <div class="text-right">
                        <span class="font-bold uppercase tracking-wider text-slate-400 block mb-1">Deliver To:</span>
                        <h4 class="font-bold text-sm text-slate-900 dark:text-white">Asterisko Main Terminals</h4>
                        <p class="text-slate-500 mt-0.5">Authorized Issuer: <b><?= htmlspecialchars($poData['created_by']) ?></b></p>
                        <p class="text-slate-500 mt-1">Order Status: <b class="uppercase <?= $poData['status'] === 'ordered' ? 'text-[#F28C28]' : ($poData['status'] === 'received' ? 'text-emerald-600' : 'text-rose-600') ?>"><?= htmlspecialchars($poData['status']) ?></b></p>
                    </div>
                </div>

                <!-- PO Items Table -->
                <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-[#15345C]">
                    <table class="w-full text-xs text-left">
                        <thead>
                            <tr>
                                <th>Item Description</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th class="text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($poItems as $line): ?>
                            <tr>
                                <td class="font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($line['item_name']) ?></td>
                                <td class="font-mono"><?= number_format($line['quantity'], 2) ?> <?= htmlspecialchars($line['unit']) ?></td>
                                <td class="font-mono">₱<?= number_format($line['unit_price'], 2) ?></td>
                                <td class="font-mono font-bold text-right">₱<?= number_format($line['subtotal'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-50 dark:bg-[#07172C] font-bold text-sm">
                                <td colspan="3" class="text-right py-3 px-4 uppercase text-slate-500">Total Purchase Amount:</td>
                                <td class="text-right py-3 px-4 font-mono font-black text-slate-900 dark:text-white">₱<?= number_format($poData['total_amount'], 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="flex items-center justify-between pt-4 border-t border-slate-200 dark:border-[#15345C] no-print">
                    <div class="flex items-center gap-2">
                        <form method="POST" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="po_id" value="<?= $poData['id'] ?>">
                            <select name="status" onchange="this.form.submit()" class="text-xs p-1.5 rounded-lg border border-slate-300 dark:border-[#173863] bg-white dark:bg-[#07172C]">
                                <option value="ordered" <?= $poData['status'] === 'ordered' ? 'selected' : '' ?>>Status: Ordered (Pending)</option>
                                <option value="received" <?= $poData['status'] === 'received' ? 'selected' : '' ?>>Status: Received</option>
                                <option value="cancelled" <?= $poData['status'] === 'cancelled' ? 'selected' : '' ?>>Status: Cancelled</option>
                            </select>
                        </form>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="window.print()" class="btn-primary px-5 py-2 rounded-xl text-xs font-bold shadow-md">
                            Print / Save PDF
                        </button>
                        <a href="purchase_orders.php" class="px-4 py-2 border border-slate-300 dark:border-[#173863] rounded-xl text-xs font-semibold hover:bg-slate-100 dark:hover:bg-[#132E52]">
                            Close
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- 3 Metric Cards (Canva Layout) -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6 no-print">
                <div class="stat-card p-5" style="--stat-accent: #0C2340;">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Total Orders</span>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight"><?= sprintf('%02d', $totalOrdersCount) ?></span>
                        <p class="text-xs text-slate-400 mt-1">Generated purchase records</p>
                    </div>
                </div>
                <div class="stat-card p-5" style="--stat-accent: #F28C28;">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Pending Orders</span>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-[#F28C28] tracking-tight"><?= sprintf('%02d', $orderedPendingCount) ?></span>
                        <p class="text-xs text-[#F28C28] font-semibold mt-1">Awaiting vendor delivery</p>
                    </div>
                </div>
                <div class="stat-card p-5" style="--stat-accent: #10B981;">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Total Value</span>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-emerald-600 dark:text-emerald-400 tracking-tight">₱<?= number_format($totalPoSpend, 2) ?></span>
                        <p class="text-xs text-emerald-600 dark:text-emerald-400 font-semibold mt-1">Order commitments</p>
                    </div>
                </div>
            </div>

            <!-- Create Purchase Order Section -->
            <div id="create-po-section" class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] p-6 space-y-6 no-print">
                <div class="border-b border-slate-200 dark:border-[#15345C] pb-4 flex justify-between items-center">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900 dark:text-white">Create Purchase Order or Pre-Order</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Select preferred vendor, add line items or auto-fill items below reorder point.</p>
                    </div>
                    <button type="button" onclick="autoFillLowStock()" class="btn-secondary px-3.5 py-1.5 rounded-xl text-xs font-bold shadow-sm">
                        Auto-Fill Low Stock Items
                    </button>
                </div>

                <form method="POST" id="po-form" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="create_po">
                    <input type="hidden" name="items_json" id="po-items-json" value="[]">

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Target Supplier *</label>
                            <select name="supplier_id" id="po-supplier-select" required class="w-full">
                                <option value="">-- Choose Supplier --</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['NAME'] ?? $s['name']) ?> (<?= $s['lead_time_days'] ?>d lead)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Order Type</label>
                            <select name="order_type" class="w-full">
                                <option value="purchase_order">Standard Purchase Order</option>
                                <option value="pre_order">Pre-Order (Advance Notice)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Internal Notes</label>
                            <input type="text" name="notes" placeholder="Delivery instructions or PO notes..." class="w-full">
                        </div>
                    </div>

                    <!-- Line Items Table -->
                    <div class="rounded-xl border border-slate-200 dark:border-[#15345C] overflow-hidden">
                        <div class="p-3 bg-slate-50 dark:bg-[#07172C] border-b border-slate-200 dark:border-[#15345C] flex justify-between items-center">
                            <span class="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300">Order Line Items</span>
                            <button type="button" onclick="addPoRow()" class="text-xs font-bold text-[#F28C28] hover:text-[#E07B18]">+ Add Line</button>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs text-left" id="po-lines-table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Order Qty</th>
                                        <th>Unit Cost (₱)</th>
                                        <th class="text-right">Line Total</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="po-lines-body">
                                    <tr id="empty-po-row">
                                        <td colspan="5" class="text-center py-6 text-slate-400">Click "+ Add Line" or "Auto-Fill Low Stock Items" to populate.</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="bg-slate-50 dark:bg-[#07172C] font-bold">
                                        <td colspan="3" class="text-right py-2.5 px-4 text-slate-500">Grand Total:</td>
                                        <td class="text-right py-2.5 px-4 font-mono text-sm text-[#F28C28]" id="grand-total-display">₱0.00</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button type="submit" class="btn-primary px-6 py-2.5 rounded-xl text-sm font-bold shadow-md">
                            Generate Purchase Order
                        </button>
                    </div>
                </form>
            </div>

            <!-- Recent Orders Directory Table -->
            <div class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] overflow-hidden no-print">
                <div class="p-5 border-b border-slate-200 dark:border-[#15345C] flex flex-col sm:flex-row justify-between items-center gap-3">
                    <div>
                        <h2 class="font-bold text-base text-slate-900 dark:text-white">Recent Purchase Orders</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Track and manage vendor procurement status</p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>Supplier</th>
                                <th>Type</th>
                                <th>Lines</th>
                                <th>Total Amount</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($recentOrders) === 0): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-8 text-slate-400">No purchase orders generated yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentOrders as $ro): ?>
                                <tr>
                                    <td class="font-mono font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($ro['po_number']) ?></td>
                                    <td class="font-semibold"><?= htmlspecialchars($ro['supplier_name']) ?></td>
                                    <td>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?= $ro['order_type'] === 'pre_order' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800' ?>">
                                            <?= $ro['order_type'] === 'pre_order' ? 'Pre-Order' : 'Standard' ?>
                                        </span>
                                    </td>
                                    <td class="font-mono"><?= $ro['total_lines'] ?> items</td>
                                    <td class="font-mono font-bold">₱<?= number_format($ro['total_amount'], 2) ?></td>
                                    <td class="text-slate-500 whitespace-nowrap"><?= substr($ro['created_at'], 0, 10) ?></td>
                                    <td>
                                        <?php if ($ro['status'] === 'ordered'): ?>
                                            <span class="badge-pending">Ordered</span>
                                        <?php elseif ($ro['status'] === 'received'): ?>
                                            <span class="badge-good">Received</span>
                                        <?php else: ?>
                                            <span class="badge-danger">Cancelled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <a href="purchase_orders.php?view_po=<?= $ro['id'] ?>" class="text-[#F28C28] hover:text-[#E07B18] font-bold text-xs inline-flex items-center gap-1">
                                            View / Print &rarr;
                                        </a>
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

    <script>
        const stockCatalog = <?= json_encode($stockItems) ?>;

        function addPoRow(selectedItemId = 0, defaultQty = 1) {
            const tbody = document.getElementById('po-lines-body');
            const empty = document.getElementById('empty-po-row');
            if (empty) empty.remove();

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <select class="w-full text-xs p-1.5 rounded-lg border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] po-item-select" onchange="onPoItemChange(this)">
                        <option value="0">-- Select Catalog Item --</option>
                        ${stockCatalog.map(s => `<option value="${s.id}" data-cost="${s.unit_cost}" data-unit="${s.unit}" ${selectedItemId == s.id ? 'selected' : ''}>${s.NAME || s.name} (${s.unit})</option>`).join('')}
                    </select>
                </td>
                <td><input type="number" step="0.01" min="0.1" value="${defaultQty}" class="w-24 text-xs p-1.5 rounded-lg border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] po-qty" oninput="calcPoTotals()"></td>
                <td><input type="number" step="0.01" min="0" value="0.00" class="w-28 text-xs p-1.5 rounded-lg border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] po-cost" oninput="calcPoTotals()"></td>
                <td class="font-mono font-bold text-right po-subtotal">₱0.00</td>
                <td class="text-center"><button type="button" onclick="this.closest('tr').remove(); calcPoTotals();" class="text-rose-500 font-bold">&times;</button></td>
            `;
            tbody.appendChild(tr);

            if (selectedItemId > 0) {
                const selectEl = tr.querySelector('.po-item-select');
                onPoItemChange(selectEl);
            }
            calcPoTotals();
        }

        function onPoItemChange(sel) {
            const tr = sel.closest('tr');
            const opt = sel.options[sel.selectedIndex];
            const cost = parseFloat(opt.getAttribute('data-cost')) || 0;
            tr.querySelector('.po-cost').value = cost.toFixed(2);
            calcPoTotals();
        }

        function calcPoTotals() {
            let grand = 0;
            const items = [];
            document.querySelectorAll('#po-lines-body tr').forEach(tr => {
                const sel = tr.querySelector('.po-item-select');
                if (!sel) return;
                const itemId = parseInt(sel.value) || 0;
                const qty = parseFloat(tr.querySelector('.po-qty')?.value) || 0;
                const cost = parseFloat(tr.querySelector('.po-cost')?.value) || 0;
                const opt = sel.options[sel.selectedIndex];
                const unit = opt?.getAttribute('data-unit') || 'units';
                const subtotal = round2(qty * cost);

                tr.querySelector('.po-subtotal').textContent = '₱' + subtotal.toFixed(2);
                grand += subtotal;

                if (itemId > 0 && qty > 0) {
                    items.push({ item_id: itemId, quantity: qty, unit: unit, unit_price: cost });
                }
            });

            document.getElementById('grand-total-display').textContent = '₱' + grand.toFixed(2);
            document.getElementById('po-items-json').value = JSON.stringify(items);
        }

        function autoFillLowStock() {
            const tbody = document.getElementById('po-lines-body');
            tbody.innerHTML = '';
            let added = 0;

            stockCatalog.forEach(it => {
                if (parseFloat(it.quantity) <= parseFloat(it.reorder_point)) {
                    const needed = Math.max(1, parseFloat(it.reorder_point) * 2 - parseFloat(it.quantity));
                    addPoRow(it.id, Math.ceil(needed));
                    added++;
                }
            });

            if (added === 0) {
                alert('All items are currently above their reorder points! Adding one empty line.');
                addPoRow();
            }
        }

        function round2(num) {
            return Math.round((num + Number.EPSILON) * 100) / 100;
        }
    </script>
</body>
</html>
