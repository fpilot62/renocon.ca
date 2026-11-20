<?php
/** Path: /inventory/save_order.php */
include("../sip/db_config.php");
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$restaurant_id = $_SESSION['restaurant_id'] ?? 6; // Defaulting to 6 based on your earlier screenshots

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id = (int)$_POST['item_id'];
    $quantity = (float)$_POST['quantity'];
    
    // We insert directly into inv_orders_details
    $stmt = $conn->prepare("INSERT INTO inv_orders_details (restaurant_id, item_id, quantity, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("iid", $restaurant_id, $item_id, $quantity);

    if ($stmt->execute()) {
        echo "<script>alert('Stock added successfully!'); window.location.href='inventory_dashboard.php';</script>";
    } else {
        echo "Error: " . $conn->error;
    }
}
?>