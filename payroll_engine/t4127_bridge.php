<?php
/**
 * Connects the live payroll engine (pe_line / pe_refresh_period_stat) to the
 * T4127 calculator. Callers keep the same arguments and the same return keys.
 * Extra keys (cpp2, cpp2_er, eht, edition) are added and ignored by old inserts.
 */

require_once __DIR__ . '/source_deductions_lib.php';

if (!function_exists('pe_periods_in_year')) {
    /** How many period-end dates of this cadence fall in the calendar year. */
    function pe_periods_in_year(string $mode, int $year, string $anchor = '2024-01-01'): int
    {
        $mode = strtolower(trim($mode));
        if ($mode === 'monthly') {
            return 12;
        }
        if ($mode === 'semi_monthly' || $mode === '') {
            return 24;
        }
        if ($year < 1990 || $year > 2100) {
            return $mode === 'weekly' ? 52 : 26;
        }
        $n = 0;
        $cursor = sprintf('%04d-01-01', $year);
        $last = sprintf('%04d-12-31', $year);
        $guard = 0;
        while ($cursor <= $last && $guard < 400) {
            [, $end] = pe_period_window($cursor, $mode, $anchor);
            if (substr($end, 0, 4) === (string)$year) {
                $n++;
            }
            $next = date('Y-m-d', strtotime($end . ' +1 day'));
            if ($next <= $cursor) {
                break;
            }
            $cursor = $next;
            $guard++;
        }
        return $n > 0 ? $n : ($mode === 'weekly' ? 52 : 26);
    }
}

function pe_t4127_col(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $k = $table . '.' . $column;
    if (array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    try {
        $st = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ' . $pdo->quote($column));
        $cache[$k] = (bool)($st && $st->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        $cache[$k] = false;
    }
    return $cache[$k];
}

function pe_t4127_periods(PDO $pdo, int $siteId, int $orgId, string $payDate): int
{
    if (!function_exists('sip_org_pay_periods')) {
        $lib = dirname(__DIR__) . '/engine/org_modules_lib.php';
        if (is_file($lib)) {
            require_once $lib;
        }
    }
    if ($orgId <= 0 && $siteId > 0) {
        try {
            $q = $pdo->prepare('SELECT org_id FROM sites WHERE SId=? LIMIT 1');
            $q->execute([$siteId]);
            $orgId = (int)($q->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $orgId = 0;
        }
    }
    if ($orgId > 0 && function_exists('sip_org_pay_periods')) {
        $n = (int)sip_org_pay_periods($pdo, $orgId);
        if ($n > 0) {
            return $n;
        }
    }
    $mode = function_exists('pe_period_mode_for_site') ? pe_period_mode_for_site($pdo, $siteId, $orgId) : 'semi_monthly';
    $anchor = function_exists('pe_period_anchor_for_org') ? pe_period_anchor_for_org($pdo, $orgId) : '2024-01-01';
    $year = (int)substr($payDate, 0, 4);
    if (function_exists('pe_periods_in_year')) {
        return pe_periods_in_year($mode, $year > 0 ? $year : (int)date('Y'), $anchor);
    }
    if ($mode === 'weekly') {
        return 52;
    }
    if ($mode === 'monthly') {
        return 12;
    }
    if ($mode === 'bi_weekly') {
        return 26;
    }
    return 24;
}

function pe_t4127_window(PDO $pdo, int $siteId, int $orgId, string $workDate): array
{
    $mode = pe_period_mode_for_site($pdo, $siteId, $orgId);
    $anchor = pe_period_anchor_for_org($pdo, $orgId);
    return pe_period_window($workDate, $mode, $anchor);
}

/** Prior periods for this employee at this site (each site is its own CRA account). */
function pe_t4127_ytd(PDO $pdo, int $userId, int $siteId, string $periodStart): array
{
    $ytd = ['pensionable' => 0.0, 'cpp' => 0.0, 'cpp2' => 0.0, 'ei' => 0.0, 'gross' => 0.0];
    $year = (int)substr($periodStart, 0, 4);
    $from = sprintf('%04d-01-01', $year > 0 ? $year : (int)date('Y'));
    if (pe_t4127_col($pdo, 'payroll_deduction_openings', 'user_id')) {
        try {
            $st = $pdo->prepare('SELECT pensionable, cpp, cpp2, ei, gross FROM payroll_deduction_openings WHERE user_id=? AND tax_year=? AND (org_id=(SELECT org_id FROM sites WHERE SId=? LIMIT 1) OR org_id=0) LIMIT 1');
            $st->execute([$userId, $year, $siteId]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                foreach ($ytd as $k => $_) {
                    if (isset($row[$k])) {
                        $ytd[$k] += (float)$row[$k];
                    }
                }
            }
        } catch (Throwable $e) {
        }
    }
    $cpp2 = pe_t4127_col($pdo, 'payroll_period_stat', 'cpp2') ? 'COALESCE(SUM(cpp2),0)' : '0';
    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(pensionable),0) pensionable, COALESCE(SUM(cpp),0) cpp, $cpp2 cpp2,
                    COALESCE(SUM(ei),0) ei, COALESCE(SUM(gross),0) gross
             FROM payroll_period_stat
             WHERE user_id=? AND site_id=? AND period_end >= ? AND period_end < ?"
        );
        $st->execute([$userId, $siteId, $from, $periodStart]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            foreach ($ytd as $k => $_) {
                $ytd[$k] += (float)($row[$k] ?? 0);
            }
        }
    } catch (Throwable $e) {
    }
    return $ytd;
}

