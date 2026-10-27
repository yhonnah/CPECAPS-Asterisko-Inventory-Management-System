<?php
// includes/sidebar.php
// Reusable navigation sidebar matching Asterisko Canva design
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$currentUserRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'Staff';
$currentUsername = $_SESSION['username'] ?? 'User';
$currentPage = $activePage ?? '';

// Role capabilities matching system permissions:
// - Owner: Full access across all modules including Settings & Backups
// - Admin/Manager: Inventory Management, Purchasing, Suppliers, and Financial/Variance Reports
// - Staff/Barista: Front-of-house & inventory intake (Dashboard, Orders, Stock Count, Deliveries, Waste)
$isOwner = in_array(strtolower($currentUserRole), ['owner']);
$isManagerOrOwner = in_array(strtolower($currentUserRole), ['admin', 'manager', 'owner']);
?>

<!-- Mobile Sidebar Backdrop -->
<div id="sidebar-backdrop" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 hidden lg:hidden animate-ast-fade-in" onclick="toggleSidebar(false)"></div>

<!-- Main Left Sidebar -->
<aside id="main-sidebar" class="fixed top-0 left-0 h-screen w-64 bg-[#0C2340] text-white flex flex-col justify-between z-50 transition-transform duration-300 ease-in-out -translate-x-full lg:translate-x-0 border-r border-[#15345C] shadow-[4px_0_24px_rgba(7,21,38,0.18)]">
    <div>
        <!-- Brand Header -->
        <div class="px-6 py-6 border-b border-[#15345C]/70 flex items-center justify-between">
            <a href="dashboard.php" class="flex items-center gap-3 group">
                <!-- Asterisko Official Brand Logo -->
                <div class="w-10 h-10 rounded-xl overflow-hidden bg-[#FEF2DA] border border-white/20 p-1 flex items-center justify-center flex-shrink-0 shadow-md animate-ast-logo-pop transition-shadow duration-300 group-hover:shadow-[0_0_0_3px_rgba(242,140,40,0.35),0_6px_16px_rgba(0,0,0,0.3)]">
                    <img src="../assets/logo.png" alt="Asterisko" class="w-full h-full object-contain rounded-lg animate-ast-logo-twinkle group-hover:animate-ast-logo-spin">
                </div>
                <div>
                    <h1 class="text-lg font-black tracking-wider text-white uppercase leading-none animate-ast-text-reveal [animation-delay:250ms] transition-colors duration-200 group-hover:text-[#F28C28]">ASTERISKO</h1>
                    <span class="text-[10px] text-slate-400 font-medium tracking-tight animate-ast-text-reveal [animation-delay:380ms]">Inventory Management</span>
                </div>
            </a>
            <!-- Mobile Close Button -->
            <button onclick="toggleSidebar(false)" class="lg:hidden text-slate-400 hover:text-white p-1" aria-label="Close menu">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <!-- Navigation Menu -->
        <nav class="px-4 py-6 space-y-1.5 overflow-y-auto max-h-[calc(100vh-170px)] custom-scrollbar">
            <!-- Dashboard -->
            <a href="dashboard.php" class="group/link relative flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all animate-ast-slide-right [animation-delay:20ms] <?= ($currentPage === 'dashboard') ? 'bg-[#F28C28] text-white shadow-[0_6px_18px_rgba(242,140,40,0.32)] before:content-[\'\'] before:absolute before:-left-4 before:top-1/2 before:-translate-y-1/2 before:w-1 before:h-[60%] before:rounded-r before:bg-[#F28C28]' : 'text-slate-300 hover:text-white hover:bg-[#132E52] hover:translate-x-[3px]' ?>">
                <svg class="w-5 h-5 transition-transform duration-150 <?= ($currentPage === 'dashboard') ? 'text-white' : 'text-slate-400 group-hover/link:text-[#F28C28] group-hover/link:scale-110' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                <span>Dashboard</span>
            </a>

            <!-- Coffee Orders & Stock Deduction -->
            <a href="orders.php" class="group/link relative flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all animate-ast-slide-right [animation-delay:50ms] <?= ($currentPage === 'orders') ? 'bg-[#F28C28] text-white shadow-[0_6px_18px_rgba(242,140,40,0.32)] before:content-[\'\'] before:absolute before:-left-4 before:top-1/2 before:-translate-y-1/2 before:w-1 before:h-[60%] before:rounded-r before:bg-[#F28C28]' : 'text-slate-300 hover:text-white hover:bg-[#132E52] hover:translate-x-[3px]' ?>">
                <svg class="w-5 h-5 transition-transform duration-150 <?= ($currentPage === 'orders') ? 'text-white' : 'text-slate-400 group-hover/link:text-[#F28C28] group-hover/link:scale-110' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                </svg>
                <span>Orders</span>
            </a>

            <!-- Manage Items (Admin & Owner) -->
            <?php if ($isManagerOrOwner): ?>
            <a href="manage_items.php" class="group/link relative flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all animate-ast-slide-right [animation-delay:80ms] <?= ($currentPage === 'manage_items') ? 'bg-[#F28C28] text-white shadow-[0_6px_18px_rgba(242,140,40,0.32)] before:content-[\'\'] before:absolute before:-left-4 before:top-1/2 before:-translate-y-1/2 before:w-1 before:h-[60%] before:rounded-r before:bg-[#F28C28]' : 'text-slate-300 hover:text-white hover:bg-[#132E52] hover:translate-x-[3px]' ?>">
                <svg class="w-5 h-5 transition-transform duration-150 <?= ($currentPage === 'manage_items') ? 'text-white' : 'text-slate-400 group-hover/link:text-[#F28C28] group-hover/link:scale-110' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
                <span>Manage Items</span>
            </a>
            <?php endif; ?>

            <!-- Stock Count -->
            <a href="count.php" class="group/link relative flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all animate-ast-slide-right [animation-delay:110ms] <?= ($currentPage === 'count') ? 'bg-[#F28C28] text-white shadow-[0_6px_18px_rgba(242,140,40,0.32)] before:content-[\'\'] before:absolute before:-left-4 before:top-1/2 before:-translate-y-1/2 before:w-1 before:h-[60%] before:rounded-r before:bg-[#F28C28]' : 'text-slate-300 hover:text-white hover:bg-[#132E52] hover:translate-x-[3px]' ?>">
                <svg class="w-5 h-5 transition-transform duration-150 <?= ($currentPage === 'count') ? 'text-white' : 'text-slate-400 group-hover/link:text-[#F28C28] group-hover/link:scale-110' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                </svg>
                <span>Stock Count</span>
            </a>

            <!-- Deliveries & Intake -->
            <a href="delivery_intake.php" class="group/link relative flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all animate-ast-slide-right [animation-delay:140ms] <?= ($currentPage === 'deliveries') ? 'bg-[#F28C28] text-white shadow-[0_6px_18px_rgba(242,140,40,0.32)] before:content-[\'\'] before:absolute before:-left-4 before:top-1/2 before:-translate-y-1/2 before:w-1 before:h-[60%] before:rounded-r before:bg-[#F28C28]' : 'text-slate-300 hover:text-white hover:bg-[#132E52] hover:translate-x-[3px]' ?>">
                <svg class="w-5 h-5 transition-transform duration-150 <?= ($currentPage === 'deliveries') ? 'text-white' : 'text-slate-400 group-hover/link:text-[#F28C28] group-hover/link:scale-110' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/>
                </svg>
                <span>Deliveries</span>
            </a>

            <!-- Purchase Orders (Admin & Owner) -->
            <?php if ($isManagerOrOwner): ?>
            <a href="purchase_orders.php" class="group/link relative flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all animate-ast-slide-right [animation-delay:170ms] <?= ($currentPage === 'purchase_orders') ? 'bg-[#F28C28] text-white shadow-[0_6px_18px_rgba(242,140,40,0.32)] before:content-[\'\'] before:absolute before:-left-4 before:top-1/2 before:-translate-y-1/2 before:w-1 before:h-[60%] before:rounded-r before:bg-[#F28C28]' : 'text-slate-300 hover:text-white hover:bg-[#132E52] hover:translate-x-[3px]' ?>">
                <svg class="w-5 h-5 transition-transform duration-150 <?= ($currentPage === 'purchase_orders') ? 'text-white' : 'text-slate-400 group-hover/link:text-[#F28C28] group-hover/link:scale-110' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <span>Purchase Orders</span>
            </a>
            <?php endif; ?>

            <!-- Suppliers (Admin & Owner) -->
            <?php if ($isManagerOrOwner): ?>
            <a href="manage_suppliers.php" class="group/link relative flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all animate-ast-slide-right [animation-delay:200ms] <?= ($currentPage === 'suppliers') ? 'bg-[#F28C28] text-white shadow-[0_6px_18px_rgba(242,140,40,0.32)] before:content-[\'\'] before:absolute before:-left-4 before:top-1/2 before:-translate-y-1/2 before:w-1 before:h-[60%] before:rounded-r before:bg-[#F28C28]' : 'text-slate-300 hover:text-white hover:bg-[#132E52] hover:translate-x-[3px]' ?>">
                <svg class="w-5 h-5 transition-transform duration-150 <?= ($currentPage === 'suppliers') ? 'text-white' : 'text-slate-400 group-hover/link:text-[#F28C28] group-hover/link:scale-110' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
                <span>Suppliers</span>
            </a>
            <?php endif; ?>

            <!-- Waste & Spoilage -->
            <a href="waste.php" class="group/link relative flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all animate-ast-slide-right [animation-delay:230ms] <?= ($currentPage === 'waste') ? 'bg-[#F28C28] text-white shadow-[0_6px_18px_rgba(242,140,40,0.32)] before:content-[\'\'] before:absolute before:-left-4 before:top-1/2 before:-translate-y-1/2 before:w-1 before:h-[60%] before:rounded-r before:bg-[#F28C28]' : 'text-slate-300 hover:text-white hover:bg-[#132E52] hover:translate-x-[3px]' ?>">
                <svg class="w-5 h-5 transition-transform duration-150 <?= ($currentPage === 'waste') ? 'text-white' : 'text-slate-400 group-hover/link:text-[#F28C28] group-hover/link:scale-110' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
                <span>Waste</span>
            </a>

            <!-- Reports (Admin & Owner) -->
            <?php if ($isManagerOrOwner): ?>
            <a href="financial_report.php" class="group/link relative flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all animate-ast-slide-right [animation-delay:260ms] <?= (in_array($currentPage, ['reports', 'financial_report', 'variance_report'])) ? 'bg-[#F28C28] text-white shadow-[0_6px_18px_rgba(242,140,40,0.32)] before:content-[\'\'] before:absolute before:-left-4 before:top-1/2 before:-translate-y-1/2 before:w-1 before:h-[60%] before:rounded-r before:bg-[#F28C28]' : 'text-slate-300 hover:text-white hover:bg-[#132E52] hover:translate-x-[3px]' ?>">
                <svg class="w-5 h-5 transition-transform duration-150 <?= (in_array($currentPage, ['reports', 'financial_report', 'variance_report'])) ? 'text-white' : 'text-slate-400 group-hover/link:text-[#F28C28] group-hover/link:scale-110' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
                <span>Reports</span>
            </a>
            <?php endif; ?>

            <!-- Settings / Management (Owner Only) -->
            <?php if ($isOwner): ?>
            <a href="manage_users.php" class="group/link relative flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all animate-ast-slide-right [animation-delay:290ms] <?= (in_array($currentPage, ['settings', 'manage_users', 'backup'])) ? 'bg-[#F28C28] text-white shadow-[0_6px_18px_rgba(242,140,40,0.32)] before:content-[\'\'] before:absolute before:-left-4 before:top-1/2 before:-translate-y-1/2 before:w-1 before:h-[60%] before:rounded-r before:bg-[#F28C28]' : 'text-slate-300 hover:text-white hover:bg-[#132E52] hover:translate-x-[3px]' ?>">
                <svg class="w-5 h-5 transition-transform duration-150 <?= (in_array($currentPage, ['settings', 'manage_users', 'backup'])) ? 'text-white' : 'text-slate-400 group-hover/link:text-[#F28C28] group-hover/link:scale-110' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span>Settings</span>
            </a>
            <?php endif; ?>
        </nav>
    </div>

    <!-- Bottom Sidebar Section (Logout) -->
    <div class="p-4 border-t border-[#15345C]/70">
        <a href="logout.php" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold text-slate-300 hover:text-white hover:bg-rose-500/20 hover:text-rose-300 transition-all hover:translate-x-[3px]">
            <svg class="w-5 h-5 text-slate-400 group-hover:text-rose-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
            <span>Logout</span>
        </a>
    </div>
