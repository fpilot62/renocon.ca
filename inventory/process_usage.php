<?php
// ... [DB Config] ...
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $item_id = $_POST['item_id'];
    $sold_qty = (float)$_POST['sold_qty'];

    // 1. Get Conversion factors from Master Table
    $stmt = $conn->prepare("SELECT Pcs_In_Box, Pcs_In_Bag, Cost_Pc FROM Gem_inventory_items WHERE Id = ?");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();

    // 2. Perform Backend Math
    $boxes_used = ($item['Pcs_In_Box'] > 0) ? ($sold_qty / $item['Pcs_In_Box']) : 0;
    $bags_used  = ($item['Pcs_In_Bag'] > 0) ? ($sold_qty / $item['Pcs_In_Bag']) : 0;
    $total_value = $sold_qty * $item['Cost_Pc'];

    // 3. Insert into Usage Table with ALL fields filled
    $sql = "INSERT INTO Gem_inventory_usage (item_id, sold_qty, boxes_calc, bags_calc, total_value, usage_date) 
            VALUES (?, ?, ?, ?, ?, NOW())";
    $ins = $conn->prepare($sql);
    $ins->bind_param("idddd", $item_id, $sold_qty, $boxes_used, $bags_used, $total_value);
    $ins->execute();

    header("Location: record_usage.php?success=1");
}