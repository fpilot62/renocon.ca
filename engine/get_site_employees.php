<?php
/** Path: /engine/get_site_employees.php */
require_once '../db.php';

$site_id = $_GET['site_id'] ?? '';

try {
    if (!empty($site_id)) {
        // Filter by Site: Check Primary Site OR Assigned Sites list
        $stmt = $pdo->prepare("SELECT id, Name FROM users WHERE (primary_restaurant_id = ? OR FIND_IN_SET(?, assigned_site_ids)) AND active = 1 ORDER BY Name ASC");
        $stmt->execute([$site_id, $site_id]);
    } else {
        // All Sites: Fetch every active employee
        $stmt = $pdo->query("SELECT id, Name FROM users WHERE active = 1 ORDER BY Name ASC");
    }

    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($employees);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}