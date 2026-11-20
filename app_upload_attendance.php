<?php
header("Content-Type: application/json");
require_once 'db.php';
date_default_timezone_set('America/Toronto'); 

$user_id = $_POST['user_id'] ?? 0;
$action  = $_POST['status'] ?? ''; 
$SId     = $_POST['SId'] ?? 0;
$lat     = (float)($_POST['lat'] ?? 0);
$lng     = (float)($_POST['lng'] ?? 0);

try {
    if (isset($_FILES['selfie'])) {
        $path = "uploads/selfies/" . time() . "_" . $user_id . ".jpg";
        move_uploaded_file($_FILES["selfie"]["tmp_name"], $path);

        $stmt = $pdo->prepare("SELECT id, status FROM attendance WHERE user_id = ? AND clock_out IS NULL ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $active = $stmt->fetch();

        if ($action == 'working' && (!$active || $active['status'] == 4)) {
            $reason = ""; $flag = 0;
            // 1. Geofence Logic
            $site = $pdo->prepare("SELECT target_lat, target_lng FROM sites WHERE SId = ?");
            $site->execute([$SId]);
            $geo = $site->fetch();
            if ($geo && (float)$geo['target_lat'] !== 0.0) {
                $dist = sqrt(pow($lat - $geo['target_lat'], 2) + pow($lng - $geo['target_lng'], 2));
                if ($dist > 0.002) { $reason .= "Away; "; $flag = 1; }
            }
            // 2. Schedule Logic
            $sch = $pdo->prepare("SELECT start_time FROM Gem_Schedules WHERE employee_id = ? AND RId = ? AND DATE(start_time) = CURDATE()");
            $sch->execute([$user_id, $SId]);
            $s = $sch->fetch();
            if (!$s) { $reason .= "Unplanned; "; $flag = 1; }
            else {
                $m = (time() - strtotime($s['start_time'])) / 60;
                if (abs($m) > 5) { $reason .= "Off-Sched; "; $flag = 1; }
            }
            $sql = "INSERT INTO attendance (site_id, user_id, status, selfie_in, clock_in, lat_in, lng_in, flag_reason, is_flagged, created_at) VALUES (?, ?, 1, ?, NOW(), ?, ?, ?, ?, NOW())";
            $pdo->prepare($sql)->execute([$SId, $user_id, $path, $lat, $lng, $reason, $flag]);
        } 
        else if ($action == 'on_break' && $active) {
            $pdo->prepare("UPDATE attendance SET status = 3 WHERE id = ?")->execute([$active['id']]);
            $pdo->prepare("INSERT INTO breaks (shift_id, break_start, start_selfie, start_lat, start_lng) VALUES (?, NOW(), ?, ?, ?)")->execute([$active['id'], $path, $lat, $lng]);
        }
        else if ($action == 'working' && $active && $active['status'] == 3) {
            $pdo->prepare("UPDATE attendance SET status = 1 WHERE id = ?")->execute([$active['id']]);
            $pdo->prepare("UPDATE breaks SET break_end = NOW(), end_selfie = ?, end_lat = ?, end_lng = ? WHERE shift_id = ? AND break_end IS NULL")->execute([$path, $lat, $lng, $active['id']]);
        }
        else if ($action == 'not_working' && $active) {
            $pdo->prepare("UPDATE attendance SET clock_out = NOW(), status = 4, lat_out = ?, lng_out = ?, selfie_out = ? WHERE id = ?")->execute([$lat, $lng, $path, $active['id']]);
        }
        echo json_encode(["success" => true]);
    }
} catch (Exception $e) { echo json_encode(["success" => false, "message" => "Error"]); }