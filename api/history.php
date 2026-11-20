<?php
/**
 * Path: /api/history.php
 * Method: GET
 * Headers: Authorization: Bearer <token>
 * Query: ?limit=20&offset=0
 * Returns: recent shifts with breaks nested
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../db.php';
require_once 'jwt_helper.php';

$auth  = require_auth();
$uid   = (int)$auth['user_id'];
$limit  = min((int)($_GET['limit']  ?? 20), 100);
$offset = (int)($_GET['offset'] ?? 0);

try {
    $stmt = $pdo->prepare("
        SELECT a.id, a.site_id, a.clock_in, a.clock_out, a.status, a.transfer_status,
               a.lat_in, a.lng_in, a.lat_out, a.lng_out,
               a.is_flagged, a.flag_reason,
               a.hourly_rate, a.official_rate, a.approved_hours,
               a.travel_time_hours,
               s.nick_name as site_name
        FROM attendance a
        LEFT JOIN sites s ON s.Sid = a.site_id
        WHERE a.user_id = ?
        ORDER BY a.id DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$uid, $limit, $offset]);
    $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attach breaks to each shift
    foreach ($shifts as &$shift) {
        $bStmt = $pdo->prepare("SELECT id, break_start, break_end, start_lat, start_lng, end_lat, end_lng FROM breaks WHERE shift_id = ? ORDER BY id ASC");
        $bStmt->execute([$shift['id']]);
        $shift['breaks'] = $bStmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate worked duration (excluding breaks)
        $shift['duration_seconds'] = null;
        if (!empty($shift['clock_in']) && !empty($shift['clock_out']) && $shift['clock_out'] !== '0000-00-00 00:00:00') {
            $total = strtotime($shift['clock_out']) - strtotime($shift['clock_in']);
            $break_secs = 0;
            foreach ($shift['breaks'] as $b) {
                if (!empty($b['break_start']) && !empty($b['break_end'])) {
                    $break_secs += strtotime($b['break_end']) - strtotime($b['break_start']);
                }
            }
            $shift['duration_seconds'] = max(0, $total - $break_secs);
        }
    }

    echo json_encode(['success' => true, 'shifts' => $shifts, 'count' => count($shifts)]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
