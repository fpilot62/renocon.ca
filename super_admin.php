<?php
/** Path: /super_admin.php */
session_start();
require_once 'db.php';
if ($_SESSION['user_role'] != 1) { header("Location: logout.php"); exit; }

// Org Switcher
if (isset($_POST['set_org'])) { $_SESSION['org_id'] = $_POST['new_org']; }
$current_org = $_SESSION['org_id'] ?? 1;

$orgs = $pdo->query("SELECT * FROM organizations")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head><title>Super Admin | Renocon</title></head>
<body style="background:#0f172a; color:white; font-family:sans-serif; margin:0;">
    <div style="background:#1e293b; padding:20px; display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #0097a7;">
        <h2 style="margin:0;">SYSTEM CONTROL <span style="font-size:12px; color:#0097a7;">ROLE 1</span></h2>
        <form method="POST" id="orgForm">
            <label style="font-size:12px; color:#94a3b8;">ACTIVE CONTEXT:</label>
            <select name="new_org" onchange="this.form.submit()" style="padding:8px; border-radius:4px; background:#0f172a; color:white; border:1px solid #334155;">
                <?php foreach($orgs as $o): ?>
                    <option value="<?= $o['id'] ?>" <?= $o['id']==$current_org ? 'selected':'' ?>><?= $o['org_name'] ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="set_org" value="1">
        </form>
    </div>

    <div style="padding:30px; display:grid; grid-template-columns: 1fr 1fr; gap:20px; max-width:1200px; margin:auto;">
        <div style="background:#1e293b; padding:20px; border-radius:12px;">
            <h3 style="color:#0097a7; margin-top:0;">Global Management</h3>
            <a href="super_admin_onboard.php" style="display:block; color:#38bdf8; margin-bottom:10px; text-decoration:none;">🚀 Onboard New Client</a>
            <a href="manage_all_orgs.php" style="display:block; color:#38bdf8; margin-bottom:10px; text-decoration:none;">🏢 Manage Organizations</a>
            <a href="manage_users.php" style="display:block; color:#38bdf8; margin-bottom:10px; text-decoration:none;">👥 Global User Directory</a>
        </div>

        <div style="background:#1e293b; padding:20px; border-radius:12px;">
            <h3 style="color:#0097a7; margin-top:0;">Live Tools</h3>
            <a href="manager_dashboard.php" style="display:block; color:#fbbf24; margin-bottom:10px; text-decoration:none;">📊 View Attendance (Current Org)</a>
            <a href="kiosk.php" style="display:block; color:#fbbf24; margin-bottom:10px; text-decoration:none;">📟 Open Kiosk Mode</a>
            <hr style="border:0; border-top:1px solid #334155;">
            <a href="logout.php" style="color:#ef4444; text-decoration:none; font-size:14px;">Logout Securely</a>
        </div>
    </div>
</body>
</html>