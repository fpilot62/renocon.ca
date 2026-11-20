<?php
/**
 * Attach pending copy invites to the signed-in user when their email or
 * phone matches. Called after login and while the alarm list is open, so a
 * registration that lives in login.php still links the alarm.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/alarm_api_lib.php';
require_once __DIR__ . '/alarm_invite_lib.php';
alarm_api_cors();

require_once __DIR__ . '/alarm_jwt_helper.php';
$auth = alarm_require_auth();
$uid = (int)($auth['user_id'] ?? 0);
if ($uid < 1) alarm_api_send(alarm_api_err(401, 'unauthorized', 'Unauthorized'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  alarm_api_send(alarm_api_err(405, 'method_not_allowed', 'Use POST'));
}

require_once __DIR__ . '/../../db.php';

try {
  $email = '';
  $phone = '';
  try {
    $stmt = $pdo->prepare('SELECT email, tel FROM alarm_users WHERE user_id = ? AND active = 1 LIMIT 1');
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
  } catch (PDOException $e) {
    $stmt = $pdo->prepare('SELECT email FROM alarm_users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
  }
  if (!$row) alarm_api_send(alarm_api_err(401, 'unauthorized', 'Unauthorized'));
  $email = (string)($row['email'] ?? '');
  $phone = (string)($row['tel'] ?? '');
  $claimed = alarm_invite_claim_user($pdo, $uid, $email, $phone);
  alarm_api_send(['ok' => true, 'claimed' => $claimed]);
} catch (PDOException $e) {
  error_log('claim_invites database error SQLSTATE=' . $e->getCode());
  alarm_api_send(alarm_api_err(500, 'server_error', 'Database error'));
}
