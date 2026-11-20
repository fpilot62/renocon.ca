<?php
/**
 * Statutory holiday pay for BC, Ontario, and Saskatchewan.
 * Run: php tests/stat_pay_test.php
 */
$fail = 0;
function check(string $label, bool $cond): void {
    global $fail;
    if ($cond) {
        echo "ok  $label\n";
        return;
    }
    echo "FAIL $label\n";
    $fail++;
}

function pe_line(float $gross, float $vacRate, array $rates, array $ctx = []): array {
    return [
        'cpp' => 1.0, 'cpp2' => 0.0, 'ei' => 2.0, 'income_tax' => 3.0,
        'cpp_er' => 1.0, 'cpp2_er' => 0.0, 'ei_er' => 2.8, 'wcb' => 0.0, 'vac' => 0.0,
        'pay_gross' => round($gross, 2), 'net' => round($gross - 6, 2),
    ];
}

require_once __DIR__ . '/../payroll_engine/stat_holidays.php';

function fresh(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE users (id INT, org_id INT, Name TEXT, active INT, hourly_rate REAL)');
    $pdo->exec('CREATE TABLE sites (SId INT, org_id INT, province TEXT, nick_name TEXT)');
    $pdo->exec('CREATE TABLE employee_company_service (user_id INT, site_id INT, start_date TEXT)');
    $pdo->exec("CREATE TABLE payroll_finalized (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INT, org_id INT, site_id INT, work_date TEXT, pay_period_end TEXT,
        category TEXT, display_label TEXT, pay_type TEXT,
        reg_hours REAL, hourly_rate REAL, reg_pay REAL, total_gross REAL,
        overtime_hours REAL, vac REAL, cpp REAL, ei REAL, income_tax REAL,
        cpp_er REAL, ei_er REAL, wcb REAL, status TEXT, finalized_by INT, comments TEXT)");
    $pdo->exec('CREATE TABLE attendance (id INTEGER PRIMARY KEY, user_id INT, site_id INT, clock_in TEXT, status TEXT)');
    return $pdo;
}

function seed_person(PDO $pdo, int $id, int $site, string $prov, string $start): void {
    $pdo->prepare('INSERT INTO users (id, org_id, Name, active, hourly_rate) VALUES (?,1,?,1,20)')->execute([$id, 'E' . $id]);
    $pdo->prepare('INSERT INTO sites (SId, org_id, province, nick_name) VALUES (?,1,?,?)')->execute([$site, $prov, $prov]);
    $pdo->prepare('INSERT INTO employee_company_service (user_id, site_id, start_date) VALUES (?,?,?)')->execute([$id, $site, $start]);
}

function add_day(PDO $pdo, int $user, int $site, string $day, float $gross, float $otHours = 0, float $vac = 0): void {
    $pdo->prepare('INSERT INTO payroll_finalized (user_id, org_id, site_id, work_date, category, total_gross, reg_pay, overtime_hours, hourly_rate, vac, reg_hours)
                   VALUES (?,?,?,?,?,?,?,?,20,?,8)')->execute([$user, 1, $site, $day, 'Regular', $gross, $gross, $otHours, $vac]);
}

function stat_count(PDO $pdo, int $user, int $site, string $day): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM payroll_finalized WHERE user_id=? AND site_id=? AND work_date=? AND category LIKE 'Statutory%'");
    $st->execute([$user, $site, $day]);
    return (int)$st->fetchColumn();
}

$bc = fresh();
seed_person($bc, 1, 10, 'BC', '2026-01-01');
seed_person($bc, 2, 10, 'BC', '2026-06-15');
for ($i = 0; $i < 15; $i++) {
    add_day($bc, 1, 10, date('Y-m-d', strtotime('2026-06-01 +' . $i . ' days')), 160);
}
for ($i = 0; $i < 10; $i++) {
    add_day($bc, 2, 10, date('Y-m-d', strtotime('2026-06-01 +' . $i . ' days')), 160);
}
$eligible = sh_compute_stat_pay($bc, 1, 10, '2026-07-01', 'BC');
$short = sh_compute_stat_pay($bc, 2, 10, '2026-07-01', 'BC');
check('BC eligible average day is 160', $eligible['eligible'] && abs($eligible['amount'] - 160) < 0.01);
check('BC employee under 15 days is not eligible', !$short['eligible'] && $short['amount'] == 0.0);
check('BC posts one stat line through the engine', sh_post_stat_line($bc, 1, 1, 10, '2026-07-01', 'Canada Day', 'BC', 9) && stat_count($bc, 1, 10, '2026-07-01') === 1);
$cpp = $bc->query("SELECT cpp, ei, income_tax FROM payroll_finalized WHERE user_id=1 AND category LIKE 'Statutory%'")->fetch(PDO::FETCH_ASSOC);
check('BC stat line stores engine deductions', (float)$cpp['cpp'] === 1.0 && (float)$cpp['ei'] === 2.0 && (float)$cpp['income_tax'] === 3.0);
check('BC second finalize does not duplicate', sh_post_stat_line($bc, 1, 1, 10, '2026-07-01', 'Canada Day', 'BC', 9) === false && stat_count($bc, 1, 10, '2026-07-01') === 1);
check('BC ineligible employee is not posted', sh_post_stat_line($bc, 1, 2, 10, '2026-07-01', 'Canada Day', 'BC', 9) === false);

