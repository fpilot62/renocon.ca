<?php
/** Path: /payroll_statement.php */
require_once 'engine.php';

if (!function_exists('ps_topbar_org') && is_file(__DIR__ . '/payroll_scope.php')) { require_once __DIR__ . '/payroll_scope.php'; }
$org_filter = function_exists('ps_topbar_org') && ps_topbar_org() > 0 ? ps_topbar_org() : $_SESSION['org_id'];
$from_date  = $_GET['from'] ?? date('Y-m-01');
$to_date    = $_GET['to'] ?? date('Y-m-t');

// Fetch finalized payroll grouped by employee
$sql = "SELECT pf.*, u.Name as staff_name, s.nick_name as site_name 
        FROM payroll_finalized pf
        JOIN users u ON pf.user_id = u.id
        JOIN sites s ON pf.site_id = s.SId
        JOIN attendance a ON pf.attendance_id = a.id
        WHERE pf.org_id = ? AND DATE(a.clock_in) BETWEEN ? AND ?
        ORDER BY u.Name ASC, a.clock_in ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$org_filter, $from_date, $to_date]);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by staff for the "Cheque/Stub" view
$stubs = [];
foreach ($entries as $e) {
    $stubs[$e['user_id']]['info'] = ['name' => $e['staff_name']];
    $stubs[$e['user_id']]['lines'][] = $e;
}
?>

<div class="print-container">
    <?php foreach ($stubs as $uid => $data): ?>
        <div class="paystub" style="border: 2px solid #000; padding: 20px; margin-bottom: 40px; page-break-after: always;">
            <h2>PAY STATEMENT: <?= htmlspecialchars($data['info']['name']) ?></h2>
            <p>Period: <?= $from_date ?> to <?= $to_date ?></p>
            
            <table width="100%" border="1" cellpadding="5" style="border-collapse: collapse;">
                <thead>
                    <tr style="background: #eee;">
                        <th>Type</th>
                        <th>Hours</th>
                        <th>Rate</th>
                        <th>Total Gross</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $reg_total = 0; $bal_total = 0; $rate = 0;
                    foreach ($data['lines'] as $l) {
                        $reg_total += $l['reg_hours'];
                        $bal_total += $l['balance_hours'];
                        $rate = $l['hourly_rate']; // Simplified: assumes same rate for period
                    }
                    ?>
                    <tr>
                        <td><strong>REGULAR PAY</strong></td>
                        <td><?= number_format($reg_total, 2) ?></td>
                        <td>$<?= number_format($rate, 2) ?></td>
                        <td>$<?= number_format($reg_total * $rate, 2) ?></td>
                    </tr>
                    <tr>
                        <td><strong>BALANCE PAY</strong></td>
                        <td><?= number_format($bal_total, 2) ?></td>
                        <td>$<?= number_format($rate, 2) ?></td>
                        <td>$<?= number_format($bal_total * $rate, 2) ?></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr style="background: #f9f9f9; font-weight: bold;">
                        <td colspan="3">TOTAL GROSS PAY</td>
                        <td>$<?= number_format(($reg_total + $bal_total) * $rate, 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endforeach; ?>
</div>