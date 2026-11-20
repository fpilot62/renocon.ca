<?php
/** Path: /export_quickbooks_desktop.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';

$start = $_GET['start'] ?? date('Y-m-d', strtotime('-14 days'));
$end   = $_GET['end'] ?? date('Y-m-d');
$org   = (int)$_SESSION['org_id'];

// 1. Force download as .IIF file
header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="QB_Desktop_Export_'.date('Ymd').'.iif"');

// 2. QuickBooks Desktop Headers (Tab-Separated)
// !TIMERHDR is the file version
echo "!TIMERHDR\tVER\tREL\tCOMPANYNAME\tIMPORTEDBEFORE\tFROMTIMER\tUPDATETIMER\r\n";
echo "TIMERHDR\t8\t0\t\tN\tY\tY\r\n";

// !TIMEAMT defines the columns for the entries
echo "!TIMEAMT\tDATE\tJOB\tEMP\tITEM\tPITEM\tDURATION\tPROJ\tNOTE\tBILLINGSTATUS\r\n";

// 3. Fetch Finalized Data
$sql = "SELECT t.*, u.Name as staff_name, s.nick_name as site_name 
        FROM payroll_finalized t 
        JOIN users u ON t.user_id = u.id 
        JOIN sites s ON t.site_id = s.SId
        WHERE t.work_date BETWEEN ? AND ? AND t.org_id = ?";
        
$stmt = $pdo->prepare($sql);
$stmt->execute([$start, $end, $org]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $date = date('m/d/y', strtotime($row['work_date'])); // QBD prefers MM/DD/YY
    $duration = number_format($row['reg_hours'], 2);
    
    // Format: TIMEAMT [TAB] DATE [TAB] SITE [TAB] NAME [TAB] ...
    echo "TIMEAMT\t$date\t{$row['site_name']}\t{$row['staff_name']}\t\tRegular Pay\t$duration\t\tShift: {$row['attendance_id']}\t1\r\n";
}
exit;