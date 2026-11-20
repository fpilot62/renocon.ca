<?php
require_once __DIR__ . '/../db.php';
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
if ($token === '') { http_response_code(400); echo 'No token provided.'; exit; }

$stmt = $pdo->prepare("SELECT user_id, email FROM alarm_users WHERE reset_token = ? AND token_expiry > NOW() LIMIT 1");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { http_response_code(400); echo 'Invalid or expired token.'; exit; }

$done = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['password2'] ?? $password);
    if (strlen($password) < 4) {
        $error = 'Password must be at least 4 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $upd = $pdo->prepare("UPDATE alarm_users SET password = ?, reset_token = NULL, token_expiry = NULL WHERE user_id = ?");
        $upd->execute([$hash, (int)$user['user_id']]);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset AlarmPulse password</title>
  <style>
    body { font-family: sans-serif; display:flex; justify-content:center; align-items:center; min-height:100vh; margin:0; background:#0A1628; color:#F0F4FF; }
    .card { background:#111E35; padding:28px; border-radius:12px; width:320px; text-align:center; }
    input { width:100%; padding:12px; margin:8px 0; border-radius:8px; border:1px solid #1E3050; background:#1C2D4A; color:#F0F4FF; box-sizing:border-box; }
    button { width:100%; padding:12px; background:#FF6B1A; color:#fff; border:0; border-radius:8px; font-weight:700; }
    .ok { color:#2ECC71; } .err { color:#E74C3C; }
  </style>
</head>
<body>
  <div class="card">
    <h2>AlarmPulse</h2>
    <?php if ($done): ?>
      <p class="ok">Password updated. Open AlarmPulse and sign in with your email.</p>
    <?php else: ?>
      <?php if ($error): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
      <form method="POST">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <input type="password" name="password" placeholder="New password" required minlength="4">
        <input type="password" name="password2" placeholder="Confirm password" required minlength="4">
        <button type="submit">Save new password</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
