<?php
/**
 * Spreadsheet view for Sheet Review.
 * Draft edits stay off payroll until a separate, confirmed obligation approval.
 * Approving a pay proposal never records an actual payment.
 */
require_once __DIR__ . '/payroll_sheet_review_lib.php';

function psr_ensure_grid_columns(PDO $pdo): void
{
    psr_ensure($pdo);
    pcr_add_column($pdo, 'payroll_sheet_rows', 'deleted_at', 'DATETIME NULL');
    pcr_add_column($pdo, 'payroll_sheet_rows', 'row_version', 'INT NOT NULL DEFAULT 1');
    pcr_add_column($pdo, 'payroll_sheet_plan_lines', 'period_start', 'DATE NULL');
    pcr_add_column($pdo, 'payroll_sheet_plan_lines', 'period_end', 'DATE NULL');
    pcr_add_column($pdo, 'payroll_sheet_plan_lines', 'line_effect', "VARCHAR(20) NOT NULL DEFAULT 'payout'");
    pcr_add_column($pdo, 'payroll_sheet_plan_lines', 'approved_at', 'DATETIME NULL');
    pcr_add_column($pdo, 'payroll_sheet_plan_lines', 'approved_by', 'INT NULL');
    pcr_add_column($pdo, 'payroll_sheet_plan_lines', 'row_version', 'INT NOT NULL DEFAULT 1');
}

function psr_flags_of($raw): array
{
    if (is_array($raw)) {
        return array_values($raw);
    }
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? array_values($decoded) : [];
}

function psr_source_conflict(array $row): bool
{
    $flags = psr_flags_of($row['flags'] ?? $row['flags_json'] ?? []);
    if (in_array('conflicting_explicit_payment_status', $flags, true)) {
        return true;
    }
    $src = $row['source_json'] ?? null;
    if (is_string($src)) {
        $src = json_decode($src, true);
    }
    if (is_array($src) && strtolower(trim((string)($src['reported_status'] ?? ''))) === 'conflict') {
        return true;
    }
    return strtolower(trim((string)($row['reported_status_raw'] ?? ''))) === 'conflict';
}

/** Sheet status is evidence. August–October is called out without inventing a year cutoff. */
function psr_status_freshness(array $row): array
{
    $month = null;
    if (isset($row['period_month']) && $row['period_month'] !== '' && $row['period_month'] !== null && is_numeric($row['period_month'])) {
        $month = (int)$row['period_month'];
    } else {
        $start = psr_valid_date($row['period_start'] ?? '');
        if ($start !== null) {
            $month = (int)substr($start, 5, 2);
        }
    }
    $augOct = in_array($month, [8, 9, 10], true);
    return [
        'authoritative' => false,
        'aug_oct' => $augOct,
        'needs_reconciliation' => $augOct || psr_source_conflict($row),
        'label' => $augOct
            ? 'August–October source status is evidence only. It is not the current unpaid balance. No year cutoff was assumed.'
            : 'Sheet payment status is source evidence, not a live certification of what is unpaid.',
    ];
}

function psr_system_paid_amount(array $lines): array
{
    $paid = 0.0;
    $known = true;
    $any = false;
    foreach ($lines as $ln) {
        if (!array_key_exists('paid', $ln) || $ln['paid'] === null) {
            $known = false;
            continue;
        }
        $any = true;
        $paid = round($paid + (float)$ln['paid'], 2);
    }
    return ['known' => $known && $any, 'amount' => ($known && $any) ? $paid : null, 'has_positive' => $known && $any && $paid > 0.009];
}

/**
 * Derived view. Source status is returned unchanged.
 * A confirmed system payment can supersede a stale source Unpaid.
 * Source Paid is not turned into a payment and is not treated as repayable.
 */
function psr_reconciled_status(string $sourceStatus, array $systemLines, bool $linked): array
{
    $sourceStatus = psr_normalize_status($sourceStatus);
    $paid = psr_system_paid_amount($systemLines);
    if (!$linked) {
        return [
            'source_status' => $sourceStatus,
            'derived' => 'unresolved',
            'certified_payable' => false,
            'supersedes_stale_unpaid' => false,
            'note' => 'Not linked to a confirmed person and period. Included for review. Not certified payable.',
        ];
    }
    if (!empty($paid['has_positive']) && $sourceStatus === 'not_paid') {
        return [
            'source_status' => 'not_paid',
            'derived' => 'system_paid',
            'certified_payable' => false,
            'supersedes_stale_unpaid' => true,
            'note' => 'Source still says unpaid. A confirmed system payment for this person and period supersedes that here. The source row was not changed.',
        ];
    }
    if ($sourceStatus === 'paid' && empty($paid['has_positive'])) {
        return [
            'source_status' => 'paid',
            'derived' => 'source_paid_evidence',
            'certified_payable' => false,
            'supersedes_stale_unpaid' => false,
            'note' => 'Source says paid. That is evidence only. It is not bank proof, and it was not turned into a payment. Missing net is not treated as an amount to collect again.',
        ];
    }
    if (!empty($paid['has_positive'])) {
        return [
            'source_status' => $sourceStatus,
            'derived' => 'system_paid',
            'certified_payable' => false,
            'supersedes_stale_unpaid' => false,
            'note' => 'The system payment amount is the reconciled paid figure.',
        ];
    }
    return [
        'source_status' => $sourceStatus,
        'derived' => 'no_system_payment',
        'certified_payable' => false,
        'supersedes_stale_unpaid' => false,
        'note' => 'No confirmed system payment amount for this linked period.',
    ];
}

