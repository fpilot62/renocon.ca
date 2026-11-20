<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit; }

// 1. Centralized connection (Standard across all modules)
require_once 'db.php'; 

// 2. Collect Data from Flutter
$user_id    = $_POST['user_id'] ?? '';
$org_id     = $_POST['org_id'] ?? '';
$start_date = $_POST['start_date'] ?? '';
$end_date   = $_POST['end_date'] ?? '';
$reason     = $_POST['reason'] ?? '';

if (empty($user_id) || empty($start_date)) {
    echo json_encode(["status" => "error", "message" => "Required data missing"]);
    exit;
}

try {
    // 3. Prepare and Execute using the $pdo object from db.php
    $sql = "INSERT INTO time_off_requests (user_id, org_id, start_date, end_date, reason, status, created_at) 
            VALUES (?, ?, ?, ?, ?, 'pending', NOW())";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $org_id, $start_date, $end_date, $reason]);

    echo json_encode(["status" => "success", "message" => "Time off request submitted successfully!"]);

} catch (Exception $e) {
    // This catches database errors and sends them to Flutter instead of crashing
    echo json_encode(["status" => "error", "message" => "Database Error: " . $e->getMessage()]);
}
?>