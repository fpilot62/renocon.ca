<?php
header('Content-Type: application/json');
require_once 'db.php'; 

$user_id = $_GET['user_id'];

// We look for the absolute latest entry for this user, ignoring the org_id for a moment
$stmt = $pdo->prepare("SELECT status FROM attendance WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$user_id]);
$status = $stmt->fetchColumn();

// If status is 1 (Clocked In), the Flutter app will show "Start Break"
// If status is 2 (On Break), the Flutter app will show "End Break"
echo json_encode(["status" => (int)$status]);