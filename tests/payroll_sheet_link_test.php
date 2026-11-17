<?php
/**
 * Suggested employee and store links. Synthetic people only.
 * Run: php tests/payroll_sheet_link_test.php
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

$sites = [
    ['id' => 6, 'name' => 'Penticton'],
    ['id' => 11, 'name' => 'Westbank'],
    ['id' => 19, 'name' => 'Vernon'],
    ['id' => 20, 'name' => 'Merritt'],
    ['id' => 21, 'name' => 'Salmon Arm'],
    ['id' => 23, 'name' => 'Harvey'],
];
$employees = [
    ['id' => 5, 'name' => 'Pat Example', 'site_id' => 6, 'site_name' => 'Penticton', 'org_id' => 2],
    ['id' => 8, 'name' => 'Pat Example', 'site_id' => 19, 'site_name' => 'Vernon', 'org_id' => 2],
    ['id' => 9, 'name' => 'Sam Rivers', 'site_id' => 11, 'site_name' => 'Westbank', 'org_id' => 2],
    ['id' => 12, 'name' => 'Sam Other', 'site_id' => 11, 'site_name' => 'Westbank', 'org_id' => 2],
    ['id' => 15, 'name' => 'Riley North', 'site_id' => 20, 'site_name' => 'Merritt', 'org_id' => 2],
    ['id' => 16, 'name' => 'Rylee North', 'site_id' => 21, 'site_name' => 'Salmon Arm', 'org_id' => 2],
    ['id' => 40, 'name' => 'Other Company', 'site_id' => 6, 'site_name' => 'Penticton', 'org_id' => 9],
];

$penticton = psr_employees_for_store($employees, 6);
check('a chosen store lists only that store', count($penticton) === 2 && (int)$penticton[0]['site_id'] === 6);
check('all stores label the employee with the store', psr_employee_label($employees[0], true) === 'Pat Example - Penticton' && psr_employee_label($employees[0], false) === 'Pat Example');

$row = ['key' => 'sheet:1', 'sheet_row_id' => 1, 'org_id' => 2, 'user_id' => 0, 'site_id' => 0, 'employee_name' => 'Pat Example', 'store_name' => 'Penticton', 'row_version' => 3];
$exact = psr_suggest_link($row, $employees, $sites, 0);
check('exact name in the named store is suggested and not saved', $exact['status'] === 'suggested' && $exact['user_id'] === 5 && $exact['site_id'] === 6 && $exact['saved'] === false && $exact['approved'] === false && $exact['marks_paid'] === false);
$ambiguous = psr_suggest_link(['employee_name' => 'Pat Example', 'store_name' => '', 'user_id' => 0, 'site_id' => 0, 'org_id' => 2], $employees, $sites, 0);
check('the same name at two stores stays blank', $ambiguous['status'] === 'ambiguous' && $ambiguous['user_id'] === 0 && count($ambiguous['candidates']) === 2);
$filtered = psr_suggest_link(['employee_name' => 'Pat Example', 'store_name' => '', 'user_id' => 0, 'site_id' => 0, 'org_id' => 2], $employees, $sites, 19);
check('the store filter picks the employee at that store', $filtered['status'] === 'suggested' && $filtered['user_id'] === 8 && $filtered['site_id'] === 19);
$first = psr_suggest_link(['employee_name' => 'Riley', 'store_name' => 'Merritt', 'user_id' => 0, 'site_id' => 0, 'org_id' => 2], $employees, $sites, 0);
check('a unique first name inside the store is suggested', $first['status'] === 'suggested' && $first['user_id'] === 15 && $first['site_id'] === 20);
$twoSams = psr_suggest_link(['employee_name' => 'Sam', 'store_name' => 'Westbank', 'user_id' => 0, 'site_id' => 0, 'org_id' => 2], $employees, $sites, 0);
check('two first names in the store stay blank with both candidates', $twoSams['status'] === 'ambiguous' && $twoSams['user_id'] === 0 && count($twoSams['candidates']) === 2);
$fuzzy = psr_suggest_link(['employee_name' => 'Riley Noth', 'store_name' => 'Merritt', 'user_id' => 0, 'site_id' => 0, 'org_id' => 2], $employees, $sites, 0);
check('a close spelling inside the store is suggested', $fuzzy['status'] === 'suggested' && $fuzzy['user_id'] === 15);
$noStore = psr_suggest_link(['employee_name' => 'Riley Noth', 'store_name' => '', 'user_id' => 0, 'site_id' => 0, 'org_id' => 2], $employees, $sites, 0);
check('a close spelling is not used when no store is selected', $noStore['status'] === 'none' && $noStore['user_id'] === 0);
$saved = psr_suggest_link(['employee_name' => 'Pat Example', 'store_name' => 'Penticton', 'user_id' => 9, 'site_id' => 11, 'org_id' => 2], $employees, $sites, 6);
check('an existing link is not replaced', $saved['status'] === 'saved' && $saved['user_id'] === 9 && $saved['site_id'] === 11);
$foreign = psr_suggest_link(['employee_name' => 'Other Company', 'store_name' => 'Penticton', 'user_id' => 0, 'site_id' => 0, 'org_id' => 2], $employees, $sites, 0);
check('another company employee is not suggested', $foreign['status'] === 'none');
$defaultStore = psr_suggest_link(['employee_name' => '', 'store_name' => '', 'user_id' => 0, 'site_id' => 0, 'org_id' => 2], $employees, $sites, 23);
check('a store filter defaults the store and does not invent an employee', $defaultStore['status'] === 'suggested' && $defaultStore['site_id'] === 23 && $defaultStore['user_id'] === 0 && $defaultStore['saved'] === false);

$sheet = [
    $row,
    ['key' => 'sheet:2', 'sheet_row_id' => 2, 'org_id' => 2, 'user_id' => 0, 'site_id' => 0, 'employee_name' => 'Pat Example', 'store_name' => '', 'row_version' => 1, 'sheet_gross' => null, 'sheet_net' => null, 'today_pay' => null],
    ['key' => 'sheet:3', 'sheet_row_id' => 3, 'org_id' => 2, 'user_id' => 9, 'site_id' => 11, 'employee_name' => 'Sam Rivers', 'store_name' => 'Westbank', 'row_version' => 2],
    ['key' => 'payroll:1', 'sheet_row_id' => 0, 'org_id' => 2, 'user_id' => 0, 'site_id' => 0, 'employee_name' => 'Riley North', 'store_name' => 'Merritt', 'row_version' => 1],
];
$accept = psr_accept_link_changes($sheet, $employees, $sites, 0);
check('accept all keeps only unique unsaved sheet links', count($accept['changes']) === 1 && $accept['changes'][0]['sheet_row_id'] === 1 && $accept['changes'][0]['user_id'] === 5 && $accept['writes_payroll'] === false && $accept['marks_paid'] === false && $accept['approved'] === false);
check('the same suggestion hash is stable', psr_link_change_hash($accept['changes']) === psr_link_change_hash($accept['changes']));

$filteredRows = psr_grid_filter([
    ['store_name' => 'Penticton', 'site_id' => 0, 'employee_name' => 'A', 'period_start' => '2026-01-01', 'source' => 'sheet', 'source_status' => '', 'user_id' => 0, 'net_known' => true, 'route' => 'unset', 'active' => 1, 'source_conflict' => false, 'reconciled' => ['derived' => ''], 'freshness' => []],
    ['store_name' => 'Vernon', 'site_id' => 19, 'employee_name' => 'B', 'period_start' => '2026-01-01', 'source' => 'sheet', 'source_status' => '', 'user_id' => 0, 'net_known' => true, 'route' => 'unset', 'active' => 1, 'source_conflict' => false, 'reconciled' => ['derived' => ''], 'freshness' => []],
    ['store_name' => '', 'site_id' => 0, 'employee_name' => 'C', 'period_start' => '2026-01-01', 'source' => 'sheet', 'source_status' => '', 'user_id' => 0, 'net_known' => true, 'route' => 'unset', 'active' => 1, 'source_conflict' => false, 'reconciled' => ['derived' => ''], 'freshness' => []],
], ['site_id' => 6, 'site_name' => 'Penticton']);
check('the store filter keeps that store and rows with no store', count($filteredRows) === 2);

$html = file_get_contents(__DIR__ . '/../payroll_sheet_review.php');
check('the page can accept suggested links and lists every store', strpos($html, 'Accept all suggested links') !== false && strpos($html, 'All stores') !== false && strpos($html, 'confirm_accept_links') !== false && strpos($html, 'link-user') !== false && strpos($html, '(suggested)') !== false);
$src = file_get_contents(__DIR__ . '/../engine/payroll_sheet_grid_lib.php');
$acceptFn = substr($src, strpos($src, 'function psr_accept_link_changes'), strpos($src, 'function psr_link_change_hash') - strpos($src, 'function psr_accept_link_changes'));
check('accepting a link does not post payroll', strpos($acceptFn, 'payroll_payments') === false && strpos($acceptFn, 'payroll_finalized') === false);

if ($fail > 0) {
    echo "$fail failed\n";
    exit(1);
}
echo "all passed\n";
exit(0);
