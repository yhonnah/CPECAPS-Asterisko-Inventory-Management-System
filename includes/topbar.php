<?php
// includes/topbar.php
// Reusable top navigation header matching Asterisko Canva design
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$currentUserRole = $_SESSION['user_role'] ?? 'Staff';
$currentUsername = $_SESSION['username'] ?? 'User';
?>

<header class="bg-white dark:bg-[#0C2340] border-b border-slate-200 dark:border-[#15345C] sticky top-0 z-30 transition-colors">
    <div class="px-4 sm:px-6 lg:px-8 py-3.5 flex items-center justify-between gap-4">
        <!-- Left: Mobile Menu Toggle & Global Search Bar -->
        <div class="flex items-center gap-3 flex-1 max-w-lg">
            <button onclick="toggleSidebar(true)" class="lg:hidden p-2 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-[#132E52] transition-colors" aria-label="Open navigation menu">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
            </button>

            <!-- Search Bar (matching Canva Slide 2) -->
            <div class="relative w-full">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
                <input type="text" id="global-search-input" placeholder="Search items, categories, suppliers..." 
                       class="w-full !pl-11 !pr-4 py-2 bg-slate-50 dark:bg-[#07172C] text-slate-800 dark:text-slate-100 text-sm rounded-xl border border-slate-200 dark:border-[#1C3E69] focus:outline-none focus:border-[#F28C28] focus:ring-2 focus:ring-[#F28C28]/20 transition-all placeholder-slate-400 has-icon-left">
            </div>
        </div>

        <!-- Right: Actions & User Info -->
        <div class="flex items-center gap-2.5 sm:gap-3.5">
            <!-- Global Live Connectivity Pill (Offline-First Indicator) -->
            <div id="global-connectivity-pill" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-200/80 dark:border-emerald-800/40 shadow-sm cursor-pointer select-none transition-all" title="Connected to cloud server. Click to check sync.">
                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                <span class="hidden sm:inline">Online (Synced)</span>
                <span class="sm:hidden">Online</span>
            </div>

            <!-- Theme Toggle Button -->
            <button onclick="toggleTheme()" class="p-2 rounded-xl text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100 hover:bg-slate-100 dark:hover:bg-[#132E52] transition-colors" title="Toggle Theme">
                <svg id="theme-icon-sun" class="w-5 h-5 hidden dark:block text-[#F28C28]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                </svg>
                <svg id="theme-icon-moon" class="w-5 h-5 block dark:hidden text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                </svg>
            </button>

            <!-- Role Badge -->
            <span class="hidden sm:inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold uppercase tracking-wider <?= ($currentUserRole === 'Owner') ? 'bg-[#F28C28]/15 text-[#F28C28] border border-[#F28C28]/30' : (($currentUserRole === 'Manager' || $currentUserRole === 'Admin') ? 'bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300') ?>">
                <?= htmlspecialchars($currentUserRole) ?>
            </span>

            <!-- User Profile Avatar & Name (matching Canva Slide 2) -->
            <div class="flex items-center gap-2.5 pl-2 border-l border-slate-200 dark:border-[#15345C]">
                <div class="w-9 h-9 rounded-full bg-[#0C2340] dark:bg-[#132E52] border border-[#F28C28]/40 flex items-center justify-center text-white font-bold text-sm shadow-sm">
                    <?= strtoupper(substr($currentUsername, 0, 1)) ?>
                </div>
                <div class="hidden md:block text-left">
                    <span class="block text-sm font-semibold text-slate-800 dark:text-slate-100 leading-tight"><?= htmlspecialchars($currentUsername) ?></span>
                    <span class="block text-[11px] text-slate-400 leading-tight"><?= htmlspecialchars($currentUserRole) ?></span>
                </div>
            </div>
        </div>
    </div>
</header>

<script>
function toggleSidebar(open) {
    const sidebar = document.getElementById('main-sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    if (sidebar && backdrop) {
        if (open) {
            sidebar.classList.remove('-translate-x-full');
            backdrop.classList.remove('hidden');
        } else {
            sidebar.classList.add('-translate-x-full');
            backdrop.classList.add('hidden');
        }
    }
}

function toggleTheme() {
    const isDark = document.documentElement.classList.toggle('dark');
    try {
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
    } catch (e) {}
}
</script>
