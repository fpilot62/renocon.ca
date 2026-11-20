<?php
/**
 * Path: /payroll_compliance_desk.php
 * PRODUCTION PIPELINE - TOTAL MULTI-ORG WORKFORCE COMPLIANCE CORE BUILD
 */

require_once 'db.php'; 
require_once 'engine/menu_generator.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) { header("Location: login_kiosk.php"); exit; }
$uid = (int)$_SESSION['user_id'];

date_default_timezone_set('America/Vancouver');

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 1);



// 1. Establish operational payroll-wise date filters
$selected_start = $_GET['p_start'] ?? '2026-04-16';
$selected_end   = $_GET['p_end'] ?? '2026-04-30';
$report_type    = $_GET['type'] ?? 'dashboard';

// 2. Fetch primary data array tracking ALL active shift workers in the timeline pool
$stmt = $pdo->prepare("
    SELECT pf.user_id,
           COALESCE(u.Name, CONCAT('User #', pf.user_id)) as staff_name,
           u.Email as user_email,
           COALESCE(org.id, 1) as org_id,
           COALESCE(org.org_name, 'Renocon Systems Corporation') as company_name,
           COALESCE(NULLIF(u.bank_transit, ''), '00000') as transit,
           COALESCE(NULLIF(u.bank_institution, ''), '000') as institution,
           COALESCE(NULLIF(u.bank_account, ''), '000000000000') as account,
           SUM(pf.reg_hours) as total_hours,
           SUM(pf.total_gross) as total_gross,
           SUM(COALESCE(pf.paid_amount, 0.00)) as actual_paid
    FROM payroll_finalized pf
    LEFT JOIN users u ON pf.user_id = u.id
    LEFT JOIN organizations org ON u.org_id = org.id
    WHERE pf.work_date BETWEEN ? AND ?
    GROUP BY org.id, pf.user_id
    ORDER BY org.id ASC, u.Name ASC
");
$stmt->execute([$selected_start, $selected_end]);
$raw_payroll_dataset = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Re-structure data completely into a Company-Wise multi-tenant matrix array
$company_buckets = [];
foreach ($raw_payroll_dataset as $row) {
    $c_name = $row['company_name'];
    if (!isset($company_buckets[$c_name])) {
        $company_buckets[$c_name] = [
            'org_id' => $row['org_id'],
            'staff'  => []
        ];
    }
    $company_buckets[$c_name]['staff'][] = $row;
}

// Handle Bank Auto-Deposit File Generation
if ($report_type === 'download_eft') {
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="COMPANY_WISE_EFT_' . $selected_start . '_TO_' . $selected_end . '.txt"');
    
    foreach ($company_buckets as $c_title => $bucket) {
        echo "## START COMPANY LEDGER: " . strtoupper($c_title) . " ##\r\n";
        echo "A00000001" . str_pad(str_replace(' ', '', strtoupper($c_title)), 30, " ", STR_PAD_RIGHT) . "0000000000" . date('Ymd') . "CAD\r\n";
        
        foreach ($bucket['staff'] as $row) {
            if ($row['actual_paid'] <= 0.01) continue;
            $cents_amount = str_pad(round($row['actual_paid'] * 100), 10, "0", STR_PAD_LEFT);
            echo "C600" . 
                 str_pad(preg_replace('/[^0-9]/', '', $row['institution']), 3, "0", STR_PAD_LEFT) . 
                 str_pad(preg_replace('/[^0-9]/', '', $row['transit']), 5, "0", STR_PAD_LEFT) . 
                 str_pad(preg_replace('/[^0-9]/', '', $row['account']), 12, "0", STR_PAD_LEFT) . "0000" .
                 $cents_amount . "0000000000" .
                 str_pad(strtoupper($row['staff_name']), 30, " ", STR_PAD_RIGHT) . 
                 str_pad(str_replace(' ', '', strtoupper($c_title)), 30, " ", STR_PAD_RIGHT) . "CAD\r\n";
        }
        echo "Z9999999900000000000000000000000000000000000000000000000\r\n\r\n";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll Compliance Desk</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #eaedf2; color: #334155; margin: 0; padding-top: 115px; font-size: 11px; }
        .wrapper { max-width: 1400px; margin: 0 auto; padding: 0 20px; }
        .report-nav { background: #1e293b; padding: 15px; border-radius: 6px; margin-bottom: 25px; display: flex; gap: 15px; align-items: center; justify-content: space-between; }
        .report-nav select, .report-nav input { padding: 6px 10px; border-radius: 4px; border: 1px solid #475569; background: white; font-weight: bold; font-size: 12px; }
        .card { background: white; border-radius: 6px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); margin-bottom: 35px; border-top: 4px solid #475569; }
        .company-header-banner { background: #0f172a; color: #38bdf8; padding: 12px 20px; font-size: 14px; font-weight: bold; border-radius: 4px; margin-bottom: 15px; display: flex; align-items: center; justify-content: space-between; text-transform: uppercase; letter-spacing: 0.5px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th { background: #334155; color: #cbd5e1; padding: 10px; text-align: left; text-transform: uppercase; font-size: 11px; }
        td { padding: 10px; border-bottom: 1px solid #e2e8f0; font-size: 12px; color: #334155; }
        .btn-action { background: #2563eb; color: white; text-decoration: none; padding: 6px 12px; border-radius: 4px; font-weight: bold; font-size: 11px; display: inline-flex; align-items: center; gap: 4px; border: none; cursor: pointer; }
        .btn-action:hover { background: #1d4ed8; }
        .btn-green { background: #16a34a; }
        .btn-green:hover { background: #15803d; }
        
        /* MULTI-PAGE LAYOUT ENGINE */
        .print-package-group { margin-bottom: 45px; border: 1px solid #cbd5e1; padding: 15px; background: white; border-radius: 8px; }
        .toggle-badge-panel { background: #f1f5f9; padding: 8px 12px; border-radius: 4px; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; font-weight: bold; color: #1e293b; }
        
        .cheque-container-block { border: 1px solid #000000; background: #ffffff; padding: 25px; border-radius: 6px; color: #000000; font-family: 'Courier New', monospace; position: relative; height: 230px; margin-bottom: 30px; page-break-after: always; }
        .cheque-number { font-size: 15px; font-weight: bold; color: #000000; }
        .cheque-date-box { border: 1px solid #000000; padding: 4px 12px; background: white; font-weight: bold; font-size: 11px; display: inline-block; color: #000000; }
        .cheque-line-item { display: flex; align-items: flex-end; margin-top: 15px; font-size: 12px; color: #000000; }
        .cheque-line-item span { border-bottom: 1px dashed #000000; flex-grow: 1; padding-left: 10px; font-weight: bold; font-size: 13px; color: #000000; }
        .cheque-amount-box { border: 2px solid #000000; background: white; padding: 4px 10px; font-weight: bold; font-size: 14px; min-width: 100px; text-align: right; color: #000000; }
        .cheque-footer-micr { position: absolute; bottom: 15px; left: 8%; font-size: 15px; letter-spacing: 4px; word-spacing: 6px; font-weight: bold; color: #000000; }

        .paystub-container-block { border: 1px solid #cbd5e1; padding: 20px; background: #fafafa; border-radius: 4px; font-family: 'Segoe UI', sans-serif; page-break-after: always; }
        .stub-header { display: flex; justify-content: space-between; border-bottom: 2px solid #334155; padding-bottom: 6px; margin-bottom: 12px; }
        .stub-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 12px; }
        .stub-box { background: white; border: 1px solid #e2e8f0; padding: 10px; border-radius: 4px; }
        .stub-box h4 { margin: 0 0 8px 0; color: #475569; font-size: 10px; text-transform: uppercase; border-bottom: 1px solid #f1f5f9; padding-bottom: 4px; }

        @media print {
            body { padding-top: 0 !important; background: white !important; color: #000000 !important; }
            .top-navbar, .report-nav, .btn-action, .toggle-badge-panel, .hide-on-print, header, nav { display: none !important; visibility: hidden !important; }
            .wrapper { max-width: 100% !important; width: 100% !important; padding: 0 !important; margin: 0 !important; }
            .card { box-shadow: none !important; padding: 0 !important; margin: 0 !important; border: none !important; }
            .print-package-group { border: none !important; padding: 0 !important; margin: 0 !important; background: transparent !important; }
            .company-header-banner { background: #cbd5e1 !important; color: black !important; page-break-before: always !important; }
            .cheque-container-block { border: 1px solid #000000 !important; background: #ffffff !important; margin-bottom: 0 !important; page-break-after: always !important; display: block !important; }
            .paystub-container-block { border: 1px solid #000000 !important; background: #ffffff !important; margin-bottom: 0 !important; page-break-after: always !important; display: block !important; }
            .suppress-cheque { display: none !important; }
        }
    </style>
    <script>
        function toggleChequePrinting(userId) {
            var checkbox = document.getElementById('chk-' + userId);
            var chequeElement = document.getElementById('chq-frame-' + userId);
            var statusLabel = document.getElementById('lbl-' + userId);
            
            if (checkbox.checked) {
                chequeElement.classList.remove('suppress-cheque');
                chequeElement.style.display = 'block';
                statusLabel.innerHTML = "<i class='fas fa-money-check-alt' style='color:#16a34a;'></i> Paper Cheque Generation Active";
                statusLabel.style.color = "#16a34a";
            } else {
                chequeElement.classList.add('suppress-cheque');
                chequeElement.style.display = 'none';
                statusLabel.innerHTML = "<i class='fas fa-university' style='color:#2563eb;'></i> EFT Direct Deposit Active (Cheque Suppressed)";
                statusLabel.style.color = "#2563eb";
            }
        }
    </script>
</head>
<body>

<?php 
if (function_exists('generateMenu')) echo generateMenu($pdo); 
session_write_close();
?>

<div class="wrapper">

    <div class="report-nav">
        <form method="GET" action="" style="display: flex; gap: 12px; align-items: center; margin: 0;">
            <label style="color: #94a3b8; font-weight: bold;">Payroll Period Run:</label>
            <input type="date" name="p_start" value="<?= htmlspecialchars($selected_start) ?>">
            <input type="date" name="p_end" value="<?= htmlspecialchars($selected_end) ?>">
            
            <select name="type">
                <option value="dashboard" <?= $report_type === 'dashboard' ? 'selected' : '' ?>>📊 Company-Wise Audit Dashboards</option>
                <option value="stubs" <?= $report_type === 'stubs' ? 'selected' : '' ?>>📄 Print Stubs / Cheques Statements</option>
                <option value="remittance" <?= $report_type === 'remittance' ? 'selected' : '' ?>>🏛️ Government CRA Remittances</option>
            </select>
            <button type="submit" class="btn-action"><i class="fas fa-sync"></i> Compile Selection</button>
        </form>
        
        <div>
            <a href="?type=download_eft&p_start=<?= $selected_start ?>&p_end=<?= $selected_end ?>" class="btn-action btn-green"><i class="fas fa-file-download"></i> Download Unified Bank EFT File</a>
        </div>
    </div>

    <?php if ($report_type === 'dashboard'): ?>
        <?php foreach ($company_buckets as $company_name => $bucket): ?>
            <div class="card">
                <div class="company-header-banner">
                    <span><i class="fas fa-building"></i> Company Portfolio: <?= htmlspecialchars($company_name) ?></span>
                    <span style="font-size: 11px; background: rgba(255,255,255,0.15); padding: 2px 6px; border-radius: 4px;">Org ID: #<?= $bucket['org_id'] ?></span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Employee Staff Name</th>
                            <th>Hours Logged</th>
                            <th>Gross Calculated</th>
                            <th>Deductions Owed</th>
                            <th>Net Period Value</th>
                            <th>Total Paid Via FIFO</th>
                            <th>Outstanding Remaining Debt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $c_gross = 0; $c_paid = 0; $c_hours = 0;
                        foreach ($bucket['staff'] as $row): 
                            $gross = (float)$row['total_gross'];
                            $deductions = $gross * (0.12 + 0.0595 + 0.0166);
                            $net = $gross - $deductions;
                            $paid = (float)$row['actual_paid'];
                            $remaining = $net - $paid;
                            $c_gross += $gross; $c_paid += $paid; $c_hours += (float)$row['total_hours'];
                        ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['staff_name']) ?></strong></td>
                                <td><?= round($row['total_hours'], 2) ?> Hrs</td>
                                <td>$<?= number_format($gross, 2) ?></td>
                                <td>$<?= number_format($deductions, 2) ?></td>
                                <td>$<?= number_format($net, 2) ?></td>
                                <td style="color: #16a34a; font-weight: bold;">$<?= number_format($paid, 2) ?></td>
                                <td style="font-weight: bold; color: <?= $remaining > 0.01 ? '#b45309' : '#1e293b' ?>;">$<?= number_format(max(0, $remaining), 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="background: #f8fafc; font-weight: bold; border-top: 2px solid #cbd5e1;">
                            <td>SUBTOTAL BALANCES:</td>
                            <td><?= round($c_hours, 2) ?> Hrs</td>
                            <td>$<?= number_format($c_gross, 2) ?></td>
                            <td>$<?= number_format($c_gross * (0.12 + 0.0595 + 0.0166), 2) ?></td>
                            <td>$<?= number_format($c_gross - ($c_gross * (0.12 + 0.0595 + 0.0166)), 2) ?></td>
                            <td style="color: #16a34a;">$<?= number_format($c_paid, 2) ?></td>
                            <td style="color: #b45309;">$<?= number_format(max(0, ($c_gross - ($c_gross * (0.12 + 0.0595 + 0.0166))) - $c_paid), 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

    <?php elseif ($report_type === 'remittance'): ?>
        <?php foreach ($company_buckets as $company_name => $bucket): 
            $total_gross_pool = 0;
            foreach ($bucket['staff'] as $row) { $total_gross_pool += (float)$row['total_gross']; }
            $ee_tax = $total_gross_pool * 0.12; $ee_cpp = $total_gross_pool * 0.0595; $ee_ei = $total_gross_pool * 0.0166;
            $er_cpp = $ee_cpp * 1.0; $er_ei = $ee_ei * 1.4; $total_cra_remittance = $ee_tax + $ee_cpp + $er_cpp + $ee_ei + $er_ei;
        ?>
            <div class="card">
                <div class="company-header-banner"><span><i class="fas fa-gavel"></i> CRA Source Remittance: <?= htmlspecialchars($company_name) ?></span></div>
                <table>
                    <thead>
                        <tr>
                            <th>Source Component Category</th>
                            <th>Employee Contribution (Withheld)</th>
                            <th>Employer Share (Company Matching)</th>
                            <th style="text-align: right;">Total Line Item Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td><strong>Provincial / Federal Income Tax</strong></td><td>$<?= number_format($ee_tax, 2) ?></td><td style="color:#64748b; font-style:italic;">No Corporate Matching</td><td style="text-align:right; font-weight:bold;">$<?= number_format($ee_tax, 2) ?></td></tr>
                        <tr><td><strong>Canada Pension Plan (CPP)</strong></td><td>$<?= number_format($ee_cpp, 2) ?></td><td>$<?= number_format($er_cpp, 2) ?> <span style="font-size:10px; color:#64748b;">(1.0× Match)</span></td><td style="text-align:right; font-weight:bold;">$<?= number_format($ee_cpp + $er_cpp, 2) ?></td></tr>
                        <tr><td><strong>Employment Insurance (EI)</strong></td><td>$<?= number_format($ee_ei, 2) ?></td><td>$<?= number_format($er_ei, 2) ?> <span style="font-size:10px; color:#64748b;">(1.4× Match)</span></td><td style="text-align:right; font-weight:bold;">$<?= number_format($ee_ei + $er_ei, 2) ?></td></tr>
                        <tr style="background:#f1f5f9; font-size:13px; font-weight:900; border-top:3px double #334155;"><td colspan="3">TOTAL REMITTANCE BALANCE DUE FOR <?= htmlspecialchars($company_name) ?>:</td><td style="text-align:right; color:#dc2626;">$<?= number_format($total_cra_remittance, 2) ?></td></tr>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
        <div style="text-align: center; margin-bottom: 30px;"><button class="btn-action btn-green" onclick="window.print();"><i class="fas fa-print"></i> Batch Print All CRA Slips</button></div>

    <?php elseif ($report_type === 'stubs'): ?>
        <div style="margin-bottom: 25px; text-align: right;">
            <button class="btn-action btn-green" onclick="window.print();" style="font-size: 13px; padding: 10px 20px;"><i class="fas fa-print"></i> Execute Dynamic Batch Print System</button>
        </div>

        <?php 
        $chq_counter = 8000;
        foreach ($company_buckets as $company_name => $bucket): 
            foreach ($bucket['staff'] as $sKey => $emp): 
                $chq_counter++;
                $uniqueUserKey = $bucket['org_id'] . "_" . $sKey;

                $whole_dollars = floor($emp['actual_paid']); $cents = round(($emp['actual_paid'] - $whole_dollars) * 100);
                $legal_words_string = strtoupper(number_format($whole_dollars) . " DOLLARS AND " . $cents . "/100 CENTS");

                $gross = (float)$emp['total_gross']; $tax = $gross * 0.12; $cpp = $gross * 0.0595; $ei = $gross * 0.0166;
                $net = $gross - $tax - $cpp - $ei; $paid = (float)$emp['actual_paid']; $bal = $net - $paid;
        ?>
                <div class="print-package-group">
                    
                    <div class="toggle-badge-panel hide-on-print">
                        <input type="checkbox" id="chk-<?= $uniqueUserKey ?>" checked onclick="toggleChequePrinting('<?= $uniqueUserKey ?>')" style="width:16px; height:16px; cursor:pointer;">
                        <label for="chk-<?= $uniqueUserKey ?>" style="cursor:pointer; font-size:12px;">Include Paper Cheque Page for <strong><?= htmlspecialchars($emp['staff_name']) ?></strong> (<?= htmlspecialchars($company_name) ?>)</label>
                        <span id="lbl-<?= $uniqueUserKey ?>" style="margin-left:auto; font-size:11px; color:#16a34a;"><i class="fas fa-money-check-alt"></i> Paper Cheque Generation Active</span>
                    </div>

                    <div class="cheque-container-block" id="chq-frame-<?= $uniqueUserKey ?>">
                        <table style="width: 100%; border: none; margin: 0;">
                            <tr>
                                <td style="width: 65%; border: none; padding: 0; vertical-align: top;">
                                    <span style="font-size: 14px; font-weight: bold; text-transform: uppercase; display: block; color: #000000;"><?= htmlspecialchars($company_name) ?></span>
                                    <span style="font-size: 10px; color: #333333; display: block; margin-top: 2px;">Corporate Operating Payroll Account</span>
                                </td>
                                <td style="width: 35%; border: none; padding: 0; text-align: right; vertical-align: top;">
                                    <span style="font-size: 15px; font-weight: bold; color: #000000;">Cheque No: #<?= $chq_counter ?></span>
                                    <div style="margin-top: 10px; border: 1px solid #000000; padding: 4px 10px; display: inline-block; background: #ffffff; font-weight: bold;">Date: <?= date('Y-m-d') ?></div>
                                </td>
                            </tr>
                        </table>

                        <div style="margin-top: 30px; display: flex; align-items: flex-end;">
                            <span style="font-weight: bold; font-size: 11px; width: 130px; color: #000000;">PAY TO THE ORDER OF:</span>
                            <div style="border-bottom: 1px dashed #000000; flex-grow: 1; padding-bottom: 2px; font-weight: bold; font-size: 13px; color: #000000;"><?= htmlspecialchars($emp['staff_name']) ?></div>
                            <div style="border: 2px solid #000000; background: #ffffff; padding: 4px 10px; font-weight: bold; font-size: 13px; min-width: 110px; text-align: right; margin-left: 15px; color: #000000;">$**<?= number_format($emp['actual_paid'], 2) ?></div>
                        </div>

                        <div style="margin-top: 20px; display: flex; align-items: flex-end;">
                            <span style="font-weight: bold; font-size: 11px; width: 65px; color: #000000;">SUM OF:</span>
                            <div style="border-bottom: 1px dashed #000000; flex-grow: 1; padding-bottom: 2px; font-weight: bold; font-size: 11px; color: #000000; letter-spacing: 0.5px;"><?= $legal_words_string ?> ****************************************</div>
                        </div>

                        <div style="margin-top: 35px; display: flex; justify-content: space-between; align-items: flex-end;">
                            <div style="font-size: 10px; color: #000000;"><strong>MEMO:</strong> Statement Remittance Cycle Settlement</div>
                            <div style="border-top: 1px solid #000000; width: 240px; text-align: center; padding-top: 5px; font-weight: bold; font-size: 10px; color: #000000;">AUTHORIZED SIGNATURE CONTROL</div>
                        </div>

                        <div class="cheque-footer-micr">
                            ⑈<?= $chq_counter ?>⑈  ⑈<?= $emp['transit'] ?>⑈ - ⑈<?= $emp['institution'] ?>⑈  <?= $emp['account'] ?>⑈
                        </div>
                    </div>

                    <div class="paystub-container-block">
                        <div class="stub-header">
                            <div><h3 style="margin:0; color:#1e293b; font-size:14px; font-weight:bold; text-transform:uppercase;"><?= htmlspecialchars($company_name) ?> - EARNINGS STATEMENT</h3></div>
                            <div style="text-align:right; font-size:11px;">Period Range: <strong><?= $selected_start ?></strong> to <strong><?= $selected_end ?></strong></div>
                        </div>
                        
                        <div class="stub-grid">
                            <div class="stub-box">
                                <h4>Employee Profile</h4>
                                <strong>Recipient Name:</strong> <?= htmlspecialchars($emp['staff_name']) ?><br>
                                <strong>Email Address:</strong> <?= htmlspecialchars($emp['user_email'] ?? 'N/A') ?><br>
                                <strong>Jurisdiction:</strong> British Columbia (BC)
                            </div>
                            <div class="stub-box">
                                <h4>Banking Distribution Routing Data</h4>
                                <strong>Transit Code:</strong> *****-<?= substr($emp['transit'], -2) ?> | <strong>Bank ID:</strong> ***-<?= $emp['institution'] ?><br>
                                <strong>Account Track:</strong> ***********<?= substr($emp['account'], -3) ?>
                            </div>
                        </div>

                        <div class="stub-grid">
                            <div class="stub-box">
                                <h4>Gross Hours Earnings Ledger</h4>
                                <table style="margin:0; width:100%;">
                                    <tr><td>Regular Hours Vol:</td><td style="text-align:right;"><strong><?= round($emp['total_hours'], 2) ?> Hrs</strong></td></tr>
                                    <tr style="border-top:1px solid #e2e8f0;"><td style="padding-top:6px; font-weight:bold;">TOTAL PERIOD GROSS:</td><td style="text-align:right; padding-top:6px; font-weight:bold;">$<?= number_format($gross, 2) ?></td></tr>
                                </table>
                            </div>
                            <div class="stub-box">
                                <h4>Statutory Source Deductions (Withheld)</h4>
                                <table style="margin:0; width:100%; line-height:1.3;">
                                    <tr><td>Income Tax (12%):</td><td style="text-align:right; color:#dc2626;">-$<?= number_format($tax, 2) ?></td></tr>
                                    <tr><td>CPP (5.95%):</td><td style="text-align:right; color:#dc2626;">-$<?= number_format($cpp, 2) ?></td></tr>
                                    <tr><td>EI (1.66%):</td><td style="text-align:right; color:#dc2626;">-$<?= number_format($ei, 2) ?></td></tr>
                                </table>
                            </div>
                        </div>

                        <div style="background:#1e293b; color:white; border-radius:4px; padding:10px 15px; display:flex; justify-content:space-between; align-items:center;">
                            <div><span style="font-size:9px; text-transform:uppercase; color:#94a3b8; display:block;">Period Net Value Owed:</span><strong style="font-size:14px; color:#38bdf8;">$<?= number_format($net, 2) ?></strong></div>
                            <div><span style="font-size:9px; text-transform:uppercase; color:#94a3b8; display:block;">Amount Disbursed:</span><strong style="font-size:14px; color:#34d399;">$<?= number_format($paid, 2) ?></strong></div>
                            <div><span style="font-size:9px; text-transform:uppercase; color:#94a3b8; display:block;">Remaining Unpaid Balance:</span><strong style="font-size:14px; color:<?= $bal > 0.01 ? '#f87171' : '#34d399' ?>;">$<?= number_format(max(0, $bal), 2) ?></strong></div>
                        </div>
                    </div>

                </div>
        <?php 
            endforeach;
        endforeach; 
        ?>
    <?php endif; ?>

</div>
</body>
</html>