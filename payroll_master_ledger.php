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
$ledgerAuditLib = __DIR__ . '/engine/attendance_audit_lib.php';
if (is_file($ledgerAuditLib)) {
    require_once $ledgerAuditLib;
}
require_once __DIR__ . '/engine/ledger_matrix_lib.php';
require_once 'payroll_scope.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
require_once __DIR__ . '/quickbooks/qb_export_lib.php';
ps_require_payroll_admin();
$user_org_id = ps_selected_org($pdo);
$ledgerCpp2 = (function_exists('pe_t4127_col') && pe_t4127_col($pdo, 'payroll_finalized', 'cpp2'))
    ? 'IFNULL(f.cpp2,0) AS ee_cpp2'
    : '0 AS ee_cpp2';
$ledgerCpp2Er = (function_exists('pe_t4127_col') && pe_t4127_col($pdo, 'payroll_finalized', 'cpp2_er'))
    ? 'IFNULL(f.cpp2_er,0) AS er_cpp2'
    : '0 AS er_cpp2';
$ledgerHasStatApproval = function_exists('pe_t4127_col') && pe_t4127_col($pdo, 'payroll_finalized', 'stat_approval_status');
$ledgerApprovalSelect = $ledgerHasStatApproval
    ? "COALESCE(f.stat_approval_status,'') AS stat_approval_status"
    : "'' AS stat_approval_status";
$ledgerApprovalExclude = $ledgerHasStatApproval
    ? "COALESCE(f.stat_approval_status,'') <> 'pending'"
    : '1=1';

if (file_exists('engine/menu_generator.php')) { require_once 'engine/menu_generator.php'; }

