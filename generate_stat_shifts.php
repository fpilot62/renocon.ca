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

$orgId = stat_selected_org($pdo);
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

function stat_selected_org(PDO $pdo): int {
    if (!ps_is_superadmin()) {
        return ps_selected_org($pdo);
    }
    $raw = $_GET['org_id'] ?? $_POST['org_id'] ?? null;
    if ($raw !== null && $raw !== '' && $raw !== 'all' && (int)$raw > 0) {
        return (int)$raw;
    }
    $session = ps_org_id();
    if ($session > 0) {
        return $session;
    }
    $cookie = $_COOKIE['sip_org_id'] ?? ($_COOKIE['sa_org_id'] ?? '');
    if ($cookie !== '' && $cookie !== 'all' && (int)$cookie > 0) {
        return (int)$cookie;
    }
    return 0;
}

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
    $ineligibleReason = (!$calc['eligible'] || $amount <= 0) ? ((string)($calc['reason'] ?? '') !== '' ? (string)$calc['reason'] : 'Not eligible') : '';

    $holiday_hours = 0.0;
    try {
    $hrsQ = $pdo->prepare("SELECT COALESCE(SUM(
            GREATEST(0, TIMESTAMPDIFF(SECOND, a.clock_in, COALESCE(NULLIF(a.clock_out,'0000-00-00 00:00:00'), a.clock_in)))
            - COALESCE((SELECT SUM(ABS(TIMESTAMPDIFF(SECOND, b.break_start, b.break_end))) FROM breaks b WHERE b.shift_id = a.id), 0)
        ), 0) / 3600
        FROM attendance a
        INNER JOIN users u ON u.id=a.user_id AND u.org_id=?
        WHERE a.user_id=? AND a.site_id=? AND DATE(a.clock_in)=?");
    $hrsQ->execute([$orgId, $userId, $siteId, $holidayDate]);
    $holiday_hours = round((float)$hrsQ->fetchColumn(), 2);
    } catch (Throwable $e) {
        $holiday_hours = 0.0;
    }
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
    $exclusion = function_exists('sh_stat_excluded') ? sh_stat_excluded($pdo, $orgId, $userId, $siteId, $holidayDate) : null;
    $stored = null;
    try {
        $storedQ = $pdo->prepare(
            "SELECT id, total_gross, reg_hours, COALESCE(paid_amount,0) AS paid_amount, LOWER(COALESCE(status,'')) AS pay_status,
                    COALESCE(stat_approval_status,'') AS approval, COALESCE(stat_flag,'') AS stat_flag,
                    COALESCE(stat_calculated_amount, total_gross) AS calculated
               FROM payroll_finalized
              WHERE user_id=? AND site_id=? AND work_date=? AND category='Statutory Holiday'
              ORDER BY id DESC LIMIT 1"
        );
        $storedQ->execute([$userId, $siteId, $holidayDate]);
        $stored = $storedQ->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $stored = null;
    }
    $flag = '';
    if ($stored && trim((string)$stored['stat_flag']) !== '') {
        $flag = trim((string)$stored['stat_flag']);
    } elseif (function_exists('sh_stat_flag_text')) {
        $flag = sh_stat_flag_text($pdo, $userId, $siteId, $holidayDate, $province, null);
        if (function_exists('sh_employment_ended_before') && sh_employment_ended_before($pdo, $userId, $siteId, $orgId, $holidayDate)) {
            $flag = trim('Ended before holiday' . ($flag !== '' ? '; ' . $flag : ''));
        }
    }
    $paid = $stored && ((float)$stored['paid_amount'] > 0.02 || in_array($stored['pay_status'], ['paid', 'remitted'], true));
    $approval = $stored ? (string)$stored['approval'] : '';
    if ($paid) {
        $kind = 'paid';
    } elseif ($stored && $approval === 'approved') {
        $kind = 'approved';
    } elseif ($exclusion) {
        $kind = 'excluded';
    } elseif ($stored) {
        $kind = 'pending';
    } elseif ($ineligibleReason === '' && $amount >= 0.01) {
        $kind = 'candidate';
    } else {
        $kind = 'ineligible';
    }
    if ($kind === 'ineligible' && $ineligibleReason === '') {
        $ineligibleReason = 'Not eligible';
    }
    $calculated = $stored ? (float)$stored['calculated'] : $amount;
    $hoursShown = $stored ? (float)$stored['reg_hours'] : ($rate > 0 ? round($amount / $rate, 2) : 0);
    if ($stored) {
        $amount = (float)$stored['total_gross'];
        $postedId = (int)$stored['id'];
    }
    $hidden = in_array($kind, ['excluded', 'ineligible'], true);
    return $person + [
        'province' => $province,
        'lookback_from'=>$from, 'lookback_to'=>$to, 'regular_wages'=>$regular,
        'vacation_pay'=>$vacation, 'base'=>$base, 'amount'=>$amount,
        'equiv_hours'=>$hoursShown,
        'holiday_hours'=>$holiday_hours, 'worked_pay'=>$worked_pay,
        'work_mult'=>1.5, 'orig_gross'=>$origGross, 'orig_paid'=>$origPaid ? 1 : 0,
        'work_posted'=>$workPosted ? 1 : 0, 'att_id'=>$attId,
        'worked_holiday'=>$worked, 'posted_id'=>$postedId, 'posted_status'=>$postedStatus,
        'hourly_rate'=>$rate, 'change_text'=>$change,
        'formula'=>$calc['formula'],
        'stat_flag'=>$flag, 'kind'=>$kind, 'hidden'=>$hidden ? 1 : 0,
        'exclude_reason'=>$exclusion ? (string)$exclusion['reason'] : '',
        'hide_reason'=>$exclusion ? (string)$exclusion['reason'] : $ineligibleReason,
        'calculated'=>$calculated, 'approval'=>$approval,
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

function stat_period_board(PDO $pdo, int $orgId, array $orgProvinces, string $from, string $to, int $siteFilter): array {
    $rows = [];
    if ($from === '' || $to === '' || !$orgProvinces) {
        return $rows;
    }
    foreach (sh_holidays_for_provinces($pdo, $orgProvinces, $from, $to) as $holiday) {
        [$provinces, $name] = stat_holiday_provinces_for($pdo, $orgProvinces, $holiday['holiday_date'], $holiday['holiday_name']);
        foreach (stat_build_preview($pdo, $orgId, $holiday['holiday_date'], $provinces, $siteFilter) as $row) {
            $row['holiday_date'] = $holiday['holiday_date'];
            $row['holiday_name'] = $name;
            $rows[] = $row;
        }
    }
    usort($rows, static function ($a, $b) {
        $rank = static function ($row) {
            $kind = (string)($row['kind'] ?? '');
            if ($kind === 'excluded') return 5;
            if ($kind === 'ineligible') return 6;
            if ($kind === 'paid') return 4;
            if ($kind === 'approved') return 3;
            if (trim((string)($row['stat_flag'] ?? '')) !== '') return 0;
            if ($kind === 'pending') return 1;
            return 2;
        };
        return [$rank($a), $a['holiday_date'] ?? '', $a['Name'] ?? ''] <=> [$rank($b), $b['holiday_date'] ?? '', $b['Name'] ?? ''];
    });
    return $rows;
}

function stat_redirect_period(int $orgId, string $from, string $to, int $siteFilter, string $message): void {
    $qs = http_build_query([
        'from_date' => $from, 'to_date' => $to, 'site_id' => $siteFilter,
        'org_id' => $orgId, 'preview_period' => 1, 'msg' => $message,
    ]);
    header('Location: generate_stat_shifts.php?' . $qs);
    exit;
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
    if (!$row || $row['posted_id'] || !empty($row['hidden']) || sh_stat_line_exists($pdo, $userId, $siteId, $holidayDate)) { return false; }
    if (function_exists('sh_stat_excluded') && sh_stat_excluded($pdo, $orgId, $userId, $siteId, $holidayDate)) { return false; }
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
    if (function_exists('sh_table_cols')) {
        $statCols = sh_table_cols($pdo, 'payroll_finalized');
        $statSets = [];
        $statParams = [];
        if (isset($statCols['stat_approval_status'])) {
            $statSets[] = "stat_approval_status='pending'";
        }
        if (isset($statCols['stat_calculated_amount'])) {
            $statSets[] = 'stat_calculated_amount=?';
            $statParams[] = round((float)$row['amount'], 2);
        }
        if ($statSets) {
            $statParams[] = $finalizedId;
            $pdo->prepare('UPDATE payroll_finalized SET ' . implode(', ', $statSets) . ' WHERE id=?')->execute($statParams);
        }
    }
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

$message = '';
$actorId = (int)($_SESSION['user_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['approve_line']) || isset($_POST['bulk_approve']) || isset($_POST['exclude_line']))) {
    ps_require_csrf();
    $postFrom = (string)($_POST['from_date'] ?? $periodFrom);
    $postTo = (string)($_POST['to_date'] ?? $periodTo);
    $postSite = (int)($_POST['site_id'] ?? $siteFilter);
    try {
        if (isset($_POST['exclude_line'])) {
            $userId = (int)($_POST['user_id'] ?? 0);
            $siteId = (int)($_POST['site_id_line'] ?? 0);
            $day = (string)($_POST['holiday_date'] ?? '');
            $reason = trim((string)($_POST['exclude_reason'] ?? ''));
            if ($reason === 'other') {
                $reason = trim((string)($_POST['exclude_other'] ?? ''));
            }
            if ($userId <= 0 || $siteId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $reason === '') {
                stat_redirect_period($orgId, $postFrom, $postTo, $postSite, 'Exclude needs a person, a holiday, and a reason.');
            }
            ps_assert_user($pdo, $userId, $orgId);
            ps_assert_site($pdo, $siteId, $orgId);
            sh_record_stat_exclusion($pdo, $orgId, $userId, $siteId, $day, $reason, $actorId, 'Excluded from Stat Holiday Pay');
            sh_drop_unapproved_stat($pdo, $orgId, $userId, $siteId, $day);
            stat_redirect_period($orgId, $postFrom, $postTo, $postSite, 'Excluded. That holiday will not be offered again.');
        }
        $board = stat_period_board($pdo, $orgId, $orgProvinces, $postFrom, $postTo, $postSite);
        if (isset($_POST['approve_line'])) {
            $userId = (int)($_POST['user_id'] ?? 0);
            $siteId = (int)($_POST['site_id_line'] ?? 0);
            $day = (string)($_POST['holiday_date'] ?? '');
            $name = (string)($_POST['holiday_name'] ?? '');
            $amount = ($_POST['amount'] ?? '') === '' ? null : (float)$_POST['amount'];
            $hours = ($_POST['hours'] ?? '') === '' ? null : (float)$_POST['hours'];
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === 'other') {
                $reason = trim((string)($_POST['reason_other'] ?? ''));
            }
            ps_assert_user($pdo, $userId, $orgId);
            ps_assert_site($pdo, $siteId, $orgId);
            $match = null;
            foreach ($board as $row) {
                if ((int)$row['id'] === $userId && (int)$row['SId'] === $siteId && $row['holiday_date'] === $day) {
                    $match = $row;
                    break;
                }
            }
            $province = $match ? (string)$match['province'] : '';
            if (!$match || !in_array($match['kind'], ['candidate', 'pending'], true)) {
                stat_redirect_period($orgId, $postFrom, $postTo, $postSite, 'That line cannot be approved.');
            }
            if (sh_decide_stat_line($pdo, $orgId, $userId, $siteId, $day, $name, $province, $actorId, $amount, $hours, $reason)) {
                stat_redirect_period($orgId, $postFrom, $postTo, $postSite, 'Approved. It can be paid on the next pay run. It is not paid yet.');
            }
            stat_redirect_period($orgId, $postFrom, $postTo, $postSite, 'Could not approve. If the amount differs from the calculated amount, pick a reason.');
        }
        $n = 0;
        $total = 0.0;
        $skippedFlag = 0;
        foreach ($board as $row) {
            if (!in_array($row['kind'], ['candidate', 'pending'], true)) {
                continue;
            }
            if (trim((string)$row['stat_flag']) !== '') {
                $skippedFlag++;
                continue;
            }
            ps_assert_user($pdo, (int)$row['id'], $orgId);
            ps_assert_site($pdo, (int)$row['SId'], $orgId);
            if (sh_decide_stat_line($pdo, $orgId, (int)$row['id'], (int)$row['SId'], (string)$row['holiday_date'], (string)$row['holiday_name'], (string)$row['province'], $actorId, null, null, '')) {
                $n++;
                $total += (float)$row['calculated'];
            }
        }
        stat_redirect_period($orgId, $postFrom, $postTo, $postSite, 'Approved ' . $n . ' unflagged line(s), $' . number_format($total, 2) . '. ' . $skippedFlag . ' flagged line(s) still need their own approval. Nothing was marked paid.');
    } catch (Throwable $e) {
        $message = 'Stat holiday action failed: ' . $e->getMessage();
    }
}

$message = $message ?: (string)($_GET['msg'] ?? '');
$board = ($periodFrom !== '' && $periodTo !== '') ? stat_period_board($pdo, $orgId, $orgProvinces, $periodFrom, $periodTo, $siteFilter) : [];
$bulkN = 0;
$bulkAmt = 0.0;
$hiddenN = 0;
foreach ($board as $boardRow) {
    if (!empty($boardRow['hidden'])) {
        $hiddenN++;
        continue;
    }
    if (in_array($boardRow['kind'], ['candidate', 'pending'], true) && trim((string)$boardRow['stat_flag']) === '') {
        $bulkN++;
        $bulkAmt += (float)$boardRow['calculated'];
    }
}
$excludeReasons = ['Removed: no shift after the holiday', 'quit', 'missed shift without reasonable cause', 'other'];
$editReasons = ['quit', 'on vacation/leave - pay on return', 'missed shift without reasonable cause', 'other'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Statutory Pay Preview</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<style>body{background:#f1f5f9;padding-top:95px;font-family:Inter,Arial}.wrap{max-width:1450px;margin:auto;padding:20px}.small-note{font-size:12px;color:#64748b}.worked{background:#fff7ed}.posted,.posted td{background:#fff3b0 !important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
tr.stat-flagged td{background:#fdba74 !important}
tr.stat-pending td{background:#fff3b0 !important}
tr.stat-approved td{background:#dcfce7 !important}
tr.stat-paid td{background:#e5e7eb !important}
tr.stat-excluded td{background:#fee2e2 !important}
tr.stat-ineligible td{background:#f8fafc !important}
tr.stat-hidden{display:none}
body.stat-show-hidden tr.stat-hidden{display:table-row}
tr.stat-worked td:first-child{box-shadow:inset 4px 0 0 #2563eb}
.badge-worked{background:#dbeafe;color:#1e3a8a;border-radius:4px;padding:1px 6px;font-size:11px;font-weight:700}
.stat-legend{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 12px}
.stat-legend span{border-radius:4px;padding:3px 8px;font-size:12px;font-weight:700}
.stat-legend .stat-flagged{background:#fdba74}
.stat-legend .stat-pending{background:#fff3b0}
.stat-legend .stat-approved{background:#dcfce7}
.stat-legend .stat-paid{background:#e5e7eb}
.stat-legend .stat-excluded{background:#fee2e2}
.stat-legend .stat-worked-key{background:#dbeafe;color:#1e3a8a}.chip{display:inline-block;background:#e2e8f0;border-radius:4px;padding:2px 8px;margin:0 4px 4px 0;font-size:12px}.period-card{border-left:4px solid #2563eb}
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
<h2>Statutory Holiday Pay</h2>
<p class="small-note">One list for the pay period. Approving a calculated line creates it as approved and does not pay it. Flagged lines need their own approval. Excluding someone records the reason and they are not offered again. People who worked the holiday also have a blue 1.5× premium, separate from the stat pay.</p>
<?php if($message):?><div class="alert alert-info"><?=htmlspecialchars($message)?></div><?php endif;?>

<form class="card card-body mb-3 period-card" method="get" id="statPeriodForm">
  <?php if(!ps_is_superadmin()):?><input type="hidden" name="org_id" value="<?=(int)$orgId?>"><?php endif;?>
  <h5 class="mb-2">Pay period — all holidays</h5>
  <p class="small-note">The organization follows the top bar. Eligible and flagged people are listed. Excluded and not-eligible people stay hidden until you ask for them.</p>
  <?php if (ps_is_superadmin()):
      $statOrgs = [];
      try { $statOrgs = $pdo->query('SELECT id, org_name FROM organizations ORDER BY org_name')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $statOrgs = []; }
  ?>
  <div class="form-row mb-2">
    <div class="col-md-4">
      <label>Organization</label>
      <select class="form-control" name="org_id" onchange="document.cookie='sip_org_id='+encodeURIComponent(this.value)+';path=/;max-age='+(86400*120)+';samesite=lax'; this.form.submit();">
        <?php foreach ($statOrgs as $o): ?>
        <option value="<?= (int)$o['id'] ?>" <?= (int)$orgId === (int)$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$o['org_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <?php endif; ?>
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
<div class="stat-legend" aria-label="Status colors">
  <span class="stat-flagged">Flagged, needs review</span>
  <span class="stat-pending">Needs approval</span>
  <span class="stat-approved">Approved, not paid</span>
  <span class="stat-paid">Paid</span>
  <span class="stat-excluded">Excluded</span>
  <span class="stat-worked-key">Worked the holiday, 1.5×</span>
</div>
<label class="stat-group-toggle"><input type="checkbox" id="statShowHidden" onchange="document.body.classList.toggle('stat-show-hidden', this.checked)"> Show excluded / not eligible<?php if($hiddenN):?> (<?= (int)$hiddenN ?>)<?php endif; ?></label>
<?php if($bulkN > 0):?>
<form method="post" class="d-inline" onsubmit="return confirm('Approve <?= (int)$bulkN ?> unflagged line(s), total $<?= number_format($bulkAmt, 2) ?>? Flagged lines stay for individual approval. Nothing is marked paid.');">
  <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>">
  <input type="hidden" name="from_date" value="<?=htmlspecialchars($periodFrom)?>">
  <input type="hidden" name="to_date" value="<?=htmlspecialchars($periodTo)?>">
  <input type="hidden" name="site_id" value="<?= (int)$siteFilter ?>">
  <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
  <button class="btn btn-success mb-2" name="bulk_approve" value="1">Approve all unflagged</button>
</form>
<?php endif; ?>
<table class="table table-sm table-bordered bg-white stat-hol-table" id="statBoard">
<thead class="thead-light"><tr><th>Holiday</th><th>Employee</th><th>Site</th><th>Flag / reason</th><th>Calculated</th><th>Amount</th><th>Hours</th><th>On the holiday</th><th>Reason if changed</th><th></th></tr></thead>
<tbody>
<?php if(!$board):?><tr><td colspan="10" class="text-muted">No statutory holidays in this pay period.</td></tr><?php endif; ?>
<?php foreach ($board as $r):
    $kind = (string)$r['kind'];
    $flag = trim((string)$r['stat_flag']);
    $cls = 'stat-emp-row';
    if ($kind === 'excluded') $cls .= ' stat-excluded stat-hidden';
    elseif ($kind === 'ineligible') $cls .= ' stat-ineligible stat-hidden';
    elseif ($flag !== '' && in_array($kind, ['candidate','pending'], true)) $cls .= ' stat-flagged posted';
    elseif ($kind === 'pending') $cls .= ' stat-pending posted';
    elseif ($kind === 'approved') $cls .= ' stat-approved';
    elseif ($kind === 'paid') $cls .= ' stat-paid';
    else $cls .= ' stat-pending posted';
    if (!empty($r['worked_holiday'])) $cls .= ' stat-worked';
    $editable = in_array($kind, ['candidate','pending'], true);
    $reasonText = $kind === 'excluded' ? (string)$r['exclude_reason'] : ($kind === 'ineligible' ? (string)$r['hide_reason'] : $flag);
?>
<tr class="<?= $cls ?>" data-stat-amt="<?= htmlspecialchars(number_format((float)$r['amount'], 2, '.', '')) ?>" data-stat-ready="<?= $editable && $flag === '' ? '1' : '0' ?>">
  <td><?= htmlspecialchars($r['holiday_date'].' '.$r['holiday_name']) ?></td>
  <td><?= htmlspecialchars((string)$r['Name']) ?></td>
  <td><?= htmlspecialchars((string)$r['nick_name'].' ['.$r['province'].']') ?></td>
  <td><?= htmlspecialchars($reasonText) ?></td>
  <td>$<?= number_format((float)$r['calculated'], 2) ?></td>
  <?php if ($editable): ?>
  <td colspan="5" style="padding:0;">
    <form method="post" class="form-inline" style="display:flex;gap:6px;align-items:center;padding:6px;flex-wrap:wrap;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ps_csrf_token()) ?>">
      <input type="hidden" name="from_date" value="<?= htmlspecialchars($periodFrom) ?>">
      <input type="hidden" name="to_date" value="<?= htmlspecialchars($periodTo) ?>">
      <input type="hidden" name="site_id" value="<?= (int)$siteFilter ?>">
      <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
      <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
      <input type="hidden" name="site_id_line" value="<?= (int)$r['SId'] ?>">
      <input type="hidden" name="holiday_date" value="<?= htmlspecialchars($r['holiday_date']) ?>">
      <input type="hidden" name="holiday_name" value="<?= htmlspecialchars($r['holiday_name']) ?>">
      <input class="form-control form-control-sm" style="width:90px;" name="amount" value="<?= htmlspecialchars(number_format((float)$r['amount'], 2, '.', '')) ?>">
      <input class="form-control form-control-sm" style="width:70px;" name="hours" value="<?= htmlspecialchars(number_format((float)$r['equiv_hours'], 2, '.', '')) ?>">
      <span class="stat-pay-show"><?php if (!empty($r['worked_holiday'])): ?><span class="badge-worked">Worked 1.5× $<?= number_format((float)$r['worked_pay'], 2) ?></span><?php else: ?>—<?php endif; ?></span>
      <select class="form-control form-control-sm" name="reason" style="width:220px;">
        <option value="">Amount unchanged</option>
        <?php foreach ($editReasons as $reason): ?><option><?= htmlspecialchars($reason) ?></option><?php endforeach; ?>
      </select>
      <input class="form-control form-control-sm" name="reason_other" placeholder="Reason if Other" style="width:140px;">
      <button class="btn btn-sm btn-success" name="approve_line" value="1">Approve</button>
      <select class="form-control form-control-sm" name="exclude_reason" style="width:220px;">
        <option value="">Exclude reason</option>
        <?php foreach ($excludeReasons as $reason): ?><option><?= htmlspecialchars($reason) ?></option><?php endforeach; ?>
      </select>
      <input class="form-control form-control-sm" name="exclude_other" placeholder="Reason if Other" style="width:140px;">
      <button class="btn btn-sm btn-outline-danger" name="exclude_line" value="1">Exclude</button>
    </form>
  </td>
  <?php else: ?>
  <td>$<?= number_format((float)$r['amount'], 2) ?></td>
  <td><?= number_format((float)$r['equiv_hours'], 2) ?></td>
  <td><?php if (!empty($r['worked_holiday'])): ?><span class="badge-worked">Worked 1.5× $<?= number_format((float)$r['worked_pay'], 2) ?></span><?php else: ?>—<?php endif; ?></td>
  <td></td>
  <td><?php if ($kind === 'approved'): ?>Approved, not paid<?php elseif ($kind === 'paid'): ?>Paid<?php elseif ($kind === 'excluded'): ?>Excluded<?php else: ?>Not eligible<?php endif; ?></td>
  <?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<script>
function statMoney(n){ return '$' + (Math.round(n * 100) / 100).toFixed(2); }
function statReveal(el, n){
  var box = el && el.closest ? el.closest('.stat-pay-show') : null;
  if (box) box.style.display = n > 0.009 ? '' : 'none';
}
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
      wrap.querySelectorAll('.stat-hol-total').forEach(function(el){ el.textContent = statMoney(sum); statReveal(el, sum); });
      wrap.querySelectorAll('.stat-hol-avg').forEach(function(el){ el.textContent = statAvg(sum, n); });
    }
    tbl.querySelectorAll('.stat-hol-total').forEach(function(el){ el.textContent = statMoney(sum); statReveal(el, sum); });
    tbl.querySelectorAll('.stat-hol-avg').forEach(function(el){ el.textContent = statAvg(sum, n); });
    tbl.querySelectorAll('.stat-site-head').forEach(function(head){
      var sid = head.getAttribute('data-site');
      var sSum = 0, sN = 0;
      tbl.querySelectorAll('.stat-emp-row[data-site="'+sid+'"][data-stat-ready="1"]').forEach(function(tr){
        var cb = tr.querySelector('input[type="checkbox"]');
        var amt = parseFloat(tr.getAttribute('data-stat-amt') || '0');
        if (cb && cb.checked) { sSum += amt; sN++; }
      });
      head.querySelectorAll('.stat-site-total').forEach(function(el){ el.textContent = statMoney(sSum); statReveal(el, sSum); });
      head.querySelectorAll('.stat-site-avg').forEach(function(el){ el.textContent = statAvg(sSum, sN); });
    });
    grand += sum; gn += n;
  });
  document.querySelectorAll('.stat-grand-total').forEach(function(el){ el.textContent = statMoney(grand); statReveal(el, grand); });
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
      head.querySelectorAll('.stat-site-total').forEach(function(el){ el.textContent = statMoney(siteSum); statReveal(el, siteSum); });
      head.querySelectorAll('.stat-site-avg').forEach(function(el){ el.textContent = statAvg(siteSum, siteN); });
    });
  }
  document.querySelectorAll('.stat-single-total').forEach(function(el){ el.textContent = statMoney(sSum); statReveal(el, sSum); });
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
