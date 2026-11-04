<?php
/**
 * Pure checks for the cheque register. No database.
 * Run: php tests/payroll_cheque_lib_test.php
 */
require_once __DIR__ . '/../engine/payroll_cheque_lib.php';
require_once __DIR__ . '/../engine/payroll_payment_status_lib.php';

$fail = 0;
function check($label, $cond): void {
    global $fail;
    if ($cond) {
        echo "ok  $label\n";
        return;
    }
    echo "FAIL $label\n";
    $fail++;
}

// 4 Nov 2026 series starts at 2611041, matching cheque No. 2611041 printed that day.
check('date floor 2026-11-04', pcr_date_floor('2026-11-04') === 2611040);
check('first number of the day', pcr_number_after(2611040, []) === 2611041);
check('voided number is not reused', pcr_number_after(2611040, [2611041]) === 2611042);
check('gap is filled only above the floor', pcr_number_after(2611040, [2611041, 2611042, 2611044]) === 2611043);

// Unpaid net of $114.60 is the cheque. A later run must not print the amount already paid.
$face = pcr_cheque_face_amount(150.00, 12.40, 8.00, 15.00, 0.0, false, 0.0);
check('unpaid face is net', abs($face - 114.60) < 0.001);
$partial = pcr_cheque_face_amount(150.00, 12.40, 8.00, 15.00, 50.0, false, 0.0);
check('partial face is remaining, not the amount already paid', abs($partial - 64.60) < 0.001);
$settled = pcr_cheque_face_amount(150.00, 12.40, 8.00, 15.00, 114.60, false, 0.0);
check('settled face is zero', $settled === 0.0);
$withVac = pcr_cheque_face_amount(100.00, 0, 0, 0, 0, true, 4.50);
check('vacation paid each period stays on the cheque face', abs($withVac - 104.50) < 0.001);
$noVac = pcr_cheque_face_amount(100.00, 0, 0, 0, 0, false, 4.50);
check('accrued vacation is not added to the cheque', abs($noVac - 100.00) < 0.001);

check('payable caps at remaining', abs(pcr_payable_amount(114.60, 0, 500) - 114.60) < 0.001);
check('second pay of a settled line is zero', pcr_payable_amount(114.60, 114.60, 114.60) === 0.0);
check('zero request pays nothing', pcr_payable_amount(114.60, 0, 0) === 0.0);
check('line remaining matches net minus paid', abs(pcr_line_remaining(150, 12.40, 8, 15, 10) - 104.60) < 0.001);

check('unknown method falls back', pcr_payment_method('wire') === 'E-Transfer');
check('cheque method kept', pcr_payment_method('Cheque') === 'Cheque');

check('paid shift blocks send-back', pcr_block_send_back(114.60, null) !== null);
check('issued cheque blocks send-back', pcr_block_send_back(0, ['status' => 'issued', 'cheque_number' => 2611041]) !== null);
check('void cheque does not block send-back', pcr_block_send_back(0, ['status' => 'void', 'cheque_number' => 2611041]) === null);
check('unpaid shift with no cheque can go back', pcr_block_send_back(0, null) === null);

$ledger = file_get_contents(__DIR__ . '/../payroll_master_ledger.php');
$docs = file_get_contents(__DIR__ . '/../generate_pay_documents.php');
check('employee select is separate from EFT', strpos($ledger, 'class="emp-select"') !== false && strpos($ledger, 'class="eft-badge') !== false && strpos($ledger, 'class="eft-check"') === false);
check('cheque confirm dialog is on the page', strpos($ledger, 'id="chequeConfirm"') !== false && strpos($ledger, 'Starting cheque number') !== false);
check('mark issued cheques paid is offered', strpos($ledger, 'mark_issued_cheques_paid') !== false);
check('documents record cheques only on POST', strpos($docs, '$record_cheques') !== false && strpos($docs, 'pcr_issue_cheque') !== false);
check('paystub YTD cutoff block is still present', strpos($docs, 'Year-to-date runs through the pay period') !== false);
check('period CPP override is still present', strpos($docs, 'pe_read_period_stats') !== false);

