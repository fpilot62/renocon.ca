<?php
/**
 * Synthetic checks for sheet review. No workbook, names, or amounts from the source files.
 * Run: php tests/payroll_sheet_review_test.php
 */
require_once __DIR__ . '/../engine/payroll_sheet_review_lib.php';

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

$blank = psr_parse_amount('');
$zero = psr_parse_amount('0');
$junk = psr_parse_amount('n/a');
check('blank amount stays null', $blank['ok'] && $blank['value'] === null);
check('typed zero stays zero', $zero['ok'] && $zero['value'] === 0.0);
check('text amount is not zero', !$junk['ok'] && $junk['value'] === null);

check('paid stays paid', psr_normalize_status('Paid') === 'paid' && psr_normalize_status('PAID') === 'paid');
check('explicit not paid is not unknown', psr_normalize_status('Not Paid') === 'not_paid');
check('blank status is unknown, not unpaid', psr_normalize_status('') === 'unknown');
check('unrecognized status is unknown, not unpaid', psr_normalize_status('maybe') === 'unknown');

$csv = "source_sheet,source_row,row_kind,employee,store,period_start,period_end,year_confidence,gross,net,reported_status,flags,raw_notes\n"
    . "Example,2,employee_record,Pat Example,North,2026-09-01,2026-09-15,explicit,100,,Paid,\"[\"\"net_unknown\"\",\"\"year_requires_confirmation\"\"]\",\"note <b>keep</b>\"\n"
    . "Example,3,employee_record,Pat Example,North,2026-09-16,2026-09-30,explicit,10,0,unknown,[] ,\"\"\n";
$mapped = [];
foreach (psr_csv_records($csv) as $rec) {
    $mapped[] = psr_map_workbook_row($rec);
}
check('imported net stays null', $mapped[0]['net'] === null && $mapped[0]['gross'] === 100.0);
check('paid without net is still paid', $mapped[0]['reported_status'] === 'paid');
check('source flags and notes are kept', in_array('net_unknown', $mapped[0]['flags'], true) && strpos($mapped[0]['raw_notes'], '<b>') !== false);
check('explicit zero net is zero', $mapped[1]['net'] === 0.0);
check('import does not decide contractor or GST from cash', $mapped[0]['party_class'] === 'unanswered' && $mapped[0]['pay_route'] === 'unset' && (int)$mapped[0]['gst_verified'] === 0 && (int)$mapped[0]['actual_confirmed'] === 0);

$event = psr_map_event_row([
    'event_id' => 'ex-1',
    'source_row' => '9',
    'signed_amount_raw' => '-40',
    'allocation_status' => 'unallocated',
    'flags' => '["no_period_allocation_approved"]',
]);
check('cash event stays unallocated', $event['allocation_status'] === 'unallocated' && $event['signed_amount'] === -40.0 && in_array('no_period_allocation_approved', $event['flags'], true));

$proposal = psr_map_proposal_row([
    'employee' => 'Pat Example',
    'proposed_amount' => '25',
    'route' => 'cash',
    'gst_verified' => 'yes',
    'party_class' => 'employee',
]);
check('cash proposal does not become GST', $proposal['pay_route'] === 'cash' && (int)$proposal['gst_verified'] === 0 && $proposal['amount_basis'] === 'unset');

$dups = psr_duplicate_findings(
    [['source_sheet' => 'Example', 'source_row' => 2, 'row_kind' => 'employee_record', 'employee_name' => 'Pat Example', 'store_name' => 'North', 'period_start' => '2026-09-01', 'period_end' => '2026-09-15']],
    [['source_sheet' => 'Example', 'source_row' => 2, 'row_kind' => 'employee_record', 'employee_name' => 'Pat Example', 'store_name' => 'North', 'period_start' => '2026-09-01', 'period_end' => '2026-09-15']]
);
check('duplicate source row is previewed', count($dups) === 1 && $dups[0]['reason'] === 'same source row');

$baseRow = [
    'row_kind' => 'employee_record',
    'user_id' => 5,
    'site_id' => 2,
    'period_start' => '2026-09-01',
    'period_end' => '2026-09-15',
    'year_confidence' => 'explicit',
    'year_confirmed' => 0,
    'gross' => 100,
    'net' => 100,
    'reported_status' => 'paid',
    'ee_deduction_state' => 'none',
    'er_cpp_state' => 'none',
    'er_ei_state' => 'none',
    'er_other_state' => 'none',
    'actual_confirmed' => 0,
    'party_class' => 'employee',
    'pay_route' => 'cash',
    'amount_basis' => 'net',
    'proposed_amount' => 40,
];
$created = psr_approval_plan($baseRow, [], []);
check('net equal to gross can create a line', $created['ok'] && $created['wage']['action'] === 'create' && in_array('create_line', $created['writes'], true));
check('proposal is not posted as a payment', $created['payment']['action'] === 'skip' && !in_array('post_payment', $created['writes'], true));

