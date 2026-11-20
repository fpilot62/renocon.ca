<?php
/**
 * One-time: retag menu_manager.Module so org entitlements can hide non-payroll areas.
 * Safe / idempotent. Does not delete data.
 */
require '/home/admin/domains/payroll.renocon.ca/public_html/db.php';

$pdo->beginTransaction();
try {
    // Ensure catalog has keys used by menus
    $ensure = [
        ['operations', 'Operations'],
        ['alarm', 'Alarm / Tasks'],
        ['audit', 'Audit & Compliance'],
    ];
    $ins = $pdo->prepare("INSERT INTO modules (module_key, module_display_name, is_active)
        SELECT ?, ?, 1 FROM DUAL
        WHERE NOT EXISTS (SELECT 1 FROM modules WHERE module_key = ?)");
    foreach ($ensure as [$k, $n]) {
        $ins->execute([$k, $n, $k]);
    }

    // Top-level mis-tags (were PAY so they always showed)
    $pdo->exec("UPDATE menu_manager SET Module='clockin' WHERE MenuID IN (12,13,55,132,47,51,97,28,96,52)");
    $pdo->exec("UPDATE menu_manager SET Module='Scheduling' WHERE MenuID=49");
    $pdo->exec("UPDATE menu_manager SET Module='inventory' WHERE MenuID IN (86,73,69,143,144) OR ParentID=86");
    $pdo->exec("UPDATE menu_manager SET Module='operations' WHERE MenuID=58 OR ParentID=58");
    $pdo->exec("UPDATE menu_manager SET Module='alarm' WHERE MenuID=93");
    // Reports parent was clockin — PAY so Payroll Lite still sees report nav
    $pdo->exec("UPDATE menu_manager SET Module='PAY' WHERE MenuID=3");
    $pdo->exec("UPDATE menu_manager SET Module='inventory' WHERE LOWER(Module)='inventory'");
    $pdo->exec("UPDATE menu_manager SET Module='PAY' WHERE Module='pay'");

    $pdo->commit();
    echo "OK menu retag + catalog ensure\n";
    foreach ($pdo->query("SELECT Module, COUNT(*) c FROM menu_manager WHERE IsActive=1 GROUP BY Module") as $r) {
        echo ($r['Module'] ?: '(empty)') . "\t" . $r['c'] . "\n";
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
