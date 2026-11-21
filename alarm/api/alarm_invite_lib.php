<?php
/**
 * Copy invites for contacts who are not AlarmPulse users yet.
 *
 * The creator picks one phone contact. We store that name, phone, and email
 * on the alarm and send one signup invite (SMS and email) plus at most one
 * later reminder of that invite. The alarm itself is not texted or emailed
 * at the alarm time. When someone registers with the invited phone or email,
 * they are attached as a normal copy recipient and then get the in-app alarm
 * and push.
 *
 * Rows are readable only by created_by. Providers are env vars. Nothing here
 * reads a secret from the repo.
 *
 *   ALARMPULSE_BASE_URL     default https://alarmpulse.ecommi.com
 *   ALARMPULSE_SIGNUP_URL   default {base}/signup.html
 *   TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_FROM
 *   RESEND_API_KEY, RESEND_FROM
 */

require_once __DIR__ . '/alarm_public_url.php';

function alarm_invite_env(string $name): string {
  $value = getenv($name);
  return is_string($value) ? trim($value) : '';
}

function alarm_invite_signup_url(): string {
  return alarmpulse_signup_url();
}

function alarm_invite_norm_email(string $email): string {
  return strtolower(trim($email));
}

function alarm_invite_digits(string $phone): string {
  return preg_replace('/\D+/', '', $phone) ?? '';
}

function alarm_invite_phone_key(string $phone): string {
  $digits = alarm_invite_digits($phone);
  if (strlen($digits) < 10) return '';
  return substr($digits, -10);
}

function alarm_invite_e164(string $phone): string {
  $digits = alarm_invite_digits($phone);
  if (strlen($digits) === 10) return '+1' . $digits;
  if (strlen($digits) === 11 && $digits[0] === '1') return '+' . $digits;
  if (strlen($digits) >= 10 && strlen($digits) <= 15) return '+' . $digits;
  return '';
}

function alarm_invite_same_person(string $emailA, string $phoneA, string $emailB, string $phoneB): bool {
  $emailA = alarm_invite_norm_email($emailA);
  $emailB = alarm_invite_norm_email($emailB);
  if ($emailA !== '' && $emailA === $emailB) return true;
  $phoneA = alarm_invite_phone_key($phoneA);
  $phoneB = alarm_invite_phone_key($phoneB);
  return $phoneA !== '' && $phoneA === $phoneB;
}

function alarm_invite_clip(string $text, int $max): string {
  $text = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '');
  if (function_exists('mb_substr')) return mb_substr($text, 0, $max, 'UTF-8');
  return substr($text, 0, $max);
}

function alarm_invite_status_label(string $status): string {
  if ($status === 'registered') return 'Registered';
  return 'Invited, not registered yet';
}

/**
 * Invite and the one follow-up. Both say a reminder is waiting in the app.
 * Neither says the alarm is due now, and neither includes a clock time.
 */
function alarm_invite_compose(string $sender, string $title, string $signupUrl, string $kind): array {
  $sender = alarm_invite_clip($sender, 80);
  if ($sender === '') $sender = 'Someone';
  $title = alarm_invite_clip($title, 80);
  if ($title === '') $title = 'a reminder';
  $signupUrl = trim($signupUrl);
  if ($signupUrl === '') $signupUrl = alarm_invite_signup_url();
  $optOut = 'Reply STOP to opt out.';
  if ($kind === 'reminder') {
    $sms = $sender . ' still has a reminder waiting for you in AlarmPulse ("' . $title . '"). Register to see it: ' . $signupUrl . "\n" . $optOut;
    $subject = $sender . ' still has a reminder waiting in AlarmPulse';
  } else {
    $sms = $sender . ' invited you to AlarmPulse. A reminder is waiting for you there ("' . $title . '"). It shows up after you register: ' . $signupUrl . "\n" . $optOut;
    $subject = $sender . ' invited you to AlarmPulse';
  }
  $email = $sms . "\n\nIf you were not expecting this, ignore it. This is not the reminder itself. AlarmPulse will not text or email you when it is time.";
  return ['sms' => $sms, 'subject' => $subject, 'email' => $email];
}

