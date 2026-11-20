<?php
/**
 * Copy the dated T4127 packs into payroll_rate_packs.
 * Inserts a date only when that effective_from is missing. Does not change a row
 * you have edited, and does not touch payroll amounts.
 *
 *   php payroll_engine/seed_rates.php
 */
require_once __DIR__ . '/payroll_engine.php';

if (!is_file(__DIR__ . '/../db.php')) {
    fwrite(STDERR, "db.php is not in this tree. Run this on the server after the migration, from the site root:\n  php payroll_engine/seed_rates.php\n");
    exit(1);
}
require_once __DIR__ . '/../db.php';
if (!isset($pdo) || !$pdo instanceof PDO) {
    fwrite(STDERR, "db.php did not provide \$pdo.\n");
    exit(1);
}
if (!pe_has_table($pdo, 'payroll_rate_packs')) {
    fwrite(STDERR, "payroll_rate_packs does not exist. Run migrations/20261120_source_deductions.sql first.\n");
    exit(1);
}
$added = pe_install_rates($pdo);
echo "Rate packs inserted: $added\n";
foreach (pe_db_packs($pdo) as $pack) {
    echo $pack['effective_from'] . '  ' . $pack['edition'] . "\n";
}
