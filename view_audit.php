<?php
require_once 'engine.php';
$id = (int)$_GET['id'];

$stmt = $pdo->prepare("SELECT a.*, u.Name, u.official_rate FROM attendance a 
                       JOIN users u ON a.user_id = u.id WHERE a.id = ?");
$stmt->execute([$id]);
$s = $stmt->fetch(PDO::FETCH_ASSOC);
$rate = (float)($s['official_rate'] ?? 0);
?>

<input type="hidden" id="current-id" value="<?= $id ?>">
<input type="hidden" id="current-rate" value="<?= $rate ?>">

<div style="font-family: sans-serif;">
    <h3>Shift Audit: <?= htmlspecialchars($s['Name']) ?></h3>
    <p>Staff Wage Rate: <strong>$<?= number_format($rate, 2) ?>/hr</strong></p>
    <hr>
    
    <div style="display:flex; gap:20px; justify-content:center; margin-bottom:20px;">
        <img src="<?= $s['selfie_in'] ?>" style="width:150px; border-radius:8px; border:1px solid #ddd;">
        <img src="<?= $s['selfie_out'] ?>" style="width:150px; border-radius:8px; border:1px solid #ddd;">
    </div>

    <table style="width:100%; border-collapse:collapse; border:1px solid #e2e8f0;">
        <tr style="background:#f8fafc; font-weight:bold;">
            <td style="padding:10px; border:1px solid #e2e8f0;">Category</td>
            <td align="center" style="padding:10px; border:1px solid #e2e8f0;">Hours</td>
            <td align="right" style="padding:10px; border:1px solid #e2e8f0;">Gross</td>
        </tr>
        <tr>
            <td style="padding:10px; border:1px solid #e2e8f0;">Regular Hours</td>
            <td align="center" id="modal-reg-hrs">0.00</td>
            <td align="right" id="modal-reg-gross">$0.00</td>
        </tr>
        <tr>
            <td style="padding:10px; border:1px solid #e2e8f0;">Balance Hours</td>
            <td align="center" id="modal-bal-hrs">0.00</td>
            <td align="right" id="modal-bal-gross">$0.00</td>
        </tr>
        <tr style="background:#f1f5f9; font-weight:bold;">
            <td colspan="2" style="padding:10px;">Total Employee Gross</td>
            <td align="right" id="modal-total-gross" style="color:#2563eb;">$0.00</td>
        </tr>
        <tr>
            <td colspan="2" style="padding:10px; color:#64748b;">Employer Burden (Deductions/Contributions)</td>
            <td align="right" id="modal-total-burden" style="color:#64748b;">$0.00</td>
        </tr>
        <tr style="background:#0f172a; color:#fff; font-size:1.2em;">
            <td colspan="2" style="padding:12px;"><strong>TOTAL COMPANY COST</strong></td>
            <td align="right"><strong id="modal-company-cost">$0.00</strong></td>
        </tr>
    </table>
</div>