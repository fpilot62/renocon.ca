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
    pcr_add_column($pdo, 'payroll_sheet_plan_lines', 'pay_on', 'DATE NULL');
    pcr_add_column($pdo, 'payroll_sheet_plans', 'proposal_key', 'VARCHAR(64) NULL');
    pcr_add_column($pdo, 'payroll_sheet_plans', 'suggest_policy', 'VARCHAR(40) NULL');
}

function psr_parse_funds($raw): array
{
    if (is_float($raw) && is_nan($raw)) {
        return ['ok' => false, 'value' => null, 'error' => 'Available funds are not a number.'];
    }
    if (is_string($raw) && trim($raw) === '') {
        return ['ok' => false, 'value' => null, 'error' => 'Enter available funds in CAD.'];
    }
    $parsed = psr_parse_amount($raw);
    if (!$parsed['ok'] || $parsed['value'] === null) {
        return ['ok' => false, 'value' => null, 'error' => 'Available funds are not a number.'];
    }
    if (!is_finite((float)$parsed['value'])) {
        return ['ok' => false, 'value' => null, 'error' => 'Available funds are not a number.'];
    }
    if ($parsed['value'] < 0) {
        return ['ok' => false, 'value' => null, 'error' => 'Available funds cannot be negative.'];
    }
    return ['ok' => true, 'value' => round((float)$parsed['value'], 2), 'error' => ''];
}

function psr_suggest_policy_label(string $policy): string
{
    if ($policy === 'equal_same_date') {
        return 'Equal split when the period starts on the same date, oldest date first. Extra cents go to the lowest person id.';
    }
    return 'Oldest verified amount owing first. A partial payment is used when the funds run out.';
}

function psr_cents($amount): int
{
    return (int)round(((float)$amount) * 100);
}

function psr_from_cents(int $cents): float
{
    return round($cents / 100, 2);
}

/** Split $pot cents across caps. Extra cents go to the earliest indexes. Sum never exceeds the pot or any cap. */
function psr_split_cents(int $pot, array $caps): array
{
    $count = count($caps);
    $out = array_fill(0, $count, 0);
    if ($count === 0 || $pot <= 0) {
        return $out;
    }
    $room = 0;
    foreach ($caps as $cap) {
        $room += max(0, (int)$cap);
    }
    $pot = min($pot, $room);
    $base = intdiv($pot, $count);
    $extra = $pot % $count;
    foreach ($caps as $i => $cap) {
        $share = $base + ($i < $extra ? 1 : 0);
        $out[$i] = min(max(0, (int)$cap), $share);
    }
    $left = $pot - array_sum($out);
    while ($left > 0) {
        $moved = false;
        foreach ($out as $i => $value) {
            if ($left <= 0) {
                break;
            }
            if ($out[$i] < (int)$caps[$i]) {
                $out[$i]++;
                $left--;
                $moved = true;
            }
        }
        if (!$moved) {
            break;
        }
    }
    return $out;
}

function psr_verified_cost_reserve(array $row): array
{
    $parts = [
        'er_cpp' => psr_tri($row['er_cpp_state'] ?? 'unknown', $row['er_cpp_amount'] ?? null),
        'er_ei' => psr_tri($row['er_ei_state'] ?? 'unknown', $row['er_ei_amount'] ?? null),
        'er_other' => psr_tri($row['er_other_state'] ?? 'unknown', $row['er_other_amount'] ?? null),
    ];
    $reasons = [];
    $reserve = 0.0;
    foreach ($parts as $name => $part) {
        if ($part['state'] === 'unknown') {
            $reasons[] = str_replace('_', ' ', $name) . ' is unknown. No percentage was estimated.';
        } elseif ($part['state'] === 'amount') {
            $reserve = round($reserve + (float)$part['amount'], 2);
        }
    }
    $party = strtolower(trim((string)($row['party_class'] ?? 'unanswered')));
    $invoice = null;
    $verifiedGst = 0.0;
    if ($party === 'contractor') {
        if (empty($row['gst_verified'])) {
            $reasons[] = 'Contractor GST is not a verified invoice. The unverified estimate was not used, and gst_verified was not turned on.';
        } else {
            $gst = psr_gst($row);
            if (($gst['state'] ?? '') !== 'amount') {
                $reasons[] = 'Verified contractor GST is pending because the invoice principal is missing. No estimate was used.';
            } else {
                $verifiedGst = (float)$gst['amount'];
                $reserve = round($reserve + $verifiedGst, 2);
                $invoice = psr_parse_amount($row['gst_invoice_principal'] ?? null)['value'];
            }
        }
    }
    return [
        'complete' => !$reasons,
        'amount' => $reasons ? null : $reserve,
        'reasons' => $reasons,
        'verified_gst' => $verifiedGst,
        'invoice_principal' => $invoice,
    ];
}

function psr_suggestion_eligibility(array $row): array
{
    if (($row['today_effect'] ?? '') === 'recovery' || ((float)($row['today_pay'] ?? 0) < -0.009 && $row['today_pay'] !== null)) {
        return ['eligible' => false, 'reason' => 'A planned recovery is not cash available to assign, and it is not a payout.'];
    }
    if ((int)($row['user_id'] ?? 0) <= 0) {
        return ['eligible' => false, 'reason' => 'Person is not linked in this company.'];
    }
    if ((int)($row['site_id'] ?? 0) <= 0) {
        return ['eligible' => false, 'reason' => 'Store is not linked in this company.'];
    }
    if (psr_valid_date($row['period_start'] ?? '') === null || psr_valid_date($row['period_end'] ?? '') === null) {
        return ['eligible' => false, 'reason' => 'Period is missing.'];
    }
    if (!empty($row['source_conflict'])) {
        return ['eligible' => false, 'reason' => 'Source conflict is unresolved.'];
    }
    if (empty($row['net_known']) || $row['net'] === null) {
        return ['eligible' => false, 'reason' => 'Net is unknown. It was not treated as zero.'];
    }
    $fresh = $row['freshness'] ?? psr_status_freshness($row);
    $superseded = !empty($row['reconciled']['supersedes_stale_unpaid']);
    if (!empty($fresh['aug_oct']) && ($row['source_status'] ?? '') === 'not_paid' && !$superseded) {
        return ['eligible' => false, 'reason' => 'August–October source unpaid is not a current obligation.'];
    }
    if (($row['reconciled']['derived'] ?? '') === 'unresolved') {
        return ['eligible' => false, 'reason' => 'The row is unresolved and is not certified payable.'];
    }
    if (($row['balance_before']['state'] ?? '') !== 'amount' || $row['balance_before']['amount'] === null) {
        return ['eligible' => false, 'reason' => 'There is no known reconciled amount owing.'];
    }
    if ((float)$row['balance_before']['amount'] <= 0.009) {
        return ['eligible' => false, 'reason' => 'Already fully paid.'];
    }
    $costs = psr_verified_cost_reserve($row);
    if (!$costs['complete']) {
        return ['eligible' => false, 'reason' => 'Funding plan incomplete. ' . implode(' ', $costs['reasons']), 'incomplete' => true];
    }
    return ['eligible' => true, 'reason' => '', 'reserve' => (float)$costs['amount'], 'invoice' => $costs['invoice_principal']];
}

