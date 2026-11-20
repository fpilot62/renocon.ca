<?php
/** Path: active/modules/attendance/finalize_shift.php */
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// AUTHENTICATION SECURITY CHECK
if (!isset($_SESSION['user_level']) || !isset($_SESSION['org_id'])) {
    header("Location: /login.php?error=unauthorized");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php'; 

$attendance_id = intval($_GET['id'] ?? 0);
if ($attendance_id === 0) { die("Error: Missing record identifier mapping reference parameters."); }

// Fetch the targeted attendance row context details
$stmt = $pdo->prepare("SELECT a.*, u.Name FROM attendance a JOIN users u ON a.user_id = u.id WHERE a.id = ?");
$stmt->execute([$attendance_id]);
$shift_data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shift_data) { die("Record tracing logs could not look up matching rows index profiles."); }

// Rate Fallback Check Engine Rule
$display_hours = floatval($shift_data['approved_hours']);
$display_base_rate = pe_profile_hourly_rate($pdo, (int)$shift_data['user_id']);

// --- TAXATION & BURDEN ENGINE CALCULATION MODEL ---
// Split categorization flags mock evaluation configurations if already split elsewhere
$regular_gross = $display_hours * $display_base_rate;
$travel_gross  = 0.00; 
$bonus_gross   = 0.00;

// 1. CRA Pensionable & Insurable Gross (Includes wages + travel + cash bonuses)
$total_taxable_payroll_gross = $regular_gross + $travel_gross + $bonus_gross;

$day = !empty($shift_data['clock_in']) ? date('Y-m-d', strtotime($shift_data['clock_in'])) : date('Y-m-d');
$uid = (int)$shift_data['user_id'];
$sid = (int)($shift_data['site_id'] ?? 0);
$fin = pe_line($total_taxable_payroll_gross, pe_vacation_rate($pdo, $uid, $sid, $day), pe_rates($pdo), [
    'pdo' => $pdo, 'user_id' => $uid, 'site_id' => $sid, 'org_id' => (int)($shift_data['org_id'] ?? 0), 'work_date' => $day,
]);
$cpp_employee = (float)$fin['cpp'] + (float)($fin['cpp2'] ?? 0);
$ei_employee = (float)$fin['ei'];
$income_tax = (float)$fin['income_tax'];
$net_take_home = (float)$fin['net'];
$wcb_employer_premium = (float)$fin['wcb'];
$cpp_employer_match = (float)$fin['cpp_er'] + (float)($fin['cpp2_er'] ?? 0);
$ei_employer_match = (float)$fin['ei_er'];
$total_employer_burden_cost = (float)$fin['pay_gross'] + $cpp_employer_match + $ei_employer_match + $wcb_employer_premium;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Finalize Shift Execution Desk</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f1f5f9; margin: 0; padding: 40px 20px; }
        .wrapper { max-width: 1000px; margin: 0 auto; background: white; padding: 35px; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .grid-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 30px; }
        .ledger-card { background: #f8fafc; border: 1px solid #cbd5e1; padding: 20px; border-radius: 6px; }
        .ledger-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px; font-family: monospace; }
        .total-row { border-top: 2px dashed #cbd5e1; padding-top: 10px; margin-top: 12px; font-weight: bold; font-size: 14px; }
    </style>
</head>
<body>

<div class="wrapper">
    <h2 style="margin-top:0; color:#0f172a;"><i class="fa fa-calculator" style="color:#3b82f6;"></i> Finalize Shift Execution Desk: <span style="color:#2563eb;"><?= htmlspecialchars($shift_data['Name']) ?></span></h2>
    
    <p>Reviewing historical attendance row index reference tracking parameter profile: <strong>#<?= $attendance_id ?></strong></p>

    <div style="background:#f0fdf4; border:1px solid #bbf7d0; padding:15px; border-radius:6px; margin-bottom:25px; font-size:15px;">
        Current Gross Record Target Value Baseline: <strong><?= number_format($display_hours, 2) ?> hours</strong> worked at <strong>$<?= number_format($display_base_rate, 2) ?>/hr</strong> = <span style="color:#16a34a; font-weight:bold;">$<?= number_format($regular_gross, 2) ?> Total Gross</span>
    </div>

    <div class="grid-cards">
        <div class="ledger-card">
            <h3 style="margin-top:0; font-size:12px; color:#475569; text-transform:uppercase; border-bottom:1px solid #cbd5e1; padding-bottom:6px;">Employee Deductions Run Ledger</h3>
            <div class="ledger-row"><span>Canada Pension Plan (CPP):</span><span style="color:#ef4444;">-$<?=number_format($cpp_employee, 2)?></span></div>
            <div class="ledger-row"><span>Employment Insurance (EI):</span><span style="color:#ef4444;">-$<?=number_format($ei_employee, 2)?></span></div>
            <div class="ledger-row"><span>Federal/Provincial Income Tax:</span><span style="color:#ef4444;">-$<?=number_format($income_tax, 2)?></span></div>
            <div class="ledger-row total-row"><span>NET WORKER TAKE-HOME PAYOUT:</span><span style="color:#16a34a;">$<?=number_format($net_take_home, 2)?></span></div>
        </div>

        <div class="ledger-card">
            <h3 style="margin-top:0; font-size:12px; color:#475569; text-transform:uppercase; border-bottom:1px solid #cbd5e1; padding-bottom:6px;">Employer Corporate Burden Premiums</h3>
            <div class="ledger-row"><span>CPP Corporate Match Premium:</span><span>+$<?=number_format($cpp_employer_match, 2)?></span></div>
            <div class="ledger-row"><span>EI Corporate Premium Match (1.4x):</span><span>+$<?=number_format($ei_employer_match, 2)?></span></div>
            <div class="ledger-row"><span>WCB Assessable Levy (Bonus Excluded):</span><span>+$<?=number_format($wcb_employer_premium, 2)?></span></div>
            <div class="ledger-row total-row"><span>TOTAL TARGET LABOUR REVENUE COST:</span><span>$<?=number_format($total_employer_burden_cost, 2)?></span></div>
        </div>
    </div>

    <div style="margin-top:40px; text-align:center;">
        <button onclick="location.href='payroll_dashboard.php'" style="padding:12px 25px; background:#0f172a; color:white; border:none; border-radius:6px; font-weight:bold; cursor:pointer;"><i class="fa fa-arrow-left"></i> Return to Timesheets Grid Panel</button>
    </div>
</div>

</body>
</html>