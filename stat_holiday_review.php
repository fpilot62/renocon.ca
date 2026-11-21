<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll_scope.php';
require_once __DIR__ . '/payroll_engine/stat_holidays.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ps_require_payroll_admin();
// Top bar stores the org in sip_org_id and the URL, not in the session.
// The session value is the home org from login and must not replace the top bar.
$orgId = ps_topbar_org();
if ($orgId <= 0) {
    $orgId = sh_org_with_latest_pending_stat($pdo);
}
header('Location: ' . sh_stat_pay_href($pdo, $orgId));
exit;
