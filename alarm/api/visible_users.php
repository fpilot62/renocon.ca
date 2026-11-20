<?php
/**
 * People the signed-in user may assign or copy.
 * Returns user_id, name, and email only. Managers, peers, other orgs,
 * and inactive accounts are left out.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/alarm_api_lib.php';
alarm_api_cors();

require_once __DIR__ . '/alarm_jwt_helper.php';
$auth = alarm_require_auth();
$uid = (int)($auth['user_id'] ?? 0);
if ($uid < 1) alarm_api_send(alarm_api_err(401, 'unauthorized', 'Unauthorized'));

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET') alarm_api_send(alarm_api_err(405, 'method_not_allowed', 'Use GET'));

require_once __DIR__ . '/../../db.php';

try {
  alarm_vis_ensure_schema($pdo);
  $user = alarm_api_user_row($pdo, $uid);
  if (!$user) alarm_api_send(alarm_api_err(401, 'unauthorized', 'Unauthorized'));
  if ((int)$user['active'] !== 1) alarm_api_send(alarm_api_err(403, 'inactive', 'This account is inactive'));

  $directory = alarm_vis_load_directory($pdo);
  $actor = alarm_vis_actor($directory, $uid);
  if (!$actor) alarm_api_send(alarm_api_err(401, 'unauthorized', 'Unauthorized'));

  $users = [];
  foreach (alarm_vis_visible($actor, $directory, false) as $row) {
    $users[] = alarm_vis_public($row);
  }
  alarm_api_send(['ok' => true, 'users' => $users]);
} catch (PDOException $e) {
  error_log('visible_users database error SQLSTATE=' . $e->getCode());
  alarm_api_send(alarm_api_err(500, 'server_error', 'Database error'));
}
