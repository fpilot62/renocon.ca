<?php
/**
 * Synthetic pay-date and available-funds suggestion checks. No live rows.
 * Run: php tests/payroll_sheet_suggest_test.php
 */
require_once __DIR__ . '/../engine/payroll_sheet_grid_lib.php';

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

function owe(string $key, int $user, string $start, float $amount, array $extra = []): array
{
    return array_merge([
        'key' => $key,
        'org_id' => 1,
        'scope_org_id' => 1,
        'sheet_row_id' => 11,
        'user_id' => $user,
        'site_id' => 2,
        'employee_name' => 'Example Person',
        'period_start' => $start,
        'period_end' => $start,
        'source_conflict' => false,
        'net_known' => true,
        'net' => $amount,
        'source_status' => 'unknown',
        'freshness' => ['aug_oct' => false],
        'reconciled' => ['derived' => 'no_system_payment', 'supersedes_stale_unpaid' => false],
        'balance_before' => ['state' => 'amount', 'amount' => $amount],
        'today_pay' => null,
        'today_effect' => 'unset',
        'party_class' => 'employee',
        'er_cpp_state' => 'none',
        'er_ei_state' => 'none',
        'er_other_state' => 'none',
        'gst_verified' => 0,
        'gst_invoice_principal' => null,
        'row_version' => 1,
        'basis' => 'net',
        'route' => 'unset',
        'actual_date' => '',
        'pay_on' => '',
        'planned_deposit' => 500,
    ], $extra);
}

function lineOf(array $suggestion, string $key): array
{
    foreach ($suggestion['lines'] as $line) {
        if ($line['key'] === $key) {
            return $line;
        }
    }
    return [];
}

$older = owe('a', 5, '2026-01-01', 80);
$newer = owe('b', 9, '2026-02-01', 40);
$before = [$older, $newer];
$zero = psr_suggest_payments($before, '0', 'oldest_first', '2026-11-20');
check('zero funding suggests nothing and does not spend reserves', $zero['ok'] && abs($zero['total_wage']) < 0.001 && abs($zero['total_funding']) < 0.001 && abs($zero['remaining_funds']) < 0.001 && abs(lineOf($zero, 'a')['suggested']) < 0.001 && $zero['posts_payment'] === false);
check('zero funding does not treat a planned deposit as extra cash', abs($zero['available_funds'] - 0) < 0.001 && abs($zero['planned_deposits_added']) < 0.001);
$small = psr_suggest_payments($before, '10', 'oldest_first', '2026-11-20');
check('small funding pays the oldest verified amount first and leaves the newer one', abs(lineOf($small, 'a')['suggested'] - 10) < 0.001 && abs(lineOf($small, 'b')['suggested']) < 0.001 && abs($small['shortfall'] - 110) < 0.001 && $small['total_funding'] <= 10);
check('suggestion preview does not replace the typed proposal', $before[0]['today_pay'] === null && $before[0]['actual_date'] === '');

$tieA = owe('low', 5, '2026-03-01', 10);
$tieB = owe('high', 9, '2026-03-01', 10);
$tie = psr_suggest_payments([$tieB, $tieA], '0.01', 'equal_same_date', '2026-11-21');
check('equal date tie gives the extra cent to the lowest person id', abs(lineOf($tie, 'low')['suggested'] - 0.01) < 0.001 && abs(lineOf($tie, 'high')['suggested']) < 0.001 && abs($tie['total_wage'] - 0.01) < 0.001);

$costly = owe('old-cost', 5, '2026-01-01', 80, ['er_cpp_state' => 'amount', 'er_cpp_amount' => 50]);
$later = owe('later', 9, '2026-04-01', 20);
$shortReserve = psr_suggest_payments([$later, $costly], '40', 'oldest_first', '2026-11-22');
check('insufficient cost reserve does not skip the older period', abs(lineOf($shortReserve, 'old-cost')['suggested']) < 0.001 && abs(lineOf($shortReserve, 'later')['suggested']) < 0.001 && strpos(lineOf($shortReserve, 'old-cost')['reason'], 'cost reserve') !== false && $shortReserve['claims_complete_fit'] === false && $shortReserve['total_funding'] <= 40);

$unknown = owe('unk', 5, '2026-05-01', 80, ['er_cpp_state' => 'unknown']);
$unknownPlan = psr_suggest_payments([$unknown], '500', 'oldest_first', '2026-11-22');
check('unknown employer cost stays incomplete and is not a 20 percent estimate', !$unknownPlan['claims_complete_fit'] && $unknownPlan['incomplete'] === true && empty(lineOf($unknownPlan, 'unk')['eligible']) && strpos(lineOf($unknownPlan, 'unk')['reason'], 'unknown') !== false && strpos(lineOf($unknownPlan, 'unk')['reason'], '20') === false && $unknownPlan['total_reserve'] === null);

