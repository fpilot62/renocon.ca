<?php
/** Path: /inventory/inventory_report.php */
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. DATABASE & AUTH
require_once dirname(__DIR__) . '/db.php'; 
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 2. CONTEXT SETUP
$rid = isset($_GET['rid']) ? (int)$_GET['rid'] : 6; 

// Fetch sites for the unified menu dropdown
require_once __DIR__ . '/_org_sites.php';

// 3. FETCH REPORT DATA
// This query calculates: Total In - Total Out = Theoretical Stock
$invIn = implode(',', array_map('intval', inv_query_site_ids() ?: [0]));
$report_sql = "
    SELECT 
        i.Id, 
        i.Name as name, 
        i.Unit as count_unit, 
        i.cost_pc,
        IFNULL(rec.total_received, 0) as received,
        IFNULL(usg.total_used, 0) as used,
        (IFNULL(rec.total_received, 0) - IFNULL(usg.total_used, 0)) as theoretical_stock
    FROM Gem_inventory_items i
    LEFT JOIN (
        SELECT item_id, SUM(qty_received * pcs_in_box) as total_received 
        FROM Gem_inventory_invoice_details 
        WHERE site_id IN ($invIn) GROUP BY item_id
    ) rec ON i.Id = rec.item_id
    LEFT JOIN (
        SELECT item_id, SUM(sold_qty + waste_qty) as total_used 
        FROM Gem_inventory_usage 
        WHERE site_id IN ($invIn) GROUP BY item_id
    ) usg ON i.Id = usg.item_id
    WHERE i.Restaurant_Id = 0 OR i.Restaurant_Id IN ($invIn)
    ORDER BY i.Name ASC";

$items = $pdo->query($report_sql)->fetchAll(PDO::FETCH_ASSOC);

require_once "../engine/menu_generator.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inventory Report | SIP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { background:#f1f5f9; font-family: 'Inter', sans-serif; padding-top: 130px; }
        .report-card { background: white; border-radius: 15px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); border: none; margin-bottom: 50px; }
        .table thead th { font-size: 11px; text-transform: uppercase; color: #64748b; border: none; background: #f8fafc; letter-spacing: 0.5px; }
        .stock-positive { color: #10b981; font-weight: 800; }
        .stock-negative { color: #ef4444; font-weight: 800; }
        .val-text { font-weight: 800; color: #1e293b; }
    </style>
</head>
<body>
    <?= generateMenu($pdo); ?>

    <div class="container-fluid px-5">
        <?php include("inventory_menu.php"); ?>

        <div class="report-card">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="font-weight-bold mb-0" style="color: #1e293b;">Inventory Status Report</h2>
                    <p class="text-muted small">Live calculation: Total Deliveries - Total Consumption (Sales + Waste)</p>
                </div>
                <div class="btn-group">
                    <button class="btn btn-outline-dark btn-sm" onclick="window.print()">
                        <i class="fas fa-print mr-1"></i> Print
                    </button>
                    <button class="btn btn-outline-primary btn-sm" onclick="location.reload()">
                        <i class="fas fa-sync-alt mr-1"></i> Refresh
                    </button>
                </div>
            </div>

            <table class="table table-hover">
                <thead>
                    <tr>
                        <th style="width: 30%;">Item Description</th>
                        <th class="text-center">Total Received</th>
                        <th class="text-center">Total Used/Waste</th>
                        <th class="text-center">Theo. On Hand</th>
                        <th class="text-center">Unit Cost</th>
                        <th class="text-right">Stock Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $grand_total_value = 0;
                    foreach($items as $item): 
                        $stock_on_hand = (float)$item['theoretical_stock'];
                        $unit_cost = (float)$item['cost_pc'];
                        $line_value = $stock_on_hand * $unit_cost;
                        $grand_total_value += $line_value;
                    ?>
                    <tr>
                        <td>
                            <strong><?= strtoupper($item['name']) ?></strong><br>
                            <small class="text-muted"><?= $item['count_unit'] ?></small>
                        </td>
                        <td class="text-center"><?= number_format($item['received'], 2) ?></td>
                        <td class="text-center"><?= number_format($item['used'], 2) ?></td>
                        <td class="text-center <?= $stock_on_hand >= 0 ? 'stock-positive' : 'stock-negative' ?>">
                            <?= number_format($stock_on_hand, 2) ?>
                        </td>
                        <td class="text-center text-muted">$<?= number_format($unit_cost, 2) ?></td>
                        <td class="text-right val-text">$<?= number_format($line_value, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #f1f5f9;">
                        <td colspan="5" class="text-right"><strong>GRAND TOTAL INVENTORY VALUE:</strong></td>
                        <td class="text-right"><h4 class="mb-0" style="font-weight:900; color:#0891b2;">$<?= number_format($grand_total_value, 2) ?></h4></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</body>
</html>