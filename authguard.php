<?php
function requireRole($allowedRoles, $pdo) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }

    if (!in_array($_SESSION['role'], $allowedRoles)) {
        http_response_code(403);
        die("<h3>403 Forbidden</h3><p>Access Denied: You do not have permission to access this page.</p>");
    }
}
?>