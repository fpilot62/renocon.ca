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
check('payment status page is admin gated and saves a batch', strpos($page, '$sess_level > 20') !== false && strpos($page, 'save_payment_status') !== false && strpos($page, 'undo_last') !== false);
check('historical pay date is accepted', pcr_valid_payment_date('2026-09-05') === '2026-09-05');
check('ancient pay date is rejected', pcr_valid_payment_date('1990-01-01') === null);

if ($fail > 0) {
    echo "$fail failed\n";
    exit(1);
}
echo "all passed\n";
exit(0);
