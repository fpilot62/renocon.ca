<?php
/**
 * Path: /break_waiver_finalize_guard.php
 * Include after db.php (and session start when the page uses one).
 * If any targeted attendance shift has a pending no-break request, shows
 * "These shifts cannot be finalized: no-break request awaiting manager review"
 * and exits. Does nothing, and does not redirect, when none are pending.
 */
if (!isset($pdo) || !($pdo instanceof PDO)) {
    return;
}
if (is_file(__DIR__ . '/engine/auto_break_lib.php')) {
    require_once __DIR__ . '/engine/auto_break_lib.php';
}
if (function_exists('ab_feature_on') && !ab_feature_on()) {
    return;
}
if (!is_file(__DIR__ . '/engine/break_waiver_lib.php')) {
    return;
}
require_once __DIR__ . '/engine/break_waiver_lib.php';
if (!function_exists('bwr_collect_request_ids') || !function_exists('bwr_pending_for_shifts')) {
    return;
}
$bwrIds = bwr_collect_request_ids($_POST ?? [], $_GET ?? []);
if (!empty($bwrGuardIds) && is_array($bwrGuardIds)) {
    $bwrIds = array_merge($bwrIds, $bwrGuardIds);
}
$bwrPending = bwr_pending_for_shifts($pdo, $bwrIds);
if ($bwrPending === []) {
    return;
}
$bwrBlocked = bwr_blocked_ids($bwrPending);
$bwrContinue = array_values(array_filter($bwrIds, static fn($id) => !in_array($id, $bwrBlocked, true)));
$bwrIsPost = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
bwr_send_finalize_blocked_page($bwrPending, $bwrContinue, $bwrIsPost);
exit;
