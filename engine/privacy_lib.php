<?php
/**
 * PIPEDA / BC PIPA safeguards for the payroll app.
 *
 * Platform superadmins (role level 10 and below) do not receive an
 * organization's pay, bank, SIN, employee, report, or attachment data
 * unless an organization admin has an unexpired support grant,
 * PLATFORM_SUPERADMIN_FULL_ACCESS=1 is set, or the organization is on
 * SUPERADMIN_PAYROLL_ORG_ALLOWLIST (PK BC Food, id 2, and AA Group, id 4,
 * unless that list is overridden in the private payroll env file).
 * PLATFORM_SUPERADMIN_FULL_ACCESS=1 in the private payroll env file
 * lets those superadmins through until it is set back to 0. The grant
 * flow stays. The allowlist stays. Inside an organization, a higher role number sees less.
 *
 * This file does not calculate payroll and does not mark anyone paid.
 */

require_once __DIR__ . '/payroll_db_config.php';

const PRIVACY_ENC_PREFIX = 'enc:v1:';
const PRIVACY_IDLE_SECONDS = 1800;
const PRIVACY_ABSOLUTE_SECONDS = 28800;
const PRIVACY_GRANT_MAX_HOURS = 72;

/** @var list<int> PK BC Food INC (2) and AA Group (4). */
const SUPERADMIN_PAYROLL_ORG_ALLOWLIST = [2, 4];

function privacy_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function privacy_harden_runtime(): void
{
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store, private');
}

function privacy_flag_enabled(string $name): bool
{
    $fromEnv = getenv($name);
    if ($fromEnv === '1') {
        return true;
    }
    try {
        $path = payroll_resolve_env_file(payroll_app_root());
    } catch (Throwable $e) {
        return false;
    }
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }
    $cfg = payroll_parse_env((string)file_get_contents($path));
    return (string)($cfg[$name] ?? '') === '1';
}

function privacy_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    if (privacy_flag_enabled('PAYROLL_TRUST_PROXY')) {
        $fwd = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        return $fwd === 'https';
    }
    return false;
}

