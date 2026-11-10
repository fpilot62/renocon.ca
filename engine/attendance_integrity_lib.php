<?php
/**
 * Org checks and soft-delete rules for attendance.
 * The shift's organization comes from sites.org_id (sites.SId), never from the admin session.
 */

function att_manual_remark(): string
{
    return 'Manually Injected Shift';
}

function payroll_admin_denial($userId, int $level): ?string
{
    if ($userId === null || $userId === '') {
        return 'login';
    }
    if ($level > 20) {
        return 'admin';
    }
    return null;
}

function payroll_require_admin(bool $json, string $adminMessage = 'Admins only.'): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $denial = payroll_admin_denial($_SESSION['user_id'] ?? null, (int)($_SESSION['user_level'] ?? 99));
    if ($denial === null) {
        return;
    }
    if ($json) {
        http_response_code($denial === 'login' ? 401 : 403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'status' => 'error',
            'error' => $denial === 'login' ? 'Login required.' : $adminMessage,
        ]);
        exit;
    }
    if ($denial === 'login') {
        header('Location: login_kiosk.php?err=session_expired');
        exit;
    }
    http_response_code(403);
    exit($adminMessage);
}

function payroll_require_scope_admin(): void
{
    $scope = __DIR__ . '/../payroll_scope.php';
    if (is_file($scope)) {
        require_once $scope;
    }
    if (function_exists('ps_require_payroll_admin')) {
        ps_require_payroll_admin();
    }
}

/**
 * Superadmins (level <= 10) may manage any org. A selected org limits them to that org.
 * Other admins may manage only their session org.
 */
function att_admin_may_manage_org(int $userLevel, int $sessionOrg, int $targetOrg, int $selectedOrg = 0): bool
{
    if ($userLevel > 20 || $targetOrg <= 0) {
        return false;
    }
    if ($selectedOrg > 0 && $targetOrg !== $selectedOrg) {
        return false;
    }
    if ($userLevel <= 10) {
        return true;
    }
    return $sessionOrg > 0 && $targetOrg === $sessionOrg;
}

function att_row_orgs_manageable(int $userLevel, int $sessionOrg, int $selectedOrg, int $attendanceOrg, int $userOrg, int $siteOrg): bool
{
    foreach ([$attendanceOrg, $userOrg, $siteOrg] as $org) {
        if ($org > 0 && att_admin_may_manage_org($userLevel, $sessionOrg, $org, $selectedOrg)) {
            return true;
        }
    }
    return false;
}

/**
 * @param array<string,mixed> $site sites row; primary key is SId
 * @param array<string,mixed> $employee users row
 * @return array{ok:bool,org_id:int,error:string}
 */
function att_manual_shift_decision(array $site, array $employee, int $userLevel, int $sessionOrg, int $selectedOrg): array
{
    $siteOrg = (int)($site['org_id'] ?? 0);
    $employeeOrg = (int)($employee['org_id'] ?? 0);
    $siteId = (int)($site['SId'] ?? $site['Sid'] ?? 0);
    $employeeId = (int)($employee['id'] ?? 0);
    if ($siteId <= 0 || $siteOrg <= 0) {
        return ['ok' => false, 'org_id' => 0, 'error' => 'That site is not in an organization. The shift was not saved.'];
    }
    if ($employeeId <= 0 || $employeeOrg <= 0) {
        return ['ok' => false, 'org_id' => 0, 'error' => 'That employee is not in an organization. The shift was not saved.'];
    }
    if ($employeeOrg !== $siteOrg) {
        return ['ok' => false, 'org_id' => 0, 'error' => 'The employee and the site are in different organizations. The shift was not saved.'];
    }
    if (!att_admin_may_manage_org($userLevel, $sessionOrg, $siteOrg, $selectedOrg)) {
        return ['ok' => false, 'org_id' => 0, 'error' => 'That organization is outside your access. The shift was not saved.'];
    }
    return ['ok' => true, 'org_id' => $siteOrg, 'error' => ''];
}

function att_sql_alias(string $alias): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid SQL alias.');
    }
    return $alias;
}

function att_live_predicate_sql(string $alias = 'a'): string
{
    return att_sql_alias($alias) . '.deleted_at IS NULL';
}

function att_finalized_live_predicate_sql(string $alias = 'f'): string
{
    $alias = att_sql_alias($alias);
    return '(IFNULL(' . $alias . '.attendance_id, 0) = 0 OR NOT EXISTS ('
        . 'SELECT 1 FROM attendance att_soft WHERE att_soft.id = ' . $alias . '.attendance_id AND att_soft.deleted_at IS NOT NULL))';
}

function att_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = spl_object_id($pdo) . '|' . $table . '|' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        $cache[$key] = false;
        return false;
    }
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$table, $column]);
        $cache[$key] = ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function att_has_deleted_at(PDO $pdo): bool
{
    return att_has_column($pdo, 'attendance', 'deleted_at');
}

function att_soft_delete_ready(PDO $pdo): bool
{
    return att_has_deleted_at($pdo) && att_has_column($pdo, 'attendance', 'deleted_by');
}

function att_live_predicate(PDO $pdo, string $alias = 'a'): string
{
    if (!att_has_deleted_at($pdo)) {
        return '1=1';
    }
    return att_live_predicate_sql($alias);
}

