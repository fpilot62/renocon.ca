<?php
/**
 * Path: /manual_pay_entry.php
 * Enter one unpaid pay for an employee already on file. Deductions come from
 * the T4127 engine (pe_line, then pe_refresh_period_stat). Nothing is marked paid.
 *
 * The province is the site's province. A blank TD1 uses the basic personal amount.
 */
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
require_once __DIR__ . '/payroll_engine/page_gate.php';

$isPost = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
[$pdo, $orgId, $isSuper, $actor] = pe_page_org('manual_pay_entry.php', $isPost ? 'update' : 'view');
if (is_file(__DIR__ . '/engine/menu_generator.php')) {
    require_once __DIR__ . '/engine/menu_generator.php';
}
try {
    if (pe_has_table($pdo, 'payroll_rate_packs')) {
        pe_install_rates($pdo);
    }
} catch (Throwable $e) {
}

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$money = static fn($v) => '$' . number_format((float)$v, 2);

$provSql = pe_has_column($pdo, 'sites', 'province') ? 'province' : "''";
$sites = $pdo->prepare('SELECT SId, nick_name, ' . $provSql . ' AS province, ' . (pe_has_column($pdo, 'sites', 'wsib_percent') ? 'wsib_percent' : 'NULL') . ' AS wsib_percent FROM sites WHERE org_id = ? ORDER BY nick_name');
$sites->execute([$orgId]);
$siteRows = $sites->fetchAll(PDO::FETCH_ASSOC);
$siteIds = array_map(static fn($s) => (int)$s['SId'], $siteRows);

$active = pe_has_column($pdo, 'users', 'active') ? ' AND active = 1' : '';
$people = $pdo->prepare('SELECT id, Name FROM users WHERE org_id = ?' . $active . ' ORDER BY Name');
$people->execute([$orgId]);
$employees = $people->fetchAll(PDO::FETCH_ASSOC);

$mode = pe_period_mode_for_site($pdo, 0, $orgId);
$anchor = pe_period_anchor_for_org($pdo, $orgId);
$periods = [];
$cursor = date('Y-m-d');
for ($i = 0; $i < 8; $i++) {
    $periods[] = pe_period_window($cursor, $mode, $anchor);
    $cursor = date('Y-m-d', strtotime($periods[$i][0] . ' -1 day'));
}

