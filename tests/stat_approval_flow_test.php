<?php
/**
 * Stat holiday approval reaches pay only after an admin approves it.
 * Run: php tests/stat_approval_flow_test.php
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

require_once __DIR__ . '/../engine/paydocs_earn.php';
require_once __DIR__ . '/../payroll_engine/payroll_engine.php';
require_once __DIR__ . '/../payroll_engine/stat_holidays.php';

$root = dirname(__DIR__);
foreach ([
    'engine/payroll_cheque_lib.php',
    'payroll_payment_status.php',
    'payroll_payment_desk.php',
    'payroll_save_payment.php',
    'print_payrun.php',
    'generate_pay_documents.php',
] as $rel) {
    $src = (string)file_get_contents($root . '/' . $rel);
    check($rel . ' skips lines waiting for approval', strpos($src, 'pe_not_pending_sql') !== false);
}
check('payment desk keeps the live org-scope guard', strpos((string)file_get_contents($root . '/payroll_payment_desk.php'), 'orgscope_guard.php') !== false);
check('print payrun keeps the live org-scope guard', strpos((string)file_get_contents($root . '/print_payrun.php'), 'orgscope_guard.php') !== false);
$docs = (string)file_get_contents($root . '/generate_pay_documents.php');
check('both stub layouts say Public holiday pay', substr_count($docs, 'Public holiday pay') >= 2);
check('Holiday worked 1.5× stays its own line', substr_count($docs, 'Holiday worked 1.5×') >= 2);
check('year-to-date queries skip pending stat', substr_count($docs, 'pe_not_pending_sql') >= 2);
$ledger = (string)file_get_contents($root . '/payroll_master_ledger.php');
check('ledger shows pending stat as awaiting approval', strpos($ledger, 'awaiting approval') !== false);
check('ledger totals leave pending and ignored stat out of gross', strpos($ledger, '$outOfPay ? 0.0 : (float)$r[\'total_gross\']') !== false && strpos($ledger, "value=\"ignored\"") !== false);
$page = (string)file_get_contents($root . '/generate_stat_shifts.php');
check('the holiday page has a Create pending rows button', strpos($page, 'create_pending_rows') !== false && strpos($page, 'Create pending rows') !== false);
check('the holiday page includes BC sites', strpos($page, "'BC','ON','SK'") !== false);
check('the holiday page filters the table by province', strpos($page, 'name="province"') !== false && strpos($page, '$boardProvinces') !== false && strpos($page, "'province'") !== false);
check('the holiday table has a select-all and a row checkbox', strpos($page, 'id="statSelectAll"') !== false && strpos($page, 'class="stat-pick"') !== false);
check('the holiday table has a sticky selected and listed footer', strpos($page, 'stat-totals-foot') !== false && strpos($page, 'id="statSelectedTotals"') !== false && strpos($page, 'id="statListedTotals"') !== false && strpos($page, 'position:sticky;bottom:0') !== false);
check('footer totals follow ticks and amount edits', strpos($page, 'function statBoardTotals') !== false && strpos($page, "e.target.name === 'amount'") !== false);
check('bulk approve can be limited to the selection and still skips flagged lines', strpos($page, 'pick_on') !== false && strpos($page, 'data-stat-ready') !== false && strpos($page, "trim((string)\$row['stat_flag'])") !== false);
$backfill = (string)file_get_contents($root . '/ops/t4127_stat_backfill.php');
check('backfill includes BC sites', strpos($backfill, "['BC', 'ON', 'SK']") !== false);
$gen = (string)file_get_contents($root . '/payroll_gen.php');
check('period finalize creates pending rows', strpos($gen, 'sh_generate_period_candidates') !== false);
$bulk = (string)file_get_contents($root . '/bulk_finalize_handler.php');
check('batch finalize creates pending rows for the period', strpos($bulk, 'sh_generate_period_candidates') !== false);
$save = (string)file_get_contents($root . '/payroll_save_payment.php');
check('save payment does not hard-require the audit library', !preg_match('/^\s*require(?:_once)?\s+.+attendance_audit_lib\.php/m', $save));
check('save payment still logs when the audit library is present', strpos($save, "function_exists('aa_log_ids')") !== false);
check('bulk finalize does not hard-require the audit library', !preg_match('/^\s*require(?:_once)?\s+.+attendance_audit_lib\.php/m', $bulk));
check('bulk finalize still logs when the audit library is present', strpos($bulk, "function_exists('aa_log')") !== false);

$bucket = ['reg_h' => 16.0, 'reg_pay' => 320.0, 'bonus' => 0.0, 'gross' => 410.0, 'ph_pay' => 160.0, 'stat_worked' => 90.0];
paydocs_peel_special($bucket, 'Statutory Holiday', 'Canada Day — Statutory Holiday Pay', 160.0, 0.0, 160.0, false, 8.0);
paydocs_peel_special($bucket, 'Holiday Worked', 'Canada Day — Hours worked @ 1.5x', 90.0, 0.0, 0.0, false, 0.0);
check('stat holiday hours leave Regular', abs($bucket['reg_h'] - 8.0) < 0.001 && abs($bucket['ph_h'] - 8.0) < 0.001);
check('stat holiday pay leaves Regular', abs($bucket['reg_pay'] - 160.0) < 0.001);
check('Stat Day Worked is not peeled into holiday pay hours', abs($bucket['stat_worked'] - 90.0) < 0.001 && abs($bucket['ph_h'] - 8.0) < 0.001);
$earn = $bucket['reg_pay'] + $bucket['ph_pay'] + $bucket['stat_worked'];
check('regular plus stat holiday pay plus stat day worked equals gross', abs($earn - $bucket['gross']) < 0.001);
check('worked-the-holiday is not labelled as holiday pay', paydocs_is_stat_worked('Holiday Worked', 'Hours worked @ 1.5x') && !paydocs_is_public_holiday('Holiday Worked', 'Hours worked @ 1.5x'));

function flow_pdo(): PDO {
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
        stat_calculated_amount REAL, stat_flag TEXT, stat_approval_status TEXT,
        stat_approved_amount REAL, stat_approved_by INT, stat_approved_at TEXT,
        stat_edited_by INT, stat_edit_reason TEXT)");
    $pdo->exec('CREATE TABLE attendance (id INTEGER PRIMARY KEY, user_id INT, site_id INT, clock_in TEXT, status TEXT)');
    $pdo->exec("CREATE TABLE payroll_period_stat (
        org_id INT, site_id INT, user_id INT, period_start TEXT, period_end TEXT,
        pensionable REAL, cpp_exempt REAL, cpp REAL, cpp_er REAL, ei REAL, income_tax REAL,
        vac REAL, gross REAL, net REAL)");
    return $pdo;
}

function flow_person(PDO $pdo, int $id, int $site, string $prov, int $active = 1): void {
    $pdo->prepare('INSERT INTO users (id, org_id, Name, active, hourly_rate) VALUES (?,4,?, ?,20)')->execute([$id, 'E' . $id, $active]);
    $pdo->prepare('INSERT INTO sites (SId, org_id, province, nick_name) VALUES (?,4,?,?)')->execute([$site, $prov, $prov . $site]);
    $pdo->prepare('INSERT INTO employee_company_service (user_id, site_id, start_date) VALUES (?,?,?)')->execute([$id, $site, '2025-01-01']);
}

function flow_wage(PDO $pdo, int $user, int $site, string $day, float $gross): void {
    $pdo->prepare("INSERT INTO payroll_finalized (user_id, org_id, site_id, work_date, category, total_gross, reg_pay, hourly_rate, vac, reg_hours, status, paid_amount, cpp, ei, income_tax)
                   VALUES (?,?,?,?,'Regular',?,?,20,0,8,'pending',0,5,4,3)")->execute([$user, 4, $site, $day, $gross, $gross]);
}

$pdo = flow_pdo();
flow_person($pdo, 1, 10, 'BC');
flow_person($pdo, 2, 11, 'BC', 0);
flow_person($pdo, 3, 12, 'BC');
flow_person($pdo, 4, 13, 'AB');
for ($d = 1; $d <= 15; $d++) {
    $day = sprintf('2026-06-%02d', $d);
    flow_wage($pdo, 1, 10, $day, 160);
    flow_wage($pdo, 2, 11, $day, 160);
    flow_wage($pdo, 3, 12, $day, 160);
    flow_wage($pdo, 4, 13, $day, 160);
}
sh_record_stat_exclusion($pdo, 4, 3, 12, '2026-07-01', 'quit', 9);
$first = sh_generate_period_candidates($pdo, 4, '2026-07-01|2026-07-15', 9);
$lines = $pdo->query("SELECT user_id, status, stat_approval_status, paid_amount, total_gross FROM payroll_finalized WHERE category='Statutory Holiday' ORDER BY user_id")->fetchAll(PDO::FETCH_ASSOC);
check('generator posts the eligible BC employee only', $first['posted'] === 1 && count($lines) === 1 && (int)$lines[0]['user_id'] === 1);
check('the new line is in pay and is not paid', $lines && $lines[0]['status'] === 'pending' && $lines[0]['stat_approval_status'] === 'approved' && (float)$lines[0]['paid_amount'] === 0.0);
check('an exclusion is respected', $first['excluded'] >= 1 && !$pdo->query("SELECT id FROM payroll_finalized WHERE user_id=3 AND category='Statutory Holiday'")->fetchColumn());
check('an inactive employee is skipped', !$pdo->query("SELECT id FROM payroll_finalized WHERE user_id=2 AND category='Statutory Holiday'")->fetchColumn());
check('an Alberta site is skipped', !$pdo->query("SELECT id FROM payroll_finalized WHERE user_id=4 AND category='Statutory Holiday'")->fetchColumn());
$second = sh_generate_period_candidates($pdo, 4, ['start' => '2026-07-01', 'end' => '2026-07-15'], 9);
$again = (int)$pdo->query("SELECT COUNT(*) FROM payroll_finalized WHERE category='Statutory Holiday'")->fetchColumn();
check('a second generate does not duplicate', $second['posted'] === 0 && $second['already'] === 1 && $again === 1);

$bare = new PDO('sqlite::memory:');
$bare->exec('CREATE TABLE payroll_finalized (id INT, total_gross REAL)');
check('missing approval column adds no SQL filter', pe_not_pending_sql($bare, 'f') === '');
$withCol = new PDO('sqlite::memory:');
$withCol->exec('CREATE TABLE payroll_finalized (id INT, total_gross REAL, stat_approval_status TEXT)');
check('approval column is filtered when it exists', pe_not_pending_sql($withCol, 'f') === " AND COALESCE(f.stat_approval_status,'') NOT IN ('pending','ignored')");

flow_wage($pdo, 1, 10, '2026-07-02', 1000);
$lineId = (int)$pdo->query("SELECT id FROM payroll_finalized WHERE user_id=1 AND category='Statutory Holiday'")->fetchColumn();
$grossBefore = (float)$pdo->query("SELECT total_gross FROM payroll_finalized WHERE id=$lineId")->fetchColumn();
$before = pe_refresh_period_stat($pdo, 1, 10, '2026-07-02');
check('a calculated stat line is inside period gross', $before && (float)$before['gross'] > 1000.05);
$pdo->prepare("UPDATE payroll_finalized SET stat_approval_status='pending', comments='[[stat-admin-hold]]' WHERE id=?")->execute([$lineId]);
$held = pe_refresh_period_stat($pdo, 1, 10, '2026-07-02');
check('an admin Pending line is outside period gross', $held && abs((float)$held['gross'] - 1000) < 0.05);
$pendingCpp = (float)$pdo->query("SELECT COALESCE(cpp,0)+COALESCE(ei,0)+COALESCE(income_tax,0) FROM payroll_finalized WHERE id=$lineId")->fetchColumn();
check('an admin Pending line does not keep its own deductions', $pendingCpp < 0.01);
$pdo->prepare("UPDATE payroll_finalized SET stat_approval_status='approved', comments='' WHERE id=?")->execute([$lineId]);
pe_refresh_period_stat($pdo, 1, 10, '2026-07-02');
check('approving again does not mark the line paid', sh_approve_stat_line($pdo, 4, $lineId, 9, null, null, '') === false);
$approved = $pdo->query("SELECT status, stat_approval_status, paid_amount FROM payroll_finalized WHERE id=$lineId")->fetch(PDO::FETCH_ASSOC);
check('approved stat stays unpaid', $approved && $approved['stat_approval_status'] === 'approved' && $approved['status'] === 'pending' && (float)$approved['paid_amount'] === 0.0);
$after = $pdo->query('SELECT gross FROM payroll_period_stat WHERE user_id=1 AND site_id=10 ORDER BY period_end DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
check('approval refreshes deductions onto the approved gross', $after && abs((float)$after['gross'] - (1000 + $grossBefore)) < 0.05);

if ($fail > 0) {
    echo $fail . " failed\n";
    exit(1);
}
echo "all passed\n";
