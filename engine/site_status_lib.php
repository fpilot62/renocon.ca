<?php
/**
 * Site Active / Inactive helpers.
 * Uses existing sites.status VARCHAR ('Active' / 'Inactive') — same as CSV site import.
 */

function sip_site_status_is_active($status): bool {
    $v = strtolower(trim((string)$status));
    if ($v === '' || $v === 'active' || $v === '1' || $v === 'yes' || $v === 'true' || $v === 'on') {
        return true;
    }
    if (in_array($v, ['inactive', '0', 'no', 'false', 'off', 'disabled', 'hidden'], true)) {
        return false;
    }
    return true;
}

/** SQL fragment: site is Active (or legacy blank/NULL). $alias e.g. 's' or ''. */
function sip_sites_active_sql(string $alias = ''): string {
    $c = $alias === '' ? 'status' : $alias . '.status';
    return "($c IS NULL OR TRIM($c) = '' OR LOWER(TRIM($c)) IN ('active','1','yes','true','on'))";
}

function sip_table_has_column(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}

function sip_table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

function sip_count_site_col(PDO $pdo, string $table, string $col, int $sid): int {
    if (!sip_table_exists($pdo, $table) || !sip_table_has_column($pdo, $table, $col)) {
        return 0;
    }
    $sql = "SELECT COUNT(*) FROM `$table` WHERE `$col` = ? OR `$col` = ?";
    $st = $pdo->prepare($sql);
    $st->execute([$sid, (string)$sid]);
    return (int)$st->fetchColumn();
}

/** Attendance + payroll history counts (hours). */
function sip_site_hours_count(PDO $pdo, int $sid): int {
    $n = sip_count_site_col($pdo, 'attendance', 'site_id', $sid);
    foreach (['payroll_finalized', 'payroll_records', 'payroll_masters', 'payroll_balance'] as $t) {
        $n += sip_count_site_col($pdo, $t, 'site_id', $sid);
    }
    return $n;
}

