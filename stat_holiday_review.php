<?php
require_once __DIR__ . '/payroll_scope.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ps_require_payroll_admin();
$raw = $_GET['org_id'] ?? ($_COOKIE['sip_org_id'] ?? '');
if ($raw === '' || $raw === 'all') {
    $raw = (string)ps_org_id();
}
$query = [
    'preview_period' => '1',
    'org_id' => (int)$raw,
];
if (!empty($_GET['from_date'])) {
    $query['from_date'] = (string)$_GET['from_date'];
}
if (!empty($_GET['to_date'])) {
    $query['to_date'] = (string)$_GET['to_date'];
}
header('Location: generate_stat_shifts.php?' . http_build_query($query));
exit;
