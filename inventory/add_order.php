<?php
/** Path: /inventory/add_order.php */
include("../sip/db_config.php");

// Fetch items for the dropdown
$items_res = $conn->query("SELECT Id, Name, Unit FROM Gem_inventory_items WHERE Restaurant_Id = 0 ORDER BY Name ASC");
?>

<!DOCTYPE html>
<html>
<head><title>Receive New Stock</title></head>
<body>
    <h2>Receive New Order</h2>
    <form action="save_order.php" method="POST">
        <label>Item Name:</label>
        <select name="item_id" required>
            <?php while($row = $items_res->fetch_assoc()): ?>
                <option value="<?php echo $row['Id']; ?>"><?php echo $row['Name']; ?> (<?php echo $row['Unit']; ?>)</option>
            <?php endwhile; ?>
        </select>
        <br><br>
        <label>Quantity Received (Pieces):</label>
        <input type="number" step="0.01" name="quantity" required>
        <br><br>
        <button type="submit">Save Order</button>
    </form>
</body>
</html>