<?php
/**
 * Asterisko Diagnostics, Watchdog Health Checks & Standardized Error Code Registry
 * Module 8: Integration & Fault-Proofing
 */

function asteriskoErrorRegistry(): array
{
    return [
        [
            'code' => 'SYS-OK',
            'led' => 'GREEN',
            'severity' => 'Normal',
            'component' => 'Watchdog / All Subsystems',
            'trigger' => 'Web server, Aiven MySQL TLS connection, and local sync engine healthy.',
            'repair' => 'No action required.'
        ],
        [
            'code' => 'WARN-SYNC-PENDING',
            'led' => 'AMBER',
            'severity' => 'Warning',
            'component' => 'Offline Outbox / PWA',
            'trigger' => 'Client offline or IndexedDB/localStorage queue contains unsynced operations.',
            'repair' => 'Restore internet connectivity and click Sync in Sales/Stock Count.'
        ],
        [
            'code' => 'ERR-DB-500',
            'led' => 'RED',
            'severity' => 'Critical',
            'component' => 'Database / Aiven TLS',
            'trigger' => 'PDO connection failure, expired CA cert, or missing .env DB credentials.',
            'repair' => 'Verify DB_HOST/DB_PORT/DB_USER/DB_PASS/DB_SSL_CA in .env and Aiven service status.'
        ],
        [
            'code' => 'ERR-AUTH-429',
            'led' => 'AMBER',
            'severity' => 'Security',
            'component' => 'Login Rate Limiter',
            'trigger' => '>= 5 failed login attempts from the same IP or username within 5 minutes.',
            'repair' => 'Wait 5 minutes for window expiry or inspect audit_logs / login_attempts.'
        ],
        [
            'code' => 'ERR-AUTH-403',
            'led' => 'AMBER',
            'severity' => 'Security',
            'component' => 'CSRF / Role Guard',
            'trigger' => 'Missing/expired X-CSRF-Token or unauthorized role access attempt.',
            'repair' => 'Refresh page to renew session CSRF token or verify user role permissions.'
        ],
        [
            'code' => 'ERR-SYNC-422',
            'led' => 'AMBER',
            'severity' => 'Validation',
            'component' => 'Stock Count Sync',
            'trigger' => 'Physical count differs by >100% from system stock without supervisor ack.',
            'repair' => 'Recount physical stock and submit with supervisor acknowledgment + note.'
        ],
        [
            'code' => 'ERR-SYNC-409',
            'led' => 'AMBER',
            'severity' => 'Warning',
            'component' => 'Offline Delta Sync',
            'trigger' => 'Cached offline baseline stock differed from live DB balance at sync time.',
            'repair' => 'Review [OFFLINE SYNC CONFLICT] entry in audit_logs and verify physical stock.'
        ],
        [
            'code' => 'ERR-OCR-415',
            'led' => 'AMBER',
            'severity' => 'Validation',
            'component' => 'Delivery Invoice Upload',
            'trigger' => 'Uploaded file failed server-side finfo MIME signature validation.',
            'repair' => 'Re-upload a valid JPG, PNG, WEBP, or GIF image.'
        ],
        [
            'code' => 'ERR-PO-400',
            'led' => 'AMBER',
            'severity' => 'Validation',
            'component' => 'Purchase Orders',
            'trigger' => 'Attempted PO creation referencing an inactive (is_active=0) or missing supplier.',
            'repair' => 'Select an active supplier or reactivate the vendor in Suppliers.'
        ],
        [
            'code' => 'ERR-STK-400',
            'led' => 'AMBER',
            'severity' => 'Validation',
            'component' => 'Sales / Dashboard / Waste',
            'trigger' => 'Requested deduction exceeds available stock or quantity <= 0.',
            'repair' => 'Enter a positive quantity within available on-hand balance.'
        ],
    ];
}

function getDiagnosticsLogPath(): string
{
    $repoLogsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($repoLogsDir)) {
        @mkdir($repoLogsDir, 0755, true);
    }
    if (is_dir($repoLogsDir) && is_writable($repoLogsDir)) {
        return $repoLogsDir . DIRECTORY_SEPARATOR . 'app_diagnostics.log';
    }
    $tmpLogsDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'asterisko_logs';
    if (!is_dir($tmpLogsDir)) {
        @mkdir($tmpLogsDir, 0755, true);
    }
    return $tmpLogsDir . DIRECTORY_SEPARATOR . 'app_diagnostics.log';
}

