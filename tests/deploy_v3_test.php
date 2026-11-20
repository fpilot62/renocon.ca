<?php
/**
 * The v3 bundle keeps the org-scope line and the low-pay Ontario example is not blank.
 * Run: php tests/deploy_v3_test.php
 */
$fail = 0;
function check(string $label, bool $cond): void {
    global $fail;
    if ($cond) { echo "ok  $label\n"; return; }
    echo "FAIL $label\n";
    $fail++;
}
$root = dirname(__DIR__);
$pages = [
    'bulk_finalize_autosplit.php' => "osg_page(['level'=>29,'fence'=>['shift_ids'=>'attendance']])",
    'bulk_finalize_handler.php' => "osg_page(['level'=>10])",
    'finalize_shift.php' => "osg_page(['level'=>20,'fence'=>['id'=>'attendance']])",
    'generate_pay_documents.php' => "osg_page(['level'=>29,'fence'=>['site_id'=>'site','user_id'=>'user']])",
    'payroll_command_center.php' => "osg_page(['level'=>10])",
    'payroll_processor.php' => "osg_page(['level'=>29,'fence'=>['attendance_id'=>'attendance']])",
    'process_audit.php' => "osg_page(['level'=>10])",
    'process_payroll_logic.php' => "osg_page(['level'=>10])",
    'process_payroll.php' => "osg_page(['level'=>29,'fence'=>['selected_ids'=>'attendance']])",
    'save_split_payroll.php' => "osg_page(['level'=>29,'fence'=>['attendance_id'=>'attendance','id'=>'attendance']])",
    'payroll_split_80.php' => 'osg_cli_only()',
];
foreach ($pages as $file => $needle) {
    $src = file_get_contents($root . '/' . $file);
    check($file . ' keeps the org-scope line', strpos($src, 'engine/orgscope_guard.php') !== false && strpos($src, $needle) !== false);
}
check('split utility refuses the flat SQL', strpos(file_get_contents($root . '/payroll_split_80.php'), 'disabled: use the engine') !== false);
require $root . '/payroll_engine/source_deductions_lib.php';
$on = sd_calculate(['pay_date' => '2026-11-15', 'periods' => 26, 'province' => 'ON', 'periodic_gross' => 189.50]);
check('low Ontario pay calculates', !empty($on['ok']) && $on['cpp'] > 0 && $on['ei'] > 0);
check('low Ontario pay has no income tax', abs((float)$on['income_tax']) < 0.001);
printf("ON example cpp=%.2f ei=%.2f tax=%.2f\n", $on['cpp'], $on['ei'], $on['income_tax']);
if ($fail > 0) { echo $fail . " failed\n"; exit(1); }
echo "all passed\n";
