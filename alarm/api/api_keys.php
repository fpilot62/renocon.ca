<?php
/**
 * Logged-in user API keys for AlarmPulse assistants.
 * POST action=create returns the raw key once. List and revoke never return it.
 * Auth is the same JWT as save_push_token.php. The key is not written to the log.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/alarm_api_lib.php';
alarm_api_cors();

require_once __DIR__ . '/alarm_jwt_helper.php';
$auth = alarm_require_auth();
$uid = (int)($auth['user_id'] ?? 0);
if ($uid < 1) alarm_api_send(alarm_api_err(401, 'unauthorized', 'Unauthorized'));

require_once __DIR__ . '/../../db.php';

try {
  alarm_api_ensure_schema($pdo);
  $user = alarm_api_user_row($pdo, $uid);
  if (!$user) alarm_api_send(alarm_api_err(401, 'unauthorized', 'Unauthorized'));
  if ((int)$user['active'] !== 1) alarm_api_send(alarm_api_err(403, 'inactive', 'This account is inactive'));

  $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
  if (!in_array($method, ['GET', 'POST'], true)) {
    alarm_api_send(alarm_api_err(405, 'method_not_allowed', 'Use GET or POST'));
  }
  $body = $method === 'POST' ? alarm_api_body_or_send(alarm_api_read_body()) : [];

  $action = strtolower(alarm_api_str($body['action'] ?? ($_GET['action'] ?? '')));
  if ($method === 'GET' && $action === '') $action = 'list';

  if ($action === 'list') {
    alarm_api_send(['ok' => true, 'keys' => alarm_api_list_keys($pdo, $uid)]);
  }
  if ($action === 'create') {
    alarm_api_send(alarm_api_create_key($pdo, $uid, $body['label'] ?? ''));
  }
  if ($action === 'revoke') {
    alarm_api_send(alarm_api_revoke_key($pdo, $uid, (int)($body['key_id'] ?? 0)));
  }
  alarm_api_send(alarm_api_err(400, 'unknown_action', 'action must be list, create, or revoke'));
} catch (PDOException $e) {
  error_log('api_keys database error SQLSTATE=' . $e->getCode());
  alarm_api_send(alarm_api_err(500, 'server_error', 'Database error'));
}


