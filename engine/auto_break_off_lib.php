<?php
/**
 * Turn automatic meal breaks off and remove every source='auto' row.
 * Punched breaks are not deleted. Paid and cheque-printed lines are not changed.
 * Statutory lines and their approval columns are not updated.
 */

const ABO_SPLIT = '2026-11-18';

function abo_columns(PDO $pdo, string $table): array
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return [];
    }
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
            $names = [];
            foreach ($rows as $row) {
                $names[] = (string)$row['name'];
            }
            return $names;
        }
        return $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function abo_has(array $cols, string $name): bool
{
    return in_array($name, $cols, true);
}

function abo_pick(array $cols, array $candidates): ?string
{
    $byLower = [];
    foreach ($cols as $col) {
        $byLower[strtolower((string)$col)] = (string)$col;
    }
    foreach ($candidates as $candidate) {
        $key = strtolower($candidate);
        if (isset($byLower[$key])) {
            return $byLower[$key];
        }
    }
    return null;
}

function abo_ident(string $name): string
{
    return '`' . str_replace('`', '', $name) . '`';
}

function abo_schema(PDO $pdo): array
{
    static $cache = [];
    $key = spl_object_id($pdo);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $breaks = abo_columns($pdo, 'breaks');
    $attendance = abo_columns($pdo, 'attendance');
    $sites = abo_columns($pdo, 'sites');
    $cache[$key] = [
        'breaks' => $breaks,
        'attendance' => $attendance,
        'source' => abo_pick($breaks, ['source', 'break_source']),
        'shift_id' => abo_pick($breaks, ['shift_id', 'attendance_id']),
        'break_start' => abo_pick($breaks, ['break_start', 'start_time', 'started_at']),
        'break_end' => abo_pick($breaks, ['break_end', 'end_time', 'ended_at']),
        'date' => abo_pick($attendance, ['clock_in', 'work_date', 'shift_date', 'date', 'created_at']),
        'user_id' => abo_pick($attendance, ['user_id', 'employee_id']),
        'site_id' => abo_pick($attendance, ['site_id']),
        'att_id' => abo_pick($attendance, ['id']),
        'site_key' => abo_pick($sites, ['SId', 'sid', 'id', 'site_id']),
        'site_name' => abo_pick($sites, ['nick_name', 'name', 'site_name']),
    ];
    return $cache[$key];
}

function abo_seconds_expr(PDO $pdo, ?array $schema = null): string
{
    $schema = $schema ?? abo_schema($pdo);
    $start = abo_ident((string)($schema['break_start'] ?? 'break_start'));
    $end = abo_ident((string)($schema['break_end'] ?? 'break_end'));
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        return "CAST(strftime('%s', b.$end) AS INTEGER) - CAST(strftime('%s', b.$start) AS INTEGER)";
    }
    return "TIMESTAMPDIFF(SECOND, b.$start, b.$end)";
}

function abo_auto_where(PDO $pdo, ?array $schema = null): string
{
    $schema = $schema ?? abo_schema($pdo);
    $source = abo_ident((string)$schema['source']);
    return "LOWER(TRIM(COALESCE(b.$source,''))) = 'auto'";
}

/**
 * @return array<string,mixed>
 */
