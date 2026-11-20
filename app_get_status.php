<?php
header("Content-Type: application/json");
require_once 'db.php';

$user_id = $_GET['user_id'] ?? 0;

// Fetch the most recent row for this user
$stmt = $pdo->prepare("SELECT status FROM attendance WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$user_id]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(["status" => "not_working"]);
} else {
    $db_status = (int)$row['status'];
    
    if ($db_status === 1) {
        $status = "working";
    } elseif ($db_status === 3) {
        $status = "on_break";
    } else {
        $status = "not_working";
    }
    
    echo json_encode(["status" => $status]);
}
?>