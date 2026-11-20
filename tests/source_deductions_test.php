<?php
/**
 * T4127 Option 1 checks for 2026.
 *
 * The Payroll Deductions Online Calculator (canada.ca/pdoc) could not be
 * queried from this environment. Expected cents are worked from the formulas
 * in T4127, 122nd edition (pay dates before July 1, 2026) and 123rd edition
 * (pay dates on or after July 1, 2026). The bonus case is the worked example
 * printed in chapter 4 of the 122nd edition. Each other case records the
 * inputs and the T4127 factors that produce the expected tax.
 *
 * Run: php tests/source_deductions_test.php
 */
require_once __DIR__ . '/../payroll_engine/source_deductions_lib.php';

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
function near(string $label, float $got, float $want, float $tol = 0.001): void
{
    check($label . " (got $got, want $want)", abs($got - $want) <= $tol);
}

$jan = sd_pack_for('2026-03-15');
$jun = sd_pack_for('2026-06-30');
$jul = sd_pack_for('2026-07-01');
check('January pack is the 122nd edition', $jan && strpos($jan['edition'], '122nd') !== false);
check('June 30 still uses the 122nd edition', $jun && strpos($jun['edition'], '122nd') !== false);
check('July 1 uses the 123rd edition', $jul && strpos($jul['edition'], '123rd') !== false);
check('July BC lowest rate is the prorated 6.14%', $jul && abs($jul['provinces']['BC']['lowest_rate'] - 0.0614) < 0.0000001);
check('July leaves Ontario brackets alone', $jul && abs($jul['provinces']['ON']['brackets'][0]['rate'] - 0.0505) < 0.0000001);
check('July leaves Saskatchewan BPA alone', $jul && abs($jul['provinces']['SK']['bpa'] - 20381) < 0.001);
check('a 2025 pay date has no pack', sd_pack_for('2025-12-31') === null);

// Official chapter 4 bonus example. Federal factors only (the guide does not
// run a province in that example). Weekly $1,000, current bonus $2,500,
// prior bonus $1,500, prior F5B $14.60, 52 periods, PM 12, claim code 1 ($16,452).
$bonus = sd_calculate([
    'pay_date' => '2026-03-15',
    'province' => '',
    'periods' => 52,
    'periodic_gross' => 1000,
    'bonus' => 2500,
    'ytd_bonus' => 1500,
    'ytd_f5b' => 14.60,
    'tc' => 16452,
    'tcp' => 0,
]);
$f = $bonus['factors'];
near('guide example CPP on salary plus bonus', $bonus['cpp'], 204.25);
near('guide example F5', $f['F5'], 34.33);
near('guide example F5A', $f['F5A'], 9.81);
near('guide example F5B', $f['F5B'], 24.52);
near('guide example A with the bonus', $f['A'], 55450.76);
near('guide example K2 with the bonus', $f['K2'], 491.63);
near('guide example K4', $f['K4'], 210.14);
near('guide example T1 with the bonus', $f['T1'], 4758.06);
near('guide example A without the current bonus', $f['A_without'], 52975.28);
near('guide example K2 without the current bonus', $f['K2_without'], 468.60);
near('guide example T1 without the current bonus', $f['T1_without'], 4434.52);
near('guide example tax on the bonus', $f['TB'], 323.54);
check('guide example does not invent provincial tax', $bonus['provincial_annual'] == 0.0 && $bonus['province_supported'] === false);

// BC, semi-monthly, first pay of a $1,600 period, March 15, 2026 (122nd).
// No TD1: BPAF $16,452 because annual income is under $181,440. BC BPA $13,216.
// A 38,051.04, T1 2,484.25, T2 1,006.90 after the BC reduction, tax 145.46.
$bc = sd_calculate([
    'pay_date' => '2026-03-15',
    'province' => 'British Columbia',
    'periods' => 24,
    'periodic_gross' => 1600,
]);
near('BC semi-monthly CPP', $bc['cpp'], 86.52);
near('BC semi-monthly EI', $bc['ei'], 26.08);
near('BC semi-monthly employer EI is 1.4 times', $bc['ei_er'], 36.51);
near('BC semi-monthly tax', $bc['income_tax'], 145.46);
near('BC semi-monthly uses BPAF as the default claim', $bc['factors']['TC'], 16452);
near('BC semi-monthly default provincial claim is the BPA', $bc['factors']['TCP'], 13216);
check('BC is supported', $bc['province_supported'] === true && $bc['cpp2'] == 0.0);