$recovery = owe('recover', 5, '2026-01-01', 80, ['today_pay' => -1066, 'today_effect' => 'recovery']);
$open = owe('open', 8, '2026-01-15', 25);
$recoveryPlan = psr_suggest_payments([$recovery, $open], '100', 'oldest_first', '2026-11-23');
check('planned recovery is not added to available funds', abs($recoveryPlan['available_funds'] - 100) < 0.001 && abs($recoveryPlan['recovery_added_to_funds']) < 0.001 && empty(lineOf($recoveryPlan, 'recover')['eligible']) && abs(lineOf($recoveryPlan, 'open')['suggested'] - 25) < 0.001);
$recoveryUpdates = psr_suggestion_updates([$recovery, $open], $recoveryPlan);
check('existing recovery draft is kept', $recoveryUpdates[0]['changed'] === false && abs($recoveryUpdates[0]['proposed_amount'] + 1066) < 0.001 && $recovery['today_pay'] === -1066);

$paid = owe('paid', 5, '2026-01-01', 0);
$loose = owe('loose', 0, '2026-01-01', 80);
$conflict = owe('conflict', 5, '2026-01-01', 80, ['source_conflict' => true]);
$blankNet = owe('net', 5, '2026-01-01', 80, ['net_known' => false, 'net' => null, 'balance_before' => ['state' => 'incomplete', 'amount' => null]]);
$stale = owe('stale', 5, '2026-08-01', 80, ['source_status' => 'not_paid', 'freshness' => ['aug_oct' => true]]);
$excluded = psr_suggest_payments([$paid, $loose, $conflict, $blankNet, $stale], '1000', 'oldest_first', '2026-11-24');
check('fully paid, unresolved, conflict, unknown net, and stale August unpaid are excluded', empty(lineOf($excluded, 'paid')['eligible']) && strpos(lineOf($excluded, 'paid')['reason'], 'fully paid') !== false && strpos(lineOf($excluded, 'loose')['reason'], 'not linked') !== false && strpos(lineOf($excluded, 'conflict')['reason'], 'conflict') !== false && strpos(lineOf($excluded, 'net')['reason'], 'unknown') !== false && strpos(lineOf($excluded, 'stale')['reason'], 'August') !== false && abs($excluded['total_wage']) < 0.001);

$dup1 = owe('sheet:2', 5, '2026-06-01', 80);
$dup2 = owe('sheet:1', 5, '2026-06-01', 80);
$dup = psr_suggest_payments([$dup1, $dup2], '200', 'oldest_first', '2026-11-25');
check('the same person and period is not paid twice', abs($dup['total_wage'] - 80) < 0.001 && abs(lineOf($dup, 'sheet:1')['suggested'] - 80) < 0.001 && abs(lineOf($dup, 'sheet:2')['suggested']) < 0.001);

$manual = owe('manual', 5, '2026-01-01', 80, ['today_pay' => 33]);
$manualPlan = psr_suggest_payments([$manual], '0', 'oldest_first', '2026-11-26');
$manualUpdates = psr_suggestion_updates([$manual], $manualPlan);
check('an existing manual draft is preserved when the suggestion pays nothing', $manual['today_pay'] === 33 && $manualUpdates[0]['changed'] === false && abs($manualUpdates[0]['proposed_amount'] - 33) < 0.001);
$replace = psr_suggest_payments([$manual], '80', 'oldest_first', '2026-11-26');
$replaceUpdates = psr_suggestion_updates([$manual], $replace);
check('a positive suggestion stays a preview until the update list is applied', $manual['today_pay'] === 33 && $replaceUpdates[0]['changed'] === true && abs($replaceUpdates[0]['previous'] - 33) < 0.001 && abs($replaceUpdates[0]['proposed_amount'] - 80) < 0.001 && $replaceUpdates[0]['actual_date'] === '');

$first = psr_dated_round($small, ['a' => 1, 'b' => 1]);
$second = psr_dated_round(psr_suggest_payments($before, '10', 'oldest_first', '2026-12-01'), ['a' => 1, 'b' => 1]);
$history = psr_append_dated_round([], $first);
$again = psr_append_dated_round($history['history'], $first);
$both = psr_append_dated_round($again['history'], $second);
check('dated rounds stay distinct and the same suggestion is saved once', count($both['history']) === 2 && $both['history'][0]['plan_date'] === '2026-11-20' && $both['history'][1]['plan_date'] === '2026-12-01' && $again['already'] === true && $both['posts_payment'] === false && $both['approved'] === false);

