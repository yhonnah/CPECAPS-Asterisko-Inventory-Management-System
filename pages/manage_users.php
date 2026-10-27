<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/authguard.php';

// Strictly restricted to Owner
requireRole(['Owner'], $pdo);

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$userId = (int)$_SESSION['user_id'];
$message = '';
$messageType = '';

// Handle User Management Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $newUsername = trim($_POST['username'] ?? '');
        $newRole = $_POST['role'] ?? 'Staff';
        $newPassword = $_POST['password'] ?? '';

        if ($newUsername === '' || $newPassword === '') {
            $message = 'Username and password are required.';
            $messageType = 'error';
        } elseif (!in_array($newRole, ['Admin', 'Staff'])) {
            $message = 'Invalid role selected. Only Manager (Admin) or Barista (Staff) can be created.';
            $messageType = 'error';
        } else {
            $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $check->execute([$newUsername]);
            if ($check->fetch()) {
                $message = "Username '{$newUsername}' is already taken.";
                $messageType = 'error';
            } else {
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, is_active) VALUES (?, ?, ?, 1)");
                $stmt->execute([$newUsername, $hash, $newRole]);
                $newId = (int)$pdo->lastInsertId();

                $audit = $pdo->prepare("
                    INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
                    VALUES (NULL, NULL, NULL, 'user_management', ?, ?)
                ");
                $audit->execute(["Created user account '{$newUsername}' (Role: {$newRole}, ID: {$newId})", $username]);

                $message = "User '{$newUsername}' ({$newRole}) created successfully!";
                $messageType = 'success';
            }
        }
    } elseif ($action === 'toggle_active') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        if ($targetId === $userId) {
            $message = 'You cannot deactivate your own active Owner account.';
            $messageType = 'error';
        } else {
            $curr = $pdo->prepare("SELECT username, is_active FROM users WHERE id = ?");
            $curr->execute([$targetId]);
            $targetUser = $curr->fetch(PDO::FETCH_ASSOC);

            if ($targetUser) {
                $newStatus = $targetUser['is_active'] ? 0 : 1;
                $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                $stmt->execute([$newStatus, $targetId]);

                $statusLabel = $newStatus ? 'Activated' : 'Deactivated';
                $audit = $pdo->prepare("
                    INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
                    VALUES (NULL, NULL, NULL, 'user_management', ?, ?)
                ");
                $audit->execute(["{$statusLabel} account for '{$targetUser['username']}' (ID: {$targetId})", $username]);

                $message = "User '{$targetUser['username']}' has been {$statusLabel}.";
                $messageType = 'success';
            }
        }
    } elseif ($action === 'reset_password') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $newPass = $_POST['new_password'] ?? '';

        if ($targetId <= 0 || trim($newPass) === '') {
            $message = 'Please provide a valid new password.';
            $messageType = 'error';
        } else {
            $curr = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $curr->execute([$targetId]);
            $targetUser = $curr->fetch(PDO::FETCH_ASSOC);

            if ($targetUser) {
                $hash = password_hash($newPass, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$hash, $targetId]);

                $audit = $pdo->prepare("
                    INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
                    VALUES (NULL, NULL, NULL, 'user_management', ?, ?)
                ");
                $audit->execute(["Reset password for user '{$targetUser['username']}' (ID: {$targetId})", $username]);

                $message = "Password for '{$targetUser['username']}' has been updated successfully.";
                $messageType = 'success';
            }
        }
    }
}

// Fetch all registered users
$allUsers = $pdo->query("SELECT id, username, role, is_active, created_at FROM users ORDER BY role, username")->fetchAll(PDO::FETCH_ASSOC);

