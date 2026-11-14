<?php
/**
 * Partial payment review checks. No database.
 * Run: php tests/partial_payments_review_test.php
 */
require_once __DIR__ . '/../engine/outstanding_wages_lib.php';
require_once __DIR__ . '/../engine/partial_payments_review_lib.php';

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

$netPaid = ['notes' => 'net 1000 paid per son sheet', 'status' => 'Pending', 'gross' => 1800, 'net' => 1400];
$money = ['notes' => 'Net $1,000 paid', 'status' => 'Pending', 'gross' => 1800, 'net' => 1400];
$half = ['notes' => '', 'status' => 'half paid', 'gross' => 900, 'net' => 800];
$halfGross = ['notes' => 'Half   paid', 'status' => 'Pending', 'gross' => 900, 'net' => null];
$plain = ['notes' => 'still owing', 'status' => 'Pending', 'gross' => 100, 'net' => 80];
$bigger = ['notes' => 'net 10000 paid', 'status' => 'Pending', 'gross' => 1, 'net' => 1];
check('net 1000 paid is a candidate', ppr_is_candidate($netPaid));
check('currency text is a candidate', ppr_is_candidate($money));
check('half paid on the status is a candidate', ppr_is_candidate($half));
check('an ordinary row is not a candidate', !ppr_is_candidate($plain));
check('a larger net figure is not the 1000 phrase', !ppr_is_candidate($bigger));

$sheet = ppr_sheet_payment($netPaid);
$sheetMoney = ppr_sheet_payment($money);
$sheetHalf = ppr_sheet_payment($half);
$sheetGross = ppr_sheet_payment($halfGross);
check('the sheet payment is 1000', $sheet['amount'] === 1000.0 && $sheet['basis'] === 'net 1000 paid');
check('a comma in the note is still 1000', $sheetMoney['amount'] === 1000.0);
check('half paid uses half the net', $sheetHalf['amount'] === 400.0 && $sheetHalf['basis'] === 'half of net');
check('half paid uses gross when net is blank', $sheetGross['amount'] === 450.0 && $sheetGross['basis'] === 'half of gross');

check('first name matches the person and not a longer name', ppr_name_matches('Gurpreet Kaur', 'Gurpreet Kaur') && !ppr_name_matches('Gurpreetdeep Singh', 'Gurpreet Kaur'));
check('the lookup pattern is the first name', ppr_like_pattern('Sehaj') === 'Sehaj%' && ppr_like_pattern('100% Ann') === '100\\%%');

$lines = [
    ['id' => 1, 'user_id' => 9, 'employee_name' => 'Gurpreet Kaur', 'work_date' => '2026-09-01', 'site_id' => 20, 'site_name' => 'Merritt', 'total_gross' => 100, 'cpp' => 0, 'ei' => 2, 'income_tax' => 8, 'paid_amount' => 0, 'payment_status' => 'Unpaid'],
    ['id' => 2, 'user_id' => 9, 'employee_name' => 'Gurpreet Kaur', 'work_date' => '2026-09-01', 'site_id' => 20, 'site_name' => 'Merritt', 'total_gross' => 100, 'cpp' => 4, 'ei' => 2, 'income_tax' => 8, 'paid_amount' => 50, 'payment_status' => 'Partial'],
    ['id' => 3, 'user_id' => 9, 'employee_name' => 'Gurpreet Kaur', 'work_date' => '2026-09-02', 'site_id' => 20, 'site_name' => 'Merritt', 'total_gross' => 80, 'cpp' => 3, 'ei' => 1, 'income_tax' => 6, 'paid_amount' => 0, 'payment_status' => 'Unpaid'],
    ['id' => 4, 'user_id' => 8, 'employee_name' => 'Other Person', 'work_date' => '2026-09-02', 'site_id' => 20, 'site_name' => 'Merritt', 'total_gross' => 80, 'cpp' => 3, 'ei' => 1, 'income_tax' => 6, 'paid_amount' => 0, 'payment_status' => 'Unpaid'],
];
$view = ppr_build_view(
    ['id' => 15, 'store_name' => 'Merritt', 'employee_name' => 'Gurpreet Kaur', 'pay_period' => 'Sep 1-3, 2026', 'period_start' => '2026-09-01', 'period_end' => '2026-09-03', 'gross' => 1800, 'net' => 1400, 'status' => 'Pending', 'notes' => 'net 1000 paid'],
    $lines,
    null
);
check('another person is left out of the match', $view['totals']['count'] === 3);
check('duplicate lines are flagged', in_array(1, ppr_duplicate_ids($view['lines']), true) && in_array(2, ppr_duplicate_ids($view['lines']), true) && !in_array(3, ppr_duplicate_ids($view['lines']), true));
check('the empty day is listed', $view['missing_dates'] === ['2026-09-03']);
check('zero CPP is flagged', strpos(implode(' ', $view['flags']), 'CPP is zero') !== false);
$unpaid = ppr_line_unpaid($lines[0]) + ppr_line_unpaid($lines[1]) + ppr_line_unpaid($lines[2]);
check('unpaid net is below the sheet payment', abs($view['totals']['unpaid'] - $unpaid) < 0.001 && $view['short'] === true && $view['totals']['unpaid'] < 1000);
check('net is gross minus cpp, ei, and tax', abs(ppr_line_net($lines[0]) - 90) < 0.001 && abs(ppr_line_unpaid($lines[1]) - 36) < 0.001);

