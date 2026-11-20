<?php
require_once 'db.php';
include_once 'engine/menu_generator.php'; 

// --- 1. HANDLE NEW PAYMENT ---
if (isset($_POST['add_payment'])) {
    $pid    = $_POST['payroll_id'];
    $amt    = $_POST['amount'];
    $p_date = $_POST['date_paid'];
    $target = $_POST['recipient'];
    $ref    = $_POST['ref_no'];

    // Updated to match your 'payments' table structure
    $ins = $pdo->prepare("INSERT INTO payments (payroll_id, amount_paid, payment_date, recipient, reference_no) VALUES (?, ?, ?, ?, ?)");
    if ($ins->execute([$pid, $amt, $p_date, $target, $ref])) {
        // Also update the payrolls table summary
        $upd = $pdo->prepare("UPDATE payrolls SET paid_amount = paid_amount + ?, balance_amount = total_amount - (paid_amount) WHERE id = ?");
        $upd->execute([$amt, $pid]);
        
        header("Location: payroll_manage.php");
        exit;
    }
}

echo "<html><head><title>Payroll Dashboard</title>";
echo '<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">';
echo "<style>
    body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .status-paid { color: #28a745; font-weight: bold; background: #e8f5e9; padding: 4px 8px; border-radius: 4px; }
    .status-unpaid { color: #dc3545; font-weight: bold; background: #ffebee; padding: 4px 8px; border-radius: 4px; }
    .status-partial { color: #fd7e14; font-weight: bold; background: #fff3e0; padding: 4px 8px; border-radius: 4px; }
    .table thead th { background: #343a40; color: white; border: none; font-size: 0.9rem; }
    .card { border: none; border-radius: 12px; overflow: hidden; }
    .empty-state { padding: 60px; text-align: center; color: #6c757d; }
</style></head><body>";

if (function_exists('generateMenu')) { echo generateMenu($pdo); }

echo "<div class='container-fluid mt-4'>";
echo "<div class='d-flex justify-content-between align-items-center mb-4'>";
echo "<h2 style='font-weight:700;'>💳 Payroll Management</h2>";
echo "<a href='payroll_gen.php' class='btn btn-success px-4' style='border-radius:8px;'>🚀 Run Generator</a>";
echo "</div>";

// Query using your ACTUAL column names from phpMyAdmin
$sql = "SELECT 
            p.*, 
            u.Name as employee_name
        FROM payrolls p
        JOIN users u ON p.user_id = u.id
        ORDER BY p.id DESC";

$stmt = $pdo->query($sql);
$hasData = ($stmt && $stmt->rowCount() > 0);
?>

<div class='card shadow-sm'>
    <div class='card-body p-0'>
        <table class='table table-hover mb-0'>
            <thead>
                <tr>
                    <th>Pay Period</th>
                    <th>Employee Name</th>
                    <th class="text-right">Total Owed</th>
                    <th class="text-right">Amount Paid</th>
                    <th class="text-right">Balance</th>
                    <th class="text-center">Payment Status</th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($hasData): ?>
                <?php while ($row = $stmt->fetch()): 
                    $total   = $row['total_amount'];
                    $paid    = $row['paid_amount'];
                    $balance = $row['balance_amount'];
                    
                    if ($balance <= 0) { $status_text = 'PAID'; } 
                    elseif ($paid > 0) { $status_text = 'PARTIAL'; } 
                    else { $status_text = 'UNPAID'; }
                    
                    $status_class = strtolower("status-$status_text");
                ?>
                    <tr>
                        <td class="align-middle"><?= htmlspecialchars($row['pay_period_start']) ?> to <?= htmlspecialchars($row['pay_period_end']) ?></td>
                        <td class="align-middle"><strong><?= htmlspecialchars($row['employee_name']) ?></strong></td>
                        <td class="text-right align-middle">$<?= number_format($total, 2) ?></td>
                        <td class="text-right align-middle text-success">$<?= number_format($paid, 2) ?></td>
                        <td class="text-right align-middle text-danger"><strong>$<?= number_format($balance, 2) ?></strong></td>
                        <td class="text-center align-middle"><span class='<?= $status_class ?>'><?= $status_text ?></span></td>
                        <td class="text-center align-middle">
                            <button class='btn btn-sm btn-primary' onclick="showPaymentModal(<?= $row['id'] ?>, '<?= addslashes($row['employee_name']) ?>', <?= $balance ?>)">Add Payment</button>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" class="empty-state">
                        <img src="https://cdn-icons-png.flaticon.com/512/4076/4076432.png" width="80" style="opacity: 0.2; margin-bottom: 15px;"><br>
                        <h5>No payroll records found.</h5>
                        <p>Generate a new payroll period to start tracking payments.</p>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="paymentModal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:30px; border-radius:15px; box-shadow:0 15px 40px rgba(0,0,0,0.3); z-index:9999; width:420px; border: 1px solid #ddd;">
    <h4 class="mb-1">Submit Payment</h4>
    <p id="modalName" class="text-muted small mb-4"></p>
    <form method="POST">
        <input type="hidden" name="payroll_id" id="modalPid">
        <div class="form-group">
            <label class="small font-weight-bold">Amount to Pay</label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="number" step="0.01" name="amount" id="modalAmount" class="form-control" required>
            </div>
        </div>
        <div class="form-group">
            <label class="small font-weight-bold">Recipient Type</label>
            <select name="recipient" class="form-control">
                <option value="Employee">Employee (Net Pay)</option>
                <option value="CRA">CRA (Source Deductions)</option>
            </select>
        </div>
        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    <label class="small font-weight-bold">Payment Date</label>
                    <input type="date" name="date_paid" value="<?= date('Y-m-d') ?>" class="form-control">
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    <label class="small font-weight-bold">Ref / Cheque #</label>
                    <input type="text" name="ref_no" class="form-control" placeholder="e.g. E-Transfer">
                </div>
            </div>
        </div>
        <hr>
        <button type="submit" name="add_payment" class="btn btn-primary btn-block btn-lg shadow-sm">Confirm Payment</button>
        <button type="button" onclick="document.getElementById('paymentModal').style.display='none'" class="btn btn-link btn-block text-muted mt-1">Cancel</button>
    </form>
</div>

<script>
function showPaymentModal(id, name, balance) {
    document.getElementById('modalPid').value = id;
    document.getElementById('modalName').innerText = "Recording payment for: " + name;
    document.getElementById('modalAmount').value = balance > 0 ? balance.toFixed(2) : "0.00";
    document.getElementById('paymentModal').style.display = 'block';
}
</script>

<?php echo "</div></body></html>"; ?>