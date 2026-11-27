<?php
/**
 * Simple Sheet Review summary: one line per employee.
 * Run: php tests/sheet_pay_summary_test.php
 */
require_once __DIR__ . '/../engine/payroll_sheet_review_lib.php';
require_once __DIR__ . '/../engine/payroll_sheet_grid_lib.php';
require_once __DIR__ . '/../engine/sheet_summary_lib.php';

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

function row(array $over): array
{
    return array_merge([
        'key' => 'sheet:1',
        'sheet_row_id' => 1,
        'finalized_ids' => [],
        'user_id' => 10004,
        'site_id' => 2,
        'employee_name' => 'Amandeep',
        'store_name' => 'Vernon',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-15',
        'paid' => null,
        'paid_state' => 'none_confirmed',
        'balance_before' => ['state' => 'amount', 'amount' => 0.0],
        'today_pay' => null,
        'pay_on' => '',
        'basis' => 'net',
        'route' => 'cash',
        'party_class' => 'employee',
        'sheet_gross' => 100.0,
        'sheet_net' => 80.0,
        'actual_amount' => null,
        'actual_date' => '',
        'actual_method' => '',
        'actual_confirmed' => 0,
        'row_version' => 1,
    ], $over);
}

$summary = psr_pay_summary([
    row([
        'key' => 'sheet:1', 'sheet_row_id' => 1,
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15',
        'paid' => 100.0, 'paid_state' => 'amount', 'actual_confirmed' => 1, 'actual_date' => '2026-08-20',
        'balance_before' => ['state' => 'amount', 'amount' => 0.0],
    ]),
    row([
        'key' => 'sheet:2', 'sheet_row_id' => 2,
        'period_start' => '2026-09-01', 'period_end' => '2026-09-15',
        'paid' => 40.0, 'paid_state' => 'amount', 'actual_confirmed' => 1, 'actual_date' => '2026-09-20',
        'balance_before' => ['state' => 'amount', 'amount' => 60.0],
        'today_pay' => 25.0,
    ]),
    row([
        'key' => 'sheet:3', 'sheet_row_id' => 3, 'user_id' => 10004, 'store_name' => 'Kelowna',
        'period_start' => '2026-07-01', 'period_end' => '2026-07-15',
        'paid' => null, 'paid_state' => 'none_confirmed',
        'balance_before' => ['state' => 'amount', 'amount' => 10.0],
    ]),
], []);
$line = $summary['lines'][0];
check('one linked person is one line across stores', count($summary['lines']) === 1 && $line['user_id'] === 10004);
check('the summary line keeps every sheet row id', $line['sheet_ids'] === [1, 2, 3]);
check('a system line with no sheet id is not a sheet source', psr_sheet_source_ids([['sheet_row_id' => 0], ['sheet_row_id' => 9]]) === [9]);
check('stores are listed on that line', $line['stores'] === ['Kelowna', 'Vernon']);
check('total paid sums confirmed amounts and ignores none confirmed', abs($line['total_paid'] - 140) < 0.001 && $line['paid_unknown'] === 0);
check('last paid on is the latest confirmed actual date', $line['last_paid_on'] === '2026-09-20');
check('current pay due is the latest unpaid period, not the older one', $line['current_due_state'] === 'amount' && abs($line['current_due'] - 60) < 0.001 && $line['current_period_end'] === '2026-09-15');
check('total due sums every balance before', $line['total_due'] !== null && abs($line['total_due'] - 70) < 0.001 && $line['due_unknown'] === 0);
check('paid now is the proposal already on that period', abs((float)$line['pay_now'] - 25) < 0.001 && $line['target']['key'] === 'sheet:2');

