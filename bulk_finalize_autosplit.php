<?php
/**
 * Path: /bulk_finalize_autosplit.php
 * ACTION: Process 8h Split (Work) OR Travel Costs (Transit)
 * GOLDEN RULE COMPLIANT WITH REGIONAL TAX ENGINE
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
require_once __DIR__ . '/payroll_engine/stat_holidays.php';
if (is_file(__DIR__ . '/break_waiver_finalize_guard.php')) {
    require_once __DIR__ . '/break_waiver_finalize_guard.php';
}

$ids = $_POST['shift_ids'] ?? [];
$results = ['success' => [], 'failed' => []];

if (empty($ids)) {
    die("No records selected. <a href='staff_audit.php'>Go Back</a>");
}

/**
 * Dynamic Localization Tax & Contribution Matrix
 * Calculates deductions on the fly based on regional site properties.
 * Supports Canada (AB, BC, ON), USA, UK, and Australia.
 */
function calculateRegionalDeductions($gross_pay, $region) {
    $rates = [
        'cpp_ee'     => 0.00, // Employee Pension
        'cpp_er'     => 0.00, // Employer Pension
        'ei_ee'      => 0.00, // Employee Insurance
        'ei_er'      => 0.00, // Employer Insurance
        'income_tax' => 0.10, // Base Income Tax
        'vac_pay'    => 0.04, // Vacation Accumulator
        'wsib'       => 0.02  // Workers Comp baseline
    ];

    switch (strtoupper(trim((string)$region))) {
        case 'AB':
        case 'ALL_CAN':
            $rates['cpp_ee']     = 0.0595;
            $rates['cpp_er']     = 0.0595;
            $rates['ei_ee']      = 0.0166;
            $rates['ei_er']      = 0.0166 * 1.4;
            $rates['income_tax'] = 0.1500;
            $rates['vac_pay']    = 0.0400;
            $rates['wsib']       = 0.0175;
            break;

        case 'BC':
            $rates['cpp_ee']     = 0.0595;
            $rates['cpp_er']     = 0.0595;
            $rates['ei_ee']      = 0.0166;
            $rates['ei_er']      = 0.0166 * 1.4;
            $rates['income_tax'] = 0.1450;
            $rates['vac_pay']    = 0.0400;
            $rates['wsib']       = 0.0210;
            break;

        case 'USA':
            $rates['cpp_ee']     = 0.0620; // FICA EE
            $rates['cpp_er']     = 0.0620; // FICA ER
            $rates['ei_ee']      = 0.0145; // Medicare EE
            $rates['ei_er']      = 0.0145; // Medicare ER
            $rates['income_tax'] = 0.1200;
            $rates['vac_pay']    = 0.0000;
            $rates['wsib']       = 0.0250;
            break;

        case 'UK':
            $rates['cpp_ee']     = 0.0800; // NI EE
            $rates['cpp_er']     = 0.1380; // NI ER
            $rates['income_tax'] = 0.2000; // PAYE
            $rates['vac_pay']    = 0.1207; // Holiday premium
            $rates['wsib']       = 0.0100;
            break;

        case 'AUS':
            $rates['cpp_ee']     = 0.0000;
            $rates['cpp_er']     = 0.1150; // Superannuation Guarantee
            $rates['income_tax'] = 0.1900; // PAYG
            $rates['vac_pay']    = 0.0833;
            $rates['wsib']       = 0.0150;
            break;
    }

    return [
        'gross'      => round($gross_pay, 2),
        'cpp'        => round($gross_pay * $rates['cpp_ee'], 2),
        'cpp_er'     => round($gross_pay * $rates['cpp_er'], 2),
        'ei'         => round($gross_pay * $rates['ei_ee'], 2),
        'ei_er'      => round($gross_pay * $rates['ei_er'], 2),
        'income_tax' => round($gross_pay * $rates['income_tax'], 2),
        'vac'        => round($gross_pay * $rates['vac_pay'], 2),
        'wsib'       => round($gross_pay * $rates['wsib'], 2),
        'net_pay'    => round($gross_pay - ($gross_pay * $rates['cpp_ee']) - ($gross_pay * $rates['ei_ee']) - ($gross_pay * $rates['income_tax']), 2)
    ];
}

