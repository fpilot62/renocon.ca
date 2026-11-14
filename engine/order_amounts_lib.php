<?php
/**
 * Food-order amounts from Daily Activity Report sales.
 * Reads daily_report the same way daily_report_view.php does:
 * DAILY rows only (remarks without week_split or mtd_split).
 * This library only reads daily_report and sites.
 */

function oa_org_id(): int
{
    return 2;
}

function oa_excluded_site_id(): int
{
    return 103;
}

/** Site id => name. Temp (103) is intentionally absent. */
function oa_sites(): array
{
    return [
        6 => 'Penticton',
        11 => 'Westbank',
        19 => 'Vernon',
        20 => 'Merritt',
        21 => 'Salmon Arm',
        23 => 'Popeyes Harvey',
    ];
}

function oa_site_ids(): array
{
    return array_keys(oa_sites());
}

function oa_is_date(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $value));
    return checkdate($m, $d, $y);
}

function oa_sql_alias(string $alias): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid SQL alias.');
    }
    return $alias;
}

/** Same daily-row test as daily_report_view.php dal_row_period(). */
function oa_is_daily_remark($remarks): bool
{
    $rmk = (string)$remarks;
    if (preg_match('/week_split/i', $rmk)) {
        return false;
    }
    if (preg_match('/mtd_split/i', $rmk)) {
        return false;
    }
    return true;
}

function oa_daily_remarks_sql(string $alias = 'dr'): string
{
    $a = oa_sql_alias($alias);
    return '(' . $a . '.remarks IS NULL OR (' . $a . '.remarks NOT LIKE ' . "'%week_split%'"
        . ' AND ' . $a . '.remarks NOT LIKE ' . "'%mtd_split%'" . '))';
}

function oa_food_pct($raw): float
{
    if ($raw === null || $raw === '' || !is_numeric($raw)) {
        return 38.0;
    }
    $n = (float)$raw;
    if ($n < 0) {
        return 0.0;
    }
    if ($n > 100) {
        return 100.0;
    }
    return round($n, 4);
}

function oa_weeks($raw): int
{
    $n = (int)$raw;
    if ($n < 1) {
        return 8;
    }
    if ($n > 104) {
        return 104;
    }
    return $n;
}

/**
 * Inclusive window. useWeeks forces the last N weeks ending on $today.
 * Otherwise a valid from/to pair is used as typed.
 *
 * @return array{0:string,1:string,2:int}
 */
function oa_resolve_window(int $weeks, string $from, string $to, string $today, bool $useWeeks): array
{
    $weeks = oa_weeks($weeks);
    if (!$useWeeks && oa_is_date($from) && oa_is_date($to)) {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [$from, $to, $weeks];
    }
    $end = oa_is_date($today) ? $today : date('Y-m-d');
    $start = date('Y-m-d', strtotime($end . ' 12:00:00 -' . (($weeks * 7) - 1) . ' days'));
    return [$start, $end, $weeks];
}

function oa_span_days(string $from, string $to): int
{
    $a = strtotime($from . ' 12:00:00');
    $b = strtotime($to . ' 12:00:00');
    if ($a === false || $b === false) {
        return 0;
    }
    return (int)round(($b - $a) / 86400) + 1;
}

function oa_weekday_index(string $date): int
{
    $ts = strtotime($date . ' 12:00:00');
    if ($ts === false) {
        return 0;
    }
    return (int)date('N', $ts);
}

function oa_stale_cutoff(string $today): string
{
    $base = oa_is_date($today) ? $today : date('Y-m-d');
    return date('Y-m-d', strtotime($base . ' 12:00:00 -13 days'));
}

function oa_is_stale(?string $latest, string $today): bool
{
    if ($latest === null || !oa_is_date($latest)) {
        return true;
    }
    return $latest < oa_stale_cutoff($today);
}

/**
 * Sum daily sales by site and date. Weekly/monthly rows, zero and negative
 * days, unknown sites, and Temp (103) are left out.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,float>>
 */
function oa_aggregate_days(array $rows): array
{
    $allowed = array_flip(oa_site_ids());
    $sum = [];
    foreach ($rows as $row) {
        $id = (int)($row['restaurant_id'] ?? $row['site_id'] ?? 0);
        if ($id === oa_excluded_site_id() || !isset($allowed[$id])) {
            continue;
        }
        if (!oa_is_daily_remark($row['remarks'] ?? null)) {
            continue;
        }
        $date = substr((string)($row['report_date'] ?? ''), 0, 10);
        if (!oa_is_date($date)) {
            continue;
        }
        if (!isset($sum[$id][$date])) {
            $sum[$id][$date] = 0.0;
        }
        $sum[$id][$date] += (float)($row['pos_sales'] ?? 0);
    }
    $out = [];
    foreach ($sum as $id => $dates) {
        foreach ($dates as $date => $sales) {
            if ($sales <= 0) {
                continue;
            }
            $out[$id][$date] = $sales;
        }
    }
    return $out;
}

/**
 * @param array<string,float> $dateSales date => positive daily sales
 * @return array<string,mixed>
 */
