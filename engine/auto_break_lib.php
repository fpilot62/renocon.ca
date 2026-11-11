<?php
/**
 * Automatic 30-minute meal break for a closed shift longer than 5 hours
 * that has no break recorded.
 *
 * Storage: a normal row in `breaks` (shift_id, break_start, break_end) with
 * source = 'auto'. Staff Audit and any total that sums `breaks` deduct it the
 * same way as a break someone typed. The badge text is "Auto break".
 * Run migrations/20261111_break_source.sql before the first insert. Until that
 * column exists, these functions write nothing.
 *
 * Placement: one 30-minute block centered on the midpoint of [clock_in, clock_out].
 * The midpoint is clock_in plus half the elapsed seconds, rounded down.
 * Both ends stay inside the shift. A shift of exactly 5 hours does not qualify.
 * This is one break for the whole shift, not one break per five hours.
 *
 * Idempotent: a shift keeps at most one source='auto' row. A real break is any
 * other `breaks` row (source manual, blank, or missing) or a legacy pair of
 * attendance.break_start and attendance.break_end. When a real break exists,
 * the auto row is deleted. It is not left in place and ignored.
 *
 * Finalized shifts are never updated. A shift is finalized when any of these
 * is true: attendance.is_finalized = 1, attendance.payout_status is set and
 * not 0, attendance.is_processed = 1, or payroll_finalized has that
 * attendance_id. Staff Audit's own Paid badge checks payout_status = 1 only.
 * This guard is stricter on purpose. Soft-deleted rows are left alone.
 * Transit (status 5 / travel / transfer-out) and split layers (status 6) are
 * not meal-break shifts: Staff Audit already ignores breaks on transit duration,
 * and a split layer is an allocation of another shift.
 *
 * The Staff Audit break editor can delete the Auto break. Saving that form
 * does not put it back. The next create/close of the shift does, if the shift
 * is still over 5 hours and still has no real break. Adding a real break in
 * the editor deletes the Auto break.
 *
 * Call ab_apply_to_shift($pdo, $shiftId) after a shift is inserted or its
 * clock-out is saved, inside the caller's transaction when there is one.
 * Wired in this repo: manual_attendance.php and staff_audit.php (duplicate).
 * Not in this repo, so not wired here: the kiosk clock-out and edit_shift.php.
 * Those files need the same call after they store clock_out.
 */

require_once __DIR__ . '/attendance_integrity_lib.php';

const AB_THRESHOLD_SECONDS = 18000;
const AB_BREAK_SECONDS = 1800;
const AB_LABEL = 'Auto break';

function ab_is_auto_source($source): bool
{
    return strtolower(trim((string)$source)) === 'auto';
}

function ab_parse_dt(?string $value): ?int
{
    if ($value === null) {
        return null;
    }
    $value = trim(str_replace('T', ' ', $value));
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return null;
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    return $ts;
}

function ab_format_dt(int $ts): string
{
    return date('Y-m-d H:i:s', $ts);
}

function ab_normalize_dt(?string $value): ?string
{
    $ts = ab_parse_dt($value);
    if ($ts === null) {
        return null;
    }
    return ab_format_dt($ts);
}

function ab_datetime_local(?string $value): string
{
    $norm = ab_normalize_dt($value);
    if ($norm === null) {
        return '';
    }
    return substr(str_replace(' ', 'T', $norm), 0, 16);
}

/**
 * 30 minutes centered on the floored midpoint, or null when the shift is not
 * a closed span longer than 5 hours.
 *
 * @return array{break_start:string,break_end:string}|null
 */
function ab_window_for_shift(?string $clockIn, ?string $clockOut): ?array
{
    $in = ab_parse_dt($clockIn);
    $out = ab_parse_dt($clockOut);
    if ($in === null || $out === null || $out <= $in) {
        return null;
    }
    $duration = $out - $in;
    if ($duration <= AB_THRESHOLD_SECONDS) {
        return null;
    }
    $mid = $in + intdiv($duration, 2);
    $start = $mid - intdiv(AB_BREAK_SECONDS, 2);
    $end = $start + AB_BREAK_SECONDS;
    if ($start < $in) {
        $start = $in;
        $end = $start + AB_BREAK_SECONDS;
    }
    if ($end > $out) {
        $end = $out;
        $start = $end - AB_BREAK_SECONDS;
    }
    if ($start < $in || $end > $out || $end <= $start) {
        return null;
    }
    return [
        'break_start' => ab_format_dt($start),
        'break_end' => ab_format_dt($end),
    ];
}

