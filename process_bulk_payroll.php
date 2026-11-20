<?php
/** Path: process_bulk_payroll.php */
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// AUTHENTICATION SECURITY CHECK
if (!isset($_SESSION['user_id']) || !isset($_SESSION['org_id'])) {
    die("Security Error: Unauthorized operation access parameters.");
}

require_once "db.php";
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
if (is_file(__DIR__ . '/break_waiver_finalize_guard.php')) {
    require_once __DIR__ . '/break_waiver_finalize_guard.php';
}

// Extract structural parameters forwarded from our allocation desk workspace form
$ids = explode(',', $_POST['record_ids'] ?? '');
$allocation_type = $_POST['allocation_type'] ?? 'Overtime';
$hourly_cap = floatval($_POST['hourly_cap_trigger'] ?? 8.0);

try {
    $pdo->beginTransaction();

    foreach ($ids as $id) {
        $id = intval($id);
        if ($id === 0) continue;

        // FETCH 1: Explicit column mappings to avoid any table collisions
        $stmt = $pdo->prepare("SELECT 
                                    a.id,
                                    a.user_id,
                                    a.clock_in,
                                    a.clock_out,
                                    u.hourly_rate AS user_hourly_rate,   
                                    u.official_rate AS user_official_rate  
                               FROM attendance a 
                               LEFT JOIN users u ON a.user_id = u.id 
                               WHERE a.id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) continue;

        // -----------------------------------------------------------------
        // DYNAMIC NET HOURS EVALUATION (WITH MULTI-BREAK DEDUCTIONS)
        // -----------------------------------------------------------------
        $in_ts  = !empty($row['clock_in']) ? strtotime($row['clock_in']) : time();
        $out_ts = (!empty($row['clock_out']) && $row['clock_out'] != '0000-00-00 00:00:00') ? strtotime($row['clock_out']) : time();
        
        $break_sec = 0;
        $bStmt = $pdo->prepare("SELECT break_start, break_end FROM breaks WHERE shift_id = ?");
        $bStmt->execute([$id]);
        foreach ($bStmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $b_start = !empty($b['break_start']) ? strtotime($b['break_start']) : 0;
            if ($b_start > 0) {
                if (!empty($b['break_end']) && $b['break_end'] != '0000-00-00 00:00:00') {
                    $break_sec += abs(strtotime($b['break_end']) - $b_start);
                } else {
                    $break_sec += (30 * 60); // Safety default fallback
                }
            }
        }
        
        $net_sec = ($out_ts >= $in_ts) ? (($out_ts - $in_ts) - $break_sec) : 0;
        if ($net_sec < 0) $net_sec = 0;
        $total_calculated_hours = round($net_sec / 3600, 2);

        // STANDARD: always the employee profile hourly rate.
        $work_as_of = !empty($row['clock_in']) ? date('Y-m-d', strtotime($row['clock_in'])) : date('Y-m-d');
        $base_rate = pe_profile_hourly_rate($pdo, (int)$row['user_id'], $work_as_of);

        // -----------------------------------------------------------------
        // EXECUTE TRANSACTION MATRIX
        // -----------------------------------------------------------------
        if ($allocation_type === 'Bonus') {
            $bonus_amt = floatval($_POST['bonus_amount'] ?? 0);
            $reason = trim($_POST['bonus_reason'] ?? 'Discretionary Bonus');

            $ins = $pdo->prepare("INSERT INTO payroll_finalized (user_id, attendance_ref_id, hours, rate, total_pay, allocation_type, notes) VALUES (?, ?, 1.00, ?, ?, 'Bonus', ?)");
            $ins->execute([$row['user_id'], $id, $bonus_amt, $bonus_amt, $reason]);
        } 
        else {
            if ($total_calculated_hours > $hourly_cap) {
                $reg_hours = $hourly_cap;
                $bal_hours = $total_calculated_hours - $hourly_cap;

                // UPDATE 2: Explicitly updates targeted fields in attendance
                $pdo->prepare("UPDATE attendance SET approved_hours = ?, hourly_rate = ?, payout_status = 1, status = 'Finalized', flag_reason = 'Core Split Line' WHERE id = ?")
                    ->execute([$reg_hours, $base_rate, $id]);

                if ($allocation_type === 'StandardBalance' || $allocation_type === 'PeriodSplit') {
                    // INSERT 3A: Pushes excess to the generic balance repository
                    $ins = $pdo->prepare("INSERT INTO payroll_balance (user_id, attendance_ref_id, hours, rate, total_pay) VALUES (?, ?, ?, ?, ?)");
                    $ins->execute([$row['user_id'], $id, $bal_hours, $base_rate, ($bal_hours * $base_rate)]);
                } else {
                    // INSERT 3B: Pushes Overtime or Travel to the finalization table
                    $multiplier = ($allocation_type === 'Overtime') ? 1.5 : 1.0;
                    $final_rate = $base_rate * $multiplier;
                    $gross_pay  = $bal_hours * $final_rate;

                    $ins = $pdo->prepare("INSERT INTO payroll_finalized (user_id, attendance_ref_id, hours, rate, total_pay, allocation_type) VALUES (?, ?, ?, ?, ?, ?)");
                    $ins->execute([$row['user_id'], $id, $bal_hours, $final_rate, $gross_pay, $allocation_type]);
                }
            } else {
                // FALLBACK: Finalizes timesheet cleanly if under the selected cap limit boundary
                $pdo->prepare("UPDATE attendance SET approved_hours = ?, hourly_rate = ?, payout_status = 1, status = 'Finalized' WHERE id = ?")
                    ->execute([$total_calculated_hours, $base_rate, $id]);
            }
        }
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    die("Bulk Payroll Processing Error Exception: " . $e->getMessage());
}

// Redirect back cleanly to your completed timesheet audit view module
header("Location: staff_audit.php");
exit();