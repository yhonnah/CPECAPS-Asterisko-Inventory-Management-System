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
            // Check if username exists
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

                // Audit log entry
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Asterisko Inventory</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
        try {
            const saved = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (saved === 'dark' || (!saved && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {}
    </script>

<link rel="stylesheet" href="../assets/app-enhancements.css">

</head>
<body class="bg-stone-100 dark:bg-stone-950 text-stone-800 dark:text-stone-200 min-h-screen transition-colors">

    <!-- Top Navigation Bar -->
    <nav class="bg-stone-900 dark:bg-black text-white px-4 py-3 flex justify-between items-center border-b border-stone-800">
        <div class="flex items-center gap-3">
            <a href="dashboard.php" class="text-emerald-400 hover:text-emerald-300 text-sm font-semibold flex items-center gap-1">
                &larr; Dashboard
            </a>
            <span class="text-stone-600">|</span>
            <span class="font-bold text-base sm:text-lg">User Access & Staff Administration</span>
            <span class="text-xs bg-emerald-600 px-2 py-0.5 rounded uppercase font-semibold"><?= htmlspecialchars($role) ?></span>
        </div>
        <div class="flex items-center gap-3 text-sm">
            <span class="hidden sm:inline">User: <b><?= htmlspecialchars($username) ?></b></span>
            <button id="theme-toggle" type="button" aria-label="Toggle dark mode"
                    class="p-1.5 rounded-full bg-stone-700 hover:bg-stone-600 text-emerald-300 transition">
                <svg class="w-4 h-4 hidden dark:block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                <svg class="w-4 h-4 block dark:hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
            </button>
            <a href="logout.php" class="bg-stone-700 hover:bg-stone-600 px-3 py-1 rounded text-xs">Logout</a>
        </div>
    </nav>

    <div class="max-w-6xl mx-auto p-4 space-y-6">

        <!-- Role Badge Info -->
        <div class="bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-800 text-emerald-900 dark:text-emerald-200 p-4 rounded-xl text-xs flex justify-between items-center shadow-sm">
            <div>
                <p class="font-bold text-sm">Owner Executive Clearance</p>
                <p class="text-stone-600 dark:text-stone-400 mt-0.5">You have elevated access to provision user accounts, reset staff passwords, and manage store operational roles.</p>
            </div>
            <span class="font-mono bg-emerald-600 text-white font-bold px-3 py-1 rounded-full text-xs">
                <?= count($allUsers) ?> Registered Accounts
            </span>
        </div>

        <?php if ($message): ?>
            <div class="p-3.5 rounded-xl text-sm font-medium border <?= $messageType === 'success' ? 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-200 border-emerald-300 dark:border-emerald-800' : 'bg-rose-50 dark:bg-rose-950/60 text-rose-800 dark:text-rose-200 border-rose-300 dark:border-rose-800' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Create New User Form -->
        <div class="bg-white dark:bg-stone-900 rounded-xl shadow p-5 border border-stone-200 dark:border-stone-800">
            <h2 class="text-lg font-bold text-stone-900 dark:text-stone-100 mb-3 flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                Provision New Employee / Staff Account
            </h2>
            <form method="POST" class="grid grid-cols-1 sm:grid-cols-4 gap-4 text-xs items-end">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="create_user">

                <div>
                    <label class="block font-semibold mb-1">Username *</label>
                    <input type="text" name="username" required placeholder="e.g. barista_mike"
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:ring-2 focus:ring-emerald-600 focus:outline-none">
                </div>

                <div>
                    <label class="block font-semibold mb-1">System Role *</label>
                    <select name="role" required class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:ring-2 focus:ring-emerald-600 focus:outline-none">
                        <option value="Staff">Staff (Barista - Prices Masked)</option>
                        <option value="Admin">Admin (Store Manager - Operational Oversight)</option>
                    </select>
                </div>

                <div>
                    <label class="block font-semibold mb-1">Initial Password *</label>
                    <input type="password" name="password" required placeholder="••••••••"
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:ring-2 focus:ring-emerald-600 focus:outline-none">
                </div>

                <div>
                    <button type="submit" class="w-full bg-emerald-700 hover:bg-emerald-800 text-white font-semibold py-2.5 rounded-lg transition shadow">
                        + Create Account
                    </button>
                </div>
            </form>
        </div>

        <!-- Users Roster Table -->
        <div class="bg-white dark:bg-stone-900 rounded-xl shadow overflow-hidden border border-stone-200 dark:border-stone-800">
            <div class="p-4 border-b border-stone-200 dark:border-stone-800 flex justify-between items-center">
                <h3 class="text-sm font-bold text-stone-900 dark:text-stone-100">User Accounts Roster</h3>
                <span class="text-xs text-stone-500">All credential alterations are permanently recorded in the audit trail.</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-xs text-left">
                    <thead class="bg-stone-50 dark:bg-stone-800 uppercase font-semibold text-stone-600 dark:text-stone-400 border-b border-stone-200 dark:border-stone-700 text-[11px]">
                        <tr>
                            <th class="p-3">ID</th>
                            <th class="p-3">Username</th>
                            <th class="p-3">Role</th>
                            <th class="p-3">Status</th>
                            <th class="p-3">Created Date</th>
                            <th class="p-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200 dark:divide-stone-800">
                        <?php foreach ($allUsers as $u): 
                            $isSelf = ((int)$u['id'] === $userId);
                        ?>
                        <tr class="hover:bg-stone-50 dark:hover:bg-stone-800/50">
                            <td class="p-3 font-mono text-stone-500">#<?= $u['id'] ?></td>
                            <td class="p-3 font-bold text-stone-900 dark:text-stone-100">
                                <?= htmlspecialchars($u['username']) ?>
                                <?php if ($isSelf): ?>
                                    <span class="text-[10px] bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 px-1.5 py-0.5 rounded font-semibold ml-1">You</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-3">
                                <?php if ($u['role'] === 'Owner'): ?>
                                    <span class="bg-purple-100 dark:bg-purple-950 text-purple-800 dark:text-purple-300 font-bold px-2 py-0.5 rounded text-[11px]">Owner</span>
                                <?php elseif ($u['role'] === 'Admin'): ?>
                                    <span class="bg-blue-100 dark:bg-blue-950 text-blue-800 dark:text-blue-300 font-semibold px-2 py-0.5 rounded text-[11px]">Manager (Admin)</span>
                                <?php else: ?>
                                    <span class="bg-stone-200 dark:bg-stone-800 text-stone-700 dark:text-stone-300 font-medium px-2 py-0.5 rounded text-[11px]">Staff (Barista)</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-3">
                                <?php if (!empty($u['is_active'])): ?>
                                    <span class="inline-flex items-center gap-1.5 text-emerald-700 dark:text-emerald-400 font-semibold text-[11px]">
                                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span> Active
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 text-rose-600 dark:text-rose-400 font-semibold text-[11px]">
                                        <span class="w-2 h-2 rounded-full bg-rose-500"></span> Deactivated
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="p-3 text-stone-500 font-mono text-[11px]">
                                <?= !empty($u['created_at']) ? htmlspecialchars($u['created_at']) : 'N/A' ?>
                            </td>
                            <td class="p-3 text-right space-x-2">
                                <button type="button" onclick="openResetModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['username'])) ?>')"
                                        class="text-emerald-600 hover:text-emerald-500 font-semibold underline text-xs">
                                    Reset Password
                                </button>
                                <?php if (!$isSelf): ?>
                                    <form method="POST" class="inline" onsubmit="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> account for <?= htmlspecialchars(addslashes($u['username'])) ?>?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="text-xs font-semibold <?= $u['is_active'] ? 'text-rose-600 hover:underline' : 'text-emerald-600 hover:underline' ?>">
                                            <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- Password Reset Modal -->
    <div id="reset-modal" class="fixed inset-0 bg-black/60 hidden items-center justify-center p-4 z-50">
        <div class="bg-white dark:bg-stone-900 rounded-xl max-w-sm w-full p-5 border border-stone-300 dark:border-stone-700 shadow-2xl">
            <div class="flex justify-between items-center mb-3">
                <h3 class="text-base font-bold text-stone-900 dark:text-stone-100">Reset User Password</h3>
                <button type="button" onclick="closeResetModal()" class="text-stone-400 hover:text-stone-600 text-lg">&times;</button>
            </div>
            <p class="text-xs text-stone-500 mb-4">Set a new temporary password for <b id="modal-username" class="text-stone-800 dark:text-stone-200"></b>.</p>

            <form method="POST" class="space-y-3 text-xs">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="modal-user-id">

                <div>
                    <label class="block font-semibold mb-1">New Password *</label>
                    <input type="password" name="new_password" required placeholder="••••••••"
                           class="w-full p-2.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 font-mono text-stone-900 dark:text-stone-100 focus:ring-2 focus:ring-emerald-600 focus:outline-none">
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="closeResetModal()" class="px-3 py-2 border border-stone-300 dark:border-stone-700 rounded-lg text-stone-700 dark:text-stone-300 hover:bg-stone-100 dark:hover:bg-stone-800 font-semibold">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg font-semibold shadow">
                        Save New Password
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('theme-toggle').addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark');
            try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch (e) {}
        });

        function openResetModal(id, uname) {
            document.getElementById('modal-user-id').value = id;
            document.getElementById('modal-username').textContent = uname;
            document.getElementById('reset-modal').classList.remove('hidden');
            document.getElementById('reset-modal').classList.add('flex');
        }

        function closeResetModal() {
            document.getElementById('reset-modal').classList.add('hidden');
            document.getElementById('reset-modal').classList.remove('flex');
        }
    </script>
</body>
</html>
