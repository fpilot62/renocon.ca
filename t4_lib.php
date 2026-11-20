<?php
/**
 * T4 v1 helpers — identity validation, completeness, capped boxes 24/26.
 * XML/T619 mapper lives here as it lands; deduction engine rewrite is separate.
 *
 * Scope: BC / ON / SK only. Each sites row = one RP / one T4 Summary.
 */

if (!function_exists('t4_supported_provinces')) {

/** v1 provinces of employment (excludes QC). */
function t4_supported_provinces(): array {
    return ['BC', 'ON', 'SK'];
}

/** CRA annual caps used for boxes 24 / 26 (earnings). Deductions stay as withheld. */
function t4_year_caps(int $year): array {
    // Source: CRA CPP / EI maximums pages. Update annually.
    static $caps = [
        2025 => [
            'ympe' => 71300.00,   // box 26 base CPP ceiling (YAMPE used for CPP2 band)
            'yampe' => 81200.00,  // box 26 may report up to YAMPE when CPP2 applies
            'mie' => 65700.00,    // EI maximum insurable earnings → box 24
            'ybe' => 3500.00,
            'cpp_max' => 4034.10,
            'cpp2_max' => 396.00,
            'ei_max' => 1077.48,
            'ei_rate' => 0.0164,
        ],
        2026 => [
            'ympe' => 74600.00,
            'yampe' => 85000.00,
            'mie' => 68900.00,
            'ybe' => 3500.00,
            'cpp_max' => 4230.45,
            'cpp2_max' => 416.00,
            'ei_max' => 1123.07,
            'ei_rate' => 0.0163, // published 2026 employee EI rate for the annual maximum, not a pay-cheque estimate
        ],
    ];
    if (!isset($caps[$year])) {
        // Fail closed to nearest known year rather than inventing.
        $year = $year > 2026 ? 2026 : 2025;
    }
    return $caps[$year];
}

function t4_normalize_bn15(?string $raw): string {
    $s = strtoupper(preg_replace('/[\s\-]/', '', (string)$raw));
    return $s;
}

function t4_is_valid_bn15(?string $raw): bool {
    return (bool)preg_match('/^\d{9}RP\d{4}$/', t4_normalize_bn15($raw));
}

function t4_digits_only(?string $raw): string {
    return preg_replace('/\D+/', '', (string)$raw);
}

function t4_is_valid_sin(?string $raw): bool {
    $d = t4_digits_only($raw);
    if ($d === '000000000') {
        return true; // CRA allows all zeroes when SIN not provided (with consequences)
    }
    if (!preg_match('/^\d{9}$/', $d)) {
        return false;
    }
    // Luhn check (CRA SINs are Luhn-valid)
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $n = (int)$d[$i];
        if ($i % 2 === 1) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
    }
    return $sum % 10 === 0;
}

function t4_normalize_postal(?string $raw): string {
    return strtoupper(preg_replace('/\s+/', '', (string)$raw));
}

function t4_is_valid_ca_postal(?string $raw): bool {
    return (bool)preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', t4_normalize_postal($raw));
}

function t4_normalize_province(?string $raw): string {
    return strtoupper(trim((string)$raw));
}

/**
 * Split display Name into CRA surname / given name.
 * Prefer explicit columns when present.
 */
function t4_split_name(array $user): array {
    $given = trim((string)($user['given_name'] ?? ''));
    $surname = trim((string)($user['surname'] ?? ''));
    if ($given !== '' && $surname !== '') {
        return ['given' => $given, 'surname' => $surname, 'source' => 'columns'];
    }

    $full = trim((string)($user['Name'] ?? $user['name'] ?? ''));
    if ($full === '') {
        return ['given' => '', 'surname' => '', 'source' => 'empty'];
    }

    // "Surname, Given Middle"
    if (strpos($full, ',') !== false) {
        [$sur, $giv] = array_map('trim', explode(',', $full, 2));
        return ['given' => $giv, 'surname' => $sur, 'source' => 'comma'];
    }

    $parts = preg_split('/\s+/', $full);
    if (count($parts) === 1) {
        return ['given' => '', 'surname' => $parts[0], 'source' => 'single'];
    }
    $sur = array_pop($parts);
    return ['given' => implode(' ', $parts), 'surname' => $sur, 'source' => 'space'];
}

