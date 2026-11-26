<?php
/**
 * Synthetic checks for the Sheet Review edit and source panel. Uses in-memory
 * SQLite and made-up names. No workbook, real names, or real amounts.
 * Run: php tests/payroll_sheet_edit_test.php
 */
define('PSE_SKIP_MIGRATION', 1);
require_once __DIR__ . '/../engine/payroll_sheet_edit_lib.php';

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
function throws(callable $fn): string
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return '';
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, Name TEXT, org_id INT)');
$pdo->exec('CREATE TABLE sites (SId INTEGER PRIMARY KEY, nick_name TEXT, org_id INT)');
$pdo->exec('CREATE TABLE payroll_sheet_rows (id INTEGER PRIMARY KEY, org_id INT, import_id INT, source_sheet TEXT, source_row INT, row_kind TEXT,
    source_json TEXT, employee_name TEXT, store_name TEXT, user_id INT NULL, site_id INT NULL, period_start TEXT NULL, period_end TEXT NULL,
    net REAL NULL, gross REAL NULL, reported_status TEXT, draft_notes TEXT DEFAULT "", row_version INT DEFAULT 1, deleted_at TEXT NULL, updated_at TEXT NULL,
    actual_confirmed INT DEFAULT 0, proposed_amount REAL NULL)');
$pdo->exec('CREATE TABLE payroll_sheet_approval_effects (id INTEGER PRIMARY KEY, org_id INT, row_id INT, undone_at TEXT NULL)');
$pdo->exec('CREATE TABLE payroll_sheet_events (id INTEGER PRIMARY KEY, org_id INT, source_row INT, source_cell TEXT)');
$pdo->exec('CREATE TABLE payroll_sheet_cells (id INTEGER PRIMARY KEY, org_id INT, import_id INT, sheet_name TEXT, row_num INT, col_letter TEXT, col_index INT,
    cell_value TEXT, cell_formula TEXT NULL, is_number INT, created_at TEXT, UNIQUE (org_id, import_id, sheet_name, row_num, col_index))');
$pdo->exec('CREATE TABLE payroll_sheet_edit_audit (id INTEGER PRIMARY KEY, org_id INT, row_id INT, batch_key TEXT, action TEXT, field_name TEXT,
    old_value TEXT NULL, new_value TEXT NULL, actor_id INT, created_at TEXT)');

$pdo->exec("INSERT INTO users VALUES (1,'Pat Example',2),(2,'Sam Example',2),(9,'Other Org Person',4)");
$pdo->exec("INSERT INTO sites VALUES (6,'North',2),(7,'South',2),(30,'Elsewhere',4)");
function addRow(PDO $pdo, int $id, int $org, string $name, int $srcRow, ?float $net, array $raw): void
{
    $pdo->prepare('INSERT INTO payroll_sheet_rows (id, org_id, import_id, source_sheet, source_row, row_kind, source_json, employee_name, store_name, net)
                   VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $org, 1, 'Sheet A', $srcRow, 'employee_record', json_encode(['raw_values' => json_encode($raw)]), $name, 'North', $net]);
}
addRow($pdo, 101, 2, 'Alias', 3, null, ['A' => 'Alias', 'B' => '2026-09-01', 'C' => 100.5, 'E' => 'North']);
addRow($pdo, 102, 2, 'alias ', 4, 55.0, ['A' => 'alias', 'C' => 20]);
addRow($pdo, 103, 2, 'Alias', 5, 10.0, ['A' => 'Alias']);
addRow($pdo, 201, 4, 'Alias', 3, null, ['A' => 'Alias']);
$pdo->exec('INSERT INTO payroll_sheet_approval_effects (org_id, row_id) VALUES (2, 103)');

// planning
$cur = $pdo->query('SELECT * FROM payroll_sheet_rows WHERE id = 101')->fetch(PDO::FETCH_ASSOC);
$users = pse_org_users($pdo, 2);
$sites = pse_org_sites($pdo, 2);
check('org users exclude other companies', array_keys($users) === [1, 2]);
check('org sites exclude other companies', array_keys($sites) === [6, 7]);
$p = pse_plan_edit($cur, ['net' => '', 'net_unknown' => ''], $users, $sites);
check('blank net on an unknown net changes nothing', !$p['errors'] && !$p['changes']);
$p = pse_plan_edit($cur, ['net' => '0'], $users, $sites);
check('typed zero is a real zero, not unknown', !$p['errors'] && $p['changes']['net'] === [null, '0.00']);
$p = pse_plan_edit($cur, ['net' => 'abc'], $users, $sites);
check('text net is refused', count($p['errors']) === 1);
$p = pse_plan_edit($cur, ['user_id' => '9'], $users, $sites);
check('employee from another company is refused', count($p['errors']) === 1);
$p = pse_plan_edit($cur, ['site_id' => '30'], $users, $sites);
check('store from another company is refused', count($p['errors']) === 1);
$p = pse_plan_edit($cur, ['user_id' => '1', 'site_id' => '6', 'net' => '1,234.50', 'draft_notes' => ' hello '], $users, $sites);
check('valid edit lists four changes', !$p['errors'] && array_keys($p['changes']) === ['user_id', 'site_id', 'net', 'draft_notes']);
check('net is read with a comma', $p['changes']['net'][1] === '1234.50');

