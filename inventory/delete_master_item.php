<?php
include("../sip/db_config.php");
$id = $_GET['id'];

// Note: In a live system, you might want to check if this item is used in invoices first.
$sql = "DELETE FROM Gem_inventory_items WHERE id = $id";

if ($conn->query($sql)) {
    header("Location: manage_items.php?status=deleted");
} else {
    echo "Error: " . $conn->error;
}
?>