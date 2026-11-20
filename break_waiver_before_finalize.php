<?php
/**
 * Path: /break_waiver_before_finalize.php
 * Block finalize when a no-break request is still pending. Otherwise apply
 * missing Auto breaks (same as auto_break_before_finalize.php) and continue
 * to finalize_shift.php (GET) or bulk_allocation_desk.php (POST).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login_kiosk.php?err=session_expired');
    exit;
}
require_once __DIR__ . '/db.php';
if (is_file(__DIR__ . '/engine/auto_break_lib.php')) {
    require_once __DIR__ . '/engine/auto_break_lib.php';
}
require_once __DIR__ . '/engine/break_waiver_lib.php';
if (is_file(__DIR__ . '/engine/menu_generator.php')) {
    require_once __DIR__ . '/engine/menu_generator.php';
}

$ids = bwr_collect_request_ids($_POST, $_GET);
$pending = bwr_pending_for_shifts($pdo, $ids);
$blocked = bwr_blocked_ids($pending);
$continue = array_values(array_filter($ids, static fn($id) => !in_array($id, $blocked, true)));
$force = isset($_POST['continue_unblocked']) && $_POST['continue_unblocked'] === '1';

if ($blocked !== [] && !$force) {
    $isPost = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
    bwr_send_finalize_blocked_page($pending, $continue, $isPost);
    exit;
}

$applyIds = $force ? $continue : $ids;
if (function_exists('ab_ensure_shift_ids') && $applyIds !== []) {
    try {
        ab_ensure_shift_ids($pdo, $applyIds);
    } catch (Throwable $e) {
        error_log('auto-break before finalize: ' . $e->getMessage());
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if ($force) {
        $_POST['record_ids_csv'] = implode(',', $continue);
    }
    $next = __DIR__ . '/bulk_allocation_desk.php';
    if (is_file($next)) {
        require $next;
        exit;
    }
    http_response_code(500);
    exit('Bulk allocation is also not available.');
}

$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: finalize_shift.php' . ($query !== '' ? '?' . $query : ''));
exit;
