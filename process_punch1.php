<?php
/**
 * Path: /process_punch1.php (SANDBOX TESTING ENGINE)
 * Logic: Core SaaS Processing Engine with Dynamic Referer Page Redirection Targets
 * Schema Sync: Plural 'Gem_Schedules', 'employee_id' employee column, 'RId' site column, 'flag_reason', 'is_flagged'
 * Rate Snapshot Sandbox: Locks 'hourly_rate' and 'official_rate' fields during test punch loops
 * Timezone Alignment Patch: Secures uniform timezone resolution across ALL action pathways
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';
require_once __DIR__ . '/engine/org_modules_lib.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) { die("Access Denied."); }

$uid     = (int)$_SESSION['user_id'];
$action  = $_POST['action'] ?? '';
$site_id = (int)($_POST['site_id'] ?? 0);
$lat     = $_POST['lat'] ?? 0;
$lng     = $_POST['lng'] ?? 0;
$image   = $_POST['image'] ?? ''; 
$org_id  = (int)($_SESSION['org_id'] ?? 0);

if (!function_exists('getDistance')) {
    function getDistance($lat1, $lng1, $lat2, $lng2) {
        if (empty($lat1) || empty($lng1) || empty($lat2) || empty($lng2)) return null;
        $lat1 = (float)$lat1; $lng1 = (float)$lng1;
        $lat2 = (float)$lat2; $lng2 = (float)$lng2;
        $earth_radius = 6371000; 
        $dLat = deg2rad($lat2 - $lat1); $dLon = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
        return $earth_radius * (2 * atan2(sqrt($a), sqrt(1-$a)));
    }
}

// -------------------------------------------------------------------------
// 🕒 CRITICAL: UNIFIED TIMEZONE ENGINE RESOLVER (ABSOLUTE TOP BOUNDARY)
// -------------------------------------------------------------------------
// 1. Immediately look up if there is an active open shift record in play for this user
$stmt = $pdo->prepare("SELECT id, site_id FROM attendance WHERE user_id = ? AND (clock_out IS NULL OR clock_out = '0000-00-00 00:00:00') ORDER BY id DESC LIMIT 1");
$stmt->execute([$uid]);
$live = $stmt->fetch();

$resolved_timezone = 'America/Edmonton'; // Default Fallback Baseline
$target_timezone_site_id = 0;

// 2. Identify the active location contextual anchor safely
if (($action === 'clock_in' || $action === 'transfer_in' || $action === 'clock_out') && $site_id > 0) {
    $target_timezone_site_id = $site_id; // Use direct form input selection if available
} elseif ($live && (int)$live['site_id'] > 0) {
    $target_timezone_site_id = (int)$live['site_id']; // Otherwise anchor to the active open database row context
}

// 3. Extract the physical string definition mapping parameters from database
if ($target_timezone_site_id > 0) {
    $siteTimeStmt = $pdo->prepare("SELECT Timezone FROM sites WHERE Sid = ? LIMIT 1");
    $siteTimeStmt->execute([$target_timezone_site_id]);
    $siteRow = $siteTimeStmt->fetch();
    if ($siteRow && !empty(trim($siteRow['Timezone']))) {
        $resolved_timezone = trim($siteRow['Timezone']);
    }
}

// 4. Force global environment calibration profile before initializing time strings
date_default_timezone_set($resolved_timezone);
$now = date('Y-m-d H:i:s');
// -------------------------------------------------------------------------

// -------------------------------------------------------------------------
// 💰 SANDBOX USER WAGE PROFILE PARSER
// -------------------------------------------------------------------------
$wageStmt = $pdo->prepare("SELECT hourly_rate, official_rate FROM users WHERE id = ? LIMIT 1");
$wageStmt->execute([$uid]);
$userWages = $wageStmt->fetch(PDO::FETCH_ASSOC);

$current_hourly   = floatval($userWages['hourly_rate'] ?? 0.00);
$current_official = floatval($userWages['official_rate'] ?? 0.00);
// -------------------------------------------------------------------------

// TRACK THE SENDER: stay on kiosk.php or kiosk1.php (login lands on kiosk.php)
$kiosk_page = sip_kiosk_return_page();

try {
    if ($action == 'clock_in') {
        // =========================================================================
        // 🛡️ MULTI-TENANT RULE MAP WATERFALL
        // =========================================================================
        $geo_policy        = 'allow_reason';
        $schedule_policy   = sip_org_restrain_clockin($pdo, $org_id) ? 'block' : 'allow_reason';
        $geo_tolerance_pct = 1.00;
        $max_r             = 200.00;
        $sched_margin      = sip_org_schedule_margin_minutes($pdo, $org_id);

        $siteGeomStmt = $pdo->prepare("SELECT target_lat, target_lng, geo_radius_meters FROM sites WHERE Sid = ? LIMIT 1");
        $siteGeomStmt->execute([$site_id]);
        $siteGeom = $siteGeomStmt->fetch();
        if ($siteGeom) { $max_r = (float)($siteGeom['geo_radius_meters'] ?? 200.00); }

        $gemStmt = $pdo->prepare("SELECT setting_key, setting_value FROM Gem_Settings WHERE org_id = ?");
        $gemStmt->execute([$org_id]);
        $gemSettings = $gemStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        if (isset($gemSettings['global_geo_policy']))        $geo_policy        = trim($gemSettings['global_geo_policy']);
        if (isset($gemSettings['global_schedule_policy']))   $schedule_policy   = trim($gemSettings['global_schedule_policy']);
        if (isset($gemSettings['global_geo_tolerance_pct'])) $geo_tolerance_pct = (float)$gemSettings['global_geo_tolerance_pct'];

        $siteSettingsStmt = $pdo->prepare("SELECT setting_key, setting_value FROM Site_Settings WHERE site_id = ?");
        $siteSettingsStmt->execute([$site_id]);
        $siteSettings = $siteSettingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        if (isset($siteSettings['site_geo_policy']))        $geo_policy        = trim($siteSettings['site_geo_policy']);
        if (isset($siteSettings['site_schedule_policy']))   $schedule_policy   = trim($siteSettings['site_schedule_policy']);
        if (isset($siteSettings['site_geo_tolerance_pct']))  $geo_tolerance_pct = (float)$siteSettings['site_geo_tolerance_pct'];

        $reason       = trim((string)($_POST['flag_reason'] ?? ''));

        // =========================================================================
        // 🕒 COMPLIANCE MATRIX: SHIFT MATRIX SCHEDULES
        // =========================================================================
        $requires_sched_reason = false;
        $block_sched_execution = false;
        $sched_why = '';

        if ($schedule_policy !== 'allow_free') {
            $sched_status = sip_employee_schedule_status($pdo, $uid, $site_id, $sched_margin, $now);
            if (!empty($sched_status['outside_window'])) {
                $sched_why = (string)($sched_status['reason'] ?? 'not_scheduled');
                if ($schedule_policy === 'block') { $block_sched_execution = true; }
                elseif ($schedule_policy === 'allow_reason') { $requires_sched_reason = true; }
            }
        }

        if ($block_sched_execution) {
            header("Location: " . $kiosk_page . "?error_alert=schedule_blocked&sched_why=" . urlencode($sched_why) . "&site_id=" . $site_id);
            exit;
        }

        // =========================================================================
        // 📍 COMPLIANCE MATRIX: RADIUS GEOFENCING
        // =========================================================================
        $requires_geo_reason = false;
        $block_geo_execution = false;
        $actual_dist         = null;

        if ($geo_policy !== 'allow_free' && $siteGeom) {
            $actual_dist = getDistance($lat, $lng, $siteGeom['target_lat'] ?? 0, $siteGeom['target_lng'] ?? 0);
            if ($actual_dist !== null) {
                $tolerance_factor = $geo_tolerance_pct / 100;
                $allowed_perimeter_boundary = $max_r * (1 + $tolerance_factor);
                
                if ($actual_dist > $allowed_perimeter_boundary) {
                    if ($geo_policy === 'block') { $block_geo_execution = true; } 
                    elseif ($geo_policy === 'allow_reason') { $requires_geo_reason = true; }
                }
            }
        }

        if ($block_geo_execution) {
            header("Location: " . $kiosk_page . "?error_alert=geofence_blocked&distance=" . round($actual_dist) . "&site_id=" . $site_id);
            exit;
        }

        // =========================================================================
        // 🛑 AUTHORIZE STORAGE MAPPING
        // =========================================================================
        $is_flagged_trigger = 0;

        if ($requires_sched_reason || $requires_geo_reason) {
            $is_flagged_trigger = 1; 
            if (empty($reason)) {
                header("Location: " . $kiosk_page . "?prompt_reason=1&site_id=" . $site_id . "&geo_fail=" . ($requires_geo_reason ? 1:0) . "&sched_fail=" . ($requires_sched_reason ? 1:0) . "&sched_why=" . urlencode($sched_why));
                exit;
            }
        }

        $ins = $pdo->prepare("INSERT INTO attendance (org_id, user_id, site_id, clock_in, status, transfer_status, lat_in, lng_in, selfie_in, is_flagged, flag_reason, hourly_rate, official_rate) VALUES (?, ?, ?, ?, '0', 'none', ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([$org_id, $uid, $site_id, $now, $lat, $lng, $image, $is_flagged_trigger, $reason, $current_hourly, $current_official]);

    } elseif ($action == 'clock_out' && $live) {
        $upd = $pdo->prepare("UPDATE attendance SET clock_out = ?, status = '4', lat_out = ?, lng_out = ?, selfie_out = ?, hourly_rate = ?, official_rate = ? WHERE id = ?");
        $upd->execute([$now, $lat, $lng, $image, $current_hourly, $current_official, $live['id']]);

        if (is_file(__DIR__ . '/engine/break_waiver_lib.php')) {
            require_once __DIR__ . '/engine/break_waiver_lib.php';
            if (is_file(__DIR__ . '/engine/auto_break_lib.php')) {
                require_once __DIR__ . '/engine/auto_break_lib.php';
            }
            if (function_exists('bwr_clockout_redirect')) {
                $bwrNext = bwr_clockout_redirect($pdo, (int)$live['id'], $kiosk_page);
                if (is_string($bwrNext) && $bwrNext !== '') {
                    header("Location: " . $bwrNext);
                    exit;
                }
            }
        }

    } elseif ($action == 'transfer_out' && $live) {
        $old_site_id = (int)$live['site_id'];
        
        $upd = $pdo->prepare("UPDATE attendance SET clock_out = ?, status = '4', lat_out = ?, lng_out = ?, selfie_out = ?, hourly_rate = ?, official_rate = ? WHERE id = ?");
        $upd->execute([$now, $lat, $lng, $image, $current_hourly, $current_official, $live['id']]);

        $insTravel = $pdo->prepare("INSERT INTO attendance (org_id, user_id, site_id, clock_in, status, transfer_status, lat_in, lng_in, selfie_in, hourly_rate, official_rate) VALUES (?, ?, ?, ?, '5', 'travel', ?, ?, ?, ?, ?)");
        $insTravel->execute([$org_id, $uid, $old_site_id, $now, $lat, $lng, $image, $current_hourly, $current_official]);

    } elseif ($action == 'transfer_in' && $live) {
        $origin_site_name = 'Origin Site';
        if (!empty($live['site_id'])) {
            $origStmt = $pdo->prepare("SELECT nick_name FROM sites WHERE Sid = ? LIMIT 1");
            $origStmt->execute([(int)$live['site_id']]);
            $origin_site_name = $origStmt->fetchColumn() ?: 'Origin Site';
        }

        $destStmt = $pdo->prepare("SELECT nick_name FROM sites WHERE Sid = ? LIMIT 1");
        $destStmt->execute([$site_id]);
        $dest_site_name = $destStmt->fetchColumn() ?: 'Destination Site';
        $full_route_string = $origin_site_name . ' → ' . $dest_site_name;

        // Finalize old transit link row
        $updTravel = $pdo->prepare("UPDATE attendance SET clock_out = ?, status = '5', transfer_status = ?, lat_out = ?, lng_out = ?, selfie_out = ?, hourly_rate = ?, official_rate = ? WHERE id = ?");
        $updTravel->execute([$now, $full_route_string, $lat, $lng, $image, $current_hourly, $current_official, $live['id']]);

        // Launch newly targeted shift sequence with unified calibrated parameters 
        $insNewShift = $pdo->prepare("INSERT INTO attendance (org_id, user_id, site_id, clock_in, status, transfer_status, lat_in, lng_in, selfie_in, hourly_rate, official_rate) VALUES (?, ?, ?, ?, '0', 'none', ?, ?, ?, ?, ?)");
        $insNewShift->execute([$org_id, $uid, $site_id, $now, $lat, $lng, $image, $current_hourly, $current_official]);

    } elseif ($action == 'break_start' && $live) {
        $upd = $pdo->prepare("UPDATE attendance SET status = '1' WHERE id = ?");
        $upd->execute([$live['id']]);

        $insBreak = $pdo->prepare("INSERT INTO breaks (shift_id, break_start, start_selfie, start_lat, start_lng) VALUES (?, ?, ?, ?, ?)");
        $insBreak->execute([$live['id'], $now, $image, $lat, $lng]);

    } elseif ($action == 'break_end' && $live) {
        $breakCheck = $pdo->prepare("SELECT id FROM breaks WHERE shift_id = ? AND break_end IS NULL ORDER BY id DESC LIMIT 1");
        $breakCheck->execute([$live['id']]);
        $activeBreak = $breakCheck->fetch();

        if ($activeBreak) {
            $updBreak = $pdo->prepare("UPDATE breaks SET break_end = ?, end_selfie = ?, end_lat = ?, end_lng = ? WHERE id = ?");
            $updBreak->execute([$now, $image, $lat, $lng, $activeBreak['id']]);
        }
        $upd = $pdo->prepare("UPDATE attendance SET status = '0' WHERE id = ?");
        $upd->execute([$live['id']]);
    }

    header("Location: " . $kiosk_page);
} catch (Exception $e) {
    die("Engine Error: " . $e->getMessage());
}
exit;
?>