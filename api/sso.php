<?php
/**
 * CrewPulse app → payroll website SSO.
 * POST JSON { next: "payroll_master_ledger.php" } + Bearer JWT
 *   → { success: true, url: "https://payroll.renocon.ca/api/sso.php?code=..." }
 * GET ?code=...
 *   → set PHP session like login_kiosk.php, redirect to next
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/jwt_helper.php';
require_once __DIR__ . '/../engine/login_auth_lib.php';
if (!function_exists('sip_sites_active_sql')) {
    require_once __DIR__ . '/../engine/site_status_lib.php';
}

function sso_code_dir(): string {
    $dir = sys_get_temp_dir() . '/crewpulse_sso';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function sso_allowed_next(int $role, string $next): ?string {
    $next = basename(trim($next));
    if (!preg_match('/^[a-z0-9_]+\.php$/i', $next)) {
        return null;
    }
    $admin = ['payroll_master_ledger.php', 'daily_report_view.php', 'payroll_report.php'];
    $sup = ['timeoff_requests.php', 'daily_report_view.php', 'staff_audit.php'];
    $staff = ['submit_timeoff.php'];
    if ($role <= 20) {
        $ok = array_merge($admin, $sup, $staff);
    } elseif ($role <= 30) {
        $ok = array_merge($sup, $staff);
    } else {
        $ok = $staff;
    }
    return in_array($next, $ok, true) ? $next : null;
}

function sso_start_session(PDO $pdo, array $user): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_full_name'] = $user['Name'] ?? '';
    $_SESSION['org_id'] = (int)$user['org_id'];
    $_SESSION['user_level'] = (int)$user['user_role'];
    $_SESSION['user_role'] = (int)$user['user_role'];
    $_SESSION['assigned_site_ids'] = $user['assigned_site_ids'] ?? '';

    $user_lvl = (int)$user['user_role'];
    $org_id = (int)$user['org_id'];
    $assigned = $user['assigned_site_ids'] ?? '';
    $active_sql = sip_sites_active_sql('');
    if ($user_lvl <= 10) {
        $_SESSION['access_scope'] = 'all';
        $site_stmt = $pdo->query("SELECT SId as id, nick_name FROM sites WHERE $active_sql ORDER BY nick_name ASC");
    } else {
        $_SESSION['access_scope'] = 'restricted';
        $clean_ids = preg_replace('/[^0-9,]/', '', $assigned);
        if (!empty($clean_ids)) {
            $sql = "SELECT SId as id, nick_name FROM sites WHERE org_id = ? AND SId IN ($clean_ids) AND $active_sql ORDER BY nick_name ASC";
        } else {
            $sql = "SELECT SId as id, nick_name FROM sites WHERE org_id = ? AND $active_sql ORDER BY nick_name ASC";
        }
        $site_stmt = $pdo->prepare($sql);
        $site_stmt->execute([$org_id]);
    }
    $allowed_sites = $site_stmt->fetchAll(PDO::FETCH_ASSOC);
    $_SESSION['inventory_sites'] = $allowed_sites;
    $_SESSION['active_rid'] = !empty($allowed_sites) ? $allowed_sites[0]['id'] : 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $payload = get_token_payload();
    if (!$payload || empty($payload['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    if (($payload['auth'] ?? 'password') === 'pin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'PIN login is for clock-in only']);
        exit;
    }
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];
    $uid = (int)$payload['user_id'];
    $st = $pdo->prepare("SELECT id, Name, user_role, org_id, assigned_site_ids, active FROM users WHERE id = ? LIMIT 1");
    $st->execute([$uid]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user || (int)$user['active'] !== 1) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    $role = (int)$user['user_role'];
    $next = sso_allowed_next($role, (string)($body['next'] ?? ''));
    if (!$next) {
        $dest = sip_post_login_url($pdo, $user);
        $next = basename(parse_url($dest, PHP_URL_PATH) ?: $dest);
        $next = sso_allowed_next($role, $next) ?: ($role <= 20 ? 'payroll_master_ledger.php' : ($role <= 30 ? 'daily_report_view.php' : 'submit_timeoff.php'));
    }
    $code = bin2hex(random_bytes(16));
    $file = sso_code_dir() . '/' . $code;
    file_put_contents($file, json_encode([
        'user_id' => $uid,
        'next' => $next,
        'exp' => time() + 90,
    ]));
    @chmod($file, 0600);
    echo json_encode([
        'success' => true,
        'url' => 'https://payroll.renocon.ca/api/sso.php?code=' . urlencode($code),
    ]);
    exit;
}

$code = preg_replace('/[^a-f0-9]/', '', (string)($_GET['code'] ?? ''));
$file = $code !== '' ? sso_code_dir() . '/' . $code : '';
$raw = ($file && is_file($file)) ? file_get_contents($file) : '';
if ($file && is_file($file)) {
    @unlink($file);
}
$data = $raw ? json_decode($raw, true) : null;
if (!$data || (int)($data['exp'] ?? 0) < time() || empty($data['user_id'])) {
    header('Location: /login_kiosk.php');
    exit;
}
$st = $pdo->prepare("SELECT id, Name, user_role, org_id, assigned_site_ids, active FROM users WHERE id = ? LIMIT 1");
$st->execute([(int)$data['user_id']]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user || (int)$user['active'] !== 1) {
    header('Location: /login_kiosk.php');
    exit;
}
$next = sso_allowed_next((int)$user['user_role'], (string)($data['next'] ?? '')) ?: 'submit_timeoff.php';
sso_start_session($pdo, $user);
header('Location: /' . $next);
exit;
