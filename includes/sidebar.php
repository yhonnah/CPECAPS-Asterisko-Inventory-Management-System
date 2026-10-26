<?php
// includes/sidebar.php
// Reusable navigation sidebar matching Asterisko Canva design
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$currentUserRole = $_SESSION['user_role'] ?? 'Staff';
$currentUsername = $_SESSION['username'] ?? 'User';
$currentPage = $activePage ?? '';
?>

<!-- Mobile Sidebar Backdrop -->
<div id="sidebar-backdrop" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 hidden lg:hidden" onclick="toggleSidebar(false)"></div>

<!-- Main Left Sidebar -->
<aside id="main-sidebar" class="fixed top-0 left-0 h-screen w-64 bg-[#0C2340] text-white flex flex-col justify-between z-50 transition-transform duration-300 ease-in-out -translate-x-full lg:translate-x-0 border-r border-[#15345C] shadow-xl">
    <div>
        <!-- Brand Header -->
        <div class="px-6 py-6 border-b border-[#15345C]/70 flex items-center justify-between">
            <a href="dashboard.php" class="flex items-center gap-3 group">
                <!-- 8-Point Asterisko Starburst Logo -->
                <div class="w-10 h-10 rounded-xl bg-[#F28C28]/15 flex items-center justify-center border border-[#F28C28]/30 group-hover:scale-105 transition-transform">
                    <svg class="w-6 h-6 text-[#F28C28]" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2a1.25 1.25 0 0 1 1.25 1.25v3.3a1.25 1.25 0 1 1-2.5 0v-3.3A1.25 1.25 0 0 1 12 2zm0 14.2a1.25 1.25 0 0 1 1.25 1.25v3.3a1.25 1.25 0 1 1-2.5 0v-3.3A1.25 1.25 0 0 1 12 16.2zM20.75 10.75a1.25 1.25 0 0 1 0 2.5h-3.3a1.25 1.25 0 1 1 0-2.5h3.3zm-14.2 0a1.25 1.25 0 0 1 0 2.5h-3.3a1.25 1.25 0 1 1 0-2.5h3.3zm11.66-5.83a1.25 1.25 0 0 1 0 1.77l-2.33 2.33a1.25 1.25 0 1 1-1.77-1.77l2.33-2.33a1.25 1.25 0 0 1 1.77 0zm-10.05 10.05a1.25 1.25 0 0 1 0 1.77l-2.33 2.33a1.25 1.25 0 1 1-1.77-1.77l2.33-2.33a1.25 1.25 0 0 1 1.77 0zm10.05 0a1.25 1.25 0 0 1 1.77 1.77l-2.33 2.33a1.25 1.25 0 1 1-1.77-1.77l2.33-2.33zm-10.05-10.05a1.25 1.25 0 0 1 1.77-1.77l2.33 2.33a1.25 1.25 0 1 1-1.77 1.77l-2.33-2.33z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-lg font-black tracking-wider text-white uppercase leading-none">ASTERISKO</h1>
                    <span class="text-[10px] text-slate-400 font-medium tracking-tight">Inventory Management</span>
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
            <a href="dashboard.php" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all <?= ($currentPage === 'dashboard') ? 'bg-[#F28C28] text-white shadow-lg shadow-[#F28C28]/25' : 'text-slate-300 hover:text-white hover:bg-[#132E52]' ?>">
                <svg class="w-5 h-5 <?= ($currentPage === 'dashboard') ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                <span>Dashboard</span>
            </a>

            <!-- Manage Items -->
            <a href="manage_items.php" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all <?= ($currentPage === 'manage_items') ? 'bg-[#F28C28] text-white shadow-lg shadow-[#F28C28]/25' : 'text-slate-300 hover:text-white hover:bg-[#132E52]' ?>">
                <svg class="w-5 h-5 <?= ($currentPage === 'manage_items') ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
                <span>Manage Items</span>
            </a>

            <!-- Stock Count -->
            <a href="count.php" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all <?= ($currentPage === 'count') ? 'bg-[#F28C28] text-white shadow-lg shadow-[#F28C28]/25' : 'text-slate-300 hover:text-white hover:bg-[#132E52]' ?>">
                <svg class="w-5 h-5 <?= ($currentPage === 'count') ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                </svg>
                <span>Stock Count</span>
            </a>

            <!-- Deliveries & Intake -->
            <a href="delivery_intake.php" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all <?= ($currentPage === 'deliveries') ? 'bg-[#F28C28] text-white shadow-lg shadow-[#F28C28]/25' : 'text-slate-300 hover:text-white hover:bg-[#132E52]' ?>">
                <svg class="w-5 h-5 <?= ($currentPage === 'deliveries') ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/>
                </svg>
                <span>Deliveries</span>
            </a>

            <!-- Purchase Orders -->
            <a href="purchase_orders.php" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all <?= ($currentPage === 'purchase_orders') ? 'bg-[#F28C28] text-white shadow-lg shadow-[#F28C28]/25' : 'text-slate-300 hover:text-white hover:bg-[#132E52]' ?>">
                <svg class="w-5 h-5 <?= ($currentPage === 'purchase_orders') ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <span>Purchase Orders</span>
            </a>

            <!-- Suppliers -->
            <a href="manage_suppliers.php" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all <?= ($currentPage === 'suppliers') ? 'bg-[#F28C28] text-white shadow-lg shadow-[#F28C28]/25' : 'text-slate-300 hover:text-white hover:bg-[#132E52]' ?>">
                <svg class="w-5 h-5 <?= ($currentPage === 'suppliers') ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
                <span>Suppliers</span>
            </a>

            <!-- Waste & Spoilage -->
            <a href="waste.php" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all <?= ($currentPage === 'waste') ? 'bg-[#F28C28] text-white shadow-lg shadow-[#F28C28]/25' : 'text-slate-300 hover:text-white hover:bg-[#132E52]' ?>">
                <svg class="w-5 h-5 <?= ($currentPage === 'waste') ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
                <span>Waste</span>
            </a>

            <!-- Reports -->
            <a href="financial_report.php" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all <?= (in_array($currentPage, ['reports', 'financial_report', 'variance_report'])) ? 'bg-[#F28C28] text-white shadow-lg shadow-[#F28C28]/25' : 'text-slate-300 hover:text-white hover:bg-[#132E52]' ?>">
                <svg class="w-5 h-5 <?= (in_array($currentPage, ['reports', 'financial_report', 'variance_report'])) ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
                <span>Reports</span>
            </a>

            <!-- Settings / Management (Owner / Manager) -->
            <?php if ($currentUserRole === 'Owner' || $currentUserRole === 'Manager'): ?>
            <a href="manage_users.php" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all <?= (in_array($currentPage, ['settings', 'manage_users', 'backup'])) ? 'bg-[#F28C28] text-white shadow-lg shadow-[#F28C28]/25' : 'text-slate-300 hover:text-white hover:bg-[#132E52]' ?>">
                <svg class="w-5 h-5 <?= (in_array($currentPage, ['settings', 'manage_users', 'backup'])) ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
        <a href="logout.php" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-sm font-semibold text-slate-300 hover:text-white hover:bg-rose-500/20 hover:text-rose-300 transition-colors">
            <svg class="w-5 h-5 text-slate-400 group-hover:text-rose-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
            <span>Logout</span>
        </a>
    </div>
</aside>
