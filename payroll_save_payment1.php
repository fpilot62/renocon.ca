<?php
/**
 * Path: /payroll_save_payment.php
 * PURPOSE: Secure Production Engine - Centralized Ledger Commits & Historical Auditing
 * STATUS: 100% Production-Ready - Configured to use your exact 'comments' column layout
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_payout_batch'])) {
    
    $batch_start   = $_POST['batch_start'] ?? '';
    $batch_end     = $_POST['batch_end'] ?? '';
    $user_targets  = $_POST['approved_payouts'] ?? [];
    $pay_method    = $_POST['form_payment_method'] ?? 'Cheque';

    if (empty($user_targets)) {
        die("Error Processing Queue: No employees targeted for disbursement mapping execution.");
    }

    if (!is_array($user_targets)) {
        $user_targets = [$user_targets];
    }

    try {
        $pdo->beginTransaction();

        $stmtName = $pdo->prepare("SELECT Name, hourly_rate, COALESCE(transit_rate, hourly_rate) as transit_rate FROM users WHERE id = ?");
        
        $stmtClearReg = $pdo->prepare("UPDATE payroll_finalized SET reg_hours = 0, reg_pay = 0 WHERE user_id = ? AND work_date BETWEEN ? AND ?");
        $stmtClearBal = $pdo->prepare("UPDATE payroll_balance SET balance_hours = 0, total_gross = 0, status = 'paid' WHERE user_id = ? AND work_date BETWEEN ? AND ?");
        
        $stmtMasterAnchor = $pdo->prepare("INSERT INTO payroll_masters 
            (org_id, site_id, user_id, pay_period_start, pay_period_end, gross_total, er_contributions, cra_deductions, net_payable, status, created_at) 
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, 'processed', CURRENT_TIMESTAMP())");
        
        $stmtInsertPay = $pdo->prepare("INSERT INTO payroll_payments 
            (payroll_id, recipient, amount, date_paid, payment_type, method, ref_no) 
            VALUES (?, 'Employee', ?, CURRENT_DATE(), 'employee_net', ?, ?)");

        foreach ($user_targets as $uid) {
            $uid = (int)$uid;
            if ($uid <= 0) continue;
            
            // 1. Fetch user rate definitions
            $stmtName->execute([$uid]);
            $user_profile = $stmtName->fetch(PDO::FETCH_ASSOC);
            $uName = $user_profile['Name'] ?? "Staff ID Reference #{$uid}";
            $base_rate = pe_profile_hourly_rate($pdo, $uid);
            $transit_rate = (float)($user_profile['transit_rate'] ?? $base_rate);

            // 2. Aggregate active regular pay sums
            $stmtCheckReg = $pdo->prepare("SELECT SUM(reg_pay) as total_pay FROM payroll_finalized WHERE user_id = ? AND work_date BETWEEN ? AND ? AND reg_hours > 0");
            $stmtCheckReg->execute([$uid, $batch_start, $batch_end]);
            $reg_pay_total = (float)($stmtCheckReg->fetchColumn() ?: 0);

            // 3. Loop through balance lines to check for Overtime or Travel/Transit contexts
            $bal_pay_total = 0;
            $stmtCheckBal = $pdo->prepare("SELECT balance_hours, total_gross, comments FROM payroll_balance WHERE user_id = ? AND work_date BETWEEN ? AND ? AND balance_hours > 0 AND status != 'paid'");
            $stmtCheckBal->execute([$uid, $batch_start, $batch_end]);
            $bal_rows = $stmtCheckBal->fetchAll(PDO::FETCH_ASSOC);

            foreach ($bal_rows as $b_row) {
                $hours = (float)$b_row['balance_hours'];
                $stored_gross = (float)$b_row['total_gross'];
                $notes = strtolower($b_row['comments'] ?? '');

                if (strpos($notes, 'overtime') !== false) {
                    $bal_pay_total += $stored_gross;
                } elseif (strpos($notes, 'travel') !== false || strpos($notes, 'transit') !== false) {
                    $bal_pay_total += ($hours * $transit_rate);
                } else {
                    $bal_pay_total += ($hours * $base_rate);
                }
            }

            // Combine calculation streams 
            $total_distribution_gross = $reg_pay_total + $bal_pay_total;

            // 4. Clear active pools down to zero
            $stmtClearReg->execute([$uid, $batch_start, $batch_end]);
            $stmtClearBal->execute([$uid, $batch_start, $batch_end]);

            // 5. Document permanently inside historical logs
            if ($total_distribution_gross > 0) {
            $sitePick = $pdo->prepare("SELECT site_id FROM payroll_finalized WHERE user_id=? AND work_date BETWEEN ? AND ? AND site_id>0 ORDER BY work_date DESC LIMIT 1");
            $sitePick->execute([$uid, $batch_start, $batch_end]);
            $paySite = (int)($sitePick->fetchColumn() ?: 0);
            $payLine = pe_line($total_distribution_gross, pe_vacation_rate($pdo, $uid, $paySite, substr((string)$batch_end, 0, 10)), pe_rates($pdo), [
                'pdo' => $pdo, 'user_id' => $uid, 'site_id' => $paySite, 'work_date' => substr((string)$batch_end, 0, 10),
            ]);
            $net_payable = (float)$payLine['net'];

            $stmtMasterAnchor->execute([
                $paySite, $uid, $batch_start, $batch_end, $total_distribution_gross,
                (float)$payLine['cpp_er'] + (float)($payLine['cpp2_er'] ?? 0) + (float)$payLine['ei_er'] + (float)$payLine['wcb'],
                (float)$payLine['cpp'] + (float)($payLine['cpp2'] ?? 0) + (float)$payLine['ei'] + (float)$payLine['income_tax'],
                $net_payable,
            ]);
                $parent_master_id = $pdo->lastInsertId();

                $ref_code = "TXN-" . strtoupper(uniqid());
                $method_payload_string = $uName . " (" . $pay_method . ")";

                $stmtInsertPay->execute([$parent_master_id, $total_distribution_gross, $method_payload_string, $ref_code]);
            }
        }

        $pdo->commit();
        
        header("Location: payroll_master_ledger.php?from_date={$batch_start}&to_date={$batch_end}&view_scope=cleared&msg=disbursal_success");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die("Critical Core Transaction Aborted: Ledger mutation step stalled. Error context: " . $e->getMessage());
    }
} else {
    die("Error Exception Route: Direct Script Request Intercepted and Blocked.");
}

/*******************************************************************************************************
 * 📘 ARCHITECTURAL DOCUMENTATION & BUSINESS LOGIC SHEET
 *******************************************************************************************************
 * 1. TRANSACTION INTEGRITY (ACID COMPLIANCE)
 * - Uses $pdo->beginTransaction(), commit(), and rollBack() inside a try-catch architecture. 
 * If any database statement fails, all updates are completely rolled back to preserve state accuracy.
 *
 * 2. BUSINESS RULES FOR MULTI-TIERED RATE EVALUATION (`payroll_balance`)
 * - Regular Core Hours: Automatically calculates totals using the stored value (`reg_pay`) in `payroll_finalized`.
 * - Overage/Balance Shifts: Loops through rows inside `payroll_balance` checking the `comments` column:
 * - OVERTIME: If text contains 'overtime', it grabs the premium dollar amount (`total_gross`) written during validation.
 * - TRAVEL/TRANSIT: If text contains 'travel' or 'transit', it applies the employee's `transit_rate` from the `users` table.
 * - STANDARD FALLBACK: Fallback condition defaults straight to regular base hourly rate math (`hours * regular_rate`).
 *******************************************************************************************************/