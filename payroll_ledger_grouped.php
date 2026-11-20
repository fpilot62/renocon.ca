<?php
/** Path: /payroll_ledger_grouped.php */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
require_once 'engine/menu_generator.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$sess_user_level = isset($_SESSION['user_level']) ? (int)$_SESSION['user_level'] : 99;
$org_id = (int)($_SESSION['org_id'] ?? 1);

$from_date   = $_GET['from_date']   ?? '2026-02-01';
$to_date     = $_GET['to_date']     ?? '2026-05-31';
$site_id     = $_GET['site_id']     ?? '';
$employee_id = $_GET['user_id']     ?? ''; 
$report_type = $_GET['type']        ?? 'combined'; 

function getBiWeekHeading($date) {
    $dt = new DateTime($date);
    $monday = clone $dt;
    if ($dt->format('N') != 1) { $monday->modify('last monday'); }
    $anchor = new DateTime('2026-01-05'); 
    $days_diff = (int)$anchor->diff($monday)->format('%r%a');
    $biweek_index = floor($days_diff / 14);
    $start = clone $anchor; $start->modify(($biweek_index * 14) . ' days');
    $end = clone $start; $end->modify('+13 days'); 
    return "PAY PERIOD: " . $start->format('M j') . " - " . $end->format('M j, Y');
}

