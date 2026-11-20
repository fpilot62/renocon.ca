<?php
/** Path: /login_kiosk.php */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once __DIR__ . '/engine/login_auth_lib.php';

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

            $user_lvl = (int)$user['user_role'];
            $org_id   = (int)$user['org_id'];
            $assigned = $user['assigned_site_ids'] ?? '';

            if (!function_exists('sip_sites_active_sql')) {
                require_once __DIR__ . '/engine/site_status_lib.php';
            }
            $active_sql = sip_sites_active_sql('');
            if ($user_lvl <= 10) {
                $_SESSION['access_scope'] = 'all';
                $site_stmt = $pdo->query("SELECT SId as id, nick_name FROM sites WHERE $active_sql ORDER BY nick_name ASC");
            } else {
                $_SESSION['access_scope'] = 'restricted';
                $clean_ids = preg_replace('/[^0-9,]/', '', $assigned);
                if (!empty($clean_ids)) {
                    $sql = "SELECT SId as id, nick_name FROM sites WHERE org_id = ? AND SId IN ($clean_ids) AND $active_sql ORDER BY nick_name ASC";
                } else {
                    $sql = "SELECT SId as id, nick_name FROM sites WHERE org_id = ? AND $active_sql ORDER BY nick_name ASC";
                }
                $site_stmt = $pdo->prepare($sql);
                $site_stmt->execute([$org_id]);
            }

            $allowed_sites = $site_stmt->fetchAll(PDO::FETCH_ASSOC);
            $_SESSION['inventory_sites'] = $allowed_sites;
            $_SESSION['active_rid'] = !empty($allowed_sites) ? $allowed_sites[0]['id'] : 0;

            $dest = sip_post_login_url($pdo, $user);
            if (stripos((string)$dest, 'kiosk.php') !== false) {
                if (!function_exists('sip_org_restrain_clockin')) {
                    require_once __DIR__ . '/engine/org_modules_lib.php';
                }
                $sep = (strpos($dest, '?') !== false) ? '&' : '?';
                if (sip_org_restrain_clockin($pdo, $org_id)) {
                    $dest .= $sep . 'policy=restrain';
                } else {
                    $dest .= $sep . 'policy=reason';
                }
            }
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
    <!-- pwa-head -->
    <?php if (file_exists(__DIR__ . '/pwa_head.php')) include __DIR__ . '/pwa_head.php'; ?>

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
        <div class="text-center mt-4">
    <p class="text-muted small">Don't have an account yet?</p>
    <a href="register1.php" class="btn btn-outline-info btn-sm px-4">Request Employee Access</a>
    <div class="mt-3 text-center">
    <p class="text-muted small">New staff? <a href="register1.php" class="font-weight-bold">Register here</a> for admin approval.</p>
</div>
</div>
    </div>
    
</body>
</html>