<?php
/**
 * AlarmPulse cron_notify.php
 *
 * Sends Expo push notifications for alarms due around now.
 *
 * Root cause of missed pushes: this script used to join `users` on `users.id`
 * and read `users.push_token` / `users.Name`. The app writes the Expo token with
 * save_push_token.php onto `alarm_users.push_token` where `user_id` matches
 * `alarm_tasks.created_by`. Those rows never matched, so nothing was sent.
 *
 * Schema (save_push_token.php, admin/admin_users.php, and PR #1):
 *   alarm_users.user_id, alarm_users.push_token
 *   alarm_tasks.created_by -> alarm_users.user_id
 *   alarm_tasks.task_date, task_time, is_active, status, last_notified, fired_count
 *
 * Run every 5 minutes from CLI so the working directory does not matter:
 *   php /path/to/alarm/api/cron_notify.php
 * HTTP is rejected unless ALARMPULSE_CRON_SECRET is set and sent as
 *   X-AlarmPulse-Cron-Secret or ?secret=
 *
 *   php cron_notify.php --selftest
 *
 * Due times are wall-clock task_date/task_time. The window is evaluated in the
 * alarm's timezone column, or America/Edmonton when that column is empty.
 * PR #1 uses the same join, ticket handling, and window math, and takes "now"
 * from the server default timezone. This copy pins the clock so a UTC php.ini
 * still fires America/Edmonton alarms. The Android channel is alarmpulse-alarms
 * because that is the channel the current app creates. PR #1 sends
 * alarmpulse_rt_<ringtone> after its app build creates those channels.
 * Leave this channel id alone while that app build is unmerged. Assignee
 * and copy pushes use the same channel.
 *
 * Push goes to the alarm owner (created_by) and to alarm_task_recipients.
 * If that table is not installed yet, only the owner is notified.
 * People who were invited from the phone book and have not registered do
 * not get this push and do not get an alarm-time text or email. The cron
 * only sends their signup invite, plus one later reminder of that invite.
 */

function alarm_due_clause(DateTimeInterface $now, int $lookbackMin = 5, int $lookaheadMin = 5): array {
  $stamp = $now->format('Y-m-d H:i:s');
  $start = (new DateTimeImmutable($stamp))->modify("-{$lookbackMin} minutes");
  $end   = (new DateTimeImmutable($stamp))->modify("+{$lookaheadMin} minutes");
  $startDate = $start->format('Y-m-d');
  $startTime = $start->format('H:i:s');
  $endDate   = $end->format('Y-m-d');
  $endTime   = $end->format('H:i:s');

  // Half-open [start, end) so a boundary is not claimed by two runs.
  // When the window crosses midnight, match tonight's late times OR tomorrow's early times.
  if ($startDate === $endDate) {
    return [
      't.task_date = ? AND t.task_time >= ? AND t.task_time < ?',
      [$startDate, $startTime, $endTime],
    ];
  }
  return [
    '((t.task_date = ? AND t.task_time >= ?) OR (t.task_date = ? AND t.task_time < ?))',
    [$startDate, $startTime, $endDate, $endTime],
  ];
}

function alarm_cron_authorized(): bool {
  if (PHP_SAPI === 'cli') return true;
  $secret = getenv('ALARMPULSE_CRON_SECRET');
  if (!is_string($secret) || $secret === '') return false;
  $provided = $_SERVER['HTTP_X_ALARMPULSE_CRON_SECRET'] ?? ($_GET['secret'] ?? '');
  if (!is_string($provided) || $provided === '') return false;
  return hash_equals($secret, $provided);
}

function alarm_json_out(int $status, array $payload): void {
  if (PHP_SAPI !== 'cli' && !headers_sent()) {
    http_response_code($status);
    header('Content-Type: application/json');
  }
  echo json_encode($payload);
}

function alarm_notify_user_ids(int $ownerId, array $extraIds): array {
  $ids = [];
  if ($ownerId > 0) $ids[$ownerId] = true;
  foreach ($extraIds as $id) {
    $id = (int)$id;
    if ($id > 0) $ids[$id] = true;
  }
  return array_map('intval', array_keys($ids));
}

