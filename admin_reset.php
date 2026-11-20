<?php
session_start();
require_once 'db.php';
// Only the Admin (role 1) can access this
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] != 1) { die("Access Denied"); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];
    $new_plain_pass = $_POST['new_password'];
    
    // Hash the plain text so it's saved correctly
    $new_hash = password_hash($new_plain_pass, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
        $stmt->execute([$new_hash, $email]);
        $msg = "Password for $email has been updated to: $new_plain_pass";
    } catch (PDOException $e) {
        $msg = "Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head><title>Reset Staff Password</title></head>
<body style="font-family:sans-serif; text-align:center; padding:50px;">
    <h2>Admin: Reset Staff Password</h2>
    <?php if(isset($msg)) echo "<p style='color:green;'>$msg</p>"; ?>
    <form method="POST">
        <input type="email" name="email" placeholder="Employee Email" required style="padding:10px;"><br><br>
        <input type="text" name="new_password" placeholder="New Normal Password (e.g. 1234)" required style="padding:10px;"><br><br>
        <button type="submit" style="padding:10px 20px; background:#007bff; color:white; border:none; cursor:pointer;">Update Password</button>
    </form>
    <br><a href="manager.php">Back to Dashboard</a>
</body>
</html>