<?php
/**
 * Path: /login_employee.php
 * Employee self-serve login — requires ?org= (works for any org_id).
 * Auth: mobile OR email (users.email / users.user) + PIN or password, scoped to org.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/employee_selfserve_lib.php';

$org_id = (int)($_GET['org'] ?? $_POST['org'] ?? 0);
$error = '';
$org_name = '';
$login_mode = strtolower(trim((string)($_POST['login_mode'] ?? 'mobile')));
if ($login_mode !== 'email') {
    $login_mode = 'mobile';
}
$ident_value = trim((string)($_POST['ident'] ?? $_POST['mobile'] ?? ''));

if ($org_id > 0) {
    $org_name = ess_org_name($pdo, $org_id);
    if ($org_name === '') {
        $error = 'Organization not found. Check the link from your employer.';
        $org_id = 0;
    }
} else {
    $error = 'This login link requires an organization. Use the link your employer shared (…?org=…).';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $org_id > 0) {
    $ident = $ident_value;
    $secret = (string)($_POST['password'] ?? '');

    // Prefer explicit mode; fall back to @ detection if mode missing.
    $mode = $login_mode;
    if ($mode !== 'email' && $mode !== 'mobile') {
        $mode = (strpos($ident, '@') !== false) ? 'email' : 'mobile';
    }

    if ($ident === '') {
        $error = ($mode === 'email') ? 'Enter your email address.' : 'Enter your mobile number.';
    } elseif ($secret === '') {
        $error = 'Enter your PIN or password.';
    } elseif ($mode === 'email') {
        if (ess_email_needs_setup($ident)) {
            $error = 'Temporary import emails cannot be used to log in. Use your mobile number until you set a real email.';
            $login_mode = 'mobile';
        } elseif (!filter_var($ident, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } else {
            try {
                $matches = ess_find_by_email($pdo, $org_id, $ident);
                if (count($matches) === 0) {
                    $error = 'No active employee found for that email in this organization.';
                } elseif (count($matches) > 1) {
                    $error = 'Multiple matches for that email. Contact your admin.';
                } else {
                    $user = $matches[0];
                    if (ess_email_needs_setup($user['email'] ?? '')) {
                        $error = 'That account still has a temporary email. Use your mobile number to log in, then set a real email.';
                        $login_mode = 'mobile';
                    } elseif (!ess_verify_secret($user, $secret)) {
                        $error = 'Incorrect PIN or password.';
                    } else {
                        ess_establish_session($pdo, $user);
                        $dest = ess_post_login_destination($pdo, $user);
                        header('Location: ' . $dest);
                        exit;
                    }
                }
            } catch (Throwable $e) {
                $error = 'Login failed. Please try again.';
            }
        }
    } else {
        // Mobile mode
        $mobile = ess_phone_digits($ident);
        if (strpos($ident, '@') !== false) {
            $error = 'Switch to Email to log in with an email address.';
        } elseif (strlen($mobile) < 7) {
            $error = 'Enter a valid mobile number.';
        } else {
            try {
                $matches = ess_find_by_mobile($pdo, $org_id, $mobile);
                if (count($matches) === 0) {
                    $error = 'No active employee found for that mobile in this organization.';
                } elseif (count($matches) > 1) {
                    $error = 'Multiple matches for that mobile. Contact your admin.';
                } else {
                    $user = $matches[0];
                    if (!ess_verify_secret($user, $secret)) {
                        $error = 'Incorrect PIN or password.';
                    } else {
                        ess_establish_session($pdo, $user);
                        $dest = ess_post_login_destination($pdo, $user);
                        header('Location: ' . $dest);
                        exit;
                    }
                }
            } catch (Throwable $e) {
                $error = 'Login failed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php if (file_exists(__DIR__ . '/pwa_head.php')) include __DIR__ . '/pwa_head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Employee Login<?= $org_name !== '' ? ' — ' . htmlspecialchars($org_name) : '' ?></title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #1e293b; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 16px; box-sizing: border-box; }
        .login-box { background: white; padding: 36px 32px; border-radius: 12px; width: 100%; max-width: 360px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); text-align: center; }
        h2 { color: #1e293b; margin: 0 0 6px; font-size: 1.35rem; }
        .org-label { color: #64748b; font-size: 13px; margin-bottom: 22px; }
        .mode-toggle { display: flex; gap: 0; margin: 0 0 16px; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; background: #f1f5f9; }
        .mode-toggle label { flex: 1; margin: 0; text-align: center; font-size: 13px; font-weight: 700; color: #64748b; cursor: pointer; padding: 10px 8px; transition: background .15s, color .15s; }
        .mode-toggle input { position: absolute; opacity: 0; pointer-events: none; width: 0; height: 0; }
        .mode-toggle label:has(input:checked) { background: #0891b2; color: #fff; }
        label.field { display: block; text-align: left; font-size: 12px; font-weight: 700; color: #475569; margin-top: 8px; }
        input[type="tel"], input[type="email"], input[type="password"], input[type="text"] { width: 100%; padding: 12px; margin: 6px 0 4px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; font-size: 16px; }
        button { width: 100%; padding: 12px; background: #0891b2; color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; margin-top: 14px; font-size: 15px; }
        button:hover { background: #0e7490; }
        .error { color: #dc2626; background: #fee2e2; padding: 10px; border-radius: 4px; border: 1px solid #fecaca; margin-bottom: 15px; font-size: 13px; text-align: left; }
        .hint { color: #94a3b8; font-size: 12px; margin-top: 16px; line-height: 1.4; }
        a.admin { color: #0891b2; font-size: 12px; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>Employee Login</h2>
        <?php if ($org_name !== ''): ?>
            <div class="org-label"><?= htmlspecialchars($org_name) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($org_id > 0): ?>
        <form method="POST" autocomplete="username" id="ess-login-form">
            <input type="hidden" name="org" value="<?= $org_id ?>">

            <div class="mode-toggle" role="radiogroup" aria-label="Login with">
                <label>
                    <input type="radio" name="login_mode" value="mobile" <?= $login_mode === 'mobile' ? 'checked' : '' ?>>
                    Mobile
                </label>
                <label>
                    <input type="radio" name="login_mode" value="email" <?= $login_mode === 'email' ? 'checked' : '' ?>>
                    Email
                </label>
            </div>

            <label class="field" for="ident" id="ident-label"><?= $login_mode === 'email' ? 'Email' : 'Mobile' ?></label>
            <input
                type="<?= $login_mode === 'email' ? 'email' : 'tel' ?>"
                id="ident"
                name="ident"
                inputmode="<?= $login_mode === 'email' ? 'email' : 'tel' ?>"
                autocomplete="<?= $login_mode === 'email' ? 'email' : 'tel' ?>"
                placeholder="<?= $login_mode === 'email' ? 'you@example.com' : 'Mobile number' ?>"
                value="<?= htmlspecialchars($ident_value) ?>"
                required
                autofocus
            >

            <label class="field" for="password">PIN or password</label>
            <input type="password" id="password" name="password" placeholder="PIN or password" required autocomplete="current-password">
            <button type="submit">LOGIN</button>
        </form>
        <p class="hint">First time? Choose <strong>Mobile</strong> and use the PIN your employer provided (often the last 4 digits of your mobile). After you set a real email, you can choose <strong>Email</strong> to log in with that instead.</p>
        <?php endif; ?>

        <p class="hint" style="margin-top:20px"><a class="admin" href="login_kiosk.php">Admin / username login</a></p>
    </div>
    <?php if ($org_id > 0): ?>
    <script>
    (function () {
        var ident = document.getElementById('ident');
        var label = document.getElementById('ident-label');
        var radios = document.querySelectorAll('input[name="login_mode"]');
        if (!ident || !label || !radios.length) return;

        function applyMode(mode, clearValue) {
            var isEmail = mode === 'email';
            label.textContent = isEmail ? 'Email' : 'Mobile';
            ident.type = isEmail ? 'email' : 'tel';
            ident.inputMode = isEmail ? 'email' : 'tel';
            ident.autocomplete = isEmail ? 'email' : 'tel';
            ident.placeholder = isEmail ? 'you@example.com' : 'Mobile number';
            if (clearValue) {
                ident.value = '';
            }
            ident.focus();
        }

        radios.forEach(function (r) {
            r.addEventListener('change', function () {
                if (r.checked) applyMode(r.value, true);
            });
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
