<?php
/**
 * Org stat-holiday backfill. Run: php tests/stat_backfill_test.php
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
$refreshCalls = [];
function pe_refresh_period_stat(PDO $pdo, int $user_id, int $site_id, string $work_date): ?array {
    global $refreshCalls;
    $refreshCalls[] = [$user_id, $site_id, $work_date];
    return ['period_end' => $work_date];
}

require_once __DIR__ . '/../payroll_engine/stat_holidays.php';
define('STAT_BACKFILL_LIB', true);
require_once __DIR__ . '/../ops/t4127_stat_backfill.php';

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
        cpp_er REAL, ei_er REAL, wcb REAL, status TEXT, finalized_by INT, comments TEXT,
        paid_amount REAL DEFAULT 0, payment_status TEXT DEFAULT '',
        stat_calculated_amount REAL, stat_flag TEXT, stat_approval_status TEXT)");
    $pdo->exec('CREATE TABLE attendance (id INTEGER PRIMARY KEY, user_id INT, site_id INT, clock_in TEXT, status TEXT)');
    return $pdo;
}

function person(PDO $pdo, int $id, int $site, string $prov, string $name): void {
    $pdo->prepare('INSERT INTO users (id, org_id, Name, active, hourly_rate) VALUES (?,4,?,1,20)')->execute([$id, $name]);
    $pdo->prepare('INSERT INTO sites (SId, org_id, province, nick_name) VALUES (?,4,?,?)')->execute([$site, $prov, $name . ' site']);
    $pdo->prepare('INSERT INTO employee_company_service (user_id, site_id, start_date) VALUES (?,?,?)')->execute([$id, $site, '2025-01-01']);
}

function wage(PDO $pdo, int $user, int $site, string $day, float $gross, string $status = 'pending', float $paid = 0): void {
    $pdo->prepare('INSERT INTO payroll_finalized (user_id, org_id, site_id, work_date, category, total_gross, reg_pay, hourly_rate, vac, reg_hours, status, paid_amount, cpp, ei, income_tax)
                   VALUES (?,?,?,?,?,?,?,20,0,8,?,?,1,2,3)')->execute([$user, 4, $site, $day, 'Regular', $gross, $gross, $status, $paid]);
}

$pdo = fresh();
person($pdo, 1, 10, 'SK', 'Ada');
person($pdo, 2, 20, 'SK', 'Ben');
person($pdo, 3, 30, 'ON', 'Cam');
person($pdo, 4, 40, '', 'Dee');
wage($pdo, 1, 10, '2026-08-10', 1000);
wage($pdo, 2, 20, '2026-08-10', 1000);
wage($pdo, 2, 20, '2026-09-08', 400, 'paid', 400);
wage($pdo, 3, 30, '2026-08-09', 2000, 'pending', 0);
$pdo->prepare('INSERT INTO attendance (id, user_id, site_id, clock_in, status) VALUES (1,3,30,?,?)')->execute(['2026-09-05', 'missed']);

$plan = stat_bf_plan($pdo, 4, '2026-07-15', '2026-11-20');
check('blank province is listed and skipped', count($plan['unmapped']) === 1 && $plan['unmapped'][0]['id'] === 40);

$byKey = [];
foreach ($plan['rows'] as $row) {
    $byKey[$row['user_id'] . '|' . $row['holiday']] = $row;
}
$dates = array_map(function ($g) { return $g['date'] . ' ' . $g['province']; }, $plan['groups']);
check('Canada Day is before the window', !in_array('2026-07-01 SK', $dates, true) && !in_array('2026-07-01 ON', $dates, true));
check('Christmas is after the as-of date', !in_array('2026-12-25 SK', $dates, true));
check('Remembrance Day is inside the window for Saskatchewan only', in_array('2026-11-11 SK', $dates, true) && !in_array('2026-11-11 ON', $dates, true));
check('unpaid SK Labour Day stays in that fortnight', isset($byKey['1|2026-09-07']) && $byKey['1|2026-09-07']['period_end'] === '2026-09-20' && $byKey['1|2026-09-07']['late'] === false);
check('unpaid SK amount is 5 percent', isset($byKey['1|2026-09-07']) && abs($byKey['1|2026-09-07']['amount'] - 50) < 0.01);
check('paid SK fortnight moves to the next unpaid period', isset($byKey['2|2026-09-07']) && $byKey['2|2026-09-07']['late'] === true && $byKey['2|2026-09-07']['period_end'] === '2026-10-04');
check('ON employee who missed the last shift is still created', isset($byKey['3|2026-09-07']) && abs($byKey['3|2026-09-07']['amount'] - 100) < 0.01);

$labour = null;
foreach ($plan['groups'] as $g) {
    if ($g['date'] === '2026-09-07' && $g['province'] === 'SK') {
        $labour = $g;
    }
}
check('SK Labour Day summary counts both employees', $labour && $labour['eligible'] === 2 && $labour['add'] === 2 && $labour['already'] === 0);

$paidBefore = $pdo->query("SELECT total_gross, cpp, ei, income_tax, status, paid_amount FROM payroll_finalized WHERE user_id=2 AND work_date='2026-09-08'")->fetch(PDO::FETCH_ASSOC);
$applied = stat_bf_apply($pdo, $plan);
$paidAfter = $pdo->query("SELECT total_gross, cpp, ei, income_tax, status, paid_amount FROM payroll_finalized WHERE user_id=2 AND work_date='2026-09-08'")->fetch(PDO::FETCH_ASSOC);
check('apply posts the planned lines', $applied['posted'] === count($plan['rows']) && $applied['posted'] > 0);
check('paid row is unchanged', $paidBefore === $paidAfter);

$late = $pdo->query("SELECT work_date, pay_period_end, status, stat_approval_status, paid_amount, comments FROM payroll_finalized WHERE user_id=2 AND category='Statutory Holiday' AND work_date='2026-09-07'")->fetch(PDO::FETCH_ASSOC);
check('late line keeps the holiday date and waits for approval', $late && $late['work_date'] === '2026-09-07' && $late['pay_period_end'] === '2026-10-04' && $late['status'] === 'pending' && $late['stat_approval_status'] === 'pending' && (float)$late['paid_amount'] === 0.0);
check('late line says the original period was paid', $late && strpos((string)$late['comments'], 'late stat, period paid') !== false);

$home = $pdo->query("SELECT pay_period_end, comments FROM payroll_finalized WHERE user_id=1 AND category='Statutory Holiday' AND work_date='2026-09-07'")->fetch(PDO::FETCH_ASSOC);
check('in-period line uses that period end', $home && $home['pay_period_end'] === '2026-09-20' && strpos((string)$home['comments'], 'late stat') === false);

$refreshedPaid = false;
$refreshedUnpaid = false;
foreach ($refreshCalls as $call) {
    if ($call[0] === 2 && $call[2] >= '2026-09-07' && $call[2] <= '2026-09-20') {
        $refreshedPaid = true;
    }
    if ($call[0] === 2 && $call[2] === '2026-10-04') {
        $refreshedUnpaid = true;
    }
}
check('refresh runs for the unpaid period only', $refreshedUnpaid && !$refreshedPaid);

$again = stat_bf_plan($pdo, 4, '2026-07-15', '2026-11-20');
$againAdd = 0;
foreach ($again['groups'] as $g) {
    $againAdd += $g['add'];
}
check('second plan adds nothing', $againAdd === 0);
check('second apply posts nothing', stat_bf_apply($pdo, $again)['posted'] === 0);

if ($fail > 0) {
    echo $fail . " failed\n";
    exit(1);
}
echo "all passed\n";
