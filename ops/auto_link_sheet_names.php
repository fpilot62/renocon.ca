<?php
/**
 * Propose or apply one employee link per unambiguous sheet name in org 2.
 *
 *   php ops/auto_link_sheet_names.php --dry-run
 *   php ops/auto_link_sheet_names.php
 *   php ops/auto_link_sheet_names.php --org=2
 *
 * A name with two or more plausible employees is listed and never linked.
 * Confirming a name updates the name map, every matching draft row, and
 * outstanding summary rows. Rows with an active approval are skipped.
 * Nobody is marked paid.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is command-line only.\n");
    exit(1);
}
$app = getenv('PAYROLL_APP') ?: '/home/renocon/public_html/payroll';
require $app . '/db.php';
require $app . '/engine/sheet_name_link_lib.php';
if (!isset($pdo) || !$pdo instanceof PDO) {
    fwrite(STDERR, "db.php did not provide a connection\n");
    exit(1);
}
$dry = false;
$orgId = 2;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dry = true;
    } elseif (strpos($arg, '--org=') === 0) {
        $orgId = (int)substr($arg, 6);
    } else {
        fwrite(STDERR, "Unexpected argument.\n");
        exit(1);
    }
}
if ($orgId <= 0) {
    fwrite(STDERR, "Usage: php auto_link_sheet_names.php [--dry-run] [--org=2]\n");
    exit(1);
}
try {
    $plan = psl_auto_plan($pdo, $orgId, false);
    echo psl_format_plan($plan);
    if ($dry) {
        echo "dry-run\n";
        exit(0);
    }
    $actor = (int)(getenv('SHEET_LINK_ACTOR') ?: 0);
    $done = psl_apply_plan($pdo, $orgId, $plan, $actor, false);
    echo 'applied ' . (int)$done['proposals']
        . ' name(s). Sheet rows updated: ' . (int)$done['linked']
        . '. Skipped with an active approval: ' . (int)$done['skipped_locked']
        . '. Outstanding rows updated: ' . (int)$done['outstanding_linked']
        . ".\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
