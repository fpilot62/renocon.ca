<?php
declare(strict_types=1);

function admin_boot_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('ecommi_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function csrf_token(): string
{
    admin_boot_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals(csrf_token(), is_string($token) ? $token : '')) {
        http_response_code(400);
        exit('Invalid request.');
    }
}

function current_user(): ?array
{
    admin_boot_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = admin_db()->prepare('SELECT id, username, role FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function is_superadmin(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'superadmin';
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

function require_superadmin(): array
{
    $user = require_login();
    if ($user['role'] !== 'superadmin') {
        http_response_code(403);
        exit('Superadmin only.');
    }
    return $user;
}

function user_count(): int
{
    return (int) admin_db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}
