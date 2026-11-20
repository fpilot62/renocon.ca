<?php
/** Path: /engine/update_shift_time.php */
require_once '../db.php';
require_once __DIR__ . '/attendance_audit_lib.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $in = $_POST['clock_in'];
    $out = $_POST['clock_out'];

    try {
        $aaId = (int)$id;
        $aaBefore = aa_snapshot($pdo, $aaId);
        $stmt = $pdo->prepare("UPDATE attendance SET clock_in = ?, clock_out = ? WHERE id = ?");
        $stmt->execute([$in, $out, $id]);
        aa_log($pdo, 'update', $aaId, $aaBefore, null, 'Update shift time');
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}