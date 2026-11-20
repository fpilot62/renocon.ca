<?php
/** Path: /consolidated_report.php */
require_once 'db.php'; 
require_once 'engine/menu_generator.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Default to current month
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date   = $_GET['end'] ?? date('Y-m-d');

/** * Aggregated Executive Query 
 * Using the exact table names from your working script
 */
$sql = "SELECT 
            s.nick_name,
            s.SId,
            SUM(dr.pos_sales) as total_sales,
            (SELECT SUM(t.reg_pay) FROM payroll_finalized t 
             WHERE t.site_id = s.SId AND t.work_date BETWEEN ? AND ?) as period_labor,
            (SELECT SUM(fc.Amount) FROM FoodCost fc 
             WHERE fc.RId = s.SId AND fc.InvoiceDate BETWEEN ? AND ?) as period_food
        FROM sites s
        LEFT JOIN daily_report dr ON s.SId = dr.restaurant_id AND dr.report_date BETWEEN ? AND ?
        GROUP BY s.SId, s.nick_name
        ORDER BY total_sales DESC";

$params = [$start_date, $end_date, $start_date, $end_date, $start_date, $end_date];
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Global Totals for the Dashboard
$g_sales = 0; $g_food = 0; $g_labor = 0;
foreach($summary as $row) {
    $g_sales += (float)$row['total_sales'];
    $g_food  += (float)$row['period_food'];
    $g_labor += (float)$row['period_labor'];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Executive Profit/Loss Summary</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: sans-serif; background: #f1f5f9; margin: 0; color: #1e293b; }
        .container { padding: 25px; max-width: 1200px; margin: auto; }
        
        /* Summary Grid */
        .summary-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 25px; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); text-align: center; }
        .card label { font-size: 11px; font-weight: bold; color: #64748b; text-transform: uppercase; }
        .card h2 { margin: 10px 0 0; font-size: 24px; color: #0f172a; }

        /* Table Style */
        .report-table { width: 100%; border-collapse: collapse; background: white; border-radius: 10px; overflow: hidden; }
        .report-table th { background: #1e293b; color: white; padding: 12px; text-align: left; font-size: 11px; text-transform: uppercase; }
        .report-table td { padding: 12px; border-bottom: 1px solid #e2e8f0; font-size: 13px; }
        
        .pct-badge { padding: 4px 8px; border-radius: 15px; font-weight: bold; font-size: 11px; }
        .bg-red { background: #fee2e2; color: #b91c1c; }
        .bg-green { background: #dcfce7; color: #15803d; }
        
        .filter-bar { background: white; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; gap: 10px; align-items: flex-end; }
        input[type="date"] { padding: 8px; border: 1px solid #cbd5e1; border-radius: 5px; }
    </style>
</head>
<body>

<?php echo generateMenu($pdo); ?>

<div class="container">
    <h2>Executive Site Comparison</h2>

    <form method="GET" class="filter-bar">
        <input type="date" name="start" value="<?=$start_date?>">
        <input type="date" name="end" value="<?=$end_date?>">
        <button type="submit" style="background:#1e293b; color:white; padding:9px 15px; border:none; border-radius:5px; cursor:pointer; font-weight:bold;">Run Report</button>
    </form>

    <div class="summary-grid">
        <div class="card">
            <label>Total Revenue</label>
            <h2>$<?= number_format($g_sales, 2) ?></h2>
        </div>
        <div class="card">
            <label>Food Cost Avg</label>
            <h2 style="color:#15803d;"><?= $g_sales > 0 ? number_format(($g_food/$g_sales)*100, 1) : 0 ?>%</h2>
        </div>
        <div class="card">
            <label>Labor Cost Avg</label>
            <h2 style="color:#b91c1c;"><?= $g_sales > 0 ? number_format(($g_labor/$g_sales)*100, 1) : 0 ?>%</h2>
        </div>
    </div>

    <table class="report-table">
        <thead>
            <tr>
                <th>Site Name</th>
                <th>Revenue</th>
                <th>Food %</th>
                <th>Labor %</th>
                <th>Prime Cost %</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($summary as $s): 
                $f_pct = $s['total_sales'] > 0 ? ($s['period_food'] / $s['total_sales']) * 100 : 0;
                $l_pct = $s['total_sales'] > 0 ? ($s['period_labor'] / $s['total_sales']) * 100 : 0;
                $prime = $f_pct + $l_pct;
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($s['nick_name']) ?></strong></td>
                <td style="font-weight:bold;">$<?= number_format($s['total_sales'], 2) ?></td>
                <td><span class="pct-badge <?= $f_pct > 35 ? 'bg-red' : 'bg-green' ?>"><?= number_format($f_pct, 1) ?>%</span></td>
                <td><span class="pct-badge <?= $l_pct > 30 ? 'bg-red' : 'bg-green' ?>"><?= number_format($l_pct, 1) ?>%</span></td>
                <td style="font-weight:800; color:<?= $prime > 65 ? '#b91c1c' : '#1e293b' ?>;"><?= number_format($prime, 1) ?>%</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>