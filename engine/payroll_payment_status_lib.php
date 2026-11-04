<?php
/**
 * Path: /engine/payroll_payment_status_lib.php
 * Bulk payment entry. Writes payroll_payments through pcr_apply_payment and
 * reverses a save by the same reversal used for cheques. Does not change
 * finalized gross, CPP, EI, or tax.
 */

require_once __DIR__ . '/payroll_cheque_lib.php';

function pstat_window(string $day, string $mode, string $anchor = '2024-01-01'): array
{
    if (function_exists('sip_period_window')) {
        return sip_period_window($day, $mode, $anchor);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        $day = date('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) {
        $anchor = '2024-01-01';
    }
    if ($mode === 'monthly') {
        $ts = strtotime($day) ?: time();
        return [date('Y-m-01', $ts), date('Y-m-t', $ts)];
    }
    if ($mode === 'weekly' || $mode === 'bi_weekly') {
        $span = ($mode === 'weekly') ? 7 : 14;
        $d0 = new DateTimeImmutable($anchor);
        $d1 = new DateTimeImmutable($day);
        $days = (int)$d0->diff($d1)->format('%r%a');
        $block = (int)floor($days / $span);
        $start = $d0->modify(($block * $span) . ' days');
        $end = $start->modify('+' . ($span - 1) . ' days');
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }
    $ts = strtotime($day) ?: time();
    $dom = (int)date('j', $ts);
    if ($dom <= 15) {
        return [date('Y-m-01', $ts), date('Y-m-15', $ts)];
    }
    return [date('Y-m-16', $ts), date('Y-m-t', $ts)];
}

function pstat_period_label(string $start, string $end): string
{
    $a = strtotime($start) ?: time();
    $b = strtotime($end) ?: $a;
    return date('M j, Y', $a) . ' – ' . date('M j, Y', $b);
}

/** Periods from the anchor through the period that contains $today, newest first. */
function pstat_list_periods(string $mode, string $anchor, string $today): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
        $today = date('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) {
        $anchor = '2024-01-01';
    }
    [$curStart] = pstat_window($today, $mode, $anchor);
    $cursor = $anchor;
    if ($cursor > $curStart) {
        $cursor = $curStart;
    }
    $out = [];
    $seen = [];
    $guard = 0;
    while ($guard++ < 400) {
        [$start, $end] = pstat_window($cursor, $mode, $anchor);
        $key = $start . '|' . $end;
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $out[] = ['start' => $start, 'end' => $end, 'label' => pstat_period_label($start, $end)];
        }
        if ($start >= $curStart) {
            break;
        }
        $next = date('Y-m-d', strtotime($end . ' +1 day'));
        if ($next <= $cursor) {
            break;
        }
        $cursor = $next;
    }
    [$curStart, $curEnd] = pstat_window($today, $mode, $anchor);
    $curKey = $curStart . '|' . $curEnd;
    if (!isset($seen[$curKey])) {
        $out[] = ['start' => $curStart, 'end' => $curEnd, 'label' => pstat_period_label($curStart, $curEnd)];
    }
    return array_reverse($out);
}

function pstat_accept_period(string $start, string $end, string $mode, string $anchor): ?array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        return null;
    }
    [$checkStart, $checkEnd] = pstat_window($start, $mode, $anchor);
    if ($checkStart !== $start || $checkEnd !== $end) {
        return null;
    }
    return [$start, $end];
}

/**
 * Period to open. A posted save wins, then ?period=YYYY-MM-DD|YYYY-MM-DD,
 * then from_date/to_date, then the period that contains $today.
 */
function pstat_requested_period(array $query, array $body, string $mode, string $anchor, string $today): array
{
    $candidates = [
        [(string)($body['period_start'] ?? ''), (string)($body['period_end'] ?? '')],
    ];
    $period = trim((string)($query['period'] ?? $body['period'] ?? ''));
    if (preg_match('/^(\d{4}-\d{2}-\d{2})\|(\d{4}-\d{2}-\d{2})$/', $period, $m)) {
        $candidates[] = [$m[1], $m[2]];
    }
    $candidates[] = [(string)($query['from_date'] ?? ''), (string)($query['to_date'] ?? '')];
    foreach ($candidates as $pair) {
        $ok = pstat_accept_period($pair[0], $pair[1], $mode, $anchor);
        if ($ok) {
            return $ok;
        }
    }
    return pstat_window($today, $mode, $anchor);
}