$older = psr_pay_summary([
    row([
        'key' => 'sheet:9', 'period_end' => '2026-10-15', 'period_start' => '2026-10-01',
        'paid' => 80.0, 'paid_state' => 'amount',
        'balance_before' => ['state' => 'amount', 'amount' => 0.0],
    ]),
    row([
        'key' => 'sheet:8', 'sheet_row_id' => 8, 'period_end' => '2026-09-15', 'period_start' => '2026-09-01',
        'paid' => 0.0, 'paid_state' => 'amount',
        'balance_before' => ['state' => 'amount', 'amount' => 15.0],
    ]),
], []);
check('a settled latest period is skipped for current pay due', abs($older['lines'][0]['current_due'] - 15) < 0.001 && $older['lines'][0]['target']['key'] === 'sheet:8');

$unknown = psr_pay_summary([
    row([
        'paid' => null, 'paid_state' => 'unknown',
        'balance_before' => ['state' => 'incomplete', 'amount' => null],
        'period_end' => '2026-09-15',
    ]),
    row([
        'key' => 'sheet:4', 'sheet_row_id' => 4,
        'paid' => 5.0, 'paid_state' => 'amount', 'finalized_ids' => [44],
        'balance_before' => ['state' => 'amount', 'amount' => 12.0],
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15',
    ]),
], [44 => '2026-08-18']);
$u = $unknown['lines'][0];
check('missing nets are an unknown count, not a zero balance', $u['due_unknown'] === 1 && abs($u['total_due'] - 12) < 0.001);
check('unknown paid is counted and not added as zero proof', $u['paid_unknown'] === 1 && abs($u['total_paid'] - 5) < 0.001);
check('current pay due is unknown when the latest period has no net', $u['current_due_state'] === 'unknown' && $u['current_due'] === null && $u['current_period_end'] === '2026-09-15');
check('last paid on uses the system payment date for that finalized row', $u['last_paid_on'] === '2026-08-18');

$split = psr_pay_summary([
    row(['user_id' => 0, 'employee_name' => 'Pat', 'store_name' => 'A', 'key' => 'sheet:5', 'sheet_row_id' => 5]),
    row(['user_id' => 0, 'employee_name' => 'Pat', 'store_name' => 'B', 'key' => 'sheet:6', 'sheet_row_id' => 6]),
], []);
check('unlinked same names at different stores stay apart', count($split['lines']) === 2);

$settled = psr_pay_summary([
    row(['paid' => 80.0, 'paid_state' => 'amount', 'balance_before' => ['state' => 'amount', 'amount' => 0.0]]),
], []);
check('everyone settled has current pay due of zero', abs($settled['lines'][0]['current_due'] - 0) < 0.001 && $settled['totals']['current_due'] !== null);

$totals = $summary['totals'];
check('the totals row adds the employee lines', abs($totals['total_paid'] - 140) < 0.001 && abs($totals['total_due'] - 70) < 0.001 && abs($totals['current_due'] - 60) < 0.001 && abs($totals['pay_now'] - 25) < 0.001);

$target = row([
    'key' => 'sheet:2', 'sheet_row_id' => 2, 'row_version' => 4,
    'today_pay' => null, 'actual_confirmed' => 0, 'actual_amount' => null,
]);
$same = psr_grid_save_changes(['sheet:2' => psr_summary_post_fields($target, '')], ['sheet:2' => $target]);
check('an empty paid-now box does not write', $same['ok'] && $same['changes'] === [] && $same['writes_payroll'] === false);
$changed = psr_grid_save_changes(['sheet:2' => psr_summary_post_fields($target, '30')], ['sheet:2' => $target]);
check('paid now becomes the existing proposal amount', $changed['ok'] && count($changed['changes']) === 1 && abs($changed['changes'][0]['today_pay'] - 30) < 0.001);
check('paid now does not confirm an actual payment', (int)$changed['changes'][0]['actual_confirmed'] === 0 && $changed['writes_payroll'] === false);