function alarm_zone_or_edmonton(string $name): DateTimeZone {
  $name = trim($name);
  if ($name === '') $name = 'America/Edmonton';
  try {
    return new DateTimeZone($name);
  } catch (Exception $e) {
    return new DateTimeZone('America/Edmonton');
  }
}

function alarm_selftest(): void {
  $late = alarm_due_clause(new DateTimeImmutable('2026-10-31 23:58:00'));
  $early = alarm_due_clause(new DateTimeImmutable('2026-11-01 00:02:00'));
  $mid = alarm_due_clause(new DateTimeImmutable('2026-10-31 15:10:00'));
  $ok = true;
  $ok = $ok && strpos($late[0], 'OR') !== false;
  $ok = $ok && $late[1] === ['2026-10-31', '23:53:00', '2026-11-01', '00:03:00'];
  $ok = $ok && $early[1][0] === '2026-10-31' && $early[1][2] === '2026-11-01';
  $ok = $ok && strpos($mid[0], 'OR') === false;
  $ok = $ok && $mid[1] === ['2026-10-31', '15:05:00', '15:15:00'];
  $ok = $ok && alarm_zone_or_edmonton('Not/AZone')->getName() === 'America/Edmonton';
  $ok = $ok && alarm_zone_or_edmonton('America/Edmonton')->getName() === 'America/Edmonton';
  $ok = $ok && alarm_notify_user_ids(4, [4, 5, 0, 5, 8]) === [4, 5, 8];
  $ok = $ok && alarm_notify_user_ids(0, []) === [];
  if (!$ok) {
    fwrite(STDERR, "cron window selftest failed\n");
    exit(1);
  }
  echo "ok\n";
  exit(0);
}

if (PHP_SAPI === 'cli' && isset($argv) && in_array('--selftest', $argv, true)) {
  alarm_selftest();
}

if (!alarm_cron_authorized()) {
  alarm_json_out(403, ['success' => false, 'error' => 'Forbidden']);
  exit;
}

require_once __DIR__ . '/../../db.php';

function alarm_receipt_queue_path(): string {
  return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'alarmpulse-expo-receipts.json';
}

function alarm_load_receipt_queue(): array {
  $path = alarm_receipt_queue_path();
  if (!is_file($path)) return [];
  $raw = @file_get_contents($path);
  $data = json_decode($raw ?: '', true);
  return is_array($data) ? $data : [];
}

function alarm_save_receipt_queue(array $items): void {
  $path = alarm_receipt_queue_path();
  $json = json_encode(array_values($items));
  if ($json === false) return;
  file_put_contents($path, $json, LOCK_EX);
  @chmod($path, 0600);
}

function alarm_expo_post(string $url, array $body): ?array {
  require_once __DIR__ . '/../../engine/staging.php';
  if (payroll_staging_outbound_blocked('push')) {
    return null;
  }
  $ch = curl_init($url);
  if ($ch === false) return null;
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($body),
    CURLOPT_HTTPHEADER     => [
      'Content-Type: application/json',
      'Accept: application/json',
      'User-Agent: AlarmPulse-cron/1.1',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_ENCODING       => '',
  ]);
  $raw = curl_exec($ch);
  $errno = curl_errno($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($errno !== 0 || !is_string($raw) || $raw === '') {
    error_log('Expo push HTTP failure status=' . $status . ' errno=' . $errno);
    return null;
  }
  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    error_log('Expo push returned non-JSON');
    return null;
  }
  return $decoded;
}

function alarm_clear_push_token(PDO $pdo, int $userId, string $token): void {
  if ($userId < 1 || $token === '') return;
  $stmt = $pdo->prepare('UPDATE alarm_users SET push_token = NULL WHERE user_id = ? AND push_token = ?');
  $stmt->execute([$userId, $token]);
}