function ab_is_deleted(array $row): bool
{
    if (!array_key_exists('deleted_at', $row) || $row['deleted_at'] === null) {
        return false;
    }
    $value = trim((string)$row['deleted_at']);
    return $value !== '' && !str_starts_with($value, '0000-00-00');
}

function ab_is_finalized(array $row, bool $inPayrollFinalized): bool
{
    if ($inPayrollFinalized) {
        return true;
    }
    if ((int)($row['is_finalized'] ?? 0) === 1) {
        return true;
    }
    if (array_key_exists('payout_status', $row)) {
        $payout = $row['payout_status'];
        if ($payout !== null && $payout !== '' && (int)$payout !== 0) {
            return true;
        }
    }
    if ((int)($row['is_processed'] ?? 0) === 1) {
        return true;
    }
    return false;
}

function ab_is_work_shift(array $row): bool
{
    $status = strtolower(trim((string)($row['status'] ?? '')));
    return !in_array($status, ['5', '6', 'travel', 'transfer-out'], true);
}

function ab_legacy_break_recorded(array $row): bool
{
    $start = ab_parse_dt(isset($row['break_start']) ? (string)$row['break_start'] : null);
    $end = ab_parse_dt(isset($row['break_end']) ? (string)$row['break_end'] : null);
    return $start !== null && $end !== null && $end > $start;
}

/**
 * @param list<array<string,mixed>> $breaks
 * @return array{action:string,reason:string,window:?array,auto_ids:list<int>}
 */
function ab_plan(array $shift, array $breaks, bool $inPayrollFinalized): array
{
    $empty = ['action' => 'skip', 'reason' => '', 'window' => null, 'auto_ids' => []];
    if (ab_is_deleted($shift)) {
        $empty['reason'] = 'deleted';
        return $empty;
    }
    if (ab_is_finalized($shift, $inPayrollFinalized)) {
        $empty['reason'] = 'finalized';
        return $empty;
    }

    $autoIds = [];
    $hasReal = ab_legacy_break_recorded($shift);
    foreach ($breaks as $break) {
        if (ab_is_auto_source($break['source'] ?? null)) {
            $autoIds[] = (int)($break['id'] ?? 0);
            continue;
        }
        $hasReal = true;
    }
    if (!ab_is_work_shift($shift)) {
        if ($autoIds !== []) {
            return ['action' => 'remove_auto', 'reason' => 'not a work shift', 'window' => null, 'auto_ids' => $autoIds];
        }
        $empty['reason'] = 'not a work shift';
        return $empty;
    }
    if ($hasReal) {
        if ($autoIds !== []) {
            return ['action' => 'remove_auto', 'reason' => 'real break', 'window' => null, 'auto_ids' => $autoIds];
        }
        $empty['reason'] = 'real break';
        return $empty;
    }

    $window = ab_window_for_shift(
        isset($shift['clock_in']) ? (string)$shift['clock_in'] : null,
        isset($shift['clock_out']) ? (string)$shift['clock_out'] : null
    );
    if ($window === null) {
        if ($autoIds !== []) {
            return ['action' => 'remove_auto', 'reason' => 'not over 5 hours', 'window' => null, 'auto_ids' => $autoIds];
        }
        $empty['reason'] = 'not over 5 hours';
        return $empty;
    }
    if ($autoIds === []) {
        return ['action' => 'insert', 'reason' => 'no break', 'window' => $window, 'auto_ids' => []];
    }

    $primary = null;
    foreach ($breaks as $break) {
        if (ab_is_auto_source($break['source'] ?? null)) {
            $primary = $break;
            break;
        }
    }
    $same = $primary !== null
        && ab_parse_dt((string)($primary['break_start'] ?? '')) === ab_parse_dt($window['break_start'])
        && ab_parse_dt((string)($primary['break_end'] ?? '')) === ab_parse_dt($window['break_end']);
    if ($same && count($autoIds) === 1) {
        return ['action' => 'skip', 'reason' => 'already', 'window' => $window, 'auto_ids' => $autoIds];
    }
    return [
        'action' => 'move',
        'reason' => $same ? 'duplicate auto' : 'reposition',
        'window' => $window,
        'auto_ids' => $autoIds,
    ];
}