$gap = psr_clock_sheet_gap([
    ['user_id' => 1, 'site_id' => 2, 'employee_name' => 'Pat', 'store_name' => 'North', 'period_start' => '2026-07-01', 'period_end' => '2026-07-15', 'net_known' => true, 'net' => 100],
    ['user_id' => 1, 'site_id' => 2, 'employee_name' => 'Pat', 'store_name' => 'North', 'period_start' => '2026-07-16', 'period_end' => '2026-07-31', 'net_known' => false, 'net' => null],
], [
    ['source_sheet' => 'By Abdul', 'row_kind' => 'employee_record', 'user_id' => 1, 'site_id' => 2, 'employee_name' => 'Pat', 'store_name' => 'North', 'period_start' => '2026-07-01', 'period_end' => '2026-07-15', 'net' => 80],
    ['source_sheet' => 'By Abdul', 'row_kind' => 'employee_record', 'user_id' => 1, 'site_id' => 2, 'employee_name' => 'Pat', 'store_name' => 'North', 'period_start' => '2026-07-16', 'period_end' => '2026-07-31', 'net' => null],
    ['source_sheet' => 'Other', 'row_kind' => 'employee_record', 'user_id' => 1, 'site_id' => 2, 'employee_name' => 'Pat', 'store_name' => 'North', 'period_start' => '2026-07-01', 'period_end' => '2026-07-15', 'net' => 999],
], []);
check('clock-in net sums known system lines and counts unknown', $gap['clock']['amount'] === 100.0 && $gap['clock']['unknown'] === 1);
check('sheet total is only By Abdul nets and counts a blank net', $gap['sheet']['amount'] === 80.0 && $gap['sheet']['unknown'] === 1);
check('a difference is incomplete while either side has an unknown net', $gap['difference'] === null && strpos(psr_gap_sentence($gap), 'Clock-in payroll total 100.00 (1 unknown) − Sheet total 80.00 (1 unknown) = Difference incomplete') === 0);
$known = psr_clock_sheet_gap([
    ['employee_name' => 'Pat', 'store_name' => 'North', 'period_start' => '2026-07-01', 'period_end' => '2026-07-15', 'net_known' => true, 'net' => 100, 'user_id' => 1, 'site_id' => 2],
    ['employee_name' => 'Sam', 'store_name' => 'South', 'period_start' => '2025-01-01', 'period_end' => '2025-01-15', 'net_known' => true, 'net' => 50, 'user_id' => 2, 'site_id' => 3, 'active' => 0],
], [
    ['source_sheet' => 'by abdul', 'employee_name' => 'Pat', 'store_name' => 'North', 'period_start' => '2026-07-01', 'period_end' => '2026-07-15', 'net' => 70, 'user_id' => 1, 'site_id' => 2],
], ['person' => 'Pat', 'year' => '2026', 'active' => 'active']);
check('filters apply to both sides and a full pair subtracts', abs($known['difference'] - 30) < 0.001 && $known['clock']['unknown'] === 0 && $known['sheet']['amount'] === 70.0);

$lib = (string)file_get_contents(__DIR__ . '/../engine/sheet_summary_lib.php');
$page = (string)file_get_contents(__DIR__ . '/../payroll_sheet_review.php');
check('the summary library does not write payroll', stripos($lib, 'INSERT') === false && stripos($lib, 'UPDATE') === false && stripos($lib, 'DELETE') === false);
check('the summary page is the default and can open the detailed view', strpos($page, "!== 'detail'") !== false && strpos($page, 'Detailed view') !== false && strpos($page, 'Paid now') !== false && strpos($page, 'Current pay due') !== false);
check('paid now posts through the existing proposal save', strpos($page, 'summary_pay') !== false && strpos($page, 'preview_grid_save') !== false);
check('the pay summary starts collapsed and the comparison line stays outside it', strpos($page, 'data-panel="pay-summary"') !== false && strpos($page, 'psr_gap_sentence') !== false && strpos($page, 'Clock-in payroll total') === false);

if ($fail > 0) {
    echo $fail . " failed\n";
    exit(1);
}
echo "all passed\n";
