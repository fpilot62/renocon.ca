<?php
/**
 * Outstanding report checks. No database.
 * Run: php tests/outstanding_report_test.php
 */
require_once __DIR__ . '/../engine/outstanding_report_lib.php';
require_once __DIR__ . '/../engine/outstanding_report_view.php';

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

$admin = ['id' => 4, 'org_id' => 2, 'user_role' => 20, 'active' => 1];
$home = orpt_access_decision($admin, 'Admin', 0, []);
$same = orpt_access_decision($admin, 'Admin', 2, []);
$other = orpt_access_decision($admin, '', 9, []);
check('an admin sees their own org', $home['ok'] && $home['kind'] === 'member' && $home['org_id'] === 2);
check('an admin may name that same org', $same['ok'] && $same['org_id'] === 2);
check('an admin cannot open another org', !$other['ok'] && $other['http'] === 403 && $other['org_id'] === 0);

$owner = orpt_access_decision(['id' => 8, 'org_id' => 4, 'user_role' => 22, 'active' => 1], 'Owner', 0, []);
check('a named owner above site manager is allowed', $owner['ok'] && $owner['org_id'] === 4);
$manager = orpt_access_decision(['id' => 3, 'org_id' => 2, 'user_role' => 25, 'active' => 1], 'Site Manager', 0, []);
$staff = orpt_access_decision(['id' => 9, 'org_id' => 2, 'user_role' => 40, 'active' => 1], 'Staff', 0, []);
check('a site manager and a staff member are refused', !$manager['ok'] && !$staff['ok']);
$inactive = orpt_access_decision(['id' => 4, 'org_id' => 2, 'user_role' => 20, 'active' => 0], 'Admin', 0, []);
$guest = orpt_access_decision(['id' => 0, 'org_id' => 2, 'user_role' => 20, 'active' => 1], 'Admin', 0, []);
check('an inactive account and a guest are refused', !$inactive['ok'] && $inactive['http'] === 403 && !$guest['ok'] && $guest['login']);

$super = ['id' => 1, 'org_id' => 2, 'user_role' => 10, 'active' => 1];
$pkbc = orpt_access_decision($super, 'Superadmin', 2, []);
$aa = orpt_access_decision($super, 'Superadmin', 4, []);
$blocked = orpt_access_decision($super, 'Superadmin', 1, []);
$outside = orpt_access_decision($super, 'Superadmin', 9, []);
$chooseList = orpt_access_decision($super, 'Superadmin', 0, []);
check('a platform superadmin can open PK BC Food without a grant', $pkbc['ok'] && $pkbc['kind'] === 'allowlist' && $pkbc['org_id'] === 2);
check('a platform superadmin can open AA Group without a grant', $aa['ok'] && $aa['kind'] === 'allowlist' && $aa['org_id'] === 4);
check('Renocon and any other org still need a support-access grant', !$blocked['ok'] && strpos($blocked['error'], 'support-access') === false && $blocked['http'] === 403 && !$outside['ok']);
check('with no org picked the allowlist asks for a choice of 2 and 4', $chooseList['ok'] && $chooseList['kind'] === 'allowlist-choose' && $chooseList['org_id'] === 0 && $chooseList['choices'] === [2, 4]);
$picked = orpt_access_decision($super, 'Superadmin', 2, [2, 4]);
$wrong = orpt_access_decision($super, 'Superadmin', 9, [2, 4]);
$choose = orpt_access_decision($super, 'Superadmin', 0, [4, 2]);
$renoconGrant = orpt_access_decision($super, 'Superadmin', 1, [1]);
$grantPlusAllow = orpt_access_decision($super, 'Superadmin', 0, [1]);
check('a granted org can be chosen and another org cannot', $picked['ok'] && $picked['kind'] === 'support' && $picked['org_id'] === 2 && !$wrong['ok']);
check('grants plus the allowlist still ask for a choice', $choose['ok'] && $choose['kind'] === 'support-choose' && $choose['org_id'] === 0 && $choose['choices'] === [2, 4]);
check('a grant can open an org that is not on the allowlist', $renoconGrant['ok'] && $renoconGrant['kind'] === 'support' && $renoconGrant['org_id'] === 1);
check('a grant for another org is added to the allowlist choice', $grantPlusAllow['ok'] && $grantPlusAllow['kind'] === 'support-choose' && $grantPlusAllow['choices'] === [1, 2, 4]);

