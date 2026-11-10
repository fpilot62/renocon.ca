<?php
/**
 * Path: /payroll_payment_status.php
 * Bulk payment status for one org and pay period. Admins only.
 * Payments go through pcr_apply_payment, so Payroll Center Paid / Net Remaining
 * stay in step. Finalized gross, CPP, EI, and tax are not edited.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] === '') {
    header('Location: login_kiosk.php?err=session_expired');
    exit;
}
$sess_level = (int)($_SESSION['user_level'] ?? 99);
if ($sess_level > 20) {
    http_response_code(403);
    exit('Payment Status is for admins only.');
}

ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';
require_once 'payroll_scope.php';
require_once __DIR__ . '/engine/attendance_integrity_lib.php';
if (is_file(__DIR__ . '/engine/org_modules_lib.php')) {
    require_once __DIR__ . '/engine/org_modules_lib.php';
}
require_once __DIR__ . '/engine/payroll_payment_status_lib.php';
ps_require_payroll_admin();
if (is_file(__DIR__ . '/engine/menu_generator.php')) {
    require_once __DIR__ . '/engine/menu_generator.php';
}

$actorId = (int)$_SESSION['user_id'];
$isSuper = function_exists('ps_is_superadmin') && ps_is_superadmin();
$homeOrg = function_exists('ps_selected_org') ? (int)ps_selected_org($pdo) : (int)($_SESSION['org_id'] ?? 0);

$orgs = [];
if ($isSuper) {
    try {
        $orgs = $pdo->query('SELECT id, org_name FROM organizations ORDER BY org_name ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $orgs = [];
    }
}
$requestedOrg = (int)($_POST['org_id'] ?? $_GET['org_id'] ?? 0);
if ($isSuper) {
    $allowed = [];
    foreach ($orgs as $o) {
        $allowed[(int)$o['id']] = true;
    }
    if ($requestedOrg > 0 && isset($allowed[$requestedOrg])) {
        $orgId = $requestedOrg;
    } else {
        $prefer = pstat_default_org_id($pdo);
        $orgId = isset($allowed[$prefer]) ? $prefer : ($homeOrg > 0 && isset($allowed[$homeOrg]) ? $homeOrg : (int)($orgs[0]['id'] ?? 0));
    }
} else {
    $orgId = $homeOrg;
    if ($requestedOrg > 0 && $requestedOrg !== $orgId) {
        http_response_code(403);
        exit('That organization is outside your access.');
    }
}
if ($orgId <= 0) {
    exit('No organization is selected.');
}

try {
    pstat_ensure_menu($pdo);
} catch (Throwable $e) {
    // The Payroll Center link still opens this page if the menu row cannot be added.
}

$periodMode = function_exists('sip_org_pay_period_mode') ? sip_org_pay_period_mode($pdo, $orgId) : 'semi_monthly';
$anchor = function_exists('sip_org_pay_period_anchor') ? sip_org_pay_period_anchor($pdo, $orgId) : '2024-01-01';
$periods = pstat_list_periods($periodMode, $anchor, date('Y-m-d'));
[$startIn, $endIn] = pstat_requested_period($_GET, $_POST, $periodMode, $anchor, date('Y-m-d'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_require_csrf();
}

function pstat_flash(int $orgId, string $start, string $end, string $text): void
{
    $_SESSION['pstat_flash'] = $text;
    header('Location: payroll_payment_status.php?org_id=' . $orgId . '&period=' . urlencode($start . '|' . $end) . '&from_date=' . urlencode($start) . '&to_date=' . urlencode($end));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['undo_batch'])) {
    try {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        if (isset($_POST['undo_last'])) {
            $batchId = pstat_latest_open_batch_id($pdo, $orgId);
            if ($batchId <= 0) {
                throw new RuntimeException('There is no save to undo.');
            }
        }
        $res = pstat_reverse_batch($pdo, $batchId, $orgId, $actorId);
        $undoMsg = 'Undid save #' . $res['batch_id'] . '. Reversed $' . number_format($res['amount'], 2) . ' across ' . (int)$res['payments'] . ' payment(s).';
        if (!empty($res['removed'])) {
            $undoMsg .= ' Removed ' . (int)$res['removed'] . ' name(s) that save had added.';
        }
        $undoMsg .= ' Existing wages and deductions were not changed.';
        pstat_flash($orgId, $startIn, $endIn, $undoMsg);
    } catch (Throwable $e) {
        pstat_flash($orgId, $startIn, $endIn, $e->getMessage());
    }
}

$flash = (string)($_SESSION['pstat_flash'] ?? '');
unset($_SESSION['pstat_flash']);
$warnings = [];

$q = $pdo->prepare(
    "SELECT f.id AS line_id, f.user_id, f.site_id, f.work_date, f.total_gross, f.paid_amount,
            f.cpp AS ee_cpp, f.ei AS ee_ei, f.income_tax AS ee_tax,
            u.Name AS employee_name, s.nick_name AS site_name
     FROM payroll_finalized f
     INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
     INNER JOIN sites s ON s.SId = f.site_id AND s.org_id = u.org_id
     WHERE f.work_date BETWEEN ? AND ?
       AND " . att_finalized_live_predicate($pdo, 'f') . "
     ORDER BY s.nick_name ASC, u.Name ASC, f.work_date ASC, f.id ASC"
);
$q->execute([$orgId, $startIn, $endIn]);
$groups = [];
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = pstat_row_key((int)$row['site_id'], (int)$row['user_id']);
    $rem = pcr_line_remaining((float)$row['total_gross'], (float)$row['ee_cpp'], (float)$row['ee_ei'], (float)$row['ee_tax'], (float)$row['paid_amount']);
    $net = round($rem + (float)$row['paid_amount'], 2);
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'key' => $key,
            'user_id' => (int)$row['user_id'],
            'site_id' => (int)$row['site_id'],
            'name' => (string)$row['employee_name'],
            'site' => (string)($row['site_name'] ?: 'Store'),
            'net' => 0.0,
            'paid' => 0.0,
            'remaining' => 0.0,
            'lines' => [],
        ];
    }
    $groups[$key]['net'] = round($groups[$key]['net'] + $net, 2);
    $groups[$key]['paid'] = round($groups[$key]['paid'] + (float)$row['paid_amount'], 2);
    $groups[$key]['remaining'] = round($groups[$key]['remaining'] + $rem, 2);
    $groups[$key]['lines'][] = [
        'id' => (int)$row['line_id'],
        'remaining' => $rem,
        'work_date' => (string)$row['work_date'],
    ];
}

$roster = [];
$sites = [];
try {
    $rs = $pdo->prepare('SELECT id, Name FROM users WHERE org_id = ? ORDER BY Name ASC');
    $rs->execute([$orgId]);
    $roster = $rs->fetchAll(PDO::FETCH_ASSOC);
    $ss = $pdo->prepare('SELECT SId, nick_name FROM sites WHERE org_id = ? ORDER BY nick_name ASC');
    $ss->execute([$orgId]);
    $sites = $ss->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $roster = [];
    $sites = [];
}
$nameToId = [];
$userNames = [];
foreach ($roster as $person) {
    $pid = (int)$person['id'];
    $userNames[$pid] = (string)$person['Name'];
    $lk = strtolower(trim((string)$person['Name']));
    if ($lk === '') {
        continue;
    }
    if (!array_key_exists($lk, $nameToId)) {
        $nameToId[$lk] = $pid;
    } elseif ($nameToId[$lk] !== $pid) {
        $nameToId[$lk] = 0;
    }
}
$siteIds = [];
$siteNames = [];
foreach ($sites as $siteRow) {
    $siteIds[(int)$siteRow['SId']] = true;
    $siteNames[(int)$siteRow['SId']] = (string)$siteRow['nick_name'];
}

$postedKeep = [];
$postedAdded = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment_status'])) {
    $postedRows = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
    $plans = [];
    $blocked = false;
    foreach ($postedRows as $key => $posted) {
        if (!is_array($posted) || (string)($posted['changed'] ?? '') !== '1') {
            continue;
        }
        $key = (string)$key;
        if (!isset($groups[$key])) {
            $warnings[] = 'A row is no longer in this period. Nothing was saved.';
            $blocked = true;
            continue;
        }
        $g = $groups[$key];
        $who = $g['name'] . ' (' . $g['site'] . ')';
        $date = pcr_valid_payment_date((string)($posted['paid_date'] ?? ''));
        if ($date === null) {
            $warnings[] = $who . ': enter a pay date between 2018 and two weeks from today.';
            $blocked = true;
            continue;
        }
        $calc = pstat_payment_amount((string)($posted['status'] ?? ''), (float)$g['remaining'], (float)($posted['amount'] ?? 0));
        if ($calc['warning']) {
            $warnings[] = $who . ': ' . $calc['warning'];
            $blocked = true;
            continue;
        }
        if ($calc['amount'] <= 0.009) {
            continue;
        }
        $alloc = pstat_allocate($calc['amount'], $g['lines']);
        $sum = 0.0;
        foreach ($alloc as $bit) {
            $sum += $bit['amount'];
        }
        if (abs(round($sum, 2) - $calc['amount']) > 0.02) {
            $warnings[] = $who . ': that amount is more than the shifts still owing. Nothing was saved.';
            $blocked = true;
            continue;
        }
        foreach ($alloc as $bit) {
            $plans[] = [
                'finalized_id' => $bit['id'],
                'amount' => $bit['amount'],
                'method' => pcr_payment_method($posted['method'] ?? ''),
                'paid_date' => $date,
                'reference' => (string)($posted['reference'] ?? ''),
                'note' => (string)($posted['note'] ?? ''),
                'user_id' => $g['user_id'],
                'site_id' => $g['site_id'],
                'employee_name' => $g['name'],
                'site_name' => $g['site'],
            ];
        }
    }
    $occupied = [];
    foreach ($groups as $key => $unused) {
        $occupied[(string)$key] = true;
    }
    $addedRows = is_array($_POST['added'] ?? null) ? $_POST['added'] : [];
    foreach ($addedRows as $added) {
        if (!is_array($added)) {
            continue;
        }
        $review = pstat_review_added($added, $nameToId, $userNames, $siteIds, $occupied);
        if (!empty($review['skip'])) {
            continue;
        }
        if (!empty($review['warning'])) {
            $warnings[] = $review['warning'];
            $blocked = true;
            continue;
        }
        $payDate = $review['pay_amount'] > 0.009 ? pcr_valid_payment_date((string)($added['paid_date'] ?? '')) : $startIn;
        $addedMethod = pstat_added_method($added['method'] ?? '');
        if ($review['pay_amount'] > 0.009 && $addedMethod === '') {
            $warnings[] = $review['employee_name'] . ': choose Cash, Cheque, or e-Transfer.';
            $blocked = true;
            continue;
        }
        $occupied[$review['slot']] = true;
        $plans[] = [
            'finalized_id' => 0,
            'amount' => $review['pay_amount'],
            'method' => $addedMethod,
            'paid_date' => $payDate ?: $startIn,
            'reference' => (string)($added['reference'] ?? ''),
            'note' => (string)($added['note'] ?? ''),
            'user_id' => $review['user_id'],
            'site_id' => $review['site_id'],
            'employee_name' => $review['employee_name'],
            'site_name' => $siteNames[$review['site_id']] ?? '',
            'owed' => $review['owed'],
            'manual' => [
                'user_id' => $review['user_id'],
                'new_name' => $review['new_name'],
                'employee_name' => $review['employee_name'],
                'site_id' => $review['site_id'],
                'owed' => $review['owed'],
            ],
        ];
    }
    if ($blocked) {
        $warnings[] = 'Nothing was saved. Fix the rows above and save again.';
        $postedKeep = $postedRows;
        $postedAdded = $addedRows;
    } elseif (!$plans) {
        $flash = 'No payment changes to save.';
    } else {
        try {
            $batchId = pstat_save_batch($pdo, $orgId, $startIn, $endIn, $actorId, $plans);
            if ($batchId <= 0) {
                $flash = 'Those rows were already paid. Nothing new was recorded.';
            } else {
                $total = 0.0;
                $addedCount = 0;
                foreach ($plans as $p) {
                    $total += (float)$p['amount'];
                    if (!empty($p['manual'])) {
                        $addedCount++;
                    }
                }
                $msg = 'Saved batch #' . $batchId . '. Recorded $' . number_format($total, 2) . '.';
                if ($addedCount > 0) {
                    $msg .= ' Added ' . $addedCount . ' name(s) for this period.';
                }
                $msg .= ' Payroll Center Paid and Net Remaining use these same payments.';
                pstat_flash($orgId, $startIn, $endIn, $msg);
            }
        } catch (Throwable $e) {
            $warnings[] = 'Save failed and was rolled back. ' . $e->getMessage();
            $postedKeep = $postedRows;
            $postedAdded = $addedRows;
        }
    }
}

$history = [];
$latestOpen = 0;
try {
    $history = pstat_batch_history($pdo, $orgId, 40);
    $latestOpen = pstat_latest_open_batch_id($pdo, $orgId);
} catch (Throwable $e) {
    $history = [];
}

$bySite = [];
foreach ($groups as $g) {
    $bySite[$g['site']][] = $g;
}
ksort($bySite);
$csrf = ps_csrf_token();
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Status</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f1f5f9; padding: 90px 16px 40px; color: #1e293b; font-size: 13px; }
        .sheet { background: #fff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
        table.pay td, table.pay th { vertical-align: middle; }
        table.pay th { background: #0f172a; color: #fff; font-size: 11px; text-transform: uppercase; }
        .site-row td { background: #e2e8f0; font-weight: 800; }
        .money { text-align: right; font-variant-numeric: tabular-nums; }
        .amt { width: 110px; text-align: right; }
        .mini { width: 130px; }
        .warn { background: #fff7ed; border: 1px solid #fdba74; }
        .name-pick { position: relative; }
        .suggest { position: absolute; z-index: 5; left: 0; right: 0; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; max-height: 180px; overflow: auto; display: none; }
        .suggest button { display: block; width: 100%; text-align: left; background: #fff; border: 0; padding: 6px 8px; }
        .suggest button:hover { background: #e0f2fe; }
        tr.add-row td { background: #f8fafc; }
    </style>
</head>
<body>
<?= function_exists('generateMenu') ? generateMenu($pdo) : '' ?>
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h4 class="font-weight-bold mb-0">Payment Status</h4>
            <p class="text-muted small mb-0">Enter who was paid for the period, including a person who is not already listed. This writes the same payment records Payroll Center uses. Existing finalized wages and deductions are not changed.</p>
        </div>
        <a class="btn btn-outline-dark font-weight-bold" href="payroll_master_ledger.php?org_id=<?= (int)$orgId ?>&from_date=<?= urlencode($startIn) ?>&to_date=<?= urlencode($endIn) ?>">Back to Payroll Center</a>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert alert-info py-2 font-weight-bold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if ($warnings): ?>
        <div class="alert warn py-2">
            <?php foreach ($warnings as $w): ?>
                <div><?= htmlspecialchars($w) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="GET" class="sheet">
        <div class="form-row align-items-end">
            <?php if ($isSuper): ?>
            <div class="col-md-4 mb-2">
                <label class="small font-weight-bold text-uppercase text-muted">Organization</label>
                <select name="org_id" class="form-control form-control-sm font-weight-bold" onchange="this.form.submit()">
                    <?php foreach ($orgs as $o): ?>
                        <option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === $orgId ? 'selected' : '' ?>><?= htmlspecialchars((string)$o['org_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
                <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
            <?php endif; ?>
            <div class="col-md-5 mb-2">
                <label class="small font-weight-bold text-uppercase text-muted">Pay period</label>
                <select name="period" class="form-control form-control-sm font-weight-bold" onchange="var p=this.value.split('|'); this.form.from_date.value=p[0]||''; this.form.to_date.value=p[1]||''; this.form.submit();">
                    <?php foreach ($periods as $per):
                        $sel = ($per['start'] === $startIn && $per['end'] === $endIn) ? ' selected' : '';
                    ?>
                        <option value="<?= htmlspecialchars($per['start'] . '|' . $per['end']) ?>"<?= $sel ?>><?= htmlspecialchars($per['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="from_date" value="<?= htmlspecialchars($startIn) ?>">
                <input type="hidden" name="to_date" value="<?= htmlspecialchars($endIn) ?>">
            </div>
            <div class="col-md-3 mb-2 small text-muted">Older periods are in this list, back to the organization’s pay-period anchor, so past payments can be entered.</div>
        </div>
    </form>

    <form method="POST" id="payStatusForm" class="sheet">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
        <input type="hidden" name="period_start" value="<?= htmlspecialchars($startIn) ?>">
        <input type="hidden" name="period_end" value="<?= htmlspecialchars($endIn) ?>">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
            <strong><?= htmlspecialchars(pstat_period_label($startIn, $endIn)) ?></strong>
            <button type="submit" name="save_payment_status" value="1" class="btn btn-primary font-weight-bold">Save payments</button>
        </div>
        <?php if (!$groups): ?>
            <p class="text-muted mb-0">No finalized employees in this period.</p>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm table-bordered pay mb-0">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th class="money">Net payable</th>
                    <th class="money">Already paid</th>
                    <th class="money">Remaining</th>
                    <th>Status</th>
                    <th>Amount this save</th>
                    <th>Method</th>
                    <th>Date</th>
                    <th>Cheque # / reference</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($bySite as $siteName => $emps): ?>
                <tr class="site-row"><td colspan="10"><?= htmlspecialchars($siteName) ?> · <?= count($emps) ?> employee(s)</td></tr>
                <?php foreach ($emps as $g):
                    $rem = (float)$g['remaining'];
                    $status = $rem <= 0.01 ? 'paid' : ((float)$g['paid'] > 0.01 ? 'partial' : 'unpaid');
                    $key = $g['key'];
                    $keep = (isset($postedKeep[$key]) && is_array($postedKeep[$key])) ? $postedKeep[$key] : null;
                    if ($keep && in_array((string)($keep['status'] ?? ''), ['unpaid', 'paid', 'partial'], true)) {
                        $status = (string)$keep['status'];
                    }
                    $amountValue = $status === 'paid' ? number_format($rem, 2, '.', '') : '';
                    if ($keep && $status === 'partial') {
                        $amountValue = (string)($keep['amount'] ?? '');
                    }
                    $methodValue = pcr_payment_method($keep['method'] ?? 'E-Transfer');
                    $dateValue = $keep ? (string)($keep['paid_date'] ?? $today) : $today;
                    $refValue = $keep ? (string)($keep['reference'] ?? '') : '';
                    $noteValue = $keep ? (string)($keep['note'] ?? '') : '';
                    $changedValue = $keep ? '1' : '0';
                ?>
                <tr class="emp-row" data-key="<?= htmlspecialchars($key) ?>" data-remaining="<?= htmlspecialchars(number_format($rem, 2, '.', '')) ?>" data-initial="<?= htmlspecialchars($status) ?>">
                    <td>
                        <?= htmlspecialchars($g['name']) ?>
                        <div class="text-muted small"><?= count($g['lines']) ?> shift(s)</div>
                        <input type="hidden" name="rows[<?= htmlspecialchars($key) ?>][changed]" value="<?= $changedValue ?>" class="changed-flag">
                    </td>
                    <td class="money">$<?= number_format((float)$g['net'], 2) ?></td>
                    <td class="money">$<?= number_format((float)$g['paid'], 2) ?></td>
                    <td class="money">$<?= number_format($rem, 2) ?></td>
                    <td>
                        <select name="rows[<?= htmlspecialchars($key) ?>][status]" class="form-control form-control-sm status-pick">
                            <option value="unpaid" <?= $status === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                            <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid in full</option>
                            <option value="partial" <?= $status === 'partial' ? 'selected' : '' ?>>Partially paid</option>
                        </select>
                    </td>
                    <td><input type="number" step="0.01" min="0" name="rows[<?= htmlspecialchars($key) ?>][amount]" class="form-control form-control-sm amt" value="<?= htmlspecialchars($amountValue) ?>" <?= $status === 'partial' ? '' : 'readonly' ?>></td>
                    <td>
                        <select name="rows[<?= htmlspecialchars($key) ?>][method]" class="form-control form-control-sm mini">
                            <?php foreach (pcr_payment_methods() as $methodOpt): ?>
                                <option <?= $methodValue === $methodOpt ? 'selected' : '' ?>><?= htmlspecialchars($methodOpt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="date" name="rows[<?= htmlspecialchars($key) ?>][paid_date]" class="form-control form-control-sm" value="<?= htmlspecialchars($dateValue) ?>"></td>
                    <td><input type="text" name="rows[<?= htmlspecialchars($key) ?>][reference]" class="form-control form-control-sm" maxlength="64" placeholder="Optional" value="<?= htmlspecialchars($refValue) ?>"></td>
                    <td><input type="text" name="rows[<?= htmlspecialchars($key) ?>][note]" class="form-control form-control-sm" maxlength="255" placeholder="Optional" value="<?= htmlspecialchars($noteValue) ?>"></td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <div class="mt-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
                <div>
                    <strong>Add name</strong>
                    <div class="text-muted small">Someone missing from this period. Search an existing employee, or type a new name and choose the store. Amount owed is their net for the period.</div>
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm font-weight-bold" id="addNameBtn">Add name</button>
            </div>
            <div class="table-responsive">
            <table class="table table-sm table-bordered pay mb-0">
                <thead>
                    <tr>
                        <th>Name and store</th>
                        <th>Amount owed</th>
                        <th>Status</th>
                        <th>Amount this save</th>
                        <th>Method</th>
                        <th>Date</th>
                        <th>Cheque # / reference</th>
                        <th>Note</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="addedBody">
                <?php
                $addedIndex = 0;
                foreach ($postedAdded as $added) {
                    if (!is_array($added) || pstat_added_is_blank($added)) {
                        continue;
                    }
                    $aStatus = (string)($added['status'] ?? 'unpaid');
                    if (!in_array($aStatus, ['unpaid', 'paid', 'partial'], true)) {
                        $aStatus = 'unpaid';
                    }
                    $aSite = (int)($added['site_id'] ?? 0);
                    $aUser = (int)($added['user_id'] ?? 0);
                    ?>
                    <tr class="add-row">
                        <td>
                            <div class="name-pick">
                                <input type="text" class="form-control form-control-sm added-name" name="added[<?= $addedIndex ?>][name]" value="<?= htmlspecialchars((string)($added['name'] ?? '')) ?>" placeholder="Search or type a name" autocomplete="off">
                                <input type="hidden" class="added-user" name="added[<?= $addedIndex ?>][user_id]" value="<?= $aUser ?>">
                                <div class="suggest"></div>
                            </div>
                            <select class="form-control form-control-sm mt-1" name="added[<?= $addedIndex ?>][site_id]">
                                <option value="0">Store</option>
                                <?php foreach ($sites as $siteRow): ?>
                                    <option value="<?= (int)$siteRow['SId'] ?>" <?= $aSite === (int)$siteRow['SId'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$siteRow['nick_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm amt added-owed" name="added[<?= $addedIndex ?>][owed]" value="<?= htmlspecialchars((string)($added['owed'] ?? '')) ?>"></td>
                        <td>
                            <select class="form-control form-control-sm added-status" name="added[<?= $addedIndex ?>][status]">
                                <option value="unpaid" <?= $aStatus === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                                <option value="paid" <?= $aStatus === 'paid' ? 'selected' : '' ?>>Paid in full</option>
                                <option value="partial" <?= $aStatus === 'partial' ? 'selected' : '' ?>>Partially paid</option>
                            </select>
                        </td>
                        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm amt added-amount" name="added[<?= $addedIndex ?>][amount]" value="<?= htmlspecialchars((string)($added['amount'] ?? '')) ?>" <?= $aStatus === 'partial' ? '' : 'readonly' ?>></td>
                        <td>
                            <select class="form-control form-control-sm mini" name="added[<?= $addedIndex ?>][method]">
                                <?php $aMethod = pstat_added_method($added['method'] ?? ''); ?>
                                <option value="" <?= $aMethod === '' ? 'selected' : '' ?>>Choose</option>
                                <?php foreach (pcr_payment_methods() as $methodOpt): ?>
                                    <option <?= $aMethod === $methodOpt ? 'selected' : '' ?>><?= htmlspecialchars($methodOpt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="date" class="form-control form-control-sm" name="added[<?= $addedIndex ?>][paid_date]" value="<?= htmlspecialchars((string)($added['paid_date'] ?? $today)) ?>"></td>
                        <td><input type="text" class="form-control form-control-sm" name="added[<?= $addedIndex ?>][reference]" maxlength="64" value="<?= htmlspecialchars((string)($added['reference'] ?? '')) ?>"></td>
                        <td><input type="text" class="form-control form-control-sm" name="added[<?= $addedIndex ?>][note]" maxlength="255" value="<?= htmlspecialchars((string)($added['note'] ?? '')) ?>"></td>
                        <td><button type="button" class="btn btn-sm btn-outline-secondary remove-add">Remove</button></td>
                    </tr>
                    <?php
                    $addedIndex++;
                }
                ?>
                </tbody>
            </table>
            </div>
        </div>
        <div class="text-right mt-3">
            <button type="submit" name="save_payment_status" value="1" class="btn btn-primary font-weight-bold">Save payments</button>
        </div>
    </form>

    <div class="sheet">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
            <h6 class="font-weight-bold mb-0">Save history</h6>
            <?php if ($latestOpen > 0): ?>
            <form method="POST" class="mb-0" onsubmit="return confirm('Undo the last payment save for this organization? Only those payments are reversed.');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
                <input type="hidden" name="period_start" value="<?= htmlspecialchars($startIn) ?>">
                <input type="hidden" name="period_end" value="<?= htmlspecialchars($endIn) ?>">
                <button class="btn btn-outline-danger btn-sm font-weight-bold" name="undo_batch" value="1">Undo last save</button>
                <input type="hidden" name="undo_last" value="1">
            </form>
            <?php endif; ?>
        </div>
        <?php if (!$history): ?>
            <p class="text-muted mb-0">No payment saves yet.</p>
        <?php else: ?>
        <table class="table table-sm mb-0">
            <thead><tr><th>#</th><th>When</th><th>Who</th><th>Period</th><th>Rows</th><th class="money">Amount</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td><?= (int)$h['id'] ?></td>
                    <td><?= htmlspecialchars((string)$h['created_at']) ?></td>
                    <td><?= htmlspecialchars((string)($h['actor_name'] ?: ('User #' . $h['created_by']))) ?></td>
                    <td><?= htmlspecialchars((string)$h['period_start']) ?> – <?= htmlspecialchars((string)$h['period_end']) ?></td>
                    <td><?= (int)$h['row_count'] ?></td>
                    <td class="money">$<?= number_format((float)$h['total_amount'], 2) ?></td>
                    <td>
                        <?php if (!empty($h['undone_at'])): ?>
                            <span class="text-muted">Undone <?= htmlspecialchars((string)$h['undone_at']) ?></span>
                        <?php else: ?>
                            <form method="POST" class="mb-0" onsubmit="return confirm('Reverse save #<?= (int)$h['id'] ?>? Only the payments in that save are removed from the amounts owing.');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
                                <input type="hidden" name="period_start" value="<?= htmlspecialchars($startIn) ?>">
                                <input type="hidden" name="period_end" value="<?= htmlspecialchars($endIn) ?>">
                                <input type="hidden" name="batch_id" value="<?= (int)$h['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" name="undo_batch" value="1">Reverse</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
    function mark(row) {
        var flag = row.querySelector('.changed-flag');
        if (flag) flag.value = '1';
    }
    document.querySelectorAll('.emp-row').forEach(function (row) {
        var status = row.querySelector('.status-pick');
        var amount = row.querySelector('.amt');
        var remaining = parseFloat(row.getAttribute('data-remaining') || '0');
        function applyStatus() {
            if (!status || !amount) return;
            if (status.value === 'paid') {
                amount.value = remaining.toFixed(2);
                amount.readOnly = true;
            } else if (status.value === 'unpaid') {
                amount.value = '';
                amount.readOnly = true;
            } else {
                amount.readOnly = false;
                if (amount.value === remaining.toFixed(2) && row.getAttribute('data-initial') !== 'partial') {
                    amount.value = '';
                }
            }
        }
        status.addEventListener('change', function () { applyStatus(); mark(row); });
        row.querySelectorAll('input, select').forEach(function (el) {
            el.addEventListener('change', function () { mark(row); });
        });
        applyStatus();
    });
    var form = document.getElementById('payStatusForm');
    if (!form) return;

    var roster = <?= json_encode(array_map(static function ($p) {
        return ['id' => (int)$p['id'], 'name' => (string)$p['Name']];
    }, $roster), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var siteOptions = <?= json_encode(array_map(static function ($s) {
        return ['id' => (int)$s['SId'], 'name' => (string)$s['nick_name']];
    }, $sites), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var addIndex = document.querySelectorAll('#addedBody .add-row').length;

    function siteSelectHtml(idx) {
        var html = '<select class="form-control form-control-sm mt-1" name="added['+idx+'][site_id]"><option value="0">Store</option>';
        siteOptions.forEach(function (s) {
            html += '<option value="'+s.id+'">'+String(s.name).replace(/[&<>"]/g, function (c) {
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];
            })+'</option>';
        });
        return html + '</select>';
    }

    function bindAdded(row) {
        var nameInput = row.querySelector('.added-name');
        var userInput = row.querySelector('.added-user');
        var suggest = row.querySelector('.suggest');
        var status = row.querySelector('.added-status');
        var owed = row.querySelector('.added-owed');
        var amount = row.querySelector('.added-amount');
        function syncAmount() {
            var owedVal = parseFloat(owed.value || '0');
            if (status.value === 'paid') {
                amount.value = owedVal > 0 ? owedVal.toFixed(2) : '';
                amount.readOnly = true;
            } else if (status.value === 'unpaid') {
                amount.value = '';
                amount.readOnly = true;
            } else {
                amount.readOnly = false;
            }
        }
        status.addEventListener('change', syncAmount);
        owed.addEventListener('input', function () { if (status.value === 'paid') syncAmount(); });
        nameInput.addEventListener('input', function () {
            userInput.value = '0';
            var q = nameInput.value.trim().toLowerCase();
            suggest.innerHTML = '';
            if (q.length < 1) { suggest.style.display = 'none'; return; }
            var hits = roster.filter(function (p) { return p.name.toLowerCase().indexOf(q) !== -1; }).slice(0, 8);
            if (!hits.length) { suggest.style.display = 'none'; return; }
            hits.forEach(function (p) {
                var b = document.createElement('button');
                b.type = 'button';
                b.textContent = p.name;
                b.addEventListener('click', function () {
                    nameInput.value = p.name;
                    userInput.value = String(p.id);
                    suggest.style.display = 'none';
                });
                suggest.appendChild(b);
            });
            suggest.style.display = 'block';
        });
        row.querySelector('.remove-add').addEventListener('click', function () { row.remove(); });
        syncAmount();
    }

    document.querySelectorAll('#addedBody .add-row').forEach(bindAdded);
    document.getElementById('addNameBtn').addEventListener('click', function () {
        var idx = addIndex++;
        var tr = document.createElement('tr');
        tr.className = 'add-row';
        tr.innerHTML =
            '<td><div class="name-pick"><input type="text" class="form-control form-control-sm added-name" name="added['+idx+'][name]" placeholder="Search or type a name" autocomplete="off"><input type="hidden" class="added-user" name="added['+idx+'][user_id]" value="0"><div class="suggest"></div></div>'+siteSelectHtml(idx)+'</td>'+
            '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm amt added-owed" name="added['+idx+'][owed]"></td>'+
            '<td><select class="form-control form-control-sm added-status" name="added['+idx+'][status]"><option value="unpaid">Unpaid</option><option value="paid">Paid in full</option><option value="partial">Partially paid</option></select></td>'+
            '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm amt added-amount" name="added['+idx+'][amount]" readonly></td>'+
            '<td><select class="form-control form-control-sm mini" name="added['+idx+'][method]"><option value="">Choose</option><option>Cash</option><option>Cheque</option><option>E-Transfer</option></select></td>'+
            '<td><input type="date" class="form-control form-control-sm" name="added['+idx+'][paid_date]" value="<?= htmlspecialchars($today) ?>"></td>'+
            '<td><input type="text" class="form-control form-control-sm" name="added['+idx+'][reference]" maxlength="64"></td>'+
            '<td><input type="text" class="form-control form-control-sm" name="added['+idx+'][note]" maxlength="255"></td>'+
            '<td><button type="button" class="btn btn-sm btn-outline-secondary remove-add">Remove</button></td>';
        document.getElementById('addedBody').appendChild(tr);
        bindAdded(tr);
        tr.querySelector('.added-name').focus();
    });

    form.addEventListener('submit', function (ev) {
        var n = 0, total = 0, added = 0, problems = [];
        document.querySelectorAll('.emp-row').forEach(function (row) {
            if (row.querySelector('.changed-flag').value !== '1') return;
            var name = row.querySelector('td').innerText.split('\n')[0];
            var status = row.querySelector('.status-pick').value;
            var remaining = parseFloat(row.getAttribute('data-remaining') || '0');
            var amt = parseFloat(row.querySelector('.amt').value || '0');
            if (status === 'paid') amt = remaining;
            if (status === 'unpaid') return;
            if (amt > remaining + 0.009) {
                problems.push(name + ': $' + amt.toFixed(2) + ' is more than the $' + remaining.toFixed(2) + ' still owing.');
                return;
            }
            if (amt > 0.009) { n++; total += amt; }
        });
        document.querySelectorAll('#addedBody .add-row').forEach(function (row) {
            var name = (row.querySelector('.added-name').value || '').trim();
            var owed = parseFloat(row.querySelector('.added-owed').value || '0');
            var status = row.querySelector('.added-status').value;
            var amt = parseFloat(row.querySelector('.added-amount').value || '0');
            if (!name && owed <= 0) return;
            if (!name) { problems.push('Add name: type a name or pick an employee.'); return; }
            if (!(owed > 0)) { problems.push(name + ': enter the amount owed.'); return; }
            if (row.querySelector('select[name$="[site_id]"]').value === '0') { problems.push(name + ': choose a store.'); return; }
            if (status === 'paid') amt = owed;
            var methodSel = row.querySelector('select[name$="[method]"]');
            if (status !== 'unpaid' && amt > 0.009 && methodSel && !methodSel.value) {
                problems.push(name + ': choose Cash, Cheque, or e-Transfer.');
                return;
            }
            if (status !== 'unpaid' && amt > owed + 0.009) {
                problems.push(name + ': $' + amt.toFixed(2) + ' is more than the $' + owed.toFixed(2) + ' owed.');
                return;
            }
            added++;
            if (status !== 'unpaid' && amt > 0.009) { n++; total += amt; }
        });
        if (problems.length) {
            ev.preventDefault();
            alert('Nothing will be saved.\n\n' + problems.join('\n'));
            return;
        }
        if (n === 0 && added === 0) return;
        var msg = 'Record ' + n + ' payment(s) totalling $' + total.toFixed(2);
        if (added) msg += ' and add ' + added + ' name(s)';
        msg += '? Already-paid amounts are not recorded again.';
        if (!confirm(msg)) ev.preventDefault();
    });
})();
</script>
</body>
</html>