function abo_summarize(PDO $pdo, string $split = ABO_SPLIT): array
{
    $schema = abo_schema($pdo);
    if ($schema['source'] === null || $schema['shift_id'] === null || $schema['date'] === null || $schema['att_id'] === null) {
        return [
            'split' => $split,
            'schema' => $schema,
            'error' => 'Detected columns are not enough to count auto breaks.',
            'all' => ['breaks' => 0, 'employees' => 0, 'shifts' => 0, 'minutes' => 0, 'hours' => 0],
            'before' => ['breaks' => 0, 'employees' => 0, 'shifts' => 0, 'minutes' => 0, 'hours' => 0],
            'after' => ['breaks' => 0, 'employees' => 0, 'shifts' => 0, 'minutes' => 0, 'hours' => 0],
            'sites' => [],
            'lines' => ['pending' => 0, 'paid' => 0, 'cheque_printed' => 0, 'other' => 0, 'stat_pending' => 0, 'stat_approved' => 0, 'stat_lines' => 0, 'payrun_locked' => 0],
            'topups' => [],
        ];
    }
    $secs = abo_seconds_expr($pdo, $schema);
    $where = abo_auto_where($pdo, $schema);
    $shiftCol = abo_ident((string)$schema['shift_id']);
    $attId = abo_ident((string)$schema['att_id']);
    $dateCol = abo_ident((string)$schema['date']);
    $userCol = abo_ident((string)($schema['user_id'] ?? 'user_id'));
    $base = "FROM breaks b INNER JOIN attendance a ON a.$attId = b.$shiftCol WHERE $where";
    $one = function (string $extra) use ($pdo, $base, $secs, $userCol, $shiftCol): array {
        $sql = "SELECT COUNT(*) AS breaks,
                       COUNT(DISTINCT a.$userCol) AS employees,
                       COUNT(DISTINCT b.$shiftCol) AS shifts,
                       COALESCE(SUM(CASE WHEN ($secs) > 0 THEN ($secs) ELSE 0 END), 0) AS seconds
                $base $extra";
        $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
        $seconds = (int)($row['seconds'] ?? 0);
        return [
            'breaks' => (int)($row['breaks'] ?? 0),
            'employees' => (int)($row['employees'] ?? 0),
            'shifts' => (int)($row['shifts'] ?? 0),
            'minutes' => intdiv($seconds, 60),
            'hours' => round($seconds / 3600, 2),
        ];
    };
    $siteSelect = '0 AS site_id';
    $siteJoin = '';
    $siteGroup = '';
    if ($schema['site_id'] !== null) {
        $siteCol = abo_ident((string)$schema['site_id']);
        $siteSelect = "a.$siteCol AS site_id";
        $siteGroup = "a.$siteCol";
        if ($schema['site_key'] !== null) {
            $siteKey = abo_ident((string)$schema['site_key']);
            $nameExpr = $schema['site_name'] !== null
                ? 'COALESCE(s.' . abo_ident((string)$schema['site_name']) . ", '')"
                : "''";
            $siteSelect .= ", $nameExpr AS site_name";
            $siteJoin = " LEFT JOIN sites s ON s.$siteKey = a.$siteCol";
            $siteGroup .= ', ' . ($schema['site_name'] !== null ? 's.' . abo_ident((string)$schema['site_name']) : 'site_id');
        } else {
            $siteSelect .= ", '' AS site_name";
        }
    } else {
        $siteSelect .= ", '' AS site_name";
        $siteGroup = 'site_id';
    }
    $sites = $pdo->query(
        "SELECT $siteSelect,
                COUNT(*) AS breaks,
                COALESCE(SUM(CASE WHEN ($secs) > 0 THEN ($secs) ELSE 0 END), 0) AS seconds
         FROM breaks b
         INNER JOIN attendance a ON a.$attId = b.$shiftCol
         $siteJoin
         WHERE $where
         GROUP BY $siteGroup
         ORDER BY site_id"
    )->fetchAll(PDO::FETCH_ASSOC);

    $lines = abo_line_counts($pdo);
    return [
        'split' => $split,
        'schema' => $schema,
        'error' => '',
        'all' => $one(''),
        'before' => $one('AND DATE(a.' . $dateCol . ') < ' . $pdo->quote($split)),
        'after' => $one('AND DATE(a.' . $dateCol . ') >= ' . $pdo->quote($split)),
        'sites' => $sites,
        'lines' => $lines,
        'topups' => abo_topups($pdo),
    ];
}

