<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

// Handle preflight 'OPTIONS' requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit;
}
/** Path: /app_clock.php */
header('Content-Type: application/json');
require_once 'db.php'; 

$user_id = $_POST['user_id'] ?? '';
$org_id  = $_POST['org_id'] ?? '';
$site_id = $_POST['site_id'] ?? '';
$action  = $_POST['action'] ?? ''; 
$note    = $_POST['note'] ?? ''; // Reason for unscheduled shift
$lat     = $_POST['lat'] ?? '0';
$lng     = $_POST['lng'] ?? '0';

try {
    $today = date('Y-m-d');
    $localNow = date('Y-m-d H:i:s');

    if ($action == 'clock_in') {
        // MATCHING LOGIC: Check schedule just like process_punch.php does
        $sch = $pdo->prepare("SELECT id FROM Gem_Schedules WHERE employee_id = ? AND RId = ? AND DATE(start_time) = ? AND status='scheduled' LIMIT 1");
        $sch->execute([$user_id, $site_id, $today]);
        $is_flagged = $sch->fetch() ? 0 : 1;

        $sql = "INSERT INTO attendance (user_id, org_id, site_id, clock_in, status, flag_reason, is_flagged, lat_in, lng_in) 
                VALUES (?, ?, ?, ?, '1', ?, ?, ?, ?)";
        $pdo->prepare($sql)->execute([$user_id, $org_id, $site_id, $localNow, $note, $is_flagged, $lat, $lng]);
        
        $pdo->prepare("UPDATE users SET current_status = '1' WHERE id = ?")->execute([$user_id]);
    } 
    elseif ($action == 'clock_out') {
        $stmtShift = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND clock_out IS NULL ORDER BY id DESC LIMIT 1");
        $stmtShift->execute([$user_id]);
        $shift_id = $stmtShift->fetchColumn();

        $stmt = $pdo->prepare("UPDATE attendance SET clock_out = ?, status = '4', lat_out = ?, lng_out = ? WHERE id = ?");
        $stmt->execute([$localNow, $lat, $lng, $shift_id]);
        
        $pdo->prepare("UPDATE users SET current_status = '4' WHERE id = ?")->execute([$user_id]);
    }

    echo json_encode(["status" => "success", "message" => "Sync Successful"]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}