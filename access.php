<?php
/** Path: /access.php */
ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = $_POST['username'] ?? '';
    $p = $_POST['pin'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE user = ? AND pin = ? LIMIT 1");
    $stmt->execute([$u, $p]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_name']  = $user['Name'];
        $_SESSION['user_level'] = $user['user_role'];
        $_SESSION['org_id']     = $user['org_id'];
        header("Location: index.php");
        exit;
    } else {
        die("Invalid credentials. <a href='kiosk.php'>Try again</a>");
    }
}
echo "System Ready. Point your kiosk form to access.php";
ob_end_flush();