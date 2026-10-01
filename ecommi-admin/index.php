<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';

admin_boot_session();

if (user_count() === 0) {
    header('Location: setup.php');
    exit;
}

$user = require_login();
$page = $_GET['page'] ?? 'dashboard';
$allowed = ['dashboard', 'apps', 'records', 'prices'];
if ($user['role'] === 'superadmin') {
    $allowed[] = 'passwords';
}
if (!in_array($page, $allowed, true)) {
    $page = 'dashboard';
}

require __DIR__ . '/pages/' . $page . '.php';
