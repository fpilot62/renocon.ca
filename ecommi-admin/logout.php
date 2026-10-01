<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
admin_boot_session();
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
