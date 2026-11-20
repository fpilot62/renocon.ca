<?php
header("Access-Control-Allow-Origin: *");
include __DIR__ . '/sip/db_config.php';

$user_id = $_POST['user_id'] ?? null;
$site_id = $_POST['site_id'] ?? null;
$start   = $_POST['start_date'] ?? null;
$end     = $_POST['end_date'] ?? null;
$reason  = $_POST['reason'] ?? '';

if (!$user_id || !$site_id || !$start || !$end) {
    die("Error: Missing Data");
}

$stmt = $conn->prepare("INSERT INTO time_off_requests (user_id, site_id, start_date, end_date, reason, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
$stmt->bind_param("iisss", $user_id, $site_id, $start, $end, $reason);

if ($stmt->execute()) {
    echo "success";
} else {
    echo "Error: " . $conn->error;
}
?>