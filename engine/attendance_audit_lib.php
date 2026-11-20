<?php
/**
 * Audit log for attendance writes.
 * aa_log() never throws and never stops the save that called it.
 * created_at is stored in UTC. Selfie blobs are not copied into the JSON.
 */

function aa_actions(): array
{
    return ['create', 'update', 'delete', 'finalize', 'unfinalize'];
}

function aa_clock_sources(): array
{
    return [
        'api/punch.php',
        'process_punch.php',
        'process_punch1.php',
        'process_kiosk.php',
        'app_clock.php',
        'app_punch.php',
        'app_upload_attendance.php',
    ];
}

function aa_source_page(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $script = ltrim($script, '/');
    if (strncmp($script, 'payroll/', 8) === 0) {
        $script = substr($script, 8);
    }
    if ($script === '') {
        $script = 'cli';
    }
    return substr($script, 0, 191);
}

function aa_is_clock_source(string $page): bool
{
    $page = strtolower(str_replace('\\', '/', $page));
    foreach (aa_clock_sources() as $src) {
        if ($page === $src || substr($page, -strlen('/' . $src)) === '/' . $src) {
            return true;
        }
    }
    return false;
}

function aa_snapshot(PDO $pdo, int $attendanceId): ?array
{
    if ($attendanceId <= 0) {
        return null;
    }
    try {
        $st = $pdo->prepare('SELECT * FROM attendance WHERE id = ?');
        $st->execute([$attendanceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? aa_slim_row($row) : null;
    } catch (Throwable $e) {
        error_log('attendance audit snapshot: ' . $e->getMessage());
        return null;
    }
}

function aa_slim_row(array $row): array
{
    $out = [];
    foreach ($row as $key => $value) {
        $name = strtolower((string)$key);
        if (strpos($name, 'selfie') !== false || strpos($name, 'photo') !== false) {
            $out[$key] = ($value === null || $value === '') ? $value : '[photo]';
            continue;
        }
        if (is_string($value) && strlen($value) > 500) {
            $out[$key] = '[omitted ' . strlen($value) . ' chars]';
            continue;
        }
        $out[$key] = $value;
    }
    return $out;
}

function aa_actor(PDO $pdo): array
{
    $id = (int)($_SESSION['user_id'] ?? 0);
    $name = trim((string)($_SESSION['user_full_name'] ?? $_SESSION['user_name'] ?? ''));
    if ($name === '' && $id > 0) {
        try {
            $st = $pdo->prepare('SELECT Name FROM users WHERE id = ?');
            $st->execute([$id]);
            $name = trim((string)$st->fetchColumn());
        } catch (Throwable $e) {
            $name = '';
        }
    }
    $role = $_SESSION['user_level'] ?? $_SESSION['user_role'] ?? null;
    return [
        'id' => $id > 0 ? $id : null,
        'name' => $name !== '' ? substr($name, 0, 191) : null,
        'role' => $role === null || $role === '' ? null : substr((string)$role, 0, 64),
    ];
}

function aa_log(PDO $pdo, string $action, int $attendanceId, $before = null, $after = null, string $note = ''): void
{
    try {
        $action = strtolower(trim($action));
        if (!in_array($action, aa_actions(), true) || $attendanceId <= 0) {
            return;
        }
        if ($before !== null && !is_array($before)) {
            $before = null;
        }
        if ($after !== null && !is_array($after)) {
            $after = null;
        }
        if ($after === null && $action !== 'delete') {
            $after = aa_snapshot($pdo, $attendanceId);
        }
        if ($before === null && $action === 'delete') {
            $before = aa_snapshot($pdo, $attendanceId);
        }
        $row = $after ?: $before ?: [];
        $actor = aa_actor($pdo);
        $ip = substr(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 64);
        $agent = substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
        $note = substr(trim($note), 0, 500);
        $site = $row['site_id'] ?? null;
        if ($site === '0' || $site === 'all' || $site === '') {
            $site = null;
        }
        $pdo->prepare(
            'INSERT INTO attendance_audit
                (attendance_id, org_id, site_id, target_user_id, action, actor_user_id, actor_name, actor_role,
                 ip, user_agent, source_page, before_json, after_json, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $attendanceId,
            isset($row['org_id']) && $row['org_id'] !== '' ? (int)$row['org_id'] : null,
            $site !== null ? (int)$site : null,
            isset($row['user_id']) && $row['user_id'] !== '' ? (int)$row['user_id'] : null,
            $action,
            $actor['id'],
            $actor['name'],
            $actor['role'],
            $ip !== '' ? $ip : null,
            $agent !== '' ? $agent : null,
            aa_source_page(),
            $before ? json_encode(aa_slim_row($before), JSON_UNESCAPED_UNICODE) : null,
            $after ? json_encode(aa_slim_row($after), JSON_UNESCAPED_UNICODE) : null,
            $note !== '' ? $note : null,
            gmdate('Y-m-d H:i:s'),
        ]);
        aa_stamp_actor($pdo, $action, $attendanceId, $actor['id']);
    } catch (Throwable $e) {
        error_log('attendance audit: ' . $e->getMessage());
    }
}

function aa_stamp_actor(PDO $pdo, string $action, int $attendanceId, ?int $actorId): void
{
    if ($actorId === null || $actorId <= 0 || $attendanceId <= 0) {
        return;
    }
    try {
        if ($action === 'create') {
            $pdo->prepare('UPDATE attendance SET created_by = ? WHERE id = ? AND created_by IS NULL')->execute([$actorId, $attendanceId]);
        }
        $pdo->prepare('UPDATE attendance SET updated_by = ? WHERE id = ?')->execute([$actorId, $attendanceId]);
    } catch (Throwable $e) {
        error_log('attendance audit stamp: ' . $e->getMessage());
    }
}

function aa_befores(PDO $pdo, array $ids): array
{
    $out = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id <= 0) {
            continue;
        }
        $out[$id] = aa_snapshot($pdo, $id);
    }
    return $out;
}