$badFunds = psr_suggest_payments([$older], 'nope', 'oldest_first', '2026-11-20');
$neg = psr_suggest_payments([$older], -5, 'oldest_first', '2026-11-20');
$nan = psr_suggest_payments([$older], NAN, 'oldest_first', '2026-11-20');
$blankDate = psr_suggest_payments([$older], '10', 'oldest_first', '');
check('negative, blank, and malformed funds and a missing pay date are rejected', !$badFunds['ok'] && !$neg['ok'] && !$nan['ok'] && !$blankDate['ok'] && $older['actual_date'] === '');

$versions = psr_suggestion_versions([$older, $newer]);
$stored = [
    'org_id' => 1,
    'available_funds' => $small['available_funds'],
    'policy' => $small['policy'],
    'plan_date' => $small['plan_date'],
    'versions' => $versions,
    'fingerprint' => psr_suggestion_fingerprint($small, $versions),
];
$fresh = psr_revalidate_suggestion($stored, [$older, $newer], 1);
$staleRow = $newer;
$staleRow['row_version'] = 2;
$stale = psr_revalidate_suggestion($stored, [$older, $staleRow], 1);
$wrongOrg = psr_revalidate_suggestion($stored, [$older, $newer], 2);
$movedFunds = $stored;
$movedFunds['available_funds'] = 999;
$moved = psr_revalidate_suggestion($movedFunds, [$older, $newer], 1);
check('confirmation accepts the same preview and rejects a stale row, another company, and changed funds', $fresh['ok'] && $fresh['suggestion']['posts_payment'] === false && !$stale['ok'] && !$wrongOrg['ok'] && !$moved['ok']);

$foreign = owe('other', 5, '2026-01-01', 80, ['org_id' => 2, 'scope_org_id' => 1]);
$foreignPlan = psr_suggest_payments([$foreign], '80', 'oldest_first', '2026-11-20');
check('a row from another company is not suggested', empty(lineOf($foreignPlan, 'other')['eligible']) && abs($foreignPlan['total_wage']) < 0.001);

$invoice = owe('invoice', 7, '2026-07-01', 40, [
    'party_class' => 'contractor',
    'gst_verified' => 1,
    'gst_invoice_principal' => 100,
]);
$invoicePlan = psr_suggest_payments([$invoice], '105', 'oldest_first', '2026-11-27');
$invoiceLine = lineOf($invoicePlan, 'invoice');
check('a verified contractor invoice is suggested apart from the wage and the reserve does not reduce it', $invoiceLine['base_kind'] === 'verified_contractor_invoice' && abs($invoiceLine['suggested'] - 100) < 0.001 && abs($invoiceLine['cost_reserve'] - 5) < 0.001 && abs($invoiceLine['wage_payable'] - 40) < 0.001 && $invoicePlan['claims_complete_fit'] === true && $invoice['gst_verified'] === 1);
$guess = owe('guess', 7, '2026-07-01', 7500, [
    'party_class' => 'contractor',
    'gst_verified' => 0,
    'gst_invoice_principal' => 7500,
    'today_pay' => 7500,
    'today_effect' => 'payout',
]);
$guessPlan = psr_suggest_payments([$guess], '8000', 'oldest_first', '2026-11-27');
check('an unverified contractor estimate is not confirmed just to make a suggestion', empty(lineOf($guessPlan, 'guess')['eligible']) && $guess['gst_verified'] === 0 && $guessPlan['claims_complete_fit'] === false && strpos(lineOf($guessPlan, 'guess')['reason'], 'not a verified invoice') !== false);

$src = file_get_contents(__DIR__ . '/../engine/payroll_sheet_grid_lib.php');
$suggestFn = substr($src, strpos($src, 'function psr_suggest_payments'), strpos($src, 'function psr_suggestion_fingerprint') - strpos($src, 'function psr_suggest_payments'));
$saveFn = substr($src, strpos($src, 'function psr_save_suggestion_round'));
$html = file_get_contents(__DIR__ . '/../payroll_sheet_review.php');
check('suggestions do not estimate statutory tax or post payroll', strpos($suggestFn, '0.20') === false && strpos($suggestFn, 'payroll_payments') === false && strpos($saveFn, 'payroll_payments') === false && strpos($saveFn, 'payroll_finalized') === false && strpos($saveFn, 'gst_verified') === false);
check('the page asks for a pay date and funds and still requires an admin and a token', strpos($html, 'name="plan_date"') !== false && strpos($html, 'Available funds') !== false && strpos($html, 'confirm_suggestion') !== false && strpos($html, 'ps_require_csrf') !== false && strpos($html, 'ps_require_payroll_admin') !== false);

if ($fail > 0) {
    echo "$fail failed\n";
    exit(1);
}
echo "all passed\n";
exit(0);