function privacy_cookie_params(bool $https, bool $allowHttp): array
{
    return [
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https || !$allowHttp,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function privacy_configure_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $params = privacy_cookie_params(privacy_request_is_https(), privacy_flag_enabled('PAYROLL_ALLOW_HTTP'));
    session_set_cookie_params($params);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $params['secure'] ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
}

function privacy_https_redirect_target(string $host, string $uri): ?string
{
    if (!preg_match('/^[A-Za-z0-9.-]+(?::\d+)?$/', $host)) {
        return null;
    }
    if ($uri === '' || $uri[0] !== '/') {
        return null;
    }
    return 'https://' . $host . $uri;
}

function privacy_enforce_transport(): void
{
    if (PHP_SAPI === 'cli' || privacy_request_is_https()) {
        if (PHP_SAPI !== 'cli' && privacy_request_is_https() && !headers_sent()) {
            header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
        }
        return;
    }
    if (privacy_flag_enabled('PAYROLL_ALLOW_HTTP')) {
        return;
    }
    $target = privacy_https_redirect_target((string)($_SERVER['HTTP_HOST'] ?? ''), (string)($_SERVER['REQUEST_URI'] ?? '/'));
    if ($target === null) {
        http_response_code(403);
        exit('HTTPS is required.');
    }
    header('Location: ' . $target, true, 301);
    exit;
}

function privacy_timeout_decision(?int $started, ?int $lastSeen, int $now, bool $loggedIn, int $idle = PRIVACY_IDLE_SECONDS, int $absolute = PRIVACY_ABSOLUTE_SECONDS): string
{
    if (!$loggedIn) {
        return 'ok';
    }
    if ($started !== null && ($now - $started) > $absolute) {
        return 'absolute';
    }
    if ($lastSeen !== null && ($now - $lastSeen) > $idle) {
        return 'idle';
    }
    return 'ok';
}

function privacy_logged_in(): bool
{
    $id = $_SESSION['user_id'] ?? null;
    return $id !== null && $id !== '' && (int)$id > 0;
}

function privacy_expire_session(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    if (PHP_SAPI !== 'cli') {
        header('Location: login_kiosk.php?err=session_expired');
        exit;
    }
}

function privacy_enforce_timeout(int $now = 0): void
{
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    if ($now <= 0) {
        $now = time();
    }
    $started = isset($_SESSION['privacy_started_at']) ? (int)$_SESSION['privacy_started_at'] : null;
    $last = isset($_SESSION['privacy_last_seen']) ? (int)$_SESSION['privacy_last_seen'] : null;
    $decision = privacy_timeout_decision($started, $last, $now, privacy_logged_in());
    if ($decision !== 'ok') {
        privacy_expire_session();
    }
    if (privacy_logged_in()) {
        if ($started === null) {
            $_SESSION['privacy_started_at'] = $now;
        }
        $_SESSION['privacy_last_seen'] = $now;
    }
}

function privacy_boot_web(): void
{
    privacy_harden_runtime();
    if (PHP_SAPI === 'cli') {
        return;
    }
    privacy_configure_session();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (privacy_logged_in() && empty($_SESSION['privacy_cookie_refreshed'])) {
        session_regenerate_id(true);
        $_SESSION['privacy_cookie_refreshed'] = 1;
    }
    privacy_enforce_transport();
    privacy_enforce_timeout();
}

function privacy_session_level(): int
{
    $level = (int)($_SESSION['user_level'] ?? 99);
    if (function_exists('ps_is_superadmin') && ps_is_superadmin()) {
        return min($level, 10);
    }
    return $level;
}

function privacy_session_org(): int
{
    return (int)($_SESSION['org_id'] ?? 0);
}

function privacy_csrf_token(): string
{
    if (function_exists('ps_csrf_token')) {
        return (string)ps_csrf_token();
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['privacy_csrf']) || !is_string($_SESSION['privacy_csrf'])) {
        $_SESSION['privacy_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['privacy_csrf'];
}

function privacy_csrf_ok(string $sessionToken, string $posted): bool
{
    if ($sessionToken === '' || $posted === '') {
        return false;
    }
    return hash_equals($sessionToken, $posted);
}

function privacy_require_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    if (function_exists('ps_require_csrf')) {
        ps_require_csrf();
        return;
    }
    $posted = (string)($_POST['csrf_token'] ?? '');
    if (!privacy_csrf_ok(privacy_csrf_token(), $posted)) {
        http_response_code(403);
        exit('The form expired. Reload the page and try again.');
    }
}

function privacy_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . privacy_h(privacy_csrf_token()) . '">';
}

/**
 * @return array{allow:bool,support:bool,reason:string}
 */
function privacy_business_decision(int $level, int $sessionOrg, int $targetOrg, ?array $grant, string $dataClass, int $now, int $maxLevel = 20): array
{
    $known = ['platform', 'employee', 'pay', 'bank', 'sin', 'report', 'attachment'];
    if (!in_array($dataClass, $known, true)) {
        return ['allow' => false, 'support' => false, 'reason' => 'unknown'];
    }
    if ($dataClass === 'bank' || $dataClass === 'sin') {
        $maxLevel = min($maxLevel, 20);
    }
    if ($dataClass === 'platform') {
        if ($level <= 10) {
            return ['allow' => true, 'support' => false, 'reason' => 'platform'];
        }
        return ['allow' => false, 'support' => false, 'reason' => 'role'];
    }
    if ($targetOrg <= 0) {
        return ['allow' => false, 'support' => false, 'reason' => $level <= 10 ? 'choose_org' : 'org'];
    }
    if ($level <= 10) {
        if (!privacy_grant_active($grant, $targetOrg, $now)) {
            return ['allow' => false, 'support' => false, 'reason' => 'support'];
        }
        return ['allow' => true, 'support' => true, 'reason' => 'support'];
    }
    if ($level > $maxLevel || $level >= 30) {
        return ['allow' => false, 'support' => false, 'reason' => 'role'];
    }
    if ($sessionOrg <= 0 || $sessionOrg !== $targetOrg) {
        return ['allow' => false, 'support' => false, 'reason' => 'org'];
    }
    return ['allow' => true, 'support' => false, 'reason' => $level <= 20 ? 'admin' : 'supervisor'];
}

function privacy_grant_active(?array $grant, int $orgId, int $now): bool
{
    if (!is_array($grant)) {
        return false;
    }
    if ((int)($grant['org_id'] ?? 0) !== $orgId || $orgId <= 0) {
        return false;
    }
    if (!empty($grant['revoked_at'])) {
        return false;
    }
    $exp = strtotime((string)($grant['expires_at'] ?? ''));
    return $exp !== false && $exp > $now;
}

