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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Closing Shift Count - Asterisko Inventory</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };

        // Prevent theme flicker
        try {
            const saved = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (saved === 'dark' || (!saved && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {}
    </script>
</head>
<body class="bg-stone-100 dark:bg-stone-950 text-stone-800 dark:text-stone-200 min-h-screen transition-colors">

    <!-- Top Navigation Bar -->
    <nav class="bg-stone-900 dark:bg-black text-white px-4 py-3 flex justify-between items-center border-b border-stone-800">
        <div class="flex items-center gap-3">
            <a href="dashboard.php" class="text-emerald-400 hover:text-emerald-300 text-sm font-semibold flex items-center gap-1">
                &larr; Dashboard
            </a>
            <span class="text-stone-600">|</span>
            <span class="font-bold text-base sm:text-lg">Closing Shift Count</span>
            <span class="text-xs bg-emerald-600 px-2 py-0.5 rounded uppercase font-semibold"><?= htmlspecialchars($role) ?></span>
        </div>
        <div class="flex items-center gap-3 text-sm">
            <span class="hidden sm:inline">Barista: <b><?= htmlspecialchars($username) ?></b></span>

            <!-- Dark Mode Toggle -->
            <button id="theme-toggle" type="button" aria-label="Toggle dark mode"
                    class="p-1.5 rounded-full bg-stone-700 hover:bg-stone-600 text-emerald-300 transition">
                <svg class="w-4 h-4 hidden dark:block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="4"/>
                    <path stroke-linecap="round" d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>
                </svg>
                <svg class="w-4 h-4 block dark:hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>
                </svg>
            </button>

            <a href="logout.php" class="bg-stone-700 hover:bg-stone-600 px-3 py-1 rounded text-xs">Logout</a>
        </div>
    </nav>

    <div class="max-w-4xl mx-auto p-4 space-y-5">

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
                    <h1 class="text-xl font-bold text-stone-900 dark:text-stone-100">End-of-Shift Physical Count</h1>
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
                        <option value="Closing Shift">Closing Shift Count</option>
                        <option value="Opening Shift">Opening Shift Count</option>
                        <option value="Shift Handover">Mid-Shift Handover Count</option>
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
                    <span id="footer-status-text">Ready to record shift count.</span>
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
                                System Recorded: <b class="font-mono text-stone-800 dark:text-stone-200">${systemStock.toFixed(2)} ${escapeHtml(unit)}</b>
                                (ROP: ${parseFloat(item.reorder_point).toFixed(2)})
                            </div>
                        </div>

                        <div class="sm:w-7/12 flex flex-col sm:flex-row items-start sm:items-center gap-2">
                            <!-- Physical Count Input -->
                            <div class="w-full sm:w-36">
                                <label class="block sm:hidden text-[11px] font-semibold text-stone-500">Physical Count</label>
                                <div class="relative">
                                    <input type="number" step="0.01" min="0" 
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

                const variance = parseFloat((physicalCount - systemStock).toFixed(2));
                const sign = variance > 0 ? '+' : '';

                if (variance === 0) {
                    badge.className = "variance-badge block px-2 py-1.5 rounded-lg text-center font-mono font-semibold bg-emerald-100 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-800";
                    badge.textContent = `Match: 0.00`;
                } else if (variance < 0) {
                    badge.className = "variance-badge block px-2 py-1.5 rounded-lg text-center font-mono font-semibold bg-rose-100 dark:bg-rose-950/60 text-rose-800 dark:text-rose-300 border border-rose-300 dark:border-rose-800";
                    badge.textContent = `${variance.toFixed(2)} (Short)`;
                } else {
                    badge.className = "variance-badge block px-2 py-1.5 rounded-lg text-center font-mono font-semibold bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 border border-amber-300 dark:border-amber-800";
                    badge.textContent = `+${variance.toFixed(2)} (Surplus)`;
                }
            }

            // Autofill helper
            btnFillRecorded.addEventListener('click', () => {
                document.querySelectorAll('.input-physical').forEach(input => {
                    input.value = parseFloat(input.dataset.systemStock).toFixed(2);
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
            });

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

            // Dark Mode toggle
            document.getElementById('theme-toggle').addEventListener('click', () => {
                const isDark = document.documentElement.classList.toggle('dark');
                try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch (e) {}
            });

            // Initial render and status check
            renderItems();
            updateNetworkStatus();
        })();
    </script>
</body>
</html>