function pe_t4127_claims(PDO $pdo, int $userId): array
{
    $out = ['tc' => null, 'tcp' => null, 'tax_exempt' => false, 'additional_tax' => 0.0, 'ontario_dependants' => 0, 'wsib_exempt' => false];
    if ($userId <= 0) {
        return $out;
    }
    $want = ['td1_federal_total', 'td1_provincial_total', 'td1_tax_exempt', 'td1_additional_tax', 'ontario_dependants', 'wsib_exempt'];
    $cols = [];
    foreach ($want as $c) {
        if (pe_t4127_col($pdo, 'users', $c)) {
            $cols[] = $c;
        }
    }
    if (!$cols) {
        return $out;
    }
    try {
        $st = $pdo->prepare('SELECT ' . implode(',', $cols) . ' FROM users WHERE id=? LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $out;
    }
    if (array_key_exists('td1_federal_total', $row) && $row['td1_federal_total'] !== null && $row['td1_federal_total'] !== '') {
        $out['tc'] = (float)$row['td1_federal_total'];
    }
    if (array_key_exists('td1_provincial_total', $row) && $row['td1_provincial_total'] !== null && $row['td1_provincial_total'] !== '') {
        $out['tcp'] = (float)$row['td1_provincial_total'];
    }
    $out['tax_exempt'] = !empty($row['td1_tax_exempt']);
    $out['additional_tax'] = (float)($row['td1_additional_tax'] ?? 0);
    $out['ontario_dependants'] = (int)($row['ontario_dependants'] ?? 0);
    $out['wsib_exempt'] = !empty($row['wsib_exempt']);
    return $out;
}

function pe_t4127_site(PDO $pdo, int $siteId): array
{
    $out = ['province' => '', 'wsib_percent' => null, 'org_id' => 0];
    if ($siteId <= 0) {
        return $out;
    }
    $prov = pe_t4127_col($pdo, 'sites', 'province') ? 'province' : "''";
    $rate = pe_t4127_col($pdo, 'sites', 'wsib_percent') ? 'wsib_percent' : 'NULL';
    try {
        $st = $pdo->prepare("SELECT org_id, $prov AS province, $rate AS wsib_percent FROM sites WHERE SId=? LIMIT 1");
        $st->execute([$siteId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $row = null;
    }
    if (!$row) {
        return $out;
    }
    $out['org_id'] = (int)($row['org_id'] ?? 0);
    $out['province'] = sd_province_code((string)($row['province'] ?? ''));
    if ($row['wsib_percent'] !== null && $row['wsib_percent'] !== '') {
        $out['wsib_percent'] = (float)$row['wsib_percent'];
    }
    return $out;
}

/**
 * One remuneration amount through T4127.
 * $gross is wages before vacation. Vacation follows pe_vacation_rate unless the
 * org pays it each period, in which case it is part of pensionable pay.
 * $nonperiodic true means the whole amount is a bonus (factor B), not regular wages.
 */
function pe_t4127_line(PDO $pdo, float $gross, float $vacRate, array $rates, array $ctx): ?array
{
    $base = round(max(0.0, $gross), 2);
    $userId = (int)($ctx['user_id'] ?? 0);
    $siteId = (int)($ctx['site_id'] ?? 0);
    $orgId = (int)($ctx['org_id'] ?? 0);
    $workDate = substr((string)($ctx['work_date'] ?? $ctx['pay_date'] ?? date('Y-m-d')), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
        $workDate = date('Y-m-d');
    }
    $payDate = substr((string)($ctx['pay_date'] ?? $workDate), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) {
        $payDate = $workDate;
    }
    if (array_key_exists('force_vac_rate', $ctx)) {
        $vacRate = (float)$ctx['force_vac_rate'];
    }
    $vac = round($base * $vacRate, 2);
    $payVac = pe_org_pays_vacation_each_period($pdo, $siteId, $orgId);
    $site = pe_t4127_site($pdo, $siteId);
    if ($orgId <= 0) {
        $orgId = $site['org_id'];
    }
    [$pStart, $pEnd] = pe_t4127_window($pdo, $siteId, $orgId, $workDate);
    if (empty($ctx['pay_date'])) {
        $payDate = $pEnd;
    }
    $claims = pe_t4127_claims($pdo, $userId);
    $ytd = ($userId > 0 && $siteId > 0 && empty($ctx['ytd_cpp']))
        ? pe_t4127_ytd($pdo, $userId, $siteId, $pStart)
        : [
            'pensionable' => (float)($ctx['ytd_pensionable'] ?? 0),
            'cpp' => (float)($ctx['ytd_cpp'] ?? 0),
            'cpp2' => (float)($ctx['ytd_cpp2'] ?? 0),
            'ei' => (float)($ctx['ytd_ei'] ?? 0),
        ];
    $periods = (int)($ctx['pay_periods'] ?? 0);
    if ($periods <= 0) {
        $periods = pe_t4127_periods($pdo, $siteId, $orgId, $payDate);
    }
    $nonperiodic = !empty($ctx['nonperiodic']);
    $input = [
        'pay_date' => $payDate,
        'province' => $site['province'],
        'periods' => $periods,
        'periodic_gross' => $nonperiodic ? 0.0 : round($base + ($payVac ? $vac : 0), 2),
        'bonus' => $nonperiodic ? round($base + ($payVac ? $vac : 0), 2) : (float)($ctx['bonus'] ?? 0),
        'ytd_pensionable' => $ytd['pensionable'],
        'ytd_cpp' => $ytd['cpp'],
        'ytd_cpp2' => $ytd['cpp2'],
        'ytd_ei' => $ytd['ei'],
        'tc' => $claims['tc'],
        'tcp' => $claims['tcp'],
        'tax_exempt' => $claims['tax_exempt'],
        'additional_tax' => $claims['additional_tax'],
        'ontario_dependants' => $claims['ontario_dependants'],
        'cpp_exempt' => !empty($ctx['skip_cpp_ei']),
        'ei_exempt' => !empty($ctx['skip_ei']),
    ];
    if ($userId > 0 && empty($ctx['skip_cpp_ei']) && pe_user_skips_cpp_ei($pdo, $userId)) {
        $input['cpp_exempt'] = true;
        $input['ei_exempt'] = true;
    } elseif ($userId > 0 && pe_user_skips_ei($pdo, $userId)) {
        $input['ei_exempt'] = true;
    }
    $calc = sd_calculate($input);
    if (empty($calc['ok'])) {
        return null;
    }
    $cpp = (float)$calc['cpp'];
    $cpp2 = (float)$calc['cpp2'];
    $ei = (float)$calc['ei'];
    $tax = (float)$calc['income_tax'];
    if (!pe_org_withholds_income_tax($pdo, $siteId, $orgId)) {
        $tax = 0.0;
    }
    if (array_key_exists('force_income_tax', $ctx)) {
        $tax = round((float)$ctx['force_income_tax'], 2);
    }
    $wsibExempt = $claims['wsib_exempt'] || !empty($ctx['wsib_exempt']);
    if ($wsibExempt) {
        $wcb = 0.0;
    } elseif ($site['wsib_percent'] !== null && $site['wsib_percent'] > 0) {
        $wcbRow = sd_workers_comp($site['province'], (float)$calc['pensionable'], (float)$ytd['pensionable'], $site['wsib_percent'], false);
        $wcb = (float)$wcbRow['premium'];
    } else {
        $wcb = round((float)$calc['pensionable'] * (float)($rates['wcb'] ?? 0), 2);
    }
    $payGross = $nonperiodic ? round($base + ($payVac ? $vac : 0), 2) : (float)$input['periodic_gross'];
    if (!$payVac) {
        $payGross = $base;
    }
    $ee = round($cpp + $cpp2 + $ei + $tax, 2);
    $eiEr = (float)$calc['ei_er'];
    $vacEr = $payVac ? 0.0 : $vac;
    $rules = ['eligible' => true, 'exemption' => 1000000.0, 'group_over_cap' => false];
    if (pe_t4127_col($pdo, 'organizations', 'eht_eligible')) {
        try {
            $st = $pdo->prepare('SELECT eht_eligible, eht_exemption_amount, eht_group_over_cap FROM organizations WHERE id=?');
            $st->execute([$orgId]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $rules['eligible'] = (int)$row['eht_eligible'] === 1;
                $rules['exemption'] = (float)$row['eht_exemption_amount'];
                $rules['group_over_cap'] = (int)$row['eht_group_over_cap'] === 1;
            }
        } catch (Throwable $e) {
        }
    }
    $eht = 0.0;
    if ($site['province'] === 'ON') {
        $step = sd_ontario_eht_increment(0, (float)$calc['pensionable'], $rules['exemption'], $rules['eligible'], $rules['group_over_cap']);
        $eht = (float)$step['increment'];
    }
    return [
        'cpp' => $cpp,
        'cpp2' => $cpp2,
        'ei' => $ei,
        'income_tax' => $tax,
        'cpp_er' => $cpp,
        'cpp2_er' => $cpp2,
        'ei_er' => $eiEr,
        'wcb' => $wcb,
        'eht' => $eht,
        'vac' => $vac,
        'vac_paid' => $payVac ? $vac : 0.0,
        'vac_accrued' => $payVac ? 0.0 : $vac,
        'pay_gross' => $payGross,
        'base_gross' => $base,
        'vac_payout' => $payVac,
        'ee_total' => $ee,
        'er_total' => round($cpp + $cpp2 + $eiEr + $wcb + $vacEr + $eht, 2),
        'net' => round($payGross - $ee, 2),
        'pensionable' => (float)$calc['pensionable'],
        'insurable' => (float)$calc['insurable'],
        'edition' => (string)$calc['edition'],
        'period_start' => $pStart,
        'period_end' => $pEnd,
        'province' => $site['province'],
        'warnings' => $calc['warnings'],
    ];
}

/** Split a period total across line weights. Last line keeps the remainder. */
function pe_t4127_allocate(float $total, array $weights): array
{
    return sd_allocate_money($total, $weights);
}

/**
 * Annual Ontario EHT from stored period gross on Ontario sites.
 * This is the report figure. It does not file anything.
 */
function pe_eht_annual(PDO $pdo, int $orgId, int $year): array
{
    $rules = ['eligible' => true, 'exemption' => 1000000.0, 'group_over_cap' => false];
    if (pe_t4127_col($pdo, 'organizations', 'eht_eligible')) {
        try {
            $st = $pdo->prepare('SELECT eht_eligible, eht_exemption_amount, eht_group_over_cap FROM organizations WHERE id=?');
            $st->execute([$orgId]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $rules['eligible'] = (int)$row['eht_eligible'] === 1;
                $rules['exemption'] = (float)$row['eht_exemption_amount'];
                $rules['group_over_cap'] = (int)$row['eht_group_over_cap'] === 1;
            }
        } catch (Throwable $e) {
        }
    }
    $sites = [];
    $total = 0.0;
    $from = sprintf('%04d-01-01', $year);
    $to = sprintf('%04d-12-31', $year);
    try {
        $prov = pe_t4127_col($pdo, 'sites', 'province')
            ? "UPPER(TRIM(s.province)) IN ('ON','ONTARIO')"
            : '0';
        $name = pe_t4127_col($pdo, 'sites', 'nick_name') ? 's.nick_name' : "CONCAT('Site ', s.SId)";
        $st = $pdo->prepare(
            "SELECT s.SId AS site_id, $name AS nick_name, COALESCE(SUM(p.gross),0) AS pay
             FROM sites s
             LEFT JOIN payroll_period_stat p ON p.site_id = s.SId AND p.period_end BETWEEN ? AND ?
             WHERE s.org_id = ? AND $prov
             GROUP BY s.SId, nick_name"
        );
        $st->execute([$from, $to, $orgId]);
        foreach ($st as $row) {
            $pay = round((float)$row['pay'], 2);
            $sites[] = ['nick_name' => (string)$row['nick_name'], 'pay' => $pay];
            $total += $pay;
        }
    } catch (Throwable $e) {
    }
    $calc = sd_ontario_eht($total, $rules['exemption'], $rules['eligible'], $rules['group_over_cap']);
    $calc['sites'] = $sites;
    $calc['eligible_configured'] = $rules['eligible'];
    $calc['exemption_configured'] = $rules['exemption'];
    $calc['group_over_cap'] = $rules['group_over_cap'];
    return $calc;
}