/**
 * Error string when a typed break is outside the shift, or null when it fits.
 * The break must start and end inside [clock_in, clock_out], inclusive.
 */
function ab_break_bounds_error(?string $clockIn, ?string $clockOut, ?string $breakStart, ?string $breakEnd): ?string
{
    $in = ab_parse_dt($clockIn);
    $out = ab_parse_dt($clockOut);
    if ($in === null) {
        return 'The shift needs a clock-in before a break can be saved.';
    }
    if ($out === null || $out <= $in) {
        return 'Enter a clock-out so the break stays inside the shift.';
    }
    $start = ab_parse_dt($breakStart);
    $end = ab_parse_dt($breakEnd);
    if ($start === null || $end === null) {
        return 'Each break needs a start and an end.';
    }
    if ($end <= $start) {
        return 'The break end must be after the break start.';
    }
    if ($start < $in || $end > $out) {
        return 'The break must sit inside the shift, from clock-in through clock-out.';
    }
    return null;
}

/**
 * @param list<mixed> $starts
 * @param list<mixed> $ends
 * @param list<mixed> $sources
 * @return list<array{break_start:string,break_end:string,source:string}>
 */
function ab_collect_breaks(array $starts, array $ends, array $sources = [], bool $allowAuto = true): array
{
    $rows = [];
    foreach ($starts as $idx => $startRaw) {
        $startRaw = trim((string)$startRaw);
        $endRaw = trim((string)($ends[$idx] ?? ''));
        if ($startRaw === '' && $endRaw === '') {
            continue;
        }
        $source = ($allowAuto && ab_is_auto_source($sources[$idx] ?? null)) ? 'auto' : 'manual';
        $rows[] = [
            'break_start' => ab_normalize_dt($startRaw) ?? $startRaw,
            'break_end' => $endRaw === '' ? '' : (ab_normalize_dt($endRaw) ?? $endRaw),
            'source' => $source,
        ];
    }
    return $rows;
}

/**
 * A real break wins. Extra Auto break rows are dropped. An editor save with
 * no rows stays empty: this does not invent an Auto break.
 *
 * @param list<array{break_start:string,break_end:string,source:string}> $rows
 * @return list<array{break_start:string,break_end:string,source:string}>
 */
function ab_editor_rows(array $rows): array
{
    $hasManual = false;
    foreach ($rows as $row) {
        if (($row['source'] ?? '') !== 'auto') {
            $hasManual = true;
            break;
        }
    }
    if ($hasManual) {
        $kept = [];
        foreach ($rows as $row) {
            if (($row['source'] ?? '') !== 'auto') {
                $kept[] = $row;
            }
        }
        return $kept;
    }
    $kept = [];
    $seenAuto = false;
    foreach ($rows as $row) {
        if (($row['source'] ?? '') === 'auto') {
            if ($seenAuto) {
                continue;
            }
            $seenAuto = true;
        }
        $kept[] = $row;
    }
    return $kept;
}

/**
 * @param list<mixed> $starts
 * @param list<mixed> $ends
 */
function ab_validate_posted_breaks(?string $clockIn, ?string $clockOut, array $starts, array $ends): ?string
{
    foreach (ab_collect_breaks($starts, $ends, [], false) as $row) {
        $error = ab_break_bounds_error($clockIn, $clockOut, $row['break_start'], $row['break_end']);
        if ($error !== null) {
            return $error;
        }
    }
    return null;
}

function ab_finalized_sql(string $alias, bool $hasFinalized, bool $hasPayout, bool $hasProcessed): string
{
    $alias = att_sql_alias($alias);
    $parts = [];
    if ($hasFinalized) {
        $parts[] = 'COALESCE(' . $alias . '.is_finalized, 0) = 1';
    }
    if ($hasPayout) {
        $parts[] = 'COALESCE(' . $alias . '.payout_status, 0) <> 0';
    }
    if ($hasProcessed) {
        $parts[] = 'COALESCE(' . $alias . '.is_processed, 0) = 1';
    }
    $parts[] = 'EXISTS (SELECT 1 FROM payroll_finalized pf_ab WHERE pf_ab.attendance_id = ' . $alias . '.id)';
    return '(' . implode(' OR ', $parts) . ')';
}

function ab_over_five_hours_sql(string $alias): string
{
    $alias = att_sql_alias($alias);
    return 'TIMESTAMPDIFF(SECOND, ' . $alias . '.clock_in, ' . $alias . '.clock_out) > ' . AB_THRESHOLD_SECONDS;
}

