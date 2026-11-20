<?php
header('Content-Type: application/json');
include 'sip/db_config.php'; // Ensure this matches your DB connection file name

try {
    $org_id = $_GET['org_id'];
    
    // Selects pending requests and joins with users table to get the staff name
    $stmt = $pdo->prepare("SELECT t.id, t.type, t.start_date, t.end_date, t.note, u.staff_name 
                           FROM pto_requests t 
                           JOIN users u ON t.user_id = u.user_id 
                           WHERE t.org_id = ? AND t.status = 'Pending'");
    $stmt.execute([$org_id]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>