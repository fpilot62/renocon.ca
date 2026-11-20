<?php
/**
 * Path: /payroll/migration_identity_fix.php
 * MASTER PRODUCTION MIGRATION BUILD - HARDENED EPOCH PROTECTION (CAPPED AT 20 RECORDS)
 */
require_once 'db.php';
require_once __DIR__ . '/engine/attendance_audit_lib.php';

// LOCK IMPORTER FRAMEWORK TO OPERATIONS TIMEZONE
date_default_timezone_set('America/Vancouver');

ini_set('auto_detect_line_endings', true);
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- 1. AJAX PROCESSOR (Saves duplicate rows deliberately for comparison) ---
if (isset($_POST['ajax_import'])) {
    $uid  = (int)$_POST['uid'];
    $sid  = (int)$_POST['sid'];
    $cin_raw = $_POST['cin'];
    $cout_raw = $_POST['cout'];

    $final_cin  = date('Y-m-d H:i:s', strtotime($cin_raw));
    $final_cout = date('Y-m-d H:i:s', strtotime($cout_raw));

    // Stacking rows with a distinctive remark tag to contrast against previous logs
    $ins = $pdo->prepare("INSERT INTO attendance (user_id, org_id, site_id, clock_in, clock_out, status, Remarks, payout_status)  
                          VALUES (?, 2, ?, ?, ?, 0, 'CV Military Test Run', 0)");
    
    if ($ins->execute([$uid, $sid, $final_cin, $final_cout])) {
        aa_log($pdo, 'create', (int)$pdo->lastInsertId(), null, null, 'Identity migration');
        echo "SUCCESS";
    } else {
        echo "ERROR";
    }
    exit;
}

// --- 2. UI HEADER & STYLES ---
echo "<html><head><title>Clearview Migration: Comparison Mode</title><style>
    body { font-family: 'Segoe UI', sans-serif; background: #f1f5f9; padding: 20px; font-size: 13px; }
    .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    .upload-box { background: #fff7ed; border: 2px dashed #f97316; padding: 20px; border-radius: 8px; margin-bottom: 25px; text-align: center; }
    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    th { background: #1e293b; color: white; padding: 12px; text-align: left; position: sticky; top: 0; }
    td { padding: 10px; border-bottom: 1px solid #e2e8f0; }
    .btn-row { cursor: pointer; padding: 8px 15px; background: #f97316; color: white; border: none; border-radius: 5px; font-weight: bold; }
    .btn-row:disabled { background: #94a3b8; cursor: not-allowed; }
    .err { color: #ef4444; font-weight: bold; padding: 4px 8px; background: #fee2e2; border-radius: 4px; }
    .military-time { font-family: monospace; background: #ffedd5; color: #9a3412; padding: 2px 6px; border-radius: 4px; font-weight: bold; }
</style></head><body><div class='container'>";

echo "<h2>⚖️ Clearview Migration: 20-Row Comparison Mode (Epoch Protection Active)</h2>";

echo '<div class="upload-box">';
echo '<form method="POST" enctype="multipart/form-data" style="display:inline-block; gap:15px;">';
echo '<strong>Select Payroll CSV File: </strong> ';
echo '<input type="file" name="payroll_csv" accept=".csv" required style="margin-right:15px;">';
echo '<button type="submit" style="padding: 8px 20px; background: #f97316; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">Upload & Convert to 24h Time</button>';
echo '</form>';
echo '</div>';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['payroll_csv']['tmp_name'])) {
    $csvFile = $_FILES['payroll_csv']['tmp_name'];
    
    echo '<button id="process-all-btn" onclick="processAll()" style="padding: 12px 24px; background: #e65c00; color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; margin-bottom: 20px;">🚀 Force Save These 20 Parallel Records</button>';
    echo "<table><thead><tr><th>Action</th><th>Excel Name</th><th>DB Match</th><th>Default Site ID</th><th>Date</th><th>24h Clock In</th><th>24h Clock Out</th></tr></thead><tbody>";

    $currentExcelName = ""; 
    $userId = null;
    $dbNameDisplay = "";
    $fallbackSiteId = 23; 

    if (($handle = fopen($csvFile, "r")) !== FALSE) {
        $rowNum = 0;
        $loadedRowsCount = 0;

        while (($raw_data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $rowNum++;
            if ($rowNum == 1 || empty($raw_data[0]) || trim($raw_data[0]) == 'Name') continue;

            $data = $raw_data;
            if (count($data) == 1 && strpos($data[0], ';') !== false) {
                $data = explode(';', $data[0]);
            }

            $data = array_map('trim', $data);

            // --- CRITICAL FILTER: Skip completely blank lines or summary footer labels ---
            if (empty($data[0]) || empty($data[1]) || strtolower($data[0]) == 'total' || strpos(strtolower($data[0]), 'report') !== false) {
                continue; 
            }

            // --- IDENTITY & SITE MAPPING LOOKUP ---
            $rawName = trim(preg_replace('/^[0-9\s\-]+/', '', $data[0])); 
            if ($rawName !== $currentExcelName) {
                $currentExcelName = $rawName;
                
                $searchName = $currentExcelName;
                if (strpos($currentExcelName, ',') !== false) {
                    $parts = explode(',', $currentExcelName);
                    $searchName = trim($parts[1] ?? '') . ' ' . trim($parts[0] ?? '');
                }

                $uStmt = $pdo->prepare("SELECT id, Name, primary_site_id FROM users WHERE Name LIKE ? OR Name LIKE ? LIMIT 1");
                $uStmt->execute(["%$searchName%", "%$currentExcelName%"]);
                $u = $uStmt->fetch();
                
                $userId = $u ? $u['id'] : null;
                $dbNameDisplay = $u ? $u['Name'] : "<span class='err'>User Not Found</span>";
                $fallbackSiteId = ($u && !empty($u['primary_site_id'])) ? (int)$u['primary_site_id'] : 23;
            }

            // --- INDEX ANCHOR MATRIX ---
            $dateStr = isset($data[1]) ? $data[1] : ''; 
            $timeInRaw  = isset($data[2]) ? str_replace('.', ':', $data[2]) : ''; 
            $timeOutRaw = isset($data[3]) ? str_replace('.', ':', $data[3]) : ''; 
            
            $siteId  = $fallbackSiteId; 

            if (empty($dateStr) || empty($timeInRaw) || empty($timeOutRaw)) continue;

            // Strict transformation checks to block Unix Epoch evaluation bugs (1969 / 1970)
            $time_in_epoch  = strtotime("$dateStr $timeInRaw");
            $time_out_epoch = strtotime("$dateStr $timeOutRaw");

            if (!$time_in_epoch || !$time_out_epoch || $time_in_epoch < 86400) {
                continue; // Skip the row cleanly if string math fails evaluation parameters
            }

            $cin_val  = date('Y-m-d H:i:s', $time_in_epoch);
            $cout_val = date('Y-m-d H:i:s', $time_out_epoch);

            if ($time_out_epoch <= $time_in_epoch) {
                $cout_val = date('Y-m-d H:i:s', strtotime($cout_val . ' + 1 day'));
            }

            $milInDisplay  = date('H:i', strtotime($cin_val));
            $milOutDisplay = date('H:i', strtotime($cout_val));

            echo "<tr>";
            echo "<td>" . ($userId ? "<button class='btn-row' onclick=\"importRow(this, $userId, $siteId, '$cin_val', '$cout_val')\">Force Import</button>" : "<span class='err'>NOT FOUND</span>") . "</td>";
            echo "<td>$currentExcelName</td><td>$dbNameDisplay</td><td><strong>$siteId</strong></td><td>$dateStr</td><td><span class='military-time'>$milInDisplay</span></td><td><span class='military-time'>$milOutDisplay</span></td>";
            echo "</tr>";

            $loadedRowsCount++;
            if ($loadedRowsCount >= 20) {
                break; 
            }
        }
        fclose($handle);
    }
    echo "</tbody></table>";
} else {
    echo "<p style='color:#64748b; font-style:italic; text-align:center; margin-top:40px;'>Please upload your modified file in an incognito window to view your parallel comparison rows.</p>";
}
echo "</div>";
?>

<script>
async function processAll() {
    const btn = document.getElementById('process-all-btn');
    const all = Array.from(document.querySelectorAll('.btn-row:not(:disabled)'));
    if (!confirm(`Force duplicate entry import for these ${all.length} items to allow live dashboard comparison?`)) return;
    btn.disabled = true;
    for (let i = 0; i < all.length; i++) {
        const p = all[i].getAttribute('onclick').match(/\(([^)]+)\)/)[1].split(',').map(s => s.trim().replace(/'/g, ""));
        await importRow(all[i], ...p);
        btn.innerText = `⏳ Stacking Row: ${i+1} / ${all.length}`;
        await new Promise(r => setTimeout(r, 80)); 
    }
    btn.innerText = "✅ Parallel Test Records Stacked!";
}

function importRow(btn, uid, sid, cin, cout) {
    return new Promise((resolve) => {
        btn.disabled = true;
        let f = new FormData();
        f.append('ajax_import', 1); f.append('uid', uid); f.append('sid', sid); f.append('cin', cin); f.append('cout', cout);
        fetch(window.location.href, { method: 'POST', body: f })
        .then(r => r.text()).then(t => {
            if (t.trim() === 'SUCCESS') {
                btn.innerText = 'STACKED'; btn.style.background = '#94a3b8';
            } else {
                btn.innerText = 'ERR'; btn.style.background = 'red';
            }
            resolve();
        }).catch(() => resolve());
    });
}
</script></body></html>