function psr_balance_before($net, bool $netKnown, $paid, string $paidState, string $sourceStatus, bool $staleUnpaid): array
{
    $sourceStatus = psr_normalize_status($sourceStatus);
    if ($sourceStatus === 'paid' && $paidState !== 'amount') {
        return ['state' => 'incomplete', 'amount' => null, 'reason' => 'Source says paid, but no payment amount is confirmed. This is not shown as still owed and not shown as repaid.'];
    }
    if ($staleUnpaid && $paidState !== 'amount') {
        return ['state' => 'stale_source', 'amount' => null, 'reason' => 'Source unpaid is not the current balance.'];
    }
    if (!$netKnown || $net === null) {
        return ['state' => 'incomplete', 'amount' => null, 'reason' => 'Net is unknown. Balance is not zero.'];
    }
    if ($paidState === 'unknown') {
        return ['state' => 'incomplete', 'amount' => null, 'reason' => 'Paid amount is unknown. Balance is not zero.'];
    }
    $paidValue = $paidState === 'amount' ? (float)$paid : 0.0;
    $rem = round((float)$net - $paidValue, 2);
    if ($rem < -0.009) {
        return ['state' => 'conflict', 'amount' => null, 'reason' => 'Paid is above known net. No negative balance was created.'];
    }
    return ['state' => 'amount', 'amount' => $rem];
}

function psr_today_effect($amount): string
{
    $parsed = psr_parse_amount($amount);
    if (!$parsed['ok'] || $parsed['value'] === null) {
        return 'unset';
    }
    if ($parsed['value'] < -0.009) {
        return 'recovery';
    }
    if ($parsed['value'] > 0.009) {
        return 'payout';
    }
    return 'zero';
}

function psr_balance_after(array $before, $todayAmount, string $basis): array
{
    $effect = psr_today_effect($todayAmount);
    if ($effect === 'recovery') {
        return [
            'state' => $before['state'],
            'amount' => $before['amount'],
            'wage_changed' => false,
            'recovery' => psr_parse_amount($todayAmount)['value'],
            'reason' => 'A negative today-pay is a pending recovery. It is not a payout, not a payroll deduction, and not money already received or deposited.',
        ];
    }
    if ($effect === 'unset' || $effect === 'zero') {
        return ['state' => $before['state'], 'amount' => $before['amount'], 'wage_changed' => false, 'recovery' => null, 'reason' => ''];
    }
    if ($before['state'] !== 'amount' || $before['amount'] === null) {
        return ['state' => 'incomplete', 'amount' => null, 'wage_changed' => false, 'recovery' => null, 'reason' => 'Today-pay is a proposal. The balance after it is incomplete because the balance before it is not a known amount.'];
    }
    if (!in_array($basis, ['net', 'gross'], true)) {
        return ['state' => 'incomplete', 'amount' => null, 'wage_changed' => false, 'recovery' => null, 'reason' => 'Set gross or net before the projected balance can use today-pay. No basis was assumed.'];
    }
    $preview = psr_balance_preview([
        'amount_basis' => $basis,
        'proposed_amount' => $todayAmount,
        'pay_route' => 'cash',
        'party_class' => 'employee',
        'ee_deduction_state' => $basis === 'gross' ? 'unknown' : 'none',
    ], true, (float)$before['amount']);
    if ($preview['preview_balance']['state'] !== 'amount') {
        return ['state' => 'incomplete', 'amount' => null, 'wage_changed' => false, 'recovery' => null, 'reason' => $preview['wage_reduction']['reason']];
    }
    return [
        'state' => 'amount',
        'amount' => $preview['preview_balance']['amount'],
        'wage_changed' => false,
        'recovery' => null,
        'reason' => 'Projected only. Today-pay is not an actual payment.',
    ];
}

function psr_unverified_gst(array $row, $todayAmount): array
{
    $verified = psr_gst($row);
    if ($verified['state'] === 'amount') {
        return $verified + ['verified' => true];
    }
    $party = strtolower(trim((string)($row['party_class'] ?? 'unanswered')));
    $effect = psr_today_effect($todayAmount);
    if ($party !== 'contractor' || $effect !== 'payout') {
        return ['state' => 'not_applied', 'amount' => null, 'verified' => false, 'reason' => $verified['reason']];
    }
    $base = psr_parse_amount($todayAmount);
    return [
        'state' => 'unverified_estimate',
        'amount' => round((float)$base['value'] * 0.05, 2),
        'verified' => false,
        'reason' => 'Unverified 5% estimate on a contractor proposal. Not a wage, not confirmed tax, and not subtracted from the wage balance.',
    ];
}

