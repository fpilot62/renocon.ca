<?php
/**
 * Name-level sheet links. Synthetic names only. No payroll data.
 * Run: php tests/sheet_name_link_test.php
 */
define('PSE_SKIP_MIGRATION', 1);
require_once __DIR__ . '/../engine/sheet_name_link_lib.php';

$fail = 0;
function check($label, $cond): void
{
    global $fail;
    if ($cond) {
        echo "ok  $label\n";
        return;
    }
    echo "FAIL $label\n";
    $fail++;
}

$singh = ['id' => 12, 'name' => 'Aaditya Singh', 'sites' => ['North'], 'aliases' => [], 'active' => 1];
$kaur = ['id' => 13, 'name' => 'Aaditya Kaur', 'sites' => ['South'], 'aliases' => [], 'active' => 0];
$pat = ['id' => 14, 'name' => 'Pat Example', 'sites' => ['North'], 'aliases' => ['Patty'], 'active' => 1];
$sam = ['id' => 15, 'name' => 'Sam Example', 'sites' => [], 'aliases' => [], 'active' => 0];
$people = [$singh, $kaur, $pat, $sam];

$one = psl_plan_from_rows([
    ['employee_name' => 'Aaditya', 'store_name' => 'North', 'row_kind' => 'employee_record'],
    ['employee_name' => 'aaditya ', 'store_name' => 'North', 'row_kind' => 'employee_record'],
], [], [$singh]);
check('one first-name match covers every period of that name', count($one['proposals']) === 1 && $one['proposals'][0]['user_id'] === 12 && $one['proposals'][0]['store'] === '' && $one['proposals'][0]['rows'] === 2 && $one['ambiguous'] === []);

$split = psl_plan_from_rows([
    ['employee_name' => 'Aaditya', 'store_name' => 'North', 'row_kind' => 'employee_record'],
    ['employee_name' => 'Aaditya', 'store_name' => 'South', 'row_kind' => 'employee_record'],
], [], [$singh, $kaur]);
check('each store keeps its own single match', count($split['proposals']) === 2 && $split['ambiguous'] === []);

$amb = psl_plan_from_rows([
    ['employee_name' => 'Aaditya', 'store_name' => 'North', 'row_kind' => 'employee_record'],
], [], [$singh, ['id' => 19, 'name' => 'Aaditya Other', 'sites' => ['North'], 'aliases' => []]]);
check('two candidates in one store are not proposed', $amb['proposals'] === [] && $amb['ambiguous'][0]['count'] === 2);

$alias = psl_plan_from_rows([
    ['employee_name' => 'Patty', 'store_name' => 'North', 'row_kind' => 'employee_record'],
], [['sheet_employee' => 'Patty', 'store_label' => 'North']], $people);
check('a known alias is one match and counts the outstanding row', $alias['proposals'][0]['user_id'] === 14 && $alias['proposals'][0]['rows'] === 2);

$inactive = psl_plan_from_rows([
    ['employee_name' => 'Sam Example', 'store_name' => '', 'row_kind' => 'employee_record'],
], [], $people);
check('an inactive employee is still a match', $inactive['proposals'][0]['user_id'] === 15);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, Name TEXT, org_id INT, active INT)');
$pdo->exec('CREATE TABLE sites (SId INTEGER PRIMARY KEY, nick_name TEXT, org_id INT)');
$pdo->exec('CREATE TABLE payroll_finalized (id INTEGER PRIMARY KEY, user_id INT, site_id INT)');
$pdo->exec('CREATE TABLE payroll_sheet_rows (id INTEGER PRIMARY KEY, org_id INT, row_kind TEXT, employee_name TEXT, store_name TEXT, user_id INT, site_id INT, net REAL, draft_notes TEXT, row_version INT DEFAULT 1, deleted_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE payroll_sheet_approval_effects (id INTEGER PRIMARY KEY, org_id INT, row_id INT, undone_at TEXT)');
$pdo->exec('CREATE TABLE payroll_sheet_edit_audit (id INTEGER PRIMARY KEY, org_id INT, row_id INT, batch_key TEXT, action TEXT, field_name TEXT, old_value TEXT, new_value TEXT, actor_id INT, created_at TEXT)');
$pdo->exec('CREATE TABLE payroll_outstanding_summary (id INTEGER PRIMARY KEY, org_id INT, store_label TEXT, sheet_employee TEXT, user_id INT, match_confidence TEXT, total_amount REAL, notes TEXT, source_file TEXT, loaded_at TEXT, loaded_by INT, row_version INT DEFAULT 1)');
$pdo->exec('CREATE TABLE payroll_outstanding_summary_audit (id INTEGER PRIMARY KEY, org_id INT, summary_id INT, target_type TEXT, target_id INT, batch_key TEXT, action TEXT, field_name TEXT, old_value TEXT, new_value TEXT, actor_id INT, created_at TEXT)');
psl_ensure($pdo);
$pdo->exec("INSERT INTO users (id, Name, org_id, active) VALUES (12,'Aaditya Singh',2,1),(13,'Aaditya Kaur',2,0),(10006,'Admin Vernon',2,1),(9,'Other Org',4,1)");
$pdo->exec("INSERT INTO sites (SId, nick_name, org_id) VALUES (6,'North',2),(7,'South',2)");
$pdo->exec('INSERT INTO payroll_finalized (id, user_id, site_id) VALUES (1,12,6),(2,13,7)');
$pdo->exec("INSERT INTO payroll_sheet_rows (id, org_id, row_kind, employee_name, store_name) VALUES (1,2,'employee_record','Aaditya','North'),(2,2,'employee_record','aaditya','North'),(3,2,'employee_record','Aaditya','North'),(4,4,'employee_record','Aaditya','North')");
$pdo->exec('INSERT INTO payroll_sheet_approval_effects (org_id, row_id) VALUES (2,3)');
$pdo->exec("INSERT INTO payroll_outstanding_summary (id, org_id, store_label, sheet_employee, total_amount, loaded_at, loaded_by) VALUES (8,2,'North','Aaditya',10,'2026-01-01',1)");

