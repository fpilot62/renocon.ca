<?php
require_once 'engine.php';
require_once __DIR__ . '/engine/attendance_audit_lib.php';

$id    = (int)($_GET['id'] ?? 0);
$type  = $_GET['type'] ?? ''; // 'reg' or 'bal'
$val   = (float)($_GET['val'] ?? 0);

if ($id > 0 && ($type === 'reg' || $type === 'bal')) {
    $column = ($type === 'reg') ? 'pending_reg' : 'pending_bal';
    
    $aaBefore = aa_snapshot($pdo, $id);
    $stmt = $pdo->prepare("UPDATE attendance SET $column = ? WHERE id = ?");
    $stmt->execute([$val, $id]);
    aa_log($pdo, 'update', $id, $aaBefore, null, 'Pending hours');
    
    echo "Success";
} else {
    echo "Invalid Request";
}