function ab_real_break_exists_sql(string $alias): string
{
    $alias = att_sql_alias($alias);
    return "EXISTS (SELECT 1 FROM breaks b_real WHERE b_real.shift_id = " . $alias . ".id AND COALESCE(b_real.source, 'manual') <> 'auto')";
}

function ab_auto_break_exists_sql(string $alias): string
{
    $alias = att_sql_alias($alias);
    return "EXISTS (SELECT 1 FROM breaks b_auto WHERE b_auto.shift_id = " . $alias . ".id AND b_auto.source = 'auto')";
}

function ab_legacy_break_sql(string $alias): string
{
    $alias = att_sql_alias($alias);
    return '(' . $alias . ".break_start IS NOT NULL AND TRIM(" . $alias . ".break_start) <> '' AND " . $alias . ".break_start <> '0000-00-00 00:00:00'"
        . ' AND ' . $alias . ".break_end IS NOT NULL AND TRIM(" . $alias . ".break_end) <> '' AND " . $alias . ".break_end <> '0000-00-00 00:00:00')";
}

function ab_work_shift_sql(string $alias): string
{
    $alias = att_sql_alias($alias);
    return "TRIM(IFNULL(" . $alias . ".status, '')) NOT IN ('5', '6', 'travel', 'transfer-out')";
}

function ab_backfill_where(bool $hasDeleted, bool $hasFinalized, bool $hasPayout, bool $hasProcessed, bool $orgScoped): string
{
    $parts = [];
    if ($hasDeleted) {
        $parts[] = 'a.deleted_at IS NULL';
    }
    $parts[] = 'a.clock_in IS NOT NULL';
    $parts[] = "a.clock_out IS NOT NULL AND TRIM(a.clock_out) <> '' AND a.clock_out <> '0000-00-00 00:00:00'";
    $parts[] = 'NOT ' . ab_finalized_sql('a', $hasFinalized, $hasPayout, $hasProcessed);
    if ($orgScoped) {
        $parts[] = 'a.org_id = ?';
    }
    return implode(' AND ', $parts);
}

/**
 * @return array{help:bool,apply:bool,org:?int,error:?string}
 */
function ab_parse_cli(array $args): array
{
    $apply = false;
    $org = null;
    foreach ($args as $arg) {
        if ($arg === '--apply') {
            $apply = true;
            continue;
        }
        if ($arg === '--dry-run') {
            $apply = false;
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            return ['help' => true, 'apply' => false, 'org' => null, 'error' => null];
        }
        if (str_starts_with($arg, '--org=')) {
            $raw = substr($arg, 6);
            if (!preg_match('/^[1-9][0-9]*$/', $raw)) {
                return ['help' => false, 'apply' => false, 'org' => null, 'error' => '--org needs a positive organization id.'];
            }
            $org = (int)$raw;
            continue;
        }
        return ['help' => false, 'apply' => false, 'org' => null, 'error' => 'Unknown option ' . $arg . '.'];
    }
    return ['help' => false, 'apply' => $apply, 'org' => $org, 'error' => null];
}

function ab_cli_usage(): string
{
    return "Usage: php auto_break_backfill.php [--dry-run] [--apply] [--org=ID]\n"
        . "  --dry-run   List unfinalized shifts that would change. This is the default.\n"
        . "  --apply     Insert, move, or remove Auto break rows.\n"
        . "  --org=ID    Limit to one attendance.org_id.\n"
        . "A shift longer than 5 hours with no real break gets one 30-minute Auto break\n"
        . "at the midpoint. Finalized shifts are not listed and are not changed.\n";
}

/**
 * @param array{action:string,reason:string,window:?array,auto_ids?:list<int>,shift?:array<string,mixed>} $plan
 */
function ab_format_backfill_line(array $plan): string
{
    $shift = $plan['shift'] ?? [];
    $verb = $plan['action'] === 'insert' ? 'add' : ($plan['action'] === 'move' ? 'move' : 'remove');
    $line = 'shift ' . (int)($shift['id'] ?? 0)
        . ' org ' . (int)($shift['org_id'] ?? 0)
        . ' user ' . (int)($shift['user_id'] ?? 0)
        . ' in ' . trim((string)($shift['clock_in'] ?? ''))
        . ' out ' . trim((string)($shift['clock_out'] ?? ''))
        . ' ' . $verb;
    $window = $plan['window'] ?? null;
    if (is_array($window) && isset($window['break_start'], $window['break_end'])) {
        $line .= ' ' . $window['break_start'] . ' -> ' . $window['break_end'];
    }
    return $line;
}