// apply
$msg = throws(function () use ($pdo) { pse_apply_edit($pdo, 2, 101, 7, ['row_version' => 5, 'user_id' => '1']); });
check('stale version is refused', strpos($msg, 'Someone saved') !== false);
$msg = throws(function () use ($pdo) { pse_apply_edit($pdo, 2, 201, 7, ['row_version' => 1, 'user_id' => '1']); });
check('a row in another company is refused', strpos($msg, 'not in this company') !== false);
$msg = throws(function () use ($pdo) { pse_apply_edit($pdo, 2, 103, 7, ['row_version' => 1, 'user_id' => '1']); });
check('an approved row is refused', strpos($msg, 'active approval') !== false);
$msg = throws(function () use ($pdo) { pse_apply_edit($pdo, 2, 101, 7, ['row_version' => 1, 'user_id' => '9']); });
check('linking to another company employee is refused and writes nothing', $msg !== ''
    && (int)$pdo->query('SELECT COUNT(*) FROM payroll_sheet_edit_audit')->fetchColumn() === 0);
$n = pse_apply_edit($pdo, 2, 101, 7, ['row_version' => 1, 'user_id' => '1', 'site_id' => '6', 'net' => '88.25', 'draft_notes' => 'checked']);
check('apply reports four fields', $n === 4);
$row = $pdo->query('SELECT * FROM payroll_sheet_rows WHERE id = 101')->fetch(PDO::FETCH_ASSOC);
check('row saved', (int)$row['user_id'] === 1 && (int)$row['site_id'] === 6 && abs((float)$row['net'] - 88.25) < 0.001 && $row['draft_notes'] === 'checked');
check('row version bumped', (int)$row['row_version'] === 2);
check('sheet name and source store untouched', $row['employee_name'] === 'Alias' && $row['store_name'] === 'North');
check('audit has one line per field', (int)$pdo->query('SELECT COUNT(*) FROM payroll_sheet_edit_audit WHERE row_id = 101')->fetchColumn() === 4);
$a = $pdo->query("SELECT * FROM payroll_sheet_edit_audit WHERE field_name = 'net'")->fetch(PDO::FETCH_ASSOC);
check('audit keeps who, old and new', (int)$a['actor_id'] === 7 && $a['old_value'] === null && $a['new_value'] === '88.25' && $a['created_at'] !== '');
$n = pse_apply_edit($pdo, 2, 101, 7, ['row_version' => 2, 'net' => '', 'net_unknown' => '1']);
check('unknown clears net to NULL', $n === 1 && $pdo->query('SELECT net FROM payroll_sheet_rows WHERE id = 101')->fetchColumn() === null);
$n = pse_apply_edit($pdo, 2, 101, 7, ['row_version' => 3, 'user_id' => '1']);
check('saving the same value changes nothing', $n === 0 && (int)$pdo->query('SELECT row_version FROM payroll_sheet_rows WHERE id = 101')->fetchColumn() === 3);
$audit = pse_audit_fetch($pdo, 2, 101, 50);
check('audit fetch is newest first and company scoped', $audit[0]['field_name'] === 'net' && pse_audit_fetch($pdo, 4, 101, 50) === []);

// person link
check('rows for a name ignore case and spacing and company', pse_rows_for_name($pdo, 2, 'ALIAS') === [101, 102, 103]);
$res = pse_apply_person_link($pdo, 2, 'Alias', 2, 7, 7);
check('person link skips the approved row', $res['skipped_locked'] === 1 && $res['linked'] === 2 && $res['unchanged'] === 0);
check('linked rows point at the employee and store', (int)$pdo->query('SELECT user_id FROM payroll_sheet_rows WHERE id = 102')->fetchColumn() === 2
    && (int)$pdo->query('SELECT site_id FROM payroll_sheet_rows WHERE id = 101')->fetchColumn() === 7);
