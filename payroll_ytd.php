<?php
/**
 * Path: /payroll_ytd.php
 * Official Year-To-Date from imported paystubs (AA Group / Bramalea first).
 * Calendar YTD in payroll_finalized only covers imported periods; this page
 * shows the stub snapshot (source of truth) plus later finalized days.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'payroll_scope.php';

if (file_exists('engine/menu_generator.php')) {
    require_once 'engine/menu_generator.php';
}

$user_lvl = (int)($_SESSION['user_level'] ?? 99);
if (!isset($_SESSION['user_id']) || $user_lvl >= 30) {
    header('Location: login_kiosk.php?err=access_denied_report');
    exit;
}

$scope_org_id = function_exists('ps_scope_org_id') ? (int)ps_scope_org_id() : (int)($_SESSION['org_id'] ?? 0);
$year = (int)($_GET['year'] ?? 2026);
if ($year < 2000 || $year > 2100) {
    $year = 2026;
}
$site_id = trim((string)($_GET['site_id'] ?? '121'));
$want_csv = isset($_GET['csv']);

function ytd_money(float $n): string
{
    return number_format($n, 2);
}

$sites = [];
try {
    if (function_exists('ps_list_sites') && $scope_org_id > 0) {
        $sites = ps_list_sites($pdo, $scope_org_id);
    } else {
        $sites = $pdo->query('SELECT SId, nick_name FROM sites WHERE org_id=4 ORDER BY nick_name')->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $sites = [];
}

$where = ['o.year = ?'];
$params = [$year];
if ($scope_org_id > 0) {
    $where[] = 'o.org_id = ?';
    $params[] = $scope_org_id;
}
if ($site_id !== '' && $site_id !== 'all') {
    $where[] = 'o.site_id = ?';
    $params[] = (int)$site_id;
    if (function_exists('ps_assert_site') && $scope_org_id > 0) {
        try { ps_assert_site($pdo, (int)$site_id, $scope_org_id); } catch (Throwable $e) { /* keep filter */ }
    }
}

$rows = [];
$has_table = false;
try {
    $chk = $pdo->query("SHOW TABLES LIKE 'payroll_ytd_opening'");
    $has_table = (bool)$chk->fetchColumn();
} catch (Throwable $e) {
    $has_table = false;
}

