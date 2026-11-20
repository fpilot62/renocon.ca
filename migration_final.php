<?php
/**
 * Path: /migration_final.php
 * PRODUCTION SQL GENERATOR ENGINE - CSV PARSING ONLY
 */
require_once 'db.php';

date_default_timezone_set('America/Vancouver');

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<html><head><title>Clearview SQL Generation Panel</title><style>
    body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; font-size: 13px; }
    .container { max-width: 1200px; margin: 0 auto; background: #1e293b; padding: 20px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
    .upload-box { background: #334155; border: 2px dashed #38bdf8; padding: 20px; border-radius: 8px; margin-bottom: 25px; text-align: center; }
    .sql-block { font-family: monospace; background: #020617; color: #4ade80; padding: 15px; border-radius: 6px; white-space: pre-wrap; word-break: break-all; margin-top: 15px; border: 1px solid #334155; font-size: 12px; line-height: 1.6; }
    h3 { color: #38bdf8; margin-top: 30px; border-bottom: 1px solid #334155; padding-bottom: 5px; }
    .alert-info { color: #93c5fd; background: #1e3a8a; padding: 10px; border-radius: 5px; margin-bottom: 15px; }
</style></head><body><div class='container'>";

echo "<h2>📋 Clearview Migration: SQL Batch Script Generator</h2>";
echo "<div class='alert-info'><strong>Database execution is paused.</strong> This page will strictly translate your Excel CSV file into clean raw SQL statements that you can copy and execute directly in your phpMyAdmin SQL tab.</div>";

echo '<div class="upload-box">';
echo '<form method="POST" enctype="multipart/form-data" style="display:inline-block;">';
echo '<strong>Select Payroll CSV File (.csv): </strong> ';
echo '<input type="file" name="payroll_csv" accept=".csv" required style="margin-right:15px; color:white;">';
echo '<button type="submit" style="padding: 8px 20px; background: #38bdf8; color: #0f172a; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">Generate SQL Scripts</button>';
echo '</form>';
echo '</div>';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['payroll_csv']['tmp_name'])) {
    $csvFile = $_FILES['payroll_csv']['tmp_name'];

    $currentExcelName = ""; 
    $userId = null;
    $fallbackSiteId = 23; 
    
    $generatedQueries = [];

    if (($handle = fopen($csvFile, "r")) !== FALSE) {
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $data = array_map('trim', $data);

            // Dynamically strip text headers and totals rows
            if (empty($data[0]) || strtolower($data[0]) == 'name' || strtolower($data[0]) == 'total' || strpos(strtolower($data[0]), 'report') !== false || empty($data[1])) {
                continue; 
            }

            // --- IDENTITY MAPPING ENGINE ---
            $rawName = trim(preg_replace('/^[0-9\s\-]+/', '', $data[0])); 
            if ($rawName !== $currentExcelName) {
                $currentExcelName = $rawName;
                $searchName = $currentExcelName;
                if (strpos($currentExcelName, ',') !== false) {
                    $parts = explode(',', $currentExcelName);
                    $searchName = trim($parts[1] ?? '') . ' ' . trim($parts[0] ?? '');
                }

                $uStmt = $pdo->prepare("SELECT id, primary_site_id FROM users WHERE Name LIKE ? OR Name LIKE ? LIMIT 1");
                $uStmt->execute(["%$searchName%", "%$currentExcelName%"]);
                $u = $uStmt->fetch();
                
                $userId = $u ? (int)$u['id'] : null;
                $fallbackSiteId = ($u && !empty($u['primary_site_id'])) ? (int)$u['primary_site_id'] : 23;
            }

            // Skip records if user mapping cannot locate employee account safely
            if (!$userId) continue;

            $dateStr    = isset($data[1]) ? $data[1] : ''; 
            $timeInRaw  = isset($data[2]) ? str_replace('.', ':', $data[2]) : ''; 
            $timeOutRaw = isset($data[3]) ? str_replace('.', ':', $data[3]) : ''; 
            
            if (empty($dateStr) || empty($timeInRaw) || empty($timeOutRaw)) continue;

            $time_in_epoch  = strtotime("$dateStr $timeInRaw");
            $time_out_epoch = strtotime("$dateStr $timeOutRaw");

            if (!$time_in_epoch || !$time_out_epoch || $time_in_epoch < 86400) continue;

            $cin_val  = date('Y-m-d H:i:s', $time_in_epoch);
            $cout_val = date('Y-m-d H:i:s', $time_out_epoch);

            // Handle overnight shifts natively
            if ($time_out_epoch <= $time_in_epoch) {
                $cout_val = date('Y-m-d H:i:s', strtotime($cout_val . ' + 1 day'));
            }

            // Structure safe, optimized insert syntax
            $generatedQueries[] = "INSERT INTO attendance (user_id, org_id, site_id, clock_in, clock_out, status, Remarks, payout_status) VALUES ($userId, 2, $fallbackSiteId, '$cin_val', '$cout_val', 0, 'CV Master Migration Final', 0);";
        }
        fclose($handle);
    }

    // Output query text strings directly onto page grouped into manageable sets
    if (!empty($generatedQueries)) {
        echo "<h3>✅ Generated SQL Query Script Rows (" . count($generatedQueries) . " Queries Found)</h3>";
        echo "<p>Copy each block of text below and run them directly inside your phpMyAdmin SQL interface tab:</p>";
        
        $chunks = array_chunk($generatedQueries, 50); // Split text rows into batches of 50 lines for clean visualization copy
        foreach ($chunks as $index => $chunk) {
            $batchNum = $index + 1;
            echo "<h4>📥 Batch Script Section #$batchNum (" . count($chunk) . " Rows)</h4>";
            echo "<div class='sql-block' onclick='this.select;'>";
            echo implode("\n", $chunk);
            echo "</div>";
        }
    } else {
        echo "<p style='color:#ef4444; font-weight:bold;'>No records successfully mapped. Ensure you are uploading the comma-separated .csv document template.</p>";
    }
}
echo "</div></body></html>";