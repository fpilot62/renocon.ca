<?php
/**
 * One line per employee for Sheet Review.
 * Amounts come from the grid rows already built for Confirmed paid and
 * Balance before. Paid now is only a proposal field. Nothing here writes,
 * marks anyone paid, or approves a row.
 */

function psr_summary_employee_key(array $row): string
{
    $userId = (int)($row['user_id'] ?? 0);
    if ($userId > 0) {
        return 'user:' . $userId;
    }
    return 'name:' . psr_norm_name((string)($row['employee_name'] ?? ''))
        . '|store:' . psr_norm_name((string)($row['store_name'] ?? ''));
}

function psr_summary_period_key(array $row): string
{
    return (string)($row['period_end'] ?? '') . '|' . (string)($row['period_start'] ?? '');
}

function psr_row_confirmed_paid_on(array $row, array $paidDates): string
{
    if (($row['paid_state'] ?? '') !== 'amount' || $row['paid'] === null || (float)$row['paid'] <= 0.009) {
        return '';
    }
    $best = '';
    foreach ($row['finalized_ids'] ?? [] as $id) {
        $date = (string)($paidDates[(int)$id] ?? '');
        if ($date !== '' && strcmp($date, $best) > 0) {
            $best = $date;
        }
    }
    if ($best === '' && !empty($row['actual_confirmed'])) {
        $date = (string)($row['actual_date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $best = $date;
        }
    }
    return $best;
}

function psr_summary_target_row(array $rows): ?array
{
    $sheet = null;
    $bestBalance = -1.0;
    $fallback = null;
    foreach ($rows as $row) {
        if ($fallback === null) {
            $fallback = $row;
        }
        if ((int)($row['sheet_row_id'] ?? 0) <= 0) {
            continue;
        }
        $amount = 0.0;
        if (($row['balance_before']['state'] ?? '') === 'amount' && ($row['balance_before']['amount'] ?? null) !== null) {
            $amount = (float)$row['balance_before']['amount'];
        }
        if ($sheet === null || $amount > $bestBalance) {
            $sheet = $row;
            $bestBalance = $amount;
        }
    }
    return $sheet ?? $fallback;
}

function psr_summary_current_due(array $rows): array
{
    $periods = [];
    foreach ($rows as $row) {
        $periods[psr_summary_period_key($row)][] = $row;
    }
    uksort($periods, static function ($a, $b) {
        return strcmp($b, $a);
    });
    $latest = null;
    foreach ($periods as $periodRows) {
        $latest = $periodRows;
        $known = true;
        $sum = 0.0;
        foreach ($periodRows as $row) {
            $before = $row['balance_before'] ?? [];
            if (($before['state'] ?? '') === 'amount' && ($before['amount'] ?? null) !== null) {
                $sum = round($sum + (float)$before['amount'], 2);
            } else {
                $known = false;
            }
        }
        $start = (string)($periodRows[0]['period_start'] ?? '');
        $end = (string)($periodRows[0]['period_end'] ?? '');
        if (!$known) {
            return [
                'state' => 'unknown',
                'amount' => null,
                'period_start' => $start,
                'period_end' => $end,
                'rows' => $periodRows,
            ];
        }
        if ($sum > 0.009) {
            return [
                'state' => 'amount',
                'amount' => $sum,
                'period_start' => $start,
                'period_end' => $end,
                'rows' => $periodRows,
            ];
        }
    }
    $start = $latest ? (string)($latest[0]['period_start'] ?? '') : '';
    $end = $latest ? (string)($latest[0]['period_end'] ?? '') : '';
    return [
        'state' => 'amount',
        'amount' => 0.0,
        'period_start' => $start,
        'period_end' => $end,
        'rows' => $latest ?: [],
    ];
}

function psr_sheet_source_ids(array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        $id = (int)($row['sheet_row_id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    $out = array_map('intval', array_keys($ids));
    sort($out);
    return $out;
}

function psr_pay_summary_line(array $bucket, array $paidDates): array
{
    $paid = 0.0;
    $paidUnknown = 0;
    $due = 0.0;
    $dueKnown = 0;
    $dueUnknown = 0;
    $lastPaid = '';
    foreach ($bucket['rows'] as $row) {
        if (($row['paid_state'] ?? '') === 'amount' && $row['paid'] !== null) {
            $paid = round($paid + (float)$row['paid'], 2);
        } elseif (($row['paid_state'] ?? '') === 'unknown') {
            $paidUnknown++;
        }
        $before = $row['balance_before'] ?? [];
        if (($before['state'] ?? '') === 'amount' && ($before['amount'] ?? null) !== null) {
            $due = round($due + (float)$before['amount'], 2);
            $dueKnown++;
        } else {
            $dueUnknown++;
        }
        $paidOn = psr_row_confirmed_paid_on($row, $paidDates);
        if ($paidOn !== '' && strcmp($paidOn, $lastPaid) > 0) {
            $lastPaid = $paidOn;
        }
    }
    $current = psr_summary_current_due($bucket['rows']);
    $target = psr_summary_target_row($current['rows'] ?: $bucket['rows']);
    return [
        'key' => $bucket['key'],
        'user_id' => (int)$bucket['user_id'],
        'employee_name' => (string)$bucket['employee_name'],
        'stores' => $bucket['stores'],
        'total_paid' => $paid,
        'paid_unknown' => $paidUnknown,
        'last_paid_on' => $lastPaid,
        'current_due' => $current['state'] === 'amount' ? $current['amount'] : null,
        'current_due_state' => $current['state'],
        'current_period_start' => $current['period_start'],
        'current_period_end' => $current['period_end'],
        'total_due' => $dueKnown > 0 ? $due : null,
        'due_unknown' => $dueUnknown,
        'pay_now' => $target !== null && array_key_exists('today_pay', $target) ? $target['today_pay'] : null,
        'target' => $target,
        'sheet_ids' => psr_sheet_source_ids($bucket['rows']),
    ];
}

function psr_pay_summary(array $rows, array $paidDates = []): array
{
    $buckets = [];
    foreach ($rows as $row) {
        $key = psr_summary_employee_key($row);
        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'key' => $key,
                'user_id' => (int)($row['user_id'] ?? 0),
                'employee_name' => (string)($row['employee_name'] ?? ''),
                'stores' => [],
                'rows' => [],
            ];
        }
        if ($buckets[$key]['employee_name'] === '' && (string)($row['employee_name'] ?? '') !== '') {
            $buckets[$key]['employee_name'] = (string)$row['employee_name'];
        }
        $store = trim((string)($row['store_name'] ?? ''));
        if ($store !== '' && !in_array($store, $buckets[$key]['stores'], true)) {
            $buckets[$key]['stores'][] = $store;
        }
        $buckets[$key]['rows'][] = $row;
    }
    $lines = [];
    foreach ($buckets as $bucket) {
        sort($bucket['stores']);
        $lines[] = psr_pay_summary_line($bucket, $paidDates);
    }
    usort($lines, static function ($a, $b) {
        return strcmp(psr_norm_name($a['employee_name']), psr_norm_name($b['employee_name']));
    });
    return ['lines' => $lines, 'totals' => psr_pay_summary_totals($lines)];
}

function psr_pay_summary_totals(array $lines): array
{
    $paid = 0.0;
    $paidUnknown = 0;
    $due = 0.0;
    $dueKnown = 0;
    $dueUnknown = 0;
    $current = 0.0;
    $currentKnown = 0;
    $currentUnknown = 0;
    $payNow = 0.0;
    $payNowKnown = 0;
    foreach ($lines as $line) {
        $paid = round($paid + (float)$line['total_paid'], 2);
        $paidUnknown += (int)$line['paid_unknown'];
        if ($line['total_due'] !== null) {
            $due = round($due + (float)$line['total_due'], 2);
            $dueKnown++;
        }
        $dueUnknown += (int)$line['due_unknown'];
        if (($line['current_due_state'] ?? '') === 'amount' && $line['current_due'] !== null) {
            $current = round($current + (float)$line['current_due'], 2);
            $currentKnown++;
        } else {
            $currentUnknown++;
        }
        if ($line['pay_now'] !== null) {
            $payNow = round($payNow + (float)$line['pay_now'], 2);
            $payNowKnown++;
        }
    }
    return [
        'employees' => count($lines),
        'total_paid' => $paid,
        'paid_unknown' => $paidUnknown,
        'total_due' => $dueKnown > 0 ? $due : null,
        'due_unknown' => $dueUnknown,
        'current_due' => $currentKnown > 0 ? $current : null,
        'current_unknown' => $currentUnknown,
        'pay_now' => $payNowKnown > 0 ? $payNow : null,
    ];
}

function psr_summary_post_fields(array $row, $todayRaw): array
{
    $post = [
        'row_version' => (int)($row['row_version'] ?? 1),
        'employee_name' => (string)($row['employee_name'] ?? ''),
        'store_name' => (string)($row['store_name'] ?? ''),
        'user_id' => (int)($row['user_id'] ?? 0),
        'site_id' => (int)($row['site_id'] ?? 0),
        'period_start' => (string)($row['period_start'] ?? ''),
        'period_end' => (string)($row['period_end'] ?? ''),
        'pay_on' => (string)($row['pay_on'] ?? ''),
        'basis' => (string)($row['basis'] ?? 'unset'),
        'route' => (string)($row['route'] ?? 'unset'),
        'party_class' => (string)($row['party_class'] ?? 'unanswered'),
        'today_pay' => $todayRaw,
        'gross' => $row['sheet_gross'] ?? null,
        'net' => $row['sheet_net'] ?? null,
        'actual_amount' => $row['actual_amount'] ?? null,
        'actual_date' => (string)($row['actual_date'] ?? ''),
        'actual_method' => (string)($row['actual_method'] ?? ''),
    ];
    if (!empty($row['actual_confirmed'])) {
        $post['actual_confirmed'] = '1';
    }
    return $post;
}

function psr_load_paid_dates(PDO $pdo, int $orgId, array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        foreach ($row['finalized_ids'] ?? [] as $id) {
            if ((int)$id > 0) {
                $ids[(int)$id] = true;
            }
        }
    }
    if (!$ids) {
        return [];
    }
    try {
        $col = $pdo->query("SHOW COLUMNS FROM payroll_finalized LIKE 'last_paid_date'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    if (!$col) {
        return [];
    }
    $idList = array_keys($ids);
    $holders = implode(',', array_fill(0, count($idList), '?'));
    $st = $pdo->prepare(
        "SELECT f.id, f.last_paid_date
         FROM payroll_finalized f
         INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
         WHERE f.id IN ($holders)"
    );
    $st->execute(array_merge([$orgId], $idList));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $found) {
        $date = psr_valid_date($found['last_paid_date'] ?? '');
        if ($date !== null) {
            $out[(int)$found['id']] = $date;
        }
    }
    return $out;
}

