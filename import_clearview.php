<?php
/** Path: /import_clearview_all.php */
require_once 'db.php';
require_once __DIR__ . '/engine/attendance_audit_lib.php';

try {
    $pdo->beginTransaction();

    // 1. CLEAR EXISTING FEBRUARY DATA
    // Clears raw attendance and finalized payroll for Feb 2026
    $aaIds = aa_ids_where($pdo, "clock_in BETWEEN '2026-02-01 00:00:00' AND '2026-02-28 23:59:59'", []);
    $aaBefore = aa_befores($pdo, $aaIds);
    $pdo->query("DELETE FROM attendance WHERE clock_in BETWEEN '2026-02-01 00:00:00' AND '2026-02-28 23:59:59'");
    aa_log_ids($pdo, 'delete', $aaIds, $aaBefore, 'Clearview February replace');
    $pdo->query("DELETE FROM payroll_finalized WHERE work_date BETWEEN '2026-02-01' AND '2026-02-28'");
    
    $file = 'payroll_feb.csv'; // Ensure your Clearview export is named this
    if (!file_exists($file)) { die("Error: CSV file not found."); }

    $handle = fopen($file, "r");
    $rowCount = 0;
    fgetcsv($handle); // Skip header row

    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        // Mapping: A: Emp, B: Date, G: Reg, H: OT, I: Stat
        $emp_raw  = $data[0] ?? '';
        $date_raw = $data[1] ?? '';
        $reg_hrs  = (float)($data[6] ?? 0); 
        $ot_hrs   = (float)($data[7] ?? 0);
        $stat_hrs = (float)($data[8] ?? 0);

        if (empty($emp_raw) || ($reg_hrs + $ot_hrs + $stat_hrs) <= 0) continue;

        // Extract Name from "000080 - Kumar, Deepak"
        $parts = explode(' - ', $emp_raw);
        $full_name = trim($parts[1] ?? '');

        // Match to Database User
        $u_stmt = $pdo->prepare("SELECT id, org_id, assigned_site_ids FROM users WHERE Name LIKE ? LIMIT 1");
        $u_stmt->execute(["%$full_name%"]);
        $user = $u_stmt->fetch();

        if ($user) {
            $work_date = date('Y-m-d', strtotime($date_raw));
            $clock_in  = $work_date . " 08:00:00";
            
            // Combine all hours into one duration for the Audit screen
            // Your finalize_shift.php will use the checkbox logic to split these later
            $total_seconds = ($reg_hrs + $ot_hrs + $stat_hrs) * 3600;
            $clock_out = date('Y-m-d H:i:s', strtotime($clock_in) + $total_seconds);

            // Use their primary assigned site
            $site_id = explode(',', $user['assigned_site_ids'])[0] ?: 1;

            $sql = "INSERT INTO attendance (
                        user_id, org_id, site_id, status, 
                        clock_in, clock_out, notes, transfer_status
                    ) VALUES (?, ?, ?, '4', ?, ?, 'CLEARVIEW IMPORT', 'none')";
            
            $pdo->prepare($sql)->execute([
                $user['id'], 
                $user['org_id'], 
                $site_id, 
                $clock_in, 
                $clock_out
            ]);
            aa_log($pdo, 'create', (int)$pdo->lastInsertId(), null, null, 'Clearview import');
            $rowCount++;
        }
    }

    fclose($handle);
    $pdo->commit();
    echo "<h1>Success</h1>";
    echo "<p>February cleaned and $rowCount shifts imported to Staff Audit.</p>";
    echo "<a href='staff_audit.php'>Go to Staff Audit to Approve</a>";

} catch (Exception $e) {
    $pdo->rollBack();
    die("Import Error: " . $e->getMessage());
}