<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = trim($_POST['password'] ?? '');

    if (Auth::login($user, $pass)) {
        header('Location: dashboard.php');
        exit;
    } else {
        $error = 'Invalid username or password!';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - GameLand Web Panel</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">
        <div style="text-align: center; margin-bottom: 1rem;">
            <span class="badge-gl" style="font-size: 0.9rem; padding: 0.3rem 0.8rem;">GAMELAND</span>
        </div>
        <h1 class="login-title">Control Panel</h1>
        <p class="login-sub">Sign in to manage your Counter-Strike 1.6 server</p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'expired'): ?>
            <div class="alert alert-warning">Your session expired. Please log in again.</div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" class="input-field" style="width: 100%;" required autofocus autocomplete="username">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="input-field" style="width: 100%;" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.75rem; margin-top: 1rem;">Sign In</button>
        </form>
    </div>
</div>

</body>
</html>
