<?php
/**
 * Path: /payroll_reports.php
 * PURPOSE: Consolidated Master Payroll Report Suite
 * VIEW: Nested drill-down — Period > Site > Employee > Shift (collapsible)
 *
 * Toggle behaviour:
 *   - Each Site row is always visible and shows its rolled-up totals.
 *   - Clicking a Site reveals its Employees (each also shows rolled-up totals).
 *   - Clicking an Employee reveals that employee's individual shift lines.
 *   - Collapsing a parent hides everything beneath it but keeps the parent's
 *     summary row (and its totals) on screen.
 *
 * The "View Grouping Mode" filter swaps the top two levels:
 *   site_wise     => Site  > Employee > Shift   (default, what you asked for)
 *   employee_wise => Employee > Site > Shift
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
require_once 'payroll_scope.php';

if (file_exists('engine/menu_generator.php')) {
    require_once 'engine/menu_generator.php';
}

// 1. GATE RESTRAINTS
$user_lvl = (int)($_SESSION['user_level'] ?? 99);
if (!isset($_SESSION['user_id']) || $user_lvl >= 30) {
    header("Location: login_kiosk.php?err=access_denied_report");
    exit;
}
$scope_org_id = ps_scope_org_id();

// 2. CAPTURE REPORT PARAMETERS & FILTER CONFIGURATIONS
$from_date      = $_GET['from_date']   ?? date('Y-m-01');
$to_date        = $_GET['to_date']     ?? date('Y-m-t');
$site_id        = $_GET['site_id']     ?? '';
$report_type    = $_GET['report_type'] ?? 'site_wise';      // 'site_wise' or 'employee_wise'
$period_mode    = 'semi_monthly';
/* ORG_PAY_PERIOD_ALWAYS — selected site's company profile. */
if (!function_exists('sip_org_pay_period_mode')) {
    require_once __DIR__ . '/engine/org_modules_lib.php';
}
$__org_for_pm = (int)$scope_org_id;
if ($site_id !== '' && strtolower((string)$site_id) !== 'all' && function_exists('sip_org_id_from_site_ids')) {
    $__from_sites = sip_org_id_from_site_ids($pdo, [(int)$site_id], 0);
    if ($__from_sites > 0) {
        $__org_for_pm = $__from_sites;
    }
}
if ($__org_for_pm > 0 && function_exists('sip_org_pay_period_mode')) {
    $period_mode = sip_org_pay_period_mode($pdo, $__org_for_pm);
} elseif (!empty($_GET['period_mode']) && in_array($_GET['period_mode'], ['semi_monthly', 'bi_weekly', 'monthly'], true)) {
    $period_mode = $_GET['period_mode'];
}
$period_anchor = function_exists('sip_org_pay_period_anchor') ? sip_org_pay_period_anchor($pdo, $__org_for_pm) : '2024-01-01';
$inc_deductions = isset($_GET['inc_deductions']);
$inc_employer   = isset($_GET['inc_employer']);