function oa_store_report(int $siteId, string $name, array $dateSales, ?string $latest, string $today, float $foodPct, bool $inOrg): array
{
    $buckets = [1 => [], 2 => [], 3 => [], 4 => [], 5 => [], 6 => [], 7 => []];
    $total = 0.0;
    foreach ($dateSales as $date => $sales) {
        $n = oa_weekday_index((string)$date);
        if ($n < 1 || $n > 7) {
            continue;
        }
        $buckets[$n][] = (float)$sales;
        $total += (float)$sales;
    }
    $avg = [];
    $counts = [];
    $missing = [];
    $labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    foreach ($buckets as $n => $vals) {
        $counts[$n] = count($vals);
        $avg[$n] = $counts[$n] ? array_sum($vals) / $counts[$n] : 0.0;
        if ($counts[$n] === 0) {
            $missing[] = $labels[$n];
        }
    }
    $days = array_sum($counts);
    $orders = $days > 0 ? oa_orders($avg, $foodPct) : [
        'monday_order' => null,
        'friday_order' => null,
        'first_monday' => null,
        'first_friday' => null,
        'week_total' => null,
    ];
    return [
        'site_id' => $siteId,
        'name' => $name,
        'in_org' => $inOrg,
        'days' => $days,
        'avg_daily' => $days > 0 ? round($total / $days, 2) : null,
        'weekday_avg' => $avg,
        'weekday_n' => $counts,
        'missing_weekdays' => $missing,
        'monday_order' => $orders['monday_order'],
        'friday_order' => $orders['friday_order'],
        'first_monday' => $orders['first_monday'],
        'first_friday' => $orders['first_friday'],
        'week_total' => $orders['week_total'],
        'latest' => ($latest !== null && oa_is_date($latest)) ? $latest : null,
        'stale' => oa_is_stale($latest, $today),
        'little_data' => $days > 0 && $days < 7,
        'empty' => $days === 0,
    ];
}

/**
 * Weekday averages are Mon=1 .. Sun=7. A weekday with no positive days is 0.
 *
 * Monday order  = (Mon+Tue+Wed+Thu) x food%
 * Friday order  = (Fri+Sat+Sun) x food%
 * First Monday  = Monday order + Friday average x food%
 * First Friday  = Friday order + Monday average x food%
 * Week total    = Monday order + Friday order
 *
 * @param array<int,float> $weekdayAvgs
 * @return array{monday_order:float,friday_order:float,first_monday:float,first_friday:float,week_total:float}
 */
function oa_orders(array $weekdayAvgs, float $foodPct): array
{
    $pct = $foodPct / 100;
    $monBase = (float)($weekdayAvgs[1] ?? 0) + (float)($weekdayAvgs[2] ?? 0)
        + (float)($weekdayAvgs[3] ?? 0) + (float)($weekdayAvgs[4] ?? 0);
    $friBase = (float)($weekdayAvgs[5] ?? 0) + (float)($weekdayAvgs[6] ?? 0) + (float)($weekdayAvgs[7] ?? 0);
    $monday = $monBase * $pct;
    $friday = $friBase * $pct;
    return [
        'monday_order' => round($monday, 2),
        'friday_order' => round($friday, 2),
        'first_monday' => round($monday + ((float)($weekdayAvgs[5] ?? 0) * $pct), 2),
        'first_friday' => round($friday + ((float)($weekdayAvgs[1] ?? 0) * $pct), 2),
        'week_total' => round($monday + $friday, 2),
    ];
}

function oa_money($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    return '$' . number_format((float)$value, 2);
}

/**
 * Read-only load for org 2 and the six stores. Throws if the database rejects the SELECT.
 *
 * @return array<string,mixed>
 */
function oa_load(PDO $pdo, string $from, string $to, string $today, float $foodPct): array
{
    $orgId = oa_org_id();
    $ids = oa_site_ids();
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $daily = oa_daily_remarks_sql('dr');
    $sql = "SELECT dr.restaurant_id, dr.report_date, dr.pos_sales, dr.remarks
            FROM daily_report dr
            INNER JOIN sites s ON s.SId = dr.restaurant_id AND s.org_id = ?
            WHERE dr.restaurant_id IN ($marks)
              AND dr.report_date BETWEEN ? AND ?
              AND $daily";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$orgId], $ids, [$from, $to]));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $names = [];
    $nq = $pdo->prepare("SELECT SId, nick_name FROM sites WHERE org_id = ? AND SId IN ($marks)");
    $nq->execute(array_merge([$orgId], $ids));
    foreach ($nq->fetchAll(PDO::FETCH_ASSOC) as $site) {
        $names[(int)$site['SId']] = (string)$site['nick_name'];
    }

    $latest = [];
    $lq = $pdo->prepare("SELECT dr.restaurant_id, MAX(dr.report_date) AS latest
            FROM daily_report dr
            INNER JOIN sites s ON s.SId = dr.restaurant_id AND s.org_id = ?
            WHERE dr.restaurant_id IN ($marks)
              AND dr.pos_sales > 0
              AND $daily
            GROUP BY dr.restaurant_id");
    $lq->execute(array_merge([$orgId], $ids));
    foreach ($lq->fetchAll(PDO::FETCH_ASSOC) as $hit) {
        $latest[(int)$hit['restaurant_id']] = substr((string)$hit['latest'], 0, 10);
    }

    $bySite = oa_aggregate_days($rows);
    $stores = [];
    foreach (oa_sites() as $id => $canonical) {
        $label = trim((string)($names[$id] ?? ''));
        if ($label === '') {
            $label = $canonical;
        }
        $stores[] = oa_store_report(
            (int)$id,
            $label,
            $bySite[$id] ?? [],
            $latest[$id] ?? null,
            $today,
            $foodPct,
            isset($names[$id])
        );
    }
    return [
        'org_id' => $orgId,
        'from' => $from,
        'to' => $to,
        'today' => $today,
        'cutoff' => oa_stale_cutoff($today),
        'food_pct' => $foodPct,
        'span_days' => oa_span_days($from, $to),
        'stores' => $stores,
    ];
}
