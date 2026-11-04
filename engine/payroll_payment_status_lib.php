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
                (batch_id, user_id, site_id, finalized_id, payment_id, employee_name, site_name, amount, method, paid_date, reference_no, note)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($plans as $plan) {
            $ref = trim((string)($plan['reference'] ?? ''));
            if ($ref === '') {
                $ref = 'B' . $batchId;
            }
            $ref = substr($ref, 0, 40);
            $payId = null;
            $got = pcr_apply_payment(
                $pdo,
                (int)$plan['finalized_id'],
                (float)$plan['amount'],
                (string)$plan['method'],
                $orgId,
                $payId,
                $ref,
                null,
                (string)$plan['paid_date']
            );
            if ($got <= 0.009 || !$payId) {
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
                pcr_payment_method($plan['method'] ?? ''),
                $plan['paid_date'],
                substr(trim((string)($plan['reference'] ?? '')), 0, 64),
                substr(trim((string)($plan['note'] ?? '')), 0, 255),
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
        foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $got = pcr_reverse_finalized_payment($pdo, (int)$line['payment_id'], (int)$line['finalized_id'], $orgId, $actorId);
            if ($got > 0) {
                $reversed += $got;
                $n++;
            }
        }
        $pdo->prepare('UPDATE payroll_payment_batches SET undone_at = NOW(), undone_by = ? WHERE id = ? AND undone_at IS NULL')
            ->execute([$actorId, $batchId]);
        if ($own) {
            $pdo->commit();
        }
        return ['payments' => $n, 'amount' => round($reversed, 2), 'batch_id' => $batchId];
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
