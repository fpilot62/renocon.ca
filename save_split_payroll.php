<?php
require_once __DIR__ . '/engine/orgscope_guard.php'; osg_page(['level'=>29,'fence'=>['attendance_id'=>'attendance','id'=>'attendance']]); // org-scope audit Nov 2026

/**
 * Path: /save_split_payroll.php
 * STAGE: A (LIVE — previews, then on confirmation writes to the database)
 *
 * Flow:
 *   1) First POST from finalize_shift.php  -> show the preview (Confirm button ACTIVE).
 *   2) POST with confirm_save=1            -> insert rows in a transaction, redirect to ledger.
 *
 * Routing per pay line (the "Send To Finalized" checkbox decides the destination TABLE):
 *   - checked   (route_finalized_* present) -> payroll_finalized  (full deductions)
 *   - unchecked (route_finalized_* absent)  -> payroll_balance    (full deductions too)
 *
 * Line classification (which hours column it lands in):
 *   - kind_0 row : 'regular' (reg_hours) or 'travel' (travel_hours) when kind_0=transit
 *   - ot row     : 'overtime' (overtime_hours)
 *   - baseline   : 'balance'  (balance_hours)
 *   - dynamic_*  : 'balance'  (balance_hours)
 *   Lines with hours <= 0 are skipped.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once __DIR__ . '/engine/attendance_audit_lib.php';
if (is_file(__DIR__ . '/break_waiver_finalize_guard.php')) {
    require_once __DIR__ . '/break_waiver_finalize_guard.php';
}

if (!isset($_SESSION['user_id'])) { die("Access denied."); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { die("This page expects a finalize submission."); }

$attendance_id = (int)($_POST['attendance_id'] ?? 0);
if (!$attendance_id) { die("Missing attendance reference."); }
$do_save = isset($_POST['confirm_save']);

// single source of truth for all payroll math
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
$pe_rates = pe_rates($pdo);   // also keeps the display block's $ei_mult etc. available below
$ei_mult  = $pe_rates['ei_mult'];


$ctx = $pdo->prepare("SELECT a.user_id, a.org_id, a.site_id, a.clock_in, a.status, a.transfer_status, u.Name
                      FROM attendance a LEFT JOIN users u ON a.user_id = u.id WHERE a.id = ?");
$ctx->execute([$attendance_id]);
$ctx = $ctx->fetch(PDO::FETCH_ASSOC);
if (!$ctx) { die("Attendance record not found."); }
$is_travel_shift = (trim((string)($ctx['status'] ?? '')) === '5');
$work_date = !empty($ctx['clock_in']) ? date('Y-m-d', strtotime($ctx['clock_in'])) : date('Y-m-d');
$profile_hourly = pe_profile_hourly_rate($pdo, (int)$ctx['user_id'], $work_date); // dated history, else profile
$vac_rate = pe_vacation_rate($pdo, (int)$ctx['user_id'], (int)$ctx['site_id'], $work_date);
$day_of_month     = (int)date('j', strtotime($work_date));
$pay_period_end   = ($day_of_month <= 15) ? date('Y-m-15', strtotime($work_date)) : date('Y-m-t', strtotime($work_date));
$pay_period_start = ($day_of_month <= 15) ? date('Y-m-01', strtotime($work_date)) : date('Y-m-16', strtotime($work_date));

$suffixes = ['0', 'ot', 'baseline'];
foreach (array_keys($_POST) as $k) {
    if (preg_match('/^(?:route_finalized|hours)_(dynamic_.+)$/', $k, $m)) { $suffixes[] = $m[1]; }
}
$suffixes = array_values(array_unique($suffixes));

$kind_for = function ($sfx) use ($is_travel_shift) {
    if ($sfx === '0')  {
        if ($is_travel_shift) { return 'travel'; } // status=5 shift -> travel hours
        return strtolower(trim($_POST['kind_0'] ?? 'regular')) === 'transit' ? 'travel' : 'regular';
    }
    if ($sfx === 'ot') { return 'overtime'; }
    return 'balance';
};

$lines = [];
$tot = ['hours'=>0,'gross'=>0,'cpp'=>0,'ei'=>0,'tax'=>0,'cpp_er'=>0,'ei_er'=>0,'wcb'=>0,'vac'=>0,'net'=>0,'fin'=>0,'bal'=>0];

foreach ($suffixes as $sfx) {
    $hours = (float)($_POST['hours_' . $sfx] ?? 0);
    if ($hours <= 0) { continue; }

    $to_finalized = isset($_POST['route_finalized_' . $sfx]);
    $label = trim((string)($_POST['label_' . $sfx] ?? ''));
    $kind  = $kind_for($sfx);
    // ALWAYS the employee profile rate (OT = 1.5x profile). Ignore posted/punch rates.
    $rate  = pe_profile_pay_rate($pdo, (int)$ctx['user_id'], $kind, $work_date);
    $gross  = round($hours * $rate, 2);
    // single source of truth: compute via the payroll engine (same rates, same math)
    $d      = pe_line($gross, $vac_rate, $pe_rates, ['pdo' => $pdo, 'site_id' => (int)$ctx['site_id'], 'org_id' => (int)$ctx['org_id'], 'user_id' => (int)$ctx['user_id']]);
    $cpp    = $d['cpp'];
    $ei     = $d['ei'];
    $tax    = $d['income_tax'];
    $cpp_er = $d['cpp_er'];
    $ei_er  = $d['ei_er'];
    $wcb    = $d['wcb'];
    $vac    = $d['vac'];
    $net    = $d['net'];
    $gross  = isset($d['pay_gross']) ? (float)$d['pay_gross'] : $gross;

    $lines[] = compact('kind','label','hours','rate','gross','cpp','ei','tax','cpp_er','ei_er','wcb','vac','net','to_finalized');

    $tot['hours'] += $hours; $tot['gross'] += $gross; $tot['cpp'] += $cpp; $tot['ei'] += $ei; $tot['tax'] += $tax;
    $tot['cpp_er'] += $cpp_er; $tot['ei_er'] += $ei_er; $tot['wcb'] += $wcb; $tot['vac'] += $vac; $tot['net'] += $net;
    $tot[$to_finalized ? 'fin' : 'bal'] += $gross;
}

if ($do_save) {
    if (empty($lines)) { die("Nothing to save."); }
    try {
        $pdo->beginTransaction();

        $insFin = $pdo->prepare(
            "INSERT INTO payroll_finalized
                (attendance_id, category, user_id, org_id, site_id, work_date, pay_period_end, display_label,
                 reg_hours, overtime_hours, travel_hours, balance_hours, hourly_rate, reg_pay, total_gross,
                 cpp, ei, income_tax, cpp_er, ei_er, wcb, vac,
                 country_code, tax_label, safety_label, pay_type, status, payment_status, paid_amount, finalized_by)
             VALUES (?,?,?,?,?,?,?,?, ?,?,?,?,?,?,?, ?,?,?,?,?,?,?, 'CA','EI/CPP','WCB',?, 'pending','Unpaid',0, ?)"
        );
        $insBal = $pdo->prepare(
            "INSERT INTO payroll_balance
                (attendance_id, user_id, org_id, site_id, work_date, pay_period_end,
                 balance_hours, hourly_rate, total_gross,
                 cpp_paid, ei_paid, wcb_wsib_paid, vacation_accrued,
                 status, pay_type, comments)
             VALUES (?,?,?,?,?,?, ?,?,?, ?,?,?,?, 'pending', ?, ?)"
        );

        foreach ($lines as $ln) {
            $reg  = ($ln['kind'] === 'regular')  ? $ln['hours'] : 0;
            $ot   = ($ln['kind'] === 'overtime') ? $ln['hours'] : 0;
            $trav = ($ln['kind'] === 'travel')   ? $ln['hours'] : 0;
            $balh = ($ln['kind'] === 'balance')  ? $ln['hours'] : 0;

            if ($ln['to_finalized']) {
                $line_category = ($ln['kind'] === 'travel') ? 'Travel' : ucfirst($ln['kind']);
                $insFin->execute([
                    $attendance_id, $line_category, $ctx['user_id'], $ctx['org_id'], $ctx['site_id'], $work_date, $pay_period_end, $ln['label'],
                    $reg, $ot, $trav, $balh, $ln['rate'], ($ln['kind']==='regular' ? $ln['gross'] : 0), $ln['gross'],
                    $ln['cpp'], $ln['ei'], $ln['tax'], $ln['cpp_er'], $ln['ei_er'], $ln['wcb'], $ln['vac'],
                    ucfirst($ln['kind']), (int)$_SESSION['user_id'],
                ]);
            } else {
                $insBal->execute([
                    $attendance_id, $ctx['user_id'], $ctx['org_id'], $ctx['site_id'], $work_date, $pay_period_end,
                    $ln['hours'], $ln['rate'], $ln['gross'],
                    $ln['cpp'], $ln['ei'], $ln['wcb'], $ln['vac'],
                    ucfirst($ln['kind']), $ln['label'],
                ]);
            }
        }

        $aaBefore = aa_snapshot($pdo, (int)$attendance_id);
        $pdo->prepare("UPDATE attendance SET payout_status = 1 WHERE id = ?")->execute([$attendance_id]);
        aa_log($pdo, 'finalize', (int)$attendance_id, $aaBefore, null, 'Save split payroll');
                pe_refresh_period_stat($pdo, (int)$ctx['user_id'], (int)$ctx['site_id'], $work_date);
        if (is_file(__DIR__ . '/payroll_engine/stat_holidays.php')) {
            require_once __DIR__ . '/payroll_engine/stat_holidays.php';
            sh_after_finalize($pdo, (int)$ctx['user_id'], (int)$ctx['site_id'], $work_date, (int)($_SESSION['user_id'] ?? 0));
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        die("Save failed (nothing was written): " . htmlspecialchars($e->getMessage()));
    }

    $url = "payroll_master_ledger.php?from_date=" . urlencode($pay_period_start)
         . "&to_date=" . urlencode($pay_period_end) . "&balance_scope=all&msg=finalized";
    header("Location: $url");
    exit;
}

$kind_badge = [
    'regular'  => ['Regular',  '#16a34a', '#dcfce7'],
    'travel'   => ['Travel',   '#d97706', '#fef3c7'],
    'overtime' => ['Overtime', '#2563eb', '#dbeafe'],
    'balance'  => ['Balance',  '#7c3aed', '#ede9fe'],
];
$money = fn($v) => '$' . number_format((float)$v, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Finalize Preview</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', system-ui, sans-serif; background:#f1f5f9; padding-top:40px; color:#334155; font-size:13px; }
        .wrap { max-width:940px; margin:0 auto 60px; background:#fff; padding:28px; border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,0.06); }
        h2 { margin:0 0 4px; font-size:18px; font-weight:800; color:#0f172a; }
        .sub { color:#64748b; font-size:12px; margin-bottom:18px; }
        .review-flag { background:#ecfdf5; border:1px solid #6ee7b7; color:#065f46; padding:10px 14px; border-radius:8px; font-weight:700; font-size:12px; margin-bottom:18px; }
        table { width:100%; border-collapse:collapse; }
        th { background:#475569; color:#fff; font-size:10px; text-transform:uppercase; padding:10px; text-align:left; }
        td { border-bottom:1px solid #e2e8f0; padding:10px; vertical-align:middle; }
        .num { text-align:right; font-variant-numeric:tabular-nums; }
        .badge-kind { padding:2px 9px; border-radius:10px; font-size:10px; font-weight:800; }
        .dest { font-size:9px; font-weight:800; text-transform:uppercase; padding:2px 7px; border-radius:4px; }
        .dest-fin { background:#dcfce7; color:#15803d; } .dest-bal { background:#fee2e2; color:#b91c1c; }
        .totrow td { background:#f8fafc; font-weight:800; font-size:14px; border-top:2px solid #cbd5e1; }
        .ded { display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-top:22px; }
        .box { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; }
        .box h4 { font-size:12px; text-transform:uppercase; color:#0f172a; border-bottom:2px solid #cbd5e1; padding-bottom:8px; margin:0 0 12px; }
        .li { display:flex; justify-content:space-between; font-family:monospace; font-weight:700; margin-bottom:8px; font-size:13px; }
        .li.total { border-top:2px dashed #cbd5e1; padding-top:10px; margin-top:10px; font-size:15px; font-weight:900; }
        .split-note { display:flex; gap:24px; margin-top:14px; font-size:12px; font-weight:700; }
        .actions { display:flex; gap:12px; justify-content:flex-end; margin-top:26px; }
        .btn-back { background:#e2e8f0; color:#475569; border:none; padding:12px 20px; border-radius:8px; font-weight:800; text-decoration:none; }
        .btn-confirm { background:#10b981; color:#fff; border:none; padding:12px 26px; border-radius:8px; font-weight:800; cursor:pointer; }
        .btn-confirm:hover { background:#059669; }
    </style>
</head>
<body>
<div class="wrap">
    <h2><i class="fa fa-calculator text-primary"></i> Finalize Preview &mdash; <?= htmlspecialchars($ctx['Name'] ?? 'Staff') ?></h2>
    <div class="sub">Work date <strong><?= htmlspecialchars($work_date) ?></strong> &middot; Attendance #<?= $attendance_id ?> &middot; <?= count($lines) ?> pay line(s)</div>

    <div class="review-flag"><i class="fa fa-eye"></i> Review before saving. Checked lines go to <strong>Finalized</strong>; unchecked lines go to <strong>Balance</strong>. Nothing is written until you click Confirm.</div>

    <?php if (empty($lines)): ?>
        <div class="alert alert-warning">No pay lines with hours were submitted. Go back and check the rows.</div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Type</th><th>Destination</th><th>Label</th>
                <th class="num">Hours</th><th class="num">Rate</th><th class="num">Gross</th><th class="num">Net</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $ln): $b = $kind_badge[$ln['kind']]; ?>
                <tr>
                    <td><span class="badge-kind" style="background:<?= $b[2] ?>; color:<?= $b[1] ?>;"><?= $b[0] ?></span></td>
                    <td><span class="dest <?= $ln['to_finalized'] ? 'dest-fin' : 'dest-bal' ?>"><?= $ln['to_finalized'] ? 'Finalized' : 'Balance' ?></span></td>
                    <td><?= htmlspecialchars($ln['label']) ?></td>
                    <td class="num"><?= number_format($ln['hours'], 2) ?></td>
                    <td class="num"><?= $money($ln['rate']) ?></td>
                    <td class="num"><?= $money($ln['gross']) ?></td>
                    <td class="num"><?= $money($ln['net']) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="totrow">
                <td colspan="3">TOTAL</td>
                <td class="num"><?= number_format($tot['hours'], 2) ?></td>
                <td class="num">&mdash;</td>
                <td class="num"><?= $money($tot['gross']) ?></td>
                <td class="num"><?= $money($tot['net']) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="split-note">
        <span style="color:#15803d;"><i class="fa fa-check-circle"></i> To Finalized: <?= $money($tot['fin']) ?></span>
        <span style="color:#b91c1c;"><i class="fa fa-clock"></i> To Balance (held): <?= $money($tot['bal']) ?></span>
    </div>

    <div class="ded">
        <div class="box">
            <h4>Employee Deductions (all lines)</h4>
            <div class="li"><span>CPP</span><span class="text-danger">&minus;<?= $money($tot['cpp']) ?></span></div>
            <div class="li"><span>EI</span><span class="text-danger">&minus;<?= $money($tot['ei']) ?></span></div>
            <div class="li"><span>Income Tax</span><span class="text-danger">&minus;<?= $money($tot['tax']) ?></span></div>
            <div class="li total"><span>Net take-home</span><span class="text-success"><?= $money($tot['net']) ?></span></div>
        </div>
        <div class="box">
            <h4>Employer Burden (all lines)</h4>
            <div class="li"><span>CPP match</span><span>+<?= $money($tot['cpp_er']) ?></span></div>
            <div class="li"><span>EI match (<?= $ei_mult ?>&times;)</span><span>+<?= $money($tot['ei_er']) ?></span></div>
            <div class="li"><span>WCB</span><span>+<?= $money($tot['wcb']) ?></span></div>
            <div class="li"><span>Vacation (<?= $vac_rate*100 ?>%)</span><span>+<?= $money($tot['vac']) ?></span></div>
            <div class="li total"><span>Total labour cost</span><span><?= $money($tot['gross'] + $tot['cpp_er'] + $tot['ei_er'] + $tot['wcb'] + $tot['vac']) ?></span></div>
        </div>
    </div>

    <form method="POST" action="save_split_payroll.php">
        <input type="hidden" name="confirm_save" value="1">
        <input type="hidden" name="attendance_id" value="<?= (int)$attendance_id ?>">
        <input type="hidden" name="kind_0" value="<?= htmlspecialchars($_POST['kind_0'] ?? 'regular') ?>">
        <?php foreach ($suffixes as $sfx):
            $hours = (float)($_POST['hours_' . $sfx] ?? 0);
            $rate  = (float)($_POST['rate_'  . $sfx] ?? 0);
            if ($hours <= 0 || $rate <= 0) continue;
        ?>
            <input type="hidden" name="hours_<?= htmlspecialchars($sfx) ?>" value="<?= htmlspecialchars($hours) ?>">
            <input type="hidden" name="rate_<?= htmlspecialchars($sfx) ?>"  value="<?= htmlspecialchars($rate) ?>">
            <input type="hidden" name="label_<?= htmlspecialchars($sfx) ?>" value="<?= htmlspecialchars($_POST['label_' . $sfx] ?? '') ?>">
            <?php if (isset($_POST['route_finalized_' . $sfx])): ?>
                <input type="hidden" name="route_finalized_<?= htmlspecialchars($sfx) ?>" value="1">
            <?php endif; ?>
        <?php endforeach; ?>

        <div class="actions">
            <a href="javascript:history.back()" class="btn-back"><i class="fa fa-arrow-left"></i> Back to edit</a>
            <button type="submit" class="btn-confirm"><i class="fa fa-check-circle"></i> Confirm &amp; Save</button>
        </div>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
