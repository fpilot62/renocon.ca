<?php
/**
 * Load the private Outstanding pay CSV. The file is not in the repository.
 *
 *   php ops/load_outstanding_summary.php --dry-run /root/outstanding_summary.csv
 *   php ops/load_outstanding_summary.php /root/outstanding_summary.csv
 *   php ops/load_outstanding_summary.php --org=2 /root/outstanding_summary.csv
 *
 * Header: store,sheet_employee,total_amount,pending,balance,notes,user_id,match_confidence
 * pending and balance are ignored. Default company is org 2.
 * A reload updates the sheet total and notes. It does not clear a saved
 * employee link, a manual period amount, or a reconciliation payment.
 * Nobody is marked paid.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This loader is command-line only.\n");
    exit(1);
}
$app = getenv('PAYROLL_APP') ?: '/home/renocon/public_html/payroll';
require $app . '/db.php';
require $app . '/engine/outstanding_summary_lib.php';
require $app . '/engine/sheet_name_link_lib.php';
if (!isset($pdo) || !$pdo instanceof PDO) {
    fwrite(STDERR, "db.php did not provide a connection\n");
    exit(1);
}
$dry = false;
$orgId = 2;
$file = '';
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dry = true;
    } elseif (strpos($arg, '--org=') === 0) {
        $orgId = (int)substr($arg, 6);
    } elseif ($file === '') {
        $file = $arg;
    } else {
        fwrite(STDERR, "Unexpected argument.\n");
        exit(1);
    }
}
if ($orgId <= 0 || $file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php load_outstanding_summary.php [--dry-run] [--org=2] <file.csv>\n");
    exit(1);
}
try {
    $rows = pos_parse_csv((string)file_get_contents($file));
    $actor = (int)(getenv('OUTSTANDING_ACTOR') ?: 0);
    $result = pos_load($pdo, $orgId, $rows, $actor, $file, $dry);
    echo 'org ' . $orgId . "\n";
    echo pos_format_report($result, $dry, (int)$result['inserted'], (int)$result['updated']);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
