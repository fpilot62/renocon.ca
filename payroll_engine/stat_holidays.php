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

if (!function_exists('sh_table_cols')) {
    function sh_table_cols(PDO $pdo, string $table): array {
        static $cache = [];
        $key = spl_object_id($pdo) . '|' . $table;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $out = [];
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $q = $pdo->query('PRAGMA table_info(' . $table . ')');
                if ($q) {
                    foreach ($q as $col) {
                        $out[strtolower((string)$col['name'])] = true;
                    }
                }
            } else {
                $st = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $st->execute([$table]);
                foreach ($st as $col) {
                    $out[strtolower((string)$col['COLUMN_NAME'])] = true;
                }
            }
        } catch (Throwable $e) {
        }
        return $cache[$key] = $out;
    }
}

if (!function_exists('sh_on_after_days')) {
    function sh_on_after_days(): int {
        $n = (int)(getenv('SH_ON_AFTER_DAYS') ?: 28);
        return $n > 0 ? $n : 28;
    }
}

if (!function_exists('sh_date_only')) {
    function sh_date_only($value): ?string {
        $value = substr(trim((string)$value), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && $value !== '0000-00-00' ? $value : null;
    }
}

if (!function_exists('sh_employment_end')) {
    /** Earliest known end: users end date, service end date, or an ROE last day paid. */
    function sh_employment_end(PDO $pdo, int $userId, int $siteId, int $orgId = 0): ?string {
        $dates = [];
        $userCols = sh_table_cols($pdo, 'users');
        foreach (['enddate', 'end_date', 'termination_date'] as $col) {
            if (!isset($userCols[$col])) {
                continue;
            }
            try {
                $st = $pdo->prepare("SELECT `$col` FROM users WHERE id=? LIMIT 1");
                $st->execute([$userId]);
                $d = sh_date_only($st->fetchColumn());
                if ($d) {
                    $dates[] = $d;
                }
                if ($orgId <= 0 && isset($userCols['org_id'])) {
                    $st = $pdo->prepare('SELECT org_id FROM users WHERE id=? LIMIT 1');
                    $st->execute([$userId]);
                    $orgId = (int)$st->fetchColumn();
                }
            } catch (Throwable $e) {
            }
            break;
        }
        $svcCols = sh_table_cols($pdo, 'employee_company_service');
        foreach (['end_date', 'enddate'] as $col) {
            if (!isset($svcCols[$col])) {
                continue;
            }
            try {
                $st = $pdo->prepare("SELECT `$col` FROM employee_company_service WHERE user_id=? AND site_id=? AND `$col` IS NOT NULL ORDER BY `$col` LIMIT 1");
                $st->execute([$userId, $siteId]);
                $d = sh_date_only($st->fetchColumn());
                if ($d) {
                    $dates[] = $d;
                }
            } catch (Throwable $e) {
            }
        }
        if (isset(sh_table_cols($pdo, 'roe_records')['last_day_paid'])) {
            try {
                $sql = "SELECT MIN(last_day_paid) FROM roe_records WHERE user_id=? AND last_day_paid IS NOT NULL AND COALESCE(status,'') NOT IN ('void','cancelled')";
                $params = [$userId];
                if ($orgId > 0 && isset(sh_table_cols($pdo, 'roe_records')['org_id'])) {
                    $sql .= ' AND org_id=?';
                    $params[] = $orgId;
                }
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $d = sh_date_only($st->fetchColumn());
                if ($d) {
                    $dates[] = $d;
                }
            } catch (Throwable $e) {
            }
        }
        if (!$dates) {
            return null;
        }
        sort($dates);
        return $dates[0];
    }
}

if (!function_exists('sh_employment_ended_before')) {
    function sh_employment_ended_before(PDO $pdo, int $userId, int $siteId, int $orgId, string $holidayDate): bool {
        $end = sh_employment_end($pdo, $userId, $siteId, $orgId);
        return $end !== null && $end < substr($holidayDate, 0, 10);
    }
}

if (!function_exists('sh_missed_status')) {
    function sh_missed_status(string $status): bool {
        return in_array(strtolower(trim($status)), ['missed', 'absent', 'no-show', 'noshow'], true);
    }
}

if (!function_exists('sh_on_review_flags')) {
    /**
     * Ontario last-and-first questions. Empty means the line can be posted.
     * no_shift_after is raised only after the watch window has closed.
     *
     * @return list<string>
     */
    function sh_on_review_flags(PDO $pdo, int $userId, int $siteId, string $holidayDate, ?string $asOf = null, ?int $afterDays = null): array {
        $holiday = substr($holidayDate, 0, 10);
        $asOf = $asOf ?: date('Y-m-d');
        $afterDays = $afterDays ?? sh_on_after_days();
        $afterEnd = date('Y-m-d', strtotime($holiday . ' +' . $afterDays . ' days'));
        $flags = [];
        if (sh_on_missed_last_shift($pdo, $userId, $siteId, $holiday)) {
            $flags[] = 'missed_before';
        }
        try {
            $st = $pdo->prepare(
                "SELECT status FROM attendance
                  WHERE user_id=? AND site_id=? AND substr(clock_in,1,10) > ? AND substr(clock_in,1,10) <= ?
                  ORDER BY clock_in ASC LIMIT 1"
            );
            $st->execute([$userId, $siteId, $holiday, $afterEnd]);
            $status = $st->fetchColumn();
            if ($status !== false && sh_missed_status((string)$status)) {
                $flags[] = 'missed_after';
            }
        } catch (Throwable $e) {
        }
        if ($asOf >= $afterEnd) {
            $seen = false;
            try {
                $st = $pdo->prepare(
                    "SELECT 1 FROM attendance
                      WHERE user_id=? AND site_id=? AND substr(clock_in,1,10) > ? AND substr(clock_in,1,10) <= ?
                      LIMIT 1"
                );
                $st->execute([$userId, $siteId, $holiday, $afterEnd]);
                $seen = (bool)$st->fetchColumn();
            } catch (Throwable $e) {
            }
            if (!$seen) {
                try {
                    $st = $pdo->prepare(
                        "SELECT 1 FROM payroll_finalized
                          WHERE user_id=? AND site_id=? AND work_date > ? AND work_date <= ?
                            AND COALESCE(category,'') NOT LIKE 'Statutory%'
                          LIMIT 1"
                    );
                    $st->execute([$userId, $siteId, $holiday, $afterEnd]);
                    $seen = (bool)$st->fetchColumn();
                } catch (Throwable $e) {
                }
            }
            if (!$seen) {
                $flags[] = 'no_shift_after';
            }
        }
        return array_values(array_unique($flags));
    }
}

if (!function_exists('sh_ensure_holiday_review')) {
    function sh_ensure_holiday_review(PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS stat_holiday_review (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                org_id INT NOT NULL, user_id INT NOT NULL, site_id INT NOT NULL,
                holiday_date TEXT NOT NULL, holiday_name TEXT, province TEXT,
                amount REAL NOT NULL DEFAULT 0, flags TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending', decision TEXT,
                decided_by INT, decided_at TEXT, note TEXT, created_at TEXT,
                UNIQUE (org_id, user_id, site_id, holiday_date))");
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS stat_holiday_review (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL, user_id INT NOT NULL, site_id INT NOT NULL,
            holiday_date DATE NOT NULL, holiday_name VARCHAR(120) NULL, province VARCHAR(8) NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0, flags VARCHAR(80) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending', decision VARCHAR(20) NULL,
            decided_by INT NULL, decided_at DATETIME NULL, note VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_stat_review (org_id, user_id, site_id, holiday_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('sh_review_row')) {
    function sh_review_row(PDO $pdo, int $orgId, int $userId, int $siteId, string $holidayDate): ?array {
        try {
            $st = $pdo->prepare('SELECT * FROM stat_holiday_review WHERE org_id=? AND user_id=? AND site_id=? AND holiday_date=? LIMIT 1');
            $st->execute([$orgId, $userId, $siteId, substr($holidayDate, 0, 10)]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('sh_queue_review')) {
    function sh_queue_review(PDO $pdo, int $orgId, int $userId, int $siteId, string $holidayDate, string $holidayName, string $province, float $amount, array $flags): void {
        sh_ensure_holiday_review($pdo);
        $existing = sh_review_row($pdo, $orgId, $userId, $siteId, $holidayDate);
        $flagText = implode(',', $flags);
        if ($existing && ($existing['status'] ?? '') !== 'pending') {
            return;
        }
        if ($existing) {
            $pdo->prepare('UPDATE stat_holiday_review SET amount=?, flags=?, holiday_name=?, province=? WHERE id=?')
                ->execute([round($amount, 2), $flagText, $holidayName, $province, (int)$existing['id']]);
            return;
        }
        $pdo->prepare('INSERT INTO stat_holiday_review (org_id, user_id, site_id, holiday_date, holiday_name, province, amount, flags, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$orgId, $userId, $siteId, substr($holidayDate, 0, 10), $holidayName, $province, round($amount, 2), $flagText, 'pending', date('Y-m-d H:i:s')]);
    }
}

if (!function_exists('sh_should_hold_stat')) {
    /** True when a payable statutory line must not be written yet. */
    function sh_should_hold_stat(PDO $pdo, int $orgId, int $userId, int $siteId, string $holidayDate, string $holidayName, string $province, float $amount, ?string $asOf = null, bool $queue = true): bool {
        if (sh_normalize_province($province) !== 'ON') {
            return false;
        }
        $flags = sh_on_review_flags($pdo, $userId, $siteId, $holidayDate, $asOf);
        $row = sh_review_row($pdo, $orgId, $userId, $siteId, $holidayDate);
        if (!$flags) {
            if ($row && ($row['status'] ?? '') === 'pending' && strpos((string)$row['flags'], 'no_shift_after') !== false && strpos((string)$row['flags'], 'missed') === false) {
                $pdo->prepare("UPDATE stat_holiday_review SET status='employed', decision='employed', decided_at=? WHERE id=?")
                    ->execute([date('Y-m-d H:i:s'), (int)$row['id']]);
            }
            return false;
        }
        $status = (string)($row['status'] ?? '');
        if (in_array($status, ['employed', 'reasonable', 'leave_pay'], true)) {
            return false;
        }
        if (in_array($status, ['quit', 'no_cause', 'deny'], true)) {
            return true;
        }
        if ($queue) {
            sh_queue_review($pdo, $orgId, $userId, $siteId, $holidayDate, $holidayName, $province, $amount, $flags);
        }
        return true;
    }
}

if (!function_exists('sh_review_audit')) {
    function sh_review_audit(PDO $pdo, int $userId, string $decision, string $note): void {
        if (!function_exists('aa_log')) {
            return;
        }
        try {
            $st = $pdo->prepare('SELECT id FROM attendance WHERE user_id=? ORDER BY id DESC LIMIT 1');
            $st->execute([$userId]);
            $id = (int)$st->fetchColumn();
            if ($id > 0) {
                aa_log($pdo, 'update', $id, null, null, 'Holiday eligibility review: ' . $decision . ' ' . $note);
            }
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('sh_apply_review_decision')) {
    function sh_apply_review_decision(PDO $pdo, int $orgId, int $reviewId, string $decision, int $actorId, ?string $endDate = null): bool {
        sh_ensure_holiday_review($pdo);
        $st = $pdo->prepare('SELECT * FROM stat_holiday_review WHERE id=? AND org_id=?');
        $st->execute([$reviewId, $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $allowed = ['quit', 'leave_wait', 'leave_pay', 'employed', 'reasonable', 'no_cause'];
        if (!in_array($decision, $allowed, true)) {
            return false;
        }
        $pdo->prepare('UPDATE stat_holiday_review SET status=?, decision=?, decided_by=?, decided_at=?, note=? WHERE id=?')
            ->execute([$decision, $decision, $actorId, date('Y-m-d H:i:s'), $endDate, $reviewId]);
        if ($decision === 'quit' && $endDate && sh_date_only($endDate)) {
            $userCols = sh_table_cols($pdo, 'users');
            foreach (['enddate', 'end_date', 'termination_date'] as $col) {
                if (isset($userCols[$col])) {
                    $pdo->prepare("UPDATE users SET `$col`=? WHERE id=? AND org_id=?")->execute([$endDate, (int)$row['user_id'], $orgId]);
                    break;
                }
            }
        }
        sh_review_audit($pdo, (int)$row['user_id'], $decision, (string)$row['holiday_date']);
        if (in_array($decision, ['employed', 'reasonable', 'leave_pay'], true)) {
            sh_post_stat_line($pdo, $orgId, (int)$row['user_id'], (int)$row['site_id'], (string)$row['holiday_date'], (string)$row['holiday_name'], (string)$row['province'], $actorId);
        }
        return true;
    }
}

if (!function_exists('sh_release_leave_reviews')) {
    function sh_release_leave_reviews(PDO $pdo, int $orgId, int $userId, int $siteId): int {
        try {
            $st = $pdo->prepare("SELECT * FROM stat_holiday_review WHERE org_id=? AND user_id=? AND site_id=? AND status='leave_wait'");
            $st->execute([$orgId, $userId, $siteId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return 0;
        }
        $n = 0;
        foreach ($rows as $row) {
            $holiday = (string)$row['holiday_date'];
            $seen = false;
            try {
                $q = $pdo->prepare("SELECT 1 FROM payroll_finalized WHERE user_id=? AND site_id=? AND work_date > ? AND COALESCE(category,'') NOT LIKE 'Statutory%' LIMIT 1");
                $q->execute([$userId, $siteId, $holiday]);
                $seen = (bool)$q->fetchColumn();
            } catch (Throwable $e) {
            }
            if (!$seen) {
                try {
                    $q = $pdo->prepare('SELECT 1 FROM attendance WHERE user_id=? AND site_id=? AND substr(clock_in,1,10) > ? LIMIT 1');
                    $q->execute([$userId, $siteId, $holiday]);
                    $seen = (bool)$q->fetchColumn();
                } catch (Throwable $e) {
                }
            }
            if ($seen && sh_apply_review_decision($pdo, $orgId, (int)$row['id'], 'leave_pay', 0, null)) {
                $n++;
            }
        }
        return $n;
    }
}

if (!function_exists('sh_pending_review_count')) {
    function sh_pending_review_count(PDO $pdo, int $orgId, string $from, string $to): int {
        $st = $pdo->prepare("SELECT COUNT(*) FROM stat_holiday_review WHERE org_id=? AND status IN ('pending','leave_wait') AND holiday_date BETWEEN ? AND ?");
        $st->execute([$orgId, $from, $to]);
        return (int)$st->fetchColumn();
    }
}

if (!function_exists('sh_review_banner_html')) {
    function sh_review_banner_html(PDO $pdo, int $orgId, string $from, string $to): string {
        try {
            $n = sh_pending_review_count($pdo, $orgId, $from, $to);
        } catch (Throwable $e) {
            return '';
        }
        if ($n < 1) {
            return '';
        }
        $href = sh_stat_pay_href($pdo, $orgId);
        return '<div class="alert alert-warning font-weight-bold">Holiday eligibility review: ' . $n . ' pending. <a href="' . htmlspecialchars($href) . '">Open Stat Holiday Pay</a></div>';
    }
}

if (!function_exists('sh_org_with_latest_pending_stat')) {
    /** Org that owns the newest statutory line still waiting for approval. Never a hardcoded id. */
    function sh_org_with_latest_pending_stat(PDO $pdo): int {
        try {
            $row = $pdo->query(
                "SELECT u.org_id
                   FROM payroll_finalized f
                   INNER JOIN users u ON u.id = f.user_id
                  WHERE f.category = 'Statutory Holiday'
                    AND COALESCE(f.stat_approval_status,'') = 'pending'
                    AND u.org_id > 0
                  ORDER BY f.work_date DESC, f.id DESC
                  LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            return $row ? (int)$row['org_id'] : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('sh_latest_pending_stat_date')) {
    function sh_latest_pending_stat_date(PDO $pdo, int $orgId): ?string {
        if ($orgId <= 0) {
            return null;
        }
        try {
            $st = $pdo->prepare(
                "SELECT MAX(f.work_date) FROM payroll_finalized f
                  INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
                 WHERE f.category = 'Statutory Holiday'
                   AND COALESCE(f.stat_approval_status,'') = 'pending'"
            );
            $st->execute([$orgId]);
            $day = substr((string)$st->fetchColumn(), 0, 10);
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? $day : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('sh_period_containing')) {
    /** Pay period that contains $day, using the same window as the holiday page. */
    function sh_period_containing(PDO $pdo, int $orgId, string $day): array {
        $lib = dirname(__DIR__) . '/engine/org_modules_lib.php';
        if (!function_exists('sip_period_window') && is_file($lib)) {
            require_once $lib;
        }
        if (function_exists('sip_period_window')) {
            $mode = function_exists('sip_org_pay_period_mode') ? sip_org_pay_period_mode($pdo, $orgId) : ($orgId === 4 ? 'bi_weekly' : 'semi_monthly');
            $anchor = function_exists('sip_org_pay_period_anchor') ? sip_org_pay_period_anchor($pdo, $orgId) : '2024-01-01';
            return sip_period_window($day, $mode, $anchor);
        }
        $mode = $orgId === 4 ? 'bi_weekly' : 'semi_monthly';
        $anchor = '2024-01-01';
        if ($mode === 'bi_weekly') {
            $d0 = new DateTimeImmutable($anchor);
            $d1 = new DateTimeImmutable(substr($day, 0, 10));
            $days = (int)$d0->diff($d1)->format('%r%a');
            $block = (int)floor($days / 14);
            $start = $d0->modify(($block * 14) . ' days');
            $end = $start->modify('+13 days');
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];
        }
        $ts = strtotime($day) ?: time();
        $dom = (int)date('j', $ts);
        if ($dom <= 15) {
            return [date('Y-m-01', $ts), date('Y-m-15', $ts)];
        }
        return [date('Y-m-16', $ts), date('Y-m-t', $ts)];
    }
}

if (!function_exists('sh_stat_pay_href')) {
    /** Link to Stat Holiday Pay for this org, on the latest period that still has a line waiting. */
    function sh_stat_pay_href(PDO $pdo, int $orgId): string {
        if ($orgId <= 0 && function_exists('ps_org_id')) {
            $orgId = ps_org_id();
        }
        $from = '';
        $to = '';
        $day = sh_latest_pending_stat_date($pdo, $orgId);
        if ($day) {
            [$from, $to] = sh_period_containing($pdo, $orgId, $day);
        }
        $query = ['preview_period' => '1'];
        if ($orgId > 0) {
            $query['org_id'] = $orgId;
        }
        if ($from !== '' && $to !== '') {
            $query['from_date'] = $from;
            $query['to_date'] = $to;
        }
        return 'generate_stat_shifts.php?' . http_build_query($query);
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
        $orgId = 0;
        try {
            $ost = $pdo->prepare('SELECT org_id FROM users WHERE id=? LIMIT 1');
            $ost->execute([$userId]);
            $orgId = (int)$ost->fetchColumn();
        } catch (Throwable $e) {
        }
        if (sh_employment_ended_before($pdo, $userId, $siteId, $orgId, $holidayDate)) {
            $blank['reason'] = 'employment ended before the holiday';
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

if (!function_exists('sh_finalized_stat_line')) {
    /**
     * The pay-ledger line already written for this employee, site, and holiday.
     * status is "paid" or "pending" (or the stored status when it is something else).
     *
     * @return array{id:int,amount:float,status:string}|null
     */
    function sh_finalized_stat_line(PDO $pdo, int $userId, int $siteId, string $holidayDate): ?array {
        $st = $pdo->prepare(
            "SELECT id, COALESCE(total_gross,0) AS amount,
                    LOWER(TRIM(COALESCE(status,''))) AS row_status
               FROM payroll_finalized
              WHERE user_id=? AND site_id=? AND work_date=?
                AND COALESCE(category,'') = 'Statutory Holiday'
              ORDER BY id DESC
              LIMIT 1"
        );
        $st->execute([$userId, $siteId, $holidayDate]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $paymentStatus = '';
        $paidAmount = 0.0;
        try {
            $extra = $pdo->prepare(
                "SELECT COALESCE(paid_amount,0) AS paid_amount,
                        LOWER(TRIM(COALESCE(payment_status,''))) AS payment_status
                   FROM payroll_finalized WHERE id=?"
            );
            $extra->execute([(int)$row['id']]);
            $ex = $extra->fetch(PDO::FETCH_ASSOC);
            if ($ex) {
                $paidAmount = (float)$ex['paid_amount'];
                $paymentStatus = (string)$ex['payment_status'];
            }
        } catch (Throwable $e) {
            $paymentStatus = '';
            $paidAmount = 0.0;
        }
        $stored = (string)$row['row_status'];
        if (in_array($paymentStatus, ['paid', 'remitted'], true) || $paidAmount > 0.02 || in_array($stored, ['paid', 'remitted'], true)) {
            $payStatus = 'paid';
        } elseif ($stored === '' || $stored === 'pending') {
            $payStatus = 'pending';
        } else {
            $payStatus = $stored;
        }
        return [
            'id' => (int)$row['id'],
            'amount' => round((float)$row['amount'], 2),
            'status' => $payStatus,
        ];
    }
}

if (!function_exists('sh_stat_flag_text')) {
    function sh_stat_flag_text(PDO $pdo, int $userId, int $siteId, string $holidayDate, string $province, ?string $asOf = null): string {
        if (sh_normalize_province($province) !== 'ON') {
            return '';
        }
        $labels = [
            'missed_before' => 'Missed last shift before',
            'missed_after' => 'Missed first shift after',
            'no_shift_after' => 'No shift in 28 days after',
        ];
        $out = [];
        foreach (sh_on_review_flags($pdo, $userId, $siteId, $holidayDate, $asOf) as $flag) {
            if (isset($labels[$flag])) {
                $out[] = $labels[$flag];
            }
        }
        return implode('; ', $out);
    }
}

if (!function_exists('sh_needs_approval')) {
    /** Approval lives in stat_approval_status so payroll_finalized.status can stay an enum. */
    function sh_needs_approval(array $row): bool {
        return strcasecmp(trim((string)($row['stat_approval_status'] ?? '')), 'pending') === 0;
    }
}

if (!function_exists('sh_stat_payable')) {
    function sh_stat_payable(array $row): bool {
        return !sh_needs_approval($row);
    }
}

if (!function_exists('sh_store_stat_meta')) {
    function sh_store_stat_meta(PDO $pdo, int $lineId, float $calculated, string $flag): void {
        $cols = sh_table_cols($pdo, 'payroll_finalized');
        $sets = [];
        $params = [];
        if (isset($cols['stat_calculated_amount'])) {
            $sets[] = 'stat_calculated_amount=?';
            $params[] = round($calculated, 2);
        }
        if (isset($cols['stat_flag'])) {
            $sets[] = 'stat_flag=?';
            $params[] = $flag !== '' ? $flag : null;
        }
        if (isset($cols['stat_approval_status'])) {
            $sets[] = "stat_approval_status='pending'";
        }
        if (!$sets) {
            return;
        }
        $params[] = $lineId;
        $pdo->prepare('UPDATE payroll_finalized SET ' . implode(', ', $sets) . ' WHERE id=?')->execute($params);
    }
}

if (!function_exists('sh_refresh_pending_stat')) {
    function sh_refresh_pending_stat(PDO $pdo, int $userId, int $siteId, string $holidayDate, float $calculated, string $flag): void {
        $cols = sh_table_cols($pdo, 'payroll_finalized');
        $extra = '';
        if (isset($cols['stat_approved_at'])) {
            $extra .= ', stat_approved_at';
        }
        if (isset($cols['stat_edited_by'])) {
            $extra .= ', stat_edited_by';
        }
        if (isset($cols['stat_edit_reason'])) {
            $extra .= ', stat_edit_reason';
        }
        if (isset($cols['stat_approval_status'])) {
            $extra .= ', stat_approval_status';
        }
        $st = $pdo->prepare(
            "SELECT id, status, total_gross, comments $extra FROM payroll_finalized
              WHERE user_id=? AND site_id=? AND work_date=? AND category='Statutory Holiday' LIMIT 1"
        );
        $st->execute([$userId, $siteId, substr($holidayDate, 0, 10)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !sh_needs_approval($row)) {
            return;
        }
        if (!empty($row['stat_approved_at']) || (int)($row['stat_edited_by'] ?? 0) > 0 || trim((string)($row['stat_edit_reason'] ?? '')) !== '') {
            return;
        }
        $comment = preg_replace('/ FLAG: [^.]*\./', '', (string)($row['comments'] ?? ''));
        if ($flag !== '') {
            $comment = rtrim($comment) . ' FLAG: ' . $flag . '.';
        }
        $pdo->prepare('UPDATE payroll_finalized SET total_gross=?, reg_pay=?, comments=? WHERE id=?')
            ->execute([round($calculated, 2), round($calculated, 2), $comment, (int)$row['id']]);
        sh_store_stat_meta($pdo, (int)$row['id'], $calculated, $flag);
    }
}

if (!function_exists('sh_approve_stat_line')) {
    /** Approve one needs-approval line. A changed amount requires a reason. Does not mark it paid. */
    function sh_approve_stat_line(PDO $pdo, int $orgId, int $lineId, int $actorId, ?float $amount, ?float $hours, string $reason): bool {
        $cols = sh_table_cols($pdo, 'payroll_finalized');
        $st = $pdo->prepare(
            "SELECT f.* FROM payroll_finalized f
              INNER JOIN users u ON u.id=f.user_id AND u.org_id=?
              WHERE f.id=? AND f.category='Statutory Holiday' LIMIT 1"
        );
        $st->execute([$orgId, $lineId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !isset($cols['stat_approval_status']) || !sh_needs_approval($row)) {
            return false;
        }
        $calculated = isset($row['stat_calculated_amount']) ? (float)$row['stat_calculated_amount'] : (float)$row['total_gross'];
        $newAmount = $amount === null ? (float)$row['total_gross'] : round($amount, 2);
        if (abs($newAmount - $calculated) > 0.009 && trim($reason) === '') {
            return false;
        }
        $sets = ["stat_approval_status='approved'", 'total_gross=?', 'reg_pay=?'];
        $params = [$newAmount, $newAmount];
        if ($hours !== null) {
            $sets[] = 'reg_hours=?';
            $params[] = round($hours, 2);
        }
        if (isset($cols['stat_approved_amount'])) {
            $sets[] = 'stat_approved_amount=?';
            $params[] = $newAmount;
        }
        if (isset($cols['stat_approved_by'])) {
            $sets[] = 'stat_approved_by=?';
            $params[] = $actorId;
        }
        if (isset($cols['stat_approved_at'])) {
            $sets[] = 'stat_approved_at=?';
            $params[] = date('Y-m-d H:i:s');
        }
        if (abs($newAmount - $calculated) > 0.009) {
            if (isset($cols['stat_edited_by'])) {
                $sets[] = 'stat_edited_by=?';
                $params[] = $actorId;
            }
            if (isset($cols['stat_edit_reason'])) {
                $sets[] = 'stat_edit_reason=?';
                $params[] = substr(trim($reason), 0, 255);
            }
        }
        $params[] = $lineId;
        $pdo->prepare('UPDATE payroll_finalized SET ' . implode(', ', $sets) . ' WHERE id=?')->execute($params);
        if (function_exists('aa_log')) {
            sh_review_audit($pdo, (int)$row['user_id'], 'approved', (string)$row['work_date'] . ' ' . $reason);
        }
        return true;
    }
}

if (!function_exists('sh_bulk_approve_unflagged')) {
    function sh_bulk_approve_unflagged(PDO $pdo, int $orgId, int $actorId): int {
        try {
            $st = $pdo->prepare(
                "SELECT f.id FROM payroll_finalized f
                  INNER JOIN users u ON u.id=f.user_id AND u.org_id=?
                  WHERE f.category='Statutory Holiday' AND f.stat_approval_status='pending'
                    AND (f.stat_flag IS NULL OR f.stat_flag='')"
            );
            $st->execute([$orgId]);
            $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return 0;
        }
        $n = 0;
        foreach ($ids as $id) {
            if (sh_approve_stat_line($pdo, $orgId, (int)$id, $actorId, null, null, '')) {
                $n++;
            }
        }
        return $n;
    }
}

if (!function_exists('sh_ensure_stat_exclusions')) {
    function sh_ensure_stat_exclusions(PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS stat_holiday_exclusions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                org_id INT NOT NULL, user_id INT NOT NULL, site_id INT NOT NULL,
                holiday_date TEXT NOT NULL, reason TEXT NOT NULL, note TEXT,
                decided_by INT, decided_at TEXT,
                UNIQUE (org_id, user_id, site_id, holiday_date))");
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS stat_holiday_exclusions (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL, user_id INT NOT NULL, site_id INT NOT NULL,
            holiday_date DATE NOT NULL, reason VARCHAR(255) NOT NULL, note VARCHAR(255) NULL,
            decided_by INT NULL, decided_at DATETIME NULL,
            UNIQUE KEY uq_stat_excl (org_id, user_id, site_id, holiday_date))");
    }
}

if (!function_exists('sh_stat_excluded')) {
    function sh_stat_excluded(PDO $pdo, int $orgId, int $userId, int $siteId, string $holidayDate): ?array {
        try {
            sh_ensure_stat_exclusions($pdo);
            $st = $pdo->prepare('SELECT * FROM stat_holiday_exclusions WHERE org_id=? AND user_id=? AND site_id=? AND holiday_date=? LIMIT 1');
            $st->execute([$orgId, $userId, $siteId, substr($holidayDate, 0, 10)]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('sh_record_stat_exclusion')) {
    function sh_record_stat_exclusion(PDO $pdo, int $orgId, int $userId, int $siteId, string $holidayDate, string $reason, int $byUser = 0, string $note = ''): void {
        sh_ensure_stat_exclusions($pdo);
        $holidayDate = substr($holidayDate, 0, 10);
        $reason = substr(trim($reason), 0, 255);
        if ($reason === '') {
            throw new InvalidArgumentException('An exclusion needs a reason.');
        }
        $existing = sh_stat_excluded($pdo, $orgId, $userId, $siteId, $holidayDate);
        $when = date('Y-m-d H:i:s');
        if ($existing) {
            $pdo->prepare('UPDATE stat_holiday_exclusions SET reason=?, note=?, decided_by=?, decided_at=? WHERE id=?')
                ->execute([$reason, $note !== '' ? substr($note, 0, 255) : null, $byUser > 0 ? $byUser : null, $when, (int)$existing['id']]);
            return;
        }
        $pdo->prepare('INSERT INTO stat_holiday_exclusions (org_id, user_id, site_id, holiday_date, reason, note, decided_by, decided_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$orgId, $userId, $siteId, $holidayDate, $reason, $note !== '' ? substr($note, 0, 255) : null, $byUser > 0 ? $byUser : null, $when]);
    }
}

if (!function_exists('sh_drop_unapproved_stat')) {
    /** Remove an unpaid line that is still waiting for approval. Paid and approved lines stay. */
    function sh_drop_unapproved_stat(PDO $pdo, int $orgId, int $userId, int $siteId, string $holidayDate): void {
        $holidayDate = substr($holidayDate, 0, 10);
        $cols = sh_table_cols($pdo, 'payroll_finalized');
        $approval = isset($cols['stat_approval_status']) ? "AND COALESCE(stat_approval_status,'')='pending'" : '';
        $paid = isset($cols['paid_amount']) ? 'AND COALESCE(paid_amount,0)<=0.02' : '';
        $pdo->prepare(
            "DELETE FROM payroll_finalized
              WHERE org_id=? AND user_id=? AND site_id=? AND work_date=?
                AND category='Statutory Holiday'
                AND LOWER(COALESCE(status,'')) NOT IN ('paid','remitted')
                $approval $paid"
        )->execute([$orgId, $userId, $siteId, $holidayDate]);
        try {
            $pdo->prepare('DELETE FROM statutory_pay_items WHERE org_id=? AND user_id=? AND site_id=? AND holiday_date=?')
                ->execute([$orgId, $userId, $siteId, $holidayDate]);
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('sh_decide_stat_line')) {
    /**
     * Approve one stat line. A candidate is created and approved in the same step.
     * Does not mark the line paid. Refuses an exclusion, a paid line, and an amount
     * change that has no reason.
     */
    function sh_decide_stat_line(PDO $pdo, int $orgId, int $userId, int $siteId, string $holidayDate, string $holidayName, string $province, int $actorId, ?float $amount, ?float $hours, string $reason, ?string $asOf = null): bool {
        if (sh_stat_excluded($pdo, $orgId, $userId, $siteId, $holidayDate)) {
            return false;
        }
        $existed = sh_stat_line_exists($pdo, $userId, $siteId, $holidayDate);
        if (!$existed) {
            if (!sh_post_stat_line($pdo, $orgId, $userId, $siteId, $holidayDate, $holidayName, $province, $actorId, null, '', $asOf)) {
                return false;
            }
        }
        $st = $pdo->prepare(
            "SELECT id, COALESCE(stat_approval_status,'') AS approval, COALESCE(paid_amount,0) AS paid_amount, LOWER(COALESCE(status,'')) AS pay_status
               FROM payroll_finalized
              WHERE user_id=? AND site_id=? AND work_date=? AND category='Statutory Holiday'
              ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$userId, $siteId, substr($holidayDate, 0, 10)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        if ($row['approval'] === 'approved' || (float)$row['paid_amount'] > 0.02 || in_array($row['pay_status'], ['paid', 'remitted'], true)) {
            return false;
        }
        $ok = sh_approve_stat_line($pdo, $orgId, (int)$row['id'], $actorId, $amount, $hours, $reason);
        if (!$ok && !$existed) {
            sh_drop_unapproved_stat($pdo, $orgId, $userId, $siteId, $holidayDate);
        }
        return $ok;
    }
}

if (!function_exists('sh_post_stat_line')) {
    function sh_post_stat_line(PDO $pdo, int $orgId, int $userId, int $siteId, string $holidayDate, string $holidayName, string $province, int $byUser = 0, ?string $payPeriodEnd = null, string $commentNote = '', ?string $asOf = null): bool {
        if (sh_stat_excluded($pdo, $orgId, $userId, $siteId, $holidayDate)) {
            return false;
        }
        $calc = sh_compute_stat_pay($pdo, $userId, $siteId, $holidayDate, $province);
        if (!$calc['eligible'] || $calc['amount'] < 0.01) {
            return false;
        }
        $flag = sh_stat_flag_text($pdo, $userId, $siteId, $holidayDate, $province, $asOf);
        if (sh_stat_line_exists($pdo, $userId, $siteId, $holidayDate)) {
            sh_refresh_pending_stat($pdo, $userId, $siteId, $holidayDate, (float)$calc['amount'], $flag);
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
        if ($flag !== '') {
            $comment .= ' FLAG: ' . $flag . '.';
        }
        if ($commentNote !== '') {
            $comment .= ' ' . $commentNote;
        }
        $periodEnd = ($payPeriodEnd !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $payPeriodEnd)) ? $payPeriodEnd : $holidayDate;
        $ins = $pdo->prepare(
            "INSERT INTO payroll_finalized
                (user_id, org_id, site_id, work_date, pay_period_end, category, display_label, pay_type,
                 reg_hours, hourly_rate, reg_pay, total_gross, cpp, ei, income_tax, cpp_er, ei_er, wcb, vac,
                 status, finalized_by, comments)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',?,?)"
        );
        $ins->execute([
            $userId, $orgId, $siteId, $holidayDate, $periodEnd, 'Statutory Holiday',
            $holidayName . ' — Statutory Holiday Pay', 'Statutory',
            $hours, $rate, $calc['amount'], $calc['amount'],
            (float)($ded['cpp'] ?? 0), (float)($ded['ei'] ?? 0), (float)($ded['income_tax'] ?? 0),
            (float)($ded['cpp_er'] ?? 0), (float)($ded['ei_er'] ?? 0), (float)($ded['wcb'] ?? 0), (float)($ded['vac'] ?? 0),
            $byUser, $comment,
        ]);
        sh_store_stat_meta($pdo, (int)$pdo->lastInsertId(), (float)$calc['amount'], $flag);
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
