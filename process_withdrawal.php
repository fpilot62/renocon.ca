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
        $sitePick = $pdo->prepare("SELECT site_id, org_id FROM payroll_finalized WHERE user_id=? AND site_id>0 ORDER BY work_date DESC LIMIT 1");
        $sitePick->execute([$user_id]);
        $home = $sitePick->fetch(PDO::FETCH_ASSOC) ?: ['site_id' => 0, 'org_id' => 0];
        $day = date('Y-m-d');
        $drawn = pe_line($gross, pe_vacation_rate($pdo, $user_id, (int)$home['site_id'], $day), pe_rates($pdo), [
            'pdo' => $pdo, 'user_id' => $user_id, 'site_id' => (int)$home['site_id'], 'org_id' => (int)$home['org_id'], 'work_date' => $day,
        ]);
        $ledger = $pdo->prepare("INSERT INTO payroll_records (user_id, site_id, total_hours, rate_used, gross_pay, date_processed) 
                                 VALUES (?, ?, ?, ?, ?, NOW())");
        $ledger->execute([$user_id, (int)$home['site_id'], $hours_to_withdraw, $rate, (float)$drawn['pay_gross']]);
        if (is_file(__DIR__ . '/payroll_engine/stat_holidays.php')) {
            require_once __DIR__ . '/payroll_engine/stat_holidays.php';
            sh_after_finalize($pdo, $user_id, (int)$home['site_id'], $day, 0);
        }

        $pdo->commit();
        header("Location: bank_summary.php?success=Withdrawn");
    } catch (Exception $e) {
        $pdo->rollBack();
        die("Error: " . $e->getMessage());
    }
}