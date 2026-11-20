<?php
/** Path: /payroll/delete_shift.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once __DIR__ . '/engine/attendance_audit_lib.php';

// 1. SECURITY: Check Manager Session and ID presence
if (!isset($_SESSION['org_id']) || !isset($_GET['id'])) { 
    header("Location: manager_dashboard.php"); 
    exit; 
}

$org_id = $_SESSION['org_id'];
$shift_id = (int)$_GET['id'];

try {
    // 2. VERIFY OWNERSHIP: Ensure this shift belongs to the manager's organization
    $stmt = $pdo->prepare("SELECT id FROM attendance WHERE id = ? AND org_id = ?");
    $stmt->execute([$shift_id, $org_id]);
    $shift = $stmt->fetch();

    if ($shift) {
        // 3. MANUAL CLEANUP: Delete child records (breaks) first to avoid errors
        $delBreaks = $pdo->prepare("DELETE FROM breaks WHERE shift_id = ?");
        $delBreaks->execute([$shift_id]);

        // 4. DELETE PARENT: Now delete the actual shift record
        $aaBefore = aa_snapshot($pdo, $shift_id);
        $delShift = $pdo->prepare("DELETE FROM attendance WHERE id = ?");
        $delShift->execute([$shift_id]);
        aa_log($pdo, 'delete', $shift_id, $aaBefore, null, 'Hard delete shift');
        
        header("Location: manager_dashboard.php?status=success&msg=Shift+and+Audit+Trail+Removed");
    } else {
        header("Location: manager_dashboard.php?status=error&msg=Unauthorized+Access+or+Invalid+Shift");
    }
} catch (PDOException $e) {
    // Log error and provide feedback
    error_log("Delete Error: " . $e->getMessage());
    header("Location: manager_dashboard.php?status=error&msg=Database+Error+during+deletion");
}
exit;