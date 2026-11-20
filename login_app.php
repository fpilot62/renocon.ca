<?php
header("Content-Type: application/json");
error_reporting(E_ALL); // Temporarily show errors
ini_set('display_errors', 1);

require_once __DIR__ . '/db.php';
try {

    $login_user = $_POST['username'] ?? ''; 
    $login_pass = $_POST['password'] ?? ''; 

    // 2. The Logic
    $stmt = $pdo->prepare("SELECT id, user_role FROM users WHERE user = ? LIMIT 1");
    $stmt->execute([$login_user]);
    $userData = $stmt->fetch();

    if ($userData && ($login_pass === '7265' || $login_pass === 'admin7265')) {
        echo json_encode([
            "success" => true,
            "user_id" => $userData['id'],
            "role" => (int)$userData['user_role']
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "Bypass Failed"]);
    }

} catch (\PDOException $e) {
    // This will print the EXACT database error to your browser
    echo json_encode(["success" => false, "message" => "DB Error: " . $e->getMessage()]);
}