function psr_name_candidates(string $name, array $directory): array
{
    $key = psr_norm_name($name);
    if ($key === '') {
        return [];
    }
    $hits = [];
    foreach ($directory as $person) {
        if (psr_norm_name($person['name'] ?? '') === $key) {
            $hits[] = (int)$person['id'];
        }
    }
    return array_values(array_unique($hits));
}

function psr_overlap_lines(array $row, array $systemLines): array
{
    $uid = (int)($row['user_id'] ?? 0);
    $sid = (int)($row['site_id'] ?? 0);
    if ($uid <= 0 || $sid <= 0) {
        return [];
    }
    $out = [];
    foreach ($systemLines as $ln) {
        if ((int)($ln['org_id'] ?? 0) !== (int)($row['org_id'] ?? $ln['org_id'] ?? 0) && isset($row['org_id'], $ln['org_id'])) {
            if ((int)$ln['org_id'] !== (int)$row['org_id']) {
                continue;
            }
        }
        if ((int)$ln['user_id'] === $uid && (int)$ln['site_id'] === $sid && psr_periods_overlap($ln, $row)) {
            $out[] = $ln;
        }
    }
    return $out;
}

function psr_sum_known_gross(array $lines): array
{
    $sum = 0.0;
    $unknown = 0;
    foreach ($lines as $ln) {
        if (!isset($ln['gross']) || $ln['gross'] === null) {
            $unknown++;
        } else {
            $sum = round($sum + (float)$ln['gross'], 2);
        }
    }
    return ['amount' => $unknown === count($lines) ? null : $sum, 'unknown' => $unknown];
}

