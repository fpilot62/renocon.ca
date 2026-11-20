<?php
/** Path: /engine/page_rollback_lib.php
 * Undo last delete. Per restaurant (site_id) for every org. Days setting per org (default 7).
 * Marker: page-rollback-20261019-list
 */

function prb_allowed_days(): array
{
    return [1, 3, 7, 14, 30];
}

function prb_clamp_days($raw): int
{
    $d = (int)$raw;
    return in_array($d, prb_allowed_days(), true) ? $d : 7;
}

function prb_ensure_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $st = $pdo->query("SHOW TABLES LIKE 'sip_page_rollback'");
    if (!$st || !$st->fetchColumn()) {
        $pdo->exec(
            "CREATE TABLE sip_page_rollback (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                page VARCHAR(40) NOT NULL,
                user_id INT NOT NULL DEFAULT 0,
                org_id INT NOT NULL DEFAULT 0,
                site_id INT NOT NULL DEFAULT 0,
                action VARCHAR(40) NOT NULL DEFAULT 'delete',
                payload MEDIUMTEXT NOT NULL,
                created_at DATETIME NOT NULL,
                expires_at DATETIME NULL,
                used_at DATETIME NULL,
                KEY page_site (page, site_id, used_at, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } else {
        $cols = $pdo->query('SHOW COLUMNS FROM sip_page_rollback')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('expires_at', $cols, true)) {
            $pdo->exec('ALTER TABLE sip_page_rollback ADD COLUMN expires_at DATETIME NULL');
            $pdo->exec('UPDATE sip_page_rollback SET expires_at = DATE_ADD(created_at, INTERVAL 7 DAY) WHERE expires_at IS NULL');
        }
    }
    $st2 = $pdo->query("SHOW TABLES LIKE 'sip_page_rollback_settings'");
    if (!$st2 || !$st2->fetchColumn()) {
        $pdo->exec(
            "CREATE TABLE sip_page_rollback_settings (
                org_id INT NOT NULL PRIMARY KEY,
                days INT NOT NULL DEFAULT 7,
                updated_by INT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
    $ready = true;
}

function prb_stash_dir(int $undoId): string
{
    return dirname(__DIR__) . '/uploads/page_rollback/' . (int)$undoId;
}

function prb_org_days(PDO $pdo, int $orgId): int
{
    prb_ensure_table($pdo);
    if ($orgId <= 0) {
        return 7;
    }
    $st = $pdo->prepare('SELECT days FROM sip_page_rollback_settings WHERE org_id = ?');
    $st->execute([$orgId]);
    $d = $st->fetchColumn();
    return $d === false ? 7 : prb_clamp_days($d);
}

function prb_set_org_days(PDO $pdo, int $orgId, int $days, int $userId): int
{
    prb_ensure_table($pdo);
    $days = prb_clamp_days($days);
    if ($orgId <= 0) {
        return $days;
    }
    $st = $pdo->prepare(
        'INSERT INTO sip_page_rollback_settings (org_id, days, updated_by, updated_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE days = VALUES(days), updated_by = VALUES(updated_by), updated_at = NOW()'
    );
    $st->execute([$orgId, $days, $userId]);
    return $days;
}

function prb_org_for_site(PDO $pdo, int $siteId): int
{
    if ($siteId <= 0) {
        return 0;
    }
    $st = $pdo->prepare('SELECT org_id FROM sites WHERE SId = ?');
    $st->execute([$siteId]);
    return (int)$st->fetchColumn();
}

function prb_push(PDO $pdo, string $page, int $userId, int $orgId, int $siteId, string $action, array $payload): int
{
    prb_ensure_table($pdo);
    $days = prb_org_days($pdo, $orgId);
    $st = $pdo->prepare(
        'INSERT INTO sip_page_rollback (page, user_id, org_id, site_id, action, payload, created_at, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY))'
    );
    $st->execute([
        $page,
        $userId,
        $orgId,
        $siteId,
        $action,
        json_encode($payload, JSON_UNESCAPED_UNICODE),
        $days,
    ]);
    return (int)$pdo->lastInsertId();
}

function prb_latest(PDO $pdo, string $page, int $siteId): ?array
{
    $list = prb_list($pdo, $page, $siteId);
    return $list[0] ?? null;
}

function prb_list(PDO $pdo, string $page, int $siteId): array
{
    if ($siteId <= 0) {
        return [];
    }
    prb_ensure_table($pdo);
    $st = $pdo->prepare(
        "SELECT r.*, u.Name AS who_name
         FROM sip_page_rollback r
         LEFT JOIN users u ON u.id = r.user_id
         WHERE r.page = ? AND r.site_id = ? AND r.used_at IS NULL
           AND (r.expires_at IS NULL OR r.expires_at > NOW())
         ORDER BY r.id DESC
         LIMIT 50"
    );
    $st->execute([$page, $siteId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function prb_by_ids(PDO $pdo, string $page, int $siteId, array $ids): array
{
    $want = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $want[$id] = $id;
        }
    }
    if ($siteId <= 0 || !$want) {
        return [];
    }
    $in = implode(',', array_map('intval', $want));
    $st = $pdo->prepare(
        "SELECT * FROM sip_page_rollback
         WHERE page = ? AND site_id = ? AND used_at IS NULL
           AND (expires_at IS NULL OR expires_at > NOW())
           AND id IN ($in)
         ORDER BY id DESC"
    );
    $st->execute([$page, $siteId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function prb_payload_row(array $undo): array
{
    $pl = json_decode((string)($undo['payload'] ?? ''), true);
    $row = is_array($pl['rows'][0] ?? null) ? $pl['rows'][0] : [];
    return is_array($row) ? $row : [];
}

function prb_fc_summary(array $undo): array
{
    $r = prb_payload_row($undo);
    $when = (string)($undo['created_at'] ?? '');
    return [
        'id' => (int)($undo['id'] ?? 0),
        'date' => (string)($r['InvoiceDate'] ?? ''),
        'vendor' => (string)($r['Vendor'] ?? 'Invoice'),
        'amount' => (string)($r['Amount'] ?? ''),
        'invoice' => (string)($r['InvoiceNumber'] ?? ''),
        'who' => trim((string)($undo['who_name'] ?? '')) !== '' ? (string)$undo['who_name'] : ('user #' . (int)($undo['user_id'] ?? 0)),
        'when' => $when,
    ];
}

function prb_dal_summary(array $undo): array
{
    $r = prb_payload_row($undo);
    return [
        'id' => (int)($undo['id'] ?? 0),
        'date' => (string)($r['report_date'] ?? ''),
        'vendor' => 'Daily Activity',
        'amount' => (string)($r['pos_sales'] ?? ''),
        'invoice' => (string)($r['report_id'] ?? ''),
        'who' => trim((string)($undo['who_name'] ?? '')) !== '' ? (string)$undo['who_name'] : ('user #' . (int)($undo['user_id'] ?? 0)),
        'when' => (string)($undo['created_at'] ?? ''),
    ];
}

function prb_fc_confirm(array $sum): string
{
    $vend = trim($sum['vendor'] ?? '') !== '' ? $sum['vendor'] : 'invoice';
    $amt = $sum['amount'] !== '' ? (' $' . number_format((float)$sum['amount'], 2)) : '';
    $day = $sum['date'] !== '' ? date('M j', strtotime($sum['date'])) : '';
    return 'Restore this ' . $vend . $amt . ($day !== '' ? ' ' . $day : '') . '?';
}

function prb_dal_confirm(array $sum): string
{
    $amt = $sum['amount'] !== '' ? (' $' . number_format((float)$sum['amount'], 2)) : '';
    $day = $sum['date'] !== '' ? date('M j', strtotime($sum['date'])) : '';
    return 'Restore this Daily Activity' . $amt . ($day !== '' ? ' ' . $day : '') . '?';
}

function prb_when_label(string $when): string
{
    $ts = $when !== '' ? strtotime($when) : false;
    return $ts ? date('M j g:ia', $ts) : $when;
}

function prb_request_ids($raw = null): array
{
    if ($raw === null) {
        $raw = $_POST['ids'] ?? $_POST['id'] ?? [];
    }
    if (is_string($raw)) {
        $raw = preg_split('/[,\s]+/', $raw);
    }
    if (!is_array($raw)) {
        $raw = [$raw];
    }
    $out = [];
    foreach ($raw as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $out[$id] = $id;
        }
    }
    return array_values($out);
}

function prb_mark_used(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('UPDATE sip_page_rollback SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
    $st->execute([$id]);
}

function prb_can_rollback(int $userLevel): bool
{
    return $userLevel <= 20;
}

function prb_copy_file(string $from, string $to): bool
{
    if ($from === '' || !is_file($from)) {
        return false;
    }
    $dir = dirname($to);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return @copy($from, $to);
}

function prb_insert_assoc(PDO $pdo, string $table, array $row): bool
{
    if (!$row) {
        return false;
    }
    $cols = [];
    $hold = [];
    $vals = [];
    foreach ($row as $k => $v) {
        if (!preg_match('/^[A-Za-z0-9_ ]+$/', (string)$k)) {
            continue;
        }
        $cols[] = '`' . str_replace('`', '', (string)$k) . '`';
        $hold[] = '?';
        $vals[] = $v;
    }
    if (!$cols) {
        return false;
    }
    $sql = 'INSERT INTO `' . str_replace('`', '', $table) . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $hold) . ')';
    $st = $pdo->prepare($sql);
    $st->execute($vals);
    return $st->rowCount() > 0;
}

function prb_stash_fc_file(int $undoId, string $invoiceRoot, string $rel): ?string
{
    $rel = str_replace('\\', '/', $rel);
    if ($rel === '' || strpos($rel, '..') !== false) {
        return null;
    }
    $src = rtrim($invoiceRoot, '/') . '/' . ltrim($rel, '/');
    if (!is_file($src)) {
        return null;
    }
    $dest = prb_stash_dir($undoId) . '/' . basename($rel);
    if (!prb_copy_file($src, $dest)) {
        return null;
    }
    $txt = $src . '.txt';
    if (is_file($txt)) {
        prb_copy_file($txt, $dest . '.txt');
    }
    return basename($rel);
}

function prb_restore_fc_file(int $undoId, string $invoiceRoot, string $rel, ?string $stashed): void
{
    $rel = str_replace('\\', '/', $rel);
    if ($rel === '' || strpos($rel, '..') !== false) {
        return;
    }
    $dest = rtrim($invoiceRoot, '/') . '/' . ltrim($rel, '/');
    if (is_file($dest)) {
        return;
    }
    $src = $stashed ? (prb_stash_dir($undoId) . '/' . basename($stashed)) : '';
    if ($src === '' || !is_file($src)) {
        $src = prb_stash_dir($undoId) . '/' . basename($rel);
    }
    if (is_file($src)) {
        prb_copy_file($src, $dest);
        if (is_file($src . '.txt')) {
            prb_copy_file($src . '.txt', $dest . '.txt');
        }
    }
}