function abo_line_counts(PDO $pdo): array
{
    $empty = [
        'pending' => 0, 'paid' => 0, 'cheque_printed' => 0, 'other' => 0,
        'stat_pending' => 0, 'stat_approved' => 0, 'stat_lines' => 0, 'payrun_locked' => 0,
    ];
    if (!abo_columns($pdo, 'payroll_finalized')) {
        return $empty;
    }
    $cols = abo_columns($pdo, 'payroll_finalized');
    $statCol = abo_has($cols, 'stat_approval_status') ? 'f.stat_approval_status' : "'' AS stat_approval_status";
    $payCol = abo_has($cols, 'payment_status') ? 'f.payment_status' : "'' AS payment_status";
    $paidCol = abo_has($cols, 'paid_amount') ? 'f.paid_amount' : '0 AS paid_amount';
    $runCol = abo_has($cols, 'payroll_id') ? 'f.payroll_id' : '0 AS payroll_id';
    $rows = $pdo->query(
        "SELECT f.id, f.attendance_id, f.category, f.status, $payCol, $paidCol, $runCol, $statCol
         FROM payroll_finalized f
         WHERE f.attendance_id IN (
            SELECT b." . abo_ident((string)(abo_schema($pdo)['shift_id'] ?? 'shift_id')) . " FROM breaks b WHERE " . abo_auto_where($pdo) . "
         )"
    )->fetchAll(PDO::FETCH_ASSOC);
    $exported = abo_exported_payroll_ids($pdo);
    foreach ($rows as $row) {
        if (abo_is_stat($row)) {
            $empty['stat_lines']++;
            $approval = strtolower(trim((string)($row['stat_approval_status'] ?? '')));
            if ($approval === 'pending') {
                $empty['stat_pending']++;
            } elseif ($approval === 'approved') {
                $empty['stat_approved']++;
            }
        }
        if (abo_is_locked($row) || isset($exported[(int)($row['payroll_id'] ?? 0)])) {
            if (abo_is_cheque($row)) {
                $empty['cheque_printed']++;
            } elseif (abo_is_paid($row) || isset($exported[(int)($row['payroll_id'] ?? 0)])) {
                $empty['paid']++;
                if (isset($exported[(int)($row['payroll_id'] ?? 0)])) {
                    $empty['payrun_locked']++;
                }
            } else {
                $empty['other']++;
            }
            continue;
        }
        if (abo_is_stat($row)) {
            continue;
        }
        $empty['pending']++;
    }
    return $empty;
}

function abo_is_stat(array $line): bool
{
    $cat = strtolower((string)($line['category'] ?? ''));
    $type = strtolower((string)($line['pay_type'] ?? ''));
    return str_contains($cat, 'statutor') || str_contains($cat, 'holiday') || $type === 'statutory';
}

function abo_is_cheque(array $line): bool
{
    $blob = strtolower(trim((string)($line['payment_status'] ?? '') . ' ' . (string)($line['status'] ?? '')));
    return str_contains($blob, 'cheque_printed') || str_contains($blob, 'cheque printed');
}

function abo_is_paid(array $line): bool
{
    if ((float)($line['paid_amount'] ?? 0) > 0.009) {
        return true;
    }
    $pay = strtolower(trim((string)($line['payment_status'] ?? '')));
    $status = strtolower(trim((string)($line['status'] ?? '')));
    return in_array($pay, ['paid', 'remitted', 'partial'], true) || in_array($status, ['paid', 'remitted'], true);
}

function abo_is_locked(array $line): bool
{
    return abo_is_cheque($line) || abo_is_paid($line);
}

function abo_exported_payroll_ids(PDO $pdo): array
{
    $cols = abo_columns($pdo, 'payroll_masters');
    if ($cols === []) {
        return [];
    }
    $parts = [];
    if (abo_has($cols, 'status')) {
        $parts[] = "LOWER(COALESCE(status,'')) IN ('paid','exported','cheque_printed')";
    }
    if (abo_has($cols, 'exported_at')) {
        $parts[] = "exported_at IS NOT NULL AND TRIM(exported_at) <> '' AND exported_at <> '0000-00-00 00:00:00'";
    }
    if (abo_has($cols, 'qb_exported')) {
        $parts[] = 'qb_exported = 1';
    }
    if ($parts === []) {
        return [];
    }
    try {
        $ids = $pdo->query('SELECT id FROM payroll_masters WHERE ' . implode(' OR ', $parts))->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $out[$id] = true;
        }
    }
    return $out;
}

