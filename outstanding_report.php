<?php
/**
 * Path: /outstanding_report.php
 * What the organization still owes, one row per employee.
 * Pending rows of admin_outstanding_wages only. That ledger is not edited here.
 * Immediate demand is a separate list the same owners and admins can add,
 * edit, and remove. A platform superadmin sees nothing unless a
 * support-access grant names the org.
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
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD, POST');
    exit('This report does not accept that request.');
}

try {
    orpt_ensure_menu($pdo);
} catch (Throwable $e) {
    // The page still opens if the menu row cannot be added.
}

$menu = function_exists('generateMenu') ? generateMenu($pdo) : '';
if ((int)$decision['org_id'] <= 0) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(403);
        exit('Choose an organization before changing immediate demand.');
    }
    $orgs = orpt_org_names($pdo, $decision['choices']);
    echo orpt_choices_html($menu, $orgs);
    exit;
}

$orgId = (int)$decision['org_id'];
$store = (string)($_POST['store'] ?? $_GET['store'] ?? '');
$employee = (string)($_POST['employee'] ?? $_GET['employee'] ?? '');
if (strlen($store) > 200) {
    $store = '';
}
if (strlen($employee) > 40) {
    $employee = '';
}

$demandError = '';
$demandForm = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!function_exists('ps_require_csrf') || !function_exists('ps_csrf_token')) {
        http_response_code(500);
        exit('This form cannot be saved without the payroll security token.');
    }
    ps_require_csrf();
    $action = (string)($_POST['demand_action'] ?? '');
    try {
        orpt_ensure_demand($pdo);
        if ($action === 'remove') {
            orpt_demand_remove($pdo, $orgId, (int)($_POST['demand_id'] ?? 0));
            $_SESSION['orpt_demand_flash'] = 'Removed from immediate demand.';
            header('Location: ' . orpt_demand_href($orgId, $store, $employee, 0));
            exit;
        }
        if ($action !== 'save') {
            http_response_code(400);
            exit('That action is not available.');
        }
        $parsed = orpt_demand_input($_POST);
        $wageId = (int)($parsed['row']['wage_id'] ?? 0);
        if ($parsed['ok'] && $wageId > 0 && !orpt_demand_wage_in_org($pdo, $orgId, $wageId)) {
            $parsed['ok'] = false;
            $parsed['errors'][] = 'That outstanding wage row is not in this organization.';
        }
        if (!$parsed['ok']) {
            $demandError = implode(' ', $parsed['errors']);
            $demandForm = $parsed['form'];
        } else {
            orpt_demand_save($pdo, $orgId, (int)$user['id'], (int)$parsed['form']['id'], $parsed['row']);
            $_SESSION['orpt_demand_flash'] = (int)$parsed['form']['id'] > 0 ? 'Saved immediate demand.' : 'Added to immediate demand.';
            header('Location: ' . orpt_demand_href($orgId, $store, $employee, 0));
            exit;
        }
    } catch (RuntimeException $e) {
        $known = $e->getMessage();
        $demandError = strpos($known, 'not in this organization') !== false
            ? $known
            : 'Immediate demand could not be saved. Outstanding wages were not changed.';
        if ($demandForm === null && isset($parsed) && is_array($parsed['form'] ?? null)) {
            $demandForm = $parsed['form'];
        }
    } catch (Throwable $e) {
        $demandError = 'Immediate demand could not be saved. Outstanding wages were not changed.';
    }
}

try {
    $rows = orpt_fetch_pending($pdo, $orgId);
    $orgName = orpt_org_label($pdo, $orgId);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Outstanding wages could not be read. The ledger table has to be in place already. This page does not create or change it.');
}
$report = orpt_report($rows, $store, $employee);

$demandReady = false;
$demandRows = [];
try {
    orpt_ensure_demand($pdo);
    $demandRows = orpt_demand_fetch($pdo, $orgId);
    $demandReady = true;
} catch (Throwable $e) {
    if ($demandError === '') {
        $demandError = 'The immediate demand list is not ready. Outstanding wages were not changed.';
    }
}
if ($demandForm === null) {
    $editId = (int)($_GET['demand'] ?? 0);
    foreach ($demandRows as $demandRow) {
        if ($editId > 0 && (int)$demandRow['id'] === $editId) {
            $demandForm = orpt_demand_form_values($demandRow);
        }
    }
}
$flash = (string)($_SESSION['orpt_demand_flash'] ?? '');
unset($_SESSION['orpt_demand_flash']);
$demand = [
    'ready' => $demandReady,
    'rows' => array_map('orpt_demand_present', $demandRows),
    'totals' => orpt_demand_totals($demandRows),
    'wages' => orpt_demand_wage_options($rows),
    'edit' => $demandForm ?? orpt_demand_form_values(null),
    'error' => $demandError,
    'flash' => $flash,
    'csrf' => function_exists('ps_csrf_token') ? ps_csrf_token() : '',
];

if (($_GET['export'] ?? '') === 'xlsx' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        orpt_send_xlsx($orgId, $report, $demandRows);
    } catch (Throwable $e) {
        http_response_code(500);
        exit('The Excel file could not be built.');
    }
    exit;
}

echo orpt_report_html($menu, $orgId, $orgName, $report, $demand);
