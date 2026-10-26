<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/authguard.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            if (isset($user['is_active']) && (int)$user['is_active'] === 0) {
                $error = 'Your account has been deactivated. Please contact the Owner.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header("Location: dashboard.php");
                exit();
            }
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
$pageTitle = 'Asterisko - Login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>
<body class="bg-[#F4F7FB] dark:bg-[#071526] min-h-screen flex items-center justify-center p-4 sm:p-6 lg:p-8 transition-colors">

    <!-- Theme Toggle Fixed Button -->
    <button id="theme-toggle" type="button" aria-label="Toggle dark mode"
            class="fixed top-5 right-5 z-50 p-3 rounded-full bg-white dark:bg-[#0C2340] text-slate-600 dark:text-[#F28C28] shadow-lg border border-slate-200 dark:border-[#15345C] hover:scale-105 transition-transform">
        <svg class="w-5 h-5 hidden dark:block text-[#F28C28]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
        </svg>
        <svg class="w-5 h-5 block dark:hidden text-slate-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
        </svg>
    </button>

    <!-- Main Card Container (Matching Canva Slide 1 Split View) -->
    <div class="w-full max-w-4xl bg-white dark:bg-[#0C2340] rounded-2xl shadow-2xl border border-slate-200/80 dark:border-[#15345C] overflow-hidden flex flex-col md:flex-row transition-colors">
        
        <!-- Left Side: Brand Panel (Deep Navy with Orange Asterisk) -->
        <div class="md:w-1/2 bg-[#0C2340] text-white p-8 sm:p-12 flex flex-col justify-center items-center text-center relative border-b md:border-b-0 md:border-r border-[#15345C]">
            <div class="relative z-10 flex flex-col items-center">
                <!-- Large 8-Point Asterisk Logo -->
                <div class="w-20 h-20 rounded-3xl bg-[#F28C28]/15 border border-[#F28C28]/30 flex items-center justify-center mb-6 shadow-inner">
                    <svg class="w-12 h-12 text-[#F28C28]" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2a1.25 1.25 0 0 1 1.25 1.25v3.3a1.25 1.25 0 1 1-2.5 0v-3.3A1.25 1.25 0 0 1 12 2zm0 14.2a1.25 1.25 0 0 1 1.25 1.25v3.3a1.25 1.25 0 1 1-2.5 0v-3.3A1.25 1.25 0 0 1 12 16.2zM20.75 10.75a1.25 1.25 0 0 1 0 2.5h-3.3a1.25 1.25 0 1 1 0-2.5h3.3zm-14.2 0a1.25 1.25 0 0 1 0 2.5h-3.3a1.25 1.25 0 1 1 0-2.5h3.3zm11.66-5.83a1.25 1.25 0 0 1 0 1.77l-2.33 2.33a1.25 1.25 0 1 1-1.77-1.77l2.33-2.33a1.25 1.25 0 0 1 1.77 0zm-10.05 10.05a1.25 1.25 0 0 1 0 1.77l-2.33 2.33a1.25 1.25 0 1 1-1.77-1.77l2.33-2.33a1.25 1.25 0 0 1 1.77 0zm10.05 0a1.25 1.25 0 0 1 1.77 1.77l-2.33 2.33a1.25 1.25 0 1 1-1.77-1.77l2.33-2.33zm-10.05-10.05a1.25 1.25 0 0 1 1.77-1.77l2.33 2.33a1.25 1.25 0 1 1-1.77 1.77l-2.33-2.33z"/>
                    </svg>
                </div>
                <h1 class="text-3xl font-extrabold tracking-wider uppercase text-white mb-2">ASTERISKO</h1>
                <p class="text-slate-300 text-sm max-w-xs font-medium">Inventory Management System</p>
                <div class="w-16 h-1 bg-[#F28C28] rounded-full mt-4"></div>
            </div>
            
            <!-- Subtle background aesthetic pattern -->
            <div class="absolute -bottom-10 -left-10 w-40 h-40 bg-[#F28C28]/10 rounded-full blur-3xl pointer-events-none"></div>
            <div class="absolute -top-10 -right-10 w-40 h-40 bg-[#132E52]/40 rounded-full blur-2xl pointer-events-none"></div>
        </div>

        <!-- Right Side: Login Form (Matching Canva Slide 1 Form) -->
        <div class="md:w-1/2 p-8 sm:p-12 flex flex-col justify-center bg-white dark:bg-[#0C2340]">
            <div class="mb-6">
                <h2 class="text-2xl font-bold text-slate-800 dark:text-white">Welcome Back</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Sign in to your Asterisko account</p>
            </div>

            <?php if ($error): ?>
                <div class="bg-rose-50 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800 px-4 py-3 rounded-xl mb-5 text-sm flex items-center gap-2">
                    <svg class="w-4 h-4 text-rose-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">

                <!-- Username / Email Field -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1.5">Username or Email</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"></path>
                            </svg>
                        </div>
                        <input type="text" name="username" required placeholder="Enter your username"
                               class="w-full pl-10 pr-4 py-3 bg-slate-50 dark:bg-[#07172C] text-slate-800 dark:text-white border border-slate-200 dark:border-[#173863] rounded-xl text-sm focus:outline-none focus:border-[#F28C28] focus:ring-2 focus:ring-[#F28C28]/20 transition-all">
                    </div>
                </div>

                <!-- Password Field with Toggle Eye -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1.5">Password</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                        </div>
                        <input type="password" id="password-input" name="password" required placeholder="Enter your password"
                               class="w-full pl-10 pr-11 py-3 bg-slate-50 dark:bg-[#07172C] text-slate-800 dark:text-white border border-slate-200 dark:border-[#173863] rounded-xl text-sm focus:outline-none focus:border-[#F28C28] focus:ring-2 focus:ring-[#F28C28]/20 transition-all">
                        <button type="button" onclick="togglePasswordVisibility()" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200" aria-label="Toggle password visibility">
                            <svg id="eye-icon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Remember Me & Forgot Password -->
                <div class="flex items-center justify-between text-xs pt-1">
                    <label class="flex items-center text-slate-600 dark:text-slate-400 cursor-pointer">
                        <input type="checkbox" name="remember" class="w-4 h-4 rounded text-[#F28C28] focus:ring-[#F28C28] border-slate-300 dark:border-slate-700">
                        <span class="ml-2 font-medium">Remember me</span>
                    </label>
                    <a href="javascript:alert('Please contact your System Owner or Manager to reset your password.')" class="font-semibold text-[#F28C28] hover:underline">Forgot password?</a>
                </div>

                <!-- Submit Button -->
                <button type="submit"
                        class="w-full bg-[#F28C28] hover:bg-[#E07B18] text-white font-bold py-3.5 rounded-xl shadow-lg shadow-[#F28C28]/25 hover:shadow-[#F28C28]/40 hover:-translate-y-0.5 transition-all text-sm tracking-wide">
                    Log In
                </button>
            </form>

            <p class="text-center text-xs text-slate-400 dark:text-slate-500 mt-6">
                Don't have an account? <span class="font-medium text-slate-600 dark:text-slate-400">Contact your administrator.</span>
            </p>

            <!-- Quick Demo Credentials Box -->
            <div class="mt-6 p-3 bg-slate-50 dark:bg-[#07172C] rounded-xl border border-slate-200 dark:border-[#173863] text-xs text-slate-500 dark:text-slate-400">
                <span class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Demo Credentials (Password: <code class="bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-200 px-1 py-0.5 rounded">aste123</code>):</span>
                <div class="grid grid-cols-3 gap-1 text-[11px]">
                    <div><b class="text-slate-700 dark:text-slate-300">manager</b><br><span class="text-[10px]">Manager</span></div>
                    <div><b class="text-slate-700 dark:text-slate-300">barista</b><br><span class="text-[10px]">Staff</span></div>
                    <div><b class="text-slate-700 dark:text-slate-300">owner</b><br><span class="text-[10px]">Owner</span></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function togglePasswordVisibility() {
            const pwd = document.getElementById('password-input');
            pwd.type = pwd.type === 'password' ? 'text' : 'password';
        }

        document.getElementById('theme-toggle').addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark');
            try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch (e) {}
        });
    </script>
</body>
</html>
