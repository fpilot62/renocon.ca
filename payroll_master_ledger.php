<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// GATE 1: SECURITY CLEARANCE
if (!isset($_SESSION["user_id"]) || empty($_SESSION["user_id"])) {
    header("Location: login_kiosk.php?err=session_expired");
    exit;
}
$sess_user_level = (int)($_SESSION['user_level'] ?? 99);
$user_org_id     = (int)($_SESSION['org_id'] ?? 0);

// GATE 2: CLEARANCE PROTECTION
if ($sess_user_level >= 30) {
    header("Location: login_kiosk.php?err=access_denied_clearance");
    exit;
}

/**
 * Path: /payroll_master_ledger.php
 * PURPOSE: Steps 2 & 3 Ledger Desk — pay per-shift OR per-period, single source of truth.
 *
 * WHAT CHANGED (vs prior version) and WHY:
 *  - Deductions now read the STORED columns (cpp, ei, income_tax / cpp_er, ei_er, wcb, vac)
 *    instead of flat 5.95/1.64/15% guesses, so this page, the report page, and the
 *    payroll_masters table all agree on the same net.
 *  - Payments are CAPPED server-side to the real remaining balance (the HTML max= was
 *    not enforced in PHP before).
 *  - Payouts are keyed on payroll_finalized.id (the PK), not attendance_id, so a row is
 *    read and updated as the same single record.
 *  - payroll_masters is no longer polluted: a payment attaches to the EXISTING master via
 *    payroll_finalized.payroll_id; if none exists, ONE correctly-populated master is created
 *    (all columns filled, not just gross_total) and the line is stamped so it is reused.
 *  - payroll_balance is kept in sync (paid_amount / balance_remaining) on every payment.
 *  - Travel/Overtime filtering uses the real overtime_hours / travel_hours columns.
 *  - A "Pay Full Period Balance" action settles every outstanding shift for an employee
 *    inside the current date window in one transaction (the bi-weekly/semi-monthly/monthly
 *    "pay the whole period" workflow).
 *  - Cheques are confirmed, numbered, and stored by engine/payroll_cheque_lib.php.
 *    Printing a cheque does not by itself mark the row paid unless that option is ticked.
 *    Void keeps the number. Payments never exceed the stored net still owing.
 *
 * ASSUMPTIONS TO VERIFY (see notes to user):
 *  - payroll_masters.status is treated as free text written as 'Paid' / 'Partial' / 'Unpaid'
 *    to match the capitalised values already in the table. If it is a strict ENUM, send
 *    SHOW CREATE TABLE payroll_masters and these strings will be matched exactly.
 *  - An auto-created master spans a single shift's period unless your finalization process
 *    has already linked lines to a shared master via payroll_finalized.payroll_id.
 *
 * TEST ON A COPY FIRST: this writes to payroll_finalized, payroll_balance, payroll_masters
 * and payroll_payments. Verify against a few known rows before using in production.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';
require_once 'payroll_scope.php';
require_once __DIR__ . '/engine/payroll_cheque_lib.php';
require_once __DIR__ . '/quickbooks/qb_export_lib.php';
ps_require_payroll_admin();
$user_org_id = ps_selected_org($pdo);

if (file_exists('engine/menu_generator.php')) { require_once 'engine/menu_generator.php'; }

// ---------------------------------------------------------------- helpers
/** Employee statutory deductions for one finalized line, from stored columns. */
function ee_deduct(array $r): float {
    return (float)$r['ee_cpp'] + (float)$r['ee_ei'] + (float)$r['ee_tax'];
}
/** Net payable for one finalized line. */
function net_payable_line(array $r): float {
    return max(0, (float)$r['total_gross'] - ee_deduct($r));
}
/** Pay-period bucket for a work date. Returns [label, start_date, end_date]. */
function period_for(string $work_date, string $mode, string $anchor = '2024-01-01'): array {
    if (function_exists('sip_period_window')) {
        [$start, $end] = sip_period_window($work_date, $mode, $anchor);
    } elseif (function_exists('pe_period_window')) {
        [$start, $end] = pe_period_window($work_date, $mode, $anchor);
    } else {
        $ts = strtotime($work_date) ?: time();
        if ($mode === 'monthly') {
            $start = date('Y-m-01', $ts);
            $end = date('Y-m-t', $ts);
        } elseif ($mode === 'bi_weekly') {
            $d0 = new DateTimeImmutable(preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor) ? $anchor : '2024-01-01');
            $d1 = new DateTimeImmutable(substr($work_date, 0, 10));
            $days = (int)$d0->diff($d1)->format('%r%a');
            $start = $d0->modify((((int)floor($days / 14)) * 14) . ' days')->format('Y-m-d');
            $end = (new DateTimeImmutable($start))->modify('+13 days')->format('Y-m-d');
        } else {
            $day = (int)date('j', $ts);
            $start = ($day <= 15) ? date('Y-m-01', $ts) : date('Y-m-16', $ts);
            $end = ($day <= 15) ? date('Y-m-15', $ts) : date('Y-m-t', $ts);
        }
    }
    if (function_exists('sip_period_label')) {
        return [sip_period_label($start, $end, $mode), $start, $end];
    }
    $ts = strtotime($start) ?: time();
    if ($mode === 'monthly') {
        return [date('F Y', $ts), $start, $end];
    }
    if ($mode === 'bi_weekly') {
        return ['Bi-Weekly: ' . date('M j', strtotime($start)) . ' – ' . date('M j, Y', strtotime($end)), $start, $end];
    }
    $day = (int)date('j', $ts);
    if ($day <= 15) {
        return [date('M 1–15, Y', $ts), $start, $end];
    }
    return [date('M 16', $ts) . date('–t, Y', $ts), $start, $end];
}
/** Resolve (or correctly create) the period master id for a finalized line. */
function resolve_master_id(PDO $pdo, array $line, int $org_id): int {
    // 1) already linked
    if (!empty($line['payroll_id']) && (int)$line['payroll_id'] > 0) {
        return (int)$line['payroll_id'];
    }
    // 2) existing master covering this user+site+date
    $find = $pdo->prepare(
        "SELECT id FROM payroll_masters
         WHERE org_id = ? AND user_id = ? AND site_id = ? AND pay_period_start <= ? AND pay_period_end >= ?
         ORDER BY id DESC LIMIT 1"
    );
    $find->execute([$org_id, $line['user_id'], $line['site_id'], $line['work_date'], $line['work_date']]);
    if ($mid = $find->fetchColumn()) { return (int)$mid; }

    // 3) create ONE correctly-populated master (no zero-filled junk rows)
    $start = $line['work_date'];
    $end   = !empty($line['pay_period_end']) ? $line['pay_period_end'] : $line['work_date'];
    $gross = (float)$line['total_gross'];
    $cra   = ee_deduct($line);
    $er    = (float)$line['er_cpp'] + (float)$line['er_ei'] + (float)$line['er_wcb'] + (float)$line['er_vac'];
    $net   = max(0, $gross - $cra);

    $ins = $pdo->prepare(
        "INSERT INTO payroll_masters
            (org_id, site_id, user_id, pay_period_start, pay_period_end,
             gross_total, er_contributions, cra_deductions, net_payable, status, cra_status, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP())"
    );
    $ins->execute([$org_id, $line['site_id'], $line['user_id'], $start, $end,
                   $gross, $er, $cra, $net, 'Unpaid', 'Unpaid']);
    $new_id = (int)$pdo->lastInsertId();

    // stamp the line so the master is reused next time
    $pdo->prepare("UPDATE payroll_finalized SET payroll_id = ? WHERE id = ?")
        ->execute([$new_id, $line['line_id']]);
    return $new_id;
}
/** Re-derive a master's status from payments that have not been reversed. */
function refresh_master_status(PDO $pdo, int $master_id): void {
    pcr_refresh_master_status($pdo, $master_id);
}
/**
 * Apply one payment to a finalized line. The amount is capped at the stored
 * net still outstanding, so a second click cannot record the same pay again.
 */
function apply_payment(PDO $pdo, int $line_id, float $amount, string $method, int $org_id): float {
    $payment_id = null;
    return pcr_apply_payment($pdo, $line_id, $amount, pcr_payment_method($method), $org_id, $payment_id);
}

// ---------------------------------------------------------------- filters
$staff_q = $pdo->prepare("SELECT id, Name FROM users WHERE org_id=? ORDER BY Name ASC");
$staff_q->execute([$user_org_id]); $staff_list = $staff_q->fetchAll(PDO::FETCH_ASSOC);
$sites_q = $pdo->prepare("SELECT Sid, nick_name FROM sites WHERE org_id=? ORDER BY nick_name ASC");
$sites_q->execute([$user_org_id]); $sites_list = $sites_q->fetchAll(PDO::FETCH_ASSOC);
$ledger_orgs = [];
if (ps_is_superadmin()) {
    try {
        $ledger_orgs = $pdo->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $ledger_orgs = [];
    }
}

