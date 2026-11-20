<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll_scope.php';
require_once __DIR__ . '/payroll_engine/stat_holidays.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ps_require_payroll_admin();
// The session org is the one the user is working in. A stale top-bar cookie
// must not replace it. An explicit org_id on the link still wins.
if (isset($_GET['org_id']) && $_GET['org_id'] !== '' && $_GET['org_id'] !== 'all' && (int)$_GET['org_id'] > 0) {
    $orgId = (int)$_GET['org_id'];
} else {
    $orgId = ps_org_id();
}
header('Location: ' . sh_stat_pay_href($pdo, $orgId));
exit;
