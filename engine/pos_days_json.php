<?php
/**
 * CLI: php pos_days_json.php <site_id> <YYYY-MM-DD> <YYYY-MM-DD>
 * Prints Daily Report rows for that restaurant between the two dates.
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
    fwrite(STDERR, "usage: pos_days_json.php site_id YYYY-MM-DD YYYY-MM-DD\n");
    exit(1);
}
$st = $pdo->prepare(
    "SELECT report_date, pos_sales, paid_out, skip_sales, uber_sales, dash_sales,
            POS_skip, POS_uber, POS_doordash
     FROM daily_report
     WHERE restaurant_id = ? AND report_date BETWEEN ? AND ?
     ORDER BY report_date"
);
$st->execute([$site, $from, $to]);
$out = [];
foreach ($st as $r) {
    $out[] = [
        'report_date' => $r['report_date'],
        'pos_sales' => $r['pos_sales'],
        'paid_out' => $r['paid_out'],
        'skip_sales' => $r['skip_sales'],
        'uber_sales' => $r['uber_sales'],
        'dash_sales' => $r['dash_sales'],
        'POS_skip' => $r['POS_skip'],
        'POS_uber' => $r['POS_uber'],
        'POS_doordash' => $r['POS_doordash'],
    ];
}
echo json_encode($out, JSON_UNESCAPED_SLASHES);
