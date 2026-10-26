<?php
// Centralized Cache Versioning Helper for Asterisko Inventory System

function ensureCacheTable(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) return;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_cache_meta (
                meta_key VARCHAR(50) PRIMARY KEY,
                meta_value VARCHAR(255) NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $ensured = true;
    } catch (Exception $e) {
        // Fallback silently if table creation is restricted
    }
}

function getCatalogCacheVersion(PDO $pdo): string {
    ensureCacheTable($pdo);
    try {
        $stmt = $pdo->prepare("SELECT meta_value FROM system_cache_meta WHERE meta_key = 'catalog_version'");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if ($val !== false && !empty($val)) {
            return (string)$val;
        }
        // Initialize if not set
        $initVer = 'v_' . time() . '_' . bin2hex(random_bytes(3));
        $ins = $pdo->prepare("INSERT INTO system_cache_meta (meta_key, meta_value) VALUES ('catalog_version', ?) ON DUPLICATE KEY UPDATE meta_value = ?");
        $ins->execute([$initVer, $initVer]);
        return $initVer;
    } catch (Exception $e) {
        return 'v_' . date('YmdH');
    }
}

function bumpCatalogVersion(PDO $pdo): string {
    ensureCacheTable($pdo);
    $newVer = 'v_' . time() . '_' . bin2hex(random_bytes(3));
    try {
        $stmt = $pdo->prepare("
            INSERT INTO system_cache_meta (meta_key, meta_value) 
            VALUES ('catalog_version', ?) 
            ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)
        ");
        $stmt->execute([$newVer]);
    } catch (Exception $e) {}
    
    // Invalidate local session version
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['catalog_cache_version']);
        unset($_SESSION['cached_menu_items']);
        unset($_SESSION['cached_menu_ingredients']);
    }
    
    return $newVer;
}
