<?php
/**
 * One confirmed employee for a sheet name.
 * Confirming the name writes payroll_sheet_name_links and then points every
 * matching draft row and outstanding summary at that employee.
 * Rows with an active approval are skipped. Nothing here marks anyone paid
 * or writes payroll_finalized, payroll_payments, users, or attendance.
 */

require_once __DIR__ . '/payroll_sheet_review_lib.php';
require_once __DIR__ . '/payroll_sheet_edit_lib.php';
require_once __DIR__ . '/outstanding_summary_lib.php';
require_once __DIR__ . '/sheet_hide_lib.php';

function psl_ensure(PDO $pdo): void
{
    static $ready = false;
    if ($ready || defined('PSL_SKIP_MIGRATION')) {
        return;
    }
    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'sqlite') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS payroll_sheet_name_links (
                id INTEGER PRIMARY KEY,
                org_id INT NOT NULL,
                sheet_name TEXT NOT NULL,
                store_label TEXT NULL,
                store_key TEXT NOT NULL DEFAULT "",
                user_id INT NOT NULL,
                linked_by INT NOT NULL DEFAULT 0,
                linked_at TEXT NOT NULL,
                UNIQUE (org_id, sheet_name, store_key)
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS payroll_sheet_name_link_audit (
                id INTEGER PRIMARY KEY,
                org_id INT NOT NULL,
                sheet_name TEXT NOT NULL,
                store_label TEXT NULL,
                action TEXT NOT NULL DEFAULT "confirm",
                old_user_id INT NULL,
                new_user_id INT NULL,
                sheet_rows INT NOT NULL DEFAULT 0,
                skipped_locked INT NOT NULL DEFAULT 0,
                outstanding_rows INT NOT NULL DEFAULT 0,
                actor_id INT NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )'
        );
        $ready = true;
        return;
    }
    $file = dirname(__DIR__) . '/migrations/20261127_payroll_sheet_name_links.sql';
    $sql = is_file($file) ? file_get_contents($file) : false;
    if ($sql === false) {
        throw new RuntimeException('The name-link migration file is missing.');
    }
    $sql = preg_replace('/^--.*$/m', '', (string)$sql);
    foreach (preg_split('/;/', (string)$sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
    $ready = true;
}

function psl_store_key(string $store): string
{
    return psr_norm_name($store);
}

/** @return array<int,array{id:int,name:string,active:int,sites:list<string>,aliases:list<string>}> */
function psl_employees(PDO $pdo, int $orgId, bool $allowHidden): array
{
    $activeCol = psl_users_column($pdo, 'active');
    $aliasCol = '';
    foreach (['alias', 'aka', 'other_name', 'nickname', 'known_as'] as $candidate) {
        if (psl_users_column($pdo, $candidate)) {
            $aliasCol = $candidate;
            break;
        }
    }
    $sql = 'SELECT id, Name AS name';
    if ($activeCol !== '') {
        $sql .= ', active';
    }
    if ($aliasCol !== '') {
        $sql .= ', ' . $aliasCol . ' AS alias_text';
    }
    $sql .= ' FROM users WHERE org_id = ? ORDER BY Name ASC';
    $st = $pdo->prepare($sql);
    $st->execute([$orgId]);
    $people = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)$row['id'];
        $name = (string)$row['name'];
        if (!$allowHidden && sheet_person_is_hidden($id, $name)) {
            continue;
        }
        $aliases = [];
        $rawAlias = trim((string)($row['alias_text'] ?? ''));
        if ($rawAlias !== '') {
            foreach (preg_split('/[|,]/', $rawAlias) as $part) {
                $part = trim((string)$part);
                if ($part !== '') {
                    $aliases[] = $part;
                }
            }
        }
        $people[$id] = [
            'id' => $id,
            'name' => $name,
            'active' => $activeCol !== '' ? (int)$row['active'] : 1,
            'sites' => [],
            'aliases' => $aliases,
        ];
    }
    foreach (psl_employee_sites($pdo, $orgId) as $id => $sites) {
        if (isset($people[$id])) {
            $people[$id]['sites'] = $sites;
        }
    }
    return array_values($people);
}