function abo_topups(PDO $pdo): array
{
    if (!abo_columns($pdo, 'payroll_finalized')) {
        return [];
    }
    $exported = abo_exported_payroll_ids($pdo);
    $cols = abo_columns($pdo, 'payroll_finalized');
    $payCol = abo_has($cols, 'payment_status') ? 'f.payment_status' : "'' AS payment_status";
    $paidCol = abo_has($cols, 'paid_amount') ? 'f.paid_amount' : '0 AS paid_amount';
    $runCol = abo_has($cols, 'payroll_id') ? 'f.payroll_id' : '0 AS payroll_id';
    $rows = $pdo->query(
        "SELECT f.id, f.user_id, f.site_id, f.work_date, f.attendance_id, f.status, $payCol,
                $paidCol, f.reg_hours, f.total_gross, $runCol, f.category,
                u.Name AS employee_name, s.nick_name AS site_name
         FROM payroll_finalized f
         INNER JOIN attendance a ON a.id = f.attendance_id
         LEFT JOIN users u ON u.id = f.user_id
         LEFT JOIN sites s ON s.SId = f.site_id
         WHERE f.attendance_id IN (SELECT b." . abo_ident((string)(abo_schema($pdo)['shift_id'] ?? 'shift_id')) . " FROM breaks b WHERE " . abo_auto_where($pdo) . ")
         ORDER BY f.user_id, f.work_date, f.id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        $run = isset($exported[(int)($row['payroll_id'] ?? 0)]);
        if (!abo_is_locked($row) && !$run) {
            continue;
        }
        if (abo_is_stat($row) && !abo_is_locked($row) && !$run) {
            continue;
        }
        $row['needs_topup'] = true;
        $row['payrun'] = $run;
        $out[] = $row;
    }
    return $out;
}

function abo_format_bucket(string $label, array $bucket): string
{
    return $label . "\n"
        . '  breaks: ' . $bucket['breaks'] . "\n"
        . '  minutes: ' . $bucket['minutes'] . "\n"
        . '  hours: ' . number_format((float)$bucket['hours'], 2, '.', '') . "\n"
        . '  employees: ' . $bucket['employees'] . "\n"
        . '  shifts: ' . $bucket['shifts'];
}

function abo_format_detected(array $schema): string
{
    $lines = [];
    $lines[] = 'detected breaks columns: ' . ($schema['breaks'] === [] ? '(none)' : implode(', ', $schema['breaks']));
    $lines[] = 'detected attendance columns: ' . ($schema['attendance'] === [] ? '(none)' : implode(', ', $schema['attendance']));
    $lines[] = 'detected breaks source column: ' . ($schema['source'] ?? 'NOT FOUND');
    $lines[] = 'detected attendance date column: ' . ($schema['date'] ?? 'NOT FOUND');
    $lines[] = 'detected break shift column: ' . ($schema['shift_id'] ?? 'NOT FOUND');
    return implode("\n", $lines);
}