$activePage = 'settings';
$pageTitle = 'Settings & User Management - Asterisko Inventory';
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

            <!-- Title & Subtitle (Canva Slide 9 Spec) -->
            <div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">Settings</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Manage account details, user permissions, notifications, and backups.</p>
            </div>

            <!-- Messages Feedback -->
            <?php if ($message): ?>
                <div class="p-4 rounded-xl text-sm font-medium flex items-center gap-2 <?= $messageType === 'success' ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-200 border border-emerald-300 dark:border-emerald-800' : 'bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-200 border border-rose-300 dark:border-rose-800' ?>">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $messageType === 'success' ? 'M5 13l4 4L19 7' : 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z' ?>"/></svg>
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
            <?php endif; ?>

            <!-- 2-Column Settings Layout (Exact Canva Slide 9 Spec) -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                
                <!-- Left Column: Settings Navigation Tabs Card -->
                <div class="lg:col-span-4 bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] p-3 space-y-1">
                    <button type="button" onclick="switchTab('profile')" id="tab-btn-profile" class="w-full text-left p-3.5 rounded-xl transition-all flex items-center justify-between bg-[#F28C28]/15 border border-[#F28C28]/30 text-[#F28C28]">
                        <div>
                            <span class="font-bold text-sm block">Profile</span>
                            <span class="text-xs text-slate-400">Account details</span>
                        </div>
                        <svg class="w-4 h-4 text-[#F28C28]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </button>

                    <button type="button" onclick="switchTab('users')" id="tab-btn-users" class="w-full text-left p-3.5 rounded-xl transition-all flex items-center justify-between text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-[#132E52]">
                        <div>
                            <span class="font-bold text-sm block">User Management</span>
                            <span class="text-xs text-slate-400">Roles and access</span>
                        </div>
                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </button>

                    <a href="backup.php" class="w-full text-left p-3.5 rounded-xl transition-all flex items-center justify-between text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-[#132E52]">
                        <div>
                            <span class="font-bold text-sm block">Data & Backup</span>
                            <span class="text-xs text-slate-400">Database snapshots</span>
                        </div>
                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    </a>
                </div>

                <!-- Right Column: Settings Content Panel -->
                <div class="lg:col-span-8 space-y-6">
                    
                    <!-- TAB 1: Profile Settings (Canva Slide 9 Panel) -->
                    <div id="panel-profile" class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] p-6 sm:p-8 space-y-6">
                        <div class="border-b border-slate-200 dark:border-[#15345C] pb-4">
                            <h2 class="text-lg font-bold text-slate-900 dark:text-white">Profile Settings</h2>
                            <p class="text-xs text-slate-400">Manage account information and security</p>
                        </div>

                        <!-- Account Information -->
                        <div class="space-y-4">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500">Account Information</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Username</label>
                                    <input type="text" value="<?= htmlspecialchars($username) ?>" readonly class="w-full bg-slate-100 dark:bg-[#07172C] cursor-not-allowed">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Active Role</label>
                                    <input type="text" value="<?= htmlspecialchars($role) ?>" readonly class="w-full bg-slate-100 dark:bg-[#07172C] cursor-not-allowed font-semibold">
                                </div>
                            </div>
                        </div>

                        <!-- Security -->
                        <div class="space-y-4 pt-4 border-t border-slate-200 dark:border-[#15345C]">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500">Security</h3>
                            <form method="POST" class="space-y-3">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="user_id" value="<?= $userId ?>">
                                <div class="flex flex-col sm:flex-row gap-3">
                                    <input type="password" name="new_password" required placeholder="Enter new password..." class="flex-1">
                                    <button type="submit" class="btn-secondary px-5 py-2.5 rounded-xl text-xs font-bold whitespace-nowrap">
                                        Change Password
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Session Preferences -->
                        <div class="pt-4 border-t border-slate-200 dark:border-[#15345C] flex items-center justify-between">
                            <div>
                                <span class="font-bold text-sm block text-slate-800 dark:text-white">Session Security</span>
                                <span class="text-xs text-slate-400">Strict CSRF token validation and HTTP session regeneration are active.</span>
                            </div>
                            <span class="badge-good">Enabled</span>
                        </div>
                    </div>

                    <!-- TAB 2: User Management (Roles & Access) -->
                    <div id="panel-users" class="hidden space-y-6">
                        
                        <!-- Provision User Form Card -->
                        <div class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] p-6 space-y-4">
                            <h2 class="text-base font-bold text-slate-900 dark:text-white">Provision New User Account</h2>
                            <form method="POST" class="grid grid-cols-1 sm:grid-cols-4 gap-3 text-xs items-end">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="create_user">

                                <div>
                                    <label class="block font-bold mb-1">Username *</label>
                                    <input type="text" name="username" required placeholder="e.g. barista_mike" class="w-full">
                                </div>
                                <div>
                                    <label class="block font-bold mb-1">Role Permission *</label>
                                    <select name="role" required class="w-full">
                                        <option value="Staff">Barista (Staff)</option>
                                        <option value="Admin">Manager (Admin)</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block font-bold mb-1">Initial Password *</label>
                                    <input type="password" name="password" required placeholder="••••••••" class="w-full">
                                </div>
                                <div>
                                    <button type="submit" class="btn-primary w-full py-2.5 rounded-xl font-bold shadow-md">
                                        Create User
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Users Directory Table Card -->
                        <div class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] overflow-hidden">
                            <div class="p-5 border-b border-slate-200 dark:border-[#15345C]">
                                <h2 class="font-bold text-base text-slate-900 dark:text-white">System Users Directory</h2>
                                <p class="text-xs text-slate-400">Manage account access states and credentials</p>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-xs text-left">
                                    <thead>
                                        <tr>
                                            <th>Username</th>
                                            <th>Role</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                            <th class="text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($allUsers as $u): ?>
                                        <tr class="<?= !$u['is_active'] ? 'opacity-50 bg-slate-50 dark:bg-slate-900/30' : '' ?>">
                                            <td class="font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($u['username']) ?></td>
                                            <td>
                                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold uppercase <?= $u['role'] === 'Owner' ? 'bg-[#F28C28]/15 text-[#F28C28]' : ($u['role'] === 'Admin' ? 'bg-indigo-100 dark:bg-indigo-950 text-indigo-700' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300') ?>">
                                                    <?= htmlspecialchars($u['role']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($u['is_active']): ?>
                                                    <span class="badge-good">Active</span>
                                                <?php else: ?>
                                                    <span class="badge-danger">Deactivated</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-slate-500 whitespace-nowrap"><?= substr($u['created_at'], 0, 10) ?></td>
                                            <td class="text-right">
                                                <?php if ($u['id'] !== $userId): ?>
                                                    <div class="inline-flex items-center gap-2">
                                                        <form method="POST" class="inline" onsubmit="return confirm('Toggle active status for this user?');">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                                            <input type="hidden" name="action" value="toggle_active">
                                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                            <button type="submit" class="text-xs font-bold <?= $u['is_active'] ? 'text-rose-500 hover:underline' : 'text-emerald-500 hover:underline' ?>">
                                                                <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
                                                            </button>
                                                        </form>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-[11px] text-slate-400 italic">Current User</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>

                </div>
            </div>

        </main>
    </div>

    <script>
        function switchTab(tab) {
            const panelProfile = document.getElementById('panel-profile');
            const panelUsers = document.getElementById('panel-users');
            const btnProfile = document.getElementById('tab-btn-profile');
            const btnUsers = document.getElementById('tab-btn-users');

            if (tab === 'profile') {
                panelProfile.classList.remove('hidden');
                panelUsers.classList.add('hidden');
                btnProfile.className = 'w-full text-left p-3.5 rounded-xl transition-all flex items-center justify-between bg-[#F28C28]/15 border border-[#F28C28]/30 text-[#F28C28]';
                btnUsers.className = 'w-full text-left p-3.5 rounded-xl transition-all flex items-center justify-between text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-[#132E52]';
            } else {
                panelProfile.classList.add('hidden');
                panelUsers.classList.remove('hidden');
                btnUsers.className = 'w-full text-left p-3.5 rounded-xl transition-all flex items-center justify-between bg-[#F28C28]/15 border border-[#F28C28]/30 text-[#F28C28]';
                btnProfile.className = 'w-full text-left p-3.5 rounded-xl transition-all flex items-center justify-between text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-[#132E52]';
            }
        }
    </script>
</body>
</html>