function alarm_expo_error_code(array $node): string {
  $details = $node['details'] ?? null;
  if (is_array($details) && !empty($details['error'])) return (string)$details['error'];
  return '';
}

function alarm_apply_receipts(PDO $pdo, array $queue): array {
  $pending = [];
  $now = time();
  $byId = [];
  foreach ($queue as $item) {
    if (!is_array($item) || empty($item['id'])) continue;
    $queuedAt = (int)($item['queued_at'] ?? 0);
    if ($queuedAt > 0 && ($now - $queuedAt) > 86400) continue;
    $id = (string)$item['id'];
    $byId[$id] = $item;
  }
  if (!$byId) return ['pending' => [], 'cleared' => 0, 'checked' => 0];

  $cleared = 0;
  $ids = array_keys($byId);
  foreach (array_chunk($ids, 1000) as $chunk) {
    $decoded = alarm_expo_post('https://exp.host/--/api/v2/push/getReceipts', ['ids' => $chunk]);
    $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;
    if (!is_array($data)) {
      foreach ($chunk as $id) $pending[] = $byId[$id];
      continue;
    }
    foreach ($chunk as $id) {
      if (!isset($data[$id]) || !is_array($data[$id])) {
        $pending[] = $byId[$id];
        continue;
      }
      $receipt = $data[$id];
      $code = alarm_expo_error_code($receipt);
      if (($receipt['status'] ?? '') === 'error' && $code === 'DeviceNotRegistered') {
        alarm_clear_push_token($pdo, (int)($byId[$id]['user_id'] ?? 0), (string)($byId[$id]['token'] ?? ''));
        $cleared++;
        error_log('Expo receipt DeviceNotRegistered user_id=' . (int)($byId[$id]['user_id'] ?? 0));
      } elseif (($receipt['status'] ?? '') === 'error') {
        error_log('Expo receipt error code=' . $code . ' message=' . (string)($receipt['message'] ?? ''));
      }
    }
  }
  return ['pending' => $pending, 'cleared' => $cleared, 'checked' => count($ids)];
}

function alarm_channel_for_ringtone($ringtone): string {
  // Current app (src/utils/notifications.js) creates alarmpulse-alarms.
  // An unknown channelId is dropped on Android 8+. PR #1's unmerged app creates
  // alarmpulse_rt_<ringtone> and deletes alarmpulse-alarms; keep this id until
  // that build is what is installed on the phone. $ringtone is accepted so the
  // call site matches PR #1.
  unset($ringtone);
  return 'alarmpulse-alarms';
}

function alarm_task_zones(PDO $pdo): array {
  $hasTz = false;
  try {
    $chk = $pdo->query("SHOW COLUMNS FROM alarm_tasks LIKE 'timezone'");
    $hasTz = (bool)$chk->fetch(PDO::FETCH_ASSOC);
  } catch (PDOException $e) {
    return [false, ['America/Edmonton']];
  }
  if (!$hasTz) return [false, ['America/Edmonton']];
  $rows = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(timezone), ''), 'America/Edmonton') AS tz
    FROM alarm_tasks WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
  $zones = [];
  foreach ($rows as $tz) {
    $tz = trim((string)$tz);
    if ($tz === '') $tz = 'America/Edmonton';
    $zones[$tz] = $tz;
  }
  if (!$zones) $zones['America/Edmonton'] = 'America/Edmonton';
  return [true, array_values($zones)];
}

