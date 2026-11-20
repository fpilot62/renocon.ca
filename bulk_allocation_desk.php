<?php
/**
 * Path: /bulk_allocation_desk.php
 * BULK FINALIZE DESK — rebuilt to use the SAME save routine as the per-row finalize.
 *
 * Flow:
 *   1) Audit page posts selected shift ids -> this screen shows a config panel:
 *        - Regular hours CAP
 *        - Remainder routing dropdown: Regular only / Overtime / Travel / Balance
 *      plus a review list of the selected shifts.
 *   2) Submit -> for each shift: split at the cap, route the remainder per the dropdown,
 *      compute FULL deductions at the flat rates, write each line into its own hours
 *      column and correct table, set attendance.payout_status=1.
 *   3) Redirect to the new master ledger on the pay period.
 *
 * Routing (cap splits regular from remainder; dropdown decides what the remainder is):
 *   regular-only : all hours -> reg_hours        -> payroll_finalized
 *   overtime     : <=cap reg  + remainder*1.5    -> overtime_hours -> payroll_finalized
 *   travel       : <=cap reg  + remainder@rate   -> travel_hours   -> payroll_finalized
 *   balance      : <=cap reg finalized; remainder@rate -> balance_hours -> payroll_balance (HELD)
 *
 * Deductions use the same flat rates as the per-row saver so bulk and single agree.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';
require_once __DIR__ . '/engine/attendance_audit_lib.php';
if (file_exists('engine/menu_generator.php')) { require_once 'engine/menu_generator.php'; }
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (is_file(__DIR__ . '/break_waiver_finalize_guard.php')) {
    require_once __DIR__ . '/break_waiver_finalize_guard.php';
}
if (!isset($_SESSION['user_id'])) { die("Access Denied."); }

$org_id = (int)($_SESSION['org_id'] ?? 1);

// ---- single source of truth for all payroll math ----------------------------
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
require_once __DIR__ . '/engine/org_modules_lib.php';
$pe_rates = pe_rates($pdo);

// deduction helper delegates to the engine; vac rate is passed PER SHIFT.
// 'tax' key kept for backward-compat with the insert code below.
$deductions_for = function (float $gross, float $vac_rate, array $shift = []) use ($pe_rates, $pdo) {
    $d = pe_line($gross, $vac_rate, $pe_rates, [
        'pdo' => $pdo,
        'site_id' => (int)($shift['site_id'] ?? 0),
        'org_id' => (int)($shift['org_id'] ?? 0),
        'user_id' => (int)($shift['user_id'] ?? 0),
    ]);
    return [
        'cpp'    => $d['cpp'],
        'ei'     => $d['ei'],
        'tax'    => $d['income_tax'],
        'cpp_er' => $d['cpp_er'],
        'ei_er'  => $d['ei_er'],
        'wcb'    => $d['wcb'],
        'vac'    => $d['vac'],
    ];
};

$process_completed = false;
$redirect_start = $redirect_end = null;

function ba_collect_ids(array $src): array
{
    $ids = [];
    $csv = (string)($src['record_ids_csv'] ?? '');
    if ($csv !== '') {
        foreach (preg_split('/[,\s]+/', $csv) as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
    }
    foreach ((array)($src['record_ids'] ?? []) as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function ba_resolve_split(array $in, ?PDO $pdo = null, int $org_id = 0): array
{
    $formula = strtolower(trim((string)($in['target_action_formula'] ?? '')));
    $routeIn = strtolower(trim((string)($in['remainder_route'] ?? '')));
    $map = [
        'balance' => 'balance',
        'overtime' => 'overtime',
        'transit' => 'travel',
        'travel' => 'travel',
        'regular' => 'regular',
    ];
    // Desk dropdown (remainder_route / regular_cap) wins when present so a
    // changed select is not overwritten by stale modal hiddens if JS lags.
    $route = 'regular';
    if ($routeIn !== '' && isset($map[$routeIn])) {
        $route = $map[$routeIn];
    } elseif ($formula !== '' && isset($map[$formula])) {
        $route = $map[$formula];
    }
    $cap = null;
    if (isset($in['regular_cap']) && $in['regular_cap'] !== '') {
        $cap = (float)$in['regular_cap'];
    } elseif (isset($in['regular_hours_limit']) && $in['regular_hours_limit'] !== '') {
        $cap = (float)$in['regular_hours_limit'];
    }
    if ($cap === null) {
        $fallback = 12.0;
        if ($pdo instanceof PDO && function_exists('sip_org_bulk_hours_cap')) {
            $fallback = sip_org_bulk_hours_cap($pdo, $org_id);
        }
        $cap = ($route === 'regular') ? 80.0 : $fallback;
    }
    if ($cap < 0) {
        $cap = 0.0;
    }
    $ot = strtolower(trim((string)($in['ot_mode'] ?? 'per_shift')));
    if ($ot === '') {
        $ot = 'per_shift';
    }
    return [$route, $cap, $ot];
}

function ba_fail_to_audit(PDO $pdo, string $msg): void
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Bulk Finalize Desk</title></head>';
    echo '<body style="font-family:system-ui,sans-serif;padding:40px;max-width:640px;">';
    echo '<p style="color:#b91c1c;font-weight:800;">Bulk finalize stopped. This screen did not pay anyone.</p>';
    echo '<p>' . htmlspecialchars($msg) . '</p>';
    echo '<p>Open Staff Audit and check Pending vs Finalized before running again.</p>';
    echo '<p><a href="staff_audit.php">Return to Staff Audit</a></p>';
    echo '</body></html>';
    exit;
}

// execute_bulk_pay_matrix only opens this review desk. It must not finalize.
// =========================================================================
// EXECUTE
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'execute_bulk') {
    $target_ids = ba_collect_ids($_POST);
    [$route, $cap, $otMode] = ba_resolve_split($_POST, $pdo, $org_id);
    unset($otMode);
    if (empty($target_ids)) { die("No shifts selected."); }

    $period_refresh = [];
    try {
        if (function_exists('pe_ensure_user_rate_history')) {
            pe_ensure_user_rate_history($pdo);
        }
        $pdo->beginTransaction();

        $getShift = $pdo->prepare("SELECT a.*, u.hourly_rate AS profile_hourly_rate, u.Name AS staff_name FROM attendance a JOIN users u ON a.user_id = u.id WHERE a.id = ?");
        $getBreaks = $pdo->prepare("SELECT break_start, break_end FROM breaks WHERE shift_id = ?");

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
        $closeShift = $pdo->prepare("UPDATE attendance SET payout_status = 1 WHERE id = ?");

        // per-shift vacation rate, set at the top of each shift iteration (varies by employee/company)
        $shift_vac_rate = 0.04;

        // helper to push one finalized line; category defaults to pay_type unless overridden
        $writeFin = function ($shift, $work_date, $pp_end, $label, $cols, $rate, $gross) use ($insFin, $deductions_for, &$shift_vac_rate) {
            $d = $deductions_for($gross, $shift_vac_rate, $shift);
            $category = $cols['category'] ?? $cols['pay_type'];
            $store_gross = isset($d['pay_gross']) ? (float)$d['pay_gross'] : $gross;
            $insFin->execute([
                $shift['id'], $category, $shift['user_id'], $shift['org_id'], $shift['site_id'], $work_date, $pp_end, $label,
                $cols['reg'], $cols['ot'], $cols['travel'], $cols['bal'], $rate, $cols['reg_pay'], $store_gross,
                $d['cpp'], $d['ei'], $d['tax'], $d['cpp_er'], $d['ei_er'], $d['wcb'], $d['vac'],
                $cols['pay_type'], (int)$_SESSION['user_id'],
            ]);
        };

        foreach ($target_ids as $sid) {
            $getShift->execute([$sid]);
            $s = $getShift->fetch(PDO::FETCH_ASSOC);
            if (!$s) continue;

            // net worked hours (minus breaks)
            $in  = strtotime($s['clock_in']);
            $out = (!empty($s['clock_out']) && $s['clock_out'] != '0000-00-00 00:00:00') ? strtotime($s['clock_out']) : time();
            $getBreaks->execute([$sid]);
            $brk = 0;
            foreach ($getBreaks->fetchAll(PDO::FETCH_ASSOC) as $b) {
                if (!empty($b['break_end'])) { $brk += abs(strtotime($b['break_end']) - strtotime($b['break_start'])); }
            }
            $total_hours = max(0, ($out - $in - $brk) / 3600);
            // ALWAYS the employee profile rate. Never punch/attendance rate or $19 default.
            $rate = pe_profile_hourly_rate($pdo, (int)$s['user_id']); // 0.00 if profile empty — never a $19/$20 default

            $work_date = !empty($s['clock_in']) ? date('Y-m-d', strtotime($s['clock_in'])) : date('Y-m-d');
            $dom = (int)date('j', strtotime($work_date));
            $pp_end   = ($dom <= 15) ? date('Y-m-15', strtotime($work_date)) : date('Y-m-t', strtotime($work_date));
            $pp_start = ($dom <= 15) ? date('Y-m-01', strtotime($work_date)) : date('Y-m-16', strtotime($work_date));
            if ($redirect_start === null) { $redirect_start = $pp_start; $redirect_end = $pp_end; }

            // per-company ESA vacation rate for THIS shift's employee + company, as of work_date
            $shift_vac_rate = pe_vacation_rate($pdo, (int)$s['user_id'], (int)$s['site_id'], $work_date);

            // TRAVEL AUTO-DETECT: process_punch.php writes transit segments as status='5'.
            // Such a shift is entirely travel — route all hours to travel_hours, category Travel,
            // regardless of the cap/dropdown. (The dropdown 'travel' option is the separate case
            // of sending a NORMAL shift's overflow to travel.)
            $is_travel = (trim((string)($s['status'] ?? '')) === '5');

            if ($is_travel) {
                if ($total_hours > 0) {
                    $g = round($total_hours * $rate, 2);
                    $writeFin($s, $work_date, $pp_end, 'Travel / Transit Segment',
                        ['reg'=>0,'ot'=>0,'travel'=>$total_hours,'bal'=>0,'reg_pay'=>0,'pay_type'=>'Travel','category'=>'Travel'], $rate, $g);
                }
                $aaBefore = aa_snapshot($pdo, (int)$sid);
                $closeShift->execute([$sid]);
                aa_log($pdo, 'finalize', (int)$sid, $aaBefore, null, 'Bulk allocation desk');
                continue; // travel shift fully handled
            }

            // split at cap (normal shifts)
            if ($route === 'regular') {
                // no split: whole shift regular
                $reg_h = $total_hours; $rem_h = 0;
            } else {
                $reg_h = min($total_hours, $cap);
                $rem_h = max(0, $total_hours - $cap);
            }

            // --- regular portion (always finalized) ---
            if ($reg_h > 0) {
                $g = round($reg_h * $rate, 2);
                $writeFin($s, $work_date, $pp_end, 'Regular Core Payroll Line',
                    ['reg'=>$reg_h,'ot'=>0,'travel'=>0,'bal'=>0,'reg_pay'=>$g,'pay_type'=>'Regular'], $rate, $g);
            }

            // --- remainder portion (per dropdown) ---
            if ($rem_h > 0) {
                if ($route === 'overtime') {
                    $ot_rate = $rate * 1.5;
                    $g = round($rem_h * $ot_rate, 2);
                    $writeFin($s, $work_date, $pp_end, 'Overtime (1.5x) Premium',
                        ['reg'=>0,'ot'=>$rem_h,'travel'=>0,'bal'=>0,'reg_pay'=>0,'pay_type'=>'Overtime'], $ot_rate, $g);
                } elseif ($route === 'travel') {
                    $g = round($rem_h * $rate, 2);
                    $writeFin($s, $work_date, $pp_end, 'Travel / Corridor Time',
                        ['reg'=>0,'ot'=>0,'travel'=>$rem_h,'bal'=>0,'reg_pay'=>0,'pay_type'=>'Travel','category'=>'Travel'], $rate, $g);
                } elseif ($route === 'balance') {
                    // HELD in payroll_balance, full deductions
                    $g = round($rem_h * $rate, 2);
                    $d = $deductions_for($g, $shift_vac_rate, $s);
                    $insBal->execute([
                        $s['id'], $s['user_id'], $s['org_id'], $s['site_id'], $work_date, $pp_end,
                        $rem_h, $rate, $g,
                        $d['cpp'], $d['ei'], $d['wcb'], $d['vac'],
                        'Balance', 'Balance Desk Remaining (held)',
                    ]);
                }
            }

            $aaBefore = aa_snapshot($pdo, (int)$sid);
            $closeShift->execute([$sid]);
            aa_log($pdo, 'finalize', (int)$sid, $aaBefore, null, 'Bulk allocation desk');
            $period_refresh[(int)$s['user_id'] . '|' . (int)$s['site_id'] . '|' . $work_date] = [
                (int)$s['user_id'], (int)$s['site_id'], $work_date
            ];
        }

        if (!$pdo->inTransaction()) {
            throw new RuntimeException('The database closed the batch before commit. Check Staff Audit Pending vs Finalized.');
        }
        $pdo->commit();
        $process_completed = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        ba_fail_to_audit($pdo, $e->getMessage());
    }

    if ($process_completed) {
        foreach ($period_refresh as $pr) {
            try {
                pe_refresh_period_stat($pdo, $pr[0], $pr[1], $pr[2]);
            } catch (Throwable $e) {
                error_log('bulk_allocation_desk period refresh: ' . $e->getMessage());
            }
        }
        $rs = $redirect_start ?: date('Y-m-01');
        $re = $redirect_end   ?: date('Y-m-t');
        $n = count($target_ids);
        $split = ($route === 'balance')
            ? ('First ' . rtrim(rtrim(number_format((float)$cap, 2), '0'), '.') . 'h Regular; remainder held on Balance. Not bank-paid.')
            : 'Batch locked. Not bank-paid.';
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Bulk Finalize Desk</title></head>';
        echo '<body style="font-family:system-ui,sans-serif;padding:40px;max-width:640px;">';
        echo '<p style="color:#166534;font-weight:800;">Finalize finished for ' . (int)$n . ' shift(s).</p>';
        echo '<p>' . htmlspecialchars($split) . '</p>';
        echo '<p><a href="staff_audit.php">Return to Staff Audit</a></p>';
        echo '</body></html>';
        exit;
    }
}

// =========================================================================
// LOAD selected shifts for the config screen
// =========================================================================
$incoming = ba_collect_ids($_POST + $_GET);
[$desk_route, $desk_cap, $desk_ot] = ba_resolve_split($_POST + $_GET, $pdo, $org_id);

$shifts = [];
if (!empty($incoming)) {
    $ph = implode(',', array_fill(0, count($incoming), '?'));
    $q = $pdo->prepare(
        "SELECT a.id, u.Name AS staff_name, COALESCE(s.nick_name,'Unassigned') AS site_name,
                a.clock_in, a.clock_out, u.hourly_rate AS rate
         FROM attendance a
         JOIN users u ON a.user_id = u.id
         LEFT JOIN sites s ON a.site_id = s.Sid
         WHERE a.id IN ($ph)"
    );
    $q->execute($incoming);
    $shifts = $q->fetchAll(PDO::FETCH_ASSOC);
}

// compute display hours per shift
$brkQ = $pdo->prepare("SELECT break_start, break_end FROM breaks WHERE shift_id = ?");
foreach ($shifts as &$sh) {
    $in  = strtotime($sh['clock_in']);
    $out = (!empty($sh['clock_out']) && $sh['clock_out'] != '0000-00-00 00:00:00') ? strtotime($sh['clock_out']) : time();
    $brkQ->execute([$sh['id']]);
    $bs = 0;
    foreach ($brkQ->fetchAll(PDO::FETCH_ASSOC) as $b) {
        if (!empty($b['break_end'])) { $bs += abs(strtotime($b['break_end']) - strtotime($b['break_start'])); }
    }
    $sh['hours'] = max(0, ($out - $in - $bs) / 3600);
}
unset($sh);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Bulk Finalize Desk</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family:'Inter',system-ui,sans-serif; background:#f1f5f9; padding:40px 20px; color:#1e293b; font-size:13px; }
        .wrap { max-width:1100px; margin:0 auto; }
        .back-link { color:#475569; font-weight:700; font-size:11px; text-transform:uppercase; text-decoration:none; display:inline-flex; gap:6px; margin-bottom:12px; }
        .card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:26px; display:grid; grid-template-columns:1.05fr 0.95fr; gap:28px; box-shadow:0 4px 8px rgba(0,0,0,0.03); }
        .col-left { border-right:1px dashed #cbd5e1; padding-right:22px; }
        h2 { margin:0 0 4px; font-size:18px; font-weight:800; color:#0f172a; }
        .subtitle { color:#64748b; font-size:12px; margin:0 0 22px; }
        .ig { margin-bottom:18px; }
        .ig label { display:block; font-weight:800; font-size:10px; text-transform:uppercase; color:#475569; letter-spacing:.5px; margin-bottom:6px; }
        .ig select, .ig input { width:100%; max-width:440px; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; height:40px; box-sizing:border-box; background:#fff; }
        .hint { font-size:11px; color:#64748b; margin-top:6px; max-width:440px; }
        .btn-go { background:#2563eb; color:#fff; border:none; font-weight:800; font-size:13px; padding:13px 26px; border-radius:7px; cursor:pointer; text-transform:uppercase; display:inline-flex; align-items:center; gap:8px; box-shadow:0 4px 12px rgba(37,99,235,.25); }
        .btn-go:hover { background:#1d4ed8; }
        .btn-go:disabled { background:#cbd5e1; cursor:not-allowed; box-shadow:none; }
        h5 { margin:0 0 12px; font-size:11px; font-weight:800; color:#475569; text-transform:uppercase; letter-spacing:.5px; }
        .list { max-height:460px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; }
        .row { display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-bottom:1px solid #e2e8f0; }
        .row:last-child { border-bottom:none; }
        .meta { font-weight:700; color:#0f172a; font-size:12px; }
        .meta small { display:block; font-weight:500; color:#64748b; font-size:9px; margin-top:1px; }
        .hbadge { background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; padding:3px 9px; border-radius:5px; font-weight:800; font-size:11px; }
        .preview-split { font-size:9px; color:#64748b; display:block; margin-top:2px; }
        .empty { text-align:center; padding:40px; color:#94a3b8; font-style:italic; }
    </style>
</head>
<body>
<div class="wrap">
    <a href="staff_audit.php" class="back-link"><i class="fas fa-arrow-left"></i> Cancel &amp; return to audit</a>
    <div class="card">
        <div class="col-left">
            <h2><i class="fas fa-layer-group" style="color:#2563eb;"></i> Bulk Finalize Desk</h2>
            <p class="subtitle">Pay rate is always taken from the employee profile (not the punch). Set one split rule; it applies to every selected shift and runs the same finalize routine (full deductions, per-type hours). For a shift that needs different handling, use its individual Finalize link instead.</p>

            <form method="POST" action="" id="baDeskForm">
                <input type="hidden" name="action" value="execute_bulk">
                <input type="hidden" name="ot_mode" id="otModeHidden" value="<?= htmlspecialchars($desk_ot) ?>">
                <input type="hidden" name="target_action_formula" id="formulaHidden" value="<?= htmlspecialchars($desk_route === 'travel' ? 'transit' : $desk_route) ?>">
                <input type="hidden" name="regular_hours_limit" id="limitHidden" value="<?= htmlspecialchars((string)$desk_cap) ?>">
                <input type="hidden" name="record_ids_csv" value="<?= htmlspecialchars(implode(',', $incoming)) ?>">

                <div class="ig">
                    <label>Remainder Routing (what hours past the cap become)</label>
                    <select name="remainder_route" id="routeSel" onchange="refreshHints()">
                        <option value="regular" <?= $desk_route === 'regular' ? 'selected' : '' ?>>Regular only — no split, all hours at regular rate</option>
                        <option value="overtime" <?= $desk_route === 'overtime' ? 'selected' : '' ?>>Overtime — remainder at 1.5&times; (finalized)</option>
                        <option value="travel" <?= $desk_route === 'travel' ? 'selected' : '' ?>>Travel — remainder at regular rate (finalized)</option>
                        <option value="balance" <?= $desk_route === 'balance' ? 'selected' : '' ?>>Balance — remainder held in balance desk</option>
                    </select>
                    <div class="hint" id="routeHint">Hours over the cap go to Balance at regular rate (not overtime).</div>
                </div>

                <div class="ig" id="capWrap" style="<?= $desk_route === 'regular' ? 'display:none;' : '' ?>">
                    <label>Regular Hours Cap (hours up to this are regular; the rest follows the routing above)</label>
                    <input type="number" step="0.01" min="0" name="regular_cap" id="capInput" value="<?= htmlspecialchars(number_format((float)$desk_cap, 2, '.', '')) ?>" oninput="refreshHints()">
                </div>

                <div style="margin-top:30px;">
                    <button type="submit" name="action" value="execute_bulk" class="btn-go" <?=empty($shifts)?'disabled':''?>>
                        <i class="fas fa-bolt"></i> Finalize <?=count($shifts)?> Shift(s)
                    </button>
                </div>
            </form>
        </div>

        <div class="col-right">
            <h5>Selected Shifts (<?=count($shifts)?>)</h5>
            <div class="list">
                <?php if (!empty($shifts)): foreach ($shifts as $sh): ?>
                    <div class="row">
                        <div class="meta">
                            <?=htmlspecialchars($sh['staff_name'])?>
                            <small><i class="far fa-building"></i> <?=htmlspecialchars($sh['site_name'])?> · #<?=$sh['id']?></small>
                        </div>
                        <div style="text-align:right;">
                            <span style="font-weight:700; font-size:11px; color:#475569; display:block;">$<?=number_format((float)$sh['rate'],2)?>/h</span>
                            <span class="hbadge"><?=number_format($sh['hours'],2)?>h</span>
                            <span class="preview-split" data-hours="<?=round($sh['hours'],2)?>"></span>
                        </div>
                    </div>
                <?php endforeach; else: ?>
                    <div class="empty">No shifts selected. Go back to the audit screen and tick some rows.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function refreshHints() {
    var route = document.getElementById('routeSel').value;
    var capWrap = document.getElementById('capWrap');
    var hint = document.getElementById('routeHint');
    var cap = parseFloat(document.getElementById('capInput').value) || 0;
    var formula = document.getElementById('formulaHidden');
    var limit = document.getElementById('limitHidden');
    if (formula) formula.value = (route === 'travel') ? 'transit' : route;
    if (limit) limit.value = cap;

    if (route === 'regular') {
        capWrap.style.display = 'none';
        hint.textContent = 'All hours are paid as regular. The cap is ignored.';
    } else {
        capWrap.style.display = 'block';
        var dest = {overtime:'overtime at 1.5\u00d7 (finalized)', travel:'travel at regular rate (finalized)', balance:'Balance desk at regular rate (not overtime)'}[route];
        hint.textContent = 'Up to ' + cap + 'h is Regular (Hrs A); anything beyond becomes ' + dest + '. Overnight stays one punch.';
    }
    // per-shift split preview
    document.querySelectorAll('.preview-split').forEach(function(el){
        var h = parseFloat(el.getAttribute('data-hours')) || 0;
        if (route === 'regular') { el.textContent = h.toFixed(2) + 'h regular'; return; }
        var reg = Math.min(h, cap), rem = Math.max(0, h - cap);
        var lbl = {overtime:'OT', travel:'travel', balance:'balance'}[route];
        el.textContent = reg.toFixed(2) + 'h reg' + (rem > 0 ? ' + ' + rem.toFixed(2) + 'h ' + lbl : '');
    });
}
document.addEventListener('DOMContentLoaded', refreshHints);
var deskForm = document.getElementById('baDeskForm');
if (deskForm) {
    deskForm.addEventListener('submit', function (ev) {
        var n = <?= (int)count($shifts) ?>;
        var route = document.getElementById('routeSel').value;
        var cap = document.getElementById('capInput').value;
        var msg = 'Finalize ' + n + ' shift(s)? This locks them into payroll. It does not bank-pay.';
        if (route === 'balance') {
            msg += ' First ' + cap + 'h Regular; hours over the cap go to Balance at regular rate.';
        }
        if (!confirm(msg)) ev.preventDefault();
    });
}
</script>
</body>
</html>
