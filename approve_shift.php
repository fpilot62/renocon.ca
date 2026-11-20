<?php
/** Path: /payroll/approve_shift.php */
error_reporting(E_ALL);
ini_set('display_errors', 1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';

// 1. COLLECT DATA
$shift_id    = $_POST['shift_id'] ?? null; // This is the ID from the attendance table
$user_id     = $_POST['user_id'] ?? null;
$final_hours = $_POST['final_hours'] ?? null;
$org_id      = $_SESSION['org_id'] ?? null;

if (!$shift_id || !$user_id || !$org_id) {
    die("Critical Error: Missing data for processing.");
}

try {
    $pdo->beginTransaction();

    // 2. GET USER HOURLY RATE
    $u_stmt = $pdo->prepare("SELECT hourly_rate FROM users WHERE id = ?");
    $u_stmt->execute([$user_id]);
    $user_data = $u_stmt->fetch();
    $rate = $user_data['hourly_rate'] ?? 0;
    $gross_pay = $final_hours * $rate;

    // 3. GET SITE ID FROM ATTENDANCE
    $a_stmt = $pdo->prepare("SELECT site_id FROM attendance WHERE id = ?");
    $a_stmt->execute([$shift_id]);
    $att_data = $a_stmt->fetch();
    $site_id = $att_data['site_id'] ?? 0;

    // 4. INSERT INTO payroll_records 
    // Uses fields: user_id, org_id, site_id, total_hours, rate_used, gross_pay, date_processed
    $ins_rec = $pdo->prepare("INSERT INTO payroll_records 
        (user_id, org_id, site_id, total_hours, rate_used, gross_pay, date_processed) 
        VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $ins_rec->execute([$user_id, $org_id, $site_id, $final_hours, $rate, $gross_pay]);

    // 5. INSERT INTO hour_balances 
    // Uses fields: org_id, user_id, hours_added, reason, created_at
    // We EXCLUDED 'attendance_id' because it does not exist in your table.
    $ins_bal = $pdo->prepare("INSERT INTO hour_balances 
        (org_id, user_id, hours_added, reason, created_at) 
        VALUES (?, ?, ?, ?, NOW())");
    $ins_bal->execute([$org_id, $user_id, $final_hours, "Payroll Finalized for Shift #$shift_id"]);

    // 6. MARK ATTENDANCE AS FINALIZED
    $upd = $pdo->prepare("UPDATE attendance SET is_finalized = 1 WHERE id = ?");
    $upd->execute([$shift_id]);

    $pdo->commit();
    header("Location: finalize_payroll.php?success=1");
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    die("Database Error: " . $e->getMessage());
}