$loaded = psl_employees($pdo, 2, false);
$ids = array_map(static function ($p) { return (int)$p['id']; }, $loaded);
check('hidden admin is not a candidate and inactive stays', in_array(12, $ids, true) && in_array(13, $ids, true) && !in_array(10006, $ids, true));
$plan = psl_auto_plan($pdo, 2, false);
check('auto plan proposes the single North match', count($plan['proposals']) === 1 && (int)$plan['proposals'][0]['user_id'] === 12 && strpos(psl_format_plan($plan), 'Aaditya||12|Aaditya Singh|') === 0 || strpos(psl_format_plan($plan), "name|store|user_id|employee|rows\nAaditya||12|Aaditya Singh|4") !== false);

$res = psl_confirm($pdo, 2, 'Aaditya', null, 12, 7, false);
check('confirm links the open periods and skips the approval', $res['linked'] === 2 && $res['skipped_locked'] === 1 && $res['outstanding_linked'] === 1);
check('both open periods point at the employee', (int)$pdo->query('SELECT user_id FROM payroll_sheet_rows WHERE id = 1')->fetchColumn() === 12
    && (int)$pdo->query('SELECT user_id FROM payroll_sheet_rows WHERE id = 2')->fetchColumn() === 12);
check('approved and other-company rows stay unlinked', $pdo->query('SELECT user_id FROM payroll_sheet_rows WHERE id = 3')->fetchColumn() === null
    && $pdo->query('SELECT user_id FROM payroll_sheet_rows WHERE id = 4')->fetchColumn() === null);
check('row version moved on the linked row', (int)$pdo->query('SELECT row_version FROM payroll_sheet_rows WHERE id = 1')->fetchColumn() === 2);
check('outstanding row is linked and audited', (int)$pdo->query('SELECT user_id FROM payroll_outstanding_summary WHERE id = 8')->fetchColumn() === 12
    && (int)$pdo->query("SELECT COUNT(*) FROM payroll_outstanding_summary_audit WHERE action = 'link_name'")->fetchColumn() >= 1);
check('the name map and its audit exist', (int)$pdo->query('SELECT user_id FROM payroll_sheet_name_links WHERE org_id = 2')->fetchColumn() === 12
    && (int)$pdo->query('SELECT COUNT(*) FROM payroll_sheet_name_link_audit')->fetchColumn() === 1);
check('a later import of that name resolves to the same employee', psl_mapped_user_id($pdo, 2, ' Aaditya ', 'North') === 12);
$again = psl_confirm($pdo, 2, 'Aaditya', null, 12, 7, false);
check('confirming the same name again changes nothing', $again['linked'] === 0 && $again['unchanged'] === 2 && $again['skipped_locked'] === 1);
$msg = '';
try {
    psl_confirm($pdo, 2, 'Aaditya', null, 9, 7, false);
} catch (Throwable $e) {
    $msg = $e->getMessage();
}
check('another company employee is refused', strpos($msg, 'not in this company') !== false);
$view = psl_unconfirmed_view($pdo, 2, false);
check('a confirmed name leaves the unconfirmed list', $view['count'] === 0);

$pdo->exec("INSERT INTO payroll_sheet_rows (id, org_id, row_kind, employee_name, store_name) VALUES (5,2,'employee_record','Aaditya','South')");
$open = psl_unconfirmed_view($pdo, 2, false);
check('an org-wide map also covers a later row at another store', psl_mapped_user_id($pdo, 2, 'Aaditya', 'South') === 12 && $open['count'] === 0);

$page = (string)file_get_contents(__DIR__ . '/../payroll_sheet_review.php');
$sheet = (string)file_get_contents(__DIR__ . '/../payroll_sheet_sheet.php');
check('name matches is one collapsed section', strpos($page, 'data-panel="name-matches"') !== false && strpos($page, 'Name matches') !== false && strpos($page, 'confirm_name_link') !== false);
check('per-row link controls are gone', strpos($page, 'link-user') === false && strpos($page, 'save_outstanding_link') === false && strpos($sheet, 'link_person') === false);
$code = '';
foreach (token_get_all(file_get_contents(__DIR__ . '/../engine/sheet_name_link_lib.php')) as $tok) {
    if (is_array($tok) && ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT)) {
        continue;
    }
    $code .= is_array($tok) ? $tok[1] : $tok;
}
check('the name map does not write payroll', strpos($code, 'payroll_finalized') !== false
    && !preg_match('/(INSERT INTO|UPDATE|DELETE FROM)\s+payroll_(finalized|payments)\b/i', $code));

if ($fail > 0) {
    echo $fail . " failed\n";
    exit(1);
}
echo "all passed\n";