// Same gross, same employee, first payroll in July. Only BC changes.
$bcJul = sd_calculate([
    'pay_date' => '2026-07-15',
    'province' => 'BC',
    'periods' => 24,
    'periodic_gross' => 1600,
]);
near('BC July tax uses the prorated 6.14% and the $805 reduction', $bcJul['income_tax'], 146.00);
near('BC July CPP is unchanged', $bcJul['cpp'], 86.52);
check('BC July edition is recorded on the result', strpos($bcJul['edition'], '123rd') !== false);

// TD1 federal total above the basic amount. Provincial claim stays the BPA.
$td1 = sd_calculate([
    'pay_date' => '2026-03-15',
    'province' => 'BC',
    'periods' => 24,
    'periodic_gross' => 1600,
    'tc' => 22000,
]);
near('a higher federal TD1 lowers the tax', $td1['income_tax'], 113.10);
near('the filed TC is used instead of the formula', $td1['factors']['TC'], 22000);

// Vacation pay of 4% added to every cheque is part of factor I (it is paid
// every period). $1,600 wages + $64 vacation = $1,664.
$vac = sd_calculate([
    'pay_date' => '2026-03-15',
    'province' => 'BC',
    'periods' => 24,
    'periodic_gross' => 1664,
]);
near('vacation pay in the period is pensionable', $vac['cpp'], 90.33);
near('vacation pay in the period is insurable', $vac['ei'], 27.12);
near('vacation pay in the period is taxed', $vac['income_tax'], 158.99);

// Ontario, semi-monthly, $2,000. Health premium annual V2 is $450. No dependants,
// so the Ontario reduction is wiped out by the basic tax.
$on = sd_calculate([
    'pay_date' => '2026-06-15',
    'province' => 'ON',
    'periods' => 24,
    'periodic_gross' => 2000,
]);
near('Ontario semi-monthly CPP', $on['cpp'], 110.32);
near('Ontario semi-monthly EI', $on['ei'], 32.60);
near('Ontario semi-monthly tax includes the health premium', $on['income_tax'], 240.47);
near('Ontario health premium annual amount', $on['factors']['V2'], 450.00);
near('Ontario reduction is zero at this income', $on['factors']['S'], 0.00);
check('Ontario June payroll is still the 122nd edition', strpos($on['edition'], '122nd') !== false);

// Claim code E: no federal or Ontario income tax, but the health premium remains.
$exempt = sd_calculate([
    'pay_date' => '2026-06-15',
    'province' => 'Ontario',
    'periods' => 24,
    'periodic_gross' => 2000,
    'tax_exempt' => true,
]);
near('claim code E still withholds the Ontario Health Premium', $exempt['income_tax'], 18.75);
near('claim code E federal annual tax is zero', $exempt['factors']['T1'], 0.00);

// Saskatchewan, semi-monthly, $1,800. Brackets and BPA only.
$sk = sd_calculate([
    'pay_date' => '2026-05-15',
    'province' => 'SK',
    'periods' => 24,
    'periodic_gross' => 1800,
]);
near('Saskatchewan semi-monthly CPP', $sk['cpp'], 98.42);
near('Saskatchewan semi-monthly EI', $sk['ei'], 29.34);
near('Saskatchewan semi-monthly tax', $sk['income_tax'], 215.81);
near('Saskatchewan default claim is the BPA', $sk['factors']['TCP'], 20381);