function alarm_invite_validate_list($raw): array {
  if ($raw === null || $raw === '') $raw = [];
  if (is_string($raw)) {
    $decoded = json_decode($raw, true);
    $raw = is_array($decoded) ? $decoded : [];
  }
  if (!is_array($raw)) {
    return ['ok' => false, 'error' => ['status' => 400, 'error_code' => 'invalid_contact', 'error' => 'External copies must be a list.']];
  }
  $out = [];
  $seen = [];
  foreach ($raw as $item) {
    if (!is_array($item)) {
      return ['ok' => false, 'error' => ['status' => 400, 'error_code' => 'invalid_contact', 'error' => 'Each external copy needs a name and a phone or email.']];
    }
    $name = alarm_invite_clip((string)($item['name'] ?? ''), 120);
    $email = alarm_invite_norm_email((string)($item['email'] ?? ''));
    $phone = alarm_invite_e164((string)($item['phone'] ?? $item['number'] ?? ''));
    if ($name === '') {
      return ['ok' => false, 'error' => ['status' => 400, 'error_code' => 'invalid_contact', 'error' => 'Each external copy needs a name.']];
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return ['ok' => false, 'error' => ['status' => 400, 'error_code' => 'invalid_contact', 'error' => 'Enter a valid email for the contact.']];
    }
    if ($phone === '' && $email === '') {
      return ['ok' => false, 'error' => ['status' => 400, 'error_code' => 'invalid_contact', 'error' => 'Pick a phone number or email for ' . $name . '.']];
    }
    if ((string)($item['phone'] ?? $item['number'] ?? '') !== '' && $phone === '') {
      return ['ok' => false, 'error' => ['status' => 400, 'error_code' => 'invalid_contact', 'error' => 'That phone number is too short.']];
    }
    $key = $email . '|' . alarm_invite_phone_key($phone);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $out[] = ['name' => $name, 'email' => $email, 'phone' => $phone, 'phone_key' => alarm_invite_phone_key($phone)];
  }
  if (count($out) > 10) {
    return ['ok' => false, 'error' => ['status' => 400, 'error_code' => 'invalid_contact', 'error' => 'Copy at most 10 people who are not on AlarmPulse.']];
  }
  return ['ok' => true, 'contacts' => $out];
}

function alarm_invite_visible(array $rows, int $viewerId): array {
  $out = [];
  foreach ($rows as $row) {
    if ((int)($row['created_by'] ?? 0) !== $viewerId) continue;
    $out[] = $row;
  }
  return $out;
}

