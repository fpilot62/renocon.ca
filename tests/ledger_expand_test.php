<?php
/**
 * Ledger expand controls and the stat-pay employee filter.
 * Run: php tests/ledger_expand_test.php
 */
$fail = 0;
function check(string $label, bool $cond): void {
    global $fail;
    if ($cond) { echo "ok  $label\n"; return; }
    echo "FAIL $label\n";
    $fail++;
}
require_once __DIR__ . '/../engine/ledger_matrix_lib.php';

check('statutory holiday is allocated stat pay', ledger_row_is_stat_alloc('Statutory Holiday', 'Hourly'));
check('pay type Statutory is allocated stat pay', ledger_row_is_stat_alloc('Holiday Worked', 'Statutory'));
check('a regular line is not stat pay', !ledger_row_is_stat_alloc('Regular', 'Hourly'));

function emp(string $name, bool $stat, float $gross, float $statGross): array {
    return [
        'name' => $name, 'has_stat' => $stat, 't_stat_alloc' => $statGross,
        't_gross' => $gross, 't_net' => $gross, 't_paid' => 0, 't_rem' => $gross,
        't_reg_h' => 8, 't_ot_h' => 0, 't_travel_h' => 0, 't_stat_h' => $stat ? 1 : 0, 't_stat_cost' => $statGross,
        't_ee' => 0, 't_ee_tax' => 0, 't_ee_cpp' => 0, 't_ee_ei' => 0,
        't_er' => 0, 't_er_cpp' => 0, 't_er_ei' => 0, 't_er_wcb' => 0, 't_er_vac' => 0,
        'lines' => [],
    ];
}
$sites = [
    'Bramalea' => ['name' => 'Bramalea', 'emps' => [
        1 => emp('Ada', true, 1100, 100),
        2 => emp('Ben', false, 500, 0),
    ], 't_gross' => 1600, 't_net' => 1600, 't_paid' => 0, 't_rem' => 1600,
       't_reg_h' => 16, 't_ot_h' => 0, 't_travel_h' => 0, 't_stat_h' => 1, 't_stat_cost' => 100,
       't_ee' => 0, 't_ee_tax' => 0, 't_ee_cpp' => 0, 't_ee_ei' => 0,
       't_er' => 0, 't_er_cpp' => 0, 't_er_ei' => 0, 't_er_wcb' => 0, 't_er_vac' => 0],
    'Empty' => ['name' => 'Empty', 'emps' => [
        3 => emp('Cam', false, 200, 0),
    ], 't_gross' => 200, 't_net' => 200, 't_paid' => 0, 't_rem' => 200,
       't_reg_h' => 8, 't_ot_h' => 0, 't_travel_h' => 0, 't_stat_h' => 0, 't_stat_cost' => 0,
       't_ee' => 0, 't_ee_tax' => 0, 't_ee_cpp' => 0, 't_ee_ei' => 0,
       't_er' => 0, 't_er_cpp' => 0, 't_er_ei' => 0, 't_er_wcb' => 0, 't_er_vac' => 0],
];
$period = ['gross' => 1800, 'net' => 1800, 'paid' => 0, 'rem' => 1800, 'reg_h' => 24, 'ot_h' => 0, 'travel_h' => 0, 'stat_h' => 1, 'stat_cost' => 100, 'ee' => 0, 'ee_tax' => 0, 'ee_cpp' => 0, 'ee_ei' => 0, 'er' => 0, 'er_cpp' => 0, 'er_ei' => 0, 'er_wcb' => 0, 'er_vac' => 0];
$summary = ledger_stat_summary($sites);
check('summary counts only employees with stat pay', $summary['employees'] === 1 && abs($summary['stat_total'] - 100) < 0.01);
[$kept, $period] = ledger_strip_non_stat($sites, $period);
check('sites with no stat employee are hidden', !isset($kept['Empty']) && isset($kept['Bramalea']));
check('employees without stat pay are hidden', count($kept['Bramalea']['emps']) === 1 && isset($kept['Bramalea']['emps'][1]));
check('site and period totals drop the other employees', abs($kept['Bramalea']['t_gross'] - 1100) < 0.01 && abs($period['gross'] - 1100) < 0.01);

$js = ledger_expand_script();
check('expand reuses the hidden class and carets', strpos($js, "classList.remove('hidden')") !== false && strpos($js, "classList.add('open')") !== false);
check('expand is batched and remembered per page', strpos($js, 'requestAnimationFrame') !== false && strpos($js, 'localStorage') !== false && strpos($js, 'location.pathname') !== false);
$page = file_get_contents(__DIR__ . '/../payroll_master_ledger.php');
check('ledger filter is a Stat pay option in the form', strpos($page, 'name="stat_pay"') !== false && strpos($page, 'Stat pay only') !== false && strpos($page, 'pay_period_end BETWEEN') !== false);
check('ledger has expand controls', strpos($page, 'ledger_expand_controls()') !== false);
$alt = file_get_contents(__DIR__ . '/../payroll_master_ledger1.php');
check('ledger1 has the same expand controls', strpos($alt, 'ledger_expand_controls()') !== false && strpos($alt, 'ledger_expand_script()') !== false);

if ($fail > 0) { echo $fail . " failed\n"; exit(1); }
echo "all passed\n";
