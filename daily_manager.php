<?php
/** Path: /daily_manager.php */

// 1. Force Error Reporting (To stop blank pages)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 2. Database Connection
if (!file_exists("db.php")) { 
    die("Error: db.php not found. Please ensure this file is in the same folder."); 
}
require_once "db.php"; 

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 3. Resolve Filters
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date   = $_GET['end'] ?? date('Y-m-d');

try {
    // 4. FETCH SALES (All Sites)
    $sql = "SELECT dr.*, s.nick_name 
            FROM daily_report dr
            LEFT JOIN sites s ON dr.restaurant_id = s.SId
            WHERE dr.report_date BETWEEN ? AND ?
            ORDER BY dr.report_date DESC, s.nick_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start_date, $end_date]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. FETCH LABOR (Attendance)
    $labor_sql = "SELECT site_id, DATE(clock_in) as work_date,
                  SUM((TIMESTAMPDIFF(SECOND, clock_in, 
                      CASE 
                        WHEN clock_out IS NULL OR clock_out = '0000-00-00 00:00:00' OR clock_out = '' THEN NOW() 
                        ELSE clock_out 
                      END
                  ) / 3600) * u.official_rate) as total_labor
                  FROM attendance a
                  JOIN users u ON a.user_id = u.id
                  WHERE DATE(clock_in) BETWEEN ? AND ?
                  GROUP BY site_id, work_date";

    $l_stmt = $pdo->prepare($labor_sql);
    $l_stmt->execute([$start_date, $end_date]);
    $raw_labor = $l_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Map labor by Site ID and Date
    $labor_map = [];
    foreach($raw_labor as $ld) {
        $s_id = (int)$ld['site_id'];
        $d_key = $ld['work_date'];
        $labor_map[$s_id][$d_key] = (float)$ld['total_labor'];
    }

} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}

// 6. Include Menu Generator
if (file_exists("engine/menu_generator.php")) {
    require_once "engine/menu_generator.php"; 
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Daily Business Audit | SIP</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { background: #f1f5f9; padding-top: 130px; font-family: 'Inter', sans-serif; }
        .rpt-card { background: white; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); padding: 25px; }
        .table th { background: #1e293b; color: white; font-size: 11px; text-transform: uppercase; }
        .table td { vertical-align: middle; font-size: 13px; }
        .edit-input { width: 110px; border: 1px solid #cbd5e1; border-radius: 4px; padding: 4px; text-align: right; font-weight: 700; }
        .val-warning { color: #dc2626; font-weight: 800; background: #fee2e2; padding: 2px 6px; border-radius: 4px; }
        .pdf-icon { font-size: 18px; color: #e2e8f0; }
        .pdf-icon.active { color: #ef4444; } /* Red for existing files */
    </style>
</head>
<body>

    <?php if (function_exists('generateMenu')) { echo generateMenu($pdo); } ?>
    
    <div class="container-fluid px-5">
        <div class="rpt-card">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="font-weight-bold m-0">Daily Business Audit</h3>
                <form method="GET" class="form-inline">
                    <input type="date" name="start" value="<?= $start_date ?>" class="form-control form-control-sm mr-2">
                    <input type="date" name="end" value="<?= $end_date ?>" class="form-control form-control-sm mr-2">
                    <button type="submit" class="btn btn-sm btn-primary font-weight-bold">LOAD REPORT</button>
                </form>
            </div>

            <table class="table table-hover table-bordered">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Site Name</th>
                        <th class="text-right">POS Sales</th>
                        <th class="text-right">Labor Cost (Live)</th>
                        <th class="text-center">Labor %</th>
                        <th class="text-center">Inv</th>
                        <th class="text-center">Dep</th>
                        <th class="text-center">Prod</th>
                        <th class="text-center">Paid</th>
                        <th class="text-center">Weekly</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reports)): ?>
                        <tr><td colspan="10" class="text-center p-4">No records found for these dates.</td></tr>
                    <?php endif; ?>

                    <?php foreach($reports as $r): 
                        $curr_date = $r['report_date'];
                        $curr_site = (int)$r['restaurant_id'];
                        
                        // Precise lookup from the Labor Map
                        $labor = $labor_map[$curr_site][$curr_date] ?? 0;
                        $sales = (float)$r['pos_sales'];
                        $labor_pct = ($sales > 0) ? ($labor / $sales) * 100 : 0;
                    ?>
                    <tr>
                        <td><?= date('M d, Y', strtotime($curr_date)) ?></td>
                        <td class="font-weight-bold"><?= strtoupper($r['nick_name'] ?? 'N/A') ?></td>
                        <td class="text-right">
                            <input type="number" step="0.01" class="edit-input" value="<?= number_format($sales, 2, '.', '') ?>">
                        </td>
                        <td class="text-right text-muted">$<?= number_format($labor, 2) ?></td>
                        <td class="text-center">
                            <span class="<?= ($labor_pct > 25) ? 'val-warning' : '' ?>">
                                <?= number_format($labor_pct, 1) ?>%
                            </span>
                        </td>
                        <td class="text-center"><i class="fa fa-file-pdf pdf-icon <?= !empty($r['inv_upload']) ? 'active' : '' ?>"></i></td>
                        <td class="text-center"><i class="fa fa-file-pdf pdf-icon <?= !empty($r['deposit_receipt']) ? 'active' : '' ?>"></i></td>
                        <td class="text-center"><i class="fa fa-file-pdf pdf-icon <?= !empty($r['production_rpt']) ? 'active' : '' ?>"></i></td>
                        <td class="text-center"><i class="fa fa-file-pdf pdf-icon <?= !empty($r['paid_out1']) ? 'active' : '' ?>"></i></td>
                        <td class="text-center"><i class="fa fa-file-pdf pdf-icon <?= !empty($r['weekly_POS_sales_report']) ? 'active' : '' ?>"></i></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>