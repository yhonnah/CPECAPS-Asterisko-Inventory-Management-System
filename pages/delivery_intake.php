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

            // 1. Create or update invoice_drafts record
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

            // 2. If action is CONFIRM DELIVERY
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
                        continue; // Skip invalid or unmatched rows
                    }

                    // Lock and get stock
                    $selectItemStmt->execute([$internal_id]);
                    $stockItem = $selectItemStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$stockItem) continue;

                    $prevQty = (float)$stockItem['quantity'];
                    $newQty = round($prevQty + $qtyReceived, 2);

                    // Update stock
                    $updateStockStmt->execute([$newQty, $internal_id]);

                    // Audit trail entry
                    $origVal = $entry['original_extracted_qty'] ?? $qtyReceived;
                    $reason = "OCR Intake Delivery (+{$qtyReceived} {$stockItem['unit']}) [Inv #{$invoice_number}]";
                    if ($origVal != $qtyReceived) {
                        $reason .= " (Corrected from OCR: {$origVal})";
                    }
                    $auditStmt->execute([$internal_id, $prevQty, $newQty, $reason, $username]);

                    // Save new supplier mapping if requested
                    if ($saveMapping) {
                        $mappingStmt->execute([$supplier_id, $internal_id, $rawDesc, $rawDesc]);
                    }
                }

                // Mark draft as confirmed
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

// Fetch active suppliers
$suppliers = $pdo->query("SELECT id, NAME, NAME AS name FROM suppliers WHERE is_active = 1 ORDER BY NAME")->fetchAll(PDO::FETCH_ASSOC);

// Fetch active stock items
$stockItems = $pdo->query("SELECT id, NAME, NAME AS name, category, unit, quantity, supplier_id FROM stock_items WHERE is_active = 1 ORDER BY category, NAME")->fetchAll(PDO::FETCH_ASSOC);

