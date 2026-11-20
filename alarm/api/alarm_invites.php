<?php
/**
 * Copy invites created by the signed-in user. Other people, including the
 * person who was invited, do not get this list. After they register they see
 * the alarm itself, not this contact row.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/alarm_api_lib.php';
require_once __DIR__ . '/alarm_invite_lib.php';
alarm_api_cors();

require_once __DIR__ . '/alarm_jwt_helper.php';
$auth = alarm_require_auth();
$uid = (int)($auth['user_id'] ?? 0);
if ($uid < 1) alarm_api_send(alarm_api_err(401, 'unauthorized', 'Unauthorized'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
  alarm_api_send(alarm_api_err(405, 'method_not_allowed', 'Use GET'));
}

require_once __DIR__ . '/../../db.php';

try {
  $user = alarm_api_user_row($pdo, $uid);
  if (!$user) alarm_api_send(alarm_api_err(401, 'unauthorized', 'Unauthorized'));
  if ((int)$user['active'] !== 1) alarm_api_send(alarm_api_err(403, 'inactive', 'This account is inactive'));
  $taskId = (int)($_GET['task_id'] ?? 0);
  alarm_api_send(['ok' => true, 'invites' => alarm_invite_list($pdo, $uid, $taskId)]);
} catch (PDOException $e) {
  error_log('alarm_invites database error SQLSTATE=' . $e->getCode());
  alarm_api_send(alarm_api_err(500, 'server_error', 'Database error'));
}
