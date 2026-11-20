<?php
require_once 'engine.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Handle your logic (UPDATE/DELETE/ADD) here...

// Fix: Redirect using JS to bypass engine.php output issues
echo "<script>window.location.href='daily_report_view.php';</script>";
exit;