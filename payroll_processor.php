<?php
require_once __DIR__ . '/engine/orgscope_guard.php'; osg_page(['level'=>29,'fence'=>['attendance_id'=>'attendance']]); // org-scope audit Nov 2026

/** Path: /payroll_processor.php */
ob_start(); 
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'engine.php';
require_once __DIR__ . '/engine/attendance_audit_lib.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';

// Get data from the dashboard click
$att_id      = $_POST['attendance_id'] ?? null;
$reg_hrs     = floatval($_POST['reg_hrs'] ?? 0);
$balance_hrs = floatval($_POST['balance_hrs'] ?? 0);
$bal_rate    = floatval($_POST['bal_rate'] ?? 0);

try {
    // 1. Fetch User Data (REMOVED ps.ei_rate_ee to stop 500 error)
    $stmt = $pdo->prepare("
        SELECT a.*, u.official_rate, u.org_id
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        WHERE a.id = ?");
    $stmt->execute([$att_id]);
    $data = $stmt->fetch();

    if (!$data) { die("Record not found."); }

    $total_gross = ($reg_hrs * $data['official_rate']) + ($balance_hrs * $bal_rate);
    $workDay = !empty($data['clock_in']) ? date('Y-m-d', strtotime($data['clock_in'])) : date('Y-m-d');
    $line = pe_line($total_gross, pe_vacation_rate($pdo, (int)$data['user_id'], (int)$data['site_id'], $workDay), pe_rates($pdo), [
        'pdo' => $pdo,
        'user_id' => (int)$data['user_id'],
        'site_id' => (int)$data['site_id'],
        'org_id' => (int)$data['org_id'],
        'work_date' => $workDay,
    ]);
    $ee_cpp = (float)$line['cpp'] + (float)($line['cpp2'] ?? 0);
    $ee_ei  = (float)$line['ei'];
    $ee_tax = (float)$line['income_tax'];
    $net_pay = (float)$line['net'];

    // 3. INSERT (Using your exact DB column names: balance_hrs, cpp_deduction)
    $sql = "INSERT INTO payroll_records 
            (user_id, org_id, site_id, total_hours, allocated_reg_hrs, balance_hrs, 
             rate_used, balance_rate, gross_pay, net_pay, cpp_deduction, ei_deduction, income_tax,
             date_processed) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $pdo->prepare($sql)->execute([
        $data['user_id'], 
        $data['org_id'], 
        $data['site_id'], 
        ($reg_hrs + $balance_hrs),
        $reg_hrs, 
        $balance_hrs, // Matches your DB exactly
        $data['official_rate'], 
        $bal_rate, 
        $total_gross, 
        $net_pay, 
        $ee_cpp, 
        $ee_ei, 
        $ee_tax
    ]);

    // 4. Update attendance status
    $aaBefore = aa_snapshot($pdo, (int)$att_id);
    $pdo->prepare("UPDATE attendance SET is_processed = 1 WHERE id = ?")->execute([$att_id]);
    aa_log($pdo, 'finalize', (int)$att_id, $aaBefore, null, 'Payroll processor');

    header("Location: payroll_ledger.php");
    exit();

} catch (Exception $e) {
    die("Database Error: " . $e->getMessage()); // This will show you exactly what is wrong instead of a blank 500
}