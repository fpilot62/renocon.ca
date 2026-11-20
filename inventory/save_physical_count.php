<?php
/** Path: /inventory/save_physical_count.php */

include("../sip/db_config.php");
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. Get current Site ID and User (adjust session keys as per your system)
$restaurant_id = $_SESSION['restaurant_id'] ?? 0; 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['data'])) {
    
    // Start Transaction to ensure data integrity
    $conn->begin_transaction();

    try {
        // 2. Create the Header Record in inv_inventory
        $stmt = $conn->prepare("INSERT INTO inv_inventory (restaurant_id, inventory_date, status) VALUES (?, NOW(), 'Completed')");
        $stmt->bind_param("i", $restaurant_id);
        $stmt->execute();
        $inventory_id = $conn->insert_id; // Get the ID of the new batch

        // 3. Insert the Details into inv_inventory_details
        $stmt_detail = $conn->prepare("INSERT INTO inv_inventory_details 
            (inventory_id, restaurant_id, item_id, item_name, unit, qty, pcs, boxes, bags) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

        foreach ($_POST['data'] as $item_id => $row) {
            // Only save if a quantity was entered
            if ($row['qty'] > 0) {
                
                // Fetch item name for historical reference
                $res = $conn->query("SELECT Name, Pcs_In_Bag, Pcs_In_Box FROM Gem_inventory_items WHERE Id = $item_id");
                $item_master = $res->fetch_assoc();
                $item_name = $item_master['Name'];

                $qty = (float)$row['qty'];
                $unit = $row['unit'];
                $total_pcs = (float)$row['total_pcs'];

                // Calculate equivalent boxes/bags for storage
                $boxes = ($item_master['Pcs_In_Box'] > 0) ? $total_pcs / $item_master['Pcs_In_Box'] : 0;
                $bags = ($item_master['Pcs_In_Bag'] > 0) ? $total_pcs / $item_master['Pcs_In_Bag'] : 0;

                $stmt_detail->bind_param("iiissdddd", 
                    $inventory_id, 
                    $restaurant_id, 
                    $item_id, 
                    $item_name, 
                    $unit, 
                    $qty, 
                    $total_pcs, 
                    $boxes, 
                    $bags
                );
                $stmt_detail->execute();
            }
        }

        $conn->commit();
        echo "<script>alert('Inventory saved successfully!'); window.location.href='physical_count.php';</script>";

    } catch (Exception $e) {
        $conn->rollback();
        die("Error saving inventory: " . $e->getMessage());
    }
}
?>