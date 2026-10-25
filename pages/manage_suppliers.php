<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/authguard.php';

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

            // Audit supplier creation
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

// Fetch all suppliers with count of supplied items
$suppliers = $pdo->query("
    SELECT s.*, s.NAME AS NAME, s.NAME AS name, COUNT(si.id) AS total_items
    FROM suppliers s
    LEFT JOIN stock_items si ON si.supplier_id = s.id AND si.is_active = 1
    GROUP BY s.id
    ORDER BY s.is_active DESC, s.NAME
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Suppliers - Asterisko Inventory</title>
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

    <!-- Top Navbar -->
    <nav class="bg-stone-900 dark:bg-black text-white px-4 py-3 flex justify-between items-center border-b border-stone-800">
        <div class="flex items-center gap-3">
            <a href="dashboard.php" class="text-emerald-400 hover:text-emerald-300 text-sm font-semibold flex items-center gap-1">
                &larr; Dashboard
            </a>
            <span class="text-stone-600">|</span>
            <span class="font-bold text-base sm:text-lg">Supplier Master Data</span>
            <span class="text-xs bg-emerald-600 px-2 py-0.5 rounded uppercase font-semibold"><?= htmlspecialchars($role) ?></span>
        </div>
        <div class="flex items-center gap-3 text-sm">
            <a href="manage_items.php" class="bg-stone-700 hover:bg-stone-600 text-xs px-2.5 py-1 rounded transition">Stock Items</a>
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

        <!-- Create New Supplier Form -->
        <div class="bg-white dark:bg-stone-900 rounded-xl shadow p-5 border border-stone-200 dark:border-stone-800">
            <h2 class="text-lg font-bold text-stone-900 dark:text-stone-100 mb-3 flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                Register New Supplier
            </h2>
            <form method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="create_supplier">
                <div>
                    <label class="block font-semibold mb-1">Supplier / Vendor Name *</label>
                    <input type="text" name="name" required placeholder="e.g., Mount Apo Bean Co."
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Contact Details / Email</label>
                    <input type="text" name="contact_details" placeholder="e.g., orders@mtapobeans.ph, 0917-123-4567"
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Expected Lead Time (Days)</label>
                    <input type="number" name="lead_time_days" value="3" min="1"
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100">
                </div>
                <div class="sm:col-span-3 flex justify-end">
                    <button type="submit" class="bg-emerald-700 hover:bg-emerald-800 text-white font-semibold px-6 py-2.5 rounded-lg transition shadow">
                        Save Supplier
                    </button>
                </div>
            </form>
        </div>

        <!-- Supplier Catalog -->
        <div class="bg-white dark:bg-stone-900 rounded-xl shadow overflow-hidden border border-stone-200 dark:border-stone-800">
            <div class="p-4 border-b border-stone-200 dark:border-stone-800">
                <h2 class="text-base font-bold text-stone-900 dark:text-stone-100">Registered Suppliers Directory</h2>
                <p class="text-xs text-stone-500">Deactivation retains all historical receipts, POs, and audit trail logs.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-stone-50 dark:bg-stone-800 uppercase font-semibold text-stone-600 dark:text-stone-400 border-b border-stone-200 dark:border-stone-700">
                        <tr>
                            <th class="p-3">Supplier Name</th>
                            <th class="p-3">Contact Details</th>
                            <th class="p-3">Lead Time</th>
                            <th class="p-3">Active Items</th>
                            <th class="p-3">Status</th>
                            <th class="p-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200 dark:divide-stone-800">
                        <?php foreach ($suppliers as $s): ?>
                            <tr class="hover:bg-stone-50 dark:hover:bg-stone-800/50 <?= !$s['is_active'] ? 'opacity-50 bg-stone-100/50 dark:bg-stone-900/50' : '' ?>">
                                <td class="p-3 font-semibold text-stone-900 dark:text-stone-100">
                                    <?= htmlspecialchars($s['NAME'] ?? $s['name']) ?>
                                </td>
                                <td class="p-3 text-stone-600 dark:text-stone-400">
                                    <?= htmlspecialchars($s['contact_details'] ?? '—') ?>
                                </td>
                                <td class="p-3 font-mono">
                                    <?= (int)$s['lead_time_days'] ?> days
                                </td>
                                <td class="p-3 font-mono font-semibold">
                                    <?= (int)$s['total_items'] ?> items
                                </td>
                                <td class="p-3">
                                    <?php if ($s['is_active']): ?>
                                        <span class="bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 px-2 py-0.5 rounded text-[10px] font-bold">ACTIVE</span>
                                    <?php else: ?>
                                        <span class="bg-stone-300 dark:bg-stone-700 text-stone-700 dark:text-stone-300 px-2 py-0.5 rounded text-[10px] font-bold">DEACTIVATED</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 text-right space-x-2">
                                    <button type="button" 
                                            onclick='openEditModal(<?= json_encode($s) ?>)'
                                            class="text-emerald-600 hover:text-emerald-500 font-semibold underline text-xs">
                                        Edit
                                    </button>
                                    <form method="POST" class="inline" onsubmit="return confirm('Change active status for this supplier?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                        <button type="submit" class="text-xs font-semibold <?= $s['is_active'] ? 'text-rose-600 hover:underline' : 'text-emerald-600 hover:underline' ?>">
                                            <?= $s['is_active'] ? 'Deactivate' : 'Reactivate' ?>
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

    <!-- Edit Supplier Modal -->
    <div id="edit-modal" class="fixed inset-0 bg-black/60 hidden items-center justify-center p-4 z-50">
        <div class="bg-white dark:bg-stone-900 rounded-xl max-w-md w-full p-5 border border-stone-300 dark:border-stone-700 shadow-2xl">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-base font-bold text-stone-900 dark:text-stone-100">Edit Supplier Details</h3>
                <button type="button" onclick="closeEditModal()" class="text-stone-400 hover:text-stone-600 text-lg">&times;</button>
            </div>
            <form method="POST" class="space-y-3 text-xs">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="update_supplier">
                <input type="hidden" name="id" id="edit-id">

                <div>
                    <label class="block font-semibold mb-1">Supplier Name</label>
                    <input type="text" name="name" id="edit-name" required
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Contact Details / Email</label>
                    <input type="text" name="contact_details" id="edit-contact"
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Lead Time (Days)</label>
                    <input type="number" name="lead_time_days" id="edit-lead" required min="1"
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800">
                </div>
                <div class="flex justify-end gap-2 pt-3 border-t border-stone-200 dark:border-stone-800">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 border border-stone-300 dark:border-stone-700 rounded-lg text-stone-700 dark:text-stone-300">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg font-semibold">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEditModal(supplier) {
            document.getElementById('edit-id').value = supplier.id;
            document.getElementById('edit-name').value = supplier.NAME || supplier.name || '';
            document.getElementById('edit-contact').value = supplier.contact_details || '';
            document.getElementById('edit-lead').value = supplier.lead_time_days;
            const modal = document.getElementById('edit-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeEditModal() {
            const modal = document.getElementById('edit-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        document.getElementById('theme-toggle').addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark');
            try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch (e) {}
        });
    </script>
</body>
</html>
