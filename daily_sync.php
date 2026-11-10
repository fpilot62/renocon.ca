<?php
/**
 * Path: /daily_sync.php
 * Database credentials come from config/payroll.env (not committed).
 * The previous copy of this file contained a plaintext database password.
 * Rotate that password before relying on this copy.
 *
 * Run from the command line: php daily_sync.php
 * Put the existing sync statements, without the password, in daily_sync.local.php.
 * That file is gitignored. It may use $pdo.
 */
require_once __DIR__ . '/engine/attendance_integrity_lib.php';
require_once __DIR__ . '/engine/payroll_db_config.php';

if (PHP_SAPI !== 'cli') {
    payroll_require_admin(false, 'Daily sync is for admins only.');
    http_response_code(403);
    exit('Daily sync runs from the command line.');
}

$cfg = payroll_db_config(__DIR__ . '/config/payroll.env');
$pdo = payroll_pdo($cfg);
daily_sync_run($pdo);

function daily_sync_run(PDO $pdo): void
{
    $local = __DIR__ . '/daily_sync.local.php';
    if (is_file($local)) {
        require $local;
        return;
    }
    fwrite(STDERR, "daily_sync: database config loaded. The sync statements are not in this file.\n");
    fwrite(STDERR, "Move the live queries, without the password, into daily_sync.local.php and run this again.\n");
    exit(2);
}
