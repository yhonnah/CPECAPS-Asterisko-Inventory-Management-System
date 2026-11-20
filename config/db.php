<?php
if (ob_get_level() === 0) {
    ob_start();
}
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
$host    = getenv('DB_HOST') !== false ? getenv('DB_HOST') : 'aste-inventory-aste-inventory-system.j.aivencloud.com';
$port    = getenv('DB_PORT') !== false ? getenv('DB_PORT') : '13670';
$dbname  = getenv('DB_NAME') !== false ? getenv('DB_NAME') : 'aste-inventory';
$user    = getenv('DB_USER') !== false ? getenv('DB_USER') : 'avnadmin';
$pass    = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'AVNS_5-LQ3ixBakUHi93eYVK';
$sslmode = getenv('DB_SSL_MODE') !== false ? getenv('DB_SSL_MODE') : 'require';

// 2b. Stateless HMAC-signed session cookie bridge for Vercel Serverless PHP instances
$asteSessSecret = hash('sha256', 'asterisko_serverless_sess_' . ($pass ?: 'aste_key'));
$asteTrackedKeys = ['user_id', 'username', 'role', 'user_role', 'csrf_token', 'flash_message', 'flash_type'];

if (!empty($_COOKIE['ASTE_SESS'])) {
    $parts = explode('.', (string)$_COOKIE['ASTE_SESS'], 2);
    if (count($parts) === 2) {
        [$b64Payload, $cookieSig] = $parts;
        $expectedSig = hash_hmac('sha256', $b64Payload, $asteSessSecret);
        if (hash_equals($expectedSig, $cookieSig)) {
            $decoded = json_decode((string)base64_decode($b64Payload, true), true);
            if (is_array($decoded) && (($decoded['exp'] ?? 0) > time())) {
                foreach ($asteTrackedKeys as $k) {
                    if (!isset($_SESSION[$k]) && isset($decoded[$k])) {
                        $_SESSION[$k] = $decoded[$k];
                    }
                }
            }
        }
    }
}

if (!defined('ASTERISKO_SESS_SHUTDOWN_REGISTERED')) {
    define('ASTERISKO_SESS_SHUTDOWN_REGISTERED', true);
    register_shutdown_function(function () use ($asteSessSecret, $asteTrackedKeys) {
        if (headers_sent()) {
            return;
        }
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        if (session_status() !== PHP_SESSION_ACTIVE || (empty($_SESSION['user_id']) && empty($_SESSION['csrf_token']))) {
            if (!empty($_COOKIE['ASTE_SESS'])) {
                setcookie('ASTE_SESS', '', [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                    'secure' => $isHttps,
                ]);
            }
            return;
        }
        $payload = ['exp' => time() + 86400];
        foreach ($asteTrackedKeys as $k) {
            if (isset($_SESSION[$k])) {
                $payload[$k] = $_SESSION[$k];
            }
        }
        $b64 = base64_encode((string)json_encode($payload, JSON_UNESCAPED_UNICODE));
        $sig = hash_hmac('sha256', $b64, $asteSessSecret);
        setcookie('ASTE_SESS', "{$b64}.{$sig}", [
            'expires' => time() + 86400,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $isHttps,
        ]);
    });
}

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    PDO::ATTR_TIMEOUT => 15,
];

function ensureDbConnection(): PDO {
    global $pdo, $host, $port, $dbname, $user, $pass, $sslmode, $options;
    try {
        if ($pdo instanceof PDO) {
            $pdo->query("SELECT 1");
            return $pdo;
        }
    } catch (Throwable $e) {
        // Connection lost, re-establish below
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    if (!empty($sslmode)) {
        $dsn .= ";sslmode={$sslmode}";
    }

    try {
        $pdo = new PDO($dsn, $user, $pass, $options ?? []);
        return $pdo;
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    if (!empty($sslmode)) {
        $dsn .= ";sslmode={$sslmode}";
    }

    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
