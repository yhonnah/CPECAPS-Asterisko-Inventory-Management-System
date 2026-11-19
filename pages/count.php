<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/authguard.php';
requireLogin();

$role = $_SESSION['role'];
$username = $_SESSION['username'];

// Fetch all active stock items for closing count
$items = $pdo->query("
    SELECT id, NAME, NAME AS name, category, quantity, unit, reorder_point 
    FROM stock_items 
    WHERE is_active = 1 
    ORDER BY category, NAME
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php 
    $activePage = 'count';
    $pageTitle = 'Stock Count - Asterisko Inventory';
    require_once __DIR__ . '/../includes/head.php'; 
    ?>
</head>
<body class="bg-[#F4F7FB] dark:bg-[#071526] text-slate-800 dark:text-slate-100 min-h-screen">

    <!-- Left Sidebar -->
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <!-- Main Content Wrapper -->
    <div class="lg:pl-64 flex flex-col min-h-screen">
        
        <!-- Topbar -->
        <?php require_once __DIR__ . '/../includes/topbar.php'; ?>

        <!-- Canvas Body -->
        <main class="flex-1 p-4 sm:p-6 lg:p-8 space-y-6 max-w-5xl w-full mx-auto">

        <!-- Network Connectivity Status Banner (Monitored via navigator.onLine) -->
        <div id="network-banner" class="rounded-xl p-3.5 transition-all duration-300 flex items-center justify-between shadow-sm border">
            <div class="flex items-center gap-2.5">
                <span id="network-indicator" class="relative flex h-3 w-3">
                    <span id="network-ping" class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75"></span>
                    <span id="network-dot" class="relative inline-flex rounded-full h-3 w-3"></span>
                </span>
                <div>
                    <h3 id="network-title" class="font-bold text-xs uppercase tracking-wide"></h3>
                    <p id="network-desc" class="text-xs"></p>
                </div>
            </div>
            <div id="pending-queue-badge" class="hidden">
                <span class="bg-emerald-600 text-white text-xs font-bold px-2.5 py-1 rounded-full shadow-sm flex items-center gap-1">
                    <span id="pending-count">0</span> queued
                </span>
            </div>
        </div>

        <!-- Notification Toast Box -->
        <div id="alert-box" class="hidden p-4 rounded-xl text-sm font-medium border transition-all"></div>

        <!-- Closing Count Header Card -->
        <div class="bg-white dark:bg-stone-900 rounded-xl shadow p-5 border border-stone-200 dark:border-stone-800">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-stone-200 dark:border-stone-800 pb-4">
                <div>
                    <h1 class="text-xl font-bold text-stone-900 dark:text-stone-100">Physical Stock Count</h1>
                    <p class="text-xs text-stone-500 dark:text-stone-400 mt-0.5">
                        Input actual physical quantities. Discrepancies will be automatically calculated as variance and recorded to the immutable audit log.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button id="btn-sync-queue" type="button" class="hidden bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold px-3 py-2 rounded-lg transition flex items-center gap-1 shadow">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        Sync Offline Queue
                    </button>
                    <button id="btn-fill-recorded" type="button" class="bg-stone-200 hover:bg-stone-300 dark:bg-stone-800 dark:hover:bg-stone-700 text-stone-800 dark:text-stone-200 text-xs font-semibold px-3 py-2 rounded-lg transition">
                        Autofill Current Stock
                    </button>
                </div>
            </div>

            <!-- Global Shift Reason / Batch Notes -->
            <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase text-stone-600 dark:text-stone-400 mb-1">Shift Type *</label>
                    <select id="shift-type" class="w-full p-2.5 text-xs border border-stone-300 dark:border-stone-700 rounded-lg bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:ring-2 focus:ring-emerald-600 focus:outline-none font-medium">
                        <option value="Closing Shift">Closing Stock Count</option>
                        <option value="Opening Shift">Opening Stock Count</option>
                        <option value="Shift Handover">Shift Handover Stock Count</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold uppercase text-stone-600 dark:text-stone-400 mb-1">Shift Notes / Reason for Count</label>
                    <input type="text" id="batch-reason" placeholder="e.g., Regular turnover, count verification, discrepancy review"
                           class="w-full p-2.5 text-xs border border-stone-300 dark:border-stone-700 rounded-lg bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:ring-2 focus:ring-emerald-600 focus:outline-none">
                </div>
            </div>
        </div>

        <!-- Stock Items Count Form -->
        <form id="count-form" class="space-y-4">
            <div class="bg-white dark:bg-stone-900 rounded-xl shadow overflow-hidden border border-stone-200 dark:border-stone-800">
                <div class="p-4 border-b border-stone-200 dark:border-stone-800 flex justify-between items-center">
                    <span class="text-xs uppercase font-bold text-stone-500 dark:text-stone-400 tracking-wider">Item Details & Variance Calculation</span>
                    <span id="items-count-badge" class="text-xs font-semibold bg-stone-100 dark:bg-stone-800 px-2 py-0.5 rounded text-stone-600 dark:text-stone-400">
                        <?= count($items) ?> items loaded
                    </span>
                </div>

                <div class="divide-y divide-stone-200 dark:divide-stone-800" id="items-container">
                    <!-- Populated dynamically via JS from PHP array or localStorage cache -->
                </div>
            </div>

            <!-- Form Action Footer -->
            <div class="bg-white dark:bg-stone-900 rounded-xl shadow p-4 border border-stone-200 dark:border-stone-800 flex flex-col sm:flex-row justify-between items-center gap-3">
                <div class="text-xs text-stone-500 dark:text-stone-400">
                    <span id="footer-status-text">Ready to record stock count.</span>
                </div>
                <div class="flex items-center gap-3 w-full sm:w-auto">
                    <button type="button" id="btn-reset" class="w-1/2 sm:w-auto px-4 py-2 border border-stone-300 dark:border-stone-700 rounded-lg text-xs font-semibold text-stone-700 dark:text-stone-300 hover:bg-stone-100 dark:hover:bg-stone-800 transition">
                        Reset Inputs
                    </button>
                    <button type="submit" id="btn-submit" class="w-1/2 sm:w-auto px-6 py-2.5 bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg text-sm font-semibold transition shadow flex items-center justify-center gap-2">
                        <span id="btn-submit-text">Submit Closing Count</span>
                    </button>
                </div>
            </div>
        </form>

        <!-- Offline Queue Drawer / History Card -->
        <div id="queue-card" class="hidden bg-stone-50 dark:bg-stone-900/50 rounded-xl p-4 border border-stone-200 dark:border-stone-800 text-xs">
            <div class="flex justify-between items-center mb-2">
                <span class="font-bold text-stone-700 dark:text-stone-300 uppercase">Local Offline Queue Details</span>
                <button id="btn-clear-queue" type="button" class="text-red-600 hover:underline">Clear Local Queue</button>
            </div>
            <pre id="queue-details" class="bg-stone-100 dark:bg-stone-950 p-3 rounded text-stone-700 dark:text-stone-300 overflow-x-auto text-[11px]"></pre>
        </div>

    </div>

    <!-- Extreme Variance (>100%) Confirmation Modal (TC-47) -->
    <div id="extreme-variance-modal" class="fixed inset-0 z-50 hidden bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#0D2137] border border-amber-300 dark:border-amber-700 rounded-2xl max-w-lg w-full shadow-2xl overflow-hidden">
            <div class="p-5 bg-amber-50 dark:bg-amber-950/50 border-b border-amber-200 dark:border-amber-800 flex items-start gap-3">
                <div class="w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 flex items-center justify-center shrink-0 font-bold text-lg">!</div>
                <div>
                    <h3 class="text-base font-bold text-amber-950 dark:text-amber-200">Extreme Stock Variance Detected (&gt; 100%)</h3>
                    <p class="text-xs text-amber-800 dark:text-amber-300 mt-1">One or more items differ by more than 100% from system recorded stock. Please double-check your count before committing.</p>
                </div>
            </div>
            <div class="p-5 space-y-4">
                <div id="extreme-variance-list" class="max-h-44 overflow-y-auto space-y-2 text-xs"></div>
                <div>
                    <label for="extreme-variance-note" class="block text-xs font-semibold text-stone-700 dark:text-stone-300 mb-1">Mandatory Explanation / Verification Note</label>
                    <input type="text" id="extreme-variance-note" placeholder="e.g., Recounted full unopened sack, prior log missed" class="w-full p-2.5 text-sm border border-stone-300 dark:border-stone-700 rounded-lg bg-white dark:bg-stone-900 text-stone-900 dark:text-stone-100">
                </div>
                <label class="flex items-start gap-2 text-xs text-stone-700 dark:text-stone-300 cursor-pointer">
                    <input type="checkbox" id="extreme-variance-ack" class="mt-0.5 rounded border-stone-300 text-amber-600 focus:ring-amber-500">
                    <span>I confirm these extreme variance figures were physically recounted and verified.</span>
                </label>
                <p id="extreme-variance-err" class="hidden text-xs text-rose-600 dark:text-rose-400 font-semibold"></p>
            </div>
            <div class="px-5 py-3.5 bg-stone-50 dark:bg-stone-900/60 border-t border-stone-200 dark:border-stone-800 flex justify-end gap-2">
                <button type="button" id="btn-cancel-extreme" class="px-4 py-2 text-xs font-semibold rounded-lg border border-stone-300 dark:border-stone-700 text-stone-700 dark:text-stone-200 hover:bg-stone-100 dark:hover:bg-stone-800">Review Inputs</button>
                <button type="button" id="btn-confirm-extreme" class="px-4 py-2 text-xs font-semibold rounded-lg bg-amber-600 hover:bg-amber-700 text-white shadow">Confirm &amp; Submit Count</button>
            </div>
        </div>
    </div>

    <!-- Pass server items into JavaScript -->
    <script>
        const SERVER_ITEMS = <?= json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const CURRENT_USER = <?= json_encode($username) ?>;
        const CSRF_TOKEN = <?= json_encode(getCsrfToken()) ?>;
    </script>

    <!-- Offline logic and navigator.onLine monitoring -->
    <script>
        (function() {
            // Storage keys
            const CACHE_ITEMS_KEY = 'asterisko_cached_stock_items';
            const QUEUE_KEY = 'asterisko_offline_sync_queue';

            // Cache server items into localStorage for full offline availability
            if (Array.isArray(SERVER_ITEMS) && SERVER_ITEMS.length > 0) {
                localStorage.setItem(CACHE_ITEMS_KEY, JSON.stringify(SERVER_ITEMS));
            }

            // Retrieve items from server payload or fallback to offline cached items
            function getCatalogItems() {
                if (Array.isArray(SERVER_ITEMS) && SERVER_ITEMS.length > 0) {
                    return SERVER_ITEMS;
                }
                const cached = localStorage.getItem(CACHE_ITEMS_KEY);
                return cached ? JSON.parse(cached) : [];
            }

            // Offline queue helpers
            function getOfflineQueue() {
                const q = localStorage.getItem(QUEUE_KEY);
                return q ? JSON.parse(q) : [];
            }

            function saveOfflineQueue(queue) {
                localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
                updateQueueUI();
            }

            // UI Elements
            const networkBanner = document.getElementById('network-banner');
            const networkPing = document.getElementById('network-ping');
            const networkDot = document.getElementById('network-dot');
            const networkTitle = document.getElementById('network-title');
            const networkDesc = document.getElementById('network-desc');
            const pendingBadge = document.getElementById('pending-queue-badge');
            const pendingCountEl = document.getElementById('pending-count');
            const btnSyncQueue = document.getElementById('btn-sync-queue');
            const queueCard = document.getElementById('queue-card');
            const queueDetails = document.getElementById('queue-details');
            const alertBox = document.getElementById('alert-box');
            const itemsContainer = document.getElementById('items-container');
            const countForm = document.getElementById('count-form');
            const btnSubmit = document.getElementById('btn-submit');
            const btnSubmitText = document.getElementById('btn-submit-text');
            const btnFillRecorded = document.getElementById('btn-fill-recorded');
            const btnReset = document.getElementById('btn-reset');
            const btnClearQueue = document.getElementById('btn-clear-queue');
            const batchReasonInput = document.getElementById('batch-reason');
            const footerStatusText = document.getElementById('footer-status-text');

            // 1. Monitor network connectivity using navigator.onLine
            function updateNetworkStatus() {
                const isOnline = navigator.onLine;

                if (isOnline) {
                    // Online styling
                    networkBanner.className = "rounded-xl p-3.5 transition-all duration-300 flex items-center justify-between shadow-sm border bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-800 text-emerald-900 dark:text-emerald-200";
                    networkPing.className = "animate-ping absolute inline-flex h-full w-full rounded-full opacity-75 bg-emerald-400";
                    networkDot.className = "relative inline-flex rounded-full h-3 w-3 bg-emerald-500";
                    networkTitle.textContent = "Online Connection Active";
                    networkDesc.textContent = "Connected to Asterisko server. Closing counts will sync immediately.";
                    footerStatusText.textContent = "Online. Submissions sync directly to database and audit trail.";

                    // Auto-sync pending queue if items exist
                    const queue = getOfflineQueue();
                    if (queue.length > 0) {
                        btnSyncQueue.classList.remove('hidden');
                        triggerQueueSync();
                    } else {
                        btnSyncQueue.classList.add('hidden');
                    }
                } else {
                    // Offline styling
                    networkBanner.className = "rounded-xl p-3.5 transition-all duration-300 flex items-center justify-between shadow-sm border bg-amber-50 dark:bg-amber-950/40 border-amber-300 dark:border-amber-700 text-amber-900 dark:text-amber-200";
                    networkPing.className = "hidden";
                    networkDot.className = "relative inline-flex rounded-full h-3 w-3 bg-amber-500";
                    networkTitle.textContent = "Offline Mode (navigator.onLine = false)";
                    networkDesc.textContent = "No internet connection detected. Counts will be queued locally and synced once reconnected.";
                    btnSyncQueue.classList.add('hidden');
                    footerStatusText.textContent = "Offline mode active. Submitting will store data in safe local storage.";
                }

                updateQueueUI();
            }

            // Listen for browser online / offline network events
            window.addEventListener('online', () => {
                showAlert('success', 'Network restored! You are back online. Checking pending sync queue...');
                updateNetworkStatus();
            });

            window.addEventListener('offline', () => {
                showAlert('warning', 'Network connection lost. Switched to local offline mode.');
                updateNetworkStatus();
            });

            // 2. Render item list with real-time variance calculation
            const catalog = getCatalogItems();

            function renderItems() {
                itemsContainer.innerHTML = '';

                if (catalog.length === 0) {
                    itemsContainer.innerHTML = `
                        <div class="p-6 text-center text-sm text-stone-500">
                            No stock items found. Please verify your connection or catalog.
                        </div>
                    `;
                    return;
                }

                catalog.forEach(item => {
                    const row = document.createElement('div');
                    row.className = "p-4 sm:flex sm:items-center sm:justify-between gap-4 hover:bg-stone-50/50 dark:hover:bg-stone-900/50 transition";
                    row.dataset.itemId = item.id;

                    const systemStock = parseFloat(item.quantity);
                    const unit = item.unit || '';

                    row.innerHTML = `
                        <div class="sm:w-5/12 mb-2 sm:mb-0">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-stone-900 dark:text-stone-100">${escapeHtml(item.NAME || item.name)}</span>
                                <span class="text-[10px] uppercase font-semibold bg-stone-200 dark:bg-stone-800 text-stone-700 dark:text-stone-300 px-1.5 py-0.5 rounded">
                                    ${escapeHtml(item.category || 'General')}
                                </span>
                            </div>
                            <div class="text-xs text-stone-500 dark:text-stone-400 mt-0.5">
                                System Recorded: <b class="font-mono text-stone-800 dark:text-stone-200">${Number(systemStock.toFixed(4))} ${escapeHtml(unit)}</b>
                                (ROP: ${Number(parseFloat(item.reorder_point).toFixed(4))})
                            </div>
                        </div>

                        <div class="sm:w-7/12 flex flex-col sm:flex-row items-start sm:items-center gap-2">
                            <!-- Physical Count Input -->
                            <div class="w-full sm:w-36">
                                <label class="block sm:hidden text-[11px] font-semibold text-stone-500">Physical Count</label>
                                <div class="relative">
                                    <input type="number" step="0.0001" min="0" 
                                           data-item-id="${item.id}"
                                           data-system-stock="${systemStock}"
                                           placeholder="0.00" 
                                           class="input-physical w-full p-2 text-sm font-mono border border-stone-300 dark:border-stone-700 rounded-lg bg-white dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:ring-2 focus:ring-emerald-600 focus:outline-none">
                                    <span class="absolute right-2 top-2 text-xs text-stone-400 pointer-events-none">${escapeHtml(unit)}</span>
                                </div>
                            </div>

                            <!-- Real-time Variance Badge -->
                            <div class="w-full sm:w-36 text-xs">
                                <span class="variance-badge block px-2 py-1.5 rounded-lg text-center font-mono font-semibold bg-stone-100 dark:bg-stone-800 text-stone-500 dark:text-stone-400">
                                    Variance: —
                                </span>
                            </div>

                            <!-- Item-specific note -->
                            <div class="w-full sm:flex-1">
                                <input type="text" 
                                       data-item-id="${item.id}"
                                       placeholder="Notes (optional, e.g. spill)"
                                       class="input-note w-full p-2 text-xs border border-stone-300 dark:border-stone-700 rounded-lg bg-white dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:ring-2 focus:ring-emerald-600 focus:outline-none">
                            </div>
                        </div>
                    `;

                    itemsContainer.appendChild(row);
                });

                attachInputListeners();
            }

            // Real-time variance calculation on input change
            function attachInputListeners() {
                const inputs = document.querySelectorAll('.input-physical');
                inputs.forEach(input => {
                    input.addEventListener('input', () => {
                        calculateRowVariance(input);
                    });
                });
            }

            function calculateRowVariance(input) {
                const row = input.closest('[data-item-id]');
                const badge = row.querySelector('.variance-badge');
                const systemStock = parseFloat(input.dataset.systemStock);
                const rawVal = input.value.trim();

                if (rawVal === '') {
                    badge.className = "variance-badge block px-2 py-1.5 rounded-lg text-center font-mono font-semibold bg-stone-100 dark:bg-stone-800 text-stone-500 dark:text-stone-400";
                    badge.textContent = "Variance: —";
                    return;
                }

                const physicalCount = parseFloat(rawVal);
                if (isNaN(physicalCount)) {
                    badge.textContent = "Invalid";
                    return;
                }

                const variance = parseFloat((physicalCount - systemStock).toFixed(4));
                const sign = variance > 0 ? '+' : '';

                if (variance === 0) {
                    badge.className = "variance-badge block px-2 py-1.5 rounded-lg text-center font-mono font-semibold bg-emerald-100 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-800";
                    badge.textContent = `Match: 0.00`;
                } else if (variance < 0) {
                    badge.className = "variance-badge block px-2 py-1.5 rounded-lg text-center font-mono font-semibold bg-rose-100 dark:bg-rose-950/60 text-rose-800 dark:text-rose-300 border border-rose-300 dark:border-rose-800";
                    badge.textContent = `${Number(variance.toFixed(4))} (Short)`;
                } else {
                    badge.className = "variance-badge block px-2 py-1.5 rounded-lg text-center font-mono font-semibold bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 border border-amber-300 dark:border-amber-800";
                    badge.textContent = `+${Number(variance.toFixed(4))} (Surplus)`;
                }
            }

            // Autofill helper
            btnFillRecorded.addEventListener('click', () => {
                document.querySelectorAll('.input-physical').forEach(input => {
                    input.value = Number(parseFloat(input.dataset.systemStock).toFixed(4));
                    calculateRowVariance(input);
                });
            });

            // Reset helper
            btnReset.addEventListener('click', () => {
                document.querySelectorAll('.input-physical').forEach(input => {
                    input.value = '';
                    calculateRowVariance(input);
                });
                document.querySelectorAll('.input-note').forEach(input => input.value = '');
            });

            // 3. Form Submission (Online direct sync or Offline queueing)
            countForm.addEventListener('submit', async function(e) {
                e.preventDefault();

                const entries = [];
                const physicalInputs = document.querySelectorAll('.input-physical');
                const noteInputs = document.querySelectorAll('.input-note');

                const noteMap = {};
                noteInputs.forEach(n => {
                    noteMap[n.dataset.itemId] = n.value.trim();
                });

                physicalInputs.forEach(input => {
                    const rawVal = input.value.trim();
                    if (rawVal !== '') {
                        const count = parseFloat(rawVal);
                        if (!isNaN(count) && count >= 0) {
                            const itemId = parseInt(input.dataset.itemId, 10);
                            entries.push({
                                item_id: itemId,
                                physical_count: count,
                                notes: noteMap[itemId] || ''
                            });
                        }
                    }
                });

                if (entries.length === 0) {
                    showAlert('warning', 'Please fill in at least one item count before submitting.');
                    return;
                }

                const shiftType = document.getElementById('shift-type') ? document.getElementById('shift-type').value : 'Closing Shift';
                const userNotes = batchReasonInput.value.trim();
                const shiftReason = userNotes ? `[${shiftType}] ${userNotes}` : `[${shiftType}] Physical count`;

                const payload = {
                    acting_user: CURRENT_USER,
                    reason: shiftReason,
                    items: entries,
                    timestamp: new Date().toISOString()
                };

                // Detect Extreme Variance (>100% of system stock) safeguard (TC-47)
                const extremeItems = [];
                entries.forEach(entry => {
                    const catalogItem = catalog.find(c => parseInt(c.id, 10) === entry.item_id);
                    const sysStock = catalogItem ? parseFloat(catalogItem.quantity) : 0;
                    const diff = Math.abs(entry.physical_count - sysStock);
                    const pctDiff = sysStock > 0 ? (diff / sysStock) * 100 : (diff > 0 ? 999 : 0);
                    if (pctDiff > 100) {
                        extremeItems.push({
                            name: catalogItem ? (catalogItem.NAME || catalogItem.name) : `Item #${entry.item_id}`,
                            unit: catalogItem ? catalogItem.unit : '',
                            sysStock,
                            physicalCount: entry.physical_count,
                            pctDiff: Math.round(pctDiff)
                        });
                    }
                });

                if (extremeItems.length > 0) {
                    const confirmed = await promptExtremeVarianceModal(extremeItems, payload);
                    if (!confirmed) {
                        return;
                    }
                }

                await dispatchSyncPayload(payload);
            });

            function promptExtremeVarianceModal(extremeItems, payload) {
                return new Promise((resolve) => {
                    const modal = document.getElementById('extreme-variance-modal');
                    const list = document.getElementById('extreme-variance-list');
                    const noteEl = document.getElementById('extreme-variance-note');
                    const ackEl = document.getElementById('extreme-variance-ack');
                    const errEl = document.getElementById('extreme-variance-err');
                    const btnCancel = document.getElementById('btn-cancel-extreme');
                    const btnConfirm = document.getElementById('btn-confirm-extreme');

                    list.innerHTML = extremeItems.map(item => `
                        <div class="p-2.5 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 flex justify-between items-center gap-2">
                            <div>
                                <div class="font-bold text-stone-900 dark:text-stone-100">${escapeHtml(item.name)}</div>
                                <div class="text-stone-600 dark:text-stone-400">System: ${Number(item.sysStock.toFixed(4))} ${escapeHtml(item.unit)} &rarr; Physical: <b>${Number(item.physicalCount.toFixed(4))} ${escapeHtml(item.unit)}</b></div>
                            </div>
                            <span class="px-2 py-0.5 rounded bg-amber-200 dark:bg-amber-900 text-amber-950 dark:text-amber-200 font-mono font-bold">${item.pctDiff > 900 ? '>100%' : item.pctDiff + '%'}</span>
                        </div>
                    `).join('');

                    noteEl.value = '';
                    ackEl.checked = false;
                    errEl.classList.add('hidden');
                    modal.classList.remove('hidden');

                    const cleanup = () => {
                        modal.classList.add('hidden');
                        btnCancel.onclick = null;
                        btnConfirm.onclick = null;
                    };

                    btnCancel.onclick = () => {
                        cleanup();
                        resolve(false);
                    };

                    btnConfirm.onclick = () => {
                        const verifyNote = noteEl.value.trim();
                        if (!ackEl.checked || verifyNote.length < 3) {
                            errEl.textContent = 'Check the confirmation box and enter a verification reason (min 3 chars).';
                            errEl.classList.remove('hidden');
                            return;
                        }
                        payload.supervisor_acknowledged = true;
                        payload.reason = `${payload.reason} [Verified Extreme Variance: ${verifyNote}]`;
                        payload.items.forEach(it => {
                            if (!it.notes) {
                                it.notes = `Extreme variance verified: ${verifyNote}`;
                            }
                        });
                        cleanup();
                        resolve(true);
                    };
                });
            }

            async function dispatchSyncPayload(payload) {
                // Check connectivity
                if (navigator.onLine) {
                    setSubmitting(true);
                    try {
                        const result = await sendSyncPayload(payload);
                        showAlert('success', `Success! Synchronized ${result.summary.total_items} items. Discrepancies logged: ${result.summary.discrepancies}.`);
                        
                        // Update local system recorded stock with new values
                        updateCatalogFromResults(result.results);
                        renderItems();
                    } catch (err) {
                        console.warn("Direct sync failed. Enqueueing to offline storage:", err);
                        enqueueOffline(payload);
                        showAlert('warning', `Network glitch during sync (${err.message}). Count has been safely saved to your offline queue.`);
                    } finally {
                        setSubmitting(false);
                    }
                } else {
                    // Fully offline
                    enqueueOffline(payload);
                    showAlert('warning', `You are currently offline. Count safely stored in local queue. It will auto-sync when connection returns.`);
                }
            }

            // Send payload to backend sync.php
            async function sendSyncPayload(payload) {
                const response = await fetch('../api/sync.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify(payload)
                });

                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.error || 'Server rejected synchronization request.');
                }
                return data;
            }

            // Enqueue into localStorage
            function enqueueOffline(payload) {
                const queue = getOfflineQueue();
                queue.push(payload);
                saveOfflineQueue(queue);
            }

            // Background queue synchronization when reconnected
            async function triggerQueueSync() {
                const queue = getOfflineQueue();
                if (queue.length === 0 || !navigator.onLine) return;

                btnSyncQueue.disabled = true;
                btnSyncQueue.textContent = "Syncing...";

                let successCount = 0;
                const remainingQueue = [];

                for (let i = 0; i < queue.length; i++) {
                    const itemPayload = queue[i];
                    try {
                        const res = await sendSyncPayload(itemPayload);
                        successCount++;
                        updateCatalogFromResults(res.results);
                    } catch (err) {
                        console.error("Queue item failed to sync:", err);
                        remainingQueue.push(itemPayload);
                    }
                }

                saveOfflineQueue(remainingQueue);
                btnSyncQueue.disabled = false;
                btnSyncQueue.innerHTML = `
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    Sync Offline Queue
                `;

                if (successCount > 0) {
                    showAlert('success', `Offline queue processed: Synchronized ${successCount} queued closing count submission(s).`);
                    renderItems();
                }

                updateNetworkStatus();
            }

            btnSyncQueue.addEventListener('click', triggerQueueSync);

            // Update UI for offline queue
            function updateQueueUI() {
                const queue = getOfflineQueue();
                if (queue.length > 0) {
                    pendingBadge.classList.remove('hidden');
                    pendingCountEl.textContent = queue.length;
                    queueCard.classList.remove('hidden');
                    queueDetails.textContent = JSON.stringify(queue, null, 2);
                    if (navigator.onLine) {
                        btnSyncQueue.classList.remove('hidden');
                    }
                } else {
                    pendingBadge.classList.add('hidden');
                    queueCard.classList.add('hidden');
                    btnSyncQueue.classList.add('hidden');
                }
            }

            btnClearQueue.addEventListener('click', () => {
                if (confirm("Are you sure you want to discard unsynced offline counts?")) {
                    saveOfflineQueue([]);
                    showAlert('info', 'Offline queue has been cleared.');
                }
            });

            // Update catalog in memory & localStorage after successful sync
            function updateCatalogFromResults(syncedItems) {
                if (!Array.isArray(syncedItems)) return;
                const map = {};
                syncedItems.forEach(si => { map[si.item_id] = si.physical_count; });

                catalog.forEach(item => {
                    if (map[item.id] !== undefined) {
                        item.quantity = map[item.id];
                    }
                });

                localStorage.setItem(CACHE_ITEMS_KEY, JSON.stringify(catalog));
            }

            // Notification alert helper
            function showAlert(type, message) {
                alertBox.className = "p-4 rounded-xl text-sm font-medium border transition-all block";
                if (type === 'success') {
                    alertBox.className += " bg-emerald-50 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-200 border-emerald-300 dark:border-emerald-800";
                } else if (type === 'warning') {
                    alertBox.className += " bg-amber-50 dark:bg-amber-950/60 text-amber-800 dark:text-amber-200 border-amber-300 dark:border-amber-800";
                } else {
                    alertBox.className += " bg-blue-50 dark:bg-blue-950/60 text-blue-800 dark:text-blue-200 border-blue-300 dark:border-blue-800";
                }
                alertBox.textContent = message;
                alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            function setSubmitting(isSubmitting) {
                btnSubmit.disabled = isSubmitting;
                btnSubmitText.textContent = isSubmitting ? "Syncing..." : "Submit Closing Count";
            }

            function escapeHtml(str) {
                if (!str) return '';
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            // Dark Mode toggle (topbar handles toggleTheme() on logged-in pages)
            const themeToggleBtn = document.getElementById('theme-toggle');
            if (themeToggleBtn) {
                themeToggleBtn.addEventListener('click', () => {
                    const isDark = document.documentElement.classList.toggle('dark');
                    try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch (e) {}
                });
            }

            // Initial render and status check
            renderItems();
            updateNetworkStatus();
        })();
    </script>
</body>
</html>
