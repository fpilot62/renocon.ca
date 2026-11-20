<?php
/**
 * Province statutory holiday helpers (ON / SK first).
 * Eligibility always follows the work site's province — never an org-wide default.
 */
if (!function_exists('sh_supported_stat_provinces')) {
    function sh_supported_stat_provinces(): array {
        return ['BC', 'ON', 'SK'];
    }
}

if (!function_exists('sh_normalize_province')) {
    function sh_normalize_province(?string $raw): string {
        $p = strtoupper(trim((string)$raw));
        $map = [
            'ONTARIO' => 'ON', 'SASK' => 'SK', 'SASKATCHEWAN' => 'SK',
            'ALBERTA' => 'AB', 'BRITISH COLUMBIA' => 'BC', 'QUEBEC' => 'QC',
        ];
        return $map[$p] ?? $p;
    }
}

/**
 * Which employment-standards provinces observe this public_holidays.region code.
 * Legacy broad tags (ALL / ALL_CAN) are NOT treated as ESA lists — use explicit ON/SK rows.
 */
if (!function_exists('sh_provinces_for_region')) {
    function sh_provinces_for_region(string $region): array {
        $r = strtoupper(trim($region));
        if ($r === 'BC') {
            return ['BC'];
        }
        if ($r === 'ON') {
            return ['ON'];
        }
        if ($r === 'SK') {
            return ['SK'];
        }
        if (in_array($r, ['ON_SK', 'SK_ON'], true)) {
            return ['ON', 'SK'];
        }
        return [];
    }
}

if (!function_exists('sh_region_applies_to_province')) {
    function sh_region_applies_to_province(string $region, string $province): bool {
        $prov = sh_normalize_province($province);
        return in_array($prov, sh_provinces_for_region($region), true);
    }
}

/**
 * Ensure duplicate-tracking table exists (idempotent). Does not post pay.
 */
