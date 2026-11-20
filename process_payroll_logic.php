<?php
require_once __DIR__ . '/engine/orgscope_guard.php'; osg_page(['level'=>10]); // org-scope audit Nov 2026

/** Path: /process_payroll_logic.php */
require_once 'header.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
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
        $workDay = date('Y-m-t', strtotime($month . '-01'));
        $line = pe_line($base_gross, pe_vacation_rate($pdo, (int)$r['user_id'], (int)$r['site_id'], $workDay), pe_rates($pdo), [
            'pdo' => $pdo,
            'user_id' => (int)$r['user_id'],
            'site_id' => (int)$r['site_id'],
            'org_id' => (int)$r['org_id'],
            'work_date' => $workDay,
            'pay_date' => $workDay,
        ]);
        $vac_pay = (float)$line['vac'];
        $gross_total = (float)$line['pay_gross'];
        $cpp_ee = (float)$line['cpp'] + (float)($line['cpp2'] ?? 0);
        $ei_ee = (float)$line['ei'];
        $tax = (float)$line['income_tax'];
        $cpp_er = (float)$line['cpp_er'] + (float)($line['cpp2_er'] ?? 0);
        $ei_er = (float)$line['ei_er'];
        $wcb = (float)$line['wcb'];
        $net_pay = (float)$line['net'];

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