/**
 * Amount this save should record.
 * paid = the remaining net. partial = the typed amount, rejected when it
 * exceeds remaining. unpaid records nothing.
 */
function pstat_payment_amount(string $status, float $remaining, float $entered): array
{
    $remaining = round(max(0, $remaining), 2);
    $status = strtolower(trim($status));
    if ($status === 'unpaid' || $status === '') {
        return ['amount' => 0.0, 'warning' => null];
    }
    if ($status === 'paid' || $status === 'full') {
        return ['amount' => $remaining, 'warning' => null];
    }
    if ($status === 'partial') {
        $entered = round($entered, 2);
        if ($entered < 0) {
            return ['amount' => 0.0, 'warning' => 'Amount cannot be negative.'];
        }
        if ($entered - $remaining > 0.009) {
            return [
                'amount' => 0.0,
                'warning' => 'Amount $' . number_format($entered, 2) . ' is more than the $' . number_format($remaining, 2) . ' still owing.',
            ];
        }
        return ['amount' => max(0, $entered), 'warning' => null];
    }
    return ['amount' => 0.0, 'warning' => 'Unknown payment status.'];
}

/**
 * Spread a payment across finalized lines, oldest first, never above each
 * line's remaining net.
 */
function pstat_allocate(float $amount, array $lines): array
{
    $left = round($amount, 2);
    usort($lines, static function ($a, $b) {
        $da = (string)($a['work_date'] ?? '');
        $db = (string)($b['work_date'] ?? '');
        if ($da === $db) {
            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        }
        return $da <=> $db;
    });
    $out = [];
    foreach ($lines as $ln) {
        if ($left <= 0.009) {
            break;
        }
        $room = round(max(0, (float)($ln['remaining'] ?? 0)), 2);
        $take = round(min($left, $room), 2);
        if ($take <= 0.009) {
            continue;
        }
        $out[] = ['id' => (int)$ln['id'], 'amount' => $take];
        $left = round($left - $take, 2);
    }
    return $out;
}

function pstat_row_key(int $siteId, int $userId): string
{
    return $siteId . '_' . $userId;
}

