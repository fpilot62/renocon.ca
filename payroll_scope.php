<?php
/**
 * Shared payroll authorization helpers.
 * users.org_id is authoritative for finalized rows because legacy rows can have org_id=0.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function ps_require_payroll_admin(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: login_kiosk.php?err=session_expired');
        exit;
    }
    if ((int)($_SESSION['user_level'] ?? 99) >= 30) {
        http_response_code(403);
        exit('Payroll administrator access required.');
    }
}

function ps_is_superadmin(): bool {
    return (int)($_SESSION['user_level'] ?? 99) <= 10;
}

function ps_org_id(): int {
    return (int)($_SESSION['org_id'] ?? 0);
}

/**
 * Same org the top bar uses. The menu writes sip_org_id (and sa_org_id, dal_org_id)
 * and puts org_id on the URL. It does not change the session org, which stays the
 * home org from login. A superadmin's home org must not override the top bar.
 * Returns 0 when nothing is selected.
 */
function ps_topbar_org_value(bool $super, int $sessionOrg, $requestOrg, $cookieOrg): int {
    if (!$super) {
        return $sessionOrg > 0 ? $sessionOrg : 0;
    }
    $raw = $requestOrg;
    if ($raw === null) {
        $raw = $cookieOrg;
    }
    if ($raw === null || $raw === '' || $raw === 'all' || (int)$raw <= 0) {
        return 0;
    }
    return (int)$raw;
}

function ps_topbar_org(): int {
    $request = $_GET['org_id'] ?? $_POST['org_id'] ?? null;
    $cookie = $_COOKIE['sip_org_id'] ?? ($_COOKIE['sa_org_id'] ?? ($_COOKIE['dal_org_id'] ?? null));
    return ps_topbar_org_value(ps_is_superadmin(), ps_org_id(), $request, $cookie);
}

function ps_selected_org(PDO $pdo): int {
    if (!ps_is_superadmin()) {
        $org = ps_org_id();
        if ($org <= 0) { http_response_code(403); exit('No organization is assigned to this session.'); }
        return $org;
    }
    $requested = (int)($_GET['org_id'] ?? $_POST['org_id'] ?? ps_org_id());
    if ($requested <= 0) {
        $requested = (int)$pdo->query('SELECT id FROM organizations ORDER BY id LIMIT 1')->fetchColumn();
    }
    return $requested;
}

/**
 * Org fence for list/query pages.
 * Superadmin with no org_id param → 0 (all orgs, all sites).
 * Org admins → session org (required).
 */
function ps_scope_org_id(): int {
    if (!ps_is_superadmin()) {
        $org = ps_org_id();
        if ($org <= 0) { http_response_code(403); exit('No organization is assigned to this session.'); }
        return $org;
    }
    $raw = $_GET['org_id'] ?? $_POST['org_id'] ?? ($_COOKIE['sip_org_id'] ?? '');
    if ($raw === '' || $raw === 'all') {
        return 0;
    }
    return (int)$raw;
}

