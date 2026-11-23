<?php
// 1. Start session to ensure the menu recognizes your login
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once 'db.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
require_once __DIR__ . '/payroll_engine/stat_holidays.php';

// 2. Include the menu generator
if (file_exists('engine/menu_generator.php')) { 
    include_once 'engine/menu_generator.php'; 
}

echo "<html><head><title>Payroll Generator</title>";
echo '<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">';
echo "<style>
    body { background-color: #f8f9fa; }
    /* Pushes content down so it is not hidden under the nav bar */
    .main-content { margin-top: 100px; padding-bottom: 50px; } 
    .log-container { background: #1e293b; color: #38bdf8; padding: 20px; border-radius: 8px; font-family: 'Courier New', monospace; height: 400px; overflow-y: auto; box-shadow: inset 0 2px 10px rgba(0,0,0,0.5); }
    .log-entry { margin-bottom: 5px; border-bottom: 1px solid #334155; padding-bottom: 2px; }
    .log-success { color: #4ade80; }
    .card { border: none; border-radius: 12px; }
</style></head><body>";

// --- 3. GENERATE TOP NAV ---
if (function_exists('generateMenu')) { 
    echo generateMenu($pdo); 
}

// Wrap content in main-content div
echo "<div class='container main-content'>";
echo "<div class='card shadow-sm p-4'>";
echo "<h2>🚀 Payroll Generation Engine</h2>";
echo "<p class='text-muted'>This tool groups individual daily shifts into master pay periods and calculates tax obligations.</p><hr>";

// --- 4. CONFIGURATION (DATES) ---
$target_date = '2026-02-10'; 
$day = date('j', strtotime($target_date));
$month_year = date('Y-m', strtotime($target_date));

if ($day <= 15) {
    $start_date = "$month_year-01 00:00:00";
    $end_date   = "$month_year-15 23:59:59";
    $period_label = date('M 01 - 15, Y', strtotime($target_date));
} else {
    $start_date = "$month_year-16 00:00:00";
    $end_date   = date('Y-m-t 23:59:59', strtotime($target_date));
    $period_label = date('M 16 - t, Y', strtotime($target_date));
}

echo "<div class='alert alert-info'><strong>Target Period:</strong> $period_label</div>";
echo "<div class='log-container' id='process-log'>";

// --- 5. AGGREGATE TOTALS FROM ATTENDANCE ---
$sql = "SELECT user_id, org_id, site_id, SUM(amount) as gross_total 
        FROM payroll_finalized 
        WHERE clock_in BETWEEN ? AND ? 
        AND payroll_id IS NULL 
        GROUP BY user_id, site_id";

$stmt = $pdo->prepare($sql);
$stmt->execute([$start_date, $end_date]);
$summaries = $stmt->fetchAll();

if (!$summaries) {
    echo "<div class='log-entry'>[SYSTEM] No unlinked records found for $period_label. Everything is already processed.</div>";
} else {
    foreach ($summaries as $row) {
        $uid = $row['user_id'];
        $sid = $row['site_id'];
        $gross = $row['gross_total'];

        $asOf = substr((string)$end_date, 0, 10);
        sh_after_finalize($pdo, (int)$uid, (int)$sid, $asOf, 0);
        $gen = pe_line((float)$gross, pe_vacation_rate($pdo, (int)$uid, (int)$sid, $asOf), pe_rates($pdo), [
            'pdo' => $pdo, 'user_id' => (int)$uid, 'site_id' => (int)$sid, 'org_id' => (int)$row['org_id'], 'work_date' => $asOf,
        ]);
        $cra_deductions = (float)$gen['cpp'] + (float)($gen['cpp2'] ?? 0) + (float)$gen['ei'] + (float)$gen['income_tax'];
        $er_contribs = (float)$gen['cpp_er'] + (float)($gen['cpp2_er'] ?? 0) + (float)$gen['ei_er'] + (float)$gen['wcb'];
        $net_payable = (float)$gen['net'];

        // --- 7. CREATE MASTER ENTRY ---
        $ins = $pdo->prepare("INSERT INTO payrolls (org_id, site_id, user_id, pay_period, gross_total, er_contributions, cra_deductions, net_payable, status) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Unpaid')");
        $ins->execute([$row['org_id'], $sid, $uid, $period_label, $gross, $er_contribs, $cra_deductions, $net_payable]);
        
        $new_payroll_id = $pdo->lastInsertId();

        // --- 8. STAPLE THE DETAILS ---
        $update = $pdo->prepare("UPDATE payroll_finalized SET payroll_id = ? 
                                 WHERE user_id = ? AND site_id = ? 
                                 AND clock_in BETWEEN ? AND ?");
        $update->execute([$new_payroll_id, $uid, $sid, $start_date, $end_date]);

        echo "<div class='log-entry log-success'>[SUCCESS] Created Payroll #$new_payroll_id for User ID $uid (Site $sid) | Net: $$net_payable</div>";
    }
    echo "<div class='log-entry'>[SYSTEM] Batch complete. All records linked.</div>";
}
$genOrgs = [];
foreach ($summaries as $row) {
    $genOrgs[(int)$row['org_id']] = true;
}
$genStart = substr((string)$start_date, 0, 10);
$genEnd = substr((string)$end_date, 0, 10);
foreach (array_keys($genOrgs) as $genOrg) {
    if ($genOrg <= 0 || !function_exists('sh_generate_period_candidates')) {
        continue;
    }
    $made = sh_generate_period_candidates($pdo, (int)$genOrg, $genStart . '|' . $genEnd, 0);
    echo "<div class='log-entry'>[STAT] Org $genOrg pending lines created: " . (int)$made['posted'] . ". Nothing was approved or paid.</div>";
}

echo "</div>"; // Close log
echo "<div class='mt-4 text-center'>";
echo "<a href='payroll_manage.php' class='btn btn-primary btn-lg'>Go to Payroll Dashboard 💳</a>";
echo "</div>";

echo "</div></div></body></html>";
?>