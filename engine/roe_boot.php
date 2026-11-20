<?php
/**
 * Shared page start for roe.php and roe_edit.php.
 * Payroll admins only (user_level < 30). Org admins see their own org; a superadmin picks an org
 * and needs that org's active support grant (privacy_lib, data class 'sin', because exports carry SINs).
 * Sets $pdo, $orgId, $actorId, $isSuper, $orgs, $csrf.
 */
require_once __DIR__ . '/privacy_lib.php';
privacy_boot_web();
error_reporting(E_ALL);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../payroll_scope.php';
require_once __DIR__ . '/attendance_integrity_lib.php';
require_once __DIR__ . '/admin_super_lib.php';
require_once __DIR__ . '/roe_lib.php';
if (is_file(__DIR__ . '/menu_generator.php')) {
    require_once __DIR__ . '/menu_generator.php';
}
ps_require_payroll_admin();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    ps_require_csrf();
}
$actorId = (int)$_SESSION['user_id'];
$isSuper = ps_is_superadmin();
$orgs = [];
if ($isSuper) {
    try {
        $orgs = $pdo->query('SELECT id, org_name FROM organizations ORDER BY org_name ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $orgs = [];
    }
}
$requestedOrg = (int)($_POST['org_id'] ?? $_GET['org_id'] ?? 0);
if ($isSuper) {
    $allowed = array_flip(array_map(static fn($o) => (int)$o['id'], $orgs));
    $orgId = ($requestedOrg > 0 && isset($allowed[$requestedOrg])) ? $requestedOrg : (isset($allowed[2]) ? 2 : (int)($orgs[0]['id'] ?? 0));
} else {
    $orgId = ps_org_id();
    if ($requestedOrg > 0 && $requestedOrg !== $orgId) {
        http_response_code(403);
        exit('That organization is outside your access.');
    }
}
if ($orgId <= 0) {
    exit('No organization is selected.');
}
$roePrivacyAction = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')
    ? ((($_POST['action'] ?? '') === 'download') ? 'export' : 'update')
    : 'view';
privacy_require_business($pdo, $orgId, 'sin', basename((string)($_SERVER['SCRIPT_NAME'] ?? 'roe.php')), false, 20, $roePrivacyAction);
if (!roe_storage_ready($pdo)) {
    http_response_code(503);
    exit('ROE storage is not set up. Run migrations/20261119_roe.sql (see ops/DEPLOY_ROE.md).');
}
try {
    admin_super_ensure_menu($pdo, 'ROE (Record of Employment)', 'roe.php');
} catch (Throwable $e) {
    // The page still opens by URL if the menu row cannot be added.
}
$csrf = ps_csrf_token();

function roe_flash(string $msg, string $kind = 'info'): void
{
    $_SESSION['roe_flash'][] = [$kind, $msg];
}

function roe_flash_html(): string
{
    $out = '';
    foreach ((array)($_SESSION['roe_flash'] ?? []) as [$kind, $msg]) {
        $cls = $kind === 'error' ? 'danger' : ($kind === 'ok' ? 'success' : 'info');
        $out .= '<div class="alert alert-' . $cls . '">' . nl2br(roe_h($msg)) . '</div>';
    }
    unset($_SESSION['roe_flash']);
    return $out;
}

function roe_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/Edmonton')))->format('Y-m-d H:i:s');
}

function roe_page_head(string $title): void
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . roe_h($title) . '</title>'
        . '<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">'
        . '<style>body{font-family:"Segoe UI",sans-serif;background:#f1f5f9;padding:90px 16px 40px;color:#1e293b;font-size:13px}'
        . '.sheet{background:#fff;border:1px solid #cbd5e1;border-radius:12px;padding:16px;margin-bottom:16px}'
        . 'table.t th{background:#0f172a;color:#fff;font-size:11px;text-transform:uppercase}.money{text-align:right;font-variant-numeric:tabular-nums}'
        . '.st-draft{color:#92400e}.st-ready{color:#1d4ed8}.st-issued{color:#166534}.st-void,.st-amended{color:#64748b}'
        . '.warn li{color:#92400e}.err li{color:#b91c1c}</style></head><body>';
}