function aa_ids_where(PDO $pdo, string $whereSql, array $params): array
{
    try {
        $st = $pdo->prepare('SELECT id FROM attendance WHERE ' . $whereSql);
        $st->execute($params);
        return array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    } catch (Throwable $e) {
        error_log('attendance audit ids: ' . $e->getMessage());
        return [];
    }
}

function aa_log_ids(PDO $pdo, string $action, array $ids, array $befores, string $note = ''): void
{
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id <= 0) {
            continue;
        }
        aa_log($pdo, $action, $id, $befores[$id] ?? null, null, $note);
    }
}

/**
 * Badge text for Staff Audit. Real clock-ins return ''.
 * Manual rows with no stored actor return the unknown sentence.
 *
 * @param list<int> $ids
 * @return array<int,string>
 */
function aa_manual_badges(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
    if (!$ids) {
        return [];
    }
    $out = [];
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $created = 'NULL AS created_by';
        try {
            $col = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'created_by'");
            if ($col && $col->fetch(PDO::FETCH_ASSOC)) {
                $created = 'a.created_by';
            }
        } catch (Throwable $e) {
            $created = 'NULL AS created_by';
        }
        $st = $pdo->prepare("SELECT a.id, a.Remarks AS remarks, $created FROM attendance a WHERE a.id IN ($in)");
        $st->execute($ids);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int)$row['id']] = $row;
        }
        $audits = [];
        try {
            $aq = $pdo->prepare(
                "SELECT attendance_id, actor_name, actor_user_id, source_page, created_at
                 FROM attendance_audit
                 WHERE action = 'create' AND attendance_id IN ($in)
                 ORDER BY id ASC"
            );
            $aq->execute($ids);
            foreach ($aq->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $aid = (int)$row['attendance_id'];
                if (!isset($audits[$aid])) {
                    $audits[$aid] = $row;
                }
            }
        } catch (Throwable $e) {
            $audits = [];
        }
        $names = [];
        foreach ($rows as $id => $row) {
            $remarks = trim((string)($row['remarks'] ?? ''));
            $audit = $audits[$id] ?? null;
            $source = (string)($audit['source_page'] ?? '');
            $injected = $remarks === 'Manually Injected Shift';
            $manualSource = $source !== '' && !aa_is_clock_source($source);
            if (!$injected && !$manualSource) {
                continue;
            }
            $actor = trim((string)($audit['actor_name'] ?? ''));
            $createdBy = (int)($row['created_by'] ?? 0);
            if ($actor === '' && $createdBy <= 0 && $audit) {
                $createdBy = (int)($audit['actor_user_id'] ?? 0);
            }
            if ($actor === '' && $createdBy > 0) {
                if (!array_key_exists($createdBy, $names)) {
                    try {
                        $ns = $pdo->prepare('SELECT Name FROM users WHERE id = ?');
                        $ns->execute([$createdBy]);
                        $names[$createdBy] = trim((string)$ns->fetchColumn());
                    } catch (Throwable $e) {
                        $names[$createdBy] = '';
                    }
                }
                $actor = $names[$createdBy];
            }
            $when = '';
            if (!empty($audit['created_at'])) {
                try {
                    $dt = new DateTimeImmutable((string)$audit['created_at'], new DateTimeZone('UTC'));
                    $when = $dt->setTimezone(new DateTimeZone('America/Edmonton'))->format('M j, Y g:i A');
                } catch (Throwable $e) {
                    $when = (string)$audit['created_at'];
                }
            }
            if ($actor === '') {
                $out[$id] = 'Manually added · by unknown (before logging)';
            } elseif ($when !== '') {
                $out[$id] = 'Manually added by ' . $actor . ' · ' . $when;
            } else {
                $out[$id] = 'Manually added by ' . $actor;
            }
        }
    } catch (Throwable $e) {
        error_log('attendance audit badges: ' . $e->getMessage());
    }
    return $out;
}