function abo_format_report(array $report): string
{
    $lines = [];
    $lines[] = abo_format_detected($report['schema']);
    if (!empty($report['error'])) {
        $lines[] = 'count error: ' . $report['error'];
    }
    $lines[] = 'AUTO BREAKS OFF — read only';
    $lines[] = abo_format_bucket('all dates', $report['all']);
    $lines[] = 'by site:';
    if ($report['sites'] === []) {
        $lines[] = '  (none)';
    }
    foreach ($report['sites'] as $site) {
        $hours = round(((int)$site['seconds']) / 3600, 2);
        $name = trim((string)$site['site_name']);
        $lines[] = '  site ' . (int)$site['site_id'] . ($name !== '' ? ' ' . $name : '')
            . ': breaks ' . (int)$site['breaks'] . ', hours ' . number_format($hours, 2, '.', '');
    }
    $lines[] = abo_format_bucket('before ' . $report['split'], $report['before']);
    $lines[] = abo_format_bucket('on or after ' . $report['split'], $report['after']);
    $c = $report['lines'];
    $lines[] = 'finalized lines on affected shifts';
    $lines[] = '  pending: ' . $c['pending'];
    $lines[] = '  paid: ' . $c['paid'];
    $lines[] = '  cheque_printed: ' . $c['cheque_printed'];
    $lines[] = '  other: ' . $c['other'];
    $lines[] = '  stat lines left unchanged: ' . $c['stat_lines'];
    $lines[] = '  stat_approval pending: ' . $c['stat_pending'];
    $lines[] = '  stat_approval approved: ' . $c['stat_approved'];
    $lines[] = '  pay runs already exported or paid: ' . $c['payrun_locked'];
    $lines[] = 'TOP-UP (not recalculated)';
    if ($report['topups'] === []) {
        $lines[] = '  (none)';
    }
    foreach ($report['topups'] as $row) {
        $lines[] = '  line ' . (int)$row['id']
            . ' employee ' . (int)$row['user_id'] . ' ' . trim((string)($row['employee_name'] ?? ''))
            . ' site ' . (int)$row['site_id']
            . ' date ' . substr((string)($row['work_date'] ?? ''), 0, 10)
            . ' status ' . trim((string)($row['status'] ?? ''))
            . ' payment ' . trim((string)($row['payment_status'] ?? ''))
            . ' hours ' . (float)($row['reg_hours'] ?? 0)
            . ' gross ' . (float)($row['total_gross'] ?? 0);
    }
    return implode("\n", $lines);
}

function abo_net_hours(?string $clockIn, ?string $clockOut, int $breakSeconds): float
{
    if ($clockIn === null || $clockOut === null || trim($clockIn) === '' || trim($clockOut) === '') {
        return 0.0;
    }
    $span = strtotime($clockOut) - strtotime($clockIn);
    if ($span === false) {
        return 0.0;
    }
    if ($span < 0) {
        $span += 86400;
    }
    return max(0.0, round(($span - max(0, $breakSeconds)) / 3600, 4));
}

function abo_break_seconds(PDO $pdo, int $shiftId, bool $includeAuto): int
{
    $secs = abo_seconds_expr($pdo);
    $schema = abo_schema($pdo);
    $shiftCol = abo_ident((string)($schema['shift_id'] ?? 'shift_id'));
    $sql = "SELECT COALESCE(SUM(CASE WHEN ($secs) > 0 THEN ($secs) ELSE 0 END), 0)
            FROM breaks b WHERE b.$shiftCol = ?";
    if (!$includeAuto && $schema['source'] !== null) {
        $sql .= " AND NOT (" . abo_auto_where($pdo, $schema) . ")";
    }
    $st = $pdo->prepare($sql);
    $st->execute([$shiftId]);
    return (int)$st->fetchColumn();
}

/**
 * Delete source='auto' rows and recompute pending unpaid lines.
 * Returns the per-employee before/after list. Does not commit.
 *
 * @return array{deleted:int,updated:int,employees:list<array<string,mixed>>,topups:list<array<string,mixed>>}
 */
