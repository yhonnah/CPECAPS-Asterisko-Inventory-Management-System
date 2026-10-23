<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('requireLogin')) {
    function requireLogin() {
        if (!isset($_SESSION['user_id'])) {
            $loginUrl = file_exists('login.php') ? 'login.php' : 'pages/login.php';
            header("Location: " . $loginUrl);
            exit;
        }
    }
}

if (!function_exists('requireRole')) {
    function requireRole($allowedRoles, $pdo) {
        requireLogin();

        if (!in_array($_SESSION['role'], $allowedRoles)) {
            // Record unauthorized attempt in the audit log
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
                VALUES (NULL, NULL, NULL, 'unauthorized_access', ?, ?)
            ");
            $attemptedUrl = $_SERVER['REQUEST_URI'] ?? 'Direct Access';
            $username = $_SESSION['username'] ?? 'Anonymous';
            $stmt->execute(["Blocked access to restricted endpoint: $attemptedUrl", $username]);

            $dashUrl = file_exists('dashboard.php') ? 'dashboard.php' : 'pages/dashboard.php';
            http_response_code(403);
            die("
                <div style='font-family:sans-serif; padding:2rem; max-width:500px; margin:auto;'>
                    <h2 style='color:#b91c1c;'>403 Access Denied</h2>
                    <p>Your role (<b>" . htmlspecialchars($_SESSION['role'] ?? 'Unknown') . "</b>) is restricted from accessing supplier pricing or financial summaries.</p>
                    <p style='color:#666; font-size:0.9rem;'>This attempt has been recorded in the immutable Audit Trail.</p>
                    <a href='{$dashUrl}'>&larr; Return to Dashboard</a>
                </div>
            ");
        }
    }
}

if (!function_exists('getCsrfToken')) {
    function getCsrfToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('validateCsrfToken')) {
    function validateCsrfToken($token = null) {
        if ($token === null) {
            $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        }
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (empty($sessionToken) || !hash_equals($sessionToken, (string)$token)) {
            http_response_code(403);
            $isJson = (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
                      (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));
            if ($isJson) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'success' => false,
                    'error' => 'Security token mismatch or expired. Please refresh the page.'
                ]);
                exit;
            }
            $dashUrl = file_exists('dashboard.php') ? 'dashboard.php' : 'pages/dashboard.php';
            die("
                <div style='font-family:sans-serif; padding:2rem; max-width:500px; margin:auto;'>
                    <h2 style='color:#b91c1c;'>403 Security Validation Failed</h2>
                    <p>The form submission security token (CSRF) is invalid or expired. Please refresh and try again.</p>
                    <p style='color:#666; font-size:0.9rem;'>This protection prevents unauthorized cross-site request forgery.</p>
                    <a href='{$dashUrl}'>&larr; Return to Dashboard</a>
                </div>
            ");
        }
        return true;
    }
}
?>
