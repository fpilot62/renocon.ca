<?php
/**
 * Path: /auto_break_before_finalize.php
 * Insert a missing org-2 Auto break, then continue into the existing finalizer.
 * A GET continues to finalize_shift.php. A POST continues into bulk_allocation_desk.php.
 * An error is logged and finalizing still proceeds.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/engine/auto_break_lib.php';
if (is_file(__DIR__ . '/engine/break_waiver_lib.php')) {
    require_once __DIR__ . '/engine/break_waiver_lib.php';
}

if (function_exists('bwr_collect_request_ids') && function_exists('bwr_pending_for_shifts')) {
    $bwrIds = bwr_collect_request_ids($_POST, $_GET);
    $bwrPending = bwr_pending_for_shifts($pdo, $bwrIds);
    if ($bwrPending !== []) {
        require __DIR__ . '/break_waiver_before_finalize.php';
        exit;
    }
}


try {
    $ids = [];
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!empty($_POST['record_ids_csv'])) {
            $ids = preg_split('/\s*,\s*/', (string)$_POST['record_ids_csv']) ?: [];
        }
        foreach (['shift_ids', 'ids', 'attendance_ids'] as $key) {
            if (empty($_POST[$key])) {
                continue;
            }
            $more = is_array($_POST[$key]) ? $_POST[$key] : preg_split('/\s*,\s*/', (string)$_POST[$key]);
            if (is_array($more)) {
                $ids = array_merge($ids, $more);
            }
        }
    } else {
        $ids[] = (int)($_GET['id'] ?? 0);
    }
    ab_ensure_shift_ids($pdo, $ids);
} catch (Throwable $e) {
    error_log('auto-break before finalize: ' . $e->getMessage());
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $next = __DIR__ . '/bulk_allocation_desk.php';
    if (is_file($next)) {
        require $next;
        exit;
    }
    http_response_code(500);
    exit('Bulk allocation is not available.');
}

$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: finalize_shift.php' . ($query !== '' ? '?' . $query : ''));
exit;
