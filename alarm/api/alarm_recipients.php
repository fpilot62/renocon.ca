<?php
/**
 * Check or store the assignee and copy list for one alarm.
 * Both actions refuse anyone the caller is not allowed to see.
 * action=check does not write. action=save requires a task_id the caller owns.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/alarm_api_lib.php';
require_once __DIR__ . '/alarm_invite_lib.php';
alarm_api_cors();

require_once __DIR__ . '/alarm_jwt_helper.php';
$auth = alarm_require_auth();
$uid = (int)($auth['user_id'] ?? 0);
if ($uid < 1) alarm_api_send(alarm_api_err(401, 'unauthorized', 'Unauthorized'));

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') alarm_api_send(alarm_api_err(405, 'method_not_allowed', 'Use POST'));

require_once __DIR__ . '/../../db.php';

try {
  alarm_vis_ensure_schema($pdo);
  $user = alarm_api_user_row($pdo, $uid);
  if (!$user) alarm_api_send(alarm_api_err(401, 'unauthorized', 'Unauthorized'));
  if ((int)$user['active'] !== 1) alarm_api_send(alarm_api_err(403, 'inactive', 'This account is inactive'));

  $body = alarm_api_body_or_send(alarm_api_read_body());
  $action = strtolower(alarm_api_str($body['action'] ?? 'check'));
  if (!in_array($action, ['check', 'save'], true)) {
    alarm_api_send(alarm_api_err(400, 'unknown_action', 'action must be check or save'));
  }

  $external = alarm_invite_validate_list($body['external_copies'] ?? []);
  if (empty($external['ok'])) alarm_api_send(alarm_api_err((int)$external['error']['status'], (string)$external['error']['error_code'], (string)$external['error']['error']));

  $assigned = strtolower(alarm_api_str($body['assigned_to_email'] ?? ''));
  $copies = alarm_api_email_list($body['shared_emails'] ?? []);
  if (is_array($copies) && array_key_exists('ok', $copies) && empty($copies['ok'])) alarm_api_send($copies);

  $directory = alarm_vis_load_directory($pdo);
  $actor = alarm_vis_actor($directory, $uid);
  if (!$actor) alarm_api_send(alarm_api_err(401, 'unauthorized', 'Unauthorized'));
  $people = alarm_vis_resolve_people($actor, $directory, $assigned, $copies);
  if (empty($people['ok'])) {
    $err = $people['error'];
    alarm_api_send(alarm_api_err((int)$err['status'], (string)$err['error_code'], (string)$err['error']));
  }

  $payload = [
    'ok' => true,
    'assignee' => $people['assignee'] ? alarm_vis_public($people['assignee']) : null,
    'copies' => array_map('alarm_vis_public', $people['copies']),
    'external_copies' => $external['contacts'],
  ];

  if ($action === 'check') alarm_api_send($payload);

  $taskId = (int)($body['task_id'] ?? 0);
  if ($taskId < 1) alarm_api_send(alarm_api_err(400, 'task_required', 'task_id is required'));
  $own = $pdo->prepare('SELECT task_id FROM alarm_tasks WHERE task_id = ? AND created_by = ? LIMIT 1');
  $own->execute([$taskId, $uid]);
  if (!$own->fetch(PDO::FETCH_ASSOC)) alarm_api_send(alarm_api_err(404, 'not_found', 'Alarm not found'));

  alarm_vis_replace_recipients($pdo, $taskId, $uid, $people['assignee'], $people['copies']);
  $title = '';
  $task = $pdo->prepare('SELECT title FROM alarm_tasks WHERE task_id = ? AND created_by = ? LIMIT 1');
  $task->execute([$taskId, $uid]);
  $title = (string)$task->fetchColumn();
  $invites = alarm_invite_store($pdo, $taskId, $uid, $external['contacts'], (string)($user['name'] ?? ''), $title);
  $payload['task_id'] = $taskId;
  $payload['invites'] = $invites['invites'];
  $payload['warnings'] = $invites['warnings'];
  alarm_api_send($payload);
} catch (PDOException $e) {
  error_log('alarm_recipients database error SQLSTATE=' . $e->getCode());
  alarm_api_send(alarm_api_err(500, 'server_error', 'Database error'));
}