$full = pstat_payment_amount('paid', 114.60, 0);
check('paid in full uses remaining', abs($full['amount'] - 114.60) < 0.001 && $full['warning'] === null);
$again = pstat_payment_amount('paid', 0, 114.60);
check('paid in full of a settled row records nothing', $again['amount'] === 0.0);
$over = pstat_payment_amount('partial', 114.60, 200);
check('partial overpay warns and records nothing', $over['amount'] === 0.0 && $over['warning'] !== null);
$part = pstat_payment_amount('partial', 114.60, 40);
check('partial under remaining is kept', abs($part['amount'] - 40) < 0.001);
$leave = pstat_payment_amount('unpaid', 114.60, 40);
check('unpaid does not record the typed amount', $leave['amount'] === 0.0);
$alloc = pstat_allocate(100, [
    ['id' => 2, 'remaining' => 80, 'work_date' => '2026-09-10'],
    ['id' => 1, 'remaining' => 40, 'work_date' => '2026-09-02'],
]);
check('allocation is oldest first and stops at the request', $alloc[0]['id'] === 1 && abs($alloc[0]['amount'] - 40) < 0.001 && $alloc[1]['id'] === 2 && abs($alloc[1]['amount'] - 60) < 0.001);
$periods = pstat_list_periods('semi_monthly', '2024-01-01', '2026-11-04');
$haveSep = false;
foreach ($periods as $per) {
    if ($per['start'] === '2026-09-01' && $per['end'] === '2026-09-15') {
        $haveSep = true;
    }
}
check('historical Sep 1-15 2026 period is selectable', $haveSep);
$page = file_get_contents(__DIR__ . '/../payroll_payment_status.php');
check('payment status page is admin gated and saves a batch', strpos($page, '$sess_level > 20') !== false && strpos($page, 'save_payment_status') !== false && strpos($page, 'undo_last') !== false && strpos($page, 'Add name') !== false);
$blank = pstat_review_added(['name' => '', 'user_id' => 0, 'owed' => 0, 'status' => 'unpaid'], [], [], [3 => true], []);
check('blank add-name row is ignored', !empty($blank['skip']));
$names = ['former worker' => 9];
$users = [9 => 'Former Worker'];
$sites = [3 => true];
$onSheet = ['3_9' => true];
$already = pstat_review_added(['name' => 'Former Worker', 'user_id' => 0, 'site_id' => 3, 'owed' => 50, 'status' => 'paid', 'paid_date' => '2026-09-15'], $names, $users, $sites, $onSheet);
check('existing person already on the period is refused', empty($already['skip']) && !empty($already['warning']));
$fresh = pstat_review_added(['name' => 'Former Worker', 'user_id' => 0, 'site_id' => 3, 'owed' => 80, 'status' => 'partial', 'amount' => 30, 'paid_date' => '2026-09-15'], $names, $users, $sites, []);
check('typed name matches an existing employee', ($fresh['user_id'] ?? 0) === 9 && abs($fresh['pay_amount'] - 30) < 0.001);
$newbie = pstat_review_added(['name' => 'A New Person', 'user_id' => 0, 'site_id' => 3, 'owed' => 114.60, 'status' => 'paid', 'paid_date' => '2026-09-15'], $names, $users, $sites, []);
check('unknown name is a new person paid in full', ($newbie['user_id'] ?? 1) === 0 && ($newbie['new_name'] ?? '') === 'A New Person' && abs($newbie['pay_amount'] - 114.60) < 0.001);
$tooMuch = pstat_review_added(['name' => 'A New Person', 'user_id' => 0, 'site_id' => 3, 'owed' => 40, 'status' => 'partial', 'amount' => 90, 'paid_date' => '2026-09-15'], [], [], $sites, []);
check('new name cannot be paid more than owed', !empty($tooMuch['warning']) && empty($tooMuch['pay_amount']));
check('historical pay date is accepted', pcr_valid_payment_date('2026-09-05') === '2026-09-05');
check('ancient pay date is rejected', pcr_valid_payment_date('1990-01-01') === null);

$cashPay = [[
    'id' => 9,
    'payroll_id' => 44,
    'method' => 'Cash',
    'ref_no' => 'B3',
    'cheque_number' => null,
    'user_id' => 7,
    'site_id' => 2,
    'pay_period_start' => '2026-09-01',
    'pay_period_end' => '2026-09-15',
]];
$cashLine = ['line_id' => 1, 'payroll_id' => 0, 'user_id' => 7, 'site_id' => 2, 'work_date' => '2026-09-04'];
check('cash payment shows Cash even when the EFT flag is on', pcr_lines_payment_label([$cashLine], $cashPay, true) === 'Cash');
check('no recorded payment falls back to EFT', pcr_lines_payment_label([$cashLine], [], true) === 'EFT · no cheque');
check('no recorded payment and no EFT flag is blank', pcr_lines_payment_label([$cashLine], [], false) === '');
$otherPerson = ['line_id' => 2, 'payroll_id' => 0, 'user_id' => 8, 'site_id' => 2, 'work_date' => '2026-09-04'];
check('another employee does not inherit the cash payment', pcr_lines_payment_label([$otherPerson], $cashPay, false) === '');
$linked = ['line_id' => 3, 'payroll_id' => 44, 'user_id' => 7, 'site_id' => 2, 'work_date' => '2026-09-04'];
check('payment on the line payroll id is Cash', pcr_lines_payment_label([$linked], $cashPay, true) === 'Cash');
check('e-transfer label', pcr_payment_method_label('E-Transfer') === 'e-Transfer');
check('stored EFT method is not the no-cheque flag', pcr_payment_type_label([['method' => 'EFT']], true) === 'EFT');
check('cheque number from the register', pcr_payment_method_label('Cheque', 'CHQ-2611041', 2611041) === 'Cheque #2611041');
check('cheque number typed as the reference', pcr_payment_method_label('Cheque', '1042', null) === 'Cheque #1042');
check('batch reference is not shown as a cheque number', pcr_payment_method_label('Cheque', 'B12', null) === 'Cheque');
$mixed = pcr_payment_type_label([
    ['method' => 'Cash', 'ref_no' => 'B1'],
    ['method' => 'E-Transfer', 'ref_no' => ''],
    ['method' => 'Cash', 'ref_no' => 'B2'],
], false);
check('distinct methods stay in order', $mixed === 'Cash · e-Transfer');
check('reversed payment is ignored', pcr_payment_type_label([['method' => 'Cash', 'reversed_at' => '2026-09-16 00:00:00']], true) === 'EFT · no cheque');

