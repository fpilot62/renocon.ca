<?php
/** Path: /toggle_status.php */
require_once 'db.php';

$id = $_GET['id'] ?? null;
$current = $_GET['current'] ?? '';

if ($id) {
    // Switch between ACTIVE and INACTIVE
    $newStatus = (strtoupper($current) === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE';
    
    $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $id]);
}

header("Location: manage_users.php");
exit;