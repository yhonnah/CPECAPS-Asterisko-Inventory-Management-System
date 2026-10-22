<?php
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
}

function requireRole($allowedRoles, $pdo) {
    requireLogin();

    if (!in_array($_SESSION['role'], $allowedRoles)) {
        // FR-15: Record unauthorized attempt in the audit log
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
            VALUES (NULL, NULL, NULL, 'unauthorized_access', ?, ?)
        ");
        $attemptedUrl = $_SERVER['REQUEST_URI'];
        $stmt->execute(["Blocked access to restricted endpoint: $attemptedUrl", $_SESSION['username']]);

        http_response_code(403);
        die("
            <div style='font-family:sans-serif; padding:2rem; max-width:500px; margin:auto;'>
                <h2 style='color:#b91c1c;'>403 Access Denied (FR-15)</h2>
                <p>Your role (<b>{$_SESSION['role']}</b>) is restricted from accessing supplier pricing or financial summaries.</p>
                <p style='color:#666; font-size:0.9rem;'>This attempt has been recorded in the immutable Audit Trail.</p>
                <a href='dashboard.php'>&larr; Return to Dashboard</a>
            </div>
        ");
    }
}
?>