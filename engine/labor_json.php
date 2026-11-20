<?php
/**
 * CLI JSON for bookkeeping: payroll wages by restaurant site.
 * php payroll_labor_json.php SITE_ID YYYY-MM-DD YYYY-MM-DD
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}
require "/home/renocon/public_html/payroll/db.php";

$site = (int)($argv[1] ?? 0);
$from = (string)($argv[2] ?? '');
$to = (string)($argv[3] ?? '');
if ($site <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    fwrite(STDERR, "usage: payroll_labor_json.php site_id YYYY-MM-DD YYYY-MM-DD\n");
    exit(1);
}

$dateExpr = "COALESCE(f.work_date, f.pay_period_end)";

$emp = $pdo->prepare(
    "SELECT f.user_id,
            COALESCE(NULLIF(TRIM(u.Name), ''), NULLIF(TRIM(u.user), ''), CONCAT('Employee ', f.user_id)) AS name,
            SUM(IFNULL(f.total_gross, 0)) AS wages
     FROM payroll_finalized f
     LEFT JOIN users u ON u.id = f.user_id
     WHERE f.site_id = ?
       AND $dateExpr BETWEEN ? AND ?
     GROUP BY f.user_id, name
     HAVING SUM(IFNULL(f.total_gross, 0)) <> 0
     ORDER BY wages DESC, name"
);
$emp->execute([$site, $from, $to]);
$employees = [];
foreach ($emp->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $employees[] = [
        'name' => $row['name'],
        'wages' => $row['wages'],
        'user_id' => (int)$row['user_id'],
    ];
}

$mon = $pdo->prepare(
    "SELECT YEAR($dateExpr) AS y,
            MONTH($dateExpr) AS m,
            SUM(IFNULL(f.total_gross, 0)) AS gross,
            SUM(IFNULL(f.cpp_er, 0)) AS cpp_er,
            SUM(IFNULL(f.ei_er, 0)) AS ei_er,
            SUM(IFNULL(f.wcb, 0)) AS wcb
     FROM payroll_finalized f
     WHERE f.site_id = ?
       AND $dateExpr BETWEEN ? AND ?
     GROUP BY YEAR($dateExpr), MONTH($dateExpr)
     ORDER BY y, m"
);
$mon->execute([$site, $from, $to]);
$months = [];
foreach ($mon->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $months[] = [
        'year' => (int)$row['y'],
        'month' => (int)$row['m'],
        'gross' => $row['gross'],
        'cpp_er' => $row['cpp_er'],
        'ei_er' => $row['ei_er'],
        'wcb' => $row['wcb'],
    ];
}

echo json_encode(
    [
        'site_id' => $site,
        'from' => $from,
        'to' => $to,
        'employees' => $employees,
        'months' => $months,
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
