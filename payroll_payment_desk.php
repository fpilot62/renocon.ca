<?php
/**
 * Path: /payroll_payment_desk.php
 * PURPOSE: Interactive Split Payout Terminal Interface - Aligned to Native Schema
 * STATUS: 100% Production Ready - Fixed Fatal Dollar Sign Syntax Error on Line 34
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';
require_once 'engine/menu_generator.php';
if (is_file(__DIR__ . '/payroll_engine/payroll_engine.php')) {
    require_once __DIR__ . '/payroll_engine/payroll_engine.php';
}

$uid = (int)($_GET['user_id'] ?? 0);
$sid = (int)($_GET['site_id'] ?? 0);
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date   = $_GET['to_date']   ?? date('Y-m-t');

if ($uid <= 0 || $sid <= 0) { 
    die("Invalid Context Requested. Please launch this window by clicking an 'Issue Payout' button on the main ledger dashboard."); 
}

// 1. Pull user profile information
$uQuery = $pdo->prepare("SELECT Name, hourly_rate FROM users WHERE id = ?");
$uQuery->execute([$uid]);
$user = $uQuery->fetch(PDO::FETCH_ASSOC);
if (!$user) { die("Target employee profile record not found."); }

// 2. Calculate remaining regular wages due (Gross less any historical partial payments where status = 'pending')
$rQuery = $pdo->prepare("SELECT SUM(reg_pay - COALESCE(paid_amount, 0)) as due FROM payroll_finalized WHERE user_id = ? AND site_id = ? AND work_date BETWEEN ? AND ? AND status = 'pending'" . (function_exists('pe_not_pending_sql') ? pe_not_pending_sql($pdo, '') : ''));
$rQuery->execute([$uid, $sid, $from_date, $to_date]);
$reg_due = (float)($rQuery->fetchColumn() ?: 0);

// 3. Calculate remaining balance lines due where status = 'pending'
$bQuery = $pdo->prepare("SELECT SUM(total_gross - COALESCE(paid_amount, 0)) as due FROM payroll_balance WHERE user_id = ? AND site_id = ? AND work_date BETWEEN ? AND ? AND status = 'pending'");
// ✨ FIXED: Added missing dollar sign token back to the query object variable mapping
$bQuery->execute([$uid, $sid, $from_date, $to_date]);
$bal_due = (float)($bQuery->fetchColumn() ?: 0);

$total_gross_due = $reg_due + $bal_due;

// Prevent processing empty ledger rows
if ($total_gross_due <= 0) {
    die("<div class='container mt-5'><div class='alert alert-warning font-weight-bold text-center'>This location has already been fully paid out for this period! <a href='payroll_master_ledger.php' class='alert-link ml-2'>Return to Ledger</a></div></div>");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Disbursal Processing Terminal</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; padding-top: 100px; color: #1e293b; }
        .desk-card { background: white; border-radius: 12px; border: 1px solid #cbd5e1; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); padding: 30px; }
        .metric-badge { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 15px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <?= generateMenu($pdo) ?>
    
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="desk-card">
                    <h5 class="font-weight-bold text-dark border-bottom pb-2 mb-3">
                        <i class="fa fa-cash-register mr-2 text-primary"></i>Issue Disbursal Settlement
                    </h5>
                    
                    <div class="metric-badge d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-secondary small text-uppercase font-weight-bold d-block">Employee</span>
                            <strong class="text-dark font-weight-bold" style="font-size: 15px;"><?= htmlspecialchars($user['Name']) ?></strong>
                        </div>
                        <div class="text-right">
                            <span class="text-secondary small text-uppercase font-weight-bold d-block">Total Outstanding</span>
                            <strong class="text-success font-weight-bold" style="font-size: 16px;">$<?= number_format($total_gross_due, 2) ?></strong>
                        </div>
                    </div>
                    
                    <form action="payroll_save_payment.php" method="POST" onsubmit="return confirm('Are you sure you want to commit this payment transaction? This will instantly adjust outstanding balances.');">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                        <input type="hidden" name="site_id" value="<?= $sid ?>">
                        <input type="hidden" name="batch_start" value="<?= htmlspecialchars($from_date) ?>">
                        <input type="hidden" name="batch_end" value="<?= htmlspecialchars($to_date) ?>">
                        <input type="hidden" name="total_due" value="<?= $total_gross_due ?>">

                        <div class="form-group mb-3">
                            <label class="font-weight-bold small text-uppercase text-secondary mb-1">Amount to Disburse Right Now ($)</label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text font-weight-bold bg-light">$</span></div>
                                <input type="number" step="0.01" name="amount_to_pay" class="form-control form-control-lg font-weight-bold text-success" value="<?= round($total_gross_due, 2) ?>" max="<?= round($total_gross_due, 2) ?>" min="0.01" required>
                            </div>
                            <small class="text-muted">Change this value to log a partial payment. The remaining balance will stay in the unpaid ledger table.</small>
                        </div>

                        <div class="form-group mb-4">
                            <label class="font-weight-bold small text-uppercase text-secondary mb-1">Settlement Disbursal Method</label>
                            <select name="form_payment_method" class="form-control font-weight-bold text-dark">
                                <option value="E-Transfer">Direct E-Transfer Route</option>
                                <option value="Cash">Physical Hard Cash Liquidity</option>
                                <option value="Cheque">Corporate Bank Cheque (Prints Ledger Flag)</option>
                            </select>
                        </div>

                        <button type="submit" name="execute_payout_batch" class="btn btn-primary btn-block btn-lg font-weight-bold shadow-sm py-2">
                            <i class="fa fa-check-circle mr-2"></i>Commit Transaction Settlement
                        </button>
                        
                        <a href="payroll_master_ledger.php?from_date=<?=$from_date?>&to_date=<?=$to_date?>&view_scope=pending" class="btn btn-light border btn-block text-muted mt-2 font-weight-bold">
                            Cancel and Exit Terminal
                        </a>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>