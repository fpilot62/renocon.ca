<?php
ob_start();
/**
 * Path: /payroll_report.php
 * PURPOSE: Consolidated Payroll Hours Split — Regular / OT / Travel + estimated gross
 *          from payroll_finalized (same source as the ledger).
 */
require_once 'db.php';
require_once 'payroll_scope.php';
require_once 'engine/menu_generator.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

ps_require_payroll_admin();

$user_lvl     = (int)($_SESSION['user_level'] ?? 99);
$scope_org_id = ps_scope_org_id();
$is_super     = ps_is_superadmin();

$organizations_list = [];
if ($is_super) {
    try {
        $organizations_list = $pdo->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $organizations_list = [];
    }
}

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-d');
$sel_site   = $_GET['site_id'] ?? 'all';
$results    = [];

$sites_list = ps_list_sites($pdo, $scope_org_id);
if ($sel_site !== 'all' && (int)$sel_site > 0) {
    ps_assert_site($pdo, (int)$sel_site, $scope_org_id);
}

if (isset($_GET['generate'])) {
    $where  = ["f.work_date BETWEEN ? AND ?"];
    $params = [$start_date, $end_date];
    ps_apply_org_fence($where, $params, $scope_org_id);
    if ($sel_site !== 'all' && (int)$sel_site > 0) {
        $where[]  = "f.site_id = ?";
        $params[] = (int)$sel_site;
    }

    $sql = "SELECT
                u.Name AS employee_name,
                COALESCE(NULLIF(f.hourly_rate,0), u.hourly_rate, 0) AS rate,
                COALESCE(f.reg_hours, 0) AS reg,
                COALESCE(f.overtime_hours, 0) AS ot,
                COALESCE(f.travel_hours, 0) AS travel,
                COALESCE(f.total_gross, 0) AS gross_cost,
                f.work_date,
                COALESCE(s.nick_name, 'Unassigned') AS site_name,
                a.clock_in,
                a.clock_out
            FROM payroll_finalized f
            INNER JOIN users u ON f.user_id = u.id
            LEFT JOIN sites s ON f.site_id = s.SId
            LEFT JOIN attendance a ON a.id = f.attendance_id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY u.Name ASC, f.work_date DESC, f.id DESC";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $reg    = (float)$r['reg'];
            $ot     = (float)$r['ot'];
            $travel = (float)$r['travel'];
            $rate   = (float)$r['rate'];
            $cost   = (float)$r['gross_cost'];
            if ($cost <= 0) {
                $cost = ($reg * $rate) + ($ot * ($rate * 1.5)) + ($travel * $rate);
            }
            $results[] = [
                'employee_name' => $r['employee_name'],
                'site_name'     => $r['site_name'],
                'clock_in'      => $r['clock_in'],
                'clock_out'     => $r['clock_out'],
                'work_date'     => $r['work_date'],
                'rate'          => $rate,
                'reg'           => $reg,
                'ot'            => $ot,
                'travel'        => $travel,
                'total_hrs'     => $reg + $ot + $travel,
                'gross_cost'    => $cost,
            ];
        }
    } catch (PDOException $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll Report | crewPulse</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; color: #1e293b; padding-top: 130px; font-size: 11px; }
        .wrapper { padding: 40px; max-width: 1400px; margin: auto; }
        .filter-card { background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .report-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; margin-top: 30px; border: 1px solid #e2e8f0; }
        .report-table th { background: #1e293b; padding: 15px; text-align: left; font-size: 11px; color: #fff; text-transform: uppercase; }
        .report-table td { padding: 14px; border-bottom: 1px solid #f1f5f9; font-weight: bold; }
        .stat-badge { background: #fff; padding: 15px 25px; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .btn-generate { background: #0097a7; color: white; border: none; padding: 12px 30px; border-radius: 6px; font-weight: bold; cursor: pointer; transition: 0.2s; height: 43px; }
        input, select { padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; font-weight: 600; color: #334155; }
        .admin-tag { background: #fee2e2; color: #991b1b; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 800; float: right; }
        .report-note { margin: 8px 0 18px; max-width: 920px; color: #64748b; font-size: 13px; font-weight: 500; line-height: 1.55; }
    </style>
</head>
<body>

<?= generateMenu($pdo); ?>

<div class="wrapper">
    <div class="filter-card">
        <span class="admin-tag"><i class="fa fa-shield-halved"></i> LEVEL <?= $user_lvl ?> ACCESS</span>
        <h2 style="margin-top:0; color: #1e293b;"><i class="fa fa-file-invoice-dollar" style="color:#0097a7;"></i> Consolidated Payroll Hours Split Report</h2>
        <p class="report-note">Splits hours into <strong>Regular / OT / Travel</strong> and <strong>estimated GROSS $</strong> (hours × rate) by employee/site — not net pay and not CRA deductions. Not the QB export page (Daily Payroll Summary). Click <strong>Generate Report</strong> after setting dates; franchise users are bound to the active organization.</p>

        <form method="GET" style="display: flex; gap: 15px; align-items: flex-end;">
            <div>
                <label style="display:block; font-size:10px; font-weight:800; color:#94a3b8; text-transform:uppercase; margin-bottom:5px;">From</label>
                <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
            </div>
            <div>
                <label style="display:block; font-size:10px; font-weight:800; color:#94a3b8; text-transform:uppercase; margin-bottom:5px;">To</label>
                <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
            </div>

            <div style="flex-grow:1;">
                <label style="display:block; font-size:10px; font-weight:800; color:#3b82f6; text-transform:uppercase; margin-bottom:5px;"><i class="fa fa-building"></i> Company Organization</label>
                <?php if ($is_super): ?>
                    <select name="org_id" style="width:100%; border:1px solid #3b82f6; background:#f0f9ff;">
                        <option value="all">All Organizations Combined</option>
                        <?php foreach ($organizations_list as $org): ?>
                            <option value="<?= (int)$org['id'] ?>" <?= (string)$scope_org_id === (string)$org['id'] ? 'selected' : '' ?>>
                                <?= strtoupper(htmlspecialchars($org['org_name'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" style="width:100%; background:#f8fafc; color:#94a3b8;" value="Bound to Active Franchise" readonly>
                <?php endif; ?>
            </div>

            <div style="flex-grow:1;">
                <label style="display:block; font-size:10px; font-weight:800; color:#94a3b8; text-transform:uppercase; margin-bottom:5px;">Location</label>
                <select name="site_id" style="width:100%;">
                    <option value="all">-- All Available Locations --</option>
                    <?php foreach ($sites_list as $s): ?>
                        <option value="<?= (int)$s['SId'] ?>" <?= (string)$sel_site === (string)$s['SId'] ? 'selected' : '' ?>>
                            <?= strtoupper(htmlspecialchars($s['nick_name'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" name="generate" value="1" class="btn-generate">Generate Report</button>
        </form>
    </div>

    <?php if (isset($_GET['generate']) && !isset($error)): ?>
        <?php
            $t_reg = 0; $t_ot = 0; $t_tvl = 0; $t_total = 0; $t_cost = 0;
            foreach ($results as $row) {
                $t_reg += $row['reg']; $t_ot += $row['ot']; $t_tvl += $row['travel'];
                $t_total += $row['total_hrs']; $t_cost += $row['gross_cost'];
            }
        ?>

        <div style="display:flex; gap:20px; margin-top:30px; text-align:center;">
            <div class="stat-badge"><small style="color:#64748b; font-weight:bold; text-transform:uppercase; font-size:9px;">Regular Hours</small><div style="font-size:20px; font-weight:900; color: #1e293b;"><?= number_format($t_reg, 2) ?></div></div>
            <div class="stat-badge"><small style="color:#ef4444; font-weight:bold; text-transform:uppercase; font-size:9px;">Overtime Hours</small><div style="font-size:20px; font-weight:900; color:#ef4444;"><?= number_format($t_ot, 2) ?></div></div>
            <div class="stat-badge"><small style="color:#eab308; font-weight:bold; text-transform:uppercase; font-size:9px;">Travel Hours</small><div style="font-size:20px; font-weight:900; color:#eab308;"><?= number_format($t_tvl, 2) ?></div></div>
            <div class="stat-badge"><small style="color:#475569; font-weight:bold; text-transform:uppercase; font-size:9px;">Total Gross Hours</small><div style="font-size:20px; font-weight:900; color:#475569;"><?= number_format($t_total, 2) ?></div></div>
            <div class="stat-badge"><small style="color:#0097a7; font-weight:bold; text-transform:uppercase; font-size:9px;">Est. Combined Gross Cost</small><div style="font-size:20px; font-weight:900; color:#0097a7;">$<?= number_format($t_cost, 2) ?></div></div>
        </div>

        <table class="report-table text-center">
            <thead>
                <tr>
                    <th class="text-left">Employee Context</th>
                    <th>Clock In / Out Timeline</th>
                    <th>Regular</th>
                    <th>Overtime</th>
                    <th>Travel Time</th>
                    <th>Total Window</th>
                    <th class="text-right">Est. Allocation Gross</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($results)): ?>
                    <tr><td colspan="7" style="padding: 50px; text-align: center; color: #94a3b8;">No records found matching parameters.</td></tr>
                <?php else: ?>
                    <?php foreach ($results as $r): ?>
                    <tr>
                        <td class="text-left">
                            <span style="font-weight:bold; color: #1e293b; font-size:12px;"><?= htmlspecialchars($r['employee_name']) ?></span>
                            <div style="font-size: 10px; color: #94a3b8;"><?= htmlspecialchars($r['site_name']) ?> · Base Rate: $<?= number_format($r['rate'], 2) ?>/hr</div>
                        </td>
                        <td style="font-size: 11px; color: #475569;">
                            <?php if (!empty($r['clock_in']) && $r['clock_in'] !== '0000-00-00 00:00:00'): ?>
                                <span class="text-success">IN:</span> <?= date('M j, g:i A', strtotime($r['clock_in'])) ?><br>
                                <span class="text-danger">OUT:</span> <?= ($r['clock_out'] && $r['clock_out'] != '0000-00-00 00:00:00') ? date('M j, g:i A', strtotime($r['clock_out'])) : '<em>Active</em>' ?>
                            <?php else: ?>
                                <?= date('M j, Y', strtotime($r['work_date'])) ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-secondary"><?= number_format($r['reg'], 2) ?> hrs</td>
                        <td class="text-danger"><?= number_format($r['ot'], 2) ?> hrs</td>
                        <td class="text-warning"><?= number_format($r['travel'], 2) ?> hrs</td>
                        <td class="text-dark" style="font-size:12px;"><?= number_format($r['total_hrs'], 2) ?> hrs</td>
                        <td class="text-right text-info font-weight-bold" style="font-size:12px;">$<?= number_format($r['gross_cost'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

</body>
</html>
<?php ob_end_flush(); ?>
