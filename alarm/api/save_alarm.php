<?php
/**
 * Create or update an alarm for the signed-in user.
 *
 * This is the live save_alarm.php with PR #3 visibility, recipients, and
 * copy-invite storage merged in. The live SMS and calendar-email notices
 * to existing AlarmPulse users are kept. The old path that auto-created
 * an account (md5 password) for an unknown shared email is not: those
 * people must be invited via external_copies and must register.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/alarm_jwt_helper.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/alarm_visibility.php';
require_once __DIR__ . '/alarm_invite_lib.php';
$auth = alarm_require_auth();

$uid  = (int)$auth['user_id'];
$name = $auth['name'] ?? '';

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];
error_log("=== SAVE ALARM INPUT ===");
error_log("title: " . ($body['title'] ?? 'MISSING'));
error_log("task_date: " . ($body['task_date'] ?? 'MISSING'));
error_log("task_time: " . ($body['task_time'] ?? 'MISSING'));
error_log("task_id: " . ($body['task_id'] ?? 'MISSING'));
error_log("assigned_to_email: " . ($body['assigned_to_email'] ?? 'MISSING'));
error_log("shared_emails: " . json_encode($body['shared_emails'] ?? []));
error_log("full body: " . json_encode($body));

$task_id             = (int)($body['task_id']            ?? 0);
$title               = trim($body['title']               ?? '');
$description         = trim($body['description']         ?? '');
$task_date           = $body['task_date']                ?? '';
$task_time           = $body['task_time']                ?? '';
$address             = trim($body['address']             ?? '');
$ringtone            = trim($body['ringtone']            ?? 'alarm');
$repeat_type         = $body['repeat_type']              ?? 'none';
$repeat_interval     = (int)($body['repeat_interval']    ?? 1);
$repeat_days         = $body['repeat_days']              ?? '';
$repeat_expires      = $body['repeat_expires']           ?? null;
$snooze_minutes      = (int)($body['snooze_minutes']     ?? 5);
$notify_before       = (int)($body['notify_before']      ?? 30);
$attachment_url      = trim($body['attachment_url']      ?? '');
$shared_emails       = $body['shared_emails']            ?? [];
$timezone            = $body['timezone']                 ?? 'America/Edmonton';
$reminders           = $body['reminders']                ?? [0];
$assigned_to_email   = trim($body['assigned_to_email']   ?? '');
$response_required   = (int)($body['response_required']  ?? 0);
$response_due        = $body['response_due']             ?? null;
if ($response_due) {
    $response_due = date('Y-m-d H:i:s', strtotime($response_due));
}
$response_from_email = trim($body['response_from_email'] ?? '');

// If shared_emails is a JSON string, decode it
if (is_string($shared_emails)) {
    $shared_emails = json_decode($shared_emails, true) ?? [];
}
if (is_string($reminders)) {
    $reminders = json_decode($reminders, true) ?? [0];
}
if (!is_array($shared_emails)) $shared_emails = [];

$reminders_json = json_encode($reminders);

if (empty($title) || empty($task_date) || empty($task_time)) {
    echo json_encode(['success' => false, 'error' => 'Title, date and time are required']);
    exit;
}

function alarm_save_fail(int $status, string $error, string $code = ''): void {
    http_response_code($status);
    $out = ['success' => false, 'error' => $error];
    if ($code !== '') $out['error_code'] = $code;
    echo json_encode($out);
    exit;
}

function alarm_save_lookup_tels(PDO $pdo, array $userIds): array {
    $tels = [];
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds) return $tels;
    $marks = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $pdo->prepare("SELECT user_id, tel FROM alarm_users WHERE user_id IN ($marks)");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tels[(int)$row['user_id']] = trim((string)($row['tel'] ?? ''));
    }
    return $tels;
}

try {
    alarm_vis_ensure_schema($pdo);
    alarm_invite_ensure_schema($pdo);

    $external = alarm_invite_validate_list($body['external_copies'] ?? []);
    if (empty($external['ok'])) {
        $err = $external['error'];
        alarm_save_fail((int)$err['status'], (string)$err['error'], (string)$err['error_code']);
    }

    $directory = alarm_vis_load_directory($pdo);
    $actor = alarm_vis_actor($directory, $uid);
    if (!$actor) alarm_save_fail(401, 'Unauthorized', 'unauthorized');

    $people = alarm_vis_resolve_people($actor, $directory, $assigned_to_email, $shared_emails);
    if (empty($people['ok'])) {
        $err = $people['error'];
        alarm_save_fail((int)$err['status'], (string)$err['error'], (string)$err['error_code']);
    }
    if ($response_from_email !== '') {
        $from = alarm_vis_resolve_people($actor, $directory, $response_from_email, []);
        if (empty($from['ok'])) {
            alarm_save_fail(403, 'You can only ask for a response from someone on your list.', 'not_visible');
        }
    }
    if ($people['assignee']) {
        $assigned_to_email = strtolower(trim((string)$people['assignee']['email']));
    }

    $alarm_time_fmt = date('g:i A', strtotime($task_time));
    $alarm_date_fmt = date('M j, Y', strtotime($task_date));

    $lookupIds = array_map(function ($row) { return (int)$row['user_id']; }, $people['copies']);
    if ($people['assignee']) $lookupIds[] = (int)$people['assignee']['user_id'];
    $tels = alarm_save_lookup_tels($pdo, $lookupIds);

    $shared_ids   = [];
    $notif_phones = [];
    $notif_emails = [];
    foreach ($people['copies'] as $sUser) {
        $semail = strtolower(trim((string)($sUser['email'] ?? '')));
        $sid = (int)$sUser['user_id'];
        $sname = (string)($sUser['name'] ?? $semail);
        $shared_ids[] = $sid;
        $tel = $tels[$sid] ?? '';
        if ($tel !== '') $notif_phones[] = ['phone' => $tel, 'name' => $sname];
        if ($semail !== '') $notif_emails[] = ['email' => $semail, 'name' => $sname];
    }
    $shared_ids_str = implode(',', $shared_ids);

    $assigned_name = '';
    if ($people['assignee']) {
        $aUser = $people['assignee'];
        $assigned_name = (string)($aUser['name'] ?? '');
        $aid = (int)$aUser['user_id'];
        $tel = $tels[$aid] ?? '';
        if ($tel !== '' && !in_array($tel, array_column($notif_phones, 'phone'), true)) {
            $notif_phones[] = ['phone' => $tel, 'name' => $assigned_name];
        }
        if ($assigned_to_email !== '' && !in_array($assigned_to_email, array_column($notif_emails, 'email'), true)) {
            $notif_emails[] = ['email' => $assigned_to_email, 'name' => $assigned_name];
        }
    }

    error_log("DEBUG shared_ids: " . json_encode($shared_ids));
    error_log("DEBUG notif_emails: " . json_encode($notif_emails));
    error_log("DEBUG shared_emails input: " . json_encode($shared_emails));

    if ($task_id > 0) {
        $upd = $pdo->prepare("UPDATE alarm_tasks SET
            title=?, description=?, task_date=?, task_time=?, address=?,
            ringtone=?, repeat_type=?, repeat_interval=?, repeat_days=?, repeat_expires=?,
            snooze_minutes=?, notify_before=?, attachment_url=?,
            shared_user_ids=?, timezone=?, reminders=?,
            assigned_to_email=?, assigned_email=?,
            response_required=?, response_due=?, response_from_user_id=?
            WHERE task_id=? AND created_by=?");
        $upd->execute([
            $title, $description, $task_date, $task_time, $address,
            $ringtone, $repeat_type, $repeat_interval, $repeat_days, $repeat_expires ?: null,
            $snooze_minutes, $notify_before, $attachment_url,
            $shared_ids_str, $timezone, $reminders_json,
            $assigned_to_email, $assigned_to_email,
            $response_required, $response_due ?: null, $response_from_email ?: null,
            $task_id, $uid
        ]);
        $saved_id = $task_id;
        $action_label = 'updated';
    } else {
        $ins = $pdo->prepare("INSERT INTO alarm_tasks
            (created_by, title, description, task_date, task_time, address,
             ringtone, repeat_type, repeat_interval, repeat_days, repeat_expires,
             snooze_minutes, notify_before, attachment_url,
             shared_user_ids, timezone, status, is_active,
             reminders, assigned_to_email, assigned_email,
             response_required, response_due, response_from_user_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Pending',1,?,?,?,?,?,?)");
        $ins->execute([
            $uid, $title, $description, $task_date, $task_time, $address,
            $ringtone, $repeat_type, $repeat_interval, $repeat_days, $repeat_expires ?: null,
            $snooze_minutes, $notify_before, $attachment_url,
            $shared_ids_str, $timezone,
            $reminders_json, $assigned_to_email, $assigned_to_email,
            $response_required, $response_due ?: null, $response_from_email ?: null
        ]);
        $saved_id = $pdo->lastInsertId();
        $action_label = 'created';
    }

    alarm_vis_replace_recipients($pdo, (int)$saved_id, $uid, $people['assignee'], $people['copies']);
    $invites = alarm_invite_store($pdo, (int)$saved_id, $uid, $external['contacts'], (string)$name, $title);

    // Notify shared + assigned users (existing AlarmPulse accounts only)
    $all_emails = $notif_emails;
    if (!empty($assigned_to_email)) {
        $already = false;
        foreach ($all_emails as $e) {
            if ($e['email'] === $assigned_to_email) { $already = true; break; }
        }
        if (!$already) {
            $all_emails[] = ['email' => $assigned_to_email, 'name' => $assigned_name ?: $assigned_to_email];
        }
    }

    foreach ($notif_phones as $np) {
        sendSMS($np['phone'],
            APP_NAME . ": {$name} assigned you: \"{$title}\" on {$alarm_date_fmt} at {$alarm_time_fmt}."
        );
    }

    foreach ($all_emails as $ne) {
        $resp_line = $response_required
            ? "<p><strong>Response required by:</strong> " . date('M j, Y g:i A', strtotime($response_due)) . "</p>"
            : '';
        sendEmailWithCalendar(
            $ne['email'],
            "AlarmPulse: {$title}",
            "<h2>Alarm Notification</h2>
         <p><strong>{$name}</strong> shared an alarm with you:</p>
         <h3>{$title}</h3>
         <p>{$description}</p>
         <p>📅 {$alarm_date_fmt} at {$alarm_time_fmt}</p>
         {$resp_line}
         <a href='" . APP_URL . "' style='background:#f97316;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;'>Open AlarmPulse</a>",
            $title, $task_date, $task_time, $description, 'admin@renocon.ca'
        );
    }

    echo json_encode([
        'success'  => true,
        'ok'       => true,
        'task_id'  => (int)$saved_id,
        'message'  => "Alarm {$action_label} successfully",
        'notified' => count($notif_phones) + count($notif_emails),
        'invites'  => $invites['invites'] ?? [],
        'warnings' => $invites['warnings'] ?? [],
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    error_log('save_alarm database error SQLSTATE=' . $e->getCode());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
