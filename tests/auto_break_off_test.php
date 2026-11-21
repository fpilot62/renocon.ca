<?php
/**
 * Automatic breaks stay off, punched breaks stay, and only pending lines move.
 * Run: php tests/auto_break_off_test.php
 */
$fail = 0;
function check(string $label, bool $cond): void {
    global $fail;
    if ($cond) { echo "ok  $label\n"; return; }
    echo "FAIL $label\n";
    $fail++;
}
function pe_rates(PDO $pdo): array { return ['cpp' => 0, 'ei' => 0, 'fed' => 0, 'wcb' => 0, 'ei_mult' => 1]; }
function pe_vacation_rate(PDO $pdo, int $userId, int $siteId, string $day): float { return 0.04; }
function pe_line(float $gross, float $vacRate, array $rates, array $ctx = []): array {
    return [
        'cpp' => 1.25, 'cpp2' => 0.0, 'ei' => 2.0, 'income_tax' => 3.0,
        'cpp_er' => 1.25, 'cpp2_er' => 0.0, 'ei_er' => 2.8, 'wcb' => 1.0, 'vac' => round($gross * $vacRate, 2),
    ];
}
require_once __DIR__ . '/../engine/auto_break_lib.php';
require_once __DIR__ . '/../engine/break_waiver_lib.php';
require_once __DIR__ . '/../engine/auto_break_off_lib.php';

