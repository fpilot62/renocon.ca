<?php
/**
 * Payroll pages default to the top-bar org, not the login home org.
 * Run: php tests/topbar_org_default_test.php
 */
$fail = 0;
function check(string $label, bool $cond): void {
    global $fail;
    if ($cond) { echo "ok  $label\n"; return; }
    echo "FAIL $label\n";
    $fail++;
}
require_once __DIR__ . '/../payroll_scope.php';

check('cookie 4 beats home org 2', ps_topbar_org_value(true, 2, null, '4') === 4);
check('an explicit org_id beats the cookie', ps_topbar_org_value(true, 2, '4', '2') === 4);
check('a missing top bar does not invent org 2', ps_topbar_org_value(true, 2, null, null) === 0 && ps_topbar_org_value(true, 2, null, 'all') === 0);
check('an org admin cannot follow another org cookie', ps_topbar_org_value(false, 4, null, '2') === 4);

$scope = file_get_contents(__DIR__ . '/../payroll_scope.php');
$selected = substr($scope, (int)strpos($scope, 'function ps_selected_org'));
check('the shared selector uses the top-bar resolver before the home org', strpos($selected, '$top = ps_topbar_org()') !== false && strpos($selected, '$top = ps_topbar_org()') < strpos($selected, '$session = ps_org_id()'));
$fence = substr($scope, (int)strpos($scope, 'function ps_scope_org_id'));
check('the org fence uses the same resolver', strpos($fence, 'ps_topbar_org()') !== false);

$pay = file_get_contents(__DIR__ . '/../engine/payroll_payment_status_lib.php');
$def = substr($pay, (int)strpos($pay, 'function pstat_default_org_id'), 900);
check('payment status prefers the top bar over PK BC', strpos($def, 'ps_topbar_org()') !== false && strpos($def, 'ps_topbar_org()') < strpos($def, 'PK BC Food INC'));

$ledger = file_get_contents(__DIR__ . '/../payroll_master_ledger.php');
check('the ledger default org comes from the shared selector', strpos($ledger, 'ps_selected_org($pdo)') !== false);
$ledger1 = file_get_contents(__DIR__ . '/../payroll_master_ledger1.php');
check('the other ledger reads the top bar', strpos($ledger1, 'ps_topbar_org()') !== false);
$audit = file_get_contents(__DIR__ . '/../attendance_audit.php');
check('attendance audit reads the top bar when no org_id is passed', strpos($audit, 'ps_topbar_org()') !== false);

if ($fail > 0) { echo $fail . " failed\n"; exit(1); }
echo "all passed\n";
