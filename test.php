<?php
/** Path: /verify_import.php */
require_once 'db.php';

$file = 'payroll_feb.csv';
if (!file_exists($file)) { die("CSV not found."); }

$handle = fopen($file, "r");
fgetcsv($handle); // Skip header

echo "<table border='1' cellpadding='5' style='border-collapse:collapse; font-family:sans-serif; font-size:13px;'>
        <tr style='background:#0f172a; color:white;'>
            <th>Original CSV String</th>
            <th>Cleaned Name (Target)</th>
            <th>Database Match</th>
            <th>Site ID</th>
            <th>Total Hours</th>
        </tr>";

while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
    $emp_raw = $data[0] ?? '';
    $reg = (float)($data[6] ?? 0);
    $ot  = (float)($data[7] ?? 0);
    $st  = (float)($data[8] ?? 0);
    $total = $reg + $ot + $st;

    if ($total <= 0) continue;

    // --- NEW CLEANING LOGIC ---
    // This turns "000080 - Kumar, Deepak" into "Kumar, Deepak"
    $clean_name = $emp_raw; 
    if (strpos($emp_raw, ' - ') !== false) {
        $parts = explode(' - ', $emp_raw);
        $clean_name = trim($parts[1]); 
    }

    // Search for the cleaned name
    $u_stmt = $pdo->prepare("SELECT Name, assigned_site_ids FROM users WHERE Name LIKE ? LIMIT 1");
    $u_stmt->execute(["%$clean_name%"]);
    $user = $u_stmt->fetch();

    $site_id = "N/A";
    $match_display = "<span style='color:red; font-weight:bold;'>❌ NO MATCH FOUND</span>";

    if ($user) {
        $match_display = "<span style='color:green; font-weight:bold;'>✅ " . htmlspecialchars($user['Name']) . "</span>";
        $site_id = explode(',', $user['assigned_site_ids'])[0] ?: "No Site";
    }

    echo "<tr>
            <td>" . htmlspecialchars($emp_raw) . "</td>
            <td>" . htmlspecialchars($clean_name) . "</td>
            <td>$match_display</td>
            <td>$site_id</td>
            <td>$total</td>
          </tr>";
}
echo "</table>";
fclose($handle);
?>