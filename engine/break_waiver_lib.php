<?php
/**
 * No-break requests from clock-out, held until a manager reviews them.
 *
 * A pending or approved request keeps ab_plan from inserting an Auto break.
 * Rejected applies the decided minutes as an Auto break. Custom applies the
 * manager's minutes as a manual break. Pending shifts cannot be finalized.
 * This file does not mark anyone paid.
 */

function bwr_statuses(): array
{
    return ['pending', 'approved', 'rejected', 'custom'];
}

function bwr_schema_sqlite(): string
{
    return 'CREATE TABLE IF NOT EXISTS break_waiver_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        org_id INTEGER NOT NULL,
        site_id INTEGER NOT NULL,
        attendance_id INTEGER NOT NULL UNIQUE,
        user_id INTEGER NOT NULL,
        requested_at TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT \'pending\',
        minutes INTEGER NOT NULL DEFAULT 0,
        decided_by INTEGER NULL,
        decided_at TEXT NULL,
        note TEXT NULL
    )';
}

function bwr_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bwr_valid_status(string $status): bool
{
    return in_array($status, bwr_statuses(), true);
}

function bwr_review_url(array $query = []): string
{
    $base = 'break_waiver_review.php';
    if ($query === []) {
        return $base;
    }
    return $base . '?' . http_build_query($query);
}

/**
 * Center a break of $minutes on the shift midpoint. Null when it cannot fit.
 *
 * @return array{break_start:string,break_end:string,minutes:int}|null
 */
function bwr_window_for_minutes(?string $clockIn, ?string $clockOut, int $minutes): ?array
{
    if (!function_exists('ab_parse_dt') || !function_exists('ab_format_dt')) {
        return null;
    }
    $in = ab_parse_dt($clockIn);
    $out = ab_parse_dt($clockOut);
    if ($in === null || $out === null || $out <= $in || $minutes < 1) {
        return null;
    }
    $duration = $out - $in;
    $length = $minutes * 60;
    if ($length >= $duration) {
        return null;
    }
    $mid = $in + intdiv($duration, 2);
    $start = $mid - intdiv($length, 2);
    $end = $start + $length;
    if ($start < $in) {
        $start = $in;
        $end = $start + $length;
    }
    if ($end > $out) {
        $end = $out;
        $start = $end - $length;
    }
    if ($start < $in || $end > $out || $end <= $start) {
        return null;
    }
    return [
        'break_start' => ab_format_dt($start),
        'break_end' => ab_format_dt($end),
        'minutes' => $minutes,
    ];
}

function bwr_planned_window(array $shift): ?array
{
    if (!function_exists('ab_window_for_shift')) {
        return null;
    }
    return ab_window_for_shift(
        isset($shift['clock_in']) ? (string)$shift['clock_in'] : null,
        isset($shift['clock_out']) ? (string)$shift['clock_out'] : null
    );
}

function bwr_planned_minutes(array $shift): int
{
    $window = bwr_planned_window($shift);
    return $window ? (int)$window['minutes'] : 0;
}

function bwr_has_real_break(array $shift, array $breaks): bool
{
    if (function_exists('ab_legacy_break_recorded') && ab_legacy_break_recorded($shift)) {
        return true;
    }
    foreach ($breaks as $break) {
        if (function_exists('ab_is_auto_source') && ab_is_auto_source($break['source'] ?? null)) {
            continue;
        }
        $start = $break['break_start'] ?? null;
        $end = $break['break_end'] ?? null;
        if (function_exists('ab_parse_dt')) {
            $s = ab_parse_dt($start !== null ? (string)$start : null);
            $e = ab_parse_dt($end !== null ? (string)$end : null);
            if ($s !== null && $e !== null && $e > $s) {
                return true;
            }
        }
    }
    return false;
}

function bwr_is_eligible_shift(array $shift, array $breaks): bool
{
    if (function_exists('ab_feature_on') && !ab_feature_on()) {
        return false;
    }
    if (function_exists('ab_is_deleted') && ab_is_deleted($shift)) {
        return false;
    }
    if (function_exists('ab_is_work_shift') && !ab_is_work_shift($shift)) {
        return false;
    }
    if (bwr_has_real_break($shift, $breaks)) {
        return false;
    }
    return bwr_planned_window($shift) !== null;
}

function bwr_prompt_message(int $minutes): string
{
    $n = $minutes > 0 ? $minutes : 30;
    return 'A ' . $n . '-min break will be deducted. Tap "I took no break" to request no deduction.';
}

