<?php
/**
 * Sheet Review hides admin vernon and other admin/test names. No database writes.
 * Run: php tests/sheet_hide_admin_test.php
 */
require_once __DIR__ . '/../engine/sheet_hide_lib.php';

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

check('the default hidden id is admin vernon', sheet_hidden_user_ids() === [10006]);
check('user 10006 is hidden even with another name', sheet_person_is_hidden(10006, 'Vernon'));
check('a name starting with admin is hidden', sheet_person_is_hidden(50, '  Admin test'));
check('administrator is hidden because it starts with admin', sheet_person_is_hidden(51, 'Administrator'));
check('a normal employee is not hidden', !sheet_person_is_hidden(10004, 'Amandeep'));
check('an empty name with a normal id is not hidden', !sheet_person_is_hidden(8, ''));
check('a sheet row linked to 10006 is hidden', sheet_row_is_hidden(['user_id' => 10006, 'employee_name' => 'Vernon']));
check('an unlinked admin row is hidden', sheet_row_is_hidden(['user_id' => 0, 'employee_name' => 'admin vernon']));
check('a linked display name starting with admin is hidden', sheet_row_is_hidden(['user_id' => 12, 'linked_name' => 'Admin']));
$kept = sheet_filter_hidden_rows([
    ['user_id' => 10006, 'employee_name' => 'admin vernon'],
    ['user_id' => 10004, 'employee_name' => 'Amandeep'],
], false);
check('filtering drops the hidden user and keeps the employee', count($kept) === 1 && (int)$kept[0]['user_id'] === 10004);
$shown = sheet_filter_hidden_rows([
    ['user_id' => 10006, 'employee_name' => 'admin vernon'],
], true);
check('the admin toggle shows the hidden row', count($shown) === 1);
$review = (string)file_get_contents(__DIR__ . '/../payroll_sheet_review.php');
$sheet = (string)file_get_contents(__DIR__ . '/../payroll_sheet_sheet.php');
check('both sheet pages load the hide helper', strpos($review, 'sheet_hide_lib.php') !== false && strpos($sheet, 'sheet_hide_lib.php') !== false);
check('both pages offer the admin toggle', strpos($review, 'Show hidden admin/test users') !== false && strpos($sheet, 'Show hidden admin/test users') !== false);
check('neither page deletes the hidden user', strpos($review, 'DELETE FROM users') === false && strpos($sheet, 'DELETE FROM users') === false);

if ($fail > 0) {
    echo $fail . " failed\n";
    exit(1);
}
echo "all passed\n";
