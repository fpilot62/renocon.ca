<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../mailer.php';
require_once __DIR__ . '/alarm_public_url.php';

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = $_POST;
$ident = strtolower(trim((string)($body['email'] ?? $body['username'] ?? '')));
$generic = 'If that account exists, we sent a reset link. It expires in 1 hour.';

if ($ident === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Enter your email.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT user_id, name, email FROM alarm_users WHERE active = 1 AND LOWER(TRIM(email)) = ? LIMIT 1");
    $stmt->execute([$ident]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $email = strtolower(trim((string)($user['email'] ?? '')));
    if ($user && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $token = bin2hex(random_bytes(32));
        $upd = $pdo->prepare("UPDATE alarm_users SET reset_token = ?, token_expiry = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE user_id = ?");
        $upd->execute([$token, (int)$user['user_id']]);
        $link = alarmpulse_page_url('reset_password.php?token=' . urlencode($token));
        $safeName = htmlspecialchars((string)($user['name'] ?: $email), ENT_QUOTES, 'UTF-8');
        $html = "<p>Hi {$safeName},</p><p>Use this link to choose a new AlarmPulse password. It expires in 1 hour.</p><p><a href=\"{$link}\">Reset password</a></p><p>If you did not ask for this, ignore this email.</p>";
        $sent = send_mail($email, 'Reset your AlarmPulse password', $html, (string)($user['name'] ?? ''));
        if (!$sent['ok']) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Could not send email. Try again later.']);
            exit;
        }
    }
    echo json_encode(['success' => true, 'message' => $generic]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not send reset email.']);
}
