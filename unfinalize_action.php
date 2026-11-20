<?php
/** Path: /unfinalize_action.php */
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$att_id = $_GET['id'] ?? 0;

if ($att_id) {
    try {
        $pdo->beginTransaction();

        // 1. Reset Attendance flags to make it Pending again
        $pdo->prepare("UPDATE attendance SET 
            payout_status = 0, 
            is_finalized = 0, 
            status = 4, 
            is_processed = 0 
            WHERE id = ?")->execute([$att_id]);

        // 2. Clear Payroll tables linked to this shift
        $pdo->prepare("DELETE FROM payroll_finalized WHERE attendance_id = ?")->execute([$att_id]);
        $pdo->prepare("DELETE FROM payroll_balance WHERE attendance_id = ?")->execute([$att_id]);

        $pdo->commit();
        header("Location: finalized_archive.php?msg=reverted");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        die("Revert Failed: " . $e->getMessage());
    }
}