$site_filter     = $_GET['site_id']       ?? 'all';
$employee_filter = $_GET['user_id']       ?? 'all';
$view_category   = $_GET['view_category'] ?? 'consolidated';
$balance_scope   = $_GET['balance_scope'] ?? 'all';
if (!function_exists('sip_org_pay_period_mode')) {
    require_once __DIR__ . '/engine/org_modules_lib.php';
}
$period_mode = 'semi_monthly';
if ($user_org_id > 0 && function_exists('sip_org_pay_period_mode')) {
    $period_mode = sip_org_pay_period_mode($pdo, $user_org_id);
} elseif (!empty($_GET['period_mode']) && in_array($_GET['period_mode'], ['semi_monthly', 'bi_weekly', 'monthly'], true)) {
    $period_mode = $_GET['period_mode'];
}
$period_anchor = function_exists('sip_org_pay_period_anchor') ? sip_org_pay_period_anchor($pdo, $user_org_id) : '2024-01-01';
$period_mode_label = function_exists('sip_period_mode_label') ? sip_period_mode_label($period_mode) : str_replace('_', ' ', $period_mode);
$from_in = trim((string)($_GET['from_date'] ?? ''));
$to_in = trim((string)($_GET['to_date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_in)) { $from_in = ''; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_in)) { $to_in = ''; }
$period_nav = strtolower(trim((string)($_GET['period_nav'] ?? '')));
$pivot = $from_in !== '' ? $from_in : date('Y-m-d');
[, $from_date, $to_date] = period_for($pivot, $period_mode, $period_anchor);
if ($period_nav === 'prev') {
    [, $from_date, $to_date] = period_for(date('Y-m-d', strtotime($from_date . ' -1 day')), $period_mode, $period_anchor);
} elseif ($period_nav === 'next') {
    [, $from_date, $to_date] = period_for(date('Y-m-d', strtotime($to_date . ' +1 day')), $period_mode, $period_anchor);
}
$period_options = function_exists('sip_list_pay_periods')
    ? sip_list_pay_periods($period_mode, $period_anchor, date('Y-m-d'), 26, 4)
    : [];
$__have_cur = false;
foreach ($period_options as $__po) {
    if (($__po['start'] ?? '') === $from_date && ($__po['end'] ?? '') === $to_date) { $__have_cur = true; break; }
}
if (!$__have_cur) {
    [$__pl, $__ps, $__pe] = period_for($from_date, $period_mode, $period_anchor);
    array_unshift($period_options, ['start' => $__ps, 'end' => $__pe, 'label' => $__pl]);
}
if ($site_filter !== 'all' && $site_filter !== '') {
    $__sc = $pdo->prepare('SELECT COUNT(*) FROM sites WHERE SId = ? AND org_id = ?');
    $__sc->execute([(int)$site_filter, $user_org_id]);
    if (!$__sc->fetchColumn()) { $site_filter = 'all'; }
}
if ($employee_filter !== 'all' && $employee_filter !== '') {
    $__uc = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND org_id = ?');
    $__uc->execute([(int)$employee_filter, $user_org_id]);
    if (!$__uc->fetchColumn()) { $employee_filter = 'all'; }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') { ps_require_csrf(); }

$redir = "payroll_master_ledger.php?org_id=$user_org_id&from_date=$from_date&to_date=$to_date"
       . "&site_id=" . urlencode($site_filter) . "&user_id=" . urlencode($employee_filter)
       . "&view_category=" . urlencode($view_category) . "&balance_scope=" . urlencode($balance_scope)
       . "&period_mode=" . urlencode($period_mode);

function ledger_flash(string $redir, string $msg, string $text): void {
    $_SESSION['ledger_flash'] = $text;
    header('Location: ' . $redir . '&msg=' . rawurlencode($msg));
    exit;
}

// ---------------------------------------------------------------- EFT preference (saved on the employee, not a row selector)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_eft_preference'])) {
    header('Content-Type: application/json');
    try {
        $uid = (int)($_POST['eft_user_id'] ?? 0);
        $on = (($_POST['eft_no_cheque'] ?? '0') === '1');
        pcr_set_eft($pdo, $user_org_id, $uid, $on);
        echo json_encode(['ok' => true, 'eft' => $on ? 1 : 0]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------------- void a cheque number (the number stays void and is not reused)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['void_cheque'])) {
    try {
        $res = pcr_void_cheque(
            $pdo,
            (int)($_POST['cheque_id'] ?? 0),
            $user_org_id,
            (int)($_SESSION['user_id'] ?? 0),
            (string)($_POST['void_reason'] ?? ''),
            (($_POST['reverse_payment'] ?? '') === '1')
        );
        $note = 'Cheque #' . $res['number'] . ' marked void.';
        if ($res['reversed'] > 0) {
            $note .= ' Reversed $' . number_format($res['reversed'], 2) . ' that this cheque had recorded. Gross, CPP, EI, and tax were not changed.';
        }
        ledger_flash($redir, 'cheque_void', $note);
    } catch (Throwable $e) {
        ledger_flash($redir, 'cheque_error', $e->getMessage());
    }
}

// ---------------------------------------------------------------- mark one issued cheque paid, or every issued cheque in this period
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['mark_cheque_paid']) || isset($_POST['mark_issued_cheques_paid']))) {
    try {
        $actor = (int)($_SESSION['user_id'] ?? 0);
        if (isset($_POST['mark_issued_cheques_paid'])) {
            $res = pcr_mark_issued_in_period($pdo, $user_org_id, $from_date, $to_date, $actor);
            ledger_flash($redir, 'cheques_paid', 'Marked ' . (int)$res['cheques'] . ' issued cheque(s) paid by Cheque ($' . number_format($res['amount'], 2) . '). Already-paid rows were not paid again.');
        }
        $res = pcr_mark_cheque_paid($pdo, (int)($_POST['cheque_id'] ?? 0), $user_org_id, $actor);
        ledger_flash($redir, 'cheques_paid', 'Cheque payment recorded: $' . number_format($res['amount'], 2) . '. A row that was already paid was left as it was.');
    } catch (Throwable $e) {
        ledger_flash($redir, 'cheque_error', $e->getMessage());
    }
}

// ---------------------------------------------------------------- ROLLBACK (System Reversal: send an unpaid shift back to audit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_back_to_audit'])) {
    $target = (int)$_POST['rollback_attendance_id'];
    if ($target > 0) {
        $auth = $pdo->prepare("SELECT COUNT(*) FROM attendance a
            INNER JOIN users u ON u.id=a.user_id AND u.org_id=?
            INNER JOIN sites s ON s.SId=a.site_id AND s.org_id=u.org_id WHERE a.id=?");
        $auth->execute([$user_org_id, $target]);
        if (!$auth->fetchColumn()) { http_response_code(403); exit('Attendance row is outside your organization.'); }
        $fin = $pdo->prepare(
            "SELECT f.id, f.paid_amount FROM payroll_finalized f
             INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
             WHERE f.attendance_id = ?"
        );
        $fin->execute([$user_org_id, $target]);
        $frows = $fin->fetchAll(PDO::FETCH_ASSOC);
        $active = pcr_active_by_line($pdo, $user_org_id, array_map(static fn($r) => (int)$r['id'], $frows));
        foreach ($frows as $fr) {
            $block = pcr_block_send_back((float)$fr['paid_amount'], $active[(int)$fr['id']] ?? null);
            if ($block) {
                ledger_flash($redir, 'rollback_blocked', $block);
            }
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE attendance SET status = 4, payout_status = 0 WHERE id = ?")->execute([$target]);
            $pdo->prepare("DELETE FROM payroll_finalized WHERE attendance_id = ?")->execute([$target]);
            $pdo->prepare("DELETE FROM payroll_balance   WHERE attendance_id = ?")->execute([$target]);
            $pdo->commit();
            header("Location: $redir&msg=rollback_success"); exit;
        } catch (Exception $e) { $pdo->rollBack(); die("Rollback error: " . $e->getMessage()); }
    }
}

// ---------------------------------------------------------------- SINGLE-SHIFT PAYOUT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_single_payout'])) {
    $line_id = (int)($_POST['line_id'] ?? 0);
    $amt     = (float)($_POST['amount_to_pay'] ?? 0);
    $method  = pcr_payment_method($_POST['form_payment_method'] ?? 'E-Transfer');
    if ($line_id > 0 && $amt > 0) {
        try {
            $pdo->beginTransaction();
            apply_payment($pdo, $line_id, $amt, $method, $user_org_id);
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); die("Payment processing failed: " . $e->getMessage()); }
    }
    header("Location: $redir&msg=paid"); exit;
}

// ---------------------------------------------------------------- PAY SELECTED SHIFTS (checkbox batch, custom amounts)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_selected_payout'])) {
    $method = pcr_payment_method($_POST['form_payment_method'] ?? 'E-Transfer');
    // pay_lines[] holds "lineId:amount" or "lineId:amount:method" for each ticked shift
    $pairs  = $_POST['pay_lines'] ?? [];
    $paid_count = 0;
    if (!empty($pairs)) {
        try {
            $pdo->beginTransaction();
            foreach ((array)$pairs as $pair) {
                $parts = explode(':', (string)$pair, 3);
                if (count($parts) < 2) continue;
                $lid = (int)$parts[0];
                $amt = (float)$parts[1];
                $lineMethod = pcr_payment_method($parts[2] ?? $method);
                if ($lid > 0 && $amt > 0 && apply_payment($pdo, $lid, $amt, $lineMethod, $user_org_id) > 0) {
                    $paid_count++;
                }
            }
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); die("Selected payout failed: " . $e->getMessage()); }
    }
    header("Location: $redir&msg=selected_paid&n=$paid_count"); exit;
}

// ---------------------------------------------------------------- PAY FULL PERIOD (per employee + selected bucket)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_period_payout'])) {
    $uid    = (int)($_POST['period_user_id'] ?? 0);
    $method = pcr_payment_method($_POST['form_payment_method'] ?? 'E-Transfer');
    // The button sits on one site's employee row. Pay that site only, so the
    // confirmed amount matches the rows that are actually updated.
    $psite  = (int)($_POST['period_site_id'] ?? 0);
    $pstart = $_POST['period_start'] ?? $from_date;
    $pend   = $_POST['period_end']   ?? $to_date;
    if ($uid > 0) {
        try {
            $pdo->beginTransaction();
            ps_assert_user($pdo, $uid, $user_org_id);
            $w = ["f.user_id = ?", "f.work_date BETWEEN ? AND ?", "u.org_id = ?", "s.org_id = u.org_id"];
            $p = [$uid, $pstart, $pend, $user_org_id];
            if ($psite > 0) { $w[] = "f.site_id = ?"; $p[] = $psite; }
            elseif ($site_filter !== 'all' && $site_filter !== '') { $w[] = "f.site_id = ?"; $p[] = (int)$site_filter; }
            if ($view_category === 'travel') { $w[] = 'f.travel_hours > 0'; }
            elseif ($view_category === 'overtime') { $w[] = 'f.overtime_hours > 0'; }
            $ls = $pdo->prepare("SELECT f.id FROM payroll_finalized f
                INNER JOIN users u ON u.id=f.user_id
                INNER JOIN sites s ON s.SId=f.site_id
                WHERE " . implode(" AND ", $w) . " ORDER BY f.work_date ASC");
            $ls->execute($p);
            foreach ($ls->fetchAll(PDO::FETCH_COLUMN) as $lid) {
                // pay each line to its full remaining (cap inside apply_payment guards over-pay)
                apply_payment($pdo, (int)$lid, 1e9, $method, $user_org_id);
            }
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); die("Period payout failed: " . $e->getMessage()); }
    }
    header("Location: $redir&msg=period_paid"); exit;
}

