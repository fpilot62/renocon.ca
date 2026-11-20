<?php
require_once 'db.php';

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    // Check if token exists and has not expired
    $stmt = $pdo->prepare("SELECT * FROM users WHERE reset_token = ? AND token_expiry > NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) { die("Invalid or expired token."); }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $new_plain_password = $_POST['password'];
        $new_hash = password_hash($new_plain_password, PASSWORD_DEFAULT); // Hash the normal password

        // Update password and clear the token
        $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, token_expiry = NULL WHERE id = ?");
        $stmt->execute([$new_hash, $user['id']]);

        echo "Password updated! <a href='login.php'>Login here</a>";
        exit;
    }
} else { die("No token provided."); }
?>
<form method="POST" style="text-align:center; padding-top:50px; font-family:sans-serif;">
    <h2>Enter New Password</h2>
    <input type="text" name="password" placeholder="New Password (e.g. 7265)" required style="padding:10px;"><br><br>
    <button type="submit" style="padding:10px 20px; background:green; color:white; border:none;">Update Password</button>
</form>