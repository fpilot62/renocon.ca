<?php
/**
 * Flag existing pending stat lines without changing anything else.
 * Run: php tests/stat_flag_existing_test.php
 */
$fail = 0;
function check(string $label, bool $cond): void {
    global $fail;
    if ($cond) { echo "ok  $label\n"; return; }
    echo "FAIL $label\n";
    $fail++;
}
require_once __DIR__ . '/../payroll_engine/stat_holidays.php';
define('STAT_FLAG_LIB', true);
require_once __DIR__ . '/../ops/stat_flag_existing.php';

function fresh(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE users (id INT, org_id INT, Name TEXT, hourly_rate REAL, EndDate TEXT)');
    $pdo->exec('CREATE TABLE sites (SId INT, org_id INT, province TEXT)');
    $pdo->exec('CREATE TABLE employee_company_service (user_id INT, site_id INT, start_date TEXT, end_date TEXT)');
    $pdo->exec('CREATE TABLE roe_records (id INT, org_id INT, user_id INT, last_day_paid TEXT, status TEXT)');
    $pdo->exec("CREATE TABLE payroll_finalized (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, org_id INT, site_id INT, work_date TEXT, pay_period_end TEXT,
        category TEXT, total_gross REAL, status TEXT, comments TEXT,
        stat_approval_status TEXT, stat_flag TEXT, stat_edited_by INT, stat_approved_at TEXT,
        stat_calculated_amount REAL, stat_approved_amount REAL)");
    $pdo->exec('CREATE TABLE attendance (id INTEGER PRIMARY KEY, user_id INT, site_id INT, clock_in TEXT, status TEXT)');
    return $pdo;
}
function person(PDO $pdo, int $id, string $prov, ?string $end = null): void {
    $pdo->prepare('INSERT INTO users (id, org_id, Name, hourly_rate, EndDate) VALUES (?,4,?,20,?)')->execute([$id, 'E'.$id, $end]);
    $pdo->prepare('INSERT INTO sites (SId, org_id, province) VALUES (?,4,?)')->execute([$id * 10, $prov]);
}
function line(PDO $pdo, int $user, string $day, float $amount, array $extra = []): int {
    $pdo->prepare("INSERT INTO payroll_finalized
        (user_id, org_id, site_id, work_date, pay_period_end, category, total_gross, status, stat_approval_status, stat_flag, stat_edited_by, stat_approved_at, stat_calculated_amount)
        VALUES (?,?,?,?,?,'Statutory Holiday',?,'pending','pending',?,?,?,?)")
        ->execute([
            $user, 4, $user * 10, $day, $extra['pay_period_end'] ?? $day, $amount,
            $extra['stat_flag'] ?? null,
            $extra['stat_edited_by'] ?? null,
            $extra['stat_approved_at'] ?? null,
            $amount,
        ]);
    return (int)$pdo->lastInsertId();
}
function snapshot(PDO $pdo, int $id): array {
    $row = $pdo->query('SELECT * FROM payroll_finalized WHERE id=' . $id)->fetch(PDO::FETCH_ASSOC);
    $flag = $row['stat_flag'];
    unset($row['stat_flag']);
    return [$flag, $row];
}

$asOf = '2026-11-20';
$pdo = fresh();
person($pdo, 1, 'ON');
person($pdo, 2, 'ON');
person($pdo, 3, 'SK');
person($pdo, 4, 'SK', '2026-09-01');
person($pdo, 5, 'ON', '2026-09-18');
person($pdo, 6, 'ON');
person($pdo, 7, '');
person($pdo, 8, 'ON');
person($pdo, 9, 'ON');
person($pdo, 10, 'ON');
$none = line($pdo, 1, '2026-10-12', 40.00);
$missed = line($pdo, 2, '2026-10-12', 55.50);
$pdo->prepare('INSERT INTO attendance (id, user_id, site_id, clock_in, status) VALUES (1,2,20,?,?)')->execute(['2026-10-01', 'missed']);
$pdo->prepare('INSERT INTO attendance (id, user_id, site_id, clock_in, status) VALUES (2,2,20,?,?)')->execute(['2026-10-20', 'worked']);
$skClear = line($pdo, 3, '2026-09-07', 20.00);
$skEnded = line($pdo, 4, '2026-10-12', 18.25);
$onEnded = line($pdo, 5, '2026-10-12', 12.00);
$already = line($pdo, 6, '2026-10-12', 9.00, ['stat_flag' => 'Missed last shift before']);
$edited = line($pdo, 1, '2026-09-07', 8.00, ['stat_edited_by' => 9]);
$approved = line($pdo, 2, '2026-09-07', 7.00, ['stat_approved_at' => '2026-11-01 00:00:00']);
$unknown = line($pdo, 7, '2026-10-12', 6.00);
$byPeriod = line($pdo, 8, '2026-10-13', 33.00, ['pay_period_end' => '2026-10-18']);
$pdo->prepare("INSERT INTO payroll_finalized (user_id, org_id, site_id, work_date, category, total_gross, status) VALUES (1,4,10,'2026-10-11','Regular',100,'pending')")->execute();
$pdo->prepare('INSERT INTO roe_records (id, org_id, user_id, last_day_paid, status) VALUES (1,4,9,?,?)')->execute(['2026-08-01', 'issued']);
$roe = line($pdo, 9, '2026-09-07', 4.00);
$missedAfter = line($pdo, 10, '2026-09-07', 11.00);
$pdo->prepare('INSERT INTO attendance (id, user_id, site_id, clock_in, status) VALUES (3,10,100,?,?)')->execute(['2026-09-08', 'missed']);
$before = [];
foreach ([$none, $missed, $skClear, $skEnded, $onEnded, $already, $edited, $approved, $unknown, $byPeriod, $roe, $missedAfter] as $id) {
    $before[$id] = snapshot($pdo, $id);
}
$regularBefore = snapshot($pdo, (int)$pdo->query("SELECT id FROM payroll_finalized WHERE category='Regular'")->fetchColumn());

$plan = stat_flag_plan($pdo, $asOf);
$byUser = [];
foreach ($plan['flagged'] as $row) {
    $byUser[$row['user_id'] . '|' . $row['work_date']] = $row['reason'];
}
check('no shift in the 28 days after is flagged', ($byUser['1|2026-10-12'] ?? '') === 'No shift in 28 days after');
check('a missed last shift is flagged', ($byUser['2|2026-10-12'] ?? '') === 'Missed last shift before');
check('Saskatchewan with no later shift is not flagged', !isset($byUser['3|2026-09-07']));
check('Saskatchewan end date is flagged and the row stays', ($byUser['4|2026-10-12'] ?? '') === 'Ended before holiday');
check('Ontario end date is flagged instead of removed', ($byUser['5|2026-10-12'] ?? '') === 'Ended before holiday; No shift in 28 days after');
check('a line that already has a flag is left out', !isset($byUser['6|2026-10-12']));
check('an edited line is left out', !isset($byUser['1|2026-09-07']));
check('an approved line is left out', !isset($byUser['2|2026-09-07']));
check('a blank province is listed and not flagged', count($plan['unknown']) === 1 && (int)$plan['unknown'][0]['user_id'] === 7);
check('a pay period with one holiday supplies the date', ($byUser['8|2026-10-13'] ?? '') === 'No shift in 28 days after' && in_array('2026-10-12', array_column($plan['flagged'], 'holiday_date'), true));
check('an ROE last day before the holiday is flagged', ($byUser['9|2026-09-07'] ?? '') === 'Ended before holiday; No shift in 28 days after');
check('a missed first shift after is flagged', ($byUser['10|2026-09-07'] ?? '') === 'Missed first shift after');

$tmp = tempnam(sys_get_temp_dir(), 'statflag');
$n = stat_flag_apply($pdo, $plan, $tmp);
check('apply writes one flag per flagged line', $n === count($plan['flagged']) && $n > 0);
foreach ($before as $id => [$flag, $rest]) {
    [$nowFlag, $nowRest] = snapshot($pdo, $id);
    check("row $id keeps every column except the flag", $nowRest === $rest);
}
check('the open Ontario line now has its reason', snapshot($pdo, $none)[0] === 'No shift in 28 days after');
check('the Saskatchewan clear line is still unflagged', snapshot($pdo, $skClear)[0] === null);
check('a regular line is untouched', snapshot($pdo, (int)$pdo->query("SELECT id FROM payroll_finalized WHERE category='Regular'")->fetchColumn()) === $regularBefore);
check('the existing flag was not replaced', snapshot($pdo, $already)[0] === 'Missed last shift before');

$again = stat_flag_plan($pdo, $asOf);
check('a second pass flags nothing new', $again['flagged'] === []);
check('rollback restores the saved flag values', stat_flag_rollback($pdo, $tmp) === $n);
foreach ($before as $id => [$flag, $rest]) {
    check("row $id flag is back", snapshot($pdo, $id)[0] === $flag);
}
unlink($tmp);

$src = file_get_contents(__DIR__ . '/../ops/stat_flag_existing.php');
check('the update sets only stat_flag', preg_match('/UPDATE payroll_finalized\s+SET stat_flag=\?/', $src) === 1 && !preg_match('/UPDATE payroll_finalized\s+SET (?!stat_flag)/', $src));

if ($fail > 0) { echo $fail . " failed\n"; exit(1); }
echo "all passed\n";