// Weekly, low income, BC. The BC reduction clears the provincial tax.
$weekly = sd_calculate([
    'pay_date' => '2026-02-06',
    'province' => 'BC',
    'periods' => 52,
    'periodic_gross' => 500,
]);
near('weekly low-income CPP uses the $67.30 exemption', $weekly['cpp'], 25.75);
near('weekly low-income EI', $weekly['ei'], 8.15);
near('weekly low-income BC tax is fully reduced', $weekly['factors']['T2'], 0.00);
near('weekly low-income tax', $weekly['income_tax'], 16.92);

// Bi-weekly, BC, $1,200, 26 periods. Exemption $134.61.
$bi = sd_calculate([
    'pay_date' => '2026-02-13',
    'province' => 'BC',
    'periods' => 26,
    'periodic_gross' => 1200,
]);
near('bi-weekly CPP', $bi['cpp'], 63.39);
near('bi-weekly EI', $bi['ei'], 19.56);
near('bi-weekly tax', $bi['income_tax'], 75.74);

// Monthly, Ontario, $8,000. Annualized CPP and EI credits use the yearly maximum
// because 12 times this period already exceeds it. First period, so no CPP2 yet.
$monthly = sd_calculate([
    'pay_date' => '2026-08-31',
    'province' => 'ON',
    'periods' => 12,
    'periodic_gross' => 8000,
]);
near('monthly CPP', $monthly['cpp'], 458.65);
near('monthly EI', $monthly['ei'], 130.40);
near('monthly tax', $monthly['income_tax'], 1572.66);
near('monthly credit uses the maximum base CPP', $monthly['factors']['annual_base_cpp'], 3519.45);
near('monthly credit uses the maximum EI premium', $monthly['factors']['annual_ei'], 1123.07);
check('August payroll uses the July edition', strpos($monthly['edition'], '123rd') !== false);

// CPP and EI already maxed, and this bi-weekly pay crosses into CPP2.
// 21 prior periods of $3,500: CPP withheld 21 x 200.24 = 4,205.04, pensionable 73,500.
// EI maxed after 19 periods (19 x 57.05 = 1,083.95) plus period 20 (39.12) = 1,123.07.
$cpp2 = sd_calculate([
    'pay_date' => '2026-11-06',
    'province' => 'ON',
    'periods' => 26,
    'periodic_gross' => 3500,
    'ytd_cpp' => 4205.04,
    'ytd_pensionable' => 73500,
    'ytd_ei' => 1123.07,
    'ytd_cpp2' => 0,
]);
near('CPP is only the room under the annual maximum', $cpp2['cpp'], 25.41);
near('CPP2 starts once pensionable earnings pass the YMPE', $cpp2['cpp2'], 96.00);
near('EI is zero once the maximum premium is withheld', $cpp2['ei'], 0.00);
near('employer CPP matches the employee base and first additional', $cpp2['cpp_er'], 25.41);
near('employer CPP2 matches the employee', $cpp2['cpp2_er'], 96.00);
near('K2 uses the maximum base CPP once the maximum is reached', $cpp2['factors']['annual_base_cpp'], 3519.45);
near('tax in the CPP2 period', $cpp2['income_tax'], 649.65);

// Same person, next period: CPP stays zero, CPP2 continues, still under its own maximum.
$cpp2next = sd_calculate([
    'pay_date' => '2026-11-20',
    'province' => 'ON',
    'periods' => 26,
    'periodic_gross' => 3500,
    'ytd_cpp' => 4230.45,
    'ytd_cpp2' => 96.00,
    'ytd_pensionable' => 77000,
    'ytd_ei' => 1123.07,
]);
near('CPP stays at zero after the maximum', $cpp2next['cpp'], 0.00);
near('CPP2 continues between the two ceilings', $cpp2next['cpp2'], 140.00);
near('employer EI is zero when the employee premium is zero', $cpp2next['ei_er'], 0.00);

