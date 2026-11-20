<?php
session_start();
$_SESSION = [];
session_destroy();
setcookie('ASTE_SESS', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
header("Location: login.php");
exit;
