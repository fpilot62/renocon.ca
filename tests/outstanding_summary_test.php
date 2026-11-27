<?php
/**
 * Outstanding pay summary. Synthetic rows only. No real names or payroll data.
 * Run: php tests/outstanding_summary_test.php
 */
require_once __DIR__ . '/../engine/outstanding_summary_lib.php';

$fail = 0;
function check(string $label, bool $cond): void
{
    global $fail;
    if ($cond) {
        echo "ok  $label\n";
        return;
    }
    echo "FAIL $label\n";
    $fail++;
}

$csv = "store,sheet_employee,total_amount,pending,balance,notes,user_id,match_confidence\n"
    . "North,Pat Example,100.50,9.00,1.00,note a,1,exact\n"
    . "South,Sam Example,\"1,200.00\",0,0,,,\n";
$parsed = pos_parse_csv($csv);
check('pending and balance are not kept', !isset($parsed[0]['pending']) && !isset($parsed[0]['balance']));
check('the total amount is read and a comma is allowed', abs($parsed[0]['total_amount'] - 100.50) < 0.001 && abs($parsed[1]['total_amount'] - 1200) < 0.001);
check('a blank user id stays unlinked', $parsed[1]['user_id'] === 0 && $parsed[0]['user_id'] === 1);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, Name TEXT, org_id INT)');
$pdo->exec('CREATE TABLE sites (SId INTEGER PRIMARY KEY, nick_name TEXT, org_id INT)');
$pdo->exec('CREATE TABLE payroll_finalized (id INTEGER PRIMARY KEY, user_id INT, site_id INT, pay_period TEXT, pay_period_end TEXT, work_date TEXT, total_gross REAL, cpp REAL, ei REAL, income_tax REAL, cpp2 REAL, vac REAL, paid_amount REAL)');
$pdo->exec("INSERT INTO users VALUES (1,'Pat Example',2),(2,'Sam Example',2),(9,'Other Org',4),(10006,'admin vernon',2)");
$pdo->exec("INSERT INTO sites VALUES (6,'North',2),(7,'South',2)");
$pdo->exec("INSERT INTO payroll_finalized VALUES
    (1,1,6,'2026-11-01','2026-11-15','2026-11-01',200,10,4,30,3,8,50),
    (2,1,6,'2026-11-16','2026-11-30','2026-11-16',100,NULL,2,10,0,1,0),
    (3,1,6,'2026-11-14','2026-11-27','2026-11-14',40,1,1,5,0,0,0)");

$dry = pos_load($pdo, 2, $parsed, 7, '/root/private.csv', true);
check('a dry run writes nothing', $dry['dry'] === true && (int)$pdo->query('SELECT COUNT(*) FROM payroll_outstanding_summary')->fetchColumn() === 0);
check('the dry run still totals the sheet', abs($dry['grand'] - 1300.50) < 0.001 && $dry['stores']['North']['count'] === 1);
$bad = '';
try {
    pos_load($pdo, 2, [['store' => 'North', 'sheet_employee' => 'Nope', 'total_amount' => 1, 'notes' => '', 'user_id' => 9, 'match_confidence' => '']], 7, 'x.csv', false);
} catch (Throwable $e) {
    $bad = $e->getMessage();
}
check('a user from another company is refused', strpos($bad, 'not in this company') !== false && (int)$pdo->query('SELECT COUNT(*) FROM payroll_outstanding_summary')->fetchColumn() === 0);

$loaded = pos_load($pdo, 2, $parsed, 7, '/root/private.csv', false);
check('the load inserts both rows', $loaded['inserted'] === 2 && $loaded['dry'] === false);
pos_link_employee($pdo, 2, 2, 7, 2, 1, true);
pos_add_payment($pdo, 2, 1, 7, ['paid_date' => '2026-07-10', 'amount' => '10', 'method' => 'Cash', 'reference_no' => 'A', 'note' => 'first']);
$again = pos_parse_csv("store,sheet_employee,total_amount,pending,balance,notes,user_id,match_confidence\nNorth,Pat Example,80,1,1,note b,,\nSouth,Sam Example,5,0,0,kept,2,sheet\n");
pos_load($pdo, 2, $again, 7, 'reload.csv', false);
$pat = $pdo->query('SELECT * FROM payroll_outstanding_summary WHERE sheet_employee = \'Pat Example\'')->fetch(PDO::FETCH_ASSOC);
$sam = $pdo->query('SELECT * FROM payroll_outstanding_summary WHERE sheet_employee = \'Sam Example\'')->fetch(PDO::FETCH_ASSOC);
check('a reload keeps a saved link when the csv user id is blank', (int)$pat['user_id'] === 1 && abs((float)$pat['total_amount'] - 80) < 0.001);
check('a reload can set a user id and does not drop payments', (int)$sam['user_id'] === 2 && (int)$pdo->query('SELECT COUNT(*) FROM payroll_outstanding_payments')->fetchColumn() === 1);
check('payroll rows are not marked paid by the load', abs((float)$pdo->query('SELECT paid_amount FROM payroll_finalized WHERE id = 1')->fetchColumn() - 50) < 0.001);

$net = pos_cpp_ei_net([['gross' => 200.0, 'cpp' => 10.0, 'ei' => 4.0], ['gross' => 100.0, 'cpp' => 5.0, 'ei' => 1.0]]);
check('net removes employee CPP and EI and not tax', $net['state'] === 'amount' && abs($net['amount'] - 280) < 0.001);
check('a missing CPP or EI is unknown', pos_cpp_ei_net([['gross' => 100.0, 'cpp' => null, 'ei' => 1.0]])['state'] === 'unknown');
check('no payroll lines stay blank', pos_cpp_ei_net([])['state'] === 'blank');
check('a line is placed by its start date', pos_line_in_half('2026-11-14', '2026-11-01', '2026-11-15') && !pos_line_in_half('2026-11-14', '2026-11-16', '2026-11-30'));

$today = new DateTimeImmutable('2026-11-27');
$table = pos_review_table($pdo, 2, false, $today, 7);
check('the current month opens as two halves', count($table['periods']) === 2 && $table['periods'][0]['label'] === 'Nov 1–15' && $table['periods'][1]['label'] === 'Nov 16–30');
$patRow = null;
foreach ($table['groups'] as $group) {
    foreach ($group['rows'] as $row) {
        if ($row['name'] === 'Pat Example') {
            $patRow = $row;
        }
    }
}
$first = $table['periods'][0]['id'];
$second = $table['periods'][1]['id'];
check('November 1 to 15 uses the lines that start in that half', $patRow && $patRow['periods'][$first]['source'] === 'payroll' && abs($patRow['periods'][$first]['amount'] - 224) < 0.001);
check('November 16 to 30 is unknown when CPP is missing', $patRow && $patRow['periods'][$second]['state'] === 'unknown');
check('an unknown period makes the balance unknown', $patRow && $patRow['balance']['state'] === 'unknown' && $patRow['final']['state'] === 'unknown');

pos_save_period_amount($pdo, 2, (int)$pat['id'], $second, 7, '15.25', 0, ['state' => 'unknown', 'amount' => null]);
pos_add_payment($pdo, 2, (int)$pat['id'], 7, ['paid_date' => '2026-08-10', 'amount' => '25.00', 'method' => '', 'reference_no' => '', 'note' => '']);
$table = pos_review_table($pdo, 2, false, $today, 7);
$patRow = null;
foreach ($table['groups'] as $group) {
    foreach ($group['rows'] as $row) {
        if ($row['name'] === 'Pat Example') {
            $patRow = $row;
        }
    }
}
check('a typed period amount overrides the payroll default', $patRow && $patRow['periods'][$second]['source'] === 'manual' && abs($patRow['periods'][$second]['amount'] - 15.25) < 0.001);
check('balance adds the sheet total and the period columns', $patRow && abs($patRow['balance']['amount'] - (80 + 224 + 15.25)) < 0.001);
check('paid is the sum of dated payments and the date is the latest', $patRow && abs($patRow['paid'] - 35) < 0.001 && $patRow['paid_on'] === '2026-08-10' && $patRow['payment_count'] === 2);
check('final balance subtracts the payment sum', $patRow && abs($patRow['final']['amount'] - (80 + 224 + 15.25 - 35)) < 0.001);
$payId = (int)$patRow['payments'][0]['id'];
$payVersion = (int)$patRow['payments'][0]['row_version'];
pos_delete_payment($pdo, 2, $payId, 7, $payVersion);
$left = pos_sum_payments($pdo->query('SELECT * FROM payroll_outstanding_payments WHERE summary_id = ' . (int)$pat['id'])->fetchAll(PDO::FETCH_ASSOC));
check('a removed payment is kept but left out of the sum', abs($left['amount'] - 25) < 0.001 && $left['paid_on'] === '2026-08-10' && (int)$pdo->query('SELECT deleted FROM payroll_outstanding_payments WHERE id = ' . $payId)->fetchColumn() === 1);
$stale = '';
try {
    pos_edit_payment($pdo, 2, (int)$patRow['payments'][1]['id'], 7, 1, ['paid_date' => '2026-08-10', 'amount' => '1']);
    pos_edit_payment($pdo, 2, (int)$patRow['payments'][1]['id'], 7, 1, ['paid_date' => '2026-08-10', 'amount' => '9']);
} catch (Throwable $e) {
    $stale = $e->getMessage();
}
check('a stale payment version is refused', strpos($stale, 'after you opened it') !== false);

pos_remove_period($pdo, 2, $second, 7);
$after = pos_review_table($pdo, 2, false, $today, 7);
check('removing one period does not bring it back', count($after['periods']) === 1 && $after['periods'][0]['label'] === 'Nov 1–15');
pos_add_period($pdo, 2, '2026-03-01', '2026-03-15', 7);
$march = pos_period_label('2026-03-01', '2026-03-15');
check('an added period is labeled by its month and half', $march === 'Mar 1–15');

$pdo->prepare('INSERT INTO payroll_outstanding_summary (org_id, store_label, sheet_employee, user_id, total_amount, loaded_at, loaded_by, row_version) VALUES (2,?,?,?,?,?,?,1)')
    ->execute(['North', 'admin test', 10006, 4, '2026-11-27 00:00:00', 7]);
$hidden = pos_review_table($pdo, 2, false, $today, 7);
$shown = pos_review_table($pdo, 2, true, $today, 7);
check('a hidden admin row stays out until the toggle is on', $hidden['count'] + 1 === $shown['count']);
$refused = '';
try {
    pos_link_employee($pdo, 2, (int)$pat['id'], 7, 10006, (int)$pdo->query('SELECT row_version FROM payroll_outstanding_summary WHERE id = ' . (int)$pat['id'])->fetchColumn(), false);
} catch (Throwable $e) {
    $refused = $e->getMessage();
}
check('linking a hidden admin is refused', strpos($refused, 'hidden') !== false);

$lib = (string)file_get_contents(__DIR__ . '/../engine/outstanding_summary_lib.php');
$sql = (string)file_get_contents(__DIR__ . '/../migrations/20261127_payroll_outstanding_summary.sql');
$page = (string)file_get_contents(__DIR__ . '/../payroll_sheet_review.php');
check('the migration only creates the new tables', strpos($sql, 'CREATE TABLE IF NOT EXISTS payroll_outstanding_payments') !== false && strpos($sql, 'CREATE TABLE IF NOT EXISTS payroll_outstanding_periods') !== false && !preg_match('/INSERT\\s+INTO\\s+payroll_finalized/i', $sql) && !preg_match('/INSERT\\s+INTO\\s+payroll_payments/i', $sql));
check('the library does not write payroll payments or finalized rows', !preg_match('/INSERT\\s+INTO\\s+payroll_payments/i', $lib) && !preg_match('/UPDATE\\s+payroll_finalized/i', $lib) && strpos($lib, 'total_gross') !== false && strpos($lib, 'f.cpp') !== false && strpos($lib, 'f.ei') !== false);
check('the page shows the outstanding table and manual payments', strpos($page, 'Outstanding pay (Employee Pay status sheet)') !== false && strpos($page, 'data-default-open="1"') !== false && strpos($page, 'Add period') !== false && strpos($page, 'Add payment') !== false && strpos($page, 'Manual reconciliation') !== false && strpos($page, 'Final balance') !== false);
check('vacation pay is not a column on this table', strpos($page, 'Vacation Pay') === false);

if ($fail > 0) {
    echo $fail . " failed\n";
    exit(1);
}
echo "all passed\n";
