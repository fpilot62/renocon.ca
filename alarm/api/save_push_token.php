<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/alarm_jwt_helper.php';

$auth = alarm_require_auth();
$uid  = (int)($auth['user_id'] ?? 0);
if ($uid < 1) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'Unauthorized']);
  exit;
}

$body       = json_decode(file_get_contents('php://input'), true) ?: [];
$push_token = trim($body['push_token'] ?? '');

if ($push_token === '') {
  echo json_encode(['success' => false, 'error' => 'Push token required']);
  exit;
}

try {
  require_once __DIR__ . '/../../db.php';
  $stmt = $pdo->prepare('UPDATE alarm_users SET push_token = ? WHERE user_id = ?');
  $stmt->execute([$push_token, $uid]);
  echo json_encode(['success' => true, 'message' => 'Push token saved']);
} catch (PDOException $e) {
  echo json_encode(['success' => false, 'error' => 'Database error']);
}
