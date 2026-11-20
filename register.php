<?php
/** Path: /payroll/register.php */
session_start();
require_once 'db.php';

// Auth Guard
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] != 1) { 
    header("Location: login.php"); exit; 
}

$org_id = $_SESSION['org_id'] ?? 1;
$msg = "";

if (isset($_POST['register'])) {
    // --- NEW: SAAS USER LIMIT GUARD ---
    $org_stmt = $pdo->prepare("SELECT max_users FROM organizations WHERE id = ?");
    $org_stmt->execute([$org_id]);
    $max_allowed = $org_stmt->fetchColumn();

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ?");
    $count_stmt->execute([$org_id]);
    $current_total = $count_stmt->fetchColumn();

    if ($current_total >= $max_allowed) {
        $msg = "<span style='color:red;'>Limit Reached: Your plan allows $max_allowed users. Please upgrade.</span>";
    } else {
        // --- EXISTING REGISTRATION LOGIC ---
        $email = trim($_POST['email']);
        $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role = $_POST['role'];
        
        $stmt = $pdo->prepare("INSERT INTO users (email, password, user_role, org_id) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$email, $pass, $role, $org_id])) {
            $msg = "<span style='color:green;'>User registered successfully!</span>";
        }
    }
}
include 'menu_include.php';
?>
<div style="padding:40px; max-width:500px; margin:auto; background:white; border-radius:8px; box-shadow:0 4px 10px rgba(0,0,0,0.1);">
    <h2>Add New Employee</h2>
    <p><?= $msg ?></p>
    <form method="POST">
        <label>Email Address</label><br>
        <input type="email" name="email" required style="width:100%; padding:10px; margin-bottom:15px;"><br>
        <label>Temporary Password</label><br>
        <input type="password" name="password" required style="width:100%; padding:10px; margin-bottom:15px;"><br>
        <label>Role</label>
            <select name="role" class="form-control">
                <option value="Employee">Employee</option>
                <option value="Supervisor">Supervisor</option>
                <option value="Admin">Admin</option>
                <?php if ($_SESSION['Type'] === 'Super Admin'): ?>
                    <option value="Super Admin">Super Admin</option>
                <?php endif; ?>
            </select>
        <button type="submit" name="register" style="width:100%; padding:15px; background:#008ba3; color:white; border:none; font-weight:bold; cursor:pointer;">REGISTER USER</button>
    </form>
</div>