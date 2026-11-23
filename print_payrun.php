<?php
/** Path: /print_payrun.php */
// Hide warnings for a clean print
ini_set('display_errors', 0); 
require_once 'db.php';
require_once __DIR__ . '/engine/orgscope_guard.php'; osg_page(['level' => 10]); // org-scope audit Nov 2026
if (is_file(__DIR__ . '/payroll_engine/payroll_engine.php')) {
    require_once __DIR__ . '/payroll_engine/payroll_engine.php';
}

$from   = $_GET['from_date'] ?? '';
$to     = $_GET['to_date'] ?? '';
$chq_no = (int)($_GET['chq_start'] ?? 1001);
$micr   = $_GET['micr_data'] ?? '⑆0001⑆ ⑈123456789⑈ 000000000⑆';

if (!$from || !$to) { die("Dates missing."); }

// --- WORD CONVERSION ---
function convertNumberToWords($number) {
    $f = new NumberFormatter("en", NumberFormatter::SPELLOUT);
    $whole = floor($number);
    $fraction = round(($number - $whole) * 100);
    $words = ucfirst($f->format($whole));
    return $words . " and " . str_pad($fraction, 2, "0", STR_PAD_LEFT) . "/100";
}

try {
    $sql = "SELECT u.Name as emp_name, u.id as user_id, c.display_label, c.hrs, c.gross, c.cpp_val, c.ei_val
            FROM (
                SELECT user_id, display_label, reg_hours as hrs, reg_pay as gross, cpp as cpp_val, ei as ei_val 
                FROM payroll_finalized WHERE work_date BETWEEN ? AND ?" . (function_exists('pe_not_pending_sql') ? pe_not_pending_sql($pdo, '') : '') . "
                UNION ALL
                SELECT user_id, 'Balance Adjustment' as display_label, reg_hours as hrs, reg_pay as gross, cpp_paid as cpp_val, ei_paid as ei_val 
                FROM payroll_balance WHERE work_date BETWEEN ? AND ?
            ) as c
            JOIN users u ON c.user_id = u.id ORDER BY u.Name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$from, $to, $from, $to]);
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $employees = [];
    foreach ($all as $l) {
        $uid = $l['user_id'];
        if (!isset($employees[$uid])) {
            $employees[$uid] = ['name' => $l['emp_name'], 'lines' => [], 'tg' => 0, 'td' => 0];
        }
        $employees[$uid]['lines'][] = $l;
        $employees[$uid]['tg'] += (float)$l['gross'];
        $employees[$uid]['td'] += ((float)$l['cpp_val'] + (float)$l['ei_val']);
    }
} catch (Exception $e) { die($e->getMessage()); }
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payroll Run</title>
    <style>
        body { font-family: 'Courier New', Courier, monospace; margin: 0; padding: 0; font-size: 13px; }
        .page { width: 8.5in; height: 11in; padding: 0.4in; margin: 0 auto; page-break-after: always; position: relative; box-sizing: border-box; }
        .stub { height: 4in; border-bottom: 1px dashed #666; }
        .cheque { height: 3.5in; margin-top: 2.2in; border: 1px solid #999; border-radius: 10px; padding: 35px; position: relative; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th { text-align: left; border-bottom: 1px solid #333; padding: 5px; font-weight: bold; }
        td { padding: 6px 5px; border-bottom: 1px solid #eee; }
        .amt-box { position: absolute; right: 40px; top: 110px; border: 2px solid #000; padding: 10px 15px; font-size: 20px; font-weight: bold; background: #fff; }
        @media print { .no-print { display: none; } .page { border: none; padding: 0.4in; } }
    </style>
</head>
<body>

<div class="no-print" style="background:#0f172a; color:white; padding:15px; text-align:center; font-family: sans-serif;">
    <strong>PREVIEW:</strong> Ready to print <?= count($employees) ?> Cheque(s).
    <button onclick="window.print()" style="margin-left:20px; padding:8px 20px; cursor:pointer; font-weight:bold;">PRINT NOW</button>
</div>

<?php foreach ($employees as $emp): $net = $emp['tg'] - $emp['td']; ?>
<div class="page">
    <div class="stub">
        <div style="display:flex; justify-content:space-between;">
            <div><strong>PAY ADVICE</strong><br>Period: <?= $from ?> to <?= $to ?></div>
            <div style="text-align:right;">Employee: <?= htmlspecialchars((string)$emp['name']) ?><br><strong>CHQ #<?= $chq_no ?></strong></div>
        </div>
        <table>
            <thead>
                <tr>
                    <th width="40%">Description</th>
                    <th width="15%">Hrs</th>
                    <th width="15%">Gross</th>
                    <th width="15%">Deduct</th>
                    <th width="15%" style="text-align:right;">Net</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($emp['lines'] as $l): 
                    $line_net = (float)$l['gross'] - ((float)$l['cpp_val'] + (float)$l['ei_val']); ?>
                <tr>
                    <td><?= htmlspecialchars((string)($l['display_label'] ?? 'Adjustment')) ?></td>
                    <td><?= number_format((float)$l['hrs'], 2) ?></td>
                    <td>$<?= number_format((float)$l['gross'], 2) ?></td>
                    <td>-$<?= number_format((float)$l['cpp_val'] + (float)$l['ei_val'], 2) ?></td>
                    <td style="text-align:right;">$<?= number_format($line_net, 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="font-weight:bold; background:#f9f9f9;">
                    <td colspan="4" style="text-align:right; padding:10px;">TOTAL NET PAYABLE:</td>
                    <td style="text-align:right; padding:10px;">$<?= number_format($net, 2) ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="cheque">
        <div style="text-align:right;">Date: <?= date('M d, Y') ?><br><strong>No. <?= $chq_no ?></strong></div>
        <div style="margin-top:50px; font-size: 15px;">PAY TO THE ORDER OF: <strong style="text-decoration:underline;"><?= htmlspecialchars((string)$emp['name']) ?></strong></div>
        <div class="amt-box">$ <?= number_format($net, 2) ?></div>
        <div style="margin-top:40px; font-weight:bold; font-size: 14px; border-bottom: 1px solid #000; padding-bottom: 5px;">
            <?= convertNumberToWords($net) ?> Dollars
        </div>
        <div style="position:absolute; bottom:35px; left:50px; font-size:19px; letter-spacing:6px; color:#444;">
            <?= htmlspecialchars((string)$micr) ?>
        </div>
    </div>
</div>
<?php $chq_no++; endforeach; ?>

</body>
</html>
