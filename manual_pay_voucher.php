<?php
/**
 * Path: /manual_pay_voucher.php
 * Printable payment voucher for Include-in-payroll = No entries.
 * Not a paystub — not employment income for T4.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll_scope.php';
ps_require_payroll_admin();

$orgId = ps_selected_org($pdo);
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { exit('Missing entry id.'); }

$q = $pdo->prepare(
    "SELECT e.*, u.Name AS emp_name, s.nick_name AS site_name, s.corp_legal_name,
            o.org_name AS org_name
     FROM payroll_manual_entries e
     JOIN users u ON u.id = e.user_id
     JOIN sites s ON s.SId = e.site_id
     LEFT JOIN organizations o ON o.id = e.org_id
     WHERE e.id = ? AND e.org_id = ? LIMIT 1"
);
$q->execute([$id, $orgId]);
$row = $q->fetch(PDO::FETCH_ASSOC);
if (!$row) { exit('Entry not found.'); }
if ((int)$row['include_in_payroll'] === 1) {
    exit('This entry is on payroll — use Generate Cheques & Paystubs from Payroll Center.');
}
if ($row['status'] === 'void') { exit('This entry was voided.'); }

$reasonMap = [
    'adjustment' => 'Adjustment',
    'benefit' => 'Benefit',
    'bonus' => 'Bonus',
    'car_allowance' => 'Car allowance',
    'travel' => 'Travel',
    'termination' => 'Termination pay',
    'other' => 'Other',
];
$reason = $reasonMap[$row['reason']] ?? $row['reason'];
if (trim((string)($row['reason_other'] ?? '')) !== '') {
    $reason .= ' — ' . trim((string)$row['reason_other']);
}
if (!empty($row['period_count']) && (int)$row['period_count'] > 1) {
    $freqWord = (($row['frequency'] ?? '') === 'monthly') ? 'Monthly' : 'Bi-weekly';
    $reason .= ' · ' . $freqWord . ' ' . (int)($row['period_no'] ?? 1) . '/' . (int)$row['period_count'];
}
$employer = trim((string)($row['corp_legal_name'] ?: $row['org_name'] ?: 'Employer'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Voucher #<?= (int)$row['id'] ?></title>
    <style>
        body { font-family: Georgia, 'Times New Roman', serif; color:#111; max-width:720px; margin:40px auto; padding:0 16px; }
        h1 { font-size:22px; margin:0 0 4px; letter-spacing:.02em; }
        .sub { color:#555; font-size:13px; margin-bottom:24px; }
        .box { border:2px solid #111; padding:20px 24px; }
        .row { display:flex; justify-content:space-between; margin:10px 0; font-size:15px; }
        .lbl { color:#444; }
        .amt { font-size:28px; font-weight:700; margin:18px 0; }
        .note { margin-top:18px; font-size:12px; color:#444; border-top:1px solid #ccc; padding-top:12px; }
        .actions { margin-bottom:16px; }
        @media print { .actions { display:none; } body { margin:12px; } }
    </style>
</head>
<body>
<div class="actions">
    <button onclick="window.print()">Print</button>
    <a href="manual_pay_entry.php">← Back</a>
</div>
<div class="box">
    <h1>PAYMENT VOUCHER</h1>
    <div class="sub">Not a paystub — not employment income for T4 / CRA payroll remittance.</div>
    <div class="row"><span class="lbl">Voucher #</span><strong><?= (int)$row['id'] ?></strong></div>
    <div class="row"><span class="lbl">Payer</span><strong><?= htmlspecialchars($employer) ?></strong></div>
    <div class="row"><span class="lbl">Site</span><strong><?= htmlspecialchars($row['site_name']) ?></strong></div>
    <div class="row"><span class="lbl">Payee</span><strong><?= htmlspecialchars($row['emp_name']) ?></strong></div>
    <div class="row"><span class="lbl">Date</span><strong><?= htmlspecialchars($row['entry_date']) ?></strong></div>
    <div class="row"><span class="lbl">Reason</span><strong><?= htmlspecialchars($reason) ?></strong></div>
    <div class="amt"><?= ($row['reason'] === 'adjustment') ? '-' : '' ?>$<?= number_format((float)$row['amount'], 2) ?></div>
    <?php if (trim((string)($row['remarks'] ?? '')) !== ''): ?>
        <div class="row"><span class="lbl">Remarks</span><span><?= htmlspecialchars($row['remarks']) ?></span></div>
    <?php endif; ?>
    <div class="note">
        This payment was recorded with <strong>Include in payroll = No</strong>.
        It does not appear on an employee paystub and is not added to T4 box 14.
        Retain receipts / approvals with this voucher.
    </div>
</div>
</body>
</html>
