<?php
/** Path: /payroll_processor.php */
ob_start(); 
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'engine.php';

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

    // 2. Financial Calculations (Standard CA Rates)
    $total_gross = ($reg_hrs * $data['official_rate']) + ($balance_hrs * $bal_rate);
    $ee_cpp = $total_gross * 0.0595; // 2026 Estimate
    $ee_ei  = $total_gross * 0.0163; // 2026 Estimate
    $ee_tax = $total_gross * 0.15;   // Base 15% Tax
    $net_pay = $total_gross - ($ee_cpp + $ee_ei + $ee_tax);

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
    $pdo->prepare("UPDATE attendance SET is_processed = 1 WHERE id = ?")->execute([$att_id]);

    header("Location: payroll_ledger.php");
    exit();

} catch (Exception $e) {
    die("Database Error: " . $e->getMessage()); // This will show you exactly what is wrong instead of a blank 500
}