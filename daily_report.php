<?php
/** Path: /daily_report.php */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';

// 1. SECURITY (Verified Level 10 for Admin Access)
$my_rank = $_SESSION['user_level'] ?? 99;
if (intval($my_rank) > 15) { 
    header("Location: login_kiosk.php?err=access_denied"); 
    exit; 
}

// Deductions on each row come from pe_line.

// 3. CAPTURE FILTERS
// Opening the page with no date used to load every punch, including selfie
// photos stored on attendance. That query exhausts the 512MB PHP limit.
$today = (new DateTime('now', new DateTimeZone('America/Edmonton')))->format('Y-m-d');
$f_date = trim((string)($_GET['f_date'] ?? $today));
if ($f_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) {
    $f_date = $today;
}
$f_user = $_GET['f_user'] ?? '';
$f_site = $_GET['f_site'] ?? '';

$where = ["a.clock_in >= ?", "a.clock_in < ?"];
$params = [$f_date . ' 00:00:00', date('Y-m-d', strtotime($f_date . ' +1 day')) . ' 00:00:00'];
if($f_user) { $where[] = "a.user_id = ?"; $params[] = $f_user; }
if($f_site) { $where[] = "a.site_id = ?"; $params[] = $f_site; }

$where_clause = "WHERE " . implode(" AND ", $where);

// 4. MAIN QUERY — clock times and rates only. Do not select selfie columns.
$sql = "SELECT a.user_id, a.site_id, a.org_id, a.clock_in,
               a.clock_out,
               u.Name as emp_name, 
               u.official_rate, 
               u.vac_pay_percent,
               s.nick_name as site_name
        FROM attendance a 
        LEFT JOIN users u ON a.user_id = u.id 
        LEFT JOIN sites s ON a.site_id = s.SId
        $where_clause 
        ORDER BY a.clock_in DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$report_data = $stmt->fetchAll();

$users_list = $pdo->query("SELECT id, Name FROM users ORDER BY Name")->fetchAll();
if (!function_exists('sip_org_site_fence')) { require_once __DIR__ . '/engine/site_status_lib.php'; }
$__fence = sip_org_site_fence($pdo, 'sip_org_id');
$is_super = $__fence['is_super']; $selected_org = $__fence['selected_org']; $orgs = $__fence['orgs']; $sites = $__fence['sites']; $sites_list = $sites;


// Include the menu generator
require_once 'engine/menu_generator.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Payroll Master</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f7f6; margin: 0; padding-top: 70px; }
        .filter-panel { background: #2c3e50; color: white; padding: 20px; display: flex; gap: 20px; align-items: flex-end; margin-top: -10px; }
        .card { background: white; margin: 20px; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 1100px; }
        th { background: #f8f9fa; padding: 12px; text-align: left; font-size: 11px; text-transform: uppercase; color: #666; border-bottom: 2px solid #dee2e6; }
        td { padding: 12px; border-bottom: 1px solid #eee; font-size: 13px; }
        .net-pay { background: #e8f5e9; font-weight: bold; }
        .neg { color: #d32f2f; }
        .pos { color: #388e3c; }
        select, input { padding: 8px; border-radius: 4px; border: 1px solid #ccc; font-size: 13px; }
        .btn-update { background: #0097a7; color: white; border: none; padding: 10px 25px; border-radius: 4px; cursor: pointer; font-weight: bold; }
    </style>
</head>
<body>

<?php 
// Render the clean navigation menu
if (function_exists('generateMenu')) {
    echo generateMenu($pdo); 
}
?>

<form class="filter-panel" method="GET">
    <div><label class="small font-weight-bold">Date</label><br><input type="date" name="f_date" value="<?= htmlspecialchars($f_date) ?>"></div>
    <div><label class="small font-weight-bold">Employee</label><br>
        <select name="f_user">
            <option value="">All Staff</option>
            <?php foreach($users_list as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $f_user == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['Name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div><label class="small font-weight-bold">Site</label><br>
        <select name="f_site">
            <option value="">All Sites</option>
            <?php foreach($sites_list as $s): ?>
                <option value="<?= $s['SId'] ?>" <?= $f_site == $s['SId'] ? 'selected' : '' ?>><?= htmlspecialchars($s['nick_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn-update shadow-sm">RUN REPORT</button>
    <a href="daily_report.php" style="color: #bdc3c7; text-decoration: none; font-size: 12px; margin-left: 10px;">Reset View</a>
</form>

<div class="card border-0">
    <h5 class="font-weight-bold mb-4">Payroll Analysis & CRA Contributions</h5>
    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Site</th>
                <th>Hours</th>
                <th>Gross Pay</th>
                <th>Deductions (EE)</th>
                <th>Net Pay</th>
                <th>Vacation</th>
                <th>ER Costs</th>
                <th>Total Co. Cost</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $grand_cost = 0;
            foreach($report_data as $row):
                $hours = 0; $gross = 0;
                if($row['clock_out'] && $row['clock_out'] != '0000-00-00 00:00:00') {
                    $hours = (strtotime($row['clock_out']) - strtotime($row['clock_in'])) / 3600;
                    $gross = $hours * ($row['official_rate'] ?? 0); 
                }
                
                $day = substr((string)$row['clock_in'], 0, 10);
                $uid = (int)($row['user_id'] ?? 0);
                $sid = (int)($row['site_id'] ?? 0);
                $rep = pe_line($gross, pe_vacation_rate($pdo, $uid, $sid, $day), pe_rates($pdo), [
                    'pdo' => $pdo, 'user_id' => $uid, 'site_id' => $sid, 'org_id' => (int)($row['org_id'] ?? 0), 'work_date' => $day,
                ]);
                $cpp_ee = (float)$rep['cpp'] + (float)($rep['cpp2'] ?? 0);
                $ei_ee = (float)$rep['ei'];
                $tax_est = (float)$rep['income_tax'];
                $net_pay = (float)$rep['net'];
                $vac_val = (float)$rep['vac'];
                $er_extras = (float)$rep['cpp_er'] + (float)($rep['cpp2_er'] ?? 0) + (float)$rep['ei_er'] + (float)$rep['wcb'];
                
                $total_row_cost = $gross + $vac_val + $er_extras;
                $grand_cost += $total_row_cost;
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($row['emp_name'] ?? 'N/A') ?></strong></td>
                <td class="text-muted small"><?= htmlspecialchars($row['site_name'] ?? 'General') ?></td>
                <td><?= number_format($hours, 2) ?></td>
                <td>$<?= number_format($gross, 2) ?></td>
                <td class="neg">-$<?= number_format($cpp_ee + $ei_ee + $tax_est, 2) ?></td>
                <td class="net-pay">$<?= number_format($net_pay, 2) ?></td>
                <td class="pos">+$<?= number_format($vac_val, 2) ?></td>
                <td class="pos">+$<?= number_format($er_extras, 2) ?></td>
                <td><strong>$<?= number_format($total_row_cost, 2) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background: #2c3e50; color: white; font-weight: bold;">
                <td colspan="8" style="text-align: right; padding: 15px;">TOTAL COMPANY LIABILITY:</td>
                <td style="font-size: 16px;">$<?= number_format($grand_cost, 2) ?></td>
            </tr>
        </tfoot>
    </table>
</div>

</body>
</html>