try {
    if ($sess_user_level <= 10) {
        $staff = $pdo->query("SELECT id, Name FROM users ORDER BY Name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stfStmt = $pdo->prepare("SELECT id, Name FROM users WHERE org_id = ? ORDER BY Name ASC");
        $stfStmt->execute([$org_id]);
        $staff = $stfStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!function_exists('sip_org_site_fence')) { require_once __DIR__ . '/engine/site_status_lib.php'; }
$__fence = sip_org_site_fence($pdo, 'sip_org_id');
$is_super = $__fence['is_super']; $selected_org = $__fence['selected_org']; $orgs = $__fence['orgs']; $sites = $__fence['sites'];

    
    $site_clause = ($site_id !== '') ? " AND p.site_id = " . (int)$site_id : "";
    $user_clause = ($employee_id !== '') ? " AND p.user_id = " . (int)$employee_id : "";

    $q_final = "SELECT COALESCE(u.Name, 'Unresolved Profile ID: ' + p.user_id) as Name, u.hourly_rate as profile_rate, 
                COALESCE(DATE(a.clock_in), p.work_date) as work_date, 
                GREATEST(COALESCE(p.reg_hours, 0), COALESCE(p.balance_hours, 0)) as hrs, 
                p.user_id, p.site_id, p.hourly_rate as line_rate, p.reg_pay, s.nick_name as site, 'FINALIZED' as src 
                FROM payroll_finalized p 
                LEFT JOIN attendance a ON p.attendance_id = a.id
                LEFT JOIN users u ON p.user_id = u.id 
                LEFT JOIN sites s ON p.site_id = s.SId 
                WHERE COALESCE(DATE(a.clock_in), p.work_date) BETWEEN ? AND ? $site_clause $user_clause";
    
    $q_bal = "SELECT COALESCE(u.Name, 'Unresolved Profile ID: ' + p.user_id) as Name, u.hourly_rate as profile_rate, 
              COALESCE(DATE(a.clock_in), p.work_date) as work_date, 
              GREATEST(COALESCE(p.reg_hours, 0), COALESCE(p.balance_hours, 0)) as hrs, 
              p.user_id, p.site_id, 0 as line_rate, 0 as reg_pay, s.nick_name as site, 'BALANCE' as src 
              FROM payroll_balance p 
              LEFT JOIN attendance a ON p.attendance_id = a.id
              LEFT JOIN users u ON p.user_id = u.id 
              LEFT JOIN sites s ON p.site_id = s.SId 
              WHERE COALESCE(DATE(a.clock_in), p.work_date) BETWEEN ? AND ? $site_clause $user_clause";

    if ($report_type === 'finalized') {
        $stmt = $pdo->prepare("$q_final ORDER BY work_date ASC");
        $stmt->execute([$from_date, $to_date]);
    } elseif ($report_type === 'balance') {
        $stmt = $pdo->prepare("$q_bal ORDER BY work_date ASC");
        $stmt->execute([$from_date, $to_date]);
    } else {
        $stmt = $pdo->prepare("($q_final) UNION ALL ($q_bal) ORDER BY work_date ASC");
        $stmt->execute([$from_date, $to_date, $from_date, $to_date]);
    }
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { die("Error: " . $e->getMessage()); }

// Initialize grand summary total accumulators
$grand_total_gross = 0; 
$grand_total_tax = 0;
$grand_total_cpp = 0;
$grand_total_ei = 0;
$grand_total_net = 0;

$p_hrs = 0; $p_gross = 0; $p_tax = 0; $p_cpp = 0; $p_ei = 0; $p_net = 0;
$current_period = "";
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payroll Ledger</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; padding-top: 110px; font-size: 11px; }
        .container { max-width: 1650px; margin: 0 auto; padding: 0 20px; }
        .nav-tabs { display: flex; gap: 10px; margin-bottom: 15px; }
        .nav-tabs a { padding: 10px 20px; background: white; border-radius: 8px; text-decoration: none; color: #64748b; font-weight: 800; border: 1px solid #e2e8f0; }
        .nav-tabs a.active { background: #0f172a; color: white; border-color: #0f172a; }
        .filter-bar { background: white; padding: 15px; border-radius: 12px; display: flex; gap: 15px; align-items: flex-end; margin-bottom: 25px; border: 1px solid #e2e8f0; }
        .filter-bar div { display: inline-block; }
        .filter-bar label { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 4px; display: inline-block; }
        .filter-bar select, .filter-bar input { padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 11px; font-weight: 600; background: white; height: 32px; box-sizing: border-box; }
        .period-header { background: #334155; color: white; padding: 12px 15px; font-weight: 900; margin-top: 30px; border-radius: 8px 8px 0 0; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; background: white; margin-bottom: 10px; }
        th { padding: 10px; border: 1px solid #e2e8f0; background: #f8fafc; cursor: pointer; text-transform: uppercase; user-select: none; color: #475569; position: relative; }
        th:hover { background: #edf2f7; }
        td { padding: 10px; border: 1px solid #e2e8f0; }
        .subtotal-row { background: #f8fafc; font-weight: 900; color: #2563eb; }
        .pill { padding: 2px 6px; border-radius: 4px; font-size: 9px; font-weight: 900; text-transform: uppercase; }
        .pill-FINALIZED { background: #dcfce7; color: #16a34a; }
        .pill-BALANCE { background: #fee2e2; color: #dc2626; }
        .tax-col { color: #dc2626; font-style: italic; }
        .net-col { font-weight: bold; color: #16a34a; }
        .summary-dashboard-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 15px; margin-top: 20px; }
        .summary-card { background: white; padding: 15px; border-radius: 12px; border: 1px solid #e2e8f0; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
        .summary-card h6 { margin: 0 0 5px 0; text-transform: uppercase; font-size: 10px; color: #64748b; font-weight: 800; }
        .summary-card p { margin: 0; font-size: 18px; font-weight: bold; color: #0f172a; }
    </style>
</head>
<body>
<?= generateMenu($pdo) ?>
<div class="container">
    <div class="nav-tabs">
        <a href="?type=finalized&from_date=<?=$from_date?>&to_date=<?=$to_date?>&site_id=<?=$site_id?>&user_id=<?=$employee_id?>" class="<?=$report_type=='finalized'?'active':''?>">FINALIZED</a>
        <a href="?type=balance&from_date=<?=$from_date?>&to_date=<?=$to_date?>&site_id=<?=$site_id?>&user_id=<?=$employee_id?>" class="<?=$report_type=='balance'?'active':''?>">BALANCE</a>
        <a href="?type=combined&from_date=<?=$from_date?>&to_date=<?=$to_date?>&site_id=<?=$site_id?>&user_id=<?=$employee_id?>" class="<?=$report_type=='combined'?'active':''?>">COMBINED VIEW</a>
    </div>

    <form class="filter-bar" method="GET">
        <input type="hidden" name="type" value="<?=$report_type?>">
        <div><label>START</label><br><input type="date" name="from_date" value="<?=$from_date?>"></div>
        <div><label>END</label><br><input type="date" name="to_date" value="<?=$to_date?>"></div>
        
        <div><label>Employee Staff</label><br>
            <select name="user_id" style="min-width: 180px;">
                <option value="">All Personnel Members</option>
                <?php foreach($staff as $u): ?>
                    <option value="<?=$u['id']?>" <?=$employee_id == $u['id'] ? 'selected' : ''?>><?= htmlspecialchars($u['Name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div><label>SITE</label><br>
            <?php if (!empty($is_super)): ?>
                <select name="org_id" onchange="this.form.submit()">
                    <option value="all" <?= (int)($selected_org ?? 0) === 0 ? 'selected' : '' ?>>All organizations</option>
                    <?php foreach (($orgs ?? []) as $o): ?>
                        <option value="<?= (int)$o['id'] ?>" <?= (int)($selected_org ?? 0) === (int)$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$o['org_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <select name="site_id">
                <option value="">All Projects</option>
                <?php foreach($sites as $s): ?>
                    <option value="<?=$s['SId']?>" <?=$site_id==$s['SId']?'selected':''?>><?=$s['nick_name']?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" style="background:#2563eb; color:white; padding:0 15px; border:none; border-radius:6px; cursor:pointer; font-weight:bold; height:32px;">Update View</button>
    </form>

    <?php foreach ($data as $i => $r): 
        $biweek = getBiWeekHeading($r['work_date']);
        $hrs = (float)$r['hrs'];
        $rate = (float)($r['line_rate'] > 0 ? $r['line_rate'] : $r['profile_rate']);
        
        // Dynamic deduction computations matches payout manager engine formulas exactly
        $gross = (float)$r['reg_pay'] > 0 ? (float)$r['reg_pay'] : ($hrs * $rate);
        $day = substr((string)$r['work_date'], 0, 10);
        $grp = pe_line($gross, pe_vacation_rate($pdo, (int)($r['user_id'] ?? 0), (int)($r['site_id'] ?? 0), $day), pe_rates($pdo), [
            'pdo' => $pdo, 'user_id' => (int)($r['user_id'] ?? 0), 'site_id' => (int)($r['site_id'] ?? 0), 'org_id' => $org_id, 'work_date' => $day,
        ]);
        $tax_withheld = (float)$grp['income_tax'];
        $cpp_withheld = (float)$grp['cpp'] + (float)($grp['cpp2'] ?? 0);
        $ei_withheld = (float)$grp['ei'];
        $total_deductions = $tax_withheld + $cpp_withheld + $ei_withheld;
        $net_payout = (float)$grp['net'];

        if ($current_period !== "" && $current_period !== $biweek): ?>
            <tr class="subtotal-row">
                <td colspan="3" style="text-align:right;">Subtotal:</td>
                <td><?=number_format($p_hrs, 2)?>h</td>
                <td></td>
                <td style="text-align:right;">$<?=number_format($p_gross, 2)?></td>
                <td class="tax-col" style="text-align:right;">$<?=number_format($p_tax, 2)?></td>
                <td class="tax-col" style="text-align:right;">$<?=number_format($p_cpp, 2)?></td>
                <td class="tax-col" style="text-align:right;">$<?=number_format($p_ei, 2)?></td>
                <td></td>
                <td class="net-col" style="text-align:right;">$<?=number_format($p_net, 2)?></td>
            </tr>
            </table>
            <?php 
            // Reset period subtotal arrays
            $p_gross = 0; $p_hrs = 0; $p_tax = 0; $p_cpp = 0; $p_ei = 0; $p_net = 0; 
            ?>
        <?php endif; ?>

        <?php if ($current_period !== $biweek): $current_period = $biweek; ?>
            <div class="period-header"><?=$biweek?></div>
            <table><thead><tr>
                <th width="8%">Date</th>
                <th width="15%">Employee</th>
                <th width="12%">Site</th>
                <th width="6%">Hrs</th>
                <th width="6%">Rate</th>
                <th width="10%" style="text-align:right;">Gross Pay</th>
                <th width="8%" style="text-align:right; color:#be123c;">Tax (12%)</th>
                <th width="8%" style="text-align:right; color:#be123c;">CPP (5.95%)</th>
                <th width="8%" style="text-align:right; color:#be123c;">EI (1.66%)</th>
                <th width="9%">Status</th>
                <th width="10%" style="text-align:right; color:#16a34a;">Net Owed</th>
            </tr></thead><tbody>
        <?php endif; ?>

        <?php 
        // Accumulate Period Subtotals
        $p_gross += $gross; $p_hrs += $hrs; $p_tax += $tax_withheld; $p_cpp += $cpp_withheld; $p_ei += $ei_withheld; $p_net += $net_payout;
        
        // Accumulate Corporate Grand Totals
        $grand_total_gross += $gross; 
        $grand_total_tax   += $tax_withheld;
        $grand_total_cpp   += $cpp_withheld;
        $grand_total_ei    += $ei_withheld;
        $grand_total_net   += $net_payout;
        ?>
        <tr>
            <td><?=$r['work_date']?></td>
            <td><strong><?=$r['Name']?></strong></td>
            <td><?=$r['site'] ?: 'Office/Import'?></td>
            <td><?=number_format($hrs, 2)?></td>
            <td>$<?=number_format($rate, 2)?></td>
            <td style="text-align:right; font-weight:600;">$<?=number_format($gross, 2)?></td>
            <td class="tax-col" style="text-align:right;">$<?=number_format($tax_withheld, 2)?></td>
            <td class="tax-col" style="text-align:right;">$<?=number_format($cpp_withheld, 2)?></td>
            <td class="tax-col" style="text-align:right;">$<?=number_format($ei_withheld, 2)?></td>
            <td><span class="pill pill-<?=$r['src']?>"><?=$r['src']?></span></td>
            <td class="net-col" style="text-align:right;">$<?=number_format($net_payout, 2)?></td>
        </tr>
    <?php endforeach; ?>
    
    <?php if(!empty($data)): ?>
        <tr class="subtotal-row">
            <td colspan="3" style="text-align:right;">Subtotal:</td>
            <td><?=number_format($p_hrs, 2)?>h</td>
            <td></td>
            <td style="text-align:right;">$<?=number_format($p_gross, 2)?></td>
            <td class="tax-col" style="text-align:right;">$<?=number_format($p_tax, 2)?></td>
            <td class="tax-col" style="text-align:right;">$<?=number_format($p_cpp, 2)?></td>
            <td class="tax-col" style="text-align:right;">$<?=number_format($p_ei, 2)?></td>
            <td></td>
            <td class="net-col" style="text-align:right;">$<?=number_format($p_net, 2)?></td>
        </tr>
        </tbody></table>
        
        <div class="summary-dashboard-grid">
            <div class="summary-card" style="border-top: 4px solid #0f172a;">
                <h6>Grand Total Gross</h6>
                <p>$<?=number_format($grand_total_gross, 2)?></p>
            </div>
            <div class="summary-card" style="border-top: 4px solid #dc2626;">
                <h6>Total Income Tax Liability (12%)</h6>
                <p style="color:#b91c1c;">$<?=number_format($grand_total_tax, 2)?></p>
            </div>
            <div class="summary-card" style="border-top: 4px solid #d97706;">
                <h6>Total CPP Pool Liability (5.95%)</h6>
                <p style="color:#b45309;">$<?=number_format($grand_total_cpp, 2)?></p>
            </div>
            <div class="summary-card" style="border-top: 4px solid #2563eb;">
                <h6>Total EI Pool Liability (1.66%)</h6>
                <p style="color:#1d4ed8;">$<?=number_format($grand_total_ei, 2)?></p>
            </div>
            <div class="summary-card" style="border-top: 4px solid #16a34a; background:#f0fdf4;">
                <h6>Net Payroll Liability</h6>
                <p style="color:#15803d; font-size:20px;">$<?=number_format($grand_total_net, 2)?></p>
            </div>
        </div>
    <?php else: ?>
        <div style="text-align:center; padding:50px; background:white; border-radius:12px; font-size:13px; color:#64748b; font-style:italic; border:1px solid #e2e8f0;">No historic ledger records detected matching search query limits.</div>
    <?php endif; ?>
</div>
</body>
</html>