function privacy_may_grant(int $level, int $sessionOrg, int $targetOrg): bool
{
    return $level > 10 && $level <= 20 && $sessionOrg > 0 && $sessionOrg === $targetOrg;
}

function privacy_grant_expiry(int $hours, int $now): ?int
{
    if ($hours < 1 || $hours > PRIVACY_GRANT_MAX_HOURS) {
        return null;
    }
    return $now + ($hours * 3600);
}

function privacy_reason_is_safe(string $reason): bool
{
    $reason = trim($reason);
    if (strlen($reason) < 8 || strlen($reason) > 500) {
        return false;
    }
    if (preg_match('/\b\d{3}[ -]?\d{3}[ -]?\d{3}\b/', $reason)) {
        return false;
    }
    if (preg_match('/\b\d{7,17}\b/', $reason)) {
        return false;
    }
    return true;
}

function privacy_column_exists(PDO $pdo, string $table, string $column): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return false;
    }
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$table, $column]);
        return ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) {
        try {
            $pdo->query('SELECT `' . $column . '` FROM `' . $table . '` LIMIT 0');
            return true;
        } catch (Throwable $e2) {
            return false;
        }
    }
}

function privacy_table_exists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $st->execute([$table]);
        return ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) {
        try {
            $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
            return true;
        } catch (Throwable $e2) {
            return false;
        }
    }
}