function bwr_get_by_attendance(PDO $pdo, int $attendanceId): ?array
{
    if ($attendanceId <= 0) {
        return null;
    }
    try {
        $st = $pdo->prepare('SELECT id, org_id, site_id, attendance_id, user_id, requested_at, status, minutes, decided_by, decided_at, note FROM break_waiver_requests WHERE attendance_id = ?');
        $st->execute([$attendanceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function bwr_get(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    try {
        $st = $pdo->prepare('SELECT id, org_id, site_id, attendance_id, user_id, requested_at, status, minutes, decided_by, decided_at, note FROM break_waiver_requests WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Attach waiver_status and waiver_minutes to a shift row for ab_plan.
 */
function bwr_enrich_shift(PDO $pdo, array $shift): array
{
    $id = (int)($shift['id'] ?? $shift['attendance_id'] ?? 0);
    if ($id <= 0) {
        return $shift;
    }
    $row = bwr_get_by_attendance($pdo, $id);
    if ($row === null) {
        return $shift;
    }
    $shift['waiver_status'] = (string)$row['status'];
    $shift['waiver_minutes'] = (int)$row['minutes'];
    $shift['waiver_id'] = (int)$row['id'];
    return $shift;
}

function bwr_status_from_shift(array $shift): string
{
    $status = strtolower(trim((string)($shift['waiver_status'] ?? '')));
    return bwr_valid_status($status) ? $status : '';
}

/**
 * Adjust an auto-break plan for a waiver on the shift. Returns null to keep the core plan.
 *
 * @param array{action:string,reason:string,window:?array,auto_ids:list<int>} $empty
 * @param list<int> $autoIds
 * @return array{action:string,reason:string,window:?array,auto_ids:list<int>,source?:string}|null
 */
function bwr_adjust_plan(array $shift, array $breaks, array $empty, array $autoIds, bool $hasReal): ?array
{
    $status = bwr_status_from_shift($shift);
    if ($status === '') {
        return null;
    }
    if ($status === 'pending' || $status === 'approved') {
        if ($autoIds !== []) {
            return ['action' => 'remove_auto', 'reason' => 'waiver_' . $status, 'window' => null, 'auto_ids' => $autoIds];
        }
        $empty['reason'] = 'waiver_' . $status;
        return $empty;
    }
    if ($hasReal) {
        return null;
    }
    if ($status !== 'rejected' && $status !== 'custom') {
        return null;
    }
    $minutes = (int)($shift['waiver_minutes'] ?? 0);
    $clockIn = isset($shift['clock_in']) ? (string)$shift['clock_in'] : null;
    $clockOut = isset($shift['clock_out']) ? (string)$shift['clock_out'] : null;
    $window = $minutes > 0 ? bwr_window_for_minutes($clockIn, $clockOut, $minutes) : bwr_planned_window($shift);
    if ($window === null) {
        if ($autoIds !== []) {
            return ['action' => 'remove_auto', 'reason' => 'waiver_' . $status, 'window' => null, 'auto_ids' => $autoIds];
        }
        $empty['reason'] = 'waiver_' . $status;
        return $empty;
    }
    $source = $status === 'custom' ? 'manual' : 'auto';
    if ($autoIds === []) {
        return ['action' => 'insert', 'reason' => 'waiver_' . $status, 'window' => $window, 'auto_ids' => [], 'source' => $source];
    }
    return ['action' => 'move', 'reason' => 'waiver_' . $status, 'window' => $window, 'auto_ids' => $autoIds, 'source' => $source];
}

function bwr_fetch_shift(PDO $pdo, int $shiftId): ?array
{
    if ($shiftId <= 0) {
        return null;
    }
    $shift = null;
    if (function_exists('ab_fetch_shift')) {
        try {
            $fetched = ab_fetch_shift($pdo, $shiftId, false);
            if (is_array($fetched) && $fetched) {
                $shift = $fetched;
            }
        } catch (Throwable $e) {
            $shift = null;
        }
    }
    if ($shift !== null && isset($shift['site_id'], $shift['org_id'], $shift['user_id'])) {
        return $shift;
    }
    try {
        $st = $pdo->prepare('SELECT * FROM attendance WHERE id = ?');
        $st->execute([$shiftId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $shift;
        }
        return $shift !== null ? array_merge($row, $shift) : $row;
    } catch (Throwable $e) {
        return $shift;
    }
}

function bwr_fetch_breaks(PDO $pdo, int $shiftId): array
{
    if (function_exists('ab_fetch_breaks')) {
        return ab_fetch_breaks($pdo, $shiftId, false);
    }
    try {
        $st = $pdo->prepare('SELECT * FROM breaks WHERE shift_id = ?');
        $st->execute([$shiftId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return array{ok:bool,error:?string,id?:int,status?:string,minutes?:int,already?:bool}
 */
function bwr_create_pending(PDO $pdo, int $shiftId, int $userId): array
{
    if (function_exists('ab_feature_on') && !ab_feature_on()) {
        return ['ok' => false, 'error' => 'Automatic breaks are off. No request is needed.'];
    }
    $shift = bwr_fetch_shift($pdo, $shiftId);
    if ($shift === null) {
        return ['ok' => false, 'error' => 'That shift could not be found.'];
    }
    if ($userId > 0 && (int)($shift['user_id'] ?? 0) !== $userId) {
        return ['ok' => false, 'error' => 'You can only request no deduction on your own shift.'];
    }
    $existing = bwr_get_by_attendance($pdo, $shiftId);
    if ($existing !== null) {
        return [
            'ok' => true,
            'error' => null,
            'id' => (int)$existing['id'],
            'status' => (string)$existing['status'],
            'minutes' => (int)$existing['minutes'],
            'already' => true,
        ];
    }
    $breaks = bwr_fetch_breaks($pdo, $shiftId);
    if (!bwr_is_eligible_shift($shift, $breaks)) {
        return ['ok' => false, 'error' => 'This shift does not qualify for a no-break request.'];
    }
    $minutes = bwr_planned_minutes($shift);
    $orgId = (int)($shift['org_id'] ?? 0);
    $siteId = (int)($shift['site_id'] ?? 0);
    $owner = (int)($shift['user_id'] ?? $userId);
    try {
        $st = $pdo->prepare('INSERT INTO break_waiver_requests (org_id, site_id, attendance_id, user_id, requested_at, status, minutes) VALUES (?, ?, ?, ?, ?, \'pending\', ?)');
        $st->execute([$orgId, $siteId, $shiftId, $owner, date('Y-m-d H:i:s'), $minutes]);
        $id = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        $again = bwr_get_by_attendance($pdo, $shiftId);
        if ($again !== null) {
            return ['ok' => true, 'error' => null, 'id' => (int)$again['id'], 'status' => (string)$again['status'], 'minutes' => (int)$again['minutes'], 'already' => true];
        }
        return ['ok' => false, 'error' => 'The no-break request could not be saved.'];
    }
    if (function_exists('ab_apply_to_shift')) {
        try {
            ab_apply_to_shift($pdo, $shiftId);
        } catch (Throwable $e) {
            // The request is stored; auto-break cleanup can run later.
        }
    }
    return ['ok' => true, 'error' => null, 'id' => $id, 'status' => 'pending', 'minutes' => $minutes, 'already' => false];
}

function bwr_clockout_redirect(PDO $pdo, int $shiftId, string $fallback): ?string
{
    if (function_exists('ab_feature_on') && !ab_feature_on()) {
        return null;
    }
    $shift = bwr_fetch_shift($pdo, $shiftId);
    if ($shift === null) {
        return null;
    }
    $breaks = bwr_fetch_breaks($pdo, $shiftId);
    if (!bwr_is_eligible_shift($shift, $breaks)) {
        return null;
    }
    if (bwr_get_by_attendance($pdo, $shiftId) !== null) {
        return null;
    }
    $sep = strpos($fallback, '?') === false ? '?' : '&';
    return 'no_break_request.php?shift_id=' . (int)$shiftId . '&return=' . rawurlencode($fallback);
}

/**
 * @param list<int> $attendanceIds
 * @return list<array<string,mixed>>
 */
function bwr_pending_for_shifts(PDO $pdo, array $attendanceIds): array
{
    if (function_exists('ab_feature_on') && !ab_feature_on()) {
        return [];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $attendanceIds), static fn($v) => $v > 0)));
    if ($ids === []) {
        return [];
    }
    try {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT w.id, w.org_id, w.site_id, w.attendance_id, w.user_id, w.requested_at, w.status, w.minutes,
                       a.clock_in, a.clock_out, u.Name AS employee_name, s.nick_name AS site_name
                FROM break_waiver_requests w
                LEFT JOIN attendance a ON a.id = w.attendance_id
                LEFT JOIN users u ON u.id = w.user_id
                LEFT JOIN sites s ON s.SId = w.site_id
                WHERE w.status = \'pending\' AND w.attendance_id IN (' . $ph . ')
                ORDER BY a.clock_in ASC, w.id ASC';
        $st = $pdo->prepare($sql);
        $st->execute($ids);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function bwr_collect_request_ids($post, $get): array
{
    $ids = [];
    if (isset($post['record_ids_csv']) || isset($get['record_ids_csv'])) {
        $csv = (string)($post['record_ids_csv'] ?? $get['record_ids_csv'] ?? '');
        foreach (preg_split('/\s*,\s*/', $csv) ?: [] as $id) {
            $ids[] = (int)$id;
        }
    }
    foreach (['shift_ids', 'ids', 'attendance_ids', 'record_ids', 'final_ids', 'selected_ids'] as $key) {
        $raw = $post[$key] ?? $get[$key] ?? null;
        if ($raw === null || $raw === '') {
            continue;
        }
        $more = is_array($raw) ? $raw : preg_split('/\s*,\s*/', (string)$raw);
        if (is_array($more)) {
            foreach ($more as $id) {
                $ids[] = (int)$id;
            }
        }
    }
    foreach (['id', 'attendance_id'] as $key) {
        if (isset($get[$key])) {
            $ids[] = (int)$get[$key];
        }
        if (isset($post[$key])) {
            $ids[] = (int)$post[$key];
        }
    }
    return array_values(array_unique(array_filter($ids, static fn($v) => $v > 0)));
}

/**
 * Show the payroll-safety alert and stop. Used by the before-finalize gate
 * and by the include guard inside finalize writers.
 *
 * @param list<array<string,mixed>> $pending
 * @param list<int> $continueIds
 */
function bwr_send_finalize_blocked_page(array $pending, array $continueIds = [], bool $isPost = false): void
{
    $menu = function_exists('generateMenu') ? generateMenu($GLOBALS['pdo'] ?? null) : '';
    if (!is_string($menu)) {
        $menu = '';
    }
    $csrf = function_exists('ps_csrf_token') ? ps_csrf_token() : '';
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Cannot finalize</title>';
    echo '<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">';
    echo '<style>body{font-family:Segoe UI,sans-serif;background:#f4f7f6;padding-top:70px;} .wrap{max-width:800px;margin:24px auto;background:#fff;padding:24px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.06);} li{margin:6px 0;} .alert-box{background:#fef3c7;border:1px solid #f59e0b;padding:12px 14px;border-radius:8px;margin-bottom:16px;}</style>';
    echo '</head><body>' . $menu . '<div class="wrap">';
    echo '<div class="alert-box"><strong>These shifts cannot be finalized: no-break request awaiting manager review.</strong></div>';
    echo '<ul>';
    foreach ($pending as $row) {
        $label = bwr_h(bwr_shift_label($row));
        $review = bwr_h(bwr_review_url(['request_id' => (int)$row['id'], 'site_id' => (int)$row['site_id']]));
        echo '<li>' . $label . ' — <a href="' . $review . '">Open review</a></li>';
    }
    echo '</ul>';
    echo '<p><a class="btn btn-primary" href="break_waiver_review.php">Go to the no-break review page</a></p>';
    if ($isPost && $continueIds !== []) {
        echo '<form method="post" action="break_waiver_before_finalize.php">';
        if ($csrf !== '') {
            echo '<input type="hidden" name="csrf_token" value="' . bwr_h($csrf) . '">';
        }
        echo '<input type="hidden" name="continue_unblocked" value="1">';
        echo '<input type="hidden" name="record_ids_csv" value="' . bwr_h(implode(',', $continueIds)) . '">';
        echo '<button class="btn btn-secondary" type="submit">Continue with the ' . count($continueIds) . ' shift' . (count($continueIds) === 1 ? '' : 's') . ' that are not blocked</button>';
        echo '</form>';
    } else {
        echo '<p><a href="staff_audit.php">Back to Staff Audit</a></p>';
    }
    echo '</div></body></html>';
}

function bwr_blocked_ids(array $pendingRows): array
{
    $out = [];
    foreach ($pendingRows as $row) {
        $id = (int)($row['attendance_id'] ?? 0);
        if ($id > 0) {
            $out[] = $id;
        }
    }
    return $out;
}

function bwr_shift_label(array $row): string
{
    $name = trim((string)($row['employee_name'] ?? ''));
    if ($name === '') {
        $name = 'Employee #' . (int)($row['user_id'] ?? 0);
    }
    $in = (string)($row['clock_in'] ?? '');
    $out = (string)($row['clock_out'] ?? '');
    $when = $in !== '' ? $in : '';
    if ($out !== '') {
        $when = $when !== '' ? ($when . ' – ' . $out) : $out;
    }
    $site = trim((string)($row['site_name'] ?? ''));
    $parts = [$name];
    if ($site !== '') {
        $parts[] = $site;
    }
    if ($when !== '') {
        $parts[] = $when;
    }
    return implode(' · ', $parts);
}

/**
 * Apply a manager decision and the matching break outcome.
 *
 * @return array{ok:bool,error:?string}
 */
function bwr_decide(PDO $pdo, int $requestId, string $decision, int $minutes, int $decidedBy, string $note, int $currentOrg, bool $isSuper, string $assignedCsv): array
{
    $decision = strtolower(trim($decision));
    if (!in_array($decision, ['approved', 'rejected', 'custom'], true)) {
        return ['ok' => false, 'error' => 'Choose Approve, Reject, or Custom.'];
    }
    $row = bwr_get($pdo, $requestId);
    if ($row === null) {
        return ['ok' => false, 'error' => 'That request could not be found.'];
    }
    if ((string)$row['status'] !== 'pending') {
        return ['ok' => false, 'error' => 'That request has already been reviewed.'];
    }
    $siteId = (int)$row['site_id'];
    $orgId = (int)$row['org_id'];
    if ($siteId <= 0 || $orgId <= 0) {
        return ['ok' => false, 'error' => 'That request is missing a store.'];
    }
    if (!bwr_may_access_site($siteId, $orgId, $currentOrg, $isSuper, $assignedCsv)) {
        return ['ok' => false, 'error' => 'You cannot review a request for that store.'];
    }
    $shiftId = (int)$row['attendance_id'];
    $shift = bwr_fetch_shift($pdo, $shiftId);
    if ($shift !== null && function_exists('ab_is_finalized')) {
        $inPayroll = false;
        if (function_exists('ab_in_payroll_finalized')) {
            try {
                $inPayroll = ab_in_payroll_finalized($pdo, $shiftId);
            } catch (Throwable $e) {
                $inPayroll = false;
            }
        }
        if (ab_is_finalized($shift, $inPayroll)) {
            return ['ok' => false, 'error' => 'That shift is already finalized.'];
        }
    }
    if ($decision === 'approved') {
        $minutes = 0;
    } elseif ($decision === 'custom') {
        if ($minutes < 1 || $minutes > 240) {
            return ['ok' => false, 'error' => 'Enter the break minutes to apply (1 to 240).'];
        }
    } else {
        if ($minutes < 1) {
            $minutes = $shift ? bwr_planned_minutes($shift) : (int)$row['minutes'];
        }
        if ($minutes < 1) {
            $minutes = 30;
        }
    }
    $note = trim(str_replace(["\0"], '', $note));
    if (strlen($note) > 2000) {
        $note = substr($note, 0, 2000);
    }
    try {
        $st = $pdo->prepare('UPDATE break_waiver_requests SET status = ?, minutes = ?, decided_by = ?, decided_at = ?, note = ? WHERE id = ? AND status = \'pending\'');
        $st->execute([$decision, $minutes, $decidedBy > 0 ? $decidedBy : null, date('Y-m-d H:i:s'), $note !== '' ? $note : null, $requestId]);
        if ($st->rowCount() < 1) {
            return ['ok' => false, 'error' => 'That request has already been reviewed.'];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'The decision could not be saved.'];
    }
    if ($shift !== null) {
        $shift['waiver_status'] = $decision;
        $shift['waiver_minutes'] = $minutes;
        bwr_apply_break_outcome($pdo, $shiftId, $shift, $decision, $minutes);
    }
    return ['ok' => true, 'error' => null];
}

function bwr_apply_break_outcome(PDO $pdo, int $shiftId, array $shift, string $decision, int $minutes): void
{
    if (function_exists('ab_feature_on') && !ab_feature_on()) {
        if ($decision === 'custom') {
            bwr_ensure_custom_break($pdo, $shiftId, $shift, $minutes, 'manual');
        }
        return;
    }
    if (function_exists('ab_apply_to_shift')) {
        try {
            // Enrich happens inside ab_apply_to_shift after this request is saved.
            ab_apply_to_shift($pdo, $shiftId);
            if ($decision === 'custom') {
                bwr_ensure_custom_break($pdo, $shiftId, $shift, $minutes);
            }
            return;
        } catch (Throwable $e) {
            // Fall through to a direct write when auto-break apply is unavailable.
        }
    }
    bwr_ensure_custom_break($pdo, $shiftId, $shift, $decision === 'approved' ? 0 : $minutes, $decision === 'custom' ? 'manual' : 'auto');
}

function bwr_ensure_custom_break(PDO $pdo, int $shiftId, array $shift, int $minutes, string $source = 'manual'): void
{
    if ($minutes < 1) {
        if (function_exists('ab_delete_auto')) {
            try {
                ab_delete_auto($pdo, $shiftId, []);
            } catch (Throwable $e) {
            }
        }
        return;
    }
    $window = bwr_window_for_minutes(
        isset($shift['clock_in']) ? (string)$shift['clock_in'] : null,
        isset($shift['clock_out']) ? (string)$shift['clock_out'] : null,
        $minutes
    );
    if ($window === null) {
        return;
    }
    if ($source === 'auto' && function_exists('ab_apply_to_shift')) {
        return;
    }
    try {
        if (function_exists('ab_delete_auto')) {
            ab_delete_auto($pdo, $shiftId, []);
        }
        if (function_exists('ab_insert_break')) {
            ab_insert_break($pdo, $shiftId, $window['break_start'], $window['break_end'], $source);
            return;
        }
        $st = $pdo->prepare('INSERT INTO breaks (shift_id, break_start, break_end, source) VALUES (?, ?, ?, ?)');
        $st->execute([$shiftId, $window['break_start'], $window['break_end'], $source]);
    } catch (Throwable $e) {
    }
}

/**
 * @param list<int> $siteIds
 * @return list<array<string,mixed>>
 */
function bwr_list(PDO $pdo, int $orgId, array $siteIds = [], string $status = 'pending', string $from = '', string $to = '', int $limit = 200): array
{
    if ($orgId <= 0) {
        return [];
    }
    $sql = 'SELECT w.id, w.org_id, w.site_id, w.attendance_id, w.user_id, w.requested_at, w.status, w.minutes, w.decided_by, w.decided_at, w.note,
                   a.clock_in, a.clock_out, u.Name AS employee_name, s.nick_name AS site_name
            FROM break_waiver_requests w
            LEFT JOIN attendance a ON a.id = w.attendance_id
            LEFT JOIN users u ON u.id = w.user_id
            LEFT JOIN sites s ON s.SId = w.site_id
            WHERE w.org_id = ?';
    $params = [$orgId];
    $siteIds = array_values(array_unique(array_filter(array_map('intval', $siteIds), static fn($v) => $v > 0)));
    if (count($siteIds) === 1) {
        $sql .= ' AND w.site_id = ?';
        $params[] = $siteIds[0];
    } elseif (count($siteIds) > 1) {
        $ph = implode(',', array_fill(0, count($siteIds), '?'));
        $sql .= ' AND w.site_id IN (' . $ph . ')';
        foreach ($siteIds as $sid) {
            $params[] = $sid;
        }
    }
    if ($status !== '' && $status !== 'all' && bwr_valid_status($status)) {
        $sql .= ' AND w.status = ?';
        $params[] = $status;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $sql .= ' AND DATE(a.clock_in) >= ?';
        $params[] = $from;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $sql .= ' AND DATE(a.clock_in) <= ?';
        $params[] = $to;
    }
    $limit = max(1, min(500, $limit));
    $sql .= ' ORDER BY w.requested_at DESC, w.id DESC LIMIT ' . $limit;
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function bwr_may_access_site(int $siteId, int $siteOrg, int $currentOrg, bool $isSuper, string $assignedCsv): bool
{
    if ($siteId <= 0 || $siteOrg <= 0) {
        return false;
    }
    if ($isSuper) {
        return true;
    }
    if ($currentOrg <= 0 || $siteOrg !== $currentOrg) {
        return false;
    }
    $ids = array_filter(array_map('intval', explode(',', $assignedCsv)));
    if ($ids === []) {
        return true;
    }
    return in_array($siteId, $ids, true);
}
