<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/authguard.php';
requireLogin();

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$message = '';
$messageType = '';

// Handle AJAX Image Upload
if (isset($_FILES['invoice_image']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    header('Content-Type: application/json');
    $file = $_FILES['invoice_image'];
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    if (!in_array($file['type'], $allowed)) {
        echo json_encode(['success' => false, 'error' => 'Unsupported image format. Upload JPG, PNG, or WEBP.']);
        exit;
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'inv_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetDir = dirname(__DIR__) . '/uploads/invoices/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    $targetPath = $targetDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        echo json_encode([
            'success' => true,
            'image_path' => 'uploads/invoices/' . $filename,
            'filename' => $filename
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to save uploaded image.']);
    }
    exit;
}

// Handle Save Draft or Confirm Delivery
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    validateCsrfToken();
    $action = $_POST['action'];

    if ($action === 'save_draft' || $action === 'confirm_delivery') {
        $draft_id = (int)($_POST['draft_id'] ?? 0);
        $supplier_id = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
        $supplier_name_raw = trim($_POST['supplier_name_raw'] ?? '');
        $invoice_number = trim($_POST['invoice_number'] ?? '');
        $invoice_date = !empty($_POST['invoice_date']) ? $_POST['invoice_date'] : date('Y-m-d');
        $image_path = trim($_POST['image_path'] ?? '');
        $itemsJson = $_POST['items_json'] ?? '[]';
        $items = json_decode($itemsJson, true) ?: [];

        try {
            $pdo->beginTransaction();

            if ($draft_id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE invoice_drafts 
                    SET supplier_id = ?, supplier_name_raw = ?, invoice_number = ?, invoice_date = ?, image_path = ?, extracted_data_json = ?
                    WHERE id = ?
                ");
                $stmt->execute([$supplier_id, $supplier_name_raw, $invoice_number, $invoice_date, $image_path, $itemsJson, $draft_id]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO invoice_drafts (supplier_id, supplier_name_raw, invoice_number, invoice_date, image_path, status, extracted_data_json, created_by)
                    VALUES (?, ?, ?, ?, ?, 'draft_pending_review', ?, ?)
                ");
                $stmt->execute([$supplier_id, $supplier_name_raw, $invoice_number, $invoice_date, $image_path, $itemsJson, $username]);
                $draft_id = (int)$pdo->lastInsertId();
            }

            if ($action === 'confirm_delivery') {
                if (empty($items)) {
                    throw new Exception("Cannot confirm delivery with zero items.");
                }

                $selectItemStmt = $pdo->prepare("SELECT id, name, quantity, unit, unit_cost FROM stock_items WHERE id = ? FOR UPDATE");
                $updateStockStmt = $pdo->prepare("UPDATE stock_items SET quantity = ? WHERE id = ?");
                $auditStmt = $pdo->prepare("
                    INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
                    VALUES (?, ?, ?, 'delivery', ?, ?)
                ");
                $mappingStmt = $pdo->prepare("
                    INSERT INTO supplier_mappings (supplier_id, internal_item_id, supplier_item_id, supplier_item_name)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE internal_item_id = VALUES(internal_item_id), supplier_item_name = VALUES(supplier_item_name)
                ");

                foreach ($items as $entry) {
                    $internal_id = (int)($entry['internal_item_id'] ?? 0);
                    $qtyReceived = round((float)($entry['quantity'] ?? 0), 2);
                    $rawDesc = trim($entry['description'] ?? '');
                    $saveMapping = !empty($entry['save_mapping']) && $supplier_id && $rawDesc !== '';

                    if ($internal_id <= 0 || $qtyReceived <= 0) {
                        continue;
                    }

                    $selectItemStmt->execute([$internal_id]);
                    $currItem = $selectItemStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$currItem) {
                        continue;
                    }

                    $prevQty = (float)$currItem['quantity'];
                    $newQty = round($prevQty + $qtyReceived, 2);

                    $updateStockStmt->execute([$newQty, $internal_id]);

                    $auditReason = "Delivery received via invoice #{$invoice_number}";
                    $auditStmt->execute([$internal_id, $prevQty, $newQty, $auditReason, $username]);

                    if ($saveMapping) {
                        $supplierItemId = trim($entry['supplier_item_id'] ?? $rawDesc);
                        $mappingStmt->execute([$supplier_id, $internal_id, $supplierItemId, $rawDesc]);
                    }
                }

                $confirmStmt = $pdo->prepare("
                    UPDATE invoice_drafts 
                    SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW() 
                    WHERE id = ?
                ");
                $confirmStmt->execute([$username, $draft_id]);

                $pdo->commit();
                $message = "Delivery confirmed! Stock levels updated and audit trail logged successfully.";
                $messageType = 'success';
            } else {
                $pdo->commit();
                $message = "Invoice draft saved as pending review. You can return and confirm it later.";
                $messageType = 'success';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "Error: " . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($action === 'discard_draft') {
        $draft_id = (int)($_POST['draft_id'] ?? 0);
        if ($draft_id > 0) {
            $stmt = $pdo->prepare("UPDATE invoice_drafts SET status = 'discarded' WHERE id = ?");
            $stmt->execute([$draft_id]);
            $message = "Draft discarded.";
            $messageType = 'success';
        }
    }
}

// Fetch active suppliers & stock items
$suppliers = $pdo->query("SELECT id, NAME, NAME AS name FROM suppliers WHERE is_active = 1 ORDER BY NAME")->fetchAll(PDO::FETCH_ASSOC);
$stockItems = $pdo->query("SELECT id, NAME, NAME AS name, category, unit, quantity, supplier_id FROM stock_items WHERE is_active = 1 ORDER BY category, NAME")->fetchAll(PDO::FETCH_ASSOC);

// Fetch mappings
$mappings = $pdo->query("
    SELECT sm.supplier_id, sm.supplier_item_id, sm.internal_item_id, si.NAME AS item_name
    FROM supplier_mappings sm
    JOIN stock_items si ON si.id = sm.internal_item_id
")->fetchAll(PDO::FETCH_ASSOC);

// Consolidated Counts for Metric Cards (Single Aggregated SELECT over cloud SSL)
$delMetrics = $pdo->query("
    SELECT 
        COUNT(CASE WHEN status = 'confirmed' THEN 1 END) AS total_confirmed,
        COUNT(CASE WHEN status = 'draft_pending_review' THEN 1 END) AS pending_review,
        COUNT(CASE WHEN status = 'confirmed' AND DATE(confirmed_at) = CURDATE() THEN 1 END) AS confirmed_today
    FROM invoice_drafts
")->fetch(PDO::FETCH_ASSOC);
$totalDeliveriesCount = (int)($delMetrics['total_confirmed'] ?? 0);
$pendingReviewCount = (int)($delMetrics['pending_review'] ?? 0);
$confirmedTodayCount = (int)($delMetrics['confirmed_today'] ?? 0);

// Fetch all deliveries for directory table (Canva Slide 5)
$allDeliveries = $pdo->query("
    SELECT d.*, s.NAME AS supplier_name
    FROM invoice_drafts d
    LEFT JOIN suppliers s ON s.id = d.supplier_id
    WHERE d.status != 'discarded'
    ORDER BY d.created_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

$activePage = 'deliveries';
$pageTitle = 'Deliveries & Intake - Asterisko Inventory';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/../includes/head.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
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

            <!-- Title & Top Action (Canva Slide 5 Spec) -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">Deliveries</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">View deliveries, scan supplier invoices, and confirm received stock.</p>
                </div>
                <button onclick="scrollToScanner()" class="btn-primary inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold shadow-md">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span>+ Add Delivery</span>
                </button>
            </div>

            <!-- Messages Feedback -->
            <?php if ($message): ?>
                <div class="p-4 rounded-xl text-sm font-medium flex items-center gap-2 <?= $messageType === 'success' ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-200 border border-emerald-300 dark:border-emerald-800' : 'bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-200 border border-rose-300 dark:border-rose-800' ?>">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $messageType === 'success' ? 'M5 13l4 4L19 7' : 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z' ?>"/></svg>
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
            <?php endif; ?>

            <!-- 3 Metric Cards (Exact Canva Slide 5 Spec) -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6">
                <!-- Card 1: Total Deliveries -->
                <div class="stat-card p-5" style="--stat-accent: #0C2340;">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Total Deliveries</span>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight"><?= sprintf('%02d', $totalDeliveriesCount) ?></span>
                        <p class="text-xs text-slate-400 mt-1">Confirmed deliveries this month</p>
                    </div>
                </div>

                <!-- Card 2: Pending Review -->
                <div class="stat-card p-5" style="--stat-accent: #F28C28;">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Pending Review</span>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-[#F28C28] tracking-tight"><?= sprintf('%02d', $pendingReviewCount) ?></span>
                        <p class="text-xs text-[#F28C28] font-semibold mt-1">Needs confirmation</p>
                    </div>
                </div>

                <!-- Card 3: Confirmed Today -->
                <div class="stat-card p-5" style="--stat-accent: #10B981;">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Confirmed Today</span>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-emerald-600 dark:text-emerald-400 tracking-tight"><?= sprintf('%02d', $confirmedTodayCount) ?></span>
                        <p class="text-xs text-emerald-600 dark:text-emerald-400 font-semibold mt-1">Stock updated</p>
                    </div>
                </div>
            </div>

            <!-- Recent Deliveries Directory Table (Canva Slide 5 Spec) -->
            <div class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] overflow-hidden">
                <div class="p-5 border-b border-slate-200 dark:border-[#15345C] flex flex-col sm:flex-row justify-between items-center gap-3">
                    <div>
                        <h2 class="font-bold text-base text-slate-900 dark:text-white">Recent Deliveries</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">All intake records and invoice scans</p>
                    </div>
                    <div class="flex items-center gap-2 w-full sm:w-auto">
                        <input type="text" id="delivery-search" placeholder="Search supplier or invoice..." 
                               class="text-xs p-2 rounded-xl border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] w-full sm:w-56 focus:outline-none focus:border-[#F28C28]">
                        <button class="btn-secondary px-4 py-2 rounded-xl text-xs font-bold shadow-sm whitespace-nowrap">Filter</button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left" id="deliveries-table">
                        <thead>
                            <tr>
                                <th>Delivery ID</th>
                                <th>Supplier</th>
                                <th>Date</th>
                                <th>Items</th>
                                <th>Status</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($allDeliveries) === 0): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-8 text-slate-400">No delivery intake records found yet. Scan an invoice below to start.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($allDeliveries as $del): 
                                    $itemCount = count(json_decode($del['extracted_data_json'] ?? '[]', true) ?: []);
                                    $isPending = ($del['status'] === 'draft_pending_review');
                                ?>
                                <tr>
                                    <td class="font-mono font-bold text-slate-800 dark:text-slate-200">
                                        DEL-<?= sprintf('%04d', $del['id']) ?>
                                    </td>
                                    <td class="font-semibold text-slate-900 dark:text-white">
                                        <?= htmlspecialchars($del['supplier_name'] ?? $del['supplier_name_raw'] ?? 'Unknown Supplier') ?>
                                        <?php if (!empty($del['invoice_number'])): ?>
                                            <span class="block text-[10px] text-slate-400">Inv: #<?= htmlspecialchars($del['invoice_number']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-slate-500 whitespace-nowrap">
                                        <?= htmlspecialchars($del['invoice_date'] ?? substr($del['created_at'], 0, 10)) ?>
                                    </td>
                                    <td class="font-mono font-bold">
                                        <?= $itemCount ?> <?= $itemCount === 1 ? 'item' : 'items' ?>
                                    </td>
                                    <td>
                                        <?php if ($isPending): ?>
                                            <span class="badge-pending">Pending</span>
                                        <?php else: ?>
                                            <span class="badge-confirmed">Confirmed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <?php if ($isPending): ?>
                                            <button type="button" onclick='loadDraftForReview(<?= json_encode($del) ?>)' class="text-[#F28C28] hover:text-[#E07B18] font-bold text-xs inline-flex items-center gap-1">
                                                Review <span aria-hidden="true">&rarr;</span>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" onclick='loadDraftForReview(<?= json_encode($del) ?>)' class="text-slate-500 hover:text-slate-800 dark:hover:text-white font-semibold text-xs inline-flex items-center gap-1">
                                                View <span aria-hidden="true">&rarr;</span>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- OCR Invoice Intake Scanner Workbench -->
            <div id="scanner-section" class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] p-6 space-y-6">
                <div class="border-b border-slate-200 dark:border-[#15345C] pb-4 flex flex-col sm:flex-row justify-between sm:items-center gap-2">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <svg class="w-5 h-5 text-[#F28C28]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <span>Intake & Invoice Scanner (OCR)</span>
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Upload paper invoices or receipts for client-side OCR parsing, supplier matching, and one-click stock verification.</p>
                    </div>
                    <span class="text-xs bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 px-3 py-1 rounded-full font-semibold">Tesseract.js Engine Active</span>
                </div>

                <!-- Upload Drag-Drop Area -->
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                    <div class="lg:col-span-5 space-y-4">
                        <div class="border-2 border-dashed border-slate-300 dark:border-[#1C3E69] rounded-2xl p-6 text-center hover:border-[#F28C28] transition-colors cursor-pointer bg-slate-50 dark:bg-[#07172C]" onclick="document.getElementById('file-input').click()">
                            <input type="file" id="file-input" accept="image/*" class="hidden">
                            <div class="w-12 h-12 rounded-2xl bg-[#F28C28]/15 text-[#F28C28] flex items-center justify-center mx-auto mb-3">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            </div>
                            <span class="font-bold text-sm text-slate-800 dark:text-white block">Click to upload invoice image</span>
                            <span class="text-xs text-slate-400 mt-1 block">Supports JPG, PNG, WEBP</span>
                        </div>

                        <!-- Preview & OCR Progress -->
                        <div id="preview-box" class="hidden space-y-3">
                            <img id="image-preview" src="" alt="Invoice Preview" class="w-full max-h-56 object-contain rounded-xl border border-slate-200 dark:border-[#173863] bg-black/5">
                            <div id="ocr-progress-box" class="hidden space-y-1.5">
                                <div class="flex justify-between text-xs text-slate-500 font-semibold">
                                    <span id="ocr-status-text">Processing text...</span>
                                    <span id="ocr-pct">0%</span>
                                </div>
                                <div class="w-full h-2 rounded-full bg-slate-200 dark:bg-[#132E52] overflow-hidden">
                                    <div id="ocr-bar" class="h-full bg-[#F28C28] transition-all duration-200" style="width: 0%"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Intake Review Form -->
                    <div class="lg:col-span-7">
                        <form method="POST" id="intake-form" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                            <input type="hidden" name="draft_id" id="form-draft-id" value="">
                            <input type="hidden" name="image_path" id="form-image-path" value="">
                            <input type="hidden" name="items_json" id="form-items-json" value="[]">

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Supplier</label>
                                    <select name="supplier_id" id="form-supplier" class="w-full">
                                        <option value="">Select or Detect</option>
                                        <?php foreach ($suppliers as $s): ?>
                                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['NAME'] ?? $s['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Invoice / Ref #</label>
                                    <input type="text" name="invoice_number" id="form-invoice-num" placeholder="e.g. INV-10492" class="w-full">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Delivery Date</label>
                                    <input type="date" name="invoice_date" id="form-date" value="<?= date('Y-m-d') ?>" class="w-full">
                                </div>
                            </div>

                            <!-- Extracted Items Review Table -->
                            <div class="rounded-xl border border-slate-200 dark:border-[#173863] overflow-hidden">
                                <div class="p-3 bg-slate-50 dark:bg-[#07172C] border-b border-slate-200 dark:border-[#173863] flex justify-between items-center">
                                    <span class="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300">Scanned Delivery Items</span>
                                    <button type="button" onclick="addManualRow()" class="text-xs font-bold text-[#F28C28] hover:text-[#E07B18]">+ Add Row</button>
                                </div>
                                <div class="overflow-x-auto max-h-56">
                                    <table class="w-full text-xs text-left" id="ocr-items-table">
                                        <thead>
                                            <tr>
                                                <th>Detected Description</th>
                                                <th>Mapped Stock Item</th>
                                                <th>Qty</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody id="ocr-table-body">
                                            <tr id="empty-row">
                                                <td colspan="4" class="text-center py-6 text-slate-400">Upload an invoice to auto-extract items, or click "+ Add Row".</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="flex items-center justify-end gap-3 pt-2">
                                <button type="submit" name="action" value="save_draft" class="btn-secondary px-5 py-2.5 rounded-xl text-xs font-bold shadow-sm">
                                    Save as Draft
                                </button>
                                <button type="submit" name="action" value="confirm_delivery" class="btn-primary px-6 py-2.5 rounded-xl text-xs font-bold shadow-md">
                                    Confirm Delivery & Update Stock
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <!-- OCR Engine and Client Scripts -->
    <script>
        const catalogItems = <?= json_encode($stockItems) ?>;
        const knownMappings = <?= json_encode($mappings) ?>;

        function scrollToScanner() {
            document.getElementById('scanner-section').scrollIntoView({ behavior: 'smooth' });
        }

        document.getElementById('file-input').addEventListener('change', async function(e) {
            const file = e.target.files[0];
            if (!file) return;

            // Preview image
            const preview = document.getElementById('image-preview');
            preview.src = URL.createObjectURL(file);
            document.getElementById('preview-box').classList.remove('hidden');

            // Upload via AJAX
            const fd = new FormData();
            fd.append('invoice_image', file);
            fd.append('csrf_token', '<?= htmlspecialchars(getCsrfToken()) ?>');

            try {
                const res = await fetch('delivery_intake.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    document.getElementById('form-image-path').value = data.image_path;
                }
            } catch (err) {
                console.error('Upload error:', err);
            }

            // Run Tesseract.js in-browser
            runOcr(file);
        });

        async function runOcr(imageSource) {
            const progressBox = document.getElementById('ocr-progress-box');
            const pText = document.getElementById('ocr-status-text');
            const pBar = document.getElementById('ocr-bar');
            const pPct = document.getElementById('ocr-pct');

            progressBox.classList.remove('hidden');
            pText.textContent = 'Initializing OCR engine...';

            try {
                const worker = await Tesseract.createWorker('eng');
                pText.textContent = 'Scanning document lines...';

                const ret = await worker.recognize(imageSource);
                const text = ret.data.text;
                await worker.terminate();

                progressBox.classList.add('hidden');
                parseOcrText(text);
            } catch (err) {
                pText.textContent = 'OCR Error: ' + err.message;
            }
        }

        function parseOcrText(text) {
            const lines = text.split('\n').map(l => l.trim()).filter(l => l.length > 2);
            const detected = [];

            lines.forEach(line => {
                const qtyMatch = line.match(/(\d+(?:\.\d+)?)\s*(kg|g|l|ml|pcs|boxes|packs|unit)?/i);
                if (qtyMatch) {
                    const qty = parseFloat(qtyMatch[1]);
                    const cleanDesc = line.replace(qtyMatch[0], '').replace(/[^\w\s]/gi, ' ').trim();
                    if (cleanDesc.length >= 3 && qty > 0) {
                        detected.push({ desc: cleanDesc, qty: qty });
                    }
                }
            });

            if (detected.length > 0) {
                renderOcrItems(detected);
            } else {
                addManualRow();
            }
        }

        function renderOcrItems(items) {
            const tbody = document.getElementById('ocr-table-body');
            tbody.innerHTML = '';

            items.forEach((it, idx) => {
                const tr = document.createElement('tr');
                const matchedInternal = findCatalogMatch(it.desc);

                tr.innerHTML = `
                    <td><input type="text" class="w-full text-xs p-1 rounded bg-slate-50 dark:bg-[#07172C] item-desc" value="${escapeHtml(it.desc)}"></td>
                    <td>
                        <select class="w-full text-xs p-1 rounded bg-slate-50 dark:bg-[#07172C] item-select">
                            <option value="0">-- Select Catalog Item --</option>
                            ${catalogItems.map(c => `<option value="${c.id}" ${matchedInternal === c.id ? 'selected' : ''}>${escapeHtml(c.NAME || c.name)} (${c.unit})</option>`).join('')}
                        </select>
                    </td>
                    <td><input type="number" step="0.01" class="w-20 text-xs p-1 rounded bg-slate-50 dark:bg-[#07172C] item-qty" value="${it.qty}"></td>
                    <td><button type="button" onclick="this.closest('tr').remove(); syncJson();" class="text-rose-500 font-bold">&times;</button></td>
                `;
                tbody.appendChild(tr);
            });
            syncJson();
        }

        function findCatalogMatch(desc) {
            const low = desc.toLowerCase();
            for (const map of knownMappings) {
                if (low.includes(map.supplier_item_id.toLowerCase()) || map.supplier_item_id.toLowerCase().includes(low)) {
                    return map.internal_item_id;
                }
            }
            for (const cat of catalogItems) {
                const catName = (cat.NAME || cat.name).toLowerCase();
                if (low.includes(catName) || catName.includes(low)) {
                    return cat.id;
                }
            }
            return 0;
        }

        function addManualRow() {
            const tbody = document.getElementById('ocr-table-body');
            const empty = document.getElementById('empty-row');
            if (empty) empty.remove();

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><input type="text" class="w-full text-xs p-1 rounded bg-slate-50 dark:bg-[#07172C] item-desc" placeholder="Description"></td>
                <td>
                    <select class="w-full text-xs p-1 rounded bg-slate-50 dark:bg-[#07172C] item-select">
                        <option value="0">-- Select Catalog Item --</option>
                        ${catalogItems.map(c => `<option value="${c.id}">${escapeHtml(c.NAME || c.name)} (${c.unit})</option>`).join('')}
                    </select>
                </td>
                <td><input type="number" step="0.01" class="w-20 text-xs p-1 rounded bg-slate-50 dark:bg-[#07172C] item-qty" value="1"></td>
                <td><button type="button" onclick="this.closest('tr').remove(); syncJson();" class="text-rose-500 font-bold">&times;</button></td>
            `;
            tbody.appendChild(tr);
            syncJson();
        }

        function syncJson() {
            const rows = document.querySelectorAll('#ocr-table-body tr');
            const out = [];
            rows.forEach(r => {
                const desc = r.querySelector('.item-desc')?.value.trim();
                const sel = r.querySelector('.item-select')?.value;
                const qty = parseFloat(r.querySelector('.item-qty')?.value);
                if (desc && qty) {
                    out.push({
                        description: desc,
                        internal_item_id: parseInt(sel) || 0,
                        quantity: qty,
                        save_mapping: true
                    });
                }
            });
            document.getElementById('form-items-json').value = JSON.stringify(out);
        }

        document.getElementById('intake-form').addEventListener('submit', function() {
            syncJson();
        });

        function loadDraftForReview(del) {
            document.getElementById('form-draft-id').value = del.id;
            document.getElementById('form-supplier').value = del.supplier_id || '';
            document.getElementById('form-invoice-num').value = del.invoice_number || '';
            document.getElementById('form-date').value = del.invoice_date || '';
            document.getElementById('form-image-path').value = del.image_path || '';

            if (del.image_path) {
                const preview = document.getElementById('image-preview');
                preview.src = '../' + del.image_path;
                document.getElementById('preview-box').classList.remove('hidden');
            }

            try {
                const parsed = JSON.parse(del.extracted_data_json || '[]');
                renderOcrItems(parsed.map(p => ({ desc: p.description, qty: p.quantity })));
            } catch (e) {}

            scrollToScanner();
        }

        function escapeHtml(str) {
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
    </script>
</body>
</html>
