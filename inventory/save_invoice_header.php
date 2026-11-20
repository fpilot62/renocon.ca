<?php
include("../sip/db_config.php");

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $org_id = 1;
    $site_id = $_POST['site_id'];
    $vendor = $conn->real_escape_string($_POST['vendor']);
    $inv_num = $conn->real_escape_string($_POST['invoice_number']);
    $inv_date = $_POST['invoice_date'];
    $amount = $_POST['total_amount'];

    $sql = "INSERT INTO Gem_inventory_invoices (org_id, site_id, vendor, invoice_number, invoice_date, total_amount) 
            VALUES ('$org_id', '$site_id', '$vendor', '$inv_num', '$inv_date', '$amount')";
    
    if ($conn->query($sql)) {
        $new_invoice_id = $conn->insert_id;
        // Move to the next step: Adding items to this specific invoice ID
        header("Location: add_invoice_items.php?id=$new_invoice_id&site_id=$site_id");
    } else {
        echo "Database Error: " . $conn->error;
    }
}
?>