function sip_site_users_assigned_count(PDO $pdo, int $sid): int {
    if (!sip_table_exists($pdo, 'users')) {
        return 0;
    }
    $parts = [];
    $params = [];
    if (sip_table_has_column($pdo, 'users', 'primary_site_id')) {
        $parts[] = 'primary_site_id = ? OR primary_site_id = ?';
        $params[] = $sid;
        $params[] = (string)$sid;
    }
    if (sip_table_has_column($pdo, 'users', 'primary_restaurant_id')) {
        $parts[] = 'primary_restaurant_id = ? OR primary_restaurant_id = ?';
        $params[] = $sid;
        $params[] = (string)$sid;
    }
    if (sip_table_has_column($pdo, 'users', 'assigned_site_ids')) {
        $parts[] = "FIND_IN_SET(?, REPLACE(REPLACE(IFNULL(assigned_site_ids,''), ' ', ''), '|', ','))";
        $params[] = (string)$sid;
    }
    if (!$parts) {
        return 0;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE ' . implode(' OR ', $parts));
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/**
 * @return array{hours:int,users:int,can_delete:bool,block:string}
 */
function sip_site_usage(PDO $pdo, int $sid): array {
    $hours = sip_site_hours_count($pdo, $sid);
    $users = sip_site_users_assigned_count($pdo, $sid);
    $block = '';
    if ($hours > 0) {
        $block = 'hours';
    } elseif ($users > 0) {
        $block = 'users';
    }
    return [
        'hours' => $hours,
        'users' => $users,
        'can_delete' => $block === '',
        'block' => $block,
    ];
}

function sip_site_can_manage(array $site, bool $is_super, int $user_org): bool {
    if ($is_super) {
        return true;
    }
    $site_org = (int)($site['org_id'] ?? 0);
    return $user_org > 0 && $site_org === $user_org;
}

/**
 * @return array{ok:bool,site:?array,error:string}
 */
function sip_site_load_for_manage(PDO $pdo, int $sid, bool $is_super, int $user_org): array {
    if ($sid <= 0) {
        return ['ok' => false, 'site' => null, 'error' => 'notfound'];
    }
    $st = $pdo->prepare('SELECT * FROM sites WHERE SId = ?');
    $st->execute([$sid]);
    $site = $st->fetch(PDO::FETCH_ASSOC);
    if (!$site) {
        return ['ok' => false, 'site' => null, 'error' => 'notfound'];
    }
    if (!sip_site_can_manage($site, $is_super, $user_org)) {
        return ['ok' => false, 'site' => $site, 'error' => 'denied'];
    }
    return ['ok' => true, 'site' => $site, 'error' => ''];
}

function sip_site_set_active(PDO $pdo, int $sid, bool $active, bool $is_super, int $user_org): bool {
    $status = $active ? 'Active' : 'Inactive';
    if ($is_super) {
        $st = $pdo->prepare('UPDATE sites SET status = ? WHERE SId = ?');
        $st->execute([$status, $sid]);
    } else {
        $st = $pdo->prepare('UPDATE sites SET status = ? WHERE SId = ? AND org_id = ?');
        $st->execute([$status, $sid, $user_org]);
    }
    return $st->rowCount() > 0;
}

/** Hard delete site + lightweight orphans. Caller must have checked usage. */
function sip_site_hard_delete(PDO $pdo, int $sid, bool $is_super, int $user_org): bool {
    $got = sip_site_load_for_manage($pdo, $sid, $is_super, $user_org);
    if (!$got['ok']) {
        return false;
    }
    foreach (['Site_Settings' => 'site_id', 'site_assignments' => 'site_id'] as $t => $col) {
        if (sip_table_exists($pdo, $t) && sip_table_has_column($pdo, $t, $col)) {
            $pdo->prepare("DELETE FROM `$t` WHERE `$col` = ? OR `$col` = ?")->execute([$sid, (string)$sid]);
        }
    }
    if (sip_table_exists($pdo, 'employee_company_service') && sip_table_has_column($pdo, 'employee_company_service', 'site_id')) {
        $pdo->prepare('DELETE FROM employee_company_service WHERE site_id = ? OR site_id = ?')->execute([$sid, (string)$sid]);
    }
    if ($is_super) {
        $st = $pdo->prepare('DELETE FROM sites WHERE SId = ?');
        $st->execute([$sid]);
    } else {
        $st = $pdo->prepare('DELETE FROM sites WHERE SId = ? AND org_id = ?');
        $st->execute([$sid, $user_org]);
    }
    return $st->rowCount() > 0;
}

/**
 * Active sites for pickers / timesheet filters / kiosk inventory.
 * Returns rows with SId, Sid, id, nick_name (staff_audit uses mixed keys).
 */
function sip_load_picker_sites(PDO $pdo, int $user_level, int $org_id, string $assigned_csv = ''): array {
    $active = sip_sites_active_sql('');
    $select = "SELECT SId, SId AS Sid, SId AS id, nick_name FROM sites";
    if ($user_level <= 10) {
        if ($org_id > 0) {
            $st = $pdo->prepare("$select WHERE org_id = ? AND $active ORDER BY nick_name ASC");
            $st->execute([$org_id]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }
        return $pdo->query("$select WHERE $active ORDER BY nick_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
    $clean = preg_replace('/[^0-9,]/', '', $assigned_csv);
    if ($clean !== '' && preg_match('/[0-9]/', $clean)) {
        $st = $pdo->prepare("$select WHERE org_id = ? AND SId IN ($clean) AND $active ORDER BY nick_name ASC");
        $st->execute([$org_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            return $rows;
        }
        // Stale assignment (e.g. org-provision "Main Site" marked Inactive after real
        // sites were imported) — org admins/managers would otherwise get an empty
        // Staff Audit Sites dropdown while punches still show active site names.
        // Only fall back when assigned SIds exist for this org but none are Active.
        if ($user_level <= 30 && $org_id > 0) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM sites WHERE org_id = ? AND SId IN ($clean)");
            $chk->execute([$org_id]);
            if ((int)$chk->fetchColumn() > 0) {
                $st = $pdo->prepare("$select WHERE org_id = ? AND $active ORDER BY nick_name ASC");
                $st->execute([$org_id]);
                return $st->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        return [];
    }
    $st = $pdo->prepare("$select WHERE org_id = ? AND $active ORDER BY nick_name ASC");
    $st->execute([$org_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}


if (!function_exists('sip_org_site_fence')) {
    /**
     * Superadmin: pick an org (GET/cookie), then only that org's sites.
     * Org-admin / manager: locked to session org; assigned_site_ids if set.
     *
     * @return array{is_super:bool,selected_org:int,orgs:list,sites:list,assigned_csv:string,allowed_ids:list<int>}
     */
    function sip_org_site_fence(PDO $pdo, string $cookie_key = 'sip_org_id'): array {
        $user_level = (int)($_SESSION['user_level'] ?? 99);
        $session_org = (int)($_SESSION['org_id'] ?? 0);
        $user_id = (int)($_SESSION['user_id'] ?? 0);
        $is_super = ($user_level <= 10);

        $assigned_csv = (string)($_SESSION['assigned_site_ids'] ?? '');
        if ($assigned_csv === '' && $user_id > 0) {
            try {
                $st = $pdo->prepare('SELECT assigned_site_ids FROM users WHERE id = ? LIMIT 1');
                $st->execute([$user_id]);
                $assigned_csv = (string)($st->fetchColumn() ?: '');
                $_SESSION['assigned_site_ids'] = $assigned_csv;
            } catch (Throwable $e) {
                $assigned_csv = '';
            }
        }

        $orgs = [];
        if ($is_super) {
            try {
                $orgs = $pdo->query('SELECT id, org_name FROM organizations ORDER BY org_name ASC')->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $orgs = [];
            }
        }

        if (!$is_super) {
            $selected_org = $session_org;
        } else {
            $org_raw = $_GET['org_id'] ?? $_POST['org_id'] ?? ($_COOKIE[$cookie_key] ?? ($_COOKIE['sa_org_id'] ?? ($_COOKIE['dal_org_id'] ?? 'all')));
            $selected_org = ($org_raw === '' || $org_raw === 'all') ? 0 : (int)$org_raw;
            if (!headers_sent()) {
                setcookie($cookie_key, $selected_org > 0 ? (string)$selected_org : 'all', [
                    'expires' => time() + 86400 * 120,
                    'path' => '/',
                    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]);
            }
        }

        $picker_org = $selected_org > 0 ? $selected_org : ($is_super ? 0 : $session_org);
        $sites = sip_load_picker_sites($pdo, $user_level, $picker_org, $assigned_csv);
        $allowed_ids = [];
        foreach ((array)$sites as $s) {
            $sid = (int)($s['SId'] ?? $s['Sid'] ?? $s['id'] ?? 0);
            if ($sid > 0) {
                $allowed_ids[] = $sid;
            }
        }
        $allowed_ids = array_values(array_unique($allowed_ids));

        return [
            'is_super' => $is_super,
            'selected_org' => $selected_org,
            'orgs' => $orgs,
            'sites' => $sites,
            'assigned_csv' => $assigned_csv,
            'allowed_ids' => $allowed_ids,
        ];
    }
}

if (!function_exists('sip_org_select_html')) {
    function sip_org_select_html(array $fence, string $select_class = 'sheet-input'): string {
        if (empty($fence['is_super'])) {
            return '';
        }
        $sel = (int)($fence['selected_org'] ?? 0);
        $html = '<div class="sip-org-field"><label style="font-size:10px;font-weight:800;color:#64748b;display:block;margin-bottom:3px;">ORG</label>';
        $html .= '<select name="org_id" class="' . htmlspecialchars($select_class) . '" onchange="this.form.submit()">';
        $html .= '<option value="all"' . ($sel === 0 ? ' selected' : '') . '>All organizations</option>';
        foreach ((array)($fence['orgs'] ?? []) as $o) {
            $id = (int)($o['id'] ?? 0);
            $name = htmlspecialchars((string)($o['org_name'] ?? ''));
            $html .= '<option value="' . $id . '"' . ($sel === $id ? ' selected' : '') . '>' . $name . '</option>';
        }
        $html .= '</select></div>';
        return $html;
    }
}

