<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/authguard.php';
require_once __DIR__ . '/../includes/diagnostics.php';

requireRole(['Admin', 'Owner'], $pdo);

$username = $_SESSION['username'] ?? 'User';
$role = $_SESSION['role'] ?? 'Admin';

$health = runSystemHealthChecks($pdo);
$errorRegistry = asteriskoErrorRegistry();
$diagLogs = readRecentDiagnosticLogs(25);

// Fetch recent fault & alert audit events
$faultAudits = [];
try {
    $stmt = $pdo->query("
        SELECT al.id, al.item_id, si.NAME AS item_name, al.change_type, al.reason, al.acting_user, al.created_at
        FROM audit_logs al
        LEFT JOIN stock_items si ON si.id = al.item_id
        WHERE al.change_type IN ('rate_limit_blocked', 'unauthorized_access', 'low_stock_alert')
           OR al.reason LIKE '%[OFFLINE SYNC CONFLICT]%'
        ORDER BY al.created_at DESC
        LIMIT 20
    ");
    $faultAudits = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $faultAudits = [];
}

$activePage = 'diagnostics';
$pageTitle = 'System Diagnostics & Watchdog - Asterisko Inventory';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>
<body class="bg-[#F4F7FB] dark:bg-[#071526] text-slate-800 dark:text-slate-100 min-h-screen">

    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="lg:pl-64 flex flex-col min-h-screen">
        <?php require_once __DIR__ . '/../includes/topbar.php'; ?>

        <main class="flex-1 p-4 sm:p-6 lg:p-8 space-y-6 max-w-7xl w-full mx-auto">

            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">System Diagnostics &amp; Watchdog</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Live subsystem status LEDs, standardized error codes, and fault logs.</p>
                </div>
                <button type="button" onclick="runWatchdogProbe()" id="btn-probe"
                        class="btn-primary inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold shadow-md">
                    <span>Run Watchdog Probe</span>
                </button>
            </div>

            <!-- Live Watchdog Status LED Banner -->
            <?php
                $isOk = ($health['status'] === 'ok');
                $isErr = ($health['status'] === 'error');
                $ledColor = $isOk ? 'bg-emerald-500' : ($isErr ? 'bg-rose-500' : 'bg-amber-500');
                $bannerStyle = $isOk
                    ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-800 text-emerald-900 dark:text-emerald-200'
                    : ($isErr
                        ? 'bg-rose-50 dark:bg-rose-950/40 border-rose-200 dark:border-rose-800 text-rose-900 dark:text-rose-200'
                        : 'bg-amber-50 dark:bg-amber-950/40 border-amber-200 dark:border-amber-800 text-amber-900 dark:text-amber-200');
            ?>
            <div id="watchdog-banner" class="p-4 rounded-2xl border flex flex-col sm:flex-row sm:items-center justify-between gap-3 <?= $bannerStyle ?>">
                <div class="flex items-center gap-3">
                    <span class="relative flex h-4 w-4 shrink-0">
                        <span id="watchdog-ping" class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75 <?= $ledColor ?>"></span>
                        <span id="watchdog-dot" class="relative inline-flex rounded-full h-4 w-4 <?= $ledColor ?>"></span>
                    </span>
                    <div>
                        <div class="font-extrabold text-sm flex items-center gap-2">
                            <span id="watchdog-code" class="font-mono px-2 py-0.5 rounded bg-black/10 dark:bg-white/10"><?= htmlspecialchars($health['code']) ?></span>
                            <span id="watchdog-label"><?= $isOk ? 'All Subsystems Operational' : ($isErr ? 'Critical Database Fault' : 'Degraded Subsystem Detected') ?></span>
                        </div>
                        <p class="text-xs opacity-80 mt-0.5">Last probe: <span id="watchdog-ts"><?= htmlspecialchars($health['timestamp']) ?></span> (auto-checks every 15s)</p>
                    </div>
                </div>
                <span class="text-xs font-mono font-bold">PHP <?= htmlspecialchars($health['php_version']) ?></span>
            </div>

            <!-- 4 Subsystem LED Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="stat-card p-5" style="--stat-accent: <?= $health['db_connected'] ? '#10B981' : '#EF4444' ?>;">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Aiven MySQL TLS</span>
                        <span class="w-3 h-3 rounded-full <?= $health['db_connected'] ? 'bg-emerald-500' : 'bg-rose-500' ?>"></span>
                    </div>
                    <div class="mt-2">
                        <span id="card-db-latency" class="text-2xl font-black font-mono text-slate-900 dark:text-white">
                            <?= $health['db_connected'] ? htmlspecialchars($health['db_latency_ms'] . ' ms') : 'ERR-DB-500' ?>
                        </span>
                        <p class="text-xs text-slate-400 mt-1"><?= $health['db_connected'] ? 'SELECT 1 probe healthy' : htmlspecialchars($health['db_error'] ?? 'Connection failed') ?></p>
                    </div>
                </div>

                <div class="stat-card p-5" style="--stat-accent: <?= $health['extensions_ok'] ? '#10B981' : '#F59E0B' ?>;">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">PHP Extensions</span>
                        <span class="w-3 h-3 rounded-full <?= $health['extensions_ok'] ? 'bg-emerald-500' : 'bg-amber-500' ?>"></span>
                    </div>
                    <div class="mt-2">
                        <span class="text-2xl font-black text-slate-900 dark:text-white"><?= $health['extensions_ok'] ? '4 / 4 OK' : 'Missing' ?></span>
                        <p class="text-xs text-slate-400 mt-1">pdo_mysql, json, mbstring, fileinfo</p>
                    </div>
                </div>

                <div class="stat-card p-5" style="--stat-accent: <?= $health['storage_ok'] ? '#10B981' : '#F59E0B' ?>;">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Storage &amp; Logs</span>
                        <span class="w-3 h-3 rounded-full <?= $health['storage_ok'] ? 'bg-emerald-500' : 'bg-amber-500' ?>"></span>
                    </div>
                    <div class="mt-2">
                        <span class="text-2xl font-black text-slate-900 dark:text-white"><?= $health['storage_ok'] ? 'Writable' : 'Check Perms' ?></span>
                        <p class="text-xs text-slate-400 mt-1">logs/, uploads/invoices/, backups/</p>
                    </div>
                </div>

                <div class="stat-card p-5" style="--stat-accent: #F28C28;">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">24h Faults &amp; Alerts</span>
                        <span class="w-3 h-3 rounded-full bg-[#F28C28]"></span>
                    </div>
                    <div class="mt-2">
                        <?php $total24h = array_sum($health['faults_24h']); ?>
                        <span class="text-2xl font-black font-mono text-slate-900 dark:text-white"><?= $total24h ?></span>
                        <p class="text-xs text-slate-400 mt-1">
                            Auth: <?= (int)$health['faults_24h']['rate_limit_blocked'] ?> · ROP: <?= (int)$health['faults_24h']['low_stock_alert'] ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- How to Read Status LEDs & Error Codes -->
            <div class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] overflow-hidden">
                <div class="p-5 border-b border-slate-200 dark:border-[#15345C]">
                    <h2 class="font-bold text-base text-slate-900 dark:text-white">How to Read Status LEDs &amp; Error Codes</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Standardized fault codes emitted by Asterisko endpoints and diagnostic logs.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>LED</th>
                                <th>Component</th>
                                <th>Trigger Condition</th>
                                <th>Recommended Repair Step</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($errorRegistry as $err):
                                $pillClass = $err['led'] === 'GREEN'
                                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                                    : ($err['led'] === 'RED'
                                        ? 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300'
                                        : 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300');
                            ?>
                            <tr>
                                <td class="font-mono font-bold text-slate-900 dark:text-white whitespace-nowrap"><?= htmlspecialchars($err['code']) ?></td>
                                <td><span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $pillClass ?>"><?= htmlspecialchars($err['led']) ?></span></td>
                                <td class="font-semibold text-slate-700 dark:text-slate-200 whitespace-nowrap"><?= htmlspecialchars($err['component']) ?></td>
                                <td class="text-slate-600 dark:text-slate-300"><?= htmlspecialchars($err['trigger']) ?></td>
                                <td class="text-slate-600 dark:text-slate-300"><?= htmlspecialchars($err['repair']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Two-Column Log Panels: App Diagnostic Log + DB Fault Audit Events -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Structured File Log -->
                <div class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] overflow-hidden">
                    <div class="p-5 border-b border-slate-200 dark:border-[#15345C] flex justify-between items-center">
                        <div>
                            <h2 class="font-bold text-base text-slate-900 dark:text-white">Application Diagnostic Log</h2>
                            <p class="text-xs text-slate-400">logs/app_diagnostics.log (latest 25 events)</p>
                        </div>
                    </div>
                    <div class="p-4 space-y-2 max-h-80 overflow-y-auto text-xs font-mono">
                        <?php if (empty($diagLogs)): ?>
                            <div class="text-center py-8 text-slate-400 font-sans">No application faults recorded in app_diagnostics.log.</div>
                        <?php else: ?>
                            <?php foreach ($diagLogs as $dl): ?>
                                <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-[#07172C] border border-slate-200/80 dark:border-[#173863]">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-bold text-[#F28C28]"><?= htmlspecialchars($dl['code'] ?? 'INFO') ?></span>
                                        <span class="text-[10px] text-slate-400"><?= htmlspecialchars($dl['ts'] ?? '') ?></span>
                                    </div>
                                    <div class="text-slate-700 dark:text-slate-200 mt-1 font-sans"><?= htmlspecialchars($dl['message'] ?? '') ?></div>
                                    <div class="text-[10px] text-slate-400 mt-0.5"><?= htmlspecialchars(($dl['component'] ?? '') . ' · user=' . ($dl['user'] ?? 'system')) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- DB Security & Alert Trail -->
                <div class="bg-white dark:bg-[#0C2340] rounded-2xl shadow-sm border border-slate-200/80 dark:border-[#15345C] overflow-hidden">
                    <div class="p-5 border-b border-slate-200 dark:border-[#15345C]">
                        <h2 class="font-bold text-base text-slate-900 dark:text-white">Audit Trail Faults &amp; Alerts</h2>
                        <p class="text-xs text-slate-400">Rate limits, unauthorized access, sync conflicts, and ROP alerts</p>
                    </div>
                    <div class="p-4 space-y-2 max-h-80 overflow-y-auto text-xs">
                        <?php if (empty($faultAudits)): ?>
                            <div class="text-center py-8 text-slate-400">No fault or alert audit entries recorded.</div>
                        <?php else: ?>
                            <?php foreach ($faultAudits as $fa): ?>
                                <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-[#07172C] border border-slate-200/80 dark:border-[#173863]">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-mono font-bold text-amber-600 dark:text-amber-400"><?= htmlspecialchars($fa['change_type']) ?></span>
                                        <span class="text-[10px] text-slate-400"><?= htmlspecialchars($fa['created_at']) ?></span>
                                    </div>
                                    <div class="text-slate-700 dark:text-slate-200 mt-1"><?= htmlspecialchars($fa['reason']) ?></div>
                                    <div class="text-[10px] text-slate-400 mt-0.5">User: <?= htmlspecialchars($fa['acting_user']) ?><?= !empty($fa['item_name']) ? ' · Item: ' . htmlspecialchars($fa['item_name']) : '' ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <script>
        async function runWatchdogProbe() {
            const btn = document.getElementById('btn-probe');
            btn.disabled = true;
            try {
                const res = await fetch('../api/ping.php?diag=1', { cache: 'no-store' });
                const data = await res.json();
                document.getElementById('watchdog-code').textContent = data.code || 'SYS-OK';
                document.getElementById('watchdog-ts').textContent = data.timestamp || '';
                document.getElementById('card-db-latency').textContent = data.db_connected ? `${data.db_latency_ms} ms` : 'ERR-DB-500';
                document.getElementById('watchdog-label').textContent = data.status === 'ok'
                    ? 'All Subsystems Operational'
                    : (data.status === 'error' ? 'Critical Database Fault' : 'Degraded Subsystem Detected');
            } catch (e) {
                document.getElementById('watchdog-code').textContent = 'ERR-NET-000';
                document.getElementById('watchdog-label').textContent = 'Watchdog Unreachable';
            } finally {
                btn.disabled = false;
            }
        }
        setInterval(runWatchdogProbe, 15000);
    </script>
</body>
</html>
