<?php
/**
 * Path: /auto_break_cron.php
 * Catch up Auto breaks for shifts that clocked out in the last 3 days.
 * The kiosk and edit pages are not in this repo, so they do not call
 * ab_apply_to_shift themselves. This pass is safe to repeat.
 *
 *   php auto_break_cron.php
 *
 * Length comes from ab_break_minutes: under 5 hours gets nothing, and 5 hours
 * or more gets round(30 minutes times worked hours / 8), labelled Auto break.
 *
 * Refuses to run from a web request. A lock file skips a second copy
 * while one is still running.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Auto break cron runs from the command line only.\n";
    exit;
}

require_once __DIR__ . '/engine/auto_break_lib.php';

if (!isset($argv[0]) || realpath((string)$argv[0]) !== realpath(__FILE__)) {
    return;
}

$lockPath = sys_get_temp_dir() . '/payroll-auto-break-cron.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "auto-break cron: already running\n";
    exit(0);
}

try {
    $pdo = ab_open_pdo(__DIR__);
    if (!ab_has_source($pdo)) {
        fwrite(STDERR, "auto-break cron: run migrations/20261111_break_source.sql first.\n");
        exit(1);
    }
    $sql = ab_recent_clockout_sql(att_has_column($pdo, 'attendance', 'deleted_at'));
    $ids = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    $counts = ['add' => 0, 'move' => 0, 'remove' => 0, 'skip' => 0];
    foreach ($ids as $id) {
        $plan = ab_apply_to_shift($pdo, (int)$id);
        $action = $plan['action'] ?? 'skip';
        if ($action === 'insert') {
            $counts['add']++;
        } elseif ($action === 'move') {
            $counts['move']++;
        } elseif ($action === 'remove_auto') {
            $counts['remove']++;
        } else {
            $counts['skip']++;
        }
    }
    echo 'auto-break cron: looked=' . count($ids)
        . ' add=' . $counts['add']
        . ' move=' . $counts['move']
        . ' remove=' . $counts['remove']
        . ' skip=' . $counts['skip'] . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'auto-break cron failed: ' . $e->getMessage() . "\n");
    exit(1);
}