/** Cap EI insurable (box 24) and CPP pensionable (box 26) earnings for a tax year. */
function t4_cap_earnings(float $gross, int $year): array {
    $c = t4_year_caps($year);
    $box24 = round(min(max(0, $gross), $c['mie']), 2);
    // RC4120: box 26 is pensionable earnings up to additional maximum (YAMPE) when CPP2 applies.
    $box26 = round(min(max(0, $gross), $c['yampe']), 2);
    return [
        'box24' => $box24,
        'box26' => $box26,
        'gross' => round(max(0, $gross), 2),
        'capped_ei' => $gross > $c['mie'],
        'capped_cpp' => $gross > $c['yampe'],
        'caps' => $c,
    ];
}

/**
 * Completeness audit for one employee×site slip draft.
 * Returns list of blocking issues (empty = exportable identity).
 */
function t4_slip_issues(array $slip): array {
    $issues = [];
    $prov = t4_normalize_province($slip['employment_province'] ?? '');
    if ($prov === '' || !in_array($prov, t4_supported_provinces(), true)) {
        $issues[] = 'Province of employment must be BC, ON, or SK (v1). Found: ' . ($prov ?: '(blank)');
    }
    if (!t4_is_valid_bn15($slip['payroll_account'] ?? '')) {
        $issues[] = 'Site payroll account (BN15 RP) missing or invalid — need #########RP####';
    }
    $employer = trim((string)($slip['employer_name'] ?? ''));
    if ($employer === '') {
        $issues[] = 'Employer legal name (site corp_legal_name) is required';
    }
    foreach (['employer_addr', 'employer_city', 'employer_postal'] as $k) {
        if (trim((string)($slip[$k] ?? '')) === '') {
            $issues[] = 'Employer address incomplete (' . $k . ')';
        }
    }
    if (!t4_is_valid_ca_postal($slip['employer_postal'] ?? '')) {
        $issues[] = 'Employer postal code must be Canadian A9A9A9';
    }

    if (!t4_is_valid_sin($slip['sin'] ?? '')) {
        $issues[] = 'Employee SIN missing or fails checksum (or use 000000000 if none provided)';
    }
    $names = t4_split_name($slip);
    if ($names['surname'] === '' || $names['source'] === 'single') {
        $issues[] = 'Employee needs surname + given name (set given_name/surname or use "First Last")';
    }
    foreach (['street_address', 'city', 'home_province', 'postal_code'] as $k) {
        if (trim((string)($slip[$k] ?? '')) === '') {
            $issues[] = 'Employee address incomplete (' . $k . ')';
        }
    }
    if (!t4_is_valid_ca_postal($slip['postal_code'] ?? '')) {
        $issues[] = 'Employee postal code must be Canadian A9A9A9';
    }
    $dental = (int)($slip['dental_benefit_code'] ?? 0);
    if ($dental < 1 || $dental > 5) {
        $issues[] = 'Dental benefit code (box 45) must be 1–5';
    }
    return $issues;
}

/**
 * Load YTD T4 drafts for an org/year, one slip per employee×site (RP boundary).
 */