$rows = [
    [
        'id' => 1, 'org_id' => 2, 'site_id' => 6, 'store_name' => 'Penticton', 'employee_name' => 'Pat Example',
        'user_id' => 5, 'pay_period' => 'Sep 1-15, 2026', 'period_start' => '2026-09-01', 'period_end' => '2026-09-15',
        'gross' => 100, 'vacation_pay' => 10, 'net' => 80, 'employer_deductions' => 99,
        'pay_as_invoice' => 0, 'status' => 'Pending', 'notes' => 'ER CPP 5 / ER EI 2 / WCB 1', 'flagged' => 0,
    ],
    [
        'id' => 2, 'org_id' => 2, 'site_id' => 11, 'store_name' => 'Westbank', 'employee_name' => 'Pat Example',
        'user_id' => 5, 'pay_period' => 'Sep 16-30, 2026', 'period_start' => '2026-09-16', 'period_end' => '2026-09-30',
        'gross' => 50, 'vacation_pay' => null, 'net' => null, 'employer_deductions' => 4,
        'pay_as_invoice' => 0, 'status' => 'Pending', 'notes' => 'hold', 'flagged' => 1,
    ],
    [
        'id' => 3, 'org_id' => 2, 'site_id' => 6, 'store_name' => 'Penticton', 'employee_name' => 'Pat Example',
        'user_id' => null, 'pay_period' => 'Aug 16-31, 2026', 'period_start' => '2026-08-16', 'period_end' => '2026-08-31',
        'gross' => 20, 'vacation_pay' => 0, 'net' => 15, 'employer_deductions' => 1,
        'pay_as_invoice' => 0, 'status' => 'Pending', 'notes' => '', 'flagged' => 0,
    ],
    [
        'id' => 4, 'org_id' => 2, 'site_id' => 6, 'store_name' => 'Penticton', 'employee_name' => 'Ada Other',
        'user_id' => 8, 'pay_period' => 'Sep 1-15, 2026', 'period_start' => '2026-09-01', 'period_end' => '2026-09-15',
        'gross' => 1000, 'vacation_pay' => 0, 'net' => 80, 'employer_deductions' => 8,
        'pay_as_invoice' => 1, 'status' => 'Pending', 'notes' => '', 'flagged' => 0,
    ],
    [
        'id' => 5, 'org_id' => 2, 'site_id' => 6, 'store_name' => 'Penticton', 'employee_name' => 'Paid Person',
        'user_id' => 9, 'pay_period' => 'Sep 1-15, 2026', 'period_start' => '2026-01-01', 'period_end' => '2026-01-15',
        'gross' => 5000, 'vacation_pay' => 0, 'net' => 4000, 'employer_deductions' => 80,
        'pay_as_invoice' => 0, 'status' => 'Paid', 'notes' => '', 'flagged' => 0,
    ],
    [
        'id' => 6, 'org_id' => 2, 'site_id' => 6, 'store_name' => 'Penticton', 'employee_name' => 'Disputed Person',
        'user_id' => 10, 'pay_period' => 'old', 'period_start' => '2026-01-01', 'period_end' => '2026-01-15',
        'gross' => 70, 'net' => 70, 'employer_deductions' => 1, 'status' => 'Disputed', 'notes' => '', 'flagged' => 0,
    ],
    [
        'id' => 7, 'org_id' => 2, 'site_id' => 6, 'store_name' => 'Penticton', 'employee_name' => 'Dup Person',
        'user_id' => 11, 'pay_period' => 'old', 'period_start' => '2026-01-01', 'period_end' => '2026-01-15',
        'gross' => 70, 'net' => 70, 'employer_deductions' => 1, 'status' => 'Duplicate', 'notes' => '', 'flagged' => 0,
    ],
];

