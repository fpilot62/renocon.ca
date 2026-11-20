<?php
/** * Path: /forecast_cron.php 
 * FULL VERSION: Aggregates multiple daily entries (Sales & FoodCost) into single totals per store.
 */

// 1. Connection Setup
$host = "localhost"; 
$user = "fpilot"; 
$pass = "767265_sHaikH!"; 

$src_db  = "fpilot_penticton"; 
$dest_db = "admin_payroll";

$conn_src  = new mysqli($host, $user, $pass, $src_db);
$conn_dest = new mysqli($host, $user, $pass, $dest_db);

if ($conn_src->connect_error || $conn_dest->connect_error) {
    die("Connection Error: " . $conn_src->connect_error . " / " . $conn_dest->connect_error);
}

// 2. Define "Yesterday"
$yesterday = date('Y-m-d', strtotime("-1 days"));
echo "<h2>Running Aggregated Daily Sync for: $yesterday</h2>";

// --- PART 1: DAILY REPORT SYNC (Sales) ---
// Wipe destination records for yesterday so we can rewrite the totals
$conn_dest->query("DELETE FROM daily_report WHERE report_date = '$yesterday'");

// Fetch SUM of sales grouped by store
$s_sql = "SELECT restaurant_id, SUM(pos_sales) as total_sales 
          FROM daily_report 
          WHERE report_date = '$yesterday' 
          GROUP BY restaurant_id";

$s_res = $conn_src->query($s_sql);

if ($s_res && $s_res->num_rows > 0) {
    echo "<h3>Sales Totals</h3>";
    while ($s = $s_res->fetch_assoc()) {
        $rid   = $s['restaurant_id'];
        $sales = $s['total_sales'];
        
        $ins_s = "INSERT INTO daily_report (restaurant_id, report_date, pos_sales) 
                  VALUES ('$rid', '$yesterday', '$sales')";
        
        if($conn_dest->query($ins_s)) {
            echo "✅ Store #$rid: Total Sales \$$sales<br>";
        }
    }
} else {
    echo "⚠️ No sales found for $yesterday.<br>";
}

// --- PART 2: FOODCOST SYNC (Aggregation Fix) ---
// Wipe destination costs for yesterday
$conn_dest->query("DELETE FROM FoodCost WHERE InvoiceDate = '$yesterday'");

// Fetch SUM of all invoices for each store (RId)
$f_sql = "SELECT RId, MAX(ReportingMonth) as r_month, SUM(Amount) as total_amt 
          FROM FoodCost 
          WHERE InvoiceDate = '$yesterday' 
          GROUP BY RId";

$f_res = $conn_src->query($f_sql);

if ($f_res && $f_res->num_rows > 0) {
    echo "<hr><h3>Food Cost Totals (Multiple Invoices Combined)</h3>";
    while ($f = $f_res->fetch_assoc()) {
        $rid   = $f['RId'];
        $amt   = $f['total_amt'];
        $month = $f['r_month'];
        
        $ins_f = "INSERT INTO FoodCost (ReportingMonth, InvoiceDate, Amount, RId) 
                  VALUES ('$month', '$yesterday', '$amt', '$rid')";
        
        if($conn_dest->query($ins_f)) {
            echo "🍔 Store #$rid: Aggregated Cost \$$amt<br>";
        }
    }
} else {
    echo "<hr>⚠️ No food cost invoices found for $yesterday.";
}

// Close connections
$conn_src->close(); 
$conn_dest->close();

echo "<br><br><b>Sync Process Finished Successfully.</b>";
?>