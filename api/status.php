<?php
/**
 * Path: /api/status.php
 * Method: GET
 * Headers: Authorization: Bearer <token>
 * Returns current shift mode, open breaks, elapsed time
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../db.php';
require_once 'jwt_helper.php';

$auth = require_auth();
$uid  = (int)$auth['user_id'];

try {
    // Get latest open shift
    $stmt = $pdo->prepare("SELECT a.id, a.status, a.site_id, a.clock_in, a.clock_out, a.transfer_status,
                                  s.nick_name as site_name, s.Timezone
                           FROM attendance a
                           LEFT JOIN sites s ON s.Sid = a.site_id
                           WHERE a.user_id = ?
                           ORDER BY (a.clock_out IS NULL OR TRIM(a.clock_out) = '' OR a.clock_out = '0000-00-00 00:00:00') DESC, a.id DESC
                           LIMIT 1");
    $stmt->execute([$uid]);
    $last = $stmt->fetch(PDO::FETCH_ASSOC);

    $is_shift_open = false;
    $current_attendance_id = 0;
    $db_status = '4';

    if ($last) {
        $co = trim((string)$last['clock_out']);
        if ($co === '' || $co === '0000-00-00 00:00:00') {
            $is_shift_open = true;
            $db_status = strtolower(trim((string)$last['status']));
            $current_attendance_id = (int)$last['id'];
        }
    }

    // Determine timezone and local time
    $tz = 'America/Edmonton';
    if ($last && !empty($last['Timezone'])) $tz = $last['Timezone'];
    $dtz = new DateTimeZone($tz);

    // Check open break
    $has_open_break = false;
    $open_break = null;
    if ($current_attendance_id > 0) {
        $bStmt = $pdo->prepare("SELECT id, break_start FROM breaks WHERE shift_id = ? AND break_end IS NULL ORDER BY id DESC LIMIT 1");
        $bStmt->execute([$current_attendance_id]);
        $open_break = $bStmt->fetch(PDO::FETCH_ASSOC);
        if ($open_break) $has_open_break = true;
    }

    // Determine mode (mirrors kiosk1.php logic)
    if ($has_open_break || $db_status === '1' || $db_status === 'on break') {
        $mode = 'BREAK';
    } elseif ($is_shift_open && ($db_status === '5' || $db_status === 'transfer-out' || $db_status === 'travel')) {
        $mode = 'TRANSIT';
    } elseif ($is_shift_open && ($db_status === '0' || $db_status === 'working' || $db_status === 'clocked in' || $db_status === '6' || $db_status === 'transfer-in')) {
        $mode = 'WORKING';
    } else {
        $mode = 'OFF_CLOCK';
    }

    // Get all breaks for current shift
    $breaks = [];
    if ($current_attendance_id > 0) {
        $allBreaks = $pdo->prepare("SELECT id, break_start, break_end FROM breaks WHERE shift_id = ? ORDER BY id ASC");
        $allBreaks->execute([$current_attendance_id]);
        $breaks = $allBreaks->fetchAll(PDO::FETCH_ASSOC);
    }

    // Clock-in timestamp for elapsed calculation
    $clock_in_ts = null;
    if ($last && $is_shift_open && !empty($last['clock_in'])) {
        $dt = new DateTime($last['clock_in'], $dtz);
        $clock_in_ts = $dt->getTimestamp();
    }

    echo json_encode([
        'success'              => true,
        'mode'                 => $mode,
        'attendance_id'        => $current_attendance_id,
        'site_id'              => $last ? (int)$last['site_id'] : 0,
        'site_name'            => $last ? ($last['site_name'] ?? '') : '',
        'clock_in'             => ($last && $is_shift_open) ? $last['clock_in'] : null,
        'clock_in_ts'          => $clock_in_ts,
        'transfer_status'      => $last ? $last['transfer_status'] : null,
        'timezone'             => $tz,
        'has_open_break'       => $has_open_break,
        'open_break_start'     => $open_break ? $open_break['break_start'] : null,
        'breaks'               => $breaks,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
