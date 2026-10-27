/**
 * Asterisko Offline Engine (Dexie.js IndexedDB Store & Sync Outbox)
 * Manages local stock balances, recipe lookups, and offline-to-cloud synchronization.
 */

// 1. Initialize Dexie IndexedDB
const offlineDb = new Dexie('AsteriskoOfflineDB');
offlineDb.version(1).stores({
    stock_items: 'id, name, category, quantity, unit, reorder_point',
    menu_items: 'id, name, category, price',
    outbox: '++id, uuid, action, created_at, status',
    order_history: '++id, order_number, order_type, total_amount, items_count, items_summary, created_at, is_offline'
});

// 2. Hydrate / Refresh Local IndexedDB with Latest Server Data
async function hydrateLocalDatabase(menuItems = [], stockItems = []) {
    try {
        if (Array.isArray(stockItems) && stockItems.length > 0) {
            await offlineDb.transaction('rw', offlineDb.stock_items, async () => {
                for (const item of stockItems) {
                    await offlineDb.stock_items.put({
                        id: parseInt(item.id),
                        name: item.NAME || item.name || '',
                        category: item.category || 'General',
                        quantity: parseFloat(item.quantity) || 0,
                        unit: item.unit || 'units',
                        reorder_point: parseFloat(item.reorder_point) || 0
                    });
                }
            });
        }

        if (Array.isArray(menuItems) && menuItems.length > 0) {
            await offlineDb.transaction('rw', offlineDb.menu_items, async () => {
                for (const drink of menuItems) {
                    await offlineDb.menu_items.put({
                        id: parseInt(drink.id),
                        name: drink.name,
                        category: drink.category,
                        price: parseFloat(drink.price) || 0,
                        ingredients: drink.ingredients || []
                    });
                }
            });
        }
        console.info('✓ Local IndexedDB hydrated with latest server catalog.');
    } catch (err) {
        console.warn('Could not hydrate local IndexedDB:', err);
    }
}

// 3. Queue an Offline Coffee Order (Deducts stock locally and queues for sync)
async function queueOfflineCoffeeOrder(orderData) {
    const uuid = 'ord_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6);
    const timestamp = new Date().toISOString().replace('T', ' ').substr(0, 19);

    // Order payload details
    const orderRecord = {
        uuid: uuid,
        action: 'coffee_order',
        order_number: orderData.orderNumber || ('ORD-OFFLINE-' + Date.now().toString().slice(-6)),
        items: orderData.items, // Array of { id, name, qty, price, ingredients }
        total_amount: orderData.totalAmount || 0,
        items_count: orderData.itemsCount || 1,
        notes: orderData.notes || 'Offline POS Order',
        served_by: orderData.servedBy || 'Barista',
        created_at: timestamp,
        status: 'pending'
    };

    // Atomic transaction: Deduct required ingredients from local IndexedDB & store outbox
    await offlineDb.transaction('rw', [offlineDb.stock_items, offlineDb.outbox, offlineDb.order_history], async () => {
        // Collect aggregated ingredient deductions
        const ingredientDeltas = {}; // stock_id -> { deduct, unit, name }

        for (const item of orderData.items) {
            if (Array.isArray(item.ingredients)) {
                for (const ing of item.ingredients) {
                    const sid = parseInt(ing.stock_item_id);
                    const req = (parseFloat(ing.quantity_required) || 0) * (parseInt(item.qty) || 1);
                    if (!ingredientDeltas[sid]) {
                        ingredientDeltas[sid] = {
                            stock_id: sid,
                            deduct: 0,
                            unit: ing.stock_unit || ing.unit,
                            name: ing.stock_name || ''
                        };
                    }
                    ingredientDeltas[sid].deduct += req;
                }
            }
        }

        // Apply deductions to local stock items in IndexedDB
        for (const [sid, delta] of Object.entries(ingredientDeltas)) {
            const stock = await offlineDb.stock_items.get(parseInt(sid));
            if (stock) {
                const updatedQty = Math.max(0, stock.quantity - delta.deduct);
                await offlineDb.stock_items.update(parseInt(sid), { quantity: updatedQty });
            }
        }

        // Save into Outbox
        await offlineDb.outbox.add(orderRecord);

        // Save into Local Order History
        await offlineDb.order_history.add({
            order_number: orderRecord.order_number,
            order_type: 'coffee_drink',
            total_amount: orderRecord.total_amount,
            items_count: orderRecord.items_count,
            items_summary: orderData.items.map(i => `${i.qty}x ${i.name}`).join(', '),
            created_at: timestamp,
            is_offline: true
        });
    });

    updateOutboxBadge();
    return orderRecord;
}