try {
    $sites = ps_list_sites($pdo, $scope_org_id);
    if ($site_id !== '') { ps_assert_site($pdo, (int)$site_id, $scope_org_id); }

    $where  = ["p.work_date BETWEEN ? AND ?"];
    $params = [$from_date, $to_date];
    ps_apply_org_fence($where, $params, $scope_org_id);

    if ($site_id !== '') {
        $where[]  = "p.site_id = ?";
        $params[] = (int)$site_id;
    }

    // Pull every finalized line. Each row in payroll_finalized is one shift line
    // (linked to attendance via attendance_id), so this is already shift-level data.
    $sql = "SELECT
                p.id                                      AS line_id,
                p.attendance_id,
                u.id                                      AS user_id,
                u.Name                                    AS employee_name,
                u.hourly_rate                             AS user_profile_rate,
                COALESCE(s.nick_name, 'Unassigned/Imported') AS site_name,
                COALESCE(p.display_label, 'Regular Pay')  AS display_label,
                COALESCE(p.reg_hours, 0)                  AS reg_hours,
                COALESCE(p.overtime_hours, 0)             AS overtime_hours,
                COALESCE(p.travel_hours, 0)               AS travel_hours,
                COALESCE(p.hourly_rate, 0)                AS line_rate,
                COALESCE(p.reg_pay, 0)                    AS reg_pay,
                COALESCE(p.total_gross, 0)                AS total_gross,
                COALESCE(p.paid_amount, 0)                AS paid_amount,
                COALESCE(p.payment_status, 'Unpaid')      AS payment_status,
                COALESCE(p.cpp, 0)                        AS ee_cpp,
                COALESCE(p.ei, 0)                         AS ee_ei,
                COALESCE(p.income_tax, 0)                 AS ee_tax,
                COALESCE(p.cpp_er, 0)                     AS er_cpp,
                COALESCE(p.ei_er, 0)                      AS er_ei,
                COALESCE(p.wcb, 0)                        AS er_wcb,
                COALESCE(p.vac, 0)                        AS er_vac,
                p.work_date,
                p.site_id,
                p.org_id
            FROM payroll_finalized p
            JOIN users u  ON p.user_id = u.id
            LEFT JOIN sites s ON p.site_id = s.SId
            WHERE " . implode(" AND ", $where) . "
            ORDER BY p.work_date ASC, u.Name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // =========================================================================
    // NESTED PERIOD ENGINE  ->  $tree[period][L1][L2] = ['totals'=>..,'shifts'=>[]]
    // L1/L2 depend on report_type. Totals roll up at every level.
    // =========================================================================
    $tree = [];

    // blank totals bucket
    $blank = [
        'reg_hours' => 0, 'ot_hours' => 0, 'travel_hours' => 0, 'gross' => 0,
        'ee_cpp' => 0, 'ee_ei' => 0, 'ee_tax' => 0,
        'er_cpp' => 0, 'er_ei' => 0, 'er_wcb' => 0, 'er_vac' => 0,
        'paid' => 0, 'balance' => 0,
    ];

    foreach ($rows as $r) {
        $work_ts = strtotime($r['work_date']);
        $year    = date('Y', $work_ts);

        // Dynamic payroll-period bucket
        if ($period_mode === 'monthly') {
            $period_label = date('F Y', $work_ts);
        } elseif ($period_mode === 'semi_monthly') {
            $day          = (int)date('j', $work_ts);
            $period_label = ($day <= 15) ? date('M 01-15, Y', $work_ts) : date('M 16-t, Y', $work_ts);
        } else {
            $p_anchor = $period_anchor ?? '2024-01-01';
            if (function_exists('pe_period_window')) {
                [$pstart_d, $pend_d] = pe_period_window((string)$r['work_date'], 'bi_weekly', $p_anchor);
            } else {
                $d0 = new DateTimeImmutable($p_anchor);
                $d1 = new DateTimeImmutable(substr((string)$r['work_date'], 0, 10));
                $days = (int)$d0->diff($d1)->format('%r%a');
                $pstart_d = $d0->modify((((int)floor($days / 14)) * 14) . ' days')->format('Y-m-d');
                $pend_d = (new DateTimeImmutable($pstart_d))->modify('+13 days')->format('Y-m-d');
            }
            $period_label = 'Bi-Weekly: ' . date('M j', strtotime($pstart_d)) . ' – ' . date('M j, Y', strtotime($pend_d));
        }

        // --- Metrics straight from real columns (no comment parsing, no 15% guess) ---
        $reg_hrs    = (float)$r['reg_hours'];
        $ot_hrs     = (float)$r['overtime_hours'];
        $travel_hrs = (float)$r['travel_hours'];

        $rate  = ((float)$r['line_rate'] > 0) ? (float)$r['line_rate'] : (float)$r['user_profile_rate'];
        $gross = (float)$r['total_gross'];
        if ($gross <= 0) {
            $gross = ((float)$r['reg_pay'] > 0) ? (float)$r['reg_pay'] : ($reg_hrs * $rate);
        }

        $ee_cpp = (float)$r['ee_cpp'];
        $ee_ei  = (float)$r['ee_ei'];
        $ee_tax = (float)$r['ee_tax'];                 // real income_tax column
        $ee_total = $ee_cpp + $ee_ei + $ee_tax;

        $net     = pe_employee_net($pdo, $gross, $ee_cpp, $ee_ei, $ee_tax, (float)$r['er_vac'], (int)($r['site_id'] ?? 0), (int)($r['org_id'] ?? 0));
        $paid    = (float)$r['paid_amount'];
        $balance = max(0, $net - $paid);

        $er_cpp = (float)$r['er_cpp'];                 // real cpp_er column
        $er_ei  = (float)$r['er_ei'];                  // real ei_er column
        $er_wcb = (float)$r['er_wcb'];
        $er_vac = (float)$r['er_vac'];

        // --- Choose hierarchy ---
        if ($report_type === 'employee_wise') {
            $l1 = $r['employee_name'];
            $l2 = $r['site_name'];
        } else { // site_wise (default)
            $l1 = $r['site_name'];
            $l2 = $r['employee_name'];
        }

        // init nodes
        if (!isset($tree[$period_label]))            { $tree[$period_label] = ['totals' => $blank, 'children' => []]; }
        if (!isset($tree[$period_label]['children'][$l1])) {
            $tree[$period_label]['children'][$l1] = ['name' => $l1, 'totals' => $blank, 'children' => []];
        }
        if (!isset($tree[$period_label]['children'][$l1]['children'][$l2])) {
            $tree[$period_label]['children'][$l1]['children'][$l2] = ['name' => $l2, 'totals' => $blank, 'shifts' => []];
        }

        // shift line
        $tree[$period_label]['children'][$l1]['children'][$l2]['shifts'][] = [
            'work_date'      => $r['work_date'],
            'display_label'  => $r['display_label'],
            'payment_status' => $r['payment_status'],
            'reg_hours'      => $reg_hrs,
            'ot_hours'       => $ot_hrs,
            'travel_hours'   => $travel_hrs,
            'rate'           => $rate,
            'gross'          => $gross,
            'ee_cpp'         => $ee_cpp,
            'ee_ei'          => $ee_ei,
            'ee_tax'         => $ee_tax,
            'er_cpp'         => $er_cpp,
            'er_ei'          => $er_ei,
            'er_wcb'         => $er_wcb,
            'er_vac'         => $er_vac,
            'paid'           => $paid,
            'balance'        => $balance,
        ];

        // roll up identical keys across the three totals buckets
        $add = [
            'reg_hours' => $reg_hrs, 'ot_hours' => $ot_hrs, 'travel_hours' => $travel_hrs,
            'gross' => $gross, 'ee_cpp' => $ee_cpp, 'ee_ei' => $ee_ei, 'ee_tax' => $ee_tax,
            'er_cpp' => $er_cpp, 'er_ei' => $er_ei, 'er_wcb' => $er_wcb, 'er_vac' => $er_vac,
            'paid' => $paid, 'balance' => $balance,
        ];
        foreach ($add as $k => $v) {
            $tree[$period_label]['totals'][$k]                                   += $v;
            $tree[$period_label]['children'][$l1]['totals'][$k]                  += $v;
            $tree[$period_label]['children'][$l1]['children'][$l2]['totals'][$k] += $v;
        }
    }

} catch (Exception $e) {
    die("Master Report Compilation Error: " . $e->getMessage());
}

