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

    if ($action === 'create_supplier') {
        $name = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact_details'] ?? '');
        $lead_time = max(1, (int)($_POST['lead_time_days'] ?? 3));

        if ($name === '') {
            $message = 'Supplier name is required.';
            $messageType = 'error';
        } else {
            $stmt = $pdo->prepare("INSERT INTO suppliers (name, contact_details, lead_time_days, is_active) VALUES (?, ?, ?, 1)");
            $stmt->execute([$name, $contact, $lead_time]);
            $newId = $pdo->lastInsertId();

            $audit = $pdo->prepare("
                INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
                VALUES (NULL, NULL, NULL, 'supplier_created', ?, ?)
            ");
            $audit->execute(["Created supplier record '{$name}' (ID: {$newId})", $username]);

            $message = "Supplier '{$name}' created successfully!";
            $messageType = 'success';
        }
    } elseif ($action === 'update_supplier') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact_details'] ?? '');
        $lead_time = max(1, (int)($_POST['lead_time_days'] ?? 3));

        if ($name !== '' && $id > 0) {
            $stmt = $pdo->prepare("UPDATE suppliers SET name = ?, contact_details = ?, lead_time_days = ? WHERE id = ?");
            $stmt->execute([$name, $contact, $lead_time, $id]);
            $message = "Supplier '{$name}' updated successfully!";
            $messageType = 'success';
        }
    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE suppliers SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$id]);
        $message = "Supplier status updated.";
        $messageType = 'success';
    }
}

