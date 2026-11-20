<?php
/**
 * Path: /payroll_payout_manager.php
 * STATUS: ROSTER DISPATCH PANEL WITH ROW SELECTORS & FILTER FALLBACK
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';
require_once 'engine/menu_generator.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// BACKEND FALLBACK: If parameters are completely missing, don't throw an error. Calculate the active period boundaries!
$day = (int)date('d');
$default_start = ($day <= 15) ? date('Y-m-01') : date('Y-m-16');
$default_end   = ($day <= 15) ? date('Y-m-15') : date('Y-m-t');

$f_start = $_GET['from_date'] ?? $default_start;
$f_end   = $_GET['to_date']   ?? $default_end;
$target_uid = $_GET['user_id'] ?? '';

try {
    $sql = "SELECT u.id as user_id, u.Name as staff_name, u.hourly_rate,
                   IFNULL(SUM(pf.reg_hours), 0) as total_reg_hours,
                   IFNULL(SUM(pf.reg_pay), 0) as regular_gross_pay,
                   IFNULL(SUM(pb.balance_hours), 0) as total_bal_hours,
                   IFNULL(SUM(pb.total_gross), 0) as balance_gross_pay
            FROM users u
            LEFT JOIN payroll_finalized pf ON u.id = pf.user_id AND pf.work_date BETWEEN ? AND ?
            LEFT JOIN payroll_balance pb ON u.id = pb.user_id AND pb.work_date BETWEEN ? AND ?
            WHERE 1=1";

    $params = [$f_start, $f_end, $f_start, $f_end];
    if ($target_uid !== '') {
        $sql .= " AND u.id = ?";
        $params[] = (int)$target_uid;
    }

    $sql .= " GROUP BY u.id HAVING total_reg_hours > 0 OR total_bal_hours > 0 ORDER BY u.Name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Database Processing Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Consolidated Roster Executions</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f8fafc; color: #1e293b; padding-top: 90px; font-family: 'Inter', sans-serif; font-size: 11px; }
        .batch-container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        .top-meta-strip { background: #fafafa; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; margin-bottom: 25px; }
        .roster-card-row { background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; margin-bottom: 12px; display: flex; align-items: center; }
        .roster-card-row:hover { border-color: #cbd5e1; }
        .payout-box { max-width: 110px; text-align: center; font-weight: bold; color: #16a34a; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; }
        .master-submit-btn { background: #4f46e5; color: white; border: none; padding: 10px 30px; border-radius: 6px; font-weight: bold; text-transform: uppercase; cursor: pointer; }
        .checkbox-container-block { display: flex; align-items: center; justify-content: center; padding-right: 20px; margin-right: 10px; }
        .custom-check-lg { transform: scale(1.5); cursor: pointer; }
    </style>
</head>
<body>
    <?= generateMenu($pdo) ?>
    <div class="container mb-5">
        <div class="batch-container shadow-sm">
            
            <form method="POST" action="process_bulk_payment.php">
                <input type="hidden" name="start_date" value="<?= htmlspecialchars($f_start) ?>">
                <input type="hidden" name="end_date" value="<?= htmlspecialchars($f_end) ?>">

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="font-weight-bold mb-1" style="color:#0f172a; font-size: 22px;"><i class="fa fa-cubes text-indigo mr-2"></i> Consolidated Roster Executions</h2>
                        <span class="text-muted font-weight-bold">Pay Period Cycle Focus: <span class="text-dark"><?= date('M d, Y', strtotime($f_start)) ?></span> ── TO ── <span class="text-dark"><?= date('M d, Y', strtotime($f_end)) ?></span></span>
                    </div>
                    <a href="payroll_master_ledger.php?from_date=<?= $f_start ?>&to_date=<?= $f_end ?>" class="btn btn-outline-secondary btn-sm font-weight-bold"><i class="fa fa-arrow-left mr-1"></i> Central Command Ledger</a>
                </div>

                <div class="top-meta-strip row mx-0 align-items-center shadow-sm">
                    <div class="col-md-4">
                        <label class="small font-weight-bold text-muted text-uppercase mb-1">Batch Release Date</label>
                        <input type="date" class="form-control form-control-sm font-weight-bold" value="<?= date('Y-m-d') ?>" readonly>
                    </div>
                    <div class="col-md-5">
                        <label class="small font-weight-bold text-muted text-uppercase mb-1">Batch Payment Release Channel</label>
                        <select name="global_payment_channel" class="form-control form-control-sm font-weight-bold">
                            <option value="Electronic E-Transfer Check">Electronic E-Transfer Check</option>
                            <option value="Corporate Cheque">Corporate Cheque Instrument</option>
                            <option value="Direct Deposit Hub">Direct Bank Deposit Allocation</option>
                            <option value="Cash Disbursement">Cash Currency Disbursement</option>
                        </select>
                    </div>
                    <div class="col-md-3 text-right">
                        <div class="p-2 border rounded bg-white text-center shadow-sm d-flex align-items-center justify-content-center">
                            <input type="checkbox" id="masterCheckboxSelectAll" class="custom-check-lg" checked>
                            <label for="masterCheckboxSelectAll" class="small font-weight-bold text-uppercase mb-0 ml-2 cursor-pointer">Select All Roster</label>
                        </div>
                    </div>
                </div>

                <?php foreach ($records as $r): 
                    $gross_reg = (float)$r['regular_gross_pay'];
                    $gross_bal = (float)$r['balance_gross_pay'];
                    $total_gross = $gross_reg + $gross_bal;
                    $deductions = $total_gross * 0.1961; 
                    $net_owed = $total_gross - $deductions;
                ?>
                    <div class="roster-card-row shadow-sm">
                        <div class="checkbox-container-block border-right pr-4">
                            <input type="checkbox" name="approved_payouts[]" value="<?= $r['user_id'] ?>" class="custom-check-lg individual-roster-check" checked>
                        </div>
                        <div class="row w-100 align-items-center mx-0">
                            <div class="col-md-3">
                                <h4 class="font-weight-bold mb-1 text-dark" style="font-size: 14px;"><?= htmlspecialchars($r['staff_name']) ?></h4>
                                <span class="small text-muted font-weight-bold">ID REFERENCE: #10<?= $r['user_id'] ?></span>
                            </div>
                            <div class="col-md-4 border-left text-muted" style="line-height:1.7;">
                                <div class="d-flex justify-content-between"><span>Regular Core:</span><strong class="text-dark"><?= number_format($r['total_reg_hours'], 2) ?>h ($<?= number_format($gross_reg, 2) ?>)</strong></div>
                                <div class="d-flex justify-content-between"><span>Overtime Storage Balance:</span><strong class="text-dark"><?= number_format($r['total_bal_hours'], 2) ?>h ($<?= number_format($gross_bal, 2) ?>)</strong></div>
                            </div>
                            <div class="col-md-3 border-left text-right">
                                <div class="small text-muted font-weight-bold">Gross: $<?= number_format($total_gross, 2) ?></div>
                                <div class="small text-danger">Deductions Slices: -$<?= number_format($deductions, 2) ?></div>
                                <div class="h6 font-weight-bold text-success mt-1">Net Owed: $<?= number_format($net_owed, 2) ?></div>
                            </div>
                            <div class="col-md-2 text-right">
                                <input type="number" step="0.01" name="override_amounts[<?= $r['user_id'] ?>]" class="form-control form-control-sm payout-box ml-auto" value="<?= round($net_owed, 2) ?>">
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="text-right mt-4">
                    <button type="submit" class="master-submit-btn shadow-sm">Execute Roster Batch Transmission <i class="fa fa-paper-plane ml-1"></i></button>
                </div>
            </form>

        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script>
        $('#masterCheckboxSelectAll').on('change', function() {
            $('.individual-roster-check').prop('checked', this.checked);
        });
        $('.individual-roster-check').on('change', function() {
            if (!this.checked) { $('#masterCheckboxSelectAll').prop('checked', false); }
            else if ($('.individual-roster-check:checked').length === $('.individual-roster-check').length) { $('#masterCheckboxSelectAll').prop('checked', true); }
        });
    </script>
</body>
</html>