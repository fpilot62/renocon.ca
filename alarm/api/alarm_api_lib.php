<?php
/**
 * AlarmPulse assistant keys and bot-alarm helpers.
 *
 * Stores SHA-256 hashes only. Callers must not log the raw key, the
 * Authorization header, or X-API-Key. PDO comes from ../../db.php.
 */

const ALARM_API_DEFAULT_TZ = 'America/Edmonton';
const ALARM_API_MAX_ACTIVE_KEYS = 5;
const ALARM_API_RATE_LIMIT = 30;

require_once __DIR__ . '/alarm_visibility.php';

function alarm_api_len($text) {
  $text = (string)$text;
  if (function_exists('mb_strlen')) return mb_strlen($text, 'UTF-8');
  return strlen($text);
}

function alarm_api_clip($text, $max) {
  $text = (string)$text;
  if (alarm_api_len($text) <= $max) return $text;
  if (function_exists('mb_substr')) return mb_substr($text, 0, $max, 'UTF-8');
  return substr($text, 0, $max);
}

function alarm_api_str($value) {
  if (is_string($value) || is_numeric($value)) return trim((string)$value);
  return '';
}

function alarm_api_err($status, $code, $message) {
  return [
    'ok' => false,
    'status' => (int)$status,
    'error_code' => (string)$code,
    'error' => (string)$message,
  ];
}

function alarm_api_send(array $result) {
  $status = (int)($result['status'] ?? 200);
  if ($status < 100) $status = !empty($result['ok']) ? 200 : 500;
  $payload = $result;
  unset($payload['ok'], $payload['status'], $payload['token_internal']);
  if (!isset($payload['success'])) $payload['success'] = $status < 400;
  if (!headers_sent()) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
  }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function alarm_api_cors() {
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
  header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
  if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
  }
}

function alarm_api_read_body() {
  $raw = file_get_contents('php://input');
  if ($raw === false) $raw = '';
  if (strlen($raw) > 100000) {
    return alarm_api_err(413, 'body_too_large', 'Request body is too large');
  }
  $trim = trim($raw);
  if ($trim === '') return ['__body' => []];
  $decoded = json_decode($trim, true);
  if (!is_array($decoded)) {
    return alarm_api_err(400, 'invalid_json', 'Body must be a JSON object');
  }
  return ['__body' => $decoded];
}

function alarm_api_body_or_send($parsed) {
  if (is_array($parsed) && isset($parsed['error_code']) && !array_key_exists('__body', $parsed)) {
    alarm_api_send($parsed);
  }
  return is_array($parsed) && array_key_exists('__body', $parsed) ? $parsed['__body'] : [];
}

function alarm_api_timezone($name) {
  $name = trim((string)$name);
  if ($name === '') return null;
  try {
    return new DateTimeZone($name);
  } catch (Exception $e) {
    return null;
  }
}

function alarm_api_checked_when(DateTimeImmutable $local, $tzName) {
  $now = new DateTimeImmutable('now', $local->getTimezone());
  if ($local < $now->modify('-1 day') || $local > $now->modify('+2 years')) {
    return alarm_api_err(400, 'invalid_datetime', 'Alarm time must be within the past day and the next two years');
  }
  return [
    'ok' => true,
    'task_date' => $local->format('Y-m-d'),
    'task_time' => $local->format('H:i:s'),
    'timezone' => (string)$tzName,
  ];
}

function alarm_api_parse_clock($text, DateTimeZone $tz) {
  $text = str_replace('T', ' ', trim((string)$text));
  $text = preg_replace('/\.\d+/', '', $text);
  if (preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})(?::(\d{2}))?/', $text, $m) !== 1) {
    return null;
  }
  $stamp = $m[1] . ' ' . $m[2] . ':' . (isset($m[3]) ? $m[3] : '00');
  $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $stamp, $tz);
  $errors = DateTimeImmutable::getLastErrors();
  $bad = !$local || (is_array($errors) && (((int)($errors['warning_count'] ?? 0)) > 0 || ((int)($errors['error_count'] ?? 0)) > 0));
  return $bad ? null : $local;
}

/**
 * ISO 8601 with an offset is an instant, stored as wall-clock time in `timezone`
 * (default America/Edmonton). task_date + task_time are already that wall clock.
 */
function alarm_api_parse_when(array $body, $defaultTz = ALARM_API_DEFAULT_TZ) {
  $tzName = alarm_api_str($body['timezone'] ?? '');
  if ($tzName === '') $tzName = (string)$defaultTz;
  $tz = alarm_api_timezone($tzName);
  if (!$tz) {
    return alarm_api_err(400, 'invalid_timezone', 'timezone must be an IANA name such as America/Edmonton');
  }

  $iso = '';
  foreach (['datetime', 'when', 'alarm_time'] as $key) {
    $candidate = alarm_api_str($body[$key] ?? '');
    if ($candidate !== '') {
      $iso = $candidate;
      break;
    }
  }

  if ($iso !== '') {
    if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $iso) !== 1) {
      return alarm_api_err(400, 'invalid_datetime', 'datetime must be ISO 8601, for example 2026-11-16T09:30:00-07:00');
    }
    $hasZone = preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $iso) === 1;
    try {
      if ($hasZone) {
        $local = (new DateTimeImmutable($iso))->setTimezone($tz);
      } else {
        $local = alarm_api_parse_clock($iso, $tz);
        if (!$local) {
          return alarm_api_err(400, 'invalid_datetime', 'datetime could not be parsed');
        }
      }
    } catch (Exception $e) {
      return alarm_api_err(400, 'invalid_datetime', 'datetime could not be parsed');
    }
    return alarm_api_checked_when($local, $tz->getName());
  }

  $date = alarm_api_str($body['task_date'] ?? '');
  $time = alarm_api_str($body['task_time'] ?? '');
  if ($date === '' || $time === '') {
    return alarm_api_err(400, 'invalid_datetime', 'Send datetime (ISO 8601) or task_date, task_time, and timezone');
  }
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
    return alarm_api_err(400, 'invalid_datetime', 'task_date must be YYYY-MM-DD');
  }
  if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time) !== 1) {
    return alarm_api_err(400, 'invalid_datetime', 'task_time must be HH:MM or HH:MM:SS');
  }
  $local = alarm_api_parse_clock($date . ' ' . $time, $tz);
  if (!$local) {
    return alarm_api_err(400, 'invalid_datetime', 'task_date and task_time are not a real date');
  }
  return alarm_api_checked_when($local, $tz->getName());
}

