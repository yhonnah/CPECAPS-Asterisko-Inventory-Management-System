<?php
require 'db.php';
require 'authguard.php';

// Only Admin and Owner can view financial endpoints. Staff will be blocked & logged!
requireRole(['Admin', 'Owner'], $pdo);
?>
<h1>Financial Summary Report (Authorized View)</h1>
<p>Welcome, <?= htmlspecialchars($_SESSION['username']) ?>. We will add the Excel export here.</p>
<a href="dashboard.php">Back to Dashboard</a>