function psl_users_column(PDO $pdo, string $column): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
        return '';
    }
    try {
        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            $cols = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                if (strcasecmp((string)($col['name'] ?? ''), $column) === 0) {
                    return $column;
                }
            }
            return '';
        }
        $st = $pdo->query('SHOW COLUMNS FROM users');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if (strcasecmp((string)($col['Field'] ?? ''), $column) === 0) {
                return $column;
            }
        }
    } catch (Throwable $e) {
        return '';
    }
    return '';
}

/** @return array<int,list<string>> */
function psl_employee_sites(PDO $pdo, int $orgId): array
{
    $out = [];
    $queries = [
        'SELECT u.id AS user_id, s.nick_name AS site_name
         FROM users u
         INNER JOIN payroll_finalized f ON f.user_id = u.id
         INNER JOIN sites s ON s.SId = f.site_id AND s.org_id = u.org_id
         WHERE u.org_id = ?
         GROUP BY u.id, s.nick_name',
        'SELECT u.id AS user_id, s.nick_name AS site_name
         FROM users u
         INNER JOIN attendance a ON a.user_id = u.id
         INNER JOIN sites s ON s.SId = a.site_id AND s.org_id = u.org_id
         WHERE u.org_id = ?
         GROUP BY u.id, s.nick_name',
    ];
    foreach ($queries as $sql) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$orgId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int)$row['user_id'];
                $site = trim((string)$row['site_name']);
                if ($id > 0 && $site !== '' && !in_array($site, $out[$id] ?? [], true)) {
                    $out[$id][] = $site;
                }
            }
        } catch (Throwable $e) {
        }
    }
    return $out;
}

function psl_first_token(string $name): string
{
    $norm = psr_norm_name($name);
    if ($norm === '') {
        return '';
    }
    $parts = explode(' ', $norm);
    return (string)($parts[0] ?? '');
}

/**
 * Exact full name, then a known alias, then a first name.
 * Active and inactive employees are both eligible. The caller drops hidden users.
 *
 * @param list<array{id:int,name:string,sites?:list<string>,aliases?:list<string>}> $employees
 * @return array{status:string,users:list<array<string,mixed>>}
 */
