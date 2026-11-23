<?php
ob_start(); 
/** * Path: /employee_hub.php 
 * PRODUCTION CONSOLE - MULTI-TENANT SUPERADMIN DIRECTORY HUB
 * Fix: Added dynamic organization switcher dropdown exclusively for Super Admins.
 */

ini_set('display_errors', 1); error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php'; 
require_once __DIR__ . '/engine/orgscope_guard.php'; osg_page(['level' => 30, 'fence' => ['employee_id' => 'user', 'user_id' => 'user', 'primary_restaurant_id' => 'site', 'org_id' => 'org']]); // org-scope audit Nov 2026
require_once 'engine/menu_generator.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
require_once 'employee_selfserve_lib.php';
require_once __DIR__ . '/app_activity_lib.php';
ess_ensure_dd_columns($pdo);

if (!function_exists('eh_ensure_contact_columns')) {
    function eh_ensure_contact_columns(PDO $pdo): void {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        foreach (['Tel_Mobile' => 'VARCHAR(50) NULL', 'Tel' => 'VARCHAR(50) NULL'] as $name => $def) {
            try {
                $chk = $pdo->query('SHOW COLUMNS FROM users LIKE ' . $pdo->quote($name))->fetch();
                if (!$chk) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN `$name` $def");
                }
            } catch (Throwable $e) {
                // page still works; contact fields stay empty until column exists
            }
        }
    }
}
eh_ensure_contact_columns($pdo);

if (!function_exists('eh_sanitize_username')) {
    function eh_sanitize_username($raw): string {
        return preg_replace('/\s+/', '', trim((string)$raw));
    }
}

if (!function_exists('eh_hub_redirect')) {
    function eh_hub_redirect(string $key, string $val, int $org_filter = 0): void {
        $q = [$key => $val];
        if ($org_filter > 0) {
            $q['org_filter'] = (string)$org_filter;
        } elseif (!empty($_POST['return_org_filter']) && $_POST['return_org_filter'] !== 'all') {
            $q['org_filter'] = (string)$_POST['return_org_filter'];
        }
        $site_back = '';
        if (!empty($_POST['return_site_filter']) && $_POST['return_site_filter'] !== 'all') {
            $site_back = (string)$_POST['return_site_filter'];
        } elseif (!empty($_GET['site_filter']) && $_GET['site_filter'] !== 'all') {
            $site_back = (string)$_GET['site_filter'];
        }
        if ($site_back !== '' && $site_back !== '0') {
            $q['site_filter'] = $site_back;
        }
        header('Location: employee_hub.php?' . http_build_query($q));
        exit;
    }
}


