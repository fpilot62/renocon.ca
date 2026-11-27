<?php
/**
 * Read-only. Org 4 Thanksgiving rows: what payroll shows now, and what
 * Approve would post (public holiday pay, 1.5× holiday hours, regular
 * hours reduced on the worked shift). Does not write.
 *
 *   php ops/stat_payroll_lines_dry.php
 *   php ops/stat_payroll_lines_dry.php --org=4 --date=2026-10-12
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
    fwrite(STDERR, "Usage: php stat_payroll_lines_dry.php [--org=4] [--date=2026-10-12]\n");
    exit(1);
}
$st = $pdo->prepare(
    "SELECT DISTINCT f.user_id, f.site_id, u.Name AS name, s.nick_name AS site, UPPER(TRIM(s.province)) AS province
       FROM payroll_finalized f
       INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
       INNER JOIN sites s ON s.SId = f.site_id AND s.org_id = u.org_id
      WHERE f.work_date = ?
      ORDER BY s.nick_name, u.Name, f.user_id"
);
$st->execute([$orgId, $holiday]);
echo "READ ONLY org $orgId holiday $holiday\n";
echo "Old total is the shift as stored plus public holiday pay only when that line is already in pay.\n";
echo "New total is leftover regular hours plus holiday hours at 1.5x plus public holiday pay, after Approve.\n";
echo "name|site|shift_h|holiday_h|regular_h|rate|old_shift|new_shift|premium_1_5|holiday_pay|old_total|new_total|stat\n";
$n = 0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $userId = (int)$row['user_id'];
    $siteId = (int)$row['site_id'];
    $province = function_exists('sh_normalize_province') ? sh_normalize_province((string)$row['province']) : (string)$row['province'];
    $rate = function_exists('pe_profile_hourly_rate') ? pe_profile_hourly_rate($pdo, $userId, $holiday) : 0.0;
    if ($rate <= 0) {
        $rst = $pdo->prepare('SELECT hourly_rate FROM users WHERE id=?');
        $rst->execute([$userId]);
        $rate = (float)$rst->fetchColumn();
    }
    $line = $pdo->prepare(
        "SELECT COALESCE(reg_hours,0) AS reg_hours, COALESCE(hourly_rate,0) AS hourly_rate,
                COALESCE(total_gross,0) AS total_gross, COALESCE(comments,'') AS comments
           FROM payroll_finalized
          WHERE user_id=? AND site_id=? AND work_date=?
            AND COALESCE(category,'') NOT IN ('Statutory Holiday','Holiday Worked','Deduction','Benefit')
            AND COALESCE(pay_type,'') <> 'Statutory'
          ORDER BY id LIMIT 1"
    );
    $line->execute([$userId, $siteId, $holiday]);
    $shift = $line->fetch(PDO::FETCH_ASSOC) ?: null;
    $shiftHours = $shift ? (float)$shift['reg_hours'] : 0.0;
    $oldShift = $shift ? (float)$shift['total_gross'] : 0.0;
    if ($shift && preg_match('/\[\[stat-split ' . preg_quote($holiday, '/') . ' ([0-9.]+) ([0-9.]+) ([0-9.]+) /', (string)$shift['comments'], $m)) {
        $shiftHours = (float)$m[1];
        $oldShift = (float)$m[3];
    }
    if ($shift && (float)$shift['hourly_rate'] > 0) {
        $rate = (float)$shift['hourly_rate'];
    }
    $worked = sh_holiday_worked($pdo, $userId, $siteId, $holiday, $province, $rate);
    $holidayHours = (float)$worked['hours'];
    if ($shiftHours > 0 && $holidayHours > $shiftHours) {
        $holidayHours = $shiftHours;
    }
    $regularHours = round(max(0, $shiftHours - $holidayHours), 2);
    $removed = round($holidayHours * $rate, 2);
    $newShift = round(max(0, $oldShift - $removed), 2);
    $premium = round($holidayHours * $rate * 1.5, 2);
    $calc = sh_compute_stat_pay($pdo, $userId, $siteId, $holiday, $province);
    $holidayPay = (float)$calc['amount'];
    $stat = 'none';
    try {
        $ap = $pdo->prepare(
            "SELECT COALESCE(stat_approval_status,'') AS approval, COALESCE(total_gross,0) AS gross
               FROM payroll_finalized
              WHERE user_id=? AND site_id=? AND work_date=? AND category='Statutory Holiday'
              ORDER BY id DESC LIMIT 1"
        );
        $ap->execute([$userId, $siteId, $holiday]);
        $got = $ap->fetch(PDO::FETCH_ASSOC);
        if ($got) {
            $stat = (string)$got['approval'] !== '' ? (string)$got['approval'] : 'posted';
            if ((float)$got['gross'] > 0) {
                $holidayPay = (float)$got['gross'];
            }
        }
    } catch (Throwable $e) {
        $stat = 'unknown';
    }
    $inPay = ($stat === 'approved' || $stat === 'posted');
    $oldTotal = round($oldShift + ($inPay ? $holidayPay : 0), 2);
    $newTotal = round($newShift + $premium + $holidayPay, 2);
    echo str_replace('|', '/', (string)$row['name']) . '|' . str_replace('|', '/', (string)$row['site'])
        . '|' . number_format($shiftHours, 2, '.', '')
        . '|' . number_format($holidayHours, 2, '.', '')
        . '|' . number_format($regularHours, 2, '.', '')
        . '|' . number_format($rate, 2, '.', '')
        . '|' . number_format($oldShift, 2, '.', '')
        . '|' . number_format($newShift, 2, '.', '')
        . '|' . number_format($premium, 2, '.', '')
        . '|' . number_format($holidayPay, 2, '.', '')
        . '|' . number_format($oldTotal, 2, '.', '')
        . '|' . number_format($newTotal, 2, '.', '')
        . '|' . $stat . "\n";
    $n++;
}
echo "rows $n\n";
echo "Read-only. No row was updated.\n";
