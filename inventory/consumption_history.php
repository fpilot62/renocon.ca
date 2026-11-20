<?php
/** Path: /inventory/consumption_history.php */
include("../sip/db_config.php"); 
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once dirname(__DIR__) . '/db.php';

include("../engine/menu_generator.php"); 

$rid = $_GET['rid'] ?? 6;

// Fetch latest consumption logs joined with Item Names
$sql = "SELECT u.*, i.Name as ItemName 
        FROM Gem_inventory_usage u 
        JOIN Gem_inventory_items i ON u.Item_Id = i.Id 
        WHERE u.Restaurant_Id = ? 
        ORDER BY u.Date_Recorded DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute([$rid]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Consumption History</title>
    <style>
        body { margin: 0; padding-top: 100px; font-family: 'Inter', sans-serif; background: #f1f5f9; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .card { background: white; border-radius: 12px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .table th { background: #1e293b; color: white; font-size: 11px; text-transform: uppercase; }
        .usage-bad { color: #ef4444; font-weight: 800; }
        .usage-good { color: #10b981; font-weight: 800; }
    </style>
</head>
<body>
    <?= generateMenu($pdo); ?>
    <div class="container">
        <div class="card">
            <h2>Consumption & Yield History</h2>
            <p style="color:#64748b;">Reviewing the Actual Qty vs. Usage Percentage per item.</p>
            
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Item Name</th>
                        <th>On-Hand (Pcs)</th>
                        <th>Usage (Pcs)</th>
                        <th>Yield (Usage %)</th>
                        <th>Recorded By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($logs as $log): ?>
                    <tr>
                        <td><?= date('M d, H:i', strtotime($log['Date_Recorded'])) ?></td>
                        <td><strong><?= strtoupper($log['ItemName']) ?></strong></td>
                        <td><?= number_format($log['actual_qty_pcs'], 2) ?></td>
                        <td><?= number_format($log['Usage_Pcs'], 2) ?></td>
                        <td class="<?= ($log['Usage_Percent'] > 0.5) ? 'usage-bad' : 'usage-good' ?>">
                            <?= number_format($log['Usage_Percent'], 4) ?>%
                        </td>
                        <td><?= $log['Recorded_By'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>