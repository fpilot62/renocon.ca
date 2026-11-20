<?php
/** Path: /bulk_update.php */
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['user_ids'])) {
    $field = $_POST['field_to_update'];
    $newValue = $_POST['new_value'];
    $ids = $_POST['user_ids'];

    // Security check
    $allowed = ['org_id', 'user_role', 'pin'];
    if (!in_array($field, $allowed)) { die("Invalid field."); }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE users SET $field = ? WHERE id IN ($placeholders)";
    
    $stmt = $pdo->prepare($sql);
    $params = array_merge([$newValue], $ids);

    if ($stmt->execute($params)) {
        // Success: Redirect back with the count of updated users
        header("Location: manage_users.php?success=" . count($ids));
        exit;
    } else {
        die("Database error occurred.");
    }
} else {
    header("Location: manage_users.php");
    exit;
}