function alarm_api_ringtones() {
  return ['alarm', 'ertugrul', 'jean_michel_jarre', 'katra_mila', 'oxy4', 'oxygene2'];
}

function alarm_api_repeats() {
  return ['none', 'minutes', 'hours', 'daily', 'weekly', 'monthly', 'yearly'];
}

function alarm_api_priorities() {
  return ['low', 'medium', 'high', 'critical'];
}

function alarm_api_reminders(array $body) {
  $raw = $body['reminders'] ?? null;
  if (is_string($raw)) {
    $trim = trim($raw);
    if ($trim === '') $raw = [];
    else {
      $decoded = json_decode($trim, true);
      $raw = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $trim);
    }
  }
  if ($raw === null || $raw === []) {
    if (array_key_exists('notify_before', $body) && $body['notify_before'] !== '' && $body['notify_before'] !== null) {
      if (!is_numeric($body['notify_before'])) {
        return alarm_api_err(400, 'invalid_reminder', 'notify_before must be whole minutes');
      }
      $mins = (int)$body['notify_before'];
      if ($mins < 0 || $mins > 10080) {
        return alarm_api_err(400, 'invalid_reminder', 'notify_before must be from 0 to 10080 minutes');
      }
      return $mins > 0 ? [0, $mins] : [0];
    }
    return [0];
  }
  if (!is_array($raw)) {
    return alarm_api_err(400, 'invalid_reminder', 'reminders must be a list of minutes');
  }
  $out = [];
  foreach ($raw as $item) {
    if ($item === '' || $item === null) continue;
    if (is_array($item) || !is_numeric($item)) {
      return alarm_api_err(400, 'invalid_reminder', 'reminders must be whole minutes');
    }
    $mins = (int)$item;
    if ($mins < 0 || $mins > 10080) {
      return alarm_api_err(400, 'invalid_reminder', 'Each reminder must be from 0 to 10080 minutes');
    }
    $out[$mins] = $mins;
  }
  if (!$out) return [0];
  if (count($out) > 8) return alarm_api_err(400, 'invalid_reminder', 'At most 8 reminders');
  $values = array_values($out);
  sort($values);
  return $values;
}

function alarm_api_flag($value) {
  if (is_bool($value)) return $value ? 1 : 0;
  if (is_numeric($value)) return ((int)$value) === 1 ? 1 : 0;
  $text = strtolower(trim((string)$value));
  return in_array($text, ['1', 'true', 'yes'], true) ? 1 : 0;
}

function alarm_api_email_list($raw) {
  if (is_string($raw)) $raw = trim($raw) === '' ? [] : preg_split('/\s*,\s*/', trim($raw));
  if ($raw === null) $raw = [];
  if (!is_array($raw)) return alarm_api_err(400, 'invalid_email', 'shared_emails must be a list of emails');
  $out = [];
  foreach ($raw as $item) {
    $email = strtolower(alarm_api_str($item));
    if ($email === '') continue;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return alarm_api_err(400, 'invalid_email', 'shared_emails contains an invalid email');
    }
    $out[$email] = $email;
  }
  if (count($out) > 10) return alarm_api_err(400, 'invalid_email', 'At most 10 shared emails');
  return array_values($out);
}