function privacy_active_grant(PDO $pdo, int $orgId, int $now = 0): ?array
{
    if ($orgId <= 0 || !privacy_table_exists($pdo, 'privacy_support_grants')) {
        return null;
    }
    if ($now <= 0) {
        $now = time();
    }
    $st = $pdo->prepare(
        'SELECT * FROM privacy_support_grants
         WHERE org_id = ? AND revoked_at IS NULL AND expires_at > ?
         ORDER BY expires_at DESC LIMIT 1'
    );
    $st->execute([$orgId, date('Y-m-d H:i:s', $now)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function privacy_log_file(string $action, string $resource, int $orgId, int $actorId): void
{
    $line = date('c') . ' action=' . $action . ' resource=' . $resource
        . ' org=' . $orgId . ' actor=' . $actorId . "\n";
    try {
        $dir = dirname(payroll_resolve_env_file(payroll_app_root()));
    } catch (Throwable $e) {
        error_log('privacy log unavailable');
        return;
    }
    if (!is_dir($dir)) {
        error_log('privacy log directory missing');
        return;
    }
    $path = $dir . DIRECTORY_SEPARATOR . 'privacy.log';
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

function privacy_platform_full_access(): bool
{
    return privacy_flag_enabled('PLATFORM_SUPERADMIN_FULL_ACCESS');
}

/**
 * Org ids a platform superadmin may open without a support grant.
 * The constant is the default (2 and 4). A non-empty
 * SUPERADMIN_PAYROLL_ORG_ALLOWLIST value in the process environment
 * or the private payroll env file, as a comma-separated list of
 * positive integers, replaces that default.
 *
 * @return list<int>
 */
function privacy_superadmin_payroll_allowlist(): array
{
    $raw = null;
    $fromEnv = getenv('SUPERADMIN_PAYROLL_ORG_ALLOWLIST');
    if (is_string($fromEnv) && trim($fromEnv) !== '') {
        $raw = $fromEnv;
    } else {
        try {
            $path = payroll_resolve_env_file(payroll_app_root());
            if (is_file($path) && is_readable($path)) {
                $cfg = payroll_parse_env((string)file_get_contents($path));
                $fromFile = trim((string)($cfg['SUPERADMIN_PAYROLL_ORG_ALLOWLIST'] ?? ''));
                if ($fromFile !== '') {
                    $raw = $fromFile;
                }
            }
        } catch (Throwable $e) {
            $raw = null;
        }
    }
    $ids = SUPERADMIN_PAYROLL_ORG_ALLOWLIST;
    if (is_string($raw)) {
        $ids = [];
        foreach (preg_split('/[,\s]+/', $raw) ?: [] as $part) {
            if ($part === '' || !preg_match('/^\d+$/', $part)) {
                continue;
            }
            $ids[] = (int)$part;
        }
    }
    $out = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $out[$id] = $id;
        }
    }
    return array_values($out);
}

function privacy_superadmin_org_allowed(int $orgId): bool
{
    return $orgId > 0 && in_array($orgId, privacy_superadmin_payroll_allowlist(), true);
}

function privacy_log_event(PDO $pdo, string $action, string $resource, int $orgId, ?int $grantId, ?string $note = null): void
{
    $action = substr($action, 0, 32);
    $resource = substr($resource, 0, 128);
    $note = $note !== null ? substr($note, 0, 255) : null;
    $actor = (int)($_SESSION['user_id'] ?? 0);
    $level = privacy_session_level();
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    try {
        if (!privacy_table_exists($pdo, 'privacy_access_log')) {
            privacy_log_file($action, $resource, $orgId, $actor);
            return;
        }
        $st = $pdo->prepare(
            'INSERT INTO privacy_access_log
                (created_at, actor_id, actor_level, org_id, action, resource, grant_id, ip, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            date('Y-m-d H:i:s'),
            $actor,
            $level,
            $orgId,
            $action,
            $resource,
            $grantId,
            $ip !== '' ? $ip : null,
            $note,
        ]);
    } catch (Throwable $e) {
        privacy_log_file($action, $resource, $orgId, $actor);
    }
}

function privacy_remember_access(bool $support, ?array $grant, string $orgName, bool $fullAccess = false, bool $allowlist = false): void
{
    $GLOBALS['privacy_access_state'] = [
        'support' => $support,
        'grant' => $grant,
        'org_name' => $orgName,
        'full_access' => $fullAccess,
        'allowlist' => $allowlist,
    ];
}

function privacy_banner_html(): string
{
    $state = $GLOBALS['privacy_access_state'] ?? null;
    if (is_array($state) && !empty($state['full_access'])) {
        return '<div role="status" class="privacy-support-banner" style="background:#fef3c7;color:#78350f;border:1px solid #f59e0b;border-radius:8px;padding:10px 14px;margin:0 0 14px;font-weight:700;">'
            . 'Platform full access is ON (temporary until orgs are sold).'
            . '</div>';
    }
    if (is_array($state) && !empty($state['allowlist'])) {
        $org = trim((string)($state['org_name'] ?? ''));
        if ($org === '') {
            $org = 'this organization';
        }
        return '<div role="status" class="privacy-support-banner" style="background:#fef3c7;color:#78350f;border:1px solid #f59e0b;border-radius:8px;padding:10px 14px;margin:0 0 14px;font-weight:700;">'
            . 'Platform payroll access for ' . privacy_h($org)
            . ' is allowed by the owner allowlist. This visit is logged.'
            . '</div>';
    }
    if (!is_array($state) || empty($state['support']) || !is_array($state['grant'] ?? null)) {
        return '';
    }
    $org = trim((string)($state['org_name'] ?? ''));
    if ($org === '') {
        $org = 'this organization';
    }
    $until = (string)($state['grant']['expires_at'] ?? '');
    return '<div role="status" class="privacy-support-banner" style="background:#fef3c7;color:#78350f;border:1px solid #f59e0b;border-radius:8px;padding:10px 14px;margin:0 0 14px;font-weight:700;">'
        . 'Platform support access is active for ' . privacy_h($org)
        . ' until ' . privacy_h($until)
        . '. This visit is logged. The organization owner was emailed when access was granted. '
        . '<a href="privacy_support.php">Review or revoke access</a>.'
        . '</div>';
}

/**
 * @return list<array{id:int,org_name:string,employee_count:int,site_count:int}>
 */
function privacy_org_counts(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            'SELECT o.id, o.org_name,
                (SELECT COUNT(*) FROM users u WHERE u.org_id = o.id) AS employee_count,
                (SELECT COUNT(*) FROM sites s WHERE s.org_id = o.id) AS site_count
             FROM organizations o
             ORDER BY o.org_name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        try {
            $rows = $pdo->query('SELECT id, org_name FROM organizations ORDER BY org_name ASC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e2) {
            return [];
        }
    }
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int)($row['id'] ?? 0),
            'org_name' => (string)($row['org_name'] ?? ''),
            'employee_count' => (int)($row['employee_count'] ?? 0),
            'site_count' => (int)($row['site_count'] ?? 0),
        ];
    }
    return $out;
}

