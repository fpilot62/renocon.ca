<?php
/** Path: /inventory/index.php */
include("../sip/db_config.php"); // Load your MySQLi $conn

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. CREATE PDO CONNECTION (Required by your menu_generator.php)
require_once dirname(__DIR__) . '/db.php';

// 2. INCLUDE THE MENU ENGINE
include("../engine/menu_generator.php"); 
?>
<!DOCTYPE html>
<html>
<head>
    <title>Log New Delivery</title>
    <link rel="stylesheet" href="../css/style.css"> <style>
        .form-container { max-width: 500px; margin: 50px auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        input, select { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 12px; background: #27ae60; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
    </style>
</head>
<body style="background:#f4f7f6; font-family: sans-serif;">
    <?= generateMenu($pdo); ?>
<div class="form-container">
    <h2>Step 1: Invoice Header</h2>
    <form action="save_invoice_header.php" method="POST">
        <input type="hidden" name="site_id" value="6"> 
        
        <label>Vendor Name</label>
        <input type="text" name="vendor" placeholder="e.g. Sysco or GFS" required>

        <label>Invoice Number</label>
        <input type="text" name="invoice_number" required>

        <label>Invoice Date</label>
        <input type="date" name="invoice_date" value="<?php echo date('Y-m-d'); ?>" required>

        <label>Total Bill Amount ($)</label>
        <input type="number" step="0.01" name="total_amount" placeholder="0.00" required>

        <button type="submit">Next: Add Items to Bill</button>
    </form>
</div>

</body>
</html>