$on = fresh();
seed_person($on, 3, 20, 'ON', '2025-01-01');
seed_person($on, 4, 20, 'ON', '2025-01-01');
[$from, $to] = sh_lookback('ON', '2026-09-07');
add_day($on, 3, 20, $from, 2000, 0, 80);
add_day($on, 4, 20, $from, 2000, 0, 80);
$on->prepare('INSERT INTO attendance (id, user_id, site_id, clock_in, status) VALUES (1,3,20,?,?)')->execute([$from, 'worked']);
$on->prepare('INSERT INTO attendance (id, user_id, site_id, clock_in, status) VALUES (3,3,20,?,?)')->execute(['2026-09-20', 'worked']);
$on->prepare('INSERT INTO attendance (id, user_id, site_id, clock_in, status) VALUES (2,4,20,?,?)')->execute([$to, 'missed']);
$onOk = sh_compute_stat_pay($on, 3, 20, '2026-09-07', 'ON');
$onNo = sh_compute_stat_pay($on, 4, 20, '2026-09-07', 'ON');
check('ON public holiday pay is wages plus vacation over 20', $onOk['eligible'] && abs($onOk['amount'] - 104) < 0.01);
check('ON missed last shift still has an amount for review', $onNo['eligible'] && abs($onNo['amount'] - 104) < 0.01);
check('ON posts once', sh_after_finalize($on, 3, 20, '2026-09-07', 1) === 1 && stat_count($on, 3, 20, '2026-09-07') === 1);
check('ON finalize again does not duplicate', sh_after_finalize($on, 3, 20, '2026-09-07', 1) === 0 && stat_count($on, 3, 20, '2026-09-07') === 1);

$sk = fresh();
seed_person($sk, 5, 30, 'SK', '2025-01-01');
seed_person($sk, 6, 30, 'SK', '2026-08-03');
[$sFrom] = sh_lookback('SK', '2026-08-03');
add_day($sk, 5, 30, $sFrom, 1000, 0, 0);
add_day($sk, 6, 30, $sFrom, 1000, 0, 0);
$skOk = sh_compute_stat_pay($sk, 5, 30, '2026-08-03', 'SK');
$skNo = sh_compute_stat_pay($sk, 6, 30, '2026-08-03', 'SK');
check('SK public holiday pay is 5 percent', $skOk['eligible'] && abs($skOk['amount'] - 50) < 0.01);
check('SK employee hired on the holiday is not eligible', !$skNo['eligible']);
check('SK posts once', sh_post_stat_line($sk, 1, 5, 30, '2026-08-03', 'Saskatchewan Day', 'SK', 1) === true);
check('SK second post does not duplicate', sh_post_stat_line($sk, 1, 5, 30, '2026-08-03', 'Saskatchewan Day', 'SK', 1) === false && stat_count($sk, 5, 30, '2026-08-03') === 1);

$bcDays = sh_calendar_holidays('BC', '2026-01-01', '2026-12-31');
$onDays = sh_calendar_holidays('ON', '2027-01-01', '2027-12-31');
check('BC 2026 list has 11 holidays', count($bcDays) === 11);
check('ON 2027 list has 9 holidays', count($onDays) === 9);

$postedLine = sh_finalized_stat_line($sk, 5, 30, '2026-08-03');
check('preview sees the payroll_finalized stat line', $postedLine !== null && (int)$postedLine['id'] > 0);
check('preview uses the ledger amount', $postedLine && abs($postedLine['amount'] - 50) < 0.01);
check('unpaid stat line is pending', $postedLine && $postedLine['status'] === 'pending');
check('another employee is not marked posted', sh_finalized_stat_line($sk, 6, 30, '2026-08-03') === null);
check('a regular line is not a stat post', sh_finalized_stat_line($sk, 5, 30, $sFrom) === null);
$sk->exec('ALTER TABLE payroll_finalized ADD COLUMN paid_amount REAL DEFAULT 0');
$sk->exec("ALTER TABLE payroll_finalized ADD COLUMN payment_status TEXT DEFAULT ''");
$sk->prepare("UPDATE payroll_finalized SET payment_status='paid', paid_amount=total_gross WHERE id=?")->execute([(int)$postedLine['id']]);
$paidLine = sh_finalized_stat_line($sk, 5, 30, '2026-08-03');
check('paid stat line is paid', $paidLine && $paidLine['status'] === 'paid' && abs($paidLine['amount'] - 50) < 0.01);
$page = file_get_contents(__DIR__ . '/../generate_stat_shifts.php');
check('preview page treats the ledger line as posted', strpos($page, 'sh_finalized_stat_line') !== false && strpos($page, 'posted_status') !== false);
check('preview posted rows are yellow', strpos($page, '#fff3b0') !== false && strpos($page, '.posted') !== false);
$ledger = file_get_contents(__DIR__ . '/../payroll_master_ledger.php');
check('ledger paints Statutory Holiday rows yellow', strpos($ledger, 'stat-holiday') !== false && strpos($ledger, '#fff3b0') !== false && strpos($ledger, 'stat-pay-mark') !== false);
$docs = file_get_contents(__DIR__ . '/../generate_pay_documents.php');
check('pay stub highlights public holiday yellow', strpos($docs, '255, 243, 176') !== false && strpos($docs, "'Public Holiday'") !== false);

if ($fail > 0) {
    echo $fail . " failed\n";
    exit(1);
}
echo "all passed\n";
