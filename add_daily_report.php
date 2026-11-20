<?php
/** Path: /add_daily_report.php */
require_once 'engine.php';

// Fetch sites for the dropdown
if (!function_exists('sip_org_site_fence')) { require_once __DIR__ . '/engine/site_status_lib.php'; }
$__fence = sip_org_site_fence($pdo, 'sip_org_id');
$is_super = $__fence['is_super']; $selected_org = $__fence['selected_org']; $orgs = $__fence['orgs']; $sites = $__fence['sites'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Daily Report</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f1f5f9; padding: 40px; }
        .form-container { max-width: 500px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        h2 { color: #1e293b; margin-top: 0; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 8px; color: #475569; }
        input, select { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box; font-size: 15px; }
        .btn-submit { background-color: #22c55e; color: white; border: none; padding: 14px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; width: 100%; font-size: 16px; }
        .btn-submit:hover { background-color: #16a34a; }
        .back-link { display: block; text-align: center; margin-top: 20px; color: #64748b; text-decoration: none; }
    </style>
</head>
<body>

<div class="form-container">
    <h2>Add Daily Entry</h2>
    <form action="manage_daily_report.php" method="POST">
        <input type="hidden" name="action" value="add">
        
        <div class="form-group">
            <label>Report Date</label>
            <input type="date" name="report_date" required value="<?= date('Y-m-d') ?>">
        </div>

        <div class="form-group">
            <label>Restaurant Site</label>
            <select name="restaurant_id" required>
                <option value="">Select a Location...</option>
                <?php foreach($sites as $s): ?>
                    <option value="<?= $s['SId'] ?>"><?= htmlspecialchars($s['nick_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>POS Sales ($)</label>
            <input type="number" step="0.01" name="pos_sales" placeholder="0.00">
        </div>

        <button type="submit" class="btn-submit">Create Record</button>
        <a href="daily_report_view.php" class="back-link">← Cancel and Go Back</a>
    </form>
</div>

</body>
</html>