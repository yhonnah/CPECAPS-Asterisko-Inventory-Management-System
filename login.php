<?php
require 'db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        header("Location: dashboard.php");
        exit();
    } else {
        $error = 'Invalid username or password.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aste Inventory Login</title>
    <link rel="stylesheet" href="styles.css">

</head>
<body class="bg-stone-100 flex items-center justify-center min-h-screen p-4">
    <div class="bg-white p-6 rounded-xl shadow-md w-full max-w-sm">
        <h1 class="text-2xl font-bold text-stone-800 mb-1">Asterisko Cafe</h1>
        <p class="text-sm text-stone-500 mb-6">Inventory Management System</p>

        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4 text-sm">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-stone-700">Username</label>
                <input type="text" name="username" required 
                       class="mt-1 w-full p-2.5 border rounded-lg focus:ring-2 focus:ring-amber-600">
            </div>
            <div>
                <label class="block text-sm font-medium text-stone-700">Password</label>
                <input type="password" name="password" required 
                       class="mt-1 w-full p-2.5 border rounded-lg focus:ring-2 focus:ring-amber-600">
            </div>
            <button type="submit" 
                    class="w-full bg-amber-700 hover:bg-amber-800 text-white font-semibold py-2.5 rounded-lg transition">
                Sign In
            </button>
        </form>

        <div class="mt-6 text-xs text-stone-500 border-t pt-4">
            <p class="font-semibold mb-1">Demo Accounts (Password: <code class="bg-stone-200 px-1">password</code>):</p>
            <ul class="space-y-1">
                <li>• <b>manager_anna</b> (Admin - Full Access)</li>
                <li>• <b>barista_ben</b> (Staff - No Prices/Finance)</li>
                <li>• <b>owner_claire</b> (Owner - Finance & Stock)</li>
            </ul>
        </div>
    </div>
</body>
</html>