function privacy_deny_page(PDO $pdo, array $decision, string $continueScript): void
{
    http_response_code(403);
    $reason = (string)($decision['reason'] ?? 'role');
    $level = privacy_session_level();
    $showOrgs = $level <= 10 && ($reason === 'support' || $reason === 'choose_org');
    if ($reason === 'choose_org') {
        $message = 'Choose one organization. Employee, pay, bank, and report records are not loaded until an organization admin has granted support access.';
    } elseif ($reason === 'support') {
        $message = 'Platform support access is not active for this organization. Payroll, bank, SIN, employee, report, and attachment records stay hidden. An organization admin can grant access for up to 72 hours.';
    } elseif ($reason === 'org') {
        $message = 'That organization is outside your access.';
    } else {
        $message = 'Your role cannot view this information.';
    }
    $orgs = $showOrgs ? privacy_org_counts($pdo) : [];
    $script = preg_match('/^[a-z0-9_]+\.php$/', $continueScript) ? $continueScript : 'privacy_support.php';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Access limited</title>';
    echo '<style>body{font-family:sans-serif;background:#f8fafc;color:#0f172a;margin:32px auto;max-width:760px;padding:0 16px;}';
    echo '.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;} table{border-collapse:collapse;width:100%;} td,th{border-bottom:1px solid #e2e8f0;text-align:left;padding:6px;} a{color:#1d4ed8;}</style></head><body><div class="card">';
    echo '<h1>Access limited</h1><p>' . privacy_h($message) . '</p>';
    if ($showOrgs) {
        echo '<p><a href="privacy_support.php">Open support access</a></p>';
        if ($orgs) {
            echo '<table><thead><tr><th>Organization</th><th>Employees</th><th>Sites</th></tr></thead><tbody>';
            foreach ($orgs as $org) {
                $href = $script . '?org_id=' . (int)$org['id'];
                echo '<tr><td><a href="' . privacy_h($href) . '">' . privacy_h($org['org_name']) . '</a></td>';
                echo '<td>' . (int)$org['employee_count'] . '</td><td>' . (int)$org['site_count'] . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<p>The counts above are the only organization figures shown without support access. Names, pay, bank accounts, and SINs are not included.</p>';
        }
    }
    echo '</div></body></html>';
    exit;
}

/**
 * @return array{allow:bool,support:bool,reason:string,grant:?array}
 */
function privacy_access(PDO $pdo, int $orgId, string $dataClass, string $resource, string $action = 'view', int $maxLevel = 20, bool $log = true): array
{
    $level = privacy_session_level();
    $grant = null;
    if ($level <= 10 && $orgId > 0 && $dataClass !== 'platform') {
        $grant = privacy_active_grant($pdo, $orgId);
    }
    $decision = privacy_business_decision($level, privacy_session_org(), $orgId, $grant, $dataClass, time(), $maxLevel);
    $decision['grant'] = $decision['support'] ? $grant : null;
    $fullAccess = false;
    $allowlistAccess = false;
    if (
        !$decision['allow']
        && ($decision['reason'] ?? '') === 'support'
        && $level <= 10
        && privacy_platform_full_access()
    ) {
        $decision['allow'] = true;
        $decision['support'] = false;
        $decision['reason'] = 'platform_full';
        $fullAccess = true;
    }
    if (
        !$decision['allow']
        && ($decision['reason'] ?? '') === 'support'
        && $level <= 10
        && privacy_superadmin_org_allowed($orgId)
    ) {
        $decision['allow'] = true;
        $decision['support'] = false;
        $decision['reason'] = 'org_allowlist';
        $allowlistAccess = true;
    }
    $logNote = null;
    if ($fullAccess) {
        $logNote = 'platform_full_access';
    } elseif ($allowlistAccess) {
        $logNote = 'superadmin_org_allowlist';
    }
    if ($log && $decision['reason'] !== 'choose_org') {
        privacy_log_event(
            $pdo,
            $decision['allow'] ? $action : 'deny',
            $resource,
            $orgId,
            $decision['support'] ? (int)($grant['id'] ?? 0) : null,
            $logNote
        );
    }
    if ($decision['allow'] && ($decision['support'] || $fullAccess || $allowlistAccess)) {
        $orgName = '';
        try {
            $st = $pdo->prepare('SELECT org_name FROM organizations WHERE id = ?');
            $st->execute([$orgId]);
            $orgName = trim((string)$st->fetchColumn());
        } catch (Throwable $e) {
            $orgName = '';
        }
        privacy_remember_access($decision['support'], $decision['support'] ? $grant : null, $orgName, $fullAccess, $allowlistAccess);
    }
    return $decision;
}

function privacy_require_business(PDO $pdo, int $orgId, string $dataClass, string $resource, bool $json = false, int $maxLevel = 20, string $action = 'view'): array
{
    $decision = privacy_access($pdo, $orgId, $dataClass, $resource, $action, $maxLevel, true);
    if ($decision['allow']) {
        return $decision;
    }
    if ($json) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        $error = $decision['reason'] === 'support'
            ? 'Platform support access is not active for this organization.'
            : ($decision['reason'] === 'choose_org'
                ? 'Choose one organization.'
                : 'That organization is outside your access.');
        echo json_encode(['ok' => false, 'success' => false, 'status' => 'error', 'error' => $error]);
        exit;
    }
    privacy_deny_page($pdo, $decision, $resource . (str_ends_with($resource, '.php') ? '' : '.php'));
    return $decision;
}

