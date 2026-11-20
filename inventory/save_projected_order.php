<?php
include("../sip/db_config.php");
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// We assume the restaurant_id is stored in the session
$restaurant_id = $_SESSION['restaurant_id'] ?? 1; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Create a Header for this Order first
    $stmt_h = $conn->prepare("INSERT INTO inv_orders (restaurant_id, order_date, status) VALUES (?, NOW(), 'Pending')");
    $stmt_h->bind_param("i", $restaurant_id);
    $stmt_h->execute();
    $order_id = $conn->insert_id;

    // 2. Loop through the final quantities from your dashboard inputs
    // Assuming your input name is final_order[item_id]
    foreach ($_POST['final_order'] as $item_id => $quantity) {
        if ($quantity > 0) {
            $item_id = (int)$item_id;
            $qty = (float)$quantity;

            // Insert into the details table
            $stmt_d = $conn->prepare("INSERT INTO inv_order_details (order_id, restaurant_id, item_id, quantity, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt_d->bind_param("iiid", $order_id, $restaurant_id, $item_id, $qty);
            $stmt_d->execute();
        }
    }

    echo "<script>alert('Order #$order_id generated successfully!'); window.location.href='index.php';</script>";
}
?>