// 4. Queue an Offline Manual Stock Deduction
async function queueOfflineManualDeduction(deductData) {
    const uuid = 'ded_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6);
    const timestamp = new Date().toISOString().replace('T', ' ').substr(0, 19);

    const record = {
        uuid: uuid,
        action: 'manual_deduction',
        item_id: parseInt(deductData.itemId),
        item_name: deductData.itemName,
        amount: parseFloat(deductData.amount),
        unit: deductData.unit,
        change_type: deductData.changeType || 'deduction',
        reason: deductData.reason || 'Shift quick action (offline)',
        served_by: deductData.servedBy || 'Barista',
        created_at: timestamp,
        status: 'pending'
    };

    await offlineDb.transaction('rw', [offlineDb.stock_items, offlineDb.outbox, offlineDb.order_history], async () => {
        const stock = await offlineDb.stock_items.get(record.item_id);
        if (stock) {
            const newQty = Math.max(0, stock.quantity - record.amount);
            await offlineDb.stock_items.update(record.item_id, { quantity: newQty });
        }

        await offlineDb.outbox.add(record);

        await offlineDb.order_history.add({
            order_number: 'DED-' + Date.now().toString().slice(-6),
            order_type: 'manual_deduction',
            total_amount: 0,
            items_count: 1,
            items_summary: `-${record.amount} ${record.unit} ${record.item_name} (${record.reason})`,
            created_at: timestamp,
            is_offline: true
        });
    });

    updateOutboxBadge();
    return record;
}

// 5. Sync Outbox to Cloud Server (api/sync.php)
async function syncOfflineOutbox() {
    if (!navigator.onLine) {
        console.log('[Sync] Offline: sync postponed until internet reconnects.');
        return { synced: 0, pending: await getPendingOutboxCount() };
    }

    const pending = await offlineDb.outbox.where('status').equals('pending').toArray();
    if (pending.length === 0) {
        updateOutboxBadge();
        return { synced: 0, pending: 0 };
    }

    console.log(`[Sync] Attempting to synchronize ${pending.length} outbox items to cloud...`);

    // Prepare sync payload items for api/sync.php with Delta Commutative metadata
    const syncPayloadItems = [];

    for (const entry of pending) {
        if (entry.action === 'coffee_order') {
            // Aggregate ingredients from order items
            for (const item of entry.items) {
                if (Array.isArray(item.ingredients)) {
                    for (const ing of item.ingredients) {
                        syncPayloadItems.push({
                            uuid: entry.uuid,
                            item_id: parseInt(ing.stock_item_id),
                            amount: (parseFloat(ing.quantity_required) || 0) * (parseInt(item.qty) || 1),
                            change_type: 'deduction',
                            reason: `Order ${entry.order_number}: ${item.qty}x ${item.name}`
                        });
                    }
                }
            }
        } else if (entry.action === 'manual_deduction') {
            syncPayloadItems.push({
                uuid: entry.uuid,
                item_id: entry.item_id,
                amount: entry.amount,
                change_type: entry.change_type || 'deduction',
                reason: entry.reason
            });
        }
    }

    if (syncPayloadItems.length === 0) {
        // Mark all as synced if empty payload
        for (const entry of pending) {
            await offlineDb.outbox.update(entry.id, { status: 'synced' });
        }
        updateOutboxBadge();
        return { synced: pending.length, pending: 0 };
    }

    try {
        updateOutboxBadge(true); // Flag syncing status
        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
        const response = await fetch('/api/sync.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                reason: 'Offline Terminal Outbox Sync (Commutative Delta)',
                items: syncPayloadItems
            })
        });

        if (response.ok) {
            const result = await response.json();
            // Mark all sent records as synced
            for (const entry of pending) {
                await offlineDb.outbox.update(entry.id, { status: 'synced' });
            }

            console.info(`✓ Successfully synchronized ${pending.length} offline records to cloud.`, result);
            showOfflineToast(`✓ ${pending.length} offline records synchronized with cloud server!`, 'success');
            updateOutboxBadge(false);
            return { synced: pending.length, pending: 0, result: result };
        } else {
            const err = await response.json().catch(() => ({ error: 'Sync endpoint error' }));
            console.warn('[Sync] Server rejected sync batch:', err);
            updateOutboxBadge(false);
            return { synced: 0, pending: pending.length, error: err };
        }
    } catch (networkErr) {
        console.warn('[Sync] Network error during outbox sync:', networkErr);
        updateOutboxBadge(false);
        return { synced: 0, pending: pending.length, error: networkErr };
    }
}