function psr_suggest_payments(array $rows, $fundsRaw, string $policy, $dateRaw): array
{
    $funds = psr_parse_funds($fundsRaw);
    $date = psr_valid_date($dateRaw);
    $empty = [
        'ok' => false,
        'posts_payment' => false,
        'approved' => false,
        'marks_paid' => false,
        'policy' => $policy,
        'policy_label' => psr_suggest_policy_label($policy === 'equal_same_date' ? 'equal_same_date' : 'oldest_first'),
        'plan_date' => $date,
        'available_funds' => $funds['ok'] ? $funds['value'] : null,
        'lines' => [],
        'total_wage' => 0.0,
        'total_reserve' => null,
        'total_funding' => null,
        'remaining_funds' => null,
        'shortfall' => null,
        'incomplete' => false,
        'claims_complete_fit' => false,
        'error' => '',
    ];
    if (!in_array($policy, ['oldest_first', 'equal_same_date'], true)) {
        $empty['error'] = 'Choose a suggestion policy.';
        return $empty;
    }
    if (!$funds['ok']) {
        $empty['error'] = $funds['error'];
        return $empty;
    }
    if ($date === null) {
        $empty['error'] = 'Choose a pay date. A missing imported date was not filled in.';
        return $empty;
    }
    $lines = [];
    $eligible = [];
    $incomplete = false;
    foreach ($rows as $row) {
        if ((int)($row['org_id'] ?? 0) > 0 && isset($row['scope_org_id']) && (int)$row['org_id'] !== (int)$row['scope_org_id']) {
            $lines[] = ['key' => $row['key'], 'eligible' => false, 'reason' => 'That row is not in this company.', 'suggested' => null];
            continue;
        }
        $decision = psr_suggestion_eligibility($row);
        $item = [
            'key' => $row['key'],
            'user_id' => (int)($row['user_id'] ?? 0),
            'site_id' => (int)($row['site_id'] ?? 0),
            'period_start' => (string)($row['period_start'] ?? ''),
            'period_end' => (string)($row['period_end'] ?? ''),
            'eligible' => !empty($decision['eligible']),
            'reason' => $decision['reason'],
            'suggested' => null,
            'wage_payable' => ($row['balance_before']['state'] ?? '') === 'amount' ? $row['balance_before']['amount'] : null,
            'cost_reserve' => $decision['reserve'] ?? null,
            'keeps_existing_draft' => array_key_exists('today_pay', $row) ? $row['today_pay'] : null,
            'pay_on' => $date,
            'employee_name' => (string)($row['employee_name'] ?? ''),
            'sheet_row_id' => (int)($row['sheet_row_id'] ?? 0),
            'row_version' => (int)($row['row_version'] ?? 1),
            'basis' => (string)($row['basis'] ?? 'unset'),
            'route' => (string)($row['route'] ?? 'unset'),
            'party_class' => (string)($row['party_class'] ?? 'unanswered'),
            'wage_unchanged_by_reserve' => true,
        ];
        if (!empty($decision['incomplete'])) {
            $incomplete = true;
        }
        if (!empty($decision['eligible'])) {
            $item['base_kind'] = $decision['invoice'] !== null ? 'verified_contractor_invoice' : 'wage';
        }
        $lines[] = $item;
        if (!empty($decision['eligible'])) {
            $item['index'] = count($lines) - 1;
            $item['reserve_cents'] = psr_cents($decision['reserve'] ?? 0);
            $base = $decision['invoice'] !== null ? (float)$decision['invoice'] : (float)$row['balance_before']['amount'];
            $item['base_kind'] = $decision['invoice'] !== null ? 'verified_contractor_invoice' : 'wage';
            $item['cap_cents'] = psr_cents($base);
            $item['owed_key'] = $item['user_id'] . '|' . $item['site_id'] . '|' . $item['period_start'] . '|' . $item['period_end'];
            $eligible[] = $item;
        }
    }
    usort($eligible, static function ($a, $b) {
        $period = strcmp($a['period_start'], $b['period_start']);
        if ($period !== 0) {
            return $period;
        }
        if ($a['user_id'] !== $b['user_id']) {
            return $a['user_id'] <=> $b['user_id'];
        }
        return strcmp((string)$a['key'], (string)$b['key']);
    });
    $fundsCents = psr_cents($funds['value']);
    $spentWage = 0;
    $spentReserve = 0;
    $assigned = [];
    $groups = [];
    foreach ($eligible as $item) {
        $groups[$item['period_start']][] = $item;
    }
    foreach ($groups as $group) {
        if ($fundsCents <= 0) {
            break;
        }
        if ($policy === 'oldest_first') {
            foreach ($group as $item) {
                if ($fundsCents <= 0) {
                    break;
                }
                $already = $assigned[$item['owed_key']] ?? 0;
                $room = max(0, $item['cap_cents'] - $already);
                $reserve = $item['reserve_cents'];
                if ($room <= 0) {
                    $lines[$item['index']]['suggested'] = 0.0;
                    $lines[$item['index']]['cost_reserve'] = 0.0;
                    $lines[$item['index']]['funding_required'] = 0.0;
                    $lines[$item['index']]['reason'] = 'This person and period is already covered by an earlier proposal in this suggestion. It was not paid twice.';
                    continue;
                }
                if ($fundsCents <= $reserve) {
                    $lines[$item['index']]['reason'] = $reserve > 0
                        ? 'Available funds do not cover the verified cost reserve for this older period. No later period was paid ahead of it.'
                        : 'No funds left after older verified amounts and their cost reserves.';
                    break 2;
                }
                $wage = min($room, $fundsCents - $reserve);
                $lines[$item['index']]['suggested'] = psr_from_cents($wage);
                $lines[$item['index']]['cost_reserve'] = psr_from_cents($reserve);
                $lines[$item['index']]['funding_required'] = psr_from_cents($wage + $reserve);
                $fundsCents -= ($wage + $reserve);
                $spentWage += $wage;
                $spentReserve += $reserve;
                $assigned[$item['owed_key']] = $already + $wage;
            }
            continue;
        }
        $caps = [];
        $meta = [];
        foreach ($group as $item) {
            $already = $assigned[$item['owed_key']] ?? 0;
            $room = max(0, $item['cap_cents'] - $already);
            $reserve = $item['reserve_cents'];
            $caps[] = $room;
            $meta[] = ['item' => $item, 'already' => $already, 'reserve' => $reserve];
        }
        $reserveNeed = 0;
        foreach ($meta as $one) {
            if ($one['item']['cap_cents'] > 0) {
                $reserveNeed += $one['reserve'];
            }
        }
        if ($reserveNeed >= $fundsCents) {
            $lines[$meta[0]['item']['index']]['reason'] = 'Available funds do not cover the verified cost reserve for this date. The equal split was not forced past the budget.';
            break;
        }
        $pot = $fundsCents - $reserveNeed;
        $shares = psr_split_cents($pot, $caps);
        foreach ($meta as $i => $one) {
            $wage = $shares[$i];
            $reserve = $wage > 0 ? $one['reserve'] : 0;
            $lines[$one['item']['index']]['suggested'] = psr_from_cents($wage);
            $lines[$one['item']['index']]['cost_reserve'] = psr_from_cents($reserve);
            $lines[$one['item']['index']]['funding_required'] = psr_from_cents($wage + $reserve);
            $fundsCents -= ($wage + $reserve);
            $spentWage += $wage;
            $spentReserve += $reserve;
            $assigned[$one['item']['owed_key']] = $one['already'] + $wage;
        }
    }
    foreach ($lines as $i => $line) {
        if (!empty($line['eligible']) && $line['suggested'] === null) {
            $lines[$i]['suggested'] = 0.0;
            $lines[$i]['cost_reserve'] = 0.0;
            $lines[$i]['funding_required'] = 0.0;
            if (($lines[$i]['reason'] ?? '') === '') {
                $lines[$i]['reason'] = 'No funds left after older verified amounts and their cost reserves.';
            }
        }
    }
    $need = 0;
    $seenOwed = [];
    foreach ($eligible as $item) {
        if (isset($seenOwed[$item['owed_key']])) {
            continue;
        }
        $seenOwed[$item['owed_key']] = true;
        $need += $item['cap_cents'] + $item['reserve_cents'];
    }
    $spent = $spentWage + $spentReserve;
    $shortfallCents = max(0, $need - $spent);
    $accounted = !$incomplete;
    $fits = $accounted && $shortfallCents === 0 && $spent <= psr_cents($funds['value']);
    return [
        'ok' => true,
        'posts_payment' => false,
        'approved' => false,
        'marks_paid' => false,
        'policy' => $policy,
        'policy_label' => psr_suggest_policy_label($policy),
        'plan_date' => $date,
        'available_funds' => $funds['value'],
        'lines' => $lines,
        'total_wage' => psr_from_cents($spentWage),
        'total_reserve' => $accounted ? psr_from_cents($spentReserve) : null,
        'total_funding' => $accounted ? psr_from_cents($spent) : null,
        'remaining_funds' => $accounted ? psr_from_cents($fundsCents) : null,
        'shortfall' => $accounted ? psr_from_cents($shortfallCents) : null,
        'incomplete' => $incomplete,
        'claims_complete_fit' => $fits,
        'error' => '',
        'reserves_do_not_reduce_wages' => true,
        'recovery_added_to_funds' => 0.0,
        'planned_deposits_added' => 0.0,
    ];
}

