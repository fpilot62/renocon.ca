<?php
/** Path: /process_timeoff.php */
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_SESSION['user_id'])) {
    $uid = $_SESSION['user_id'];
    $oid = $_SESSION['org_id'] ?? 0;
    $start = $_POST['start_date'];
    $end = $_POST['end_date'];
    $reason = $_POST['reason'] ?: $_POST['type'];

    try {
        $stmt = $pdo->prepare("INSERT INTO time_off_requests (user_id, org_id, start_date, end_date, reason, status) VALUES (?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$uid, $oid, $start, $end, $reason]);
        header("Location: kiosk.php?msg=Request Submitted");
        exit;
    } catch (PDOException $e) {
        die("Database Error: " . $e->getMessage());
    }
}