if (!function_exists('eh_erp_role')) {
    function eh_erp_role(int $actorPerson, int $actorPayroll, int $targetPayroll, int $payrollOrg, int $role, bool $checkOnly): string {
        $payload = json_encode([
            "actor_person_id" => $actorPerson,
            "actor_payroll_user_id" => $actorPayroll,
            "target_payroll_user_id" => $targetPayroll,
            "payroll_org_id" => $payrollOrg,
            "role" => $role,
            "check_only" => $checkOnly,
        ]);
        $cmd = ["/home/renocon/apps/erp/venv/bin/python", "/home/renocon/apps/erp/scripts/apply_payroll_role.py"];
        $proc = proc_open($cmd, [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
        if (!is_resource($proc)) {
            return "role_denied";
        }
        fwrite($pipes[0], $payload === false ? "" : $payload);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $text = trim((string) $out);
        $known = ["ok", "unlinked", "role_denied", "role_above", "role_last_admin", "role_ladder", "role_missing", "role_payroll"];
        return in_array($text, $known, true) ? $text : "role_denied";
    }
}

if (!function_exists('eh_ei_audit_ensure')) {
    function eh_ei_audit_ensure(PDO $pdo): void {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $pdo->exec("CREATE TABLE IF NOT EXISTS ei_exempt_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            admin_user VARCHAR(190) NOT NULL DEFAULT '',
            action VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip VARCHAR(45) DEFAULT NULL,
            session_token VARCHAR(64) NOT NULL DEFAULT '',
            KEY idx_eea_emp_created (employee_id, created_at),
            KEY idx_eea_session (session_token, employee_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $col = $pdo->query("SHOW COLUMNS FROM ei_exempt_audit LIKE 'session_token'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE ei_exempt_audit ADD COLUMN session_token VARCHAR(64) NOT NULL DEFAULT ''");
        }
    }

    function eh_ei_audit_token(): string {
        if (empty($_SESSION['ei_exempt_audit_token']) || !is_string($_SESSION['ei_exempt_audit_token'])) {
            $_SESSION['ei_exempt_audit_token'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['ei_exempt_audit_token'];
    }

    function eh_ei_audit_admin_user(PDO $pdo): string {
        if (!empty($_SESSION['ei_exempt_admin_user']) && is_string($_SESSION['ei_exempt_admin_user'])) {
            return $_SESSION['ei_exempt_admin_user'];
        }
        $name = '';
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid > 0) {
            try {
                $st = $pdo->prepare('SELECT user FROM users WHERE id = ? LIMIT 1');
                $st->execute([$uid]);
                $name = trim((string)$st->fetchColumn());
            } catch (Throwable $e) {
                $name = '';
            }
        }
        if ($name === '') {
            $name = trim((string)($_SESSION['user_full_name'] ?? ''));
        }
        if ($name === '') {
            $name = 'admin';
        }
        if (strlen($name) > 190) {
            $name = substr($name, 0, 190);
        }
        $_SESSION['ei_exempt_admin_user'] = $name;
        return $name;
    }

    function eh_ei_audit_ip(): string {
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if (strlen($ip) > 45) {
            $ip = substr($ip, 0, 45);
        }
        return $ip;
    }

    function eh_ei_audit_employee_ok(PDO $pdo, int $employee_id, int $user_level, int $session_org): bool {
        if ($employee_id <= 0) {
            return true;
        }
        $st = $pdo->prepare('SELECT org_id FROM users WHERE id = ? LIMIT 1');
        $st->execute([$employee_id]);
        $org = $st->fetchColumn();
        if ($org === false) {
            return false;
        }
        if ($user_level <= 10) {
            return true;
        }
        return (int)$org === $session_org;
    }

    function eh_ei_audit_write(PDO $pdo, int $employee_id, string $action): void {
        $allowed = ['click_on', 'confirm', 'cancel', 'save_on', 'save_off', 'click_off'];
        if (!in_array($action, $allowed, true)) {
            return;
        }
        eh_ei_audit_ensure($pdo);
        $st = $pdo->prepare(
            'INSERT INTO ei_exempt_audit (employee_id, admin_user, action, created_at, ip, session_token)
             VALUES (?, ?, ?, NOW(), ?, ?)'
        );
        $st->execute([
            $employee_id,
            eh_ei_audit_admin_user($pdo),
            $action,
            eh_ei_audit_ip(),
            eh_ei_audit_token(),
        ]);
    }

    /** Latest confirm/cancel/click_off in this browser session. Confirm is required to turn Skip EI on. */
    function eh_ei_exempt_may_enable(PDO $pdo, int $employee_id, int $previous): bool {
        eh_ei_audit_ensure($pdo);
        $st = $pdo->prepare(
            "SELECT action FROM ei_exempt_audit
             WHERE employee_id = ? AND session_token = ? AND action IN ('confirm','cancel','click_off')
             ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$employee_id, eh_ei_audit_token()]);
        $last = (string)($st->fetchColumn() ?: '');
        if ($last === 'confirm') {
            return true;
        }
        return $previous === 1 && $last === '';
    }

    function eh_ei_audit_session_touched(PDO $pdo, int $employee_id): bool {
        eh_ei_audit_ensure($pdo);
        $st = $pdo->prepare(
            "SELECT id FROM ei_exempt_audit
             WHERE employee_id = ? AND session_token = ? AND action IN ('click_on','click_off','confirm','cancel')
             LIMIT 1"
        );
        $st->execute([$employee_id, eh_ei_audit_token()]);
        return (bool)$st->fetchColumn();
    }
}

// 1. SECURITY & PERMISSIONS
$user_level = (int)($_SESSION['user_level'] ?? 99);
$session_org = (int)($_SESSION['org_id'] ?? 0);
if ($user_level > 30) { header("Location: kiosk.php?err=Unauthorized"); exit; }

if (!defined('IS_DEMO')) { define('IS_DEMO', false); }

if (!function_exists('eh_site_status_active')) {
    function eh_site_status_active($status): bool {
        if (!function_exists('sip_site_status_is_active')) {
            require_once __DIR__ . '/engine/site_status_lib.php';
        }
        return sip_site_status_is_active($status);
    }
}

if (!function_exists('eh_load_assignable_sites')) {
    /** Hub assignment list: org-fenced for aaadmin; all orgs for superadmin. Includes Inactive. */
    function eh_load_assignable_sites(PDO $pdo, int $user_level, int $session_org): array {
        $sql = "SELECT s.SId, s.nick_name, s.org_id, s.status, o.org_name
                FROM sites s
                LEFT JOIN organizations o ON o.id = s.org_id";
        if ($user_level <= 10) {
            return $pdo->query("$sql ORDER BY o.org_name ASC, s.nick_name ASC, s.SId ASC")->fetchAll(PDO::FETCH_ASSOC);
        }
        $st = $pdo->prepare("$sql WHERE s.org_id = ? ORDER BY s.nick_name ASC, s.SId ASC");
        $st->execute([$session_org]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('eh_site_option_label')) {
    function eh_site_option_label(array $s, bool $show_org = false): string {
        $name = trim((string)($s['nick_name'] ?? ''));
        if ($name === '') {
            $name = 'Site';
        }
        $lab = $name . ' (#' . (int)$s['SId'] . ')';
        if (!eh_site_status_active($s['status'] ?? '')) {
            $lab .= ' — Inactive';
        }
        if ($show_org) {
            $org = trim((string)($s['org_name'] ?? ''));
            if ($org !== '') {
                $lab = $org . ' · ' . $lab;
            }
        }
        return $lab;
    }
}

if (!function_exists('eh_sanitize_site_ids')) {
    function eh_sanitize_site_ids($posted, array $allowed_ids): array {
        $allowed = [];
        foreach ($allowed_ids as $id) {
            $allowed[(string)(int)$id] = true;
        }
        $out = [];
        foreach ((array)$posted as $raw) {
            $id = (string)(int)$raw;
            if ($id === '0' || !isset($allowed[$id])) {
                continue;
            }
            $out[$id] = $id;
        }
        return array_values($out);
    }
}

if (!function_exists('eh_format_assigned_sites')) {
    function eh_format_assigned_sites($csv, array $site_by_id): string {
        $csv = trim((string)$csv);
        if ($csv === '') {
            return 'None';
        }
        $parts = [];
        foreach (preg_split('/[,\s|]+/', $csv) as $raw) {
            $id = trim((string)$raw);
            if ($id === '') {
                continue;
            }
            $s = $site_by_id[$id] ?? $site_by_id[(string)(int)$id] ?? null;
            if (is_array($s)) {
                $nm = trim((string)($s['nick_name'] ?? ''));
                $parts[] = ($nm !== '' ? $nm : 'Site') . ' (#' . (int)$id . ')';
            } else {
                $parts[] = '#' . $id;
            }
        }
        return $parts ? implode(', ', $parts) : 'None';
    }
}

$sites = eh_load_assignable_sites($pdo, $user_level, $session_org);
$allowed_site_ids = array_map('intval', array_column($sites, 'SId'));
$site_by_id = [];
foreach ($sites as $s) {
    $site_by_id[(string)(int)$s['SId']] = $s;
}
$eh_show_org_on_sites = ($user_level <= 10);

// 2. CAPTURE CHOSEN TARGET ORG FILTER (Exclusively for Super Admins)
// Default to 'all' for Super Admin global view, or local org for standard Admins
$target_org_filter = isset($_GET['org_filter']) ? $_GET['org_filter'] : ($user_level <= 10 ? 'all' : $session_org);
if ($user_level <= 10 && (isset($_GET['org_id']) || !isset($_GET['org_filter']))) {
    // Hub org scope Nov 2026: follow the header org picker (?org_id / sip_org_id cookie) like the kiosk; header "All orgs" = all.
    $eh_hdr_org = osg_scope_org();
    $target_org_filter = $eh_hdr_org > 0 ? (string)$eh_hdr_org : 'all';
}
if ($user_level > 10) { $target_org_filter = $session_org; } // Strict enforcement safeguard

$target_site_filter = isset($_GET['site_filter']) ? trim((string)$_GET['site_filter']) : 'all';
if ($target_site_filter === '' || $target_site_filter === '0') {
    $target_site_filter = 'all';
}
$allowed_site_set = [];
foreach ($allowed_site_ids as $sid) {
    $allowed_site_set[(string)(int)$sid] = true;
}
if ($target_site_filter !== 'all') {
    $sfid = (string)(int)$target_site_filter;
    if ($sfid === '0' || !isset($allowed_site_set[$sfid])) {
        $target_site_filter = 'all';
    } else {
        $target_site_filter = $sfid;
    }
}
$hub_filter_sites = [];
foreach ($sites as $s) {
    if ($target_org_filter !== 'all' && (int)($s['org_id'] ?? 0) !== (int)$target_org_filter) {
        continue;
    }
    $hub_filter_sites[] = $s;
}
if ($target_site_filter !== 'all') {
    $still_in_list = false;
    foreach ($hub_filter_sites as $s) {
        if ((string)(int)$s['SId'] === $target_site_filter) {
            $still_in_list = true;
            break;
        }
    }
    if (!$still_in_list) {
        $target_site_filter = 'all';
    }
}

// 3. BACKEND ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (IS_DEMO) {
        header("Location: employee_hub.php?err=demo_restricted");
        exit;
    }

    if ((string)($_POST['hub_action'] ?? '') === 'ei_exempt_audit') {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        $audit_action = (string)($_POST['audit_action'] ?? '');
        $audit_employee = (int)($_POST['employee_id'] ?? 0);
        if ($audit_employee < 0) {
            $audit_employee = 0;
        }
        $client_actions = ['click_on', 'confirm', 'cancel', 'click_off'];
        if (!in_array($audit_action, $client_actions, true)) {
            echo json_encode(['ok' => false, 'error' => 'bad_action']);
            exit;
        }
        if (!eh_ei_audit_employee_ok($pdo, $audit_employee, $user_level, $session_org)) {
            echo json_encode(['ok' => false, 'error' => 'forbidden']);
            exit;
        }
        try {
            eh_ei_audit_write($pdo, $audit_employee, $audit_action);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'audit_failed']);
        }
        exit;
    }

    // --- BULK SITE UPDATE LOGIC ---
    if (isset($_POST['bulk_update_sites'])) {
        $user_ids = array_values(array_filter(array_map('intval', (array)($_POST['selected_users'] ?? [])), static function ($id) {
            return $id > 0;
        }));
        $new_sites = implode(',', eh_sanitize_site_ids($_POST['bulk_allocated_sites'] ?? [], $allowed_site_ids));
        $ps = !empty($_POST['bulk_primary_site'])
            ? eh_sanitize_site_ids([$_POST['bulk_primary_site']], $allowed_site_ids)
            : [];
        $primary_site = $ps[0] ?? null;

        if (!empty($user_ids)) {
            $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
            if ($user_level <= 10) {
                $sql = "UPDATE users SET assigned_site_ids = ?, primary_restaurant_id = ? WHERE id IN ($placeholders)";
                $params = array_merge([$new_sites, $primary_site], $user_ids);
            } else {
                $sql = "UPDATE users SET assigned_site_ids = ?, primary_restaurant_id = ? WHERE id IN ($placeholders) AND org_id = ?";
                $params = array_merge([$new_sites, $primary_site], $user_ids, [$session_org]);
            }
            $pdo->prepare($sql)->execute($params);
            header("Location: employee_hub.php?msg=bulk_saved"); exit;
        }
    }

    // --- SINGLE USER UPDATE/ADD LOGIC ---
    $uid = (int)($_POST['user_id'] ?? 0);
    $hub_action = (string)($_POST['hub_action'] ?? '');
    if ($hub_action === '' && isset($_POST['add_user'])) {
        $hub_action = 'add_user';
    }
    if ($hub_action === '' && isset($_POST['update_user'])) {
        $hub_action = 'update_user';
    }
    // Enter-key / disabled Save button omit the submit name — still save from the staff form.
    if ($hub_action === '' && isset($_POST['name'], $_POST['user']) && !isset($_POST['bulk_update_sites'])) {
        $hub_action = $uid > 0 ? 'update_user' : 'add_user';
    }
    $is_add = ($hub_action === 'add_user' || $uid <= 0);
    if ($hub_action === 'update_user' || $hub_action === 'add_user') {
        $sites_str = implode(',', eh_sanitize_site_ids($_POST['allocated_sites'] ?? [], $allowed_site_ids));
        $ps = !empty($_POST['primary_restaurant_id'])
            ? eh_sanitize_site_ids([$_POST['primary_restaurant_id']], $allowed_site_ids)
            : [];
        $primary_site = $ps[0] ?? null;
        $is_active = isset($_POST['active']) ? (int)$_POST['active'] : 1;
        $posted_org = (int)($_POST['org_id'] ?? 0);
        $target_org = ($user_level <= 10) ? $posted_org : $session_org;
        $role_to_save = (int)($_POST['user_role'] ?? 30);
        if ($user_level > 10 && $role_to_save < 20) {
            $role_to_save = 30;
        }


        $role_sync = 'skip';
        $old_role = null;
        if (!$is_add && $uid > 0) {
            $role_st = $pdo->prepare('SELECT user_role FROM users WHERE id = ? LIMIT 1');
            $role_st->execute([$uid]);
            $old_role = (int) ($role_st->fetchColumn() ?: 0);
            if ($old_role !== $role_to_save) {
                $role_sync = eh_erp_role(
                    (int) ($_SESSION['erp_person_id'] ?? 0),
                    (int) ($_SESSION['user_id'] ?? 0),
                    $uid,
                    $target_org,
                    $role_to_save,
                    true
                );
                if ($role_sync !== 'ok' && $role_sync !== 'unlinked') {
                    eh_hub_redirect('err', $role_sync, $target_org);
                }
            }
        }

        $uname = eh_sanitize_username($_POST['user'] ?? '');
        $pin_plain = trim((string)($_POST['pin'] ?? ''));
        $pass_plain = (string)($_POST['password'] ?? '');
        if (trim($pass_plain) === '') {
            $pass_plain = $pin_plain !== '' ? $pin_plain : '1234';
        }
        $mobile = trim((string)($_POST['Tel_Mobile'] ?? $_POST['mobile'] ?? ''));
        $tel = trim((string)($_POST['Tel'] ?? $_POST['tel'] ?? ''));
        if ($uname === '') {
            eh_hub_redirect('err', 'bad_username', $target_org);
        }
        $org_exists = false;
        if ($target_org > 0) {
            $org_chk = $pdo->prepare('SELECT id FROM organizations WHERE id = ?');
            $org_chk->execute([$target_org]);
            $org_exists = (bool)$org_chk->fetchColumn();
        }
        if (!$org_exists) {
            eh_hub_redirect('err', 'missing_org', $target_org);
        }

        $new_hourly = (float)$_POST['hourly_rate'];
        $new_official = (float)$_POST['official_rate'];
        $rate_effective = trim((string)($_POST['rate_effective_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rate_effective)) {
            $rate_effective = date('Y-m-d');
        }
        $rate_note = trim((string)($_POST['rate_note'] ?? ''));
        $old_hourly = null;
        $old_official = null;
        $old_ei = 0;
        $prior_eff = '';
        if (!$is_add && $uid > 0) {
            $old_st = $pdo->prepare('SELECT hourly_rate, official_rate, ei_exempt FROM users WHERE id = ? LIMIT 1');
            $old_st->execute([$uid]);
            $old_row = $old_st->fetch(PDO::FETCH_ASSOC) ?: [];
            $old_hourly = isset($old_row['hourly_rate']) ? (float)$old_row['hourly_rate'] : null;
            $old_official = isset($old_row['official_rate']) ? (float)$old_row['official_rate'] : null;
            $old_ei = (int)($old_row['ei_exempt'] ?? 0);
            if (function_exists('pe_ensure_user_rate_history')) {
                pe_ensure_user_rate_history($pdo);
                $eff_st = $pdo->prepare('SELECT effective_date FROM user_rate_history WHERE user_id = ? ORDER BY recorded_at DESC, id DESC LIMIT 1');
                $eff_st->execute([$uid]);
                $prior_eff = (string)($eff_st->fetchColumn() ?: '');
            }
        }
        $posted_ei = !empty($_POST['ei_exempt']) ? 1 : 0;
        $audit_employee_id = (!$is_add && $uid > 0) ? $uid : 0;
        if ($posted_ei === 1 && !eh_ei_exempt_may_enable($pdo, $audit_employee_id, $audit_employee_id > 0 ? $old_ei : 0)) {
            eh_hub_redirect('err', 'ei_exempt_unconfirmed', $target_org);
        }
        $params = [
            $_POST['name'], (string)($_POST['email'] ?? ''), $uname, $pin_plain,
            $new_hourly, $new_official,
            $role_to_save, $is_active, $sites_str, $primary_site, (string)($_POST['remarks'] ?? ''),
            $target_org,
            preg_replace('/\D+/', '', (string)($_POST['sin'] ?? '')) ?: null,
            trim((string)($_POST['given_name'] ?? '')) ?: null,
            trim((string)($_POST['surname'] ?? '')) ?: null,
            trim((string)($_POST['street_address'] ?? '')) ?: null,
            trim((string)($_POST['city'] ?? '')) ?: null,
            strtoupper(trim((string)($_POST['province'] ?? ''))) ?: null,
            strtoupper(preg_replace('/\s+/', '', (string)($_POST['postal_code'] ?? ''))) ?: null,
            max(1, min(5, (int)($_POST['dental_benefit_code'] ?? 1))),
            $posted_ei,
            !empty($_POST['wsib_exempt']) ? 1 : 0,
            !empty($_POST['vac_pay_exempt']) ? 1 : 0,
            function_exists('pe_normalize_employee_type')
                ? pe_normalize_employee_type($_POST['employee_type'] ?? 'hourly')
                : 'hourly',
            $mobile !== '' ? $mobile : null,
            $tel !== '' ? $tel : null,
        ];

        try {
            if (!$is_add) {
                $sql = "UPDATE users SET Name=?, email=?, user=?, pin=?, hourly_rate=?, official_rate=?, 
                        user_role=?, active=?, assigned_site_ids=?, primary_restaurant_id=?, Remarks=?, org_id=?,
                        sin=?, given_name=?, surname=?, street_address=?, city=?, province=?, postal_code=?, dental_benefit_code=?,
                        ei_exempt=?, wsib_exempt=?, vac_pay_exempt=?, employee_type=?, Tel_Mobile=?, Tel=?";
                if (trim((string)($_POST['password'] ?? '')) !== '') {
                    $sql .= ", password = ?"; $params[] = password_hash($pass_plain, PASSWORD_DEFAULT);
                }
                if ($user_level > 10) {
                    $sql .= " WHERE id = ? AND org_id = ?";
                    $params[] = $uid;
                    $params[] = $session_org;
                } else {
                    $sql .= " WHERE id = ?";
                    $params[] = $uid;
                }
                $pdo->prepare($sql)->execute($params);

                if ($role_sync === 'ok') {
                    $applied = eh_erp_role(
                        (int) ($_SESSION['erp_person_id'] ?? 0),
                        (int) ($_SESSION['user_id'] ?? 0),
                        $uid,
                        $target_org,
                        $role_to_save,
                        false
                    );
                    if ($applied !== 'ok') {
                        $pdo->prepare('UPDATE users SET user_role = ? WHERE id = ?')->execute([$old_role, $uid]);
                        eh_hub_redirect('err', $applied === 'unlinked' ? 'role_missing' : $applied, $target_org);
                    }
                }
            } else {
                // SaaS max_users guard (matches register.php / importer_lib)
                $org_stmt = $pdo->prepare("SELECT max_users FROM organizations WHERE id = ?");
                $org_stmt->execute([$target_org]);
                $max_allowed = (int)$org_stmt->fetchColumn();
                $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ?");
                $count_stmt->execute([$target_org]);
                $current_total = (int)$count_stmt->fetchColumn();
                if ($max_allowed > 0 && $current_total >= $max_allowed) {
                    eh_hub_redirect('err', 'user_limit', $target_org);
                }
                $sql = "INSERT INTO users (Name, email, user, pin, hourly_rate, official_rate, user_role, active, 
                        assigned_site_ids, primary_restaurant_id, Remarks, org_id,
                        sin, given_name, surname, street_address, city, province, postal_code, dental_benefit_code,
                        ei_exempt, wsib_exempt, vac_pay_exempt, employee_type, Tel_Mobile, Tel,
                        password) 
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                $params[] = password_hash($pass_plain, PASSWORD_DEFAULT);
                $pdo->prepare($sql)->execute($params);

                app_activity_log($pdo, 'crewpulse', 'signup', $pdo->lastInsertId(), $_POST['Name'] ?? ($_POST['name'] ?? ''), $_POST['email'] ?? '', 'employee-hub');
            }
            $saved_uid = $is_add ? (int)$pdo->lastInsertId() : (int)$uid;
            $amount_changed = $is_add
                || $old_hourly === null
                || abs((float)$old_hourly - $new_hourly) > 0.001
                || abs((float)$old_official - $new_official) > 0.001;
            $date_changed = !$is_add && $prior_eff !== '' && $rate_effective !== $prior_eff;
            if ($saved_uid > 0 && $amount_changed && function_exists('pe_record_user_rate')) {
                pe_record_user_rate(
                    $pdo,
                    $saved_uid,
                    $new_hourly,
                    $new_official,
                    null,
                    $rate_effective,
                    (int)($_SESSION['user_id'] ?? 0),
                    $rate_note !== '' ? $rate_note : 'Employee Hub rate save',
                    'employee_hub'
                );
            } elseif ($saved_uid > 0 && $date_changed && function_exists('pe_move_rate_effective_date')) {
                pe_move_rate_effective_date(
                    $pdo,
                    $saved_uid,
                    $rate_effective,
                    (int)($_SESSION['user_id'] ?? 0),
                    $rate_note !== '' ? $rate_note : 'Effective date changed. Rate unchanged.',
                    'employee_hub'
                );
            }
            if ($saved_uid > 0) {
                try {
                    if ($posted_ei === 1) {
                        eh_ei_audit_write($pdo, $saved_uid, 'save_on');
                    } elseif ($old_ei === 1 || eh_ei_audit_session_touched($pdo, $audit_employee_id)) {
                        eh_ei_audit_write($pdo, $saved_uid, 'save_off');
                    }
                } catch (Throwable $e) {
                    // Profile row is already saved. Confirmation rows from this session still gate EI Exempt.
                }
            }
            eh_hub_redirect('msg', 'saved', $target_org);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { eh_hub_redirect('err', 'duplicate_user', $target_org); }
            else { eh_hub_redirect('err', 'save_failed', $target_org); }
        }
    }
}

// 4. DATA FETCHING ARCHITECTURE
@set_time_limit(120);
$search_term = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$query_params = [];
$conditions = [];

// Apply structural organization boundary constraints
if ($target_org_filter !== 'all') {
    $conditions[] = " u.org_id = ? ";
    $query_params[] = (int)$target_org_filter;
} else {
    $conditions[] = " 1 = 1 ";
}

if ($target_site_filter !== 'all') {
    $conditions[] = " (FIND_IN_SET(?, u.assigned_site_ids) OR u.primary_restaurant_id = ?) ";
    $query_params[] = $target_site_filter;
    $query_params[] = (int)$target_site_filter;
}

// Site-scope only for mid-level managers (Site Manager 25 / Supervisor 30).
// Org Admins (role <= 20) must see every employee in their org.
if ($user_level > 20 && isset($_SESSION['access_scope']) && $_SESSION['access_scope'] === 'restricted') {
    $clean_sites = preg_replace('/[^0-9,]/', '', $_SESSION['assigned_site_ids'] ?? '');
    if (!empty($clean_sites)) {
        $site_array = explode(',', $clean_sites);
        $site_conditions = [];
        foreach($site_array as $sid) { $site_conditions[] = "FIND_IN_SET('$sid', u.assigned_site_ids)"; }
        $conditions[] = " (" . implode(" OR ", $site_conditions) . " OR u.user_role <= 20) ";
    }
}

// Apply search term filters
if (!empty($search_term)) {
    $conditions[] = " (u.Name LIKE ? OR u.user LIKE ? OR u.email LIKE ?) ";
    $query_params[] = "%$search_term%";
    $query_params[] = "%$search_term%";
    $query_params[] = "%$search_term%";
}

$user_where = implode(" AND ", $conditions);

$org_total = 0;
try {
    if ($target_org_filter === 'all') {
        $org_total = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    } else {
        $stOrg = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ?");
        $stOrg->execute([(int)$target_org_filter]);
        $org_total = (int)$stOrg->fetchColumn();
    }
} catch (Throwable $e) {
    $org_total = 0;
}

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $user_where");
$count_stmt->execute($query_params);
$total_match = (int)$count_stmt->fetchColumn();

$sort_key = strtolower(trim((string)($_GET['sort'] ?? 'name')));
$sort_dir = strtolower(trim((string)($_GET['dir'] ?? 'asc')));
if ($sort_dir !== 'desc') {
    $sort_dir = 'asc';
}
$sort_sql_map = [
    'name' => 'u.Name',
    'user' => 'u.user',
    'org' => 'o.org_name',
    'sites' => 'u.assigned_site_ids',
    'role' => "COALESCE(NULLIF(TRIM(r.role_name), ''), 'Staff')",
    'etype' => 'u.employee_type',
    'rate' => 'u.hourly_rate+0',
    'account' => 'u.active',
    'clock' => 'live_status',
];
if ($user_level > 10) {
    unset($sort_sql_map['org']);
}
if (!isset($sort_sql_map[$sort_key])) {
    $sort_key = 'name';
}
$order_sql = $sort_sql_map[$sort_key] . ' ' . ($sort_dir === 'desc' ? 'DESC' : 'ASC');
if ($sort_key !== 'name') {
    $order_sql .= ', u.Name ASC';
}

$per_page_opt = strtolower(trim((string)($_GET['per_page'] ?? 'all')));
$per_page_map = ['50' => 50, '100' => 100, '250' => 250, '500' => 500, 'all' => 0];
if (!isset($per_page_map[$per_page_opt])) {
    $per_page_opt = 'all';
}
$per_page = $per_page_map[$per_page_opt];
$page = max(1, (int)($_GET['page'] ?? 1));
if ($per_page > 0) {
    $total_pages = max(1, (int)ceil(max(0, $total_match) / $per_page));
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset = ($page - 1) * $per_page;
    $limit_sql = " LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
} else {
    $total_pages = 1;
    $page = 1;
    $offset = 0;
    $limit_sql = "";
}

$stmt = $pdo->prepare("SELECT u.*, r.role_name, o.org_name,
                              (SELECT a.status FROM attendance a WHERE a.user_id = u.id ORDER BY a.id DESC LIMIT 1) AS live_status
                       FROM users u 
                       LEFT JOIN roles r ON u.user_role = r.role_id 
                       LEFT JOIN organizations o ON u.org_id = o.id
                       WHERE $user_where ORDER BY $order_sql$limit_sql");
$stmt->execute($query_params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
$rate_eff_by_user = [];
$rate_ids = array_values(array_unique(array_map(static function ($u) {
    return (int)($u['id'] ?? 0);
}, $users)));
if ($rate_ids) {
    $rate_in = implode(',', $rate_ids);
    try {
        $eff_rows = $pdo->query("
            SELECT user_id, effective_date FROM (
                SELECT h.user_id, h.effective_date,
                       ROW_NUMBER() OVER (
                           PARTITION BY h.user_id
                           ORDER BY h.recorded_at DESC, h.id DESC
                       ) AS rn
                FROM user_rate_history h
                JOIN users u ON u.id = h.user_id
                WHERE h.user_id IN ($rate_in)
                  AND ABS(h.hourly_rate - u.hourly_rate) < 0.001
            ) rated
            WHERE rn = 1
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($eff_rows as $er) {
            $rate_eff_by_user[(int)$er['user_id']] = (string)$er['effective_date'];
        }
    } catch (Throwable $e) {
        $rate_eff_by_user = [];
    }
}
foreach ($users as &$rate_user) {
    $rate_user['rate_effective_date'] = $rate_eff_by_user[(int)$rate_user['id']] ?? '';
}
unset($rate_user);
$shown_count = count($users);
$shown_from = $shown_count ? ($offset + 1) : 0;
$shown_to = $offset + $shown_count;

if (!function_exists('eh_edit_payload')) {
    function eh_edit_payload(array $u): array {
        $keys = [
            'id', 'Name', 'email', 'user', 'pin', 'user_role', 'active',
            'hourly_rate', 'official_rate', 'assigned_site_ids', 'primary_restaurant_id',
            'Remarks', 'org_id', 'sin', 'given_name', 'surname', 'street_address',
            'city', 'province', 'postal_code', 'dental_benefit_code',
            'ei_exempt', 'wsib_exempt', 'vac_pay_exempt', 'employee_type',
            'Tel_Mobile', 'Tel', 'rate_effective_date',
            'direct_deposit_file', 'direct_deposit_upload_pending',
        ];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $u[$k] ?? null;
        }
        return $out;
    }
}

if (!function_exists('eh_clock_label')) {
    function eh_clock_label($status) {
        if ($status === null || $status === '') {
            return ['Out', '#94a3b8'];
        }
        $sid = (int)$status;
        if ($sid >= 1 && $sid <= 3) {
            return ['Working', '#2563eb'];
        }
        return ['Out', '#94a3b8'];
    }
}

// EH_LIST_PARTS_BEGIN — Live search Nov 2026: the list rows, "Showing X of Y" text and pager are
// rendered by these functions for both the full page and the ?ajax=list fragment, so both share one template.
if (!function_exists('eh_list_rows_html')) {
    function eh_list_rows_html(array $users, int $user_level, array $site_by_id): string {
        ob_start();
?>
            <?php if (!empty($users)): foreach($users as $u): ?>
            <tr class="staff-row">
                <td><input type="checkbox" name="selected_users[]" value="<?= (int)$u['id'] ?>" class="user-cb" onchange="checkBulk()"></td>
                <td>
                    <strong class="staff-name" style="color: #1e293b; font-size: 14px;"><?= htmlspecialchars($u['Name'] ?? '') ?></strong><br>
                    <small class="text-muted"><?= htmlspecialchars($u['email'] ?? '') ?></small>
                </td>
                <td><span style="font-weight:700; color:#334155;">@<?= htmlspecialchars($u['user'] ?? 'n/a') ?></span></td>
                <?php if ($user_level <= 10): ?>
                    <td>
                        <span class="badge badge-secondary p-2 text-uppercase font-weight-bold" style="background:#e2e8f0; color:#475569; font-size:10px;">
                            <?= htmlspecialchars($u['org_name'] ?? 'Unassigned Org') ?> (#<?= (int)$u['org_id'] ?>)
                        </span>
                    </td>
                <?php endif; ?>
                <td style="font-size:11px; color:#2563eb; font-weight:700;">
                    <i class="fa fa-map-location-dot mr-1"></i> <?= htmlspecialchars(eh_format_assigned_sites($u['assigned_site_ids'] ?? '', $site_by_id)) ?>
                </td>
                <td><span style="padding:4px 8px; background:#f1f5f9; border-radius:4px; font-size:11px; font-weight:700; color: #334155;"><?= htmlspecialchars($u['role_name'] ?? 'Staff') ?></span></td>
                <?php
                    $etype = function_exists('pe_normalize_employee_type')
                        ? pe_normalize_employee_type($u['employee_type'] ?? 'hourly')
                        : 'hourly';
                    $elabel = function_exists('pe_employee_type_label') ? pe_employee_type_label($etype) : ucfirst($etype);
                    $ecolor = $etype === 'contract' ? '#b45309' : ($etype === 'monthly' ? '#1d4ed8' : '#334155');
                    $ebg = $etype === 'contract' ? '#fef3c7' : ($etype === 'monthly' ? '#dbeafe' : '#f1f5f9');
                ?>
                <td><span style="padding:4px 8px; background:<?= $ebg ?>; border-radius:4px; font-size:11px; font-weight:800; color:<?= $ecolor ?>;"><?= htmlspecialchars($elabel) ?></span><?php if (!empty($u['ei_exempt'])): ?> <span style="display:inline-block; margin-left:4px; padding:4px 8px; background:#ffedd5; border-radius:4px; font-size:11px; font-weight:800; color:#9a3412;">Skip EI</span><?php endif; ?></td>
                <td><strong style="color: #0f172a;">$<?= number_format((float)($u['hourly_rate'] ?? 0), 2) ?></strong><?php if (!empty($u['rate_effective_date'])): ?><div style="font-size:11px;color:#0f766e;font-weight:700;">from <?= htmlspecialchars((string)$u['rate_effective_date']) ?></div><?php endif; ?></td>
                <td><span style="color:<?= $u['active'] ? '#16a34a' : '#dc2626' ?>; font-weight:800; font-size: 11px;"><?= $u['active'] ? 'ACTIVE' : 'OFF' ?></span>
                    <?php if (!empty($u['direct_deposit_upload_pending'])): ?>
                        <div style="margin-top:4px;"><span style="background:#fef3c7;color:#b45309;font-size:10px;font-weight:800;padding:2px 6px;border-radius:999px;">DD PENDING VERIFY</span></div>
                    <?php endif; ?>
                </td>
                <?php [$clock_lbl, $clock_color] = eh_clock_label($u['live_status'] ?? null); ?>
                <td><span style="color:<?= $clock_color ?>; font-weight:800; font-size:11px;"><?= $clock_lbl ?></span></td>
                <td style="text-align:right; padding-right: 25px;">
                    <button type="button" class="pay-btn pay-btn-primary" style="padding:6px 12px; font-size:12px;" onclick="editUser(<?= htmlspecialchars(json_encode(eh_edit_payload($u)), ENT_QUOTES, 'UTF-8') ?>)">Edit profile</button>
                    <?php if (!empty($u['direct_deposit_file'])): ?>
                        <a class="pay-btn" href="direct_deposit_download.php?user_id=<?= (int)$u['id'] ?>" style="padding:6px 10px;font-size:11px;background:#0891b2;color:#fff;text-decoration:none;border-radius:6px;font-weight:700;">DD file</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; else: ?>
            <tr>
                <td colspan="<?= $user_level <= 10 ? '11' : '10' ?>" style="text-align:center; color:#64748b; padding:36px 16px; font-size:13px; background:#f8fafc;">
                    No employees match this search.
                </td>
            </tr>
            <?php endif; ?>
<?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('eh_list_showing_html')) {
    function eh_list_showing_html(array $c): string {
        $total_match = (int)$c['total_match'];
        $shown_from = (int)$c['shown_from'];
        $shown_to = (int)$c['shown_to'];
        $search_term = (string)$c['search_term'];
        $target_site_filter = (string)$c['target_site_filter'];
        $target_org_filter = (string)$c['target_org_filter'];
        $site_by_id = (array)$c['site_by_id'];
        $org_total = (int)$c['org_total'];
        ob_start();
?>
                <?php if ($total_match === 0): ?>
                    Showing 0 of 0 employees
                <?php elseif ($shown_from === $shown_to): ?>
                    Showing <?= (int)$shown_from ?> of <?= (int)$total_match ?> employees
                <?php else: ?>
                    Showing <?= (int)$shown_from ?>–<?= (int)$shown_to ?> of <?= (int)$total_match ?> employees
                <?php endif; ?>
                <?php if ($search_term !== ''): ?> matching “<?= htmlspecialchars($search_term) ?>”<?php endif; ?>
                <?php if ($target_site_filter !== 'all'): ?>
                    <?php
                    $site_filter_label = 'site #' . $target_site_filter;
                    if (isset($site_by_id[$target_site_filter])) {
                        $site_filter_label = eh_site_option_label($site_by_id[$target_site_filter], false);
                    }
                    ?>
                    <span style="color:#64748b; font-weight:600;"> at <?= htmlspecialchars($site_filter_label) ?></span>
                <?php endif; ?>
                <?php if ($org_total > 0 && ($search_term !== '' || $total_match !== $org_total || $target_site_filter !== 'all')): ?>
                    <span style="color:#64748b; font-weight:600;"> (<?= (int)$org_total ?> in <?= $target_org_filter === 'all' ? 'all orgs' : 'this org' ?>)</span>
                <?php endif; ?>
<?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('eh_list_pager_html')) {
    function eh_list_pager_html(array $c): string {
        $total_pages = (int)$c['total_pages'];
        $page = (int)$c['page'];
        $search_term = (string)$c['search_term'];
        $target_org_filter = (string)$c['target_org_filter'];
        $target_site_filter = (string)$c['target_site_filter'];
        $per_page_opt = (string)$c['per_page_opt'];
        $user_level = (int)$c['user_level'];
        $sort_key = (string)$c['sort_key'];
        $sort_dir = (string)$c['sort_dir'];
        ob_start();
?>
    <?php if ($total_pages > 1): ?>
    <div style="display:flex; gap:8px; align-items:center; justify-content:flex-end; margin:-32px 0 40px; flex-wrap:wrap;">
        <?php
        $eh_qs = static function (array $over) use ($search_term, $target_org_filter, $target_site_filter, $per_page_opt, $user_level, $sort_key, $sort_dir): string {
            $q = ['per_page' => $per_page_opt];
            if ($search_term !== '') {
                $q['search'] = $search_term;
            }
            if ($user_level <= 10) {
                $q['org_filter'] = (string)$target_org_filter;
            }
            if ($target_site_filter !== 'all') {
                $q['site_filter'] = (string)$target_site_filter;
            }
            if ($sort_key !== 'name') {
                $q['sort'] = $sort_key;
            }
            if ($sort_dir === 'desc') {
                $q['dir'] = 'desc';
            }
            $q = array_merge($q, $over);
            return 'employee_hub.php?' . http_build_query($q);
        };
        ?>
        <?php if ($page > 1): ?>
            <a class="pay-btn pay-btn-secondary" href="<?= htmlspecialchars($eh_qs(['page' => $page - 1])) ?>">Prev</a>
        <?php endif; ?>
        <span style="font-size:12px; font-weight:700; color:#64748b;">Page <?= (int)$page ?> / <?= (int)$total_pages ?></span>
        <?php if ($page < $total_pages): ?>
            <a class="pay-btn pay-btn-secondary" href="<?= htmlspecialchars($eh_qs(['page' => $page + 1])) ?>">Next</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php
        return (string)ob_get_clean();
    }
}
// EH_LIST_PARTS_END

$eh_list_ctx = [
    'total_match' => $total_match,
    'shown_from' => $shown_from,
    'shown_to' => $shown_to,
    'search_term' => $search_term,
    'target_site_filter' => $target_site_filter,
    'target_org_filter' => $target_org_filter,
    'site_by_id' => $site_by_id,
    'org_total' => $org_total,
    'total_pages' => $total_pages,
    'page' => $page,
    'per_page_opt' => $per_page_opt,
    'user_level' => $user_level,
    'sort_key' => $sort_key,
    'sort_dir' => $sort_dir,
];

// Live search (GET ?ajax=list): same guard, org/site scope, restricted-scope, search, sort and paging as the
// page above — only the output differs (JSON with the rendered rows, count text and pager).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && (string)($_GET['ajax'] ?? '') === 'list') {
    $eh_list_json = json_encode([
        'ok' => true,
        'rows' => eh_list_rows_html($users, (int)$user_level, $site_by_id),
        'showing' => eh_list_showing_html($eh_list_ctx),
        'pager' => eh_list_pager_html($eh_list_ctx),
        'total' => (int)$total_match,
        'search' => $search_term,
        'page' => (int)$page,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo $eh_list_json === false ? '{"ok":false}' : $eh_list_json;
    exit;
}

$all_roles = $pdo->query("SELECT role_id, role_name FROM roles ORDER BY role_id ASC")->fetchAll(PDO::FETCH_ASSOC);
$role_choices = [];
foreach ($all_roles as $r) {
    if ($user_level > 10 && (int)$r['role_id'] < 20) {
        continue;
    }
    $role_choices[] = $r;
}

try {
    $orgs = $pdo->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $orgs = [['id' => $session_org, 'org_name' => 'Current Org']]; }

$dd_pending_count = 0;
try {
    if ($user_level <= 10 && $target_org_filter === 'all') {
        $dd_pending_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE direct_deposit_upload_pending=1")->fetchColumn();
    } else {
        $org_for_dd = ($user_level <= 10 && $target_org_filter !== 'all') ? (int)$target_org_filter : $session_org;
        $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id=? AND direct_deposit_upload_pending=1");
        $st->execute([$org_for_dd]);
        $dd_pending_count = (int)$st->fetchColumn();
    }
} catch (Throwable $e) {
    $dd_pending_count = 0;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Employee Hub | crewPulse</title>
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; background: #f1f5f9; padding-top: 72px; color: #0f172a; }
        .demo-bar { background: #e67e22; color: white; text-align: center; padding: 8px; font-weight: 700; position: fixed; top: 0; width: 100%; z-index: 10002; }
        .alert-box { background: #fee2e2; color: #b91c1c; padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; font-weight: 600; border: 1px solid #fecaca; font-size: 13px; }
        .alert-ok { background: #dcfce7; color: #166534; padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; font-weight: 600; border: 1px solid #bbf7d0; font-size: 13px; }
        .container { max-width: 1600px; margin: auto; padding: 0 20px; }
        .audit-table { width: 100%; background: white; border-radius: 10px; border-collapse: collapse; box-shadow: 0 1px 3px rgba(15,23,42,0.06); overflow: hidden; margin-bottom: 48px; border: 1px solid #e2e8f0; }
        .audit-table th { background: #f8fafc; padding: 10px 12px; text-align: left; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.03em; cursor: pointer; border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; z-index: 2; user-select: none; }
        .audit-table th.th-sort:hover { color: #2563eb; }
        .audit-table th.th-sort.on { color: #1d4ed8; }
        .audit-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-size: 13px; }
        .audit-table tbody tr:nth-child(even) td { background: #fafbfc; }
        .audit-table tbody tr:hover td { background: #f1f5f9; }
        .search-bar { width: 280px; padding: 0 14px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; font-size: 13px; font-weight: 600; background: #fff; height: 40px; box-sizing: border-box; }
        .org-selector { width: 240px; padding: 0 12px; border-radius: 8px; border: 1px solid #93c5fd; outline: none; font-size: 13px; font-weight: 700; color: #1d4ed8; background: #fff; height: 40px; box-sizing: border-box; }
        .modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.7); z-index:9999; justify-content:center; align-items:center; }
        .modal-content { background:white; padding:28px; border-radius:14px; width:920px; max-width:95vw; max-height:90vh; overflow-y:auto; box-shadow: 0 20px 40px rgba(15,23,42,0.25); }
        .form-group label { display:block; font-size:11px; font-weight:700; color:#64748b; margin-bottom:5px; text-transform:uppercase; letter-spacing:0.02em; }
        .form-group input, .form-group select, .form-group textarea { width:100%; padding:9px 10px; border:1px solid #cbd5e1; border-radius:8px; height: 40px; box-sizing: border-box; }
        .form-group textarea { height: auto; }
        .bulk-bar { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: #0f172a; color: white; padding: 12px 24px; border-radius: 999px; display: none; z-index: 10000; box-shadow: 0 10px 28px rgba(15,23,42,0.35); align-items: center; gap: 16px; border: 1px solid #334155; }
        .hub-toolbar { display:flex; justify-content:space-between; align-items:flex-end; gap:12px; flex-wrap:wrap; margin-bottom: 16px; }
        .hub-toolbar h1 { font-weight:800; letter-spacing:-0.02em; margin:0 0 10px; color:#0f172a; font-size:1.5rem; }
        .pay-adv { margin-top:16px; border:1px solid #e2e8f0; border-radius:10px; background:#f8fafc; }
        .pay-adv > summary { cursor:pointer; list-style:none; padding:10px 12px; font-size:12px; font-weight:700; color:#334155; }
        .pay-adv > summary::-webkit-details-marker { display:none; }
        .pay-adv[open] > summary { border-bottom:1px solid #e2e8f0; }
        .pay-adv .pay-adv-body { padding:12px; }
        .ts-dropdown { z-index: 10050 !important; }
        #editOverlay .ts-wrapper, #bulkOverlay .ts-wrapper { min-height: 42px; }
        #editOverlay input:not([disabled]):not([readonly]),
        #editOverlay select:not([disabled]),
        #editOverlay textarea:not([disabled]) { background:#fff; pointer-events:auto; }
        .hub-hint { font-size:11px; font-weight:600; color:#64748b; margin-top:4px; }
    </style>
</head>
<body>

<?php if (defined('IS_DEMO') && IS_DEMO): ?>
    <div class="demo-bar">🚀 DEMO MODE: Active for Org #<?= $session_org ?></div>
<?php endif; ?>

<?php echo generateMenu($pdo); ?>
    <?php require_once __DIR__.'/payroll_help.php'; echo '<div style="margin:10px 16px 0;">'.payroll_help_panel('employee_hub.php').'</div>'; ?>


<div class="container">
    <?php if (isset($_GET['msg']) && $_GET['msg'] == 'saved'): ?>
        <div class="alert-ok">Saved. The employee is in the list below.</div>
    <?php elseif (isset($_GET['err']) && $_GET['err'] == 'duplicate_user'): ?>
        <div class="alert-box">ERROR: Username taken.</div>
    <?php elseif (isset($_GET['err']) && $_GET['err'] == 'user_limit'): ?>
        <div class="alert-box">USER LIMIT REACHED: This organization has used all seats on its plan. A superadmin can raise Max Users under Clients → Manage Organizations. (Setting Status to Off does not free a seat — the limit counts all user rows for the org.)</div>
    <?php elseif (isset($_GET['err']) && $_GET['err'] == 'missing_org'): ?>
        <div class="alert-box">Select an organization before saving (e.g. AMJ Canada). Organization is required and must exist.</div>
    <?php elseif (isset($_GET['err']) && $_GET['err'] == 'bad_username'): ?>
        <div class="alert-box">Username cannot be empty after removing spaces.</div>
    <?php elseif (isset($_GET['err']) && $_GET['err'] == 'save_failed'): ?>
        <div class="alert-box">Could not save this employee. Check the fields and try again — the form will not keep spinning.</div>
    <?php elseif (isset($_GET['err']) && in_array($_GET['err'], ['role_denied', 'role_above', 'role_last_admin', 'role_ladder', 'role_missing', 'role_payroll'], true)): ?>
    <div class="alert">That role was not changed. An owner or admin can set Admin, Site Manager, Supervisor, Demo, Staff, or Alarm. They cannot set a stronger role than their own, or remove the last admin.</div>
    <?php elseif (isset($_GET['err']) && $_GET['err'] == 'ei_exempt_unconfirmed'): ?>
        <div class="alert-box">Skip EI was not saved. Confirm the warning first — it skips employee and employer EI, and only if the CRA excepts this person. Then save again.</div>
    <?php endif; ?>

    <div class="hub-toolbar">
        <div>
            <h1>Employee Hub</h1>
            <p style="margin:0 0 10px; font-size:13px;"><a href="app_activity.php">Last 30 days signups &amp; logins</a> · <a href="app_activity.php?view=archive">Archive</a></p>
            <p style="margin:0 0 10px; font-size:13px; color:#64748b; font-weight:600;">Single staff screen — login, rates, sites, role, and status. Edit everything here.</p>
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <input type="text" id="hubSearch" class="search-bar" placeholder="Search name, user, email…" value="<?= htmlspecialchars($search_term) ?>" oninput="searchDebounce(this.value)" onkeydown="hubSearchKey(event)" autocomplete="off">
                <button type="button" id="hubClearSearch" class="pay-btn pay-btn-secondary" style="height:40px;<?= $search_term === '' ? ' display:none;' : '' ?>" onclick="clearHubSearch()">Clear search</button>
                <?php if ($user_level <= 10): ?>
                    <select id="orgFilterSelect" class="org-selector" onchange="executeOrgFilterRoute(this.value)">
                        <option value="all" <?= $target_org_filter === 'all' ? 'selected' : '' ?>>All organizations</option>
                        <?php foreach($orgs as $o): ?>
                            <option value="<?= $o['id'] ?>" <?= (string)$target_org_filter === (string)$o['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($o['org_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <select id="siteFilterSelect" class="org-selector" onchange="executeSiteFilterRoute(this.value)" title="Filter by assigned site">
                    <option value="all" <?= $target_site_filter === 'all' ? 'selected' : '' ?>>All sites</option>
                    <?php foreach ($hub_filter_sites as $s): ?>
                        <option value="<?= (int)$s['SId'] ?>" <?= $target_site_filter === (string)(int)$s['SId'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars(eh_site_option_label($s, $eh_show_org_on_sites && $target_org_filter === 'all')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select id="hubPerPage" class="search-bar" style="width:140px;" onchange="changePerPage(this.value)" title="Rows per page">
                    <?php foreach (['50' => '50 / page', '100' => '100 / page', '250' => '250 / page', '500' => '500 / page', 'all' => 'Show all'] as $val => $lab): ?>
                        <option value="<?= $val ?>" <?= $per_page_opt === (string)$val ? 'selected' : '' ?>><?= $lab ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <p id="hubShowing" style="margin:10px 0 0; font-size:13px; font-weight:700; color:#0f172a;" aria-live="polite"><?= eh_list_showing_html($eh_list_ctx) ?>            </p>
        </div>
        <button class="pay-btn pay-btn-success" style="height:40px;" onclick="openAdd()">+ Add staff</button>
        <a class="pay-btn" href="direct_deposit_pending.php"
           title="Review void cheque / DD forms uploaded from My Profile, then Approve."
           style="height:40px;display:inline-flex;align-items:center;text-decoration:none;background:#0891b2;color:#fff;padding:0 14px;border-radius:8px;font-weight:800;font-size:13px;">
            Pending direct deposit<?= $dd_pending_count > 0 ? ' (' . (int)$dd_pending_count . ')' : '' ?>
        </a>
    </div>

    <form id="mainTableForm">
    <table class="audit-table" id="hubTable">
        <?php
        if (!function_exists('eh_sort_th')) {
            function eh_sort_th(string $key, string $label, string $cur, string $dir, string $style = ''): string {
                $on = ($cur === $key);
                $arrow = $on ? ($dir === 'desc' ? ' ▼' : ' ▲') : '';
                $cls = 'th-sort' . ($on ? ' on' : '');
                $st = $style !== '' ? ' style="' . htmlspecialchars($style, ENT_QUOTES) . '"' : '';
                $jskey = htmlspecialchars($key, ENT_QUOTES);
                return "<th class=\"{$cls}\" onclick=\"hubSort('{$jskey}')\"{$st}>"
                    . htmlspecialchars($label) . $arrow . '</th>';
            }
        }
        ?>
        <thead>
            <tr>
                <th width="40"><input type="checkbox" onclick="toggleAll(this)"></th>
                <?= eh_sort_th('name', 'Name & Contact', $sort_key, $sort_dir) ?>
                <?= eh_sort_th('user', 'Username', $sort_key, $sort_dir) ?>
                <?php if ($user_level <= 10): ?><?= eh_sort_th('org', 'Organization Branch', $sort_key, $sort_dir) ?><?php endif; ?>
                <?= eh_sort_th('sites', 'Assigned Sites', $sort_key, $sort_dir) ?>
                <?= eh_sort_th('role', 'Role', $sort_key, $sort_dir) ?>
                <?= eh_sort_th('etype', 'Type', $sort_key, $sort_dir) ?>
                <?= eh_sort_th('rate', 'Rate', $sort_key, $sort_dir) ?>
                <?= eh_sort_th('account', 'Account', $sort_key, $sort_dir) ?>
                <?= eh_sort_th('clock', 'Clock', $sort_key, $sort_dir) ?>
                <th style="text-align:right; padding-right: 25px; cursor:default;">Actions</th>
            </tr>
        </thead>
        <tbody id="hubRows">
<?= eh_list_rows_html($users, (int)$user_level, $site_by_id) ?>        </tbody>
    </table>
    <div id="hubPager"><?= eh_list_pager_html($eh_list_ctx) ?></div>
    </form>
</div>

<div id="bulkBar" class="bulk-bar">
    <span id="selectedCount" style="font-weight:900; color:#10b981;">0 Selected</span>
    <button type="button" onclick="openBulkModal()" style="background:#3b82f6; color:white; border:none; padding:8px 20px; border-radius:20px; font-weight:700; cursor:pointer;">ALLOCATE SITES</button>
    <button type="button" onclick="cancelBulk()" style="background:transparent; color:#94a3b8; border:none; cursor:pointer;">Cancel</button>
</div>

<div id="bulkOverlay" class="modal-overlay">
    <div class="modal-content" style="width:600px;">
        <h2 style="font-weight:900; margin-bottom:20px; color: #0f172a;">Bulk Site Allocation</h2>
        <form method="POST">
            <div id="bulkUserList"></div> 
            <div class="form-group">
                <label>Assigned Sites</label>
                <select name="bulk_allocated_sites[]" id="bulk_allocated" multiple>
                    <?php foreach($sites as $s): ?><option value="<?= (int)$s['SId'] ?>"><?= htmlspecialchars(eh_site_option_label($s, $eh_show_org_on_sites)) ?></option><?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group" style="margin-top:20px;">
                <label>Primary Site</label>
                <select name="bulk_primary_site" style="height: 42px;">
                    <option value="">-- None --</option>
                    <?php foreach($sites as $s): ?><option value="<?= (int)$s['SId'] ?>"><?= htmlspecialchars(eh_site_option_label($s, $eh_show_org_on_sites)) ?></option><?php endforeach; ?>
                </select>
            </div>

            <div style="margin-top:30px; display:flex; gap:15px;">
                <button type="submit" name="bulk_update_sites" class="btn btn-success" style="flex:2; background:#10b981; color:white; border:none; padding:15px; font-weight:800; border-radius: 8px; cursor: pointer;">APPLY TO ALL SELECTED</button>
                <button type="button" class="btn" onclick="closeBulk()" style="flex:1; background:#64748b; color:white; border:none; border-radius: 8px; cursor: pointer;">CANCEL</button>
            </div>
        </form>
    </div>
</div>

<div id="editOverlay" class="modal-overlay">
    <div class="modal-content">
        <h2 id="mTitle" style="font-weight:900; margin-bottom:25px; color:#0f172a;">Employee Profile</h2>
        <form method="POST" action="employee_hub.php" id="staffForm">
            <input type="hidden" name="user_id" id="f_id">
            <input type="hidden" name="hub_action" id="f_hub_action" value="add_user">
            <input type="hidden" name="return_org_filter" id="f_return_org" value="<?= htmlspecialchars((string)$target_org_filter) ?>">
            <input type="hidden" name="return_site_filter" id="f_return_site" value="<?= htmlspecialchars((string)$target_site_filter) ?>">
            <div id="staffFormErr" class="alert-box" style="display:none;"></div>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:15px;">
                <div class="form-group"><label>Full Name</label><input type="text" name="name" id="f_name" required autocomplete="name"></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" id="f_email" autocomplete="off"></div>
                <div class="form-group"><label>Status</label><select name="active" id="f_active"><option value="1">Active</option><option value="0">Off</option></select></div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:15px; margin-top:16px;">
                <div class="form-group"><label>Username</label><input type="text" name="user" id="f_user" required autocomplete="off"><div class="hub-hint">Spaces are removed (aslam daud → aslamdaud).</div></div>
                <div class="form-group"><label>Password</label><input type="text" name="password" id="f_password" autocomplete="new-password" placeholder="Office login"><div class="hub-hint">Office login on login_kiosk.php. Blank = use Clock PIN.</div></div>
                <div class="form-group"><label>Clock PIN</label><input type="text" name="pin" id="f_pin" autocomplete="off"><div class="hub-hint">Clock-in kiosk only. Not the office password unless you leave Password blank.</div></div>
                <div class="form-group"><label>Role</label>
                    <select name="user_role" id="f_role">
                        <?php foreach($role_choices as $r): ?><option value="<?=$r['role_id']?>"><?=$r['role_name']?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:15px; margin-top:16px;">
                <div class="form-group"><label>Mobile</label><input type="tel" name="Tel_Mobile" id="f_mobile" autocomplete="off" placeholder="6475551234"></div>
                <div class="form-group"><label>Tel</label><input type="tel" name="Tel" id="f_tel" autocomplete="off" placeholder="4165551234"></div>
                <?php if ($user_level <= 10): ?>
                <div class="form-group"><label style="color:#2563eb;">Organization</label>
                    <select name="org_id" id="f_org_id" required style="border: 1px solid #93c5fd;">
                        <option value="">-- Select organization --</option>
                        <?php foreach($orgs as $o): ?><option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['org_name']) ?></option><?php endforeach; ?>
                    </select>
                    <div class="hub-hint">Required. Pick the company this person belongs to. Missing a company? <a href="manage_orgs.php#add-organization">Add organization</a> (Super Admin), then return here — it will be in this list.</div>
                </div>
                <?php else: ?>
                <input type="hidden" name="org_id" id="f_org_id" value="<?= (int)$session_org ?>">
                <div class="form-group"><label>Organization</label><input type="text" value="This organization only" disabled></div>
                <?php endif; ?>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:15px; margin-top:16px;">
                <div class="form-group"><label>Employee type</label>
                    <select name="employee_type" id="f_employee_type">
                        <option value="hourly">Hourly — normal CPP / EI</option>
                        <option value="monthly">Monthly / salaried — normal CPP / EI</option>
                        <option value="contract">Contract — no CPP, no EI</option>
                    </select>
                    <div class="hub-hint">Contract skips CPP and EI (employee + employer). Vac / WSIB stay on the boxes below.</div>
                </div>
                <div class="form-group"><label>Hourly Rate</label><input type="number" step="0.01" name="hourly_rate" id="f_rate"><div class="hub-hint"><a href="rate_change.php">Rate Change template</a> — dated changes (effective date + recorded time).</div></div>
                <div class="form-group"><label>Official Rate</label><input type="number" step="0.01" name="official_rate" id="f_official"></div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-top:16px;">
                <div class="form-group"><label>Rate effective date</label><input type="date" name="rate_effective_date" id="f_rate_effective" value="<?= date('Y-m-d') ?>"><div class="hub-hint">First work day this rate applies. Opening a profile shows the saved date, not today. Today is only the starting value for a new employee.</div></div>
                <div class="form-group"><label>Rate change note</label><input type="text" name="rate_note" id="f_rate_note" maxlength="255" placeholder="SK min wage 1 Oct 2026"></div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-top:16px;">
                <div class="form-group"><label>Primary Site</label><select name="primary_restaurant_id" id="f_primary"><option value="">-- None --</option><?php foreach($sites as $s): ?><option value="<?= (int)$s['SId'] ?>"><?= htmlspecialchars(eh_site_option_label($s, $eh_show_org_on_sites)) ?></option><?php endforeach; ?></select><div class="hub-hint">Optional for Admin. Leave None if this org has no sites yet.</div></div>
                <div class="form-group"><label>Assigned Sites</label>
                    <select name="allocated_sites[]" id="f_allocated" multiple><?php foreach($sites as $s): ?><option value="<?= (int)$s['SId'] ?>"><?= htmlspecialchars(eh_site_option_label($s, $eh_show_org_on_sites)) ?></option><?php endforeach; ?></select>
                    <div class="hub-hint">Search by name or site # (e.g. 104). Inactive sites stay assignable here.</div>
                </div>
            </div>
            <div style="margin-top:16px; padding:12px 14px; border:1px solid #e2e8f0; border-radius:10px; background:#f8fafc;">
                <div style="font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:0.03em; margin-bottom:10px;">Payroll exemptions</div>
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
                    <label style="display:flex; align-items:flex-start; gap:8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer;">
                        <input type="checkbox" name="ei_exempt" id="f_ei_exempt" value="1" style="margin-top:3px;">
                        <span>Skip EI<br><small style="font-weight:500; color:#64748b;">EI Exempt — no employee or employer EI</small></span>
                    </label>
                    <label style="display:flex; align-items:flex-start; gap:8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer;">
                        <input type="checkbox" name="wsib_exempt" id="f_wsib_exempt" value="1" style="margin-top:3px;">
                        <span>WSIB/WCB Exempt<br><small style="font-weight:500; color:#64748b;">No premium for this person</small></span>
                    </label>
                    <label style="display:flex; align-items:flex-start; gap:8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer;">
                        <input type="checkbox" name="vac_pay_exempt" id="f_vac_pay_exempt" value="1" style="margin-top:3px;">
                        <span>Vac Pay Exempt<br><small style="font-weight:500; color:#64748b;">Skip vacation accrual/%</small></span>
                    </label>
                </div>
                <div style="margin-top:10px; font-size:12px; line-height:1.45; color:#9a3412; font-weight:600;">Use Skip EI only if CRA excepts this person (partner, director’s fees, more than 40% voting shares, or similar). This is not a CRA ruling.</div>
            </div>
            <details class="pay-adv" open>
                <summary>T4 / CRA identity — SIN, name split, address, dental (box 45)</summary>
                <div class="pay-adv-body" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:15px;">
                    <div class="form-group"><label>Given name</label><input type="text" name="given_name" id="f_given_name" maxlength="40"></div>
                    <div class="form-group"><label>Surname</label><input type="text" name="surname" id="f_surname" maxlength="40"></div>
                    <div class="form-group"><label>SIN</label><input type="text" name="sin" id="f_sin" maxlength="11" placeholder="123456789" inputmode="numeric"></div>
                    <div class="form-group" style="grid-column:1 / -1;"><label>Street address</label><input type="text" name="street_address" id="f_street_address" maxlength="120"></div>
                    <div class="form-group"><label>City</label><input type="text" name="city" id="f_city" maxlength="80"></div>
                    <div class="form-group"><label>Province</label>
                        <select name="province" id="f_province">
                            <option value="">—</option>
                            <?php foreach (['BC','ON','SK','AB','MB','NB','NL','NS','PE','QC','NT','NU','YT'] as $p): ?>
                            <option value="<?= $p ?>"><?= $p ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Postal code</label><input type="text" name="postal_code" id="f_postal_code" maxlength="7" style="text-transform:uppercase;" placeholder="A1A1A1"></div>
                    <div class="form-group" style="grid-column:1 / -1;"><label>Dental benefits (T4 box 45)</label>
                        <select name="dental_benefit_code" id="f_dental">
                            <option value="1">1 — Not eligible</option>
                            <option value="2">2 — Payee only</option>
                            <option value="3">3 — Payee, spouse and dependent children</option>
                            <option value="4">4 — Payee and spouse</option>
                            <option value="5">5 — Payee and dependent children</option>
                        </select>
                    </div>
                </div>
            </details>
            <details class="pay-adv">
                <summary>Advanced — remarks</summary>
                <div class="pay-adv-body" style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                    <div class="form-group" style="grid-column:1 / -1;"><label>Remarks</label><textarea name="remarks" id="f_remarks" rows="2"></textarea></div>
                </div>
            </details>
            <div style="margin-top:22px; display:flex; gap:12px;">
                <button type="submit" name="update_user" id="f_submit" class="pay-btn pay-btn-success" style="flex:2;">Save changes</button>
                <button type="button" class="pay-btn pay-btn-secondary" onclick="closeEdit()" style="flex:1;">Close</button>
            </div>
        </form>
    </div>
</div>

<div id="eiExemptModal" class="modal-overlay" style="z-index:12000; display:none;" role="dialog" aria-modal="true" aria-labelledby="eiExemptTitle">
    <div class="modal-content" style="width:520px;">
        <h2 id="eiExemptTitle" style="font-weight:900; margin:0 0 12px; color:#0f172a; font-size:1.25rem;">Skip EI for this person?</h2>
        <p style="margin:0; font-size:14px; line-height:1.5; color:#334155;">Skipping employee and employer EI for this person. Use this only if the CRA excepts them (a partner, director’s fees, or more than 40% of the voting shares). This is not a CRA ruling.</p>
        <div style="margin-top:18px; display:flex; gap:12px;">
            <button type="button" id="eiExemptConfirmBtn" class="pay-btn pay-btn-success" style="flex:1;">Confirm</button>
            <button type="button" id="eiExemptCancelBtn" class="pay-btn pay-btn-secondary" style="flex:1;">Cancel</button>
        </div>
    </div>
</div>

<script>
let sitePicker, bulkSitePicker;
let ehEiSuppress = false;
let ehEiConfirmed = false;
let ehEiLoadedOn = false;
let ehEiModalOpen = false;
const tsOpts = {
    plugins: ['remove_button'],
    create: false,
    persist: false,
    maxOptions: null,
    dropdownParent: 'body',
    placeholder: 'Search site name or #id…'
};
document.addEventListener('DOMContentLoaded', () => {
    sitePicker = new TomSelect('#f_allocated', tsOpts);
    bulkSitePicker = new TomSelect('#bulk_allocated', tsOpts);
    
    const input = document.getElementById('hubSearch');
    if (input && input.value !== '') {
        input.focus();
        const val = input.value;
        input.value = '';
        input.value = val;
    }
    const form = document.getElementById('staffForm');
    if (form) {
        form.addEventListener('invalid', (e) => {
            const details = e.target && e.target.closest ? e.target.closest('details') : null;
            if (details) details.open = true;
        }, true);
        form.addEventListener('submit', (e) => {
            const userEl = document.getElementById('f_user');
            if (userEl) userEl.value = ehSanitizeUsername(userEl.value);
            if (userEl && !userEl.value) {
                e.preventDefault();
                ehShowFormErr('Username cannot be empty after removing spaces.');
                ehResetSaveBtn();
                return;
            }
            const orgEl = document.getElementById('f_org_id');
            if (orgEl && orgEl.tagName === 'SELECT' && !orgEl.value) {
                e.preventDefault();
                ehShowFormErr('Select an organization (e.g. AMJ Canada).');
                try { orgEl.focus(); } catch (err) {}
                ehResetSaveBtn();
                return;
            }
            const act = document.getElementById('f_hub_action');
            const idEl = document.getElementById('f_id');
            if (act) {
                act.value = (idEl && parseInt(idEl.value, 10) > 0) ? 'update_user' : 'add_user';
            }
            const eiBox = document.getElementById('f_ei_exempt');
            if (ehEiModalOpen) {
                e.preventDefault();
                ehResetSaveBtn();
                return;
            }
            if (eiBox && eiBox.checked && !ehEiConfirmed && !ehEiLoadedOn) {
                e.preventDefault();
                ehShowFormErr('Confirm Skip EI before saving. Employee and employer EI stay on until you confirm.');
                ehResetSaveBtn();
                ehOpenEiModal();
                return;
            }
            const btn = document.getElementById('f_submit');
            if (btn) {
                btn.dataset.prev = btn.innerText;
                btn.innerText = 'Saving…';
                setTimeout(ehResetSaveBtn, 15000);
            }
        });
    }
});

function ehSanitizeUsername(raw) {
    return String(raw || '').trim().replace(/\s+/g, '');
}
function ehShowFormErr(msg) {
    const box = document.getElementById('staffFormErr');
    if (!box) { alert(msg); return; }
    box.style.display = 'block';
    box.textContent = msg;
}
function ehResetSaveBtn() {
    const btn = document.getElementById('f_submit');
    if (!btn) return;
    btn.disabled = false;
    if (btn.dataset.prev) btn.innerText = btn.dataset.prev;
}

function toggleAll(m) { document.querySelectorAll('.user-cb').forEach(c => c.checked = m.checked); checkBulk(); }

function checkBulk() {
    let count = document.querySelectorAll('.user-cb:checked').length;
    document.getElementById('bulkBar').style.display = count > 0 ? 'flex' : 'none';
    document.getElementById('selectedCount').innerText = count + " Selected";
}

function cancelBulk() { document.querySelectorAll('.user-cb').forEach(c => c.checked = false); checkBulk(); }

function openBulkModal() {
    let container = document.getElementById('bulkUserList');
    container.innerHTML = '';
    document.querySelectorAll('.user-cb:checked').forEach(cb => {
        let input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_users[]';
        input.value = cb.value;
        container.appendChild(input);
    });
    document.getElementById('bulkOverlay').style.display = 'flex';
}

function closeBulk() { document.getElementById('bulkOverlay').style.display = 'none'; }

function hubQs(extra) {
    const params = new URLSearchParams();
    const cur = new URLSearchParams(window.location.search);
    const searchEl = document.getElementById('hubSearch');
    const orgEl = document.getElementById('orgFilterSelect');
    const perEl = document.getElementById('hubPerPage');
    const searchVal = searchEl ? searchEl.value.trim() : '';
    if (searchVal) params.set('search', searchVal);
    if (orgEl) params.set('org_filter', orgEl.value);
    const siteEl = document.getElementById('siteFilterSelect');
    if (siteEl && siteEl.value && siteEl.value !== 'all') params.set('site_filter', siteEl.value);
    if (perEl) params.set('per_page', perEl.value);
    if (cur.get('sort')) params.set('sort', cur.get('sort'));
    if (cur.get('dir')) params.set('dir', cur.get('dir'));
    if (extra) {
        Object.keys(extra).forEach((k) => {
            if (extra[k] === '' || extra[k] == null) params.delete(k);
            else params.set(k, extra[k]);
        });
    }
    const qs = params.toString();
    return window.location.pathname + (qs ? ('?' + qs) : '');
}

// Live search Nov 2026: filter the list in place (no page reload). The server runs the same query and
// access rules as the full page (?ajax=list) and sends back the rendered rows, count text and pager.
let searchTimeout;
let ehListCtrl = null;
let ehListSeq = 0;
let ehLastSearch = (function () {
    const el = document.getElementById('hubSearch');
    return el ? el.value.trim() : '';
})();
function searchDebounce(val) {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => { ehLiveSearch(false); }, 250);
}
function hubSearchKey(e) {
    if (e && e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(searchTimeout);
        ehLiveSearch(true);
    }
}
function ehLiveSearch(force) {
    const input = document.getElementById('hubSearch');
    if (!input) return;
    const next = input.value.trim();
    if (!force && next === ehLastSearch) return;
    ehLastSearch = next;
    const url = hubQs({ search: next, page: '1' });
    if (ehListCtrl) {
        try { ehListCtrl.abort(); } catch (e) {}
    }
    const ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    ehListCtrl = ctrl;
    const seq = ++ehListSeq;
    const table = document.getElementById('hubTable');
    if (table) table.setAttribute('aria-busy', 'true');
    const opts = { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
    if (ctrl) opts.signal = ctrl.signal;
    fetch(url + (url.indexOf('?') >= 0 ? '&' : '?') + 'ajax=list', opts)
        .then((r) => {
            const ct = r.headers.get('Content-Type') || '';
            if (!r.ok || ct.indexOf('application/json') === -1) throw new Error('list_failed');
            return r.json();
        })
        .then((data) => {
            if (seq !== ehListSeq) return;
            if (!data || data.ok !== true) throw new Error('list_failed');
            ehApplyList(data, url);
        })
        .catch((err) => {
            if (err && err.name === 'AbortError') return;
            if (seq !== ehListSeq) return;
            // Session expired or server error: fall back to the old full-page search.
            window.location.href = url;
        })
        .finally(() => {
            if (seq !== ehListSeq) return;
            ehListCtrl = null;
            if (table) table.removeAttribute('aria-busy');
        });
}
function ehApplyList(data, url) {
    const body = document.getElementById('hubRows');
    const showing = document.getElementById('hubShowing');
    const pager = document.getElementById('hubPager');
    if (body) body.innerHTML = String(data.rows || '');
    if (showing) showing.innerHTML = String(data.showing || '');
    if (pager) pager.innerHTML = String(data.pager || '');
    const clearBtn = document.getElementById('hubClearSearch');
    if (clearBtn) clearBtn.style.display = (String(data.search || '') !== '') ? '' : 'none';
    const allBox = document.querySelector('#hubTable thead input[type=checkbox]');
    if (allBox) allBox.checked = false;
    checkBulk();
    try { window.history.replaceState(window.history.state, '', url); } catch (e) {}
}

function clearHubSearch() {
    const input = document.getElementById('hubSearch');
    if (!input) {
        window.location.href = hubQs({ search: '', page: '1' });
        return;
    }
    input.value = '';
    clearTimeout(searchTimeout);
    ehLiveSearch(true);
    try { input.focus(); } catch (e) {}
}

function changePerPage(val) {
    window.location.href = hubQs({ per_page: val, page: '1' });
}

function hubSort(key) {
    const cur = new URLSearchParams(window.location.search);
    const now = cur.get('sort') || 'name';
    const dir = cur.get('dir') || 'asc';
    const nextDir = (now === key && dir === 'asc') ? 'desc' : 'asc';
    window.location.href = hubQs({ sort: key, dir: nextDir, page: '1' });
}

function executeOrgFilterRoute(chosenOrgId) {
    window.location.href = hubQs({ org_filter: chosenOrgId, site_filter: '', page: '1' });
}

function executeSiteFilterRoute(chosenSiteId) {
    window.location.href = hubQs({ site_filter: chosenSiteId === 'all' ? '' : chosenSiteId, page: '1' });
}

function ehUnlockHubFields() {
    ['f_name','f_email','f_active','f_user','f_password','f_pin','f_role','f_rate','f_official','f_primary','f_allocated','f_remarks','f_mobile','f_tel','f_org_id'].forEach((id) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.removeAttribute('readonly');
        el.removeAttribute('disabled');
    });
    if (sitePicker) {
        try { sitePicker.enable(); } catch (e) {}
    }
}

function ehSetAssignedSites(csv) {
    if (!sitePicker) return;
    sitePicker.clear(true);
    const ids = String(csv || '').split(/[,\s|]+/).map((s) => s.trim()).filter(Boolean);
    ids.forEach((id) => {
        if (!sitePicker.options[id]) {
            sitePicker.addOption({ value: id, text: 'Site #' + id });
        }
    });
    sitePicker.setValue(ids, true);
}

function editUser(u) {
    document.getElementById('editOverlay').style.display = 'flex';
    document.getElementById('mTitle').innerText = "Edit profile — rate, sites, role, contact, login";
    document.getElementById('f_submit').name = "update_user";
    const actUp = document.getElementById('f_hub_action');
    if (actUp) actUp.value = 'update_user';
    ehUnlockHubFields();
    document.getElementById('f_id').value = u.id;
    document.getElementById('f_name').value = u.Name;
    document.getElementById('f_email').value = u.email;
    document.getElementById('f_active').value = u.active;
    document.getElementById('f_user').value = u.user;
    const pw = document.getElementById('f_password');
    if (pw) pw.value = '';
    document.getElementById('f_pin').value = u.pin;
    document.getElementById('f_role').value = u.user_role;
    document.getElementById('f_rate').value = u.hourly_rate;
    document.getElementById('f_official').value = u.official_rate;
    const eff = document.getElementById('f_rate_effective');
    if (eff) eff.value = (u.rate_effective_date || '').toString().substring(0, 10);
    document.getElementById('f_primary').value = u.primary_restaurant_id || "";
    document.getElementById('f_remarks').value = u.Remarks || "";
    const orgEl = document.getElementById('f_org_id');
    if (orgEl) orgEl.value = u.org_id || '';
    const mob = document.getElementById('f_mobile');
    if (mob) mob.value = u.Tel_Mobile || '';
    const tel = document.getElementById('f_tel');
    if (tel) tel.value = u.Tel || '';
    document.getElementById('f_sin').value = u.sin || '';
    document.getElementById('f_given_name').value = u.given_name || '';
    document.getElementById('f_surname').value = u.surname || '';
    document.getElementById('f_street_address').value = u.street_address || '';
    document.getElementById('f_city').value = u.city || '';
    document.getElementById('f_province').value = u.province || '';
    document.getElementById('f_postal_code').value = u.postal_code || '';
    document.getElementById('f_dental').value = u.dental_benefit_code || 1;
    ehEiLoadedOn = !!(parseInt(u.ei_exempt, 10));
    ehEiConfirmed = false;
    ehCloseEiModal();
    ehSetEiBox(ehEiLoadedOn);
    document.getElementById('f_wsib_exempt').checked = !!(parseInt(u.wsib_exempt, 10));
    document.getElementById('f_vac_pay_exempt').checked = !!(parseInt(u.vac_pay_exempt, 10));
    const et = document.getElementById('f_employee_type');
    if (et) {
        const raw = String(u.employee_type || 'hourly').toLowerCase();
        et.value = (raw === 'monthly' || raw === 'contract') ? raw : 'hourly';
    }
    ehSetAssignedSites(u.assigned_site_ids);
}

function openAdd() {
    document.getElementById('editOverlay').style.display = 'flex';
    document.getElementById('mTitle').innerText = "Add staff — rate, sites, role, contact, login";
    ehEiSuppress = true;
    document.querySelector('#editOverlay form').reset();
    ehEiSuppress = false;
    ehEiLoadedOn = false;
    ehEiConfirmed = false;
    ehCloseEiModal();
    document.getElementById('f_submit').name = "add_user";
    const actAdd = document.getElementById('f_hub_action');
    if (actAdd) actAdd.value = 'add_user';
    const idAdd = document.getElementById('f_id');
    if (idAdd) idAdd.value = '';
    ehUnlockHubFields();
    const err = document.getElementById('staffFormErr');
    if (err) { err.style.display = 'none'; err.textContent = ''; }
    const orgEl = document.getElementById('f_org_id');
    const filterEl = document.getElementById('orgFilterSelect');
    let orgVal = '';
    if (filterEl && filterEl.value && filterEl.value !== 'all') {
        orgVal = filterEl.value;
    } else {
        const sessionOrg = String(<?= (int)$session_org ?>);
        if (orgEl && orgEl.tagName === 'SELECT') {
            const has = Array.from(orgEl.options).some((o) => o.value === sessionOrg);
            if (has) orgVal = sessionOrg;
        } else {
            orgVal = sessionOrg;
        }
    }
    if (orgEl) orgEl.value = orgVal;
    const ret = document.getElementById('f_return_org');
    if (ret && filterEl) ret.value = filterEl.value;
    const pwAdd = document.getElementById('f_password');
    if (pwAdd) pwAdd.value = '';
    const mob = document.getElementById('f_mobile');
    if (mob) mob.value = '';
    const tel = document.getElementById('f_tel');
    if (tel) tel.value = '';
    ehSetEiBox(false);
    document.getElementById('f_wsib_exempt').checked = false;
    document.getElementById('f_vac_pay_exempt').checked = false;
    const etAdd = document.getElementById('f_employee_type');
    if (etAdd) etAdd.value = 'hourly';
    ehResetSaveBtn();
    ehSetAssignedSites('');
}

function closeEdit() {
    ehCloseEiModal();
    document.getElementById('editOverlay').style.display = 'none';
}

function ehSetEiBox(on) {
    const box = document.getElementById('f_ei_exempt');
    if (!box) return;
    ehEiSuppress = true;
    box.checked = !!on;
    ehEiSuppress = false;
}

function ehEiEmployeeId() {
    const idEl = document.getElementById('f_id');
    const n = idEl ? parseInt(idEl.value, 10) : 0;
    return n > 0 ? n : 0;
}

function ehEiAudit(action) {
    const body = new URLSearchParams();
    body.set('hub_action', 'ei_exempt_audit');
    body.set('audit_action', action);
    body.set('employee_id', String(ehEiEmployeeId()));
    return fetch('employee_hub.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: body.toString()
    }).then((r) => r.json());
}

function ehOpenEiModal() {
    const modal = document.getElementById('eiExemptModal');
    if (!modal) return;
    ehEiModalOpen = true;
    modal.style.display = 'flex';
    const btn = document.getElementById('eiExemptConfirmBtn');
    if (btn) btn.focus();
}

function ehCloseEiModal() {
    const modal = document.getElementById('eiExemptModal');
    ehEiModalOpen = false;
    if (modal) modal.style.display = 'none';
}

function ehConfirmEiExempt() {
    const btn = document.getElementById('eiExemptConfirmBtn');
    if (btn) btn.disabled = true;
    ehEiAudit('confirm').then((res) => {
        if (btn) btn.disabled = false;
        if (!res || !res.ok) {
            ehEiConfirmed = false;
            ehSetEiBox(false);
            ehCloseEiModal();
            ehShowFormErr('Confirmation was not recorded. Skip EI stays off.');
            return;
        }
        ehEiConfirmed = true;
        ehSetEiBox(true);
        ehCloseEiModal();
    }).catch(() => {
        if (btn) btn.disabled = false;
        ehEiConfirmed = false;
        ehSetEiBox(false);
        ehCloseEiModal();
        ehShowFormErr('Confirmation was not recorded. Skip EI stays off.');
    });
}

function ehCancelEiExempt() {
    if (!ehEiModalOpen) return;
    const btn = document.getElementById('eiExemptCancelBtn');
    if (btn) btn.disabled = true;
    ehEiAudit('cancel').then((res) => {
        if (btn) btn.disabled = false;
        ehEiConfirmed = false;
        ehEiLoadedOn = false;
        ehSetEiBox(false);
        ehCloseEiModal();
        if (!res || !res.ok) {
            ehShowFormErr('Cancel was not recorded on the server. Skip EI is off.');
        }
    }).catch(() => {
        if (btn) btn.disabled = false;
        ehEiConfirmed = false;
        ehEiLoadedOn = false;
        ehSetEiBox(false);
        ehCloseEiModal();
        ehShowFormErr('Cancel was not recorded on the server. Skip EI is off.');
    });
}

function ehBindEiExempt() {
    const box = document.getElementById('f_ei_exempt');
    if (!box || box.dataset.eiBound === '1') return;
    box.dataset.eiBound = '1';
    box.addEventListener('change', () => {
        if (ehEiSuppress) return;
        if (ehEiModalOpen) {
            ehSetEiBox(true);
            return;
        }
        if (box.checked) {
            ehEiConfirmed = false;
            ehEiAudit('click_on').then((res) => {
                if (!res || !res.ok) {
                    ehSetEiBox(false);
                    ehShowFormErr('Could not record the Skip EI click. The box stays off.');
                    return;
                }
                ehOpenEiModal();
            }).catch(() => {
                ehSetEiBox(false);
                ehShowFormErr('Could not record the Skip EI click. The box stays off.');
            });
        } else {
            ehEiConfirmed = false;
            ehEiLoadedOn = false;
            ehEiAudit('click_off').catch(() => {
                ehShowFormErr('Could not record turning Skip EI off.');
            });
        }
    });
    const modal = document.getElementById('eiExemptModal');
    const confirmBtn = document.getElementById('eiExemptConfirmBtn');
    const cancelBtn = document.getElementById('eiExemptCancelBtn');
    if (confirmBtn) confirmBtn.addEventListener('click', ehConfirmEiExempt);
    if (cancelBtn) cancelBtn.addEventListener('click', ehCancelEiExempt);
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) ehCancelEiExempt();
        });
    }
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && ehEiModalOpen) {
            e.preventDefault();
            ehCancelEiExempt();
        }
    });
}
ehBindEiExempt();
</script>
</body>
</html>
<?php ob_end_flush(); ?>