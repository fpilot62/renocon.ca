<?php
/**
 * CLI: php food_cost_json.php <site_id> <YYYY-MM-DD> <YYYY-MM-DD>
 * Payroll Food Cost invoice rows for that restaurant between the two dates.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}
require __DIR__ . '/../db.php';
$site = (int)($argv[1] ?? 0);
$from = (string)($argv[2] ?? '');
$to = (string)($argv[3] ?? '');
if ($site <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    fwrite(STDERR, "usage: food_cost_json.php site_id YYYY-MM-DD YYYY-MM-DD\n");
    exit(1);
}
$st = $pdo->prepare(
    "SELECT InvoiceDate, Amount, Vendor, InvoiceNumber, Invoice, GST, PST
     FROM FoodCost
     WHERE RId = ? AND InvoiceDate BETWEEN ? AND ?
     ORDER BY InvoiceDate, FCId"
);
$st->execute([$site, $from, $to]);
$out = [];
foreach ($st as $r) {
    $out[] = [
        'InvoiceDate' => $r['InvoiceDate'],
        'Amount' => $r['Amount'],
        'Vendor' => $r['Vendor'],
        'InvoiceNumber' => $r['InvoiceNumber'],
        'Invoice' => $r['Invoice'],
        'GST' => $r['GST'],
        'PST' => $r['PST'],
    ];
}
echo json_encode($out, JSON_UNESCAPED_SLASHES);
