<?php
/**
 * Path: /payroll_save_payment.php
 * PURPOSE: Split-Disbursal Settlement Accounting Loop Engine with CRA Tax Support
 * STATUS: 100% Operational - Captures and saves cra_deductions automatically!
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';
require_once __DIR__ . '/engine/attendance_audit_lib.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_payout_batch'])) {
    
    $uid         = (int)$_POST['user_id'];
    $sid         = (int)$_POST['site_id'];
    $batch_start = $_POST['batch_start'];
    $batch_end   = $_POST['batch_end'];
    $pay_now     = (float)$_POST['amount_to_pay'];
    $pay_method  = $_POST['form_payment_method'];
    $total_due   = (float)$_POST['total_due'];
    $cra_tax_due = (float)($_POST['cra_tax_due'] ?? 0);

    if ($pay_now <= 0 || $uid <= 0) { die("Invalid Transaction Disbursal Boundaries."); }
    $target_status_flag = ($pay_method === 'Cheque') ? 'cheque_printed' : 'paid';

    try {
        $pdo->beginTransaction();

        $uQuery = $pdo->prepare("SELECT Name FROM users WHERE id = ?");
        $uQuery->execute([$uid]);
        $uName = $uQuery->fetchColumn() ?: "Staff #{$uid}";

        $notPending = function_exists('pe_not_pending_sql') ? pe_not_pending_sql($pdo, '') : '';
        $stored = $pdo->prepare("SELECT COALESCE(SUM(total_gross),0) g, COALESCE(SUM(cpp),0) cpp, COALESCE(SUM(ei),0) ei, COALESCE(SUM(income_tax),0) tax, COALESCE(SUM(cpp_er),0) cpp_er, COALESCE(SUM(ei_er),0) ei_er, COALESCE(SUM(wcb),0) wcb FROM payroll_finalized WHERE user_id=? AND site_id=? AND work_date BETWEEN ? AND ?" . $notPending);
        $stored->execute([$uid, $sid, $batch_start, $batch_end]);
        $got = $stored->fetch(PDO::FETCH_ASSOC) ?: [];
        $cra_tax_due = (float)$got['cpp'] + (float)$got['ei'] + (float)$got['tax'];
        $er_due = (float)$got['cpp_er'] + (float)$got['ei_er'] + (float)$got['wcb'];
        if ($cra_tax_due < 0.01 && (float)$got['g'] > 0) {
            $pay = pe_line((float)$got['g'], pe_vacation_rate($pdo, $uid, $sid, substr((string)$batch_end, 0, 10)), pe_rates($pdo), [
                'pdo' => $pdo, 'user_id' => $uid, 'site_id' => $sid, 'work_date' => substr((string)$batch_end, 0, 10),
            ]);
            $cra_tax_due = (float)$pay['cpp'] + (float)($pay['cpp2'] ?? 0) + (float)$pay['ei'] + (float)$pay['income_tax'];
            $er_due = (float)$pay['cpp_er'] + (float)($pay['cpp2_er'] ?? 0) + (float)$pay['ei_er'] + (float)$pay['wcb'];
        }
        $stmtMaster = $pdo->prepare("INSERT INTO payroll_masters (org_id, site_id, user_id, pay_period_start, pay_period_end, gross_total, er_contributions, cra_deductions, net_payable, status, cra_status, created_at) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Unpaid', CURRENT_TIMESTAMP())");
        $stmtMaster->execute([$sid, $uid, $batch_start, $batch_end, $total_due, $er_due, $cra_tax_due, $pay_now, $target_status_flag]);
        $master_id = $pdo->lastInsertId();

        $ref_code = "TXN-" . strtoupper(uniqid());
        $stmtPay = $pdo->prepare("INSERT INTO payroll_payments (payroll_id, recipient, amount, date_paid, payment_type, method, ref_no) VALUES (?, 'Employee', ?, CURRENT_DATE(), 'employee_net', ?, ?)");
        $stmtPay->execute([$master_id, $pay_now, "{$uName} ({$pay_method})", $ref_code]);

        // Allocate disbursed funds across outstanding employee rows
        $rQuery = $pdo->prepare("UPDATE payroll_finalized SET status = ?, payment_status = 'Paid' WHERE user_id = ? AND site_id = ? AND work_date BETWEEN ? AND ?" . $notPending);
        $rQuery->execute([$target_status_flag, $uid, $sid, $batch_start, $batch_end]);

        $bQuery = $pdo->prepare("UPDATE payroll_balance SET status = ? WHERE user_id = ? AND site_id = ? AND work_date BETWEEN ? AND ?");
        $bQuery->execute([$target_status_flag, $uid, $sid, $batch_start, $batch_end]);

        $aaIds = aa_ids_where($pdo, 'user_id = ? AND site_id = ? AND DATE(clock_in) BETWEEN ? AND ?', [$uid, $sid, $batch_start, $batch_end]);
        $aaBefore = aa_befores($pdo, $aaIds);
        $att = $pdo->prepare("UPDATE attendance SET status = 1, payout_status = 1 WHERE user_id = ? AND site_id = ? AND DATE(clock_in) BETWEEN ? AND ?");
        $att->execute([$uid, $sid, $batch_start, $batch_end]);
        aa_log_ids($pdo, 'finalize', $aaIds, $aaBefore, 'Save payment');

        $pdo->commit();
        header("Location: payroll_master_ledger.php?from_date={$batch_start}&to_date={$batch_end}&view_scope=cleared&msg=success");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die("Accounting Rollback Triggered: " . $e->getMessage());
    }
}