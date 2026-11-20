<?php
/** Path: /process_payroll_logic.php */
require_once 'header.php';
$month = $_GET['month'] ?? date('Y-m'); 

try {
    $sql = "SELECT u.id as user_id, u.org_id, a.site_id, u.Hourly_Rate,
                SUM(TIMESTAMPDIFF(SECOND, a.clock_in, a.clock_out)) / 3600 as total_hrs,
                s.cpp_rate, s.ei_rate_er, s.wcb_rate
            FROM users u
            JOIN attendance a ON u.id = a.user_id
            JOIN payroll_settings s ON u.org_id = s.org_id
            WHERE DATE_FORMAT(a.clock_in, '%Y-%m') = :month
            GROUP BY u.id, a.site_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['month' => $month]);
    $results = $stmt->fetchAll();

    foreach ($results as $r) {
        $base_gross = $r['total_hrs'] * $r['Hourly_Rate'];
        $vac_pay    = $base_gross * 0.04; // 4% Vac Pay
        $gross_total = $base_gross + $vac_pay;

        // --- EMPLOYEE DEDUCTIONS ---
        $cpp_ee = $gross_total * ($r['cpp_rate'] / 100);
        $ei_ee  = $gross_total * ($r['ei_rate_er'] / 100);
        $tax    = $gross_total * 0.15; // Placeholder Tax

        // --- EMPLOYER CONTRIBUTIONS ---
        $cpp_er = $cpp_ee;       // Employer matches CPP
        $ei_er  = $ei_ee * 1.4;  // Employer pays 1.4x EI
        $wcb    = $gross_total * ($r['wcb_rate'] / 100);

        $net_pay = $gross_total - ($cpp_ee + $ei_ee + $tax);

        $ins = $pdo->prepare("INSERT INTO payroll_records 
            (user_id, org_id, site_id, total_hours, vac_pay, rate_used, gross_pay, cpp_deduction, employer_cpp, ei_deduction, employer_ei, income_tax, wcb_payable, net_pay, pay_period) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE gross_pay = VALUES(gross_pay), net_pay = VALUES(net_pay)");
        
        $ins->execute([
            $r['user_id'], $r['org_id'], $r['site_id'], $r['total_hrs'], $vac_pay,
            $r['Hourly_Rate'], $gross_total, $cpp_ee, $cpp_er, $ei_ee, $ei_er, $tax, $wcb, $net_pay, $month
        ]);
    }
    header("Location: master_ledger.php?period=$month&success=1");
} catch (Exception $e) { die("Error: " . $e->getMessage()); }