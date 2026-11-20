<?php
/** Path: /manage_users.php — retired; Employee Hub is the single staff screen. */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$qs = isset($_SERVER['QUERY_STRING']) ? trim((string)$_SERVER['QUERY_STRING']) : '';
header('Location: employee_hub.php' . ($qs !== '' ? ('?' . $qs) : ''), true, 302);
exit;
