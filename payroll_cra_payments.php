<?php
/**
 * Path: /payroll_cra_payments.php
 * PURPOSE: CRA Tax Remittance Register — org-fenced.
 * Liabilities come only from payroll_masters created by Staff Audit / Finalize + pay flow.
 * Imported ledger display rows are not CRA source deductions.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';
require_once 'payroll_scope.php';
require_once 'engine/menu_generator.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
ps_require_payroll_admin();
$org_id = ps_selected_org($pdo);

$msg = $_GET['msg'] ?? '';

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date   = $_GET['to_date']   ?? date('Y-m-t');

$craPayHasOrg = (bool)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_cra_payments' AND COLUMN_NAME = 'org_id'"
)->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_cra_payment'])) {
    ps_require_csrf();
    $date_paid      = $_POST['date_paid'];
    $amount_paid    = (float)$_POST['amount_paid'];
    $payment_method = $_POST['payment_method'];
    $bank_ref_no    = $_POST['bank_ref_no'] ?? '';
    $notes          = $_POST['notes'] ?? '';
    $f_date         = $_POST['filter_from_date'];
    $t_date         = $_POST['filter_to_date'];

    if ($amount_paid > 0 && !empty($date_paid)) {
        try {
            $pdo->beginTransaction();

            if ($craPayHasOrg) {
                $ins = $pdo->prepare("INSERT INTO payroll_cra_payments (org_id, date_paid, amount_paid, payment_method, bank_ref_no, notes) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->execute([$org_id, $date_paid, $amount_paid, $payment_method, $bank_ref_no, $notes]);
            } else {
                $ins = $pdo->prepare("INSERT INTO payroll_cra_payments (date_paid, amount_paid, payment_method, bank_ref_no, notes) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$date_paid, $amount_paid, $payment_method, $bank_ref_no, $notes]);
            }

            $rem_pool = $amount_paid;
            $unpaid_masters = $pdo->prepare("SELECT id, cra_deductions FROM payroll_masters WHERE org_id=? AND cra_status = 'Unpaid' AND pay_period_start >= ? AND pay_period_end <= ? AND cra_deductions > 0 ORDER BY pay_period_start ASC");
            $unpaid_masters->execute([$org_id, $f_date, $t_date]);
            $masters = $unpaid_masters->fetchAll(PDO::FETCH_ASSOC);

            $upMaster = $pdo->prepare("UPDATE payroll_masters SET cra_status = 'Paid' WHERE id = ? AND org_id = ?");
            foreach ($masters as $m) {
                if ($rem_pool <= 0) break;
                $due = (float)$m['cra_deductions'];
                if ($rem_pool >= $due) {
                    $rem_pool -= $due;
                    $upMaster->execute([$m['id'], $org_id]);
                }
            }

            $pdo->commit();
            header("Location: payroll_cra_payments.php?from_date={$f_date}&to_date={$t_date}&msg=success");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            die("CRA Ledger Error: " . $e->getMessage());
        }
    }
}

$tOwed = $pdo->prepare("SELECT SUM(cra_deductions) FROM payroll_masters WHERE org_id=? AND pay_period_start >= ? AND pay_period_end <= ?");
$tOwed->execute([$org_id, $from_date, $to_date]);
$total_cra_owed = (float)($tOwed->fetchColumn() ?: 0);

if ($craPayHasOrg) {
    $tPaid = $pdo->prepare("SELECT SUM(amount_paid) FROM payroll_cra_payments WHERE org_id=? AND date_paid BETWEEN ? AND ?");
    $tPaid->execute([$org_id, $from_date, $to_date]);
    $hQuery = $pdo->prepare("SELECT * FROM payroll_cra_payments WHERE org_id=? AND date_paid BETWEEN ? AND ? ORDER BY date_paid DESC, id DESC");
    $hQuery->execute([$org_id, $from_date, $to_date]);
} else {
    $tPaid = $pdo->prepare("SELECT SUM(amount_paid) FROM payroll_cra_payments WHERE date_paid BETWEEN ? AND ?");
    $tPaid->execute([$from_date, $to_date]);
    $hQuery = $pdo->prepare("SELECT * FROM payroll_cra_payments WHERE date_paid BETWEEN ? AND ? ORDER BY date_paid DESC, id DESC");
    $hQuery->execute([$from_date, $to_date]);
}
$total_cra_paid = (float)($tPaid->fetchColumn() ?: 0);
$history = $hQuery->fetchAll(PDO::FETCH_ASSOC);

$current_balance_owed = $total_cra_owed - $total_cra_paid;
$no_source_deductions = ($total_cra_owed <= 0.004);
$hint_from = '2026-07-13';
$hint_to   = '2026-07-26';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CRA Tax Remittance Registry Desk</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; padding-top: 95px; color: #1e293b; font-size: 11px; }
        .control-panel-card { background: white; border-radius: 12px; border: 1px solid #cbd5e1; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); padding: 18px; margin-bottom: 20px; }
        .summary-card { background: white; border-radius: 12px; border: 1px solid #e2e8f0; padding: 18px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); height: 100%; }
        .form-card { background: white; border-radius: 12px; border: 1px solid #cbd5e1; padding: 22px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .table-cra th { background: #0f172a; color: white; padding: 10px; font-size: 11px; text-transform: uppercase; }
        .table-cra td { padding: 10px; vertical-align: middle; background: white; border-bottom: 1px solid #e2e8f0; font-weight: bold; }
    </style>
</head>
<body>
    <?= generateMenu($pdo) ?>

    <div class="container-fluid px-5">
        <?php if (file_exists(__DIR__.'/payroll_help.php')) { require_once __DIR__.'/payroll_help.php'; echo payroll_help_panel('payroll_cra_payments.php'); } ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="font-weight-bold text-dark mb-0"><i class="fa fa-university mr-2 text-danger"></i>CRA Source Deductions Remittance Register</h5>
            <a href="payroll_master_ledger.php?from_date=<?= htmlspecialchars($from_date) ?>&to_date=<?= htmlspecialchars($to_date) ?>&view_scope=cleared" class="btn btn-sm btn-outline-secondary font-weight-bold"><i class="fa fa-arrow-left mr-1"></i> Return to Main Ledger</a>
        </div>

        <?php if($msg === 'success'): ?>
            <div class="alert alert-success font-weight-bold shadow-sm mb-3"><i class="fa fa-check-circle mr-2"></i>CRA Government Payment Entry Logged Successfully! Dynamic Balances Adjusted.</div>
        <?php endif; ?>

        <?php if ($no_source_deductions): ?>
            <div class="alert alert-warning shadow-sm mb-3" id="cra-empty-finalize">
                <strong>No source deductions yet — finalize shifts in Staff Audit first.</strong>
                Imported ledger rows are for the pay matrix only. CRA remittance stays $0.00 until Staff Audit / Finalize runs the tax engine.
                <?php if ($from_date !== $hint_from || $to_date !== $hint_to): ?>
                    After finalize, try the full hours window
                    <a class="font-weight-bold" href="payroll_cra_payments.php?from_date=<?= $hint_from ?>&to_date=<?= $hint_to ?>"><?= $hint_from ?> to <?= $hint_to ?></a>.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="control-panel-card shadow-sm">
            <form method="GET" action="" class="row align-items-end mb-0">
                <div class="col-md-4">
                    <label class="font-weight-bold small text-muted text-uppercase mb-1">Select Payroll Start Range</label>
                    <input type="date" name="from_date" class="form-control form-control-sm font-weight-bold text-dark" value="<?= htmlspecialchars($from_date) ?>">
                </div>
                <div class="col-md-4">
                    <label class="font-weight-bold small text-muted text-uppercase mb-1">Select Payroll End Range</label>
                    <input type="date" name="to_date" class="form-control form-control-sm font-weight-bold text-dark" value="<?= htmlspecialchars($to_date) ?>">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-sm btn-primary btn-block font-weight-bold" style="height:31px;">
                        <i class="fa fa-sync mr-1"></i> Bring Payroll Tax Balances
                    </button>
                </div>
            </form>
        </div>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="summary-card border-left border-dark d-flex flex-column justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase font-weight-bold d-block mb-1">Period Tax Liabilities Generated</span>
                        <h3 class="font-weight-bold text-dark mb-0">$<?= number_format($total_cra_owed, 2) ?></h3>
                        <small class="text-secondary"><?= $no_source_deductions ? 'No source deductions yet — finalize shifts in Staff Audit first.' : 'Sum of Employee Deductions + Company Matching' ?></small>
                    </div>
                    <div class="mt-3 pt-2 border-top">
                        <a href="payroll_master_ledger.php?from_date=<?= htmlspecialchars($from_date) ?>&to_date=<?= htmlspecialchars($to_date) ?>&view_scope=cleared" class="font-weight-bold text-primary" style="font-size: 11px;">
                            <i class="fa fa-search mr-1"></i> Review Payroll Source Records &rarr;
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="summary-card border-left border-success">
                    <span class="text-muted small text-uppercase font-weight-bold d-block mb-1">Remittances Paid to CRA For This Window</span>
                    <h3 class="font-weight-bold text-success mb-0">$<?= number_format($total_cra_paid, 2) ?></h3>
                    <small class="text-secondary">Lump-sum bank clearances recorded</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="summary-card border-left <?= $current_balance_owed > 0 ? 'border-danger' : 'border-info' ?>">
                    <span class="text-muted small text-uppercase font-weight-bold d-block mb-1">Outstanding Period CRA Balance</span>
                    <h3 class="font-weight-bold <?= $current_balance_owed > 0 ? 'text-danger' : 'text-info' ?> mb-0">$<?= number_format(max(0, $current_balance_owed), 2) ?></h3>
                    <small class="text-secondary"><?= $no_source_deductions ? 'Waiting on Staff Audit / Finalize' : ($current_balance_owed > 0 ? 'Awaiting banking submission' : 'All accounts fully synchronized') ?></small>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="form-card">
                    <h6 class="font-weight-bold text-dark border-bottom pb-2 mb-3"><i class="fa fa-plus-circle mr-2 text-primary"></i>Record Tax Remittance</h6>
                    <form method="POST" action="" onsubmit="return confirm('Confirm you want to log this bulk remittance to the CRA?');">
                        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>">
                        <input type="hidden" name="filter_from_date" value="<?= htmlspecialchars($from_date) ?>">
                        <input type="hidden" name="filter_to_date" value="<?= htmlspecialchars($to_date) ?>">

                        <div class="form-group">
                            <label class="font-weight-bold small text-secondary mb-1">Date Paid (Bank Settlement Date)</label>
                            <input type="date" name="date_paid" class="form-control form-control-sm font-weight-bold" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="font-weight-bold small text-secondary mb-1">Amount Sent to CRA ($)</label>
                            <input type="number" step="0.01" name="amount_paid" class="form-control form-control-sm font-weight-bold text-danger" value="<?= round(max(0, $current_balance_owed), 2) ?>" min="0.01" required <?= $no_source_deductions ? 'disabled' : '' ?>>
                        </div>

                        <div class="form-group">
                            <label class="font-weight-bold small text-secondary mb-1">Payment Channel Route</label>
                            <select name="payment_method" class="form-control form-control-sm font-weight-bold" <?= $no_source_deductions ? 'disabled' : '' ?>>
                                <option value="Online Banking">Corporate Online Banking</option>
                                <option value="Bank Cheque">Corporate Cheque Mailed</option>
                                <option value="In-Person Terminal">In-Person Teller Branch Desk</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="font-weight-bold small text-secondary mb-1">Bank Confirmation / Trace Reference #</label>
                            <input type="text" name="bank_ref_no" class="form-control form-control-sm font-weight-bold text-muted" placeholder="e.g. FT26-XXXXX" <?= $no_source_deductions ? 'disabled' : '' ?>>
                        </div>

                        <div class="form-group">
                            <label class="font-weight-bold small text-secondary mb-1">Internal Memo / Notes</label>
                            <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="e.g. May Source Deductions Remittance Batch" <?= $no_source_deductions ? 'disabled' : '' ?>></textarea>
                        </div>

                        <button type="submit" name="log_cra_payment" class="btn btn-sm btn-danger btn-block font-weight-bold shadow-sm py-2" <?= $no_source_deductions ? 'disabled' : '' ?>><i class="fa fa-check-circle mr-1"></i> Submit Government Settlement</button>
                    </form>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card shadow-sm border-0 rounded-lg overflow-hidden">
                    <div class="card-header bg-dark text-white font-weight-bold text-uppercase py-2">Historical Remittance Clearance Logs (Filtered)</div>
                    <table class="table table-cra mb-0 text-center">
                        <thead>
                            <tr>
                                <th>Date Remitted</th>
                                <th>Method Used</th>
                                <th>Confirmation / Ref #</th>
                                <th>Memo Summary Context</th>
                                <th class="text-right">Total Amount Cleared</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($history)): ?>
                                <tr><td colspan="5" class="py-4 text-muted font-weight-normal">No corporate CRA remittance payments logged within this timeframe.</td></tr>
                            <?php else: ?>
                                <?php foreach ($history as $hRow): ?>
                                    <tr class="text-dark">
                                        <td><span class="badge badge-light border p-2 text-dark" style="font-size:10px;"><i class="fa fa-calendar mr-1 text-muted"></i><?= date('Y-m-d', strtotime($hRow['date_paid'])) ?></span></td>
                                        <td><?= htmlspecialchars($hRow['payment_method']) ?></td>
                                        <td class="text-muted"><?= !empty($hRow['bank_ref_no']) ? htmlspecialchars($hRow['bank_ref_no']) : 'N/A' ?></td>
                                        <td class="text-left small font-weight-normal text-secondary"><?= htmlspecialchars($hRow['notes'] ?? '') ?></td>
                                        <td class="text-right text-success" style="font-size:12px;">$<?= number_format($hRow['amount_paid'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