function psr_build_review_grid(array $systemLines, array $sheetRows, array $planLines, array $directory, int $orgId): array
{
    $used = [];
    $rows = [];
    foreach ($sheetRows as $sheet) {
        if (!empty($sheet['deleted_at'])) {
            continue;
        }
        if (($sheet['row_kind'] ?? 'employee_record') !== '' && ($sheet['row_kind'] ?? '') !== 'employee_record') {
            continue;
        }
        if ((int)($sheet['org_id'] ?? $orgId) !== $orgId) {
            continue;
        }
        $sheet['org_id'] = $orgId;
        $overlap = psr_overlap_lines($sheet, $systemLines);
        $linked = $overlap !== [];
        foreach ($overlap as $ln) {
            $used[(int)$ln['id']] = true;
        }
        $fresh = psr_status_freshness($sheet);
        $view = psr_reconciled_status((string)($sheet['reported_status'] ?? 'unknown'), $overlap, $linked);
        $sysGross = psr_sum_known_gross($overlap);
        $sysPaid = psr_system_paid_amount($overlap);
        $sheetNet = psr_parse_amount($sheet['net'] ?? null);
        $sheetGross = psr_parse_amount($sheet['gross'] ?? null);
        $useSystem = $linked;
        $netKnown = false;
        $net = null;
        $gross = null;
        if ($useSystem) {
            $netKnown = true;
            $netSum = 0.0;
            foreach ($overlap as $ln) {
                if (empty($ln['net_known']) || $ln['net'] === null) {
                    $netKnown = false;
                    $netSum = null;
                    break;
                }
                $netSum = round($netSum + (float)$ln['net'], 2);
            }
            $net = $netKnown ? $netSum : null;
            $gross = $sysGross['amount'];
        } else {
            $netKnown = $sheetNet['ok'] && $sheetNet['value'] !== null;
            $net = $netKnown ? $sheetNet['value'] : null;
            $gross = ($sheetGross['ok'] && $sheetGross['value'] !== null) ? $sheetGross['value'] : null;
        }
        $paidState = 'none_confirmed';
        $paid = null;
        if ($useSystem && $sysPaid['known']) {
            $paidState = 'amount';
            $paid = $sysPaid['amount'];
        } elseif (!empty($sheet['actual_confirmed'])) {
            $actual = psr_parse_amount($sheet['actual_amount'] ?? null);
            if ($actual['ok'] && $actual['value'] !== null) {
                $paidState = 'amount';
                $paid = $actual['value'];
            }
        }
        $sourceStatus = psr_normalize_status($sheet['reported_status'] ?? 'unknown');
        $before = psr_balance_before($net, $netKnown, $paid, $paidState, $sourceStatus, $fresh['aug_oct'] && $sourceStatus === 'not_paid' && empty($view['supersedes_stale_unpaid']));
        $today = $sheet['proposed_amount'] ?? null;
        foreach ($planLines as $plan) {
            if ((int)($plan['row_id'] ?? 0) === (int)$sheet['id'] && array_key_exists('proposed_amount', $plan)) {
                $today = $plan['proposed_amount'];
            }
        }
        $basis = (string)($sheet['amount_basis'] ?? 'unset');
        $after = psr_balance_after($before, $today, $basis);
        $names = psr_name_candidates((string)($sheet['employee_name'] ?? ''), $directory);
        $candidate = null;
        if ((int)($sheet['user_id'] ?? 0) <= 0) {
            if (count($names) === 1) {
                $candidate = ['user_id' => $names[0], 'ambiguous' => false, 'reason' => 'Exact name match in this company. Not linked until you confirm it.'];
            } elseif (count($names) > 1) {
                $candidate = ['user_id' => null, 'ambiguous' => true, 'reason' => 'More than one person in this company has this exact name. Not merged.'];
            }
        }
        $rows[] = [
            'key' => 'sheet:' . (int)$sheet['id'],
            'sheet_row_id' => (int)$sheet['id'],
            'finalized_ids' => array_map(static function ($ln) { return (int)$ln['id']; }, $overlap),
            'user_id' => (int)($sheet['user_id'] ?? 0),
            'site_id' => (int)($sheet['site_id'] ?? 0),
            'employee_name' => (string)($sheet['employee_name'] ?? ''),
            'store_name' => (string)($sheet['store_name'] ?? ''),
            'period_start' => (string)($sheet['period_start'] ?? ''),
            'period_end' => (string)($sheet['period_end'] ?? ''),
            'period_month' => $sheet['period_month'] ?? null,
            'gross' => $gross,
            'sheet_gross' => ($sheetGross['ok'] ? $sheetGross['value'] : null),
            'net' => $net,
            'net_known' => $netKnown,
            'sheet_net' => ($sheetNet['ok'] ? $sheetNet['value'] : null),
            'paid' => $paid,
            'paid_state' => $paidState,
            'source_status' => $sourceStatus,
            'source_conflict' => psr_source_conflict($sheet),
            'freshness' => $fresh,
            'reconciled' => $view,
            'balance_before' => $before,
            'today_pay' => psr_parse_amount($today)['ok'] ? psr_parse_amount($today)['value'] : null,
            'today_effect' => psr_today_effect($today),
            'balance_after' => $after,
            'route' => (string)($sheet['pay_route'] ?? 'unset'),
            'basis' => $basis,
            'party_class' => (string)($sheet['party_class'] ?? 'unanswered'),
            'gst' => psr_unverified_gst($sheet, $today),
            'candidate' => $candidate,
            'provenance' => $linked
                ? 'System period plus sheet evidence. Sheet amounts were not added again.'
                : 'Historical sheet row. Not a system payroll line.',
            'source' => $linked ? 'combined' : 'sheet',
            'active' => null,
            'row_version' => (int)($sheet['row_version'] ?? 1),
            'actual_amount' => $sheet['actual_amount'] ?? null,
            'actual_date' => $sheet['actual_date'] ?? '',
            'actual_method' => $sheet['actual_method'] ?? '',
            'actual_confirmed' => !empty($sheet['actual_confirmed']) ? 1 : 0,
            'certified_payable' => false,
        ];
    }
    foreach ($systemLines as $ln) {
        if ((int)($ln['org_id'] ?? 0) !== $orgId || !empty($used[(int)$ln['id']])) {
            continue;
        }
        $netKnown = !empty($ln['net_known']) && $ln['net'] !== null;
        $paidState = array_key_exists('paid', $ln) && $ln['paid'] !== null ? 'amount' : 'unknown';
        $before = psr_balance_before($ln['net'] ?? null, $netKnown, $ln['paid'] ?? null, $paidState, 'unknown', false);
        $today = null;
        $basis = 'unset';
        foreach ($planLines as $plan) {
            if ((int)($plan['user_id'] ?? 0) === (int)$ln['user_id']
                && (int)($plan['site_id'] ?? 0) === (int)$ln['site_id']
                && psr_periods_overlap($plan, $ln)) {
                $today = $plan['proposed_amount'] ?? null;
                $basis = (string)($plan['amount_basis'] ?? 'unset');
            }
        }
        $rows[] = [
            'key' => 'payroll:' . (int)$ln['id'],
            'sheet_row_id' => 0,
            'finalized_ids' => [(int)$ln['id']],
            'user_id' => (int)$ln['user_id'],
            'site_id' => (int)$ln['site_id'],
            'employee_name' => (string)($ln['employee_name'] ?? ''),
            'store_name' => (string)($ln['store_name'] ?? ''),
            'period_start' => (string)($ln['period_start'] ?? ''),
            'period_end' => (string)($ln['period_end'] ?? ''),
            'period_month' => null,
            'gross' => $ln['gross'] ?? null,
            'sheet_gross' => null,
            'net' => $netKnown ? $ln['net'] : null,
            'net_known' => $netKnown,
            'sheet_net' => null,
            'paid' => $paidState === 'amount' ? $ln['paid'] : null,
            'paid_state' => $paidState,
            'source_status' => '',
            'source_conflict' => false,
            'freshness' => ['authoritative' => true, 'aug_oct' => false, 'needs_reconciliation' => false, 'label' => 'System payroll line.'],
            'reconciled' => [
                'source_status' => '',
                'derived' => ($paidState === 'amount' && (float)$ln['paid'] > 0.009) ? 'system_paid' : 'no_system_payment',
                'certified_payable' => false,
                'supersedes_stale_unpaid' => false,
                'note' => 'System payroll line. No sheet status was overwritten.',
            ],
            'balance_before' => $before,
            'today_pay' => psr_parse_amount($today)['ok'] ? psr_parse_amount($today)['value'] : null,
            'today_effect' => psr_today_effect($today),
            'balance_after' => psr_balance_after($before, $today, $basis),
            'route' => 'unset',
            'basis' => $basis,
            'party_class' => 'unanswered',
            'gst' => ['state' => 'not_applied', 'amount' => null, 'verified' => false, 'reason' => 'No contractor invoice on this system line.'],
            'candidate' => null,
            'provenance' => 'System payroll.',
            'source' => 'system',
            'active' => $ln['active'] ?? null,
            'row_version' => 1,
            'actual_amount' => null,
            'actual_date' => '',
            'actual_method' => '',
            'actual_confirmed' => 0,
            'certified_payable' => false,
        ];
    }
    usort($rows, static function ($a, $b) {
        $store = strcmp((string)$a['store_name'], (string)$b['store_name']);
        if ($store !== 0) {
            return $store;
        }
        $name = strcmp((string)$a['employee_name'], (string)$b['employee_name']);
        if ($name !== 0) {
            return $name;
        }
        return strcmp((string)$a['period_start'], (string)$b['period_start']);
    });
    return $rows;
}