function att_finalized_live_predicate(PDO $pdo, string $alias = 'f'): string
{
    if (!att_has_deleted_at($pdo)) {
        return '1=1';
    }
    return att_finalized_live_predicate_sql($alias);
}

function att_soft_delete_sql(int $idCount, bool $restrictOrg): string
{
    if ($idCount < 1) {
        throw new InvalidArgumentException('No attendance ids.');
    }
    $placeholders = implode(',', array_fill(0, $idCount, '?'));
    $sql = 'UPDATE attendance a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN sites s ON s.SId = a.site_id
        SET a.deleted_at = NOW(), a.deleted_by = ?
        WHERE a.deleted_at IS NULL AND a.id IN (' . $placeholders . ')';
    if ($restrictOrg) {
        $sql .= ' AND (a.org_id = ? OR u.org_id = ? OR s.org_id = ?)';
    }
    return $sql;
}

function att_manual_mismatch_count_sql(bool $hasDeletedAt = true): string
{
    $live = $hasDeletedAt ? 'a.deleted_at IS NULL AND ' : '';
    return 'SELECT COUNT(*) FROM attendance a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN sites s ON s.SId = a.site_id
        WHERE ' . $live . 'a.remarks = ?
          AND a.org_id <> ?
          AND (u.org_id = ? OR s.org_id = ?)';
}

/**
 * @param array<string,mixed> $source
 * @return list<int>
 */
function att_posted_ids(array $source): array
{
    $ids = [];
    foreach (['ids', 'id', 'shift_ids', 'attendance_ids', 'shift_id', 'attendance_id'] as $key) {
        if (!array_key_exists($key, $source)) {
            continue;
        }
        $value = $source[$key];
        $parts = is_array($value) ? $value : preg_split('/\s*,\s*/', (string)$value);
        if (!is_array($parts)) {
            continue;
        }
        foreach ($parts as $part) {
            $n = (int)$part;
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
    }
    return array_values($ids);
}

function att_selfie_data_uri(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || strcasecmp($raw, 'nophoto.jpg') === 0) {
        return '';
    }
    if (stripos($raw, 'data:image/') === 0) {
        return $raw;
    }
    if (preg_match('#^[A-Za-z0-9+/=\r\n]+$#', $raw) && strlen($raw) > 80) {
        return 'data:image/jpeg;base64,' . preg_replace('/\s+/', '', $raw);
    }
    return '';
}

function att_undo_shift_sql(): string
{
    return 'SELECT a.id, a.org_id, u.org_id AS user_org, s.org_id AS site_org
            FROM attendance a
            LEFT JOIN users u ON u.id = a.user_id
            LEFT JOIN sites s ON s.SId = a.site_id
            WHERE a.id = ? AND a.deleted_at IS NULL
            LIMIT 1';
}

function att_undo_paid_sql(): string
{
    return 'SELECT COALESCE(SUM(paid_amount), 0) FROM payroll_finalized WHERE attendance_id = ?';
}

function att_undo_attendance_sql(): string
{
    return 'UPDATE attendance SET status = 4, payout_status = 0 WHERE id = ? AND deleted_at IS NULL';
}

function att_undo_finalized_sql(): string
{
    return 'DELETE FROM payroll_finalized WHERE attendance_id = ? AND COALESCE(paid_amount, 0) <= 0.02';
}

function att_undo_balance_sql(): string
{
    return 'DELETE FROM payroll_balance WHERE attendance_id = ?';
}

/**
 * Send unpaid finalized shifts back to audit. A row with a recorded payment is left as it is.
 * Does not dump or rewrite the database.
 *
 * @param list<int> $ids
 * @return array{undone:int,skipped:list<string>}
 */
function att_undo_finalized_shifts(PDO $pdo, array $ids, int $userLevel, int $sessionOrg, int $selectedOrg): array
{
    $done = 0;
    $skipped = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id <= 0) {
            continue;
        }
        $st = $pdo->prepare(att_undo_shift_sql());
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $skipped[] = 'Shift ' . $id . ' was not found.';
            continue;
        }
        $attendanceOrg = (int)($row['org_id'] ?? 0);
        $userOrg = (int)($row['user_org'] ?? 0);
        $siteOrg = (int)($row['site_org'] ?? 0);
        if (!att_row_orgs_manageable($userLevel, $sessionOrg, $selectedOrg, $attendanceOrg, $userOrg, $siteOrg)) {
            $skipped[] = 'Shift ' . $id . ' is outside your organization.';
            continue;
        }
        $paid = $pdo->prepare(att_undo_paid_sql());
        $paid->execute([$id]);
        if ((float)$paid->fetchColumn() > 0.02) {
            $skipped[] = 'Shift ' . $id . ' has a recorded payment and was left finalized.';
            continue;
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare(att_undo_attendance_sql())->execute([$id]);
            $pdo->prepare(att_undo_finalized_sql())->execute([$id]);
            $pdo->prepare(att_undo_balance_sql())->execute([$id]);
            $pdo->commit();
            $done++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $skipped[] = 'Shift ' . $id . ' could not be undone.';
        }
    }
    return ['undone' => $done, 'skipped' => $skipped];
}
