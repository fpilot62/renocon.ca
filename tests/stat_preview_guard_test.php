<?php
/**
 * Exclusions and one-step approval on the stat holiday page.
 * Run: php tests/stat_preview_guard_test.php
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
require_once __DIR__ . '/../payroll_scope.php';
require_once __DIR__ . '/../payroll_engine/stat_holidays.php';
define('STAT_BACKFILL_LIB', true);
require_once __DIR__ . '/../ops/t4127_stat_backfill.php';

function fresh(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE users (id INT, org_id INT, Name TEXT, active INT, hourly_rate REAL, EndDate TEXT)');
    $pdo->exec('CREATE TABLE sites (SId INT, org_id INT, province TEXT, nick_name TEXT)');
    $pdo->exec('CREATE TABLE employee_company_service (user_id INT, site_id INT, start_date TEXT)');
    $pdo->exec("CREATE TABLE payroll_finalized (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, org_id INT, site_id INT, work_date TEXT, pay_period_end TEXT,
        category TEXT, display_label TEXT, pay_type TEXT, reg_hours REAL, hourly_rate REAL, reg_pay REAL, total_gross REAL,
        cpp REAL, ei REAL, income_tax REAL, cpp_er REAL, ei_er REAL, wcb REAL, vac REAL,
        status TEXT, finalized_by INT, comments TEXT, paid_amount REAL DEFAULT 0,
        stat_calculated_amount REAL, stat_approved_amount REAL, stat_approved_by INT, stat_approved_at TEXT,
        stat_edited_by INT, stat_edit_reason TEXT, stat_flag TEXT, stat_approval_status TEXT)");
    $pdo->exec('CREATE TABLE attendance (id INTEGER PRIMARY KEY, user_id INT, site_id INT, clock_in TEXT, status TEXT)');
    return $pdo;
}
function person(PDO $pdo, int $id, string $prov): void {
    $pdo->prepare('INSERT INTO users (id, org_id, Name, active, hourly_rate) VALUES (?,4,?,1,20)')->execute([$id, 'E'.$id]);
    $pdo->prepare('INSERT INTO sites (SId, org_id, province, nick_name) VALUES (?,4,?,?)')->execute([$id * 10, $prov, 'Site']);
    $pdo->prepare('INSERT INTO employee_company_service (user_id, site_id, start_date) VALUES (?,?,?)')->execute([$id, $id * 10, '2025-01-01']);
}
function wage(PDO $pdo, int $id, string $day, float $gross): void {
    $pdo->prepare("INSERT INTO payroll_finalized (user_id, org_id, site_id, work_date, category, total_gross, reg_pay, hourly_rate, status) VALUES (?,?,?,?, 'Regular', ?, ?, 20, 'pending')")
        ->execute([$id, 4, $id * 10, $day, $gross, $gross]);
}

$pdo = fresh();
person($pdo, 1, 'ON');
wage($pdo, 1, '2026-09-15', 2000);
sh_record_stat_exclusion($pdo, 4, 1, 10, '2026-10-12', 'Removed: no shift after Sep 18 (AA confirmed)', 0, 'Confirmed by Abdul (superadmin). User id was not stored.');
check('an exclusion blocks a new stat line', sh_post_stat_line($pdo, 4, 1, 10, '2026-10-12', 'Thanksgiving', 'ON', 1, null, '', '2026-11-20') === false);
check('the excluded employee has no stat line', (int)$pdo->query("SELECT COUNT(*) FROM payroll_finalized WHERE user_id=1 AND category='Statutory Holiday'")->fetchColumn() === 0);

person($pdo, 2, 'ON');
wage($pdo, 2, '2026-09-20', 2000);
$pdo->prepare('INSERT INTO attendance (id, user_id, site_id, clock_in, status) VALUES (1,2,20,?,?)')->execute(['2026-09-28', 'worked']);
check('approving a candidate creates an approved unpaid line', sh_decide_stat_line($pdo, 4, 2, 20, '2026-10-12', 'Thanksgiving', 'ON', 9, null, null, '', '2026-10-20') === true);
$made = $pdo->query("SELECT status, paid_amount, stat_approval_status, stat_approved_by, stat_calculated_amount, stat_approved_amount, stat_flag FROM payroll_finalized WHERE user_id=2 AND category='Statutory Holiday'")->fetch(PDO::FETCH_ASSOC);
check('the new line is approved and not paid', $made && $made['status'] === 'pending' && (float)$made['paid_amount'] == 0.0 && $made['stat_approval_status'] === 'approved' && (int)$made['stat_approved_by'] === 9 && (float)$made['stat_calculated_amount'] === (float)$made['stat_approved_amount']);
check('a changed amount without a reason is refused', sh_decide_stat_line($pdo, 4, 2, 20, '2026-10-12', 'Thanksgiving', 'ON', 9, 1.0, null, '', '2026-10-20') === false);

person($pdo, 3, 'ON');
wage($pdo, 3, '2026-09-15', 400);
check('a bad reason does not leave a half-created line', sh_decide_stat_line($pdo, 4, 3, 30, '2026-10-12', 'Thanksgiving', 'ON', 9, 1.0, null, '', '2026-11-20') === false
    && (int)$pdo->query("SELECT COUNT(*) FROM payroll_finalized WHERE user_id=3 AND category='Statutory Holiday'")->fetchColumn() === 0);

person($pdo, 4, 'SK');
wage($pdo, 4, '2026-08-10', 1000);
$plan = stat_bf_plan($pdo, 4, '2026-07-15', '2026-11-20');
$keys = [];
foreach ($plan['rows'] as $row) { $keys[$row['user_id'].'|'.$row['holiday']] = true; }
check('backfill offers the unexcluded employee', isset($keys['4|2026-09-07']));
sh_record_stat_exclusion($pdo, 4, 4, 40, '2026-09-07', 'Removed: no shift after Sep 18 (AA confirmed)', 0, '');
$plan2 = stat_bf_plan($pdo, 4, '2026-07-15', '2026-11-20');
$keys2 = [];
foreach ($plan2['rows'] as $row) { $keys2[$row['user_id'].'|'.$row['holiday']] = true; }
check('backfill does not re-offer an exclusion', !isset($keys2['4|2026-09-07']));

$page = file_get_contents(__DIR__ . '/../generate_stat_shifts.php');
check('the page has one approval list and the status legend', strpos($page, 'Approve all unflagged') !== false && strpos($page, 'Show excluded / not eligible') !== false && strpos($page, 'stat-flagged') !== false && strpos($page, 'stat-approved') !== false && strpos($page, 'stat-paid') !== false && strpos($page, 'stat-excluded') !== false && strpos($page, 'badge-worked') !== false);
check('preview still recognizes a ledger stat line', strpos($page, 'sh_finalized_stat_line') !== false && strpos($page, '#fff3b0') !== false && strpos($page, 'stat-pay-show') !== false && strpos($page, 'statReveal') !== false);
$pdo->prepare("INSERT INTO payroll_finalized (user_id, org_id, site_id, work_date, category, total_gross, status, stat_approval_status) VALUES (1,4,10,'2026-09-07','Statutory Holiday',10,'pending','pending')")->execute();
$pdo->prepare("INSERT INTO payroll_finalized (user_id, org_id, site_id, work_date, category, total_gross, status, stat_approval_status) VALUES (1,4,10,'2026-10-12','Statutory Holiday',10,'pending','pending')")->execute();
check('the top bar cookie beats the home org', ps_topbar_org_value(true, 2, null, '4') === 4);
check('an explicit link beats a stale cookie', ps_topbar_org_value(true, 2, '4', '2') === 4);
check('all-orgs does not fall back to the home org', ps_topbar_org_value(true, 2, null, 'all') === 0 && ps_topbar_org_value(true, 2, null, null) === 0);
check('an org admin stays on the session org', ps_topbar_org_value(false, 4, null, '2') === 4);
$pdo->prepare("INSERT INTO users (id, org_id, Name, active, hourly_rate) VALUES (8,2,'Home',1,20)")->execute();
$pdo->prepare("INSERT INTO payroll_finalized (user_id, org_id, site_id, work_date, category, total_gross, status, stat_approval_status) VALUES (8,2,10,'2026-08-03','Statutory Holiday',5,'pending','pending')")->execute();
check('the newest pending line picks its org', sh_org_with_latest_pending_stat($pdo) === 4);
$review = file_get_contents(__DIR__ . '/../stat_holiday_review.php');
check('the old approval page follows the top bar, not the home org', strpos($review, 'ps_topbar_org()') !== false && strpos($review, 'sh_org_with_latest_pending_stat') !== false && strpos($review, 'ps_org_id()') === false);
$href = sh_stat_pay_href($pdo, 4);
check('the link stays on org 4 and opens the period that holds the pending line', strpos($href, 'org_id=4') !== false && strpos($href, 'from_date=2026-10-05') !== false && strpos($href, 'to_date=2026-10-18') !== false && strpos($href, 'org_id=2') === false);
$ledger = file_get_contents(__DIR__ . '/../payroll_master_ledger.php');
check('the ledger banner carries the top-bar org', strpos($ledger, 'ps_topbar_org()') !== false && strpos($ledger, 'sh_stat_pay_href') !== false && strpos($ledger, 'Open Stat Holiday Pay') !== false);
$pageOrg = file_get_contents(__DIR__ . '/../generate_stat_shifts.php');
check('the holiday page reads the top bar before any home org', strpos($pageOrg, 'ps_topbar_org()') !== false && strpos($pageOrg, 'sh_org_with_latest_pending_stat') !== false);
$migration = file_get_contents(__DIR__ . '/../migrations/20261122_stat_holiday_exclusions.sql');
check('the migration seeds the removed Thanksgiving line', strpos($migration, '10766') !== false && strpos($migration, '121') !== false && strpos($migration, '2026-10-12') !== false && strpos($migration, 'Removed: no shift after Sep 18 (AA confirmed)') !== false && strpos($migration, 'NOT EXISTS') !== false);

if ($fail > 0) { echo $fail . " failed\n"; exit(1); }
echo "all passed\n";