function alarm_api_validate_alarm(array $body, $defaultTz = ALARM_API_DEFAULT_TZ) {
  if (array_key_exists('task_id', $body) && $body['task_id'] !== null && $body['task_id'] !== '') {
    return alarm_api_err(400, 'update_unsupported', 'This endpoint creates a new alarm. Delete it and create another to change it.');
  }
  $when = alarm_api_parse_when($body, $defaultTz);
  if (empty($when['ok'])) return $when;

  $title = alarm_api_clip(alarm_api_str($body['title'] ?? ''), 200);
  if ($title === '') return alarm_api_err(400, 'title_required', 'Title is required');
  if (alarm_api_len(alarm_api_str($body['title'] ?? '')) > 200) {
    return alarm_api_err(400, 'title_too_long', 'Title must be 200 characters or less');
  }

  $description = alarm_api_str($body['description'] ?? '');
  if (alarm_api_len($description) > 5000) {
    return alarm_api_err(400, 'description_too_long', 'Description must be 5000 characters or less');
  }

  $repeat = strtolower(alarm_api_str($body['repeat_type'] ?? 'none'));
  if ($repeat === '') $repeat = 'none';
  if (!in_array($repeat, alarm_api_repeats(), true)) {
    return alarm_api_err(400, 'invalid_repeat', 'repeat_type must be none, minutes, hours, daily, weekly, monthly, or yearly');
  }
  $interval = (int)($body['repeat_interval'] ?? 1);
  if ($interval < 1) $interval = 1;
  if ($repeat === 'minutes') $interval = min($interval, 10080);
  elseif ($repeat === 'hours') $interval = min($interval, 168);
  else $interval = 1;

  $expires = null;
  if (array_key_exists('repeat_expires', $body) && $body['repeat_expires'] !== null && $body['repeat_expires'] !== '') {
    $expires = alarm_api_str($body['repeat_expires']);
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $expires) !== 1) {
      return alarm_api_err(400, 'invalid_repeat', 'repeat_expires must be YYYY-MM-DD');
    }
    $expires = substr($expires, 0, 10);
  }

  $reminders = alarm_api_reminders($body);
  if (is_array($reminders) && array_key_exists('ok', $reminders) && empty($reminders['ok'])) return $reminders;

  $snooze = array_key_exists('snooze_minutes', $body) ? (int)$body['snooze_minutes'] : 0;
  if ($snooze < 0 || $snooze > 120) {
    return alarm_api_err(400, 'invalid_snooze', 'snooze_minutes must be from 0 to 120');
  }

  $ringtone = strtolower(alarm_api_str($body['ringtone'] ?? 'alarm'));
  if ($ringtone === '') $ringtone = 'alarm';
  if (!in_array($ringtone, alarm_api_ringtones(), true)) {
    return alarm_api_err(400, 'invalid_ringtone', 'ringtone must be one of: ' . implode(', ', alarm_api_ringtones()));
  }

  $priority = strtolower(alarm_api_str($body['priority'] ?? 'medium'));
  if ($priority === '') $priority = 'medium';
  if (!in_array($priority, alarm_api_priorities(), true)) {
    return alarm_api_err(400, 'invalid_priority', 'priority must be low, medium, high, or critical');
  }

  $attachment = alarm_api_str($body['attachment_url'] ?? '');
  if (alarm_api_len($attachment) > 500) {
    return alarm_api_err(400, 'invalid_attachment', 'attachment_url must be 500 characters or less');
  }
  if ($attachment !== '' && preg_match('#^https?://#i', $attachment) !== 1) {
    return alarm_api_err(400, 'invalid_attachment', 'attachment_url must start with http:// or https://');
  }

  $assigned = strtolower(alarm_api_str($body['assigned_to_email'] ?? ''));
  if ($assigned !== '' && !filter_var($assigned, FILTER_VALIDATE_EMAIL)) {
    return alarm_api_err(400, 'invalid_email', 'assigned_to_email is not a valid email');
  }

  $shares = alarm_api_email_list($body['shared_emails'] ?? []);
  if (is_array($shares) && array_key_exists('ok', $shares) && empty($shares['ok'])) return $shares;

  $responseRequired = alarm_api_flag($body['response_required'] ?? 0);
  $responseFrom = strtolower(alarm_api_str($body['response_from_email'] ?? ''));
  if ($responseFrom !== '' && !filter_var($responseFrom, FILTER_VALIDATE_EMAIL)) {
    return alarm_api_err(400, 'invalid_email', 'response_from_email is not a valid email');
  }
  $responseDue = null;
  if ($responseRequired && array_key_exists('response_due', $body) && $body['response_due'] !== null && $body['response_due'] !== '') {
    $responseDue = str_replace('T', ' ', alarm_api_str($body['response_due']));
    if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $responseDue) !== 1) {
      return alarm_api_err(400, 'invalid_datetime', 'response_due must be YYYY-MM-DD or YYYY-MM-DD HH:MM:SS');
    }
    if (strlen($responseDue) === 16) $responseDue .= ':00';
  }

  return [
    'ok' => true,
    'task_date' => $when['task_date'],
    'task_time' => $when['task_time'],
    'timezone' => $when['timezone'],
    'title' => $title,
    'description' => $description,
    'repeat_type' => $repeat,
    'repeat_interval' => $interval,
    'repeat_expires' => $expires,
    'reminders' => $reminders,
    'notify_before' => (int)min($reminders),
    'snooze_minutes' => $snooze,
    'ringtone' => $ringtone,
    'priority' => $priority,
    'attachment_url' => $attachment,
    'assigned_to_email' => $assigned,
    'shared_emails' => $shares,
    'response_required' => $responseRequired,
    'response_due' => $responseDue,
    'response_from_email' => $responseFrom,
  ];
}

function alarm_api_generate_key() {
  $body = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
  $token = 'ap_live_' . $body;
  return [
    'token' => $token,
    'hash' => hash('sha256', $token),
    'prefix' => substr($token, 0, 16),
  ];
}

function alarm_api_normalize_key($presented) {
  $presented = trim((string)$presented);
  if ($presented === '' || strlen($presented) > 200) return '';
  if (preg_match('/^ap_live_[A-Za-z0-9_-]{20,}$/', $presented) !== 1) return '';
  return $presented;
}

function alarm_api_presented_key() {
  foreach (['api_key', 'key', 'access_token'] as $name) {
    if (isset($_GET[$name]) && $_GET[$name] !== '') {
      return alarm_api_err(400, 'key_in_query', 'Send the API key in a header, not the query string');
    }
  }
  $header = '';
  if (!empty($_SERVER['HTTP_X_API_KEY']) && is_string($_SERVER['HTTP_X_API_KEY'])) {
    $header = $_SERVER['HTTP_X_API_KEY'];
  } else {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (is_string($auth) && stripos($auth, 'Bearer ') === 0) $header = substr($auth, 7);
  }
  $key = alarm_api_normalize_key($header);
  if ($key === '') return alarm_api_err(401, 'unauthorized', 'Invalid API key');
  return ['ok' => true, 'hash' => hash('sha256', $key)];
}

function alarm_api_clean_label($label) {
  $label = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', alarm_api_str($label)));
  if ($label === '') $label = 'Assistant';
  return alarm_api_clip($label, 80);
}

function alarm_api_public_key(array $row) {
  return [
    'key_id' => (int)$row['key_id'],
    'label' => (string)($row['label'] ?? ''),
    'prefix' => (string)($row['key_prefix'] ?? ''),
    'created_at' => (string)($row['created_at'] ?? ''),
    'last_used_at' => isset($row['last_used_at']) && $row['last_used_at'] !== null ? (string)$row['last_used_at'] : null,
    'revoked_at' => isset($row['revoked_at']) && $row['revoked_at'] !== null ? (string)$row['revoked_at'] : null,
  ];
}

