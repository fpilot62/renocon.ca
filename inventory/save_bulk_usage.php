<?php
/** Path: /inventory/save_bulk_usage.php */
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. Database Connection
require_once dirname(__DIR__) . '/db.php';
$db = $pdo;

// 2. Process POST Data
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['usage'])) {
    
    $rid = (int)$_POST['rid'];
    $usage_date = !empty($_POST['usage_date']) ? $_POST['usage_date'] : date('Y-m-d');
    $org_id = $_SESSION['org_id'] ?? 1;

    /**
     * SQL LOGIC:
     * We insert the usage. If a record already exists for this item/site/date, 
     * we UPDATE the existing record instead of creating a duplicate.
     */
    $sql = "INSERT INTO Gem_inventory_usage 
            (item_id, site_id, org_id, usage_date, sold_qty, actual_qty_pcs) 
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            sold_qty = VALUES(sold_qty), 
            actual_qty_pcs = VALUES(actual_qty_pcs)";
            
    $stmt = $db->prepare($sql);
    $inserted_count = 0;

    foreach ($_POST['usage'] as $item_id => $data) {
        $qty_pcs = (float)$data['total_pcs'];

        // Only save if they actually entered a number
        if ($qty_pcs > 0) {
            $stmt->execute([
                $item_id, 
                $rid, 
                $org_id, 
                $usage_date, 
                $qty_pcs, // Logged as 'sold_qty' for usage trends
                $qty_pcs  // Logged as 'actual_qty_pcs' for current stock levels
            ]);
            $inserted_count++;
        }
    }

    // Success Screen
    echo "
    <div style='font-family:\"Inter\", sans-serif; text-align:center; padding:100px; background:#f1f5f9; height:100vh;'>
        <div style='background:white; display:inline-block; padding:50px; border-radius:20px; box-shadow:0 10px 25px rgba(0,0,0,0.05);'>
            <div style='color:#10b981; font-size:60px; margin-bottom:20px;'><i class='fas fa-check-circle'></i></div>
            <h1 style='color:#0f172a; margin:0;'>Log Saved!</h1>
            <p style='color:#64748b;'>Successfully recorded usage for <b>$inserted_count</b> items.</p>
            <br>
            <a href='record_usage.php?rid=$rid' style='background:#0ea5e9; color:white; padding:12px 30px; text-decoration:none; border-radius:10px; font-weight:800;'>Back to Entry Sheet</a>
            <div style='margin-top:20px;'>
                <a href='inventory_report.php?rid=$rid' style='color:#64748b; text-decoration:none; font-size:13px; font-weight:600;'>View Stock Report →</a>
            </div>
        </div>
    </div>
    <script src='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js'></script>";

} else {
    // If someone hits this page directly, send them back
    header("Location: index.php");
    exit;
}