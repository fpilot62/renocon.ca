<?php
/**
 * Path: /outstanding_report.php
 * What the organization still owes, one row per employee.
 * Pending rows of admin_outstanding_wages only. Read only.
 * Owners and admins see their own org. A platform superadmin sees
 * nothing unless a support-access grant names the org.
 * Menu: with the other payroll admin links, Min. Role Admin (UserRole 20).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] === '' || $_SESSION['user_id'] === null) {
    header('Location: login_kiosk.php?err=session_expired');
    exit;
}

ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';
require_once 'payroll_scope.php';
require_once __DIR__ . '/engine/outstanding_report_lib.php';
require_once __DIR__ . '/engine/outstanding_report_view.php';
if (is_file(__DIR__ . '/engine/menu_generator.php')) {
    require_once __DIR__ . '/engine/menu_generator.php';
}

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$uid = (int)$_SESSION['user_id'];
$user = orpt_load_user($pdo, $uid);
if ($user === null) {
    http_response_code(403);
    exit('Your account could not be verified.');
}
$roleName = orpt_role_name($pdo, (int)($user['user_role'] ?? 0));
$requested = (int)($_GET['org_id'] ?? $_POST['org_id'] ?? 0);
$granted = [];
if (orpt_is_platform_superadmin((int)($user['user_role'] ?? 99))) {
    $granted = orpt_granted_org_ids($pdo, (int)$user['id'], $requested);
}
$decision = orpt_access_decision($user, $roleName, $requested, $granted);
if (!$decision['ok']) {
    if (!empty($decision['login'])) {
        header('Location: login_kiosk.php?err=session_expired');
        exit;
    }
    http_response_code((int)$decision['http']);
    exit($decision['error']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit('This report is read only.');
}

try {
    orpt_ensure_menu($pdo);
} catch (Throwable $e) {
    // The page still opens if the menu row cannot be added.
}

$menu = function_exists('generateMenu') ? generateMenu($pdo) : '';
if ((int)$decision['org_id'] <= 0) {
    $orgs = orpt_org_names($pdo, $decision['choices']);
    echo orpt_choices_html($menu, $orgs);
    exit;
}

$orgId = (int)$decision['org_id'];
try {
    $rows = orpt_fetch_pending($pdo, $orgId);
    $orgName = orpt_org_label($pdo, $orgId);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Outstanding wages could not be read. The ledger table has to be in place already. This page does not create or change it.');
}

$store = (string)($_GET['store'] ?? '');
$employee = (string)($_GET['employee'] ?? '');
if (strlen($store) > 200) {
    $store = '';
}
if (strlen($employee) > 40) {
    $employee = '';
}
$report = orpt_report($rows, $store, $employee);

if (($_GET['export'] ?? '') === 'xlsx') {
    try {
        orpt_send_xlsx($orgId, $report);
    } catch (Throwable $e) {
        http_response_code(500);
        exit('The Excel file could not be built.');
    }
    exit;
}

echo orpt_report_html($menu, $orgId, $orgName, $report);