function alarm_invite_ensure_schema(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS alarm_copy_invites (
    invite_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id INT NOT NULL,
    created_by INT NOT NULL,
    contact_name VARCHAR(120) NOT NULL DEFAULT '',
    phone VARCHAR(32) NOT NULL DEFAULT '',
    email VARCHAR(190) NOT NULL DEFAULT '',
    phone_key VARCHAR(20) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'invited',
    claimed_user_id INT NULL,
    invite_sent_at DATETIME NULL,
    reminder_sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (invite_id),
    KEY idx_alarm_copy_invites_task (task_id, created_by),
    KEY idx_alarm_copy_invites_email (email),
    KEY idx_alarm_copy_invites_phone (phone_key),
    KEY idx_alarm_copy_invites_status (status, invite_sent_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function alarm_invite_public(array $row): array {
  $status = (string)($row['status'] ?? 'invited');
  return [
    'invite_id' => (int)($row['invite_id'] ?? 0),
    'task_id' => (int)($row['task_id'] ?? 0),
    'name' => (string)($row['contact_name'] ?? $row['name'] ?? ''),
    'phone' => (string)($row['phone'] ?? ''),
    'email' => (string)($row['email'] ?? ''),
    'status' => $status,
    'status_label' => alarm_invite_status_label($status),
  ];
}

function alarm_invite_http(string $url, array $headers, string $body, string $user, string $pass): array {
  $host = strtolower((string)parse_url($url, PHP_URL_HOST));
  if ($host === 'api.twilio.com' || $host === 'api.resend.com') {
    require_once __DIR__ . '/../../engine/staging.php';
    $channel = $host === 'api.twilio.com' ? 'sms' : 'mail';
    if (payroll_staging_outbound_blocked($channel)) {
      return ['ok' => false, 'status' => 0, 'error' => 'staging_blocked'];
    }
  }
  if (!function_exists('curl_init')) return ['ok' => false, 'status' => 0, 'error' => 'unavailable'];
  $ch = curl_init($url);
  if ($ch === false) return ['ok' => false, 'status' => 0, 'error' => 'unavailable'];
  $opts = [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
  ];
  if ($user !== '') {
    $opts[CURLOPT_USERPWD] = $user . ':' . $pass;
  }
  curl_setopt_array($ch, $opts);
  $raw = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $ok = $status >= 200 && $status < 300;
  return ['ok' => $ok, 'status' => $status, 'error' => $ok ? '' : 'http_' . $status];
}

function alarm_invite_send_sms(string $to, string $text): array {
  require_once __DIR__ . '/../../engine/staging.php';
  if (payroll_staging_outbound_blocked('sms')) {
    return ['ok' => false, 'skipped' => true, 'error' => 'staging_blocked'];
  }
  $sid = alarm_invite_env('TWILIO_ACCOUNT_SID');
  $token = alarm_invite_env('TWILIO_AUTH_TOKEN');
  $from = alarm_invite_env('TWILIO_FROM');
  if ($sid === '' || $token === '' || $from === '') return ['ok' => false, 'skipped' => true, 'error' => 'sms_not_configured'];
  if ($to === '') return ['ok' => false, 'skipped' => true, 'error' => 'no_phone'];
  $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';
  $result = alarm_invite_http($url, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
    'From' => $from,
    'To' => $to,
    'Body' => $text,
  ]), $sid, $token);
  $result['skipped'] = false;
  return $result;
}

function alarm_invite_send_email(string $to, string $subject, string $text): array {
  require_once __DIR__ . '/../../engine/staging.php';
  if (payroll_staging_outbound_blocked('mail')) {
    return ['ok' => false, 'skipped' => true, 'error' => 'staging_blocked'];
  }
  $key = alarm_invite_env('RESEND_API_KEY');
  $from = alarm_invite_env('RESEND_FROM');
  if ($key === '' || $from === '') return ['ok' => false, 'skipped' => true, 'error' => 'email_not_configured'];
  if ($to === '') return ['ok' => false, 'skipped' => true, 'error' => 'no_email'];
  $payload = json_encode(['from' => $from, 'to' => [$to], 'subject' => $subject, 'text' => $text]);
  $result = alarm_invite_http('https://api.resend.com/emails', [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $key,
  ], $payload === false ? '{}' : $payload, '', '');
  $result['skipped'] = false;
  return $result;
}

function alarm_invite_deliver(array $contact, string $sender, string $title, string $kind): array {
  $message = alarm_invite_compose($sender, $title, alarm_invite_signup_url(), $kind);
  $sms = alarm_invite_send_sms((string)$contact['phone'], $message['sms']);
  $email = alarm_invite_send_email((string)$contact['email'], $message['subject'], $message['email']);
  $sent = !empty($sms['ok']) || !empty($email['ok']);
  $warnings = [];
  if (!$sent) {
    $warnings[] = 'Invite for ' . $contact['name'] . ' is saved. SMS and email are not configured, so it was not sent.';
  } else {
    if (empty($sms['ok']) && empty($sms['skipped']) && $contact['phone'] !== '') {
      $warnings[] = 'Text to ' . $contact['name'] . ' was not accepted.';
    }
    if (empty($email['ok']) && empty($email['skipped']) && $contact['email'] !== '') {
      $warnings[] = 'Email to ' . $contact['name'] . ' was not accepted.';
    }
  }
  return ['sent' => $sent, 'warnings' => $warnings];
}

function alarm_invite_store(PDO $pdo, int $taskId, int $ownerId, array $contacts, string $sender, string $title): array {
  alarm_invite_ensure_schema($pdo);
  $existing = $pdo->prepare('SELECT invite_id, email, phone_key, status, invite_sent_at FROM alarm_copy_invites WHERE task_id = ? AND created_by = ?');
  $existing->execute([$taskId, $ownerId]);
  $rows = $existing->fetchAll(PDO::FETCH_ASSOC);
  $keep = [];
  $warnings = [];
  foreach ($contacts as $contact) {
    $match = null;
    foreach ($rows as $row) {
      if ((string)$row['status'] === 'registered') {
        if (alarm_invite_same_person((string)$row['email'], (string)$row['phone_key'], $contact['email'], $contact['phone_key'])) {
          $match = $row;
          break;
        }
        continue;
      }
      if (alarm_invite_same_person((string)$row['email'], (string)$row['phone_key'], $contact['email'], $contact['phone_key'])) {
        $match = $row;
        break;
      }
    }
    if ($match) {
      $keep[(int)$match['invite_id']] = true;
      if ((string)$match['status'] !== 'registered' && empty($match['invite_sent_at'])) {
        $delivery = alarm_invite_deliver($contact, $sender, $title, 'invite');
        $warnings = array_merge($warnings, $delivery['warnings']);
        if ($delivery['sent']) {
          $pdo->prepare('UPDATE alarm_copy_invites SET contact_name = ?, phone = ?, email = ?, phone_key = ?, invite_sent_at = NOW(), status = ? WHERE invite_id = ? AND created_by = ?')
            ->execute([$contact['name'], $contact['phone'], $contact['email'], $contact['phone_key'], 'invited', (int)$match['invite_id'], $ownerId]);
        }
      }
      continue;
    }
    $delivery = alarm_invite_deliver($contact, $sender, $title, 'invite');
    $warnings = array_merge($warnings, $delivery['warnings']);
    $pdo->prepare('INSERT INTO alarm_copy_invites (task_id, created_by, contact_name, phone, email, phone_key, status, invite_sent_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ' . ($delivery['sent'] ? 'NOW()' : 'NULL') . ', NOW())')
      ->execute([$taskId, $ownerId, $contact['name'], $contact['phone'], $contact['email'], $contact['phone_key'], 'invited']);
    $keep[(int)$pdo->lastInsertId()] = true;
  }
  foreach ($rows as $row) {
    $id = (int)$row['invite_id'];
    if (isset($keep[$id]) || (string)$row['status'] === 'registered') continue;
    $pdo->prepare('DELETE FROM alarm_copy_invites WHERE invite_id = ? AND created_by = ? AND status <> ?')->execute([$id, $ownerId, 'registered']);
  }
  return ['ok' => true, 'warnings' => $warnings, 'invites' => alarm_invite_list($pdo, $ownerId, $taskId)];
}

function alarm_invite_list(PDO $pdo, int $ownerId, int $taskId = 0): array {
  alarm_invite_ensure_schema($pdo);
  if ($taskId > 0) {
    $stmt = $pdo->prepare('SELECT invite_id, task_id, created_by, contact_name, phone, email, status FROM alarm_copy_invites WHERE created_by = ? AND task_id = ? ORDER BY invite_id ASC');
    $stmt->execute([$ownerId, $taskId]);
  } else {
    $stmt = $pdo->prepare('SELECT invite_id, task_id, created_by, contact_name, phone, email, status FROM alarm_copy_invites WHERE created_by = ? ORDER BY invite_id DESC LIMIT 200');
    $stmt->execute([$ownerId]);
  }
  $out = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $out[] = alarm_invite_public($row);
  return $out;
}

function alarm_invite_attach_copy(PDO $pdo, int $taskId, int $userId): void {
  if ($taskId < 1 || $userId < 1) return;
  $own = $pdo->prepare('SELECT created_by FROM alarm_tasks WHERE task_id = ? LIMIT 1');
  $own->execute([$taskId]);
  $ownerId = (int)$own->fetchColumn();
  if ($ownerId < 1 || $ownerId === $userId) return;
  $exists = $pdo->prepare('SELECT recipient_id FROM alarm_task_recipients WHERE task_id = ? AND user_id = ? AND kind = ? LIMIT 1');
  $exists->execute([$taskId, $userId, 'copy']);
  if ($exists->fetch(PDO::FETCH_ASSOC)) return;
  $pdo->prepare('INSERT INTO alarm_task_recipients (task_id, user_id, kind, created_at) VALUES (?, ?, ?, NOW())')->execute([$taskId, $userId, 'copy']);
}

function alarm_invite_claim_user(PDO $pdo, int $userId, string $email, string $phone): int {
  if ($userId < 1) return 0;
  alarm_invite_ensure_schema($pdo);
  $stmt = $pdo->query("SELECT invite_id, task_id, created_by, email, phone, phone_key, status FROM alarm_copy_invites WHERE status <> 'registered'");
  $claimed = 0;
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if ((int)$row['created_by'] === $userId) continue;
    if (!alarm_invite_same_person((string)$row['email'], (string)($row['phone'] ?: $row['phone_key']), $email, $phone)) continue;
    $upd = $pdo->prepare("UPDATE alarm_copy_invites SET status = 'registered', claimed_user_id = ? WHERE invite_id = ? AND status <> 'registered'");
    $upd->execute([$userId, (int)$row['invite_id']]);
    if ($upd->rowCount() < 1) continue;
    alarm_invite_attach_copy($pdo, (int)$row['task_id'], $userId);
    $claimed++;
  }
  return $claimed;
}