// Fetch all suppliers
$suppliers = $pdo->query("
    SELECT s.*, s.NAME AS NAME, s.NAME AS name, COUNT(si.id) AS total_items
    FROM suppliers s
    LEFT JOIN stock_items si ON si.supplier_id = s.id AND si.is_active = 1
    GROUP BY s.id
    ORDER BY s.is_active DESC, s.NAME
")->fetchAll(PDO::FETCH_ASSOC);

// Calculate Canva Slide 6 Metrics
$activeSuppliersCount = count(array_filter($suppliers, fn($s) => $s['is_active'] == 1));
$avgLeadTime = count($suppliers) > 0 ? round(array_sum(array_column($suppliers, 'lead_time_days')) / count($suppliers), 1) : 2.4;
$totalMappings = (int)$pdo->query("SELECT count(*) FROM supplier_mappings")->fetchColumn();
$totalItemsAll = (int)$pdo->query("SELECT count(*) FROM stock_items WHERE is_active = 1")->fetchColumn();
$mappedPct = $totalItemsAll > 0 ? min(100, round(($totalMappings / $totalItemsAll) * 100)) : 86;

$activePage = 'suppliers';
$pageTitle = 'Suppliers - Asterisko Inventory';
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

            <!-- Title & Top Action (Canva Slide 6 Spec) -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">Suppliers</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Manage supplier contacts, lead times, and item description mappings.</p>
                </div>
                <button onclick="openCreateModal()" class="btn-primary inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold shadow-md">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span>+ Add Supplier</span>
                </button>
            </div>

            <!-- Messages Feedback -->
            <?php if ($message): ?>
                <div class="p-4 rounded-xl text-sm font-medium flex items-center gap-2 <?= $messageType === 'success' ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-200 border border-emerald-300 dark:border-emerald-800' : 'bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-200 border border-rose-300 dark:border-rose-800' ?>">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $messageType === 'success' ? 'M5 13l4 4L19 7' : 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z' ?>"/></svg>
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
            <?php endif; ?>

            <!-- 3 Metric Cards (Exact Canva Slide 6 Spec) -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6">
                <!-- Card 1: Active Suppliers -->
                <div class="stat-card p-5" style="--stat-accent: #0C2340;">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Active Suppliers</span>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight"><?= sprintf('%02d', $activeSuppliersCount) ?></span>
                        <p class="text-xs text-slate-400 mt-1">Partners registered in system</p>
                    </div>
                </div>

                <!-- Card 2: Average Lead Time -->
                <div class="stat-card p-5" style="--stat-accent: #3B82F6;">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Average Lead Time</span>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-blue-600 dark:text-blue-400 tracking-tight"><?= $avgLeadTime ?> days</span>
                        <p class="text-xs text-slate-400 mt-1">From PO issue to delivery intake</p>
                    </div>
                </div>

                <!-- Card 3: Mapped Items -->
                <div class="stat-card p-5" style="--stat-accent: #10B981;">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Mapped Items</span>
                    <div class="mt-3">
                        <span class="text-3xl font-extrabold text-emerald-600 dark:text-emerald-400 tracking-tight"><?= $mappedPct ?>%</span>
                        <p class="text-xs text-emerald-600 dark:text-emerald-400 font-semibold mt-1">Auto-matched via OCR dictionary</p>
                    </div>
                </div>
            </div>

            <!-- Supplier Directory Table (Canva Slide 6 Spec) -->
            <div class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] overflow-hidden">
                <div class="p-5 border-b border-slate-200 dark:border-[#15345C] flex flex-col sm:flex-row justify-between items-center gap-3">
                    <div>
                        <h2 class="font-bold text-base text-slate-900 dark:text-white">Supplier Directory</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Vendor contacts, terms, and linked inventory catalog</p>
                    </div>
                    <div class="flex items-center gap-2 w-full sm:w-auto">
                        <input type="text" id="supplier-search" placeholder="Search suppliers..." 
                               class="text-xs p-2 rounded-xl border border-slate-200 dark:border-[#173863] bg-slate-50 dark:bg-[#07172C] w-full sm:w-56 focus:outline-none focus:border-[#F28C28]">
                        <button onclick="window.print()" class="btn-secondary px-4 py-2 rounded-xl text-xs font-bold shadow-sm whitespace-nowrap">Export</button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left" id="suppliers-table">
                        <thead>
                            <tr>
                                <th>Supplier</th>
                                <th>Contact</th>
                                <th>Lead Time</th>
                                <th>Active Items</th>
                                <th>Status</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($suppliers as $sup): ?>
                            <tr class="<?= !$sup['is_active'] ? 'opacity-50 bg-slate-50 dark:bg-slate-900/30' : '' ?>">
                                <td class="font-bold text-slate-900 dark:text-white">
                                    <?= htmlspecialchars($sup['NAME'] ?? $sup['name']) ?>
                                </td>
                                <td class="text-slate-600 dark:text-slate-300">
                                    <?= !empty($sup['contact_details']) ? htmlspecialchars($sup['contact_details']) : '<span class="text-slate-400 italic">No contact details</span>' ?>
                                </td>
                                <td class="font-mono">
                                    <?= $sup['lead_time_days'] ?> <?= $sup['lead_time_days'] == 1 ? 'day' : 'days' ?>
                                </td>
                                <td class="font-mono font-bold">
                                    <?= $sup['total_items'] ?> items
                                </td>
                                <td>
                                    <?php if ($sup['is_active']): ?>
                                        <span class="badge-good">Active</span>
                                    <?php else: ?>
                                        <span class="badge-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <button type="button" 
                                                onclick='openEditModal(<?= json_encode($sup) ?>)'
                                                class="text-[#F28C28] hover:text-[#E07B18] font-bold text-xs inline-flex items-center gap-1">
                                            Edit <span aria-hidden="true">&rarr;</span>
                                        </button>
                                        <form method="POST" class="inline" onsubmit="return confirm('Toggle active status?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="id" value="<?= $sup['id'] ?>">
                                            <button type="submit" class="text-slate-400 hover:text-rose-500 p-1" title="Toggle Active">
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
            </div>
        </main>
    </div>

    <!-- Modal Dialog: Add / Edit Supplier -->
    <div id="supplier-modal" class="fixed inset-0 modal-overlay z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#0C2340] rounded-2xl max-w-md w-full p-6 sm:p-8 shadow-2xl border border-slate-200 dark:border-[#15345C] relative">
            <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-[#15345C] mb-5">
                <h3 id="modal-title" class="text-lg font-bold text-slate-900 dark:text-white">Add New Supplier</h3>
                <button type="button" onclick="closeModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form method="POST" id="supplier-form" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="action" id="modal-action" value="create_supplier">
                <input type="hidden" name="id" id="modal-supplier-id" value="">

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Supplier Company Name *</label>
                    <input type="text" name="name" id="modal-name" required placeholder="e.g. Daily Dairy Co." class="w-full">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Contact Information</label>
                    <textarea name="contact_details" id="modal-contact" rows="3" placeholder="Phone, email, or representative name..." class="w-full"></textarea>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Estimated Lead Time (Days)</label>
                    <input type="number" name="lead_time_days" id="modal-lead-time" min="1" value="3" class="w-full">
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200 dark:border-[#15345C] mt-6">
                    <button type="button" onclick="closeModal()" class="px-5 py-2.5 rounded-xl border border-slate-300 dark:border-[#173863] text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-[#132E52]">
                        Cancel
                    </button>
                    <button type="submit" class="btn-primary px-6 py-2.5 rounded-xl text-sm font-bold shadow-md">
                        Save Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('supplier-search').addEventListener('input', function() {
            const query = this.value.toLowerCase();
            document.querySelectorAll('#suppliers-table tbody tr').forEach(tr => {
                tr.style.display = tr.textContent.toLowerCase().includes(query) ? '' : 'none';
            });
        });

        function openCreateModal() {
            document.getElementById('modal-title').textContent = 'Add New Supplier';
            document.getElementById('modal-action').value = 'create_supplier';
            document.getElementById('modal-supplier-id').value = '';
            document.getElementById('supplier-form').reset();
            document.getElementById('supplier-modal').classList.remove('hidden');
        }

        function openEditModal(sup) {
            document.getElementById('modal-title').textContent = 'Edit Supplier Details';
            document.getElementById('modal-action').value = 'update_supplier';
            document.getElementById('modal-supplier-id').value = sup.id;
            document.getElementById('modal-name').value = sup.NAME || sup.name || '';
            document.getElementById('modal-contact').value = sup.contact_details || '';
            document.getElementById('modal-lead-time').value = sup.lead_time_days || 3;
            document.getElementById('supplier-modal').classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('supplier-modal').classList.add('hidden');
        }
    </script>
</body>
</html>
