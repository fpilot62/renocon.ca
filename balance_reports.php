<?php
/** Path: /balance_reports.php */
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'payroll_scope.php';
require_once 'engine/menu_generator.php';

ps_require_payroll_admin();
$scope_org_id = ps_scope_org_id();

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date   = $_GET['to_date'] ?? date('Y-m-t');
$site_id   = $_GET['site_id'] ?? '';

try {
    $sites = ps_list_sites($pdo, $scope_org_id);
    if ($site_id !== '' && $site_id !== 'all') {
        ps_assert_site($pdo, (int)$site_id, $scope_org_id);
    }

    $where  = ["b.work_date BETWEEN ? AND ?"];
    $params = [$from_date, $to_date];
    ps_apply_org_fence($where, $params, $scope_org_id);

    if ($site_id !== '' && $site_id !== 'all') {
        $where[]  = "b.site_id = ?";
        $params[] = (int)$site_id;
    }

    $sql = "SELECT
                b.*,
                u.Name as employee_name,
                s.nick_name as site_name
            FROM payroll_balance b
            INNER JOIN users u ON b.user_id = u.id
            LEFT JOIN sites s ON b.site_id = s.SId
            WHERE " . implode(" AND ", $where) . "
            ORDER BY u.Name ASC, b.work_date ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("<div style='padding:20px; background:#fee2e2; color:#b91c1c; border-radius:8px;'>
            <strong>Database Error:</strong> " . htmlspecialchars($e->getMessage()) . "
         </div>");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Balance Report | SIP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; padding-top: 110px; color: #1e293b; }
        .container { max-width: 1400px; margin: 0 auto; padding: 0 20px; }
        .filter-bar { background: white; padding: 20px; border-radius: 12px; display: flex; gap: 15px; align-items: flex-end; margin-bottom: 25px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .filter-group { display: flex; flex-direction: column; gap: 5px; }
        .filter-group label { font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; }
        .input-field { padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; min-width: 150px; }
        .report-table { width: 100%; background: white; border-radius: 12px; border-collapse: collapse; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .report-table th { background: #f1f5f9; padding: 15px; text-align: left; font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; }
        .report-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; }
        .btn-blue { background: #3b82f6; color: white; padding: 10px 20px; border-radius: 6px; border: none; font-weight: 700; cursor: pointer; text-decoration: none; }
        .report-note { margin: 8px 0 18px; max-width: 860px; color: #64748b; font-size: 13px; font-weight: 500; line-height: 1.55; }
    </style>
</head>
<body>

<?= generateMenu($pdo); ?>

<div class="container">
    <h2 style="margin:0; font-weight:800; color:#0f172a;"><i class="fa fa-scale-balanced" style="color:#3b82f6;"></i> Balance Report</h2>
    <p class="report-note">Hours × rate = <strong>PAY AMOUNT (gross)</strong>. Status is PENDING or PAID. Use Print Cheques for cheque runs. This is not tax or net pay, and not the QB export page (Daily Payroll Summary).</p>

    <form method="GET" class="filter-bar">
        <div class="filter-group">
            <label>From</label>
            <input type="date" name="from_date" class="input-field" value="<?= htmlspecialchars($from_date) ?>">
        </div>
        <div class="filter-group">
            <label>To</label>
            <input type="date" name="to_date" class="input-field" value="<?= htmlspecialchars($to_date) ?>">
        </div>
        <div class="filter-group">
            <label>Site</label>
            <select name="site_id" class="input-field">
                <option value="">All Sites</option>
                <?php foreach($sites as $s): ?>
                    <option value="<?= (int)$s['SId'] ?>" <?= (string)$site_id === (string)$s['SId'] ? 'selected' : '' ?>><?= htmlspecialchars($s['nick_name'] ?? '') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-blue">Update Report</button>
        <a href="print_payrun.php?from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>" target="_blank" class="btn-blue" style="background:#0f172a;">Print Cheques</a>
    </form>

    <table class="report-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Employee</th>
                <th>Site</th>
                <th>Hours</th>
                <th>Rate</th>
                <th>Pay Amount</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($rows)): ?>
                <tr><td colspan="7" style="text-align:center; padding:50px; color:#94a3b8;">No balance records found for this period.</td></tr>
            <?php else: ?>
                <?php foreach($rows as $r): ?>
               <tr>
                    <td><?= date('M d, Y', strtotime($r['work_date'])) ?></td>
                    <td><strong><?= htmlspecialchars($r['employee_name'] ?? 'Unknown') ?></strong></td>
                    <td><?= htmlspecialchars($r['site_name'] ?? 'N/A') ?></td>

                    <td><?= number_format((float)($r['balance_hours'] ?? 0), 2) ?></td>

                    <td>$<?= number_format((float)($r['hourly_rate'] ?? 0), 2) ?></td>

                    <td style="font-weight: 800; color:#10b981;">
                        $<?php
                            $pay = ($r['balance_hours'] ?? 0) * ($r['hourly_rate'] ?? 0);
                            echo number_format((float)$pay, 2);
                        ?>
                    </td>
                    <td><span class="badge"><?= strtoupper((string)($r['status'] ?? '')) ?></span></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>