if ($has_table) {
    $sql = 'SELECT o.*, u.Name AS db_name, s.nick_name AS site_name
              FROM payroll_ytd_opening o
              LEFT JOIN users u ON u.id = o.user_id
              LEFT JOIN sites s ON s.SId = o.site_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY o.employee_name, o.as_of_date';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}

$tot = [
    'ytd_gross' => 0.0, 'ytd_net' => 0.0, 'ytd_ded' => 0.0,
    'ytd_reg' => 0.0, 'ytd_vac' => 0.0, 'ytd_ph' => 0.0, 'ytd_stat' => 0.0, 'ytd_bonus' => 0.0,
    'ytd_cpp' => 0.0, 'ytd_ei' => 0.0, 'ytd_tax' => 0.0,
    'period_gross' => 0.0, 'period_net' => 0.0,
];
foreach ($rows as $r) {
    foreach ($tot as $k => $_) {
        $tot[$k] += (float)($r[$k] ?? 0);
    }
}

$asof = $rows[0]['as_of_date'] ?? '';
$pstart = $rows[0]['period_start'] ?? '2026-07-13';
$pend = $rows[0]['period_end'] ?? '2026-07-26';
$cheque = $rows[0]['cheque_date'] ?? '2026-07-31';
$org_name = 'AA Group';
$site_label = 'Bramalea';
if ($rows) {
    $org_name = 'AA Group';
    $site_label = (string)($rows[0]['site_name'] ?? 'Bramalea');
}

if ($want_csv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="aa-group-bramalea-ytd-' . $year . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Employee', 'User ID', 'Site', 'As of', 'Period start', 'Period end', 'Cheque date',
        'YTD Gross', 'YTD Net', 'YTD Deductions', 'YTD Regular', 'YTD Vacation',
        'YTD Public Holiday', 'YTD Stat Worked', 'YTD Bonus', 'YTD CPP', 'YTD EI', 'YTD Tax',
        'Period Gross', 'Period Net', 'Source',
    ]);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['employee_name'], $r['user_id'], $r['site_name'] ?? $r['site_id'], $r['as_of_date'],
            $r['period_start'], $r['period_end'], $r['cheque_date'],
            $r['ytd_gross'], $r['ytd_net'], $r['ytd_ded'], $r['ytd_reg'], $r['ytd_vac'],
            $r['ytd_ph'], $r['ytd_stat'], $r['ytd_bonus'], $r['ytd_cpp'], $r['ytd_ei'], $r['ytd_tax'],
            $r['period_gross'], $r['period_net'], $r['source'],
        ]);
    }
    fputcsv($out, [
        'TOTAL', '', '', '', '', '', '',
        $tot['ytd_gross'], $tot['ytd_net'], $tot['ytd_ded'], $tot['ytd_reg'], $tot['ytd_vac'],
        $tot['ytd_ph'], $tot['ytd_stat'], $tot['ytd_bonus'], $tot['ytd_cpp'], $tot['ytd_ei'], $tot['ytd_tax'],
        $tot['period_gross'], $tot['period_net'], '',
    ]);
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AA Group YTD — Bramalea paystubs</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: Inter, sans-serif; background: #f8fafc; padding-top: 120px; color: #1e293b; font-size: 12px; }
        .container { max-width: 1700px; margin: 0 auto; padding: 0 20px 40px; }
        .filter-panel { background: #fff; padding: 22px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 20px; border: 1px solid #e2e8f0; }
        .note { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; color: #475569; line-height: 1.55; }
        .report-table { width: 100%; border-collapse: collapse; background: #fff; }
        .report-table th { background: #334155; padding: 10px 8px; text-align: right; font-size: 10px; font-weight: 800; text-transform: uppercase; color: #fff; }
        .report-table th.name { text-align: left; }
        .report-table td { padding: 9px 8px; border-bottom: 1px solid #f1f5f9; text-align: right; font-weight: 700; white-space: nowrap; }
        .report-table td.name { text-align: left; color: #0f172a; }
        .report-table tr.tot td { background: #0f172a; color: #fff; }
        .input-field { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; font-weight: 600; color: #334155; }
        .btn-blue { background: #0097a7; color: #fff; padding: 9px 18px; border-radius: 6px; border: none; cursor: pointer; font-weight: 700; font-size: 12px; text-decoration: none; display: inline-block; }
        .btn-ghost { background: #fff; border: 1px solid #cbd5e1; color: #334155; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-weight: 700; font-size: 12px; text-decoration: none; display: inline-block; }
        @media print { .no-print { display: none; } body { padding-top: 12px; } }
    </style>
</head>
<body>
<?php if (function_exists('generateMenu')) echo generateMenu($pdo); ?>
<div class="container">
    <div class="no-print" style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px;">
        <div>
            <h2 style="margin:0;color:#0f172a;font-weight:800;"><i class="fa fa-calendar-check" style="color:#0097a7;"></i> Year-to-date from paystubs</h2>
            <p style="margin:8px 0 0;color:#64748b;font-size:13px;font-weight:500;">
                <?= htmlspecialchars($org_name) ?> · <?= htmlspecialchars($site_label) ?> ·
                official stub YTD through <?= htmlspecialchars($asof ?: ($year . '-07-26')) ?>
            </p>
        </div>
        <div style="display:flex;gap:10px;">
            <a class="btn-ghost" href="payroll_reports.php">Period report</a>
            <a class="btn-ghost" href="generate_pay_documents.php">Pay documents</a>
            <a class="btn-blue" href="?year=<?= (int)$year ?>&amp;site_id=<?= htmlspecialchars($site_id) ?>&amp;csv=1">Download CSV</a>
        </div>
    </div>

    <form method="GET" class="filter-panel no-print" style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;">
        <div>
            <label style="display:block;font-size:9px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Year</label>
            <input class="input-field" type="number" name="year" value="<?= (int)$year ?>" min="2020" max="2100">
        </div>
        <div>
            <label style="display:block;font-size:9px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Site</label>
            <select class="input-field" name="site_id" style="min-width:200px;">
                <option value="all" <?= $site_id === 'all' ? 'selected' : '' ?>>All sites with stub YTD</option>
                <?php foreach ($sites as $s): ?>
                    <option value="<?= (int)$s['SId'] ?>" <?= (string)$s['SId'] === (string)$site_id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['nick_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn-blue" type="submit">Show YTD</button>
    </form>

    <div class="note">
        <strong>Source:</strong> Bramalea Paystubs.pdf (16 of 16) ·
        pay period <?= htmlspecialchars($pstart) ?> to <?= htmlspecialchars($pend) ?> ·
        cheque <?= htmlspecialchars($cheque) ?> · employer 2428957 Ontario Inc.- Bramalea.<br>
        Latest stub YTD is the source of truth. Live ledger only has this imported period so far;
        generated Bramalea stubs now add these opening YTD figures so later periods stay correct.<br>
        All 16 stub names are AA Group / Bramalea. No Bramalea person is missing from the PDF for this period.
        Other AA Group sites are not in this PDF.
        Sandeep Kaur also has pay at Square One this period ($338.63) that is not on the Bramalea stub.
    </div>

    <?php if (!$rows): ?>
        <div class="note">No official stub YTD loaded for this year/site yet.</div>
    <?php else: ?>
        <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:12px;">
        <table class="report-table">
            <thead>
                <tr>
                    <th class="name">Employee</th>
                    <th>YTD Gross</th>
                    <th>YTD Net</th>
                    <th>YTD Tax</th>
                    <th>YTD CPP</th>
                    <th>YTD EI</th>
                    <th>YTD Vac</th>
                    <th>YTD Regular</th>
                    <th>YTD PH</th>
                    <th>YTD Stat</th>
                    <th>YTD Bonus</th>
                    <th>Period Gross</th>
                    <th>Period Net</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="name"><?= htmlspecialchars((string)$r['employee_name']) ?></td>
                    <td><?= ytd_money((float)$r['ytd_gross']) ?></td>
                    <td><?= ytd_money((float)$r['ytd_net']) ?></td>
                    <td><?= ytd_money((float)$r['ytd_tax']) ?></td>
                    <td><?= ytd_money((float)$r['ytd_cpp']) ?></td>
                    <td><?= ytd_money((float)$r['ytd_ei']) ?></td>
                    <td><?= ytd_money((float)$r['ytd_vac']) ?></td>
                    <td><?= ytd_money((float)$r['ytd_reg']) ?></td>
                    <td><?= ytd_money((float)$r['ytd_ph']) ?></td>
                    <td><?= ytd_money((float)$r['ytd_stat']) ?></td>
                    <td><?= ytd_money((float)$r['ytd_bonus']) ?></td>
                    <td><?= ytd_money((float)$r['period_gross']) ?></td>
                    <td><?= ytd_money((float)$r['period_net']) ?></td>
                </tr>
            <?php endforeach; ?>
                <tr class="tot">
                    <td class="name">AA Group Bramalea total (<?= count($rows) ?>)</td>
                    <td><?= ytd_money($tot['ytd_gross']) ?></td>
                    <td><?= ytd_money($tot['ytd_net']) ?></td>
                    <td><?= ytd_money($tot['ytd_tax']) ?></td>
                    <td><?= ytd_money($tot['ytd_cpp']) ?></td>
                    <td><?= ytd_money($tot['ytd_ei']) ?></td>
                    <td><?= ytd_money($tot['ytd_vac']) ?></td>
                    <td><?= ytd_money($tot['ytd_reg']) ?></td>
                    <td><?= ytd_money($tot['ytd_ph']) ?></td>
                    <td><?= ytd_money($tot['ytd_stat']) ?></td>
                    <td><?= ytd_money($tot['ytd_bonus']) ?></td>
                    <td><?= ytd_money($tot['period_gross']) ?></td>
                    <td><?= ytd_money($tot['period_net']) ?></td>
                </tr>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
