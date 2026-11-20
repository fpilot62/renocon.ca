<?php
/** Path: /process_withdrawal.php */
require_once 'db.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = (int)$_POST['user_id'];
    $hours_to_withdraw = (float)$_POST['hours'];
    $rate = pe_profile_hourly_rate($pdo, $user_id); // always employee profile

    try {
        $pdo->beginTransaction();

        // 1. Insert a NEGATIVE record into hour_balances
        $stmt = $pdo->prepare("INSERT INTO hour_balances (user_id, hours_added, reason, date_added) 
                               VALUES (?, ?, 'Manual Withdrawal (Top-up)', NOW())");
        $stmt->execute([$user_id, -$hours_to_withdraw]);

        // 2. Insert into payroll_records so he gets paid for these hours
        $gross = $hours_to_withdraw * $rate;
        $ledger = $pdo->prepare("INSERT INTO payroll_records (user_id, site_id, total_hours, rate_used, gross_pay, date_processed) 
                                 VALUES (?, 0, ?, ?, ?, NOW())");
        $ledger->execute([$user_id, $hours_to_withdraw, $rate, $gross]);

        $pdo->commit();
        header("Location: bank_summary.php?success=Withdrawn");
    } catch (Exception $e) {
        $pdo->rollBack();
        die("Error: " . $e->getMessage());
    }
}