<?php
/** Path: /export_quickbooks.php */
require_once 'db.php';

$start = $_GET['start'] ?? date('Y-m-d', strtotime('-14 days'));
$end   = $_GET['end'] ?? date('Y-m-d');
$org   = (int)$_SESSION['org_id'];

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="QuickBooks_Export_'.date('Ymd').'.csv"');

$output = fopen('php://output', 'w');

// QuickBooks Header Row
fputcsv($output, ['Employee', 'Date', 'Duration', 'Payroll Item', 'Notes']);

$sql = "SELECT t.*, u.Name as staff_name FROM payroll_finalized t 
        JOIN users u ON t.user_id = u.id 
        WHERE t.work_date BETWEEN ? AND ? AND t.org_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$start, $end, $org]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [
        $row['staff_name'],
        $row['work_date'],
        number_format($row['reg_hours'], 2), // Duration as 8.50
        'Regular Pay', // You can map this from your roles table later
        'Shift ID: ' . $row['attendance_id']
    ]);
}
fclose($output);
exit;