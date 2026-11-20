<?php
/** Path: /engine/update_shift_time.php */
require_once '../db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $in = $_POST['clock_in'];
    $out = $_POST['clock_out'];

    try {
        $stmt = $pdo->prepare("UPDATE attendance SET clock_in = ?, clock_out = ? WHERE id = ?");
        $stmt->execute([$in, $out, $id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}