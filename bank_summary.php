<?php
/** Path: /bank_summary.php */
require_once 'db.php';

$query = "SELECT u.Name, 
                 SUM(h.hours_added) as current_bank_balance
          FROM users u
          LEFT JOIN hour_balances h ON u.id = h.user_id
          GROUP BY u.id";

$stmt = $pdo->query($query);
?>

<div class="container" style="margin-top:20px;">
    <h3>Employee Hour Bank Balances</h3>
    <table class="table table-bordered">
        <thead class="thead-dark">
            <tr>
                <th>Employee</th>
                <th>Banked Hours Available</th>
                <th>Value at Reg Rate ($20)</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $stmt->fetch()): ?>
            <tr>
                <td><?= htmlspecialchars($row['Name']) ?></td>
                <td class="text-primary"><b><?= number_format($row['current_bank_balance'] ?? 0, 2) ?>h</b></td>
                <td>$<?= number_format(($row['current_bank_balance'] ?? 0) * 20, 2) ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div><hr>
<div class="card p-3">
    <h5>Withdraw Banked Hours</h5>
    <form action="process_withdrawal.php" method="POST" class="form-inline">
        <input type="hidden" name="user_id" value="5"> <label class="mr-2">Hours to Pay Out:</label>
        <input type="number" name="hours" step="0.01" class="form-control mr-2" required>
        <button type="submit" class="btn btn-warning">Withdraw & Pay @ $20</button>
    </form>
</div>