$unknownNet = $baseRow;
$unknownNet['net'] = null;
$unknownNet['reported_status'] = 'Paid';
$unknownNet['actual_confirmed'] = 1;
$unknownNet['actual_amount'] = 100;
$unknownNet['actual_date'] = '2026-09-20';
$unknownNet['actual_method'] = 'Cash';
$blockedNet = psr_approval_plan($unknownNet, [], []);
check('unknown net stays unpaid-blocker and is not gross', !$blockedNet['ok'] && $blockedNet['status'] === 'paid' && $blockedNet['payment']['amount'] === null && $blockedNet['writes'] === []);
check('unknown net message does not use gross as net', strpos(implode(' ', $blockedNet['blocks']), 'Gross was not used as net') !== false);

$split = $baseRow;
$split['net'] = 80;
$splitPlan = psr_approval_plan($split, [], []);
check('unknown deduction split is not invented', !$splitPlan['ok'] && strpos(implode(' ', $splitPlan['blocks']), 'were not invented') !== false);

$existing = [[
    'id' => 10, 'org_id' => 1, 'user_id' => 5, 'site_id' => 2,
    'period_start' => '2026-09-01', 'period_end' => '2026-09-15',
    'gross' => 100.0, 'net' => 80.0, 'net_known' => true, 'paid' => 0.0, 'status' => 'not_paid',
]];
$linkRow = $baseRow;
$linkRow['gross'] = 100;
$linkRow['net'] = 80;
$linkRow['ee_deduction_state'] = 'unknown';
$linked = psr_approval_plan($linkRow, $existing, []);
check('matching payroll is linked without a second earning', $linked['ok'] && $linked['wage']['action'] === 'link' && $linked['writes'] === []);
$conflict = $linkRow;
$conflict['net'] = 70;
$conflictPlan = psr_approval_plan($conflict, $existing, []);
check('mismatched payroll is not overwritten or added again', !$conflictPlan['ok'] && $conflictPlan['writes'] === []);

$payRow = $linkRow;
$payRow['actual_confirmed'] = 1;
$payRow['actual_amount'] = 30;
$payRow['actual_date'] = '2026-09-18';
$payRow['actual_method'] = 'Cash';
$payRow['actual_reference'] = 'R-1';
$payRow['proposed_amount'] = 99;
$payPlan = psr_approval_plan($payRow, $existing, []);
check('confirmed actual payment is separate from the proposal', $payPlan['ok'] && $payPlan['payment']['action'] === 'post' && $payPlan['payment']['amount'] === 30.0 && $payPlan['payment']['method'] === 'Cash');
$again = psr_approval_plan($payRow, $existing, ['wage', 'payment']);
check('repeat approval does not post again', $again['ok'] && $again['writes'] === []);

$adpPay = $payRow;
$adpPay['actual_method'] = 'ADP';
$adpPlan = psr_approval_plan($adpPay, $existing, []);
check('ADP is not stored by rewriting it into another method', !$adpPlan['ok']);

$yearRow = $baseRow;
$yearRow['year_confidence'] = 'inferred';
$yearRow['flags'] = ['year_requires_confirmation'];
check('uncertain year blocks approval', !psr_approval_plan($yearRow, [], [])['ok']);
$yearRow['year_confirmed'] = 1;
check('explicit year confirmation allows the safe row', psr_approval_plan($yearRow, [], [])['ok']);

$fundingGross = psr_employer_funding([
    'pay_route' => 'adp', 'amount_basis' => 'gross', 'proposed_amount' => 1200,
    'ee_deduction_state' => 'amount', 'ee_deduction_amount' => 200,
    'er_cpp_state' => 'amount', 'er_cpp_amount' => 50,
    'er_ei_state' => 'amount', 'er_ei_amount' => 20,
    'er_other_state' => 'none', 'party_class' => 'employee', 'gst_verified' => 1,
]);
check('ADP gross funding does not add deductions a second time', $fundingGross['state'] === 'amount' && abs($fundingGross['amount'] - 1270) < 0.001);
$fundingNet = psr_employer_funding([
    'pay_route' => 'adp', 'amount_basis' => 'net', 'proposed_amount' => 1000,
    'ee_deduction_state' => 'amount', 'ee_deduction_amount' => 200,
    'er_cpp_state' => 'amount', 'er_cpp_amount' => 50,
    'er_ei_state' => 'amount', 'er_ei_amount' => 20,
    'er_other_state' => 'none', 'party_class' => 'employee',
]);
check('ADP net funding adds deductions once and then employer CPP/EI', $fundingNet['state'] === 'amount' && abs($fundingNet['amount'] - 1270) < 0.001);
$pending = psr_employer_funding([
    'pay_route' => 'adp', 'amount_basis' => 'gross', 'proposed_amount' => 100,
    'er_cpp_state' => 'unknown', 'er_ei_state' => 'none', 'er_other_state' => 'none',
    'party_class' => 'employee',
]);
check('unknown employer contribution stays pending', $pending['state'] === 'pending' && $pending['amount'] === null);
$noBasis = psr_employer_funding([
    'pay_route' => 'cash', 'amount_basis' => 'unset', 'proposed_amount' => 40,
    'er_cpp_state' => 'none', 'er_ei_state' => 'none', 'er_other_state' => 'none',
    'party_class' => 'employee',
]);
check('missing gross or net basis is not assumed', $noBasis['state'] === 'pending');

