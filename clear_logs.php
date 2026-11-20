<?php
session_start();
require_once 'db.php';
// Security: Only admins can clear logs
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 1) { die("Denied"); }

$pdo->query("TRUNCATE TABLE punches");
echo "All records deleted. <a href='manager.php'>Back to Manager</a>";
?>