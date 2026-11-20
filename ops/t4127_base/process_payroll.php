<?php
include 'db.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
if (is_file(__DIR__ . '/break_waiver_finalize_guard.php')) {
    require_once __DIR__ . '/break_waiver_finalize_guard.php';
}

// Check if any checkboxes were actually ticked
if (!isset($_POST['selected_ids']) || empty($_POST['selected_ids'])) {
    die("<h1>⚠️ Selection Required</h1><p>Please go back and select at least one shift to process.</p><a href='bulk_finalize.php'>Back to List</a>");
}

try {
    // Standard Canadian Burden Rates
    $cpp_r = 0.0595; $ei_r = 0.0163; $ei_m = 1.4; $wcb_r = 0.0155;

    foreach ($_POST['selected_ids'] as $attendance_id) {
        
        // 1. Fetch official record for this ID
        $stmt = $pdo->prepare("SELECT a.*, u.official_rate FROM attendance a JOIN users u ON a.user_id = u.id WHERE a.id = ?");
        $stmt->execute([$attendance_id]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$shift) continue;

        // 2. Handle the Date (Calgary Time)
        $dt = new DateTime($shift['clock_in'], new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('America/Edmonton'));
        $work_date = $dt->format('Y-m-d');

        // 3. Collect form data
        $reg_h   = (float)$_POST['s'][$attendance_id]['reg'];
        $bal_h   = (float)$_POST['s'][$attendance_id]['bal'];
        $is_stat = isset($_POST['s'][$attendance_id]['is_stat']) ? 1 : 0;
        $rate    = pe_profile_hourly_rate($pdo, (int)$shift['user_id'], $work_date);

        // 4. Calculations
        $current_rate = $is_stat ? ($rate * 1.5) : $rate;
        $reg_pay      = $reg_h * $current_rate;
        $bal_pay      = $bal_h * $current_rate;
        $stat_bonus   = $is_stat ? ($rate * 8) : 0;
        $total_gross  = $reg_pay + $bal_pay + $stat_bonus;

        // 5. Insert Finalized Record
        $sql = "INSERT INTO payroll_finalized (
                    attendance_id, user_id, org_id, site_id, work_date,
                    reg_hours, balance_hours, hourly_rate, reg_pay, bal_pay, 
                    total_gross, cpp_paid, ei_paid, wcb_wsib_paid, vacation_accrued, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'finalized')";

        $ins = $pdo->prepare($sql);
        $ins->execute([
            $attendance_id, $shift['user_id'], $shift['org_id'], $shift['site_id'], $work_date,
            $reg_h, $bal_h, $rate, $reg_pay, $bal_pay, $total_gross,
            ($total_gross * $cpp_r), (($total_gross * $ei_r) * $ei_m), ($total_gross * $wcb_r), ($total_gross * 0.04)
        ]);
        
        // 6. UPDATE ATTENDANCE - This makes it disappear from the audit list
        $pdo->prepare("UPDATE attendance SET status = 'processed' WHERE id = ?")->execute([$attendance_id]);
    }

    echo "<h1>✅ Success</h1><p>Processed " . count($_POST['selected_ids']) . " shifts based on Calgary time.</p>";
    echo "<a href='bulk_finalize.php' style='display:inline-block; padding:10px 20px; background:#007bff; color:#fff; text-decoration:none; border-radius:5px;'>Return to Payroll</a>";

} catch (Exception $e) {
    echo "<h3>❌ Error Processing:</h3> " . $e->getMessage();
}