/** Location dropdown / site roster. Superadmin orgId=0 → every site. */
function ps_list_sites(PDO $pdo, int $orgId): array {
    if ($orgId > 0) {
        $q = $pdo->prepare('SELECT SId, SId AS Sid, nick_name FROM sites WHERE org_id = ? ORDER BY nick_name ASC');
        $q->execute([$orgId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT SId, SId AS Sid, nick_name FROM sites ORDER BY nick_name ASC')->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * First-paint site list for multi-site orgs (AA Group ~50 restaurants).
 * Prefers Bramalea + Woodstock when those names exist; otherwise sites with
 * activity in [$fromDate,$toDate], then the first names in $sitesList.
 * Returns [] when the org has <= $limit sites (caller should use All Sites).
 *
 * @param array<int,array<string,mixed>> $sitesList rows with Sid/SId/id + nick_name
 * @param 'finalized'|'attendance' $source
 * @return list<int>
 */
function ps_pick_starter_sites(
    PDO $pdo,
    int $orgId,
    array $sitesList,
    string $fromDate,
    string $toDate,
    string $source = 'finalized',
    int $limit = 2
): array {
    if (count($sitesList) <= $limit) {
        return [];
    }
    $byId = [];
    $prefer = [];
    $needles = ['bramalea', 'woodstock'];
    foreach ($sitesList as $s) {
        $id = (int)($s['Sid'] ?? $s['SId'] ?? $s['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $name = strtolower(trim((string)($s['nick_name'] ?? '')));
        $byId[$id] = $name;
        foreach ($needles as $n) {
            if ($name !== '' && strpos($name, $n) !== false) {
                $prefer[$n] = $id;
            }
        }
    }
    $picked = [];
    foreach ($needles as $n) {
        if (isset($prefer[$n]) && !in_array($prefer[$n], $picked, true)) {
            $picked[] = $prefer[$n];
        }
    }

    $active = [];
    $fromOk = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate);
    $toOk   = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate);
    if ($orgId > 0 && $fromOk && $toOk) {
        try {
            if ($source === 'attendance') {
                $st = $pdo->prepare(
                    "SELECT COALESCE(NULLIF(NULLIF(a.site_id,'0'),'all'), NULLIF(u.primary_site_id,0)) AS sid, COUNT(*) c
                     FROM attendance a
                     INNER JOIN users u ON u.id = a.user_id
                     WHERE u.org_id = ? AND DATE(a.clock_in) BETWEEN ? AND ?
                     GROUP BY sid
                     HAVING sid IS NOT NULL AND sid > 0
                     ORDER BY c DESC"
                );
                $st->execute([$orgId, $fromDate, $toDate]);
            } else {
                $st = $pdo->prepare(
                    "SELECT f.site_id AS sid, COUNT(*) c
                     FROM payroll_finalized f
                     INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
                     WHERE f.work_date BETWEEN ? AND ?
                     GROUP BY f.site_id
                     ORDER BY c DESC"
                );
                $st->execute([$orgId, $fromDate, $toDate]);
            }
            foreach ($st as $row) {
                $sid = (int)$row['sid'];
                if ($sid > 0 && isset($byId[$sid])) {
                    $active[] = $sid;
                }
            }
        } catch (Throwable $e) { /* keep name-based pick */ }
    }

    $final = [];
    if (!empty($active)) {
        foreach ($picked as $id) {
            if (in_array($id, $active, true)) {
                $final[] = $id;
            }
        }
        foreach ($active as $id) {
            if (count($final) >= $limit) {
                break;
            }
            if (!in_array($id, $final, true)) {
                $final[] = $id;
            }
        }
    } else {
        $final = $picked;
    }
    foreach (array_keys($byId) as $id) {
        if (count($final) >= $limit) {
            break;
        }
        if (!in_array($id, $final, true)) {
            $final[] = $id;
        }
    }
    return array_slice($final, 0, $limit);
}

/** Append `col IN (?,?,…)` for a positive site-id list. */
function ps_site_in_sql(array &$where, array &$params, array $siteIds, string $column = 'f.site_id'): void {
    $siteIds = array_values(array_unique(array_filter(array_map('intval', $siteIds), static fn($v) => $v > 0)));
    if (empty($siteIds)) {
        return;
    }
    $where[] = $column . ' IN (' . implode(',', array_fill(0, count($siteIds), '?')) . ')';
    foreach ($siteIds as $id) {
        $params[] = $id;
    }
}

/** Employee dropdown. Superadmin orgId=0 → every user. */
function ps_list_users(PDO $pdo, int $orgId): array {
    if ($orgId > 0) {
        $q = $pdo->prepare('SELECT id, Name FROM users WHERE org_id = ? ORDER BY Name ASC');
        $q->execute([$orgId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT id, Name FROM users ORDER BY Name ASC')->fetchAll(PDO::FETCH_ASSOC);
}

/** Append users.org_id (+ matching sites.org_id) to a payroll query. */
function ps_apply_org_fence(array &$where, array &$params, int $orgId, string $userAlias = 'u', string $siteAlias = 's'): void {
    if ($orgId <= 0) {
        return;
    }
    $where[] = "{$userAlias}.org_id = ?";
    $params[] = $orgId;
    $where[] = "({$siteAlias}.org_id = {$userAlias}.org_id OR {$siteAlias}.SId IS NULL)";
}

function ps_assert_site(PDO $pdo, int $siteId, int $orgId): void {
    if ($orgId <= 0) {
        $q = $pdo->prepare('SELECT COUNT(*) FROM sites WHERE SId = ?');
        $q->execute([$siteId]);
    } else {
        $q = $pdo->prepare('SELECT COUNT(*) FROM sites WHERE SId = ? AND org_id = ?');
        $q->execute([$siteId, $orgId]);
    }
    if (!$q->fetchColumn()) { http_response_code(403); exit('That site is outside the selected organization.'); }
}

function ps_assert_user(PDO $pdo, int $userId, int $orgId): void {
    if ($orgId <= 0) {
        $q = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
        $q->execute([$userId]);
    } else {
        $q = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND org_id = ?');
        $q->execute([$userId, $orgId]);
    }
    if (!$q->fetchColumn()) { http_response_code(403); exit('That employee is outside the selected organization.'); }
}

function ps_csrf_token(): string {
    if (empty($_SESSION['payroll_csrf'])) { $_SESSION['payroll_csrf'] = bin2hex(random_bytes(24)); }
    return $_SESSION['payroll_csrf'];
}

function ps_require_csrf(): void {
    $given = (string)($_POST['csrf_token'] ?? '');
    if ($given === '' || !hash_equals((string)($_SESSION['payroll_csrf'] ?? ''), $given)) {
        http_response_code(403);
        exit('Invalid or expired request token.');
    }
}
