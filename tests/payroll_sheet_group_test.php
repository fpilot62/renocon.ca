<?php
/**
 * Employee grouping and the Approvals panel. Synthetic rows only.
 * Run: php tests/payroll_sheet_group_test.php
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

function row(array $extra): array
{
    $net = $extra['net'] ?? null;
    $paid = $extra['paid'] ?? null;
    $before = $extra['before'] ?? null;
    $after = $extra['after'] ?? $before;
    $proposed = array_key_exists('proposed', $extra) ? $extra['proposed'] : null;
    return [
        'key' => $extra['key'],
        'sheet_row_id' => $extra['sheet_row_id'] ?? 0,
        'user_id' => $extra['user_id'] ?? 0,
        'site_id' => $extra['site_id'] ?? 0,
        'employee_name' => $extra['employee_name'],
        'store_name' => $extra['store_name'] ?? 'North',
        'period_start' => $extra['period_start'] ?? '2026-01-01',
        'gross' => $extra['gross'] ?? null,
        'net' => $net,
        'net_known' => $net !== null,
        'paid' => $paid,
        'paid_state' => $paid === null ? 'unknown' : 'amount',
        'balance_before' => $before === null ? ['state' => 'incomplete', 'amount' => null] : ['state' => 'amount', 'amount' => $before],
        'balance_after' => $after === null ? ['state' => 'incomplete', 'amount' => null] : ['state' => 'amount', 'amount' => $after],
        'today_pay' => $proposed,
    ];
}

$linkedOld = row([
    'key' => 'sheet:1', 'sheet_row_id' => 1, 'user_id' => 5, 'site_id' => 2,
    'employee_name' => 'Pat Example', 'period_start' => '2024-08-01',
    'gross' => 40, 'net' => null, 'paid' => null, 'before' => null, 'proposed' => null,
]);
$linkedNew = row([
    'key' => 'payroll:9', 'sheet_row_id' => 0, 'user_id' => 5, 'site_id' => 2,
    'employee_name' => 'Pat Example', 'period_start' => '2026-03-01',
    'gross' => 100, 'net' => 80, 'paid' => 20, 'before' => 60, 'after' => 60, 'proposed' => 10,
]);
$otherStore = row([
    'key' => 'payroll:10', 'sheet_row_id' => 0, 'user_id' => 5, 'site_id' => 4,
    'employee_name' => 'Pat Example', 'store_name' => 'South', 'period_start' => '2026-04-01',
    'gross' => 15, 'net' => 15, 'paid' => 0, 'before' => 15, 'proposed' => null,
]);
$unlinkedA = row([
    'key' => 'sheet:2', 'sheet_row_id' => 2, 'user_id' => 0, 'site_id' => 0,
    'employee_name' => 'Pat Example', 'period_start' => '2024-09-01',
    'gross' => null, 'net' => null, 'before' => null, 'proposed' => 4,
]);
$unlinkedB = row([
    'key' => 'sheet:3', 'sheet_row_id' => 3, 'user_id' => 0, 'site_id' => 0,
    'employee_name' => 'pat   example', 'period_start' => '2024-10-01',
    'gross' => 8, 'net' => null, 'before' => null, 'proposed' => null,
]);
$otherName = row([
    'key' => 'sheet:4', 'sheet_row_id' => 4, 'user_id' => 0, 'site_id' => 0,
    'employee_name' => 'Other Person', 'gross' => 3, 'net' => 3, 'paid' => 3, 'before' => 0, 'proposed' => 0,
]);

$groups = psr_group_employees([$unlinkedB, $linkedNew, $otherName, $unlinkedA, $linkedOld, $otherStore]);
$by = [];
foreach ($groups as $group) {
    $by[$group['key']] = $group;
}
$linked = $by[psr_employee_group_key($linkedOld)];
check('linked sheet and system rows for the same person and store are one group', count($linked['rows']) === 2 && $linked['linked'] === true && $linked['not_linked'] === false);
check('an unlinked same name is not merged into the linked person', isset($by[psr_employee_group_key($unlinkedA)]) && $by[psr_employee_group_key($unlinkedA)]['not_linked'] === true && count($by[psr_employee_group_key($unlinkedA)]['rows']) === 2);
check('a different unlinked name stays separate', count($by[psr_employee_group_key($otherName)]['rows']) === 1);
check('the same linked person at another store is a separate group', $by[psr_employee_group_key($otherStore)]['key'] !== $linked['key']);
check('selecting the employee selects every draft row in that group', $by[psr_employee_group_key($unlinkedA)]['sheet_ids'] === [2, 3] && $linked['sheet_ids'] === [1]);
check('a blank net is not treated as zero', $linked['totals']['net']['amount'] === 80.0 && $linked['totals']['net']['unknown'] === 1);
check('a blank gross is not treated as zero', $by[psr_employee_group_key($unlinkedA)]['totals']['gross']['amount'] === 8.0 && $by[psr_employee_group_key($unlinkedA)]['totals']['gross']['unknown'] === 1);
check('a blank proposal is left unknown and a typed zero stays zero', abs((float)$otherName['today_pay']) < 0.001 && abs($by[psr_employee_group_key($otherName)]['totals']['proposed']['amount']) < 0.001 && $linked['totals']['proposed']['unknown'] === 1 && abs($linked['totals']['proposed']['amount'] - 10) < 0.001);

$grand = psr_employee_group_totals([$linkedOld, $linkedNew, $otherStore, $unlinkedA, $unlinkedB, $otherName]);
check('the filtered grand total keeps unknown nets out of the sum', abs($grand['net']['amount'] - 98) < 0.001 && $grand['net']['unknown'] === 3 && $grand['rows'] === 6);
$page = psr_grid_page($groups, 1, 10);
check('paging groups does not drop a period from its employee', count($page['rows']) === count($groups));
$firstOnly = psr_grid_page($groups, 1, 10);
$sliced = array_slice($groups, 0, 1);
$partial = psr_employee_group_totals($sliced[0]['rows']);
check('a page total is not the filtered total', $partial['rows'] < $grand['rows']);

$panel = psr_approvals_panel(3, 25, 40);
check('the approvals button counts the selection', $panel['button'] === 'Approvals (3 selected)' && count($panel['sections']) === 3);
check('save does not need a selection and neither approval marks anyone paid', $panel['sections'][0]['needs_selection'] === false && $panel['sections'][0]['marks_paid'] === false && $panel['sections'][1]['marks_paid'] === false && $panel['sections'][1]['sends_money'] === false && $panel['sections'][2]['sends_money'] === false && $panel['sections'][2]['rows'] === 3);
$ids = psr_resolve_selection(['select_all_filtered' => '1', 'row_ids' => [1, 2]], [1, 2], [1, 2, 3, 4]);
check('select all filtered keeps rows on other pages', $ids === [1, 2, 3, 4]);
$dropped = psr_resolve_selection(['select_all_filtered' => '1', 'row_ids' => [1]], [1, 2], [1, 2, 3]);
check('unchecking a visible row removes only that row', $dropped === [1, 3]);
$foreign = psr_resolve_selection(['row_ids' => [9, 1]], [1], [1, 2]);
check('a posted id outside the filter is ignored', $foreign === [1]);
$summary = psr_pay_run_summary(['total_wage' => 50, 'available_funds' => 80, 'remaining_funds' => 30, 'shortfall' => 0, 'plan_date' => '2026-11-20', 'total_reserve' => 0, 'total_funding' => 50], 2);
check('the pay run summary does not send money', $summary['rows'] === 2 && abs($summary['proposed'] - 50) < 0.001 && abs($summary['remaining'] - 30) < 0.001 && $summary['marks_paid'] === false && $summary['sends_money'] === false);

$html = file_get_contents(__DIR__ . '/../payroll_sheet_review.php');
check('the page groups by employee and opens one approvals panel', strpos($html, 'Group by employee') !== false && strpos($html, 'not linked') !== false && strpos($html, 'Filtered total') !== false && strpos($html, 'id="openApprovals"') !== false && strpos($html, 'Confirm amounts owed') !== false && strpos($html, 'Confirm this pay run') !== false && strpos($html, 'Remove draft') !== false);

if ($fail > 0) {
    echo "$fail failed\n";
    exit(1);
}
echo "all passed\n";
exit(0);
