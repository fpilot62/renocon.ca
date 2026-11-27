<?php
/**
 * Holiday premium uses only the minutes on the holiday date, in the site's timezone.
 * Run: php tests/stat_worked_split_test.php
 */
require_once __DIR__ . '/../payroll_engine/stat_holidays.php';

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

$akash = [[
    'clock_in' => '2026-10-12 18:00:00',
    'clock_out' => '2026-10-13 03:00:00',
    'breaks' => [['2026-10-12 21:09:00', '2026-10-12 21:39:00']],
]];
$parts = sh_holiday_hours_for_shifts($akash, '2026-10-12', 'America/Toronto');
check('Akashdeep Thanksgiving shift is 5.50 hours on the holiday', abs($parts['hours'] - 5.50) < 0.001);
check('the old query counted the whole 8.50 hour shift', abs($parts['legacy_hours'] - 8.50) < 0.001);
check('Bramalea wall time is not read as UTC', sh_site_timezone_name('America/Toronto', 'ON') === 'America/Toronto');

$premium = sh_premium_pay(5.50, 17.96);
check('premium is 5.50 hours at 1.5 times the rate', abs($premium - 148.17) < 0.001);
$label = sh_worked_pay_label(141.49, 5.50, $premium);
check('the row states holiday pay and the 5.50 hours at 1.5', strpos($label, 'Holiday pay $141.49') !== false
    && strpos($label, 'Worked 5.50h × 1.5 = $148.17') !== false
    && strpos($label, 'total $289.66') !== false
    && strpos($label, 'leftover hours at the regular rate') !== false);
$split = sh_shift_pay_split(7.50, 5.50, 17.95);
check('Akashdeep 7.50h shift is 5.50 holiday at 1.5 plus 2.00 regular', abs($split['holiday_hours'] - 5.50) < 0.001
    && abs($split['regular_hours'] - 2.00) < 0.001
    && abs($split['holiday_pay'] - 148.09) < 0.001
    && abs($split['regular_pay'] - 35.90) < 0.001
    && abs($split['amount'] - 183.99) < 0.001);
check('the breakdown is the sentence on the shift and the stub', $split['text'] === 'Total 7.50h = Holiday 5.50h × 1.5 @ $17.95 = $148.09 + Regular 2.00h @ $17.95 = $35.90');
$regular = [[
    'id' => 1, 'work_date' => '2026-10-12', 'reg_hours' => 7.50, 'hourly_rate' => 17.95,
    'reg_pay' => 134.63, 'total_gross' => 134.63, 'vac' => 6.00, 'comments' => '', 'paid' => 0,
]];
$held = sh_plan_regular_hold($regular, 5.50, '2026-10-12', 17.95);
check('an approved stat line leaves 2.00 regular hours and the 1.5x amount on its own line', abs($held[0]['reg_hours'] - 2.00) < 0.001 && abs($held[0]['total_gross'] - 35.90) < 0.001 && sh_split_sentence((string)$held[0]['comments']) === $split['text']);
$again = sh_plan_regular_hold($held, 5.50, '2026-10-12', 17.95);
check('the hour reduction is not applied twice', abs($again[0]['reg_hours'] - 2.00) < 0.001 && abs($again[0]['total_gross'] - 35.90) < 0.001);
$back = sh_plan_regular_restore($held, '2026-10-12');
check('pending or ignored puts the 7.50 regular hours back', abs($back[0]['reg_hours'] - 7.50) < 0.001 && abs($back[0]['total_gross'] - 134.63) < 0.001 && abs($back[0]['reg_pay'] - 134.63) < 0.001 && abs((float)$back[0]['vac'] - 6.00) < 0.001 && sh_split_sentence((string)$back[0]['comments']) === '');
check('an ignored stat line is not live', sh_stat_line_is_live('ignored') === false && sh_stat_line_is_live('pending') === false && sh_stat_line_is_live('approved') === true);
check('public holiday hours are labelled as an average, not time worked', sh_public_holiday_label(141.49, 17.95, 7.88) === 'Public holiday pay (average, equiv. 7.88h)');

$after = [[
    'clock_in' => '2026-10-12 18:00:00',
    'clock_out' => '2026-10-13 03:00:00',
    'breaks' => [['2026-10-13 01:00:00', '2026-10-13 01:30:00']],
]];
$afterParts = sh_holiday_hours_for_shifts($after, '2026-10-12', 'America/Toronto');
check('a break after local midnight is not taken off the holiday', abs($afterParts['hours'] - 6.00) < 0.001);

$prior = [[
    'clock_in' => '2026-10-11 22:00:00',
    'clock_out' => '2026-10-12 04:00:00',
    'breaks' => [['2026-10-12 00:10:00', '2026-10-12 00:25:00']],
]];
$priorParts = sh_holiday_hours_for_shifts($prior, '2026-10-12', 'America/Toronto');
check('a shift that starts the day before counts only the holiday morning', abs($priorParts['hours'] - 3.75) < 0.001 && abs($priorParts['legacy_hours'] - 0) < 0.001);

$flag = sh_worked_change_flag(8.50, 201.94, 5.50, 148.17, true);
check('an approved row is flagged and not described as changed pay', strpos($flag, 'Approved pay was not changed') !== false && strpos($flag, '5.50h') !== false);
check('an unapproved row is not flagged', sh_worked_change_flag(8.50, 201.94, 5.50, 148.17, false) === '');

$page = (string)file_get_contents(__DIR__ . '/../generate_stat_shifts.php');
check('the holiday page uses the split hours and the combined label', strpos($page, 'sh_holiday_worked') !== false && strpos($page, 'sh_worked_pay_label') !== false && strpos($page, 'sh_worked_change_flag') !== false);
$docs = (string)file_get_contents(__DIR__ . '/../generate_pay_documents.php');
$ledger = (string)file_get_contents(__DIR__ . '/../payroll_master_ledger.php');
check('the pay stub names public holiday pay and the shift breakdown', strpos($docs, 'Public holiday pay') !== false && strpos($docs, 'split_lines') !== false && strpos($docs, 'paydocs_long_earn') !== false);
check('ignored and pending lines stay off the stub', strpos($docs, "!== 'ignored'") !== false);
check('payroll lines have Pending and Ignore and the breakdown', strpos($ledger, 'value="pending"') !== false && strpos($ledger, 'value="ignored"') !== false && strpos($ledger, 'sh_split_sentence') !== false && strpos($ledger, 'sh_public_holiday_label') !== false);
$lib = (string)file_get_contents(__DIR__ . '/../payroll_engine/stat_holidays.php');
check('calculated stat lines enter payroll without an Approve click', strpos($ledger, 'sh_release_pending_stat') !== false && strpos($lib, 'function sh_bring_stat_into_pay') !== false && strpos($ledger, 'name="approve_stat_line"') === false);
check('deductions are recomputed after the shift amount changes', strpos($lib, 'sh_refresh_employee_period') !== false);
check('approving still refuses a line that is already approved before any rewrite', strpos($lib, "if (\$row['approval'] === 'approved'") !== false);
check('ledger approval posts the 1.5x line and uses the same decide function', strpos($lib, 'function sh_approve_ledger_stat') !== false && strpos($lib, 'sh_decide_stat_line') !== false && strpos($lib, 'sh_post_worked_premium') !== false);

if ($fail > 0) {
    echo $fail . " failed\n";
    exit(1);
}
echo "all passed\n";
