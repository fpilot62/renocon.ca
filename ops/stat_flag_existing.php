<?php
/**
 * Fill stat_flag on statutory lines that are still waiting for approval.
 * Writes that column only. Does not change amounts, status, approval
 * fields, or delete a row. An end date or ROE before the holiday is a
 * flag, not a reason to remove the line.
 *
 *   php ops/stat_flag_existing.php dry
 *   php ops/stat_flag_existing.php apply /path/flags.tsv
 *   php ops/stat_flag_existing.php rollback /path/flags.tsv
 */
if (!defined('STAT_FLAG_LIB')) {
    $app = getenv('PAYROLL_APP') ?: '/home/renocon/public_html/payroll';
    require $app . '/db.php';
    require $app . '/payroll_engine/payroll_engine.php';
    require $app . '/payroll_engine/stat_holidays.php';
    if (!isset($pdo) || !$pdo instanceof PDO) {
        fwrite(STDERR, "db.php did not provide a connection\n");
        exit(1);
    }
    $mode = $argv[1] ?? 'dry';
    $tsv = $argv[2] ?? '';
    $asOf = getenv('STAT_ASOF') ?: null;
    try {
        if ($mode === 'dry') {
            $plan = stat_flag_plan($pdo, $asOf);
            stat_flag_print($plan);
            echo "Dry run wrote nothing.\n";
        } elseif ($mode === 'apply') {
            if ($tsv === '') {
                fwrite(STDERR, "Usage: php stat_flag_existing.php apply /path/flags.tsv\n");
                exit(1);
            }
            $plan = stat_flag_plan($pdo, $asOf);
            stat_flag_print($plan);
            $n = stat_flag_apply($pdo, $plan, $tsv);
            echo "Updated $n stat_flag values. Amounts, status, and approval fields were not changed.\n";
        } elseif ($mode === 'rollback') {
            if ($tsv === '' || !is_file($tsv)) {
                fwrite(STDERR, "Usage: php stat_flag_existing.php rollback /path/flags.tsv\n");
                exit(1);
            }
            $n = stat_flag_rollback($pdo, $tsv);
            echo "Restored stat_flag on $n rows.\n";
        } else {
            fwrite(STDERR, "Usage: php stat_flag_existing.php dry|apply|rollback\n");
            exit(1);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Stat flag failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
    exit(0);
}

function stat_flag_period(PDO $pdo, int $orgId, int $siteId, string $day): array {
    $day = substr($day, 0, 10);
    if (function_exists('pe_period_window') && function_exists('pe_period_mode_for_site')) {
        $mode = pe_period_mode_for_site($pdo, $siteId, $orgId);
        $anchor = function_exists('pe_period_anchor_for_org') ? pe_period_anchor_for_org($pdo, $orgId) : '2024-01-01';
        return pe_period_window($day, $mode, $anchor);
    }
    $anchor = '2024-01-01';
    $span = 14;
    $d0 = new DateTimeImmutable($anchor);
    $d1 = new DateTimeImmutable($day);
    $days = (int)$d0->diff($d1)->format('%r%a');
    $block = (int)floor($days / $span);
    $start = $d0->modify(($block * $span) . ' days');
    $end = $start->modify('+' . ($span - 1) . ' days');
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

/** The holiday this pay line belongs to, or null when the calendar cannot say. */
function stat_flag_holiday(PDO $pdo, array $row): ?array {
    $prov = sh_normalize_province((string)($row['province'] ?? ''));
    if (!in_array($prov, ['ON', 'SK', 'BC'], true)) {
        return null;
    }
    $work = substr((string)$row['work_date'], 0, 10);
    foreach (sh_calendar_holidays($prov) as $holiday) {
        if ($holiday['holiday_date'] === $work) {
            return $holiday;
        }
    }
    $found = [];
    $days = [$work];
    $periodEnd = substr((string)($row['pay_period_end'] ?? ''), 0, 10);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)) {
        $days[] = $periodEnd;
    }
    foreach ($days as $day) {
        [$start, $end] = stat_flag_period($pdo, (int)$row['org_id'], (int)$row['site_id'], $day);
        foreach (sh_calendar_holidays($prov, $start, $end) as $holiday) {
            $found[$holiday['holiday_date']] = $holiday;
        }
    }
    if (count($found) === 1) {
        return array_values($found)[0];
    }
    return null;
}

function stat_flag_reason(PDO $pdo, int $orgId, int $userId, int $siteId, string $holidayDate, string $province, ?string $asOf): string {
    $parts = [];
    if (sh_employment_ended_before($pdo, $userId, $siteId, $orgId, $holidayDate)) {
        $parts[] = 'Ended before holiday';
    }
    $shift = sh_stat_flag_text($pdo, $userId, $siteId, $holidayDate, $province, $asOf);
    if ($shift !== '') {
        $parts[] = $shift;
    }
    return implode('; ', $parts);
}

function stat_flag_candidates(PDO $pdo): array {
    $sql = "SELECT f.id, f.user_id, f.org_id, f.site_id, f.work_date, f.pay_period_end,
                   f.total_gross, f.status, f.stat_flag, f.stat_approval_status,
                   f.stat_edited_by, f.stat_approved_at, s.province
              FROM payroll_finalized f
              LEFT JOIN sites s ON s.SId = f.site_id
             WHERE f.category = 'Statutory Holiday'
               AND f.stat_approval_status = 'pending'
               AND f.stat_flag IS NULL
               AND f.stat_edited_by IS NULL
               AND f.stat_approved_at IS NULL
             ORDER BY f.work_date, f.user_id, f.id";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function stat_flag_plan(PDO $pdo, ?string $asOf = null): array {
    $flagged = [];
    $clear = [];
    $unknown = [];
    foreach (stat_flag_candidates($pdo) as $row) {
        $holiday = stat_flag_holiday($pdo, $row);
        if ($holiday === null) {
            $unknown[] = $row;
            continue;
        }
        $reason = stat_flag_reason(
            $pdo,
            (int)$row['org_id'],
            (int)$row['user_id'],
            (int)$row['site_id'],
            $holiday['holiday_date'],
            (string)($row['province'] ?? ''),
            $asOf
        );
        $item = $row;
        $item['holiday_date'] = $holiday['holiday_date'];
        $item['holiday_name'] = $holiday['holiday_name'];
        $item['reason'] = $reason;
        if ($reason === '') {
            $clear[] = $item;
        } else {
            $flagged[] = $item;
        }
    }
    return ['flagged' => $flagged, 'clear' => $clear, 'unknown' => $unknown];
}

function stat_flag_print(array $plan): void {
    $groups = [];
    foreach (array_merge($plan['flagged'], $plan['clear']) as $row) {
        $key = $row['holiday_date'] . '|' . $row['holiday_name'] . '|' . sh_normalize_province((string)$row['province']);
        if (!isset($groups[$key])) {
            $groups[$key] = ['date' => $row['holiday_date'], 'name' => $row['holiday_name'], 'province' => sh_normalize_province((string)$row['province']), 'reasons' => [], 'clear' => 0];
        }
        if ($row['reason'] === '') {
            $groups[$key]['clear']++;
        } else {
            $groups[$key]['reasons'][$row['reason']] = ($groups[$key]['reasons'][$row['reason']] ?? 0) + 1;
        }
    }
    ksort($groups);
    foreach ($groups as $group) {
        $flagged = array_sum($group['reasons']);
        echo $group['date'] . ' ' . $group['name'] . ' ' . $group['province'] . " flagged $flagged clear {$group['clear']}\n";
        ksort($group['reasons']);
        foreach ($group['reasons'] as $reason => $count) {
            echo "  $count $reason\n";
        }
    }
    echo 'Undetermined ' . count($plan['unknown']) . "\n";
    foreach ($plan['unknown'] as $row) {
        echo 'unknown id=' . (int)$row['id']
            . ' user=' . (int)$row['user_id']
            . ' site=' . (int)$row['site_id']
            . ' work_date=' . substr((string)$row['work_date'], 0, 10)
            . ' amount=' . number_format((float)$row['total_gross'], 2, '.', '')
            . "\n";
    }
    echo "Lines to flag " . count($plan['flagged']) . "\n";
    foreach ($plan['flagged'] as $row) {
        echo 'user=' . (int)$row['user_id']
            . ' site=' . (int)$row['site_id']
            . ' holiday=' . $row['holiday_date']
            . ' amount=' . number_format((float)$row['total_gross'], 2, '.', '')
            . ' reason=' . $row['reason']
            . "\n";
    }
}

function stat_flag_write_tsv(string $path, array $flagged): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $fh = fopen($path, 'wb');
    if ($fh === false) {
        throw new RuntimeException('Could not write ' . $path);
    }
    fputcsv($fh, ['id', 'stat_flag'], "\t");
    foreach ($flagged as $row) {
        $old = $row['stat_flag'];
        fputcsv($fh, [(int)$row['id'], $old === null || $old === '' ? '\\N' : $old], "\t");
    }
    fclose($fh);
}

