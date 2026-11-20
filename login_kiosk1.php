<?php
/** Path: /login_kiosk.php */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_input = $_POST['username'] ?? ''; 
    $password   = $_POST['password'] ?? '';

    try {
        // --- FIXED: DEMO CHECK LOGIC FOR ID 10083 ---
        $is_demo_login = ($user_input === 'demo' && $password === '1234');
        $is_valid = false;
        $user = null;

        if ($is_demo_login) {
            // Target the exact ID from your screenshot
            $stmt = $pdo->prepare("SELECT id, Name, password, user_role, org_id, assigned_site_ids FROM users WHERE id = 10083 LIMIT 1");
            $stmt->execute();
            $user = $stmt->fetch();
            
            // Fallback: If 10083 doesn't exist in DB, create manual object so login doesn't fail
            if (!$user) {
                $user = [
                    'id' => 10083,
                    'Name' => 'Demo User',
                    'user_role' => 1, 
                    'org_id' => 1, 
                    'assigned_site_ids' => ''
                ];
            }
            $is_valid = true; 
        } else {
            // Standard fetch for real users
            $stmt = $pdo->prepare("SELECT id, Name, password, user_role, org_id, assigned_site_ids FROM users WHERE user = ? OR pin = ? LIMIT 1");
            $stmt->execute([$user_input, $user_input]);
            $user = $stmt->fetch();

            if ($user) {
                if (strpos($user['password'], '$2y$') === 0) {
                    $is_valid = password_verify($password, $user['password']);
                } else {
                    $is_valid = ($password === $user['password']);
                }
            }
        }

        if ($is_valid && $user) {
            // Set Standard Session Variables
            $_SESSION['user_id']        = $user['id'];
            $_SESSION['user_full_name'] = $user['Name'];
            $_SESSION['org_id']         = (int)$user['org_id'];
            $_SESSION['user_level']     = (int)$user['user_role'];
            $_SESSION['user_role']      = (int)$user['user_role'];
            $_SESSION['assigned_site_ids'] = $user['assigned_site_ids'] ?? '';
            
            // --- FLAG FOR DEMO PROTECTION ---
            $_SESSION['is_demo'] = $is_demo_login;

            $user_lvl = (int)$user['user_role'];
            $org_id   = (int)$user['org_id'];
            $assigned = $user['assigned_site_ids'] ?? '';

            // --- THE GLOBAL SCOPE GATE ---
            // If it's a demo, force 'restricted' scope so they don't see other Orgs
            if ($user_lvl <= 10 && !$_SESSION['is_demo']) {
                $_SESSION['access_scope'] = 'all';
                $site_stmt = $pdo->query("SELECT SId as id, nick_name FROM sites ORDER BY nick_name ASC");
            } else {
                $_SESSION['access_scope'] = 'restricted';
                $clean_ids = preg_replace('/[^0-9,]/', '', $assigned);
                if (!empty($clean_ids)) {
                    $sql = "SELECT SId as id, nick_name FROM sites WHERE org_id = ? AND SId IN ($clean_ids) ORDER BY nick_name ASC";
                } else {
                    $sql = "SELECT SId as id, nick_name FROM sites WHERE org_id = ? ORDER BY nick_name ASC";
                }
                $site_stmt = $pdo->prepare($sql);
                $site_stmt->execute([$org_id]);
            }

            $allowed_sites = $site_stmt->fetchAll(PDO::FETCH_ASSOC);
            $_SESSION['inventory_sites'] = $allowed_sites;
            
            if (!empty($allowed_sites)) {
                $_SESSION['active_rid'] = $allowed_sites[0]['id'];
            } else {
                $_SESSION['active_rid'] = 0;
            }

            header("Location: kiosk.php");
            exit;
        } else {
            $error = "Invalid credentials. Please try again.";
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
        .demo-hint { margin-top: 15px; font-size: 0.85em; color: #64748b; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>Payroll Login</h2>
        <?php if(isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Username or PIN" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">LOGIN</button>
        </form>
        
        <div class="demo-hint">
            <p>Testing? Use <b>demo</b> / <b>1234</b></p>
        </div>

        <div style="margin-top: 20px; border-top: 1px solid #eee; padding-top: 10px;">
            <p style="font-size: 0.8em; color: #64748b;">New staff? <a href="register1.php">Register here</a></p>
        </div>
    </div>
</body>
</html>