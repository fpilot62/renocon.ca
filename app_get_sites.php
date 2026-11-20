<?php
header('Content-Type: application/json');
require_once 'db.php'; 

$org_id = isset($_REQUEST['org_id']) ? (int)$_REQUEST['org_id'] : 0;

if ($org_id > 0) {
    try {
        // FIX: Added $sql =, cleaned up the query, and used COALESCE to prevent nulls
        $sql = "SELECT SId, nick_name AS SiteName, target_lat AS latitude, target_lng AS longitude FROM sites WHERE org_id = ? AND target_lat IS NOT NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$org_id]);
        $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($sites);
    } catch (Exception $e) {
        echo json_encode([]);
    }
} else {
    echo json_encode([]);
}