// WSIB / WCB. The rate is the site's rate. The cap is the province's.
$wsib = sd_workers_comp('ON', 1600, 0, 1.23, false, $jan);
near('WSIB premium is the site percent of earnings', $wsib['premium'], 19.68);
$wsibCap = sd_workers_comp('ON', 1600, 121000, 1.23, false, $jan);
near('WSIB stops at the 2026 maximum insurable earnings', $wsibCap['assessable'], 700.00);
near('WSIB premium on the remaining room', $wsibCap['premium'], 8.61);
$wsibEx = sd_workers_comp('ON', 1600, 0, 1.23, true, $jan);
check('an exempt worker has no WSIB premium', $wsibEx['premium'] == 0.0 && $wsibEx['assessable'] == 0.0);
$wcbBc = sd_workers_comp('BC', 200000, 0, 0.55, false, $jan);
near('WorkSafeBC assessable earnings stop at $127,500', $wcbBc['assessable'], 127500.00);
$wcbSk = sd_workers_comp('Saskatchewan', 200000, 0, 1.10, false, $jan);
near('Saskatchewan WCB assessable earnings stop at $108,223', $wcbSk['assessable'], 108223.00);

// Ontario EHT. Rate from total remuneration, tax on remuneration minus the exemption.
$under = sd_ontario_eht(150000, 1000000, true, false, $jan);
check('EHT is zero while remuneration is inside the exemption', $under['tax'] == 0.0 && abs($under['rate'] - 0.0098) < 0.0000001);
$notch = sd_ontario_eht(250000, 0, false, false, $jan);
near('EHT with no exemption uses the notch for $250,000', $notch['tax'], 3057.50);
check('that notch rate is 1.223%', abs($notch['rate'] - 0.01223) < 0.0000001);
$over = sd_ontario_eht(1200000, 1000000, true, false, $jan);
near('EHT above $400,000 is 1.95% of pay after the $1,000,000 exemption', $over['tax'], 3900.00);
$shared = sd_ontario_eht(900000, 400000, true, false, $jan);
near('an associated employer is taxed on pay above its own share of the exemption', $shared['tax'], 9750.00);
$group = sd_ontario_eht(900000, 400000, true, true, $jan);
near('a group over $5,000,000 loses the exemption', $group['tax'], 17550.00);
$step = sd_ontario_eht_increment(1100000, 50000, 1000000, true, false, $jan);
near('a later pay picks up only the extra EHT', $step['increment'], 975.00);

// Two provinces, one gross. The difference is the provincial calculation.
$sameBc = sd_calculate(['pay_date' => '2026-04-15', 'province' => 'BC', 'periods' => 24, 'periodic_gross' => 1800]);
$sameOn = sd_calculate(['pay_date' => '2026-04-15', 'province' => 'ON', 'periods' => 24, 'periodic_gross' => 1800]);
$sameSk = sd_calculate(['pay_date' => '2026-04-15', 'province' => 'SK', 'periods' => 24, 'periodic_gross' => 1800]);
check('CPP does not change with the province', $sameBc['cpp'] === $sameOn['cpp'] && $sameOn['cpp'] === $sameSk['cpp']);
check('the three provinces do not withhold the same tax', $sameBc['income_tax'] !== $sameOn['income_tax'] && $sameOn['income_tax'] !== $sameSk['income_tax']);

$none = sd_calculate(['pay_date' => '2025-06-15', 'province' => 'BC', 'periods' => 24, 'periodic_gross' => 1600]);
check('a pay date with no rate pack is refused', $none['ok'] === false && $none['income_tax'] == 0.0);

$pack = sd_pack_for('2026-01-01');
near('BPAF is the maximum at the start of the phase-out', sd_bpaf($pack, 181440), 16452);
near('BPAF is the minimum at the end of the phase-out', sd_bpaf($pack, 258482), 14829);

$parts = sd_allocate_money(10.00, [1, 1, 1]);
near('allocation sums to the original total', array_sum($parts), 10.00);
check('allocation gives the remainder to the last share', $parts === [3.33, 3.33, 3.34]);

