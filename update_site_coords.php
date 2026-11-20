<?php
// This would be your update_site_coords.php
include 'db_config.php';

$sId = $_POST['SId'];
$lat = $_POST['target_lat'];
$lng = $_POST['target_lng'];

// Update the sites table based on the SId
$sql = "UPDATE sites SET target_lat = '$lat', target_lng = '$lng' WHERE SId = '$sId'";

if ($conn->query($sql)) {
    echo "Success: Site location updated.";
} else {
    echo "Error: " . $conn->error;
}
?>