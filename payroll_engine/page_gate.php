<?php
/**
 * Org gate for the deduction pages. Same rules as the other payroll screens:
 * an organization admin stays in their own org, and a platform superadmin
 * needs the support grant that privacy_require_business already enforces.
 *
 * @return array{0:PDO,1:int,2:bool,3:int}
 */
function pe_page_org(string $page, string $action): array
{
    require_once dirname(__DIR__) . '/engine/privacy_lib.php';
    privacy_boot_web();
    require_once dirname(__DIR__) . '/db.php';
    require_once dirname(__DIR__) . '/engine/attendance_integrity_lib.php';
    if (is_file(dirname(__DIR__) . '/payroll_scope.php')) {
        require_once dirname(__DIR__) . '/payroll_scope.php';
    }
    if (is_file(dirname(__DIR__) . '/engine/orgscope_guard.php')) {
        require_once dirname(__DIR__) . '/engine/orgscope_guard.php';
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        privacy_require_csrf();
    }
    payroll_require_scope_admin();
    if (!isset($pdo) || !$pdo instanceof PDO) {
        http_response_code(500);
        exit('Database is not available.');
    }
    $actor = (int)($_SESSION['user_id'] ?? 0);
    $level = (int)($_SESSION['user_level'] ?? 99);
    $isSuper = function_exists('osg_is_superadmin') ? osg_is_superadmin() : ($level <= 10);
    $home = function_exists('osg_scope_org') ? (int)osg_scope_org() : (int)($_SESSION['org_id'] ?? 0);
    if (function_exists('ps_selected_org') && !$isSuper) {
        $home = (int)ps_selected_org($pdo);
    }
    $asked = (int)($_POST['org_id'] ?? $_GET['org_id'] ?? 0);
    if ($isSuper) {
        $orgId = $asked > 0 ? $asked : $home;
    } else {
        $orgId = $home;
        if ($asked > 0 && $asked !== $orgId) {
            http_response_code(403);
            exit('That organization is outside your access.');
        }
    }
    if ($orgId <= 0) {
        http_response_code(400);
        exit('No organization is selected.');
    }
    privacy_require_business($pdo, $orgId, 'pay', $page, false, 20, $action);
    return [$pdo, $orgId, $isSuper, $actor];
}