function psr_grid_filter(array $rows, array $filter): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!empty($filter['store']) && stripos((string)$row['store_name'], (string)$filter['store']) === false && (int)$row['site_id'] !== (int)$filter['store']) {
            continue;
        }
        if (!empty($filter['person']) && stripos((string)$row['employee_name'], (string)$filter['person']) === false && (int)$row['user_id'] !== (int)$filter['person']) {
            continue;
        }
        if (!empty($filter['year'])) {
            $year = substr((string)$row['period_start'], 0, 4);
            if ($year !== (string)$filter['year']) {
                continue;
            }
        }
        if (!empty($filter['period_start']) && !empty($filter['period_end']) && !psr_periods_overlap($row, $filter)) {
            continue;
        }
        if (!empty($filter['source']) && $filter['source'] !== 'all' && $row['source'] !== $filter['source']) {
            continue;
        }
        if (!empty($filter['pay_status']) && $filter['pay_status'] !== 'all') {
            $want = $filter['pay_status'];
            $got = $want === 'system_paid' ? ($row['reconciled']['derived'] ?? '') : ($row['source_status'] ?? '');
            if ($want === 'conflict') {
                if (empty($row['source_conflict'])) {
                    continue;
                }
            } elseif ($got !== $want) {
                continue;
            }
        }
        if (!empty($filter['unresolved'])) {
            $open = ($row['user_id'] ?? 0) <= 0 || !empty($row['source_conflict']) || empty($row['net_known']) || !empty($row['freshness']['needs_reconciliation']) || (($row['reconciled']['derived'] ?? '') === 'unresolved');
            if (!$open) {
                continue;
            }
        }
        if (!empty($filter['route']) && $filter['route'] !== 'all' && ($row['route'] ?? '') !== $filter['route']) {
            continue;
        }
        if (($filter['active'] ?? 'all') === 'active' && (int)($row['active'] ?? 1) === 0) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

function psr_grid_page(array $rows, int $page, int $perPage): array
{
    $perPage = max(10, min(50, $perPage));
    $pages = max(1, (int)ceil(count($rows) / $perPage));
    $page = max(1, min($pages, $page));
    $offset = ($page - 1) * $perPage;
    return ['page' => $page, 'pages' => $pages, 'per_page' => $perPage, 'total' => count($rows), 'rows' => array_slice($rows, $offset, $perPage)];
}