/**
 * Clock-in payroll net versus the By Abdul sheet net for the same filters.
 * X is the system line net: gross minus employee CPP, CPP2, EI, and income tax,
 * already stored on each payroll_finalized line when every part is known.
 * Y is payroll_sheet_rows.net on the By Abdul sheet. Unknown nets are counted,
 * not treated as zero.
 */
function psr_gap_in_scope(array $row, array $filter): bool
{
    if ((int)($filter['user_id'] ?? 0) > 0 && (int)($row['user_id'] ?? 0) !== (int)$filter['user_id']) {
        return false;
    }
    if ((int)($filter['site_id'] ?? 0) > 0 && (int)($row['site_id'] ?? 0) !== (int)$filter['site_id']) {
        return false;
    }
    if (!empty($filter['person'])) {
        $person = (string)$filter['person'];
        $name = (string)($row['employee_name'] ?? '');
        if (stripos($name, $person) === false && (string)($row['user_id'] ?? '') !== $person) {
            return false;
        }
    }
    if (!empty($filter['store'])) {
        $want = psr_norm_name((string)$filter['store']);
        $got = psr_norm_name((string)($row['store_name'] ?? ''));
        if ($want === '' || $got === '' || (strpos($got, $want) === false && strpos($want, $got) === false)) {
            return false;
        }
    }
    if (!empty($filter['year']) && substr((string)($row['period_start'] ?? ''), 0, 4) !== (string)$filter['year']) {
        return false;
    }
    if (!empty($filter['period_start']) && !empty($filter['period_end']) && !psr_periods_overlap($row, $filter)) {
        return false;
    }
    if (($filter['active'] ?? 'all') === 'active' && array_key_exists('active', $row) && (int)$row['active'] === 0) {
        return false;
    }
    return true;
}

