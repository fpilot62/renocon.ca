<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

// Handle preflight 'OPTIONS' requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit;
}
/** Path: /check_schedule.php */
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

header('Content-Type: application/json');

try {
    // This line allows it to work for BOTH Laptop (Session) and Mobile (GET)
    $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (int)($_GET['user_id'] ?? 0);
    $sid = (int)($_GET['site_id'] ?? 0); 
    $today = date('Y-m-d');

    if ($uid === 0 || $sid === 0) {
        echo json_encode(['is_scheduled' => false, 'error' => 'Missing user or site ID']);
        exit;
    }

    $sql = "SELECT id FROM Gem_Schedules 
            WHERE employee_id = ? 
            AND RId = ? 
            AND DATE(start_time) = ? 
            AND status = 'scheduled' 
            LIMIT 1";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$uid, $sid, $today]);
    $row = $stmt->fetch();

    echo json_encode(['is_scheduled' => (bool)$row]);

} catch (Exception $e) {
    echo json_encode(['is_scheduled' => false, 'debug' => $e->getMessage()]);
}