<?php
/**
 * Stat lines are created for every candidate, flagged when questionable,
 * and stay unpaid until an approver in the same org approves them.
 * Run: php tests/stat_approval_test.php
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
    $pdo->exec("CREATE TABLE payroll_finalized (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, org_id INT, site_id INT, work_date TEXT, pay_period_end TEXT,
        category TEXT, display_label TEXT, pay_type TEXT, reg_hours REAL, hourly_rate REAL, reg_pay REAL, total_gross REAL,
        overtime_hours REAL, vac REAL, cpp REAL, ei REAL, income_tax REAL, cpp_er REAL, ei_er REAL, wcb REAL,
        status TEXT, finalized_by INT, comments TEXT, paid_amount REAL DEFAULT 0,
        stat_calculated_amount REAL, stat_approved_amount REAL, stat_approved_by INT, stat_approved_at TEXT,
        stat_edited_by INT, stat_edit_reason TEXT, stat_flag TEXT, stat_approval_status TEXT)");
    $pdo->exec('CREATE TABLE attendance (id INTEGER PRIMARY KEY, user_id INT, site_id INT, clock_in TEXT, status TEXT)');
    return $pdo;
}
function person(PDO $pdo, int $id, int $org, string $prov, ?string $end = null): void {
    $pdo->prepare('INSERT INTO users (id, org_id, Name, hourly_rate, EndDate) VALUES (?,?,?,20,?)')->execute([$id, $org, 'E'.$id, $end]);
    $pdo->prepare('INSERT INTO sites (SId, org_id, province) VALUES (?,?,?)')->execute([$id * 10, $org, $prov]);
    $pdo->prepare('INSERT INTO employee_company_service (user_id, site_id, start_date) VALUES (?,?,?)')->execute([$id, $id * 10, '2025-01-01']);
}
function wage(PDO $pdo, int $id, int $org, string $day, float $gross): void {
    $pdo->prepare("INSERT INTO payroll_finalized (user_id, org_id, site_id, work_date, category, total_gross, reg_pay, hourly_rate, status) VALUES (?,?,?,?, 'Regular', ?, ?, 20, 'pending')")
        ->execute([$id, $org, $id * 10, $day, $gross, $gross]);
}

check('a normal unpaid line stays payable', sh_stat_payable(['status' => 'pending', 'stat_approval_status' => null, 'category' => 'Regular']));
check('approval pending blocks pay while status stays unpaid', !sh_stat_payable(['status' => 'pending', 'stat_approval_status' => 'pending']));

$pdo = fresh();
person($pdo, 1, 4, 'ON', '2026-09-18');
wage($pdo, 1, 4, '2026-09-14', 800);
check('ended before the holiday gets no line', sh_post_stat_line($pdo, 4, 1, 10, '2026-10-12', 'Thanksgiving', 'ON', 1, null, '', '2026-11-20') === false);

person($pdo, 2, 4, 'ON');
wage($pdo, 2, 4, '2026-09-15', 2000);
check('flagged candidate still gets the calculated line', sh_post_stat_line($pdo, 4, 2, 20, '2026-10-12', 'Thanksgiving', 'ON', 1, null, '', '2026-11-20') === true);
$row = $pdo->query("SELECT * FROM payroll_finalized WHERE user_id=2 AND category='Statutory Holiday'")->fetch(PDO::FETCH_ASSOC);
check('flag text and calculated amount are stored', $row && $row['stat_flag'] === 'No shift in 28 days after' && (float)$row['stat_calculated_amount'] === (float)$row['total_gross'] && (float)$row['total_gross'] > 0);
check('a pending line is not payable', !sh_stat_payable($row));
check('changing the amount without a reason is refused', sh_approve_stat_line($pdo, 4, (int)$row['id'], 9, 0.0, 0.0, '') === false);
check('the refused edit leaves the amount alone', (float)$pdo->query('SELECT total_gross FROM payroll_finalized WHERE user_id=2 AND category=\'Statutory Holiday\'')->fetchColumn() === (float)$row['total_gross']);
check('another org cannot approve it', sh_approve_stat_line($pdo, 9, (int)$row['id'], 9, null, null, '') === false);
check('zero with a reason is approved and still unpaid', sh_approve_stat_line($pdo, 4, (int)$row['id'], 9, 0.0, 0.0, 'quit') === true);
$edited = $pdo->query("SELECT status, stat_approval_status, total_gross, paid_amount, stat_edit_reason, stat_edited_by, stat_approved_by FROM payroll_finalized WHERE id=" . (int)$row['id'])->fetch(PDO::FETCH_ASSOC);
check('the audit trail records the editor and approver', $edited['status'] === 'pending' && $edited['stat_approval_status'] === 'approved' && (float)$edited['total_gross'] == 0.0 && (float)$edited['paid_amount'] == 0.0 && $edited['stat_edit_reason'] === 'quit' && (int)$edited['stat_edited_by'] === 9 && (int)$edited['stat_approved_by'] === 9);

person($pdo, 6, 4, 'ON');
wage($pdo, 6, 4, '2026-09-18', 1000);
sh_post_stat_line($pdo, 4, 6, 60, '2026-10-12', 'Thanksgiving', 'ON', 1, null, '', '2026-11-20');
person($pdo, 3, 4, 'ON');
wage($pdo, 3, 4, '2026-09-20', 2000);
$pdo->prepare('INSERT INTO attendance (id, user_id, site_id, clock_in, status) VALUES (9,3,30,?,?)')->execute(['2026-09-28', 'worked']);
sh_post_stat_line($pdo, 4, 3, 30, '2026-10-12', 'Thanksgiving', 'ON', 1, null, '', '2026-10-20');
$plain = $pdo->query("SELECT * FROM payroll_finalized WHERE user_id=3 AND category='Statutory Holiday'")->fetch(PDO::FETCH_ASSOC);
check('an unflagged line can be bulk approved', ($plain['stat_flag'] === null || $plain['stat_flag'] === '') && sh_bulk_approve_unflagged($pdo, 4, 7) === 1);
check('a flagged line still waiting is not bulk approved', $pdo->query("SELECT stat_approval_status FROM payroll_finalized WHERE user_id=6 AND category='Statutory Holiday'")->fetchColumn() === 'pending' && $pdo->query("SELECT status FROM payroll_finalized WHERE user_id=6 AND category='Statutory Holiday'")->fetchColumn() === 'pending');

person($pdo, 4, 4, 'SK');
wage($pdo, 4, 4, '2026-08-20', 1000);
sh_post_stat_line($pdo, 4, 4, 40, '2026-09-07', 'Labour Day', 'SK', 1, null, '', '2026-11-20');
$sk = $pdo->query("SELECT id, total_gross, stat_calculated_amount, status FROM payroll_finalized WHERE user_id=4 AND category='Statutory Holiday'")->fetch(PDO::FETCH_ASSOC);
$original = (float)$sk['total_gross'];
check('editing requires the reason before approval', sh_approve_stat_line($pdo, 4, (int)$sk['id'], 8, $original - 10, null, '') === false);
check('the same amount approves without a reason', sh_approve_stat_line($pdo, 4, (int)$sk['id'], 8, $original, null, '') === true);
wage($pdo, 4, 4, '2026-08-21', 500);
check('recalc does not overwrite an approved line', sh_post_stat_line($pdo, 4, 4, 40, '2026-09-07', 'Labour Day', 'SK', 1, null, '', '2026-11-20') === false);
$after = $pdo->query("SELECT total_gross, stat_calculated_amount, status FROM payroll_finalized WHERE id=" . (int)$sk['id'])->fetch(PDO::FETCH_ASSOC);
check('approved amount stays put', (float)$after['total_gross'] === $original && (float)$after['stat_calculated_amount'] === $original && $after['status'] === 'pending');

person($pdo, 5, 4, 'ON');
wage($pdo, 5, 4, '2026-09-15', 400);
sh_post_stat_line($pdo, 4, 5, 50, '2026-10-12', 'Thanksgiving', 'ON', 1, null, '', '2026-11-20');
$pending = $pdo->query("SELECT id, total_gross FROM payroll_finalized WHERE user_id=5 AND category='Statutory Holiday'")->fetch(PDO::FETCH_ASSOC);
wage($pdo, 5, 4, '2026-09-16', 400);
sh_post_stat_line($pdo, 4, 5, 50, '2026-10-12', 'Thanksgiving', 'ON', 1, null, '', '2026-11-20');
$refreshed = $pdo->query("SELECT total_gross, stat_calculated_amount, status, stat_approval_status FROM payroll_finalized WHERE id=" . (int)$pending['id'])->fetch(PDO::FETCH_ASSOC);
check('an untouched pending line refreshes the calculated amount', (float)$refreshed['total_gross'] > (float)$pending['total_gross'] && (float)$refreshed['stat_calculated_amount'] === (float)$refreshed['total_gross'] && $refreshed['status'] === 'pending' && $refreshed['stat_approval_status'] === 'pending');

$ledger = file_get_contents(__DIR__ . '/../payroll_master_ledger.php');
$docs = file_get_contents(__DIR__ . '/../generate_pay_documents.php');
check('the ledger refuses to pay a line waiting for approval', strpos($ledger, "stat_approval_status") !== false && strpos($ledger, 'Open Stat Holiday Pay') !== false);
check('pay documents skip lines waiting for approval or ignored', strpos($docs, "stat_approval_status") !== false && strpos($docs, "!== 'ignored'") !== false);
$ledger1 = file_get_contents(__DIR__ . '/../payroll_master_ledger1.php');
check('the other ledger also refuses a line waiting for approval', strpos($ledger1, "stat_approval_status") !== false);
$migration = file_get_contents(__DIR__ . '/../migrations/20261121_stat_approval.sql');
check('the migration keeps status and uses its own approval column', strpos($migration, 'unpaid_stat_to_pending') !== false && strpos($migration, "stat_approval_status = 'pending'") !== false && strpos($migration, "status = 'needs approval'") === false && strpos($migration, 'information_schema.COLUMNS') !== false && strpos($migration, 'ADD COLUMN stat_approval_status VARCHAR(20)') !== false);

if ($fail > 0) { echo $fail . " failed\n"; exit(1); }
echo "all passed\n";