</aside>

<!-- Mobile Bottom Tab Bar (phones & tablets only, matches mobile dashboard wireframe) -->
<nav id="mobile-tabbar" aria-label="Quick navigation" class="lg:hidden print:hidden fixed inset-x-3 bottom-[calc(10px+env(safe-area-inset-bottom))] z-[35] h-16 grid grid-cols-4 items-center bg-[#0C2340] border border-[#15345C] rounded-[20px] shadow-[0_14px_34px_rgba(7,21,38,0.35)] animate-ast-fade-up [animation-delay:200ms]">
    <a href="dashboard.php" class="group/tab flex flex-col items-center justify-center gap-[3px] h-full text-[11px] font-semibold transition-colors <?= ($currentPage === 'dashboard') ? 'text-[#F28C28] after:content-[\'\'] after:w-[5px] after:h-[5px] after:rounded-full after:bg-[#F28C28]' : 'text-slate-400 hover:text-white' ?>">
        <svg class="w-[22px] h-[22px] transition-transform duration-300 group-active/tab:scale-90 <?= ($currentPage === 'dashboard') ? '-translate-y-0.5' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
        <span>Home</span>
    </a>

    <?php if ($isManagerOrOwner): ?>
    <a href="manage_items.php" class="group/tab flex flex-col items-center justify-center gap-[3px] h-full text-[11px] font-semibold transition-colors <?= ($currentPage === 'manage_items') ? 'text-[#F28C28] after:content-[\'\'] after:w-[5px] after:h-[5px] after:rounded-full after:bg-[#F28C28]' : 'text-slate-400 hover:text-white' ?>">
        <svg class="w-[22px] h-[22px] transition-transform duration-300 group-active/tab:scale-90 <?= ($currentPage === 'manage_items') ? '-translate-y-0.5' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
        <span>Items</span>
    </a>
    <?php else: ?>
    <a href="count.php" class="group/tab flex flex-col items-center justify-center gap-[3px] h-full text-[11px] font-semibold transition-colors <?= ($currentPage === 'count') ? 'text-[#F28C28] after:content-[\'\'] after:w-[5px] after:h-[5px] after:rounded-full after:bg-[#F28C28]' : 'text-slate-400 hover:text-white' ?>">
        <svg class="w-[22px] h-[22px] transition-transform duration-300 group-active/tab:scale-90 <?= ($currentPage === 'count') ? '-translate-y-0.5' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
        <span>Count</span>
    </a>
    <?php endif; ?>

    <a href="delivery_intake.php" class="group/tab flex flex-col items-center justify-center gap-[3px] h-full text-[11px] font-semibold transition-colors <?= ($currentPage === 'deliveries') ? 'text-[#F28C28] after:content-[\'\'] after:w-[5px] after:h-[5px] after:rounded-full after:bg-[#F28C28]' : 'text-slate-400 hover:text-white' ?>">
        <svg class="w-[22px] h-[22px] transition-transform duration-300 group-active/tab:scale-90 <?= ($currentPage === 'deliveries') ? '-translate-y-0.5' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
        <span>Deliveries</span>
    </a>

    <button type="button" onclick="toggleSidebar(true)" aria-label="Open full menu" class="group/tab flex flex-col items-center justify-center gap-[3px] h-full text-[11px] font-semibold text-slate-400 hover:text-white transition-colors">
        <svg class="w-[22px] h-[22px] transition-transform duration-300 group-active/tab:scale-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h.01M12 12h.01M19 12h.01M6 12a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0z"/></svg>
        <span>More</span>
    </button>
</nav>