if (!function_exists('sh_ensure_statutory_pay_items')) {
    function sh_ensure_statutory_pay_items(PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS statutory_pay_items (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            user_id INT NOT NULL,
            site_id INT NOT NULL,
            holiday_date DATE NOT NULL,
            holiday_name VARCHAR(100) NOT NULL,
            province VARCHAR(4) NOT NULL,
            lookback_start DATE NOT NULL,
            lookback_end DATE NOT NULL,
            formula_code VARCHAR(40) NOT NULL,
            eligible_wages DECIMAL(12,2) NOT NULL DEFAULT 0,
            vacation_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
            stat_pay_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            equivalent_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
            worked_holiday TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('preview','posted','void') NOT NULL DEFAULT 'preview',
            finalized_id INT NULL,
            approved_by INT NULL,
            approved_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_stat_employee_holiday (org_id,user_id,site_id,holiday_date),
            KEY idx_stat_finalized (finalized_id),
            KEY idx_stat_org_date (org_id,holiday_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

/**
 * Holidays for the given provinces from public_holidays (explicit ON/SK rows only).
 *
 * @param list<string> $provinces
 * @return list<array{holiday_date:string,holiday_name:string,region:string,provinces:list<string>}>
 */
if (!function_exists('sh_holidays_for_provinces')) {
    function sh_holidays_for_provinces(PDO $pdo, array $provinces, ?string $from = null, ?string $to = null): array {
        $want = [];
        foreach ($provinces as $p) {
            $n = sh_normalize_province($p);
            if (in_array($n, sh_supported_stat_provinces(), true)) {
                $want[$n] = true;
            }
        }
        if (!$want) {
            return [];
        }
        $regions = array_keys($want);
        if (count($want) > 1) {
            $regions[] = 'ON_SK';
        }
        $in = implode(',', array_fill(0, count($regions), '?'));
        $sql = "SELECT holiday_date, holiday_name, region
                FROM public_holidays
                WHERE is_active = 1 AND UPPER(TRIM(region)) IN ($in)";
        $params = $regions;
        if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $sql .= ' AND holiday_date >= ?';
            $params[] = $from;
        }
        if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $sql .= ' AND holiday_date <= ?';
            $params[] = $to;
        }
        $sql .= ' ORDER BY holiday_date, holiday_name, region';
        $out = [];
        $seen = [];
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $dbRows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $dbRows = [];
        }
        foreach ($dbRows as $row) {
            $provs = array_values(array_intersect(sh_provinces_for_region((string)$row['region']), array_keys($want)));
            if (!$provs) {
                continue;
            }
            $key = $row['holiday_date'] . '|' . strtolower(trim((string)$row['holiday_name']));
            if (isset($seen[$key])) {
                $out[$seen[$key]]['provinces'] = array_values(array_unique(array_merge($out[$seen[$key]]['provinces'], $provs)));
                continue;
            }
            $seen[$key] = count($out);
            $out[] = [
                'holiday_date' => (string)$row['holiday_date'],
                'holiday_name' => (string)$row['holiday_name'],
                'region' => (string)$row['region'],
                'provinces' => $provs,
            ];
        }
        foreach (array_keys($want) as $prov) {
            foreach (sh_calendar_holidays($prov, $from, $to) as $cal) {
                $key = $cal['holiday_date'] . '|' . strtolower($cal['holiday_name']);
                if (isset($seen[$key])) {
                    $out[$seen[$key]]['provinces'] = array_values(array_unique(array_merge($out[$seen[$key]]['provinces'], [$prov])));
                    continue;
                }
                $seen[$key] = count($out);
                $out[] = $cal;
            }
        }
        return $out;
    }
}

if (!function_exists('sh_lookup_holiday_name')) {
    function sh_lookup_holiday_name(PDO $pdo, string $holidayDate, array $provinces = []): ?string {
        $rows = sh_holidays_for_provinces($pdo, $provinces ?: sh_supported_stat_provinces(), $holidayDate, $holidayDate);
        return $rows[0]['holiday_name'] ?? null;
    }
}

/**
 * Canonical 2026 ON / SK employment-standards holiday seed rows.
 * @return list<array{0:string,1:string,2:string}>
 */
if (!function_exists('sh_seed_rows_2026')) {
    function sh_seed_rows_2026(): array {
        return [
            // Ontario ESA
            ['2026-01-01', "New Year's Day", 'ON'],
            ['2026-02-16', 'Family Day', 'ON'],
            ['2026-04-03', 'Good Friday', 'ON'],
            ['2026-05-18', 'Victoria Day', 'ON'],
            ['2026-07-01', 'Canada Day', 'ON'],
            ['2026-09-07', 'Labour Day', 'ON'],
            ['2026-10-12', 'Thanksgiving Day', 'ON'],
            ['2026-12-25', 'Christmas Day', 'ON'],
            ['2026-12-26', 'Boxing Day', 'ON'],
            // Saskatchewan
            ['2026-01-01', "New Year's Day", 'SK'],
            ['2026-02-16', 'Family Day', 'SK'],
            ['2026-04-03', 'Good Friday', 'SK'],
            ['2026-05-18', 'Victoria Day', 'SK'],
            ['2026-07-01', 'Canada Day', 'SK'],
            ['2026-08-03', 'Saskatchewan Day', 'SK'],
            ['2026-09-07', 'Labour Day', 'SK'],
            ['2026-10-12', 'Thanksgiving Day', 'SK'],
            ['2026-11-11', 'Remembrance Day', 'SK'],
            ['2026-12-25', 'Christmas Day', 'SK'],
        ];
    }
}

if (!function_exists('sh_calendar_rows')) {
    /** @return list<array{0:string,1:string,2:string}> Official dates, not a weekend substitute unless the statute moves the day. */
    function sh_calendar_rows(): array {
        return [
            ['2026-01-01', "New Year's Day", 'BC'], ['2026-02-16', 'Family Day', 'BC'], ['2026-04-03', 'Good Friday', 'BC'],
            ['2026-05-18', 'Victoria Day', 'BC'], ['2026-07-01', 'Canada Day', 'BC'], ['2026-08-03', 'B.C. Day', 'BC'],
            ['2026-09-07', 'Labour Day', 'BC'], ['2026-09-30', 'National Day for Truth and Reconciliation', 'BC'],
            ['2026-10-12', 'Thanksgiving Day', 'BC'], ['2026-11-11', 'Remembrance Day', 'BC'], ['2026-12-25', 'Christmas Day', 'BC'],
            ['2027-01-01', "New Year's Day", 'BC'], ['2027-02-15', 'Family Day', 'BC'], ['2027-03-26', 'Good Friday', 'BC'],
            ['2027-05-24', 'Victoria Day', 'BC'], ['2027-07-01', 'Canada Day', 'BC'], ['2027-08-02', 'B.C. Day', 'BC'],
            ['2027-09-06', 'Labour Day', 'BC'], ['2027-09-30', 'National Day for Truth and Reconciliation', 'BC'],
            ['2027-10-11', 'Thanksgiving Day', 'BC'], ['2027-11-11', 'Remembrance Day', 'BC'], ['2027-12-25', 'Christmas Day', 'BC'],
            ['2026-01-01', "New Year's Day", 'ON'], ['2026-02-16', 'Family Day', 'ON'], ['2026-04-03', 'Good Friday', 'ON'],
            ['2026-05-18', 'Victoria Day', 'ON'], ['2026-07-01', 'Canada Day', 'ON'], ['2026-09-07', 'Labour Day', 'ON'],
            ['2026-10-12', 'Thanksgiving Day', 'ON'], ['2026-12-25', 'Christmas Day', 'ON'], ['2026-12-26', 'Boxing Day', 'ON'],
            ['2027-01-01', "New Year's Day", 'ON'], ['2027-02-15', 'Family Day', 'ON'], ['2027-03-26', 'Good Friday', 'ON'],
            ['2027-05-24', 'Victoria Day', 'ON'], ['2027-07-01', 'Canada Day', 'ON'], ['2027-09-06', 'Labour Day', 'ON'],
            ['2027-10-11', 'Thanksgiving Day', 'ON'], ['2027-12-25', 'Christmas Day', 'ON'], ['2027-12-26', 'Boxing Day', 'ON'],
            ['2026-01-01', "New Year's Day", 'SK'], ['2026-02-16', 'Family Day', 'SK'], ['2026-04-03', 'Good Friday', 'SK'],
            ['2026-05-18', 'Victoria Day', 'SK'], ['2026-07-01', 'Canada Day', 'SK'], ['2026-08-03', 'Saskatchewan Day', 'SK'],
            ['2026-09-07', 'Labour Day', 'SK'], ['2026-10-12', 'Thanksgiving Day', 'SK'], ['2026-11-11', 'Remembrance Day', 'SK'],
            ['2026-12-25', 'Christmas Day', 'SK'],
            ['2027-01-01', "New Year's Day", 'SK'], ['2027-02-15', 'Family Day', 'SK'], ['2027-03-26', 'Good Friday', 'SK'],
            ['2027-05-24', 'Victoria Day', 'SK'], ['2027-07-01', 'Canada Day', 'SK'], ['2027-08-02', 'Saskatchewan Day', 'SK'],
            ['2027-09-06', 'Labour Day', 'SK'], ['2027-10-11', 'Thanksgiving Day', 'SK'], ['2027-11-11', 'Remembrance Day', 'SK'],
            ['2027-12-25', 'Christmas Day', 'SK'],
        ];
    }
}

if (!function_exists('sh_calendar_holidays')) {
    function sh_calendar_holidays(string $province, ?string $from = null, ?string $to = null): array {
        $prov = sh_normalize_province($province);
        $out = [];
        foreach (sh_calendar_rows() as $row) {
            if ($row[2] !== $prov) {
                continue;
            }
            if ($from && $row[0] < $from) {
                continue;
            }
            if ($to && $row[0] > $to) {
                continue;
            }
            $out[] = [
                'holiday_date' => $row[0],
                'holiday_name' => $row[1],
                'region' => $prov,
                'provinces' => [$prov],
            ];
        }
        return $out;
    }
}

if (!function_exists('sh_lookback')) {
    /** @return array{0:string,1:string} */
    function sh_lookback(string $province, string $holidayDate): array {
        $prov = sh_normalize_province($province);
        $day = substr($holidayDate, 0, 10);
        if ($prov === 'BC') {
            return [date('Y-m-d', strtotime($day . ' -30 days')), date('Y-m-d', strtotime($day . ' -1 day'))];
        }
        if ($prov === 'ON') {
            $dow = (int)date('w', strtotime($day));
            $weekStart = date('Y-m-d', strtotime($day . ' -' . $dow . ' days'));
            return [date('Y-m-d', strtotime($weekStart . ' -28 days')), date('Y-m-d', strtotime($weekStart . ' -1 day'))];
        }
        return [date('Y-m-d', strtotime($day . ' -28 days')), date('Y-m-d', strtotime($day . ' -1 day'))];
    }
}

if (!function_exists('sh_money')) {
    function sh_money(float $n): float {
        return round($n + ($n >= 0 ? 1e-9 : -1e-9), 2);
    }
}

if (!function_exists('sh_start_date')) {
    function sh_start_date(PDO $pdo, int $userId, int $siteId): ?string {
        try {
            $st = $pdo->prepare('SELECT start_date FROM employee_company_service WHERE user_id=? AND site_id=? AND start_date IS NOT NULL ORDER BY start_date LIMIT 1');
            $st->execute([$userId, $siteId]);
            $d = $st->fetchColumn();
            if ($d && preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$d)) {
                return substr((string)$d, 0, 10);
            }
        } catch (Throwable $e) {
        }
        return null;
    }
}

