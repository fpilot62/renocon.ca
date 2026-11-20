<?php
/**
 * Alarms this user was assigned or copied on, not ones they created.
 * The owner's own list still comes from get_alarms.php. The phone merges the two.
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

function alarm_shared_overdue(array $row): bool {
  $active = (int)($row['is_active'] ?? 1);
  if ($active !== 1) return false;
  $status = strtolower(trim((string)($row['status'] ?? '')));
  if (in_array($status, ['done', 'completed', 'dismissed'], true)) return false;
  $date = trim((string)($row['task_date'] ?? ''));
  $time = trim((string)($row['task_time'] ?? ''));
  if ($date === '') return false;
  if (strlen($time) === 5) $time .= ':00';
  $tzName = trim((string)($row['timezone'] ?? ''));
  if ($tzName === '') $tzName = ALARM_API_DEFAULT_TZ;
  try {
    $tz = new DateTimeZone($tzName);
  } catch (Exception $e) {
    $tz = new DateTimeZone(ALARM_API_DEFAULT_TZ);
  }
  $when = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . ($time !== '' ? $time : '00:00:00'), $tz);
  if (!$when) return false;
  return $when < new DateTimeImmutable('now', $tz);
}

try {
  alarm_vis_ensure_schema($pdo);
  $user = alarm_api_user_row($pdo, $uid);
  if (!$user) alarm_api_send(alarm_api_err(401, 'unauthorized', 'Unauthorized'));
  if ((int)$user['active'] !== 1) alarm_api_send(alarm_api_err(403, 'inactive', 'This account is inactive'));

  $cols = alarm_api_columns($pdo, 'alarm_tasks');
  $names = ['task_id', 'title', 'description', 'task_date', 'task_time', 'timezone', 'repeat_type', 'repeat_interval', 'repeat_expires', 'snooze_minutes', 'notify_before', 'reminders', 'ringtone', 'priority', 'status', 'is_active', 'created_by', 'assigned_to_email', 'shared_emails'];
  $select = [];
  foreach ($names as $name) {
    if (isset($cols[$name])) $select[] = 't.`' . $name . '`';
  }
  if (!isset($cols['task_id']) || !isset($cols['created_by'])) {
    alarm_api_send(alarm_api_err(500, 'schema_mismatch', 'alarm_tasks is missing a column this endpoint needs'));
  }
  $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ', r.kind AS recipient_kind
    FROM alarm_task_recipients r
    INNER JOIN alarm_tasks t ON t.task_id = r.task_id
    WHERE r.user_id = ? AND t.created_by <> ?
    ORDER BY t.task_date DESC, t.task_time DESC
    LIMIT 200');
  $stmt->execute([$uid, $uid]);
  $alarms = [];
  $seen = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $taskId = (int)($row['task_id'] ?? 0);
    if ($taskId < 1 || isset($seen[$taskId])) continue;
    $seen[$taskId] = true;
    $public = alarm_api_public_alarm($row);
    $public['is_overdue'] = alarm_shared_overdue($row) ? 1 : 0;
    $public['created_by'] = (int)($row['created_by'] ?? 0);
    $public['recipient_kind'] = (string)($row['recipient_kind'] ?? '');
    if (isset($row['assigned_to_email'])) $public['assigned_to_email'] = (string)$row['assigned_to_email'];
    $alarms[] = $public;
  }
  alarm_api_send(['ok' => true, 'alarms' => $alarms]);
} catch (PDOException $e) {
  error_log('get_shared_alarms database error SQLSTATE=' . $e->getCode());
  alarm_api_send(alarm_api_err(500, 'server_error', 'Database error'));
}
