<?php
require_once 'db.php';
session_start();

// Security: Only Admins can toggle status
if (($_SESSION['user_level'] ?? 99) > 30) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$id = (int)$_GET['id'];

// 1. Get current status
$stmt = $pdo->prepare("SELECT active FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

// 2. Flip it
$new_status = ($user['active'] == 1) ? 0 : 1;
$update = $pdo->prepare("UPDATE users SET active = ? WHERE id = ?");
$update->execute([$new_status, $id]);

echo json_encode(['success' => true, 'new_status' => $new_status]);