$gstWage = psr_gst(['party_class' => 'employee', 'pay_route' => 'cash', 'gst_verified' => 1, 'gst_invoice_principal' => 100]);
check('cash wages do not get 5 percent GST', $gstWage['state'] === 'not_applied' && $gstWage['amount'] === null);
$gstUnknown = psr_gst(['party_class' => 'unanswered', 'pay_route' => 'cash', 'gst_verified' => 1, 'gst_invoice_principal' => 100]);
check('cash alone does not establish contractor GST', $gstUnknown['state'] === 'not_applied');
$gstOk = psr_gst(['party_class' => 'contractor', 'gst_verified' => 1, 'gst_invoice_principal' => 200]);
check('verified contractor invoice is 5 percent of that principal', $gstOk['state'] === 'amount' && abs($gstOk['amount'] - 10) < 0.001);
$gstMissing = psr_gst(['party_class' => 'contractor', 'gst_verified' => 1, 'gst_invoice_principal' => null]);
check('verified GST without a principal stays pending', $gstMissing['state'] === 'pending');

$preview = psr_balance_preview([
    'amount_basis' => 'net', 'proposed_amount' => 40, 'pay_route' => 'cash', 'party_class' => 'employee',
    'er_cpp_state' => 'amount', 'er_cpp_amount' => 50, 'gst_verified' => 0,
], true, 100);
check('proposal previews a lower balance without changing the live one', $preview['live_balance']['amount'] === 100.0 && abs($preview['preview_balance']['amount'] - 60) < 0.001);
check('employer cost and GST do not reduce the wage preview', (int)$preview['employer_cost_in_wage_balance'] === 0 && (int)$preview['gst_in_wage_balance'] === 0);