function psr_suggestion_fingerprint(array $suggestion, array $versions): string
{
    $slim = [
        'date' => $suggestion['plan_date'] ?? '',
        'funds' => $suggestion['available_funds'] ?? null,
        'policy' => $suggestion['policy'] ?? '',
        'versions' => $versions,
        'lines' => [],
    ];
    foreach ($suggestion['lines'] as $line) {
        $slim['lines'][] = [$line['key'] ?? '', $line['suggested'] ?? null];
    }
    return hash('sha256', json_encode($slim));
}

function psr_resolve_selection(array $post, array $pageIds, array $filteredIds): array
{
    $posted = [];
    foreach ((array)($post['row_ids'] ?? []) as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $posted[$id] = true;
        }
    }
    $page = [];
    foreach ($pageIds as $id) {
        $page[(int)$id] = true;
    }
    $allowed = [];
    foreach ($filteredIds as $id) {
        $allowed[(int)$id] = true;
    }
    $filteredMode = isset($post['select_all_filtered']) && (string)$post['select_all_filtered'] !== '' && (string)$post['select_all_filtered'] !== '0';
    $out = [];
    if ($filteredMode) {
        foreach ($filteredIds as $id) {
            $id = (int)$id;
            if ($id <= 0 || empty($allowed[$id])) {
                continue;
            }
            if (isset($page[$id]) && empty($posted[$id])) {
                continue;
            }
            $out[$id] = true;
        }
    } else {
        foreach ($posted as $id => $_) {
            if (!empty($allowed[$id])) {
                $out[$id] = true;
            }
        }
    }
    $ids = array_map('intval', array_keys($out));
    sort($ids);
    return $ids;
}

function psr_rows_for_selection(array $rows, array $selectedIds): array
{
    $want = [];
    foreach ($selectedIds as $id) {
        $want[(int)$id] = true;
    }
    $out = [];
    foreach ($rows as $row) {
        $id = (int)($row['sheet_row_id'] ?? 0);
        if ($id > 0 && !empty($want[$id])) {
            $out[] = $row;
        }
    }
    return $out;
}

function psr_approvals_panel(int $selectedCount, int $pageCount, int $filteredCount): array
{
    return [
        'button' => 'Approvals (' . $selectedCount . ' selected)',
        'selected' => $selectedCount,
        'page_rows' => $pageCount,
        'filtered_rows' => $filteredCount,
        'sections' => [
            [
                'key' => 'save_edits',
                'title' => '1. Save my edits',
                'explains' => 'Saves the amounts typed on this page as drafts.',
                'marks_paid' => false,
                'sends_money' => false,
                'needs_selection' => false,
                'rows' => $pageCount,
            ],
            [
                'key' => 'amounts_owed',
                'title' => '2. Approve amounts owed',
                'explains' => 'Approves gross and net for the selected rows. It does not mark anyone paid.',
                'marks_paid' => false,
                'sends_money' => false,
                'needs_selection' => true,
                'rows' => $selectedCount,
            ],
            [
                'key' => 'pay_run',
                'title' => '3. Approve this pay run',
                'explains' => 'Approves the payment plan for the selected rows. It does not mark anyone paid and does not send money.',
                'marks_paid' => false,
                'sends_money' => false,
                'needs_selection' => true,
                'rows' => $selectedCount,
            ],
        ],
    ];
}

function psr_pay_run_summary(?array $suggestion, int $selectedCount): array
{
    return [
        'rows' => $selectedCount,
        'proposed' => $suggestion['total_wage'] ?? null,
        'reserve' => $suggestion['total_reserve'] ?? null,
        'funding' => $suggestion['total_funding'] ?? null,
        'available' => $suggestion['available_funds'] ?? null,
        'remaining' => $suggestion['remaining_funds'] ?? null,
        'shortfall' => $suggestion['shortfall'] ?? null,
        'plan_date' => $suggestion['plan_date'] ?? '',
        'marks_paid' => false,
        'sends_money' => false,
        'posts_payment' => false,
    ];
}

function psr_suggestion_is_stale(array $previewVersions, array $currentVersions): bool
{
    foreach ($previewVersions as $key => $version) {
        if (!isset($currentVersions[$key]) || (int)$currentVersions[$key] !== (int)$version) {
            return true;
        }
    }
    return false;
}

function psr_suggestion_versions(array $rows): array
{
    $versions = [];
    foreach ($rows as $row) {
        $versions[(string)($row['key'] ?? '')] = (int)($row['row_version'] ?? 1);
    }
    return $versions;
}

