<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
/**
 * Path: /alarm/api/alarm_jwt_helper.php
 * JWT helper specifically for AlarmPulse
 */
define('ALARM_JWT_SECRET', 'RenoconPayroll2026_XkP9mQ3vL7wN2sT8');
define('ALARM_JWT_EXPIRY_HOURS', 720);

function alarm_jwt_encode(array $payload): string {
    $header  = alarm_base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['exp'] = time() + (ALARM_JWT_EXPIRY_HOURS * 3600);
    $body    = alarm_base64url_encode(json_encode($payload));
    $sig     = alarm_base64url_encode(hash_hmac('sha256', "$header.$body", ALARM_JWT_SECRET, true));
    return "$header.$body.$sig";
}

function alarm_jwt_decode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $body, $sig] = $parts;
    $expected = alarm_base64url_encode(hash_hmac('sha256', "$header.$body", ALARM_JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $payload = json_decode(alarm_base64url_decode($body), true);
    if (!$payload || (isset($payload['exp']) && $payload['exp'] < time())) return null;
    return $payload;
}

function alarm_base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function alarm_base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

function alarm_get_bearer_token(): ?string {
    $auths = [];
    if (function_exists("getallheaders")) {
        foreach ((getallheaders() ?: []) as $k => $v) {
            if (strcasecmp((string)$k, "Authorization") === 0) $auths[] = (string)$v;
        }
    }
    foreach (["HTTP_AUTHORIZATION", "REDIRECT_HTTP_AUTHORIZATION"] as $key) {
        if (!empty($_SERVER[$key])) $auths[] = (string)$_SERVER[$key];
    }
    foreach ($auths as $auth) {
        $auth = trim($auth);
        if ($auth === "") continue;
        if (stripos($auth, "Bearer ") === 0) return trim(substr($auth, 7));
        return $auth;
    }
    return null;
}

function alarm_get_token_payload(): ?array {
    $token = alarm_get_bearer_token();
    if (!$token) return null;
    return alarm_jwt_decode($token);
}

function alarm_require_auth(): array {
    $payload = alarm_get_token_payload();
    if (!$payload) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Session expired. Sign out and sign in again.']);
        exit;
    }
    return $payload;
}

/**
 * ============================================================
 * FILE: alarm/api/alarm_jwt_helper.php
 * LAST MODIFIED: June 2026
 * ============================================================
 * OBJECTIVE:
 *   JWT encode/decode specifically for AlarmPulse API.
 *   Separate from CrewPulse jwt_helper.php to avoid constant
 *   redefinition conflicts.
 *
 * LOGIC:
 *   - Uses ALARM_JWT_SECRET (different constant name)
 *   - All functions prefixed with alarm_ to avoid conflicts
 *   - alarm_require_auth() reads Bearer token from headers
 *   - Returns 401 Unauthorized if token missing or invalid
 *
 * PROCESS:
 *   1. login.php calls alarm_jwt_encode() to create token
 *   2. App stores token in AsyncStorage
 *   3. App sends token as Authorization: Bearer <token>
 *   4. API files call alarm_require_auth() to validate
 *
 * CHANGES LOG:
 *   - June 2026: Created to replace ../../api/jwt_helper.php
 *   - Reason: config.php redefines JWT_SECRET causing PHP warnings
 *     and potential 500 errors when loaded after jwt_helper.php
 * ============================================================
 */