<?php
/** Path: /inventory/record_usage.php */
include("../sip/db_config.php"); 
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once dirname(__DIR__) . '/db.php';

include("../engine/menu_generator.php"); 

$rid = isset($_GET['rid']) ? (int)$_GET['rid'] : 6;

$sql = "SELECT Id, Name, Unit, Pcs_In_Bag, Pcs_In_Box, IFNULL(Pcs_In_Oz, 1) as Pcs_In_Oz FROM Gem_inventory_items WHERE Restaurant_Id = 0 OR Restaurant_Id = ? ORDER BY Name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$rid]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Daily Consumption | SIP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { margin: 0; padding-top: 130px; font-family: 'Inter', sans-serif; background: #f1f5f9; }
        .container { max-width: 1300px; margin: 0 auto; padding: 20px; }
        .sub-nav { background: #1e293b; padding: 15px 25px; border-radius: 12px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; color: white; }
        .nav-links a { color: #94a3b8; text-decoration: none !important; margin-left: 20px; font-weight: 700; font-size: 13px; }
        .nav-links a:hover, .nav-links a.active { color: #38bdf8; }
        .card { background: white; border-radius: 12px; padding: 30px; border: 1px solid #e2e8f0; }
        .qty-input { width: 90px; padding: 8px; border: 2px solid #cbd5e1; border-radius: 8px; font-weight: 800; text-align: center; color: #2563eb; }
        .unit-select { padding: 8px; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 600; width: 150px; }
    </style>
</head>
<body>
    <?= generateMenu($pdo); ?>
    <div class="container">
        <div class="sub-nav">
            <span style="font-weight:900; font-size:18px;">Daily Consumption Log</span>
            <div class="nav-links">
                <a href="inventory_report.php?rid=<?= $rid ?>">Report</a>
                <a href="physical_count.php?rid=<?= $rid ?>">Count</a>
                <a href="record_usage.php?rid=<?= $rid ?>" class="active">Usage</a>
                <a href="order_generator.php?rid=<?= $rid ?>">Orders</a>
            </div>
        </div>
        <div class="card">
            <form action="save_bulk_usage.php" method="POST">
                <input type="hidden" name="rid" value="<?= $rid ?>">
                <table class="table">
                    <thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th style="text-align:right;">Total Pcs</th></tr></thead>
                    <tbody>
                        <?php foreach($items as $item): ?>
                        <tr>
                            <td><strong><?= strtoupper($item['Name']) ?></strong></td>
                            <td><input type="number" step="0.01" name="usage[<?= $item['Id'] ?>][qty_val]" class="qty-input" id="qty_<?= $item['Id'] ?>" oninput="calcRow(<?= $item['Id'] ?>)"></td>
                            <td>
                                <select name="usage[<?= $item['Id'] ?>][unit_type]" class="unit-select" id="unit_<?= $item['Id'] ?>" onchange="calcRow(<?= $item['Id'] ?>)">
                                    <option value="1">Pieces</option>
                                    <option value="<?= $item['Pcs_In_Oz'] ?>">Ounces</option>
                                    <?php if($item['Pcs_In_Bag'] > 0): ?><option value="<?= $item['Pcs_In_Bag'] ?>">Bags</option><?php endif; ?>
                                    <?php if($item['Pcs_In_Box'] > 0): ?><option value="<?= $item['Pcs_In_Box'] ?>">Boxes</option><?php endif; ?>
                                </select>
                            </td>
                            <td style="text-align:right;"><span id="prev_<?= $item['Id'] ?>" style="font-weight:800;">0.00</span><input type="hidden" name="usage[<?= $item['Id'] ?>][total_pcs]" id="hidden_<?= $item['Id'] ?>"></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit" class="btn btn-primary btn-lg float-right" style="padding:15px 60px; font-weight:800; border-radius:12px;">Save Consumption Log</button>
            </form>
        </div>
    </div>
    <script>function calcRow(id) { 
        const qty = parseFloat(document.getElementById('qty_'+id).value)||0; 
        const mult = parseFloat(document.getElementById('unit_'+id).value)||1; 
        const tot = (qty*mult).toFixed(2); 
        document.getElementById('prev_'+id).innerText = tot; 
        document.getElementById('hidden_'+id).value = tot; 
    }</script>
</body>
</html>