// ---------------------------------------------------------------- QUICKBOOKS EXPORT (profile-driven journal entries)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_qb_matrix'])) {
    // load this org's mapping profile
    $pf = $pdo->prepare("SELECT * FROM qb_export_profiles WHERE org_id = ? LIMIT 1");
    $pf->execute([$user_org_id]);
    $profile = $pf->fetch(PDO::FETCH_ASSOC);
    if (!$profile) {
        echo "<script>alert('No QuickBooks mapping is set up yet. Configure it once, then export.'); window.location='qb_export_setup.php';</script>"; exit;
    }

    // collect the selected zero-balance line ids (server re-fetches the real numbers)
    $ids = array_values(array_filter(array_map('intval', $_POST['qb_line_ids'] ?? [])));
    if (empty($ids)) {
        echo "<script>alert('No zero-balance rows are loaded to export. Set Balance Scope to All or Zero Balance first.'); window.history.back();</script>"; exit;
    }

    $in  = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare(
        "SELECT f.id, f.user_id, u.Name AS employee_name, s.nick_name AS site_name,
                f.work_date, f.pay_period_end, f.last_paid_date, f.total_gross,
                f.cpp AS ee_cpp, f.ei AS ee_ei, f.income_tax AS ee_tax,
                f.cpp_er AS er_cpp, f.ei_er AS er_ei, f.wcb AS er_wcb, f.vac AS er_vac
         FROM payroll_finalized f
         INNER JOIN users u ON f.user_id = u.id
         LEFT  JOIN sites s ON f.site_id = s.Sid
         WHERE f.id IN ($in) AND u.org_id = ? AND s.org_id = u.org_id"
    );
    $q->execute(array_merge($ids, [$user_org_id]));
    $lines = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($lines) !== count($ids)) { http_response_code(403); exit('One or more export rows are outside your organization.'); }

    $opts       = json_decode($profile['je_options'] ?? '[]', true) ?: [];
    $grouping   = $opts['grouping']   ?? 'per_employee_period';
    $date_basis = $opts['date_basis'] ?? 'pay_period_end';

    $pick_date = function ($r) use ($date_basis) {
        if ($date_basis === 'today')     return date('Y-m-d');
        if ($date_basis === 'paid_date' && !empty($r['last_paid_date'])) return $r['last_paid_date'];
        return !empty($r['pay_period_end']) ? $r['pay_period_end'] : $r['work_date'];
    };

    // accumulate component amounts into groups
    $groups = [];
    foreach ($lines as $r) {
        $date = $pick_date($r);
        if ($grouping === 'summary') {
            $key = 'ALL'; $name = 'Payroll batch'; $memo = 'Payroll ' . date('Y-m-d');
            $gdate = date('Y-m-d');
        } else {
            [$plabel, $pstart, $pend] = period_for($r['work_date'], $period_mode, $period_anchor);
            $key = $r['user_id'] . '|' . $pstart;
            $name = $r['employee_name']; $memo = $plabel; $gdate = $date;
        }
        if (!isset($groups[$key])) {
            $groups[$key] = ['name' => $name, 'date' => $gdate, 'memo' => $memo,
                             'gross'=>0,'ee_cpp'=>0,'ee_ei'=>0,'ee_tax'=>0,'er_cpp'=>0,'er_ei'=>0,'wcb'=>0,'vac'=>0];
        }
        $groups[$key]['gross']  += (float)$r['total_gross'];
        $groups[$key]['ee_cpp'] += (float)$r['ee_cpp'];
        $groups[$key]['ee_ei']  += (float)$r['ee_ei'];
        $groups[$key]['ee_tax'] += (float)$r['ee_tax'];
        $groups[$key]['er_cpp'] += (float)$r['er_cpp'];
        $groups[$key]['er_ei']  += (float)$r['er_ei'];
        $groups[$key]['wcb']    += (float)$r['er_wcb'];
        $groups[$key]['vac']    += (float)$r['er_vac'];
    }

    $result = qbx_build_export(array_values($groups), $profile);

    // if any entry was out of balance, stop and tell the user rather than ship a bad file
    if (!empty($result['warnings'])) {
        $msg = "Export halted — these entries don't balance:\\n\\n" . implode("\\n", $result['warnings']);
        echo "<script>alert(" . json_encode($msg) . "); window.location='qb_export_setup.php';</script>"; exit;
    }

    header('Content-Type: ' . $result['mime']);
    header('Content-Disposition: attachment; filename=' . $result['filename']);
    echo $result['content'];
    exit;
}

// ---------------------------------------------------------------- DATA PULL (stored columns)
$where  = ["f.work_date BETWEEN ? AND ?", "u.org_id = ?", "s.org_id = u.org_id"];
$params = [$from_date, $to_date, $user_org_id];
if ($site_filter !== 'all' && $site_filter !== '')     { $where[] = "f.site_id = ?"; $params[] = (int)$site_filter; }
if ($employee_filter !== 'all' && $employee_filter !== '') { $where[] = "f.user_id = ?"; $params[] = (int)$employee_filter; }
if ($view_category === 'travel')   { $where[] = "f.travel_hours > 0"; }
elseif ($view_category === 'overtime') { $where[] = "f.overtime_hours > 0"; }

$sql = "SELECT
            f.id AS line_id, f.attendance_id, f.user_id, f.site_id, f.payroll_id,
            u.Name AS employee_name, s.nick_name AS site_name,
            f.status AS row_lifecycle, f.payment_status, f.paid_amount AS db_paid_amount,
            f.reg_hours, f.overtime_hours, f.travel_hours, f.hourly_rate, f.total_gross,
            f.cpp AS ee_cpp, f.ei AS ee_ei, f.income_tax AS ee_tax,
            f.cpp_er AS er_cpp, f.ei_er AS er_ei, f.wcb AS er_wcb, f.vac AS er_vac,
            f.work_date, f.display_label, f.comments, f.category, f.pay_type
        FROM payroll_finalized f
        INNER JOIN users u ON f.user_id = u.id
        LEFT  JOIN sites s ON f.site_id = s.Sid
        WHERE " . implode(" AND ", $where) . "
        ORDER BY u.Name ASC, f.work_date ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pay Period is the top-level date filter (from/to + period_mode already narrow $raw).
// Build a nested tree:  Site  ->  Employee  ->  Shifts
// with rolled-up totals at the period (grand), site, and employee levels.
$period_tot = ['gross'=>0,'net'=>0,'paid'=>0,'rem'=>0,'reg_h'=>0,'ot_h'=>0,'travel_h'=>0,'stat_h'=>0,'stat_cost'=>0,
               'ee'=>0,'ee_tax'=>0,'ee_cpp'=>0,'ee_ei'=>0,
               'er'=>0,'er_cpp'=>0,'er_ei'=>0,'er_wcb'=>0,'er_vac'=>0];
$sites_tree = []; // [site_name] => ['name','site_id','t_*','emps'=>[user_id=>['name','user_id','t_*','lines'=>[]]]]

foreach ($raw as $r) {
    $net       = net_payable_line($r);
    $paid      = (float)$r['db_paid_amount'];
    $remaining = max(0, $net - $paid);
    if ($balance_scope === 'outstanding' && $remaining <= 0.01) continue;
    if ($balance_scope === 'zero'        && $remaining > 0.01)  continue;

    $r['_ee']        = ee_deduct($r);
    $r['_er']        = (float)$r['er_cpp'] + (float)$r['er_ei'] + (float)$r['er_wcb'] + (float)$r['er_vac'];
    $r['_net']       = $net;
    $r['_paid']      = $paid;
    $r['_remaining'] = $remaining;
    $is_stat         = stripos((string)($r['category'] ?? ''), 'Statutory') === 0 || strcasecmp((string)($r['pay_type'] ?? ''), 'Statutory') === 0;
    $reg_h           = $is_stat ? 0.0 : (float)$r['reg_hours'];
    $stat_h          = $is_stat ? (float)$r['reg_hours'] : 0.0;
    $ot_h            = (float)$r['overtime_hours'];
    $trav_h          = (float)$r['travel_hours'];

    $sname = $r['site_name'] ?? 'Unassigned';
    $uid   = $r['user_id'];

    if (!isset($sites_tree[$sname])) {
        $sites_tree[$sname] = ['name'=>$sname, 'site_id'=>$r['site_id'],
                               't_gross'=>0,'t_net'=>0,'t_paid'=>0,'t_rem'=>0,'t_reg_h'=>0,'t_ot_h'=>0,'t_travel_h'=>0,'t_stat_h'=>0,'t_stat_cost'=>0,
                               't_ee'=>0,'t_ee_tax'=>0,'t_ee_cpp'=>0,'t_ee_ei'=>0,
                               't_er'=>0,'t_er_cpp'=>0,'t_er_ei'=>0,'t_er_wcb'=>0,'t_er_vac'=>0, 'emps'=>[]];
    }
    if (!isset($sites_tree[$sname]['emps'][$uid])) {
        $sites_tree[$sname]['emps'][$uid] = ['name'=>$r['employee_name'], 'user_id'=>$uid,
                                             't_gross'=>0,'t_net'=>0,'t_paid'=>0,'t_rem'=>0,'t_reg_h'=>0,'t_ot_h'=>0,'t_travel_h'=>0,'t_stat_h'=>0,'t_stat_cost'=>0,
                                             't_ee'=>0,'t_ee_tax'=>0,'t_ee_cpp'=>0,'t_ee_ei'=>0,
                                             't_er'=>0,'t_er_cpp'=>0,'t_er_ei'=>0,'t_er_wcb'=>0,'t_er_vac'=>0, 'lines'=>[]];
    }

    $sites_tree[$sname]['emps'][$uid]['lines'][] = $r;
    foreach ([[&$sites_tree[$sname]], [&$sites_tree[$sname]['emps'][$uid]]] as $ref) {
        $ref[0]['t_gross']    += (float)$r['total_gross'];
        $ref[0]['t_net']      += $net;
        $ref[0]['t_paid']     += $paid;
        $ref[0]['t_rem']      += $remaining;
        $ref[0]['t_reg_h']    += $reg_h;
        $ref[0]['t_ot_h']     += $ot_h;
        $ref[0]['t_travel_h'] += $trav_h;
        $ref[0]['t_stat_h']   += $stat_h;
        $ref[0]['t_stat_cost']+= $is_stat ? (float)$r['total_gross'] : 0;
        $ref[0]['t_ee']       += $r['_ee'];
        $ref[0]['t_ee_tax']   += (float)$r['ee_tax'];
        $ref[0]['t_ee_cpp']   += (float)$r['ee_cpp'];
        $ref[0]['t_ee_ei']    += (float)$r['ee_ei'];
        $ref[0]['t_er']       += $r['_er'];
        $ref[0]['t_er_cpp']   += (float)$r['er_cpp'];
        $ref[0]['t_er_ei']    += (float)$r['er_ei'];
        $ref[0]['t_er_wcb']   += (float)$r['er_wcb'];
        $ref[0]['t_er_vac']   += (float)$r['er_vac'];
    }
    $period_tot['gross']    += (float)$r['total_gross'];
    $period_tot['net']      += $net;
    $period_tot['reg_h']    += $reg_h;
    $period_tot['ot_h']     += $ot_h;
    $period_tot['travel_h'] += $trav_h;
    $period_tot['stat_h']   += $stat_h;
    $period_tot['stat_cost']+= $is_stat ? (float)$r['total_gross'] : 0;
    $period_tot['paid']  += $paid;
    $period_tot['rem']   += $remaining;
    $period_tot['ee']     += $r['_ee'];
    $period_tot['ee_tax'] += (float)$r['ee_tax'];
    $period_tot['ee_cpp'] += (float)$r['ee_cpp'];
    $period_tot['ee_ei']  += (float)$r['ee_ei'];
    $period_tot['er']     += $r['_er'];
    $period_tot['er_cpp'] += (float)$r['er_cpp'];
    $period_tot['er_ei']  += (float)$r['er_ei'];
    $period_tot['er_wcb'] += (float)$r['er_wcb'];
    $period_tot['er_vac'] += (float)$r['er_vac'];
}
// sort sites by name, employees within each by name
uksort($sites_tree, fn($a,$b) => strcmp($a,$b));
foreach ($sites_tree as &$st) { uasort($st['emps'], fn($a,$b)=>strcmp($a['name'],$b['name'])); }
unset($st);

