<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    header("Location: pages/dashboard.php");
} else {
    header("Location: pages/login.php");
}
exit;
