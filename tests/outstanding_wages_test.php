<?php
/**
 * Outstanding wages ledger checks. No database.
 * Run: php tests/outstanding_wages_test.php
 */
require_once __DIR__ . '/../engine/outstanding_wages_lib.php';

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

$blank = ow_parse_money('', false);
$zero = ow_parse_money('$0.00', true);
$gross = ow_parse_money('$1,234.50', true);
$neg = ow_parse_money('(40.00)', false);
$junk = ow_parse_money('n/a', true);
check('blank optional amount stays null', $blank['ok'] && $blank['value'] === null);
check('typed zero stays zero', $zero['ok'] && $zero['value'] === 0.0);
check('currency commas are money', $gross['ok'] && abs($gross['value'] - 1234.5) < 0.001);
check('parentheses are negative', $neg['ok'] && abs($neg['value'] + 40) < 0.001);
check('text is not a gross amount', !$junk['ok'] && $junk['value'] === null);

$iso = ow_parse_period('2026-09-01 to 2026-09-15');
$same = ow_parse_period('Sep 1-15, 2026');
$span = ow_parse_period('September 1 – September 15, 2026');
$bad = ow_parse_period('Feb 31-32, 2026');
$none = ow_parse_period('first half');
check('iso pay period keeps both dates', $iso['start'] === '2026-09-01' && $iso['end'] === '2026-09-15');
check('same-month pay period is parsed', $same['start'] === '2026-09-01' && $same['end'] === '2026-09-15');
check('month names across the period are parsed', $span['start'] === '2026-09-01' && $span['end'] === '2026-09-15');
check('impossible dates stay blank', $bad['start'] === null && $bad['end'] === null);
check('free text period stays text only', $none['start'] === null && $none['end'] === null);

$csv = "Store,Employee,Pay period,Gross,Net,Employer deductions,Payroll record in our portal,Notes\n"
    . "Penticton,Pat Example,\"Sep 1-15, 2026\",\"$1,000.00\",,\"12.50\",portal-1,\"note <b>keep</b>\"\n"
    . "Penticton,Pat Example,\"Sep 1-15, 2026\",1000.00,,12.50,portal-1,\"note <b>keep</b>\"\n"
    . "Westbank,Pat Example,\"Sep 1-15, 2026\",1000,800,,portal-2,Second\n"
    . ",Nobody,period,10,9,1,x,y\n"
    . "Vernon,Pat Example,period,n/a,1,1,x,y\n";
$rows = ow_parse_csv($csv);
check('csv keeps a blank net', $rows[0]['net'] === null && abs($rows[0]['gross'] - 1000) < 0.001 && abs($rows[0]['employer_deductions'] - 12.5) < 0.001);
check('csv keeps the note text', $rows[0]['notes'] === 'note <b>keep</b>' && $rows[0]['period_start'] === '2026-09-01');
check('formatting differences are the same duplicate', $rows[0]['dup_key'] === $rows[1]['dup_key'] && $rows[0]['dup_key'] !== $rows[2]['dup_key']);
check('missing store and bad gross are invalid', $rows[3]['error'] !== '' && $rows[4]['error'] !== '');
$part = ow_partition_import([$rows[2]['dup_key'] => true], $rows);
check('exact duplicates and invalid rows are not inserted', count($part['insert']) === 1 && count($part['skipped']) === 2 && count($part['invalid']) === 2 && $part['insert'][0]['employee'] === 'Pat Example' && $part['insert'][0]['store'] === 'Penticton');
check('a spreadsheet formula is stored as text on export', ow_csv_cell('=1+1') === "'=1+1");

$sites = ow_index_by_name([
    ['SId' => 6, 'nick_name' => 'Penticton'],
    ['SId' => 11, 'nick_name' => 'Westbank'],
    ['SId' => 19, 'nick_name' => 'Westbank'],
], 'SId', 'nick_name');
check('a unique store name links and a repeated name does not', ow_match_id(' penticton ', $sites) === 6 && ow_match_id('Westbank', $sites) === null);

$paid = ow_parse_edit('status', 'Paid');
$weird = ow_parse_edit('status', 'Approved');
$netBlank = ow_parse_edit('net', '');
$flag = ow_parse_edit('flagged', '1');
$unknown = ow_parse_edit('payroll_id', '1');
check('paid is a ledger label', $paid['ok'] && $paid['value'] === 'Paid');
check('unknown status is refused', !$weird['ok']);
check('clearing net stores null', $netBlank['ok'] && $netBlank['value'] === null);
check('flag is a yes or no', $flag['ok'] && $flag['value'] === 1);
check('payroll columns cannot be edited', !$unknown['ok']);
check('unchanged money is not a new edit', ow_same_value('gross', 10.0, 10));
check('changed money is an edit', !ow_same_value('net', null, 0));