$sep = pstat_requested_period(['period' => '2026-09-01|2026-09-15'], [], 'semi_monthly', '2024-01-01', '2026-11-04');
check('period query opens that window', $sep === ['2026-09-01', '2026-09-15']);
$stale = pstat_requested_period(
    ['period' => '2026-09-01|2026-09-15', 'from_date' => '2026-11-01', 'to_date' => '2026-11-15'],
    [],
    'semi_monthly',
    '2024-01-01',
    '2026-11-04'
);
check('period query wins over a stale from/to', $stale === ['2026-09-01', '2026-09-15']);
$fromTo = pstat_requested_period(['from_date' => '2026-09-16', 'to_date' => '2026-09-30'], [], 'semi_monthly', '2024-01-01', '2026-11-04');
check('from/to still opens that period', $fromTo === ['2026-09-16', '2026-09-30']);
$current = pstat_requested_period([], [], 'semi_monthly', '2024-01-01', '2026-11-04');
check('missing period opens the current window', $current === ['2026-11-01', '2026-11-15']);
$posted = pstat_requested_period(
    ['period' => '2026-09-01|2026-09-15'],
    ['period_start' => '2026-10-01', 'period_end' => '2026-10-15'],
    'semi_monthly',
    '2024-01-01',
    '2026-11-04'
);
check('posted period wins over the query', $posted === ['2026-10-01', '2026-10-15']);
$badPeriod = pstat_requested_period(['period' => '2026-09-02|2026-09-15'], [], 'semi_monthly', '2024-01-01', '2026-11-04');
check('a period that is not a pay window falls back to current', $badPeriod === ['2026-11-01', '2026-11-15']);

check('add-name method stays blank', pstat_added_method('') === '' && pstat_added_method('EFT') === '');
check('add-name keeps an explicit method', pstat_added_method('Cash') === 'Cash' && pstat_added_method(' Cheque ') === 'Cheque');

$timeCols = [
    ['Field' => 'id', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment', 'Type' => 'int(11)'],
    ['Field' => 'user_id', 'Null' => 'NO', 'Default' => null, 'Extra' => '', 'Type' => 'int(11)'],
    ['Field' => 'Remarks', 'Null' => 'NO', 'Default' => null, 'Extra' => '', 'Type' => 'varchar(255)'],
    ['Field' => 'note', 'Null' => 'YES', 'Default' => null, 'Extra' => '', 'Type' => 'varchar(255)'],
    ['Field' => 'active', 'Null' => 'NO', 'Default' => '1', 'Extra' => '', 'Type' => 'tinyint(1)'],
];
$timeFill = pstat_missing_required_values($timeCols, ['user_id' => 5]);
check('payroll_time Remarks is supplied as blank', array_key_exists('Remarks', $timeFill) && $timeFill['Remarks'] === '');
check('auto increment is not invented', !array_key_exists('id', $timeFill));
check('nullable note is not invented', !array_key_exists('note', $timeFill));
check('column with a default is not invented', !array_key_exists('active', $timeFill));
check('provided user id is not replaced', !array_key_exists('user_id', $timeFill));
$userCols = [
    ['Field' => 'Remarks', 'Null' => 'NO', 'Default' => null, 'Extra' => '', 'Type' => 'text'],
    ['Field' => 'hours', 'Null' => 'NO', 'Default' => null, 'Extra' => '', 'Type' => 'decimal(8,2)'],
];
$userFill = pstat_missing_required_values($userCols, []);
check('users Remarks is blank and a missing number is zero', ($userFill['Remarks'] ?? null) === '' && ($userFill['hours'] ?? null) === 0);

$statusLib = file_get_contents(__DIR__ . '/../engine/payroll_payment_status_lib.php');
check('new names are not marked EFT', strpos($statusLib, "'eft_no_cheque' => 0") !== false);
check('user insert sets Remarks to blank', strpos($statusLib, "'Remarks' => ''") !== false);
check('payment status reads the period query', strpos($page, 'pstat_requested_period') !== false);
$addSnippetAt = strpos($page, 'name="added[\'+idx+\'][method]"');
$addSnippet = $addSnippetAt === false ? '' : substr($page, $addSnippetAt, 240);
check('add-name row does not default the method to E-Transfer', strpos($addSnippet, '<option value="">Choose</option>') !== false && strpos($addSnippet, '<option>E-Transfer</option><option>Cheque') === false);
check('ledger uses recorded payment methods', strpos($ledger, 'pcr_lines_payment_label') !== false && strpos($ledger, 'class="pay-type"') !== false);

if ($fail > 0) {
    echo "$fail failed\n";
    exit(1);
}
echo "all passed\n";
exit(0);
