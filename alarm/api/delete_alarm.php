<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../../db.php';
require_once 'alarm_jwt_helper.php';
$auth = alarm_require_auth();
$uid  = (int)$auth['user_id'];

$body    = json_decode(file_get_contents('php://input'), true);
$task_id = (int)($body['task_id'] ?? 0);

if (!$task_id) {
  echo json_encode(['success' => false, 'error' => 'task_id required']);
  exit;
}

try {
  // Only delete if owned by this user
  $stmt = $pdo->prepare("DELETE FROM alarm_tasks WHERE task_id = ? AND created_by = ?");
  $stmt->execute([$task_id, $uid]);

  if ($stmt->rowCount() > 0) {
    echo json_encode(['success' => true]);
  } else {
    echo json_encode(['success' => false, 'error' => 'Alarm not found or not authorized']);
  }
} catch (PDOException $e) {
  echo json_encode(['success' => false, 'error' => 'Database error']);
}