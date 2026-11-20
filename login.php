<?php
/** Path: /login.php */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once __DIR__ . '/engine/login_auth_lib.php';
require_once __DIR__ . '/app_activity_lib.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_input = sip_normalize_login_ident($_POST['username'] ?? '');
    $password   = (string)($_POST['password'] ?? '');

    try {
        $user = sip_find_login_user($pdo, $user_input);
        $is_valid = $user && sip_verify_login_secret($user, $password);

        if ($is_valid) {
            $_SESSION['user_id']        = $user['id'];
            $_SESSION['user_full_name'] = $user['Name'];
            $_SESSION['org_id']         = (int)$user['org_id'];
            $_SESSION['user_level']     = (int)$user['user_role'];
            $_SESSION['user_role']      = (int)$user['user_role'];
            $_SESSION['assigned_site_ids'] = $user['assigned_site_ids'] ?? '';
            $_SESSION['access_scope']   = ((int)$user['user_role'] <= 10) ? 'all' : 'restricted';
            app_activity_log($pdo, 'payroll', 'login', $user['id'], $user['Name'] ?? '', $user_input, 'website');
            require_once __DIR__ . '/contact_lib.php';
            if (contact_needs_prompt($user)) {
                $_SESSION['contact_prompt'] = 1;
            } else {
                unset($_SESSION['contact_prompt']);
            }

            $dest = sip_post_login_url($pdo, $user);
            header('Location: ' . $dest);
            exit;
        } else {
            $error = "Invalid Credentials";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll Login</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #1e293b; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-box { background: white; padding: 40px; border-radius: 12px; width: 340px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); text-align: center; }
        h2 { color: #1e293b; margin-bottom: 25px; }
        input { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; }
        button { width: 100%; padding: 12px; background: #0891b2; color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; margin-top: 10px; }
        .error { color: #dc2626; background: #fee2e2; padding: 10px; border-radius: 4px; border: 1px solid #fecaca; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>Payroll Login</h2>
        <?php if(isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Username, email, or PIN" required>
            <input type="password" name="password" placeholder="Password or PIN" required>
            <button type="submit">LOGIN</button>
        </form>
    </div>
    <p class="ecommi-devby" style="text-align:center;font-size:11px;line-height:1.4;color:#94a3b8;padding:10px 12px;margin:0;">
      Developed by <a href="https://ecommi.com" target="_blank" rel="noopener noreferrer" style="color:#64748b;text-decoration:underline;">eComm i</a>
    </p>
</body>
</html>