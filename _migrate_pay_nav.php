<?php
/**
 * Idempotent Payroll dropdown cleanup.
 * Restores the consolidated PAY nav (CSV Import = bulk_importer.php) and
 * hides obsolete pre-consolidation payroll links.
 *
 * menu_manager is global — this is the product nav, not org-specific.
 */
require '/home/admin/domains/payroll.renocon.ca/public_html/db.php';

$doc = '/home/admin/domains/payroll.renocon.ca/public_html';
$stamp = date('Ymd_His');
$bak = 'menu_manager_bak_paynav_' . $stamp;
$pdo->exec("CREATE TABLE `$bak` LIKE menu_manager");
$pdo->exec("INSERT INTO `$bak` SELECT * FROM menu_manager");
echo "backup=$bak\n";

// 129 was a duplicate of Payroll Center with ?balance_scope=outstanding — hide it.
$keep = [126, 134, 137, 44, 118, 115, 117];
$updates = [
    126 => ['Staff Audit / Finalize', '/bulk_finalize.php', 0, 20],
    134 => ['Payroll Center', '/payroll_master_ledger.php', 1, 20],
    137 => ['CRA Remittance', '/payroll_cra_payments.php', 2, 20],
    44  => ['Tax & T4', '/generate_t4.php', 3, 20],
    118 => ['Stat Pay Preview', '/generate_stat_shifts.php', 4, 20],
    115 => ['CSV Import', '/bulk_importer.php', 5, 20],
    117 => ['Payroll Settings', '/vacation_service_admin.php', 6, 20],
];

// If bulk_finalize is missing, keep a working finalize/audit target.
if (!is_file($doc . '/bulk_finalize.php')) {
    if (is_file($doc . '/staff_audit.php')) {
        $updates[126][1] = '/staff_audit.php';
        echo "warn: bulk_finalize.php missing — Staff Audit -> /staff_audit.php\n";
    } elseif (is_file($doc . '/finalize_payroll.php')) {
        $updates[126][1] = '/finalize_payroll.php';
        echo "warn: bulk_finalize.php missing — Staff Audit -> /finalize_payroll.php\n";
    }
}

foreach ($updates as $id => $v) {
    $rel = ltrim(explode('?', $v[1], 2)[0], '/');
    if ($rel !== '' && $rel !== '#' && !is_file($doc . '/' . $rel)) {
        echo "warn: target missing for MenuID $id: {$v[1]}\n";
    }
}

$ids = implode(',', array_map('intval', $keep));
$pdo->exec("UPDATE menu_manager SET IsActive=0 WHERE ParentID=116 AND MenuID NOT IN ($ids)");

$up = $pdo->prepare(
    "UPDATE menu_manager
     SET MenuTitle=?, LinkURL=?, SortOrder=?, UserRole=?, IsActive=1, ParentID=116, Module='PAY'
     WHERE MenuID=?"
);
foreach ($updates as $id => $v) {
    $up->execute([$v[0], $v[1], $v[2], $v[3], $id]);
}

// Old superadmin-only CSV path (migration_final.php) — keep hidden.
$pdo->exec("UPDATE menu_manager SET IsActive=0 WHERE MenuID=128");
// Duplicate outstanding-filter alias of Payroll Center.
$pdo->exec("UPDATE menu_manager SET IsActive=0 WHERE MenuID=129");

echo "pay_nav=ok\n";
foreach ($pdo->query(
    "SELECT MenuID, MenuTitle, LinkURL, UserRole, Module, IsActive, SortOrder
     FROM menu_manager WHERE ParentID=116 ORDER BY IsActive DESC, SortOrder, MenuID"
) as $r) {
    echo sprintf(
        "%s id=%s role=%s mod=%s %s => %s\n",
        ((int)$r['IsActive'] === 1 ? 'ON ' : 'off'),
        $r['MenuID'],
        $r['UserRole'],
        $r['Module'],
        $r['MenuTitle'],
        $r['LinkURL']
    );
}
