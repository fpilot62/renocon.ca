<?php
/**
 * Read-only counts, a rolled-back preview, or the real removal of source='auto' breaks.
 *   php ops/auto_break_off.php check
 *   php ops/auto_break_off.php dry
 *   php ops/auto_break_off.php go
 * Does not mark anything paid. Punched breaks are not deleted.
 */
function abo_say(string $message): void
{
    echo $message . "\n";
}

if (PHP_SAPI !== 'cli') {
    abo_say('This runner is command line only.');
    exit(1);
}
$mode = $argv[1] ?? 'check';
$app = getenv('PAYROLL_APP') ?: dirname(__DIR__);
if (getenv('ABO_SKIP_DB') === '1') {
    echo "DB report skipped\n";
    exit(0);
}
if (!is_file($app . '/db.php')) {
    abo_say("count error: missing db.php under $app");
    exit(1);
}
try {
    require_once $app . '/db.php';
} catch (Throwable $e) {
    abo_say('count error: db.php failed: ' . $e->getMessage());
    exit(1);
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    abo_say('count error: db.php did not provide a connection.');
    exit(1);
}
$engine = $app . '/payroll_engine/payroll_engine.php';
if (is_file($engine)) {
    require_once $engine;
}
require_once __DIR__ . '/../engine/auto_break_off_lib.php';

try {
    if ($mode === 'check') {
        $report = abo_summarize($pdo);
        echo abo_format_report($report) . "\n";
        if (!empty($report['error'])) {
            abo_say('check failed: ' . $report['error']);
            exit(1);
        }
        exit(0);
    }
    if ($mode !== 'dry' && $mode !== 'go') {
        abo_say('Usage: php ops/auto_break_off.php check|dry|go');
        exit(1);
    }
    $pdo->beginTransaction();
    $result = abo_apply($pdo);
    echo abo_format_apply($result, $mode) . "\n";
    if ($mode === 'dry') {
        $pdo->rollBack();
        $source = abo_ident((string)(abo_schema($pdo)['source'] ?? 'source'));
        $left = (int)$pdo->query("SELECT COUNT(*) FROM breaks WHERE LOWER(TRIM(COALESCE($source,''))) = 'auto'")->fetchColumn();
        echo "auto breaks still present after rollback: $left\n";
        exit(0);
    }
    $pdo->commit();
    echo "Committed. Nothing was marked paid.\n";
    exit(0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    abo_say('auto-break off failed: ' . $e->getMessage());
    exit(1);
}