function privacy_attendance_org(PDO $pdo, int $shiftId): int
{
    if ($shiftId <= 0) {
        return 0;
    }
    $st = $pdo->prepare('SELECT org_id FROM attendance WHERE id = ?');
    $st->execute([$shiftId]);
    return (int)($st->fetchColumn() ?: 0);
}

/**
 * @return array{subject:string,body:string}
 */
function privacy_owner_message(string $orgName, string $expiresAt, int $grantedBy, string $reason): array
{
    $orgName = trim($orgName) !== '' ? trim($orgName) : 'your organization';
    $body = "An organization administrator granted platform support access.\n\n"
        . 'Organization: ' . $orgName . "\n"
        . 'Access ends: ' . $expiresAt . "\n"
        . 'Granted by user id: ' . $grantedBy . "\n"
        . 'Reason: ' . $reason . "\n\n"
        . "The platform superadmin can view this organization's payroll data until that time. Each visit is logged.\n"
        . "Revoke access from the Payroll support access page before it expires if you need to.\n\n"
        . "This message does not include employee, pay, bank, or SIN values.\n";
    return [
        'subject' => 'Payroll support access granted for ' . $orgName,
        'body' => $body,
    ];
}

/**
 * @return list<string>
 */
function privacy_owner_emails(PDO $pdo, int $orgId): array
{
    $found = [];
    foreach (['Email', 'email'] as $col) {
        if (!privacy_column_exists($pdo, 'users', $col)) {
            continue;
        }
        $st = $pdo->prepare('SELECT `' . $col . '` AS email FROM users WHERE org_id = ? AND user_level > 10 AND user_level <= 20');
        $st->execute([$orgId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $email) {
            $email = trim((string)$email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $found[strtolower($email)] = $email;
            }
        }
    }
    foreach (['owner_email', 'contact_email', 'email'] as $col) {
        if (!privacy_column_exists($pdo, 'organizations', $col)) {
            continue;
        }
        $st = $pdo->prepare('SELECT `' . $col . '` FROM organizations WHERE id = ?');
        $st->execute([$orgId]);
        $email = trim((string)$st->fetchColumn());
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $found[strtolower($email)] = $email;
        }
    }
    return array_values($found);
}

/**
 * @param callable|null $sender function(string $to, string $subject, string $body): bool
 * @return array{sent:int,note:string}
 */
function privacy_notify_owners(PDO $pdo, int $orgId, array $message, ?callable $sender = null): array
{
    $emails = privacy_owner_emails($pdo, $orgId);
    if (!$emails) {
        return ['sent' => 0, 'note' => 'no owner email on file'];
    }
    $sender = $sender ?? static function (string $to, string $subject, string $body): bool {
        $host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'payroll.renocon.ca'));
        if (!is_string($host) || !preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
            $host = 'payroll.renocon.ca';
        }
        $headers = "Content-Type: text/plain; charset=UTF-8\r\nFrom: payroll@" . $host . "\r\n";
        return @mail($to, $subject, $body, $headers);
    };
    $sent = 0;
    foreach ($emails as $email) {
        try {
            if ($sender($email, $message['subject'], $message['body'])) {
                $sent++;
            }
        } catch (Throwable $e) {
            // The grant still stands. The note records that mail did not go out.
        }
    }
    if ($sent === 0) {
        return ['sent' => 0, 'note' => 'email was not accepted'];
    }
    return ['sent' => $sent, 'note' => 'emailed ' . $sent . ' owner address(es)'];
}

