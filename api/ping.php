<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!empty($_GET['diag'])) {
    require_once __DIR__ . '/../includes/diagnostics.php';
    $health = runSystemHealthChecks();
    if (!$health['db_connected']) {
        logDiagnosticEvent('ERR-DB-500', 'Watchdog', 'Database health probe failed: ' . ($health['db_error'] ?? 'Unknown'));
        http_response_code(503);
    } else {
        http_response_code(200);
    }
    echo json_encode($health, JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(200);
echo json_encode([
    'status' => 'ok',
    'code' => 'SYS-OK',
    'timestamp' => time()
]);
exit;
