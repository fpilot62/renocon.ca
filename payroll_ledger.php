<?php
/** Path: /payroll_ledger.php */
ob_start(); // This captures output in a buffer to prevent "headers already sent"
require_once 'engine.php';

// Handle Actions FIRST before any HTML starts
if (isset($_GET['action']) && isset($_GET['att_id'])) {
    $id = (int)$_GET['att_id'];
    
    if ($_GET['action'] === 'undo') {
        $pdo->prepare("UPDATE attendance SET is_processed = 0 WHERE id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM payroll_records WHERE id = ?")->execute([$id]);
        header("Location: manager_dashboard.php"); // This will now work
        exit;
    } elseif ($_GET['action'] === 'finalize') {
        $pdo->prepare("UPDATE attendance SET is_processed = 2 WHERE id = ?")->execute([$id]);
        header("Location: payroll_history.php");
        exit;
    }
}

// REST OF YOUR PAGE DATA FETCHING...

// Fixed Query: Matches attendance and payroll_records on 'id'
$sql = "SELECT a.id as att_id, u.Name as emp_name, 
               p.allocated_reg_hrs, p.rate_used, p.balance_hrs, p.balance_rate
        FROM attendance a
        LEFT JOIN payroll_records p ON a.id = p.id
        LEFT JOIN users u ON a.user_id = u.id
        WHERE a.is_processed = 1 
        ORDER BY a.id DESC";

$history = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>

<!DOCTYPE html>
<html>
<head>
    <style>
        .ledger-table { width: 100%; border-collapse: collapse; background: white; margin-top: 20px; }
        .ledger-table th { background: #1e293b; color: white; padding: 12px; text-align: left; }
        .ledger-table td { padding: 12px; border-bottom: 1px solid #eee; }
        .btn { padding: 5px 10px; border-radius: 4px; text-decoration: none; font-size: 12px; }
        .btn-undo { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        .btn-fin { background: #059669; color: white; margin-left: 5px; }
    </style>
</head>
<body style="background:#f3f4f6; padding:20px;">
    <h2>Payroll Ledger (Stage 2)</h2>
    <table class="ledger-table">
        <thead>
            <tr>
                <th>ID / Employee</th>
                <th>Reg Pay</th>
                <th>Bal Pay</th>
                <th>Total Net</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($history as $row): 
                $reg_p = ($row['allocated_reg_hrs'] ?? 0) * ($row['rate_used'] ?? 0);
                $bal_p = ($row['balance_hrs'] ?? 0) * ($row['balance_rate'] ?? 0);
            ?>
            <tr>
                <td><strong>#<?= $row['att_id'] ?></strong> <?= htmlspecialchars($row['emp_name'] ?? 'Staff') ?></td>
                <td>$<?= number_format($reg_p, 2) ?></td>
                <td>$<?= number_format($bal_p, 2) ?></td>
                <td style="font-weight:bold; color:green;">$<?= number_format($reg_p + $bal_p, 2) ?></td>
                <td>
                    <a href="payroll_ledger.php?action=undo&att_id=<?= $row['att_id'] ?>" class="btn btn-undo">🔄 Undo</a>
                    <a href="payroll_ledger.php?action=finalize&att_id=<?= $row['att_id'] ?>" class="btn btn-fin">✅ Finalize</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>