<?php
/** Path: /inventory/waste.php */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/db.php'; 
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$rid = isset($_GET['rid']) ? (int)$_GET['rid'] : 6;
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// 1. FETCH SITES (For Menu)
require_once __DIR__ . '/_org_sites.php';
$invIn = implode(',', array_map('intval', inv_query_site_ids() ?: [0]));

// 2. FETCH WASTE DATA GROUPED BY DATE
// We calculate the dollar loss using i.cost_pc
$sql = "
    SELECT 
        u.usage_date,
        i.Name,
        u.waste_qty,
        i.Unit,
        i.cost_pc,
        (u.waste_qty * i.cost_pc) as dollar_loss
    FROM Gem_inventory_usage u
    JOIN Gem_inventory_items i ON u.item_id = i.Id
    WHERE u.site_id IN ($invIn)
      AND u.waste_qty > 0 
      AND u.usage_date LIKE :month
    ORDER BY u.usage_date DESC, i.Name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute(['month' => $month . '%']);
$waste_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. CALCULATE TOTALS
$monthly_total_loss = 0;
foreach($waste_logs as $log) {
    $monthly_total_loss += $log['dollar_loss'];
}

require_once "../engine/menu_generator.php"; 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Waste Report | SIP</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background:#f1f5f9; font-family: 'Inter', sans-serif; padding-top: 155px !important; }
        .report-card { background: white; border-radius: 15px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); border: none; }
        .total-banner { background: #fee2e2; border-left: 5px solid #ef4444; padding: 20px; border-radius: 10px; margin-bottom: 30px; }
        .date-header { background: #f8fafc; font-weight: 800; color: #64748b; padding: 10px 15px; border-radius: 8px; margin-top: 20px; }
    </style>
</head>
<body>
    <?= generateMenu($pdo); ?>

    <div class="container-fluid px-5">
        <?php include "inventory_menu.php"; ?>

        <div class="report-card">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 style="font-weight: 900; color: #1e293b;">Wastage Analysis</h2>
                    <p class="text-muted"><?= inv_all_sites() ? 'Tracking inventory loss for all restaurants in this org' : ('Tracking inventory loss for ' . htmlspecialchars(inv_site_nick((int)$rid))) ?></p>
                </div>
                <div class="d-flex gap-2">
                    <input type="month" class="form-control mr-2" value="<?= $month ?>" onchange="location.href='?rid=<?= $rid ?>&month='+this.value">
                    <button class="btn btn-outline-dark" onclick="window.print()"><i class="fas fa-print"></i></button>
                </div>
            </div>

            <div class="total-banner d-flex justify-content-between align-items-center">
                <div>
                    <span class="small text-uppercase font-weight-bold text-danger">Total Loss for <?= date('F Y', strtotime($month)) ?></span>
                    <h1 class="m-0" style="font-weight: 900; color: #b91c1c;">$<?= number_format($monthly_total_loss, 2) ?></h1>
                </div>
                <i class="fas fa-chart-line fa-3x text-danger" style="opacity: 0.2;"></i>
            </div>

            <table class="table table-hover">
                <thead class="thead-light">
                    <tr class="small font-weight-bold uppercase">
                        <th>Date</th>
                        <th>Item Description</th>
                        <th class="text-center">Qty Wasted</th>
                        <th class="text-center">Unit Cost</th>
                        <th class="text-right">Total Loss</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $current_date = "";
                    foreach($waste_logs as $log): 
                        if($current_date != $log['usage_date']):
                            $current_date = $log['usage_date'];
                    ?>
                        <tr class="table-secondary"><td colspan="5" class="font-weight-bold small"><?= date('D, M j, Y', strtotime($current_date)) ?></td></tr>
                    <?php endif; ?>
                    <tr>
                        <td></td>
                        <td><strong><?= strtoupper($log['Name']) ?></strong></td>
                        <td class="text-center"><?= number_format($log['waste_qty'], 2) ?> <small><?= $log['Unit'] ?></small></td>
                        <td class="text-center text-muted">$<?= number_format($log['cost_pc'], 2) ?></td>
                        <td class="text-right font-weight-bold text-danger">$<?= number_format($log['dollar_loss'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if(empty($waste_logs)): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">No wastage recorded for this month.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>