// 6. Helpers & Connectivity Watcher
async function getPendingOutboxCount() {
    try {
        return await offlineDb.outbox.where('status').equals('pending').count();
    } catch (e) {
        return 0;
    }
}

async function updateOutboxBadge(isSyncing = false) {
    const targets = [
        document.getElementById('global-connectivity-pill'),
        document.getElementById('offline-sync-badge')
    ];

    const count = await getPendingOutboxCount();
    const isOnline = navigator.onLine;

    targets.forEach(pill => {
        if (!pill) return;

        if (!isOnline) {
            pill.className = "inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-900 dark:bg-amber-950/70 dark:text-amber-300 border border-amber-300 dark:border-amber-700/60 shadow-sm cursor-pointer select-none transition-all";
            pill.innerHTML = `<span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span><span>Offline ${count > 0 ? `(${count} queued)` : ''}</span>`;
            pill.title = "Offline Mode: Transactions saved in local IndexedDB. Click to test reconnection.";
        } else if (isSyncing || count > 0) {
            pill.className = "inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-blue-50 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300 border border-blue-200 dark:border-blue-700/60 shadow-sm cursor-pointer select-none transition-all";
            pill.innerHTML = `<span class="w-2 h-2 rounded-full bg-blue-500 animate-ping"></span><span>Syncing ${count > 0 ? `(${count})` : ''}</span>`;
            pill.title = "Syncing local outbox to cloud server...";
        } else {
            pill.className = "inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-200/80 dark:border-emerald-800/40 shadow-sm cursor-pointer select-none transition-all";
            pill.innerHTML = `<span class="w-2 h-2 rounded-full bg-emerald-500"></span><span class="hidden sm:inline">Online (Synced)</span><span class="sm:hidden">Online</span>`;
            pill.title = "Connected to cloud server. Click to force check sync.";
        }

        pill.onclick = () => {
            pill.classList.add('opacity-75');
            syncOfflineOutbox().finally(() => pill.classList.remove('opacity-75'));
        };
    });
}

// 7. Active End-to-End Heartbeat & Auto-Reconnection
async function checkConnectivityHeartbeat() {
    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 3500);
        const res = await fetch('/api/ping.php', { method: 'GET', cache: 'no-store', signal: controller.signal });
        clearTimeout(timeoutId);
        if (res.ok) {
            const count = await getPendingOutboxCount();
            if (count > 0) {
                syncOfflineOutbox();
            } else {
                updateOutboxBadge(false);
            }
            return true;
        }
    } catch (e) {}

    // Cloud unreachable
    updateOutboxBadge(false);
    return false;
}

// Heartbeat every 20 seconds to auto-reconnect
setInterval(checkConnectivityHeartbeat, 20000);

function showOfflineToast(message, type = 'info') {
    const existing = document.getElementById('offline-toast-notification');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.id = 'offline-toast-notification';
    const bgClass = type === 'success' 
        ? 'bg-emerald-600 text-white' 
        : (type === 'warning' ? 'bg-amber-600 text-white' : 'bg-slate-900 text-white');

    toast.className = `fixed bottom-5 right-5 z-50 px-4 py-3 rounded-2xl shadow-2xl text-xs font-bold flex items-center gap-2.5 ${bgClass} transition-all transform animate-bounce`;
    toast.innerHTML = `<span>${type === 'success' ? '✓' : (type === 'warning' ? '⚠️' : 'ℹ️')}</span> <span>${message}</span>`;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 400);
    }, 4000);
}

// 8. Auto-detect online/offline events & Background Sync
window.addEventListener('online', () => {
    console.info('[Network] Back online! Auto-syncing outbox...');
    showOfflineToast('Internet connection restored. Synchronizing outbox...', 'info');
    updateOutboxBadge();
    syncOfflineOutbox();
});

window.addEventListener('offline', () => {
    console.warn('[Network] Disconnected. Running in Offline-First Mode.');
    showOfflineToast('Internet lost. Operating in Offline Mode (orders stored locally).', 'warning');
    updateOutboxBadge();
});

// Register Service Worker Background Sync if supported
if ('serviceWorker' in navigator && 'SyncManager' in window) {
    navigator.serviceWorker.ready.then(reg => {
        return reg.sync.register('asterisko-outbox-sync');
    }).catch(err => console.log('[SW] Background sync registration skipped:', err));
}

// Listen for Service Worker messages (e.g. background sync triggers)
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', event => {
        if (event.data && event.data.type === 'TRIGGER_OUTBOX_SYNC') {
            syncOfflineOutbox();
        }
    });
}

// Run initial badge check
document.addEventListener('DOMContentLoaded', () => {
    updateOutboxBadge();
    if (navigator.onLine) {
        syncOfflineOutbox();
    }
});
