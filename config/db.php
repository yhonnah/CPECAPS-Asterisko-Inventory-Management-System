<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Load configuration from .env file if present
$envPath = dirname(__DIR__) . '/.env';
if (!file_exists($envPath)) {
    $envPath = __DIR__ . '/.env';
}
if (file_exists($envPath) && is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv("$name=$value");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

// 2. Resolve database credentials from environment or defaults
$host    = getenv('DB_HOST') ?: 'aste-inventory-aste-inventory-system.j.aivencloud.com';
$port    = getenv('DB_PORT') ?: '13670';
$dbname  = getenv('DB_NAME') ?: 'aste-inventory';
$user    = getenv('DB_USER') ?: 'avnadmin';
$pass    = getenv('DB_PASS') ?: 'AVNS_5-LQ3ixBakUHi93eYVK';
$sslmode = getenv('DB_SSL_MODE') ?: 'require';

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    if (!empty($sslmode)) {
        $dsn .= ";sslmode={$sslmode}";
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    ];

    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