function logDiagnosticEvent(string $code, string $component, string $message, array $context = []): void
{
    try {
        $logPath = getDiagnosticsLogPath();
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        if (file_exists($logPath) && @filesize($logPath) > 1048576) {
            @rename($logPath, $logPath . '.1');
        }
        $entry = [
            'ts' => date('Y-m-d H:i:s'),
            'code' => $code,
            'component' => $component,
            'message' => $message,
            'user' => $_SESSION['username'] ?? 'system',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
        ];
        if (!empty($context)) {
            $entry['ctx'] = $context;
        }
        @file_put_contents($logPath, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // Never interrupt primary application flow due to file logging errors
    }
}

function readRecentDiagnosticLogs(int $limit = 30): array
{
    $logPath = getDiagnosticsLogPath();
    if (!is_file($logPath)) {
        return [];
    }
    $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }
    $slice = array_slice($lines, -$limit);
    $parsed = [];
    foreach (array_reverse($slice) as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $parsed[] = $row;
        }
    }
    return $parsed;
}

function runSystemHealthChecks(?PDO $pdo = null): array
{
    $overallCode = 'SYS-OK';
    $overallStatus = 'ok';

    // 1. Database TLS Probe
    $dbOk = false;
    $dbLatencyMs = null;
    $dbError = null;
    $t0 = microtime(true);
    try {
        if ($pdo === null) {
            global $pdo, $host, $port, $dbname, $user, $pass, $sslmode, $options;
            require_once __DIR__ . '/../config/db.php';
            $pdo = ensureDbConnection();
        }
        $pdo->query('SELECT 1');
        $dbLatencyMs = round((microtime(true) - $t0) * 1000, 2);
        $dbOk = true;
    } catch (Throwable $e) {
        $dbLatencyMs = round((microtime(true) - $t0) * 1000, 2);
        $dbError = $e->getMessage();
        $overallCode = 'ERR-DB-500';
        $overallStatus = 'error';
    }

    // 2. Required PHP Extensions
    $requiredExts = ['pdo_mysql', 'json', 'mbstring', 'fileinfo'];
    $missingExts = array_values(array_filter($requiredExts, fn($ext) => !extension_loaded($ext)));
    $extOk = empty($missingExts);
    if (!$extOk && $overallStatus === 'ok') {
        $overallStatus = 'degraded';
        $overallCode = 'WARN-EXT-MISSING';
    }

    // 3. Writable Operational Directories (with serverless /tmp fallback on Vercel)
    $baseDir = dirname(__DIR__);
    $tmpBase = sys_get_temp_dir();
    $dirs = [
        'logs' => [$baseDir . DIRECTORY_SEPARATOR . 'logs', $tmpBase . DIRECTORY_SEPARATOR . 'asterisko_logs'],
        'uploads/invoices' => [$baseDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'invoices', $tmpBase . DIRECTORY_SEPARATOR . 'asterisko_uploads'],
        'backups' => [$baseDir . DIRECTORY_SEPARATOR . 'backups', $tmpBase . DIRECTORY_SEPARATOR . 'asterisko_backups'],
    ];
    $dirResults = [];
    $dirsOk = true;
    foreach ($dirs as $label => [$primaryPath, $fallbackPath]) {
        if (!is_dir($primaryPath)) {
            @mkdir($primaryPath, 0755, true);
        }
        $writable = is_dir($primaryPath) && is_writable($primaryPath);
        if (!$writable) {
            if (!is_dir($fallbackPath)) {
                @mkdir($fallbackPath, 0755, true);
            }
            $writable = is_dir($fallbackPath) && is_writable($fallbackPath);
        }
        $dirResults[$label] = $writable;
        if (!$writable) {
            $dirsOk = false;
        }
    }
    if (!$dirsOk && $overallStatus === 'ok') {
        $overallStatus = 'degraded';
        $overallCode = 'WARN-STORAGE-RO';
    }

    // 4. 24-Hour Fault & Alert Counters from audit_logs
    $faults24h = ['rate_limit_blocked' => 0, 'unauthorized_access' => 0, 'low_stock_alert' => 0];
    if ($dbOk && $pdo instanceof PDO) {
        try {
            $stmt = $pdo->query("
                SELECT change_type, COUNT(*) AS cnt
                FROM audit_logs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                  AND change_type IN ('rate_limit_blocked', 'unauthorized_access', 'low_stock_alert')
                GROUP BY change_type
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $faults24h[$r['change_type']] = (int)$r['cnt'];
            }
        } catch (Throwable $e) {
            // Ignore non-fatal metric failure
        }
    }

    return [
        'status' => $overallStatus,
        'code' => $overallCode,
        'timestamp' => date('Y-m-d H:i:s'),
        'php_version' => PHP_VERSION,
        'db_connected' => $dbOk,
        'db_latency_ms' => $dbLatencyMs,
        'db_error' => $dbError,
        'extensions_ok' => $extOk,
        'missing_extensions' => $missingExts,
        'storage_ok' => $dirsOk,
        'storage_dirs' => $dirResults,
        'faults_24h' => $faults24h,
    ];
}
