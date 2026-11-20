<?php
/** Path: /delete_user.php */
ob_start(); // Start buffering to prevent header errors
$required_level = 10; 
require_once 'engine.php';

$id = intval($_GET['id'] ?? 0);

// Safety: Don't let a user delete themselves
if ($id > 0 && $id != ($_SESSION['user_id'] ?? 0)) {
    try {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
    } catch (Exception $e) {
        // If there's an error, we can log it here
    }
}

// Clear the buffer and redirect
ob_end_clean(); 
header("Location: manage_users.php?msg=deleted");
exit;