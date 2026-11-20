<?php
/**
 * Assistant alarm API.
 *
 * Authorization: Bearer ap_live_...
 * or X-API-Key: ap_live_...
 *
 * POST creates an alarm for the key's user. GET lists that user's alarms.
 * POST action=delete (or DELETE) removes one alarm owned by that user.
 * The raw key is never logged.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/alarm_api_lib.php';
alarm_api_cors();
require_once __DIR__ . '/../../db.php';

try {
  alarm_api_ensure_schema($pdo);
  $presented = alarm_api_presented_key();
  if (empty($presented['ok'])) alarm_api_send($presented);

  $key = alarm_api_key_row($pdo, $presented['hash']);
  if (!$key || !empty($key['revoked_at'])) {
    alarm_api_send(alarm_api_err(401, 'unauthorized', 'Invalid API key'));
  }
  if ((int)($key['active'] ?? 0) !== 1) {
    alarm_api_send(alarm_api_err(403, 'inactive', 'This account is inactive'));
  }
  if (!alarm_api_rate_allow($pdo, (int)$key['key_id'])) {
    if (!headers_sent()) header('Retry-After: 60');
    alarm_api_send(alarm_api_err(429, 'rate_limited', 'Too many requests. Try again in a minute.'));
  }

  $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
  if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    alarm_api_send(alarm_api_err(405, 'method_not_allowed', 'Use GET, POST, or DELETE'));
  }
  $body = ($method === 'POST' || $method === 'DELETE') ? alarm_api_body_or_send(alarm_api_read_body()) : [];

  $action = strtolower(alarm_api_str($body['action'] ?? ($_GET['action'] ?? '')));
  if ($action === '' && $method === 'GET') $action = 'list';
  if ($action === '' && $method === 'DELETE') $action = 'delete';
  if ($action === '' && $method === 'POST') $action = 'create';

  if ($action === 'list') {
    alarm_api_send(alarm_api_list_alarms($pdo, (int)$key['user_id']));
  }
  if ($action === 'delete') {
    $taskId = (int)($body['task_id'] ?? ($_GET['task_id'] ?? 0));
    try {
      alarm_api_send(alarm_api_delete_alarm($pdo, (int)$key['user_id'], $taskId));
    } catch (PDOException $e) {
      error_log('bot_alarm delete SQLSTATE=' . $e->getCode() . ' user_id=' . (int)$key['user_id']);
      alarm_api_send(alarm_api_err(409, 'conflict', 'This alarm could not be deleted because another record still points at it.'));
    }
  }
  if ($action === 'create') {
    alarm_api_send(alarm_api_create_alarm($pdo, $key, $body));
  }
  alarm_api_send(alarm_api_err(400, 'unknown_action', 'action must be create, list, or delete'));
} catch (PDOException $e) {
  error_log('bot_alarm database error SQLSTATE=' . $e->getCode());
  alarm_api_send(alarm_api_err(500, 'server_error', 'Database error'));
}


