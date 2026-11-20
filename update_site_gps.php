<?php
/** Path: /update_site_gps.php */
require_once 'db.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$lat = isset($_GET['lat']) ? $_GET['lat'] : '';
$lng = isset($_GET['lng']) ? $_GET['lng'] : '';

if ($id > 0 && !empty($lat)) {
    // Update the site with the new coordinates
    $stmt = $pdo->prepare("UPDATE sites SET lat = ?, lng = ? WHERE SId = ?");
    if ($stmt->execute([$lat, $lng, $id])) {
        // Success: Go back to the manager
        header("Location: site_manager.php?org_id=" . ($_GET['org_id'] ?? '') . "&msg=updated");
        exit;
    }
}
echo "Error: Missing ID or Location data.";
?>