$report = orpt_report($rows, '', '');
check('paid disputed and duplicate lines are not outstanding', count($report['employees']) === 2);
$pat = null;
$ada = null;
foreach ($report['employees'] as $emp) {
    if ($emp['name'] === 'Pat Example') {
        $pat = $emp;
    }
    if ($emp['name'] === 'Ada Other') {
        $ada = $emp;
    }
}
check('one employee row folds the unmatched name into the user', $pat !== null && $pat['periods'] === 3 && $pat['stores'] === 'Penticton, Westbank');
check('gross is the pending gross and since is the earliest start', $pat['gross_text'] === '170.00' && $pat['since'] === '2026-08-16');
check('net leaves a blank line out of the sum', $pat['net_text'] === '95.00 (1 line has no net)');
check('employer parts are used and the leftover line stays a lump', $pat['employer_text'] === 'CPP 5.00 · EI 2.00 · WCB 1.00 · other 5.00');
check('payroll total uses the parts, not the larger deductions column', $pat['total_text'] === '193.00');
check('an invoice total is net plus gst and its burden is not added again', $ada !== null && $ada['employer_text'] === '8.00' && $ada['total_text'] === '84.00');
check('the totals row adds both employees', $report['totals_row'][0] === 'Total' && $report['totals_row'][2] === '1,170.00' && $report['totals_row'][1] === '2 employees');
check('notes keep the flag and the note text', strpos($pat['notes'], 'Flagged') !== false && strpos($pat['notes'], 'hold') !== false);

$storeReport = orpt_report($rows, 'site-11', '');
check('a store filter keeps that store only', count($storeReport['employees']) === 1 && $storeReport['employees'][0]['gross_text'] === '50.00' && $storeReport['employees'][0]['stores'] === 'Westbank');
$unknownStore = orpt_report($rows, 'site-999', '');
check('an unknown store does not filter and is not echoed as a key', $unknownStore['store'] === '' && count($unknownStore['employees']) === 2);
$drill = orpt_report($rows, '', $pat['key']);
check('a drill-down keeps that employee periods', $drill['employee'] === $pat['key'] && count($drill['detail']) === 3 && count($drill['employees']) === 1);
$badDrill = orpt_report($rows, '', 'u999');
check('an unknown employee drill-down is ignored', $badDrill['employee'] === '' && count($badDrill['employees']) === 2);

$sheet = orpt_summary_sheet($report);
$detail = orpt_detail_sheet($report);
check('the summary sheet matches the table columns and the total', $sheet[0] === orpt_summary_headers() && $sheet[1][0] === 'Ada Other' && $sheet[count($sheet) - 1][0] === 'Total' && $sheet[count($sheet) - 1][2] === '1,170.00');
$flat = json_encode($sheet);
check('the summary sheet leaves out paid wages', strpos((string)$flat, 'Paid Person') === false && strpos((string)$flat, '5,000') === false);
$detailGross = [];
foreach ($detail as $i => $line) {
    if ($i > 0) {
        $detailGross[] = $line[5];
    }
}
check('the detail sheet is the periods behind the summary', $detail[0][0] === 'Employee' && count($detail) === 5 && in_array(20.0, $detailGross, true) && in_array(1000.0, $detailGross, true));

$bytes = xlsx_build(['Summary' => $sheet, 'Detail' => $detail]);
$zip = new ZipArchive();
$tmp = tempnam(sys_get_temp_dir(), 'orpt');
file_put_contents($tmp, $bytes);
$opened = $zip->open($tmp) === true;
$workbook = $opened ? $zip->getFromName('xl/workbook.xml') : '';
$sheetXml = $opened ? $zip->getFromName('xl/worksheets/sheet1.xml') : '';
$detailXml = $opened ? $zip->getFromName('xl/worksheets/sheet2.xml') : '';
if ($opened) {
    $zip->close();
}
@unlink($tmp);
check('the workbook has a summary tab and a detail tab', $opened && strpos($workbook, 'name="Summary"') !== false && strpos($workbook, 'name="Detail"') !== false);
check('the summary tab contains the live employee total', strpos($sheetXml, 'Pat Example') !== false && strpos($sheetXml, '1,170.00') !== false && strpos($sheetXml, 'Paid Person') === false);
check('the detail tab contains a period amount', strpos($detailXml, '<v>20.00</v>') !== false && strpos($detailXml, 'Penticton') !== false);
$formula = xlsx_build(['Summary' => [['Name'], ['=1+1']]]);
$fz = new ZipArchive();
$ft = tempnam(sys_get_temp_dir(), 'orptf');
file_put_contents($ft, $formula);
$fz->open($ft);
$formulaXml = $fz->getFromName('xl/worksheets/sheet1.xml');
$fz->close();
@unlink($ft);
check('a formula-looking name is stored as text', (strpos($formulaXml, "'=1+1") !== false || strpos($formulaXml, '&apos;=1+1') !== false) && strpos($formulaXml, '<f>') === false);