/**
 * @return array<string,mixed>
 */
function privacy_create_grant(PDO $pdo, int $orgId, int $actorId, int $actorLevel, int $sessionOrg, int $hours, string $reason, int $now = 0, ?callable $sender = null): array
{
    if ($now <= 0) {
        $now = time();
    }
    if (!privacy_may_grant($actorLevel, $sessionOrg, $orgId)) {
        throw new RuntimeException('Only an organization admin can grant support access for their own organization.');
    }
    $reason = trim($reason);
    if (!privacy_reason_is_safe($reason)) {
        throw new RuntimeException('Enter a reason of 8 to 500 characters. Do not include a SIN, bank account, or long number.');
    }
    $exp = privacy_grant_expiry($hours, $now);
    if ($exp === null) {
        throw new RuntimeException('Support access must be between 1 and 72 hours.');
    }
    if (!privacy_table_exists($pdo, 'privacy_support_grants')) {
        throw new RuntimeException('Run migrations/20261117_privacy_safeguards.sql before granting support access.');
    }
    $starts = date('Y-m-d H:i:s', $now);
    $expires = date('Y-m-d H:i:s', $exp);
    $st = $pdo->prepare(
        'INSERT INTO privacy_support_grants
            (org_id, granted_by, reason, starts_at, expires_at, revoked_at, revoked_by, owner_notified_at, notify_note, created_at)
         VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, ?)'
    );
    $st->execute([$orgId, $actorId, $reason, $starts, $expires, $starts]);
    $id = (int)$pdo->lastInsertId();
    $orgName = '';
    try {
        $name = $pdo->prepare('SELECT org_name FROM organizations WHERE id = ?');
        $name->execute([$orgId]);
        $orgName = trim((string)$name->fetchColumn());
    } catch (Throwable $e) {
        $orgName = '';
    }
    $message = privacy_owner_message($orgName, $expires, $actorId, $reason);
    $notice = privacy_notify_owners($pdo, $orgId, $message, $sender);
    $note = substr($notice['note'], 0, 255);
    $notified = $notice['sent'] > 0 ? date('Y-m-d H:i:s', $now) : null;
    $pdo->prepare('UPDATE privacy_support_grants SET owner_notified_at = ?, notify_note = ? WHERE id = ?')
        ->execute([$notified, $note, $id]);
    privacy_log_event($pdo, 'grant', 'privacy_support', $orgId, $id);
    $grant = privacy_active_grant($pdo, $orgId, $now);
    if (!$grant) {
        throw new RuntimeException('The support grant was not saved.');
    }
    return $grant;
}

function privacy_revoke_grant(PDO $pdo, int $grantId, int $actorId, int $actorLevel, int $sessionOrg, int $now = 0): void
{
    if ($now <= 0) {
        $now = time();
    }
    if (!privacy_table_exists($pdo, 'privacy_support_grants')) {
        throw new RuntimeException('Run migrations/20261117_privacy_safeguards.sql first.');
    }
    $st = $pdo->prepare('SELECT * FROM privacy_support_grants WHERE id = ?');
    $st->execute([$grantId]);
    $grant = $st->fetch(PDO::FETCH_ASSOC);
    if (!$grant) {
        throw new RuntimeException('That support grant was not found.');
    }
    $orgId = (int)$grant['org_id'];
    $isOwner = privacy_may_grant($actorLevel, $sessionOrg, $orgId);
    $isPlatform = $actorLevel <= 10;
    if (!$isOwner && !$isPlatform) {
        throw new RuntimeException('You cannot revoke support access for that organization.');
    }
    if (!empty($grant['revoked_at'])) {
        return;
    }
    $pdo->prepare('UPDATE privacy_support_grants SET revoked_at = ?, revoked_by = ? WHERE id = ?')
        ->execute([date('Y-m-d H:i:s', $now), $actorId, $grantId]);
    privacy_log_event($pdo, 'revoke', 'privacy_support', $orgId, $grantId);
}

