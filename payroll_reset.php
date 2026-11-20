<?php
/**
 * Shared CrewPulse / payroll password reset.
 */
function payroll_request_password_reset(PDO $pdo, string $ident): array {
    $generic = 'If that account exists and has an email, we sent a reset link. It expires in 1 hour.';
    $ident = trim($ident);
    if ($ident === '') {
        return ['ok' => false, 'error' => 'Enter your username, PIN, or email.'];
    }

    require_once __DIR__ . '/mailer.php';

    $stmt = $pdo->prepare(
        "SELECT id, Name, email, user FROM users
         WHERE active = 1 AND (user = ? OR pin = ? OR LOWER(TRIM(email)) = LOWER(?))
         LIMIT 1"
    );
    $stmt->execute([$ident, $ident, $ident]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $email = strtolower(trim((string)($user['email'] ?? '')));
    $hasEmail = $user
        && $email !== ''
        && filter_var($email, FILTER_VALIDATE_EMAIL)
        && !preg_match('/@[^@\s]+\.import\.invalid$/i', $email);

    if ($hasEmail) {
        $token = bin2hex(random_bytes(32));
        $upd = $pdo->prepare("UPDATE users SET reset_token = ?, token_expiry = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?");
        $upd->execute([$token, (int)$user['id']]);
        $link = 'https://payroll.renocon.ca/reset_password.php?token=' . urlencode($token);
        $safeName = htmlspecialchars((string)($user['Name'] ?: $user['user']), ENT_QUOTES, 'UTF-8');
        $html = "<p>Hi {$safeName},</p>"
              . "<p>Use this link to choose a new CrewPulse password. It expires in 1 hour.</p>"
              . "<p><a href=\"{$link}\">Reset password</a></p>"
              . "<p>If you did not ask for this, ignore this email.</p>";
        $sent = send_mail($email, 'Reset your CrewPulse password', $html, (string)($user['Name'] ?? ''));
        if (!$sent['ok']) {
            return ['ok' => false, 'error' => 'Could not send email. Try again later.'];
        }
    }

    return ['ok' => true, 'message' => $generic];
}