$siteId = (int)($_POST['site_id'] ?? $_GET['site_id'] ?? 0);
$userId = (int)($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
$periodRaw = (string)($_POST['period'] ?? ($periods[0][0] . '|' . $periods[0][1]));
[$from, $to] = array_pad(explode('|', $periodRaw, 2), 2, '');
$payDate = pe_ymd((string)($_POST['pay_date'] ?? $to));
if ($payDate === '') {
    $payDate = pe_ymd($to) ?: date('Y-m-d');
}
$regHours = (string)($_POST['reg_hours'] ?? '');
$otHours = (string)($_POST['ot_hours'] ?? '');
$grossRaw = (string)($_POST['gross'] ?? '');
$vacRaw = (string)($_POST['vacation_rate'] ?? '');
$bonusRaw = (string)($_POST['bonus'] ?? '');
$message = '';
$error = '';
$preview = null;

$window = pe_ymd($from) !== '' ? pe_period_window($from, $mode, $anchor) : ['', ''];
$periodOk = $from === ($window[0] ?? '') && $to === ($window[1] ?? '');

if ($isPost) {
    if (!in_array($siteId, $siteIds, true)) {
        $error = 'That store is not in this organization.';
    } elseif ($userId <= 0 || !pe_employee_in_org($pdo, $userId, $orgId)) {
        $error = 'That employee is not in this organization.';
    } elseif (!$periodOk) {
        $error = 'Pick one of this organization\'s pay periods.';
    } else {
        $reg = pe_hours($regHours);
        $ot = pe_hours($otHours);
        $grossTyped = pe_money_input($grossRaw);
        $bonus = pe_money_input($bonusRaw);
        if ($reg === null || $ot === null || $grossTyped === null || $bonus === null) {
            $error = 'Hours and amounts have to be numbers.';
        } elseif ($reg + $ot > 400) {
            $error = 'More than 400 hours in one period.';
        } else {
            $regRate = pe_profile_pay_rate($pdo, $userId, 'regular', $to);
            $otRate = pe_profile_pay_rate($pdo, $userId, 'overtime', $to);
            if ($grossTyped > 0) {
                $base = $grossTyped;
            } else {
                if (($reg > 0 && $regRate <= 0) || ($ot > 0 && $otRate <= 0)) {
                    $error = 'No pay rate is on file for that employee.';
                }
                $base = round($reg * $regRate + $ot * $otRate, 2);
            }
            if ($error === '' && $base <= 0 && $bonus <= 0) {
                $error = 'Enter hours or a gross amount.';
            }
            if ($error === '') {
                $vacRate = $vacRaw === '' ? pe_vacation_rate($pdo, $userId, $siteId, $to) : pe_normalize_rate((float)$vacRaw);
                $preview = pe_line($base, $vacRate, null, [
                    'pdo' => $pdo,
                    'org_id' => $orgId,
                    'site_id' => $siteId,
                    'user_id' => $userId,
                    'work_date' => $to,
                    'pay_date' => $payDate,
                    'bonus' => $bonus,
                    'ignore_saved_lines' => true,
                ]);
                if (empty($preview['ok'])) {
                    $error = $preview['error'] ?: 'Deductions could not be calculated.';
                    $preview = null;
                } elseif (($_POST['action'] ?? '') === 'save') {
                    $pdo->beginTransaction();
                    try {
                        $id = pe_insert_known($pdo, 'payroll_finalized', [
                            'attendance_id' => null,
                            'user_id' => $userId,
                            'org_id' => $orgId,
                            'site_id' => $siteId,
                            'work_date' => $to,
                            'pay_period' => $from,
                            'pay_period_end' => $to,
                            'display_label' => 'Manual pay ' . $from . ' to ' . $to,
                            'category' => 'Manual',
                            'pay_type' => 'Manual',
                            'reg_hours' => $reg,
                            'overtime_hours' => $ot,
                            'hourly_rate' => $regRate,
                            'total_gross' => $preview['pay_gross'],
                            'vac' => $preview['vac'],
                            'cpp' => $preview['cpp'],
                            'cpp2' => $preview['cpp2'],
                            'ei' => $preview['ei'],
                            'income_tax' => $preview['income_tax'],
                            'cpp_er' => $preview['cpp_er'],
                            'cpp2_er' => $preview['cpp2_er'],
                            'ei_er' => $preview['ei_er'],
                            'wcb' => $preview['wcb'],
                            'eht' => $preview['eht'],
                            'pensionable_earnings' => $preview['pensionable'],
                            'insurable_earnings' => $preview['insurable'],
                            'deduction_edition' => $preview['edition'],
                            'status' => 'pending',
                            'payment_status' => 'Unpaid',
                            'paid_amount' => 0,
                            'finalized_by' => $actor,
                            'comments' => 'manual-pay-entry',
                        ]);
                        $refresh = pe_refresh_period_stat($pdo, $userId, $siteId, $to);
                        $pdo->commit();
                        $message = 'Saved unpaid line #' . $id . '. Nothing was marked paid.';
                        if (!empty($refresh['locked'])) {
                            $message .= ' Existing payments in the period were left as they were.';
                        }
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $error = 'Nothing was saved.';
                    }
                }
            }
        }
    }
}

function pe_employee_in_org(PDO $pdo, int $userId, int $orgId): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND org_id = ?');
    $st->execute([$userId, $orgId]);
    return (int)$st->fetchColumn() === 1;
}
function pe_hours(string $raw): ?float
{
    $raw = trim(str_replace([',', ' '], '', $raw));
    if ($raw === '') {
        return 0.0;
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
        return null;
    }
    return round((float)$raw, 2);
}
function pe_money_input(string $raw): ?float
{
    $raw = trim(str_replace(['$', ',', ' '], '', $raw));
    if ($raw === '') {
        return 0.0;
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
        return null;
    }
    return round((float)$raw, 2);
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Manual pay</title>
<style>
body { font-family: Georgia, serif; margin: 24px; color: #1c1915; background: #f7f4ee; }
form, .card { background: #fff; border: 1px solid #e4dccb; padding: 16px; max-width: 760px; }
label { display: block; margin: 8px 0 2px; }
input, select { padding: 6px; width: 100%; max-width: 420px; }
table { border-collapse: collapse; margin-top: 12px; }
td { padding: 4px 12px 4px 0; }
.err { color: #8a1f1f; }
.ok { color: #1d4a32; }
button { margin-right: 8px; padding: 8px 12px; }
</style>
</head>
<body>
<h1>Manual pay</h1>
<p>This saves an unpaid line. It does not mark anyone paid and it does not file a T4.</p>
<?php if ($error): ?><p class="err"><?= $h($error) ?></p><?php endif; ?>
<?php if ($message): ?><p class="ok"><?= $h($message) ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= $h(function_exists('privacy_csrf_token') ? privacy_csrf_token() : '') ?>">
<input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
<label>Store</label>
<select name="site_id" required>
<option value="">Choose</option>
<?php foreach ($siteRows as $s): ?>
<option value="<?= (int)$s['SId'] ?>" <?= (int)$s['SId'] === $siteId ? 'selected' : '' ?>><?= $h($s['nick_name']) ?> (<?= $h($s['province'] ?: 'no province') ?><?= $s['wsib_percent'] === null || $s['wsib_percent'] === '' ? '' : ', WCB ' . $h($s['wsib_percent']) . '%' ?>)</option>
<?php endforeach; ?>
</select>
<label>Employee</label>
<select name="user_id" required>
<option value="">Choose</option>
<?php foreach ($employees as $e): ?>
<option value="<?= (int)$e['id'] ?>" <?= (int)$e['id'] === $userId ? 'selected' : '' ?>><?= $h($e['Name']) ?></option>
<?php endforeach; ?>
</select>
<label>Pay period (<?= $h(str_replace('_', ' ', $mode)) ?>)</label>
<select name="period">
<?php foreach ($periods as $p): $key = $p[0] . '|' . $p[1]; ?>
<option value="<?= $h($key) ?>" <?= $key === $periodRaw ? 'selected' : '' ?>><?= $h($p[0] . ' to ' . $p[1]) ?></option>
<?php endforeach; ?>
</select>
<label>Pay date (picks the January or July rate edition)</label>
<input name="pay_date" value="<?= $h($payDate) ?>">
<label>Regular hours</label>
<input name="reg_hours" value="<?= $h($regHours) ?>">
<label>Overtime hours</label>
<input name="ot_hours" value="<?= $h($otHours) ?>">
<label>Or a gross amount, before vacation</label>
<input name="gross" value="<?= $h($grossRaw) ?>">
<label>Vacation rate (blank uses the rate on file; 4 or 0.04 both mean 4%)</label>
<input name="vacation_rate" value="<?= $h($vacRaw) ?>">
<label>Lump-sum bonus or vacation payout (taxed with the bonus method)</label>
<input name="bonus" value="<?= $h($bonusRaw) ?>">
<p>
<button name="action" value="preview">Preview</button>
<button name="action" value="save">Save unpaid</button>
</p>
</form>
<?php if ($preview): ?>
<div class="card">
<p><?= $h($preview['edition']) ?> · <?= $h($preview['province'] ?: 'no province') ?></p>
<table>
<tr><td>Gross before vacation</td><td><?= $money($preview['pay_gross']) ?></td></tr>
<tr><td>Vacation <?= $preview['vacation_paid'] ? '(paid this period)' : '(accrued, not in this cheque)' ?></td><td><?= $money($preview['vac']) ?></td></tr>
<tr><td>CPP</td><td><?= $money($preview['cpp']) ?></td></tr>
<tr><td>CPP2</td><td><?= $money($preview['cpp2']) ?></td></tr>
<tr><td>EI</td><td><?= $money($preview['ei']) ?></td></tr>
<tr><td>Income tax</td><td><?= $money($preview['income_tax']) ?></td></tr>
<tr><td>Net</td><td><?= $money($preview['net']) ?></td></tr>
<tr><td>Employer CPP / CPP2</td><td><?= $money($preview['cpp_er']) ?> / <?= $money($preview['cpp2_er']) ?></td></tr>
<tr><td>Employer EI</td><td><?= $money($preview['ei_er']) ?></td></tr>
<tr><td>WSIB / WCB</td><td><?= $money($preview['wcb']) ?></td></tr>
<tr><td>Ontario EHT on this pay</td><td><?= $money($preview['eht']) ?></td></tr>
</table>
<?php foreach ($preview['warnings'] as $w): ?><p class="err"><?= $h($w) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>
</body>
</html>