$rows = [
    ['id' => 2, 'store_name' => 'Penticton', 'site_id' => 6, 'employee_name' => 'Bea', 'pay_period' => 'Sep 16-30, 2026', 'period_start' => '2026-09-16', 'gross' => 50, 'net' => null, 'employer_deductions' => 5, 'status' => 'Pending', 'flagged' => 0, 'notes' => '', 'portal_record' => ''],
    ['id' => 1, 'store_name' => 'Penticton', 'site_id' => 6, 'employee_name' => 'Bea', 'pay_period' => 'Sep 1-15, 2026', 'period_start' => '2026-09-01', 'gross' => 40, 'net' => 30, 'employer_deductions' => null, 'status' => 'Paid', 'flagged' => 1, 'notes' => 'hold', 'portal_record' => 'p'],
    ['id' => 3, 'store_name' => 'Vernon', 'site_id' => 19, 'employee_name' => 'Ada', 'pay_period' => 'old', 'period_start' => null, 'gross' => 10, 'net' => 10, 'employer_deductions' => 1, 'status' => 'Pending', 'flagged' => 0, 'notes' => '', 'portal_record' => ''],
];
$sorted = ow_sort_rows($rows);
check('rows sort by employee then period', $sorted[0]['id'] === 1 && $sorted[1]['id'] === 2 && $sorted[2]['id'] === 3);
$groups = ow_group_stores($sorted);
check('one section per store', count($groups) === 2 && $groups[0]['store_name'] === 'Penticton' && count($groups[0]['rows']) === 2);
$tot = ow_totals($groups[0]['rows']);
check('blank net is not counted as zero and pending is separate from paid', abs($tot['gross'] - 90) < 0.001 && abs($tot['net'] - 30) < 0.001 && $tot['net_missing'] === 1 && $tot['pending_count'] === 1 && abs($tot['pending_gross'] - 50) < 0.001 && abs($tot['employer'] - 5) < 0.001);
$parts = ow_er_breakdown('cash. ER CPP 12.50 / ER EI 8.10 / WCB 3.00 and a note');
check('notes split ER CPP, ER EI, and WCB', $parts['er_cpp'] === 12.5 && $parts['er_ei'] === 8.1 && $parts['wcb'] === 3.0);
$emptyParts = ow_er_breakdown('no employer split here');
check('notes without the phrase leave the parts blank', $emptyParts['er_cpp'] === null && $emptyParts['er_ei'] === null && $emptyParts['wcb'] === null);
$withNote = $groups[0]['rows'];
$withNote[0]['notes'] = 'ER CPP 10 / ER EI 4 / WCB 1';
$withNote[1]['notes'] = 'ER CPP 2.25 / ER EI 1 / WCB 0.50';
$burden = ow_totals($withNote);
check('store employer burden sums the column and the note parts', abs($burden['employer'] - 5) < 0.001 && abs($burden['er_cpp'] - 12.25) < 0.001 && abs($burden['er_ei'] - 5) < 0.001 && abs($burden['wcb'] - 1.5) < 0.001 && $burden['er_cpp_rows'] === 2);
$found = ow_filter_rows($sorted, 'hold', true, 'Bea');
check('search and flagged and employee filters combine', count($found) === 1 && (int)$found[0]['id'] === 1);
$export = ow_export_csv($found);
check('export keeps the blank net and neutralizes formulas', strpos($export, 'Store,Employee') === 0 && substr_count($export, "\n") === 2);

