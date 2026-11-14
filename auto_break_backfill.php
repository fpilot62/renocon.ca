<?php
/**
 * Path: /auto_break_backfill.php
 * List or insert Auto breaks for past shifts that are not finalized.
 *
 *   php auto_break_backfill.php
 *   php auto_break_backfill.php --dry-run
 *   php auto_break_backfill.php --dry-run --org=3
 *   php auto_break_backfill.php --apply
 *   php auto_break_backfill.php --apply --org=3
 *
 * --dry-run is the default. It writes nothing.
 * Refuses to run from a web request.
 * Does not connect until the command line check passes.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Auto break backfill runs from the command line only.\n";
    exit;
}

require_once __DIR__ . '/engine/auto_break_lib.php';

if (!isset($argv[0]) || realpath((string)$argv[0]) !== realpath(__FILE__)) {
    return;
}

$parsed = ab_parse_cli(array_slice($argv, 1));
if ($parsed['help']) {
    echo ab_cli_usage();
    exit(0);
}
if ($parsed['error'] !== null) {
    fwrite(STDERR, $parsed['error'] . "\n" . ab_cli_usage());
    exit(2);
}

try {
    $pdo = ab_open_pdo(__DIR__);
    $result = ab_backfill($pdo, $parsed['org'], $parsed['apply']);
} catch (Throwable $e) {
    fwrite(STDERR, "Auto break backfill failed: " . $e->getMessage() . "\n");
    exit(1);
}

$orgLabel = $parsed['org'] === null ? 'all orgs' : ('org ' . $parsed['org']);
$mode = $parsed['apply'] ? 'apply' : 'dry-run';
echo 'Auto break backfill (' . $mode . ', ' . $orgLabel . ").\n";
if ($result['lines'] === []) {
    echo "No unfinalized shift needs an Auto break change.\n";
} else {
    foreach ($result['lines'] as $line) {
        echo $line . "\n";
    }
}
$counts = $result['counts'];
echo $counts['add'] . ' to add, ' . $counts['move'] . ' to move, ' . $counts['remove'] . " to remove.\n";
$orgSummary = ab_format_org_counts($result['by_org'] ?? []);
if ($orgSummary !== '') {
    echo "BY ORG:\n" . $orgSummary . "\n";
}
$siteSummary = ab_format_site_counts($result['by_site'] ?? [], 2);
if ($siteSummary !== '') {
    echo "BY SITE org 2:\n" . $siteSummary . "\n";
}
if ($parsed['apply']) {
    echo "Those rows were written. Finalized shifts were not changed.\n";
} else {
    echo "Nothing was written. Run with --apply to write.\n";
}