check('approved row not linked', $pdo->query('SELECT user_id FROM payroll_sheet_rows WHERE id = 103')->fetchColumn() === null);
check('other company row not linked', $pdo->query('SELECT user_id FROM payroll_sheet_rows WHERE id = 201')->fetchColumn() === null);
$msg = throws(function () use ($pdo) { pse_apply_person_link($pdo, 2, 'Alias', 9, null, 7); });
check('person link to another company employee is refused', strpos($msg, 'not in this company') !== false);
$msg = throws(function () use ($pdo) { pse_apply_person_link($pdo, 2, 'Nobody', 1, null, 7); });
check('person link with no rows is refused', strpos($msg, 'No draft rows') !== false);
$code = '';
foreach (token_get_all(file_get_contents(__DIR__ . '/../engine/payroll_sheet_edit_lib.php')) as $tok) {
    if (is_array($tok) && ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT)) {
        continue;
    }
    $code .= is_array($tok) ? $tok[1] : $tok;
}
check('code never touches payments, finalized wages, or approvals tables for writing',
    strpos($code, 'payroll_payments') === false && strpos($code, 'payroll_finalized') === false
    && !preg_match('/(INSERT INTO|UPDATE|DELETE FROM)\s+payroll_sheet_approval/i', $code)
    && !preg_match('/(INSERT INTO|UPDATE|DELETE FROM)\s+(users|sites|attendance)\b/i', $code));

// source panel without stored cells: falls back to the draft rows' raw values
$sel = $pdo->query('SELECT * FROM payroll_sheet_rows WHERE id = 101')->fetch(PDO::FETCH_ASSOC);
$w = pse_source_window($pdo, 2, $sel, 3);
check('fallback mode when no cells are stored', $w['mode'] === 'rows' && $w['selected_row'] === 3);
check('fallback shows neighbouring draft rows', count($w['rows']) === 3 && $w['rows'][0]['n'] === 3);
check('fallback highlights the cells the draft used', in_array('C', $w['rows'][0]['hl'], true) && !in_array('D', $w['rows'][0]['hl'], true));
check('window reads only the given company', count(pse_source_window($pdo, 4, $sel, 3)['rows']) === 1);

// loading cells
$csv = "sheet,row,col,value,formula,is_number\nSheet A,1,A,Header,,0\nSheet A,2,C,Gross,,0\nSheet A,3,A,Alias,,0\nSheet A,3,B,2026-09-01,,0\nSheet A,3,C,100.5,,1\nSheet A,3,E,North,,0\nSheet A,4,A,alias,,0\nSheet A,4,C,20,=40/2,1\nSheet A,5,A,Alias,,0\nSheet A,6,A,Other,,0\n";
$cells = pse_cells_parse_csv($csv);
check('cells parse', count($cells) === 10 && $cells[4]['is_number'] === 1 && $cells[7]['formula'] === '=40/2');
$drafts = $pdo->query('SELECT * FROM payroll_sheet_rows WHERE org_id = 2')->fetchAll(PDO::FETCH_ASSOC);
$v = pse_cells_verify($cells, $drafts);
check('verify matches drafts to cells', $v['mismatches'] === [] && $v['checked'] === 7 && $v['missing_rows'] === []);
$bad = $cells;
$bad[4]['value'] = '999';
check('verify flags a changed amount', count(pse_cells_verify($bad, $drafts)['mismatches']) === 1);
check('bad cell line is refused', throws(function () { pse_cells_parse_csv("sheet,row,col,value\nSheet A,0,A,x\n"); }) !== '');
check('store writes every cell', pse_cells_store($pdo, 2, 1, $cells) === 10);
check('store is repeatable without duplicates', pse_cells_store($pdo, 2, 1, $cells) === 10
    && (int)$pdo->query('SELECT COUNT(*) FROM payroll_sheet_cells')->fetchColumn() === 10);
$pdo->exec("INSERT INTO payroll_sheet_events (org_id, source_row, source_cell) VALUES (2, 3, 'C3'), (4, 3, 'A3')");
$w = pse_source_window($pdo, 2, $sel, 2);
check('cells mode when cells are stored', $w['mode'] === 'cells');
check('window has the rows around the selection', array_column($w['rows'], 'n') === [1, 2, 3, 4, 5]);
check('unknown sheets get letters only', $w['header'] === [] && pse_known_headers('By Abdul')['D'] === 'net');
$selRow = null;
foreach ($w['rows'] as $r) {
    if ($r['selected']) {
        $selRow = $r;
    }
}
check('selected row cells are highlighted', $selRow && in_array('B', $selRow['hl'], true) && in_array('E', $selRow['hl'], true));
check('event cell is marked for this company only', $selRow && $selRow['ev'] === ['C']);
check('formula text is kept for the tooltip', $w['rows'][3]['cells']['C']['f'] === '=40/2');
check('other company sees no cells', pse_source_window($pdo, 4, $sel, 2)['mode'] === 'rows');
check('column letters', pse_col_index('A') === 1 && pse_col_index('AA') === 27 && pse_col_letter(28) === 'AB');

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
