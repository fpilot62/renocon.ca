<?php
header("X-Frame-Options: ALLOWALL");
header("Content-Security-Policy: frame-ancestors 'self' https://payroll.renocon.ca http://payroll.renocon.ca");
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll_reset.php';

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ident = trim((string)($_POST['email'] ?? $_POST['username'] ?? ''));
    $result = payroll_request_password_reset($pdo, $ident);
    if (!empty($result['ok'])) {
        $message = $result['message'] ?? 'If that account exists and has an email, we sent a reset link. It expires in 1 hour.';
    } else {
        $error = $result['error'] ?? 'Could not send reset email.';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password</title>
    <style>
        body { font-family: sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f4f7f6; }
        .card { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); width: 320px; text-align: center; }
        input { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #f97316; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .ok { color: #166534; font-size: 14px; }
        .err { color: #b91c1c; font-size: 14px; }
        a { color: #334155; font-size: 14px; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Forgot Password</h2>
        <?php if ($message): ?><p class="ok"><?= htmlspecialchars($message) ?></p><?php endif; ?>
        <?php if ($error): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <form method="POST">
            <input type="text" name="email" placeholder="Username, PIN, or email" required autocapitalize="none">
            <button type="submit">Send Reset Link</button>
        </form>
        <p><a href="login_kiosk.php">Back to sign in</a></p>
    </div>
</body>
</html>