check('the switch defaults to off', ab_feature_on() === false);
$plan = ab_plan(
    ['id' => 1, 'org_id' => 2, 'status' => '4', 'clock_in' => '2026-11-01 08:00:00', 'clock_out' => '2026-11-01 16:00:00', 'deleted_at' => null],
    [],
    false
);
check('a closed shift is not given an auto break', ($plan['action'] ?? '') === 'skip' && ($plan['reason'] ?? '') === 'feature off');
check('clock-out does not ask for a no-break request', bwr_is_eligible_shift(
    ['status' => '4', 'clock_in' => '2026-11-01 08:00:00', 'clock_out' => '2026-11-01 16:00:00'],
    []
) === false);
$guard = file_get_contents(__DIR__ . '/../break_waiver_finalize_guard.php');
check('finalize does not block while auto breaks are off', strpos($guard, 'ab_feature_on') !== false && strpos($guard, 'return;') !== false);
$page = file_get_contents(__DIR__ . '/../no_break_request.php');
check('the no-break page leaves when the feature is off', strpos($page, 'ab_feature_on') !== false);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, Name TEXT)");
$pdo->exec("CREATE TABLE sites (SId INTEGER PRIMARY KEY, nick_name TEXT)");
$pdo->exec("CREATE TABLE attendance (id INTEGER PRIMARY KEY, user_id INTEGER, org_id INTEGER, site_id INTEGER, clock_in TEXT, clock_out TEXT, status TEXT)");
$pdo->exec("CREATE TABLE breaks (id INTEGER PRIMARY KEY, shift_id INTEGER, break_start TEXT, break_end TEXT, source TEXT)");
$pdo->exec("CREATE TABLE payroll_finalized (
    id INTEGER PRIMARY KEY, attendance_id INTEGER, user_id INTEGER, org_id INTEGER, site_id INTEGER,
    work_date TEXT, category TEXT, pay_type TEXT, status TEXT, payment_status TEXT, paid_amount REAL,
    reg_hours REAL, hourly_rate REAL, reg_pay REAL, total_gross REAL, payroll_id INTEGER,
    stat_approval_status TEXT, cpp REAL, ei REAL, income_tax REAL, wcb REAL, vac REAL
)");
$pdo->exec("CREATE TABLE payroll_masters (id INTEGER PRIMARY KEY, status TEXT, exported_at TEXT)");
$pdo->exec("INSERT INTO users (id, Name) VALUES (7, 'Asha')");
$pdo->exec("INSERT INTO sites (SId, nick_name) VALUES (12, 'Popeyes')");
$pdo->exec("INSERT INTO attendance (id, user_id, org_id, site_id, clock_in, clock_out, status) VALUES
    (1, 7, 2, 12, '2026-11-10 08:00:00', '2026-11-10 16:00:00', '4'),
    (2, 7, 2, 12, '2026-11-20 08:00:00', '2026-11-20 16:00:00', '4')");
$pdo->exec("INSERT INTO breaks (id, shift_id, break_start, break_end, source) VALUES
    (10, 1, '2026-11-10 12:00:00', '2026-11-10 12:30:00', 'auto'),
    (11, 1, '2026-11-10 15:00:00', '2026-11-10 15:10:00', 'manual'),
    (12, 2, '2026-11-20 12:00:00', '2026-11-20 12:30:00', 'auto')");
$pdo->exec("INSERT INTO payroll_finalized (id, attendance_id, user_id, org_id, site_id, work_date, category, status, payment_status, paid_amount, reg_hours, hourly_rate, reg_pay, total_gross, payroll_id, stat_approval_status) VALUES
    (100, 1, 7, 2, 12, '2026-11-10', 'Regular', 'pending', 'Unpaid', 0, 7.5, 20, 150, 150, 0, ''),
    (101, 1, 7, 2, 12, '2026-11-10', 'Statutory Holiday', 'pending', 'Unpaid', 0, 8, 20, 160, 160, 0, 'approved'),
    (102, 2, 7, 2, 12, '2026-11-20', 'Regular', 'paid', 'cheque_printed', 160, 7.5, 20, 150, 160, 9, '')");
$pdo->exec("INSERT INTO payroll_masters (id, status, exported_at) VALUES (9, 'exported', '2026-11-21 01:00:00')");

$report = abo_summarize($pdo);
$text = abo_format_report($report);
check('all auto breaks are counted', $report['all']['breaks'] === 2 && $report['all']['shifts'] === 2 && $report['all']['employees'] === 1);
check('the split is before and after 18 Nov', $report['before']['breaks'] === 1 && $report['after']['breaks'] === 1);
check('the report names pending, paid, and cheque lines', strpos($text, 'pending: 1') !== false && strpos($text, 'cheque_printed: 1') !== false && strpos($text, 'stat lines left unchanged: 1') !== false);
check('a cheque line is listed for a top-up', count($report['topups']) === 1 && (int)$report['topups'][0]['id'] === 102);

$pdo->beginTransaction();
$applied = abo_apply($pdo);
$manual = (int)$pdo->query("SELECT COUNT(*) FROM breaks WHERE source = 'manual'")->fetchColumn();
$auto = (int)$pdo->query("SELECT COUNT(*) FROM breaks WHERE source = 'auto'")->fetchColumn();
$pending = $pdo->query('SELECT reg_hours, total_gross, cpp, status, payment_status, paid_amount FROM payroll_finalized WHERE id = 100')->fetch(PDO::FETCH_ASSOC);
$stat = $pdo->query('SELECT reg_hours, total_gross, stat_approval_status FROM payroll_finalized WHERE id = 101')->fetch(PDO::FETCH_ASSOC);
$paid = $pdo->query('SELECT reg_hours, total_gross, payment_status, paid_amount FROM payroll_finalized WHERE id = 102')->fetch(PDO::FETCH_ASSOC);
check('only automatic breaks are deleted', $auto === 0 && $manual === 1 && (int)$applied['deleted'] === 2);
check('the pending line gets the break time back', abs((float)$pending['reg_hours'] - (8 - 10 / 60)) < 0.02 && (float)$pending['paid_amount'] == 0.0 && $pending['status'] === 'pending');
check('the engine deduction is stored', (float)$pending['cpp'] === 1.25);
check('the statutory line and its approval stay put', (float)$stat['reg_hours'] === 8.0 && $stat['stat_approval_status'] === 'approved');
check('the cheque line is not changed', (float)$paid['reg_hours'] === 7.5 && $paid['payment_status'] === 'cheque_printed');
$pdo->rollBack();
$restored = (int)$pdo->query("SELECT COUNT(*) FROM breaks WHERE source = 'auto'")->fetchColumn();
check('a dry run rolls the deletion back', $restored === 2);

if ($fail > 0) { echo $fail . " failed\n"; exit(1); }
echo "all passed\n";
