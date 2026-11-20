<?php
require_once 'auth.php';
header('Content-Type: application/json');

$user = authenticate();
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = getDB();
$stmt = $db->query("SELECT user_id, name, email, tel FROM alarm_users ORDER BY name ASC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'users' => $users]);