function psl_name_candidates(string $sheetName, array $employees): array
{
    $want = psr_norm_name($sheetName);
    if ($want === '') {
        return ['status' => 'none', 'users' => []];
    }
    $exact = [];
    $alias = [];
    $first = [];
    $wantFirst = psl_first_token($want);
    foreach ($employees as $person) {
        $id = (int)($person['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $name = psr_norm_name((string)($person['name'] ?? ''));
        if ($name !== '' && $name === $want) {
            $exact[$id] = $person;
            continue;
        }
        $aliasHit = false;
        foreach ($person['aliases'] ?? [] as $one) {
            if (psr_norm_name((string)$one) === $want) {
                $aliasHit = true;
                break;
            }
        }
        if ($aliasHit) {
            $alias[$id] = $person;
            continue;
        }
        if (strlen($wantFirst) >= 3 && psl_first_token($name) === $wantFirst) {
            $first[$id] = $person;
            continue;
        }
        foreach ($person['aliases'] ?? [] as $one) {
            if (strlen($wantFirst) >= 3 && psl_first_token((string)$one) === $wantFirst) {
                $first[$id] = $person;
                break;
            }
        }
    }
    $pool = $exact ?: ($alias ?: $first);
    if (!$pool) {
        return ['status' => 'none', 'users' => []];
    }
    return ['status' => count($pool) === 1 ? 'one' : 'ambiguous', 'users' => array_values($pool)];
}

/** @param list<string> $sheetStores @param list<string> $personSites */
function psl_store_consistent(array $sheetStores, array $personSites): bool
{
    $stores = [];
    foreach ($sheetStores as $store) {
        $norm = psr_norm_name($store);
        if ($norm !== '') {
            $stores[$norm] = true;
        }
    }
    if (!$stores) {
        return true;
    }
    $sites = [];
    foreach ($personSites as $site) {
        $norm = psr_norm_name($site);
        if ($norm !== '') {
            $sites[$norm] = true;
        }
    }
    if (!$sites) {
        return true;
    }
    foreach (array_keys($stores) as $store) {
        $ok = false;
        foreach (array_keys($sites) as $site) {
            if ($site === $store || strpos($store, $site) !== false || strpos($site, $store) !== false) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return false;
        }
    }
    return true;
}

/**
 * @param list<array<string,mixed>> $sheetRows
 * @param list<array<string,mixed>> $outstanding
 * @param list<array<string,mixed>> $employees
 * @return array{proposals:list<array<string,mixed>>,ambiguous:list<array<string,mixed>>}
 */
function psl_plan_from_rows(array $sheetRows, array $outstanding, array $employees): array
{
    $groups = [];
    $add = static function (string $rawName, string $rawStore) use (&$groups): void {
        $norm = psr_norm_name($rawName);
        if ($norm === '') {
            return;
        }
        if (!isset($groups[$norm])) {
            $groups[$norm] = ['name' => trim($rawName), 'stores' => []];
        }
        $storeKey = psl_store_key($rawStore);
        if (!isset($groups[$norm]['stores'][$storeKey])) {
            $groups[$norm]['stores'][$storeKey] = ['label' => trim($rawStore), 'rows' => 0];
        }
        $groups[$norm]['stores'][$storeKey]['rows']++;
    };
    foreach ($sheetRows as $row) {
        if (($row['row_kind'] ?? 'employee_record') !== 'employee_record') {
            continue;
        }
        if (!empty($row['deleted_at'])) {
            continue;
        }
        $add((string)($row['employee_name'] ?? ''), (string)($row['store_name'] ?? ''));
    }
    foreach ($outstanding as $row) {
        $add((string)($row['sheet_employee'] ?? $row['name'] ?? ''), (string)($row['store_label'] ?? $row['store'] ?? ''));
    }
    $proposals = [];
    $ambiguous = [];
    foreach ($groups as $norm => $group) {
        $found = psl_name_candidates($group['name'], $employees);
        $perStore = [];
        foreach ($group['stores'] as $storeKey => $store) {
            $label = $storeKey === '' ? '' : (string)$store['label'];
            $users = [];
            if ($found['status'] !== 'none') {
                foreach ($found['users'] as $person) {
                    $sites = $person['sites'] ?? [];
                    if (psl_store_consistent([$label], $sites)) {
                        $users[(int)$person['id']] = $person;
                    }
                }
            }
            if (count($users) > 1) {
                $ambiguous[] = psl_ambiguous_line($group['name'], $label, array_values($users));
                continue;
            }
            if (count($users) === 1) {
                $person = reset($users);
                $perStore[$storeKey] = [
                    'name' => $group['name'],
                    'norm' => $norm,
                    'store' => $label,
                    'store_key' => $storeKey,
                    'user_id' => (int)$person['id'],
                    'employee' => (string)$person['name'],
                    'rows' => (int)$store['rows'],
                ];
            }
        }
        if (!$perStore) {
            continue;
        }
        $ids = [];
        foreach ($perStore as $line) {
            $ids[(int)$line['user_id']] = true;
        }
        $storeKeys = array_keys($group['stores']);
        if (count($ids) === 1 && count($perStore) === count($storeKeys)) {
            $one = reset($perStore);
            $rows = 0;
            foreach ($group['stores'] as $store) {
                $rows += (int)$store['rows'];
            }
            $proposals[] = [
                'name' => $group['name'],
                'norm' => $norm,
                'store' => '',
                'store_key' => '',
                'user_id' => (int)$one['user_id'],
                'employee' => (string)$one['employee'],
                'rows' => $rows,
            ];
            continue;
        }
        foreach ($perStore as $line) {
            $proposals[] = $line;
        }
    }
    return ['proposals' => $proposals, 'ambiguous' => $ambiguous];
}

/** @param list<array<string,mixed>> $users */
function psl_ambiguous_line(string $name, string $store, array $users): array
{
    $bits = [];
    foreach ($users as $person) {
        $bits[] = (string)$person['name'] . ' #' . (int)$person['id'];
    }
    return [
        'name' => $name,
        'store' => $store,
        'count' => count($users),
        'candidates' => implode('; ', $bits),
    ];
}

function psl_format_plan(array $plan): string
{
    $lines = ['name|store|user_id|employee|rows'];
    if (!$plan['proposals']) {
        $lines[] = '(none)';
    }
    foreach ($plan['proposals'] as $row) {
        $lines[] = $row['name'] . '|' . $row['store'] . '|' . (int)$row['user_id'] . '|' . $row['employee'] . '|' . (int)$row['rows'];
    }
    $lines[] = 'ambiguous';
    if (!$plan['ambiguous']) {
        $lines[] = '(none)';
    }
    foreach ($plan['ambiguous'] as $row) {
        $lines[] = $row['name'] . '|' . $row['store'] . '|' . (int)$row['count'] . ' candidates: ' . $row['candidates'];
    }
    return implode("\n", $lines) . "\n";
}

function psl_auto_plan(PDO $pdo, int $orgId, bool $allowHidden = false): array
{
    psl_ensure($pdo);
    $sheet = $pdo->prepare('SELECT employee_name, store_name, row_kind, deleted_at FROM payroll_sheet_rows WHERE org_id = ?');
    $sheet->execute([$orgId]);
    $outstanding = [];
    try {
        $sum = $pdo->prepare('SELECT sheet_employee, store_label FROM payroll_outstanding_summary WHERE org_id = ?');
        $sum->execute([$orgId]);
        $outstanding = $sum->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $outstanding = [];
    }
    return psl_plan_from_rows($sheet->fetchAll(PDO::FETCH_ASSOC), $outstanding, psl_employees($pdo, $orgId, $allowHidden));
}

/**
 * @return array{linked:int,skipped_locked:int,unchanged:int,outstanding_linked:int,outstanding_unchanged:int}
 */
function psl_confirm(PDO $pdo, int $orgId, string $rawName, ?string $rawStore, int $userId, int $actor, bool $allowHidden): array
{
    psl_ensure($pdo);
    $name = psr_norm_name($rawName);
    if ($name === '') {
        throw new RuntimeException('There is no sheet name to link.');
    }
    if ($userId <= 0) {
        throw new RuntimeException('Choose an employee. Nothing was saved.');
    }
    $users = pse_org_users($pdo, $orgId);
    if (!isset($users[$userId])) {
        throw new RuntimeException('That employee is not in this company. Nothing was saved.');
    }
    if (!$allowHidden && sheet_person_is_hidden($userId, (string)$users[$userId])) {
        throw new RuntimeException('That user is hidden from Sheet Review. Nothing was saved.');
    }
    $storeKey = $rawStore === null ? '' : psl_store_key($rawStore);
    $storeLabel = $storeKey === '' ? null : $storeKey;
    $display = trim($rawName);
    $out = ['linked' => 0, 'skipped_locked' => 0, 'unchanged' => 0, 'outstanding_linked' => 0, 'outstanding_unchanged' => 0];
    $pdo->beginTransaction();
    try {
        $oldUser = psl_upsert_link($pdo, $orgId, $name, $storeLabel, $storeKey, $userId, $actor);
        $sheetIds = psl_sheet_ids($pdo, $orgId, $name, $storeKey);
        $editUsers = pse_org_users($pdo, $orgId);
        $editSites = pse_org_sites($pdo, $orgId);
        $batch = bin2hex(random_bytes(8));
        foreach ($sheetIds as $id) {
            if (pse_row_is_locked($pdo, $orgId, $id)) {
                $out['skipped_locked']++;
                continue;
            }
            $n = pse_apply_edit_in_tx($pdo, $orgId, $id, $actor, ['user_id' => (string)$userId], $editUsers, $editSites, $batch, 'link_name', false);
            $out[$n > 0 ? 'linked' : 'unchanged']++;
        }
        $outstanding = psl_outstanding_ids($pdo, $orgId, $name, $storeKey);
        foreach ($outstanding as $summaryId) {
            $changed = psl_link_outstanding($pdo, $orgId, $summaryId, $userId, $actor, $batch);
            $out[$changed ? 'outstanding_linked' : 'outstanding_unchanged']++;
        }
        psl_audit_map($pdo, $orgId, $display !== '' ? $display : $name, $storeLabel, $oldUser, $userId, $actor, $out);
        $pdo->commit();
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function psl_upsert_link(PDO $pdo, int $orgId, string $name, ?string $storeLabel, string $storeKey, int $userId, int $actor): ?int
{
    $st = $pdo->prepare('SELECT id, user_id FROM payroll_sheet_name_links WHERE org_id = ? AND sheet_name = ? AND store_key = ?');
    $st->execute([$orgId, $name, $storeKey]);
    $have = $st->fetch(PDO::FETCH_ASSOC);
    $now = date('Y-m-d H:i:s');
    if ($have) {
        $old = (int)$have['user_id'];
        if ($old !== $userId) {
            $pdo->prepare('UPDATE payroll_sheet_name_links SET user_id = ?, linked_by = ?, linked_at = ?, store_label = ? WHERE id = ? AND org_id = ?')
                ->execute([$userId, $actor, $now, $storeLabel, (int)$have['id'], $orgId]);
        }
        return $old;
    }
    $pdo->prepare(
        'INSERT INTO payroll_sheet_name_links (org_id, sheet_name, store_label, store_key, user_id, linked_by, linked_at)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([$orgId, $name, $storeLabel, $storeKey, $userId, $actor, $now]);
    return null;
}

/** @return list<int> */
function psl_sheet_ids(PDO $pdo, int $orgId, string $name, string $storeKey): array
{
    $st = $pdo->prepare('SELECT id, employee_name, store_name FROM payroll_sheet_rows WHERE org_id = ? AND deleted_at IS NULL AND row_kind = ?');
    $st->execute([$orgId, 'employee_record']);
    $ids = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (psr_norm_name((string)$row['employee_name']) !== $name) {
            continue;
        }
        if ($storeKey !== '' && psl_store_key((string)$row['store_name']) !== $storeKey) {
            continue;
        }
        $ids[] = (int)$row['id'];
    }
    return $ids;
}

/** @return list<int> */
function psl_outstanding_ids(PDO $pdo, int $orgId, string $name, string $storeKey): array
{
    try {
        $st = $pdo->prepare('SELECT id, sheet_employee, store_label FROM payroll_outstanding_summary WHERE org_id = ?');
        $st->execute([$orgId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $ids = [];
    foreach ($rows as $row) {
        if (psr_norm_name((string)$row['sheet_employee']) !== $name) {
            continue;
        }
        if ($storeKey !== '' && psl_store_key((string)$row['store_label']) !== $storeKey) {
            continue;
        }
        $ids[] = (int)$row['id'];
    }
    return $ids;
}

function psl_link_outstanding(PDO $pdo, int $orgId, int $summaryId, int $userId, int $actor, string $batch): bool
{
    $st = $pdo->prepare('SELECT id, user_id, row_version, match_confidence FROM payroll_outstanding_summary WHERE id = ? AND org_id = ?');
    $st->execute([$summaryId, $orgId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $old = $row['user_id'] !== null ? (int)$row['user_id'] : 0;
    if ($old === $userId) {
        return false;
    }
    $upd = $pdo->prepare(
        'UPDATE payroll_outstanding_summary
            SET user_id = ?, match_confidence = ?, row_version = row_version + 1
          WHERE id = ? AND org_id = ? AND row_version = ?'
    );
    $upd->execute([$userId, 'name_link', $summaryId, $orgId, (int)$row['row_version']]);
    if ($upd->rowCount() < 1) {
        throw new RuntimeException('An outstanding row changed during the link. Nothing was saved.');
    }
    pos_audit($pdo, $orgId, $summaryId, 'summary', $summaryId, $batch, 'link_name', 'user_id', $old > 0 ? (string)$old : null, (string)$userId, $actor);
    pos_audit(
        $pdo,
        $orgId,
        $summaryId,
        'summary',
        $summaryId,
        $batch,
        'link_name',
        'match_confidence',
        $row['match_confidence'] !== null ? (string)$row['match_confidence'] : null,
        'name_link',
        $actor
    );
    return true;
}

function psl_audit_map(PDO $pdo, int $orgId, string $name, ?string $storeLabel, ?int $oldUser, int $newUser, int $actor, array $out): void
{
    $pdo->prepare(
        'INSERT INTO payroll_sheet_name_link_audit
            (org_id, sheet_name, store_label, action, old_user_id, new_user_id, sheet_rows, skipped_locked, outstanding_rows, actor_id, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $orgId,
        substr($name, 0, 160),
        $storeLabel,
        'confirm',
        $oldUser,
        $newUser,
        (int)$out['linked'] + (int)$out['unchanged'],
        (int)$out['skipped_locked'],
        (int)$out['outstanding_linked'] + (int)$out['outstanding_unchanged'],
        $actor,
        date('Y-m-d H:i:s'),
    ]);
}

function psl_mapped_user_id(PDO $pdo, int $orgId, string $name, string $store): ?int
{
    $norm = psr_norm_name($name);
    if ($norm === '' || $orgId <= 0) {
        return null;
    }
    $storeKey = psl_store_key($store);
    try {
        $st = $pdo->prepare('SELECT user_id, store_key FROM payroll_sheet_name_links WHERE org_id = ? AND sheet_name = ?');
        $st->execute([$orgId, $norm]);
        $orgWide = null;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($storeKey !== '' && (string)$row['store_key'] === $storeKey) {
                return (int)$row['user_id'];
            }
            if ((string)$row['store_key'] === '') {
                $orgWide = (int)$row['user_id'];
            }
        }
        return $orgWide;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Names that still have at least one sheet or outstanding row with no map.
 *
 * @return array{count:int,lines:list<array<string,mixed>>,employees:array<int,string>}
 */
function psl_unconfirmed_view(PDO $pdo, int $orgId, bool $allowHidden): array
{
    psl_ensure($pdo);
    $employees = psl_employees($pdo, $orgId, $allowHidden);
    $byId = [];
    foreach ($employees as $person) {
        $byId[(int)$person['id']] = (string)$person['name'];
    }
    $sheet = $pdo->prepare('SELECT employee_name, store_name, row_kind, deleted_at FROM payroll_sheet_rows WHERE org_id = ?');
    $sheet->execute([$orgId]);
    $sheetRows = $sheet->fetchAll(PDO::FETCH_ASSOC);
    $outstanding = [];
    try {
        $sum = $pdo->prepare('SELECT sheet_employee, store_label FROM payroll_outstanding_summary WHERE org_id = ?');
        $sum->execute([$orgId]);
        $outstanding = $sum->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $outstanding = [];
    }
    $seen = [];
    $consider = static function (string $rawName, string $rawStore) use (&$seen, $pdo, $orgId): void {
        $norm = psr_norm_name($rawName);
        if ($norm === '') {
            return;
        }
        if (!isset($seen[$norm])) {
            $seen[$norm] = ['name' => trim($rawName), 'stores' => []];
        }
        if (psl_mapped_user_id($pdo, $orgId, $rawName, $rawStore) === null) {
            $seen[$norm]['open'] = true;
            $seen[$norm]['stores'][] = $rawStore;
        }
    };
    foreach ($sheetRows as $row) {
        if (($row['row_kind'] ?? '') !== 'employee_record' || !empty($row['deleted_at'])) {
            continue;
        }
        $consider((string)$row['employee_name'], (string)$row['store_name']);
    }
    foreach ($outstanding as $row) {
        $consider((string)$row['sheet_employee'], (string)$row['store_label']);
    }
    $lines = [];
    foreach ($seen as $norm => $group) {
        if (empty($group['open'])) {
            continue;
        }
        $found = psl_name_candidates($group['name'], $employees);
        $suggestionId = 0;
        $suggestionLabel = 'none';
        if ($found['status'] === 'one') {
            $person = $found['users'][0];
            if (psl_store_consistent($group['stores'], $person['sites'] ?? [])) {
                $suggestionId = (int)$person['id'];
                $suggestionLabel = (string)$person['name'] . ' #' . $suggestionId;
            }
        }
        $lines[] = [
            'norm' => $norm,
            'sheet_name' => $group['name'],
            'suggestion_id' => $suggestionId,
            'suggestion_label' => $suggestionLabel,
        ];
    }
    usort($lines, static function ($a, $b) {
        return strcmp((string)$a['norm'], (string)$b['norm']);
    });
    return ['count' => count($lines), 'lines' => $lines, 'employees' => $byId];
}

/** @return array{proposals:int,linked:int,skipped_locked:int,outstanding_linked:int} */
function psl_apply_plan(PDO $pdo, int $orgId, array $plan, int $actor, bool $allowHidden): array
{
    $tot = ['proposals' => 0, 'linked' => 0, 'skipped_locked' => 0, 'outstanding_linked' => 0];
    foreach ($plan['proposals'] as $row) {
        $store = (string)($row['store_key'] ?? '') === '' ? null : (string)$row['store'];
        $res = psl_confirm($pdo, $orgId, (string)$row['name'], $store, (int)$row['user_id'], $actor, $allowHidden);
        $tot['proposals']++;
        $tot['linked'] += (int)$res['linked'];
        $tot['skipped_locked'] += (int)$res['skipped_locked'];
        $tot['outstanding_linked'] += (int)$res['outstanding_linked'];
    }
    return $tot;
}