function t4_load_drafts(PDO $pdo, int $orgId, int $year, int $siteId = 0, int $employeeId = 0): array {
    // Scope by finalized payroll org — site.org_id is often mis-tagged across orgs.
    $where = ['f.org_id = ?', 'YEAR(f.work_date) = ?'];
    $params = [$orgId, $year];
    if ($siteId > 0) {
        $where[] = 'f.site_id = ?';
        $params[] = $siteId;
    }
    if ($employeeId > 0) {
        $where[] = 'f.user_id = ?';
        $params[] = $employeeId;
    }

    $hasCpp2 = false;
    $hasCpp2Er = false;
    try {
        $hasCpp2 = (bool)$pdo->query("SHOW COLUMNS FROM payroll_finalized LIKE 'cpp2'")->fetch();
        $hasCpp2Er = (bool)$pdo->query("SHOW COLUMNS FROM payroll_finalized LIKE 'cpp2_er'")->fetch();
    } catch (Throwable $e) { /* ignore */ }

    $cpp2Sel = $hasCpp2 ? 'SUM(COALESCE(f.cpp2, 0)) AS cpp2' : '0 AS cpp2';
    $cpp2ErSel = $hasCpp2Er ? 'SUM(COALESCE(f.cpp2_er, 0)) AS cpp2_er' : '0 AS cpp2_er';

    // Soft-detect optional identity columns so older DBs still load the worksheet.
    $uCols = [];
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM users') as $c) {
            $uCols[strtolower($c['Field'])] = true;
        }
    } catch (Throwable $e) { /* ignore */ }
    $sinSel = isset($uCols['sin']) ? 'u.sin' : 'NULL AS sin';
    $givenSel = isset($uCols['given_name']) ? 'u.given_name' : 'NULL AS given_name';
    $surnameSel = isset($uCols['surname']) ? 'u.surname' : 'NULL AS surname';
    $streetSel = isset($uCols['street_address']) ? 'u.street_address' : 'NULL AS street_address';
    $citySel = isset($uCols['city']) ? 'u.city' : 'NULL AS city';
    $homeProvSel = isset($uCols['province']) ? 'u.province AS home_province' : 'NULL AS home_province';
    $postalSel = isset($uCols['postal_code']) ? 'u.postal_code' : 'NULL AS postal_code';
    $dentalSel = isset($uCols['dental_benefit_code']) ? 'COALESCE(u.dental_benefit_code, 1) AS dental_benefit_code' : '1 AS dental_benefit_code';

    $sCols = [];
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM sites') as $c) {
            $sCols[strtolower($c['Field'])] = true;
        }
    } catch (Throwable $e) { /* ignore */ }
    $corpSel = isset($sCols['corp_legal_name']) ? 's.corp_legal_name' : 's.nick_name AS corp_legal_name';
    $bnSel = isset($sCols['payroll_account']) ? 's.payroll_account' : 'NULL AS payroll_account';
    $empAddrSel = isset($sCols['street_address']) ? 's.street_address AS employer_addr' : 'NULL AS employer_addr';
    $empCitySel = isset($sCols['city']) ? 's.city AS employer_city' : 'NULL AS employer_city';
    $empProvSel = "COALESCE(NULLIF(TRIM(s.province), ''), NULLIF(TRIM(s.state), '')) AS employment_province";
    if (!isset($sCols['province']) && isset($sCols['state'])) {
        $empProvSel = 's.state AS employment_province';
    } elseif (!isset($sCols['province']) && !isset($sCols['state'])) {
        $empProvSel = "NULL AS employment_province";
    }
    $empZipSel = isset($sCols['zipcode']) ? 's.zipcode AS employer_postal' : 'NULL AS employer_postal';
    $stateSel = isset($sCols['state']) ? 's.state AS site_state' : 'NULL AS site_state';

    $sql = "SELECT u.id AS user_id, u.Name, {$sinSel}, {$givenSel}, {$surnameSel},
                   {$streetSel}, {$citySel}, {$homeProvSel}, {$postalSel},
                   {$dentalSel},
                   f.site_id,
                   s.nick_name AS site_name,
                   {$corpSel},
                   {$bnSel},
                   {$empAddrSel},
                   {$empCitySel},
                   {$empProvSel},
                   {$empZipSel},
                   {$stateSel},
                   SUM(COALESCE(f.total_gross, 0)) AS employment_income,
                   SUM(COALESCE(f.income_tax, 0)) AS income_tax,
                   SUM(COALESCE(f.cpp, 0)) AS cpp,
                   {$cpp2Sel},
                   SUM(COALESCE(f.ei, 0)) AS ei,
                   SUM(COALESCE(f.cpp_er, 0)) AS cpp_er,
                   {$cpp2ErSel},
                   SUM(COALESCE(f.ei_er, 0)) AS ei_er
            FROM payroll_finalized f
            INNER JOIN users u ON u.id = f.user_id
            INNER JOIN sites s ON s.SId = f.site_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY u.id, u.Name, f.site_id, s.nick_name
            ORDER BY s.nick_name, u.Name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $names = t4_split_name($r);
        $caps = t4_cap_earnings((float)$r['employment_income'], $year);
        $draft = [
            'user_id' => (int)$r['user_id'],
            'site_id' => (int)$r['site_id'],
            'site_name' => $r['site_name'],
            'Name' => $r['Name'],
            'given_name' => $names['given'],
            'surname' => $names['surname'],
            'name_source' => $names['source'],
            'sin' => $r['sin'],
            'street_address' => $r['street_address'],
            'city' => $r['city'],
            'home_province' => t4_normalize_province($r['home_province']),
            'postal_code' => $r['postal_code'],
            'dental_benefit_code' => (int)$r['dental_benefit_code'],
            'employer_name' => trim((string)($r['corp_legal_name'] ?: '')),
            'payroll_account' => t4_normalize_bn15($r['payroll_account'] ?? ''),
            'employer_addr' => $r['employer_addr'],
            'employer_city' => $r['employer_city'],
            'employer_postal' => $r['employer_postal'],
            'employment_province' => t4_normalize_province($r['employment_province']),
            'employment_income' => $caps['gross'],
            'income_tax' => round((float)$r['income_tax'], 2),
            'cpp' => round((float)$r['cpp'], 2),
            'ei' => round((float)$r['ei'], 2),
            'cpp_er' => round((float)$r['cpp_er'], 2),
            'ei_er' => round((float)$r['ei_er'], 2),
            'box24' => $caps['box24'],
            'box26' => $caps['box26'],
            'capped_ei' => $caps['capped_ei'],
            'capped_cpp' => $caps['capped_cpp'],
            'cpp2' => round((float)($r['cpp2'] ?? 0), 2),
            'cpp2_er' => round((float)($r['cpp2_er'] ?? 0), 2),
            'issues' => [],
            'warnings' => [],
        ];
        $draft['issues'] = t4_slip_issues($draft);
        $capsY = t4_year_caps($year);
        if ($draft['employment_income'] > $capsY['ympe'] && $draft['cpp2'] <= 0) {
            $draft['warnings'][] = 'Gross exceeds YMPE but CPP2 (box 16A) is $0 — re-finalize pay after engine rewrite, or reconcile remittances';
        }
        if ($draft['cpp'] > $capsY['cpp_max'] + 0.01) {
            $draft['warnings'][] = 'Box 16 CPP withheld exceeds annual maximum — reconcile before filing';
        }
        if ($draft['cpp2'] > $capsY['cpp2_max'] + 0.01) {
            $draft['warnings'][] = 'Box 16A CPP2 withheld exceeds annual maximum — reconcile before filing';
        }
        if ($draft['ei'] > $capsY['ei_max'] + 0.01) {
            $draft['warnings'][] = 'Box 18 EI withheld exceeds annual maximum — reconcile before filing';
        }
        $out[] = $draft;
    }
    return $out;
}

function t4_group_by_rp(array $drafts): array {
    $groups = [];
    foreach ($drafts as $d) {
        $key = $d['payroll_account'] !== '' ? $d['payroll_account'] : ('SITE_' . $d['site_id']);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'payroll_account' => $d['payroll_account'],
                'site_id' => $d['site_id'],
                'employer_name' => $d['employer_name'],
                'site_name' => $d['site_name'],
                'employment_province' => $d['employment_province'],
                'slips' => [],
                'blocking' => 0,
                'warnings' => 0,
            ];
        }
        $groups[$key]['slips'][] = $d;
        if ($d['issues']) {
            $groups[$key]['blocking']++;
        }
        if ($d['warnings']) {
            $groups[$key]['warnings']++;
        }
    }
    return $groups;
}

} // function_exists
