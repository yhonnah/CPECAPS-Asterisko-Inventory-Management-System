<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
http_response_code(200);
echo json_encode([
    'status' => 'ok',
    'timestamp' => time()
]);
exit;
