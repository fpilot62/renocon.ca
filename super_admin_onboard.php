<?php
/** Path: /payroll/super_admin_onboard.php */
session_start();
require_once 'db.php';

// --- SECURITY: Only you can access this ---
$master_email = 'your_private_email@renocon.ca'; // CHANGE THIS to your actual email
if (!isset($_SESSION['user_email']) || $_SESSION['user_email'] !== $master_email) {
    die("Access Denied: Super Admin Privileges Required.");
}

$msg = "";

if (isset($_POST['onboard_org'])) {
    $org_name   = trim($_POST['org_name']);
    $max_users  = (int)$_POST['max_users'];
    $expiry     = $_POST['expiry_date'];
    $admin_email = trim($_POST['admin_email']);
    $admin_pass  = password_hash($_POST['admin_password'], PASSWORD_DEFAULT);
    $modules     = $_POST['modules'] ?? []; // Array from checkboxes

    try {
        $pdo->beginTransaction();

        // 1. Create the Organization
        $stmt = $pdo->prepare("INSERT INTO organizations (org_name, max_users, plan_expiry, status) VALUES (?, ?, ?, 'active')");
        $stmt->execute([$org_name, $max_users, $expiry]);
        $new_org_id = $pdo->lastInsertId();

        // 2. Assign Modules
        $mod_stmt = $pdo->prepare("INSERT INTO org_modules (org_id, module_name, is_active) VALUES (?, ?, 1)");
        foreach ($modules as $mod) {
            $mod_stmt->execute([$new_org_id, $mod]);
        }

        // 3. Create the Organization Admin User
        $user_stmt = $pdo->prepare("INSERT INTO users (email, password, user_role, org_id) VALUES (?, ?, 1, ?)");
        $user_stmt->execute([$admin_email, $admin_pass, $new_org_id]);

        $pdo->commit();
        $msg = "<div style='color:green; padding:10px; border:1px solid green;'>Successfully onboarded $org_name! Admin: $admin_email</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "<div style='color:red; padding:10px; border:1px solid red;'>Error: " . $e->getMessage() . "</div>";
    }
}

include 'menu_include.php';
?>

<style>
    .sa-card { max-width: 700px; margin: 40px auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); font-family: sans-serif; }
    .section-title { border-bottom: 2px solid #008ba3; padding-bottom: 5px; margin-top: 25px; color: #008ba3; font-size: 18px; }
    .form-group { margin-bottom: 15px; }
    label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 13px; }
    input[type="text"], input[type="email"], input[type="password"], input[type="number"], input[type="date"] {
        width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;
    }
    .mod-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; background: #f9f9f9; padding: 15px; border-radius: 4px; }
    .btn-create { background: #008ba3; color: white; border: none; padding: 15px 30px; width: 100%; border-radius: 4px; font-weight: bold; cursor: pointer; margin-top: 20px; font-size: 16px; }
</style>

<div class="sa-card">
    <h2><i class="fas fa-rocket"></i> New Client Onboarding</h2>
    <?= $msg ?>

    <form method="POST">
        <div class="section-title">1. Organization Details</div>
        <div class="form-group">
            <label>Organization Name</label>
            <input type="text" name="org_name" placeholder="e.g., Popeyes Westbank" required>
        </div>
        <div style="display:flex; gap:20px;">
            <div class="form-group" style="flex:1;">
                <label>User Limit (Seats)</label>
                <input type="number" name="max_users" value="10" required>
            </div>
            <div class="form-group" style="flex:1;">
                <label>Subscription Expiry</label>
                <input type="date" name="expiry_date" required>
            </div>
        </div>

        <div class="section-title">2. Enable Paid Modules</div>
        <div class="mod-grid">
            <label><input type="checkbox" name="modules[]" value="clockin" checked> Clock-In System</label>
            <label><input type="checkbox" name="modules[]" value="scheduling"> Scheduling / Roster</label>
            <label><input type="checkbox" name="modules[]" value="inventory"> Inventory Management</label>
            <label><input type="checkbox" name="modules[]" value="payroll" checked> Payroll Reporting</label>
        </div>

        <div class="section-title">3. Primary Admin Account</div>
        <div class="form-group">
            <label>Admin Email</label>
            <input type="email" name="admin_email" placeholder="manager@clientcompany.com" required>
        </div>
        <div class="form-group">
            <label>Temporary Password</label>
            <input type="password" name="admin_password" required>
        </div>

        <button type="submit" name="onboard_org" class="btn-create">CREATE & ACTIVATE CLIENT</button>
    </form>
</div>