function ab_has_source(PDO $pdo): bool
{
    return att_has_column($pdo, 'breaks', 'source');
}

function ab_shift_columns(PDO $pdo): array
{
    $cols = ['id', 'org_id', 'user_id', 'status', 'clock_in', 'clock_out'];
    foreach (['site_id', 'is_finalized', 'payout_status', 'is_processed', 'break_start', 'break_end', 'deleted_at'] as $col) {
        if (att_has_column($pdo, 'attendance', $col)) {
            $cols[] = $col;
        }
    }
    return $cols;
}

function ab_fetch_shift(PDO $pdo, int $shiftId, bool $lock): ?array
{
    if ($shiftId <= 0) {
        return null;
    }
    $sql = 'SELECT ' . implode(', ', ab_shift_columns($pdo)) . ' FROM attendance WHERE id = ? LIMIT 1';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $st = $pdo->prepare($sql);
    $st->execute([$shiftId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ab_fetch_breaks(PDO $pdo, int $shiftId, bool $lock): array
{
    $cols = 'id, shift_id, break_start, break_end';
    if (ab_has_source($pdo)) {
        $cols .= ', source';
    }
    $sql = 'SELECT ' . $cols . ' FROM breaks WHERE shift_id = ? ORDER BY id ASC';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $st = $pdo->prepare($sql);
    $st->execute([$shiftId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function ab_in_payroll_finalized(PDO $pdo, int $shiftId): bool
{
    $st = $pdo->prepare('SELECT 1 FROM payroll_finalized WHERE attendance_id = ? LIMIT 1');
    $st->execute([$shiftId]);
    return (bool)$st->fetchColumn();
}

function ab_insert_break(PDO $pdo, int $shiftId, string $start, string $end, string $source): void
{
    $source = ab_is_auto_source($source) ? 'auto' : 'manual';
    if (ab_has_source($pdo)) {
        $st = $pdo->prepare('INSERT INTO breaks (shift_id, break_start, break_end, source) VALUES (?, ?, ?, ?)');
        $st->execute([$shiftId, $start, $end, $source]);
        return;
    }
    $st = $pdo->prepare('INSERT INTO breaks (shift_id, break_start, break_end) VALUES (?, ?, ?)');
    $st->execute([$shiftId, $start, $end]);
}

function ab_delete_auto(PDO $pdo, int $shiftId, array $ids): void
{
    $ids = array_values(array_filter(array_map('intval', $ids), static function ($id) {
        return $id > 0;
    }));
    if ($ids === []) {
        $pdo->prepare("DELETE FROM breaks WHERE shift_id = ? AND source = 'auto'")->execute([$shiftId]);
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "DELETE FROM breaks WHERE shift_id = ? AND source = 'auto' AND id IN ($placeholders)";
    $pdo->prepare($sql)->execute(array_merge([$shiftId], $ids));
}

/**
 * @param array{action:string,reason:string,window:?array,auto_ids:list<int>} $plan
 */
function ab_execute_plan(PDO $pdo, int $shiftId, array $plan): void
{
    $action = $plan['action'];
    if ($action === 'skip') {
        return;
    }
    if ($action === 'insert') {
        $window = $plan['window'] ?? null;
        if (!is_array($window)) {
            return;
        }
        ab_insert_break($pdo, $shiftId, $window['break_start'], $window['break_end'], 'auto');
        return;
    }
    if ($action === 'remove_auto') {
        ab_delete_auto($pdo, $shiftId, $plan['auto_ids'] ?? []);
        return;
    }
    if ($action !== 'move') {
        return;
    }
    $window = $plan['window'] ?? null;
    if (!is_array($window)) {
        return;
    }
    $ids = array_values(array_filter(array_map('intval', $plan['auto_ids'] ?? []), static function ($id) {
        return $id > 0;
    }));
    if ($ids === []) {
        ab_delete_auto($pdo, $shiftId, []);
        ab_insert_break($pdo, $shiftId, $window['break_start'], $window['break_end'], 'auto');
        return;
    }
    $keep = $ids[0];
    $pdo->prepare("UPDATE breaks SET break_start = ?, break_end = ? WHERE id = ? AND shift_id = ? AND source = 'auto'")
        ->execute([$window['break_start'], $window['break_end'], $keep, $shiftId]);
    ab_delete_auto($pdo, $shiftId, array_slice($ids, 1));
}

/**
 * @return array{action:string,reason:string,window:?array,auto_ids:list<int>,shift:?array}
 */
function ab_describe_shift(PDO $pdo, int $shiftId): array
{
    $shift = ab_fetch_shift($pdo, $shiftId, false);
    if (!$shift) {
        return ['action' => 'skip', 'reason' => 'missing', 'window' => null, 'auto_ids' => [], 'shift' => null];
    }
    if (!ab_has_source($pdo)) {
        return ['action' => 'skip', 'reason' => 'source column missing', 'window' => null, 'auto_ids' => [], 'shift' => $shift];
    }
    $plan = ab_plan($shift, ab_fetch_breaks($pdo, $shiftId, false), ab_in_payroll_finalized($pdo, $shiftId));
    $plan['shift'] = $shift;
    return $plan;
}

/**
 * Insert, move, or remove the Auto break for one shift. No-op when the shift
 * is finalized, deleted, not a closed work shift over 5 hours, already has a
 * real break, or the source column has not been added yet.
 *
 * @return array{action:string,reason:string,window:?array,auto_ids:list<int>,shift:?array}
 */
function ab_apply_to_shift(PDO $pdo, int $shiftId): array
{
    if ($shiftId <= 0) {
        return ['action' => 'skip', 'reason' => 'missing', 'window' => null, 'auto_ids' => [], 'shift' => null];
    }
    if (!ab_has_source($pdo)) {
        return ['action' => 'skip', 'reason' => 'source column missing', 'window' => null, 'auto_ids' => [], 'shift' => null];
    }
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $shift = ab_fetch_shift($pdo, $shiftId, true);
        if (!$shift) {
            if ($own) {
                $pdo->commit();
            }
            return ['action' => 'skip', 'reason' => 'missing', 'window' => null, 'auto_ids' => [], 'shift' => null];
        }
        $plan = ab_plan($shift, ab_fetch_breaks($pdo, $shiftId, true), ab_in_payroll_finalized($pdo, $shiftId));
        ab_execute_plan($pdo, $shiftId, $plan);
        if ($own) {
            $pdo->commit();
        }
        $plan['shift'] = $shift;
        return $plan;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function ab_copy_breaks(PDO $pdo, int $fromShift, int $toShift): void
{
    if ($fromShift <= 0 || $toShift <= 0) {
        return;
    }
    $hasSource = ab_has_source($pdo);
    $cols = 'break_start, break_end, start_selfie, end_selfie, start_lat, start_lng, end_lat, end_lng';
    if ($hasSource) {
        $cols .= ', source';
    }
    $st = $pdo->prepare('SELECT ' . $cols . ' FROM breaks WHERE shift_id = ? ORDER BY id ASC');
    $st->execute([$fromShift]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return;
    }
    $fields = explode(', ', $cols);
    $placeholders = implode(', ', array_fill(0, count($fields) + 1, '?'));
    $ins = $pdo->prepare('INSERT INTO breaks (shift_id, ' . $cols . ') VALUES (' . $placeholders . ')');
    foreach ($rows as $row) {
        $vals = [$toShift];
        foreach ($fields as $field) {
            if ($field === 'source') {
                $vals[] = ab_is_auto_source($row[$field] ?? null) ? 'auto' : 'manual';
                continue;
            }
            $vals[] = $row[$field] ?? null;
        }
        $ins->execute($vals);
    }
}

/**
 * Replace the breaks on an unfinalized shift with what the editor submitted.
 * Does not add an Auto break when the admin cleared the list. Drops an Auto
 * break when a real break is submitted. Rejects a break outside the shift
 * without deleting the rows already stored.
 *
 * @param list<mixed> $starts
 * @param list<mixed> $ends
 * @param list<mixed> $sources
 * @return array{ok:bool,error:string}
 */
function ab_save_editor_breaks(PDO $pdo, int $shiftId, array $starts, array $ends, array $sources): array
{
    $shift = ab_fetch_shift($pdo, $shiftId, false);
    if (!$shift) {
        return ['ok' => false, 'error' => 'That shift was not found. Breaks were not changed.'];
    }
    if (ab_is_deleted($shift)) {
        return ['ok' => false, 'error' => 'That shift was removed. Breaks were not changed.'];
    }
    if (ab_is_finalized($shift, ab_in_payroll_finalized($pdo, $shiftId))) {
        return ['ok' => false, 'error' => 'That shift is finalized. Breaks were not changed.'];
    }
    $rows = ab_editor_rows(ab_collect_breaks($starts, $ends, $sources, true));
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $locked = ab_fetch_shift($pdo, $shiftId, true);
        if (!$locked || ab_is_deleted($locked) || ab_is_finalized($locked, ab_in_payroll_finalized($pdo, $shiftId))) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => 'That shift is finalized. Breaks were not changed.'];
        }
        $clockIn = isset($locked['clock_in']) ? (string)$locked['clock_in'] : null;
        $clockOut = isset($locked['clock_out']) ? (string)$locked['clock_out'] : null;
        foreach ($rows as $row) {
            $error = ab_break_bounds_error($clockIn, $clockOut, $row['break_start'], $row['break_end']);
            if ($error !== null) {
                if ($own && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return ['ok' => false, 'error' => $error . ' Breaks were not changed.'];
            }
        }
        $pdo->prepare('DELETE FROM breaks WHERE shift_id = ?')->execute([$shiftId]);
        foreach ($rows as $row) {
            ab_insert_break($pdo, $shiftId, $row['break_start'], $row['break_end'], $row['source']);
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'The breaks were not saved.'];
    }
    return ['ok' => true, 'error' => ''];
}

/**
 * @return array{counts:array{add:int,move:int,remove:int},lines:list<string>,apply:bool}
 */
function ab_backfill(PDO $pdo, ?int $orgId, bool $apply): array
{
    if (!ab_has_source($pdo)) {
        throw new RuntimeException('Run migrations/20261111_break_source.sql before auto breaks can be written.');
    }
    $hasDeleted = att_has_column($pdo, 'attendance', 'deleted_at');
    $hasFinalized = att_has_column($pdo, 'attendance', 'is_finalized');
    $hasPayout = att_has_column($pdo, 'attendance', 'payout_status');
    $hasProcessed = att_has_column($pdo, 'attendance', 'is_processed');
    $hasLegacy = att_has_column($pdo, 'attendance', 'break_start') && att_has_column($pdo, 'attendance', 'break_end');
    $where = ab_backfill_where($hasDeleted, $hasFinalized, $hasPayout, $hasProcessed, $orgId !== null);
    $params = [];
    if ($orgId !== null) {
        $params[] = $orgId;
    }

    $addSql = 'SELECT a.id FROM attendance a WHERE ' . $where
        . ' AND ' . ab_work_shift_sql('a')
        . ' AND ' . ab_over_five_hours_sql('a')
        . ' AND NOT ' . ab_real_break_exists_sql('a');
    if ($hasLegacy) {
        $addSql .= ' AND NOT ' . ab_legacy_break_sql('a');
    }
    $addSql .= ' ORDER BY a.id';

    $cleanOr = [
        'NOT (' . ab_over_five_hours_sql('a') . ')',
        ab_real_break_exists_sql('a'),
        'NOT (' . ab_work_shift_sql('a') . ')',
    ];
    if ($hasLegacy) {
        $cleanOr[] = ab_legacy_break_sql('a');
    }
    $cleanSql = 'SELECT a.id FROM attendance a WHERE ' . $where
        . ' AND ' . ab_auto_break_exists_sql('a')
        . ' AND (' . implode(' OR ', $cleanOr) . ') ORDER BY a.id';

    $counts = ['add' => 0, 'move' => 0, 'remove' => 0];
    $lines = [];
    $seen = [];
    foreach ([$addSql, $cleanSql] as $sql) {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (int)$id;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $plan = $apply ? ab_apply_to_shift($pdo, $id) : ab_describe_shift($pdo, $id);
            if (($plan['action'] ?? 'skip') === 'skip') {
                continue;
            }
            if ($plan['action'] === 'insert') {
                $counts['add']++;
            } elseif ($plan['action'] === 'move') {
                $counts['move']++;
            } elseif ($plan['action'] === 'remove_auto') {
                $counts['remove']++;
            }
            $lines[] = ab_format_backfill_line($plan);
        }
    }
    return ['counts' => $counts, 'lines' => $lines, 'apply' => $apply];
}