function privacy_key_bytes(string $key): string
{
    $key = trim($key);
    if ($key === '' || $key === PAYROLL_DATA_KEY_PLACEHOLDER) {
        throw new RuntimeException('The data key is missing. Set PAYROLL_DATA_KEY outside the web root.');
    }
    if (strlen($key) < 16) {
        throw new RuntimeException('The data key is too short.');
    }
    $decoded = base64_decode($key, true);
    if ($decoded !== false && strlen($decoded) === 32) {
        return $decoded;
    }
    return hash('sha256', $key, true);
}

function privacy_data_key(): string
{
    $fromEnv = getenv('PAYROLL_DATA_KEY');
    if (is_string($fromEnv) && trim($fromEnv) !== '' && trim($fromEnv) !== PAYROLL_DATA_KEY_PLACEHOLDER) {
        return trim($fromEnv);
    }
    $path = payroll_resolve_env_file(payroll_app_root());
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('PAYROLL_DATA_KEY is not set. Put it in the private env file outside the web root.');
    }
    $cfg = payroll_parse_env((string)file_get_contents($path));
    $key = trim((string)($cfg['PAYROLL_DATA_KEY'] ?? ''));
    if ($key === '' || $key === PAYROLL_DATA_KEY_PLACEHOLDER) {
        throw new RuntimeException('PAYROLL_DATA_KEY is not set. Put it in the private env file outside the web root.');
    }
    return $key;
}

function privacy_is_encrypted(string $stored): bool
{
    return str_starts_with($stored, PRIVACY_ENC_PREFIX);
}

function privacy_encrypt(string $plain, string $key): string
{
    if ($plain === '') {
        return '';
    }
    if (privacy_is_encrypted($plain)) {
        return $plain;
    }
    $raw = privacy_key_bytes($key);
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $raw, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false || strlen($tag) !== 16) {
        throw new RuntimeException('Encryption failed.');
    }
    return PRIVACY_ENC_PREFIX . base64_encode($iv . $tag . $ct);
}

function privacy_decrypt(string $stored, string $key): string
{
    if ($stored === '' || !privacy_is_encrypted($stored)) {
        return $stored;
    }
    $raw = base64_decode(substr($stored, strlen(PRIVACY_ENC_PREFIX)), true);
    if ($raw === false || strlen($raw) < 29) {
        throw new RuntimeException('Stored value could not be read.');
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct = substr($raw, 28);
    $plain = openssl_decrypt($ct, 'aes-256-gcm', privacy_key_bytes($key), OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        throw new RuntimeException('Stored value could not be read with the current data key.');
    }
    return $plain;
}

function privacy_open_sensitive(string $stored, ?string $key = null): string
{
    if ($stored === '' || !privacy_is_encrypted($stored)) {
        return $stored;
    }
    return privacy_decrypt($stored, $key ?? privacy_data_key());
}

function privacy_mask_account(string $plain): string
{
    $digits = preg_replace('/\D+/', '', $plain) ?? '';
    if ($digits === '') {
        return '';
    }
    $keep = substr($digits, -4);
    $hidden = max(0, strlen($digits) - 4);
    return str_repeat('•', $hidden) . $keep;
}

function privacy_mask_sin(string $plain): string
{
    $digits = preg_replace('/\D+/', '', $plain) ?? '';
    if ($digits === '') {
        return '';
    }
    return '***-***-' . substr($digits, -3);
}

function privacy_classify_stored_value(string $value): string
{
    if ($value === '') {
        return 'empty';
    }
    if (privacy_is_encrypted($value)) {
        return 'encrypted';
    }
    return 'plaintext';
}

/**
 * Column => mask kind. The encrypt script intersects this with the live table.
 *
 * @return array<string,array<string,string>>
 */
function privacy_sensitive_column_map(): array
{
    return [
        'users' => [
            'bank_account' => 'account',
            'bank_transit' => 'account',
            'bank_institution' => 'account',
            'sin' => 'sin',
            'SIN' => 'sin',
            'social_insurance' => 'sin',
            'social_insurance_number' => 'sin',
            'sin_number' => 'sin',
        ],
    ];
}
