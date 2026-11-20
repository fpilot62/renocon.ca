<?php
/** Path: /update_attendance.php */
require_once 'db.php';

$id = (int)$_GET['id'];
$column = $_GET['column']; // This will be "status", "pending_reg", or "pending_bal"
$val = $_GET['val'];

// List of allowed columns for security
$allowed = ['status', 'pending_reg', 'pending_bal'];

if (in_array($column, $allowed)) {
    $sql = "UPDATE attendance SET $column = ? WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$val, $id]);
    echo "Success";
}