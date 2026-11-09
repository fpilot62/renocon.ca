<?php
/**
 * Synthetic spreadsheet checks. No live employee rows.
 * Run: php tests/payroll_sheet_grid_test.php
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

$system = [[
    'id' => 10, 'org_id' => 1, 'user_id' => 5, 'site_id' => 2,
    'employee_name' => 'Pat Example', 'store_name' => 'North',
    'period_start' => '2026-09-01', 'period_end' => '2026-09-15',
    'gross' => 100.0, 'net' => 80.0, 'net_known' => true, 'paid' => 80.0,
    'status' => 'paid', 'active' => 1,
]];
$older = [
    'id' => 3, 'org_id' => 1, 'row_kind' => 'employee_record', 'user_id' => 0, 'site_id' => 0,
    'employee_name' => 'Pat Example', 'store_name' => 'North',
    'period_start' => '2024-08-01', 'period_end' => '2024-08-15', 'period_month' => 8,
    'gross' => 40, 'net' => null, 'reported_status' => 'paid',
    'flags' => ['net_unknown'], 'row_version' => 1, 'amount_basis' => 'unset', 'pay_route' => 'unset',
    'party_class' => 'unanswered', 'proposed_amount' => null, 'actual_confirmed' => 0,
];
$stale = [
    'id' => 4, 'org_id' => 1, 'row_kind' => 'employee_record', 'user_id' => 5, 'site_id' => 2,
    'employee_name' => 'Pat Example', 'store_name' => 'North',
    'period_start' => '2026-09-01', 'period_end' => '2026-09-15', 'period_month' => 9,
    'gross' => 100, 'net' => 80, 'reported_status' => 'not_paid',
    'flags' => ['conflicting_explicit_payment_status'], 'source_json' => json_encode(['reported_status' => 'conflict']),
    'row_version' => 2, 'amount_basis' => 'net', 'pay_route' => 'cash', 'party_class' => 'employee',
    'proposed_amount' => 10, 'actual_confirmed' => 0,
];
$grid = psr_build_review_grid($system, [$older, $stale], [], [['id' => 5, 'name' => 'Pat Example'], ['id' => 8, 'name' => 'Pat Example']], 1);
$byKey = [];
foreach ($grid as $row) {
    $byKey[$row['key']] = $row;
}
check('historical row stays separate from later system period', isset($byKey['sheet:3']) && $byKey['sheet:3']['source'] === 'sheet');
check('overlapping linked period is not double counted', isset($byKey['sheet:4']) && $byKey['sheet:4']['source'] === 'combined' && !isset($byKey['payroll:10']) && abs($byKey['sheet:4']['gross'] - 100) < 0.001);
check('ambiguous exact name is not linked', !empty($byKey['sheet:3']['candidate']['ambiguous']) && (int)$byKey['sheet:3']['user_id'] === 0);
check('August source unpaid-or-paid is not a current balance', !empty($byKey['sheet:3']['freshness']['aug_oct']) && $byKey['sheet:3']['freshness']['authoritative'] === false && $byKey['sheet:3']['balance_before']['amount'] === null);
check('source paid with unknown net is not zero and not a repayment', $byKey['sheet:3']['source_status'] === 'paid' && $byKey['sheet:3']['net'] === null && $byKey['sheet:3']['balance_before']['state'] === 'incomplete');
check('stale unpaid does not override system paid', $byKey['sheet:4']['source_status'] === 'not_paid' && !empty($byKey['sheet:4']['reconciled']['supersedes_stale_unpaid']) && $byKey['sheet:4']['reconciled']['derived'] === 'system_paid');
check('source conflict flag stays visible', !empty($byKey['sheet:4']['source_conflict']));
check('combined balance uses system net minus system paid', abs($byKey['sheet:4']['balance_before']['amount'] - 0) < 0.001);
$after = psr_balance_after($byKey['sheet:4']['balance_before'], -40, 'net');
check('negative today-pay is a recovery and does not change wage balance', $after['recovery'] === -40.0 && abs($after['amount'] - 0) < 0.001 && $after['wage_changed'] === false);
$gst = psr_unverified_gst(['party_class' => 'contractor', 'gst_verified' => 0], 100);
check('contractor GST estimate is unverified and not a wage', $gst['state'] === 'unverified_estimate' && abs($gst['amount'] - 5) < 0.001 && $gst['verified'] === false);
$cash = psr_unverified_gst(['party_class' => 'employee', 'pay_route' => 'cash', 'gst_verified' => 1], 100);
check('cash employee wages do not get GST', $cash['state'] === 'not_applied');
$plan = psr_plan_approval_plan(['proposed_amount' => 25, 'user_id' => 5, 'site_id' => 2, 'period_start' => '2026-09-01', 'period_end' => '2026-09-15']);
check('approving a proposal does not pay or create wages', $plan['posts_payment'] === false && $plan['creates_wage'] === false && $plan['marks_paid'] === false);
$loose = psr_plan_approval_plan(['proposed_amount' => 25, 'user_id' => 0, 'site_id' => 0]);
check('unlinked proposal stays unallocated', $loose['effect'] === 'unallocated' && $loose['posts_payment'] === false);
$filtered = psr_grid_filter($grid, ['pay_status' => 'paid']);
check('filter keeps source paid and does not treat unknown net as zero', count($filtered) === 1 && $filtered[0]['net'] === null);
$totals = psr_grid_totals($filtered);
check('filtered total is incomplete when net is unknown', $totals['complete'] === false && $totals['net_unknown'] === 1 && $totals['net_known_sum'] === null);
$save = psr_grid_save_changes([
    'sheet:3' => ['row_version' => 1, 'net' => '', 'gross' => '40', 'today_pay' => '', 'employee_name' => 'Pat Example', 'store_name' => 'North', 'user_id' => '0', 'site_id' => '0', 'period_start' => '2024-08-01', 'period_end' => '2024-08-15', 'basis' => 'unset', 'route' => 'unset', 'party_class' => 'unanswered'],
], $byKey);
check('unchanged draft is not written', $save['ok'] && $save['changes'] === [] && $save['writes_payroll'] === false);
$staleSave = psr_grid_save_changes([
    'sheet:3' => ['row_version' => 9, 'net' => '10', 'gross' => '40', 'today_pay' => ''],
], $byKey);
check('stale version blocks the batch', !$staleSave['ok'] && $staleSave['changes'] === []);
$foreign = psr_build_review_grid([[
    'id' => 77, 'org_id' => 2, 'user_id' => 5, 'site_id' => 2, 'employee_name' => 'Pat Example', 'store_name' => 'North',
    'period_start' => '2026-01-01', 'period_end' => '2026-01-15', 'gross' => 999, 'net' => 999, 'net_known' => true, 'paid' => 0, 'active' => 1,
]], [], [], [], 1);
check('another company line is excluded', $foreign === []);
$page = psr_grid_page($grid, 1, 10);
check('pagination does not return hidden rows', count($page['rows']) === min(10, count($grid)) && $page['total'] === count($grid));
$src = file_get_contents(__DIR__ . '/../engine/payroll_sheet_grid_lib.php');
$apply = substr($src, strpos($src, 'function psr_apply_grid_drafts'), strpos($src, 'function psr_grid_plan_id') - strpos($src, 'function psr_apply_grid_drafts'));
check('draft save does not write payroll tables', strpos($apply, 'payroll_finalized') === false && strpos($apply, 'payroll_payments') === false);
$approve = substr($src, strpos($src, 'function psr_approve_plan_lines'));
check('proposal approval does not call payment posting', strpos($approve, 'pcr_apply_payment') === false);
$html = file_get_contents(__DIR__ . '/../payroll_sheet_review.php');
check('spreadsheet is the first review surface', strpos($html, 'Pay spreadsheet') < strpos($html, 'All-period total'));
check('pay date and available funds replace a today-pay heading', strpos($html, 'name="plan_date"') !== false && strpos($html, 'available_funds') !== false && strpos($html, 'suggest_payments') !== false && strpos($html, '>Today pay<') === false);
check('visible selection and draft preview exist', strpos($html, 'selectVisible') !== false && strpos($html, 'preview_grid_save') !== false && strpos($html, 'preview_plan_rows') !== false);
check('source conflict and August freshness are shown', strpos($html, 'Source conflict') !== false && strpos($html, 'Aug–Oct needs reconciliation') !== false);
check('staff audit source was not invented', !is_file(__DIR__ . '/../staff_audit.php'));

if ($fail > 0) {
    echo "$fail failed\n";
    exit(1);
}
echo "all passed\n";
exit(0);