function psr_suggestion_updates(array $rows, array $suggestion): array
{
    $byKey = [];
    foreach ($suggestion['lines'] ?? [] as $line) {
        $byKey[(string)($line['key'] ?? '')] = $line;
    }
    $updates = [];
    foreach ($rows as $row) {
        $line = $byKey[(string)($row['key'] ?? '')] ?? null;
        $existing = array_key_exists('today_pay', $row) ? $row['today_pay'] : null;
        $suggested = $line['suggested'] ?? null;
        $apply = $line && !empty($line['eligible']) && $suggested !== null && (float)$suggested > 0.009;
        $updates[] = [
            'key' => (string)($row['key'] ?? ''),
            'changed' => $apply,
            'sheet_row_id' => (int)($row['sheet_row_id'] ?? 0),
            'proposed_amount' => $apply ? (float)$suggested : $existing,
            'previous' => $existing,
            'pay_on' => $apply ? ($suggestion['plan_date'] ?? null) : ($row['pay_on'] ?? ''),
            'cost_reserve' => $apply ? ($line['cost_reserve'] ?? 0) : null,
            'employee_name' => (string)($row['employee_name'] ?? ''),
            'user_id' => (int)($row['user_id'] ?? 0),
            'site_id' => (int)($row['site_id'] ?? 0),
            'period_start' => $row['period_start'] ?? null,
            'period_end' => $row['period_end'] ?? null,
            'basis' => (string)($row['basis'] ?? 'unset'),
            'route' => (string)($row['route'] ?? 'unset'),
            'party_class' => (string)($row['party_class'] ?? 'unanswered'),
            'row_version' => (int)($row['row_version'] ?? 1),
            'actual_date' => (string)($row['actual_date'] ?? ''),
            'posts_payment' => false,
        ];
    }
    return $updates;
}

function psr_dated_round(array $suggestion, array $versions): array
{
    $lines = [];
    foreach ($suggestion['lines'] ?? [] as $line) {
        if ((float)($line['suggested'] ?? 0) > 0.009) {
            $lines[] = $line;
        }
    }
    return [
        'plan_date' => $suggestion['plan_date'] ?? '',
        'available_funds' => $suggestion['available_funds'] ?? null,
        'policy' => $suggestion['policy'] ?? '',
        'proposal_key' => psr_suggestion_fingerprint($suggestion, $versions),
        'lines' => $lines,
        'posts_payment' => false,
        'approved' => false,
        'marks_paid' => false,
    ];
}

function psr_append_dated_round(array $history, array $round): array
{
    $key = (string)($round['proposal_key'] ?? '');
    if ($key !== '') {
        foreach ($history as $old) {
            if ((string)($old['proposal_key'] ?? '') === $key) {
                return ['history' => $history, 'already' => true, 'posts_payment' => false, 'approved' => false, 'marks_paid' => false];
            }
        }
    }
    $history[] = $round;
    return ['history' => $history, 'already' => false, 'posts_payment' => false, 'approved' => false, 'marks_paid' => false];
}

