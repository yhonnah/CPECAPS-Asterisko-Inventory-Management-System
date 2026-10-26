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

    // Prepare sync payload items for api/sync.php
    const syncPayloadItems = [];

    for (const entry of pending) {
        if (entry.action === 'coffee_order') {
            // Aggregate ingredients from order items
            for (const item of entry.items) {
                if (Array.isArray(item.ingredients)) {
                    for (const ing of item.ingredients) {
                        syncPayloadItems.push({
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
        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
        const response = await fetch('/api/sync.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                reason: 'Offline Terminal Outbox Sync',
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
            updateOutboxBadge();
            return { synced: pending.length, pending: 0, result: result };
        } else {
            const err = await response.json().catch(() => ({ error: 'Sync endpoint error' }));
            console.warn('[Sync] Server rejected sync batch:', err);
            return { synced: 0, pending: pending.length, error: err };
        }
    } catch (networkErr) {
        console.warn('[Sync] Network error during outbox sync:', networkErr);
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

async function updateOutboxBadge() {
    const badge = document.getElementById('offline-sync-badge');
    if (!badge) return;

    const count = await getPendingOutboxCount();
    const isOnline = navigator.onLine;

    if (!isOnline) {
        badge.className = "inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-[11px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-300 dark:border-amber-800 animate-pulse";
        badge.innerHTML = `<span class="w-2 h-2 rounded-full bg-amber-500"></span> Offline (${count} queued)`;
        badge.title = "Offline Mode: Transactions are stored locally in IndexedDB and will auto-sync when online.";
    } else if (count > 0) {
        badge.className = "inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-[11px] font-bold bg-blue-50 text-blue-800 dark:bg-blue-950/50 dark:text-blue-300 border border-blue-200 dark:border-blue-800";
        badge.innerHTML = `<span class="w-2 h-2 rounded-full bg-blue-500 animate-ping"></span> Syncing (${count})...`;
        badge.title = "Internet restored. Syncing outbox to cloud server.";
    } else {
        badge.className = "inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-[11px] font-semibold bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/40";
        badge.innerHTML = `<span class="w-2 h-2 rounded-full bg-emerald-500"></span> Online (Synced)`;
        badge.title = "Connected to cloud. Real-time sync active.";
    }
}

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

// 7. Auto-detect online/offline events
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

// Run initial badge check
document.addEventListener('DOMContentLoaded', () => {
    updateOutboxBadge();
    if (navigator.onLine) {
        syncOfflineOutbox();
    }
});
