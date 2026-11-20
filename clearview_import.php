<?php
/** Path: /clearview_import.php */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();
require_once 'db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$dummy_site_id = 999; 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_csv'])) {
    
    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, "r");
    if (!$handle) { die("Could not open the uploaded CSV file."); }

    $imported = 0;
    $skipped = 0;
    $notFound = [];

    fgetcsv($handle); // Skip Header

    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if (!isset($data[1]) || empty(trim($data[1]))) continue; 

        $raw_name = trim($data[1]);
        $payable_hrs = !empty($data[7]) ? (float)$data[7] : (float)($data[6] ?? 0);
        
        if ($payable_hrs <= 0) { $skipped++; continue; }

        $name_parts = explode(',', $raw_name);
        $formatted_name = (count($name_parts) == 2) ? trim($name_parts[1]) . ' ' . trim($name_parts[0]) : $raw_name;

        // 1. Find Employee
        $u_stmt = $pdo->prepare("SELECT id, org_id FROM users WHERE Name = ? LIMIT 1");
        $u_stmt->execute([$formatted_name]);
        $user = $u_stmt->fetch();

        if ($user) {
            $in_ts  = strtotime($data[4]);
            $out_ts = strtotime($data[5]);
            
            if (!$in_ts || !$out_ts) { continue; }

            $actual_worked_hrs = ($out_ts - $in_ts) / 3600;
            $travel = 0;
            $break_hrs = 0;

            if ($payable_hrs > $actual_worked_hrs) {
                $travel = $payable_hrs - $actual_worked_hrs;
                $break_hrs = 0.5; 
            } else {
                $break_hrs = $actual_worked_hrs - $payable_hrs;
                if ($break_hrs < 0.5) $break_hrs = 0.5; 
            }

            // 2. Insert Attendance
            try {
                // We use backticks `` around column names to avoid reserved word conflicts
                $sql = "INSERT INTO attendance (`user_id`, `org_id`, `site_id`, `clock_in`, `clock_out`, `travel_time_hours`, `Remarks`) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $user['id'], $user['org_id'], $dummy_site_id, 
                    date('Y-m-d H:i:s', $in_ts), date('Y-m-d H:i:s', $out_ts), 
                    $travel, "CV Migration Import"
                ]);
                $shift_id = $pdo->lastInsertId();

                // 3. Insert Break record
                $break_secs = (int)($break_hrs * 3600);
                $b_stmt = $pdo->prepare("INSERT INTO breaks (shift_id, break_start, break_end) VALUES (?, ?, ?)");
                $b_stmt->execute([$shift_id, date('Y-m-d H:i:s', $in_ts), date('Y-m-d H:i:s', $in_ts + $break_secs)]);

                $imported++;
            } catch (Exception $e) {
                die("SQL Error on Name $formatted_name: " . $e->getMessage());
            }
        } else {
            $notFound[] = $formatted_name;
        }
    }
    fclose($handle);
    
    echo "<h3>Import Finished</h3>";
    echo "Imported: $imported rows successfully.<br>";
    echo "Users not found in DB: " . count($notFound) . "<br>";
    echo "<br><a href='staff_audit.php' class='btn btn-success'>View Imported Data in Audit</a>";
    exit;
}
?>