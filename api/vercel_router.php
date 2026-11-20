<?php
/**
 * Vercel Serverless Front-Controller Router for Asterisko Inventory Management System
 * Routes /, /index.php, and /pages/*.php while preserving relative working directories.
 */

$route = $_GET['__route'] ?? 'index.php';
unset($_GET['__route']);

// Normalize and strictly allow only index.php or pages/<name>.php
$route = ltrim(str_replace('\\', '/', $route), '/');
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
