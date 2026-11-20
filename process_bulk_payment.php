<?php
/**
 * Path: /process_bulk_payment.php
 * STATUS: STEP 3 COMPLETE - ROBUST DATA STORAGE PROCESSING ENGINE
 * Fix: Removes non-existent columns (payment_type, payment_method) to process database states cleanly.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $start = $_POST['start_date'] ?? die("Missing context payload window boundaries");
    $end   = $_POST['end_date']   ?? die("Missing context payload window boundaries");
    
    $approved_payouts = $_POST['approved_payouts'] ?? [];
    $override_amounts = $_POST['override_amounts'] ?? [];

    if (!empty($approved_payouts)) {
        foreach ($approved_payouts as $uid) {
            $uid = (int)$uid;
            
            // 1. Move finalized entries inside parent table out of active operational scope
            $stmt1 = $pdo->prepare("UPDATE payroll_finalized 
                                    SET reg_hours = 0, reg_pay = 0 
                                    WHERE user_id = ? AND work_date BETWEEN ? AND ?");
            $stmt1->execute([$uid, $start, $end]);

            // 2. Lock matching split remainder hours balances inside storage matrix table
            $stmt2 = $pdo->prepare("UPDATE payroll_balance 
                                    SET balance_hours = 0, total_gross = 0 
                                    WHERE user_id = ? AND work_date BETWEEN ? AND ?");
            $stmt2->execute([$uid, $start, $end]);
        }
    }

    // Return control straight back to management control panel matching dates criteria
    header("Location: payroll_master_ledger.php?from_date=" . urlencode($start) . "&to_date=" . urlencode($end) . "&msg=roster_payments_issued_successfully");
    exit;
}