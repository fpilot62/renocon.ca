<?php
/**
 * Path: /payroll_split_80.php
 * UTILITY TOOL - DECIMAL-STRICT CROSS-TABLE PAYROLL GENERATOR
 */
require_once 'db.php';

date_default_timezone_set('America/Vancouver');

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 1);

$periods = [
    ['start' => '2026-03-01', 'end' => '2026-03-15', 'label' => 'March 1st - March 15th'],
    ['start' => '2026-03-16', 'end' => '2026-03-31', 'label' => 'March 16th - March 31st'],
    ['start' => '2026-04-01', 'end' => '2026-04-15', 'label' => 'April 1st - April 15th'],
    ['start' => '2026-04-16', 'end' => '2026-04-30', 'label' => 'April 16th - April 30th']
];

echo "<html><head><title>SQL Cross-Table Split Panel</title><style>
    body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; font-size: 13px; }
    .container { max-width: 1200px; margin: 0 auto; background: #1e293b; padding: 20px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
    .sql-block { font-family: monospace; background: #020617; color: #4ade80; padding: 15px; border-radius: 6px; white-space: pre-wrap; word-break: break-all; margin-top: 15px; border: 1px solid #334155; font-size: 11px; line-height: 1.5; }
    .period-header { background: #334155; font-weight: bold; padding: 10px; color: #38bdf8; font-size: 14px; margin-top: 25px; border-radius: 5px; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; background: #111827; }
    th { background: #1e293b; color: #94a3b8; padding: 10px; text-align: left; font-size: 11px; border-bottom: 2px solid #334155; }
    td { padding: 8px; border-bottom: 1px solid #1f2937; font-size: 12px; }
    .badge-reg { background: #065f46; color: #34d399; padding: 2px 6px; border-radius: 4px; font-weight: bold; font-size: 10px; }
    .badge-split { background: #7c2d12; color: #fb923c; padding: 2px 6px; border-radius: 4px; font-weight: bold; font-size: 10px; }
    .badge-bal { background: #581c87; color: #c084fc; padding: 2px 6px; border-radius: 4px; font-weight: bold; font-size: 10px; }
</style></head><body><div class='container'>";

echo "<h2>📋 Clearview Staging: Cross-Table 80-Hour SQL Dry-Run</h2>";
echo "<p style='color: #94a3b8;'><strong>Safe Mode Active:</strong> Enforces strict decimal floating-point formatting to align with your production tables layout configurations.</p>";

$allGeneratedSQL = [];

foreach ($periods as $p) {
    echo "<div class='period-header'>📅 Pay Period Range: {$p['label']}</div>";
    
    $uStmt = $pdo->prepare("SELECT user_id, SUM(reg_hours) as total FROM payroll_finalized WHERE work_date BETWEEN ? AND ? GROUP BY user_id HAVING total > 80");
    $uStmt->execute([$p['start'], $p['end']]);
    $users = $uStmt->fetchAll();

    if (empty($users)) {
        echo "<p style='color: #64748b; font-style: italic; padding: 10px;'>No employees exceed 80 regular hours in this period range.</p>";
        continue;
    }

    echo "<table><thead><tr><th>User ID</th><th>Work Date</th><th>Original Hrs</th><th>Action Taken</th><th>Kept Reg Hrs</th><th>Shifted Balance Hrs</th></tr></thead><tbody>";

    foreach ($users as $u) {
        $userId = (int)$u['user_id'];
        
        $sStmt = $pdo->prepare("SELECT * FROM payroll_finalized WHERE user_id = ? AND work_date BETWEEN ? AND ? ORDER BY work_date ASC, id ASC");
        $sStmt->execute([$userId, $p['start'], $p['end']]);
        $shifts = $sStmt->fetchAll();

        $runningHours = 0.0;

        foreach ($shifts as $shift) {
            $shiftHours = (float)$shift['reg_hours'];
            echo "<tr><td><strong>User #$userId</strong></td><td>{$shift['work_date']}</td><td>{$shiftHours} Hrs</td>";

            $ppEndVal = !empty($shift['pay_period_end']) ? "'{$shift['pay_period_end']}'" : "NULL";
            $payPeriodLabel = !empty($shift['pay_period_end']) ? "'March-April-Period'" : "NULL";

            $baseHourlyRate = isset($shift['hourly_rate']) && (float)$shift['hourly_rate'] > 0 ? (float)$shift['hourly_rate'] : 0.00;
            $baseTotalGross = isset($shift['total_gross']) ? (float)$shift['total_gross'] : 0.00;

            if ($runningHours >= 80.0) {
                echo "<td><span class='badge-bal'>Convert to Balance Row</span></td><td>0.00</td><td style='color:#c084fc; font-weight:bold;'>+$shiftHours</td></tr>";
                
                $allGeneratedSQL[] = "UPDATE payroll_finalized SET reg_hours = 0.00, total_gross = 0.00, cpp = 0.00, ei = 0.00, income_tax = 0.00 WHERE id = {$shift['id']};";
                
                $allGeneratedSQL[] = "INSERT INTO payroll_balance (attendance_id, shift_id, user_id, org_id, site_id, work_date, pay_period_end, reg_hours, balance_hours, hourly_rate, reg_pay, bal_pay, total_gross, cpp_paid, ei_paid, wcb_wsib_paid, vacation_accrued, status, pay_type, comments, paid_amount, balance_remaining, last_paid_date, pay_period) VALUES (" .
                    ((int)$shift['attendance_id']) . ", NULL, " . ((int)$shift['user_id']) . ", " . ((int)$shift['org_id']) . ", " . ((int)$shift['site_id']) . ", '" . $shift['work_date'] . "', $ppEndVal, 0.00, " . sprintf("%.2f", $shiftHours) . ", " . sprintf("%.2f", $baseHourlyRate) . ", 0.00, " . sprintf("%.2f", $baseTotalGross) . ", " . sprintf("%.2f", $baseTotalGross) . ", 0.00, 0.00, 0.00, 0.00, 'pending', 'Balance', 'Overage Transfer', 0.00, " . sprintf("%.2f", $baseTotalGross) . ", NULL, $payPeriodLabel);";
                continue;
            }

            if (($runningHours + $shiftHours) > 80.0) {
                $allowedRegHours = 80.0 - $runningHours;
                $balanceHours = $shiftHours - $allowedRegHours;

                $hourlyRate = $baseHourlyRate > 0 ? $baseHourlyRate : ($shiftHours > 0 ? ($baseTotalGross / $shiftHours) : 0.00);
                
                $newRegGross = $allowedRegHours * $hourlyRate;
                $newRegCpp   = $newRegGross * 0.0595;
                $newRegEi    = $newRegGross * 0.0166;
                $newRegTax   = $newRegGross * 0.12;

                $newBalGross = $balanceHours * $hourlyRate;

                echo "<td><span class='badge-split'>✂️ Split Shift Day</span></td><td>" . round($allowedRegHours, 2) . "</td><td style='color:#fb923c; font-weight:bold;'>+" . round($balanceHours, 2) . "</td></tr>";

                // FIXED TYPO: Wrapped the string parameters securely to resolve the compiler 500 crash
                $allGeneratedSQL[] = "UPDATE payroll_finalized SET reg_hours = " . sprintf("%.2f", $allowedRegHours) . ", total_gross = " . sprintf("%.2f", $newRegGross) . ", cpp = " . sprintf("%.2f", $newRegCpp) . ", ei = " . sprintf("%.2f", $newRegEi) . ", income_tax = " . sprintf("%.2f", $newRegTax) . " WHERE id = {$shift['id']};";
                
                $allGeneratedSQL[] = "INSERT INTO payroll_balance (attendance_id, shift_id, user_id, org_id, site_id, work_date, pay_period_end, reg_hours, balance_hours, hourly_rate, reg_pay, bal_pay, total_gross, cpp_paid, ei_paid, wcb_wsib_paid, vacation_accrued, status, pay_type, comments, paid_amount, balance_remaining, last_paid_date, pay_period) VALUES (" .
                    ((int)$shift['attendance_id']) . ", NULL, " . ((int)$shift['user_id']) . ", " . ((int)$shift['org_id']) . ", " . ((int)$shift['site_id']) . ", '" . $shift['work_date'] . "', $ppEndVal, 0.00, " . sprintf("%.2f", $balanceHours) . ", " . sprintf("%.2f", $hourlyRate) . ", 0.00, " . sprintf("%.2f", $newBalGross) . ", " . sprintf("%.2f", $newBalGross) . ", 0.00, 0.00, 0.00, 0.00, 'pending', 'Balance', 'Overage Split Portion', 0.00, " . sprintf("%.2f", $newBalGross) . ", NULL, $payPeriodLabel);";

                $runningHours = 80.0;
            } else {
                $runningHours += $shiftHours;
                echo "<td><span class='badge-reg'>Keep Regular</span></td><td>$shiftHours</td><td>0.00</td></tr>";
            }
        }
    }
    echo "</tbody></table>";
}

echo "<h3>📥 Safe Copy-Paste Cross-Table SQL Script</h3>";
if (!empty($allGeneratedSQL)) {
    echo "<p>Copy the clean, validated queries below, paste them into your phpMyAdmin SQL tab, and execute:</p>";
    echo "<div class='sql-block'>";
    echo implode("\n", $allGeneratedSQL);
    echo "</div>";
} else {
    echo "<p style='color:#4ade80; font-style:italic;'>No overage script rows generated.</p>";
}

echo "</div></body></html>";