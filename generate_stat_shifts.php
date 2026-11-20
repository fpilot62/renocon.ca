<?php
/**
 * Statutory holiday pay — Preview & Approve (ON / SK).
 * Province comes from the work site. Never fabricates attendance. Never auto-posts.
 *
 * Single holiday: Preview → tick → Approve & post selected.
 * Pay period: org pay-period selector → Preview all holidays → Approve & post ticked lines.
 */
require_once 'db.php';
require_once 'payroll_scope.php';
require_once 'payroll_engine/payroll_engine.php';
require_once 'payroll_engine/stat_holidays.php';
if (file_exists('engine/menu_generator.php')) { require_once 'engine/menu_generator.php'; }
ps_require_payroll_admin();
@set_time_limit(180);
sh_ensure_statutory_pay_items($pdo);

$orgId = ps_selected_org($pdo);
$periodFrom = (string)($_GET['from_date'] ?? $_POST['from_date'] ?? '');
$periodTo = (string)($_GET['to_date'] ?? $_POST['to_date'] ?? '');
if ($periodFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodFrom)) { $periodFrom = ''; }
if ($periodTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodTo)) { $periodTo = ''; }

if (!function_exists('sip_org_pay_period_mode')) {
    $statPeriodLib = __DIR__ . '/engine/org_modules_lib.php';
    if (is_file($statPeriodLib)) { require_once $statPeriodLib; }
}
$periodMode = ($orgId > 0 && function_exists('sip_org_pay_period_mode'))
    ? sip_org_pay_period_mode($pdo, $orgId) : 'semi_monthly';
$periodAnchor = function_exists('sip_org_pay_period_anchor') ? sip_org_pay_period_anchor($pdo, $orgId) : '2024-01-01';
$periodModeLabel = function_exists('sip_period_mode_label') ? sip_period_mode_label($periodMode) : $periodMode;
$periodNav = strtolower(trim((string)($_GET['period_nav'] ?? '')));
if (function_exists('sip_period_window')) {
    $pivot = $periodFrom !== '' ? $periodFrom : date('Y-m-d');
    [$periodFrom, $periodTo] = sip_period_window($pivot, $periodMode, $periodAnchor);
    if ($periodNav === 'prev') {
        [$periodFrom, $periodTo] = sip_period_window(date('Y-m-d', strtotime($periodFrom . ' -1 day')), $periodMode, $periodAnchor);
    } elseif ($periodNav === 'next') {
        [$periodFrom, $periodTo] = sip_period_window(date('Y-m-d', strtotime($periodTo . ' +1 day')), $periodMode, $periodAnchor);
    }
}
$periodLabel = function_exists('sip_period_label')
    ? sip_period_label($periodFrom, $periodTo, $periodMode)
    : ($periodFrom . ' – ' . $periodTo);
$periodOptions = function_exists('sip_list_pay_periods')
    ? sip_list_pay_periods($periodMode, $periodAnchor, $periodFrom !== '' ? $periodFrom : date('Y-m-d'), 26, 6)
    : [];
$periodHaveCur = false;
foreach ($periodOptions as $po) {
    if (($po['start'] ?? '') === $periodFrom && ($po['end'] ?? '') === $periodTo) { $periodHaveCur = true; break; }
}
if (!$periodHaveCur && $periodFrom !== '' && $periodTo !== '') {
    array_unshift($periodOptions, ['start' => $periodFrom, 'end' => $periodTo, 'label' => $periodLabel]);
}

$orgProvQ = $pdo->prepare("SELECT DISTINCT UPPER(TRIM(province)) p FROM sites
    WHERE org_id=? AND UPPER(TRIM(province)) IN ('ON','SK') ORDER BY p");
$orgProvQ->execute([$orgId]);
$orgProvinces = array_values(array_filter(array_map('strval', $orgProvQ->fetchAll(PDO::FETCH_COLUMN))));
if (!$orgProvinces) { $orgProvinces = sh_supported_stat_provinces(); }

$yearStart = ($periodFrom !== '' ? substr($periodFrom, 0, 4) : date('Y')) . '-01-01';
$yearEnd = ($periodTo !== '' ? substr($periodTo, 0, 4) : date('Y')) . '-12-31';
if ($periodFrom !== '' && $periodTo !== '') {
    $calendarHolidays = sh_holidays_for_provinces($pdo, $orgProvinces, $periodFrom, $periodTo);
    $yearHolidays = sh_holidays_for_provinces($pdo, $orgProvinces, $yearStart, $yearEnd);
} else {
    $yearHolidays = sh_holidays_for_provinces($pdo, $orgProvinces, $yearStart, $yearEnd);
    $calendarHolidays = $yearHolidays;
}

$holidayDate = (string)($_GET['holiday_date'] ?? $_POST['holiday_date'] ?? '');
$holidayName = trim((string)($_GET['holiday_name'] ?? $_POST['holiday_name'] ?? ''));
$holidayKey = (string)($_GET['holiday_key'] ?? $_POST['holiday_key'] ?? '');
$siteFilter = (int)($_GET['site_id'] ?? $_POST['site_id'] ?? 0);
$previewPeriod = isset($_GET['preview_period']) || isset($_POST['preview_period']) || $periodNav === 'prev' || $periodNav === 'next';

if ($holidayKey !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})\|(.+)$/', $holidayKey, $km)) {
    $holidayDate = $km[1];
    $holidayName = $km[2];
}
if ($holidayDate === '' && $calendarHolidays) {
    $holidayDate = $calendarHolidays[0]['holiday_date'];
    $holidayName = $calendarHolidays[0]['holiday_name'];
}
if ($holidayDate === '') { $holidayDate = date('Y-m-d'); }
if ($holidayName === '') { $holidayName = 'Public Holiday'; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate)) { exit('Invalid holiday date.'); }
if ($siteFilter) { ps_assert_site($pdo, $siteFilter, $orgId); }

function stat_holiday_provinces_for(PDO $pdo, array $orgProvinces, string $holidayDate, string $holidayName): array {
    $matched = sh_holidays_for_provinces($pdo, $orgProvinces, $holidayDate, $holidayDate);
    $holidayProvinces = [];
    foreach ($matched as $m) {
        if (strcasecmp($m['holiday_name'], $holidayName) === 0 || $holidayName === 'Public Holiday') {
            $holidayProvinces = array_values(array_unique(array_merge($holidayProvinces, $m['provinces'])));
            if ($holidayName === 'Public Holiday') { $holidayName = $m['holiday_name']; }
        }
    }
    if (!$holidayProvinces && $matched) {
        $holidayProvinces = $matched[0]['provinces'];
    }
    if (!$holidayProvinces) {
        $holidayProvinces = $orgProvinces;
    }
    return [$holidayProvinces, $holidayName];
}

[$holidayProvinces, $holidayName] = stat_holiday_provinces_for($pdo, $orgProvinces, $holidayDate, $holidayName);