$vac_paid_mode = false;
try {
    $vm = $pdo->prepare("SELECT COALESCE(vacation_payout_mode,'accrue') FROM organizations WHERE id=? LIMIT 1");
    $vm->execute([$user_org_id]);
    $vac_paid_mode = (strtolower(trim((string)$vm->fetchColumn())) === 'pay_each_period');
} catch (Throwable $e) {
    $vac_paid_mode = false;
}
$eft_flags = pcr_eft_flags($pdo, $user_org_id);
$period_cheques = pcr_list_period($pdo, $user_org_id, $from_date, $to_date);
$next_cheque_no = pcr_next_number($pdo, $user_org_id, date('Y-m-d'));
$issued_cheque_count = 0;
foreach ($period_cheques as $c) {
    if (($c['status'] ?? '') === 'issued') { $issued_cheque_count++; }
}
$line_ids_on_page = [];
foreach ($sites_tree as $siteRow) {
    foreach ($siteRow['emps'] as $empRow) {
        foreach ($empRow['lines'] as $ln) { $line_ids_on_page[] = (int)$ln['line_id']; }
    }
}
$cheque_by_line = pcr_active_by_line($pdo, $user_org_id, $line_ids_on_page);
$cheques_by_user = [];
foreach ($period_cheques as $c) {
    $cheques_by_user[(int)$c['user_id']][] = $c;
}
$ledger_flash = $_SESSION['ledger_flash'] ?? '';
unset($_SESSION['ledger_flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll Center | EE &amp; ER Report</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; padding-top: 95px; color: #1e293b; font-size: 11px; }
        .control-panel-card { background: white; border-radius: 12px; border: 1px solid #cbd5e1; padding: 18px; margin-bottom: 20px; }
        .table-ledger th { background: #0f172a; color: white; padding: 12px; font-size: 10px; text-transform: uppercase; }
        .table-ledger td { padding: 12px; background: white; border-bottom: 1px solid #e2e8f0; vertical-align: middle; font-weight: bold; }
        .emp-band td { background:#0f172a; color:#fff; font-size:12px; }
        .emp-band .sub { color:#94a3b8; font-weight:600; }
        .period-band td { background:#0f172a; color:#fff; font-size:13px; font-weight:800; }
        .period-band .sub { color:#94a3b8; font-weight:600; }
        tr.lvl-site { background:#e2e8f0; cursor:pointer; }
        tr.lvl-site:hover { background:#cbd5e1; }
        tr.lvl-site td { font-size:13px; color:#0f172a; }
        tr.lvl-emp { background:#f1f5f9; }
        tr.lvl-emp:hover { background:#e8eef5; }
        tr.lvl-emp td:first-child { cursor:pointer; }
        tr.lvl-shift { background:#ffffff; }
        .travel-badge { display:inline-block; background:#fef3c7; color:#b45309; border:1px solid #fcd34d; font-size:9px; font-weight:800; text-transform:uppercase; padding:1px 7px; border-radius:9px; margin-left:6px; letter-spacing:.3px; }
        tr.lvl-site .sub, tr.lvl-emp .sub { color:#64748b; font-weight:600; font-size:10px; }
        .name-cell { text-align:left; padding-left:14px; }
        tr.lvl-emp .name-cell { padding-left:30px; }
        .chev { display:inline-block; transition:transform .15s ease; margin-right:8px; color:#64748b; }
        .chev.open { transform:rotate(90deg); }
        tr.hidden { display:none; }
        @media print { tr.hidden { display:table-row !important; } .chev{display:none;} }
        .toggle-pocket-btn { background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 4px; padding: 2px 6px; cursor: pointer; color: #475569; font-size: 9px; font-weight: 800; }
        .inline-tray { display: none; background: #fafafa; border: 1px dashed #cbd5e1; padding: 8px; margin-top: 5px; border-radius: 6px; text-align: left; font-size: 10px; }
        .inline-pay-box { width: 80px; text-align: right; font-weight: bold; height: 28px; font-size: 11px; display: inline-block; padding: 4px; }
        .inline-select { width: 95px; height: 28px; font-size: 11px; font-weight: bold; display: inline-block; padding: 2px; }
        .badge-link { font-size:9px; }
        .emp-select { margin-right: 8px; cursor: pointer; }
        .eft-badge { display: inline-flex; align-items: center; gap: 6px; margin-left: 10px; border: 1px dashed #2563eb; background: #eff6ff; color: #1d4ed8; border-radius: 999px; padding: 2px 8px; font-size: 9px; font-weight: 800; letter-spacing: .2px; cursor: pointer; vertical-align: middle; }
        .eft-badge.is-on { background: #1d4ed8; color: #fff; border-style: solid; }
        .eft-badge .eft-knob { width: 16px; height: 10px; border-radius: 8px; background: #bfdbfe; display: inline-block; position: relative; }
        .eft-badge.is-on .eft-knob { background: #93c5fd; }
        .eft-badge .eft-knob i { position: absolute; top: 1px; left: 1px; width: 8px; height: 8px; border-radius: 50%; background: #fff; }
        .eft-badge.is-on .eft-knob i { left: 7px; }
        .chq-pill { display: inline-block; margin: 2px 4px 0 0; padding: 1px 6px; border-radius: 8px; font-size: 9px; font-weight: 800; }
        .chq-issued { background: #fef3c7; color: #92400e; }
        .chq-paid { background: #dcfce7; color: #166534; }
        .chq-void { background: #e2e8f0; color: #475569; text-decoration: line-through; }
        #chequeConfirm { position: fixed; inset: 0; background: rgba(15,23,42,.45); z-index: 2000; display: none; align-items: center; justify-content: center; }
        #chequeConfirm .box { background: #fff; width: min(560px, 94vw); border-radius: 12px; padding: 18px 20px; box-shadow: 0 16px 40px rgba(0,0,0,.25); }
        #chequeConfirm ul { max-height: 180px; overflow: auto; margin: 8px 0; padding-left: 18px; }
    </style>
</head>
<body>

    <?= (function_exists('generateMenu')) ? generateMenu($pdo) : ''; ?>

    <div class="container-fluid px-4">
        <?php if (file_exists(__DIR__.'/payroll_help.php')) { require_once __DIR__.'/payroll_help.php'; echo payroll_help_panel('payroll_master_ledger.php'); } ?>

        <div class="mb-3">
            <div class="d-flex flex-wrap justify-content-between align-items-start">
            <div class="mb-2 mr-3">
                <h4 class="font-weight-bold text-dark mb-0">Payroll Center — Employee &amp; Employer Report</h4>
                <p class="text-muted small mb-2">One stored-value report for hours, gross, employee deductions, employer contributions, documents, payments and tax actions.</p>
                <a href="manual_pay_entry.php" class="btn btn-success font-weight-bold mr-2 mb-1" style="font-size:12px;" id="manualPayLink"><i class="fa fa-hand-holding-usd"></i> Manual Pay / Adjustments</a>
                <a href="generate_stat_shifts.php" class="btn btn-outline-warning font-weight-bold mr-2 mb-1" style="font-size:11px;"><i class="fa fa-calendar-check"></i> Stat Pay Preview</a>
                <a href="generate_t4.php?year=<?=date('Y')?>&org_id=<?=$user_org_id?>" class="btn btn-outline-primary font-weight-bold mr-2 mb-1" style="font-size:11px;"><i class="fa fa-file-invoice"></i> Tax &amp; T4</a>
                <a href="payroll_outstanding_remittance.php?from_date=<?=urlencode($from_date)?>&to_date=<?=urlencode($to_date)?>" class="btn btn-outline-danger font-weight-bold mr-2 mb-1" style="font-size:11px;"><i class="fa fa-landmark"></i> CRA Detail</a>
            </div>
            <div class="d-inline-flex flex-wrap align-items-center mb-2">
                <select id="chequeMode" class="form-control form-control-sm font-weight-bold mr-2 mb-1" style="width:auto; font-size:11px;">
                    <option value="blank">🖨️ Blank paper (full cheque)</option>
                    <option value="preprinted">🏦 Pre-printed stock</option>
                </select>
                <?php $chequeIssueDefault = date('Y-m-d', strtotime($to_date . ' +5 days')); ?>
                <label for="chequeIssueDate" class="font-weight-bold mb-1 mr-1" style="font-size:11px;">Cheque date</label>
                <input type="date" id="chequeIssueDate" class="form-control form-control-sm font-weight-bold mr-2 mb-1" style="width:auto; font-size:11px;" value="<?=htmlspecialchars($chequeIssueDefault)?>" title="Defaults to 5 days after the pay period ends. Change it before generating.">
                <button type="button" id="genDocsBtn" onclick="generatePayDocs()" class="btn btn-success font-weight-bold shadow-sm px-3 mr-2 mb-1" style="font-size:11px;">
                    <i class="fas fa-money-check-alt mr-1"></i> Generate Cheques &amp; Paystubs
                </button>
                <button type="button" id="paySelBtn" onclick="paySelectedShifts()" class="btn btn-primary font-weight-bold shadow-sm px-3 mr-2 mb-1" style="font-size:11px;">
                    <i class="fas fa-hand-holding-usd mr-1"></i> Pay Selected Shifts
                </button>
                <form method="POST" action="" class="mb-0 d-inline-flex align-items-center">
                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>">
                    <div id="qb_hidden_fields_bucket"></div>
                    <a href="qb_export_setup.php" class="btn btn-outline-secondary font-weight-bold mr-2 mb-1" style="font-size:11px;"><i class="fa fa-sliders-h"></i> Configure mapping</a>
                    <button type="submit" name="export_qb_matrix" class="btn btn-dark font-weight-bold shadow-sm px-3 mb-1" style="font-size:11px;">
                        <i class="fas fa-file-export text-warning mr-1"></i> Export Settled Rows to QuickBooks
                    </button>
                </form>
            </div>
            </div>
            <!-- hidden form that posts selected line ids to the PDF generator -->
            <form id="payDocsForm" method="POST" action="generate_pay_documents.php" target="_blank" style="display:none;">
                <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>">
                <input type="hidden" name="org_id" value="<?=$user_org_id?>">
                <input type="hidden" name="period_start" value="<?=htmlspecialchars($from_date)?>">
                <input type="hidden" name="period_end" value="<?=htmlspecialchars($to_date)?>">
                <input type="hidden" name="issue_date" id="payDocsIssueDate" value="<?=htmlspecialchars($chequeIssueDefault)?>">
                <input type="hidden" name="cheque_start" id="payDocsChequeStart" value="<?= (int)$next_cheque_no ?>">
                <input type="hidden" name="issue_nonce" id="payDocsNonce" value="">
                <input type="hidden" name="mark_paid" id="payDocsMarkPaid" value="0">
                <div id="payDocsBucket"></div>
            </form>
            <!-- hidden form that posts selected shift payments -->
            <form id="paySelForm" method="POST" action="" style="display:none;">
                <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>">
                <input type="hidden" name="execute_selected_payout" value="1">
                <input type="hidden" name="form_payment_method" id="paySelMethod" value="E-Transfer">
                <div id="paySelBucket"></div>
            </form>
        </div>

        <?php if (isset($_GET['msg'])): ?>
            <?php
                $msgKind = (string)$_GET['msg'];
                $msgClass = in_array($msgKind, ['rollback_blocked', 'cheque_error'], true) ? 'alert-warning' : 'alert-success';
            ?>
            <div class="alert <?=$msgClass?> py-2 small font-weight-bold">
                <?php
                if ($ledger_flash !== '') echo htmlspecialchars($ledger_flash);
                elseif ($msgKind==='period_paid') echo 'Period balance settled.';
                elseif ($msgKind==='rollback_success') echo 'Unpaid record sent back to audit. Stored gross and deductions on other rows were not changed.';
                elseif ($msgKind==='selected_paid') echo 'Paid ' . (int)($_GET['n'] ?? 0) . ' selected shift(s). Rows that were already paid were skipped.';
                elseif ($msgKind==='rollback_blocked') echo 'Send back was blocked.';
                else echo 'Payment recorded.';
                ?>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body py-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
                    <h6 class="font-weight-bold mb-1">Cheques this period</h6>
                    <?php if ($issued_cheque_count > 0): ?>
                    <form method="POST" class="mb-0" onsubmit="return confirm('Mark every issued cheque in this period paid by Cheque? Rows that are already paid are not paid again.');">
                        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>">
                        <button type="submit" name="mark_issued_cheques_paid" value="1" class="btn btn-sm btn-success font-weight-bold">Mark issued cheques paid</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php if (!$period_cheques): ?>
                    <p class="text-muted small mb-0">No cheques recorded for this period. Generating cheques asks you to confirm, then stores each number here. A cheque printed before this register (for example No. 2611041 on 4 Nov 2026) is not listed — set the starting number past it.</p>
                <?php else: ?>
                <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Number</th><th>Date</th><th class="text-left">Employee</th><th>Amount</th><th>Period</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($period_cheques as $c):
                        $cst = (string)$c['status'];
                    ?>
                        <tr>
                            <td><?= (int)$c['cheque_number'] ?></td>
                            <td><?= htmlspecialchars((string)$c['cheque_date']) ?></td>
                            <td class="text-left"><?= htmlspecialchars((string)$c['employee_name']) ?></td>
                            <td>$<?= number_format((float)$c['amount'], 2) ?></td>
                            <td><?= htmlspecialchars((string)$c['period_start']) ?> – <?= htmlspecialchars((string)$c['period_end']) ?></td>
                            <td><span class="chq-pill chq-<?= htmlspecialchars($cst) ?>"><?= htmlspecialchars($cst) ?></span></td>
                            <td class="text-left">
                                <?php if ($cst === 'issued'): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Mark cheque #<?= (int)$c['cheque_number'] ?> paid by Cheque? This records the stored net still owing and will not pay it twice.');">
                                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>">
                                    <input type="hidden" name="cheque_id" value="<?= (int)$c['id'] ?>">
                                    <button class="btn btn-sm btn-outline-success py-0" name="mark_cheque_paid" value="1">Mark paid</button>
                                </form>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Void cheque #<?= (int)$c['cheque_number'] ?>? The number stays void and will not be reused. Nothing is marked unpaid because this cheque is not paid yet.');">
                                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>">
                                    <input type="hidden" name="cheque_id" value="<?= (int)$c['id'] ?>">
                                    <input type="text" name="void_reason" class="form-control form-control-sm d-inline-block" style="width:120px; height:26px;" placeholder="Reason" required>
                                    <button class="btn btn-sm btn-outline-secondary py-0" name="void_cheque" value="1">Void</button>
                                </form>
                                <?php elseif ($cst === 'paid'): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Void cheque #<?= (int)$c['cheque_number'] ?> and reverse only the payment this cheque recorded? Gross, CPP, EI, and tax stay as finalized.');">
                                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>">
                                    <input type="hidden" name="cheque_id" value="<?= (int)$c['id'] ?>">
                                    <input type="hidden" name="reverse_payment" value="1">
                                    <input type="text" name="void_reason" class="form-control form-control-sm d-inline-block" style="width:120px; height:26px;" placeholder="Reason" required>
                                    <button class="btn btn-sm btn-outline-danger py-0" name="void_cheque" value="1">Void and reverse payment</button>
                                </form>
                                <?php else: ?>
                                <span class="text-muted small"><?= htmlspecialchars((string)($c['void_reason'] ?? '')) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="control-panel-card shadow-sm">
            <script>
function ledgerOnOrgChange(sel){
  var f=sel.form;
  if(f.site_id) f.site_id.value='all';
  if(f.user_id) f.user_id.value='all';
  if(f.from_date) f.from_date.value='';
  if(f.to_date) f.to_date.value='';
  var max=86400*120, parts=';path=/;max-age='+max+';samesite=lax';
  document.cookie='sip_org_id='+encodeURIComponent(sel.value)+parts;
  document.cookie='dal_org_id='+encodeURIComponent(sel.value)+parts;
  document.cookie='sa_org_id='+encodeURIComponent(sel.value)+parts;
  f.submit();
}
</script>
<form method="GET" action="" class="row align-items-end mb-0" id="ledgerFilterForm">
                <?php if (ps_is_superadmin()): ?>
                <div class="col-md-2">
                    <label class="font-weight-bold small text-muted text-uppercase mb-1">Organization</label>
                    <select name="org_id" class="form-control form-control-sm font-weight-bold" onchange="ledgerOnOrgChange(this)">
                        <?php foreach ($ledger_orgs as $o): ?>
                            <option value="<?= (int)$o['id'] ?>" <?= (int)$user_org_id === (int)$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$o['org_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" name="org_id" value="<?= (int)$user_org_id ?>">
                <?php endif; ?>
                <input type="hidden" name="from_date" value="<?=htmlspecialchars($from_date)?>">
                <input type="hidden" name="to_date" value="<?=htmlspecialchars($to_date)?>">
                <input type="hidden" name="period_mode" value="<?=htmlspecialchars($period_mode)?>">
                <div class="col-md-2">
                    <label class="font-weight-bold small text-muted text-uppercase mb-1">Employee</label>
                    <select name="user_id" class="form-control form-control-sm font-weight-bold">
                        <option value="all">All Roster Staff</option>
                        <?php foreach($staff_list as $e): ?>
                            <option value="<?=$e['id']?>" <?=((string)$employee_filter===(string)$e['id'])?'selected':''?>><?=htmlspecialchars($e['Name'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="font-weight-bold small text-muted text-uppercase mb-1">Location</label>
                    <select name="site_id" class="form-control form-control-sm font-weight-bold">
                        <option value="all">All Locations</option>
                        <?php foreach($sites_list as $s): ?>
                            <option value="<?=$s['Sid']?>" <?=((string)$site_filter===(string)$s['Sid'])?'selected':''?>><?=htmlspecialchars($s['nick_name'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="font-weight-bold small text-primary text-uppercase mb-1">View Category</label>
                    <select name="view_category" class="form-control form-control-sm font-weight-bold text-primary" style="background:#f0f9ff;">
                        <option value="consolidated" <?=($view_category==='consolidated')?'selected':''?>>📋 Consolidated Matrix</option>
                        <option value="travel" <?=($view_category==='travel')?'selected':''?>>🚗 Travel Logs Only</option>
                        <option value="overtime" <?=($view_category==='overtime')?'selected':''?>>🚨 Overtime Logs Only</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="font-weight-bold small text-success text-uppercase mb-1"><i class="fa fa-calendar-alt"></i> Pay period</label>
                    <div class="d-flex align-items-center" style="gap:6px;">
                        <button type="submit" name="period_nav" value="prev" class="btn btn-sm btn-outline-secondary" title="Previous period" style="height:31px;">◀</button>
                        <select class="form-control form-control-sm font-weight-bold text-success" style="background:#f0fdf4;" onchange="var p=this.value.split('|'); var f=this.form; if(p.length===2){ f.from_date.value=p[0]; f.to_date.value=p[1]; } f.submit();">
                            <?php foreach ($period_options as $__po):
                                $__ps = (string)($__po['start'] ?? '');
                                $__pe = (string)($__po['end'] ?? '');
                                $__sel = ($__ps === $from_date && $__pe === $to_date) ? ' selected' : '';
                            ?>
                            <option value="<?=htmlspecialchars($__ps.'|'.$__pe)?>"<?=$__sel?>><?=htmlspecialchars((string)($__po['label'] ?? ($__ps.' – '.$__pe)))?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="period_nav" value="next" class="btn btn-sm btn-outline-secondary" title="Next period" style="height:31px;">▶</button>
                    </div>
                    <div class="small text-muted mt-1"><?=htmlspecialchars($period_mode_label)?> · from org profile</div>
                </div>
                <div class="col-md-2">
                    <label class="font-weight-bold small text-primary text-uppercase mb-1"><i class="fa fa-filter"></i> Balance Scope</label>
                    <select name="balance_scope" class="form-control form-control-sm font-weight-bold text-primary" style="background:#f0f9ff;">
                        <option value="all" <?=$balance_scope==='all'?'selected':''?>>📋 All Rows</option>
                        <option value="outstanding" <?=$balance_scope==='outstanding'?'selected':''?>>🚨 Outstanding (&gt; $0)</option>
                        <option value="zero" <?=$balance_scope==='zero'?'selected':''?>>✅ Zero Balance ($0)</option>
                    </select>
                </div>
                <div class="col-md-2 mt-2"><button type="submit" class="btn btn-sm btn-primary btn-block font-weight-bold" style="height:31px;">Update Matrix</button></div>
            </form>
        </div>

        <div class="card shadow-sm border-0 rounded-lg overflow-hidden">
            <table class="table table-ledger mb-0 text-center" id="ledgerCoreMatrixTable">
                <thead>
                    <tr>
                        <th class="text-left">Site / Employee / Shift</th>
                        <th>Allocated Units</th>
                        <th>Gross</th>
                        <th>EE Deductions (withheld)</th>
                        <th>ER Contributions / Cost</th>
                        <th class="text-info">Paid</th>
                        <th class="text-success">Net Remaining</th>
                        <th>Execution Desk</th>
                        <th>System Reversal</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($sites_tree)): ?>
                    <tr><td colspan="9" class="py-5 text-muted font-weight-normal">No finalized shifts found in this period / scope.</td></tr>
                <?php else: ?>

                    <!-- PERIOD GRAND-TOTAL BAND (top filter = current date window / cycle) -->
                    <tr class="period-band">
                        <td class="text-left" colspan="2">
                            <i class="far fa-calendar-alt"></i> Pay Period: <strong><?=htmlspecialchars(date('M j', strtotime($from_date)))?> – <?=htmlspecialchars(date('M j, Y', strtotime($to_date)))?></strong>
                            <span class="sub"> · <?=ucfirst(str_replace('_',' ',$period_mode))?></span>
                            <div class="sub">Regular <?=number_format($period_tot['reg_h'],2)?>h · OT <?=number_format($period_tot['ot_h'],2)?>h · Travel <?=number_format($period_tot['travel_h'],2)?>h · Statutory <?=number_format($period_tot['stat_h'],2)?>h</div>
                        </td>
                        <td>$<?=number_format($period_tot['gross'],2)?></td>
                        <td class="text-left">
                            <span style="color:#fca5a5;">-$<?=number_format($period_tot['ee'],2)?></span>
                            <div class="sub" style="font-size:9px;">Tax $<?=number_format($period_tot['ee_tax'],2)?> · CPP $<?=number_format($period_tot['ee_cpp'],2)?> · EI $<?=number_format($period_tot['ee_ei'],2)?></div>
                            <div class="sub" style="font-size:9px;">Net payable: $<?=number_format($period_tot['net'],2)?></div>
                        </td>
                        <td class="text-left">
                            <span style="color:#93c5fd;">+$<?=number_format($period_tot['er'],2)?></span>
                            <div class="sub" style="font-size:9px;">CPP $<?=number_format($period_tot['er_cpp'],2)?> · EI $<?=number_format($period_tot['er_ei'],2)?></div>
                            <div class="sub" style="font-size:9px;">WCB $<?=number_format($period_tot['er_wcb'],2)?> · Vac $<?=number_format($period_tot['er_vac'],2)?></div>
                            <div class="sub" style="font-size:9px;">Stat pay cost $<?=number_format($period_tot['stat_cost'],2)?> · Total employer cost $<?=number_format($period_tot['gross']+$period_tot['er'],2)?></div>
                        </td>
                        <td class="text-info">$<?=number_format($period_tot['paid'],2)?></td>
                        <td style="color:<?=$period_tot['rem']>0.01?'#fca5a5':'#86efac'?>;">$<?=number_format($period_tot['rem'],2)?></td>
                        <td colspan="2" class="sub small">All sites combined<?php if ($period_tot['travel_h'] > 0): ?> · <span style="color:#fcd34d;"><i class="fa fa-car"></i> <?=number_format($period_tot['travel_h'],2)?>h travel</span><?php endif; ?></td>
                    </tr>

                    <?php $idx = 0; $sx = 0; foreach ($sites_tree as $site): $sx++; $sid = 'site'.$sx; ?>
                        <!-- LEVEL 1: SITE -->
                        <tr class="lvl-site" data-row-id="<?=$sid?>" onclick="toggleRow('<?=$sid?>')">
                            <td class="name-cell" style="text-align:left;">
                                <input type="checkbox" class="site-check" data-site="<?=$sid?>" onclick="event.stopPropagation(); toggleSite('<?=$sid?>', this.checked);" style="margin-right:8px; cursor:pointer;" title="Select all shifts at this site">
                                <span class="chev" id="chev-<?=$sid?>" onclick="event.stopPropagation(); toggleRow('<?=$sid?>');">&#9656;</span>
                                <i class="fa fa-building" style="color:#60a5fa;"></i> <strong><?=htmlspecialchars($site['name'])?></strong>
                                <span class="sub"> · <?=count($site['emps'])?> employee(s)</span>
                            </td>
                            <td class="small">R <?=number_format($site['t_reg_h'],2)?>h<br>OT <?=number_format($site['t_ot_h'],2)?>h<br>Travel <?=number_format($site['t_travel_h'],2)?>h<br>Stat <?=number_format($site['t_stat_h'],2)?>h</td>
                            <td>$<?=number_format($site['t_gross'],2)?></td>
                            <td class="text-left">
                                <span class="text-danger">-$<?=number_format($site['t_ee'],2)?></span>
                                <div class="sub" style="font-size:9px;">Tax $<?=number_format($site['t_ee_tax'],2)?> · CPP $<?=number_format($site['t_ee_cpp'],2)?> · EI $<?=number_format($site['t_ee_ei'],2)?></div>
                                <div class="sub" style="font-size:9px;">Net payable: $<?=number_format($site['t_net'],2)?></div>
                            </td>
                            <td class="text-left">
                                <span class="text-primary">+$<?=number_format($site['t_er'],2)?></span>
                                <div class="sub" style="font-size:9px;">CPP $<?=number_format($site['t_er_cpp'],2)?> · EI $<?=number_format($site['t_er_ei'],2)?></div>
                                <div class="sub" style="font-size:9px;">WCB $<?=number_format($site['t_er_wcb'],2)?> · Vac $<?=number_format($site['t_er_vac'],2)?></div>
                                <div class="sub" style="font-size:9px;">Stat pay $<?=number_format($site['t_stat_cost'],2)?> · Employer cost $<?=number_format($site['t_gross']+$site['t_er'],2)?></div>
                            </td>
                            <td class="text-info">$<?=number_format($site['t_paid'],2)?></td>
                            <td style="color:<?=$site['t_rem']>0.01?'#fca5a5':'#86efac'?>;">$<?=number_format($site['t_rem'],2)?></td>
                            <td colspan="2"></td>
                        </tr>

                        <?php foreach ($site['emps'] as $emp): $ex = ++$idx; $eid = 'emp'.$ex;
                            // effective range for employee-level "pay whole period" = the visible window
                            $eff_start = $from_date; $eff_end = $to_date;
                        ?>
                            <!-- LEVEL 2: EMPLOYEE (subtotal + pay-whole-employee) -->
                            <tr class="lvl-emp hidden" data-parent="<?=$sid?>" data-row-id="<?=$eid?>">
                                <td class="name-cell" style="text-align:left;">
                                    <input type="checkbox" class="emp-select" data-emp="<?=$eid?>" data-site="<?=$sid?>" data-user="<?=(int)$emp['user_id']?>" onclick="event.stopPropagation(); toggleEmployee('<?=$eid?>', this.checked);" title="Select all shifts for this employee">
                                    <span class="chev" id="chev-<?=$eid?>" onclick="toggleRow('<?=$eid?>')">&#9656;</span>
                                    <i class="fa fa-user" style="color:#94a3b8;"></i> <?=htmlspecialchars($emp['name'])?>
                                    <span class="sub"> · <?=count($emp['lines'])?> shift(s)<?php if ($emp['t_travel_h'] > 0): ?> · <i class="fa fa-car" style="color:#b45309;"></i> <?=number_format($emp['t_travel_h'],2)?>h travel<?php endif; ?></span>
                                    <?php $eftOn = !empty($eft_flags[(int)$emp['user_id']]); ?>
                                    <button type="button" class="eft-badge<?= $eftOn ? ' is-on' : '' ?>" data-user="<?=(int)$emp['user_id']?>" aria-pressed="<?= $eftOn ? 'true' : 'false' ?>" title="Direct deposit. No paper cheque. This is not a row selector." onclick="toggleEft(this, event)">
                                        <span class="eft-knob"><i></i></span> EFT · no cheque
                                    </button>
                                    <?php foreach ($cheques_by_user[(int)$emp['user_id']] ?? [] as $uc): ?>
                                        <span class="chq-pill chq-<?= htmlspecialchars((string)$uc['status']) ?>">Chq <?= (int)$uc['cheque_number'] ?> <?= htmlspecialchars((string)$uc['status']) ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td class="small">R <?=number_format($emp['t_reg_h'],2)?>h<br>OT <?=number_format($emp['t_ot_h'],2)?>h<br>Travel <?=number_format($emp['t_travel_h'],2)?>h<br>Stat <?=number_format($emp['t_stat_h'],2)?>h</td>
                                <td>$<?=number_format($emp['t_gross'],2)?></td>
                                <td class="text-left">
                                    <span class="text-danger">-$<?=number_format($emp['t_ee'],2)?></span>
                                    <div class="sub" style="font-size:9px;">Tax $<?=number_format($emp['t_ee_tax'],2)?> · CPP $<?=number_format($emp['t_ee_cpp'],2)?> · EI $<?=number_format($emp['t_ee_ei'],2)?></div>
                                    <div class="sub" style="font-size:9px;">Net: $<?=number_format($emp['t_net'],2)?></div>
                                </td>
                                <td class="text-left">
                                    <span class="text-primary">+$<?=number_format($emp['t_er'],2)?></span>
                                    <div class="sub" style="font-size:9px;">CPP $<?=number_format($emp['t_er_cpp'],2)?> · EI $<?=number_format($emp['t_er_ei'],2)?></div>
                                    <div class="sub" style="font-size:9px;">WCB $<?=number_format($emp['t_er_wcb'],2)?> · Vac $<?=number_format($emp['t_er_vac'],2)?></div>
                                    <div class="sub" style="font-size:9px;">Stat pay $<?=number_format($emp['t_stat_cost'],2)?> · Employer cost $<?=number_format($emp['t_gross']+$emp['t_er'],2)?></div>
                                </td>
                                <td class="text-info">$<?=number_format($emp['t_paid'],2)?></td>
                                <td style="color:<?=$emp['t_rem']>0.01?'#dc2626':'#16a34a'?>; font-weight:800;">$<?=number_format($emp['t_rem'],2)?></td>
                                <td colspan="2">
                                    <?php $emp_line_ids = implode(',', array_map(fn($line)=>(int)$line['line_id'], $emp['lines'])); ?>
                                    <a class="btn btn-sm btn-outline-dark font-weight-bold py-1 px-2 mb-1" target="_blank" href="manual_pay_entry.php?lock=1&user_id=<?=(int)$emp['user_id']?>&site_id=<?=(int)$site['site_id']?>&entry_date=<?=urlencode($eff_end)?>&org_id=<?=(int)$user_org_id?>"><i class="fa fa-hand-holding-usd"></i> Manual Pay</a>
                                    <button type="button" class="btn btn-sm btn-outline-success font-weight-bold py-1 px-2 mb-1" onclick="generateForEmployee(<?=(int)$emp['user_id']?>)"><i class="fa fa-file-pdf"></i> Stub / Cheque</button>
                                    <a class="btn btn-sm btn-outline-primary font-weight-bold py-1 px-2 mb-1" target="_blank" href="generate_t4.php?year=<?=date('Y',strtotime($to_date))?>&emp_id=<?=$emp['user_id']?>&org_id=<?=$user_org_id?>"><i class="fa fa-file-invoice"></i> T4</a>
                                    <?php if ($emp['t_rem'] > 0.01): ?>
                                        <form method="POST" action="" class="mb-0 d-inline-flex align-items-center"
                                              onsubmit="return confirm('Settle this employee\'s full period balance of $<?=number_format($emp['t_rem'],2)?>?');">
                                            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>">
                                            <input type="hidden" name="period_user_id" value="<?=$emp['user_id']?>">
                                            <input type="hidden" name="period_site_id" value="<?=(int)$site['site_id']?>">
                                            <input type="hidden" name="period_start" value="<?=htmlspecialchars($eff_start)?>">
                                            <input type="hidden" name="period_end" value="<?=htmlspecialchars($eff_end)?>">
                                            <select name="form_payment_method" class="form-control form-control-sm inline-select mr-1"><option>E-Transfer</option><option>Cheque</option><option>Cash</option></select>
                                            <button type="submit" name="execute_period_payout" class="btn btn-sm btn-warning font-weight-bold py-1 px-2"><i class="fa fa-layer-group"></i> Pay Employee Period</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge badge-success py-1 px-2"><i class="fa fa-check-circle"></i> Settled</span>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <?php foreach ($emp['lines'] as $r):
                                $rem = $r['_remaining'];
                                ++$idx;
                                $total_units = (float)$r['reg_hours'] + (float)$r['overtime_hours'] + (float)$r['travel_hours'];
                            ?>
                                <!-- LEVEL 3: SHIFT (paid / balance / pay box) -->
                                <?php $is_travel_row = (strcasecmp(trim((string)($r['category'] ?? '')), 'Travel') === 0) || ((float)$r['travel_hours'] > 0 && (float)$r['reg_hours'] == 0 && (float)$r['overtime_hours'] == 0);
                                      $is_stat_row = stripos((string)($r['category'] ?? ''), 'Statutory') === 0 || strcasecmp((string)($r['pay_type'] ?? ''), 'Statutory') === 0; ?>
                                <tr class="lvl-shift hidden" data-parent="<?=$eid?>" data-balance-metric="<?=round($rem,2)?>">
                                    <td class="text-left text-dark" style="padding-left:48px;">
                                        <input type="checkbox" class="shift-check" data-site="<?=$sid?>" data-emp="<?=$eid?>" data-user="<?=(int)$r['user_id']?>" data-site-id="<?=(int)$r['site_id']?>" data-name="<?=htmlspecialchars($emp['name'], ENT_QUOTES)?>" data-rem="<?=number_format($rem, 2, '.', '')?>" data-vac="<?=number_format((float)$r['er_vac'], 2, '.', '')?>" value="<?=(int)$r['line_id']?>" style="margin-right:8px; cursor:pointer;" title="Include this shift in documents or Pay Selected">
                                        <span class="small text-muted"><?=htmlspecialchars(date('D, M j', strtotime($r['work_date'])))?></span>
                                        <?php if ($is_travel_row): ?><span class="travel-badge"><i class="fa fa-car"></i> Travel</span><?php endif; ?>
                                        <div class="text-muted small font-weight-normal" style="padding-left:24px;"><?=htmlspecialchars($r['display_label'] ?? 'Regular')?></div>
                                    </td>
                                    <td class="text-left small text-secondary">
                                        <?= $is_stat_row ? 'Statutory equivalent' : 'Regular' ?>: <?=number_format($r['reg_hours'],2)?>h
                                        <?php if ((float)$r['overtime_hours']>0): ?><br><span class="text-danger">OT: <?=number_format($r['overtime_hours'],2)?>h</span><?php endif; ?>
                                        <?php if ((float)$r['travel_hours']>0): ?><br><span class="text-info">Travel: <?=number_format($r['travel_hours'],2)?>h</span><?php endif; ?>
                                        <br><small class="text-muted">Total: <?=number_format($total_units,2)?>h</small>
                                    </td>
                                    <td style="font-size:12px;" class="text-dark">$<?=number_format($r['total_gross'],2)?></td>
                                    <td class="text-left">
                                        <span class="text-danger">-$<?=number_format($r['_ee'],2)?></span>
                                        <div class="small text-muted" style="font-weight:600; line-height:1.5;">
                                            Income Tax: $<?=number_format($r['ee_tax'],2)?><br>
                                            CPP: $<?=number_format($r['ee_cpp'],2)?><br>
                                            EI: $<?=number_format($r['ee_ei'],2)?>
                                        </div>
                                    </td>
                                    <td class="text-left">
                                        <span class="text-primary">+$<?=number_format($r['_er'],2)?></span>
                                        <div class="small text-muted" style="font-weight:600; line-height:1.5;">
                                            CPP (ER): $<?=number_format($r['er_cpp'],2)?><br>
                                            EI (ER): $<?=number_format($r['er_ei'],2)?><br>
                                            WCB: $<?=number_format($r['er_wcb'],2)?><br>
                                            Vacation: $<?=number_format($r['er_vac'],2)?>
                                        </div>
                                    </td>
                                    <td class="text-info" style="font-size:12px;">$<?=number_format($r['_paid'],2)?></td>
                                    <td style="font-size:12px; color:<?=$rem>0.01?'#dc2626':'#16a34a'?>;">$<?=number_format($rem,2)?></td>
                                    <td>
                                        <?php if ($rem > 0.01): ?>
                                            <form method="POST" action="" class="mb-0" onsubmit="return confirm('Record a ' + this.form_payment_method.value + ' payment of $' + this.amount_to_pay.value + ' for this shift? It will not pay more than the net still owing.');">
                                                <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>">
                                                <input type="hidden" name="line_id" value="<?=$r['line_id']?>">
                                                <input type="number" step="0.01" min="0.01" name="amount_to_pay" class="form-control form-control-sm inline-pay-box text-success font-weight-bold mr-1" value="<?=round($rem,2)?>" max="<?=round($rem,2)?>">
                                                <select name="form_payment_method" class="form-control form-control-sm inline-select mr-1"><option>E-Transfer</option><option>Cheque</option><option>Cash</option></select>
                                                <button type="submit" name="execute_single_payout" class="btn btn-sm btn-success font-weight-bold py-1 px-2">Pay Net</button>
                                            </form>
                                            <?php if ($r['_paid'] > 0.01): ?><span class="badge badge-warning text-uppercase mt-1 d-block" style="font-size:9px;"><i class="fa fa-chart-pie"></i> Partially Settled</span><?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge badge-success text-uppercase py-1 px-2 d-inline-block"><i class="fa fa-check-circle"></i> Settled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $activeChq = $cheque_by_line[(int)$r['line_id']] ?? null;
                                            $sendBackBlock = pcr_block_send_back((float)$r['_paid'], $activeChq);
                                        ?>
                                        <?php if ($sendBackBlock): ?>
                                            <span class="text-muted small" title="<?=htmlspecialchars($sendBackBlock)?>">Paid / cheque — void or reverse first</span>
                                        <?php elseif ((int)($r['attendance_id'] ?? 0) > 0): ?>
                                            <form method="POST" action="" class="mb-0" onsubmit="return confirm('Send this unpaid shift back to Staff Audit? This removes the finalized row. Gross, tax, CPP, and EI on other rows are not changed.');">
                                                <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>">
                                                <input type="hidden" name="rollback_attendance_id" value="<?=$r['attendance_id']?>">
                                                <button type="submit" name="send_back_to_audit" class="btn btn-sm btn-outline-danger" style="font-size:10px; font-weight:bold;"><i class="fas fa-undo-alt"></i> Send Back</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted font-italic small">Batch Block</span>
                                        <?php endif; ?>
                                        <?php if ($rem <= 0.01): ?><input type="hidden" class="qb-buffer-source-node" value="<?=$r['line_id']?>"><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>


    <div id="chequeConfirm">
        <div class="box">
            <h5 class="font-weight-bold mb-2">Generate cheques and paystubs</h5>
            <p class="small text-muted mb-2" id="chequeConfirmSummary"></p>
            <ul id="chequeConfirmList"></ul>
            <div class="form-group mb-2">
                <label class="small font-weight-bold" for="chequeStartInput">Starting cheque number</label>
                <input type="number" id="chequeStartInput" class="form-control form-control-sm" min="1" step="1" value="<?= (int)$next_cheque_no ?>">
                <div class="small text-muted">Voided numbers are kept and are not used again. If a cheque was printed before this register, start past that number.</div>
            </div>
            <label class="small font-weight-bold">
                <input type="checkbox" id="chequeMarkPaid"> Mark these rows paid by Cheque
            </label>
            <div class="small text-muted mb-3">Pays only the stored net still owing. A second generate or Pay click will not record that amount again. Gross, CPP, EI, and tax are not recalculated.</div>
            <button type="button" class="btn btn-success font-weight-bold" id="chequeConfirmGo">Generate</button>
            <button type="button" class="btn btn-outline-secondary ml-2" id="chequeConfirmCancel">Cancel</button>
        </div>
    </div>
    <script>
        var LEDGER_VAC_PAID = <?= $vac_paid_mode ? 'true' : 'false' ?>;
        var LEDGER_CSRF = <?= json_encode(ps_csrf_token()) ?>;
        var pendingDocChecks = [];

        function paySelectedShifts(){
            var checks = document.querySelectorAll('.shift-check:checked');
            if (checks.length === 0){ alert('Tick the shifts you want to pay first.'); return; }
            var bucket = document.getElementById('paySelBucket');
            bucket.innerHTML = '';
            var total = 0, n = 0, methods = {};
            checks.forEach(function(cb){
                var row = cb.closest('tr');
                if (!row) return;
                var amtInput = row.querySelector('input[name="amount_to_pay"]');
                if (!amtInput) return;
                var amt = parseFloat(amtInput.value);
                if (!(amt > 0)) return;
                var sel = row.querySelector('select[name="form_payment_method"]');
                var method = sel ? sel.value : 'E-Transfer';
                methods[method] = true;
                var i = document.createElement('input');
                i.type='hidden'; i.name='pay_lines[]'; i.value = cb.value + ':' + amt.toFixed(2) + ':' + method;
                bucket.appendChild(i);
                total += amt; n++;
            });
            if (n === 0){ alert('None of the selected shifts have a payable amount (they may already be settled).'); return; }
            var names = Object.keys(methods);
            var via = names.length === 1 ? (' via ' + names[0]) : ' using the method shown on each row';
            document.getElementById('paySelMethod').value = names[0] || 'E-Transfer';
            if (!confirm('Pay ' + n + ' selected shift(s) totalling $' + total.toFixed(2) + via + '? Already-paid amounts are not recorded again.')) return;
            document.getElementById('paySelForm').submit();
        }

        function toggleSite(siteId, checked){
            document.querySelectorAll('.shift-check[data-site="'+siteId+'"]').forEach(function(cb){ cb.checked = checked; });
            document.querySelectorAll('.emp-select[data-site="'+siteId+'"]').forEach(function(cb){ cb.checked = checked; });
        }

        function toggleEmployee(empId, checked){
            document.querySelectorAll('.shift-check[data-emp="'+empId+'"]').forEach(function(cb){ cb.checked = checked; });
        }

        function toggleEft(btn, ev){
            if (ev) ev.stopPropagation();
            var next = btn.getAttribute('aria-pressed') !== 'true';
            var fd = new FormData();
            fd.append('csrf_token', LEDGER_CSRF);
            fd.append('save_eft_preference', '1');
            fd.append('eft_user_id', btn.getAttribute('data-user'));
            fd.append('eft_no_cheque', next ? '1' : '0');
            fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(j){
                    if (!j.ok) throw new Error(j.error || 'Could not save EFT');
                    btn.setAttribute('aria-pressed', next ? 'true' : 'false');
                    btn.classList.toggle('is-on', next);
                })
                .catch(function(e){ alert(e.message); });
        }

        function eftUsers(){
            var set = {};
            document.querySelectorAll('.eft-badge[aria-pressed="true"]').forEach(function(btn){
                set[btn.getAttribute('data-user')] = true;
            });
            return set;
        }

        function openChequeConfirm(checks){
            if (!checks.length){ alert('Tick at least one shift, or use the employee or site checkbox.'); return; }
            pendingDocChecks = checks;
            var eft = eftUsers();
            var groups = {};
            checks.forEach(function(cb){
                var uid = cb.getAttribute('data-user');
                var sid = cb.getAttribute('data-site-id') || '0';
                var key = sid + '|' + uid;
                if (!groups[key]) groups[key] = { name: cb.getAttribute('data-name') || ('Employee ' + uid), user: uid, rem: 0, vac: 0, eft: !!eft[uid] };
                groups[key].rem += parseFloat(cb.getAttribute('data-rem') || '0');
                groups[key].vac += parseFloat(cb.getAttribute('data-vac') || '0');
            });
            var list = document.getElementById('chequeConfirmList');
            list.innerHTML = '';
            var chequeCount = 0, stubOnly = 0, total = 0, names = [];
            Object.keys(groups).forEach(function(k){
                var g = groups[k];
                var face = Math.max(0, g.rem + (LEDGER_VAC_PAID ? g.vac : 0));
                var li = document.createElement('li');
                if (g.eft) {
                    stubOnly++;
                    li.textContent = g.name + ' — EFT, paystub only';
                } else if (face <= 0.009) {
                    stubOnly++;
                    li.textContent = g.name + ' — nothing owing, paystub only';
                } else {
                    chequeCount++;
                    total += face;
                    names.push(g.name);
                    li.textContent = g.name + ' — cheque $' + face.toFixed(2);
                }
                list.appendChild(li);
            });
            document.getElementById('chequeConfirmSummary').textContent =
                chequeCount + ' cheque(s)' + (names.length ? ' (' + names.join(', ') + ')' : '') +
                ', total $' + total.toFixed(2) +
                (stubOnly ? ', plus ' + stubOnly + ' paystub(s) with no cheque.' : '.');
            var markBox = document.getElementById('chequeMarkPaid');
            markBox.disabled = chequeCount === 0;
            if (chequeCount === 0) markBox.checked = false;
            document.getElementById('chequeStartInput').value = document.getElementById('payDocsChequeStart').value;
            document.getElementById('chequeConfirm').style.display = 'flex';
        }

        function generatePayDocs(){
            openChequeConfirm(Array.prototype.slice.call(document.querySelectorAll('.shift-check:checked')));
        }

        function generateForEmployee(userId){
            var checks = Array.prototype.slice.call(document.querySelectorAll('.shift-check[data-user="'+userId+'"]'));
            checks.forEach(function(cb){ cb.checked = true; });
            openChequeConfirm(checks);
        }

        function fillPayDocs(checks){
            var bucket = document.getElementById('payDocsBucket');
            bucket.innerHTML = '';
            checks.forEach(function(cb){
                var i = document.createElement('input');
                i.type='hidden'; i.name='doc_line_ids[]'; i.value=cb.value; bucket.appendChild(i);
            });
            document.querySelectorAll('.eft-badge[aria-pressed="true"]').forEach(function(btn){
                var i = document.createElement('input');
                i.type='hidden'; i.name='eft_suppress[]'; i.value=btn.getAttribute('data-user'); bucket.appendChild(i);
            });
            var modeInput = document.createElement('input');
            modeInput.type='hidden'; modeInput.name='cheque_mode';
            modeInput.value = document.getElementById('chequeMode').value;
            bucket.appendChild(modeInput);
            var issueEl = document.getElementById('chequeIssueDate');
            var issueHidden = document.getElementById('payDocsIssueDate');
            if (issueEl && issueHidden && issueEl.value) issueHidden.value = issueEl.value;
            document.getElementById('payDocsChequeStart').value = document.getElementById('chequeStartInput').value || document.getElementById('payDocsChequeStart').value;
            document.getElementById('payDocsMarkPaid').value = document.getElementById('chequeMarkPaid').checked ? '1' : '0';
            document.getElementById('payDocsNonce').value = Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
        }

        document.getElementById('chequeConfirmCancel').onclick = function(){
            document.getElementById('chequeConfirm').style.display = 'none';
        };
        document.getElementById('chequeConfirmGo').onclick = function(){
            if (!pendingDocChecks.length) return;
            this.disabled = true;
            fillPayDocs(pendingDocChecks);
            document.getElementById('payDocsForm').submit();
            setTimeout(function(){ window.location.reload(); }, 1200);
        };

        function togglePocket(id){ var el=document.getElementById(id); el.style.display=(el.style.display==='block')?'none':'block'; }

        // Collapse/expand a site or employee row. Children carry data-parent="<rowId>".
        // Collapsing a site also collapses its employees' shifts (recursive).
        function toggleRow(rowId){
            var chev = document.getElementById('chev-'+rowId);
            var kids = document.querySelectorAll('[data-parent="'+rowId+'"]');
            var willOpen = kids.length && kids[0].classList.contains('hidden');
            kids.forEach(function(kid){
                if (willOpen){
                    kid.classList.remove('hidden');
                } else {
                    kid.classList.add('hidden');
                    // if this child is itself a parent (an employee), collapse its shifts too
                    var rid = kid.getAttribute('data-row-id');
                    if (rid){
                        document.querySelectorAll('[data-parent="'+rid+'"]').forEach(function(g){ g.classList.add('hidden'); });
                        var cc = document.getElementById('chev-'+rid); if (cc) cc.classList.remove('open');
                    }
                }
            });
            if (chev) chev.classList.toggle('open', willOpen);
        }

        document.addEventListener("DOMContentLoaded", function(){
            var bucket=document.getElementById('qb_hidden_fields_bucket');
            document.querySelectorAll('.qb-buffer-source-node').forEach(function(node){
                var input=document.createElement('input'); input.type='hidden'; input.name='qb_line_ids[]'; input.value=node.value; bucket.appendChild(input);
            });
        });
    </script>
</body>
</html>