// ---- tiny render helpers -------------------------------------------------
function money($v) { return '$' . number_format((float)$v, 2); }
function hrs($v)   { return number_format((float)$v, 2) . ' hrs'; }

/**
 * Emit the numeric <td> cells shared by every level so columns line up.
 */
function metric_cells($d, $inc_deductions, $inc_employer) {
    $out  = '<td>' . hrs($d['reg_hours']) . '</td>';
    $out .= '<td class="text-red">' . hrs($d['ot_hours']) . '</td>';
    $out .= '<td class="text-info">' . hrs($d['travel_hours']) . '</td>';
    $out .= '<td class="col-money">' . money($d['gross']) . '</td>';
    if ($inc_deductions) {
        $out .= '<td class="text-red">-' . money($d['ee_cpp']) . '</td>';
        $out .= '<td class="text-red">-' . money($d['ee_ei'])  . '</td>';
        $out .= '<td class="text-red">-' . money($d['ee_tax']) . '</td>';
    }
    if ($inc_employer) {
        $out .= '<td class="text-green">+' . money($d['er_cpp']) . '</td>';
        $out .= '<td class="text-green">+' . money($d['er_ei'])  . '</td>';
        $out .= '<td class="text-green">+' . money($d['er_wcb']) . '</td>';
        $out .= '<td class="text-green">+' . money($d['er_vac']) . '</td>';
    }
    $out .= '<td class="text-info col-money">' . money($d['paid']) . '</td>';
    $bal_color = ($d['balance'] > 0.01) ? '#dc2626' : '#16a34a';
    $out .= '<td class="col-balance" style="color:' . $bal_color . ';"><strong>' . money($d['balance']) . '</strong></td>';
    return $out;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Consolidated Master Payroll Suite Ledger</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; padding-top: 120px; color: #1e293b; font-size: 11px; }
        .container { max-width: 1700px; margin: 0 auto; padding: 0 20px; }
        .filter-panel { background: white; padding: 22px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 25px; border: 1px solid #e2e8f0; }
        .period-card { background: white; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border: 1px solid #cbd5e1; margin-bottom: 30px; overflow: hidden; }
        .period-header { background: #0f172a; color: white; padding: 12px 20px; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; display:flex; justify-content:space-between; align-items:center; }
        .report-table { width: 100%; border-collapse: collapse; }
        .report-table th { background: #334155; padding: 12px 12px; text-align: center; font-size: 10px; font-weight: 800; text-transform: uppercase; color: #fff; border-bottom: 2px solid #cbd5e1; }
        .report-table td { padding: 11px 12px; border-bottom: 1px solid #f1f5f9; font-size: 12px; text-align: center; font-weight: bold; }
        .col-money, .col-balance { font-size: 13px; color:#0f172a; }
        .col-balance { text-align:right; padding-right:20px; }
        .text-red { color: #dc2626; } .text-green { color: #16a34a; } .text-info { color: #2563eb; }

        /* hierarchy rows */
        tr.lvl1 { background:#eef2f7; cursor:pointer; }
        tr.lvl1:hover { background:#e2e8f0; }
        tr.lvl1 td:first-child { font-size:13px; color:#0f172a; }
        tr.lvl2 { background:#fbfdff; cursor:pointer; }
        tr.lvl2:hover { background:#f1f5f9; }
        tr.lvl3 { background:#ffffff; font-weight:600; }
        tr.lvl3 td { font-weight:600; color:#475569; }
        .name-cell { text-align:left !important; }
        .indent-1 { padding-left:18px !important; }
        .indent-2 { padding-left:42px !important; }
        .indent-3 { padding-left:70px !important; }
        .hidden { display:none; }
        .chev { display:inline-block; transition: transform .15s ease; margin-right:8px; color:#64748b; }
        .chev.open { transform: rotate(90deg); }
        .badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:9px; font-weight:800; text-transform:uppercase; margin-left:8px; }
        .badge-paid { background:#dcfce7; color:#16a34a; }
        .badge-partial { background:#fef9c3; color:#a16207; }
        .badge-unpaid { background:#fee2e2; color:#dc2626; }
        .shift-date { color:#0f172a; font-weight:700; }
        .shift-label { color:#64748b; font-weight:600; }

        .input-field { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; font-weight: 600; color: #334155; }
        .btn-blue { background: #0097a7; color: white; padding: 9px 24px; border-radius: 6px; border: none; cursor: pointer; font-weight: 700; font-size: 12px; height: 38px; }
        .btn-ghost { background:#fff; border:1px solid #cbd5e1; color:#334155; padding:6px 14px; border-radius:6px; cursor:pointer; font-weight:700; font-size:10px; }
        .checkbox-container { display: flex; gap: 15px; align-items: center; font-weight: 700; font-size: 11px; color: #475569; }
        @media print { .filter-panel, .no-print { display:none; } tr.hidden { display: table-row !important; } .chev{display:none;} }
    </style>
</head>
<body>

<?= generateMenu($pdo); ?>

<div class="container">
    <div class="d-flex justify-content-between align-items-start mb-3" style="display:flex;justify-content:space-between;align-items:flex-start;">
        <div>
            <h2 style="margin:0; color: #0f172a; font-weight: 800;"><i class="fa fa-file-invoice-dollar" style="color:#0097a7;"></i> Unified Master Payroll Period Report Suite</h2>
            <p style="margin:8px 0 0; max-width:920px; color:#64748b; font-size:13px; font-weight:500; line-height:1.55;">Period summary by site (or employee): <strong>Gross Pay</strong> vs <strong>Remaining</strong> — Remaining is closer to net when Show Deductions is on — and <strong>Paid</strong> vs still owing. Tick Show Deductions / Employer Matching to reveal the EE and ER breakdown. Not the QB export page (Daily Payroll Summary).</p>
        </div>
        <div class="no-print" style="display:flex; gap:10px;">
            <button onclick="expandAll()" class="btn-ghost"><i class="fa fa-plus-square"></i> Expand All</button>
            <button onclick="collapseAll()" class="btn-ghost"><i class="fa fa-minus-square"></i> Collapse All</button>
            <button onclick="window.print()" class="btn-blue" style="background:#1e293b;"><i class="fa fa-print mr-1"></i> Print Corporate Sheet</button>
            <a href="payroll_ytd.php" class="btn-ghost" style="text-decoration:none;display:inline-flex;align-items:center;height:38px;">YTD (paystubs)</a>
        </div>
    </div>

    <!-- CRITICAL FILTERS CONTROL PANEL -->
    <form method="GET" class="filter-panel">
        <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
            <div style="display: flex; gap: 10px;">
                <div><label style="display:block; font-size:9px; font-weight:800; color:#64748b; text-transform:uppercase; margin-bottom:4px;">From</label><input type="date" name="from_date" class="input-field" value="<?= htmlspecialchars($from_date) ?>"></div>
                <div><label style="display:block; font-size:9px; font-weight:800; color:#64748b; text-transform:uppercase; margin-bottom:4px;">To</label><input type="date" name="to_date" class="input-field" value="<?= htmlspecialchars($to_date) ?>"></div>
            </div>

            <div>
                <label style="display:block; font-size:9px; font-weight:800; color:#64748b; text-transform:uppercase; margin-bottom:4px;">Project Location</label>
                <select name="site_id" class="input-field" style="width:160px;">
                    <option value="">-- All Projects Combined --</option>
                    <?php foreach($sites as $s): ?><option value="<?= $s['SId'] ?>" <?= $site_id == $s['SId'] ? 'selected' : '' ?>><?= htmlspecialchars($s['nick_name']) ?></option><?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="display:block; font-size:9px; font-weight:800; color:#0097a7; text-transform:uppercase; margin-bottom:4px;">View Grouping Mode</label>
                <select name="report_type" class="input-field" style="width:230px; border: 1px solid #0097a7;">
                    <option value="site_wise"     <?=$report_type==='site_wise'?'selected':''?>>🏢 Site → Employee → Shift</option>
                    <option value="employee_wise" <?=$report_type==='employee_wise'?'selected':''?>>👤 Employee → Site → Shift</option>
                </select>
            </div>

            <div>
                <label style="display:block; font-size:9px; font-weight:800; color:#2563eb; text-transform:uppercase; margin-bottom:4px;">Payroll Period Cycle</label>
                <select name="period_mode" class="input-field" style="width:210px; border: 1px solid #2563eb;">
                    <option value="semi_monthly" <?=$period_mode==='semi_monthly'?'selected':''?>>📆 Semi-Monthly (1-15 / 16-End)</option>
                    <option value="bi_weekly"    <?=$period_mode==='bi_weekly'?'selected':''?>>📆 Bi-Weekly (Every 2 Weeks)</option>
                    <option value="monthly"      <?=$period_mode==='monthly'?'selected':''?>>📆 Monthly Complete Statement</option>
                </select>
            </div>

            <div class="checkbox-container">
                <label><input type="checkbox" name="inc_deductions" <?= $inc_deductions ? 'checked' : '' ?>> Show Deductions</label>
                <label><input type="checkbox" name="inc_employer" <?= $inc_employer ? 'checked' : '' ?>> Show Employer Matching</label>
            </div>

            <div style="margin-left: auto;"><button type="submit" class="btn-blue">Compile Summary</button></div>
        </div>
    </form>

    <!-- DYNAMIC NESTED PERIOD GENERATION -->
    <?php if (empty($tree)): ?>
        <div style="background:white; border-radius:12px; padding:60px; text-align:center; border:1px solid #e2e8f0; color:#64748b; font-weight:bold; font-style:italic;">No finalized ledger records found matching parameters within this window.</div>
    <?php else: ?>
        <?php
        $node_id   = 0;
        $l1_header = ($report_type === 'employee_wise') ? 'Employee' : 'Job Location Center';
        $l2_header = ($report_type === 'employee_wise') ? 'Site'     : 'Employee';
        ?>
        <?php foreach ($tree as $period_title => $period): ?>
            <div class="period-card">
                <div class="period-header">
                    <span><i class="far fa-calendar-alt mr-2"></i> Payroll Statement Period: <strong><?= htmlspecialchars($period_title) ?></strong></span>
                    <span style="font-size:11px; opacity:.85;">Period Balance: <?= money($period['totals']['balance']) ?></span>
                </div>

                <table class="report-table">
                    <thead>
                        <tr>
                            <th class="name-cell" style="width: 24%; padding-left:18px;"><?= $l1_header ?> / <?= $l2_header ?> / Shift</th>
                            <th>Regular Hrs</th>
                            <th class="text-red">Overtime Hrs</th>
                            <th class="text-info">Travel Hrs</th>
                            <th>Gross Pay</th>
                            <?php if($inc_deductions): ?>
                                <th class="text-red">CPP (EE)</th><th class="text-red">EI (EE)</th><th class="text-red">Income Tax</th>
                            <?php endif; ?>
                            <?php if($inc_employer): ?>
                                <th class="text-green">CPP (ER)</th><th class="text-green">EI (ER)</th><th class="text-green">WCB</th><th class="text-green">Vac</th>
                            <?php endif; ?>
                            <th class="text-info">Paid Amount</th>
                            <th class="text-red" style="text-align:right; padding-right:20px;">Remaining Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($period['children'] as $l1node):
                        $sid = 'n' . (++$node_id);
                    ?>
                        <!-- LEVEL 1 -->
                        <tr class="lvl1" data-row-id="<?= $sid ?>" onclick="toggleRow('<?= $sid ?>')">
                            <td class="name-cell indent-1">
                                <span class="chev" id="chev-<?= $sid ?>">&#9656;</span>
                                <strong><?= htmlspecialchars($l1node['name']) ?></strong>
                            </td>
                            <?= metric_cells($l1node['totals'], $inc_deductions, $inc_employer) ?>
                        </tr>

                        <?php foreach ($l1node['children'] as $l2node):
                            $eid = 'n' . (++$node_id);
                        ?>
                            <!-- LEVEL 2 -->
                            <tr class="lvl2 hidden" data-parent="<?= $sid ?>" data-row-id="<?= $eid ?>" onclick="toggleRow('<?= $eid ?>')">
                                <td class="name-cell indent-2">
                                    <span class="chev" id="chev-<?= $eid ?>">&#9656;</span>
                                    <?= htmlspecialchars($l2node['name']) ?>
                                </td>
                                <?= metric_cells($l2node['totals'], $inc_deductions, $inc_employer) ?>
                            </tr>

                            <?php foreach ($l2node['shifts'] as $sh):
                                $ps  = strtolower($sh['payment_status']);
                                $bc  = $ps === 'paid' ? 'badge-paid' : ($ps === 'partial' ? 'badge-partial' : 'badge-unpaid');
                            ?>
                                <!-- LEVEL 3 (shift line) -->
                                <tr class="lvl3 hidden" data-parent="<?= $eid ?>">
                                    <td class="name-cell indent-3">
                                        <span class="shift-date"><?= htmlspecialchars(date('D, M j Y', strtotime($sh['work_date']))) ?></span>
                                        <span class="shift-label">&nbsp;·&nbsp;<?= htmlspecialchars($sh['display_label']) ?> @ <?= money($sh['rate']) ?>/hr</span>
                                        <span class="badge <?= $bc ?>"><?= htmlspecialchars($sh['payment_status']) ?></span>
                                    </td>
                                    <?= metric_cells($sh, $inc_deductions, $inc_employer) ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function toggleRow(id) {
    var kids = document.querySelectorAll('tr[data-parent="' + id + '"]');
    if (!kids.length) return;
    var opening = kids[0].classList.contains('hidden');
    var chev = document.getElementById('chev-' + id);
    if (opening) {
        kids.forEach(function (k) { k.classList.remove('hidden'); });
        if (chev) chev.classList.add('open');
    } else {
        kids.forEach(function (k) { k.classList.add('hidden'); collapseDesc(k); });
        if (chev) chev.classList.remove('open');
    }
}
function collapseDesc(row) {
    var rid = row.getAttribute('data-row-id');
    if (!rid) return;
    var chev = document.getElementById('chev-' + rid);
    if (chev) chev.classList.remove('open');
    document.querySelectorAll('tr[data-parent="' + rid + '"]').forEach(function (k) {
        k.classList.add('hidden');
        collapseDesc(k);
    });
}
function expandAll() {
    document.querySelectorAll('tr[data-parent]').forEach(function (k) { k.classList.remove('hidden'); });
    document.querySelectorAll('.chev').forEach(function (c) { c.classList.add('open'); });
}
function collapseAll() {
    document.querySelectorAll('tr.lvl2, tr.lvl3').forEach(function (k) { k.classList.add('hidden'); });
    document.querySelectorAll('.chev').forEach(function (c) { c.classList.remove('open'); });
}
</script>

</body>
</html>
