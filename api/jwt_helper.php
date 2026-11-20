<?php
/**
 * Path: /api/jwt_helper.php
 * Lightweight JWT encode/decode — no external libraries needed
 */

define('JWT_SECRET', 'CHANGE_THIS_TO_A_LONG_RANDOM_STRING_MIN_32_CHARS');
define('JWT_EXPIRY_HOURS', 24);

function jwt_encode(array $payload): string {
    $header  = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['exp'] = time() + (JWT_EXPIRY_HOURS * 3600);
    $body    = base64url_encode(json_encode($payload));
    $sig     = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    return "$header.$body.$sig";
}

function jwt_decode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $body, $sig] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $payload = json_decode(base64url_decode($body), true);
    if (!$payload || (isset($payload['exp']) && $payload['exp'] < time())) return null;
    return $payload;
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

function get_auth_header(): string {
    $vals = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (strtolower((string)$k) === 'authorization') {
                    $vals[] = (string)$v;
                }
            }
        }
    }
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        if (!empty($_SERVER[$key])) {
            $vals[] = (string)$_SERVER[$key];
        }
    }
    foreach ($vals as $auth) {
        $auth = trim($auth);
        if ($auth !== '' && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
    }
    return '';
}

function get_token_payload(): ?array {
    $token = get_auth_header();
    if ($token === '') return null;
    return jwt_decode($token);
}

function require_auth(): array {
    $payload = get_token_payload();
    if (!$payload) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    return $payload;
}