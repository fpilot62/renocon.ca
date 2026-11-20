<?php
/** Path: /pay_stub.php */
require_once 'engine.php';
$id = $_GET['id'] ?? die("ID Required");
$type = $_GET['type'] ?? 'reg';

// 1. Fetch current record
$stmt = $pdo->prepare("SELECT p.*, u.Name, u.Address FROM payroll_records p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
$stmt->execute([$id]);
$p = $stmt->fetch();

// 2. Calculate YTD Totals (Standard Canadian Requirement)
$ytd_stmt = $pdo->prepare("SELECT SUM(gross_pay) as ytd_gross, SUM(net_pay) as ytd_net, 
                                  SUM(cpp_deduction) as ytd_cpp, SUM(ei_deduction) as ytd_ei, 
                                  SUM(income_tax) as ytd_tax 
                           FROM payroll_records 
                           WHERE user_id = ? AND YEAR(date_processed) = YEAR(?)");
$ytd_stmt->execute([$p['user_id'], $p['date_processed']]);
$ytd = $ytd_stmt->fetch();

// 3. Split logic for Reg vs Bal stubs
$current_gross = ($type == 'reg') ? ($p['allocated_reg_hrs'] * $p['rate_used']) : ($p['balance_hrs'] * $p['balance_rate']);
$ratio = ($p['gross_pay'] > 0) ? ($current_gross / $p['gross_pay']) : 0;

$stub_cpp = ($p['cpp_deduction'] ?? 0) * $ratio;
$stub_ei  = ($p['ei_deduction'] ?? 0) * $ratio;
$stub_tax = ($p['income_tax'] ?? 0) * $ratio;
$total_taxes = $stub_cpp + $stub_ei + $stub_tax;
$stub_net = $current_gross - $total_taxes;
?>

<style>
    .phoenix-stub { font-family: Arial, sans-serif; width: 800px; margin: auto; border: 1px solid #ccc; padding: 20px; }
    .summary-box { display: flex; justify-content: space-between; background: #f8f9fa; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    th { background: #eee; text-align: left; padding: 8px; font-size: 12px; border-bottom: 2px solid #333; }
    td { padding: 8px; border-bottom: 1px solid #eee; font-size: 13px; }
    .ytd-col { color: #666; font-style: italic; }
</style>

<div class="phoenix-stub">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h2>Renocon Services</h2>
        <div style="text-align:right;">
            <strong><?= strtoupper($type) ?> EARNINGS STUB</strong><br>
            Pay Date: <?= date('Y-m-d', strtotime($p['date_processed'])) ?>
        </div>
    </div>

    <div class="summary-box">
        <div><strong>Employee:</strong> <?= htmlspecialchars($p['Name']) ?></div>
        <div><strong>Gross:</strong> $<?= number_format($current_gross, 2) ?></div>
        <div><strong>Taxes:</strong> $<?= number_format($total_taxes, 2) ?></div>
        <div><strong>Net Pay:</strong> <span style="font-size:1.2em;">$<?= number_format($stub_net, 2) ?></span></div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Earnings Description</th>
                <th>Hours</th>
                <th>Rate</th>
                <th>Current Amount</th>
                <th class="ytd-col">YTD Total</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?= ($type == 'reg') ? 'Basic Pay' : 'Balance Adjustment' ?></td>
                <td><?= ($type == 'reg') ? $p['allocated_reg_hrs'] : $p['balance_hrs'] ?></td>
                <td>$<?= number_format(($type == 'reg') ? $p['rate_used'] : $p['balance_rate'], 2) ?></td>
                <td>$<?= number_format($current_gross, 2) ?></td>
                <td class="ytd-col">$<?= number_format($ytd['ytd_gross'], 2) ?></td>
            </tr>
        </tbody>
    </table>

    <table>
        <thead>
            <tr>
                <th>Statutory Deductions (Taxes)</th>
                <th>Current Amount</th>
                <th class="ytd-col">YTD Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>CIT (Canadian Income Tax)</td><td>-$<?= number_format($stub_tax, 2) ?></td><td class="ytd-col">-$<?= number_format($ytd['ytd_tax'], 2) ?></td></tr>
            <tr><td>EI (Employment Insurance)</td><td>-$<?= number_format($stub_ei, 2) ?></td><td class="ytd-col">-$<?= number_format($ytd['ytd_ei'], 2) ?></td></tr>
            <tr><td>CPP (Canada Pension Plan)</td><td>-$<?= number_format($stub_cpp, 2) ?></td><td class="ytd-col">-$<?= number_format($ytd['ytd_cpp'], 2) ?></td></tr>
        </tbody>
    </table>

    <div style="text-align:center; font-size: 11px; color: #888;">
        Payment Type: Direct Deposit | Reference ID: <?= str_pad($p['id'], 8, '0', STR_PAD_LEFT) ?>
    </div>
</div>