function stat_preview_row(PDO $pdo, int $orgId, int $userId, int $siteId, string $holidayDate, array $holidayProvinces): ?array {
    $q = $pdo->prepare("SELECT u.id, u.Name, u.hourly_rate, u.active, s.SId, s.nick_name, UPPER(TRIM(s.province)) province,
                               ecs.start_date
                        FROM users u
                        JOIN sites s ON s.SId = ? AND s.org_id = u.org_id
                        LEFT JOIN employee_company_service ecs ON ecs.user_id=u.id AND ecs.site_id=s.SId
                        WHERE u.id=? AND u.org_id=? AND u.active=1");
    $q->execute([$siteId, $userId, $orgId]);
    $person = $q->fetch(PDO::FETCH_ASSOC);
    if (!$person || (!empty($person['start_date']) && $person['start_date'] > $holidayDate)) { return null; }

    $province = sh_normalize_province((string)$person['province']);
    if (!in_array($province, sh_supported_stat_provinces(), true)) { return null; }
    if ($holidayProvinces && !in_array($province, $holidayProvinces, true)) { return null; }

    $calc = sh_compute_stat_pay($pdo, $userId, $siteId, $holidayDate, $province);
    $from = $calc['from'];
    $to = $calc['to'];
    $regular = (float)$calc['wages'];
    $vacation = (float)$calc['vacation'];
    $base = $province === 'ON' ? $regular + $vacation : ($province === 'BC' ? $regular : $regular);
    $amount = (float)$calc['amount'];
    if (!$calc['eligible'] || $amount <= 0) { return null; }

    $hrsQ = $pdo->prepare("SELECT COALESCE(SUM(
            GREATEST(0, TIMESTAMPDIFF(SECOND, a.clock_in, COALESCE(NULLIF(a.clock_out,'0000-00-00 00:00:00'), a.clock_in)))
            - COALESCE((SELECT SUM(ABS(TIMESTAMPDIFF(SECOND, b.break_start, b.break_end))) FROM breaks b WHERE b.shift_id = a.id), 0)
        ), 0) / 3600
        FROM attendance a
        INNER JOIN users u ON u.id=a.user_id AND u.org_id=?
        WHERE a.user_id=? AND a.site_id=? AND DATE(a.clock_in)=?");
    $hrsQ->execute([$orgId, $userId, $siteId, $holidayDate]);
    $holiday_hours = round((float)$hrsQ->fetchColumn(), 2);
    $worked = $holiday_hours > 0;

    $dupQ = $pdo->prepare("SELECT id FROM statutory_pay_items WHERE org_id=? AND user_id=? AND site_id=? AND holiday_date=? LIMIT 1");
    $dupQ->execute([$orgId, $userId, $siteId, $holidayDate]);
    $postedId = $dupQ->fetchColumn();
    $postedStatus = '';
    $finStat = function_exists('sh_finalized_stat_line') ? sh_finalized_stat_line($pdo, $userId, $siteId, $holidayDate) : null;
    if ($finStat) {
        if (!$postedId) {
            $postedId = $finStat['id'];
        }
        $amount = (float)$finStat['amount'];
        $postedStatus = (string)$finStat['status'];
    } elseif ($postedId) {
        $postedStatus = 'posted';
    }
    $rate = function_exists('pe_profile_hourly_rate')
        ? pe_profile_hourly_rate($pdo, $userId, $holidayDate)
        : (float)$person['hourly_rate'];
    if ($rate <= 0) { $rate = (float)$person['hourly_rate']; }

    $workPosted = false;
    $attId = 0;
    $origGross = 0.0;
    $origPaid = false;
    if ($holiday_hours > 0) {
        $attQ = $pdo->prepare("SELECT id FROM attendance WHERE user_id=? AND site_id=? AND DATE(clock_in)=? LIMIT 1");
        $attQ->execute([$userId, $siteId, $holidayDate]);
        $attId = (int)$attQ->fetchColumn();
        $finQ = $pdo->prepare("SELECT id, COALESCE(total_gross,0) g, COALESCE(category,'') cat, COALESCE(payment_status,'') ps
            FROM payroll_finalized WHERE user_id=? AND site_id=? AND work_date=?");
        $finQ->execute([$userId, $siteId, $holidayDate]);
        foreach ($finQ->fetchAll(PDO::FETCH_ASSOC) as $fr) {
            $cat = strtolower((string)$fr['cat']);
            if (strpos($cat, 'holiday work') !== false || strpos($cat, 'statutory work') !== false) {
                $workPosted = true;
            } elseif (strpos($cat, 'statutory') === false) {
                $origGross += (float)$fr['g'];
                if (strtolower(trim((string)$fr['ps'])) === 'paid') { $origPaid = true; }
            }
        }
    }
    $worked_pay = ($workPosted || $origPaid) ? 0.0 : round($holiday_hours * $rate * 1.5, 2);
    if ($holiday_hours > 0 && $origPaid) {
        $change = 'Regular already PAID $'.number_format($origGross, 2).' — will not zero. Review manually.';
    } elseif ($holiday_hours > 0) {
        $change = 'Reg $'.number_format($origGross, 2).' → $0.00; insert 1.5× $'.number_format($worked_pay, 2).' + ADW $'.number_format($amount, 2);
    } else {
        $change = 'No hours on holiday. Insert ADW $'.number_format($amount, 2).' only.';
    }
    if ($postedId) {
        $change = 'Already on the pay ledger. $'.number_format($amount, 2).($postedStatus !== '' ? ', '.$postedStatus : '').'.';
    }
    return $person + [
        'province' => $province,
        'lookback_from'=>$from, 'lookback_to'=>$to, 'regular_wages'=>$regular,
        'vacation_pay'=>$vacation, 'base'=>$base, 'amount'=>$amount,
        'equiv_hours'=>$rate > 0 ? round($amount/$rate, 2) : 0,
        'holiday_hours'=>$holiday_hours, 'worked_pay'=>$worked_pay,
        'work_mult'=>1.5, 'orig_gross'=>$origGross, 'orig_paid'=>$origPaid ? 1 : 0,
        'work_posted'=>$workPosted ? 1 : 0, 'att_id'=>$attId,
        'worked_holiday'=>$worked, 'posted_id'=>$postedId, 'posted_status'=>$postedStatus,
        'hourly_rate'=>$rate, 'change_text'=>$change,
        'formula'=>$calc['formula'],
    ];
}

function stat_candidates(PDO $pdo, int $orgId, array $holidayProvinces, string $holidayDate, int $siteFilter): array {
    $provIn = implode(',', array_fill(0, count($holidayProvinces), '?'));
    $candidateSql = "SELECT DISTINCT f.user_id, f.site_id
                     FROM payroll_finalized f
                     INNER JOIN users u ON u.id=f.user_id AND u.org_id=? AND u.active=1
                     INNER JOIN sites s ON s.SId=f.site_id AND s.org_id=u.org_id AND UPPER(TRIM(s.province)) IN ($provIn)
                     WHERE f.work_date < ? AND f.work_date >= DATE_SUB(?, INTERVAL 84 DAY)";
    $candidateParams = array_merge([$orgId], $holidayProvinces, [$holidayDate, $holidayDate]);
    if ($siteFilter) { $candidateSql .= ' AND f.site_id=?'; $candidateParams[] = $siteFilter; }
    $candidateSql .= ' ORDER BY f.site_id, f.user_id';
    $candidateQ = $pdo->prepare($candidateSql);
    $candidateQ->execute($candidateParams);
    return $candidateQ->fetchAll(PDO::FETCH_ASSOC);
}

function stat_build_preview(PDO $pdo, int $orgId, string $holidayDate, array $holidayProvinces, int $siteFilter): array {
    $preview = [];
    foreach (stat_candidates($pdo, $orgId, $holidayProvinces, $holidayDate, $siteFilter) as $candidate) {
        $row = stat_preview_row($pdo, $orgId, (int)$candidate['user_id'], (int)$candidate['site_id'], $holidayDate, $holidayProvinces);
        if ($row) { $preview[] = $row; }
    }
    return $preview;
}


function stat_zero_regular_shifts(PDO $pdo, int $userId, int $siteId, string $holidayDate): float {
    $st = $pdo->prepare("SELECT id, attendance_id, COALESCE(total_gross,0) g, COALESCE(payment_status,'') ps, COALESCE(category,'') cat
        FROM payroll_finalized WHERE user_id=? AND site_id=? AND work_date=?");
    $st->execute([$userId, $siteId, $holidayDate]);
    $zeroed = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $fr) {
        $cat = strtolower((string)$fr['cat']);
        if (strpos($cat, 'statutory') !== false || strpos($cat, 'holiday work') !== false) {
            continue;
        }
        if (strtolower(trim((string)$fr['ps'])) === 'paid') {
            continue;
        }
        $pdo->prepare("UPDATE payroll_finalized SET
                reg_hours=0, balance_hours=0, overtime_hours=0, travel_hours=0,
                reg_pay=0, bonus_pay=0, bal_pay=0, total_gross=0,
                cpp=0, ei=0, wcb=0, vac=0, income_tax=0, cpp_er=0, ei_er=0, paid_amount=0,
                comments=CONCAT(COALESCE(comments,''), ' | Zeroed on stat approve; replaced by 1.5x holiday hours + average daily wage')
            WHERE id=?")->execute([(int)$fr['id']]);
        $zeroed += (float)$fr['g'];
        if (!empty($fr['attendance_id'])) {
            try {
                $pdo->prepare("UPDATE payroll_balance SET reg_hours=0, balance_hours=0, reg_pay=0, bal_pay=0, total_gross=0, paid_amount=0,
                        comments=CONCAT(COALESCE(comments,''), ' | Zeroed on stat approve')
                    WHERE attendance_id=? AND user_id=?")->execute([(int)$fr['attendance_id'], $userId]);
            } catch (Throwable $e) { /* keep going */ }
        }
    }
    if ($zeroed > 0 && function_exists('pe_refresh_period_stat')) {
        try { pe_refresh_period_stat($pdo, $userId, $siteId, $holidayDate); } catch (Throwable $e) { /* ok */ }
    }
    return $zeroed;
}

function stat_post_row(PDO $pdo, int $orgId, int $userId, int $siteId, string $holidayDate, string $holidayName, array $holidayProvinces, int $byUser): bool {
    $row = stat_preview_row($pdo, $orgId, $userId, $siteId, $holidayDate, $holidayProvinces);
    if (!$row || $row['posted_id'] || sh_stat_line_exists($pdo, $userId, $siteId, $holidayDate)) { return false; }
    $ded = pe_line_for($pdo, $userId, $siteId, $holidayDate, (float)$row['amount']);
    $comments = 'Stat default: '.$row['formula'].'; lookback '.$row['lookback_from'].' to '.$row['lookback_to'].
                '; base $'.number_format($row['base'], 2, '.', '').'. If they worked, hours are also posted at 1.5x (or +0.5x if straight time already exists).';
    $ins = $pdo->prepare("INSERT INTO payroll_finalized
        (attendance_id,category,display_label,user_id,country_code,org_id,site_id,work_date,pay_period_end,
         reg_hours,balance_hours,overtime_hours,travel_hours,hourly_rate,reg_pay,bonus_pay,bal_pay,total_gross,
         cpp,ei,wcb,vac,status,finalized_by,pay_type,comments,income_tax,cpp_er,ei_er)
        VALUES (NULL,'Statutory Holiday',?,?,?,?,?,?,?,?,0,0,0,?,?,0,0,?,?,?,?,?,'pending',?,'Statutory',?,?,?,?)");
    $ins->execute([
        $holidayName.' — Statutory Holiday Pay', $userId, 'CA', $orgId, $siteId, $holidayDate, $holidayDate,
        $row['equiv_hours'], $row['hourly_rate'], $row['amount'], $row['amount'],
        $ded['cpp'], $ded['ei'], $ded['wcb'], $ded['vac'], $byUser, $comments,
        $ded['income_tax'], $ded['cpp_er'], $ded['ei_er'],
    ]);
    $finalizedId = (int)$pdo->lastInsertId();
    $item = $pdo->prepare("INSERT INTO statutory_pay_items
        (org_id,user_id,site_id,holiday_date,holiday_name,province,lookback_start,lookback_end,formula_code,
         eligible_wages,vacation_pay,stat_pay_amount,equivalent_hours,worked_holiday,status,finalized_id,approved_by,approved_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'posted',?,?,NOW())");
    $item->execute([
        $orgId, $userId, $siteId, $holidayDate, $holidayName, $row['province'], $row['lookback_from'], $row['lookback_to'],
        $row['province'] === 'ON' ? 'ON_REG_VAC_DIV20' : 'SK_WAGES_5PCT', $row['regular_wages'], $row['vacation_pay'],
        $row['amount'], $row['equiv_hours'], $row['worked_holiday'] ? 1 : 0, $finalizedId, $byUser,
    ]);

    $holHrs = (float)($row['holiday_hours'] ?? 0);
    $workedPay = (float)($row['worked_pay'] ?? 0);
    if ($holHrs > 0 && empty($row['orig_paid']) && empty($row['work_posted'])) {
        stat_zero_regular_shifts($pdo, $userId, $siteId, $holidayDate);
        if ($workedPay <= 0) {
            $workedPay = round($holHrs * (float)$row['hourly_rate'] * 1.5, 2);
        }
    }
    if ($holHrs > 0 && $workedPay > 0 && empty($row['work_posted']) && empty($row['orig_paid'])) {
        $wded = pe_line_for($pdo, $userId, $siteId, $holidayDate, $workedPay);
        $mult = 1.5;
        $wlabel = $holidayName.' — Hours worked @ 1.5x';
        $wcomments = 'Regular shift on this holiday was zeroed. This line is hours × 1.5 × rate, plus the average-daily-wage line.';
        $wins = $pdo->prepare("INSERT INTO payroll_finalized
            (attendance_id,category,display_label,user_id,country_code,org_id,site_id,work_date,pay_period_end,
             reg_hours,balance_hours,overtime_hours,travel_hours,hourly_rate,reg_pay,bonus_pay,bal_pay,total_gross,
             cpp,ei,wcb,vac,status,finalized_by,pay_type,comments,income_tax,cpp_er,ei_er)
            VALUES (?, 'Holiday Worked', ?,?,?,?,?,?,?,?,0,?,?,?,?,0,0,?,?,?,?,?,'pending',?,'Statutory',?,?,?,?)");
        $otHrs = $mult >= 1.5 ? $holHrs : 0.0;
        $regHrs = $mult >= 1.5 ? 0.0 : $holHrs;
        $wins->execute([
            !empty($row['att_id']) ? (int)$row['att_id'] : null,
            $wlabel, $userId, 'CA', $orgId, $siteId, $holidayDate, $holidayDate,
            $regHrs, $otHrs, 0, $row['hourly_rate'], $workedPay, $workedPay,
            $wded['cpp'], $wded['ei'], $wded['wcb'], $wded['vac'], $byUser, $wcomments,
            $wded['income_tax'], $wded['cpp_er'], $wded['ei_er'],
        ]);
    }
    if (function_exists('pe_refresh_period_stat')) {
        try { pe_refresh_period_stat($pdo, $userId, $siteId, $holidayDate); } catch (Throwable $e) { /* period total is retried on the next save */ }
    }
    return true;
}

$provIn = implode(',', array_fill(0, count($holidayProvinces), '?'));
$sitesSql = "SELECT SId, nick_name, province FROM sites WHERE org_id=? AND UPPER(TRIM(province)) IN ($provIn) ORDER BY nick_name";
$sitesQ = $pdo->prepare($sitesSql);
$sitesQ->execute(array_merge([$orgId], $holidayProvinces));
$sites = $sitesQ->fetchAll(PDO::FETCH_ASSOC);

$preview = stat_build_preview($pdo, $orgId, $holidayDate, $holidayProvinces, $siteFilter);

// Period-wide preview buckets: [holiday_key => ['meta'=>..., 'rows'=>...]]
$periodBuckets = [];
if ($previewPeriod && $periodFrom && $periodTo && $calendarHolidays) {
    foreach ($calendarHolidays as $h) {
        [$hp, $hn] = stat_holiday_provinces_for($pdo, $orgProvinces, $h['holiday_date'], $h['holiday_name']);
        $rows = stat_build_preview($pdo, $orgId, $h['holiday_date'], $hp, $siteFilter);
        $periodBuckets[$h['holiday_date'].'|'.$hn] = [
            'holiday_date' => $h['holiday_date'],
            'holiday_name' => $hn,
            'provinces' => $hp,
            'rows' => $rows,
            'eligible' => count(array_filter($rows, function ($r) { return empty($r['posted_id']); })),
            'already' => count(array_filter($rows, function ($r) { return !empty($r['posted_id']); })),
            'ready_amt' => array_sum(array_map(function ($r) { return empty($r['posted_id']) ? (float)$r['amount'] : 0; }, $rows)),
            'posted_amt' => array_sum(array_map(function ($r) { return !empty($r['posted_id']) ? (float)$r['amount'] : 0; }, $rows)),
        ];
    }
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_selected'])) {
    ps_require_csrf();
    $selected = array_unique((array)($_POST['items'] ?? []));
    $posted = 0; $skipped = 0;
    $pdo->beginTransaction();
    try {
        foreach ($selected as $key) {
            if (!preg_match('/^(\d+):(\d+)$/', (string)$key, $m)) { $skipped++; continue; }
            $userId = (int)$m[1]; $siteId = (int)$m[2];
            ps_assert_user($pdo, $userId, $orgId); ps_assert_site($pdo, $siteId, $orgId);
            if (stat_post_row($pdo, $orgId, $userId, $siteId, $holidayDate, $holidayName, $holidayProvinces, (int)$_SESSION['user_id'])) {
                $posted++;
            } else {
                $skipped++;
            }
        }
        $pdo->commit();
        $message = "$posted statutory pay line(s) posted; $skipped skipped as invalid or already posted.";
        $qs = http_build_query([
            'holiday_date'=>$holidayDate,'holiday_name'=>$holidayName,'site_id'=>$siteFilter,
            'from_date'=>$periodFrom,'to_date'=>$periodTo,'org_id'=>$orgId,'msg'=>$message,
        ]);
        header('Location: generate_stat_shifts.php?'.$qs);
        exit;
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_period_all'])) {
    ps_require_csrf();
    if ($periodFrom === '' || $periodTo === '') {
        $message = 'Set both pay-period start and end dates before posting the whole period.';
    } else {
        $wanted = [];
        foreach ((array)($_POST['period_items'] ?? []) as $key) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2}):(\d+):(\d+)$/', (string)$key, $wm)) {
                $wanted[$wm[1].':'.(int)$wm[2].':'.(int)$wm[3]] = true;
            }
        }
        if (!$wanted) {
            $message = 'Nobody was ticked. Leave people ticked to include them; untick anyone to exclude, then Approve.';
        } else {
        $holidays = sh_holidays_for_provinces($pdo, $orgProvinces, $periodFrom, $periodTo);
        $posted = 0; $skipped = 0; $holidayCount = count($holidays);
        $pdo->beginTransaction();
        try {
            foreach ($holidays as $h) {
                [$hp, $hn] = stat_holiday_provinces_for($pdo, $orgProvinces, $h['holiday_date'], $h['holiday_name']);
                foreach (stat_candidates($pdo, $orgId, $hp, $h['holiday_date'], $siteFilter) as $c) {
                    $uid = (int)$c['user_id']; $sid = (int)$c['site_id'];
                    $wkey = $h['holiday_date'].':'.$uid.':'.$sid;
                    if (empty($wanted[$wkey])) { $skipped++; continue; }
                    ps_assert_user($pdo, $uid, $orgId); ps_assert_site($pdo, $sid, $orgId);
                    if (stat_post_row($pdo, $orgId, $uid, $sid, $h['holiday_date'], $hn, $hp, (int)$_SESSION['user_id'])) {
                        $posted++;
                    } else {
                        $skipped++;
                    }
                }
            }
            $pdo->commit();
            $message = "Period {$periodFrom} → {$periodTo}: {$holidayCount} holiday(s); {$posted} line(s) posted; {$skipped} skipped (\$0, ineligible, or already posted).";
            $qs = http_build_query([
                'from_date'=>$periodFrom,'to_date'=>$periodTo,'site_id'=>$siteFilter,
                'org_id'=>$orgId,'preview_period'=>1,'msg'=>$message,
            ]);
            header('Location: generate_stat_shifts.php?'.$qs);
            exit;
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        }
    }
}

$message = $message ?: (string)($_GET['msg'] ?? '');
$selectedKey = $holidayDate.'|'.$holidayName;
$periodEligibleTotal = 0;
foreach ($periodBuckets as $b) { $periodEligibleTotal += (int)$b['eligible']; }
$periodReadyAmt = 0.0; $periodPostedAmt = 0.0;
foreach ($periodBuckets as $b) {
    $periodReadyAmt += (float)($b['ready_amt'] ?? 0);
    $periodPostedAmt += (float)($b['posted_amt'] ?? 0);
}
$singleReadyAmt = 0.0; $singleAllAmt = 0.0; $singleReadyN = 0;
foreach ($preview as $r) {
    $singleAllAmt += (float)$r['amount'];
    if (empty($r['posted_id'])) { $singleReadyAmt += (float)$r['amount']; $singleReadyN++; }
}
$periodReadyAvg = $periodEligibleTotal > 0 ? $periodReadyAmt / $periodEligibleTotal : 0.0;
$singleReadyAvg = $singleReadyN > 0 ? $singleReadyAmt / $singleReadyN : 0.0;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Statutory Pay Preview</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<style>body{background:#f1f5f9;padding-top:95px;font-family:Inter,Arial}.wrap{max-width:1450px;margin:auto;padding:20px}.small-note{font-size:12px;color:#64748b}.worked{background:#fff7ed}.posted{background:#ecfdf5}.chip{display:inline-block;background:#e2e8f0;border-radius:4px;padding:2px 8px;margin:0 4px 4px 0;font-size:12px}.period-card{border-left:4px solid #2563eb}
.stat-total-bar{display:flex;justify-content:space-between;align-items:center;gap:12px;background:#1e293b;color:#fff;padding:10px 14px;border-radius:8px;font-size:15px;font-weight:700;margin:8px 0}
.stat-total-bar small{font-weight:600}
.stat-total-foot{background:#e2e8f0;font-weight:800}
.stat-hol-top{font-weight:700;color:#0f172a}.tiny-avg{font-size:11px;font-weight:700;color:#334155;margin-top:2px}
.stat-site-head{background:#334155;color:#fff;cursor:pointer}
.stat-site-head td{font-weight:700;padding:8px 10px}
.stat-site-toggle{background:transparent;border:0;color:#fff;font-weight:800;margin-right:6px}
body.stat-flat .stat-site-head{display:none}
body.stat-flat .stat-emp-row{display:table-row !important}
.stat-group-toggle{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;margin-left:10px}

</style></head><body>
<?=function_exists('generateMenu')?generateMenu($pdo):''?>
<div class="wrap">
<?php if (file_exists(__DIR__.'/payroll_help.php')) { require_once __DIR__.'/payroll_help.php'; echo payroll_help_panel('generate_stat_shifts.php'); } ?>
<h2>Statutory Holiday Pay — Preview &amp; Approve</h2>
<p class="small-note">No attendance rows are created. Holiday calendars and pay formulas follow each <strong>work site’s province</strong> (ON / SK). If they <strong>did not work</strong>: insert <strong>average daily wage</strong> only. If they <strong>worked</strong>: on Approve we <strong>zero the regular shift total</strong> and insert <strong>hours × 1.5</strong> plus average daily wage. Already-paid regular lines are not zeroed. Guidance, not legal advice.</p>
<?php if($message):?><div class="alert alert-info"><?=htmlspecialchars($message)?></div><?php endif;?>

<form class="card card-body mb-3 period-card" method="get" id="statPeriodForm">
  <?php if(ps_is_superadmin()):?><input type="hidden" name="org_id" value="<?=(int)$orgId?>"><?php endif;?>
  <h5 class="mb-2">Pay period — all holidays</h5>
  <p class="small-note">Choose the pay period. Every ON/SK holiday in that window is previewed for eligible employees, then post the ticked lines in one confirm. Already-posted and $0 rows are skipped.</p>
  <input type="hidden" name="from_date" id="from_date" value="<?=htmlspecialchars($periodFrom)?>">
  <input type="hidden" name="to_date" id="to_date" value="<?=htmlspecialchars($periodTo)?>">
  <div class="form-row align-items-end">
    <div class="col-md-5">
      <label>Pay period</label>
      <div class="d-flex align-items-center" style="gap:6px;">
        <button type="submit" name="period_nav" value="prev" class="btn btn-outline-secondary" title="Previous pay period">◀</button>
        <select class="form-control font-weight-bold" style="background:#f0fdf4;" id="statPeriodSelect" onchange="var p=this.value.split('|'); if(p.length!==2) return; var f=this.form; var q=new URLSearchParams(new FormData(f)); q.set('from_date', p[0]); q.set('to_date', p[1]); q.set('preview_period','1'); q.delete('period_nav'); window.location.search='?'+q.toString();">
          <?php foreach ($periodOptions as $po):
              $ps = (string)($po['start'] ?? '');
              $pe = (string)($po['end'] ?? '');
              $sel = ($ps === $periodFrom && $pe === $periodTo) ? ' selected' : '';
          ?>
          <option value="<?=htmlspecialchars($ps.'|'.$pe)?>"<?=$sel?>><?=htmlspecialchars((string)($po['label'] ?? ($ps.' – '.$pe)))?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" name="period_nav" value="next" class="btn btn-outline-secondary" title="Next pay period">▶</button>
      </div>
      <div class="small-note mt-1"><?=htmlspecialchars($periodModeLabel)?> · <?=htmlspecialchars($periodFrom)?> → <?=htmlspecialchars($periodTo)?></div>
    </div>
    <div class="col-md-3"><label>Work site</label>
      <select class="form-control" name="site_id">
        <option value="0">All ON/SK sites</option>
        <?php
        $allSitesQ = $pdo->prepare("SELECT SId, nick_name, province FROM sites WHERE org_id=? AND UPPER(TRIM(province)) IN ('ON','SK') ORDER BY nick_name");
        $allSitesQ->execute([$orgId]);
        foreach ($allSitesQ->fetchAll(PDO::FETCH_ASSOC) as $s):
        ?>
        <option value="<?=(int)$s['SId']?>" <?=$siteFilter===(int)$s['SId']?'selected':''?>><?=htmlspecialchars($s['nick_name'].' ['.$s['province'].']')?></option>
        <?php endforeach;?>
      </select>
    </div>
    <div class="col-md-3"><button class="btn btn-primary btn-block" name="preview_period" value="1" id="statPreviewPeriodBtn">Preview all holidays in period</button></div>
  </div>
</form>

<?php if($periodFrom && $periodTo):?>
<div class="card card-body mb-3 py-2">
  <div class="small-note mb-1">Holidays in pay period <?=htmlspecialchars($periodFrom)?> → <?=htmlspecialchars($periodTo)?> for provinces <?=htmlspecialchars(implode(', ', $orgProvinces))?>:</div>
  <?php if(!$calendarHolidays):?>
    <div class="text-muted">No ON/SK statutory holidays fall in this pay period.</div>
  <?php else: foreach($calendarHolidays as $h):?>
    <a class="chip" href="?<?=htmlspecialchars(http_build_query(['holiday_date'=>$h['holiday_date'],'holiday_name'=>$h['holiday_name'],'from_date'=>$periodFrom,'to_date'=>$periodTo,'site_id'=>$siteFilter,'org_id'=>$orgId]))?>"><?=htmlspecialchars($h['holiday_date'].' '.$h['holiday_name'].' ['.implode('/', $h['provinces']).']')?></a>
  <?php endforeach; endif;?>
</div>
<?php endif;?>

<?php if($previewPeriod && $periodFrom && $periodTo):?>
<div class="card card-body mb-4" id="statPeriodPreview">
  <h5>Period preview — <?=htmlspecialchars($periodFrom)?> → <?=htmlspecialchars($periodTo)?></h5>
  <?php if(!$periodBuckets):?>
    <p class="text-muted mb-0">No holidays (or no eligible employees) in this window.</p>
  <?php else:?>
    <div class="stat-total-bar" id="statGrandTop">
      <span><strong class="stat-grand-n"><?=$periodEligibleTotal?></strong> ticked</span>
      <span>Avg daily / stat total <strong class="stat-grand-total">$<?=number_format($periodReadyAmt, 2)?></strong> · mean ADW <strong class="stat-grand-avg">$<?=number_format($periodReadyAvg, 2)?></strong><?php if($periodPostedAmt > 0):?> <small style="font-weight:600;opacity:.85;">· already posted $<?=number_format($periodPostedAmt, 2)?></small><?php endif;?></span>
    </div>
    <p class="small-note mb-2 mt-2">Tick = include. Untick = exclude. Totals follow the ticks.</p>
    <?php if($periodEligibleTotal > 0):?>
    <form method="post" id="statPeriodPostForm" onsubmit="var n=document.querySelectorAll('#statPeriodPostForm input[name=&quot;period_items[]&quot;]:checked').length; if(!n){alert('Nobody is ticked. Untick only the people to exclude.'); return false;} return confirm('Post '+n+' ticked line(s), total '+ (document.querySelector('.stat-grand-total')||{}).textContent +', for <?=htmlspecialchars($periodFrom)?> → <?=htmlspecialchars($periodTo)?>? Unticked people are excluded.');">
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>">
      <input type="hidden" name="from_date" value="<?=htmlspecialchars($periodFrom)?>">
      <input type="hidden" name="to_date" value="<?=htmlspecialchars($periodTo)?>">
      <input type="hidden" name="site_id" value="<?=(int)$siteFilter?>">
      <input type="hidden" name="org_id" value="<?=(int)$orgId?>">
      <div class="mb-2">
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('#statPeriodPostForm input[name=&quot;period_items[]&quot;]').forEach(function(el){el.checked=true;}); if(window.statRefreshTotals) statRefreshTotals();">Tick all</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('#statPeriodPostForm input[name=&quot;period_items[]&quot;]').forEach(function(el){el.checked=false;}); if(window.statRefreshTotals) statRefreshTotals();">Untick all</button>
        <label class="stat-group-toggle"><input type="checkbox" id="statGroupBySite" class="stat-group-by-site" checked onchange="statSetGroupBySite(this.checked)"> Group by site</label>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="statCollapseSites(true)">Collapse sites</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="statCollapseSites(false)">Expand sites</button>
      </div>
    <?php endif;?>
    <?php foreach($periodBuckets as $b):?>
      <h6 class="mt-3"><?=htmlspecialchars($b['holiday_date'].' · '.$b['holiday_name'])?> <span class="badge badge-secondary"><?=htmlspecialchars(implode('/', $b['provinces']))?></span>
        <small class="text-muted"><?=$b['eligible']?> ready · <?=$b['already']?> already posted</small>
        <span class="stat-hol-top"> · Stat pay <strong class="stat-hol-total">$<?=number_format((float)$b['ready_amt'], 2)?></strong> · avg <strong class="stat-hol-avg">$<?=number_format(((int)$b['eligible'] > 0 ? (float)$b['ready_amt'] / (int)$b['eligible'] : 0), 2)?></strong></span></h6>
      <table class="table table-sm table-bordered bg-white stat-hol-table"><thead class="thead-light"><tr><th></th><th>Site</th><th>Employee</th><th>Lookback</th><th>Base</th><th>Avg daily / stat</th><th>Hrs on holiday</th><th>1.5× work $</th><th>Change on approve</th><th>Status</th></tr></thead><tbody>
      <?php if(!$b['rows']):?><tr><td colspan="10" class="text-muted">No eligible employees for this holiday.</td></tr>
      <?php else:
        $grouped = [];
        foreach ($b['rows'] as $r) {
            $sid = (int)$r['SId'];
            if (!isset($grouped[$sid])) {
                $grouped[$sid] = ['name'=>($r['nick_name'] ?? '').' ['.($r['province'] ?? '').']', 'rows'=>[], 'n'=>0, 'amt'=>0.0];
            }
            $grouped[$sid]['rows'][] = $r;
            if (empty($r['posted_id'])) { $grouped[$sid]['n']++; $grouped[$sid]['amt'] += (float)$r['amount']; }
        }
        uasort($grouped, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        foreach ($grouped as $sid => $g):
      ?>
        <tr class="stat-site-head" data-site="<?= (int)$sid ?>">
          <td><input type="checkbox" class="stat-site-tick" data-site="<?= (int)$sid ?>" checked onclick="event.stopPropagation(); statTickSite(this);"></td>
          <td colspan="9">
            <button type="button" class="stat-site-toggle" data-site="<?= (int)$sid ?>" onclick="event.stopPropagation(); statToggleSite(this);">▾</button>
            <?=htmlspecialchars($g['name'])?>
            · <?=$g['n']?> people
            · <span class="stat-site-total">$<?=number_format($g['amt'], 2)?></span>
            · avg <span class="stat-site-avg">$<?=number_format($g['n'] > 0 ? $g['amt'] / $g['n'] : 0, 2)?></span>
          </td>
        </tr>
        <?php foreach ($g['rows'] as $r): ?>
        <tr class="stat-emp-row <?=$r['posted_id']?'posted':($r['worked_holiday']?'worked':'')?>" data-site="<?= (int)$sid ?>" data-stat-amt="<?=htmlspecialchars(number_format((float)$r['amount'], 2, '.', ''))?>" data-stat-ready="<?= empty($r['posted_id']) ? '1' : '0' ?>">
          <td><?php if(!$r['posted_id']):?><input type="checkbox" name="period_items[]" value="<?=htmlspecialchars($b['holiday_date'].':'.$r['id'].':'.$r['SId'])?>" checked><?php endif;?></td>
          <td><?=htmlspecialchars($r['nick_name'].' ['.$r['province'].']')?></td>
          <td><?=htmlspecialchars($r['Name'])?></td>
          <td><?=$r['lookback_from']?> – <?=$r['lookback_to']?></td>
          <td>$<?=number_format($r['base'],2)?></td>
          <td><strong>$<?=number_format($r['amount'],2)?></strong></td>
          <td><?=number_format((float)($r['holiday_hours'] ?? 0), 2)?></td>
          <td><?php $wp=(float)($r['worked_pay'] ?? 0); echo $wp>0 ? '$'.number_format($wp,2) : '—'; ?></td>
          <td class="small-note"><?=htmlspecialchars((string)($r['change_text'] ?? ''))?></td>
          <td><?php if($r['posted_id']):?><span class="badge badge-success">Posted</span><?php if(!empty($r['posted_status'])):?><div class="small-note">$<?=number_format((float)$r['amount'],2)?> · <?=htmlspecialchars((string)$r['posted_status'])?></div><?php endif;?><?php else:?>Ready<?php if($r['worked_holiday']):?> · <span class="text-danger">worked holiday</span><?php endif;?><?php endif;?></td>
        </tr>
        <?php endforeach; endforeach; endif;?>
      </tbody>
      <?php if($b['rows']):?>
      <tfoot><tr class="stat-total-foot"><td colspan="5">Total ticked / avg daily</td><td><span class="stat-hol-total">$<?=number_format((float)$b['ready_amt'], 2)?></span><div class="tiny-avg">avg <span class="stat-hol-avg">$<?=number_format(((int)$b['eligible'] > 0 ? (float)$b['ready_amt'] / (int)$b['eligible'] : 0), 2)?></span></div></td><td></td><td></td><td></td><td></td></tr></tfoot>
      <?php endif;?>
      </table>
    <?php endforeach;?>
    <?php if($periodEligibleTotal > 0):?>
      <div class="stat-total-bar" id="statGrandBottom">
        <span><strong class="stat-grand-n"><?=$periodEligibleTotal?></strong> ticked</span>
        <span>Avg daily / stat total <strong class="stat-grand-total">$<?=number_format($periodReadyAmt, 2)?></strong> · mean ADW <strong class="stat-grand-avg">$<?=number_format($periodReadyAvg, 2)?></strong></span>
      </div>
      <button class="btn btn-success btn-lg mt-2" name="post_period_all" value="1" id="statPostPeriodBtn">Approve &amp; post ticked only</button>
    </form>
    <?php endif;?>
  <?php endif;?>
</div>
<?php endif;?>

<hr class="my-4">
<h5>Single holiday (optional)</h5>
<form class="card card-body mb-3" method="get"><div class="form-row align-items-end">
<?php if(ps_is_superadmin()):?><div class="col-md-1"><label>Org</label><input class="form-control" name="org_id" value="<?=$orgId?>"></div><?php endif;?>
<div class="col-md-3"><label>Province holiday</label>
<select class="form-control" name="holiday_key" onchange="this.form.submit()">
<option value="">— pick from ON/SK calendar —</option>
<?php foreach($yearHolidays as $h):
  $k=$h['holiday_date'].'|'.$h['holiday_name'];?>
<option value="<?=htmlspecialchars($k)?>" <?=$selectedKey===$k?'selected':''?>><?=htmlspecialchars($h['holiday_date'].' · '.$h['holiday_name'].' ['.implode('/', $h['provinces']).']')?></option>
<?php endforeach;?>
</select></div>
<div class="col-md-2"><label>Holiday date</label><input class="form-control" type="date" name="holiday_date" value="<?=htmlspecialchars($holidayDate)?>" required></div>
<div class="col-md-2"><label>Holiday name</label><input class="form-control" name="holiday_name" value="<?=htmlspecialchars($holidayName)?>" required></div>
<div class="col-md-2"><label>Work site</label><select class="form-control" name="site_id"><option value="0">All <?=htmlspecialchars(implode('/', $holidayProvinces))?> sites</option><?php foreach($sites as $s):?><option value="<?=$s['SId']?>" <?=$siteFilter===$s['SId']?'selected':''?>><?=htmlspecialchars($s['nick_name'].' ['.$s['province'].']')?></option><?php endforeach;?></select></div>
<input type="hidden" name="from_date" value="<?=htmlspecialchars($periodFrom)?>"><input type="hidden" name="to_date" value="<?=htmlspecialchars($periodTo)?>">
<div class="col-md-2"><button class="btn btn-outline-primary btn-block">Preview this holiday</button></div></div>
<p class="small-note mb-0 mt-2">Scoping this holiday to province(s): <strong><?=htmlspecialchars(implode(', ', $holidayProvinces))?></strong>.</p>
</form>

<form method="post" id="statSingleForm"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>"><input type="hidden" name="holiday_date" value="<?=htmlspecialchars($holidayDate)?>"><input type="hidden" name="holiday_name" value="<?=htmlspecialchars($holidayName)?>"><input type="hidden" name="site_id" value="<?=$siteFilter?>"><input type="hidden" name="org_id" value="<?=$orgId?>"><input type="hidden" name="from_date" value="<?=htmlspecialchars($periodFrom)?>"><input type="hidden" name="to_date" value="<?=htmlspecialchars($periodTo)?>">
<?php if($preview):?>
<div class="stat-total-bar stat-single-bar mb-2">
  <span>Eligible <strong><?=$singleReadyN?></strong> · list total <strong>$<?=number_format($singleReadyAmt, 2)?></strong> · avg <strong>$<?=number_format($singleReadyAvg, 2)?></strong></span>
  <span>Ticked <strong class="stat-single-n">0</strong> · Stat pay <strong class="stat-single-total">$0.00</strong> · avg <strong class="stat-single-avg">$0.00</strong></span>
</div>
<div class="mb-2">
  <label class="stat-group-toggle"><input type="checkbox" class="stat-group-by-site" checked onchange="statSetGroupBySite(this.checked)"> Group by site</label>
  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="statCollapseSites(true)">Collapse sites</button>
  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="statCollapseSites(false)">Expand sites</button>
</div>
<?php endif;?>
<table class="table table-sm table-bordered bg-white stat-hol-table" id="statSingleTable"><thead class="thead-dark"><tr><th></th><th>Site / Province</th><th>Employee</th><th>Lookback</th><th>Formula base</th><th>Formula</th><th>Avg daily / stat</th><th>ADW hours</th><th>Hrs on holiday</th><th>1.5× work $</th><th>Change on approve</th><th>Qualification / premium note</th></tr></thead><tbody>
<?php
$sg = [];
foreach ($preview as $r) {
    $sid = (int)$r['SId'];
    if (!isset($sg[$sid])) { $sg[$sid] = ['name'=>($r['nick_name'] ?? '').' ['.($r['province'] ?? '').']', 'rows'=>[], 'n'=>0, 'amt'=>0.0]; }
    $sg[$sid]['rows'][] = $r;
    if (empty($r['posted_id'])) { $sg[$sid]['n']++; $sg[$sid]['amt'] += (float)$r['amount']; }
}
uasort($sg, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
foreach ($sg as $sid => $g):
?>
<tr class="stat-site-head" data-site="<?= (int)$sid ?>">
  <td><input type="checkbox" class="stat-site-tick" data-site="<?= (int)$sid ?>" onclick="event.stopPropagation(); statTickSite(this);"></td>
  <td colspan="11"><button type="button" class="stat-site-toggle" data-site="<?= (int)$sid ?>" onclick="event.stopPropagation(); statToggleSite(this);">▾</button> <?=htmlspecialchars($g['name'])?> · <?=$g['n']?> people · <span class="stat-site-total">$<?=number_format($g['amt'], 2)?></span> · avg <span class="stat-site-avg">$<?=number_format($g['n']>0?$g['amt']/$g['n']:0, 2)?></span></td>
</tr>
<?php foreach ($g['rows'] as $r): ?>
<tr class="stat-emp-row <?=$r['posted_id']?'posted':($r['worked_holiday']?'worked':'')?>" data-site="<?= (int)$sid ?>" data-stat-amt="<?=htmlspecialchars(number_format((float)$r['amount'], 2, '.', ''))?>" data-stat-ready="<?= empty($r['posted_id']) ? '1' : '0' ?>"><td><?php if(!$r['posted_id']):?><input type="checkbox" name="items[]" value="<?=$r['id'].':'.$r['SId']?>"><?php else:?><span class="badge badge-success">Posted</span><?php if(!empty($r['posted_status'])):?><div class="small-note">$<?=number_format((float)$r['amount'],2)?> · <?=htmlspecialchars((string)$r['posted_status'])?></div><?php endif;?><?php endif;?></td><td><?=htmlspecialchars($r['nick_name'].' ['.$r['province'].']')?></td><td><?=htmlspecialchars($r['Name'])?></td><td><?=$r['lookback_from']?> – <?=$r['lookback_to']?></td><td>$<?=number_format($r['base'],2)?><br><small>Regular $<?=number_format($r['regular_wages'],2)?> · Vac $<?=number_format($r['vacation_pay'],2)?></small></td><td><?=htmlspecialchars($r['formula'])?></td><td><strong>$<?=number_format($r['amount'],2)?></strong></td><td><?=number_format($r['equiv_hours'],2)?>h</td><td><?=number_format((float)($r['holiday_hours'] ?? 0), 2)?></td><td><?= ((float)($r['worked_pay'] ?? 0) > 0) ? '$'.number_format((float)$r['worked_pay'], 2) : '—' ?></td><td class="small-note"><?=htmlspecialchars((string)($r['change_text'] ?? ''))?></td><td><?=!empty($r['start_date'])?'Service since '.htmlspecialchars($r['start_date']):'Recent finalized earnings used';?><?php if($r['worked_holiday']):?><br><strong class="text-danger">Worked holiday: premium pay must be reviewed separately.</strong><?php endif;?></td></tr>
<?php endforeach; endforeach; ?>
<?php if(!$preview):?><tr><td colspan="12" class="text-center text-muted p-4">No eligible employees with scoped recent earnings for <?=htmlspecialchars(implode('/', $holidayProvinces))?> on this date.</td></tr><?php endif;?></tbody>
<?php if($preview):?><tfoot><tr class="stat-total-foot"><td colspan="6">Total ticked / avg daily</td><td><span class="stat-single-total">$0.00</span><div class="tiny-avg">avg <span class="stat-single-avg">$0.00</span></div></td><td colspan="4"></td></tr></tfoot><?php endif;?>
</table>
<?php if($preview):?>
<div class="stat-total-bar stat-single-bar mb-2">
  <span>Eligible <strong><?=$singleReadyN?></strong> · list total <strong>$<?=number_format($singleReadyAmt, 2)?></strong> · avg <strong>$<?=number_format($singleReadyAvg, 2)?></strong></span>
  <span>Ticked <strong class="stat-single-n">0</strong> · Stat pay <strong class="stat-single-total">$0.00</strong> · avg <strong class="stat-single-avg">$0.00</strong></span>
</div>
<?php endif;?>
<button class="btn btn-success" name="post_selected" value="1" onclick="return confirm('Post selected statutory pay lines to finalized payroll?')">Approve &amp; post selected</button> <span class="small-note">Leave someone unticked to exclude them.</span>
</form></div>
<script>
function statMoney(n){ return '$' + (Math.round(n * 100) / 100).toFixed(2); }
function statAvg(sum, n){ return n > 0 ? statMoney(sum / n) : '$0.00'; }
function statRefreshTotals(){
  var grand = 0, gn = 0;
  document.querySelectorAll('#statPeriodPostForm .stat-hol-table').forEach(function(tbl){
    var sum = 0, n = 0;
    tbl.querySelectorAll('tbody tr[data-stat-ready="1"]').forEach(function(tr){
      var cb = tr.querySelector('input[name="period_items[]"]');
      var amt = parseFloat(tr.getAttribute('data-stat-amt') || '0');
      if (cb && cb.checked) { sum += amt; n++; }
    });
    var wrap = tbl.previousElementSibling;
    if (wrap) {
      wrap.querySelectorAll('.stat-hol-total').forEach(function(el){ el.textContent = statMoney(sum); });
      wrap.querySelectorAll('.stat-hol-avg').forEach(function(el){ el.textContent = statAvg(sum, n); });
    }
    tbl.querySelectorAll('.stat-hol-total').forEach(function(el){ el.textContent = statMoney(sum); });
    tbl.querySelectorAll('.stat-hol-avg').forEach(function(el){ el.textContent = statAvg(sum, n); });
    tbl.querySelectorAll('.stat-site-head').forEach(function(head){
      var sid = head.getAttribute('data-site');
      var sSum = 0, sN = 0;
      tbl.querySelectorAll('.stat-emp-row[data-site="'+sid+'"][data-stat-ready="1"]').forEach(function(tr){
        var cb = tr.querySelector('input[type="checkbox"]');
        var amt = parseFloat(tr.getAttribute('data-stat-amt') || '0');
        if (cb && cb.checked) { sSum += amt; sN++; }
      });
      head.querySelectorAll('.stat-site-total').forEach(function(el){ el.textContent = statMoney(sSum); });
      head.querySelectorAll('.stat-site-avg').forEach(function(el){ el.textContent = statAvg(sSum, sN); });
    });
    grand += sum; gn += n;
  });
  document.querySelectorAll('.stat-grand-total').forEach(function(el){ el.textContent = statMoney(grand); });
  document.querySelectorAll('.stat-grand-avg').forEach(function(el){ el.textContent = statAvg(grand, gn); });
  document.querySelectorAll('.stat-grand-n').forEach(function(el){ el.textContent = String(gn); });
  var sSum = 0, sN = 0;
  var sTbl = document.getElementById('statSingleTable');
  if (sTbl) {
    sTbl.querySelectorAll('tbody tr.stat-emp-row[data-stat-ready="1"]').forEach(function(tr){
      var cb = tr.querySelector('input[name="items[]"]');
      var amt = parseFloat(tr.getAttribute('data-stat-amt') || '0');
      if (cb && cb.checked) { sSum += amt; sN++; }
    });
    sTbl.querySelectorAll('.stat-site-head').forEach(function(head){
      var sid = head.getAttribute('data-site');
      var siteSum = 0, siteN = 0;
      sTbl.querySelectorAll('.stat-emp-row[data-site="'+sid+'"][data-stat-ready="1"]').forEach(function(tr){
        var amt = parseFloat(tr.getAttribute('data-stat-amt') || '0');
        siteSum += amt; siteN++;
      });
      head.querySelectorAll('.stat-site-total').forEach(function(el){ el.textContent = statMoney(siteSum); });
      head.querySelectorAll('.stat-site-avg').forEach(function(el){ el.textContent = statAvg(siteSum, siteN); });
    });
  }
  document.querySelectorAll('.stat-single-total').forEach(function(el){ el.textContent = statMoney(sSum); });
  document.querySelectorAll('.stat-single-avg').forEach(function(el){ el.textContent = statAvg(sSum, sN); });
  document.querySelectorAll('.stat-single-n').forEach(function(el){ el.textContent = String(sN); });
}
document.addEventListener('change', function(e){
  if (e.target && (e.target.name === 'period_items[]' || e.target.name === 'items[]')) statRefreshTotals();
});
function statSetGroupBySite(on){
  document.body.classList.toggle('stat-flat', !on);
  document.querySelectorAll('.stat-group-by-site').forEach(function(box){ box.checked = !!on; });
  try { localStorage.setItem('statGroupBySite', on ? '1' : '0'); } catch (e) {}
}
function statToggleSite(btn){
  var sid = btn.getAttribute('data-site');
  var tbl = btn.closest('table');
  var hide = btn.textContent.trim() === '▾';
  btn.textContent = hide ? '▸' : '▾';
  tbl.querySelectorAll('.stat-emp-row[data-site="'+sid+'"]').forEach(function(tr){ tr.style.display = hide ? 'none' : ''; });
}
function statCollapseSites(hide){
  document.querySelectorAll('.stat-hol-table .stat-site-toggle, #statSingleTable .stat-site-toggle').forEach(function(btn){
    btn.textContent = hide ? '▾' : '▸';
    if ((hide && btn.textContent === '▾') || (!hide && btn.textContent === '▸')) {
      btn.textContent = hide ? '▾' : '▸';
    }
    btn.textContent = hide ? '▸' : '▾';
    var sid = btn.getAttribute('data-site');
    var tbl = btn.closest('table');
    tbl.querySelectorAll('.stat-emp-row[data-site="'+sid+'"]').forEach(function(tr){ tr.style.display = hide ? 'none' : ''; });
  });
}
function statTickSite(cb){
  var sid = cb.getAttribute('data-site');
  var tbl = cb.closest('table');
  tbl.querySelectorAll('.stat-emp-row[data-site="'+sid+'"] input[name="period_items[]"], .stat-emp-row[data-site="'+sid+'"] input[name="items[]"]').forEach(function(el){ el.checked = cb.checked; });
  if (window.statRefreshTotals) statRefreshTotals();
}
document.addEventListener('DOMContentLoaded', function(){
  var pref = '1';
  try { pref = localStorage.getItem('statGroupBySite') || '1'; } catch (e) {}
  statSetGroupBySite(pref !== '0');
  document.querySelectorAll('.stat-site-head').forEach(function(head){
    head.addEventListener('click', function(e){
      if (e.target.closest('input,button')) return;
      var btn = head.querySelector('.stat-site-toggle');
      if (btn) statToggleSite(btn);
    });
  });
  statRefreshTotals();
});
</script>
</body></html>
