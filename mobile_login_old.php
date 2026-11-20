<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
require_once 'db.php';

$user_input = $_GET['username'] ?? ''; // App will send ID/PIN here
$password   = $_GET['password'] ?? '';

try {
    $stmt = $pdo->prepare("SELECT id, Name, password FROM users WHERE user = ? OR pin = ? LIMIT 1");
    $stmt->execute([$user_input, $user_input]);
    $user = $stmt->fetch();

    $is_valid = false;
    if ($user) {
        if (strpos($user['password'], '$2y$') === 0) {
            $is_valid = password_verify($password, $user['password']);
        } else {
            $is_valid = ($password === $user['password']);
        }
    }

    if ($is_valid) {
        echo json_encode([
            "success" => true,
            "user_id" => $user['id'],
            "name" => $user['Name']
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "Invalid Credentials"]);
    }
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>