$html = orpt_report_html('', 2, 'PK BC Food INC', $report);
check('the page escapes note markup and offers the excel download', strpos($html, 'Download Excel') !== false && strpos($html, 'export=xlsx') !== false && strpos($html, '<b>') === false && strpos($html, 'Pat Example') !== false);
$scriptNote = orpt_report([[
    'id' => 1, 'store_name' => 'Penticton', 'employee_name' => '<script>', 'user_id' => 3,
    'pay_period' => 'p', 'period_start' => '2026-09-01', 'period_end' => '2026-09-15',
    'gross' => 1, 'net' => 1, 'employer_deductions' => 0, 'status' => 'Pending', 'notes' => '<b>keep</b>', 'flagged' => 0,
]], '', '');
$safe = orpt_report_html('', 2, 'Org', $scriptNote);
check('employee names and notes are escaped', strpos($safe, '<script>') === false && strpos($safe, '&lt;script&gt;') !== false && strpos($safe, '&lt;b&gt;') !== false);

$sql = orpt_pending_sql();
check('the wage read is filtered by org and pending status', strpos($sql, 'admin_outstanding_wages') !== false && substr_count($sql, '?') === 2 && stripos($sql, 'insert') === false && stripos($sql, 'update') === false);

$cash = [
    'id' => 1, 'employee_name' => 'Cash Pat', 'store_name' => 'Penticton', 'pay_period' => 'Sep 1-15, 2026',
    'gross' => 100, 'vacation_pay' => 10, 'employer_contribution' => 50, 'net_cash' => 110,
    'cash_flag' => 1, 'notes' => '<b>now</b>', 'wage_id' => 4,
];
$paidNow = [
    'id' => 2, 'employee_name' => 'Ada Other', 'store_name' => 'Westbank', 'pay_period' => 'Sep 1-15, 2026',
    'gross' => 80, 'vacation_pay' => null, 'employer_contribution' => 12.5, 'net_cash' => 70,
    'cash_flag' => 0, 'notes' => '', 'wage_id' => 0,
];
$cashLine = orpt_demand_line($cash);
$payLine = orpt_demand_line($paidNow);
check('cash or contractor rows leave employer contribution blank', $cashLine['employer'] === null && abs($cashLine['burden'] - 110) < 0.001 && $cashLine['cash']);
check('a payroll immediate demand adds CPP EI and WCB into the burden', $payLine['employer'] !== null && abs($payLine['employer'] - 12.5) < 0.001 && abs($payLine['burden'] - 92.5) < 0.001);
$demandSheet = orpt_demand_sheet([$cash, $paidNow]);
check('the immediate demand sheet has the requested columns and a total', $demandSheet[0] === orpt_demand_headers() && $demandSheet[1][5] === '—' && $demandSheet[1][6] === '110.00' && $demandSheet[3][0] === 'Total' && $demandSheet[3][6] === '202.50' && $demandSheet[1][10] === '4');
$blankName = orpt_demand_input(['employee_name' => '', 'gross' => '10']);
$cashInput = orpt_demand_input([
    'employee_name' => 'Pat', 'store_name' => 'Penticton', 'pay_period' => 'Sep 1-15',
    'gross' => '$100.00', 'vacation_pay' => '10', 'employer_contribution' => '50',
    'net_cash' => '110', 'cash_flag' => '1', 'notes' => '=1+1', 'wage_id' => '0',
]);
check('a demand row needs a name and cash clears the employer amount', !$blankName['ok'] && $cashInput['ok'] && $cashInput['row']['employer_contribution'] === null && $cashInput['row']['cash_flag'] === 1 && $cashInput['row']['wage_id'] === null);
$demandHtml = orpt_report_html('', 2, 'PK BC Food INC', $report, [
    'ready' => true,
    'rows' => [orpt_demand_present($cash)],
    'totals' => orpt_demand_totals([$cash]),
    'wages' => [],
    'edit' => orpt_demand_form_values(null),
    'error' => '',
    'flash' => '',
    'csrf' => 'tok',
]);
check('the page lists immediate demand and escapes its notes', strpos($demandHtml, 'Immediate demand') !== false && strpos($demandHtml, 'Cash / contractor') !== false && strpos($demandHtml, '&lt;b&gt;now&lt;/b&gt;') !== false && strpos($demandHtml, 'name="csrf_token" value="tok"') !== false && strpos($demandHtml, 'demand_action') !== false);
$demandBook = xlsx_build(['Summary' => [['Name']], 'Detail' => [['Employee']], 'Immediate Demand' => $demandSheet]);
$dz = new ZipArchive();
$dt = tempnam(sys_get_temp_dir(), 'orptd');
file_put_contents($dt, $demandBook);
$dz->open($dt);
$demandBookXml = $dz->getFromName('xl/workbook.xml');
$demandSheetXml = $dz->getFromName('xl/worksheets/sheet3.xml');
$dz->close();
@unlink($dt);
check('the workbook adds an Immediate Demand tab', strpos($demandBookXml, 'name="Immediate Demand"') !== false && strpos($demandSheetXml, 'Cash Pat') !== false && strpos($demandSheetXml, 'Paid Person') === false);

