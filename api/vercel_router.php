<?php
/**
 * Vercel Serverless Front-Controller Router for Asterisko Inventory Management System
 * Routes /, /index.php, and /pages/*.php while preserving relative working directories.
 */

header('Content-Type: text/html; charset=UTF-8');

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$route = $_GET['__route'] ?? ltrim($uriPath, '/');
unset($_GET['__route']);

$route = ltrim(str_replace('\\', '/', (string)$route), '/');
if ($route === '' || $route === 'api/vercel_router.php') {
    $route = 'index.php';
}

// Strictly allow only index.php or pages/<name>.php
if ($route !== 'index.php' && !preg_match('#^pages/[a-zA-Z0-9_-]+\.php$#', $route)) {
    http_response_code(404);
    echo "404 Not Found";
    exit;
}

$repoRoot = dirname(__DIR__);
$targetFile = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $route);

if (!is_file($targetFile)) {
    http_response_code(404);
    echo "404 Page Not Found";
    exit;
}

// Emulate standard Apache/PHP-S environment variables & working directory for relative paths
chdir(dirname($targetFile));
$_SERVER['SCRIPT_FILENAME'] = $targetFile;
$_SERVER['SCRIPT_NAME'] = '/' . $route;
$_SERVER['PHP_SELF'] = '/' . $route;

require $targetFile;
