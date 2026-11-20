<?php
/**
 * Post missing ON/SK statutory holiday lines for one org (default org 4).
 *
 * A holiday is posted even when that fortnight is already paid or has no
 * other finalized lines. The work date stays the holiday. If that fortnight
 * has any paid row, the line's pay_period_end is the next unpaid period and
 * the comment says "late stat, period paid", so the paid cheque is not reopened.
 * CPP/EI/tax are refreshed only on unpaid periods that received a new line.
 *
 *   php ops/t4127_stat_backfill.php dry [org]
 *   php ops/t4127_stat_backfill.php apply [org]
 */
if (!defined('STAT_BACKFILL_LIB')) {
    $app = getenv('PAYROLL_APP') ?: '/home/renocon/public_html/payroll';
    require $app . '/db.php';
    require $app . '/payroll_engine/payroll_engine.php';
    require $app . '/payroll_engine/stat_holidays.php';
    if (!isset($pdo) || !$pdo instanceof PDO) {
        fwrite(STDERR, "db.php did not provide a connection\n");
        exit(1);
    }
    $mode = $argv[1] ?? 'dry';
    $orgId = (int)($argv[2] ?? (getenv('STAT_ORG') ?: 4));
    $from = getenv('STAT_FROM') ?: '2026-07-15';
    $to = getenv('STAT_TO') ?: date('Y-m-d');
    if (!in_array($mode, ['dry', 'apply'], true)) {
        fwrite(STDERR, "Usage: php t4127_stat_backfill.php dry|apply [org]\n");
        exit(1);
    }
    $own = false;
    if ($mode === 'apply') {
        $pdo->beginTransaction();
        $own = true;
    }
    try {
        $plan = stat_bf_plan($pdo, $orgId, $from, $to);
        stat_bf_print($plan, $mode === 'apply');
        if ($mode === 'apply') {
            $result = stat_bf_apply($pdo, $plan);
            echo "Posted {$result['posted']}. Refreshed {$result['refreshed']} unpaid periods.\n";
            echo "Paid rows were not modified.\n";
            if ($own && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } else {
            echo "Dry run wrote nothing.\n";
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, 'Stat backfill failed, transaction rolled back: ' . $e->getMessage() . "\n");
        exit(1);
    }
    exit(0);
}

function stat_bf_fields(PDO $pdo, string $table): array {
    $out = [];
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $col) {
            $out[strtolower((string)$col['name'])] = true;
        }
        return $out;
    }
    foreach ($pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`') as $col) {
        $out[strtolower((string)$col['Field'])] = true;
    }
    return $out;
}

function stat_bf_window(PDO $pdo, int $orgId, int $siteId, string $day): array {
    if (function_exists('pe_period_window') && function_exists('pe_period_mode_for_site')) {
        $mode = pe_period_mode_for_site($pdo, $siteId, $orgId);
        $anchor = function_exists('pe_period_anchor_for_org') ? pe_period_anchor_for_org($pdo, $orgId) : '2024-01-01';
        return pe_period_window($day, $mode, $anchor);
    }
    $anchor = '2024-01-01';
    $span = 14;
    $d0 = new DateTimeImmutable($anchor);
    $d1 = new DateTimeImmutable(substr($day, 0, 10));
    $days = (int)$d0->diff($d1)->format('%r%a');
    $block = (int)floor($days / $span);
    $start = $d0->modify(($block * $span) . ' days');
    $end = $start->modify('+' . ($span - 1) . ' days');
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function stat_bf_period_is_paid(PDO $pdo, int $userId, int $siteId, string $start, string $end): bool {
    $fields = stat_bf_fields($pdo, 'payroll_finalized');
    $parts = [];
    if (isset($fields['paid_amount'])) {
        $parts[] = 'COALESCE(paid_amount,0) > 0.02';
    }
    if (isset($fields['paid_at'])) {
        $parts[] = "(paid_at IS NOT NULL AND paid_at NOT IN ('', '0000-00-00', '0000-00-00 00:00:00'))";
    }
    if (isset($fields['payment_status'])) {
        $parts[] = "LOWER(COALESCE(payment_status,'')) IN ('paid','remitted','cheque_printed')";
    }
    if (isset($fields['status'])) {
        $parts[] = "LOWER(COALESCE(status,'')) IN ('paid','remitted')";
    }
    if (isset($fields['cra_status'])) {
        $parts[] = "LOWER(COALESCE(cra_status,'')) IN ('paid','remitted')";
    }
    if (!$parts) {
        return false;
    }
    $sql = 'SELECT 1 FROM payroll_finalized WHERE user_id=? AND site_id=? AND work_date BETWEEN ? AND ? AND ('
        . implode(' OR ', $parts) . ') LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute([$userId, $siteId, $start, $end]);
    return (bool)$st->fetchColumn();
}

/** @return array{start:string,end:string,late:bool} */
function stat_bf_target(PDO $pdo, int $orgId, int $userId, int $siteId, string $holidayDate): array {
    [$start, $end] = stat_bf_window($pdo, $orgId, $siteId, $holidayDate);
    $late = false;
    for ($guard = 0; stat_bf_period_is_paid($pdo, $userId, $siteId, $start, $end); $guard++) {
        if ($guard > 40) {
            throw new RuntimeException("No unpaid period for user $userId site $siteId after $holidayDate");
        }
        $late = true;
        $next = date('Y-m-d', strtotime($end . ' +1 day'));
        [$start, $end] = stat_bf_window($pdo, $orgId, $siteId, $next);
    }
    return ['start' => $start, 'end' => $end, 'late' => $late];
}

function stat_bf_plan(PDO $pdo, int $orgId, string $from, string $to): array {
    $nickFields = stat_bf_fields($pdo, 'sites');
    $nick = isset($nickFields['nick_name']) ? 'nick_name' : 'SId';
    $sites = $pdo->prepare("SELECT SId, $nick AS nick_name, province FROM sites WHERE org_id=? ORDER BY SId");
    $sites->execute([$orgId]);
    $unmapped = [];
    $mapped = [];
    foreach ($sites->fetchAll(PDO::FETCH_ASSOC) as $site) {
        $prov = function_exists('sh_normalize_province') ? sh_normalize_province((string)$site['province']) : strtoupper(trim((string)$site['province']));
        if (!in_array($prov, ['ON', 'SK'], true)) {
            $unmapped[] = ['id' => (int)$site['SId'], 'name' => (string)$site['nick_name'], 'province' => (string)$site['province']];
            continue;
        }
        $site['province'] = $prov;
        $mapped[] = $site;
    }
    $holidays = [];
    foreach (['ON', 'SK'] as $prov) {
        foreach (sh_calendar_holidays($prov, $from, $to) as $h) {
            $holidays[] = $h;
        }
    }
    usort($holidays, function ($a, $b) {
        return [$a['holiday_date'], $a['provinces'][0]] <=> [$b['holiday_date'], $b['provinces'][0]];
    });
    $groups = [];
    $flat = [];
    foreach ($holidays as $h) {
        $prov = $h['provinces'][0];
        $group = [
            'date' => $h['holiday_date'],
            'name' => $h['holiday_name'],
            'province' => $prov,
            'eligible' => 0,
            'add' => 0,
            'already' => 0,
            'total' => 0.0,
            'rows' => [],
        ];
        foreach ($mapped as $site) {
            if ($site['province'] !== $prov) {
                continue;
            }
            $people = $pdo->prepare(
                'SELECT DISTINCT f.user_id, u.Name AS employee
                   FROM payroll_finalized f
                   JOIN users u ON u.id=f.user_id AND u.org_id=?
                  WHERE f.site_id=?'
            );
            $people->execute([$orgId, (int)$site['SId']]);
            foreach ($people->fetchAll(PDO::FETCH_ASSOC) as $person) {
                $uid = (int)$person['user_id'];
                $sid = (int)$site['SId'];
                $calc = sh_compute_stat_pay($pdo, $uid, $sid, $h['holiday_date'], $prov);
                if (!$calc['eligible'] || (float)$calc['amount'] < 0.01) {
                    continue;
                }
                if (function_exists('sh_should_hold_stat') && sh_should_hold_stat($pdo, $orgId, $uid, $sid, $h['holiday_date'], $h['holiday_name'], $prov, (float)$calc['amount'], null, false)) {
                    continue;
                }
                $group['eligible']++;
                $target = stat_bf_target($pdo, $orgId, $uid, $sid, $h['holiday_date']);
                $row = [
                    'user_id' => $uid,
                    'site_id' => $sid,
                    'employee' => (string)$person['employee'],
                    'site' => (string)$site['nick_name'],
                    'holiday' => $h['holiday_date'],
                    'name' => $h['holiday_name'],
                    'province' => $prov,
                    'amount' => round((float)$calc['amount'], 2),
                    'period_end' => $target['end'],
                    'late' => $target['late'],
                    'org_id' => $orgId,
                ];
                if (sh_stat_line_exists($pdo, $uid, $sid, $h['holiday_date'])) {
                    $group['already']++;
                    continue;
                }
                $group['add']++;
                $group['total'] = round($group['total'] + $row['amount'], 2);
                $group['rows'][] = $row;
                $flat[] = $row;
            }
        }
        $groups[] = $group;
    }
    return ['org_id' => $orgId, 'from' => $from, 'to' => $to, 'unmapped' => $unmapped, 'groups' => $groups, 'rows' => $flat];
}

function stat_bf_print(array $plan, bool $apply): void {
    echo ($apply ? 'APPLY' : 'DRY') . " org {$plan['org_id']} holidays {$plan['from']} to {$plan['to']}\n";
    echo "Unmapped sites (skipped):\n";
    if (!$plan['unmapped']) {
        echo "  none\n";
    }
    foreach ($plan['unmapped'] as $site) {
        $stored = $site['province'] !== '' ? $site['province'] : '-';
        echo "  site {$site['id']} {$site['name']} stored=$stored\n";
    }
    foreach ($plan['groups'] as $g) {
        echo "{$g['date']} {$g['name']} {$g['province']}: eligible {$g['eligible']}, to add {$g['add']}, already {$g['already']}, \$" . number_format($g['total'], 2, '.', '') . "\n";
    }
    echo "First rows to add:\n";
    $n = 0;
    foreach ($plan['rows'] as $row) {
        if ($n >= 30) {
            break;
        }
        $late = $row['late'] ? 'late' : 'in period';
        echo "  {$row['employee']} | {$row['site']} | {$row['holiday']} {$row['province']} | \$" . number_format($row['amount'], 2, '.', '') . " | period {$row['period_end']} | $late\n";
        $n++;
    }
    if (!$plan['rows']) {
        echo "  none\n";
    }
}

function stat_bf_apply(PDO $pdo, array $plan): array {
    $posted = 0;
    $refresh = [];
    foreach ($plan['rows'] as $row) {
        if (sh_stat_line_exists($pdo, (int)$row['user_id'], (int)$row['site_id'], (string)$row['holiday'])) {
            continue;
        }
        $note = !empty($row['late']) ? 'late stat, period paid' : '';
        $ok = sh_post_stat_line(
            $pdo,
            (int)$row['org_id'],
            (int)$row['user_id'],
            (int)$row['site_id'],
            (string)$row['holiday'],
            (string)$row['name'],
            (string)$row['province'],
            0,
            (string)$row['period_end'],
            $note
        );
        if (!$ok) {
            continue;
        }
        $posted++;
        $key = $row['user_id'] . '|' . $row['site_id'] . '|' . $row['period_end'];
        $refresh[$key] = [(int)$row['user_id'], (int)$row['site_id'], (string)$row['period_end']];
    }
    ksort($refresh);
    $refreshed = 0;
    foreach ($refresh as [$uid, $sid, $end]) {
        [$start] = stat_bf_window($pdo, (int)$plan['org_id'], $sid, $end);
        if (stat_bf_period_is_paid($pdo, $uid, $sid, $start, $end)) {
            continue;
        }
        if (function_exists('pe_refresh_period_stat')) {
            pe_refresh_period_stat($pdo, $uid, $sid, $end);
        }
        $refreshed++;
    }
    return ['posted' => $posted, 'refreshed' => $refreshed];
}
