<?php
/** Path: /revert_payroll.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_level'] > 30) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$pid = $_POST['payroll_id'] ?? 0;

try {
    // 1. Find the link to the attendance record
    $stmt = $pdo->prepare("SELECT attendance_id FROM payroll_finalized WHERE id = ?");
    $stmt->execute([$pid]);
    $attendance_id = $stmt->fetchColumn();

    if (!$attendance_id) {
        echo json_encode(['status' => 'error', 'message' => 'Could not find original attendance record.']);
        exit;
    }

    $pdo->beginTransaction();

    // 2. THE CRITICAL UPDATE: Reset both finalized and payout flags
    // This moves it back to "Audit" and marks it as "Unpaid"
    $upd = $pdo->prepare("UPDATE attendance SET is_finalized = 0, payout_status = 0 WHERE id = ?");
    $upd->execute([$attendance_id]);

    // 3. Remove the finalized entry
    $del = $pdo->prepare("DELETE FROM payroll_finalized WHERE id = ?");
    $del->execute([$pid]);

    $pdo->commit();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}