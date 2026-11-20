<?php
/** Path: /update_attendance.php */
require_once 'db.php';
require_once __DIR__ . '/engine/attendance_audit_lib.php';

$id = (int)$_GET['id'];
$column = $_GET['column']; // This will be "status", "pending_reg", or "pending_bal"
$val = $_GET['val'];

// List of allowed columns for security
$allowed = ['status', 'pending_reg', 'pending_bal'];

if (in_array($column, $allowed)) {
    $sql = "UPDATE attendance SET $column = ? WHERE id = ?";
    $aaBefore = aa_snapshot($pdo, $id);
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$val, $id]);
    aa_log($pdo, 'update', $id, $aaBefore, null, 'Update ' . $column);
    echo "Success";
}