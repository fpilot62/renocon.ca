<?php
include("../sip/db_config.php");

$id = $_GET['id'];
$inv_id = $_GET['inv'];

$sql = "DELETE FROM Gem_inventory_invoice_details WHERE id = $id";

if ($conn->query($sql)) {
    header("Location: add_invoice_items.php?id=$inv_id&status=deleted");
} else {
    echo "Error deleting: " . $conn->error;
}
?>