foreach ($ids as $id) {
    try {
        $pdo->beginTransaction();

        // 1. Fetch Shift Details with essential structural parameters
        $stmt = $pdo->prepare("
            SELECT a.*, u.transit_rate, u.hourly_rate, s.region 
            FROM attendance a 
            JOIN users u ON a.user_id = u.id 
            LEFT JOIN sites s ON a.site_id = s.SId 
            WHERE a.id = ?
        ");
        $stmt->execute([$id]);
        $shift = $stmt->fetch();

        if (!$shift || $shift['payout_status'] == 1) {
            $results['failed'][] = "ID $id: Already processed or missing.";
            $pdo->rollBack(); 
            continue;
        }

        $in_ts  = strtotime($shift['clock_in']);
        $out_ts = (!empty($shift['clock_out']) && $shift['clock_out'] != '0000-00-00 00:00:00') ? strtotime($shift['clock_out']) : time();
        $work_date = date('Y-m-d', $in_ts);
        $region = !empty($shift['region']) ? $shift['region'] : 'AB';

        // 2. Logic Branch: TRAVEL LOGIC (Status Code 5)
        if ($shift['status'] === '5' || $shift['transfer_status'] === 'out') {
            $travel_sec   = $out_ts - $in_ts;
            $travel_hours = $travel_sec / 3600;
            $travel_cost  = round($travel_hours * pe_profile_pay_rate($pdo, (int)$shift['user_id'], 'travel', $work_date), 2);

            // Travel is treated as a clean expense injection row with matching paid amount fields
            $stmtPF = $pdo->prepare("
                INSERT INTO payroll_finalized 
                (attendance_id, user_id, site_id, work_date, reg_hours, balance_hours, travel_cost, paid_amount, status) 
                VALUES (?, ?, ?, ?, 0, 0, ?, ?, 'travel_paid')
            ");
            $stmtPF->execute([$id, $shift['user_id'], $shift['site_id'], $work_date, $travel_cost, $travel_cost]);

        // 3. Logic Branch: STANDARD WORK SPLIT (Status Code 0 or 6)
        } else {
            // Subtract active lunch breaks
            $bStmt = $pdo->prepare("SELECT SUM(ABS(TIMESTAMPDIFF(SECOND, break_start, break_end))) FROM breaks WHERE shift_id = ?");
            $bStmt->execute([$id]);
            $break_sec = (float)$bStmt->fetchColumn();

            $net_hours = (($out_ts - $in_ts) - $break_sec) / 3600;
            if ($net_hours < 0) { $net_hours = 0; }

            $reg_hours = ($net_hours > 8) ? 8 : $net_hours;
            $bal_hours = ($net_hours > 8) ? ($net_hours - 8) : 0;

            // Calculate active gross pay using worker pay rate profile
            $hourly_rate = pe_profile_hourly_rate($pdo, (int)$shift['user_id'], $work_date);
            $gross_pay = $reg_hours * $hourly_rate;

            // Canada uses the T4127 engine. Other regions keep the local matrix.
            $canada = in_array(strtoupper(trim((string)$region)), ['AB', 'BC', 'ON', 'SK', 'ALL_CAN', ''], true);
            if ($canada) {
                $vacRate = pe_vacation_rate($pdo, (int)$shift['user_id'], (int)$shift['site_id'], $work_date);
                $d = pe_line($gross_pay, $vacRate, pe_rates($pdo), [
                    'pdo' => $pdo,
                    'user_id' => (int)$shift['user_id'],
                    'site_id' => (int)$shift['site_id'],
                    'org_id' => (int)($shift['org_id'] ?? 0),
                    'work_date' => $work_date,
                ]);
                $calc = [
                    'gross' => $d['pay_gross'],
                    'cpp' => $d['cpp'],
                    'ei' => $d['ei'],
                    'income_tax' => $d['income_tax'],
                    'cpp_er' => $d['cpp_er'],
                    'ei_er' => $d['ei_er'],
                    'vac' => $d['vac'],
                    'wsib' => $d['wcb'],
                    'net_pay' => $d['net'],
                ];
            } else {
                $calc = calculateRegionalDeductions($gross_pay, $region);
            }

            // Insert matching line items directly inside payroll_finalized database layout
            $stmtPF = $pdo->prepare("
                INSERT INTO payroll_finalized 
                (attendance_id, user_id, site_id, work_date, reg_hours, balance_hours, total_gross, cpp, ei, income_tax, cpp_er, ei_er, vac, safety_label, wcb, paid_amount, status) 
                VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, 'WCB', ?, ?, 'finalized')
            ");
            $stmtPF->execute([
                $id, 
                $shift['user_id'], 
                $shift['site_id'], 
                $work_date, 
                $reg_hours,
                $calc['gross'],
                $calc['cpp'],
                $calc['ei'],
                $calc['income_tax'],
                $calc['cpp_er'],
                $calc['ei_er'],
                $calc['vac'],
                $calc['wsib'],
                $calc['net_pay']
            ]);

            // If shift exceeds 8 hours, push the overflow balance to payroll_balance
            if ($bal_hours > 0) {
                $stmtPB = $pdo->prepare("
                    INSERT INTO payroll_balance 
                    (attendance_id, user_id, site_id, work_date, reg_hours, balance_hours, status) 
                    VALUES (?, ?, ?, ?, 0, ?, 'pending')
                ");
                $stmtPB->execute([$id, $shift['user_id'], $shift['site_id'], $work_date, $bal_hours]);
            }
        }

        // 4. Update parent shift status so it hides from active audit view panels
        $pdo->prepare("UPDATE attendance SET payout_status = 1 WHERE id = ?")->execute([$id]);
        if (!empty($canada) && !empty($shift['user_id']) && !empty($shift['site_id']) && !empty($work_date)) {
            sh_after_finalize($pdo, (int)$shift['user_id'], (int)$shift['site_id'], $work_date, (int)($_SESSION['user_id'] ?? 0));
        }

        $pdo->commit();
        $results['success'][] = "ID $id: Split and finalized successfully.";

    } catch (Exception $e) {
        $pdo->rollBack();
        $results['failed'][] = "ID $id: " . $e->getMessage();
    }
}

// Return safely back to the interface layout view dashboard
header("Location: staff_audit.php?msg=processed");
exit;