<?php
/**
 * Province statutory holiday helpers (ON / SK first).
 * Eligibility always follows the work site's province — never an org-wide default.
 */
if (!function_exists('sh_supported_stat_provinces')) {
    function sh_supported_stat_provinces(): array {
        return ['ON', 'SK'];
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
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $out = [];
        $seen = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
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