function abo_apply(PDO $pdo): array
{
    $topups = abo_topups($pdo);
    $blockedShifts = [];
    foreach ($topups as $row) {
        $blockedShifts[(int)$row['attendance_id']] = true;
    }
    $schema = abo_schema($pdo);
    $shiftCol = (string)($schema['shift_id'] ?? 'shift_id');
    $autoIds = $pdo->query('SELECT id, ' . abo_ident($shiftCol) . ' AS shift_id FROM breaks b WHERE ' . abo_auto_where($pdo, $schema))->fetchAll(PDO::FETCH_ASSOC);
    $shiftIds = [];
    foreach ($autoIds as $row) {
        $shiftIds[(int)$row['shift_id']] = true;
    }
    if ($autoIds !== []) {
        $sourceCol = abo_ident((string)$schema['source']);
        $pdo->exec("DELETE FROM breaks WHERE LOWER(TRIM(COALESCE($sourceCol,''))) = 'auto'");
    }
    $cols = abo_columns($pdo, 'payroll_finalized');
    $employees = [];
    $updated = 0;
    foreach (array_keys($shiftIds) as $shiftId) {
        if (isset($blockedShifts[$shiftId])) {
            continue;
        }
        $result = abo_recalc_shift($pdo, (int)$shiftId, $cols);
        $updated += $result['updated'];
        if ($result['employee'] !== null) {
            $key = (int)$result['employee']['user_id'];
            if (!isset($employees[$key])) {
                $employees[$key] = $result['employee'];
            } else {
                $employees[$key]['before_hours'] += $result['employee']['before_hours'];
                $employees[$key]['after_hours'] += $result['employee']['after_hours'];
                $employees[$key]['before_gross'] += $result['employee']['before_gross'];
                $employees[$key]['after_gross'] += $result['employee']['after_gross'];
                $employees[$key]['lines'] += $result['employee']['lines'];
            }
        }
    }
    ksort($employees);
    return [
        'deleted' => count($autoIds),
        'updated' => $updated,
        'employees' => array_values($employees),
        'topups' => $topups,
    ];
}

function abo_format_apply(array $result, string $mode): string
{
    $lines = [];
    $lines[] = strtoupper($mode) . ' automatic breaks removed: ' . (int)$result['deleted'];
    $lines[] = 'pending lines recalculated: ' . (int)$result['updated'];
    $lines[] = 'per employee';
    if ($result['employees'] === []) {
        $lines[] = '  (none)';
    }
    foreach ($result['employees'] as $row) {
        $lines[] = '  employee ' . (int)$row['user_id'] . ' ' . trim((string)$row['name'])
            . ' before hours ' . number_format((float)$row['before_hours'], 4, '.', '')
            . ' gross ' . number_format((float)$row['before_gross'], 2, '.', '')
            . ' after hours ' . number_format((float)$row['after_hours'], 4, '.', '')
            . ' gross ' . number_format((float)$row['after_gross'], 2, '.', '')
            . ' lines ' . (int)$row['lines'];
    }
    $lines[] = 'TOP-UP left unchanged: ' . count($result['topups']);
    foreach ($result['topups'] as $row) {
        $lines[] = '  line ' . (int)$row['id'] . ' employee ' . (int)$row['user_id']
            . ' payment ' . trim((string)($row['payment_status'] ?? ''))
            . ' status ' . trim((string)($row['status'] ?? ''));
    }
    if ($mode === 'dry') {
        $lines[] = 'ROLLBACK dry run, no changes kept';
    }
    return implode("\n", $lines);
}

/**
 * @param list<string> $cols
 * @return array{updated:int,employee:?array<string,mixed>}
 */
