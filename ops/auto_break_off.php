<?php
/**
 * Read-only counts, a rolled-back preview, or the real removal of source='auto' breaks.
 *   php ops/auto_break_off.php check
 *   php ops/auto_break_off.php dry
 *   php ops/auto_break_off.php go
 * Does not mark anything paid. Punched breaks are not deleted.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This runner is command line only.\n");
    exit(1);
}
$mode = $argv[1] ?? 'check';
$app = getenv('PAYROLL_APP') ?: dirname(__DIR__);
if (getenv('ABO_SKIP_DB') === '1') {
    echo "DB report skipped\n";
    exit(0);
}
if (!is_file($app . '/db.php')) {
    fwrite(STDERR, "Missing db.php under $app\n");
    exit(1);
}
require_once $app . '/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "db.php did not provide a connection.\n");
    exit(1);
}
$engine = $app . '/payroll_engine/payroll_engine.php';
if (is_file($engine)) {
    require_once $engine;
}
require_once __DIR__ . '/../engine/auto_break_off_lib.php';

try {
    if ($mode === 'check') {
        echo abo_format_report(abo_summarize($pdo)) . "\n";
        exit(0);
    }
    if ($mode !== 'dry' && $mode !== 'go') {
        fwrite(STDERR, "Usage: php ops/auto_break_off.php check|dry|go\n");
        exit(1);
    }
    $pdo->beginTransaction();
    $result = abo_apply($pdo);
    echo abo_format_apply($result, $mode) . "\n";
    if ($mode === 'dry') {
        $pdo->rollBack();
        $left = (int)$pdo->query("SELECT COUNT(*) FROM breaks WHERE LOWER(TRIM(COALESCE(source,''))) = 'auto'")->fetchColumn();
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
    fwrite(STDERR, "auto-break off failed: " . $e->getMessage() . "\n");
    exit(1);
}