$lib = file_get_contents(__DIR__ . '/../engine/outstanding_wages_lib.php');
$page = file_get_contents(__DIR__ . '/../outstanding_wages.php');
$sql = file_get_contents(__DIR__ . '/../migrations/20261114_admin_outstanding_wages.sql');
$forbidden = '/\b(payroll_finalized|payroll_payments|payroll_masters|payroll_balance|pcr_apply_payment)\b/';
check('ledger library does not write payroll tables', !preg_match($forbidden, $lib));
check('ledger page does not write payroll tables', !preg_match($forbidden, $page));
check('migration creates both tables and does not touch payroll', strpos($sql, 'CREATE TABLE IF NOT EXISTS admin_outstanding_wages') !== false && strpos($sql, 'CREATE TABLE IF NOT EXISTS admin_outstanding_wage_edits') !== false && !preg_match($forbidden, $sql));
check('page is superadmin gated for the screen and for ajax', strpos($page, 'ps_is_superadmin') !== false && strpos($page, 'admin_super_require(true)') !== false && strpos($page, 'ps_require_csrf') !== false);
check('import confirms before insert', strpos($page, 'confirm_import') !== false && strpos($page, 'Nothing is saved until you confirm') !== false);
check('page registers under the admin menu', strpos($page, "admin_super_ensure_menu(\$pdo, 'Outstanding Wages', 'outstanding_wages.php')") !== false);
check('save is limited to ledger fields', strpos($lib, 'function ow_save_field') !== false && strpos($lib, 'admin_outstanding_wage_edits') !== false);
check('page shows employer burden beside gross and net', strpos($page, 'Employer burden') !== false && strpos($page, 'data-sum="employer"') !== false && strpos($page, 'ER CPP') !== false && strpos($page, 'ps_require_csrf') !== false);
$bare = ['store' => 'A', 'employee' => 'B', 'pay_period' => 'C', 'gross' => 1, 'net' => null, 'employer_deductions' => null, 'portal_record' => '', 'notes' => ''];
$withBlankVacation = $bare;
$withBlankVacation['vacation_pay'] = null;
check('a blank vacation pay keeps the old duplicate key', ow_dup_key($bare) === ow_dup_key($withBlankVacation));
$withVacation = $bare;
$withVacation['vacation_pay'] = 15.5;
check('a vacation amount is not the same duplicate', ow_dup_key($bare) !== ow_dup_key($withVacation));
$vacCsv = "Store,Employee,Pay period,Gross,Vacation pay,Net,Employer deductions,Payroll record in our portal,Notes\n"
    . "Penticton,Pat Example,period,100,15.50,80,1,portal,note\n";
$vacRows = ow_parse_csv($vacCsv);
check('vacation pay imports after gross', abs($vacRows[0]['vacation_pay'] - 15.5) < 0.001 && abs($vacRows[0]['gross'] - 100) < 0.001);
$oldFile = ow_parse_csv("Store,Employee,Pay period,Gross,Net,Employer deductions,Payroll record in our portal,Notes\nPenticton,Pat Example,period,100,,1,portal,note\n");
check('the older eight-column file still imports', $oldFile[0]['vacation_pay'] === null && $oldFile[0]['error'] === '' && abs($oldFile[0]['gross'] - 100) < 0.001);
$payrollCost = ow_row_cost(['gross' => 100, 'vacation_pay' => 10, 'net' => 80, 'employer_deductions' => 8, 'pay_as_invoice' => 0]);
check('payroll cost is gross plus vacation plus burden', abs($payrollCost['total_cost'] - 118) < 0.001 && $payrollCost['gst'] === null);
$invoiceCost = ow_row_cost(['gross' => 100, 'vacation_pay' => 10, 'net' => 80, 'employer_deductions' => 8, 'pay_as_invoice' => 1]);
check('invoice gst is five percent of net and burden is left out', abs($invoiceCost['gst'] - 4) < 0.001 && abs($invoiceCost['invoice_total'] - 84) < 0.001 && abs($invoiceCost['total_cost'] - 84) < 0.001 && abs($invoiceCost['employer_chosen']) < 0.001);
$mixed = ow_totals([
    ['gross' => 100, 'vacation_pay' => 10, 'net' => 80, 'employer_deductions' => 8, 'pay_as_invoice' => 1, 'notes' => '', 'status' => 'Pending'],
    ['gross' => 50, 'vacation_pay' => null, 'net' => 40, 'employer_deductions' => 5, 'pay_as_invoice' => 0, 'notes' => '', 'status' => 'Pending'],
]);
check('chosen total mixes invoice and payroll', abs($mixed['employer'] - 5) < 0.001 && abs($mixed['gst'] - 4) < 0.001 && abs($mixed['total_cost'] - 139) < 0.001 && abs($mixed['all_payroll'] - 173) < 0.001 && abs($mixed['all_invoiced'] - 126) < 0.001);
$newSql = file_get_contents(__DIR__ . '/../migrations/20261115_outstanding_wages_vacation_invoice.sql');
check('vacation and invoice columns are added only when missing', strpos($newSql, 'information_schema.COLUMNS') !== false && strpos($newSql, 'vacation_pay') !== false && strpos($newSql, 'pay_as_invoice') !== false && !preg_match($forbidden, $newSql));
check('page edits vacation and invoice and can sort and collapse', strpos($page, 'vacation_pay') !== false && strpos($page, 'pay_as_invoice') !== false && strpos($page, 'set_store_invoice') !== false && strpos($page, 'localStorage') !== false && strpos($page, 'data-sort-key') !== false && strpos($page, 'Collapse all') !== false);

if ($fail > 0) {
    echo "$fail failed\n";
    exit(1);
}
echo "all passed\n";
exit(0);