function alarm_api_ensure_schema(PDO $pdo) {
  $pdo->exec("CREATE TABLE IF NOT EXISTS alarm_api_keys (
    key_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    label VARCHAR(80) NOT NULL DEFAULT '',
    key_prefix VARCHAR(24) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    rate_window_start DATETIME NULL,
    rate_count INT NOT NULL DEFAULT 0,
    PRIMARY KEY (key_id),
    UNIQUE KEY uq_alarm_api_keys_hash (key_hash),
    KEY idx_alarm_api_keys_user (user_id, revoked_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function alarm_api_columns(PDO $pdo, $table) {
  if (preg_match('/^[A-Za-z0-9_]+$/', (string)$table) !== 1) return [];
  $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
  $cols = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $cols[strtolower((string)$row['Field'])] = $row;
  }
  return $cols;
}

function alarm_api_choose_status(array $col) {
  $type = strtolower((string)($col['Type'] ?? ''));
  $preferred = ['Active', 'Pending', 'Open', 'active', 'pending', 'open'];
  if (strpos($type, 'enum(') === 0) {
    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", (string)($col['Type'] ?? ''), $matches);
    $opts = $matches[1];
    foreach ($preferred as $want) {
      if (in_array($want, $opts, true)) return $want;
    }
    foreach ($opts as $opt) {
      if (preg_match('/complete|done|dismiss|snooze|cancel/i', $opt) !== 1) return $opt;
    }
    return isset($opts[0]) ? $opts[0] : 'Active';
  }
  return 'Active';
}

function alarm_api_fit_column(array $col, $value) {
  $type = strtolower((string)($col['Type'] ?? ''));
  if (is_string($value) && preg_match('/varchar\((\d+)\)/', $type, $m) === 1) {
    $max = (int)$m[1];
    if (strlen($value) > $max) return substr($value, 0, $max);
  }
  return $value;
}

function alarm_api_empty_for(array $col, array $wanted) {
  $type = strtolower((string)($col['Type'] ?? ''));
  if (strpos($type, 'int') !== false || strpos($type, 'decimal') !== false || strpos($type, 'float') !== false || strpos($type, 'double') !== false) {
    return 0;
  }
  if (strpos($type, 'datetime') !== false || strpos($type, 'timestamp') !== false) {
    return trim((isset($wanted['task_date']) ? $wanted['task_date'] : '1970-01-01') . ' ' . (isset($wanted['task_time']) ? $wanted['task_time'] : '00:00:00'));
  }
  if (strpos($type, 'date') !== false) return isset($wanted['task_date']) ? $wanted['task_date'] : '1970-01-01';
  if (strpos($type, 'time') !== false) return isset($wanted['task_time']) ? $wanted['task_time'] : '00:00:00';
  if (strpos($type, 'enum(') === 0) return alarm_api_choose_status($col);
  return '';
}

function alarm_api_insert_plan(array $columns, array $wanted) {
  foreach (['created_by', 'title', 'task_date', 'task_time'] as $name) {
    if (!isset($columns[$name])) {
      return alarm_api_err(500, 'schema_mismatch', 'alarm_tasks is missing a column this endpoint needs');
    }
  }
  $data = [];
  foreach ($wanted as $name => $value) {
    $key = strtolower((string)$name);
    if (!isset($columns[$key])) continue;
    if ($value === null) {
      if (strtoupper((string)$columns[$key]['Null']) === 'YES') $data[$key] = null;
      continue;
    }
    $data[$key] = alarm_api_fit_column($columns[$key], $value);
  }
  foreach ($columns as $name => $col) {
    if (isset($data[$name]) || $name === 'task_id') continue;
    $nullable = strtoupper((string)($col['Null'] ?? '')) === 'YES';
    $hasDefault = array_key_exists('Default', $col) && $col['Default'] !== null;
    $extra = (string)($col['Extra'] ?? '');
    if ($nullable || $hasDefault || stripos($extra, 'auto_increment') !== false) continue;
    $data[$name] = alarm_api_empty_for($col, $wanted);
  }
  return ['ok' => true, 'data' => $data];
}

function alarm_api_is_text_col(array $col) {
  $type = strtolower((string)($col['Type'] ?? ''));
  return strpos($type, 'char') !== false || strpos($type, 'text') !== false || strpos($type, 'json') !== false || strpos($type, 'blob') !== false;
}

function alarm_api_alarm_wanted(array $alarm, $userId, array $columns, $ownerEmail, array $shareIds) {
  $status = isset($columns['status']) ? alarm_api_choose_status($columns['status']) : 'Active';
  $assigned = $alarm['assigned_to_email'] !== '' ? $alarm['assigned_to_email'] : strtolower(trim((string)$ownerEmail));
  $reminders = json_encode(array_values($alarm['reminders']));
  if (isset($columns['reminders'])) {
    $rtype = strtolower((string)$columns['reminders']['Type']);
    if (strpos($rtype, 'int') !== false || strpos($rtype, 'decimal') !== false) $reminders = (int)$alarm['notify_before'];
  }
  $wanted = [
    'created_by' => (int)$userId,
    'user_id' => (int)$userId,
    'owner_id' => (int)$userId,
    'title' => $alarm['title'],
    'description' => $alarm['description'],
    'task_date' => $alarm['task_date'],
    'task_time' => $alarm['task_time'],
    'timezone' => $alarm['timezone'],
    'repeat_type' => $alarm['repeat_type'],
    'repeat_interval' => (int)$alarm['repeat_interval'],
    'repeat_expires' => $alarm['repeat_expires'],
    'snooze_minutes' => (int)$alarm['snooze_minutes'],
    'notify_before' => (int)$alarm['notify_before'],
    'reminders' => $reminders,
    'ringtone' => $alarm['ringtone'],
    'priority' => $alarm['priority'],
    'attachment_url' => $alarm['attachment_url'],
    'assigned_to_email' => $assigned,
    'shared_emails' => json_encode(array_values($alarm['shared_emails'])),
    'shared_user_ids' => $shareIds ? implode(',', $shareIds) : '',
    'response_required' => (int)$alarm['response_required'],
    'response_due' => $alarm['response_due'],
    'response_from_email' => $alarm['response_from_email'],
    'is_active' => 1,
    'status' => $status,
    'fired_count' => 0,
    'created_at' => (new DateTimeImmutable('now', new DateTimeZone(ALARM_API_DEFAULT_TZ)))->format('Y-m-d H:i:s'),
  ];
  if (isset($columns['shared_user_ids']) && !alarm_api_is_text_col($columns['shared_user_ids'])) unset($wanted['shared_user_ids']);
  if (isset($columns['shared_emails']) && !alarm_api_is_text_col($columns['shared_emails'])) unset($wanted['shared_emails']);
  return $wanted;
}

function alarm_api_insert_alarm(PDO $pdo, array $data) {
  $names = array_keys($data);
  $sql = 'INSERT INTO alarm_tasks (`' . implode('`, `', $names) . '`) VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')';
  $stmt = $pdo->prepare($sql);
  $stmt->execute(array_values($data));
  return (int)$pdo->lastInsertId();
}

function alarm_api_decode_reminders($raw) {
  if (is_array($raw)) return array_values($raw);
  if (is_numeric($raw)) return [(int)$raw];
  $decoded = json_decode((string)$raw, true);
  if (is_array($decoded)) return array_values($decoded);
  return [0];
}

function alarm_api_public_alarm(array $row) {
  $out = [
    'task_id' => (int)($row['task_id'] ?? 0),
    'title' => (string)($row['title'] ?? ''),
    'description' => (string)($row['description'] ?? ''),
    'task_date' => (string)($row['task_date'] ?? ''),
    'task_time' => isset($row['task_time']) ? substr((string)$row['task_time'], 0, 8) : '',
    'timezone' => (string)($row['timezone'] ?? ALARM_API_DEFAULT_TZ),
    'repeat_type' => (string)($row['repeat_type'] ?? 'none'),
    'repeat_interval' => (int)($row['repeat_interval'] ?? 1),
    'repeat_expires' => isset($row['repeat_expires']) && $row['repeat_expires'] !== null && $row['repeat_expires'] !== '' ? (string)$row['repeat_expires'] : null,
    'snooze_minutes' => (int)($row['snooze_minutes'] ?? 0),
    'notify_before' => (int)($row['notify_before'] ?? 0),
    'reminders' => alarm_api_decode_reminders($row['reminders'] ?? [0]),
    'ringtone' => (string)($row['ringtone'] ?? 'alarm'),
    'priority' => (string)($row['priority'] ?? ''),
    'status' => (string)($row['status'] ?? ''),
    'is_active' => (int)($row['is_active'] ?? 1),
  ];
  if ($out['timezone'] === '') $out['timezone'] = ALARM_API_DEFAULT_TZ;
  return $out;
}

function alarm_api_user_row(PDO $pdo, $userId) {
  try {
    $stmt = $pdo->prepare('SELECT user_id, name, email, active, timezone FROM alarm_users WHERE user_id = ? LIMIT 1');
    $stmt->execute([(int)$userId]);
  } catch (PDOException $e) {
    $stmt = $pdo->prepare('SELECT user_id, name, email, active FROM alarm_users WHERE user_id = ? LIMIT 1');
    $stmt->execute([(int)$userId]);
  }
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function alarm_api_default_tz(array $user) {
  $tz = trim((string)($user['timezone'] ?? ''));
  if ($tz === '' || !alarm_api_timezone($tz)) return ALARM_API_DEFAULT_TZ;
  return $tz;
}

function alarm_api_key_row(PDO $pdo, $hash) {
  $withTz = 'SELECT k.key_id, k.user_id, k.revoked_at, u.email, u.name, u.active, u.timezone
    FROM alarm_api_keys k
    INNER JOIN alarm_users u ON u.user_id = k.user_id
    WHERE k.key_hash = ?
    LIMIT 1';
  $plain = 'SELECT k.key_id, k.user_id, k.revoked_at, u.email, u.name, u.active
    FROM alarm_api_keys k
    INNER JOIN alarm_users u ON u.user_id = k.user_id
    WHERE k.key_hash = ?
    LIMIT 1';
  try {
    $stmt = $pdo->prepare($withTz);
    $stmt->execute([$hash]);
  } catch (PDOException $e) {
    $stmt = $pdo->prepare($plain);
    $stmt->execute([$hash]);
  }
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function alarm_api_rate_allow(PDO $pdo, $keyId) {
  $upd = $pdo->prepare('UPDATE alarm_api_keys
    SET rate_count = IF(rate_window_start IS NULL OR rate_window_start <= DATE_SUB(NOW(), INTERVAL 60 SECOND), 1, rate_count + 1),
        rate_window_start = IF(rate_window_start IS NULL OR rate_window_start <= DATE_SUB(NOW(), INTERVAL 60 SECOND), NOW(), rate_window_start),
        last_used_at = NOW()
    WHERE key_id = ? AND revoked_at IS NULL');
  $upd->execute([(int)$keyId]);
  $stmt = $pdo->prepare('SELECT rate_count FROM alarm_api_keys WHERE key_id = ?');
  $stmt->execute([(int)$keyId]);
  return (int)$stmt->fetchColumn() <= ALARM_API_RATE_LIMIT;
}

function alarm_api_list_keys(PDO $pdo, $userId) {
  $stmt = $pdo->prepare('SELECT key_id, label, key_prefix, created_at, last_used_at, revoked_at
    FROM alarm_api_keys WHERE user_id = ? ORDER BY (revoked_at IS NULL) DESC, key_id DESC');
  $stmt->execute([(int)$userId]);
  $keys = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $keys[] = alarm_api_public_key($row);
  return $keys;
}

function alarm_api_create_key(PDO $pdo, $userId, $label) {
  $count = $pdo->prepare('SELECT COUNT(*) FROM alarm_api_keys WHERE user_id = ? AND revoked_at IS NULL');
  $count->execute([(int)$userId]);
  if ((int)$count->fetchColumn() >= ALARM_API_MAX_ACTIVE_KEYS) {
    return alarm_api_err(409, 'key_limit', 'Revoke an existing key first. Each account can have 5 active keys.');
  }
  $made = alarm_api_generate_key();
  $ins = $pdo->prepare('INSERT INTO alarm_api_keys (user_id, label, key_prefix, key_hash, created_at) VALUES (?, ?, ?, ?, NOW())');
  $ins->execute([(int)$userId, alarm_api_clean_label($label), $made['prefix'], $made['hash']]);
  $id = (int)$pdo->lastInsertId();
  $stmt = $pdo->prepare('SELECT key_id, label, key_prefix, created_at, last_used_at, revoked_at FROM alarm_api_keys WHERE key_id = ? AND user_id = ?');
  $stmt->execute([$id, (int)$userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return [
    'ok' => true,
    'status' => 201,
    'api_key' => $made['token'],
    'key' => alarm_api_public_key($row ?: ['key_id' => $id, 'label' => alarm_api_clean_label($label), 'key_prefix' => $made['prefix']]),
    'message' => 'Copy this key now. It will not be shown again.',
  ];
}

function alarm_api_revoke_key(PDO $pdo, $userId, $keyId) {
  if ((int)$keyId < 1) return alarm_api_err(400, 'key_required', 'key_id is required');
  $stmt = $pdo->prepare('SELECT key_id, label, key_prefix, created_at, last_used_at, revoked_at FROM alarm_api_keys WHERE key_id = ? AND user_id = ?');
  $stmt->execute([(int)$keyId, (int)$userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) return alarm_api_err(404, 'not_found', 'Key not found');
  if (empty($row['revoked_at'])) {
    $upd = $pdo->prepare('UPDATE alarm_api_keys SET revoked_at = NOW() WHERE key_id = ? AND user_id = ? AND revoked_at IS NULL');
    $upd->execute([(int)$keyId, (int)$userId]);
    $stmt->execute([(int)$keyId, (int)$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: $row;
  }
  return ['ok' => true, 'key' => alarm_api_public_key($row)];
}

function alarm_api_share_ids(PDO $pdo, array $emails) {
  if (!$emails) return ['ids' => [], 'missing' => []];
  $cols = alarm_api_columns($pdo, 'alarm_users');
  if (!isset($cols['user_id']) || !isset($cols['email'])) return ['ids' => [], 'missing' => $emails];
  $marks = implode(',', array_fill(0, count($emails), '?'));
  $stmt = $pdo->prepare("SELECT user_id, LOWER(TRIM(email)) AS email FROM alarm_users WHERE LOWER(TRIM(email)) IN ($marks)");
  $stmt->execute(array_values($emails));
  $ids = [];
  $found = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $ids[] = (int)$row['user_id'];
    $found[(string)$row['email']] = true;
  }
  $missing = [];
  foreach ($emails as $email) {
    if (empty($found[$email])) $missing[] = $email;
  }
  return ['ids' => $ids, 'missing' => $missing];
}

function alarm_api_fetch_owned(PDO $pdo, $userId, $taskId) {
  $cols = alarm_api_columns($pdo, 'alarm_tasks');
  $names = ['task_id', 'title', 'description', 'task_date', 'task_time', 'timezone', 'repeat_type', 'repeat_interval', 'repeat_expires', 'snooze_minutes', 'notify_before', 'reminders', 'ringtone', 'priority', 'status', 'is_active', 'created_by'];
  $select = [];
  foreach ($names as $name) {
    if (isset($cols[$name])) $select[] = $name;
  }
  if (!isset($cols['task_id']) || !isset($cols['created_by'])) {
    return alarm_api_err(500, 'schema_mismatch', 'alarm_tasks is missing a column this endpoint needs');
  }
  $stmt = $pdo->prepare('SELECT `' . implode('`,`', $select) . '` FROM alarm_tasks WHERE task_id = ? AND created_by = ? LIMIT 1');
  $stmt->execute([(int)$taskId, (int)$userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) return alarm_api_err(404, 'not_found', 'Alarm not found');
  return ['ok' => true, 'alarm' => alarm_api_public_alarm($row)];
}

function alarm_api_list_alarms(PDO $pdo, $userId) {
  $cols = alarm_api_columns($pdo, 'alarm_tasks');
  $names = ['task_id', 'title', 'description', 'task_date', 'task_time', 'timezone', 'repeat_type', 'repeat_interval', 'repeat_expires', 'snooze_minutes', 'notify_before', 'reminders', 'ringtone', 'priority', 'status', 'is_active'];
  $select = [];
  foreach ($names as $name) {
    if (isset($cols[$name])) $select[] = $name;
  }
  if (!isset($cols['task_id']) || !isset($cols['created_by'])) {
    return alarm_api_err(500, 'schema_mismatch', 'alarm_tasks is missing a column this endpoint needs');
  }
  $order = isset($cols['task_date']) ? 'task_date DESC, task_time DESC' : 'task_id DESC';
  $stmt = $pdo->prepare('SELECT `' . implode('`,`', $select) . '` FROM alarm_tasks WHERE created_by = ? ORDER BY ' . $order . ' LIMIT 200');
  $stmt->execute([(int)$userId]);
  $alarms = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $alarms[] = alarm_api_public_alarm($row);
  return ['ok' => true, 'alarms' => $alarms];
}

function alarm_api_delete_alarm(PDO $pdo, $userId, $taskId) {
  if ((int)$taskId < 1) return alarm_api_err(400, 'task_required', 'task_id is required');
  $stmt = $pdo->prepare('DELETE FROM alarm_tasks WHERE task_id = ? AND created_by = ?');
  $stmt->execute([(int)$taskId, (int)$userId]);
  if ($stmt->rowCount() < 1) return alarm_api_err(404, 'not_found', 'Alarm not found');
  return ['ok' => true, 'task_id' => (int)$taskId];
}

function alarm_api_create_alarm(PDO $pdo, array $user, array $body) {
  $userId = (int)$user['user_id'];
  $alarm = alarm_api_validate_alarm($body, alarm_api_default_tz($user));
  if (empty($alarm['ok'])) return $alarm;
  $columns = alarm_api_columns($pdo, 'alarm_tasks');
  $shifted = false;
  if (!isset($columns['timezone']) && $alarm['timezone'] !== ALARM_API_DEFAULT_TZ) {
    $src = alarm_api_timezone($alarm['timezone']);
    $local = $src ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $alarm['task_date'] . ' ' . $alarm['task_time'], $src) : null;
    if ($local) {
      $edm = $local->setTimezone(new DateTimeZone(ALARM_API_DEFAULT_TZ));
      $alarm['task_date'] = $edm->format('Y-m-d');
      $alarm['task_time'] = $edm->format('H:i:s');
      $alarm['timezone'] = ALARM_API_DEFAULT_TZ;
      $shifted = true;
    }
  }
  try {
    alarm_vis_ensure_schema($pdo);
  } catch (PDOException $e) {
    error_log('alarm visibility schema SQLSTATE=' . $e->getCode());
  }
  $directory = alarm_vis_load_directory($pdo);
  $actor = alarm_vis_actor($directory, $userId);
  if (!$actor) return alarm_api_err(401, 'unauthorized', 'Unauthorized');
  $people = alarm_vis_resolve_people($actor, $directory, $alarm['assigned_to_email'], $alarm['shared_emails']);
  if (empty($people['ok'])) {
    $err = $people['error'];
    return alarm_api_err((int)$err['status'], (string)$err['error_code'], (string)$err['error']);
  }
  if ($alarm['response_from_email'] !== '') {
    $from = alarm_vis_resolve_people($actor, $directory, $alarm['response_from_email'], []);
    if (empty($from['ok'])) {
      return alarm_api_err(403, 'not_visible', 'You can only ask for a response from someone on your list.');
    }
  }
  if ($people['assignee']) $alarm['assigned_to_email'] = strtolower(trim((string)$people['assignee']['email']));
  $alarm['shared_emails'] = array_map(function ($row) {
    return strtolower(trim((string)$row['email']));
  }, $people['copies']);
  $shares = ['ids' => array_map(function ($row) { return (int)$row['user_id']; }, $people['copies']), 'missing' => []];
  $wanted = alarm_api_alarm_wanted($alarm, $userId, $columns, (string)($user['email'] ?? ''), $shares['ids']);
  $plan = alarm_api_insert_plan($columns, $wanted);
  if (empty($plan['ok'])) return $plan;
  $taskId = alarm_api_insert_alarm($pdo, $plan['data']);
  $saved = alarm_api_fetch_owned($pdo, $userId, $taskId);
  if (empty($saved['ok'])) {
    $saved = ['ok' => true, 'alarm' => alarm_api_public_alarm(array_merge($plan['data'], ['task_id' => $taskId]))];
  }
  $warnings = [];
  try {
    alarm_vis_replace_recipients($pdo, $taskId, $userId, $people['assignee'], $people['copies']);
  } catch (PDOException $e) {
    error_log('alarm recipients SQLSTATE=' . $e->getCode());
    $warnings[] = 'The alarm was saved, but the assignee and copy list could not be stored for push.';
  }
  if (!isset($columns['is_active'])) {
    $warnings[] = 'alarm_tasks has no is_active column, so the push cron cannot select this row until that column exists.';
  }
  if ($shifted) {
    $warnings[] = 'No timezone column, so the clock was stored as America/Edmonton. That is the time the phone and the push cron use.';
  }
  return [
    'ok' => true,
    'status' => 201,
    'task_id' => $taskId,
    'alarm' => $saved['alarm'],
    'warnings' => $warnings,
  ];
}

function alarm_api_selftest() {
  $fail = function ($message) {
    fwrite(STDERR, "alarm_api selftest failed: {$message}\n");
    exit(1);
  };

  $tz = new DateTimeZone('America/Edmonton');
  $now = new DateTimeImmutable('now', $tz);
  $year = (int)$now->format('Y');
  $winterLocal = null;
  $summerLocal = null;
  for ($i = 0; $i < 3; $i++) {
    $jan = new DateTimeImmutable(($year + $i) . '-01-15 09:30:00', $tz);
    $jul = new DateTimeImmutable(($year + $i) . '-07-16 10:30:00', $tz);
    if ($winterLocal === null && $jan > $now->modify('+1 hour') && $jan < $now->modify('+700 days')) $winterLocal = $jan;
    if ($summerLocal === null && $jul > $now->modify('+1 hour') && $jul < $now->modify('+700 days')) $summerLocal = $jul;
  }
  if (!$winterLocal || !$summerLocal) $fail('could not pick sample dates');
  $winterUtc = $winterLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
  $summerUtc = $summerLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
  $winter = alarm_api_parse_when(['datetime' => $winterUtc], ALARM_API_DEFAULT_TZ);
  if (empty($winter['ok']) || $winter['task_date'] !== $winterLocal->format('Y-m-d') || $winter['task_time'] !== '09:30:00' || $winter['timezone'] !== 'America/Edmonton') {
    $fail('winter ISO conversion');
  }
  $summer = alarm_api_parse_when(['datetime' => $summerUtc, 'timezone' => 'America/Edmonton']);
  if (empty($summer['ok']) || $summer['task_time'] !== '10:30:00') $fail('summer ISO conversion');
  $tomorrow = $now->modify('+2 days');
  $wall = alarm_api_parse_when(['task_date' => $tomorrow->format('Y-m-d'), 'task_time' => '09:30']);
  if (empty($wall['ok']) || $wall['task_time'] !== '09:30:00' || $wall['timezone'] !== 'America/Edmonton' || $wall['task_date'] !== $tomorrow->format('Y-m-d')) $fail('wall clock');
  $naive = alarm_api_parse_when(['datetime' => $tomorrow->format('Y-m-d') . 'T09:45:00', 'timezone' => 'America/Edmonton']);
  if (empty($naive['ok']) || $naive['task_time'] !== '09:45:00') $fail('naive ISO');
  $badDay = alarm_api_parse_when(['task_date' => '2026-02-31', 'task_time' => '10:00']);
  if (!empty($badDay['ok']) || ($badDay['error_code'] ?? '') !== 'invalid_datetime') $fail('rejected real date');
  $badTz = alarm_api_parse_when(['task_date' => '2026-11-16', 'task_time' => '10:00', 'timezone' => 'Not/AZone']);
  if (($badTz['error_code'] ?? '') !== 'invalid_timezone') $fail('rejected timezone');

  $alarm = alarm_api_validate_alarm([
    'title' => 'Call the bank',
    'datetime' => $winterUtc,
    'reminders' => [30, 0, 30],
    'ringtone' => 'alarm',
    'repeat_type' => 'daily',
  ]);
  if (empty($alarm['ok']) || $alarm['task_time'] !== '09:30:00' || $alarm['notify_before'] !== 0 || $alarm['reminders'] !== [0, 30] || $alarm['repeat_interval'] !== 1) {
    $fail('validate alarm');
  }
  $noTitle = alarm_api_validate_alarm(['task_date' => $tomorrow->format('Y-m-d'), 'task_time' => '09:30']);
  if (($noTitle['error_code'] ?? '') !== 'title_required') $fail('title required');
  $badTone = alarm_api_validate_alarm(['title' => 'X', 'task_date' => $tomorrow->format('Y-m-d'), 'task_time' => '09:30', 'ringtone' => 'nope']);
  if (($badTone['error_code'] ?? '') !== 'invalid_ringtone') $fail('ringtone');
  $withId = alarm_api_validate_alarm(['title' => 'X', 'task_date' => $tomorrow->format('Y-m-d'), 'task_time' => '09:30', 'task_id' => 4]);
  if (($withId['error_code'] ?? '') !== 'update_unsupported') $fail('no update');

  $made = alarm_api_generate_key();
  if (strpos($made['token'], 'ap_live_') !== 0) $fail('key prefix');
  if ($made['hash'] !== hash('sha256', $made['token']) || $made['hash'] === $made['token'] || strlen($made['hash']) !== 64) $fail('key hash');
  if (alarm_api_normalize_key($made['token']) !== $made['token']) $fail('normalize accepts key');
  if (alarm_api_normalize_key('Bearer ' . $made['token']) !== '') $fail('normalize rejects bearer wrapper');
  if (alarm_api_normalize_key('ap_live_short') !== '') $fail('normalize rejects short key');
  if (strpos($made['hash'], $made['token']) !== false) $fail('hash must not contain the key');

  $_GET['api_key'] = $made['token'];
  $query = alarm_api_presented_key();
  unset($_GET['api_key']);
  if (($query['error_code'] ?? '') !== 'key_in_query') $fail('query key rejected');

  $cols = [
    'task_id' => ['Field' => 'task_id', 'Type' => 'int', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'],
    'created_by' => ['Field' => 'created_by', 'Type' => 'int', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
    'title' => ['Field' => 'title', 'Type' => 'varchar(10)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
    'task_date' => ['Field' => 'task_date', 'Type' => 'date', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
    'task_time' => ['Field' => 'task_time', 'Type' => 'time', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
    'is_active' => ['Field' => 'is_active', 'Type' => 'tinyint', 'Null' => 'NO', 'Default' => '1', 'Extra' => ''],
    'notes' => ['Field' => 'notes', 'Type' => 'varchar(20)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
    'status' => ['Field' => 'status', 'Type' => "enum('Completed','Pending')", 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
  ];
  if (alarm_api_choose_status($cols['status']) !== 'Pending') $fail('status enum');
  $plan = alarm_api_insert_plan($cols, [
    'created_by' => 7,
    'title' => 'Call the bank today',
    'task_date' => '2026-11-16',
    'task_time' => '09:30:00',
    'is_active' => 1,
    'status' => 'Pending',
    'not_a_column' => 'x',
  ]);
  if (empty($plan['ok'])) $fail('insert plan');
  if ($plan['data']['title'] !== 'Call the b') $fail('varchar clip');
  if (isset($plan['data']['not_a_column']) || isset($plan['data']['task_id'])) $fail('column filter');
  if (($plan['data']['notes'] ?? 'missing') !== '') $fail('filled required column');
  if ((int)$plan['data']['created_by'] !== 7) $fail('owner id');

  $public = alarm_api_public_key([
    'key_id' => 3,
    'label' => 'Home',
    'key_prefix' => 'ap_live_abcd1234',
    'created_at' => '2026-11-15 12:00:00',
    'last_used_at' => null,
    'revoked_at' => null,
    'key_hash' => $made['hash'],
  ]);
  if (isset($public['key_hash']) || isset($public['api_key']) || $public['prefix'] !== 'ap_live_abcd1234') $fail('public key hides secret');

  echo "ok\n";
  exit(0);
}

if (PHP_SAPI === 'cli' && isset($argv) && in_array('--selftest', $argv, true) && isset($argv[0]) && realpath((string)$argv[0]) === __FILE__) {
  alarm_api_selftest();
}