if (!function_exists('sh_wage_rows')) {
    function sh_wage_rows(PDO $pdo, int $userId, int $siteId, string $from, string $to): array {
        try {
            $st = $pdo->prepare(
                "SELECT work_date, COALESCE(total_gross,0) gross, COALESCE(vac,0) vac,
                        COALESCE(overtime_hours,0) ot_hours, COALESCE(hourly_rate,0) rate,
                        COALESCE(category,'') category
                   FROM payroll_finalized
                  WHERE user_id=? AND site_id=? AND work_date BETWEEN ? AND ?"
            );
            $st->execute([$userId, $siteId, $from, $to]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $st = $pdo->prepare(
                "SELECT work_date, COALESCE(total_gross,0) gross, 0 vac, 0 ot_hours, 0 rate, '' category
                   FROM payroll_finalized
                  WHERE user_id=? AND site_id=? AND work_date BETWEEN ? AND ?"
            );
            $st->execute([$userId, $siteId, $from, $to]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

if (!function_exists('sh_on_missed_last_shift')) {
    function sh_on_missed_last_shift(PDO $pdo, int $userId, int $siteId, string $holidayDate): bool {
        try {
            $st = $pdo->prepare(
                "SELECT status FROM attendance
                  WHERE user_id=? AND site_id=? AND substr(clock_in,1,10) < ? AND substr(clock_in,1,10) >= ?
                  ORDER BY clock_in DESC LIMIT 1"
            );
            $st->execute([$userId, $siteId, $holidayDate, date('Y-m-d', strtotime($holidayDate . ' -14 days'))]);
            $status = strtolower(trim((string)$st->fetchColumn()));
        } catch (Throwable $e) {
            return false;
        }
        return in_array($status, ['missed', 'absent', 'no-show', 'noshow'], true);
    }
}

if (!function_exists('sh_compute_stat_pay')) {
    /**
     * One holiday for one employee at one site. Does not write.
     * @return array{eligible:bool,amount:float,reason:string,formula:string,from:string,to:string,days:int,wages:float,vacation:float}
     */
    function sh_compute_stat_pay(PDO $pdo, int $userId, int $siteId, string $holidayDate, string $province): array {
        $prov = sh_normalize_province($province);
        [$from, $to] = sh_lookback($prov, $holidayDate);
        $blank = ['eligible' => false, 'amount' => 0.0, 'reason' => '', 'formula' => '', 'from' => $from, 'to' => $to, 'days' => 0, 'wages' => 0.0, 'vacation' => 0.0];
        if (!in_array($prov, ['BC', 'ON', 'SK'], true)) {
            $blank['reason'] = 'province is not BC, ON, or SK';
            return $blank;
        }
        $start = sh_start_date($pdo, $userId, $siteId);
        if ($start !== null && $start > $holidayDate) {
            $blank['reason'] = 'not employed on the holiday';
            return $blank;
        }
        $rows = sh_wage_rows($pdo, $userId, $siteId, $from, $to);
        $regular = 0.0;
        $vacation = 0.0;
        $days = [];
        foreach ($rows as $row) {
            $cat = strtolower((string)$row['category']);
            if (strpos($cat, 'statutory') !== false) {
                continue;
            }
            $ot = max(0.0, (float)$row['ot_hours']) * max(0.0, (float)$row['rate']) * 1.5;
            $gross = max(0.0, (float)$row['gross'] - $ot);
            $regular += $gross;
            $vacation += max(0.0, (float)$row['vac']);
            if ($gross > 0.009 || (float)$row['vac'] > 0.009) {
                $days[substr((string)$row['work_date'], 0, 10)] = true;
            }
        }
        $dayCount = count($days);
        if ($prov === 'BC') {
            $blank['formula'] = 'average day = wages in the 30 days before / days worked';
            $blank['wages'] = sh_money($regular + $vacation);
            $blank['vacation'] = sh_money($vacation);
            $blank['days'] = $dayCount;
            if ($start === null || $start > date('Y-m-d', strtotime($holidayDate . ' -30 days'))) {
                $blank['reason'] = 'employed fewer than 30 calendar days';
                return $blank;
            }
            if ($dayCount < 15) {
                $blank['reason'] = 'worked fewer than 15 of the 30 days';
                return $blank;
            }
            $blank['eligible'] = true;
            $blank['amount'] = sh_money($blank['wages'] / $dayCount);
            $blank['reason'] = 'eligible';
            return $blank;
        }
        if ($prov === 'ON') {
            $blank['formula'] = '(regular wages + vacation pay) / 20';
            $blank['wages'] = sh_money($regular);
            $blank['vacation'] = sh_money($vacation);
            $blank['days'] = $dayCount;
            if (sh_on_missed_last_shift($pdo, $userId, $siteId, $holidayDate)) {
                $blank['reason'] = 'missed the last shift before the holiday';
                return $blank;
            }
            $blank['eligible'] = true;
            $blank['amount'] = sh_money(($regular + $vacation) / 20);
            $blank['reason'] = 'eligible';
            return $blank;
        }
        $blank['formula'] = '5% of wages, overtime excluded';
        $blank['wages'] = sh_money($regular);
        $blank['vacation'] = sh_money($vacation);
        $blank['days'] = $dayCount;
        if ($start !== null && $start > $to) {
            $blank['reason'] = 'not employed in the four weeks before the holiday';
            return $blank;
        }
        $blank['eligible'] = true;
        $blank['amount'] = sh_money(($regular + $vacation) * 0.05);
        $blank['reason'] = 'eligible';
        return $blank;
    }
}

if (!function_exists('sh_stat_line_exists')) {
    function sh_stat_line_exists(PDO $pdo, int $userId, int $siteId, string $holidayDate): bool {
        $st = $pdo->prepare(
            "SELECT id FROM payroll_finalized
              WHERE user_id=? AND site_id=? AND work_date=?
                AND (COALESCE(category,'') LIKE 'Statutory%' OR COALESCE(pay_type,'')='Statutory')
              LIMIT 1"
        );
        $st->execute([$userId, $siteId, $holidayDate]);
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('sh_post_stat_line')) {
    function sh_post_stat_line(PDO $pdo, int $orgId, int $userId, int $siteId, string $holidayDate, string $holidayName, string $province, int $byUser = 0): bool {
        if (sh_stat_line_exists($pdo, $userId, $siteId, $holidayDate)) {
            return false;
        }
        $calc = sh_compute_stat_pay($pdo, $userId, $siteId, $holidayDate, $province);
        if (!$calc['eligible'] || $calc['amount'] < 0.01) {
            return false;
        }
        if (!function_exists('pe_line')) {
            require_once __DIR__ . '/payroll_engine.php';
        }
        $rates = function_exists('pe_rates') ? pe_rates($pdo) : [];
        $vacRate = function_exists('pe_vacation_rate') ? pe_vacation_rate($pdo, $userId, $siteId, $holidayDate) : 0.0;
        $ded = pe_line($calc['amount'], $vacRate, $rates, [
            'pdo' => $pdo,
            'user_id' => $userId,
            'site_id' => $siteId,
            'org_id' => $orgId,
            'work_date' => $holidayDate,
            'pay_date' => $holidayDate,
        ]);
        $hours = 0.0;
        $rate = 0.0;
        try {
            $rst = $pdo->prepare('SELECT hourly_rate FROM users WHERE id=?');
            $rst->execute([$userId]);
            $rate = (float)$rst->fetchColumn();
        } catch (Throwable $e) {
            $rate = 0.0;
        }
        if ($rate > 0) {
            $hours = round($calc['amount'] / $rate, 2);
        }
        $comment = 'Stat pay ' . $province . ': ' . $calc['formula'] . '; ' . $calc['from'] . ' to ' . $calc['to'] . '.';
        $ins = $pdo->prepare(
            "INSERT INTO payroll_finalized
                (user_id, org_id, site_id, work_date, pay_period_end, category, display_label, pay_type,
                 reg_hours, hourly_rate, reg_pay, total_gross, cpp, ei, income_tax, cpp_er, ei_er, wcb, vac,
                 status, finalized_by, comments)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',?,?)"
        );
        $ins->execute([
            $userId, $orgId, $siteId, $holidayDate, $holidayDate, 'Statutory Holiday',
            $holidayName . ' — Statutory Holiday Pay', 'Statutory',
            $hours, $rate, $calc['amount'], $calc['amount'],
            (float)($ded['cpp'] ?? 0), (float)($ded['ei'] ?? 0), (float)($ded['income_tax'] ?? 0),
            (float)($ded['cpp_er'] ?? 0), (float)($ded['ei_er'] ?? 0), (float)($ded['wcb'] ?? 0), (float)($ded['vac'] ?? 0),
            $byUser, $comment,
        ]);
        return true;
    }
}

if (!function_exists('sh_after_finalize')) {
    /** Post stat-pay lines for holidays in the pay period that contains $workDate. Safe to call again. */
    function sh_after_finalize(PDO $pdo, int $userId, int $siteId, string $workDate, int $byUser = 0): int {
        if ($userId <= 0 || $siteId <= 0) {
            return 0;
        }
        $orgId = 0;
        $province = '';
        try {
            $st = $pdo->prepare('SELECT org_id, province FROM sites WHERE SId=? LIMIT 1');
            $st->execute([$siteId]);
            $site = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $orgId = (int)($site['org_id'] ?? 0);
            $province = sh_normalize_province((string)($site['province'] ?? ''));
        } catch (Throwable $e) {
            return 0;
        }
        if (!in_array($province, ['BC', 'ON', 'SK'], true)) {
            return 0;
        }
        $mode = function_exists('pe_period_mode_for_site') ? pe_period_mode_for_site($pdo, $siteId, $orgId) : 'semi_monthly';
        $anchor = function_exists('pe_period_anchor_for_org') ? pe_period_anchor_for_org($pdo, $orgId) : '2024-01-01';
        if (function_exists('pe_period_window')) {
            [$start, $end] = pe_period_window($workDate, $mode, $anchor);
        } else {
            $dom = (int)substr($workDate, 8, 2);
            $start = $dom <= 15 ? substr($workDate, 0, 8) . '01' : substr($workDate, 0, 8) . '16';
            $end = $dom <= 15 ? substr($workDate, 0, 8) . '15' : date('Y-m-t', strtotime($workDate));
        }
        $posted = 0;
        foreach (sh_calendar_holidays($province, $start, $end) as $holiday) {
            if (sh_post_stat_line($pdo, $orgId, $userId, $siteId, $holiday['holiday_date'], $holiday['holiday_name'], $province, $byUser)) {
                $posted++;
            }
        }
        if (function_exists('pe_refresh_period_stat')) {
            try {
                pe_refresh_period_stat($pdo, $userId, $siteId, $workDate);
            } catch (Throwable $e) {
            }
        }
        return $posted;
    }
}