function stat_flag_apply(PDO $pdo, array $plan, string $tsv): int {
    stat_flag_write_tsv($tsv, $plan['flagged']);
    $upd = $pdo->prepare(
        "UPDATE payroll_finalized
            SET stat_flag=?
          WHERE id=?
            AND category='Statutory Holiday'
            AND stat_approval_status='pending'
            AND stat_flag IS NULL
            AND stat_edited_by IS NULL
            AND stat_approved_at IS NULL"
    );
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $n = 0;
        foreach ($plan['flagged'] as $row) {
            $upd->execute([$row['reason'], (int)$row['id']]);
            $n += $upd->rowCount();
        }
        if ($own && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $n;
}

function stat_flag_rollback(PDO $pdo, string $tsv): int {
    $fh = fopen($tsv, 'rb');
    if ($fh === false) {
        throw new RuntimeException('Could not read ' . $tsv);
    }
    $header = fgetcsv($fh, 0, "\t");
    if ($header === false || ($header[0] ?? '') !== 'id') {
        fclose($fh);
        throw new RuntimeException('Flag backup is not a TSV of ids');
    }
    $upd = $pdo->prepare('UPDATE payroll_finalized SET stat_flag=? WHERE id=?');
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $n = 0;
        while (($cols = fgetcsv($fh, 0, "\t")) !== false) {
            if (!isset($cols[0]) || $cols[0] === '') {
                continue;
            }
            $old = $cols[1] ?? '\\N';
            if ($old === '\\N') {
                $upd->bindValue(1, null, PDO::PARAM_NULL);
            } else {
                $upd->bindValue(1, $old, PDO::PARAM_STR);
            }
            $upd->bindValue(2, (int)$cols[0], PDO::PARAM_INT);
            $upd->execute();
            $n += $upd->rowCount();
        }
        fclose($fh);
        if ($own && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        fclose($fh);
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $n;
}
