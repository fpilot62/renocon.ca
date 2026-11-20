<?php
/** Path: /export_ledger.php */
require_once 'db.php';

$period = $_GET['period'] ?? date('Y-m');
$org_id = $_GET['org_id'] ?? 'all';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="RenoCon_Payroll_'.$period.'.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Employee', 'Hours', 'Vac Pay', 'Gross Pay', 'EE CPP', 'EE EI', 'Income Tax', 'ER CPP', 'ER EI', 'WCB', 'Net Pay', 'Total Remittance']);

$sql = "SELECT u.Name, p.* FROM payroll_records p 
        JOIN users u ON p.user_id = u.id 
        WHERE p.pay_period = :period";
if ($org_id !== 'all') { $sql .= " AND p.org_id = " . intval($org_id); }

$stmt = $pdo->prepare($sql);
$stmt->execute(['period' => $period]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $remit = $row['cpp_deduction'] + $row['employer_cpp'] + $row['ei_deduction'] + $row['employer_ei'] + $row['income_tax'];
    fputcsv($output, [
        $row['Name'], $row['total_hours'], $row['vac_pay'], $row['gross_pay'],
        $row['cpp_deduction'], $row['ei_deduction'], $row['income_tax'],
        $row['employer_cpp'], $row['employer_ei'], $row['wcb_payable'],
        $row['net_pay'], $remit
    ]);
}
fclose($output);