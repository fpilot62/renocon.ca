<?php
/** Path: /inventory/process_order.php */
include("../sip/db_config.php"); // Or use direct connection if needed

require_once dirname(__DIR__) . '/db.php';
$db = $pdo;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['orders'])) {
    $rid = $_POST['restaurant_id'];
    $order_group_id = "ORD-" . time(); // Unique Batch ID

    $stmt = $db->prepare("INSERT INTO Gem_inventory_orders (order_group_id, restaurant_id, item_id, item_name, pcs_ordered, boxes_ordered) VALUES (?, ?, ?, ?, ?, ?)");

    foreach ($_POST['orders'] as $item_id => $data) {
        $boxes = (int)$data['boxes'];
        if ($boxes > 0) {
            $total_pcs = $boxes * (float)$data['pcs_per_box'];
            $stmt->execute([$order_group_id, $rid, $item_id, $data['name'], $total_pcs, $boxes]);
        }
    }

    echo "<h1>Order Saved!</h1><p>Batch ID: $order_group_id</p>";
    echo "<a href='order_generator.php?rid=$rid'>Return to Worksheet</a>";
}