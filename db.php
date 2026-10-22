<?php
session_start();

// Aiven Cloud Database Credentials
$host = 'aste-inventory-aste-inventory-system.j.aivencloud.com'; // Paste your Aiven Host here
$port = '13670';                 // Paste your Aiven Port here
$dbname = 'aste-inventory';                     // Or 'cafe_inventory' if created
$user = 'avnadmin';                        // Default Aiven user
$pass = 'AVNS_5-LQ3ixBakUHi93eYVK';             // Paste your Aiven Password here

try {
    // DSN string configured with sslmode=require for Aiven cloud security
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4;sslmode=require";
    
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        // Bypasses strict local CA certificate verification for rapid prototype setup
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false, 
    ];

    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Aiven Cloud Database connection failed: " . $e->getMessage());
}
?>