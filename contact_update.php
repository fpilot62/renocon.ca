<?php
/** Save a confirmed email or mobile. Does not change password, PIN, role, or membership. */
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/contact_lib.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}
if (!contact_check_csrf()) {
    http_response_code(400);
    echo 'The form expired. Go back and try again.';
    exit;
}

$action = (string) ($_POST['do'] ?? '');
$back = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$parts = parse_url($back);
$path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
$same = is_array($parts)
    && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
    && ($parts['host'] ?? '') === $host
    && strpos($path, '/') === 0;
if (!$same || contact_hidden_script_path($path)) {
    $back = '/employee_hub.php';
}

if ($action === 'skip') {
    unset($_SESSION['contact_prompt'], $_SESSION['contact_pending']);
    header('Location: ' . $back);
    exit;
}

$uid = (int) $_SESSION['user_id'];
$stmt = $pdo->prepare('SELECT id, email, Tel_Mobile, org_id FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$uid]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    header('Location: /login.php');
    exit;
}

if ($action === 'send') {
    $typed_email = trim((string) ($_POST['email'] ?? ''));
    $typed_phone = trim((string) ($_POST['phone'] ?? ''));
    $new_email = contact_real_email($typed_email);
    $new_phone = contact_real_phone($typed_phone);
    $current_email = contact_real_email($user['email'] ?? '');
    $email_changed = $new_email !== '' && $new_email !== $current_email;
    $phone_changed = $new_phone !== '' && contact_digits($new_phone) !== contact_digits($user['Tel_Mobile'] ?? '');
    if ($typed_email !== '' && $new_email === '') {
        $_SESSION['contact_notice'] = 'Enter a real email address.';
    } elseif ($typed_phone !== '' && $new_phone === '') {
        $_SESSION['contact_notice'] = 'Enter a mobile number.';
    } elseif (!$email_changed && !$phone_changed) {
        $_SESSION['contact_notice'] = 'That email and mobile are already on file.';
    } else {
        $send_to = contact_send_target($new_email, $email_changed, (string) ($user['email'] ?? ''));
        if ($send_to === '') {
            $_SESSION['contact_notice'] = 'Add an email so we can send a confirmation code. Text messages are not set up, so we verify by email only.';
        } else {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            if (!contact_mail_code($send_to, $code)) {
                $_SESSION['contact_notice'] = 'The confirmation email was not sent. Nothing was changed.';
            } else {
                contact_store_pending($email_changed ? $new_email : '', $phone_changed ? $new_phone : '', $code);
                $_SESSION['contact_notice'] = 'We sent a code to your email. Your current email stays active until you confirm it. Text messages are not set up, so this code is email only.';
            }
        }
    }
    header('Location: ' . $back);
    exit;
}

if ($action === 'confirm') {
    $pending = contact_pending();
    $entered = contact_digits($_POST['code'] ?? '');
    if ($pending === null) {
        $_SESSION['contact_notice'] = 'Send a new code, then enter it here.';
    } elseif (strlen($entered) !== 6 || !hash_equals((string) $pending['hash'], hash('sha256', $entered))) {
        $pending['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;
        if ($pending['attempts'] >= 8) {
            unset($_SESSION['contact_pending']);
            $_SESSION['contact_notice'] = 'That code is no longer valid. Send a new one.';
        } else {
            $_SESSION['contact_pending'] = $pending;
            $_SESSION['contact_notice'] = 'That code does not match. Your current email was not changed.';
        }
    } else {
        $error = contact_apply($pdo, $uid, $pending);
        if ($error !== '') {
            $_SESSION['contact_notice'] = $error;
        } else {
            unset($_SESSION['contact_pending'], $_SESSION['contact_prompt']);
            $_SESSION['contact_notice'] = 'Email and mobile saved.';
        }
    }
    header('Location: ' . $back);
    exit;
}

header('Location: ' . $back);
exit;

function contact_hidden_script_path(string $path): bool {
    $base = strtolower(basename($path));
    return strpos($base, 'kiosk') !== false || strpos($base, 'punch') !== false;
}
