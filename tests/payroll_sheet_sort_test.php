<?php
/**
 * Server-side grid sorting. Synthetic rows only.
 * Run: php tests/payroll_sheet_sort_test.php
 */
require_once __DIR__ . '/../engine/payroll_sheet_grid_lib.php';

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

function moneyRow(string $key, $net, string $name = 'Pat', string $period = '2026-01-01', $gross = 1): array
{
    return [
        'key' => $key,
        'employee_name' => $name,
        'store_name' => 'North',
        'period_start' => $period,
        'period_end' => $period,
        'gross' => $gross,
        'net' => $net,
        'net_known' => $net !== null,
        'paid' => $net,
        'paid_state' => $net === null ? 'unknown' : 'amount',
        'balance_before' => $net === null ? ['state' => 'incomplete', 'amount' => null] : ['state' => 'amount', 'amount' => $net],
        'balance_after' => $net === null ? ['state' => 'incomplete', 'amount' => null] : ['state' => 'amount', 'amount' => $net],
        'today_pay' => $net,
        'source_status' => $net === null ? '' : 'not_paid',
        'reconciled' => ['derived' => $net === null ? '' : 'no_system_payment'],
        'route' => 'cash',
        'basis' => 'net',
        'gst' => $net === null ? ['state' => 'not_applied', 'amount' => null] : ['state' => 'amount', 'amount' => $net],
        'provenance' => 'Sheet',
        'user_id' => 5,
        'site_id' => 2,
        'sheet_row_id' => 1,
    ];
}

$rows = [
    moneyRow('b', 10, 'Bea', '2026-03-01', 10),
    moneyRow('z', null, 'Zed', '2026-02-01', null),
    moneyRow('a', 0, 'Amy', '2026-01-01', 0),
];
$asc = psr_sort_rows($rows, 'net', 'asc');
$desc = psr_sort_rows($rows, 'net', 'desc');
check('blank net sorts after zero when ascending', $asc[0]['key'] === 'a' && $asc[1]['key'] === 'b' && $asc[2]['key'] === 'z');
check('blank net sorts after the larger amount when descending', $desc[0]['key'] === 'b' && $desc[1]['key'] === 'a' && $desc[2]['key'] === 'z');
check('an unknown sort column is ignored', psr_sort_column('payroll_payments') === '' && psr_sort_rows($rows, 'nope', 'asc')[0]['key'] === 'b');

$many = [];
for ($i = 1; $i <= 12; $i++) {
    $many[] = moneyRow('n' . $i, $i, 'Pat', '2026-01-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT), $i);
}
$many[] = moneyRow('blank', null, 'Pat', '2026-01-13', null);
$sorted = psr_sort_rows($many, 'gross', 'desc');
$page1 = psr_grid_page($sorted, 1, 10);
$page2 = psr_grid_page($sorted, 2, 10);
check('descending sort is applied before paging', $page1['rows'][0]['key'] === 'n12' && $page1['rows'][9]['key'] === 'n3' && $page2['rows'][0]['key'] === 'n2' && $page2['rows'][2]['key'] === 'blank');

$earlySmall = moneyRow('jan', 1, 'Ada North', '2026-01-01', 1);
$earlySmall['user_id'] = 1;
$laterBig = moneyRow('mar', 100, 'Ada North', '2026-03-01', 100);
$laterBig['user_id'] = 1;
$other = moneyRow('bob', 20, 'Bob South', '2026-02-01', 20);
$other['user_id'] = 2;
$other['store_name'] = 'South';
$unknown = moneyRow('cy', null, 'Cy', '2026-04-01', null);
$unknown['user_id'] = 3;
$groups = psr_sort_groups(psr_group_employees([$laterBig, $unknown, $other, $earlySmall]), 'gross', 'desc');
check('groups sort by the employee total and unknown totals stay last', $groups[0]['employee_name'] === 'Ada North' && abs($groups[0]['totals']['gross']['amount'] - 101) < 0.001 && $groups[1]['employee_name'] === 'Bob South' && $groups[2]['totals']['gross']['amount'] === null);
check('period rows inside a group stay in period order', $groups[0]['rows'][0]['key'] === 'jan' && $groups[0]['rows'][1]['key'] === 'mar');
$byPeriod = psr_sort_groups(psr_group_employees([$laterBig, $earlySmall]), 'period', 'desc');
check('sorting by period reverses the periods inside the group', $byPeriod[0]['rows'][0]['key'] === 'mar' && $byPeriod[0]['rows'][1]['key'] === 'jan');

$html = file_get_contents(__DIR__ . '/../payroll_sheet_review.php');
check('headers link the sort into the page url', strpos($html, "psr_sort_heading(\$sortQuery, 'person'") !== false && strpos($html, "psr_sort_heading(\$sortQuery, 'gst'") !== false && strpos($html, 'name="sort"') !== false && strpos($html, '▲') !== false);

if ($fail > 0) {
    echo "$fail failed\n";
    exit(1);
}
echo "all passed\n";
exit(0);
