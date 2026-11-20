<?php
// 1. Error Suppression for clean JSON
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

// 2. Load your standard database file
require_once 'db.php'; 

// 3. Define the Distance Function
function getDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000; 
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}

try {
    // 4. Capture Data
    $site_id = $_REQUEST['site_id'] ?? '';
    $user_id = $_REQUEST['user_id'] ?? '';
    $org_id  = $_REQUEST['org_id'] ?? '1';
    $action  = $_REQUEST['action'] ?? '';
    $reason  = $_REQUEST['flag_reason'] ?? '';

    if ($action === 'no_break_request') {
        require_once __DIR__ . '/engine/break_waiver_lib.php';
        if (is_file(__DIR__ . '/engine/auto_break_lib.php')) {
            require_once __DIR__ . '/engine/auto_break_lib.php';
        }
        $sid = (int)($_REQUEST['shift_id'] ?? 0);
        $res = bwr_create_pending($pdo, $sid, (int)$user_id);
        if (!empty($res['ok'])) {
            echo json_encode(["status" => "success", "message" => "No-break request recorded", "request_id" => $res['id'], "already" => !empty($res['already'])]);
        } else {
            echo json_encode(["status" => "error", "message" => $res['error'] ?? "The request could not be saved."]);
        }
        exit;
    }

    // 5. Fetch Site Data using PDO
    $stmt = $pdo->prepare("SELECT Timezone, target_lat, target_lng FROM sites WHERE SId = ? LIMIT 1");
    $stmt->execute([$site_id]);
    $siteData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$siteData) {
        echo json_encode(["status" => "error", "message" => "Site not found"]);
        exit;
    }

    // 6. Time and Coordinates
    $siteTimezone = $siteData['Timezone'] ?? 'America/Edmonton';
    date_default_timezone_set($siteTimezone);
    $currentTime = date('Y-m-d H:i:s');

   // 6. Capture Phone GPS (Universal Check)
$userLat = $_REQUEST['lat_in'] ?? $_REQUEST['lat_out'] ?? $_REQUEST['start_lat'] ?? $_REQUEST['end_lat'] ?? $_REQUEST['lat'] ?? '0';
$userLng = $_REQUEST['lng_in'] ?? $_REQUEST['lng_out'] ?? $_REQUEST['start_lng'] ?? $_REQUEST['end_lng'] ?? $_REQUEST['lng'] ?? '0';


    $distance = getDistance($userLat, $userLng, $siteLat, $siteLng);

    // 7. Radius Check
    $radiusLimit = 15; // Default
    $is_flagged = ($distance > $radiusLimit) ? 1 : 0;

   // 8. Execute SQL
    if ($action == "1") { // CLOCK IN
        $sql = "INSERT INTO attendance (org_id, site_id, flag_reason, is_flagged, user_id, clock_in, lat_in, lng_in, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, '1')";
        $insert = $pdo->prepare($sql);
        $insert->execute([$org_id, $site_id, $reason, $is_flagged, $user_id, $currentTime, $userLat, $userLng]);
        
        echo json_encode(["status" => "success", "message" => "Clock In Processed Successfully"]);
    } 
  else if ($action == "2") { // START BREAK
        // 1. Find the current active shift (attendance ID) for this user
        $stmtShift = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND clock_out IS NULL ORDER BY id DESC LIMIT 1");
        $stmtShift->execute([$user_id]);
        $attendanceRow = $stmtShift->fetch(PDO::FETCH_ASSOC);
        $realShiftId = $attendanceRow['id'] ?? null;

        if (!$realShiftId) {
            echo json_encode(["status" => "error", "message" => "No active shift found to start a break."]);
            exit;
        }

        // 2. Insert into breaks using the REAL attendance ID
        $sql = "INSERT INTO breaks (shift_id, break_start, start_lat, start_lng) 
                VALUES (?, ?, ?, ?)";
        $insert = $pdo->prepare($sql);
        $insert->execute([$realShiftId, $currentTime, $userLat, $userLng]);
        
        echo json_encode(["status" => "success", "message" => "Break Started Successfully"]);
    }
    else if ($action == "3") { // END BREAK
        // 1. Find the active shift first
        $stmtShift = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND clock_out IS NULL ORDER BY id DESC LIMIT 1");
        $stmtShift->execute([$user_id]);
        $attendanceRow = $stmtShift->fetch(PDO::FETCH_ASSOC);
        $realShiftId = $attendanceRow['id'] ?? null;

        // 2. Update the break where shift_id matches and it's not finished
        $sql = "UPDATE breaks SET break_end = ?, end_lat = ?, end_lng = ? 
                WHERE shift_id = ? AND break_end IS NULL ORDER BY id DESC LIMIT 1";
        $update = $pdo->prepare($sql);
        $update->execute([$currentTime, $userLat, $userLng, $realShiftId]);

        echo json_encode(["status" => "success", "message" => "Break Ended Successfully"]);
    }
   else if ($action == "4") { // CLOCK OUT
    // We update the row where clock_out is empty for this user
    $sql = "UPDATE attendance 
            SET clock_out = ?, lat_out = ?, lng_out = ?, is_flagged = IF(?='1', 1, is_flagged)
            WHERE user_id = ? AND (clock_out IS NULL OR clock_out = '') 
            ORDER BY id DESC LIMIT 1";
            
    $update = $pdo->prepare($sql);
    // Use $userLat and $userLng (the Universal Check variables from line 44/45)
    $update->execute([$currentTime, $userLat, $userLng, $is_flagged, $user_id]);

    $payload = ["status" => "success", "message" => "Clock Out Processed Successfully"];
    if (is_file(__DIR__ . '/engine/break_waiver_lib.php')) {
        require_once __DIR__ . '/engine/break_waiver_lib.php';
        if (is_file(__DIR__ . '/engine/auto_break_lib.php')) {
            require_once __DIR__ . '/engine/auto_break_lib.php';
        }
        $shiftStmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND clock_out IS NOT NULL AND clock_out <> '' ORDER BY id DESC LIMIT 1");
        $shiftStmt->execute([$user_id]);
        $justClosed = $shiftStmt->fetch(PDO::FETCH_ASSOC);
        if ($justClosed && function_exists('bwr_is_eligible_shift') && function_exists('bwr_fetch_breaks')) {
            $sid = (int)$justClosed['id'];
            if (bwr_is_eligible_shift($justClosed, bwr_fetch_breaks($pdo, $sid)) && bwr_get_by_attendance($pdo, $sid) === null) {
                $mins = bwr_planned_minutes($justClosed);
                $payload['no_break_prompt'] = true;
                $payload['shift_id'] = $sid;
                $payload['planned_minutes'] = $mins;
                $payload['prompt'] = bwr_prompt_message($mins);
                $payload['request_url'] = 'no_break_request.php?shift_id=' . $sid;
            }
        }
    }
    echo json_encode($payload);
}

} catch (Exception $e) {
    // This will catch any PDO errors and show them as JSON instead of a 500 error
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>