function alarm_due_rows(PDO $pdo): array {
  [$hasTz, $zones] = alarm_task_zones($pdo);
  $alarms = [];
  $seen = [];
  foreach ($zones as $tzName) {
    $zoneNow = new DateTimeImmutable('now', alarm_zone_or_edmonton((string)$tzName));
    [$dueSql, $dueParams] = alarm_due_clause($zoneNow);
    $tzSql = '';
    if ($hasTz) {
      $tzSql = " AND COALESCE(NULLIF(TRIM(t.timezone), ''), 'America/Edmonton') = ?";
      $dueParams[] = $tzName;
    }
    $sql = "
      SELECT t.*
      FROM alarm_tasks t
      WHERE t.is_active = 1
        AND LOWER(COALESCE(t.status, '')) NOT IN ('completed', 'done', 'dismissed', 'snoozed')
        AND {$dueSql}
        {$tzSql}
        AND (t.last_notified IS NULL OR t.last_notified < DATE_SUB(NOW(), INTERVAL 9 MINUTE))
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($dueParams);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $taskId = (int)($row['task_id'] ?? 0);
      if ($taskId > 0 && isset($seen[$taskId])) continue;
      if ($taskId > 0) $seen[$taskId] = true;
      $alarms[] = $row;
    }
  }
  return $alarms;
}

function alarm_recipient_ids(PDO $pdo, array $taskIds): array {
  $map = [];
  $taskIds = array_values(array_filter(array_map('intval', $taskIds)));
  if (!$taskIds) return $map;
  try {
    $marks = implode(',', array_fill(0, count($taskIds), '?'));
    $stmt = $pdo->prepare("SELECT task_id, user_id FROM alarm_task_recipients WHERE task_id IN ($marks)");
    $stmt->execute($taskIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $map[(int)$row['task_id']][] = (int)$row['user_id'];
    }
  } catch (PDOException $e) {
    return [];
  }
  return $map;
}

function alarm_push_tokens(PDO $pdo, array $userIds): array {
  $tokens = [];
  $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
  if (!$userIds) return $tokens;
  $marks = implode(',', array_fill(0, count($userIds), '?'));
  $sqlWithActive = "SELECT user_id, push_token FROM alarm_users WHERE user_id IN ($marks) AND active = 1 AND push_token IS NOT NULL AND push_token != ''";
  $sqlPlain = "SELECT user_id, push_token FROM alarm_users WHERE user_id IN ($marks) AND push_token IS NOT NULL AND push_token != ''";
  try {
    $stmt = $pdo->prepare($sqlWithActive);
    $stmt->execute($userIds);
  } catch (PDOException $e) {
    $stmt = $pdo->prepare($sqlPlain);
    $stmt->execute($userIds);
  }
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $token = trim((string)($row['push_token'] ?? ''));
    if ($token !== '') $tokens[(int)$row['user_id']] = $token;
  }
  return $tokens;
}

$now = (new DateTimeImmutable('now', new DateTimeZone('America/Edmonton')))->format('Y-m-d H:i:s');

