<?php
/**
 * Path: /manual_pay_entry.php
 * Manual Pay / Adjustments — bonuses, termination, travel lumps, wage adjustments,
 * and off-payroll vouchers (reimbursements).
 *
 * Include in payroll = Yes  → CRA pe_line → payroll_finalized → paystub / T4
 * Include in payroll = No   → register only → printable payment voucher (not T4)
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll_scope.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
if (file_exists(__DIR__ . '/engine/menu_generator.php')) {
    require_once __DIR__ . '/engine/menu_generator.php';
}
ps_require_payroll_admin();

$orgId = ps_selected_org($pdo);
$sites = ps_list_sites($pdo, $orgId);
$users = ps_list_users($pdo, $orgId);
$csrf = ps_csrf_token();

$REASONS = [
    'adjustment' => 'Adjustment (deducted)',
    'benefit' => 'Benefit',
    'bonus' => 'Bonus',
    'car_allowance' => 'Car allowance',
    'travel' => 'Travel',
    'termination' => 'Termination pay',
    'other' => 'Other',
];

function mpe_has_col(PDO $pdo, string $table, string $col): bool {
    static $cache = [];
    $k = $table . '.' . $col;
    if (isset($cache[$k])) return $cache[$k];
    try {
        $q = $pdo->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
        );
        $q->execute([$table, $col]);
        $cache[$k] = (bool)$q->fetchColumn();
    } catch (Throwable $e) {
        $cache[$k] = false;
    }
    return $cache[$k];
}

function mpe_reason_label(string $reason, ?string $other): string {
    $map = [
        'adjustment' => 'Adjustment',
        'benefit' => 'Benefit',
        'bonus' => 'Bonus',
        'car_allowance' => 'Car allowance',
        'travel' => 'Travel',
        'termination' => 'Termination pay',
        'other' => 'Other',
    ];
    $base = $map[$reason] ?? ucfirst(str_replace('_', ' ', $reason));
    $typed = trim((string)$other);
    if ($typed !== '') {
        return $base . ' — ' . $typed;
    }
    return $base;
}

function mpe_entry_cols(PDO $pdo): array {
    static $cols = null;
    if ($cols !== null) {
        return $cols;
    }
    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM payroll_manual_entries') as $c) {
        $cols[$c['Field']] = true;
    }
    $add = [
        'frequency' => 'VARCHAR(20) NULL',
        'period_count' => 'INT NULL',
        'period_no' => 'INT NULL',
    ];
    foreach ($add as $col => $def) {
        if (empty($cols[$col])) {
            $pdo->exec('ALTER TABLE payroll_manual_entries ADD COLUMN `' . $col . '` ' . $def);
            $cols[$col] = true;
        }
    }
    return $cols;
}

function mpe_shift_date(string $start, ?string $frequency, int $step): string {
    $dt = DateTime::createFromFormat('!Y-m-d', $start);
    if (!$dt || $step <= 0 || $frequency === null || $frequency === '') {
        return $start;
    }
    if ($frequency === 'monthly') {
        $dt->modify('+' . $step . ' months');
    } else {
        $dt->modify('+' . ($step * 14) . ' days');
    }
    return $dt->format('Y-m-d');
}

function mpe_pay_period_end(string $workDate): string {
    $dom = (int)date('j', strtotime($workDate));
    return ($dom <= 15)
        ? date('Y-m-15', strtotime($workDate))
        : date('Y-m-t', strtotime($workDate));
}

/** @return array{id:int,include:int} */
function mpe_insert_line(PDO $pdo, int $orgId, int $siteId, int $userId, string $entryDate, array $line, int $actorId): array {
    $reason = (string)$line['reason'];
    $amount = (float)$line['amount'];
    $include = (int)$line['include'];
    $remarks = (string)$line['remarks'];
    $typed = trim((string)$line['other']);
    $label = $typed !== '' ? $typed : mpe_reason_label($reason, null);
    $isDeduction = ($reason === 'adjustment');
    $cols = mpe_entry_cols($pdo);
    $fields = 'org_id, site_id, user_id, entry_date, amount, reason, reason_other, include_in_payroll, remarks, status, created_by';
    $marks = '?,?,?,?,?,?,?,?,?,\'posted\',?';
    $params = [
        $orgId, $siteId, $userId, $entryDate, $amount, $reason,
        ($typed !== '' ? $typed : null),
        $include, ($remarks !== '' ? $remarks : null),
        $actorId,
    ];
    if (!empty($cols['frequency'])) {
        $fields .= ', frequency, period_count, period_no';
        $marks .= ',?,?,?';
        $params[] = $line['frequency'];
        $params[] = (int)$line['period_count'];
        $params[] = (int)$line['period_no'];
    }
    $ins = $pdo->prepare("INSERT INTO payroll_manual_entries ($fields) VALUES ($marks)");
    $ins->execute($params);
    $entryId = (int)$pdo->lastInsertId();

    if ($include === 1) {
        $ppEnd = mpe_pay_period_end($entryDate);
        $comment = ($remarks !== '' ? $remarks : 'Manual pay entry #' . $entryId);
        if ($line['period_count'] > 1) {
            $comment .= ' · period ' . (int)$line['period_no'] . ' of ' . (int)$line['period_count'];
        }
        if ($isDeduction) {
            $d = ['cpp' => 0, 'cpp2' => 0, 'ei' => 0, 'income_tax' => 0, 'cpp_er' => 0, 'cpp2_er' => 0, 'ei_er' => 0, 'wcb' => 0, 'vac' => 0];
            $storedGross = -1 * $amount;
            $category = 'Deduction';
            $bonusPay = 0;
        } else {
            $d = pe_line($amount, pe_vacation_rate($pdo, $userId, $siteId, $entryDate), pe_rates($pdo), [
                'pdo' => $pdo,
                'user_id' => $userId,
                'site_id' => $siteId,
                'org_id' => $orgId,
                'work_date' => $entryDate,
                'nonperiodic' => in_array($reason, ['bonus', 'termination'], true),
            ]);
            $storedGross = $amount;
            if ($reason === 'travel') $category = 'Travel';
            elseif ($reason === 'bonus') $category = 'Bonus';
            elseif ($reason === 'car_allowance') $category = 'Car allowance';
            elseif ($reason === 'benefit') $category = 'Benefit';
            else $category = 'Manual';
            $bonusPay = ($reason === 'bonus' ? $amount : 0);
        }
        $payType = mpe_reason_label($reason, $typed);
        $hasCpp2 = mpe_has_col($pdo, 'payroll_finalized', 'cpp2');
        $cpp2 = (float)($d['cpp2'] ?? 0);
        $cpp2Er = (float)($d['cpp2_er'] ?? 0);

        if ($hasCpp2) {
            $insFin = $pdo->prepare(
                "INSERT INTO payroll_finalized
                    (attendance_id, category, user_id, org_id, site_id, work_date, pay_period_end, display_label,
                     reg_hours, overtime_hours, travel_hours, balance_hours, hourly_rate, reg_pay, bonus_pay, total_gross,
                     cpp, cpp2, ei, income_tax, cpp_er, cpp2_er, ei_er, wcb, vac,
                     country_code, tax_label, safety_label, pay_type, status, payment_status, paid_amount, finalized_by, comments)
                 VALUES (NULL,?,?,?,?,?,?,?, 0,0,0,0,0,0,?,?, ?,?,?,?,?,?,?,?,?, 'CA','EI/CPP','WCB',?, 'pending','Unpaid',0, ?, ?)"
            );
            $insFin->execute([
                $category, $userId, $orgId, $siteId, $entryDate, $ppEnd, $label,
                $bonusPay, $storedGross,
                $d['cpp'], $cpp2, $d['ei'], $d['income_tax'], $d['cpp_er'], $cpp2Er, $d['ei_er'], $d['wcb'], $d['vac'],
                $payType, $actorId, $comment,
            ]);
        } else {
            $insFin = $pdo->prepare(
                "INSERT INTO payroll_finalized
                    (attendance_id, category, user_id, org_id, site_id, work_date, pay_period_end, display_label,
                     reg_hours, overtime_hours, travel_hours, balance_hours, hourly_rate, reg_pay, bonus_pay, total_gross,
                     cpp, ei, income_tax, cpp_er, ei_er, wcb, vac,
                     country_code, tax_label, safety_label, pay_type, status, payment_status, paid_amount, finalized_by, comments)
                 VALUES (NULL,?,?,?,?,?,?,?, 0,0,0,0,0,0,?,?, ?,?,?,?,?,?,?, 'CA','EI/CPP','WCB',?, 'pending','Unpaid',0, ?, ?)"
            );
            $insFin->execute([
                $category, $userId, $orgId, $siteId, $entryDate, $ppEnd, $label,
                $bonusPay, $storedGross,
                $d['cpp'], $d['ei'], $d['income_tax'], $d['cpp_er'], $d['ei_er'], $d['wcb'], $d['vac'],
                $payType, $actorId, $comment,
            ]);
        }
        $finalizedId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE payroll_manual_entries SET finalized_id = ? WHERE id = ?')
            ->execute([$finalizedId, $entryId]);
        if (function_exists('pe_refresh_period_stat')) {
            pe_refresh_period_stat($pdo, $userId, $siteId, $entryDate);
        }
        if (!$isDeduction && !in_array($reason, ['bonus', 'termination', 'car_allowance', 'benefit'], true)
            && is_file(__DIR__ . '/payroll_engine/stat_holidays.php')) {
            require_once __DIR__ . '/payroll_engine/stat_holidays.php';
            sh_after_finalize($pdo, $userId, $siteId, $entryDate, $actorId);
        }
    }

    return ['id' => $entryId, 'include' => $include];
}