$demandSql = file_get_contents(__DIR__ . '/../migrations/20261117_admin_immediate_demand.sql');
check('the immediate demand migration only creates its table', strpos($demandSql, 'CREATE TABLE IF NOT EXISTS admin_immediate_demand') !== false && !preg_match('/\b(ALTER|INSERT|UPDATE|DELETE|DROP)\b/i', $demandSql) && !preg_match('/payroll_finalized|payroll_payments|payroll_masters/', $demandSql));

$lib = file_get_contents(__DIR__ . '/../engine/outstanding_report_lib.php');
$page = file_get_contents(__DIR__ . '/../outstanding_report.php');
$view = file_get_contents(__DIR__ . '/../engine/outstanding_report_view.php');
$forbidden = '/\b(INSERT INTO|UPDATE|DELETE FROM)\s+`?admin_outstanding_wages\b/i';
check('the report library does not write the wage ledger', !preg_match($forbidden, $lib) && strpos($lib, 'ow_save_field') === false && strpos($lib, 'ow_ensure') === false);
check('the page does not write the wage ledger or run its migration', !preg_match($forbidden, $page) && strpos($page, 'ow_ensure') === false && strpos($page, 'ow_save_field') === false);
check('immediate demand changes require the payroll token and stay in the org', strpos($page, 'ps_require_csrf') !== false && strpos($page, 'ps_require_csrf') < strpos($page, 'orpt_demand_save') && strpos($lib, 'DELETE FROM admin_immediate_demand WHERE id = ? AND org_id = ?') !== false && strpos($lib, 'WHERE id = ? AND org_id = ?') !== false);
check('the page checks access before it reads wages', strpos($page, 'orpt_access_decision') !== false && strpos($page, 'orpt_access_decision') < strpos($page, 'orpt_fetch_pending'));
check('a visitor with no session is sent to login', strpos($page, 'login_kiosk.php?err=session_expired') !== false && strpos($page, "\$_SESSION['user_id']") !== false);
check('the menu link is the admin outstanding report', strpos($lib, "'Outstanding report'") !== false && strpos($lib, "'outstanding_report.php'") !== false && strpos($lib, "'UserRole' => 20") !== false);
check('the view does not query the database', strpos($view, '->prepare(') === false && strpos($view, '->query(') === false && stripos($view, 'INSERT INTO') === false);
check('payroll pay tables are not named by the report', strpos($lib, 'payroll_finalized') === false && strpos($page, 'payroll_payments') === false && strpos($lib, 'payroll_masters') === false);

if ($fail > 0) {
    echo "$fail failed\n";
    exit(1);
}
echo "all passed\n";
exit(0);