function abo_recalc_shift(PDO $pdo, int $shiftId, array $cols): array
{
    $none = ['updated' => 0, 'employee' => null];
    $shift = $pdo->prepare('SELECT id, user_id, org_id, site_id, clock_in, clock_out FROM attendance WHERE id = ?');
    $shift->execute([$shiftId]);
    $att = $shift->fetch(PDO::FETCH_ASSOC);
    if (!$att) {
        return $none;
    }
    $st = $pdo->prepare('SELECT * FROM payroll_finalized WHERE attendance_id = ? ORDER BY id');
    $st->execute([$shiftId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $pending = [];
    foreach ($rows as $row) {
        if (abo_is_stat($row) || abo_is_locked($row)) {
            continue;
        }
        $pending[] = $row;
    }
    if ($pending === []) {
        return $none;
    }
    $beforeHours = 0.0;
    $beforeGross = 0.0;
    $target = null;
    foreach ($pending as $row) {
        $beforeHours += (float)($row['reg_hours'] ?? 0);
        $beforeGross += (float)($row['total_gross'] ?? $row['reg_pay'] ?? 0);
        if ($target === null || (float)($row['reg_hours'] ?? 0) > (float)($target['reg_hours'] ?? 0)) {
            $target = $row;
        }
    }
    $net = abo_net_hours(
        isset($att['clock_in']) ? (string)$att['clock_in'] : null,
        isset($att['clock_out']) ? (string)$att['clock_out'] : null,
        abo_break_seconds($pdo, $shiftId, false)
    );
    $others = $beforeHours - (float)($target['reg_hours'] ?? 0);
    $newHours = round(max(0.0, $net - $others), 4);
    $oldHours = (float)($target['reg_hours'] ?? 0);
    if (abs($newHours - $oldHours) < 0.0001) {
        return $none;
    }
    $rate = (float)($target['hourly_rate'] ?? 0);
    $oldGross = (float)($target['total_gross'] ?? $target['reg_pay'] ?? 0);
    if ($rate > 0) {
        $gross = round($newHours * $rate, 2);
    } elseif ($oldHours > 0) {
        $gross = round($oldGross * ($newHours / $oldHours), 2);
    } else {
        $gross = $oldGross;
    }
    $ded = null;
    if (function_exists('pe_line')) {
        $vac = function_exists('pe_vacation_rate')
            ? (float)pe_vacation_rate($pdo, (int)$att['user_id'], (int)$att['site_id'], substr((string)$att['clock_in'], 0, 10))
            : 0.0;
        $rates = function_exists('pe_rates') ? pe_rates($pdo) : [];
        $ded = pe_line($gross, $vac, $rates, [
            'pdo' => $pdo,
            'user_id' => (int)$att['user_id'],
            'site_id' => (int)$att['site_id'],
            'org_id' => (int)$att['org_id'],
            'work_date' => substr((string)$att['clock_in'], 0, 10),
        ]);
    }
    $sets = [];
    $params = [];
    $put = function (string $col, $value) use (&$sets, &$params, $cols): void {
        if (!abo_has($cols, $col)) {
            return;
        }
        $sets[] = $col . ' = ?';
        $params[] = $value;
    };
    $put('reg_hours', $newHours);
    $put('reg_pay', $gross);
    $put('total_gross', $gross);
    if (is_array($ded)) {
        $put('cpp', round((float)($ded['cpp'] ?? 0) + (float)($ded['cpp2'] ?? 0), 2));
        $put('ei', (float)($ded['ei'] ?? 0));
        $put('income_tax', (float)($ded['income_tax'] ?? 0));
        $put('wcb', (float)($ded['wcb'] ?? 0));
        $put('vac', (float)($ded['vac'] ?? 0));
        $put('cpp_er', round((float)($ded['cpp_er'] ?? 0) + (float)($ded['cpp2_er'] ?? 0), 2));
        $put('ei_er', (float)($ded['ei_er'] ?? 0));
        $put('cpp2', (float)($ded['cpp2'] ?? 0));
        $put('cpp2_er', (float)($ded['cpp2_er'] ?? 0));
    }
    if ($sets === []) {
        return $none;
    }
    $params[] = (int)$target['id'];
    $pdo->prepare('UPDATE payroll_finalized SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    $name = '';
    try {
        $ns = $pdo->prepare('SELECT Name FROM users WHERE id = ?');
        $ns->execute([(int)$att['user_id']]);
        $name = (string)$ns->fetchColumn();
    } catch (Throwable $e) {
        $name = '';
    }
    $afterHours = $beforeHours - $oldHours + $newHours;
    $afterGross = $beforeGross - $oldGross + $gross;
    return [
        'updated' => 1,
        'employee' => [
            'user_id' => (int)$att['user_id'],
            'name' => $name,
            'before_hours' => $beforeHours,
            'after_hours' => $afterHours,
            'before_gross' => $beforeGross,
            'after_gross' => $afterGross,
            'lines' => 1,
        ],
    ];
}