$saved = ['amount' => 250, 'method' => 'Cheque', 'payment_date' => '2026-11-14', 'site_paid_from' => 'Merritt', 'verified' => 1, 'notes' => 'hold', 'shift_notes' => '{"1":{"verified":1,"site_note":"yes"}}'];
$kept = ppr_build_view($view['wage'], $lines, $saved);
check('a saved review fills the form and the shift note', $kept['form']['amount'] === 250.0 && $kept['form']['method'] === 'Cheque' && $kept['shift_notes'][1]['site_note'] === 'yes');

$ok = ppr_parse_save([
    'payment_date' => '2026-11-14',
    'site_paid_from' => 'Merritt',
    'amount' => '$1,000.00',
    'method' => 'Cash',
    'verified' => '1',
    'notes' => 'partial per son sheet',
    'shift' => [
        1 => ['verified' => '1', 'site_note' => 'Merritt'],
        99 => ['verified' => '1', 'site_note' => 'not this line'],
    ],
], [1, 2]);
check('a cash amount is kept and an unknown shift is dropped', $ok['ok'] && $ok['fields']['amount'] === 1000.0 && $ok['fields']['method'] === 'Cash' && isset($ok['fields']['shift_notes']['1']) && !isset($ok['fields']['shift_notes']['99']));
$bad = ppr_parse_save(['amount' => '10', 'method' => 'Cash/Other', 'payment_date' => '2026-02-31'], [1]);
check('Cash/Other and a bad date are refused', !$bad['ok'] && strpos($bad['error'], 'Method') !== false && strpos($bad['error'], 'date') !== false);

$lib = file_get_contents(__DIR__ . '/../engine/partial_payments_review_lib.php');
$page = file_get_contents(__DIR__ . '/../partial_payments_review.php');
$sql = file_get_contents(__DIR__ . '/../migrations/20261116_admin_partial_payment_reviews.sql');
$sqlBody = preg_replace('/^--.*$/m', '', $sql);
check('migration creates only the review table', strpos($sql, 'CREATE TABLE IF NOT EXISTS admin_partial_payment_reviews') !== false && !preg_match('/\b(payroll_finalized|payroll_payments|payroll_masters|payroll_balance)\b/', $sqlBody));
check('a select is read to the end', ppr_sql_returns_rows('SELECT 1') && ppr_sql_returns_rows('SHOW COLUMNS FROM admin_partial_payment_reviews'));
check('create table is not a row-returning query', !ppr_sql_returns_rows('CREATE TABLE IF NOT EXISTS admin_partial_payment_reviews (id INT)'));
check('schema setup reads every result', strpos($lib, 'function ppr_run_statement') !== false && strpos($lib, 'fetchAll') !== false && strpos($lib, 'closeCursor') !== false);
check('the only write is the review table', !preg_match('/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?(payroll_finalized|payroll_payments|payroll_masters|payroll_balance|admin_outstanding_wages)/i', $lib) && strpos($lib, 'INSERT INTO admin_partial_payment_reviews') !== false);
check('the page is superadmin only and says payroll is not written', strpos($page, 'ps_is_superadmin') !== false && strpos($page, 'ps_require_csrf') !== false && strpos($page, 'does not write payroll_finalized or payroll_payments') !== false && strpos($page, 'ppr_ensure_menu') !== false);
check('the page does not record a payroll payment', !preg_match('/\b(pcr_apply_payment|execute_single_payout|INSERT\s+INTO\s+payroll_payments|UPDATE\s+payroll_finalized)\b/', $page));

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
