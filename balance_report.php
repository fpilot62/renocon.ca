<?php
/** Path: /payroll/balance_report.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';

if (!function_exists('ps_topbar_org') && is_file(__DIR__ . '/payroll_scope.php')) { require_once __DIR__ . '/payroll_scope.php'; }
$org_id = function_exists('ps_topbar_org') && ps_topbar_org() > 0 ? ps_topbar_org() : $_SESSION['org_id'];

// Calculate total balance for each user
$sql = "SELECT u.Name, 
        SUM(h.hours_added) - SUM(h.hours_deducted) as current_balance
        FROM users u
        JOIN hour_balances h ON u.id = h.user_id
        WHERE u.org_id = ?
        GROUP BY u.id
        HAVING current_balance != 0";
$stmt = $pdo->prepare($sql);
$stmt->execute([$org_id]);
$balances = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'menu_include.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Banked Hours Ledger</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f1f5f9; padding: 20px; }
        .bal-card { background: white; padding: 20px; border-radius: 12px; max-width: 600px; margin: auto; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .row { display: flex; justify-content: space-between; padding: 15px 0; border-bottom: 1px solid #f1f5f9; }
    </style>
</head>
<body>
    <div class="bal-card">
        <h2 style="margin-top:0;"><i class="fas fa-vault" style="color:#f59e0b;"></i> Banked Hours Ledger</h2>
        <p style="font-size:12px; color:#64748b;">These hours are worked but not yet paid through official payroll.</p>
        
        <?php foreach($balances as $b): ?>
        <div class="row">
            <span><strong><?= htmlspecialchars($b['Name']) ?></strong></span>
            <span style="color:#10b981; font-weight:bold;"><?= $b['current_balance'] ?> hrs</span>
        </div>
        <?php endforeach; ?>

        <?php if(empty($balances)): ?>
            <p style="text-align:center; padding:20px; color:#94a3b8;">No banked hours found.</p>
        <?php endif; ?>
    </div>
</body>
</html>