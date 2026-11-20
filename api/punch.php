<?php
/**
 * Path: /api/punch.php
 * Method: POST
 * Headers: Authorization: Bearer <token>
 * Body: {
 *   "action": "clock_in|clock_out|break_start|break_end|transfer_out|transfer_in",
 *   "site_id": 5,
 *   "lat": 53.5461,
 *   "lng": -113.4938,
 *   "image": "data:image/jpeg;base64,...",   // selfie - optional but recommended
 *   "flag_reason": "reason string"           // required when flagged
 * }
 *
 * Returns:
 *   { "success": true, "mode": "WORKING", "message": "Clocked in at Site Name" }
 *   { "success": false, "error": "...", "requires_reason": true, "geo_fail": true, "sched_fail": false }
 *   { "success": false, "error": "...", "blocked": true, "block_type": "geofence|schedule" }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../db.php';
require_once __DIR__ . '/../engine/attendance_audit_lib.php';
require_once __DIR__ . '/../engine/org_modules_lib.php';
require_once 'jwt_helper.php';

$auth   = require_auth();
$uid    = (int)$auth['user_id'];
$org_id = (int)$auth['org_id'];

$body    = json_decode(file_get_contents('php://input'), true);
$action  = trim($body['action']  ?? '');
$site_id = (int)($body['site_id'] ?? 0);
$lat     = $body['lat'] ?? 0;
$lng     = $body['lng'] ?? 0;
$image   = $body['image']       ?? '';
$reason  = trim($body['flag_reason'] ?? '');

$valid_actions = ['clock_in', 'clock_out', 'break_start', 'break_end', 'transfer_out', 'transfer_in'];
if (!in_array($action, $valid_actions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

// -------------------------------------------------------------------------
// DISTANCE HELPER
// -------------------------------------------------------------------------
function getDistance($lat1, $lng1, $lat2, $lng2) {
    if (empty($lat1) || empty($lng1) || empty($lat2) || empty($lng2)) return null;
    $lat1=(float)$lat1; $lng1=(float)$lng1; $lat2=(float)$lat2; $lng2=(float)$lng2;
    $R = 6371000;
    $dLat = deg2rad($lat2-$lat1); $dLon = deg2rad($lng2-$lng1);
    $a = sin($dLat/2)*sin($dLat/2) + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)*sin($dLon/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

try {
    // -------------------------------------------------------------------------
    // TIMEZONE RESOLVER (mirrors process_punch.php exactly)
    // -------------------------------------------------------------------------
    $liveStmt = $pdo->prepare("SELECT id, site_id FROM attendance WHERE user_id = ? AND (clock_out IS NULL OR clock_out = '0000-00-00 00:00:00') ORDER BY id DESC LIMIT 1");
    $liveStmt->execute([$uid]);
    $live = $liveStmt->fetch(PDO::FETCH_ASSOC);

    $resolved_timezone = 'America/Edmonton';
    $tz_site_id = 0;
    if (in_array($action, ['clock_in','transfer_in','clock_out']) && $site_id > 0) {
        $tz_site_id = $site_id;
    } elseif ($live && (int)$live['site_id'] > 0) {
        $tz_site_id = (int)$live['site_id'];
    }
    if ($tz_site_id > 0) {
        $tzStmt = $pdo->prepare("SELECT Timezone FROM sites WHERE Sid = ? LIMIT 1");
        $tzStmt->execute([$tz_site_id]);
        $tzRow = $tzStmt->fetch();
        if ($tzRow && !empty(trim($tzRow['Timezone']))) $resolved_timezone = trim($tzRow['Timezone']);
    }
    date_default_timezone_set($resolved_timezone);
    $now = date('Y-m-d H:i:s');

    // -------------------------------------------------------------------------
    // WAGE SNAPSHOT
    // -------------------------------------------------------------------------
    $wStmt = $pdo->prepare("SELECT hourly_rate, official_rate FROM users WHERE id = ? LIMIT 1");
    $wStmt->execute([$uid]);
    $wages = $wStmt->fetch(PDO::FETCH_ASSOC);
    $hourly   = floatval($wages['hourly_rate']   ?? 0);
    $official = floatval($wages['official_rate'] ?? 0);

    // -------------------------------------------------------------------------
    // CLOCK IN
    // -------------------------------------------------------------------------
    if ($action === 'clock_in') {
        if ($site_id === 0) {
            echo json_encode(['success' => false, 'error' => 'Site required for clock in']);
            exit;
        }

        // Load site geometry
        $siteStmt = $pdo->prepare("SELECT target_lat, target_lng, geo_radius_meters, nick_name FROM sites WHERE Sid = ? LIMIT 1");
        $siteStmt->execute([$site_id]);
        $siteGeom = $siteStmt->fetch(PDO::FETCH_ASSOC);
        $max_r = (float)($siteGeom['geo_radius_meters'] ?? 200);
        $site_name = $siteGeom['nick_name'] ?? 'Site';

        // Load org-level policies
        $geo_policy      = 'allow_reason';
        $sched_policy    = sip_org_restrain_clockin($pdo, $org_id) ? 'block' : 'allow_reason';
        $geo_tolerance   = 1.00;
        $sched_margin    = sip_org_schedule_margin_minutes($pdo, $org_id);

        $gemStmt = $pdo->prepare("SELECT setting_key, setting_value FROM Gem_Settings WHERE org_id = ?");
        $gemStmt->execute([$org_id]);
        $gemSettings = $gemStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if (isset($gemSettings['global_geo_policy']))        $geo_policy    = trim($gemSettings['global_geo_policy']);
        if (isset($gemSettings['global_schedule_policy']))   $sched_policy  = trim($gemSettings['global_schedule_policy']);
        if (isset($gemSettings['global_geo_tolerance_pct'])) $geo_tolerance = (float)$gemSettings['global_geo_tolerance_pct'];

        // Site-level overrides
        $siteSetStmt = $pdo->prepare("SELECT setting_key, setting_value FROM Site_Settings WHERE site_id = ?");
        $siteSetStmt->execute([$site_id]);
        $siteSettings = $siteSetStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if (isset($siteSettings['site_geo_policy']))        $geo_policy   = trim($siteSettings['site_geo_policy']);
        if (isset($siteSettings['site_schedule_policy']))   $sched_policy = trim($siteSettings['site_schedule_policy']);
        if (isset($siteSettings['site_geo_tolerance_pct'])) $geo_tolerance = (float)$siteSettings['site_geo_tolerance_pct'];

        // --- SCHEDULE CHECK ---
        $requires_sched_reason = false;
        $block_sched = false;
        $sched_why = '';
        if ($sched_policy !== 'allow_free') {
            $sched_status = sip_employee_schedule_status($pdo, $uid, $site_id, $sched_margin, $now);
            if (!empty($sched_status['outside_window'])) {
                $sched_why = (string)($sched_status['reason'] ?? 'not_scheduled');
                if ($sched_policy === 'block')         $block_sched = true;
                elseif ($sched_policy === 'allow_reason') $requires_sched_reason = true;
            }
        }

        if ($block_sched) {
            $err = 'Clock-in blocked: you are not on the schedule at this site.';
            if ($sched_why === 'early') $err = 'Clock-in blocked: you are early for this shift.';
            if ($sched_why === 'late')  $err = 'Clock-in blocked: you are past the scheduled window.';
            echo json_encode([
                'success'    => false,
                'blocked'    => true,
                'block_type' => 'schedule',
                'sched_why'  => $sched_why,
                'error'      => $err
            ]);
            exit;
        }

        // --- GEO CHECK ---
        $requires_geo_reason = false;
        $block_geo = false;
        $actual_dist = null;
        if ($geo_policy !== 'allow_free' && $siteGeom) {
            $actual_dist = getDistance($lat, $lng, $siteGeom['target_lat'], $siteGeom['target_lng']);
            if ($actual_dist !== null) {
                $boundary = $max_r * (1 + ($geo_tolerance / 100));
                if ($actual_dist > $boundary) {
                    if ($geo_policy === 'block')           $block_geo = true;
                    elseif ($geo_policy === 'allow_reason') $requires_geo_reason = true;
                }
            }
        }

        if ($block_geo) {
            echo json_encode([
                'success'    => false,
                'blocked'    => true,
                'block_type' => 'geofence',
                'distance'   => round($actual_dist),
                'allowed_radius' => $max_r,
                'error'      => 'Clock-in blocked: you are ' . round($actual_dist) . 'm from site (max ' . $max_r . 'm).'
            ]);
            exit;
        }

        // --- REASON REQUIRED? ---
        $is_flagged = 0;
        if ($requires_sched_reason || $requires_geo_reason) {
            $is_flagged = 1;
            if (empty($reason)) {
                echo json_encode([
                    'success'          => false,
                    'requires_reason'  => true,
                    'geo_fail'         => $requires_geo_reason,
                    'sched_fail'       => $requires_sched_reason,
                    'sched_why'        => $sched_why,
                    'distance'         => $actual_dist ? round($actual_dist) : null,
                    'error'            => 'Override reason required before clocking in.'
                ]);
                exit;
            }
        }

        $ins = $pdo->prepare("INSERT INTO attendance (org_id, user_id, site_id, clock_in, status, transfer_status, lat_in, lng_in, selfie_in, is_flagged, flag_reason, hourly_rate, official_rate) VALUES (?, ?, ?, ?, '0', 'none', ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([$org_id, $uid, $site_id, $now, $lat, $lng, $image, $is_flagged, $reason, $hourly, $official]);
        aa_log($pdo, 'create', (int)$pdo->lastInsertId(), null, null, 'Clock in');

        echo json_encode(['success' => true, 'mode' => 'WORKING', 'message' => "Clocked in at $site_name", 'timestamp' => $now, 'is_flagged' => (bool)$is_flagged]);
        exit;
    }

    // -------------------------------------------------------------------------
    // ALL OTHER ACTIONS — require an open shift
    // -------------------------------------------------------------------------
    if (!$live) {
        echo json_encode(['success' => false, 'error' => 'No open shift found']);
        exit;
    }

    if ($action === 'clock_out') {
        $aaId = (int)$live['id'];
        $aaBefore = aa_snapshot($pdo, $aaId);
        $upd = $pdo->prepare("UPDATE attendance SET clock_out=?, status='4', lat_out=?, lng_out=?, selfie_out=?, hourly_rate=?, official_rate=? WHERE id=?");
        $upd->execute([$now, $lat, $lng, $image, $hourly, $official, $live['id']]);
        aa_log($pdo, 'update', $aaId, $aaBefore, null, 'Clock out');
        echo json_encode(['success' => true, 'mode' => 'OFF_CLOCK', 'message' => 'Clocked out', 'timestamp' => $now]);

    } elseif ($action === 'transfer_out') {
        $old_site_id = (int)$live['site_id'];
        // Close current shift
        $aaId = (int)$live['id'];
        $aaBefore = aa_snapshot($pdo, $aaId);
        $upd = $pdo->prepare("UPDATE attendance SET clock_out=?, status='4', lat_out=?, lng_out=?, selfie_out=?, hourly_rate=?, official_rate=? WHERE id=?");
        $upd->execute([$now, $lat, $lng, $image, $hourly, $official, $live['id']]);
        aa_log($pdo, 'update', $aaId, $aaBefore, null, 'Transfer out');
        // Open travel row
        $ins = $pdo->prepare("INSERT INTO attendance (org_id, user_id, site_id, clock_in, status, transfer_status, lat_in, lng_in, selfie_in, hourly_rate, official_rate) VALUES (?, ?, ?, ?, '5', 'travel', ?, ?, ?, ?, ?)");
        $ins->execute([$org_id, $uid, $old_site_id, $now, $lat, $lng, $image, $hourly, $official]);
        aa_log($pdo, 'create', (int)$pdo->lastInsertId(), null, null, 'Travel row');
        echo json_encode(['success' => true, 'mode' => 'TRANSIT', 'message' => 'Transferred out — select destination site', 'timestamp' => $now]);

    } elseif ($action === 'transfer_in') {
        if ($site_id === 0) {
            echo json_encode(['success' => false, 'error' => 'Destination site required for transfer in']);
            exit;
        }
        // Get site names for route string
        $origStmt = $pdo->prepare("SELECT nick_name FROM sites WHERE Sid = ? LIMIT 1");
        $origStmt->execute([(int)$live['site_id']]);
        $origin_name = $origStmt->fetchColumn() ?: 'Origin';

        $destStmt = $pdo->prepare("SELECT nick_name FROM sites WHERE Sid = ? LIMIT 1");
        $destStmt->execute([$site_id]);
        $dest_name = $destStmt->fetchColumn() ?: 'Destination';
        $route = "$origin_name → $dest_name";

        // Close travel row
        $aaId = (int)$live['id'];
        $aaBefore = aa_snapshot($pdo, $aaId);
        $upd = $pdo->prepare("UPDATE attendance SET clock_out=?, status='5', transfer_status=?, lat_out=?, lng_out=?, selfie_out=?, hourly_rate=?, official_rate=? WHERE id=?");
        $upd->execute([$now, $route, $lat, $lng, $image, $hourly, $official, $live['id']]);
        aa_log($pdo, 'update', $aaId, $aaBefore, null, 'Transfer in');
        // Open new shift at destination
        $ins = $pdo->prepare("INSERT INTO attendance (org_id, user_id, site_id, clock_in, status, transfer_status, lat_in, lng_in, selfie_in, hourly_rate, official_rate) VALUES (?, ?, ?, ?, '0', 'none', ?, ?, ?, ?, ?)");
        $ins->execute([$org_id, $uid, $site_id, $now, $lat, $lng, $image, $hourly, $official]);
        aa_log($pdo, 'create', (int)$pdo->lastInsertId(), null, null, 'Transfer in new shift');
        echo json_encode(['success' => true, 'mode' => 'WORKING', 'message' => "Arrived at $dest_name", 'timestamp' => $now, 'route' => $route]);

    } elseif ($action === 'break_start') {
        $aaId = (int)$live['id'];
        $aaBefore = aa_snapshot($pdo, $aaId);
        $upd = $pdo->prepare("UPDATE attendance SET status='1' WHERE id=?");
        $upd->execute([$live['id']]);
        aa_log($pdo, 'update', $aaId, $aaBefore, null, 'Break start');
        $ins = $pdo->prepare("INSERT INTO breaks (shift_id, break_start, start_selfie, start_lat, start_lng) VALUES (?, ?, ?, ?, ?)");
        $ins->execute([$live['id'], $now, $image, $lat, $lng]);
        echo json_encode(['success' => true, 'mode' => 'BREAK', 'message' => 'Break started', 'timestamp' => $now]);

    } elseif ($action === 'break_end') {
        $bStmt = $pdo->prepare("SELECT id FROM breaks WHERE shift_id=? AND break_end IS NULL ORDER BY id DESC LIMIT 1");
        $bStmt->execute([$live['id']]);
        $activeBreak = $bStmt->fetch();
        if ($activeBreak) {
            $upd = $pdo->prepare("UPDATE breaks SET break_end=?, end_selfie=?, end_lat=?, end_lng=? WHERE id=?");
            $upd->execute([$now, $image, $lat, $lng, $activeBreak['id']]);
        }
        $aaId = (int)$live['id'];
        $aaBefore = aa_snapshot($pdo, $aaId);
        $upd2 = $pdo->prepare("UPDATE attendance SET status='0' WHERE id=?");
        $upd2->execute([$live['id']]);
        aa_log($pdo, 'update', $aaId, $aaBefore, null, 'Break end');
        echo json_encode(['success' => true, 'mode' => 'WORKING', 'message' => 'Break ended — resumed work', 'timestamp' => $now]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
