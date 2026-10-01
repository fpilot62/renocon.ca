<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

admin_boot_session();
if (user_count() > 0) {
    header('Location: login.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');
    if ($username === '' || strlen($password) < 10 || $password !== $confirm) {
        $error = 'Use a username and a password of at least 10 characters. Both password fields must match.';
    } else {
        $stmt = admin_db()->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'superadmin', gmdate('c')]);
        $_SESSION['user_id'] = (int) admin_db()->lastInsertId();
        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Create superadmin | Ecommi Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="auth">
  <form class="card" method="post">
    <h1>Create the first superadmin</h1>
    <p>This is a one-time setup for the Ecommi administration hub. Do not reuse a public website password.</p>
    <?php if ($error !== ''): ?><p class="error"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <label>Username <input name="username" required autocomplete="username"></label>
    <label>Password <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
    <label>Confirm password <input type="password" name="confirm" required minlength="10" autocomplete="new-password"></label>
    <button type="submit">Create superadmin</button>
  </form>
</body>
</html>