// ---------------------------------------------------------------- helpers
/** Employee statutory deductions for one finalized line, from stored columns. */
function ee_deduct(array $r): float {
    return (float)$r['ee_cpp'] + (float)($r['ee_cpp2'] ?? 0) + (float)$r['ee_ei'] + (float)$r['ee_tax'];
}
/** Net payable for one finalized line. */
function net_payable_line(array $r): float {
    $net = (float)$r['total_gross'] - ee_deduct($r);
    if (strcasecmp((string)($r['category'] ?? ''), 'Deduction') === 0) {
        return $net;
    }
    return max(0, $net);
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
/** Re-derive a master's status from its logged payments. */
function refresh_master_status(PDO $pdo, int $master_id): void {
    $net  = (float)$pdo->query("SELECT net_payable FROM payroll_masters WHERE id = " . (int)$master_id)->fetchColumn();
    $paid = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payroll_payments WHERE payroll_id = " . (int)$master_id)->fetchColumn();
    $status = ($paid <= 0.01) ? 'Unpaid' : (($paid + 0.01 >= $net) ? 'Paid' : 'Partial');
    $pdo->prepare("UPDATE payroll_masters SET status = ? WHERE id = ?")->execute([$status, $master_id]);
}
/** Apply one payment to a finalized line (and its mirror balance row + master + payment log). */
function apply_payment(PDO $pdo, int $line_id, float $amount, string $method, int $org_id): float {
    global $ledgerCpp2, $ledgerCpp2Er, $ledgerApprovalSelect;
    // full line with stored columns
    $q = $pdo->prepare(
        "SELECT f.id AS line_id, f.attendance_id, f.user_id, f.site_id, f.payroll_id, f.work_date,
                f.pay_period, f.pay_period_end, f.total_gross, f.paid_amount AS db_paid_amount,
                f.status AS line_status, {$ledgerApprovalSelect},
                f.cpp AS ee_cpp, {$ledgerCpp2}, f.ei AS ee_ei, f.income_tax AS ee_tax,
                f.cpp_er AS er_cpp, {$ledgerCpp2Er}, f.ei_er AS er_ei, f.wcb AS er_wcb, f.vac AS er_vac
         FROM payroll_finalized f
         INNER JOIN users u ON u.id=f.user_id AND u.org_id=?
         INNER JOIN sites s ON s.SId=f.site_id AND s.org_id=u.org_id
         WHERE f.id = ? LIMIT 1"
    );
    $q->execute([$org_id, $line_id]);
    $line = $q->fetch(PDO::FETCH_ASSOC);
    if (!$line) { return 0.0; }
    if (strcasecmp((string)($line['stat_approval_status'] ?? ''), 'pending') === 0) { return 0.0; }

    $net       = net_payable_line($line);
    $paid_now  = (float)$line['db_paid_amount'];
    $remaining = max(0, $net - $paid_now);

    // SERVER-SIDE CAP: never record more than what is owed
    $amount = min($amount, $remaining);
    if ($amount <= 0.005) { return 0.0; }

    $new_paid       = $paid_now + $amount;
    $fully          = (round($new_paid, 2) + 0.005 >= round($net, 2));
    $new_status     = $fully ? 'paid'  : 'pending';   // payroll_finalized.status enum
    $new_pay_status = $fully ? 'Paid'  : 'Partial';   // payroll_finalized.payment_status enum

    $pdo->prepare(
        "UPDATE payroll_finalized
            SET status = ?, payment_status = ?, paid_amount = ?, last_paid_date = CURRENT_DATE(), paid_at = NOW()
          WHERE id = ?"
    )->execute([$new_status, $new_pay_status, $new_paid, $line_id]);

    // mirror into payroll_balance (only if a row exists for this shift)
    if (!empty($line['attendance_id'])) {
        $pdo->prepare(
            "UPDATE payroll_balance
                SET paid_amount = COALESCE(paid_amount,0) + ?,
                    balance_remaining = GREATEST(0, COALESCE(balance_remaining, ?) - ?),
                    status = ?, last_paid_date = CURRENT_DATE(), paid_at = NOW()
              WHERE attendance_id = ?"
        )->execute([$amount, $net, $amount, $new_status, $line['attendance_id']]);
    }

    // attach payment to the period master and log it
    $master_id = resolve_master_id($pdo, $line, $org_id);
    $ref = "TXN-" . strtoupper(uniqid());
    $pdo->prepare(
        "INSERT INTO payroll_payments (payroll_id, recipient, amount, date_paid, payment_type, method, ref_no)
         VALUES (?, 'Employee', ?, CURRENT_DATE(), 'employee_net', ?, ?)"
    )->execute([$master_id, $amount, $method, $ref]);
    refresh_master_status($pdo, $master_id);

    return $amount;
}

// ---------------------------------------------------------------- filters
$staff_q = $pdo->prepare("SELECT id, Name FROM users WHERE org_id=? ORDER BY Name ASC");
$staff_q->execute([$user_org_id]); $staff_list = $staff_q->fetchAll(PDO::FETCH_ASSOC);
$sites_q = $pdo->prepare("SELECT Sid, nick_name, province FROM sites WHERE org_id=? ORDER BY nick_name ASC");
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
$stat_pay        = $_GET['stat_pay'] ?? 'all';
if (!in_array($stat_pay, ['all', 'only'], true)) { $stat_pay = 'all'; }
$province_options = ledger_province_options($sites_list);
$province_filter = ledger_province_param((string)($_GET['province'] ?? 'all'), $province_options);
$sites_for_location = array_values(array_filter($sites_list, function ($site) use ($province_filter) {
    return ledger_site_in_province($site['province'] ?? '', $province_filter);
}));
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
    $__siteOk = false;
    foreach ($sites_for_location as $__siteRow) {
        if ((string)$__siteRow['Sid'] === (string)$site_filter) { $__siteOk = true; break; }
    }
    if (!$__siteOk) { $site_filter = 'all'; }
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
       . "&stat_pay=" . urlencode($stat_pay) . "&province=" . urlencode($province_filter)
       . "&period_mode=" . urlencode($period_mode);

// ---------------------------------------------------------------- ROLLBACK
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_back_to_audit'])) {
    $target = (int)$_POST['rollback_attendance_id'];
    if ($target > 0) {
        $auth = $pdo->prepare("SELECT COUNT(*) FROM attendance a
            INNER JOIN users u ON u.id=a.user_id AND u.org_id=?
            INNER JOIN sites s ON s.SId=a.site_id AND s.org_id=u.org_id WHERE a.id=?");
        $auth->execute([$user_org_id, $target]);
        if (!$auth->fetchColumn()) { http_response_code(403); exit('Attendance row is outside your organization.'); }
        try {
            $pdo->beginTransaction();
            $aaBefore = function_exists('aa_snapshot') ? aa_snapshot($pdo, $target) : null;
            $pdo->prepare("UPDATE attendance SET status = 4, payout_status = 0 WHERE id = ?")->execute([$target]);
            if (function_exists('aa_log')) {
                aa_log($pdo, 'unfinalize', $target, $aaBefore, null, 'Send back to audit');
            }
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
    $method  = $_POST['form_payment_method'] ?? 'E-Transfer';
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
    $method = $_POST['form_payment_method'] ?? 'E-Transfer';
    // pay_lines[] holds "lineId:amount" for each ticked shift
    $pairs  = $_POST['pay_lines'] ?? [];
    $paid_count = 0;
    if (!empty($pairs)) {
        try {
            $pdo->beginTransaction();
            foreach ((array)$pairs as $pair) {
                $parts = explode(':', (string)$pair);
                if (count($parts) !== 2) continue;
                $lid = (int)$parts[0];
                $amt = (float)$parts[1];
                if ($lid > 0 && $amt > 0) {
                    apply_payment($pdo, $lid, $amt, $method, $user_org_id);
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
    $method = $_POST['form_payment_method'] ?? 'E-Transfer';
    // Effective range is the bucket clamped to the visible window (set per band at render time).
    $pstart = $_POST['period_start'] ?? $from_date;
    $pend   = $_POST['period_end']   ?? $to_date;
    if ($uid > 0) {
        try {
            $pdo->beginTransaction();
            ps_assert_user($pdo, $uid, $user_org_id);
            $w = ["f.user_id = ?", "f.work_date BETWEEN ? AND ?", "u.org_id = ?", "s.org_id = u.org_id", $ledgerApprovalExclude];
            $p = [$uid, $pstart, $pend, $user_org_id];
            if ($site_filter !== 'all' && $site_filter !== '') { $w[] = "f.site_id = ?"; $p[] = (int)$site_filter; }
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
                f.work_date, f.pay_period_end, f.last_paid_date, f.total_gross, f.status AS line_status, {$ledgerApprovalSelect},
                f.cpp AS ee_cpp, {$ledgerCpp2}, f.ei AS ee_ei, f.income_tax AS ee_tax,
                f.cpp_er AS er_cpp, {$ledgerCpp2Er}, f.ei_er AS er_ei, f.wcb AS er_wcb, f.vac AS er_vac
         FROM payroll_finalized f
         INNER JOIN users u ON f.user_id = u.id
         LEFT  JOIN sites s ON f.site_id = s.Sid
         WHERE f.id IN ($in) AND u.org_id = ? AND s.org_id = u.org_id"
    );
    $q->execute(array_merge($ids, [$user_org_id]));
    $lines = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($lines) !== count($ids)) { http_response_code(403); exit('One or more export rows are outside your organization.'); }
    $lines = array_values(array_filter($lines, static function ($r) {
        return strcasecmp((string)($r['stat_approval_status'] ?? ''), 'pending') !== 0;
    }));

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
        $groups[$key]['ee_cpp'] += (float)$r['ee_cpp'] + (float)($r['ee_cpp2'] ?? 0);
        $groups[$key]['ee_ei']  += (float)$r['ee_ei'];
        $groups[$key]['ee_tax'] += (float)$r['ee_tax'];
        $groups[$key]['er_cpp'] += (float)$r['er_cpp'] + (float)($r['er_cpp2'] ?? 0);
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
$where  = ["(f.work_date BETWEEN ? AND ? OR ((COALESCE(f.category,'') = 'Statutory Holiday' OR COALESCE(f.pay_type,'') = 'Statutory') AND f.pay_period_end BETWEEN ? AND ?))", "u.org_id = ?", "s.org_id = u.org_id"];
$params = [$from_date, $to_date, $from_date, $to_date, $user_org_id];
if ($site_filter !== 'all' && $site_filter !== '')     { $where[] = "f.site_id = ?"; $params[] = (int)$site_filter; }
if ($province_filter !== 'all') {
    $__provIds = [];
    foreach ($sites_for_location as $__siteRow) { $__provIds[] = (int)$__siteRow['Sid']; }
    if (!$__provIds) {
        $where[] = '1=0';
    } else {
        $where[] = 'f.site_id IN (' . implode(',', array_fill(0, count($__provIds), '?')) . ')';
        foreach ($__provIds as $__provId) { $params[] = $__provId; }
    }
}
if ($employee_filter !== 'all' && $employee_filter !== '') { $where[] = "f.user_id = ?"; $params[] = (int)$employee_filter; }
if ($view_category === 'travel')   { $where[] = "f.travel_hours > 0"; }
elseif ($view_category === 'overtime') { $where[] = "f.overtime_hours > 0"; }

$payCols = [];
foreach ($pdo->query('SHOW COLUMNS FROM users') as $col) {
    $payCols[(string)$col['Field']] = true;
}
$payExtra = '';
foreach (['payment_method', 'bank_name', 'bank_institution', 'bank_transit', 'bank_account'] as $colName) {
    if (!empty($payCols[$colName])) {
        $payExtra .= ', u.' . $colName;
    }
}
$sql = "SELECT
            f.id AS line_id, f.attendance_id, f.user_id, f.site_id, f.payroll_id,
            u.Name AS employee_name, s.nick_name AS site_name $payExtra,
            f.status AS row_lifecycle, f.status AS line_status, {$ledgerApprovalSelect}, f.payment_status, f.paid_amount AS db_paid_amount,
            f.reg_hours, f.overtime_hours, f.travel_hours, f.hourly_rate, f.total_gross,
            f.cpp AS ee_cpp, {$ledgerCpp2}, f.ei AS ee_ei, f.income_tax AS ee_tax,
            f.cpp_er AS er_cpp, {$ledgerCpp2Er}, f.ei_er AS er_ei, f.wcb AS er_wcb, f.vac AS er_vac,
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
    $remaining = (strcasecmp((string)($r['category'] ?? ''), 'Deduction') === 0)
        ? ($net - $paid)
        : max(0, $net - $paid);
    if ($balance_scope === 'outstanding' && $remaining <= 0.01) continue;
    if ($balance_scope === 'zero'        && $remaining > 0.01)  continue;

    $needsApproval = strcasecmp((string)($r['stat_approval_status'] ?? ''), 'pending') === 0;
    $payRemaining = $needsApproval ? 0.0 : $remaining;
    $r['_needs_approval'] = $needsApproval;
    $r['_ee']        = ee_deduct($r);
    $r['_er']        = (float)$r['er_cpp'] + (float)($r['er_cpp2'] ?? 0) + (float)$r['er_ei'] + (float)$r['er_wcb'] + (float)$r['er_vac'];
    $r['_net']       = $net;
    $r['_paid']      = $paid;
    $r['_remaining'] = $remaining;
    $is_stat         = stripos((string)($r['category'] ?? ''), 'Statutory') === 0 || strcasecmp((string)($r['pay_type'] ?? ''), 'Statutory') === 0;
    $is_alloc        = ledger_row_is_stat_alloc($r['category'] ?? '', $r['pay_type'] ?? '');
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
                                             'pay_method'=>(string)($r['payment_method'] ?? ''),
                                             't_gross'=>0,'t_net'=>0,'t_paid'=>0,'t_rem'=>0,'t_reg_h'=>0,'t_ot_h'=>0,'t_travel_h'=>0,'t_stat_h'=>0,'t_stat_cost'=>0,
                                             't_ee'=>0,'t_ee_tax'=>0,'t_ee_cpp'=>0,'t_ee_ei'=>0,
                                             't_er'=>0,'t_er_cpp'=>0,'t_er_ei'=>0,'t_er_wcb'=>0,'t_er_vac'=>0,
                                             'has_stat'=>false, 't_stat_alloc'=>0, 'lines'=>[]];
    }

    if ($is_alloc) {
        $sites_tree[$sname]['emps'][$uid]['has_stat'] = true;
        $sites_tree[$sname]['emps'][$uid]['t_stat_alloc'] += (float)$r['total_gross'];
    }
    $sites_tree[$sname]['emps'][$uid]['lines'][] = $r;
    foreach ([[&$sites_tree[$sname]], [&$sites_tree[$sname]['emps'][$uid]]] as $ref) {
        $ref[0]['t_gross']    += (float)$r['total_gross'];
        $ref[0]['t_net']      += $net;
        $ref[0]['t_paid']     += $paid;
        $ref[0]['t_rem']      += $payRemaining;
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
    $period_tot['rem']   += $payRemaining;
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
// Base CPP is stored once per pay period. Shift rows stay at $0; roll that period amount into the totals.
// CPP2 is already split onto the shift rows, so it is not added again here.
if (function_exists('pe_read_period_stats') && $from_date && $to_date) {
    $cppStats = pe_read_period_stats($pdo, $from_date, $to_date);
    foreach ($sites_tree as &$siteRow) {
        $sid = (int)($siteRow['site_id'] ?? 0);
        foreach ($siteRow['emps'] as &$empRow) {
            $k = ((int)$empRow['user_id']) . '|' . $sid;
            if (!isset($cppStats[$k])) continue;
            $cpp = round((float)$cppStats[$k]['cpp'], 2);
            $cppEr = round((float)$cppStats[$k]['cpp_er'], 2);
            if ($cpp < 0.005 && $cppEr < 0.005) continue;
            $empRow['t_ee_cpp'] += $cpp;
            $empRow['t_ee'] += $cpp;
            $empRow['t_net'] = max(0, $empRow['t_net'] - $cpp);
            $empRow['t_rem'] = max(0, $empRow['t_rem'] - $cpp);
            $empRow['t_er_cpp'] += $cppEr;
            $empRow['t_er'] += $cppEr;
            $siteRow['t_ee_cpp'] += $cpp;
            $siteRow['t_ee'] += $cpp;
            $siteRow['t_net'] = max(0, $siteRow['t_net'] - $cpp);
            $siteRow['t_rem'] = max(0, $siteRow['t_rem'] - $cpp);
            $siteRow['t_er_cpp'] += $cppEr;
            $siteRow['t_er'] += $cppEr;
            $period_tot['ee_cpp'] += $cpp;
            $period_tot['ee'] += $cpp;
            $period_tot['net'] = max(0, $period_tot['net'] - $cpp);
            $period_tot['rem'] = max(0, $period_tot['rem'] - $cpp);
            $period_tot['er_cpp'] += $cppEr;
            $period_tot['er'] += $cppEr;
        }
    }
    unset($empRow, $siteRow);
}
// sort sites by name, employees within each by name
$pendingStatApproval = 0;
foreach ($raw as $pendingRow) {
    if (strcasecmp((string)($pendingRow['stat_approval_status'] ?? ''), 'pending') === 0) {
        $pendingStatApproval++;
    }
}
uksort($sites_tree, fn($a,$b) => strcmp($a,$b));
foreach ($sites_tree as &$st) {
    uasort($st['emps'], fn($a,$b)=>strcmp($a['name'],$b['name']));
    foreach ($st['emps'] as &$empSort) {
        usort($empSort['lines'], static function ($a, $b) {
            $af = stripos((string)($a['comments'] ?? ''), 'FLAG:') !== false ? 0 : 1;
            $bf = stripos((string)($b['comments'] ?? ''), 'FLAG:') !== false ? 0 : 1;
            return $af <=> $bf;
        });
    }
    unset($empSort);
}
unset($st);
$stat_summary = ledger_stat_summary($sites_tree);
if ($stat_pay === 'only') {
    [$sites_tree, $period_tot] = ledger_strip_non_stat($sites_tree, $period_tot);
}
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
        tr.lvl-shift.stat-holiday td { background:#fff3b0 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        tr.lvl-shift.stat-flag td { background:#fde68a !important; outline:2px solid #d97706; }
        .stat-pay-mark { background:#fff3b0; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        .travel-badge { display:inline-block; background:#fef3c7; color:#b45309; border:1px solid #fcd34d; font-size:9px; font-weight:800; text-transform:uppercase; padding:1px 7px; border-radius:9px; margin-left:6px; letter-spacing:.3px; }
        tr.lvl-site .sub, tr.lvl-emp .sub { color:#64748b; font-weight:600; font-size:10px; }
        .name-cell { text-align:left; padding-left:14px; }
        tr.lvl-emp .name-cell { padding-left:30px; }
        .chev { display:inline-block; transition:transform .15s ease; margin-right:8px; color:#64748b; }
        .ledger-expand-btn { margin-left:6px; background:transparent; color:#fff; border:1px solid #94a3b8; border-radius:4px; font-size:10px; font-weight:800; padding:2px 8px; cursor:pointer; text-transform:none; line-height:1.4; }
        .ledger-expand-btn:hover { background:#fff; color:#0f172a; }
        .chev.open { transform:rotate(90deg); }
        tr.hidden { display:none; }
        @media print { tr.hidden { display:table-row !important; } .chev{display:none;} }
        .toggle-pocket-btn { background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 4px; padding: 2px 6px; cursor: pointer; color: #475569; font-size: 9px; font-weight: 800; }
        .inline-tray { display: none; background: #fafafa; border: 1px dashed #cbd5e1; padding: 8px; margin-top: 5px; border-radius: 6px; text-align: left; font-size: 10px; }
        .inline-pay-box { width: 80px; text-align: right; font-weight: bold; height: 28px; font-size: 11px; display: inline-block; padding: 4px; }
        .inline-select { width: 95px; height: 28px; font-size: 11px; font-weight: bold; display: inline-block; padding: 2px; }
        .badge-link { font-size:9px; }
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
                <button type="button" id="genChequesBtn" onclick="generatePayDocs('cheques')" class="btn btn-success font-weight-bold shadow-sm px-3 mr-2 mb-1" style="font-size:11px;" title="Cheque file only. Pre-printed stock gets the date, name, amount, and memo on the cheque form.">
                    <i class="fas fa-money-check-alt mr-1"></i> Print cheques
                </button>
                <button type="button" id="genStubsBtn" onclick="generatePayDocs('stubs')" class="btn btn-outline-success font-weight-bold shadow-sm px-3 mr-2 mb-1" style="font-size:11px;" title="Paystub file only, with no cheque page.">
                    <i class="fas fa-file-invoice-dollar mr-1"></i> Print paystubs
                </button>
                <button type="button" id="emailStubsBtn" onclick="generatePayDocs('email')" class="btn btn-outline-success font-weight-bold shadow-sm px-3 mr-2 mb-1" style="font-size:11px;" title="Email each selected employee their own paystub.">
                    <i class="fas fa-envelope mr-1"></i> Email paystubs
                </button>
                <button type="button" id="bankFileBtn" onclick="generateBankFile()" class="btn btn-outline-primary font-weight-bold shadow-sm px-3 mr-2 mb-1" style="font-size:11px;">
                    <i class="fas fa-university mr-1"></i> Direct deposit file
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
            <form id="bankFileForm" method="POST" action="direct_deposit_file.php" target="_blank" style="display:none;">
                <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>">
                <input type="hidden" name="org_id" value="<?=$user_org_id?>">
                <input type="hidden" name="period_start" value="<?=htmlspecialchars($from_date)?>">
                <input type="hidden" name="period_end" value="<?=htmlspecialchars($to_date)?>">
                <input type="hidden" name="issue_date" id="bankFileIssueDate" value="<?=htmlspecialchars($chequeIssueDefault)?>">
                <div id="bankFileBucket"></div>
            </form>
            <form id="payDocsForm" method="POST" action="generate_pay_documents.php" target="_blank" style="display:none;">
                <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>">
                <input type="hidden" name="org_id" value="<?=$user_org_id?>">
                <input type="hidden" name="period_start" value="<?=htmlspecialchars($from_date)?>">
                <input type="hidden" name="period_end" value="<?=htmlspecialchars($to_date)?>">
                <input type="hidden" name="issue_date" id="payDocsIssueDate" value="<?=htmlspecialchars($chequeIssueDefault)?>">
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
            <div class="alert alert-success py-2 small font-weight-bold"><i class="fa fa-check-circle"></i>
                <?php
                if ($_GET['msg']==='period_paid') echo 'Period balance settled.';
                elseif ($_GET['msg']==='rollback_success') echo 'Record sent back to audit.';
                elseif ($_GET['msg']==='selected_paid') echo 'Paid ' . (int)($_GET['n'] ?? 0) . ' selected shift(s).';
                else echo 'Payment recorded.';
                ?>
            </div>
        <?php endif; ?>

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
                        <?php foreach($sites_for_location as $s): ?>
                            <option value="<?=$s['Sid']?>" <?=((string)$site_filter===(string)$s['Sid'])?'selected':''?>><?=htmlspecialchars($s['nick_name'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="font-weight-bold small text-muted text-uppercase mb-1">Province</label>
                    <select name="province" class="form-control form-control-sm font-weight-bold" onchange="this.form.site_id.value='all'; this.form.submit();">
                        <option value="all" <?=$province_filter==='all'?'selected':''?>>All Provinces</option>
                        <?php foreach ($province_options['codes'] as $__code): ?>
                            <option value="<?=htmlspecialchars($__code)?>" <?=$province_filter===$__code?'selected':''?>><?=htmlspecialchars($__code)?></option>
                        <?php endforeach; ?>
                        <?php if ($province_options['unmapped'] || $province_filter === 'unmapped'): ?>
                            <option value="unmapped" <?=$province_filter==='unmapped'?'selected':''?>>Unmapped</option>
                        <?php endif; ?>
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
                    <label class="font-weight-bold small text-uppercase mb-1" style="color:#a16207;"><i class="fa fa-calendar-check"></i> Stat pay</label>
                    <select name="stat_pay" class="form-control form-control-sm font-weight-bold" style="background:#fff3b0;">
                        <option value="all" <?=$stat_pay==='all'?'selected':''?>>All employees</option>
                        <option value="only" <?=$stat_pay==='only'?'selected':''?>>Stat pay only</option>
                    </select>
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

        <?php
        $statHolLib = __DIR__ . '/payroll_engine/stat_holidays.php';
        if (is_file($statHolLib)) { require_once $statHolLib; }
        if (function_exists('sh_review_banner_html')) { echo sh_review_banner_html($pdo, (int)$user_org_id, $from_date, $to_date); }
        ?>
        <?php if (!empty($pendingStatApproval)): ?>
        <div class="alert alert-warning font-weight-bold"><?= (int)$pendingStatApproval ?> stat holiday lines need approval before they can be paid. <a href="<?= htmlspecialchars(function_exists('sh_stat_pay_href') ? sh_stat_pay_href($pdo, (int)$user_org_id) : ('generate_stat_shifts.php?preview_period=1&org_id=' . (int)$user_org_id)) ?>">Open Stat Holiday Pay</a></div>
        <?php endif; ?>
        <?php if (ledger_stat_visible((float)$stat_summary['stat_total'])): ?>
        <div class="mb-2 font-weight-bold" style="color:#854d0e;">
            <span class="stat-pay-mark"><?= (int)$stat_summary['employees'] ?> employees with stat pay, $<?= number_format($stat_summary['stat_total'], 2) ?> total</span>
            <?php if ($stat_pay === 'only'): ?> · showing only those employees<?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="card shadow-sm border-0 rounded-lg overflow-hidden">
            <table class="table table-ledger mb-0 text-center" id="ledgerCoreMatrixTable">
                <thead>
                    <tr>
                        <th class="text-left">Site / Employee / Shift <?= ledger_expand_controls() ?></th>
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
                            <div class="sub">Regular <?=number_format($period_tot['reg_h'],2)?>h · OT <?=number_format($period_tot['ot_h'],2)?>h · Travel <?=number_format($period_tot['travel_h'],2)?>h<?php if (ledger_stat_visible((float)$period_tot['stat_h'])): ?> · Statutory <?=number_format($period_tot['stat_h'],2)?>h<?php endif; ?></div>
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
                            <div class="sub" style="font-size:9px;"><?php if (ledger_stat_visible((float)$period_tot['stat_cost'])): ?><span class="stat-pay-mark">Stat pay cost $<?=number_format($period_tot['stat_cost'],2)?></span> · <?php endif; ?>Total employer cost $<?=number_format($period_tot['gross']+$period_tot['er'],2)?></div>
                        </td>
                        <td class="text-info">$<?=number_format($period_tot['paid'],2)?></td>
                        <td style="color:<?=$period_tot['rem']>0.01?'#fca5a5':'#86efac'?>;">$<?=number_format($period_tot['rem'],2)?></td>
                        <td colspan="2" class="sub small">All sites combined<?php if ($period_tot['travel_h'] > 0): ?> · <span style="color:#fcd34d;"><i class="fa fa-car"></i> <?=number_format($period_tot['travel_h'],2)?>h travel</span><?php endif; ?></td>
                    </tr>

                    <?php $idx = 0; $sx = 0; foreach ($sites_tree as $site): $sx++; $sid = 'site'.$sx; ?>
                        <!-- LEVEL 1: SITE -->
                        <tr class="lvl-site" data-row-id="<?=$sid?>" onclick="toggleRow('<?=$sid?>')">
                            <td class="name-cell" style="text-align:left;">
                                <input type="checkbox" class="site-check" data-site="<?=$sid?>" onclick="event.stopPropagation(); toggleSite('<?=$sid?>', this.checked);" style="margin-right:8px; cursor:pointer;" title="Select all employees at this site">
                                <span class="chev" id="chev-<?=$sid?>" onclick="event.stopPropagation(); toggleRow('<?=$sid?>');">&#9656;</span>
                                <i class="fa fa-building" style="color:#60a5fa;"></i> <strong><?=htmlspecialchars($site['name'])?></strong>
                                <span class="sub"> · <?=count($site['emps'])?> employee(s)</span>
                            </td>
                            <td class="small">R <?=number_format($site['t_reg_h'],2)?>h<br>OT <?=number_format($site['t_ot_h'],2)?>h<br>Travel <?=number_format($site['t_travel_h'],2)?>h<?php if (ledger_stat_visible((float)$site['t_stat_h'])): ?><br>Stat <?=number_format($site['t_stat_h'],2)?>h<?php endif; ?></td>
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
                                <div class="sub" style="font-size:9px;"><?php if (ledger_stat_visible((float)$site['t_stat_cost'])): ?><span class="stat-pay-mark">Stat pay $<?=number_format($site['t_stat_cost'],2)?></span> · <?php endif; ?>Employer cost $<?=number_format($site['t_gross']+$site['t_er'],2)?></div>
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
                                    <span class="chev" id="chev-<?=$eid?>" onclick="toggleRow('<?=$eid?>')">&#9656;</span>
                                    <i class="fa fa-user" style="color:#94a3b8;"></i> <?=htmlspecialchars($emp['name'])?>
                                    <span class="sub"> · <?=count($emp['lines'])?> shift(s)<?php if ($emp['t_travel_h'] > 0): ?> · <i class="fa fa-car" style="color:#b45309;"></i> <?=number_format($emp['t_travel_h'],2)?>h travel<?php endif; ?></span>
                                    <?php $isDirect = (($emp['pay_method'] ?? '') === 'direct_deposit'); ?>
                                    <label class="eft-toggle" style="margin-left:10px; font-size:9px; font-weight:700; color:#2563eb; cursor:pointer;" title="Pay this person by direct deposit. The cheque is skipped and they are included in the bank file.">
                                        <input type="checkbox" class="eft-check" data-user="<?=(int)$emp['user_id']?>" <?= $isDirect ? 'checked' : '' ?> style="vertical-align:middle;"> Direct deposit
                                    </label>
                                </td>
                                <td class="small">R <?=number_format($emp['t_reg_h'],2)?>h<br>OT <?=number_format($emp['t_ot_h'],2)?>h<br>Travel <?=number_format($emp['t_travel_h'],2)?>h<?php if (ledger_stat_visible((float)$emp['t_stat_h'])): ?><br>Stat <?=number_format($emp['t_stat_h'],2)?>h<?php endif; ?></td>
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
                                    <div class="sub" style="font-size:9px;"><?php if (ledger_stat_visible((float)$emp['t_stat_cost'])): ?><span class="stat-pay-mark">Stat pay $<?=number_format($emp['t_stat_cost'],2)?></span> · <?php endif; ?>Employer cost $<?=number_format($emp['t_gross']+$emp['t_er'],2)?></div>
                                </td>
                                <td class="text-info">$<?=number_format($emp['t_paid'],2)?></td>
                                <td style="color:<?=$emp['t_rem']>0.01?'#dc2626':'#16a34a'?>; font-weight:800;">$<?=number_format($emp['t_rem'],2)?></td>
                                <td colspan="2">
                                    <?php $emp_line_ids = implode(',', array_map(fn($line)=>(int)$line['line_id'], $emp['lines'])); ?>
                                    <a class="btn btn-sm btn-outline-dark font-weight-bold py-1 px-2 mb-1" target="_blank" href="manual_pay_entry.php?lock=1&user_id=<?=(int)$emp['user_id']?>&site_id=<?=(int)$site['site_id']?>&entry_date=<?=urlencode($eff_end)?>&org_id=<?=(int)$user_org_id?>"><i class="fa fa-hand-holding-usd"></i> Manual Pay</a>
                                    <a class="btn btn-sm btn-outline-success font-weight-bold py-1 px-2 mb-1 stub-cheque-link cheque-link" target="_blank" href="generate_pay_documents.php?doc_part=cheques&doc_line_ids=<?=urlencode($emp_line_ids)?>&org_id=<?=$user_org_id?>&period_start=<?=urlencode($from_date)?>&period_end=<?=urlencode($to_date)?>&issue_date=<?=urlencode($chequeIssueDefault)?>"><i class="fa fa-money-check-alt"></i> Cheque</a>
                                    <a class="btn btn-sm btn-outline-success font-weight-bold py-1 px-2 mb-1 stub-cheque-link" target="_blank" href="generate_pay_documents.php?doc_part=stubs&doc_line_ids=<?=urlencode($emp_line_ids)?>&org_id=<?=$user_org_id?>&period_start=<?=urlencode($from_date)?>&period_end=<?=urlencode($to_date)?>&issue_date=<?=urlencode($chequeIssueDefault)?>"><i class="fa fa-file-invoice-dollar"></i> Paystub</a>
                                    <a class="btn btn-sm btn-outline-primary font-weight-bold py-1 px-2 mb-1" target="_blank" href="generate_t4.php?year=<?=date('Y',strtotime($to_date))?>&emp_id=<?=$emp['user_id']?>&org_id=<?=$user_org_id?>"><i class="fa fa-file-invoice"></i> T4</a>
                                    <?php if ($emp['t_rem'] > 0.01): ?>
                                        <form method="POST" action="" class="mb-0 d-inline-flex align-items-center"
                                              onsubmit="return confirm('Settle this employee\'s full period balance of $<?=number_format($emp['t_rem'],2)?>?');">
                                            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>">
                                            <input type="hidden" name="period_user_id" value="<?=$emp['user_id']?>">
                                            <input type="hidden" name="period_start" value="<?=htmlspecialchars($eff_start)?>">
                                            <input type="hidden" name="period_end" value="<?=htmlspecialchars($eff_end)?>">
                                            <select name="form_payment_method" class="form-control form-control-sm inline-select mr-1"><option>E-Transfer</option><option>Cheque</option><option>Cash</option><option>Direct deposit</option></select>
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
                                      $is_stat_row = stripos((string)($r['category'] ?? ''), 'Statutory') === 0 || strcasecmp((string)($r['pay_type'] ?? ''), 'Statutory') === 0;
                                      $is_holiday_row = strcasecmp((string)($r['category'] ?? ''), 'Statutory Holiday') === 0; ?>
                                <tr class="lvl-shift hidden<?= $is_holiday_row ? ' stat-holiday' : '' ?><?= !empty($r['_needs_approval']) && stripos((string)($r['comments'] ?? ''), 'FLAG:') !== false ? ' stat-flag' : '' ?>" data-parent="<?=$eid?>" data-balance-metric="<?=round($rem,2)?>">
                                    <td class="text-left text-dark" style="padding-left:48px;">
                                        <input type="checkbox" class="shift-check" data-site="<?=$sid?>" data-emp="<?=$eid?>" data-user="<?=(int)$r['user_id']?>" value="<?=(int)$r['line_id']?>" style="margin-right:8px; cursor:pointer;">
                                        <span class="small text-muted"><?=htmlspecialchars(date('D, M j', strtotime($r['work_date'])))?></span>
                                        <?php if (!empty($r['_needs_approval'])): ?><span class="badge badge-warning">Needs approval</span><?php endif; ?>
                                        <?php if (preg_match('/FLAG:\s*([^.]+)/', (string)($r['comments'] ?? ''), $flagMatch)): ?><div class="small font-weight-bold" style="color:#92400e;"><?=htmlspecialchars(trim($flagMatch[1]))?></div><?php endif; ?>
                                        <?php if ($is_travel_row): ?><span class="travel-badge"><i class="fa fa-car"></i> Travel</span><?php endif; ?>
                                        <div class="text-muted small font-weight-normal" style="padding-left:24px;"><?=htmlspecialchars($r['display_label'] ?? 'Regular')?></div>
                                    </td>
                                    <td class="text-left small text-secondary">
                                        <?php if (!$is_stat_row || ledger_stat_visible((float)$r['reg_hours'])): ?><?= $is_stat_row ? 'Statutory equivalent' : 'Regular' ?>: <?=number_format($r['reg_hours'],2)?>h<?php endif; ?>
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
                                            CPP2: $<?=number_format((float)($r['ee_cpp2'] ?? 0),2)?><br>
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
                                        <?php if (!empty($r['_needs_approval'])): ?>
                                            <span class="badge badge-warning">Needs approval</span>
                                        <?php elseif ($rem > 0.01): ?>
                                            <form method="POST" action="" class="mb-0">
                                                <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>">
                                                <input type="hidden" name="line_id" value="<?=$r['line_id']?>">
                                                <input type="number" step="0.01" min="0.01" name="amount_to_pay" class="form-control form-control-sm inline-pay-box text-success font-weight-bold mr-1" value="<?=round($rem,2)?>" max="<?=round($rem,2)?>">
                                                <select name="form_payment_method" class="form-control form-control-sm inline-select mr-1"><option>E-Transfer</option><option>Cheque</option><option>Cash</option><option>Direct deposit</option></select>
                                                <button type="submit" name="execute_single_payout" class="btn btn-sm btn-success font-weight-bold py-1 px-2">Pay Net</button>
                                            </form>
                                            <?php if ($r['_paid'] > 0.01): ?><span class="badge badge-warning text-uppercase mt-1 d-block" style="font-size:9px;"><i class="fa fa-chart-pie"></i> Partially Settled</span><?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge badge-success text-uppercase py-1 px-2 d-inline-block"><i class="fa fa-check-circle"></i> Settled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)($r['attendance_id'] ?? 0) > 0): ?>
                                            <form method="POST" action="" class="mb-0" onsubmit="return confirm('Send this record back to Step 1 Staff Audit?');">
                                                <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>">
                                                <input type="hidden" name="rollback_attendance_id" value="<?=$r['attendance_id']?>">
                                                <button type="submit" name="send_back_to_audit" class="btn btn-sm btn-outline-danger" style="font-size:10px; font-weight:bold;"><i class="fas fa-undo-alt"></i> Send Back</button>
                                            </form>
                                            <?php if ($rem <= 0.01): ?><input type="hidden" class="qb-buffer-source-node" value="<?=$r['line_id']?>"><?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted font-italic small">Batch Block</span>
                                        <?php endif; ?>
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


    <script>
        // Pay only the checked shifts, using each row's editable amount box.
        function paySelectedShifts(){
            var checks = document.querySelectorAll('.shift-check:checked');
            if (checks.length === 0){ alert('Tick the shifts you want to pay first.'); return; }
            var bucket = document.getElementById('paySelBucket');
            bucket.innerHTML = '';
            var total = 0, n = 0, method = 'E-Transfer';
            checks.forEach(function(cb){
                var row = cb.closest('tr');
                if (!row) return;
                var amtInput = row.querySelector('input[name="amount_to_pay"]');
                if (!amtInput) return; // already settled row has no pay box
                var amt = parseFloat(amtInput.value);
                if (!(amt > 0)) return;
                var sel = row.querySelector('select[name="form_payment_method"]');
                if (sel) method = sel.value;
                var i = document.createElement('input');
                i.type='hidden'; i.name='pay_lines[]'; i.value = cb.value + ':' + amt.toFixed(2);
                bucket.appendChild(i);
                total += amt; n++;
            });
            if (n === 0){ alert('None of the selected shifts have a payable amount (they may already be settled).'); return; }
            document.getElementById('paySelMethod').value = method;
            if (!confirm('Pay ' + n + ' selected shift(s) totalling $' + total.toFixed(2) + ' via ' + method + '?')) return;
            document.getElementById('paySelForm').submit();
        }

        // Site "select all": tick/untick every shift checkbox under this site.
        function toggleSite(siteId, checked){
            document.querySelectorAll('.shift-check[data-site="'+siteId+'"]').forEach(function(cb){ cb.checked = checked; });
        }

        // Collect selected shift line_ids and open the cheque file, the paystub file, or email the stubs.
        function generatePayDocs(part){
            var checks = document.querySelectorAll('.shift-check:checked');
            if (checks.length === 0){ alert('Tick at least one shift (or a site) first.'); return; }
            if (part === 'email' && !confirm('Email each selected employee their own paystub?')) return;
            var bucket = document.getElementById('payDocsBucket');
            bucket.innerHTML = '';
            checks.forEach(function(cb){
                var i = document.createElement('input');
                i.type='hidden'; i.name='doc_line_ids[]'; i.value=cb.value; bucket.appendChild(i);
            });
            document.querySelectorAll('.eft-check:checked').forEach(function(cb){
                var i = document.createElement('input');
                i.type='hidden'; i.name='eft_suppress[]'; i.value=cb.getAttribute('data-user'); bucket.appendChild(i);
            });
            var modeInput = document.createElement('input');
            modeInput.type='hidden'; modeInput.name='cheque_mode';
            modeInput.value = document.getElementById('chequeMode').value;
            bucket.appendChild(modeInput);
            var partInput = document.createElement('input');
            partInput.type='hidden'; partInput.name='doc_part'; partInput.value = part || 'stubs';
            bucket.appendChild(partInput);
            var issueEl = document.getElementById('chequeIssueDate');
            var issueHidden = document.getElementById('payDocsIssueDate');
            if (issueEl && issueHidden && issueEl.value) issueHidden.value = issueEl.value;
            var form = document.getElementById('payDocsForm');
            form.target = (part === 'email') ? '_self' : '_blank';
            form.submit();
        }

        function generateBankFile(){
            var checks = document.querySelectorAll('.shift-check:checked');
            if (checks.length === 0){ alert('Tick the shifts for the people you are paying by direct deposit.'); return; }
            var bucket = document.getElementById('bankFileBucket');
            bucket.innerHTML = '';
            checks.forEach(function(cb){
                var i = document.createElement('input');
                i.type='hidden'; i.name='doc_line_ids[]'; i.value=cb.value; bucket.appendChild(i);
            });
            document.querySelectorAll('.eft-check:checked').forEach(function(cb){
                var i = document.createElement('input');
                i.type='hidden'; i.name='eft_user[]'; i.value=cb.getAttribute('data-user'); bucket.appendChild(i);
            });
            var issueEl = document.getElementById('chequeIssueDate');
            var issueHidden = document.getElementById('bankFileIssueDate');
            if (issueEl && issueHidden && issueEl.value) issueHidden.value = issueEl.value;
            document.getElementById('bankFileForm').submit();
        }

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

        document.querySelectorAll('a.stub-cheque-link').forEach(function(a){
            a.addEventListener('click', function(){
                var u = new URL(a.href, window.location.href);
                var d = document.getElementById('chequeIssueDate');
                if (d && d.value) u.searchParams.set('issue_date', d.value);
                var mode = document.getElementById('chequeMode');
                if (mode && a.classList.contains('cheque-link')) u.searchParams.set('cheque_mode', mode.value);
                a.href = u.toString();
            });
        });

        <?= ledger_expand_script() ?>
        document.addEventListener("DOMContentLoaded", function(){
            var bucket=document.getElementById('qb_hidden_fields_bucket');
            document.querySelectorAll('.qb-buffer-source-node').forEach(function(node){
                var input=document.createElement('input'); input.type='hidden'; input.name='qb_line_ids[]'; input.value=node.value; bucket.appendChild(input);
            });
        });
    </script>
</body>
</html>