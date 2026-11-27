<?php
/**
 * Read-only. Lists org 4 Thanksgiving rows whose worked-holiday hours or
 * premium would change if only the holiday date counted.
 *
 *   php ops/stat_worked_split_dry.php
 *   php ops/stat_worked_split_dry.php --org=4 --date=2026-10-12
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This report is command-line only.\n");
    exit(1);
}
$app = getenv('PAYROLL_APP') ?: '/home/renocon/public_html/payroll';
$lib = getenv('STAT_SPLIT_LIB') ?: $app;
require $app . '/db.php';
require $lib . '/payroll_engine/stat_holidays.php';
if (is_file($app . '/payroll_engine/payroll_engine.php')) {
    require_once $app . '/payroll_engine/payroll_engine.php';
}
if (!isset($pdo) || !$pdo instanceof PDO) {
    fwrite(STDERR, "db.php did not provide a connection\n");
    exit(1);
}
$orgId = 4;
$holiday = '2026-10-12';
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--org=') === 0) {
        $orgId = (int)substr($arg, 6);
    } elseif (strpos($arg, '--date=') === 0) {
        $holiday = substr($arg, 7);
    } else {
        fwrite(STDERR, "Unexpected argument.\n");
        exit(1);
    }
}
if ($orgId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $holiday)) {
    fwrite(STDERR, "Usage: php stat_worked_split_dry.php [--org=4] [--date=2026-10-12]\n");
    exit(1);
}
$bounds = new DateTimeImmutable($holiday . ' 00:00:00', new DateTimeZone('UTC'));
$start = $bounds->format('Y-m-d H:i:s');
$end = $bounds->modify('+1 day')->format('Y-m-d H:i:s');
$earliest = $bounds->modify('-36 hours')->format('Y-m-d H:i:s');
$st = $pdo->prepare(
    "SELECT DISTINCT a.user_id, a.site_id, u.Name AS name, s.nick_name AS site, UPPER(TRIM(s.province)) AS province
       FROM attendance a
       INNER JOIN users u ON u.id = a.user_id AND u.org_id = ?
       INNER JOIN sites s ON s.SId = a.site_id AND s.org_id = u.org_id
      WHERE a.clock_in >= ? AND a.clock_in < ?
        AND a.clock_out IS NOT NULL AND a.clock_out <> '' AND a.clock_out <> '0000-00-00 00:00:00'
        AND a.clock_out > ?
      ORDER BY s.nick_name, u.Name, a.user_id"
);
$st->execute([$orgId, $earliest, $end, $start]);
echo "org $orgId holiday $holiday\n";
echo "user_id|name|site|old_hours|new_hours|old_premium|new_premium|holiday_pay|old_total|new_total|approved\n";
$n = 0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $userId = (int)$row['user_id'];
    $siteId = (int)$row['site_id'];
    $province = sh_normalize_province((string)$row['province']);
    $rate = function_exists('pe_profile_hourly_rate') ? pe_profile_hourly_rate($pdo, $userId, $holiday) : 0.0;
    if ($rate <= 0) {
        $rst = $pdo->prepare('SELECT hourly_rate FROM users WHERE id=?');
        $rst->execute([$userId]);
        $rate = (float)$rst->fetchColumn();
    }
    $worked = sh_holiday_worked($pdo, $userId, $siteId, $holiday, $province, $rate);
    if (abs($worked['hours'] - $worked['legacy_hours']) < 0.009 && abs($worked['premium'] - $worked['legacy_premium']) < 0.009) {
        continue;
    }
    $calc = sh_compute_stat_pay($pdo, $userId, $siteId, $holiday, $province);
    $holidayPay = (float)$calc['amount'];
    $approved = 'no';
    try {
        $ap = $pdo->prepare(
            "SELECT COALESCE(stat_approval_status,'') AS approval, LOWER(COALESCE(status,'')) AS pay_status, COALESCE(paid_amount,0) AS paid
               FROM payroll_finalized
              WHERE user_id=? AND site_id=? AND work_date=? AND category='Statutory Holiday'
              ORDER BY id DESC LIMIT 1"
        );
        $ap->execute([$userId, $siteId, $holiday]);
        $got = $ap->fetch(PDO::FETCH_ASSOC);
        if ($got && ($got['approval'] === 'approved' || (float)$got['paid'] > 0.02 || in_array($got['pay_status'], ['paid', 'remitted'], true))) {
            $approved = 'yes';
        }
    } catch (Throwable $e) {
        $approved = 'unknown';
    }
    $oldTotal = round($holidayPay + $worked['legacy_premium'], 2);
    $newTotal = round($holidayPay + $worked['premium'], 2);
    echo $userId . '|' . str_replace('|', '/', (string)$row['name']) . '|' . str_replace('|', '/', (string)$row['site'])
        . '|' . number_format($worked['legacy_hours'], 2, '.', '')
        . '|' . number_format($worked['hours'], 2, '.', '')
        . '|' . number_format($worked['legacy_premium'], 2, '.', '')
        . '|' . number_format($worked['premium'], 2, '.', '')
        . '|' . number_format($holidayPay, 2, '.', '')
        . '|' . number_format($oldTotal, 2, '.', '')
        . '|' . number_format($newTotal, 2, '.', '')
        . '|' . $approved . "\n";
    $n++;
}
echo "changed $n\n";
echo "Read-only. No row was updated.\n";
