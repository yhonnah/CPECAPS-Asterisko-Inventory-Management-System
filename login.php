<?php
require 'db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            header("Location: dashboard.php");
            exit();
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aste Inventory Login</title>
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

</head>
<body class="bg-stone-100 dark:bg-stone-900 flex items-center justify-center min-h-screen p-4 transition-colors">

    <button id="theme-toggle" type="button" aria-label="Toggle dark mode"
            class="fixed top-4 right-4 p-2.5 rounded-full bg-white dark:bg-stone-800 text-stone-700 dark:text-amber-400 shadow-md hover:scale-105 transition">
        <svg class="w-5 h-5 hidden dark:block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="4"/>
            <path stroke-linecap="round" d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>
        </svg>
        <svg class="w-5 h-5 block dark:hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>
        </svg>
    </button>

    <div class="bg-white dark:bg-stone-800 p-6 rounded-xl shadow-md w-full max-w-sm transition-colors">
        <h1 class="text-2xl font-bold text-stone-800 dark:text-stone-100 mb-1">Asterisko Cafe</h1>
        <p class="text-sm text-stone-500 dark:text-stone-400 mb-6">Inventory Management System</p>

        <?php if ($error): ?>
            <div class="bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 p-3 rounded mb-4 text-sm">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-stone-700 dark:text-stone-300">Username</label>
                <input type="text" name="username" required
                       class="mt-1 w-full p-2.5 border border-stone-300 dark:border-stone-600 rounded-lg bg-white dark:bg-stone-700 text-stone-900 dark:text-stone-100 focus:ring-2 focus:ring-amber-600 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-stone-700 dark:text-stone-300">Password</label>
                <input type="password" name="password" required
                       class="mt-1 w-full p-2.5 border border-stone-300 dark:border-stone-600 rounded-lg bg-white dark:bg-stone-700 text-stone-900 dark:text-stone-100 focus:ring-2 focus:ring-amber-600 focus:outline-none">
            </div>
            <button type="submit"
                    class="w-full bg-amber-700 hover:bg-amber-800 text-white font-semibold py-2.5 rounded-lg transition">
                Sign In
            </button>
        </form>

        <div class="mt-6 text-xs text-stone-500 dark:text-stone-400 border-t border-stone-200 dark:border-stone-700 pt-4">
            <p class="font-semibold mb-1">Demo Accounts (Password: <code class="bg-stone-200 dark:bg-stone-700 dark:text-stone-200 px-1">aste123</code>):</p>
            <ul class="space-y-1">
                <li>• <b>manager_bao</b> (Admin - Full Access)</li>
                <li>• <b>barista_marcie</b> (Staff - No Prices/Finance)</li>
                <li>• <b>owner_pao</b> (Owner - Finance & Stock)</li>
            </ul>
        </div>
    </div>

    <script>
        document.getElementById('theme-toggle').addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark');
            try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch (e) {}
        });
    </script>
</body>
</html>