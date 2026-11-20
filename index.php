<?php
/** Path: /index.php */
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login_kiosk.php");
    exit;
}

$level = (int)($_SESSION['user_level'] ?? 99);

// Redirect based on your specific tiers
if ($level <= 10) {
    header("Location: manage_users.php");
} elseif ($level <= 20) {
    header("Location: manager_dashboard.php");
} elseif ($level <= 30) {
    header("Location: approve_timeoff.php");
} elseif ($level <= 40) {
    header("Location: submit_timeoff.php");
} else {
    header("Location: login_kiosk.php");
}
exit;