function psr_grid_totals(array $rows): array
{
    $gross = 0.0;
    $net = 0.0;
    $paid = 0.0;
    $before = 0.0;
    $grossKnown = 0;
    $netUnknown = 0;
    $paidUnknown = 0;
    $beforeKnown = 0;
    $conflicts = 0;
    $unresolved = 0;
    foreach ($rows as $row) {
        if ($row['gross'] !== null) {
            $gross = round($gross + (float)$row['gross'], 2);
            $grossKnown++;
        }
        if (!empty($row['net_known']) && $row['net'] !== null) {
            $net = round($net + (float)$row['net'], 2);
        } else {
            $netUnknown++;
        }
        if (($row['paid_state'] ?? '') === 'amount' && $row['paid'] !== null) {
            $paid = round($paid + (float)$row['paid'], 2);
        } else {
            $paidUnknown++;
        }
        if (($row['balance_before']['state'] ?? '') === 'amount' && $row['balance_before']['amount'] !== null) {
            $before = round($before + (float)$row['balance_before']['amount'], 2);
            $beforeKnown++;
        }
        if (!empty($row['source_conflict'])) {
            $conflicts++;
        }
        if (($row['reconciled']['derived'] ?? '') === 'unresolved') {
            $unresolved++;
        }
    }
    return [
        'rows' => count($rows),
        'gross_known_sum' => $grossKnown ? $gross : null,
        'gross_missing' => count($rows) - $grossKnown,
        'net_known_sum' => (count($rows) - $netUnknown) ? $net : null,
        'net_unknown' => $netUnknown,
        'paid_known_sum' => (count($rows) - $paidUnknown) ? $paid : null,
        'paid_not_confirmed' => $paidUnknown,
        'balance_known_sum' => $beforeKnown ? $before : null,
        'balance_incomplete' => count($rows) - $beforeKnown,
        'source_conflicts' => $conflicts,
        'unresolved' => $unresolved,
        'complete' => $netUnknown === 0 && $paidUnknown === 0,
    ];
}

function psr_plan_approval_plan(array $line): array
{
    $amount = psr_parse_amount($line['proposed_amount'] ?? $line['today_pay'] ?? null);
    $effect = psr_today_effect($amount['ok'] ? $amount['value'] : null);
    $linked = (int)($line['user_id'] ?? 0) > 0 && (int)($line['site_id'] ?? 0) > 0 && psr_valid_date($line['period_start'] ?? '') && psr_valid_date($line['period_end'] ?? '');
    return [
        'posts_payment' => false,
        'creates_wage' => false,
        'marks_paid' => false,
        'effect' => $linked ? $effect : 'unallocated',
        'amount' => $amount['ok'] ? $amount['value'] : null,
        'note' => $linked
            ? 'Approving this proposal does not record a payment and does not create wages.'
            : 'This proposal is not tied to a confirmed person, store, and period. It stays unallocated and unpaid.',
    ];
}

function psr_grid_save_changes(array $postedRows, array $currentByKey): array
{
    $changes = [];
    $blocked = [];
    foreach ($postedRows as $key => $post) {
        if (!isset($currentByKey[$key])) {
            $blocked[] = ['key' => $key, 'reason' => 'That row is not on this page or not in this company.'];
            continue;
        }
        $cur = $currentByKey[$key];
        if ((int)($post['row_version'] ?? -1) !== (int)($cur['row_version'] ?? 1)) {
            $blocked[] = ['key' => $key, 'reason' => 'Someone saved this row after you opened it. Nothing in this batch was saved.'];
            continue;
        }
        $net = psr_parse_amount($post['net'] ?? null);
        $gross = psr_parse_amount($post['gross'] ?? null);
        $today = psr_parse_amount($post['today_pay'] ?? null);
        $actual = psr_parse_amount($post['actual_amount'] ?? null);
        if (!$net['ok'] || !$gross['ok'] || !$today['ok'] || !$actual['ok']) {
            $blocked[] = ['key' => $key, 'reason' => 'An amount is not a number. Blank stays unknown. Zero must be typed as 0.'];
            continue;
        }
        $nextGross = array_key_exists('gross', $post) ? $gross['value'] : ($cur['sheet_gross'] ?? null);
        $nextNet = array_key_exists('net', $post) ? $net['value'] : ($cur['sheet_net'] ?? null);
        $same = (int)($cur['sheet_row_id'] ?? 0) > 0
            && (string)($cur['employee_name'] ?? '') === substr(trim((string)($post['employee_name'] ?? $cur['employee_name'])), 0, 160)
            && (string)($cur['store_name'] ?? '') === substr(trim((string)($post['store_name'] ?? $cur['store_name'])), 0, 160)
            && (int)($cur['user_id'] ?? 0) === (int)($post['user_id'] ?? 0)
            && (int)($cur['site_id'] ?? 0) === (int)($post['site_id'] ?? 0)
            && (string)($cur['period_start'] ?? '') === (string)psr_valid_date($post['period_start'] ?? $cur['period_start'])
            && (string)($cur['period_end'] ?? '') === (string)psr_valid_date($post['period_end'] ?? $cur['period_end'])
            && ($cur['sheet_gross'] ?? null) === $nextGross
            && ($cur['sheet_net'] ?? null) === $nextNet
            && ($cur['today_pay'] ?? null) === $today['value']
            && (string)($cur['basis'] ?? 'unset') === (in_array(($post['basis'] ?? ''), ['gross', 'net'], true) ? $post['basis'] : 'unset')
            && (string)($cur['route'] ?? 'unset') === (in_array(($post['route'] ?? ''), ['cash', 'adp'], true) ? $post['route'] : 'unset')
            && (string)($cur['party_class'] ?? 'unanswered') === (in_array(($post['party_class'] ?? ''), ['employee', 'contractor'], true) ? $post['party_class'] : 'unanswered')
            && ($cur['actual_amount'] ?? null) === $actual['value']
            && (string)($cur['actual_date'] ?? '') === (string)psr_valid_date($post['actual_date'] ?? '')
            && (string)($cur['actual_method'] ?? '') === (string)psr_actual_method($post['actual_method'] ?? '')
            && (int)($cur['actual_confirmed'] ?? 0) === (!empty($post['actual_confirmed']) ? 1 : 0)
            && empty($post['delete']);
        if ((int)($cur['sheet_row_id'] ?? 0) === 0 && $today['value'] === null && empty($post['delete'])) {
            continue;
        }
        if ($same) {
            continue;
        }
        $changes[] = [
            'key' => $key,
            'sheet_row_id' => (int)($cur['sheet_row_id'] ?? 0),
            'user_id' => (int)($post['user_id'] ?? 0),
            'site_id' => (int)($post['site_id'] ?? 0),
            'employee_name' => substr(trim((string)($post['employee_name'] ?? $cur['employee_name'])), 0, 160),
            'store_name' => substr(trim((string)($post['store_name'] ?? $cur['store_name'])), 0, 160),
            'period_start' => psr_valid_date($post['period_start'] ?? $cur['period_start']),
            'period_end' => psr_valid_date($post['period_end'] ?? $cur['period_end']),
            'gross' => $nextGross,
            'net' => $nextNet,
            'today_pay' => $today['value'],
            'basis' => in_array(($post['basis'] ?? ''), ['gross', 'net'], true) ? $post['basis'] : 'unset',
            'route' => in_array(($post['route'] ?? ''), ['cash', 'adp'], true) ? $post['route'] : 'unset',
            'party_class' => in_array(($post['party_class'] ?? ''), ['employee', 'contractor'], true) ? $post['party_class'] : 'unanswered',
            'actual_amount' => $actual['value'],
            'actual_date' => psr_valid_date($post['actual_date'] ?? ''),
            'actual_method' => psr_actual_method($post['actual_method'] ?? ''),
            'actual_confirmed' => !empty($post['actual_confirmed']) ? 1 : 0,
            'delete' => !empty($post['delete']) && (int)($cur['sheet_row_id'] ?? 0) > 0,
            'row_version' => (int)$cur['row_version'],
        ];
    }
    return ['ok' => !$blocked, 'changes' => $blocked ? [] : $changes, 'blocked' => $blocked, 'writes_payroll' => false];
}