// Fetch existing supplier mappings for auto-matching
$mappings = $pdo->query("
    SELECT sm.supplier_id, sm.supplier_item_id, sm.internal_item_id, si.NAME AS item_name
    FROM supplier_mappings sm
    JOIN stock_items si ON si.id = sm.internal_item_id
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch pending drafts
$pendingDrafts = $pdo->query("
    SELECT d.*, s.name AS supplier_name
    FROM invoice_drafts d
    LEFT JOIN suppliers s ON s.id = d.supplier_id
    WHERE d.status = 'draft_pending_review'
    ORDER BY d.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent confirmed deliveries
$recentConfirmed = $pdo->query("
    SELECT d.*, s.name AS supplier_name
    FROM invoice_drafts d
    LEFT JOIN suppliers s ON s.id = d.supplier_id
    WHERE d.status = 'confirmed'
    ORDER BY d.confirmed_at DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Intake & Invoice OCR - Asterisko</title>
    <!-- Tesseract.js (Pure Client-Side In-Browser OCR) -->
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    <script>
        try {
            const saved = localStorage.getItem('theme');
            if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {}
    </script>

<link rel="stylesheet" href="../assets/app-enhancements.css">
</head>
<body class="bg-stone-100 dark:bg-stone-950 text-stone-800 dark:text-stone-200 min-h-screen">

    <!-- Top Navigation -->
    <nav class="bg-stone-900 dark:bg-black text-white px-4 py-3 flex justify-between items-center border-b border-stone-800">
        <div class="flex items-center gap-3">
            <a href="dashboard.php" class="text-emerald-400 hover:text-emerald-300 text-sm font-semibold flex items-center gap-1">
                &larr; Dashboard
            </a>
            <span class="text-stone-600">|</span>
            <span class="font-bold text-base sm:text-lg">Delivery Intake & OCR</span>
            <span class="text-xs bg-emerald-600 px-2 py-0.5 rounded uppercase font-semibold"><?= htmlspecialchars($role) ?></span>
        </div>
        <div class="flex items-center gap-3 text-sm">
            <span>Staff: <b><?= htmlspecialchars($username) ?></b></span>
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

        <!-- Step 1: Image Capture & Upload Section -->
        <div class="bg-white dark:bg-stone-900 rounded-xl shadow p-5 border border-stone-200 dark:border-stone-800">
            <h2 class="text-base font-bold text-stone-900 dark:text-stone-100 mb-1 flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><circle cx="12" cy="13" r="4"/></svg>
                1. Capture or Upload Delivery Receipt / Invoice
            </h2>
            <p class="text-xs text-stone-500 mb-4">Take a photo with your phone camera or upload a scan. Client-side OCR will extract line items without modifying inventory.</p>

            <div class="flex flex-col sm:flex-row items-center gap-4">
                <label class="cursor-pointer bg-stone-100 hover:bg-stone-200 dark:bg-stone-800 dark:hover:bg-stone-700 border-2 border-dashed border-stone-300 dark:border-stone-600 rounded-xl p-4 w-full sm:w-auto flex-1 text-center transition">
                    <input type="file" id="invoice-file-input" accept="image/*" capture="environment" class="hidden">
                    <span class="block text-sm font-semibold text-stone-800 dark:text-stone-200">📸 Take Photo or Choose File</span>
                    <span class="block text-[11px] text-stone-500 mt-1">Supports JPG, PNG, WEBP</span>
                </label>

                <!-- Processing & OCR Status Indicator -->
                <div id="ocr-status-box" class="hidden flex-1 w-full bg-stone-50 dark:bg-stone-800 p-3.5 rounded-xl border border-stone-200 dark:border-stone-700">
                    <div class="flex justify-between items-center text-xs mb-1">
                        <span id="ocr-status-text" class="font-semibold text-emerald-600">Processing OCR...</span>
                        <span id="ocr-percent-text" class="font-mono">0%</span>
                    </div>
                    <div class="w-full bg-stone-200 dark:bg-stone-700 rounded-full h-2 overflow-hidden">
                        <div id="ocr-progress-bar" class="bg-emerald-600 h-2 transition-all duration-200" style="width: 0%"></div>
                    </div>
                </div>
            </div>

            <!-- Image Preview Box -->
            <div id="image-preview-container" class="hidden mt-4 pt-4 border-t border-stone-200 dark:border-stone-800 flex flex-col sm:flex-row items-start gap-4">
                <img id="image-preview" src="" alt="Invoice Preview" class="max-h-48 rounded-lg shadow border border-stone-300 dark:border-stone-700 object-contain bg-stone-50">
                <div class="text-xs space-y-1">
                    <span class="font-bold text-stone-700 dark:text-stone-300">Invoice Photo Loaded</span>
                    <p class="text-stone-500">If text extraction is incomplete, the image is preserved so you can edit or add line items manually below (Scenario 2).</p>
                    <button type="button" onclick="runOCR(currentImageBlob)" class="mt-2 text-emerald-600 hover:underline font-semibold flex items-center gap-1">
                        &#x21bb; Re-run OCR Recognition
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 2: Extracted Draft Entry & Staff Confirmation Form -->
        <div id="draft-section" class="bg-white dark:bg-stone-900 rounded-xl shadow p-5 border border-stone-200 dark:border-stone-800">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-stone-200 dark:border-stone-800 gap-2 mb-4">
                <div>
                    <h2 class="text-base font-bold text-stone-900 dark:text-stone-100 flex items-center gap-2">
                        <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                        2. Review Draft & Confirm Delivery
                    </h2>
                    <p class="text-xs text-stone-500">No stock levels change until explicitly confirmed by staff. Low-confidence fields are flagged for review.</p>
                </div>
                <button type="button" onclick="addBlankItemRow()" class="bg-stone-100 hover:bg-stone-200 dark:bg-stone-800 dark:hover:bg-stone-700 text-stone-800 dark:text-stone-200 text-xs font-semibold px-3 py-1.5 rounded-lg border border-stone-300 dark:border-stone-700">
                    + Add Line Item Manually
                </button>
            </div>

            <form id="delivery-form" method="POST" class="space-y-4 text-xs">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="action" id="form-action" value="confirm_delivery">
                <input type="hidden" name="draft_id" id="draft_id" value="0">
                <input type="hidden" name="image_path" id="form_image_path" value="">
                <input type="hidden" name="items_json" id="items_json" value="[]">

                <!-- Header Details -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-semibold mb-1">Detected / Matched Supplier</label>
                        <select name="supplier_id" id="supplier_id" class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-medium">
                            <option value="">-- Select Registered Supplier --</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['NAME'] ?? $s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="supplier_name_raw" id="supplier_name_raw" value="">
                    </div>
                    <div>
                        <label class="block font-semibold mb-1">Invoice / Receipt #</label>
                        <input type="text" name="invoice_number" id="invoice_number" placeholder="e.g., INV-9842"
                               class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-mono">
                    </div>
                    <div>
                        <label class="block font-semibold mb-1">Document Date</label>
                        <input type="date" name="invoice_date" id="invoice_date" value="<?= date('Y-m-d') ?>"
                               class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100">
                    </div>
                </div>

                <!-- Line Items Table -->
                <div class="overflow-x-auto border border-stone-200 dark:border-stone-800 rounded-xl">
                    <table class="w-full text-left" id="line-items-table">
                        <thead class="bg-stone-50 dark:bg-stone-800 uppercase font-semibold text-stone-600 dark:text-stone-400 border-b border-stone-200 dark:border-stone-700 text-[11px]">
                            <tr>
                                <th class="p-2.5">Supplier Wording (Raw)</th>
                                <th class="p-2.5">Matched Internal Stock Item</th>
                                <th class="p-2.5 w-24">Qty Received</th>
                                <th class="p-2.5 w-20">Unit</th>
                                <th class="p-2.5 w-24">Price (₱)</th>
                                <th class="p-2.5 w-28">Confidence</th>
                                <th class="p-2.5 w-12 text-center">Del</th>
                            </tr>
                        </thead>
                        <tbody id="line-items-tbody" class="divide-y divide-stone-200 dark:divide-stone-800">
                            <!-- Populated dynamically via OCR or manual rows -->
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col sm:flex-row justify-between items-center gap-3 pt-2">
                    <div class="text-[11px] text-stone-500">
                        Fields with <span class="bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 px-1.5 py-0.5 rounded font-semibold">Review Needed</span> were detected with &lt;75% confidence.
                    </div>
                    <div class="flex gap-2 w-full sm:w-auto">
                        <button type="button" onclick="submitForm('save_draft')" class="w-1/2 sm:w-auto px-4 py-2 border border-stone-300 dark:border-stone-700 rounded-lg text-stone-700 dark:text-stone-300 hover:bg-stone-100 dark:hover:bg-stone-800 font-semibold">
                            Save as Draft
                        </button>
                        <button type="button" onclick="submitForm('confirm_delivery')" class="w-1/2 sm:w-auto px-6 py-2.5 bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg font-bold shadow flex items-center justify-center gap-2">
                            <span>Confirm & Add Stock</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Pending Drafts -->
        <?php if (!empty($pendingDrafts)): ?>
            <div class="bg-white dark:bg-stone-900 rounded-xl shadow p-5 border border-stone-200 dark:border-stone-800">
                <h3 class="text-sm font-bold text-stone-900 dark:text-stone-100 mb-1">Unconfirmed Draft Invoices</h3>
                <p class="text-xs text-stone-500 mb-3">Saved drafts awaiting review or handover from previous shifts.</p>
                <div class="divide-y divide-stone-200 dark:divide-stone-800 text-xs">
                    <?php foreach ($pendingDrafts as $d): ?>
                        <div class="py-2.5 flex justify-between items-center">
                            <div>
                                <span class="font-bold text-stone-900 dark:text-stone-100">Inv #<?= htmlspecialchars($d['invoice_number'] ?: 'Draft #' . $d['id']) ?></span>
                                <span class="text-stone-500 ml-2">(<?= htmlspecialchars($d['supplier_name'] ?? $d['supplier_name_raw'] ?? 'Unknown Supplier') ?>)</span>
                                <span class="block text-[11px] text-stone-400">Created by <?= htmlspecialchars($d['created_by']) ?> on <?= date('M d, Y h:i A', strtotime($d['created_at'])) ?></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" onclick='loadDraft(<?= json_encode($d) ?>)' class="px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-xs font-semibold">
                                    Load & Review
                                </button>
                                <form method="POST" class="inline" onsubmit="return confirm('Discard this draft?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="discard_draft">
                                    <input type="hidden" name="draft_id" value="<?= $d['id'] ?>">
                                    <button type="submit" class="px-2 py-1 text-rose-600 hover:underline text-xs">Discard</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- Pass backend catalogs into JavaScript -->
    <script>
        const STOCK_ITEMS = <?= json_encode($stockItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const SUPPLIER_MAPPINGS = <?= json_encode($mappings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const CSRF_TOKEN = <?= json_encode(getCsrfToken()) ?>;
        let currentImageBlob = null;
    </script>

    <!-- Client-Side OCR, Mapping & UI Handler -->
    <script>
        const fileInput = document.getElementById('invoice-file-input');
        const ocrStatusBox = document.getElementById('ocr-status-box');
        const ocrStatusText = document.getElementById('ocr-status-text');
        const ocrPercentText = document.getElementById('ocr-percent-text');
        const ocrProgressBar = document.getElementById('ocr-progress-bar');
        const imagePreviewContainer = document.getElementById('image-preview-container');
        const imagePreview = document.getElementById('image-preview');
        const lineItemsTbody = document.getElementById('line-items-tbody');

        fileInput.addEventListener('change', async function(e) {
            const file = e.target.files[0];
            if (!file) return;

            currentImageBlob = file;
            imagePreview.src = URL.createObjectURL(file);
            imagePreviewContainer.classList.remove('hidden');

            // 1. Upload image to server asynchronously
            const formData = new FormData();
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('invoice_image', file);
            try {
                const uploadRes = await fetch('delivery_intake.php', { method: 'POST', body: formData });
                const data = await uploadRes.json();
                if (data.success) {
                    document.getElementById('form_image_path').value = data.image_path;
                }
            } catch (err) {
                console.warn("Local upload error, continuing client OCR:", err);
            }

            // 2. Run client OCR
            runOCR(file);
        });

        async function runOCR(file) {
            ocrStatusBox.classList.remove('hidden');
            ocrStatusText.textContent = "Initializing OCR Engine...";
            ocrProgressBar.style.width = '10%';
            ocrPercentText.textContent = '10%';

            try {
                const worker = await Tesseract.createWorker('eng');
                
                ocrStatusText.textContent = "Scanning invoice line items...";
                const ret = await worker.recognize(file);
                await worker.terminate();

                ocrProgressBar.style.width = '100%';
                ocrPercentText.textContent = '100%';
                ocrStatusText.textContent = "OCR Complete!";

                setTimeout(() => ocrStatusBox.classList.add('hidden'), 1500);

                parseOCRResults(ret.data);
            } catch (err) {
                console.error("OCR recognition failed:", err);
                ocrStatusText.textContent = "OCR encountered an issue. Please complete lines manually.";
                ocrProgressBar.classList.add('bg-rose-600');
                // Scenario 2: Keep image and let user enter lines manually
                if (lineItemsTbody.children.length === 0) {
                    addBlankItemRow();
                }
            }
        }

        // Parse OCR Text into Line Items
        function parseOCRResults(data) {
            lineItemsTbody.innerHTML = '';
            const lines = data.lines || [];
            let detectedItems = 0;

            // Search for date
            const dateMatch = data.text.match(/\b(\d{4}[-/.]\d{1,2}[-/.]\d{1,2}|\d{1,2}[-/.]\d{1,2}[-/.]\d{2,4})\b/);
            if (dateMatch) {
                try {
                    const parsed = new Date(dateMatch[1]);
                    if (!isNaN(parsed)) {
                        document.getElementById('invoice_date').value = parsed.toISOString().split('T')[0];
                    }
                } catch(e) {}
            }

            // Search for invoice number
            const invMatch = data.text.match(/(?:inv(?:oice)?|receipt|dr|si)[#:\s]*([a-z0-9-]+)/i);
            if (invMatch && invMatch[1]) {
                document.getElementById('invoice_number').value = invMatch[1].trim();
            }

            // Iterate over lines
            lines.forEach(l => {
                const text = l.text.trim();
                if (text.length < 3) return;

                // Look for quantity and price patterns (e.g., "Whole Milk 12 pcs 110.00" or "Beans 5.0 kg 850")
                const numMatch = text.match(/(\d+(?:\.\d+)?)\s*(kg|l|liters|pcs|box|pack|bags|cans|bottles)?(?:\s+(?:at|@)?\s*(\d+(?:\.\d+)?))?/i);
                
                if (numMatch) {
                    const qty = parseFloat(numMatch[1]);
                    const unit = numMatch[2] || 'pcs';
                    const price = numMatch[3] ? parseFloat(numMatch[3]) : 0;
                    const desc = text.replace(numMatch[0], '').replace(/[#*|]/g, '').trim();

                    if (desc.length > 2 && qty > 0) {
                        detectedItems++;
                        const conf = Math.round(l.confidence);
                        appendItemRow({
                            description: desc,
                            quantity: qty,
                            unit: unit,
                            price: price,
                            confidence: conf
                        });
                    }
                }
            });

            // If no lines detected, add 1 blank row for manual entry (Scenario 2)
            if (detectedItems === 0) {
                addBlankItemRow();
            }
        }

        function appendItemRow(row) {
            const tr = document.createElement('tr');
            tr.className = "hover:bg-stone-50 dark:hover:bg-stone-800/50";

            // Attempt auto-mapping
            let matchedInternalId = 0;
            const normDesc = row.description.toLowerCase();
            
            // 1. Check supplier_mappings table
            const existingMap = SUPPLIER_MAPPINGS.find(m => m.supplier_item_id.toLowerCase() === normDesc);
            if (existingMap) {
                matchedInternalId = existingMap.internal_item_id;
            } else {
                // 2. Fuzzy name match against stock_items
                const nameMatch = STOCK_ITEMS.find(si => (si.NAME || si.name || '').toLowerCase().includes(normDesc) || normDesc.includes((si.NAME || si.name || '').toLowerCase()));
                if (nameMatch) {
                    matchedInternalId = nameMatch.id;
                }
            }

            const isLowConf = (row.confidence !== undefined && row.confidence < 75);

            tr.innerHTML = `
                <td class="p-2.5">
                    <input type="text" class="row-desc w-full p-1.5 border border-stone-300 dark:border-stone-700 rounded bg-white dark:bg-stone-800 font-medium"
                           value="${escapeHtml(row.description)}">
                    ${!existingMap ? `
                        <label class="inline-flex items-center gap-1 mt-1 text-[10px] text-stone-500">
                            <input type="checkbox" class="row-save-mapping text-emerald-600 rounded" checked>
                            <span>Save mapping for future invoices</span>
                        </label>
                    ` : `
                        <span class="inline-block text-[10px] text-emerald-600 font-semibold mt-1">Pre-matched via supplier mapping</span>
                    `}
                </td>
                <td class="p-2.5">
                    <select class="row-internal-id w-full p-1.5 border border-stone-300 dark:border-stone-700 rounded bg-white dark:bg-stone-800 font-semibold text-stone-800 dark:text-stone-200">
                        <option value="0">-- Select Internal Item --</option>
                        ${STOCK_ITEMS.map(si => `
                            <option value="${si.id}" ${si.id == matchedInternalId ? 'selected' : ''}>
                                ${escapeHtml(si.NAME || si.name)} (${si.quantity} ${si.unit})
                            </option>
                        `).join('')}
                    </select>
                </td>
                <td class="p-2.5">
                    <input type="number" step="0.01" class="row-qty w-full p-1.5 border border-stone-300 dark:border-stone-700 rounded bg-white dark:bg-stone-800 font-mono font-bold"
                           value="${row.quantity || 1.00}">
                    <input type="hidden" class="row-orig-qty" value="${row.quantity || 1.00}">
                </td>
                <td class="p-2.5">
                    <input type="text" class="row-unit w-full p-1.5 border border-stone-300 dark:border-stone-700 rounded bg-white dark:bg-stone-800 font-mono"
                           value="${escapeHtml(row.unit || 'units')}">
                </td>
                <td class="p-2.5">
                    <input type="number" step="0.01" class="row-price w-full p-1.5 border border-stone-300 dark:border-stone-700 rounded bg-white dark:bg-stone-800 font-mono"
                           value="${row.price || 0.00}">
                </td>
                <td class="p-2.5">
                    ${isLowConf ? `
                        <span class="bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 px-1.5 py-0.5 rounded text-[10px] font-bold block text-center">
                            Review (${row.confidence}%)
                        </span>
                    ` : `
                        <span class="bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 px-1.5 py-0.5 rounded text-[10px] font-semibold block text-center">
                            ${row.confidence ? row.confidence + '%' : 'Manual'}
                        </span>
                    `}
                </td>
                <td class="p-2.5 text-center">
                    <button type="button" onclick="this.closest('tr').remove()" class="text-rose-600 hover:text-rose-700 text-sm font-bold">&times;</button>
                </td>
            `;

            lineItemsTbody.appendChild(tr);
        }

        function addBlankItemRow() {
            appendItemRow({
                description: '',
                quantity: 1.00,
                unit: 'units',
                price: 0.00,
                confidence: null
            });
        }

        function submitForm(actionType) {
            document.getElementById('form-action').value = actionType;

            const rows = lineItemsTbody.querySelectorAll('tr');
            const items = [];

            rows.forEach(r => {
                const desc = r.querySelector('.row-desc').value.trim();
                const internalId = parseInt(r.querySelector('.row-internal-id').value, 10);
                const qty = parseFloat(r.querySelector('.row-qty').value);
                const origQty = parseFloat(r.querySelector('.row-orig-qty').value);
                const unit = r.querySelector('.row-unit').value.trim();
                const price = parseFloat(r.querySelector('.row-price').value) || 0;
                const saveMap = r.querySelector('.row-save-mapping') ? r.querySelector('.row-save-mapping').checked : false;

                if (desc !== '' || internalId > 0) {
                    items.push({
                        description: desc,
                        internal_item_id: internalId,
                        quantity: qty,
                        original_extracted_qty: origQty,
                        unit: unit,
                        price: price,
                        save_mapping: saveMap
                    });
                }
            });

            if (items.length === 0) {
                alert("Please add at least one line item.");
                return;
            }

            if (actionType === 'confirm_delivery') {
                const unmatched = items.filter(it => !it.internal_item_id || it.internal_item_id <= 0);
                if (unmatched.length > 0) {
                    alert("Please match all line items to an internal stock item before confirming delivery.");
                    return;
                }
                if (!confirm("Confirm delivery and update on-hand stock quantities? This will write to the audit trail.")) {
                    return;
                }
            }

            document.getElementById('items_json').value = JSON.stringify(items);
            document.getElementById('delivery-form').submit();
        }

        function loadDraft(draft) {
            document.getElementById('draft_id').value = draft.id;
            document.getElementById('supplier_id').value = draft.supplier_id || '';
            document.getElementById('invoice_number').value = draft.invoice_number || '';
            document.getElementById('invoice_date').value = draft.invoice_date || '';
            document.getElementById('form_image_path').value = draft.image_path || '';

            if (draft.image_path) {
                imagePreview.src = draft.image_path.startsWith('../') ? draft.image_path : '../' + draft.image_path;
                imagePreviewContainer.classList.remove('hidden');
            }

            try {
                const items = JSON.parse(draft.extracted_data_json);
                lineItemsTbody.innerHTML = '';
                items.forEach(it => appendItemRow(it));
            } catch(e) {
                console.error("Failed to parse draft JSON", e);
            }

            document.getElementById('draft-section').scrollIntoView({ behavior: 'smooth' });
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