require_once __DIR__ . '/../payroll_engine/payroll_engine.php';
check('semi-monthly window is the 1st through the 15th', pe_period_window('2026-03-10', 'semi_monthly') === ['2026-03-01', '2026-03-15']);
check('semi-monthly window is the 16th through month end', pe_period_window('2026-03-20', 'semi_monthly') === ['2026-03-16', '2026-03-31']);
check('monthly window is the calendar month', pe_period_window('2026-03-20', 'monthly') === ['2026-03-01', '2026-03-31']);
check('a year of semi-monthly pays is 24', pe_periods_in_year('semi_monthly', 2026) === 24);
check('a year of monthly pays is 12', pe_periods_in_year('monthly', 2026) === 12);
$weeklyCount = pe_periods_in_year('weekly', 2026, '2024-01-01');
check('weekly pays in 2026 are 52 or 53', $weeklyCount === 52 || $weeklyCount === 53);
$migration = file_get_contents(__DIR__ . '/../migrations/20261120_source_deductions.sql');
check('the migration does not drop or delete payroll rows', stripos($migration, 'DROP TABLE') === false && stripos($migration, 'DELETE FROM') === false && stripos($migration, 'payroll_finalized') !== false);

// CRA PDOC, 2026-11-20, 26 pays, claim code 1, no year-to-date.
// Federal and provincial tax are each rounded to the cent, then added.
function pdoc(string $prov, float $gross, string $date = '2026-11-20'): array {
    return sd_calculate([
        'pay_date' => $date,
        'province' => $prov,
        'periods' => 26,
        'periodic_gross' => $gross,
    ]);
}
function exact(string $label, float $got, float $want): void {
    check($label . " (got $got, want $want)", abs($got - $want) < 0.001);
}
$p1 = pdoc('ON', 1000);
exact('PDOC ON 1000 federal', $p1['federal_period'], 33.84);
exact('PDOC ON 1000 provincial', $p1['provincial_period'], 32.16);
exact('PDOC ON 1000 tax', $p1['income_tax'], 66.00);
exact('PDOC ON 1000 CPP', $p1['cpp'], 51.49);
exact('PDOC ON 1000 EI', $p1['ei'], 16.30);
$p2 = pdoc('ON', 2000);
exact('PDOC ON 2000 federal', $p2['federal_period'], 163.23);
exact('PDOC ON 2000 provincial', $p2['provincial_period'], 91.60);
exact('PDOC ON 2000 tax', $p2['income_tax'], 254.83);
exact('PDOC ON 2000 CPP', $p2['cpp'], 110.99);
exact('PDOC ON 2000 EI', $p2['ei'], 32.60);
exact('PDOC ON 2000 net', round(2000 - $p2['cpp'] - $p2['cpp2'] - $p2['ei'] - $p2['income_tax'], 2), 1601.58);
$p3 = pdoc('SK', 1500);
exact('PDOC SK 1500 federal', $p3['federal_period'], 98.53);
exact('PDOC SK 1500 provincial', $p3['provincial_period'], 64.10);
exact('PDOC SK 1500 tax', $p3['income_tax'], 162.63);
exact('PDOC SK 1500 CPP', $p3['cpp'], 81.24);
exact('PDOC SK 1500 EI', $p3['ei'], 24.45);
$p4 = pdoc('ON', 189.50);
exact('PDOC ON 189.50 tax', $p4['income_tax'], 0.00);
exact('PDOC ON 189.50 CPP', $p4['cpp'], 3.27);
exact('PDOC ON 189.50 EI', $p4['ei'], 3.09);
$july = pdoc('ON', 2000, '2026-07-01');
check('July 1 pack is the 123rd edition', strpos((string)$july['edition'], '123rd') !== false);
exact('July pack ON 2000 tax matches November', $july['income_tax'], 254.83);
exact('July pack ON 2000 federal', $july['federal_period'], 163.23);
exact('July pack ON 2000 provincial', $july['provincial_period'], 91.60);

if ($fail > 0) {
    echo "\n$fail failed\n";
    exit(1);
}
echo "\nall source-deduction checks passed\n";