function pstat_default_org_id(PDO $pdo): int
{
    try {
        $st = $pdo->prepare('SELECT id FROM organizations WHERE org_name = ? LIMIT 1');
        $st->execute(['PK BC Food INC']);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        $st = $pdo->query("SELECT id FROM organizations WHERE org_name LIKE 'PK BC Food%' ORDER BY id ASC LIMIT 1");
        return (int)($st ? $st->fetchColumn() : 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function pstat_ensure_menu(PDO $pdo): void
{
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM menu_manager')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }
    if (!$cols) {
        return;
    }
    $have = array_flip($cols);
    $exists = $pdo->query("SELECT MenuID FROM menu_manager WHERE LinkURL LIKE '%payroll_payment_status.php%' LIMIT 1");
    if ($exists && $exists->fetchColumn()) {
        return;
    }
    $parentId = 0;
    $module = '';
    $role = 20;
    $parent = $pdo->query("SELECT * FROM menu_manager WHERE ParentID = 0 AND UPPER(TRIM(MenuTitle)) = 'PAYROLL' LIMIT 1");
    $parentRow = $parent ? $parent->fetch(PDO::FETCH_ASSOC) : false;
    if (!$parentRow) {
        $parent = $pdo->query("SELECT * FROM menu_manager WHERE UPPER(MenuTitle) LIKE '%PAYROLL%' AND (ParentID = 0 OR ParentID IS NULL) ORDER BY MenuID ASC LIMIT 1");
        $parentRow = $parent ? $parent->fetch(PDO::FETCH_ASSOC) : false;
    }
    if ($parentRow) {
        $parentId = (int)($parentRow['MenuID'] ?? 0);
        if (isset($parentRow['Module'])) {
            $module = (string)$parentRow['Module'];
        }
    }
    $sib = $pdo->query("SELECT * FROM menu_manager WHERE LinkURL LIKE '%payroll_master_ledger.php%' LIMIT 1");
    $sibRow = $sib ? $sib->fetch(PDO::FETCH_ASSOC) : false;
    if ($sibRow) {
        if ($parentId <= 0) {
            $parentId = (int)($sibRow['ParentID'] ?? 0);
        }
        if ($module === '' && isset($sibRow['Module'])) {
            $module = (string)$sibRow['Module'];
        }
    }
    $data = [
        'MenuTitle' => 'Payment Status',
        'LinkURL' => 'payroll_payment_status.php',
        'ParentID' => $parentId,
        'IsActive' => 1,
        'UserRole' => $role,
        'Module' => $module,
    ];
    $fields = [];
    $holders = [];
    $vals = [];
    foreach ($data as $col => $val) {
        if (!isset($have[$col])) {
            continue;
        }
        $fields[] = '`' . str_replace('`', '', $col) . '`';
        $holders[] = '?';
        $vals[] = $val;
    }
    if (!$fields) {
        return;
    }
    $pdo->prepare('INSERT INTO menu_manager (' . implode(',', $fields) . ') VALUES (' . implode(',', $holders) . ')')->execute($vals);
}

function pstat_save_batch(PDO $pdo, int $orgId, string $start, string $end, int $actorId, array $plans): int
{
    pcr_ensure($pdo);
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $ins = $pdo->prepare(
            'INSERT INTO payroll_payment_batches (org_id, period_start, period_end, created_by, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $ins->execute([$orgId, $start, $end, $actorId]);
        $batchId = (int)$pdo->lastInsertId();
        $lineIns = $pdo->prepare(
            'INSERT INTO payroll_payment_batch_lines
                (batch_id, user_id, site_id, finalized_id, payment_id, employee_name, site_name, amount, method, paid_date, reference_no, note, created_line, created_user_id, owed_amount)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $namesCreated = [];
        foreach ($plans as $plan) {
            $createdLine = 0;
            $createdUserId = null;
            $owedAmount = round((float)($plan['owed'] ?? 0), 2);
            if (!empty($plan['manual']) && is_array($plan['manual'])) {
                $made = pstat_materialize_manual($pdo, $orgId, $plan['manual'], $start, $end, $namesCreated);
                $plan['finalized_id'] = $made['finalized_id'];
                $plan['user_id'] = $made['user_id'];
                $plan['employee_name'] = $made['employee_name'];
                $createdLine = 1;
                $createdUserId = $made['created_user_id'] > 0 ? $made['created_user_id'] : null;
                $owedAmount = $made['owed'];
            }
            $ref = trim((string)($plan['reference'] ?? ''));
            if ($ref === '') {
                $ref = 'B' . $batchId;
            }
            $ref = substr($ref, 0, 40);
            $payId = 0;
            $got = 0.0;
            $method = trim((string)($plan['method'] ?? ''));
            if ($method !== '' && !in_array($method, pcr_payment_methods(), true)) {
                $method = pcr_payment_method($method);
            }
            if ((float)$plan['amount'] > 0.009 && (int)$plan['finalized_id'] > 0) {
                if (!in_array($method, pcr_payment_methods(), true)) {
                    $who = trim((string)($plan['employee_name'] ?? ''));
                    throw new RuntimeException(($who !== '' ? $who . ': ' : '') . 'Choose Cash, Cheque, or e-Transfer.');
                }
                $newPayId = null;
                $got = pcr_apply_payment(
                    $pdo,
                    (int)$plan['finalized_id'],
                    (float)$plan['amount'],
                    $method,
                    $orgId,
                    $newPayId,
                    $ref,
                    null,
                    (string)$plan['paid_date']
                );
                $payId = (int)$newPayId;
            }
            if ($got <= 0.009 && $createdLine !== 1) {
                continue;
            }
            $lineIns->execute([
                $batchId,
                (int)$plan['user_id'],
                (int)$plan['site_id'],
                (int)$plan['finalized_id'],
                $payId,
                substr((string)($plan['employee_name'] ?? ''), 0, 160),
                substr((string)($plan['site_name'] ?? ''), 0, 160),
                $got,
                $method,
                $plan['paid_date'] ?? null,
                substr(trim((string)($plan['reference'] ?? '')), 0, 64),
                substr(trim((string)($plan['note'] ?? '')), 0, 255),
                $createdLine,
                $createdUserId,
                $owedAmount,
            ]);
        }
        $count = $pdo->prepare('SELECT COUNT(*) FROM payroll_payment_batch_lines WHERE batch_id = ?');
        $count->execute([$batchId]);
        if ((int)$count->fetchColumn() < 1) {
            $pdo->prepare('DELETE FROM payroll_payment_batches WHERE id = ?')->execute([$batchId]);
            if ($own) {
                $pdo->commit();
            }
            return 0;
        }
        if ($own) {
            $pdo->commit();
        }
        return $batchId;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function pstat_reverse_batch(PDO $pdo, int $batchId, int $orgId, int $actorId): array
{
    pcr_ensure($pdo);
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $st = $pdo->prepare('SELECT * FROM payroll_payment_batches WHERE id = ? AND org_id = ? FOR UPDATE');
        $st->execute([$batchId, $orgId]);
        $batch = $st->fetch(PDO::FETCH_ASSOC);
        if (!$batch) {
            throw new RuntimeException('That save was not found for this organization.');
        }
        if (!empty($batch['undone_at'])) {
            throw new RuntimeException('That save was already undone.');
        }
        $lines = $pdo->prepare('SELECT * FROM payroll_payment_batch_lines WHERE batch_id = ?');
        $lines->execute([$batchId]);
        $reversed = 0.0;
        $n = 0;
        $removed = 0;
        $fetched = $lines->fetchAll(PDO::FETCH_ASSOC);
        foreach ($fetched as $line) {
            $got = pcr_reverse_finalized_payment($pdo, (int)$line['payment_id'], (int)$line['finalized_id'], $orgId, $actorId);
            if ($got > 0) {
                $reversed += $got;
                $n++;
            }
        }
        $createdUsers = [];
        foreach ($fetched as $line) {
            if (empty($line['created_line'])) {
                continue;
            }
            if (pstat_remove_manual_line($pdo, (int)$line['finalized_id'], $orgId)) {
                $removed++;
            }
            if (!empty($line['created_user_id'])) {
                $createdUsers[(int)$line['created_user_id']] = true;
            }
        }
        foreach (array_keys($createdUsers) as $createdUserId) {
            pstat_remove_manual_user($pdo, (int)$createdUserId, $orgId);
        }
        $pdo->prepare('UPDATE payroll_payment_batches SET undone_at = NOW(), undone_by = ? WHERE id = ? AND undone_at IS NULL')
            ->execute([$actorId, $batchId]);
        if ($own) {
            $pdo->commit();
        }
        return ['payments' => $n, 'amount' => round($reversed, 2), 'batch_id' => $batchId, 'removed' => $removed];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function pstat_latest_open_batch_id(PDO $pdo, int $orgId): int
{
    pcr_ensure($pdo);
    $st = $pdo->prepare(
        'SELECT id FROM payroll_payment_batches WHERE org_id = ? AND undone_at IS NULL ORDER BY id DESC LIMIT 1'
    );
    $st->execute([$orgId]);
    return (int)$st->fetchColumn();
}

function pstat_batch_history(PDO $pdo, int $orgId, int $limit = 40): array
{
    pcr_ensure($pdo);
    $limit = max(1, min(100, $limit));
    $st = $pdo->prepare(
        "SELECT b.*, u.Name AS actor_name,
                (SELECT COUNT(DISTINCT CONCAT(l.site_id, '-', l.user_id)) FROM payroll_payment_batch_lines l WHERE l.batch_id = b.id) AS row_count,
                (SELECT COALESCE(SUM(l.amount),0) FROM payroll_payment_batch_lines l WHERE l.batch_id = b.id) AS total_amount
         FROM payroll_payment_batches b
         LEFT JOIN users u ON u.id = b.created_by
         WHERE b.org_id = ?
         ORDER BY b.id DESC
         LIMIT $limit"
    );
    $st->execute([$orgId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Add-name method. Blank stays blank — it is not treated as EFT or e-Transfer. */
function pstat_added_method($raw): string
{
    $raw = trim((string)$raw);
    return in_array($raw, pcr_payment_methods(), true) ? $raw : '';
}

function pstat_added_is_blank(array $row): bool
{
    $name = trim((string)($row['name'] ?? ''));
    $uid = (int)($row['user_id'] ?? 0);
    $owed = round((float)($row['owed'] ?? 0), 2);
    $amt = round((float)($row['amount'] ?? 0), 2);
    $status = strtolower(trim((string)($row['status'] ?? 'unpaid')));
    return $uid <= 0 && $name === '' && $owed <= 0.009 && $amt <= 0.009 && ($status === '' || $status === 'unpaid');
}

/**
 * Check one "add name" row. $nameToId maps a lower-case name to a user id,
 * or 0 when more than one employee shares that name. $occupied is "site_user".
 * A blank row is skipped. An amount above what is owed is a warning.
 */
function pstat_review_added(array $row, array $nameToId, array $userNames, array $siteIds, array $occupied): array
{
    if (pstat_added_is_blank($row)) {
        return ['skip' => true, 'warning' => null];
    }
    $uid = (int)($row['user_id'] ?? 0);
    $typed = trim((string)($row['name'] ?? ''));
    $siteId = (int)($row['site_id'] ?? 0);
    $owed = round((float)($row['owed'] ?? 0), 2);
    if (!isset($siteIds[$siteId])) {
        return ['skip' => false, 'warning' => ($typed !== '' ? $typed : 'New name') . ': choose a store.'];
    }
    if ($uid > 0 && !isset($userNames[$uid])) {
        return ['skip' => false, 'warning' => 'That employee is not in this organization.'];
    }
    if ($uid <= 0) {
        $keyName = strtolower($typed);
        if ($keyName === '' || strlen($typed) < 2) {
            return ['skip' => false, 'warning' => 'Type a name, or pick someone from the list.'];
        }
        if (strlen($typed) > 120) {
            return ['skip' => false, 'warning' => $typed . ': that name is too long.'];
        }
        if (array_key_exists($keyName, $nameToId)) {
            if ((int)$nameToId[$keyName] <= 0) {
                return ['skip' => false, 'warning' => $typed . ': more than one employee has that name. Pick one from the list.'];
            }
            $uid = (int)$nameToId[$keyName];
        }
    }
    $display = $uid > 0 ? (string)$userNames[$uid] : $typed;
    if ($owed <= 0.009) {
        return ['skip' => false, 'warning' => $display . ': enter the amount owed.'];
    }
    if ($owed > 1000000) {
        return ['skip' => false, 'warning' => $display . ': amount owed looks too large.'];
    }
    $slot = $siteId . '_' . ($uid > 0 ? $uid : ('new:' . strtolower($display)));
    if (isset($occupied[$slot])) {
        return ['skip' => false, 'warning' => $display . ' is already on this period for that store. Edit that row instead of adding them again.'];
    }
    $calc = pstat_payment_amount((string)($row['status'] ?? ''), $owed, (float)($row['amount'] ?? 0));
    if ($calc['warning']) {
        return ['skip' => false, 'warning' => $display . ': ' . $calc['warning']];
    }
    $date = (string)($row['paid_date'] ?? '');
    if ($calc['amount'] > 0.009 && pcr_valid_payment_date($date) === null) {
        return ['skip' => false, 'warning' => $display . ': enter a pay date between 2018 and two weeks from today.'];
    }
    return [
        'skip' => false,
        'warning' => null,
        'slot' => $slot,
        'user_id' => $uid,
        'new_name' => $uid > 0 ? '' : $display,
        'employee_name' => $display,
        'site_id' => $siteId,
        'owed' => $owed,
        'pay_amount' => $calc['amount'],
    ];
}

/**
 * Values for NOT NULL columns with no default that the caller did not set.
 * Legacy tables such as users and payroll_time reject the insert otherwise.
 * Character columns get ''.
 */
function pstat_blank_required_value(array $col)
{
    $type = strtolower((string)($col['Type'] ?? ''));
    if (preg_match('/^(tinyint|smallint|mediumint|bigint|integer|int|decimal|numeric|float|double|real|bit|bool|boolean)\b/', $type)) {
        return 0;
    }
    if (preg_match('/^(datetime|timestamp)\b/', $type)) {
        return '1970-01-01 00:00:00';
    }
    if (preg_match('/^time\b/', $type)) {
        return '00:00:00';
    }
    if (preg_match('/^year\b/', $type)) {
        return '1970';
    }
    if (preg_match('/^date\b/', $type)) {
        return '1970-01-01';
    }
    return '';
}

function pstat_missing_required_values(array $columns, array $data): array
{
    $extra = [];
    foreach ($columns as $col) {
        if (!is_array($col)) {
            continue;
        }
        $field = (string)($col['Field'] ?? '');
        if ($field === '' || array_key_exists($field, $data)) {
            continue;
        }
        if (strtoupper((string)($col['Null'] ?? 'YES')) !== 'NO') {
            continue;
        }
        if (array_key_exists('Default', $col) && $col['Default'] !== null) {
            continue;
        }
        $columnExtra = strtolower((string)($col['Extra'] ?? ''));
        if (strpos($columnExtra, 'auto_increment') !== false || strpos($columnExtra, 'generated') !== false) {
            continue;
        }
        $extra[$field] = pstat_blank_required_value($col);
    }
    return $extra;
}

function pstat_insert_known(PDO $pdo, string $table, array $data): int
{
    $cols = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`')->fetchAll(PDO::FETCH_ASSOC);
    foreach (pstat_missing_required_values($cols, $data) as $field => $val) {
        $data[$field] = $val;
    }
    $have = [];
    foreach ($cols as $col) {
        $have[$col['Field']] = true;
    }
    $fields = [];
    $holders = [];
    $vals = [];
    foreach ($data as $key => $val) {
        if (!isset($have[$key])) {
            continue;
        }
        $fields[] = '`' . str_replace('`', '', (string)$key) . '`';
        $holders[] = '?';
        $vals[] = $val;
    }
    if (!$fields) {
        throw new RuntimeException('Could not store a row in ' . $table . '.');
    }
    $pdo->prepare('INSERT INTO `' . str_replace('`', '', $table) . '` (' . implode(',', $fields) . ') VALUES (' . implode(',', $holders) . ')')
        ->execute($vals);
    return (int)$pdo->lastInsertId();
}

function pstat_create_user(PDO $pdo, int $orgId, string $name): int
{
    $name = trim($name);
    $token = bin2hex(random_bytes(4));
    $username = 'pstat' . $token;
    $hash = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
    $id = pstat_insert_known($pdo, 'users', [
        'Name' => $name,
        'name' => $name,
        'org_id' => $orgId,
        'active' => 0,
        'user_level' => 99,
        'UserLevel' => 99,
        'username' => $username,
        'Username' => $username,
        'password' => $hash,
        'Password' => $hash,
        'Email' => '',
        'email' => '',
        'comments' => 'pstat-manual-name',
        'Notes' => 'Added from Payment Status',
        'Remarks' => '',
        'remarks' => '',
        'eft_no_cheque' => 0,
    ]);
    if ($id <= 0) {
        throw new RuntimeException('Could not add ' . $name . '.');
    }
    return $id;
}

function pstat_find_user_id(PDO $pdo, int $orgId, string $name): int
{
    $st = $pdo->prepare('SELECT id FROM users WHERE org_id = ? AND LOWER(TRIM(Name)) = LOWER(TRIM(?))');
    $st->execute([$orgId, $name]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (count($ids) > 1) {
        throw new RuntimeException($name . ': more than one employee has that name. Pick one from the list.');
    }
    return $ids[0] ?? 0;
}

/**
 * Create the owed line for a person who was not already on this period.
 * Does not change any existing finalized row.
 */
function pstat_materialize_manual(PDO $pdo, int $orgId, array $manual, string $start, string $end, array &$namesCreated): array
{
    $uid = (int)($manual['user_id'] ?? 0);
    $createdUser = 0;
    $name = trim((string)($manual['new_name'] ?? $manual['employee_name'] ?? ''));
    if ($uid <= 0) {
        $key = strtolower($name);
        if (isset($namesCreated[$key])) {
            $uid = $namesCreated[$key];
        } else {
            $uid = pstat_find_user_id($pdo, $orgId, $name);
            if ($uid <= 0) {
                $uid = pstat_create_user($pdo, $orgId, $name);
                $createdUser = $uid;
            }
            $namesCreated[$key] = $uid;
        }
    }
    $siteId = (int)$manual['site_id'];
    $siteOk = $pdo->prepare('SELECT nick_name FROM sites WHERE SId = ? AND org_id = ?');
    $siteOk->execute([$siteId, $orgId]);
    $siteName = $siteOk->fetchColumn();
    if ($siteName === false) {
        throw new RuntimeException('That store is not in this organization.');
    }
    $userOk = $pdo->prepare('SELECT Name FROM users WHERE id = ? AND org_id = ?');
    $userOk->execute([$uid, $orgId]);
    $userName = $userOk->fetchColumn();
    if ($userName === false) {
        throw new RuntimeException('That employee is not in this organization.');
    }
    $owed = round((float)$manual['owed'], 2);
    $fid = pstat_insert_known($pdo, 'payroll_finalized', [
        'user_id' => $uid,
        'site_id' => $siteId,
        'work_date' => $end,
        'pay_period' => $start,
        'pay_period_end' => $end,
        'total_gross' => $owed,
        'paid_amount' => 0,
        'cpp' => 0,
        'ei' => 0,
        'income_tax' => 0,
        'cpp_er' => 0,
        'ei_er' => 0,
        'wcb' => 0,
        'vac' => 0,
        'reg_hours' => 0,
        'overtime_hours' => 0,
        'travel_hours' => 0,
        'hourly_rate' => 0,
        'status' => 'pending',
        'payment_status' => 'Unpaid',
        'display_label' => 'Manual payment entry',
        'category' => 'Manual',
        'pay_type' => 'Manual',
        'comments' => 'pstat-manual',
        'Remarks' => '',
        'remarks' => '',
    ]);
    if ($fid <= 0) {
        throw new RuntimeException('Could not add a payment line for ' . $userName . '.');
    }
    return [
        'finalized_id' => $fid,
        'user_id' => $uid,
        'created_user_id' => $createdUser,
        'employee_name' => (string)$userName,
        'site_name' => (string)$siteName,
        'owed' => $owed,
    ];
}

function pstat_remove_manual_line(PDO $pdo, int $finalizedId, int $orgId): bool
{
    if ($finalizedId <= 0) {
        return false;
    }
    $st = $pdo->prepare(
        'SELECT f.* FROM payroll_finalized f
         INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
         WHERE f.id = ? LIMIT 1'
    );
    $st->execute([$orgId, $finalizedId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    if ((float)($row['paid_amount'] ?? 0) > 0.02) {
        return false;
    }
    if (array_key_exists('comments', $row) && (string)$row['comments'] !== 'pstat-manual') {
        return false;
    }
    if ((float)($row['cpp'] ?? 0) > 0.001 || (float)($row['ei'] ?? 0) > 0.001 || (float)($row['income_tax'] ?? 0) > 0.001) {
        return false;
    }
    if ((float)($row['reg_hours'] ?? 0) > 0.001 || (float)($row['overtime_hours'] ?? 0) > 0.001) {
        return false;
    }
    if (!empty($row['attendance_id'])) {
        try {
            $pdo->prepare('DELETE FROM payroll_balance WHERE attendance_id = ?')->execute([(int)$row['attendance_id']]);
        } catch (Throwable $e) {
        }
    }
    $del = $pdo->prepare('DELETE FROM payroll_finalized WHERE id = ?');
    $del->execute([$finalizedId]);
    return $del->rowCount() > 0;
}

function pstat_remove_manual_user(PDO $pdo, int $userId, int $orgId): void
{
    if ($userId <= 0) {
        return;
    }
    $fin = $pdo->prepare('SELECT COUNT(*) FROM payroll_finalized WHERE user_id = ?');
    $fin->execute([$userId]);
    if ((int)$fin->fetchColumn() > 0) {
        return;
    }
    try {
        $att = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE user_id = ?');
        $att->execute([$userId]);
        if ((int)$att->fetchColumn() > 0) {
            return;
        }
    } catch (Throwable $e) {
    }
    try {
        $pdo->prepare('DELETE FROM users WHERE id = ? AND org_id = ?')->execute([$userId, $orgId]);
    } catch (Throwable $e) {
    }
}
