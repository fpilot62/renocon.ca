<?php
/**
 * Path: /payroll_print_center.php
 * PRODUCTION ENGINE - CENTRAL PRINT STATION FOR PAYSTUBS & BANK CHEQUES
 * Features: Itemized earnings summaries, numeric-to-words conversion engine, and custom printable CSS layers.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';
require_once 'engine/menu_generator.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$payment_id = isset($_GET['payment_id']) ? (int)$_GET['payment_id'] : 0;

if ($payment_id <= 0) {
    die("<div class='container mt-5 alert alert-danger'><strong>Registry Exception:</strong> Missing valid target transaction payment identifier hook context.</div>");
}

// 1. FETCH LEDGER TRANSACTION RECORD WITH PROFILE DATA JOIN HOOKS
$sql = "SELECT p.*, u.Name as emp_name, u.hourly_rate as base_rate, s.nick_name as project_site 
        FROM Gem_payroll_payments p
        JOIN users u ON p.employee_id = u.id
        LEFT JOIN sites s ON p.site_id = s.SId
        WHERE p.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$payment_id]);
$pay = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pay) {
    die("<div class='container mt-5 alert alert-danger'>Target transaction records not detected within ledger database maps.</div>");
}

// 2. CANADIAN ALPHANUMERIC DOLLAR STRING CONVERSION ENGINE
function convertAmountToWords($number) {
    $hyphen      = '-'; $conjunction = ' and '; $separator   = ', '; $negative    = 'negative ';
    $dictionary  = [
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen',
        20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety',
        100 => 'Hundred', 1000 => 'Thousand'
    ];
    
    if (!is_numeric($number)) return '';
    $float_parts = explode('.', number_format($number, 2, '.', ''));
    $main_num = (int)$float_parts[0];
    $cents = $float_parts[1];
    
    if ($main_num == 0) { $string = $dictionary[0]; }
    else {
        $string = '';
        if ($main_num >= 1000) {
            $thousands = floor($main_num / 1000);
            $string .= convertAmountToWords($thousands) . ' ' . $dictionary[1000];
            $main_num %= 1000;
            if ($main_num > 0) $string .= $separator;
        }
        if ($main_num >= 100) {
            $hundreds = floor($main_num / 100);
            $string .= $dictionary[$hundreds] . ' ' . $dictionary[100];
            $main_num %= 100;
            if ($main_num > 0) $string .= $conjunction;
        }
        if ($main_num > 0) {
            if ($main_num < 20) { $string .= $dictionary[$main_num]; }
            else {
                $tens = floor($main_num / 10) * 10;
                $units = $main_num % 10;
                $string .= $dictionary[$tens];
                if ($units > 0) $string .= $hyphen . $dictionary[$units];
            }
        }
    }
    return $string . ' Dollars and ' . $cents . '/100';
}

$amount_in_words = convertAmountToWords((float)$pay['net_paid_to_employee']);

// 3. INFER SUB-UNIT COMPILER HOUR METRICS BACKWARDS FROM TOTAL PAYMENTS LEDGERS safely
$total_deductions = $pay['gov_tax_withheld'] + $pay['gov_cpp_withheld'] + $pay['gov_ei_withheld'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll Document Station | SIP</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background:#f1f5f9; font-family: 'Inter', sans-serif; padding-top: 130px; }
        .control-panel-card { background: white; border-radius: 12px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: none; margin-bottom: 30px; }
        
        /* 📄 SYSTEM OUTPUT PRINT CONTAINER STYLING RULES (STUBS) */
        .printable-stub-container { display: none; background: #fff; padding: 40px; border: 1px solid #cbd5e1; border-radius: 8px; max-width: 850px; margin: 0 auto; color: #000; }
        .stub-header-title { font-size: 24px; font-weight: 900; color: #1e3a8a; border-bottom: 3px solid #1e3a8a; padding-bottom: 10px; text-transform: uppercase; }
        
        /* ✍️ PRECISE LAYOUT OVERLAY CHANNELS (CHEQUES) */
        .printable-cheque-container { display: none; width: 8in; height: 3.5in; background: #fff; border: 1px dashed #94a3b8; position: relative; margin: 0 auto; overflow: hidden; font-family: Courier, monospace; font-size: 13px; color: #000; font-weight: bold; }
        
        /* Precise Coordinates mapping vectors onto preprinted standard paper sizes */
        .ch-date { position: absolute; top: 0.45in; left: 6.2in; letter-spacing: 2px; }
        .ch-payee { position: absolute; top: 1.05in; left: 1.2in; text-transform: uppercase; }
        .ch-amount-num { position: absolute; top: 1.05in; left: 6.6in; font-size: 15px; }
        .ch-amount-words { position: absolute; top: 1.45in; left: 0.7in; text-transform: uppercase; max-width: 5.5in; line-height: 1.2; }
        .ch-memo { position: absolute; top: 2.85in; left: 1.0in; font-size: 11px; font-family: sans-serif; text-transform: uppercase; }

        /* 🖨️ MEDIA CSS INJECTION BLOCK DEEP OVERRIDES FOR HARDWARE OUTPUT */
        @media print {
            body { padding-top: 0 !important; background: #fff !important; }
            .navbar, .control-panel-card, .footer { display: none !important; }
            
            /* Toggled View Vector Routes triggered dynamically via javascript flags */
            body[data-print-mode="stub"] .printable-stub-container { display: block !important; border: none !important; padding: 0 !important; }
            body[data-print-mode="cheque"] .printable-cheque-container { display: block !important; border: none !important; margin: 0 !important; padding: 0 !important; background: transparent !important; }
            @page { margin: 0.25in; }
        }
    </style>
</head>
<body data-print-mode="stub">
    <?= generateMenu($pdo); ?>

    <div class="container">
        
        <div class="control-panel-card text-center d-print-none">
            <h4 class="font-weight-bold text-dark mb-2"><i class="fas fa-print text-primary mr-1"></i> Document Output Routing Hub</h4>
            <p class="text-muted small">Financial transaction logged successfully. Choose a printing template below to push directly to your paper office tray hardware loops.</p>
            <div class="d-flex justify-content-center gap-3 mt-4">
                <button type="button" class="btn btn-primary font-weight-bold mx-2 px-4 py-3" onclick="triggerHardwarePrint('stub')" style="border-radius:6px; background:#2563eb; border:none;">
                    <i class="fas fa-file-invoice-dollar mr-2"></i> GENERATE OFFICIAL PAYSTUB
                </button>
                <button type="button" class="btn btn-success font-weight-bold mx-2 px-4 py-3" onclick="triggerHardwarePrint('cheque')" style="border-radius:6px; background:#16a34a; border:none;">
                    <i class="fas fa-money-check mr-2"></i> PRINT PRE-ALIGNED BANK CHEQUE
                </button>
                <a href="payroll_master_ledger.php" class="btn btn-outline-secondary font-weight-bold mx-2 px-4 d-flex align-items-center" style="border-radius:6px;">
                    <i class="fas fa-undo mr-1"></i> Close Console
                </a>
            </div>
        </div>

        <div class="printable-stub-container shadow-sm">
            <div class="d-flex justify-content-between align-items-end mb-4 border-bottom pb-3">
                <div>
                    <h2 class="font-weight-black m-0" style="font-weight:900; color:#1e3a8a;">PAY STATEMENT</h2>
                    <div class="text-muted small font-weight-bold mt-1">Statement Date: <?= date('M d, Y', strtotime($pay['payment_date'])) ?></div>
                </div>
                <div class="text-right" style="line-height:1.4;">
                    <strong style="font-size:16px; color:#0f172a;">RENOCON MANAGEMENT INC.</strong><br>
                    <small class="text-muted font-weight-bold">Site Project: <?= htmlspecialchars($pay['project_site'] ?: 'Corporate Operations') ?></small>
                </div>
            </div>

            <div class="row my-4 bg-light p-3 rounded mx-0 border" style="font-size:13px;">
                <div class="col-md-6">
                    <span class="text-muted d-block uppercase font-weight-bold small" style="font-size:10px;">Employee Profile Recipient:</span>
                    <strong><?= htmlspecialchars(strtoupper($pay['emp_name'])) ?></strong>
                </div>
                <div class="col-md-6 text-right">
                    <span class="text-muted d-block uppercase font-weight-bold small" style="font-size:10px;">Pay Period Window Limits:</span>
                    <strong><?= date('M d, Y', strtotime($pay['pay_period_start'])) ?> ── TO ── <?= date('M d, Y', strtotime($pay['pay_period_end'])) ?></strong>
                </div>
            </div>

            <div class="row mt-5">
                <div class="col-6 pr-4 border-right">
                    <h6 class="font-weight-bold text-primary border-bottom pb-2 text-uppercase" style="letter-spacing:0.5px;">1. Earnings Calculations</h6>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span>Total Cumulative Period Gross Pay</span>
                        <strong class="text-dark">$<?= number_format($pay['gross_earned'], 2) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between py-2 border-bottom text-muted small">
                        <span>Vacation Pay Allocation (4% Accrued)</span>
                        <span>$<?= number_format($pay['vacation_pay_paid'], 2) ?></span>
                    </div>
                </div>

                <div class="col-6 pl-4">
                    <h6 class="font-weight-bold text-danger border-bottom pb-2 text-uppercase" style="letter-spacing:0.5px;">2. Statutory Withholdings</h6>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span>Federal Income Tax (12.00%)</span>
                        <strong class="text-danger">-$<?= number_format($pay['gov_tax_withheld'], 2) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span>Canada Pension Plan (CPP 5.95%)</span>
                        <strong class="text-danger">-$<?= number_format($pay['gov_cpp_withheld'], 2) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span>Employment Insurance (EI 1.66%)</span>
                        <strong class="text-danger">-$<?= number_format($pay['gov_ei_withheld'], 2) ?></strong>
                    </div>
                </div>
            </div>

            <div class="mt-5 p-4 rounded d-flex justify-content-between align-items-center" style="background: #f0fdf4; border: 2px solid #bbf7d0;">
                <div>
                    <h5 class="m-0 font-weight-bold text-success" style="text-transform:uppercase; font-size:12px; letter-spacing:0.5px;">Net Transferred Employee Disbursement</h5>
                    <small class="text-muted font-weight-bold mt-1 d-block"><i class="fas fa-exchange-alt"></i> Settlement Channel: <?= htmlspecialchars($pay['payment_method']) ?> <?= $pay['cheque_number'] ? '(#'.$pay['cheque_number'].')' : '' ?></small>
                </div>
                <div class="text-right">
                    <span class="text-muted d-block small font-weight-bold uppercase" style="font-size:9px; margin-bottom:-2px;">Amount Settled</span>
                    <h2 class="m-0 font-weight-black text-success" style="font-weight:900; font-size:2.2rem;">$<?= number_format($pay['net_paid_to_employee'], 2) ?></h2>
                </div>
            </div>

            <?php if ($pay['balance_owing'] > 0): ?>
                <div class="mt-3 p-3 rounded d-flex justify-content-between align-items-center bg-light border border-warning">
                    <span class="font-weight-bold text-warning small text-uppercase"><i class="fas fa-exclamation-triangle"></i> Notice: Active Partial Pay Balance Carried Over</span>
                    <strong class="text-danger" style="font-size:14px;">$<?= number_format($pay['balance_owing'], 2) ?> Owed</strong>
                </div>
            <?php endif; ?>

            <div class="mt-5 pt-5 text-center text-muted small border-top" style="font-size:10px; font-weight:600; letter-spacing:0.5px; opacity:0.6;">
                ELECTRONIC PAY STATEMENT SUMMARY DOCUMENT — GENERATED BY RENOCON MASTER SYSTEMS RECORD ARCHIVES.
            </div>
        </div>

        <div class="printable-cheque-container shadow-sm my-5">
            <div class="ch-date"><?= date('m d Y', strtotime($pay['payment_date'])) ?></div>
            <div class="ch-payee"><?= htmlspecialchars($pay['emp_name']) ?></div>
            <div class="ch-amount-num">**<?= number_format($pay['net_paid_to_employee'], 2) ?>**</div>
            <div class="ch-amount-words">***<?= htmlspecialchars($amount_in_words) ?>***</div>
            <div class="ch-memo">Period: <?= date('M d', strtotime($pay['pay_period_start'])) ?> - <?= date('M d', strtotime($pay['pay_period_end'])) ?></div>
        </div>

    </div>

    <script>
        function triggerHardwarePrint(modeToken) {
            // Apply the chosen display type flag context straight to the global document body channel wrapper 
            document.body.setAttribute('data-print-mode', modeToken);
            // Execute standard browser system hardware sequence loops instantly
            window.print();
        }
    </script>
</body>
</html>