function psr_gap_sum(array $rows, callable $netOf): array
{
    $sum = 0.0;
    $known = 0;
    $unknown = 0;
    foreach ($rows as $row) {
        $net = $netOf($row);
        if ($net === null) {
            $unknown++;
            continue;
        }
        $sum = round($sum + (float)$net, 2);
        $known++;
    }
    return [
        'amount' => $known > 0 ? $sum : null,
        'known' => $known,
        'unknown' => $unknown,
    ];
}

function psr_clock_sheet_gap(array $systemLines, array $sheetRows, array $filter): array
{
    $system = [];
    foreach ($systemLines as $line) {
        if (psr_gap_in_scope($line, $filter)) {
            $system[] = $line;
        }
    }
    $sheet = [];
    foreach ($sheetRows as $row) {
        if (strcasecmp(trim((string)($row['source_sheet'] ?? '')), 'By Abdul') !== 0) {
            continue;
        }
        $kind = (string)($row['row_kind'] ?? 'employee_record');
        if ($kind !== '' && $kind !== 'employee_record') {
            continue;
        }
        if (!psr_gap_in_scope($row, $filter)) {
            continue;
        }
        $sheet[] = $row;
    }
    $clock = psr_gap_sum($system, static function (array $line) {
        if (empty($line['net_known']) || $line['net'] === null || $line['net'] === '') {
            return null;
        }
        return (float)$line['net'];
    });
    $sheetSum = psr_gap_sum($sheet, static function (array $row) {
        if (!array_key_exists('net', $row) || $row['net'] === null || $row['net'] === '') {
            return null;
        }
        return (float)$row['net'];
    });
    $difference = null;
    if ((int)$clock['unknown'] === 0 && (int)$sheetSum['unknown'] === 0) {
        $difference = round((float)($clock['amount'] ?? 0) - (float)($sheetSum['amount'] ?? 0), 2);
    }
    return ['clock' => $clock, 'sheet' => $sheetSum, 'difference' => $difference];
}

function psr_gap_money(array $part): string
{
    if ($part['amount'] === null) {
        return (int)$part['unknown'] > 0 ? 'unknown (' . (int)$part['unknown'] . ' unknown)' : '0.00';
    }
    $text = number_format((float)$part['amount'], 2);
    if ((int)$part['unknown'] > 0) {
        $text .= ' (' . (int)$part['unknown'] . ' unknown)';
    }
    return $text;
}

function psr_gap_sentence(array $gap): string
{
    $diff = $gap['difference'] === null ? 'incomplete' : number_format((float)$gap['difference'], 2);
    return 'Clock-in payroll total ' . psr_gap_money($gap['clock'])
        . ' − Sheet total ' . psr_gap_money($gap['sheet'])
        . ' = Difference ' . $diff;
}
