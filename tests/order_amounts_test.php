<?php
/**
 * Order amount checks. No database.
 * Run: php tests/order_amounts_test.php
 */
require_once __DIR__ . '/../engine/order_amounts_lib.php';
require_once __DIR__ . '/../engine/admin_super_lib.php';

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

check('temp site is excluded from the store list', !in_array(103, oa_site_ids(), true) && oa_excluded_site_id() === 103);
check('food company stores are the six named sites', oa_site_ids() === [6, 11, 19, 20, 21, 23] && oa_org_id() === 2);
check('weekly and monthly remarks are not daily rows', !oa_is_daily_remark('week_split 2026-11-08') && !oa_is_daily_remark('mtd_split 2026-11-30') && oa_is_daily_remark('') && oa_is_daily_remark(null));
check('daily filter matches the activity report', strpos(oa_daily_remarks_sql('dr'), 'week_split') !== false && strpos(oa_daily_remarks_sql('dr'), 'mtd_split') !== false);

$rows = [
    ['restaurant_id' => 6, 'report_date' => '2026-09-14', 'pos_sales' => 1000, 'remarks' => ''],
    ['restaurant_id' => 6, 'report_date' => '2026-09-14', 'pos_sales' => 500, 'remarks' => ''],
    ['restaurant_id' => 6, 'report_date' => '2026-09-14', 'pos_sales' => 9000, 'remarks' => 'week_split 2026-09-14'],
    ['restaurant_id' => 6, 'report_date' => '2026-09-15', 'pos_sales' => 0, 'remarks' => ''],
    ['restaurant_id' => 6, 'report_date' => '2026-09-16', 'pos_sales' => -20, 'remarks' => ''],
    ['restaurant_id' => 6, 'report_date' => '2026-09-21', 'pos_sales' => 800, 'remarks' => ''],
    ['restaurant_id' => 103, 'report_date' => '2026-09-14', 'pos_sales' => 5000, 'remarks' => ''],
    ['restaurant_id' => 99, 'report_date' => '2026-09-14', 'pos_sales' => 5000, 'remarks' => ''],
    ['restaurant_id' => 23, 'report_date' => '2026-09-14', 'pos_sales' => 2000, 'remarks' => 'mtd_split 2026-09-30'],
    ['restaurant_id' => 23, 'report_date' => '2026-09-18', 'pos_sales' => 400, 'remarks' => null],
];
$days = oa_aggregate_days($rows);
check('same-day daily rows are summed and the weekly row is left out', isset($days[6]['2026-09-14']) && abs($days[6]['2026-09-14'] - 1500) < 0.001);
check('zero and negative days are ignored', !isset($days[6]['2026-09-15']) && !isset($days[6]['2026-09-16']));
check('temp and unknown sites are ignored', !isset($days[103]) && !isset($days[99]));
check('monthly summary is ignored and a real daily row is kept', !isset($days[23]['2026-09-14']) && isset($days[23]['2026-09-18']));
check('14 Sep 2026 is a Monday', oa_weekday_index('2026-09-14') === 1);

// Sep 14–Nov 12, 2026 at 38% was described as about
// Penticton Mon $3,428 / Fri $2,663 / first Mon $4,424
// and Harvey Mon $7,117 / Fri $6,824. Those come from live daily sales,
// which this test does not read. The numbers below lock the same formula.
$orders = oa_orders([1 => 1000, 2 => 1000, 3 => 1000, 4 => 1000, 5 => 500, 6 => 400, 7 => 300], 38);
check('monday order is four weekday averages times food percent', abs($orders['monday_order'] - 1520) < 0.001);
check('friday order is three weekday averages times food percent', abs($orders['friday_order'] - 456) < 0.001);
check('first monday adds friday average times food percent', abs($orders['first_monday'] - 1710) < 0.001);
check('first friday adds monday average times food percent', abs($orders['first_friday'] - 836) < 0.001);
check('week total is the monday order plus the friday order', abs($orders['week_total'] - 1976) < 0.001);

$report = oa_store_report(6, 'Penticton', $days[6], '2026-10-01', '2026-11-14', 38, true);
check('penticton report uses the summed monday', abs($report['weekday_avg'][1] - 1150) < 0.001 && $report['weekday_n'][1] === 2);
check('empty weekday does not invent an average', $report['weekday_n'][3] === 0 && in_array('Wed', $report['missing_weekdays'], true));
check('a sale before the last 14 days is stale', $report['stale'] === true && $report['little_data'] === true);
$fresh = oa_store_report(11, 'Westbank', ['2026-11-12' => 10], '2026-11-12', '2026-11-14', 38, true);
check('a sale inside the last 14 days is not stale', $fresh['stale'] === false && oa_stale_cutoff('2026-11-14') === '2026-11-01');
$none = oa_store_report(20, 'Merritt', [], null, '2026-11-14', 38, true);
check('a store with no days has no order amounts', $none['empty'] && $none['monday_order'] === null && $none['stale']);

[$from, $to, $weeks] = oa_resolve_window(8, '', '', '2026-11-14', true);
check('last 8 weeks ending 14 Nov 2026', $from === '2026-09-20' && $to === '2026-11-14' && $weeks === 8 && oa_span_days($from, $to) === 56);
[$from, $to] = array_slice(oa_resolve_window(8, '2026-11-12', '2026-09-14', '2026-11-14', false), 0, 2);
check('typed dates are kept and ordered', $from === '2026-09-14' && $to === '2026-11-12' && oa_span_days($from, $to) === 60);
check('food percent defaults to 38 and stays in range', oa_food_pct(null) === 38.0 && oa_food_pct('38') === 38.0 && oa_food_pct('150') === 100.0 && oa_food_pct('nope') === 38.0);

$lib = file_get_contents(__DIR__ . '/../engine/order_amounts_lib.php');
check('calculator library has no write statement', !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w|DELETE\s+FROM|REPLACE\s+INTO)\b/i', $lib));
$page = file_get_contents(__DIR__ . '/../order_amounts.php');
check('order page is superadmin gated', strpos($page, 'ps_is_superadmin') !== false && strpos($page, 'Superadmin access required.') !== false && strpos($page, 'ps_require_payroll_admin') !== false);
check('order page registers under the admin menu', strpos($page, "admin_super_ensure_menu(\$pdo, 'Order Amounts', 'order_amounts.php')") !== false);
$menu = file_get_contents(__DIR__ . '/../engine/admin_super_lib.php');
check('menu min role is superadmin and the parent is Admin', strpos($menu, "'UserRole' => 10") !== false && strpos($menu, "'ADMIN'") !== false);

if ($fail > 0) {
    echo "$fail failed\n";
    exit(1);
}
echo "all passed\n";
exit(0);