try {
  $receiptStats = alarm_apply_receipts($pdo, alarm_load_receipt_queue());
  alarm_save_receipt_queue($receiptStats['pending']);

  $alarms = alarm_due_rows($pdo);
  $taskIds = [];
  foreach ($alarms as $alarm) {
    $taskId = (int)($alarm['task_id'] ?? 0);
    if ($taskId > 0) $taskIds[] = $taskId;
  }
  $recipientMap = alarm_recipient_ids($pdo, $taskIds);
  $allUserIds = [];
  foreach ($alarms as $alarm) {
    $taskId = (int)($alarm['task_id'] ?? 0);
    $ownerId = (int)($alarm['created_by'] ?? 0);
    foreach (alarm_notify_user_ids($ownerId, $recipientMap[$taskId] ?? []) as $userId) {
      $allUserIds[] = $userId;
    }
  }
  $tokens = alarm_push_tokens($pdo, $allUserIds);

  $messages = [];
  $meta = [];
  foreach ($alarms as $alarm) {
    $taskId = (int)($alarm['task_id'] ?? 0);
    $ownerId = (int)($alarm['created_by'] ?? 0);
    if ($taskId < 1) continue;
    $title = trim((string)($alarm['title'] ?? ''));
    if ($title === '') $title = 'AlarmPulse';
    $body = trim((string)($alarm['description'] ?? ''));
    if ($body === '') $body = 'Your alarm is due now';
    foreach (alarm_notify_user_ids($ownerId, $recipientMap[$taskId] ?? []) as $userId) {
      $token = $tokens[$userId] ?? '';
      if ($token === '') continue;
      $messages[] = [
        'to'        => $token,
        'title'     => $title,
        'body'      => $body,
        // iOS plays this. Android 8+ uses channelId, which the current app creates.
        // Keep alarmpulse-alarms. PR #1's per-ringtone channels are not created by this app build.
        'sound'     => 'default',
        'channelId' => alarm_channel_for_ringtone($alarm['ringtone'] ?? 'alarm'),
        'priority'  => 'high',
        'badge'     => 1,
        'data'      => ['task_id' => $taskId, 'kind' => 'remote'],
      ];
      $meta[] = ['task_id' => $taskId, 'user_id' => $userId, 'token' => $token];
    }
  }

  $sent = 0;
  $marked = [];
  $queued = $receiptStats['pending'];
  foreach (array_chunk($messages, 100, true) as $chunk) {
    $payload = array_values($chunk);
    $indexes = array_keys($chunk);
    $decoded = alarm_expo_post('https://exp.host/--/api/v2/push/send', $payload);
    $tickets = is_array($decoded) ? ($decoded['data'] ?? null) : null;
    if (!is_array($tickets)) {
      error_log('Expo push send failed for ' . count($payload) . ' message(s)');
      continue;
    }
    foreach ($indexes as $i => $srcIndex) {
      $ticket = $tickets[$i] ?? null;
      $row = $meta[$srcIndex];
      if (!is_array($ticket)) {
        error_log('Expo push missing ticket for task_id=' . $row['task_id']);
        continue;
      }
      $code = alarm_expo_error_code($ticket);
      if (($ticket['status'] ?? '') === 'ok' && !empty($ticket['id'])) {
        if (empty($marked[$row['task_id']])) {
          $upd = $pdo->prepare('UPDATE alarm_tasks SET last_notified = NOW(), fired_count = COALESCE(fired_count, 0) + 1 WHERE task_id = ?');
          $upd->execute([$row['task_id']]);
          $marked[$row['task_id']] = true;
        }
        $queued[] = [
          'id'        => (string)$ticket['id'],
          'user_id'   => $row['user_id'],
          'token'     => $row['token'],
          'queued_at' => time(),
        ];
        $sent++;
      } elseif ($code === 'DeviceNotRegistered') {
        alarm_clear_push_token($pdo, $row['user_id'], $row['token']);
        $receiptStats['cleared']++;
        error_log('Expo ticket DeviceNotRegistered user_id=' . $row['user_id']);
      } else {
        error_log('Expo ticket error task_id=' . $row['task_id'] . ' code=' . $code . ' message=' . (string)($ticket['message'] ?? ''));
      }
    }
  }

  alarm_save_receipt_queue($queued);
  if ($sent > 0) {
    sleep(5);
    $again = alarm_apply_receipts($pdo, $queued);
    $queued = $again['pending'];
    $receiptStats['cleared'] += $again['cleared'];
    $receiptStats['checked'] += $again['checked'];
    alarm_save_receipt_queue($queued);
  }

  $inviteStats = ['claimed' => 0, 'sent' => 0, 'reminded' => 0];
  try {
    require_once __DIR__ . '/alarm_invite_lib.php';
    $inviteStats = alarm_invite_cron($pdo);
  } catch (Throwable $e) {
    error_log('invite cron failed');
  }

  alarm_json_out(200, [
    'success' => true,
    'sent' => $sent,
    'due' => count($alarms),
    'receipts_checked' => $receiptStats['checked'],
    'tokens_cleared' => $receiptStats['cleared'],
    'receipts_pending' => count($queued),
    'invites_claimed' => $inviteStats['claimed'],
    'invites_sent' => $inviteStats['sent'],
    'invites_reminded' => $inviteStats['reminded'],
    'time' => $now,
  ]);
} catch (PDOException $e) {
  error_log('cron_notify database error: ' . $e->getMessage());
  alarm_json_out(500, ['success' => false, 'error' => 'Database error']);
}
