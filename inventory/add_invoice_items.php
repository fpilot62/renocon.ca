<?php
include("../sip/db_config.php");
$invoice_id = $_GET['id'];
$site_id = $_GET['site_id'] ?? 6;

// 1. Fetch Invoice Header Info
$header = $conn->query("SELECT * FROM Gem_inventory_invoices WHERE id = $invoice_id")->fetch_assoc();

// 2. Fetch Master Items for Dropdown
$items_master = $conn->query("SELECT id, name, purchase_unit FROM Gem_inventory_items WHERE org_id = 1 AND (site_id = 0 OR site_id = $site_id) ORDER BY name ASC");

// 3. Fetch current Line Items already added to this invoice
$lines = $conn->query("SELECT d.*, i.name, i.purchase_unit 
                       FROM Gem_inventory_invoice_details d 
                       JOIN Gem_inventory_items i ON d.item_id = i.id 
                       WHERE d.invoice_id = $invoice_id");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Invoice Details - <?= $header['vendor'] ?></title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .container { max-width: 900px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header-info { display: flex; justify-content: space-between; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #34495e; color: white; padding: 10px; text-align: left; }
        td { padding: 10px; border-bottom: 1px solid #eee; }
        .form-row { background: #e8f0fe; padding: 15px; border-radius: 5px; display: flex; gap: 10px; align-items: flex-end; }
        .btn-del { color: red; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <div class="header-info">
        <div>
            <strong>Vendor:</strong> <?= $header['vendor'] ?><br>
            <strong>Invoice #:</strong> <?= $header['invoice_number'] ?>
        </div>
        <div style="text-align:right;">
            <strong>Date:</strong> <?= $header['invoice_date'] ?><br>
            <strong>Total Expected:</strong> $<?= number_format($header['total_amount'], 2) ?>
        </div>
    </div>

    <h3>Add Item to Invoice</h3>
    <form action="save_line_item.php" method="POST" class="form-row">
        <input type="hidden" name="invoice_id" value="<?= $invoice_id ?>">
        <input type="hidden" name="site_id" value="<?= $site_id ?>">
        
        <div style="flex: 2;">
            <label style="font-size:12px;">Item</label>
            <select name="item_id" style="width:100%; padding:8px;" required>
                <option value="">-- Select Item --</option>
                <?php while($im = $items_master->fetch_assoc()): ?>
                    <option value="<?= $im['id'] ?>"><?= $im['name'] ?> (<?= $im['purchase_unit'] ?>)</option>
                <?php endwhile; ?>
            </select>
        </div>
        <div style="flex: 1;">
            <label style="font-size:12px;">Qty (Cases/Pcs)</label>
            <input type="number" step="0.01" name="qty" style="width:100%; padding:8px;" required>
        </div>
        <div style="flex: 1;">
            <label style="font-size:12px;">Unit Price ($)</label>
            <input type="number" step="0.01" name="price" style="width:100%; padding:8px;" required>
        </div>
        <button type="submit" style="background:#27ae60; color:white; border:none; padding:10px 20px; border-radius:4px; cursor:pointer;">ADD</button>
    </form>

    <table>
        <thead>
            <tr>
                <th>Item Description</th>
                <th>Qty</th>
                <th>Unit Price</th>
                <th>Line Total</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $running_total = 0;
            while($row = $lines->fetch_assoc()): 
                $running_total += $row['line_total'];
            ?>
            <tr>
                <td><?= $row['name'] ?></td>
                <td><?= $row['qty_received'] ?> <?= $row['purchase_unit'] ?></td>
                <td>$<?= number_format($row['unit_cost'], 2) ?></td>
                <td>$<?= number_format($row['line_total'], 2) ?></td>
                <td>
                    <a href="delete_line_item.php?id=<?= $row['id'] ?>&inv=<?= $invoice_id ?>" class="btn-del" onclick="return confirm('Remove this item?')">Delete</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight:bold; background:#f9f9f9;">
                <td colspan="3" style="text-align:right;">Calculated Total:</td>
                <td>$<?= number_format($running_total, 2) ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    
    <div style="margin-top:20px;">
        <a href="index.php" style="color:#3498db;">← Back to Dashboard</a>
    </div>
</div>

</body>
</html>