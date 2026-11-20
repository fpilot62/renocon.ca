<?php
/** Shared payroll login helpers — username/email/PIN + password or PIN. */

if (!function_exists('sip_normalize_login_ident')) {
    function sip_normalize_login_ident($raw): string {
        $s = trim((string)$raw);
        $s = preg_replace('/\s+/', '', $s);
        if (strpos($s, '@') !== false) {
            return strtolower($s);
        }
        return $s;
    }
}

if (!function_exists('sip_find_login_user')) {
    function sip_find_login_user(PDO $pdo, string $ident) {
        if ($ident === '') {
            return false;
        }
        $stmt = $pdo->prepare(
            "SELECT id, Name, password, pin, user_role, org_id, assigned_site_ids, email, user, active, Tel_Mobile
             FROM users
             WHERE user = ? OR email = ? OR pin = ?
             LIMIT 1"
        );
        $stmt->execute([$ident, $ident, $ident]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: false;
    }
}

if (!function_exists('sip_django_password_ok')) {
    /** Bookkeeping stores pbkdf2_sha256$iterations$salt$hash. Payroll accepts that too. */
    function sip_django_password_ok(string $password, string $hash): bool {
        $parts = explode('$', $hash, 4);
        if (count($parts) !== 4 || $parts[0] !== 'pbkdf2_sha256') {
            return false;
        }
        $iterations = (int)$parts[1];
        if ($iterations < 1 || $iterations > 2000000 || $parts[2] === '' || $parts[3] === '') {
            return false;
        }
        $calc = base64_encode(hash_pbkdf2('sha256', $password, $parts[2], $iterations, 32, true));
        return hash_equals($parts[3], $calc);
    }
}

if (!function_exists('sip_verify_login_secret')) {
    function sip_verify_login_secret(array $user, string $password): bool {
        if ((int)($user['active'] ?? 1) !== 1) {
            return false;
        }
        $hash = (string)($user['password'] ?? '');
        if ($hash !== '' && strpos($hash, '$2y$') === 0) {
            if (password_verify($password, $hash)) {
                return true;
            }
        } elseif ($hash !== '' && sip_django_password_ok($password, $hash)) {
            return true;
        } elseif ($hash !== '' && hash_equals($hash, $password)) {
            return true;
        }
        $pin = trim((string)($user['pin'] ?? ''));
        if ($pin !== '' && hash_equals($pin, $password)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('sip_login_secret_kind')) {
    /** @return 'password'|'pin'|false */
    function sip_login_secret_kind(array $user, string $password) {
        if ((int)($user['active'] ?? 1) !== 1) {
            return false;
        }
        $hash = (string)($user['password'] ?? '');
        if ($hash !== '' && strpos($hash, '$2y$') === 0 && password_verify($password, $hash)) {
            return 'password';
        }
        if ($hash !== '' && function_exists('sip_django_password_ok') && sip_django_password_ok($password, $hash)) {
            return 'password';
        }
        if ($hash !== '' && hash_equals($hash, $password)) {
            return 'password';
        }
        $pin = trim((string)($user['pin'] ?? ''));
        if ($pin !== '' && hash_equals($pin, $password)) {
            return 'pin';
        }
        return false;
    }
}

if (!function_exists('sip_post_login_url')) {
    /** Org Admin / site manager → employee hub (their org only). */
    function sip_post_login_url(PDO $pdo, array $user): string {
        $role = (int)($user['user_role'] ?? 99);
        $org = (int)($user['org_id'] ?? 0);
        if ($role >= 11 && $role <= 25) {
            return 'employee_hub.php';
        }
        if (!function_exists('sip_resolve_login_destination')) {
            require_once __DIR__ . '/org_modules_lib.php';
        }
        return sip_resolve_login_destination($pdo, $org, $role);
    }
}
