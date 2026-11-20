<?php
/** Path: /edit_daily_report.php */
require_once 'engine.php';

$id = $_GET['id'] ?? die('ID Missing');
$stmt = $pdo->prepare("SELECT dr.*, s.nick_name FROM daily_report dr LEFT JOIN sites s ON dr.restaurant_id = s.SId WHERE report_id = ?");
$stmt->execute([$id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        .card { max-width: 450px; margin: 50px auto; background: white; padding: 30px; border-radius: 12px; border: 1px solid #cbd5e1; font-family: sans-serif; }
        input { width: 100%; padding: 12px; margin: 10px 0 20px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box; }
        button { width: 100%; background: #0ea5e9; color: white; border: none; padding: 14px; border-radius: 8px; font-weight: bold; cursor: pointer; }
    </style>
</head>
<body style="background:#f1f5f9;">
    <div class="card">
        <h3>Edit Report: <?=$report['nick_name']?></h3>
        <form action="manage_daily_report.php" method="POST">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="report_id" value="<?=$report['report_id']?>">
            
            <label>Daily POS Sales ($)</label>
            <input type="number" step="0.01" name="pos_sales" value="<?=(float)$report['pos_sales']?>">
            
            <label>Wastage Pieces</label>
            <input type="number" name="wastage_pieces" value="<?=(int)($report['wastage_pieces'] ?? 0)?>" placeholder="Enter pieces">

            <button type="submit">Update Record</button>
            <a href="daily_report_view.php" style="display:block; text-align:center; margin-top:15px; color:#64748b; text-decoration:none;">Cancel</a>
        </form>
    </div>
</body>
</html>