try { mpe_entry_cols($pdo); } catch (Throwable $e) {}

$flash = '';
$flashErr = '';

// ---------- POST: create ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'post_entry') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $flashErr = 'Invalid session token. Reload and try again.';
    } else {
        $entryDate = (string)($_POST['entry_date'] ?? '');
        $siteId = (int)($_POST['site_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $amounts = $_POST['amount'] ?? [];
        $reasons = $_POST['reason'] ?? [];
        $others = $_POST['typed_reason'] ?? [];
        $includes = $_POST['include_in_payroll'] ?? [];
        $remarksIn = $_POST['remarks'] ?? [];
        $freqs = $_POST['frequency'] ?? [];
        $periodCounts = $_POST['periods'] ?? [];
        if (!is_array($amounts)) {
            $amounts = [$amounts];
            $reasons = [$reasons];
            $others = [$others];
            $includes = [$includes];
            $remarksIn = [$remarksIn];
            $freqs = [$freqs];
            $periodCounts = [$periodCounts];
        }

        $lines = [];
        $lineErrors = [];
        foreach (array_values($amounts) as $i => $amountRaw) {
            $n = $i + 1;
            $amount = round((float)$amountRaw, 2);
            $reason = strtolower(trim((string)($reasons[$i] ?? '')));
            $reasonOther = trim((string)($others[$i] ?? ''));
            $include = ((string)($includes[$i] ?? '1') === '1') ? 1 : 0;
            $remarks = trim((string)($remarksIn[$i] ?? ''));
            $recurring = in_array($reason, ['adjustment', 'benefit'], true);
            $frequency = strtolower(trim((string)($freqs[$i] ?? '')));
            $periods = (int)($periodCounts[$i] ?? 1);
            if ($amount <= 0) {
                $lineErrors[] = "Line {$n}: amount must be greater than zero.";
            } elseif (!isset($REASONS[$reason])) {
                $lineErrors[] = "Line {$n}: choose a type.";
            } elseif ($reasonOther === '') {
                $lineErrors[] = "Line {$n}: type the reason.";
            } elseif ($recurring && !in_array($frequency, ['biweekly', 'monthly'], true)) {
                $lineErrors[] = "Line {$n}: choose bi-weekly or monthly.";
            } elseif ($recurring && ($periods < 1 || $periods > 26)) {
                $lineErrors[] = "Line {$n}: number of periods must be from 1 to 26.";
            } else {
                if (!$recurring) {
                    $frequency = null;
                    $periods = 1;
                }
                $lines[] = [
                    'amount' => $amount,
                    'reason' => $reason,
                    'other' => $reasonOther,
                    'include' => $include,
                    'remarks' => $remarks,
                    'frequency' => $frequency,
                    'period_count' => $periods,
                    'period_no' => 1,
                ];
            }
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
            $flashErr = 'Valid date is required.';
        } elseif ($siteId <= 0 || $userId <= 0) {
            $flashErr = 'Site and employee are required.';
        } elseif ($lineErrors) {
            $flashErr = implode(' ', $lineErrors);
        } elseif (!$lines) {
            $flashErr = 'Add at least one line with an amount.';
        } else {
            try {
                ps_assert_site($pdo, $siteId, $orgId);
                $uq = $pdo->prepare('SELECT id, org_id, Name FROM users WHERE id = ? AND org_id = ? LIMIT 1');
                $uq->execute([$userId, $orgId]);
                $emp = $uq->fetch(PDO::FETCH_ASSOC);
                if (!$emp) {
                    throw new RuntimeException('Employee is outside this organization.');
                }

                $pdo->beginTransaction();
                $posted = [];
                foreach ($lines as $line) {
                    $count = max(1, (int)$line['period_count']);
                    for ($p = 0; $p < $count; $p++) {
                        $one = $line;
                        $one['period_no'] = $p + 1;
                        $date = mpe_shift_date($entryDate, $line['frequency'], $p);
                        $posted[] = mpe_insert_line($pdo, $orgId, $siteId, $userId, $date, $one, (int)$_SESSION['user_id']);
                    }
                }
                $pdo->commit();

                $ids = array_map(static fn(array $row): string => '#' . $row['id'], $posted);
                $payrollN = count(array_filter($posted, static fn(array $row): bool => $row['include'] === 1));
                $voucherN = count($posted) - $payrollN;
                if (count($posted) === 1 && $payrollN === 1) {
                    $flash = 'Posted to payroll (entry ' . $ids[0] . '). Open Payroll Center to pay and print the paystub.';
                } elseif (count($posted) === 1) {
                    $flash = 'Posted off-payroll voucher (entry ' . $ids[0] . '). Print the voucher below — it is not on a paystub or T4.';
                } else {
                    $flash = 'Posted ' . count($posted) . ' lines (' . implode(', ', $ids) . ').';
                    if ($payrollN > 0) {
                        $flash .= ' Open Payroll Center for the payroll lines.';
                    }
                    if ($voucherN > 0) {
                        $flash .= ' Print a voucher for each off-payroll line.';
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $flashErr = 'Save failed: ' . $e->getMessage();
            }
        }
    }
}

// ---------- POST: void ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void_entry') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $flashErr = 'Invalid session token.';
    } else {
        $vid = (int)($_POST['entry_id'] ?? 0);
        try {
            $q = $pdo->prepare('SELECT * FROM payroll_manual_entries WHERE id = ? AND org_id = ? LIMIT 1');
            $q->execute([$vid, $orgId]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['status'] === 'void') {
                $flashErr = 'Entry not found or already void.';
            } else {
                $pdo->beginTransaction();
                if (!empty($row['finalized_id'])) {
                    $pdo->prepare('DELETE FROM payroll_finalized WHERE id = ? AND org_id = ?')
                        ->execute([(int)$row['finalized_id'], $orgId]);
                }
                $pdo->prepare(
                    "UPDATE payroll_manual_entries
                     SET status='void', voided_at=NOW(), voided_by=?, finalized_id=NULL
                     WHERE id=? AND org_id=?"
                )->execute([(int)$_SESSION['user_id'], $vid, $orgId]);
                $pdo->commit();
                $flash = "Entry #{$vid} voided" . (!empty($row['finalized_id']) ? ' and removed from payroll.' : '.');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $flashErr = 'Void failed: ' . $e->getMessage();
        }
    }
}

// Recent list
$list = [];
try {
    $lq = $pdo->prepare(
        "SELECT e.*, u.Name AS emp_name, s.nick_name AS site_name
         FROM payroll_manual_entries e
         JOIN users u ON u.id = e.user_id
         JOIN sites s ON s.SId = e.site_id
         WHERE e.org_id = ?
         ORDER BY e.id DESC
         LIMIT 40"
    );
    $lq->execute([$orgId]);
    $list = $lq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $flashErr = $flashErr ?: ('List unavailable — run migration: ' . $e->getMessage());
}

$defaultDate = date('Y-m-d');
$preUser = (int)($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
$preSite = (int)($_POST['site_id'] ?? $_GET['site_id'] ?? 0);
$preDateRaw = (string)($_POST['entry_date'] ?? $_GET['entry_date'] ?? '');
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $preDateRaw)) {
    $defaultDate = $preDateRaw;
}
$preUserRow = null;
$preSiteRow = null;
foreach ($users as $u) {
    if ((int)$u['id'] === $preUser) { $preUserRow = $u; break; }
}
foreach ($sites as $s) {
    if ((int)$s['SId'] === $preSite) { $preSiteRow = $s; break; }
}
$wantLock = (($_POST['lock_identity'] ?? $_GET['lock'] ?? '') === '1');
$lockIdentity = $wantLock && $preUserRow && $preSiteRow;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manual Pay / Adjustments</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background:#f1f5f9; font-family:'Segoe UI',system-ui,sans-serif; color:#334155; font-size:13px; }
        .wrap { max-width:980px; margin:24px auto 60px; }
        .card-box { background:#fff; border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,.06); padding:22px 24px; margin-bottom:18px; }
        h2 { font-size:18px; font-weight:800; color:#0f172a; margin:0 0 4px; }
        .sub { color:#64748b; font-size:12px; margin-bottom:16px; }
        label { font-weight:700; font-size:11px; text-transform:uppercase; color:#475569; }
        .hint { font-size:11px; color:#64748b; margin-top:4px; }
        table { font-size:12px; }
        th { font-size:10px; text-transform:uppercase; background:#475569; color:#fff; }
        .badge-yes { background:#dcfce7; color:#15803d; }
        .badge-no { background:#fef3c7; color:#92400e; }
        .badge-void { background:#fee2e2; color:#b91c1c; }
        .badge-posted { background:#e0e7ff; color:#3730a3; }
        .mpe-line { border:1px solid #e2e8f0; border-radius:8px; padding:12px 12px 4px; margin-bottom:10px; background:#f8fafc; }
        .mpe-line-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
        .mpe-line-label { font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; }
        .mpe-recur { display:none; }
        .mpe-remove { visibility:hidden; }
    </style>
</head>
<body>
<?= function_exists('generateMenu') ? generateMenu($pdo) : '' ?>
<div class="wrap">
    <?php require_once __DIR__ . '/payroll_help.php'; echo payroll_help_panel('manual_pay_entry.php'); ?>

    <div class="card-box" id="mpeFormCard">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
                <h2 id="mpeTitle"><i class="fa fa-hand-holding-usd text-primary"></i> Manual Pay / Adjustments</h2>
                <div class="sub">Choose a type, then type the reason. An adjustment is deducted from pay. A benefit is added. Adjustment and benefit can repeat bi-weekly or monthly for a number of periods. Add another line for a second amount on the same person.</div>
            </div>
            <a href="payroll_master_ledger.php" class="btn btn-sm btn-outline-secondary font-weight-bold">← Payroll Center</a>
        </div>

        <?php if ($flash): ?><div class="alert alert-success py-2"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
        <?php if ($flashErr): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

        <form method="POST" id="mpeForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="post_entry">
            <?php if (ps_is_superadmin()): ?>
                <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
            <?php endif; ?>

            <?php if ($lockIdentity): ?>
                <input type="hidden" name="lock_identity" value="1">
                <input type="hidden" name="site_id" value="<?= (int)$preSiteRow['SId'] ?>">
                <input type="hidden" name="user_id" value="<?= (int)$preUserRow['id'] ?>">
            <?php endif; ?>
            <div class="form-row">
                <div class="form-group col-md-3" id="mpeDate">
                    <label for="entry_date">Date</label>
                    <input type="date" class="form-control" name="entry_date" id="entry_date" required value="<?= htmlspecialchars($defaultDate) ?>">
                </div>
                <div class="form-group col-md-4" id="mpeSite">
                    <label for="site_id">Site</label>
                    <?php if ($lockIdentity): ?>
                        <input type="text" class="form-control" id="site_id" value="<?= htmlspecialchars($preSiteRow['nick_name']) ?>" readonly style="background:#f8fafc;">
                    <?php else: ?>
                    <select class="form-control" name="site_id" id="site_id" required>
                        <option value="">— Select site —</option>
                        <?php foreach ($sites as $s): ?>
                            <option value="<?= (int)$s['SId'] ?>"<?= ((int)$s['SId'] === $preSite) ? ' selected' : '' ?>><?= htmlspecialchars($s['nick_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
                <div class="form-group col-md-5" id="mpeEmployee">
                    <label for="user_id">Employee</label>
                    <?php if ($lockIdentity): ?>
                        <input type="text" class="form-control" id="user_id" value="<?= htmlspecialchars($preUserRow['Name']) ?>" readonly style="background:#f8fafc;">
                        <div class="hint">Employee and site come from the row you opened. They stay on this person.</div>
                    <?php else: ?>
                    <select class="form-control" name="user_id" id="user_id" required>
                        <option value="">— Select employee —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"<?= ((int)$u['id'] === $preUser) ? ' selected' : '' ?>><?= htmlspecialchars($u['Name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
            </div>

            <div id="mpeLines">
                <div class="mpe-line">
                    <div class="mpe-line-head">
                        <span class="mpe-line-label">Line 1</span>
                        <button type="button" class="btn btn-sm btn-outline-danger mpe-remove py-0">Remove</button>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-3" id="mpeAmount">
                            <label for="amount">Amount ($)</label>
                            <input type="number" step="0.01" min="0.01" class="form-control" name="amount[]" id="amount" required placeholder="0.00">
                            <div class="hint mpe-amount-hint">Deducted from pay each period.</div>
                        </div>
                        <div class="form-group col-md-4" id="mpeType">
                            <label for="reason">Type</label>
                            <select class="form-control mpe-type" name="reason[]" id="reason" required>
                                <?php foreach ($REASONS as $k => $lab): ?>
                                    <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lab) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-5" id="mpeReason">
                            <label for="typed_reason">Reason</label>
                            <input type="text" class="form-control mpe-typed" name="typed_reason[]" id="typed_reason" maxlength="80" required placeholder="" autocomplete="off">
                            <div class="hint">Type the reason. It prints on the paystub.</div>
                        </div>
                    </div>
                    <div class="form-row mpe-recur">
                        <div class="form-group col-md-4" id="mpeFrequency">
                            <label>Frequency</label>
                            <select class="form-control mpe-frequency" name="frequency[]">
                                <option value="biweekly">Bi-weekly</option>
                                <option value="monthly">Monthly</option>
                            </select>
                            <div class="hint">How often this adjustment or benefit repeats.</div>
                        </div>
                        <div class="form-group col-md-3" id="mpePeriods">
                            <label>Number of periods</label>
                            <input type="number" class="form-control mpe-periods" name="periods[]" min="1" max="26" value="1">
                            <div class="hint">1 is this period only. Up to 26.</div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-5" id="mpeInclude">
                            <label>Include in payroll?</label>
                            <div class="mt-1">
                                <div class="custom-control custom-radio custom-control-inline">
                                    <input type="radio" id="inc_yes" name="include_in_payroll[]" value="1" class="custom-control-input" checked>
                                    <label class="custom-control-label font-weight-bold" for="inc_yes" style="text-transform:none;font-size:13px;">Yes — paystub / T4 / CRA</label>
                                </div>
                                <div class="custom-control custom-radio custom-control-inline">
                                    <input type="radio" id="inc_no" name="include_in_payroll[]" value="0" class="custom-control-input">
                                    <label class="custom-control-label font-weight-bold" for="inc_no" style="text-transform:none;font-size:13px;">No — voucher only</label>
                                </div>
                            </div>
                            <div class="hint">Yes = on the payroll cheque as its own line. No = voucher only, not on the cheque or T4.</div>
                        </div>
                        <div class="form-group col-md-7" id="mpeRemarks">
                            <label for="remarks">Remarks</label>
                            <input type="text" class="form-control" name="remarks[]" id="remarks" maxlength="500" placeholder="Optional — cheque #, period reference…">
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center" id="mpeSubmit">
                <button type="button" class="btn btn-outline-primary font-weight-bold" id="mpeAddLine"><i class="fa fa-plus"></i> Add another line</button>
                <button type="submit" class="btn btn-success font-weight-bold px-4" id="mpePostBtn"><i class="fa fa-check"></i> <span id="mpePostLabel">Post entry</span></button>
            </div>
        </form>
    </div>

    <div class="card-box" id="mpeListCard">
        <h2 style="font-size:15px;">Recent entries</h2>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th><th>Date</th><th>Employee</th><th>Site</th><th>Reason</th>
                        <th class="text-right">Amount</th><th>Payroll</th><th>Status</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$list): ?>
                    <tr><td colspan="9" class="text-muted">No entries yet.</td></tr>
                <?php else: foreach ($list as $r):
                    $lab = mpe_reason_label((string)$r['reason'], $r['reason_other'] ?? null);
                    if (!empty($r['period_count']) && (int)$r['period_count'] > 1) {
                        $freqWord = (($r['frequency'] ?? '') === 'monthly') ? 'Monthly' : 'Bi-weekly';
                        $lab .= ' · ' . $freqWord . ' ' . (int)($r['period_no'] ?? 1) . '/' . (int)$r['period_count'];
                    }
                    $inc = (int)$r['include_in_payroll'] === 1;
                    $amtSign = ((string)$r['reason'] === 'adjustment') ? '-' : '';
                ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td><?= htmlspecialchars($r['entry_date']) ?></td>
                        <td><?= htmlspecialchars($r['emp_name']) ?></td>
                        <td><?= htmlspecialchars($r['site_name']) ?></td>
                        <td><?= htmlspecialchars($lab) ?></td>
                        <td class="text-right font-weight-bold"><?= $amtSign ?>$<?= number_format((float)$r['amount'], 2) ?></td>
                        <td><?= $inc ? '<span class="badge badge-yes px-2">Yes</span>' : '<span class="badge badge-no px-2">No</span>' ?></td>
                        <td>
                            <?php if ($r['status'] === 'void'): ?>
                                <span class="badge badge-void px-2">Void</span>
                            <?php else: ?>
                                <span class="badge badge-posted px-2">Posted</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right text-nowrap">
                            <?php if ($r['status'] !== 'void' && !$inc): ?>
                                <a class="btn btn-sm btn-outline-secondary py-0" target="_blank"
                                   href="manual_pay_voucher.php?id=<?= (int)$r['id'] ?>">Voucher</a>
                            <?php endif; ?>
                            <?php if ($r['status'] !== 'void' && $inc && !empty($r['finalized_id'])): ?>
                                <a class="btn btn-sm btn-outline-primary py-0"
                                   href="payroll_master_ledger.php?from_date=<?= urlencode($r['entry_date']) ?>&to_date=<?= urlencode($r['entry_date']) ?>&user_id=<?= (int)$r['user_id'] ?>&balance_scope=all">Ledger</a>
                            <?php endif; ?>
                            <?php if ($r['status'] !== 'void'): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Void this entry?<?= $inc ? ' Removes the payroll line too.' : '' ?>');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="action" value="void_entry">
                                    <input type="hidden" name="entry_id" value="<?= (int)$r['id'] ?>">
                                    <?php if (ps_is_superadmin()): ?><input type="hidden" name="org_id" value="<?= (int)$orgId ?>"><?php endif; ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger py-0">Void</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
(function () {
    var box = document.getElementById('mpeLines');
    var addBtn = document.getElementById('mpeAddLine');
    if (!box || !addBtn) return;

    function syncLine(row) {
        var type = row.querySelector('.mpe-type');
        var recur = row.querySelector('.mpe-recur');
        var hint = row.querySelector('.mpe-amount-hint');
        if (!type) return;
        var recurring = type.value === 'adjustment' || type.value === 'benefit';
        if (recur) recur.style.display = recurring ? 'flex' : 'none';
        if (hint) {
            if (type.value === 'adjustment') hint.textContent = 'Deducted from pay each period.';
            else if (type.value === 'benefit') hint.textContent = 'Added to pay each period.';
            else hint.textContent = 'Added on this pay.';
        }
    }

    function refreshLines() {
        var rows = box.querySelectorAll('.mpe-line');
        rows.forEach(function (row, i) {
            var label = row.querySelector('.mpe-line-label');
            var remove = row.querySelector('.mpe-remove');
            if (label) label.textContent = 'Line ' + (i + 1);
            if (remove) remove.style.visibility = rows.length > 1 ? 'visible' : 'hidden';
        });
        var postLabel = document.getElementById('mpePostLabel');
        if (postLabel) postLabel.textContent = rows.length > 1 ? 'Post lines' : 'Post entry';
    }

    function bindLine(row) {
        var type = row.querySelector('.mpe-type');
        var remove = row.querySelector('.mpe-remove');
        if (type && !type.dataset.bound) {
            type.dataset.bound = '1';
            type.addEventListener('change', function () { syncLine(row); });
        }
        if (remove && !remove.dataset.bound) {
            remove.dataset.bound = '1';
            remove.addEventListener('click', function () {
                if (box.querySelectorAll('.mpe-line').length < 2) return;
                row.remove();
                refreshLines();
            });
        }
        syncLine(row);
    }

    addBtn.addEventListener('click', function () {
        var first = box.querySelector('.mpe-line');
        var clone = first.cloneNode(true);
        clone.querySelectorAll('[id]').forEach(function (el) { el.removeAttribute('id'); });
        clone.querySelectorAll('input[type="text"], input[type="number"]').forEach(function (el) { el.value = ''; });
        var periods = clone.querySelector('.mpe-periods');
        if (periods) periods.value = '1';
        var freq = clone.querySelector('.mpe-frequency');
        if (freq) freq.selectedIndex = 0;
        var type = clone.querySelector('.mpe-type');
        if (type) {
            type.selectedIndex = 0;
            delete type.dataset.bound;
        }
        var remove = clone.querySelector('.mpe-remove');
        if (remove) delete remove.dataset.bound;
        clone.querySelectorAll('input[type="radio"]').forEach(function (el) {
            var id = 'inc_' + el.value + '_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
            var lab = el.parentElement.querySelector('label');
            el.id = id;
            if (lab) lab.setAttribute('for', id);
            el.checked = el.value === '1';
        });
        box.appendChild(clone);
        bindLine(clone);
        refreshLines();
        var amount = clone.querySelector('input[type="number"]');
        if (amount) amount.focus();
    });

    box.querySelectorAll('.mpe-line').forEach(bindLine);
    refreshLines();
})();
</script>
</body>
</html>
