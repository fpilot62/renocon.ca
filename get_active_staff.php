<?php
/** Path: /get_active_staff.php */
require_once 'db.php';
header('Content-Type: application/json');

// Logic: Latest entry per user in 'attendance' where 'clock_out' is still NULL
$sql = "SELECT u.Name as staff_name, s.nick_name as site_name, a.clock_in 
        FROM users u 
        JOIN attendance a ON u.id = a.user_id 
        JOIN sites s ON a.site_id = s.SId
        WHERE a.id IN (SELECT MAX(id) FROM attendance GROUP BY user_id)
        AND a.clock_out IS NULL
        ORDER BY a.clock_in DESC";

try {
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status' => 'success', 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>