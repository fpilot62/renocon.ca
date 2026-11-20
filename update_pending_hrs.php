<?php
require_once 'engine.php';

$id    = (int)($_GET['id'] ?? 0);
$type  = $_GET['type'] ?? ''; // 'reg' or 'bal'
$val   = (float)($_GET['val'] ?? 0);

if ($id > 0 && ($type === 'reg' || $type === 'bal')) {
    $column = ($type === 'reg') ? 'pending_reg' : 'pending_bal';
    
    $stmt = $pdo->prepare("UPDATE attendance SET $column = ? WHERE id = ?");
    $stmt->execute([$val, $id]);
    
    echo "Success";
} else {
    echo "Invalid Request";
}