function psr_revalidate_suggestion(array $stored, array $currentRows, int $scopeOrg): array
{
    if ((int)($stored['org_id'] ?? 0) !== $scopeOrg || $scopeOrg <= 0) {
        return ['ok' => false, 'error' => 'That suggestion is for another company. Nothing was saved.'];
    }
    $currentVersions = psr_suggestion_versions($currentRows);
    $previewVersions = is_array($stored['versions'] ?? null) ? $stored['versions'] : [];
    if (psr_suggestion_is_stale($previewVersions, $currentVersions)) {
        return ['ok' => false, 'error' => 'A row changed after the preview. Nothing was saved.'];
    }
    $again = psr_suggest_payments(
        $currentRows,
        $stored['available_funds'] ?? null,
        (string)($stored['policy'] ?? ''),
        $stored['plan_date'] ?? ''
    );
    if (empty($again['ok']) || psr_suggestion_fingerprint($again, $currentVersions) !== (string)($stored['fingerprint'] ?? '')) {
        return ['ok' => false, 'error' => 'The suggestion no longer matches the preview. Nothing was saved.'];
    }
    if (!empty($again['posts_payment']) || !empty($again['marks_paid']) || !empty($again['approved'])) {
        return ['ok' => false, 'error' => 'A suggestion tried to record a payment. Nothing was saved.'];
    }
    return ['ok' => true, 'suggestion' => $again, 'error' => ''];
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
            'reason' => 'A negative pay proposal is a pending recovery. It is not a payout, not a payroll deduction, and not money already received or deposited.',
        ];
    }
    if ($effect === 'unset' || $effect === 'zero') {
        return ['state' => $before['state'], 'amount' => $before['amount'], 'wage_changed' => false, 'recovery' => null, 'reason' => ''];
    }
    if ($before['state'] !== 'amount' || $before['amount'] === null) {
        return ['state' => 'incomplete', 'amount' => null, 'wage_changed' => false, 'recovery' => null, 'reason' => 'The pay proposal is not an actual payment. The balance after it is incomplete because the balance before it is not a known amount.'];
    }
    if (!in_array($basis, ['net', 'gross'], true)) {
        return ['state' => 'incomplete', 'amount' => null, 'wage_changed' => false, 'recovery' => null, 'reason' => 'Set gross or net before the projected balance can use the pay proposal. No basis was assumed.'];
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
        'reason' => 'Projected only. The pay proposal is not an actual payment.',
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

function psr_employee_label(array $person, bool $withStore): string
{
    $name = trim((string)($person['name'] ?? ''));
    $store = trim((string)($person['site_name'] ?? ''));
    if ($withStore && $store !== '') {
        return $name . ' - ' . $store;
    }
    return $name;
}

function psr_employees_for_store(array $employees, int $siteId): array
{
    if ($siteId <= 0) {
        return array_values($employees);
    }
    $out = [];
    foreach ($employees as $person) {
        if ((int)($person['site_id'] ?? 0) === $siteId) {
            $out[] = $person;
        }
    }
    return $out;
}

function psr_sites_named(string $storeName, array $sites): array
{
    $want = psr_norm_name($storeName);
    if ($want === '') {
        return [];
    }
    $hits = [];
    foreach ($sites as $site) {
        $nick = psr_norm_name($site['name'] ?? '');
        if ($nick !== '' && ($nick === $want || strpos($want, $nick) !== false || strpos($nick, $want) !== false)) {
            $hits[] = (int)$site['id'];
        }
    }
    return array_values(array_unique($hits));
}

function psr_link_choice(array $hits, int $scopeSite): array
{
    $byUser = [];
    foreach ($hits as $person) {
        $id = (int)($person['id'] ?? 0);
        $site = (int)($person['site_id'] ?? 0);
        if ($id <= 0 || $site <= 0) {
            continue;
        }
        $byUser[$id][$site] = $person;
    }
    if (!$byUser) {
        return ['status' => 'none', 'user_id' => 0, 'site_id' => 0, 'candidates' => []];
    }
    $candidates = [];
    foreach ($byUser as $sitesForUser) {
        foreach ($sitesForUser as $person) {
            $candidates[] = [
                'id' => (int)$person['id'],
                'name' => (string)$person['name'],
                'site_id' => (int)$person['site_id'],
                'site_name' => (string)($person['site_name'] ?? ''),
            ];
        }
    }
    if (count($byUser) === 1) {
        $sitesForUser = reset($byUser);
        if ($scopeSite > 0) {
            if (isset($sitesForUser[$scopeSite])) {
                $person = $sitesForUser[$scopeSite];
                return ['status' => 'suggested', 'user_id' => (int)$person['id'], 'site_id' => (int)$person['site_id'], 'site_name' => (string)($person['site_name'] ?? ''), 'candidates' => $candidates];
            }
            return ['status' => 'ambiguous', 'user_id' => 0, 'site_id' => 0, 'candidates' => $candidates];
        }
        if (count($sitesForUser) === 1) {
            $person = reset($sitesForUser);
            return ['status' => 'suggested', 'user_id' => (int)$person['id'], 'site_id' => (int)$person['site_id'], 'site_name' => (string)($person['site_name'] ?? ''), 'candidates' => $candidates];
        }
    } elseif ($scopeSite > 0) {
        $inStore = [];
        foreach ($byUser as $sitesForUser) {
            if (isset($sitesForUser[$scopeSite])) {
                $inStore[] = $sitesForUser[$scopeSite];
            }
        }
        if (count($inStore) === 1) {
            $person = $inStore[0];
            return ['status' => 'suggested', 'user_id' => (int)$person['id'], 'site_id' => (int)$person['site_id'], 'site_name' => (string)($person['site_name'] ?? ''), 'candidates' => $candidates];
        }
    }
    return ['status' => 'ambiguous', 'user_id' => 0, 'site_id' => 0, 'candidates' => $candidates];
}

function psr_suggest_link(array $row, array $employees, array $sites, int $filterSiteId): array
{
    $empty = [
        'status' => 'none',
        'user_id' => (int)($row['user_id'] ?? 0),
        'site_id' => (int)($row['site_id'] ?? 0),
        'site_name' => '',
        'saved' => false,
        'approved' => false,
        'marks_paid' => false,
        'candidates' => [],
        'reason' => '',
    ];
    $rowOrg = (int)($row['org_id'] ?? 0);
    $pool = [];
    foreach ($employees as $person) {
        if ($rowOrg > 0 && (int)($person['org_id'] ?? 0) > 0 && (int)$person['org_id'] !== $rowOrg) {
            continue;
        }
        if ($filterSiteId > 0 && (int)($person['site_id'] ?? 0) !== $filterSiteId) {
            continue;
        }
        $pool[] = $person;
    }
    if ((int)($row['user_id'] ?? 0) > 0 && (int)($row['site_id'] ?? 0) > 0) {
        $empty['status'] = 'saved';
        $empty['saved'] = true;
        $empty['reason'] = 'Already linked.';
        return $empty;
    }
    $scopeSite = $filterSiteId;
    if ($scopeSite <= 0) {
        $named = psr_sites_named((string)($row['store_name'] ?? ''), $sites);
        if (count($named) === 1) {
            $scopeSite = $named[0];
        }
    }
    $name = psr_norm_name($row['employee_name'] ?? '');
    if ($name === '') {
        if ($filterSiteId > 0 && (int)($row['site_id'] ?? 0) <= 0) {
            $empty['status'] = 'suggested';
            $empty['site_id'] = $filterSiteId;
            $empty['reason'] = 'Store defaulted from the filter. Not saved.';
        }
        return $empty;
    }
    $exact = [];
    foreach ($pool as $person) {
        if (psr_norm_name($person['name'] ?? '') === $name) {
            $exact[] = $person;
        }
    }
    if ($exact) {
        $choice = psr_link_choice($exact, $scopeSite);
        $choice['saved'] = false;
        $choice['approved'] = false;
        $choice['marks_paid'] = false;
        $choice['reason'] = $choice['status'] === 'suggested'
            ? 'Suggested from the name. Not saved.'
            : 'More than one employee matches. Nothing was selected.';
        return $choice;
    }
    if ($scopeSite <= 0) {
        return $empty;
    }
    $first = explode(' ', $name)[0] ?? '';
    $loose = [];
    foreach ($pool as $person) {
        if ((int)($person['site_id'] ?? 0) !== $scopeSite) {
            continue;
        }
        $personName = psr_norm_name($person['name'] ?? '');
        $personFirst = explode(' ', $personName)[0] ?? '';
        $firstHit = strlen($first) >= 3 && $personFirst === $first;
        $fuzzyHit = strlen($name) >= 5 && strlen($personName) >= 5 && levenshtein($name, $personName) <= 2;
        if ($firstHit || $fuzzyHit) {
            $loose[] = $person;
        }
    }
    if (!$loose) {
        if ($filterSiteId > 0 && (int)($row['site_id'] ?? 0) <= 0) {
            $empty['status'] = 'suggested';
            $empty['site_id'] = $filterSiteId;
            $empty['user_id'] = 0;
            $empty['reason'] = 'Store defaulted from the filter. Not saved.';
        }
        return $empty;
    }
    $choice = psr_link_choice($loose, $scopeSite);
    $choice['saved'] = false;
    $choice['approved'] = false;
    $choice['marks_paid'] = false;
    $choice['reason'] = $choice['status'] === 'suggested'
        ? 'Suggested from the first name or a close spelling in this store. Not saved.'
        : 'More than one close name in this store. Nothing was selected.';
    return $choice;
}

function psr_accept_link_changes(array $rows, array $employees, array $sites, int $filterSiteId): array
{
    $changes = [];
    foreach ($rows as $row) {
        if ((int)($row['sheet_row_id'] ?? 0) <= 0) {
            continue;
        }
        $link = psr_suggest_link($row, $employees, $sites, $filterSiteId);
        if (($link['status'] ?? '') !== 'suggested' || (int)($link['user_id'] ?? 0) <= 0 || (int)($link['site_id'] ?? 0) <= 0) {
            continue;
        }
        if ((int)($row['user_id'] ?? 0) > 0 && (int)($row['site_id'] ?? 0) > 0) {
            continue;
        }
        $storeName = trim((string)($row['store_name'] ?? ''));
        if ($storeName === '' && !empty($link['site_name'])) {
            $storeName = (string)$link['site_name'];
        }
        $changes[] = [
            'key' => (string)($row['key'] ?? ''),
            'sheet_row_id' => (int)$row['sheet_row_id'],
            'user_id' => (int)$link['user_id'],
            'site_id' => (int)$link['site_id'],
            'employee_name' => substr(trim((string)($row['employee_name'] ?? '')), 0, 160),
            'store_name' => substr($storeName, 0, 160),
            'period_start' => $row['period_start'] ?? null,
            'period_end' => $row['period_end'] ?? null,
            'gross' => $row['sheet_gross'] ?? null,
            'net' => $row['sheet_net'] ?? null,
            'today_pay' => $row['today_pay'] ?? null,
            'pay_on' => $row['pay_on'] ?? null,
            'basis' => $row['basis'] ?? 'unset',
            'route' => $row['route'] ?? 'unset',
            'party_class' => $row['party_class'] ?? 'unanswered',
            'actual_amount' => $row['actual_amount'] ?? null,
            'actual_date' => $row['actual_date'] ?? null,
            'actual_method' => $row['actual_method'] ?? '',
            'actual_confirmed' => !empty($row['actual_confirmed']) ? 1 : 0,
            'delete' => false,
            'row_version' => (int)($row['row_version'] ?? 1),
            'link_only' => true,
            'marks_paid' => false,
        ];
    }
    return ['ok' => true, 'changes' => $changes, 'writes_payroll' => false, 'marks_paid' => false, 'approved' => false];
}

function psr_link_change_hash(array $changes): string
{
    $slim = [];
    foreach ($changes as $change) {
        $slim[] = [(int)$change['sheet_row_id'], (int)$change['user_id'], (int)$change['site_id'], (int)$change['row_version']];
    }
    return hash('sha256', json_encode($slim));
}

function psr_fetch_staff_directory(PDO $pdo, int $orgId): array
{
    $people = [];
    $add = static function (array $person) use (&$people): void {
        $id = (int)($person['id'] ?? 0);
        $site = (int)($person['site_id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $people[$id . ':' . $site] = [
            'id' => $id,
            'name' => (string)($person['name'] ?? ''),
            'site_id' => $site,
            'site_name' => (string)($person['site_name'] ?? ''),
            'org_id' => (int)($person['org_id'] ?? 0),
        ];
    };
    try {
        $st = $pdo->prepare(
            'SELECT u.id, u.Name AS name, s.SId AS site_id, s.nick_name AS site_name, COUNT(*) AS n
             FROM users u
             INNER JOIN payroll_finalized f ON f.user_id = u.id
             INNER JOIN sites s ON s.SId = f.site_id AND s.org_id = u.org_id
             WHERE u.org_id = ?
             GROUP BY u.id, u.Name, s.SId, s.nick_name'
        );
        $st->execute([$orgId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $person) {
            $person['org_id'] = $orgId;
            $add($person);
        }
    } catch (Throwable $e) {
    }
    try {
        $st = $pdo->prepare(
            'SELECT u.id, u.Name AS name, s.SId AS site_id, s.nick_name AS site_name, COUNT(*) AS n
             FROM users u
             INNER JOIN attendance a ON a.user_id = u.id
             INNER JOIN sites s ON s.SId = a.site_id AND s.org_id = u.org_id
             WHERE u.org_id = ? AND a.clock_in >= DATE_SUB(CURDATE(), INTERVAL 18 MONTH)
             GROUP BY u.id, u.Name, s.SId, s.nick_name'
        );
        $st->execute([$orgId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $person) {
            $person['org_id'] = $orgId;
            $add($person);
        }
    } catch (Throwable $e) {
    }
    try {
        $st = $pdo->prepare('SELECT id, Name AS name FROM users WHERE org_id = ? ORDER BY Name ASC');
        $st->execute([$orgId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $person) {
            $seen = false;
            foreach ($people as $have) {
                if ((int)$have['id'] === (int)$person['id'] && (int)$have['site_id'] > 0) {
                    $seen = true;
                    break;
                }
            }
            if (!$seen) {
                $add(['id' => (int)$person['id'], 'name' => (string)$person['name'], 'site_id' => 0, 'site_name' => '', 'org_id' => $orgId]);
            }
        }
    } catch (Throwable $e) {
    }
    $out = array_values($people);
    usort($out, static function ($a, $b) {
        $name = strcmp(psr_norm_name($a['name']), psr_norm_name($b['name']));
        return $name !== 0 ? $name : strcmp(psr_norm_name($a['site_name']), psr_norm_name($b['site_name']));
    });
    return $out;
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
        $payOn = '';
        foreach ($planLines as $plan) {
            if ((int)($plan['row_id'] ?? 0) === (int)$sheet['id'] && array_key_exists('proposed_amount', $plan)) {
                $today = $plan['proposed_amount'];
                if (!empty($plan['pay_on'])) {
                    $payOn = (string)$plan['pay_on'];
                }
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
            'pay_on' => $payOn,
            'today_effect' => psr_today_effect($today),
            'balance_after' => $after,
            'route' => (string)($sheet['pay_route'] ?? 'unset'),
            'basis' => $basis,
            'party_class' => (string)($sheet['party_class'] ?? 'unanswered'),
            'er_cpp_state' => (string)($sheet['er_cpp_state'] ?? 'unknown'),
            'er_cpp_amount' => $sheet['er_cpp_amount'] ?? null,
            'er_ei_state' => (string)($sheet['er_ei_state'] ?? 'unknown'),
            'er_ei_amount' => $sheet['er_ei_amount'] ?? null,
            'er_other_state' => (string)($sheet['er_other_state'] ?? 'unknown'),
            'er_other_amount' => $sheet['er_other_amount'] ?? null,
            'gst_verified' => !empty($sheet['gst_verified']) ? 1 : 0,
            'gst_invoice_principal' => $sheet['gst_invoice_principal'] ?? null,
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
        $payOn = '';
        foreach ($planLines as $plan) {
            if ((int)($plan['user_id'] ?? 0) === (int)$ln['user_id']
                && (int)($plan['site_id'] ?? 0) === (int)$ln['site_id']
                && psr_periods_overlap($plan, $ln)) {
                $today = $plan['proposed_amount'] ?? null;
                $basis = (string)($plan['amount_basis'] ?? 'unset');
                if (!empty($plan['pay_on'])) {
                    $payOn = (string)$plan['pay_on'];
                }
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
            'pay_on' => $payOn,
            'today_effect' => psr_today_effect($today),
            'balance_after' => psr_balance_after($before, $today, $basis),
            'route' => 'unset',
            'basis' => $basis,
            'party_class' => 'unanswered',
            'er_cpp_state' => 'unknown',
            'er_cpp_amount' => null,
            'er_ei_state' => 'unknown',
            'er_ei_amount' => null,
            'er_other_state' => 'unknown',
            'er_other_amount' => null,
            'gst_verified' => 0,
            'gst_invoice_principal' => null,
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
        if (!empty($filter['site_id'])) {
            $sid = (int)$filter['site_id'];
            $want = psr_norm_name($filter['site_name'] ?? '');
            $rowStore = psr_norm_name($row['store_name'] ?? '');
            $nameMatch = $want !== '' && $rowStore !== '' && ($rowStore === $want || strpos($rowStore, $want) !== false || strpos($want, $rowStore) !== false);
            if ((int)($row['site_id'] ?? 0) !== $sid && !$nameMatch && $rowStore !== '') {
                continue;
            }
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

function psr_sort_columns(): array
{
    return ['person', 'period', 'status', 'reconciled', 'gross', 'net', 'paid', 'balance_before', 'proposed', 'balance_after', 'route', 'gst', 'provenance'];
}

function psr_sort_column($column): string
{
    $column = (string)$column;
    return in_array($column, psr_sort_columns(), true) ? $column : '';
}

function psr_sort_dir($dir): string
{
    return $dir === 'desc' ? 'desc' : 'asc';
}

function psr_amount_sort_key(bool $known, $value): array
{
    if (!$known || $value === null) {
        return [1, 0.0];
    }
    return [0, (float)$value];
}

function psr_text_sort_key(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return [1, ''];
    }
    return [0, psr_norm_name($value)];
}

function psr_row_sort_key(array $row, string $column): array
{
    switch ($column) {
        case 'person':
            return psr_text_sort_key(trim((string)($row['employee_name'] ?? '') . ' ' . (string)($row['store_name'] ?? '')));
        case 'period':
            $start = trim((string)($row['period_start'] ?? ''));
            return $start === '' ? [1, ''] : [0, $start . '|' . (string)($row['period_end'] ?? '')];
        case 'status':
            return psr_text_sort_key((string)($row['source_status'] ?? ''));
        case 'reconciled':
            return psr_text_sort_key((string)($row['reconciled']['derived'] ?? ''));
        case 'gross':
            return psr_amount_sort_key(array_key_exists('gross', $row) && $row['gross'] !== null, $row['gross'] ?? null);
        case 'net':
            return psr_amount_sort_key(!empty($row['net_known']) && ($row['net'] ?? null) !== null, $row['net'] ?? null);
        case 'paid':
            return psr_amount_sort_key(($row['paid_state'] ?? '') === 'amount' && ($row['paid'] ?? null) !== null, $row['paid'] ?? null);
        case 'balance_before':
            $known = ($row['balance_before']['state'] ?? '') === 'amount' && ($row['balance_before']['amount'] ?? null) !== null;
            return psr_amount_sort_key($known, $row['balance_before']['amount'] ?? null);
        case 'proposed':
            return psr_amount_sort_key(array_key_exists('today_pay', $row) && $row['today_pay'] !== null, $row['today_pay'] ?? null);
        case 'balance_after':
            $known = ($row['balance_after']['state'] ?? '') === 'amount' && ($row['balance_after']['amount'] ?? null) !== null;
            return psr_amount_sort_key($known, $row['balance_after']['amount'] ?? null);
        case 'route':
            return psr_text_sort_key(trim((string)($row['route'] ?? '') . ' ' . (string)($row['basis'] ?? '')));
        case 'gst':
            $gst = is_array($row['gst'] ?? null) ? $row['gst'] : [];
            $known = in_array(($gst['state'] ?? ''), ['amount', 'unverified_estimate'], true) && ($gst['amount'] ?? null) !== null;
            return psr_amount_sort_key($known, $gst['amount'] ?? null);
        case 'provenance':
            return psr_text_sort_key((string)($row['provenance'] ?? ''));
        default:
            return [0, (string)($row['key'] ?? '')];
    }
}

function psr_sort_compare(array $aKey, array $bKey, string $dir, string $aTie, string $bTie): int
{
    if ($aKey[0] !== $bKey[0]) {
        return $aKey[0] <=> $bKey[0];
    }
    if (is_int($aKey[1]) || is_float($aKey[1]) || is_int($bKey[1]) || is_float($bKey[1])) {
        $cmp = ((float)$aKey[1] <=> (float)$bKey[1]);
    } else {
        $cmp = strcmp((string)$aKey[1], (string)$bKey[1]);
    }
    if ($dir === 'desc') {
        $cmp = -$cmp;
    }
    return $cmp !== 0 ? $cmp : strcmp($aTie, $bTie);
}

function psr_sort_rows(array $rows, string $column, string $dir): array
{
    $column = psr_sort_column($column);
    if ($column === '') {
        return $rows;
    }
    $dir = psr_sort_dir($dir);
    usort($rows, static function ($a, $b) use ($column, $dir) {
        return psr_sort_compare(psr_row_sort_key($a, $column), psr_row_sort_key($b, $column), $dir, (string)($a['key'] ?? ''), (string)($b['key'] ?? ''));
    });
    return $rows;
}

function psr_group_sort_key(array $group, string $column): array
{
    if (in_array($column, ['gross', 'net', 'paid', 'balance_before', 'proposed', 'balance_after'], true)) {
        $part = $group['totals'][$column] ?? ['amount' => null];
        return psr_amount_sort_key($part['amount'] !== null, $part['amount']);
    }
    if ($column === 'person') {
        return psr_text_sort_key(trim((string)($group['employee_name'] ?? '') . ' ' . (string)($group['store_name'] ?? '')));
    }
    if ($column === 'gst') {
        $sum = 0.0;
        $known = 0;
        foreach ($group['rows'] as $row) {
            $key = psr_row_sort_key($row, 'gst');
            if ($key[0] === 0) {
                $sum = round($sum + (float)$key[1], 2);
                $known++;
            }
        }
        return psr_amount_sort_key($known > 0, $known > 0 ? $sum : null);
    }
    $rows = psr_sort_rows($group['rows'], 'period', 'asc');
    return psr_row_sort_key($rows[0] ?? [], $column === 'period' ? 'period' : $column);
}

function psr_sort_groups(array $groups, string $column, string $dir): array
{
    $column = psr_sort_column($column);
    if ($column === '') {
        return $groups;
    }
    $dir = psr_sort_dir($dir);
    $periodDir = $column === 'period' ? $dir : 'asc';
    foreach ($groups as $i => $group) {
        $groups[$i]['rows'] = psr_sort_rows($group['rows'], 'period', $periodDir);
    }
    usort($groups, static function ($a, $b) use ($column, $dir) {
        return psr_sort_compare(psr_group_sort_key($a, $column), psr_group_sort_key($b, $column), $dir, (string)($a['key'] ?? ''), (string)($b['key'] ?? ''));
    });
    return $groups;
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

function psr_money_total(array $values): array
{
    $sum = 0.0;
    $known = 0;
    foreach ($values as $value) {
        if ($value === null) {
            continue;
        }
        $sum = round($sum + (float)$value, 2);
        $known++;
    }
    return [
        'amount' => $known > 0 ? $sum : null,
        'known' => $known,
        'unknown' => count($values) - $known,
    ];
}

function psr_employee_group_key(array $row): string
{
    $userId = (int)($row['user_id'] ?? 0);
    $siteId = (int)($row['site_id'] ?? 0);
    $store = psr_norm_name($row['store_name'] ?? '');
    if ($userId > 0) {
        $storeKey = $siteId > 0 ? 'site:' . $siteId : 'store:' . $store;
        return 'user:' . $userId . '|' . $storeKey;
    }
    return 'name:' . psr_norm_name($row['employee_name'] ?? '') . '|store:' . $store;
}

function psr_row_money_values(array $row): array
{
    $gross = array_key_exists('gross', $row) && $row['gross'] !== null ? (float)$row['gross'] : null;
    $net = (!empty($row['net_known']) && $row['net'] !== null) ? (float)$row['net'] : null;
    $paid = (($row['paid_state'] ?? '') === 'amount' && $row['paid'] !== null) ? (float)$row['paid'] : null;
    $before = (($row['balance_before']['state'] ?? '') === 'amount' && ($row['balance_before']['amount'] ?? null) !== null)
        ? (float)$row['balance_before']['amount'] : null;
    $proposed = array_key_exists('today_pay', $row) && $row['today_pay'] !== null ? (float)$row['today_pay'] : null;
    $after = (($row['balance_after']['state'] ?? '') === 'amount' && ($row['balance_after']['amount'] ?? null) !== null)
        ? (float)$row['balance_after']['amount'] : null;
    return [$gross, $net, $paid, $before, $proposed, $after];
}

function psr_employee_group_totals(array $rows): array
{
    $cols = [[], [], [], [], [], []];
    foreach ($rows as $row) {
        $values = psr_row_money_values($row);
        foreach ($values as $i => $value) {
            $cols[$i][] = $value;
        }
    }
    $named = [];
    foreach (['gross', 'net', 'paid', 'balance_before', 'proposed', 'balance_after'] as $i => $name) {
        $named[$name] = psr_money_total($cols[$i]);
    }
    $named['rows'] = count($rows);
    return $named;
}

function psr_group_employees(array $rows): array
{
    $buckets = [];
    foreach ($rows as $row) {
        $key = psr_employee_group_key($row);
        $userId = (int)($row['user_id'] ?? 0);
        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'key' => $key,
                'linked' => $userId > 0,
                'not_linked' => $userId <= 0,
                'user_id' => $userId,
                'site_id' => (int)($row['site_id'] ?? 0),
                'employee_name' => (string)($row['employee_name'] ?? ''),
                'store_name' => (string)($row['store_name'] ?? ''),
                'rows' => [],
                'sheet_ids' => [],
            ];
        }
        $buckets[$key]['rows'][] = $row;
        $sheetId = (int)($row['sheet_row_id'] ?? 0);
        if ($sheetId > 0) {
            $buckets[$key]['sheet_ids'][] = $sheetId;
        }
    }
    $groups = [];
    foreach ($buckets as $bucket) {
        usort($bucket['rows'], static function ($a, $b) {
            return strcmp((string)($a['period_start'] ?? ''), (string)($b['period_start'] ?? ''));
        });
        $bucket['sheet_ids'] = array_values(array_unique($bucket['sheet_ids']));
        sort($bucket['sheet_ids']);
        $bucket['totals'] = psr_employee_group_totals($bucket['rows']);
        $groups[] = $bucket;
    }
    usort($groups, static function ($a, $b) {
        $store = strcmp(psr_norm_name($a['store_name']), psr_norm_name($b['store_name']));
        if ($store !== 0) {
            return $store;
        }
        return strcmp(psr_norm_name($a['employee_name']), psr_norm_name($b['employee_name']));
    });
    return $groups;
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
            && (string)($cur['pay_on'] ?? '') === (string)(psr_valid_date($post['pay_on'] ?? '') ?? '')
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
            'pay_on' => psr_valid_date($post['pay_on'] ?? ''),
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
                psr_insert_revision($pdo, $orgId, 'row', $ch['sheet_row_id'], !empty($ch['link_only']) ? 'accept_link' : 'grid_draft', $actor, null, $ch);
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
                        'UPDATE payroll_sheet_plan_lines SET proposed_amount=?, amount_basis=?, pay_route=?, party_class=?, period_start=?, period_end=?, line_effect=?, user_id=?, site_id=?, pay_on=?
                         WHERE id=? AND org_id=? AND approved_at IS NULL'
                    )->execute([
                        $ch['today_pay'], $ch['basis'], $ch['route'], $ch['party_class'], $ch['period_start'], $ch['period_end'],
                        $effect, $ch['user_id'] ?: null, $ch['site_id'] ?: null, $ch['pay_on'] ?: null, (int)$existing['id'], $orgId,
                    ]);
                } elseif (!$existing) {
                    $pdo->prepare(
                        'INSERT INTO payroll_sheet_plan_lines
                            (org_id, plan_id, row_id, employee_name, user_id, site_id, proposed_amount, amount_basis, pay_route, party_class, period_start, period_end, line_effect, pay_on, note, created_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
                    )->execute([
                        $orgId, $planId, $ch['sheet_row_id'] ?: null, $ch['employee_name'], $ch['user_id'] ?: null, $ch['site_id'] ?: null,
                        $ch['today_pay'], $ch['basis'], $ch['route'], $ch['party_class'], $ch['period_start'], $ch['period_end'],
                        $effect, $ch['pay_on'] ?: null, 'Spreadsheet proposal. Not an actual payment.',
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

function psr_save_suggestion_round(PDO $pdo, int $orgId, int $actor, array $suggestion, array $rows): array
{
    psr_ensure_grid_columns($pdo);
    if (!empty($suggestion['posts_payment']) || !empty($suggestion['marks_paid']) || !empty($suggestion['approved'])) {
        throw new RuntimeException('A suggestion tried to record a payment. Nothing was saved.');
    }
    $versions = psr_suggestion_versions($rows);
    $round = psr_dated_round($suggestion, $versions);
    if ($round['proposal_key'] === '') {
        throw new RuntimeException('The suggestion has no key. Nothing was saved.');
    }
    $updates = psr_suggestion_updates($rows, $suggestion);
    $pdo->beginTransaction();
    try {
        $have = $pdo->prepare('SELECT id FROM payroll_sheet_plans WHERE org_id = ? AND proposal_key = ? LIMIT 1');
        $have->execute([$orgId, $round['proposal_key']]);
        $existingId = (int)$have->fetchColumn();
        if ($existingId > 0) {
            $pdo->commit();
            return ['already' => true, 'plan_id' => $existingId, 'payments_posted' => 0, 'approved' => false, 'marks_paid' => false];
        }
        $pdo->prepare(
            'INSERT INTO payroll_sheet_plans (org_id, plan_date, title, available_amount, note, created_by, created_at, proposal_key, suggest_policy)
             VALUES (?,?,?,?,?,?,NOW(),?,?)'
        )->execute([
            $orgId,
            $round['plan_date'],
            'Pay proposal ' . $round['plan_date'],
            $round['available_funds'],
            'Suggested draft for ' . $round['plan_date'] . '. ' . psr_suggest_policy_label((string)$round['policy']) . ' Not a payment, not approved, and not sent to ADP or a bank.',
            $actor,
            $round['proposal_key'],
            $round['policy'],
        ]);
        $planId = (int)$pdo->lastInsertId();
        $written = 0;
        foreach ($updates as $up) {
            if (empty($up['changed'])) {
                continue;
            }
            if ($up['user_id'] > 0) {
                $uq = $pdo->prepare('SELECT id FROM users WHERE id = ? AND org_id = ?');
                $uq->execute([$up['user_id'], $orgId]);
                if (!$uq->fetchColumn()) {
                    throw new RuntimeException('That employee is not in this company. Nothing in this suggestion was saved.');
                }
            }
            if ($up['site_id'] > 0) {
                $sq = $pdo->prepare('SELECT SId FROM sites WHERE SId = ? AND org_id = ?');
                $sq->execute([$up['site_id'], $orgId]);
                if (!$sq->fetchColumn()) {
                    throw new RuntimeException('That store is not in this company. Nothing in this suggestion was saved.');
                }
            }
            $note = 'Suggested pay on ' . $round['plan_date'] . '. Cost reserve '
                . number_format((float)$up['cost_reserve'], 2)
                . ' is funding set aside and does not reduce the wage payable. Not an actual payment.';
            $pdo->prepare(
                'INSERT INTO payroll_sheet_plan_lines
                    (org_id, plan_id, row_id, employee_name, user_id, site_id, proposed_amount, amount_basis, pay_route, party_class, period_start, period_end, line_effect, pay_on, note, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
            )->execute([
                $orgId, $planId, $up['sheet_row_id'] ?: null, $up['employee_name'], $up['user_id'] ?: null, $up['site_id'] ?: null,
                $up['proposed_amount'], $up['basis'] ?: 'unset', $up['route'] ?: 'unset', $up['party_class'] ?: 'unanswered',
                $up['period_start'], $up['period_end'], 'payout', $round['plan_date'], $note,
            ]);
            if ($up['sheet_row_id'] > 0) {
                $upd = $pdo->prepare(
                    'UPDATE payroll_sheet_rows SET proposed_amount = ?, row_version = row_version + 1, updated_at = NOW()
                     WHERE id = ? AND org_id = ? AND deleted_at IS NULL AND row_version = ?'
                );
                $upd->execute([$up['proposed_amount'], $up['sheet_row_id'], $orgId, $up['row_version']]);
                if ($upd->rowCount() < 1) {
                    throw new RuntimeException('A row changed after the preview. Nothing in this suggestion was saved.');
                }
                psr_insert_revision($pdo, $orgId, 'row', $up['sheet_row_id'], 'suggest_draft', $actor, ['proposed_amount' => $up['previous'], 'actual_date' => $up['actual_date']], [
                    'proposed_amount' => $up['proposed_amount'],
                    'pay_on' => $round['plan_date'],
                    'actual_date' => $up['actual_date'],
                    'posts_payment' => false,
                ]);
            }
            $written++;
        }
        $pdo->commit();
        return ['already' => false, 'plan_id' => $planId, 'lines' => $written, 'payments_posted' => 0, 'approved' => false, 'marks_paid' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
