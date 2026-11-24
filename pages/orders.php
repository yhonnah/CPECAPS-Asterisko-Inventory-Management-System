<?php
// Legacy route kept as a redirect after renaming the Orders page to Sales.
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: sales.php' . ($qs !== '' ? '?' . $qs : ''), true, 302);
exit;
