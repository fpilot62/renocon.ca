<?php
/**
 * Path: /employee_setup.php
 * Force email confirm after first login (empty or @*.import.invalid).
 * Optional: set a real password (replacing temporary last-4).
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/employee_selfserve_lib.php';
require_once __DIR__ . '/engine/org_modules_lib.php';

ess_require_login();
$user = ess_load_self($pdo);
if (!$user) {
    header('Location: login_employee.php');
    exit;
}

$needs = ess_email_needs_setup($user['email'] ?? '');
// Already set a real email and not forcing — continue
if (!$needs && empty($_GET['force'])) {
    $dest = sip_resolve_login_destination($pdo, (int)$user['org_id'], (int)$user['user_role']);
    header('Location: ' . $dest);
    exit;
}

$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $new_pass = (string)($_POST['new_password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (ess_email_needs_setup($email)) {
        $error = 'Please use a real email address (not a temporary import address).';
    } elseif ($new_pass !== '' && strlen($new_pass) < 4) {
        $error = 'New password must be at least 4 characters.';
    } elseif ($new_pass !== '' && $new_pass !== $confirm) {
        $error = 'Password confirmation does not match.';
    } else {
        try {
            // Unique email within org (soft check)
            $chk = $pdo->prepare("SELECT id FROM users WHERE org_id = ? AND email = ? AND id <> ? LIMIT 1");
            $chk->execute([(int)$user['org_id'], $email, (int)$user['id']]);
            if ($chk->fetch()) {
                $error = 'That email is already used by another employee in your organization.';
            } else {
                if ($new_pass !== '') {
                    $stmt = $pdo->prepare("UPDATE users SET email = ?, password = ? WHERE id = ?");
                    $stmt->execute([$email, password_hash($new_pass, PASSWORD_DEFAULT), (int)$user['id']]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                    $stmt->execute([$email, (int)$user['id']]);
                }
                $user['email'] = $email;
                $dest = !empty($_POST['goto_profile'])
                    ? 'my_profile.php'
                    : sip_resolve_login_destination($pdo, (int)$user['org_id'], (int)$user['user_role']);
                header('Location: ' . $dest . (strpos($dest, '?') === false ? '?msg=email_saved' : '&msg=email_saved'));
                exit;
            }
        } catch (Throwable $e) {
            $error = 'Could not save. Please try again.';
        }
    }
}

$current_email = ess_email_needs_setup($user['email'] ?? '') ? '' : trim((string)$user['email']);
$continue = sip_resolve_login_destination($pdo, (int)$user['org_id'], (int)$user['user_role']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php if (file_exists(__DIR__ . '/pwa_head.php')) include __DIR__ . '/pwa_head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirm your email</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #1e293b; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 16px; box-sizing: border-box; }
        .box { background: #fff; padding: 36px 32px; border-radius: 12px; width: 100%; max-width: 420px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
        h2 { color: #1e293b; margin: 0 0 8px; font-size: 1.3rem; }
        .sub { color: #64748b; font-size: 13px; margin-bottom: 20px; line-height: 1.45; }
        label { display: block; font-size: 12px; font-weight: 700; color: #475569; margin-top: 10px; }
        input { width: 100%; padding: 11px; margin-top: 5px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; font-size: 16px; }
        button { width: 100%; padding: 12px; background: #0891b2; color: #fff; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; margin-top: 16px; }
        .error { color: #dc2626; background: #fee2e2; padding: 10px; border-radius: 4px; border: 1px solid #fecaca; margin-bottom: 12px; font-size: 13px; }
        .hint { color: #94a3b8; font-size: 12px; margin-top: 6px; }
        .opt { margin-top: 18px; padding-top: 14px; border-top: 1px solid #e2e8f0; }
        .opt h3 { margin: 0 0 6px; font-size: 13px; color: #334155; }
        .row-actions { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
        .btn-secondary { background: #fff; color: #0891b2; border: 1px solid #0891b2; }
    </style>
</head>
<body>
    <div class="box">
        <h2>Confirm your email</h2>
        <p class="sub">Hi <?= htmlspecialchars($user['Name'] ?? '') ?> — set a real email before continuing. You can also change the temporary password (last 4 of your mobile) to something only you know.</p>

        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <label for="email">Email address</label>
            <input type="email" id="email" name="email" required
                   value="<?= htmlspecialchars($_POST['email'] ?? $current_email) ?>"
                   placeholder="you@example.com" autocomplete="email">

            <div class="opt">
                <h3>Change password (optional)</h3>
                <p class="hint">Leave blank to keep your current PIN/password.</p>
                <label for="new_password">New password</label>
                <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="4">
                <label for="confirm_password">Confirm new password</label>
                <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="4">
            </div>

            <div class="row-actions">
                <button type="submit" name="goto_profile" value="0">Save &amp; continue</button>
                <button type="submit" name="goto_profile" value="1" class="btn-secondary">Save &amp; open my profile</button>
            </div>
            <p class="hint" style="margin-top:14px">After setup you can update address, PIN, and upload a Direct Deposit form from <strong>My Profile</strong>. Banking is auto-read for manager verification — account numbers are never edited freely online.</p>
        </form>
        <p class="hint"><a href="<?= htmlspecialchars($continue) ?>">Continue without updating</a></p>
    </div>
</body>
</html>