$jan = ['source' => 'payroll', 'id' => 10, 'org_id' => 1, 'user_id' => 5, 'site_id' => 2, 'employee_name' => 'Pat Example', 'store_name' => 'North', 'period_start' => '2026-01-01', 'period_end' => '2026-01-15', 'gross' => 100.0, 'net' => 100.0, 'net_known' => true, 'paid' => 0.0, 'status' => 'not_paid'];
$sep = ['source' => 'payroll', 'id' => 11, 'org_id' => 1, 'user_id' => 5, 'site_id' => 2, 'employee_name' => 'Pat Example', 'store_name' => 'North', 'period_start' => '2026-09-01', 'period_end' => '2026-09-15', 'gross' => 50.0, 'net' => 40.0, 'net_known' => true, 'paid' => 10.0, 'status' => 'partial'];
$other = ['source' => 'payroll', 'id' => 12, 'org_id' => 1, 'user_id' => 6, 'site_id' => 2, 'employee_name' => 'Sam Example', 'store_name' => 'North', 'period_start' => '2026-01-01', 'period_end' => '2026-01-15', 'gross' => 20.0, 'net' => 20.0, 'net_known' => true, 'paid' => 20.0, 'status' => 'paid'];
$paidUnknown = ['source' => 'payroll', 'id' => 13, 'org_id' => 1, 'user_id' => 5, 'site_id' => 2, 'employee_name' => 'Pat Example', 'store_name' => 'North', 'period_start' => '2026-10-01', 'period_end' => '2026-10-15', 'gross' => 15.0, 'net' => null, 'net_known' => false, 'paid' => null, 'status' => 'paid'];
$foreign = ['source' => 'payroll', 'id' => 99, 'org_id' => 2, 'user_id' => 5, 'site_id' => 2, 'employee_name' => 'Pat Example', 'store_name' => 'North', 'period_start' => '2026-01-01', 'period_end' => '2026-01-15', 'gross' => 999.0, 'net' => 999.0, 'net_known' => true, 'paid' => 0.0, 'status' => 'not_paid'];
$sheetDup = ['source' => 'approved_sheet', 'id' => 3, 'org_id' => 1, 'user_id' => 5, 'site_id' => 2, 'finalized_ids' => [10], 'period_start' => '2026-01-01', 'period_end' => '2026-01-15', 'gross' => 100.0, 'net' => 100.0, 'net_known' => true, 'paid' => 0.0, 'status' => 'not_paid'];
$draft = ['org_id' => 1, 'user_id' => 5, 'gross' => 80, 'net' => null, 'proposed_amount' => 25, 'reported_status' => 'paid'];
$unlinked = ['org_id' => 1, 'user_id' => 0, 'employee_name' => 'Pat Example', 'gross' => 500, 'net' => 500, 'proposed_amount' => 500];
$company = [
    'org_id' => 1,
    'org_name' => 'Example Foods',
    'system_lines' => [$jan, $sep, $other, $paidUnknown, $foreign],
    'approved_sheet' => [$sheetDup],
    'draft_sheet' => [$draft, $unlinked],
];
$report = psr_company_report($company, []);
check('all-period gross includes every period and employee in the company', abs($report['all_period']['gross_known'] - 185) < 0.001);
check('approved sheet line is not added on top of payroll', abs($report['all_period']['gross_known'] - 185) < 0.001 && $report['employees'][0]['dedup_notes']);
check('another company line is excluded', abs($report['all_period']['gross_known'] - 185) < 0.001);
$pat = null;
foreach ($report['employees'] as $emp) {
    if ((int)$emp['user_id'] === 5) {
        $pat = $emp;
    }
}
check('paid without a known net is flagged and is not a zero balance', $pat && $pat['all_period']['paid_without_known_net'] === 1 && abs($pat['all_period']['remaining_known'] - 130) < 0.001);
check('draft proposal is outside the actual total', $pat && abs($pat['draft_preview']['proposed_known'] - 25) < 0.001 && abs($pat['all_period']['paid_known'] - 10) < 0.001);
check('unlinked same name is not folded into the employee', $pat && $pat['draft_preview']['rows'] === 1);
$filtered = psr_company_report($company, ['period_start' => '2026-09-01', 'period_end' => '2026-09-15']);
check('filter does not replace the all-period total', abs($filtered['all_period']['gross_known'] - 185) < 0.001 && abs($filtered['filtered']['gross_known'] - 50) < 0.001 && $filtered['filter_active']);
check('filter label says it is not the all-period total', strpos($filtered['filter_label'], 'not the all-period total') !== false);
$companies = psr_companies_report([
    $company,
    ['org_id' => 2, 'org_name' => 'Other Foods', 'system_lines' => [$foreign], 'approved_sheet' => [], 'draft_sheet' => []],
], []);
check('companies are not netted into one obligation', $companies['cross_company_sum'] === null && count($companies['companies']) === 2 && abs($companies['companies'][1]['all_period']['gross_known'] - 999) < 0.001);
check('other company view requires authority', psr_may_view_org(false, 1, 2) === false && psr_may_view_org(true, 1, 2) === true);
check('uploads inside the web app are refused', psr_directory_is_safe('/home/renocon/public_html/payroll/uploads', '/home/renocon/public_html/payroll') === false);
check('private directory outside the web app is allowed', psr_directory_is_safe('/home/renocon/payroll_private/sheet_review', '/home/renocon/public_html/payroll') === true);

$src = file_get_contents(__DIR__ . '/../engine/payroll_sheet_review_lib.php');
$draftStart = strpos($src, 'function psr_save_draft_row');
$draftEnd = strpos($src, 'function psr_row_to_plan_input');
$draftFn = substr($src, $draftStart, $draftEnd - $draftStart);
check('saving a draft does not write payroll tables', strpos($draftFn, 'payroll_finalized') === false && strpos($draftFn, 'payroll_payments') === false);
$page = file_get_contents(__DIR__ . '/../payroll_sheet_review.php');
check('review page is admin gated and confirms approval', strpos($page, 'ps_require_payroll_admin') !== false && strpos($page, 'csrf_token') !== false && strpos($page, 'confirm_approval') !== false && strpos($page, 'save_draft') !== false);
check('review page shows all-period and filtered totals', strpos($page, 'All-period total') !== false && strpos($page, 'Filtered subset') !== false);
check('review page has no mail send', stripos($page, 'mail(') === false);
check('deferred calculations are listed', strpos($page, 'psr_deferred_items') !== false);

if ($fail > 0) {
    echo "$fail failed\n";
    exit(1);
}
echo "all passed\n";
exit(0);
