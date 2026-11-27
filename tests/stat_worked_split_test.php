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
check('the row states holiday pay, the 5.50 hours, and the total', strpos($label, 'Holiday pay $141.49') !== false
    && strpos($label, 'Worked 5.50h × 1.5 = $148.17') !== false
    && strpos($label, 'total $289.66') !== false);

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
$lib = (string)file_get_contents(__DIR__ . '/../payroll_engine/stat_holidays.php');
check('approving still refuses a line that is already approved before any rewrite', strpos($lib, "if (\$row['approval'] === 'approved'") !== false);

if ($fail > 0) {
    echo $fail . " failed\n";
    exit(1);
}
echo "all passed\n";
