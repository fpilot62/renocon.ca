<?php
/** Email and mobile confirmation. Does not change passwords, PINs, roles, or memberships. */

if (!function_exists('contact_digits')) {
    function contact_digits($value): string {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    function contact_real_email($email): string {
        $email = strtolower(trim((string) $email));
        if ($email === '' || strpos($email, '@') === false || preg_match('/[\r\n]/', $email)) {
            return '';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        $domain = substr($email, strrpos($email, '@') + 1);
        if (preg_match('/\.import\.invalid$/i', $domain)) {
            return '';
        }
        $blocked = ['example.com', 'example.org', 'example.net', 'test.com', 'mailinator.com', 'localhost', 'fake.com'];
        if (in_array($domain, $blocked, true)) {
            return '';
        }
        return $email;
    }

    function contact_real_phone($phone): string {
        $phone = trim(str_replace(["\r", "\n"], '', (string) $phone));
        return strlen(contact_digits($phone)) >= 7 ? $phone : '';
    }

    function contact_needs_prompt(array $user): bool {
        return contact_real_email($user['email'] ?? '') === '' || contact_real_phone($user['Tel_Mobile'] ?? '') === '';
    }

    function contact_hidden_script(): bool {
        $base = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        if (strpos($base, 'kiosk') !== false || strpos($base, 'punch') !== false || strpos($base, 'clock') !== false) {
            return true;
        }
        return in_array($base, ['login.php', 'login_employee.php', 'login_kiosk.php', 'login_kiosk1.php'], true);
    }

    function contact_csrf(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['contact_csrf'])) {
            $_SESSION['contact_csrf'] = bin2hex(random_bytes(16));
        }
        return (string) $_SESSION['contact_csrf'];
    }

    function contact_check_csrf(): bool {
        $sent = (string) ($_POST['csrf'] ?? '');
        $saved = (string) ($_SESSION['contact_csrf'] ?? '');
        return $sent !== '' && $saved !== '' && hash_equals($saved, $sent);
    }

    function contact_send_target(string $new_email, bool $email_changed, string $current_email): string {
        if ($email_changed) {
            return contact_real_email($new_email);
        }
        return contact_real_email($current_email);
    }

    function contact_mail_code(string $email, string $code): bool {
        $target = contact_real_email($email);
        if ($target === '' || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $body = "Your confirmation code is {$code}.\n\n"
            . "It expires in 15 minutes. Your current email stays active until you enter this code.\n\n"
            . "Text messages are not set up, so this code is sent by email only.\n\n"
            . "If you did not ask for this, ignore this email.\n";
        $headers = "From: eCommi <noreply@ecommi.com>\r\nContent-Type: text/plain; charset=utf-8";
        return mail($target, 'Confirm your email and mobile', $body, $headers);
    }

    function contact_store_pending(string $email, string $phone, string $code): void {
        $_SESSION['contact_pending'] = [
            'email' => $email,
            'phone' => $phone,
            'hash' => hash('sha256', $code),
            'exp' => time() + 900,
            'attempts' => 0,
        ];
    }

    function contact_pending(): ?array {
        $pending = $_SESSION['contact_pending'] ?? null;
        if (!is_array($pending)) {
            return null;
        }
        if ((int) ($pending['exp'] ?? 0) < time()) {
            unset($_SESSION['contact_pending']);
            return null;
        }
        return $pending;
    }

    function contact_apply(PDO $pdo, int $user_id, array $pending): string {
        $email = contact_real_email($pending['email'] ?? '');
        $phone = contact_real_phone($pending['phone'] ?? '');
        if ($user_id <= 0 || ($email === '' && $phone === '')) {
            return 'Nothing to save.';
        }
        $user = $pdo->prepare('SELECT org_id FROM users WHERE id = ? LIMIT 1');
        $user->execute([$user_id]);
        $org_id = (int) $user->fetchColumn();
        if ($email !== '') {
            $chk = $pdo->prepare('SELECT id FROM users WHERE org_id = ? AND LOWER(TRIM(email)) = ? AND id <> ? LIMIT 1');
            $chk->execute([$org_id, $email, $user_id]);
            if ($chk->fetch()) {
                return 'That email is already used by another person in your organization.';
            }
        }
        $sets = [];
        $params = [];
        if ($email !== '') {
            $sets[] = 'email = ?';
            $params[] = $email;
        }
        if ($phone !== '') {
            $sets[] = 'Tel_Mobile = ?';
            $params[] = $phone;
        }
        $params[] = $user_id;
        $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        contact_sync_erp($user_id, $email, $phone);
        return '';
    }

    function contact_sync_erp(int $payroll_user_id, string $email, string $phone): void {
        if ($payroll_user_id <= 0 || ($email === '' && $phone === '')) {
            return;
        }
        try {
            $erp = new PDO('sqlite:/home/renocon/apps/erp/data/erp.db');
            $erp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $sets = [];
            $params = [];
            if ($email !== '') {
                $sets[] = 'email = ?';
                $params[] = $email;
            }
            if ($phone !== '') {
                $sets[] = 'phone = ?';
                $params[] = $phone;
            }
            $params[] = $payroll_user_id;
            $erp->prepare('UPDATE clock_person SET ' . implode(', ', $sets) . ' WHERE payroll_user_id = ?')->execute($params);
        } catch (Throwable $e) {
            return;
        }
    }

    function contact_card_html(PDO $pdo): string {
        if (contact_hidden_script() || empty($_SESSION['user_id'])) {
            return '';
        }
        $pending = contact_pending();
        if (empty($_SESSION['contact_prompt']) && $pending === null) {
            return '';
        }
        $csrf = htmlspecialchars(contact_csrf(), ENT_QUOTES);
        $html = '<div class="contact-card" style="max-width:520px;margin:12px auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;font-family:Segoe UI,sans-serif;color:#0f172a;">';
        if (!empty($_SESSION['contact_notice'])) {
            $html .= '<p style="margin:0 0 8px;font-size:13px;">' . htmlspecialchars((string) $_SESSION['contact_notice']) . '</p>';
            unset($_SESSION['contact_notice']);
        }
        $html .= '<h2 style="margin:0 0 6px;font-size:16px;">Email and mobile</h2>';
        $html .= '<p style="margin:0 0 10px;font-size:13px;line-height:1.45;">Add the email and mobile you want on file. You can skip this and update it later from My Profile. Text messages are not set up, so we verify by email only. A super admin can set this for someone who has no email.</p>';
        if ($pending) {
            $html .= '<form method="post" action="/contact_update.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">';
            $html .= '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="do" value="confirm">';
            $html .= '<label style="font-size:12px;">Confirmation code<br><input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;"></label>';
            $html .= '<button type="submit" style="padding:8px 14px;background:#0891b2;color:#fff;border:none;border-radius:8px;font-weight:700;">Confirm</button></form>';
        } else {
            $stmt = $pdo->prepare('SELECT email, Tel_Mobile FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $_SESSION['user_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['email' => '', 'Tel_Mobile' => ''];
            $email = htmlspecialchars(contact_real_email($row['email'] ?? '') !== '' ? (string) $row['email'] : '', ENT_QUOTES);
            $phone = htmlspecialchars((string) ($row['Tel_Mobile'] ?? ''), ENT_QUOTES);
            $html .= '<form method="post" action="/contact_update.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">';
            $html .= '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="do" value="send">';
            $html .= '<label style="font-size:12px;">Email<br><input type="email" name="email" autocomplete="email" value="' . $email . '" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;"></label>';
            $html .= '<label style="font-size:12px;">Mobile<br><input type="tel" name="phone" autocomplete="tel" value="' . $phone . '" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;"></label>';
            $html .= '<button type="submit" style="padding:8px 14px;background:#0891b2;color:#fff;border:none;border-radius:8px;font-weight:700;">Send code</button></form>';
        }
        $html .= '<form method="post" action="/contact_update.php" style="margin-top:8px;">';
        $html .= '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="do" value="skip">';
        $html .= '<button type="submit" style="padding:8px 14px;background:#fff;color:#0891b2;border:1px solid #0891b2;border-radius:8px;font-weight:700;">Skip for now</button></form>';
        $html .= '</div>';
        return $html;
    }
}
