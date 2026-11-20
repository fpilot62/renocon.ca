<?php
require_once 'db.php';
header('Content-Type: application/json');

$aid = $_GET['attendance_id'] ?? 0;
$sql = "SELECT break_start as start_time, break_end as end_time 
        FROM breaks 
        WHERE shift_id = ? 
        ORDER BY break_start ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$aid]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['status' => 'success', 'data' => $data]);
?>