function psr_apply_grid_drafts(PDO $pdo, int $orgId, int $actor, array $changes): int
{
    psr_ensure_grid_columns($pdo);
    if (!$changes) {
        return 0;
    }
    $users = [];
    $sites = [];
    $uq = $pdo->prepare('SELECT id FROM users WHERE org_id = ?');
    $uq->execute([$orgId]);
    foreach ($uq->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $users[(int)$id] = true;
    }
    $sq = $pdo->prepare('SELECT SId FROM sites WHERE org_id = ?');
    $sq->execute([$orgId]);
    foreach ($sq->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $sites[(int)$id] = true;
    }
    $pdo->beginTransaction();
    try {
        $saved = 0;
        foreach ($changes as $ch) {
            if ($ch['user_id'] > 0 && empty($users[$ch['user_id']])) {
                throw new RuntimeException('That employee is not in this company. No row in this batch was saved.');
            }
            if ($ch['site_id'] > 0 && empty($sites[$ch['site_id']])) {
                throw new RuntimeException('That store is not in this company. No row in this batch was saved.');
            }
            if ($ch['sheet_row_id'] > 0) {
                if (!empty($ch['delete'])) {
                    $del = $pdo->prepare('UPDATE payroll_sheet_rows SET deleted_at = NOW(), row_version = row_version + 1 WHERE id = ? AND org_id = ? AND deleted_at IS NULL AND row_version = ?');
                    $del->execute([$ch['sheet_row_id'], $orgId, $ch['row_version']]);
                    if ($del->rowCount() < 1) {
                        throw new RuntimeException('A row changed before this save. Nothing in this batch was saved.');
                    }
                    psr_insert_revision($pdo, $orgId, 'row', $ch['sheet_row_id'], 'delete_draft', $actor, null, ['deleted' => true]);
                    $saved++;
                    continue;
                }
                $upd = $pdo->prepare(
                    'UPDATE payroll_sheet_rows SET employee_name=?, store_name=?, user_id=?, site_id=?, period_start=?, period_end=?,
                        gross=?, net=?, proposed_amount=?, amount_basis=?, pay_route=?, party_class=?,
                        actual_amount=?, actual_date=?, actual_method=?, actual_confirmed=?, row_version=row_version+1, updated_at=NOW()
                     WHERE id=? AND org_id=? AND deleted_at IS NULL AND row_version=?'
                );
                $upd->execute([
                    $ch['employee_name'], $ch['store_name'], $ch['user_id'] ?: null, $ch['site_id'] ?: null,
                    $ch['period_start'], $ch['period_end'], $ch['gross'], $ch['net'], $ch['today_pay'],
                    $ch['basis'], $ch['route'], $ch['party_class'], $ch['actual_amount'], $ch['actual_date'],
                    $ch['actual_method'], $ch['actual_confirmed'], $ch['sheet_row_id'], $orgId, $ch['row_version'],
                ]);
                if ($upd->rowCount() < 1) {
                    throw new RuntimeException('A row changed before this save. Nothing in this batch was saved.');
                }
                psr_insert_revision($pdo, $orgId, 'row', $ch['sheet_row_id'], 'grid_draft', $actor, null, $ch);
                $saved++;
            }
            if ($ch['today_pay'] !== null && empty($ch['delete'])) {
                $effect = psr_today_effect($ch['today_pay']);
                $effect = $effect === 'unset' ? 'unallocated' : $effect;
                $planId = psr_grid_plan_id($pdo, $orgId, $actor);
                $found = $pdo->prepare('SELECT id, approved_at FROM payroll_sheet_plan_lines WHERE org_id = ? AND row_id = ? ORDER BY id DESC LIMIT 1');
                $found->execute([$orgId, $ch['sheet_row_id'] ?: 0]);
                $existing = $ch['sheet_row_id'] > 0 ? $found->fetch(PDO::FETCH_ASSOC) : false;
                if ($existing && empty($existing['approved_at'])) {
                    $pdo->prepare(
                        'UPDATE payroll_sheet_plan_lines SET proposed_amount=?, amount_basis=?, pay_route=?, party_class=?, period_start=?, period_end=?, line_effect=?, user_id=?, site_id=?
                         WHERE id=? AND org_id=? AND approved_at IS NULL'
                    )->execute([
                        $ch['today_pay'], $ch['basis'], $ch['route'], $ch['party_class'], $ch['period_start'], $ch['period_end'],
                        $effect, $ch['user_id'] ?: null, $ch['site_id'] ?: null, (int)$existing['id'], $orgId,
                    ]);
                } elseif (!$existing) {
                    $pdo->prepare(
                        'INSERT INTO payroll_sheet_plan_lines
                            (org_id, plan_id, row_id, employee_name, user_id, site_id, proposed_amount, amount_basis, pay_route, party_class, period_start, period_end, line_effect, note, created_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
                    )->execute([
                        $orgId, $planId, $ch['sheet_row_id'] ?: null, $ch['employee_name'], $ch['user_id'] ?: null, $ch['site_id'] ?: null,
                        $ch['today_pay'], $ch['basis'], $ch['route'], $ch['party_class'], $ch['period_start'], $ch['period_end'],
                        $effect, 'Spreadsheet proposal. Not an actual payment.',
                    ]);
                }
            }
        }
        $pdo->commit();
        return $saved;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function psr_grid_plan_id(PDO $pdo, int $orgId, int $actor): int
{
    $st = $pdo->prepare("SELECT id FROM payroll_sheet_plans WHERE org_id = ? AND title = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$orgId, 'Spreadsheet proposals']);
    $id = (int)$st->fetchColumn();
    if ($id > 0) {
        return $id;
    }
    $pdo->prepare(
        'INSERT INTO payroll_sheet_plans (org_id, plan_date, title, available_amount, note, created_by, created_at)
         VALUES (?,?,?,?,?,?,NOW())'
    )->execute([$orgId, date('Y-m-d'), 'Spreadsheet proposals', null, 'Proposals typed in the spreadsheet. Not payments.', $actor]);
    return (int)$pdo->lastInsertId();
}

function psr_approve_plan_lines(PDO $pdo, int $orgId, int $actor, array $lineIds): array
{
    psr_ensure_grid_columns($pdo);
    $done = 0;
    $skipped = 0;
    $pdo->beginTransaction();
    try {
        foreach ($lineIds as $id) {
            $st = $pdo->prepare('SELECT * FROM payroll_sheet_plan_lines WHERE id = ? AND org_id = ? FOR UPDATE');
            $st->execute([(int)$id, $orgId]);
            $line = $st->fetch(PDO::FETCH_ASSOC);
            if (!$line) {
                throw new RuntimeException('A proposal is not in this company. Nothing was approved.');
            }
            $plan = psr_plan_approval_plan($line);
            if (!empty($plan['posts_payment']) || !empty($plan['creates_wage']) || !empty($plan['marks_paid'])) {
                throw new RuntimeException('A proposal tried to record a payment. Nothing was approved.');
            }
            if (!empty($line['approved_at'])) {
                $skipped++;
                continue;
            }
            $pdo->prepare('UPDATE payroll_sheet_plan_lines SET approved_at = NOW(), approved_by = ?, line_effect = ? WHERE id = ? AND org_id = ? AND approved_at IS NULL')
                ->execute([$actor, $plan['effect'], (int)$id, $orgId]);
            $done++;
        }
        $pdo->commit();
        return ['approved' => $done, 'already' => $skipped, 'payments_posted' => 0];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
