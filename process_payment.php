<?php
/**
 * Path: /process_payment.php
 * STATUS: 100% GOLDEN RULE SYSTEM INTEGRATION
 * Features: Editable balances, payment type dispatcher, multi-select printing matrix.
 */
ini_set('display_errors', 0);
require_once 'db.php';
require_once 'engine/menu_generator.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$f_start = $_GET['start_date'] ?? die("Error: Missing Pay Period Start Date.");
$f_end   = $_GET['end_date'] ?? die("Error: Missing Pay Period End Date.");

// 1. FETCH COMBINED DATA (Finalized regular shifts + Overtime/Remainder Balances)
$sql = "SELECT u.id as user_id, u.Name as staff_name, u.hourly_rate, u.tel_mobile, u.email,
               IFNULL(SUM(pf.reg_hours), 0) as total_reg_hours,
               IFNULL(SUM(pf.travel_cost), 0) as total_travel,
               IFNULL(SUM(pb.balance_hours), 0) as total_bal_hours
        FROM users u
        LEFT JOIN payroll_finalized pf ON u.id = pf.user_id AND pf.work_date BETWEEN ? AND ?
        LEFT JOIN payroll_balance pb ON u.id = pb.user_id AND pb.split_date BETWEEN ? AND ?
        GROUP BY u.id
        HAVING total_reg_hours > 0 OR total_bal_hours > 0
        ORDER BY u.Name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$f_start, $f_end, $f_start, $f_end]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Processing Center | RenoCon</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #0f172a; color: #f8fafc; padding-top: 80px; font-family: 'Inter', sans-serif; font-size: 0.85rem; }
        .processing-container { background: #1e293b; border-radius: 12px; border: 1px solid #334155; padding: 25px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3); }
        .editable-input { background: #0f172a !important; color: #22c55e !important; border: 1px solid #475569 !important; font-weight: bold; text-align: right; border-radius: 6px; width: 110px; }
        .table-dark-custom { background: #0f172a; border-radius: 8px; overflow: hidden; }
        .table-dark-custom th { background: #334155; color: #cbd5e1; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        @media print {
            body { background: white; color: black; padding-top: 0; }
            .no-print { display: none !important; }
            .print-only { display: block !important; }
        }
    </style>
</head>
<body>
    <div class="no-print"><?= generateMenu($pdo) ?></div>
    
    <div class="container-fluid px-5 mb-5 no-print">
        <div class="processing-container">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="font-weight-bold mb-0 text-white"><i class="fa fa-receipt text-warning mr-2"></i> Dispatch Pay Period Ledger</h3>
                    <span class="text-muted font-weight-bold">Active Cycle Block: <?= date('M d, Y', strtotime($f_start)) ?> to <?= date('M d, Y', strtotime($f_end)) ?></span>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" onclick="triggerPrint('paystub')" class="btn btn-info font-weight-bold mr-2"><i class="fa fa-file-invoice-dollar mr-1"></i> Print Paystubs</button>
                    <button type="button" onclick="triggerPrint('cheque')" class="btn btn-light font-weight-bold text-dark"><i class="fa fa-money-check mr-1"></i> Print Corporate Cheques</button>
                </div>
            </div>

            <form id="paymentForm" method="POST" action="execute_bulk_payout.php">
                <input type="hidden" name="start_date" value="<?= $f_start ?>">
                <input type="hidden" name="end_date" value="<?= $f_end ?>">

                <table class="table table-dark table-striped table-hover table-dark-custom alignment-middle mb-4">
                    <thead>
                        <tr>
                            <th width="40" class="text-center"><input type="checkbox" id="checkAllEmployees"></th>
                            <th>Staff Member Name</th>
                            <th class="text-right">Finalized (Regular)</th>
                            <th class="text-right">Balance (Overtime)</th>
                            <th class="text-right">Travel Reimbursement</th>
                            <th class="text-right">Calculated Net Total</th>
                            <th class="text-right" style="color:#f59e0b;">Adjust Payment Amount</th>
                            <th>Method Selection</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $index => $r): 
                            $hourly_rate = (float)$r['hourly_rate'];
                            $finalized_wages = (float)$r['total_reg_hours'] * $hourly_rate;
                            $balance_wages = (float)$r['total_bal_hours'] * $hourly_rate;
                            $travel_allowance = (float)$r['total_travel'];
                            $calculated_payout = $finalized_wages + $balance_wages + $travel_allowance;
                        ?>
                        <tr class="employee-pay-row" data-uid="<?= $r['user_id'] ?>">
                            <td class="text-center align-middle">
                                <input type="checkbox" name="selected_users[]" value="<?= $r['user_id'] ?>" class="employee-selector-check">
                            </td>
                            <td class="align-middle">
                                <span class="h6 font-weight-bold text-white mb-0"><?= htmlspecialchars($r['staff_name']) ?></span>
                                <div class="small text-muted"><?= $r['tel_mobile'] ?> | <?= $r['email'] ?></div>
                                <input type="hidden" class="js-name" value="<?= htmlspecialchars($r['staff_name']) ?>">
                                <input type="hidden" class="js-reg-hrs" value="<?= $r['total_reg_hours'] ?>">
                                <input type="hidden" class="js-bal-hrs" value="<?= $r['total_bal_hours'] ?>">
                                <input type="hidden" class="js-travel" value="<?= $travel_allowance ?>">
                            </td>
                            <td class="text-right align-middle text-info font-weight-bold">$<?= number_format($finalized_wages, 2) ?><br><small class="text-muted"><?= $r['total_reg_hours'] ?> hrs</small></td>
                            <td class="text-right align-middle text-purple font-weight-bold text-white">$<?= number_format($balance_wages, 2) ?><br><small class="text-muted"><?= $r['total_bal_hours'] ?> hrs</small></td>
                            <td class="text-right align-middle text-warning font-weight-bold">$<?= number_format($travel_allowance, 2) ?></td>
                            <td class="text-right align-middle font-weight-bold text-muted">$<?= number_format($calculated_payout, 2) ?></td>
                            
                            <td class="text-right align-middle">
                                <div class="input-group input-group-sm justify-content-end">
                                    <div class="input-group-prepend"><span class="input-group-text bg-secondary border-secondary text-white">$</span></div>
                                    <input type="number" step="0.01" name="adjusted_amount[<?= $r['user_id'] ?>]" 
                                           class="form-control editable-input js-final-amount" 
                                           value="<?= round($calculated_payout, 2) ?>">
                                </div>
                            </td>
                            
                            <td class="align-middle">
                                <select name="payment_method[<?= $r['user_id'] ?>]" class="form-control form-control-sm bg-dark text-white border-secondary js-pay-method">
                                    <option value="E-transfer">E-Transfer (Direct)</option>
                                    <option value="Cheque">Corporate Cheque</option>
                                    <option value="Cash">Cash Currency</option>
                                    <option value="Direct Deposit">Direct Bank Deposit</option>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted font-italic"><i class="fa fa-info-circle mr-1"></i> Modifying any values in the green column will store the customized override amount directly onto the user payout records.</span>
                    <button type="submit" class="btn btn-success font-weight-bold px-5 py-2 shadow" onclick="return confirm('Execute final settlement and seal selected records?')">
                        <i class="fa fa-wallet mr-2"></i> Seal & Disburse Selected Payouts
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="printOutputBlock" class="d-none print-only container bg-white text-dark p-4"></div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script>
        // Check/Uncheck All Functionality
        $('#checkAllEmployees').click(function() {
            $('.employee-selector-check').prop('checked', this.checked);
        });

        // Dynamic Paystub and Cheque Layout Printer
        function triggerPrint(mode) {
            let checkedItems = $('.employee-selector-check:checked');
            if (checkedItems.length === 0) return alert("Please select at least one staff member checkbox to print outputs.");
            
            let printArea = $('#printOutputBlock');
            printArea.empty(); // Reset container data

            checkedItems.each(function() {
                let row = $(this).closest('.employee-pay-row');
                let name = row.find('.js-name').val();
                let regHrs = parseFloat(row.find('.js-reg-hrs').val()).toFixed(2);
                let balHrs = parseFloat(row.find('.js-bal-hrs').val()).toFixed(2);
                let travel = parseFloat(row.find('.js-travel').val()).toFixed(2);
                let finalAmt = parseFloat(row.find('.js-final-amount').val()).toFixed(2);
                let method = row.find('.js-pay-method').val();
                
                let currentDate = '<?= date("Y-m-d") ?>';

                if (mode === 'paystub') {
                    // Paystub Remittance Format (Statement of Earnings)
                    printArea.append(`
                        <div class="paystub-output-sheet" style="page-break-after: always; font-family: monospace; padding: 40px; border: 2px dashed #000; margin-bottom: 50px; color: #000;">
                            <h2 style="text-align: center; margin-bottom: 5px; font-weight: bold;">RENOCON CONSTRUCTION</h2>
                            <p style="text-align: center; margin-top:0; font-size:12px;">Remittance Statement of Earnings & Expenses</p>
                            <hr style="border-top: 1px solid #000; margin: 20px 0;">
                            <table style="width: 100%; font-size: 14px; margin-bottom: 20px;">
                                <tr><td><strong>EMPLOYEE:</strong> ${name}</td><td style="text-align: right;"><strong>DATE ISSUED:</strong> ${currentDate}</td></tr>
                                <tr><td><strong>CYCLE BLOCK:</strong> <?= $f_start ?> to <?= $f_end ?></td><td style="text-align: right;"><strong>DISBURSEMENT VIA:</strong> ${method}</td></tr>
                            </table>
                            <table style="width: 100%; border-collapse: collapse; margin-top: 30px; font-size: 14px;">
                                <tr style="border-bottom: 2px solid #000; text-align: left;"><th>Description Matrix</th><th style="text-align: right;">Units/Value</th></tr>
                                <tr><td style="padding: 8px 0;">Finalized Hours Shift Track</td><td style="text-align: right;">${regHrs} hrs</td></tr>
                                <tr><td style="padding: 8px 0;">Accumulated Remainder Balance (Overtime Shift Split)</td><td style="text-align: right;">${balHrs} hrs</td></tr>
                                <tr><td style="padding: 8px 0;">Transit & Travel Radius Reimbursements</td><td style="text-align: right;">$${travel} CAD</td></tr>
                                <tr style="border-top: 2px solid #000; font-weight: bold; font-size: 16px;">
                                    <td style="padding: 15px 0;">TOTAL NET PAID DISBURSEMENT</td>
                                    <td style="text-align: right; padding: 15px 0;">$${finalAmt} CAD</td>
                                </tr>
                            </table>
                            <div style="margin-top: 60px; text-align: center; font-size: 11px; font-style: italic;">Thank you for your valuable service to RenoCon Group operations. Please retain this copy for your tax profiles.</div>
                        </div>
                    `);
                } else if (mode === 'cheque') {
                    // Standard Corporate Bank Cheque Printing Layout Context 
                    printArea.append(`
                        <div class="cheque-output-sheet" style="page-break-after: always; height: 3.5in; width: 8.5in; border: 1px solid #999; padding: 30px; position: relative; font-family: 'Courier New', monospace; color: #000; margin-bottom: 40px; background: #fdfdfd;">
                            <div style="font-size: 16px; font-weight: bold;">RENOCON GROUP SERVICES INC.</div>
                            <div style="position: absolute; top: 30px; right: 40px; font-size: 14px;">DATE: <span style="border-bottom: 1px solid #000; padding-bottom: 2px;">${currentDate}</span></div>
                            
                            <div style="margin-top: 40px; font-size: 14px;">
                                PAY TO THE ORDER OF: <span style="font-weight: bold; font-size: 16px; text-decoration: underline;"> ${name} </span>
                            </div>
                            
                            <div style="position: absolute; top: 100px; right: 40px; font-size: 16px; font-weight: bold; border: 2px solid #000; padding: 5px 15px; background: #fff;">
                                $**${finalAmt}**
                            </div>
                            
                            <div style="margin-top: 30px; font-size: 13px; text-transform: uppercase; font-weight: bold;">
                                AMOUNT SUMMARY: <span style="font-style: italic; border-bottom: 1px solid #000; width: 80%; display: inline-block;">*** Dollars and Cents Equivalent Only ***</span>
                            </div>
                            
                            <div style="margin-top: 50px; font-size: 12px; width: 50%; float: left;">RE: Pay Period Payroll Settlement</div>
                            <div style="margin-top: 35px; font-size: 12px; width: 40%; float: right; text-align: center; border-top: 1px solid #000; padding-top: 5px;">AUTHORIZED SIGNATURE MANAGEMENT</div>
                        </div>
                    `);
                }
            });

            // Fire Browser Native Print Module
            window.print();
        }
    </script>
</body>
</html>