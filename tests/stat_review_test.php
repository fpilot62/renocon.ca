<?php
/**
 * Ontario last-and-first review, and the termination exclusion for ON and SK.
 * Run: php tests/stat_review_test.php
 */
$fail = 0;
function check(string $label, bool $cond): void {
    global $fail;
    if ($cond) { echo "ok  $label\n"; return; }
    echo "FAIL $label\n";
    $fail++;
}
function pe_line(float $gross, float $vacRate, array $rates, array $ctx = []): array {
    return ['cpp'=>1,'ei'=>2,'income_tax'=>3,'cpp_er'=>1,'ei_er'=>2.8,'wcb'=>0,'vac'=>0,'pay_gross'=>round($gross,2),'net'=>round($gross-6,2)];
}
require_once __DIR__ . '/../payroll_engine/stat_holidays.php';

function fresh(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE users (id INT, org_id INT, Name TEXT, hourly_rate REAL, EndDate TEXT)');
    $pdo->exec('CREATE TABLE sites (SId INT, org_id INT, province TEXT)');
    $pdo->exec('CREATE TABLE employee_company_service (user_id INT, site_id INT, start_date TEXT)');
    $pdo->exec('CREATE TABLE roe_records (id INT, org_id INT, user_id INT, last_day_paid TEXT, status TEXT)');
    $pdo->exec("CREATE TABLE payroll_finalized (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, org_id INT, site_id INT, work_date TEXT, pay_period_end TEXT,
        category TEXT, display_label TEXT, pay_type TEXT, reg_hours REAL, hourly_rate REAL, reg_pay REAL, total_gross REAL,
        overtime_hours REAL, vac REAL, cpp REAL, ei REAL, income_tax REAL, cpp_er REAL, ei_er REAL, wcb REAL,
        status TEXT, finalized_by INT, comments TEXT, paid_amount REAL DEFAULT 0,
        stat_calculated_amount REAL, stat_flag TEXT, stat_approval_status TEXT)");
    $pdo->exec('CREATE TABLE attendance (id INTEGER PRIMARY KEY, user_id INT, site_id INT, clock_in TEXT, status TEXT)');
    return $pdo;
}
function person(PDO $pdo, int $id, string $prov, ?string $end = null): void {
    $pdo->prepare('INSERT INTO users (id, org_id, Name, hourly_rate, EndDate) VALUES (?,4,?,20,?)')->execute([$id, 'E'.$id, $end]);
    $pdo->prepare('INSERT INTO sites (SId, org_id, province) VALUES (?,4,?)')->execute([$id * 10, $prov]);
    $pdo->prepare('INSERT INTO employee_company_service (user_id, site_id, start_date) VALUES (?,?,?)')->execute([$id, $id * 10, '2025-01-01']);
}
function wage(PDO $pdo, int $id, string $day, float $gross): void {
    $pdo->prepare('INSERT INTO payroll_finalized (user_id, org_id, site_id, work_date, category, total_gross, reg_pay, hourly_rate) VALUES (?,?,?,?,?,?,?,20)')
        ->execute([$id, 4, $id * 10, $day, 'Regular', $gross, $gross]);
}

$pdo = fresh();
person($pdo, 1, 'ON', '2026-09-18');
wage($pdo, 1, '2026-09-14', 800);
$ended = sh_compute_stat_pay($pdo, 1, 10, '2026-10-12', 'ON');
check('ON employee who ended before Thanksgiving gets nothing', !$ended['eligible'] && $ended['amount'] == 0.0);
check('no payable line is posted for that ended employee', sh_post_stat_line($pdo, 4, 1, 10, '2026-10-12', 'Thanksgiving Day', 'ON', 1, null, '', '2026-11-20') === false);
check('ended employment does not open a review', sh_review_row($pdo, 4, 1, 10, '2026-10-12') === null);

person($pdo, 2, 'SK', '2026-08-01');
wage($pdo, 2, '2026-07-20', 1000);
$skEnd = sh_compute_stat_pay($pdo, 2, 20, '2026-09-07', 'SK');
check('SK also excludes an employment end before the holiday', !$skEnd['eligible'] && $skEnd['amount'] == 0.0);

person($pdo, 3, 'ON');
wage($pdo, 3, '2026-09-15', 2000);
check('ON with no shift in the 28 days after still gets a line', sh_post_stat_line($pdo, 4, 3, 30, '2026-10-12', 'Thanksgiving Day', 'ON', 1, null, '', '2026-11-20') === true);
$flagged = $pdo->query("SELECT status, stat_approval_status, comments FROM payroll_finalized WHERE user_id=3 AND category='Statutory Holiday'")->fetch(PDO::FETCH_ASSOC);
check('that line is flagged and not payable', $flagged && $flagged['status'] === 'pending' && $flagged['stat_approval_status'] === 'pending' && strpos($flagged['comments'], 'No shift in 28 days after') !== false && !sh_stat_payable($flagged));
check('queueing the same holiday again does not duplicate', sh_post_stat_line($pdo, 4, 3, 30, '2026-10-12', 'Thanksgiving Day', 'ON', 1, null, '', '2026-11-20') === false
    && (int)$pdo->query("SELECT COUNT(*) FROM payroll_finalized WHERE user_id=3 AND category='Statutory Holiday'")->fetchColumn() === 1);

person($pdo, 4, 'ON');
wage($pdo, 4, '2026-09-20', 2000);
$pdo->prepare('INSERT INTO attendance (id, user_id, site_id, clock_in, status) VALUES (1,4,40,?,?)')->execute(['2026-10-01', 'missed']);
$pdo->prepare('INSERT INTO attendance (id, user_id, site_id, clock_in, status) VALUES (2,4,40,?,?)')->execute(['2026-10-20', 'worked']);
check('a missed last shift is created and flagged', sh_post_stat_line($pdo, 4, 4, 40, '2026-10-12', 'Thanksgiving Day', 'ON', 9, null, '', '2026-11-20') === true);
$missedLine = $pdo->query("SELECT id, status, comments, total_gross FROM payroll_finalized WHERE user_id=4 AND category='Statutory Holiday'")->fetch(PDO::FETCH_ASSOC);
check('the missed-shift line names the reason', $missedLine && strpos($missedLine['comments'], 'Missed last shift before') !== false);
check('approving it does not mark it paid', sh_approve_stat_line($pdo, 4, (int)$missedLine['id'], 9, null, null, '') === true
    && (float)$pdo->query("SELECT COALESCE(paid_amount,0) FROM payroll_finalized WHERE user_id=4 AND category='Statutory Holiday'")->fetchColumn() == 0.0);

person($pdo, 5, 'SK');
wage($pdo, 5, '2026-08-20', 1000);
check('SK with no shift after the holiday is still posted', sh_post_stat_line($pdo, 4, 5, 50, '2026-09-07', 'Labour Day', 'SK', 1, null, '', '2026-11-20') === true);
check('SK does not open a last-and-first review', sh_review_row($pdo, 4, 5, 50, '2026-09-07') === null);

person($pdo, 6, 'ON');
$pdo->prepare('INSERT INTO roe_records (id, org_id, user_id, last_day_paid, status) VALUES (1,4,6,?,?)')->execute(['2026-09-01', 'issued']);
wage($pdo, 6, '2026-08-20', 500);
$roe = sh_compute_stat_pay($pdo, 6, 60, '2026-10-12', 'ON');
check('an ROE last day paid before the holiday excludes the employee', !$roe['eligible']);

if ($fail > 0) { echo $fail . " failed\n"; exit(1); }
echo "all passed\n";