function alarm_invite_claim_known(PDO $pdo): int {
  alarm_invite_ensure_schema($pdo);
  try {
    $users = $pdo->query('SELECT user_id, email, tel FROM alarm_users WHERE active = 1')->fetchAll(PDO::FETCH_ASSOC);
  } catch (PDOException $e) {
    $users = $pdo->query('SELECT user_id, email FROM alarm_users WHERE active = 1')->fetchAll(PDO::FETCH_ASSOC);
  }
  $claimed = 0;
  foreach ($users as $user) {
    $claimed += alarm_invite_claim_user($pdo, (int)$user['user_id'], (string)($user['email'] ?? ''), (string)($user['tel'] ?? ''));
  }
  return $claimed;
}

function alarm_invite_cron(PDO $pdo): array {
  $stats = ['claimed' => 0, 'sent' => 0, 'reminded' => 0];
  try {
    alarm_invite_ensure_schema($pdo);
  } catch (PDOException $e) {
    return $stats;
  }
  try {
    $stats['claimed'] = alarm_invite_claim_known($pdo);
  } catch (PDOException $e) {
    error_log('invite claim SQLSTATE=' . $e->getCode());
  }
  $pending = $pdo->query("SELECT i.invite_id, i.contact_name, i.phone, i.email, i.invite_sent_at, i.reminder_sent_at, i.status, t.title, u.name AS sender_name
    FROM alarm_copy_invites i
    INNER JOIN alarm_tasks t ON t.task_id = i.task_id
    INNER JOIN alarm_users u ON u.user_id = i.created_by
    WHERE i.status <> 'registered'
    ORDER BY i.invite_id ASC
    LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($pending as $row) {
    $contact = [
      'name' => (string)$row['contact_name'],
      'phone' => (string)$row['phone'],
      'email' => (string)$row['email'],
    ];
    $sender = (string)($row['sender_name'] ?? '');
    $title = (string)($row['title'] ?? '');
    if (empty($row['invite_sent_at'])) {
      $delivery = alarm_invite_deliver($contact, $sender, $title, 'invite');
      if ($delivery['sent']) {
        $pdo->prepare('UPDATE alarm_copy_invites SET invite_sent_at = NOW(), status = ? WHERE invite_id = ? AND invite_sent_at IS NULL')
          ->execute(['invited', (int)$row['invite_id']]);
        $stats['sent']++;
      }
      continue;
    }
    if (!empty($row['reminder_sent_at']) || (string)$row['status'] === 'reminded') continue;
    $sentAt = strtotime((string)$row['invite_sent_at']);
    if ($sentAt === false || $sentAt > time() - 86400) continue;
    $delivery = alarm_invite_deliver($contact, $sender, $title, 'reminder');
    if ($delivery['sent']) {
      $pdo->prepare("UPDATE alarm_copy_invites SET reminder_sent_at = NOW(), status = 'reminded' WHERE invite_id = ? AND reminder_sent_at IS NULL")
        ->execute([(int)$row['invite_id']]);
      $stats['reminded']++;
    }
  }
  return $stats;
}

function alarm_invite_selftest(): void {
  $fail = function ($message) {
    fwrite(STDERR, "alarm_invite selftest failed: {$message}\n");
    exit(1);
  };
  if (!alarm_invite_same_person('Ali@Example.com', '', 'ali@example.com', '')) $fail('email match is case insensitive');
  if (!alarm_invite_same_person('', '(780) 555-0199', '', '+1 780-555-0199')) $fail('phone match ignores formatting');
  if (alarm_invite_same_person('', '', '', '')) $fail('blank contacts must not match each other');
  if (alarm_invite_same_person('a@x.com', '7805550100', 'b@y.com', '4035550199')) $fail('different people must not match');

  $signup = 'https://alarmpulse.ecommi.com/signup.html';
  $savedSignup = getenv('ALARMPULSE_SIGNUP_URL');
  $savedBase = getenv('ALARMPULSE_BASE_URL');
  putenv('ALARMPULSE_SIGNUP_URL');
  putenv('ALARMPULSE_BASE_URL');
  if (alarm_invite_signup_url() !== $signup) $fail('default signup url');
  putenv('ALARMPULSE_BASE_URL=https://payroll.renocon.ca/alarm');
  if (alarm_invite_signup_url() !== 'https://payroll.renocon.ca/alarm/signup.html') $fail('old host still builds a signup url');
  if ($savedBase === false) putenv('ALARMPULSE_BASE_URL');
  else putenv('ALARMPULSE_BASE_URL=' . $savedBase);
  if ($savedSignup === false) putenv('ALARMPULSE_SIGNUP_URL');
  else putenv('ALARMPULSE_SIGNUP_URL=' . $savedSignup);

  $invite = alarm_invite_compose('Ada Admin', 'Call the supplier', $signup, 'invite');
  foreach (['Ada Admin', 'Call the supplier', $signup, 'Reply STOP', 'register'] as $need) {
    if (stripos($invite['sms'], $need) === false) $fail('invite sms missing ' . $need);
  }
  if (stripos($invite['sms'], 'due now') !== false || preg_match('/\b\d{1,2}:\d{2}\b/', $invite['sms'])) $fail('invite must not be an alarm-time message');
  $legacy = alarm_invite_compose('Ada Admin', 'Call the supplier', 'https://payroll.renocon.ca/alarm/signup.html', 'invite');
  if (strpos($legacy['sms'], 'https://payroll.renocon.ca/alarm/signup.html') === false) $fail('a passed-in payroll signup url is kept');
  $again = alarm_invite_compose('Ada Admin', 'Call the supplier', $signup, 'reminder');
  if (stripos($again['sms'], 'still has a reminder') === false || stripos($again['email'], 'not the reminder itself') === false) $fail('follow-up is one invite reminder');
  if (preg_match('/\b\d{1,2}:\d{2}\b/', $again['sms'])) $fail('follow-up must not include a clock time');

  $bad = alarm_invite_validate_list([['name' => 'Jo']]);
  if (!empty($bad['ok'])) $fail('contact needs a phone or email');
  $ok = alarm_invite_validate_list([['name' => 'Jo Patel', 'phone' => '780-555-0100', 'email' => 'Jo@Patel.test']]);
  if (empty($ok['ok']) || $ok['contacts'][0]['email'] !== 'jo@patel.test' || $ok['contacts'][0]['phone'] !== '+17805550100') $fail('contact normalized');

  $rows = [
    ['created_by' => 4, 'contact_name' => 'Jo', 'phone' => '+17805550100', 'email' => 'jo@patel.test'],
    ['created_by' => 9, 'contact_name' => 'Hidden', 'phone' => '+17805550199', 'email' => 'hidden@x.test'],
  ];
  $mine = alarm_invite_visible($rows, 4);
  if (count($mine) !== 1 || $mine[0]['contact_name'] !== 'Jo') $fail('invites are visible only to the creator');
  if (alarm_invite_status_label('invited') !== 'Invited, not registered yet') $fail('status label');

  echo "ok\n";
  exit(0);
}

if (PHP_SAPI === 'cli' && isset($argv) && in_array('--selftest', $argv, true) && isset($argv[0]) && realpath((string)$argv[0]) === __FILE__) {
  alarm_invite_selftest();
}
