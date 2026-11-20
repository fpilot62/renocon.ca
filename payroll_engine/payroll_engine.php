<?php
/**
 * Path: /payroll_engine/payroll_engine.php
 *
 * Payroll deduction engine. Option 1 of the CRA T4127 formulas for the pay
 * date: the 122nd edition from January 1, 2026, and the 123rd edition from
 * July 1, 2026. Rates are dated packs, not a flat percentage.
 *
 * pe_line, pe_rates, pe_refresh_period_stat, pe_read_period_stats, and
 * pe_period_window are the functions the pay-run, manual pay, pay stub, and
 * quick-hours paths already call. The tax math is in source_deductions_lib.php.
 *
 * Nothing here marks a row paid, prints a cheque, or files a T4, an ROE, or
 * an Employer Health Tax return.
 */

require_once __DIR__ . '/source_deductions_lib.php';

function pe_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (function_exists('privacy_column_exists')) {
        $cache[$key] = privacy_column_exists($pdo, $table, $column);
        return $cache[$key];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        $cache[$key] = false;
        return false;
    }
    try {
        $st = $pdo->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $pdo->quote($column));
        $cache[$key] = (bool)($st && $st->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function pe_has_table(PDO $pdo, string $table): bool
{
    if (function_exists('privacy_table_exists')) {
        return privacy_table_exists($pdo, $table);
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    try {
        $st = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
        return (bool)($st && $st->fetchColumn());
    } catch (Throwable $e) {
        return false;
    }
}

/** Employee CPP that reduces net: base + first additional + CPP2 when that column exists. */
function pe_sql_employee_cpp(PDO $pdo, string $alias = 'f'): string
{
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'f';
    if (pe_has_column($pdo, 'payroll_finalized', 'cpp2')) {
        return '(' . $alias . '.cpp + IFNULL(' . $alias . '.cpp2,0))';
    }
    return $alias . '.cpp';
}

/** Employer CPP that matches, including CPP2. */
function pe_sql_employer_cpp(PDO $pdo, string $alias = 'f'): string
{
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'f';
    $expr = $alias . '.cpp_er';
    if (pe_has_column($pdo, 'payroll_finalized', 'cpp2_er')) {
        $expr = '(' . $alias . '.cpp_er + IFNULL(' . $alias . '.cpp2_er,0))';
    }
    return $expr;
}

function pe_ymd(string $raw): string
{
    $raw = substr(trim($raw), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return '';
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    return ($dt && $dt->format('Y-m-d') === $raw) ? $raw : '';
}

/**
 * Pay-period window containing $workDate.
 * weekly and bi_weekly count from $anchor. semi_monthly is the 1st–15th and
 * the 16th through month end. monthly is the calendar month.
 *
 * @return array{0:string,1:string}
 */
function pe_period_window(string $workDate, string $mode, string $anchor = '2024-01-01'): array
{
    $day = pe_ymd($workDate);
    if ($day === '') {
        $day = date('Y-m-d');
    }
    $mode = strtolower(trim($mode));
    $ts = strtotime($day) ?: time();
    if ($mode === 'monthly') {
        return [date('Y-m-01', $ts), date('Y-m-t', $ts)];
    }
    if ($mode === 'weekly' || $mode === 'bi_weekly') {
        $span = $mode === 'weekly' ? 7 : 14;
        $a = pe_ymd($anchor);
        if ($a === '') {
            $a = '2024-01-01';
        }
        $d0 = new DateTimeImmutable($a);
        $d1 = new DateTimeImmutable($day);
        $days = (int)$d0->diff($d1)->format('%r%a');
        $start = $d0->modify((intdiv((int)floor($days / $span), 1) * $span) . ' days');
        if ($days < 0 && $start->format('Y-m-d') > $day) {
            $start = $start->modify('-' . $span . ' days');
        }
        $end = $start->modify('+' . ($span - 1) . ' days');
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }
    $dayn = (int)date('j', $ts);
    if ($dayn <= 15) {
        return [date('Y-m-01', $ts), date('Y-m-15', $ts)];
    }
    return [date('Y-m-16', $ts), date('Y-m-t', $ts)];
}

/** How many period-end dates of this cadence fall in the calendar year. */
function pe_periods_in_year(string $mode, int $year, string $anchor = '2024-01-01'): int
{
    $mode = strtolower(trim($mode));
    if ($mode === 'monthly') {
        return 12;
    }
    if ($mode === 'semi_monthly') {
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
    if ($n <= 0) {
        return $mode === 'weekly' ? 52 : 26;
    }
    return $n;
}

function pe_period_mode_for_site(PDO $pdo, int $siteId, int $orgId): string
{
    $mode = '';
    if ($orgId > 0 && pe_has_column($pdo, 'organizations', 'pay_period_mode')) {
        $st = $pdo->prepare('SELECT pay_period_mode FROM organizations WHERE id = ?');
        $st->execute([$orgId]);
        $mode = strtolower(trim((string)$st->fetchColumn()));
    }
    if ($mode === '' && $siteId > 0 && pe_has_column($pdo, 'sites', 'pay_period_mode')) {
        $st = $pdo->prepare('SELECT pay_period_mode FROM sites WHERE SId = ? AND org_id = ?');
        $st->execute([$siteId, $orgId]);
        $mode = strtolower(trim((string)$st->fetchColumn()));
    }
    if (!in_array($mode, ['weekly', 'bi_weekly', 'semi_monthly', 'monthly'], true)) {
        $mode = 'semi_monthly';
    }
    return $mode;
}

function pe_period_anchor_for_org(PDO $pdo, int $orgId): string
{
    if ($orgId > 0 && pe_has_column($pdo, 'organizations', 'pay_period_anchor')) {
        $st = $pdo->prepare('SELECT pay_period_anchor FROM organizations WHERE id = ?');
        $st->execute([$orgId]);
        $anchor = pe_ymd((string)$st->fetchColumn());
        if ($anchor !== '') {
            return $anchor;
        }
    }
    return '2024-01-01';
}

function pe_db_packs(PDO $pdo): array
{
    if (!pe_has_table($pdo, 'payroll_rate_packs')) {
        return [];
    }
    try {
        $rows = $pdo->query('SELECT effective_from, edition, source_url, pack_json FROM payroll_rate_packs')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $pack = json_decode((string)$row['pack_json'], true);
        if (!is_array($pack)) {
            continue;
        }
        $pack['effective_from'] = (string)$row['effective_from'];
        if (!empty($row['edition'])) {
            $pack['edition'] = (string)$row['edition'];
        }
        if (!empty($row['source_url'])) {
            $pack['source_url'] = (string)$row['source_url'];
        }
        $out[] = $pack;
    }
    return $out;
}

/**
 * Insert any built-in pack whose effective_from is not already stored.
 * A row you have edited is left alone.
 */
function pe_install_rates(PDO $pdo): int
{
    if (!pe_has_table($pdo, 'payroll_rate_packs')) {
        return 0;
    }
    $ins = $pdo->prepare(
        'INSERT INTO payroll_rate_packs (effective_from, edition, source_url, pack_json)
         SELECT ?, ?, ?, ? FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM payroll_rate_packs WHERE effective_from = ?)'
    );
    $n = 0;
    foreach (sd_builtin_packs() as $pack) {
        $from = (string)$pack['effective_from'];
        $ins->execute([
            $from,
            (string)($pack['edition'] ?? ''),
            (string)($pack['source_url'] ?? ''),
            json_encode($pack, JSON_UNESCAPED_SLASHES),
            $from,
        ]);
        $n += $ins->rowCount() > 0 ? 1 : 0;
    }
    return $n;
}

/** Dated pack for a pay date. Database rows override a built-in pack with the same date. */
function pe_rates($pdo = null, ?string $payDate = null): array
{
    $payDate = pe_ymd((string)$payDate);
    if ($payDate === '') {
        $payDate = date('Y-m-d');
    }
    $extra = ($pdo instanceof PDO) ? pe_db_packs($pdo) : [];
    $pack = sd_pack_for($payDate, $extra);
    if ($pack === null) {
        return ['ok' => false, 'effective_from' => '', 'edition' => '', 'pack' => null, 'pay_date' => $payDate];
    }
    return [
        'ok' => true,
        'pay_date' => $payDate,
        'effective_from' => (string)$pack['effective_from'],
        'edition' => (string)$pack['edition'],
        'source_url' => (string)($pack['source_url'] ?? ''),
        'pack' => $pack,
        'method' => 't4127',
    ];
}

function pe_scalar(PDO $pdo, string $sql, array $params)
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

function pe_profile_pay_rate(PDO $pdo, int $userId, string $kind, string $workDate): float
{
    if ($userId <= 0) {
        return 0.0;
    }
    $kind = strtolower($kind) === 'overtime' ? 'overtime' : 'regular';
    $candidates = $kind === 'overtime'
        ? ['ot_rate', 'overtime_rate', 'hourly_rate', 'pay_rate', 'wage_rate', 'regular_rate']
        : ['hourly_rate', 'pay_rate', 'wage_rate', 'regular_rate'];
    $rate = 0.0;
    foreach ($candidates as $col) {
        if (!pe_has_column($pdo, 'users', $col)) {
            continue;
        }
        $v = pe_scalar($pdo, "SELECT `$col` FROM users WHERE id = ?", [$userId]);
        if ($v !== null && (float)$v > 0) {
            $rate = (float)$v;
            if ($kind === 'overtime' && ($col === 'hourly_rate' || $col === 'pay_rate' || $col === 'wage_rate' || $col === 'regular_rate')) {
                $rate = round($rate * 1.5, 4);
            }
            break;
        }
    }
    if ($rate <= 0 && pe_has_column($pdo, 'payroll_finalized', 'hourly_rate')) {
        $day = pe_ymd($workDate);
        $sql = 'SELECT hourly_rate FROM payroll_finalized WHERE user_id = ? AND hourly_rate > 0';
        $params = [$userId];
        if ($day !== '') {
            $sql .= ' AND work_date <= ?';
            $params[] = $day;
        }
        $sql .= ' ORDER BY work_date DESC, id DESC LIMIT 1';
        $v = pe_scalar($pdo, $sql, $params);
        if ($v !== null && (float)$v > 0) {
            $rate = (float)$v;
            if ($kind === 'overtime') {
                $rate = round($rate * 1.5, 4);
            }
        }
    }
    return round($rate, 4);
}

function pe_vacation_rate(PDO $pdo, int $userId, int $siteId, string $workDate): float
{
    foreach (['vacation_rate', 'vacation_percent', 'vac_rate'] as $col) {
        if ($userId > 0 && pe_has_column($pdo, 'users', $col)) {
            $v = pe_scalar($pdo, "SELECT `$col` FROM users WHERE id = ?", [$userId]);
            if ($v !== null && (float)$v > 0) {
                return pe_normalize_rate((float)$v);
            }
        }
    }
    if ($siteId > 0 && pe_has_column($pdo, 'sites', 'vacation_rate')) {
        $v = pe_scalar($pdo, 'SELECT vacation_rate FROM sites WHERE SId = ?', [$siteId]);
        if ($v !== null && (float)$v > 0) {
            return pe_normalize_rate((float)$v);
        }
    }
    return 0.0;
}

function pe_normalize_rate(float $rate): float
{
    if ($rate > 1) {
        $rate = $rate / 100;
    }
    if ($rate < 0) {
        $rate = 0;
    }
    if ($rate > 1) {
        $rate = 1;
    }
    return round($rate, 6);
}

function pe_vacation_paid(PDO $pdo, int $orgId, array $ctx): bool
{
    if (array_key_exists('vacation_paid', $ctx)) {
        return (bool)$ctx['vacation_paid'];
    }
    if ($orgId > 0 && pe_has_column($pdo, 'organizations', 'vacation_payout_mode')) {
        $mode = strtolower(trim((string)pe_scalar($pdo, 'SELECT vacation_payout_mode FROM organizations WHERE id = ?', [$orgId])));
        return $mode === 'pay_each_period';
    }
    return false;
}

function pe_employee_factors(PDO $pdo, int $userId, int $orgId): array
{
    $out = [
        'tc' => null,
        'tcp' => null,
        'tax_exempt' => false,
        'additional_tax' => 0.0,
        'ontario_dependants' => 0,
        'cpp_exempt' => false,
        'ei_exempt' => false,
        'wsib_exempt' => false,
    ];
    if ($userId <= 0 || $orgId <= 0) {
        return $out;
    }
    $cols = ['id'];
    foreach ([
        'td1_federal_total', 'td1_provincial_total', 'td1_tax_exempt', 'td1_additional_tax',
        'ontario_dependants', 'cpp_exempt', 'ei_exempt', 'wsib_exempt',
    ] as $col) {
        if (pe_has_column($pdo, 'users', $col)) {
            $cols[] = $col;
        }
    }
    $st = $pdo->prepare('SELECT ' . implode(',', $cols) . ' FROM users WHERE id = ? AND org_id = ?');
    $st->execute([$userId, $orgId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
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
    $out['cpp_exempt'] = !empty($row['cpp_exempt']);
    $out['ei_exempt'] = !empty($row['ei_exempt']);
    $out['wsib_exempt'] = !empty($row['wsib_exempt']);
    return $out;
}

function pe_site_context(PDO $pdo, int $siteId, int $orgId): array
{
    $out = ['province' => '', 'wsib_percent' => 0.0, 'found' => false];
    if ($siteId <= 0 || $orgId <= 0) {
        return $out;
    }
    $prov = pe_has_column($pdo, 'sites', 'province') ? 'province' : "''";
    $rate = pe_has_column($pdo, 'sites', 'wsib_percent') ? 'wsib_percent' : 'NULL';
    $st = $pdo->prepare("SELECT $prov AS province, $rate AS wsib_percent FROM sites WHERE SId = ? AND org_id = ?");
    $st->execute([$siteId, $orgId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $out;
    }
    $out['found'] = true;
    $out['province'] = sd_province_code((string)($row['province'] ?? ''));
    $out['wsib_percent'] = $row['wsib_percent'] === null ? 0.0 : (float)$row['wsib_percent'];
    return $out;
}

function pe_org_eht(PDO $pdo, int $orgId): array
{
    $out = ['eligible' => true, 'exemption' => 1000000.0, 'group_over_cap' => false];
    if ($orgId <= 0) {
        return $out;
    }
    $sel = ['id'];
    if (pe_has_column($pdo, 'organizations', 'eht_eligible')) {
        $sel[] = 'eht_eligible';
    }
    if (pe_has_column($pdo, 'organizations', 'eht_exemption_amount')) {
        $sel[] = 'eht_exemption_amount';
    }
    if (pe_has_column($pdo, 'organizations', 'eht_group_over_cap')) {
        $sel[] = 'eht_group_over_cap';
    }
    if (count($sel) === 1) {
        return $out;
    }
    $st = $pdo->prepare('SELECT ' . implode(',', $sel) . ' FROM organizations WHERE id = ?');
    $st->execute([$orgId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $out;
    }
    if (array_key_exists('eht_eligible', $row)) {
        $out['eligible'] = (int)$row['eht_eligible'] === 1;
    }
    if (array_key_exists('eht_exemption_amount', $row) && $row['eht_exemption_amount'] !== null) {
        $out['exemption'] = (float)$row['eht_exemption_amount'];
    }
    if (array_key_exists('eht_group_over_cap', $row)) {
        $out['group_over_cap'] = (int)$row['eht_group_over_cap'] === 1;
    }
    return $out;
}

function pe_live_sql(PDO $pdo, string $alias): string
{
    if (function_exists('att_finalized_live_predicate')) {
        return att_finalized_live_predicate($pdo, $alias);
    }
    return '1=1';
}

/**
 * Year-to-date amounts for one employee before $periodStart, inside one org.
 * Openings cover money that is not already in payroll_finalized.
 */
function pe_ytd(PDO $pdo, int $orgId, int $userId, string $periodStart, ?string $province = null): array
{
    $year = (int)substr($periodStart, 0, 4);
    $from = sprintf('%04d-01-01', $year > 0 ? $year : (int)date('Y'));
    $ytd = [
        'pensionable' => 0.0, 'cpp' => 0.0, 'cpp2' => 0.0, 'insurable' => 0.0, 'ei' => 0.0,
        'gross' => 0.0, 'assessable' => 0.0, 'bonus' => 0.0, 'f5b' => 0.0,
    ];
    if ($orgId <= 0 || $userId <= 0 || pe_ymd($periodStart) === '') {
        return $ytd;
    }
    if (pe_has_table($pdo, 'payroll_deduction_openings')) {
        $st = $pdo->prepare(
            'SELECT pensionable, cpp, cpp2, insurable, ei, gross, assessable
             FROM payroll_deduction_openings WHERE org_id = ? AND user_id = ? AND tax_year = ?'
        );
        $st->execute([$orgId, $userId, $year]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            foreach (['pensionable', 'cpp', 'cpp2', 'insurable', 'ei', 'gross', 'assessable'] as $k) {
                $ytd[$k] += (float)$row[$k];
            }
        }
    }
    $pen = pe_has_column($pdo, 'payroll_finalized', 'pensionable_earnings')
        ? 'COALESCE(SUM(pensionable_earnings), SUM(total_gross), 0)'
        : 'COALESCE(SUM(total_gross),0)';
    $ins = pe_has_column($pdo, 'payroll_finalized', 'insurable_earnings')
        ? 'COALESCE(SUM(insurable_earnings), SUM(total_gross), 0)'
        : 'COALESCE(SUM(total_gross),0)';
    $cpp2 = pe_has_column($pdo, 'payroll_finalized', 'cpp2') ? 'COALESCE(SUM(cpp2),0)' : '0';
    $sql = "SELECT COALESCE(SUM(f.total_gross),0) gross, COALESCE(SUM(f.cpp),0) cpp, $cpp2 cpp2,
                   $pen pensionable, $ins insurable, COALESCE(SUM(f.ei),0) ei
            FROM payroll_finalized f
            INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
            WHERE f.user_id = ? AND f.work_date >= ? AND f.work_date < ?
              AND " . pe_live_sql($pdo, 'f');
    $st = $pdo->prepare($sql);
    $st->execute([$orgId, $userId, $from, $periodStart]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $ytd['gross'] += (float)$row['gross'];
        $ytd['cpp'] += (float)$row['cpp'];
        $ytd['cpp2'] += (float)$row['cpp2'];
        $ytd['pensionable'] += (float)$row['pensionable'];
        $ytd['insurable'] += (float)$row['insurable'];
        $ytd['ei'] += (float)$row['ei'];
        $ytd['assessable'] += (float)$row['pensionable'];
    }
    return $ytd;
}

function pe_ontario_remuneration(PDO $pdo, int $orgId, string $from, string $toExclusive, ?int $excludeUser = null): float
{
    if ($orgId <= 0 || !pe_has_column($pdo, 'sites', 'province')) {
        return 0.0;
    }
    $vacSql = pe_vacation_paid($pdo, $orgId, []) ? ' + COALESCE(SUM(f.vac),0)' : '';
    $sql = "SELECT COALESCE(SUM(f.total_gross),0)$vacSql
            FROM payroll_finalized f
            INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
            INNER JOIN sites s ON s.SId = f.site_id AND s.org_id = u.org_id
            WHERE f.work_date >= ? AND f.work_date < ?
              AND UPPER(TRIM(s.province)) IN ('ON','ONTARIO')
              AND " . pe_live_sql($pdo, 'f');
    $params = [$orgId, $from, $toExclusive];
    if ($excludeUser) {
        $sql .= ' AND f.user_id <> ?';
        $params[] = $excludeUser;
    }
    return round((float)pe_scalar($pdo, $sql, $params), 2);
}

/**
 * Deductions for one earnings amount.
 * $base is the wage before vacation. Vacation is $base times $vacRate.
 * When the organization pays vacation each period, vacation is part of the
 * cheque and part of pensionable, insurable, and taxable income. When it is
 * accrued, it is recorded but not deducted on until it is paid.
 *
 * $ctx: pdo, org_id, site_id, user_id, pay_date, work_date, province, tc, tcp,
 * vacation_paid, bonus, and the ytd_* overrides. A missing employee claim
 * uses the basic personal amount.
 *
 * @return array<string,mixed>
 */
function pe_line($base, $vacRate, $rates = null, array $ctx = []): array
{
    $base = round((float)$base, 2);
    $vacRate = pe_normalize_rate((float)$vacRate);
    $vac = round($base * $vacRate, 2);
    $pdo = ($ctx['pdo'] ?? null) instanceof PDO ? $ctx['pdo'] : null;
    $orgId = (int)($ctx['org_id'] ?? 0);
    $siteId = (int)($ctx['site_id'] ?? 0);
    $userId = (int)($ctx['user_id'] ?? 0);
    $workDate = pe_ymd((string)($ctx['work_date'] ?? $ctx['pay_date'] ?? ''));
    if ($workDate === '') {
        $workDate = date('Y-m-d');
    }
    $payDate = pe_ymd((string)($ctx['pay_date'] ?? ''));
    if ($payDate === '') {
        $payDate = $workDate;
    }
    $mode = (string)($ctx['period_mode'] ?? '');
    $anchor = (string)($ctx['period_anchor'] ?? '2024-01-01');
    if ($pdo && $mode === '' && $orgId > 0) {
        $mode = pe_period_mode_for_site($pdo, $siteId, $orgId);
        $anchor = pe_period_anchor_for_org($pdo, $orgId);
    }
    if ($mode === '') {
        $mode = 'semi_monthly';
    }
    [$pStart, $pEnd] = pe_period_window($workDate, $mode, $anchor);
    $periods = (int)($ctx['periods'] ?? 0);
    if ($periods <= 0) {
        $periods = pe_periods_in_year($mode, (int)substr($payDate, 0, 4), $anchor);
    }
    $paidVac = $pdo ? pe_vacation_paid($pdo, $orgId, $ctx) : !empty($ctx['vacation_paid']);
    $province = sd_province_code((string)($ctx['province'] ?? ''));
    $wsibPercent = array_key_exists('wsib_percent', $ctx) ? (float)$ctx['wsib_percent'] : 0.0;
    $factors = [
        'tc' => $ctx['tc'] ?? null,
        'tcp' => $ctx['tcp'] ?? null,
        'tax_exempt' => !empty($ctx['tax_exempt']),
        'additional_tax' => (float)($ctx['additional_tax'] ?? 0),
        'ontario_dependants' => (int)($ctx['ontario_dependants'] ?? 0),
        'cpp_exempt' => !empty($ctx['cpp_exempt']),
        'ei_exempt' => !empty($ctx['ei_exempt']),
        'wsib_exempt' => !empty($ctx['wsib_exempt']),
    ];
    if ($pdo && $userId > 0 && $orgId > 0 && !array_key_exists('tc', $ctx)) {
        $loaded = pe_employee_factors($pdo, $userId, $orgId);
        $factors = $loaded;
        if ($province === '' || $wsibPercent <= 0) {
            $site = pe_site_context($pdo, $siteId, $orgId);
            if ($province === '') {
                $province = $site['province'];
            }
            if (!array_key_exists('wsib_percent', $ctx)) {
                $wsibPercent = $site['wsib_percent'];
            }
        }
    } elseif ($pdo && $siteId > 0 && $orgId > 0 && $province === '') {
        $site = pe_site_context($pdo, $siteId, $orgId);
        $province = $site['province'];
        if (!array_key_exists('wsib_percent', $ctx)) {
            $wsibPercent = $site['wsib_percent'];
        }
    }
    $siblingGross = round((float)($ctx['period_gross_before'] ?? 0), 2);
    $siblingVac = round((float)($ctx['period_vac_before'] ?? 0), 2);
    if ($pdo && $userId > 0 && empty($ctx['ignore_saved_lines'])) {
        $saved = pe_period_saved($pdo, $orgId, $userId, $siteId, $pStart, $pEnd, (int)($ctx['exclude_line_id'] ?? 0));
        $siblingGross = round($siblingGross + $saved['gross'], 2);
        $siblingVac = round($siblingVac + $saved['vac'], 2);
    }
    $thisRemuneration = round($base + ($paidVac ? $vac : 0), 2);
    $priorRemuneration = round($siblingGross + ($paidVac ? $siblingVac : 0), 2);
    $ytd = [
        'ytd_cpp' => (float)($ctx['ytd_cpp'] ?? 0),
        'ytd_cpp2' => (float)($ctx['ytd_cpp2'] ?? 0),
        'ytd_ei' => (float)($ctx['ytd_ei'] ?? 0),
        'ytd_pensionable' => (float)($ctx['ytd_pensionable'] ?? 0),
        'ytd_bonus' => (float)($ctx['ytd_bonus'] ?? 0),
        'ytd_f5b' => (float)($ctx['ytd_f5b'] ?? 0),
    ];
    if ($pdo && $userId > 0 && $orgId > 0 && !array_key_exists('ytd_cpp', $ctx)) {
        $loadedYtd = pe_ytd($pdo, $orgId, $userId, $pStart, $province);
        $ytd['ytd_cpp'] = $loadedYtd['cpp'];
        $ytd['ytd_cpp2'] = $loadedYtd['cpp2'];
        $ytd['ytd_ei'] = $loadedYtd['ei'];
        $ytd['ytd_pensionable'] = $loadedYtd['pensionable'];
    }
    $packRates = pe_rates($pdo, $payDate);
    $input = array_merge($ytd, [
        'pay_date' => $payDate,
        'province' => $province,
        'periods' => $periods,
        'pm' => (int)($ctx['pm'] ?? 12),
        'periodic_gross' => round($priorRemuneration + $thisRemuneration, 2),
        'bonus' => round((float)($ctx['bonus'] ?? 0), 2),
        'tc' => $factors['tc'],
        'tcp' => $factors['tcp'],
        'tax_exempt' => $factors['tax_exempt'],
        'additional_tax' => $factors['additional_tax'],
        'ontario_dependants' => $factors['ontario_dependants'],
        'cpp_exempt' => $factors['cpp_exempt'],
        'ei_exempt' => $factors['ei_exempt'],
        'rpp' => (float)($ctx['rpp'] ?? 0),
        'union_dues' => (float)($ctx['union_dues'] ?? 0),
        'rate_packs' => ($pdo instanceof PDO) ? pe_db_packs($pdo) : [],
    ]);
    $combined = sd_calculate($input, $packRates['pack'] ?? null);
    $prior = $combined;
    if ($priorRemuneration > 0.009) {
        $priorInput = $input;
        $priorInput['periodic_gross'] = $priorRemuneration;
        $prior = sd_calculate($priorInput, $packRates['pack'] ?? null);
    } else {
        $prior = null;
    }
    $pick = static function (array $now, ?array $before, string $key): float {
        $already = (is_array($before) && isset($before[$key])) ? (float)$before[$key] : 0.0;
        return round((float)$now[$key] - $already, 2);
    };
    $wcb = ['assessable' => 0.0, 'premium' => 0.0];
    if ($province !== '' && !$factors['wsib_exempt']) {
        $priorPensionable = (is_array($prior) && isset($prior['pensionable'])) ? (float)$prior['pensionable'] : 0.0;
        $wcb = sd_workers_comp(
            $province,
            $pick($combined, $prior, 'pensionable'),
            (float)($ctx['ytd_assessable'] ?? ($ytd['ytd_pensionable'] ?? 0)) + $priorPensionable,
            $wsibPercent,
            false,
            $packRates['pack'] ?? null
        );
    }
    $eht = 0.0;
    if ($pdo && $province === 'ON' && $orgId > 0) {
        $eht = pe_line_eht($pdo, $orgId, $pStart, $pEnd, $userId, $thisRemuneration, $priorRemuneration, $packRates['pack'] ?? null);
    }
    $netBase = round($base + ($paidVac ? $vac : 0), 2);
    $cpp = $pick($combined, $prior, 'cpp');
    $cpp2 = $pick($combined, $prior, 'cpp2');
    $ei = $pick($combined, $prior, 'ei');
    $tax = $pick($combined, $prior, 'income_tax');
    return [
        'ok' => (bool)$combined['ok'],
        'error' => (string)$combined['error'],
        'pay_gross' => $base,
        'vac' => $vac,
        'vacation_paid' => $paidVac,
        'remuneration' => $thisRemuneration,
        'cpp' => $cpp,
        'cpp_base' => $pick($combined, $prior, 'cpp_base'),
        'cpp_additional' => $pick($combined, $prior, 'cpp_additional'),
        'cpp2' => $cpp2,
        'ei' => $ei,
        'income_tax' => $tax,
        'net' => round($netBase - $cpp - $cpp2 - $ei - $tax, 2),
        'cpp_er' => $cpp,
        'cpp2_er' => $cpp2,
        'ei_er' => $pick($combined, $prior, 'ei_er'),
        'wcb' => (float)$wcb['premium'],
        'wcb_assessable' => (float)$wcb['assessable'],
        'eht' => $eht,
        'pensionable' => $pick($combined, $prior, 'pensionable'),
        'insurable' => $pick($combined, $prior, 'insurable'),
        'province' => $province,
        'edition' => (string)$combined['edition'],
        'period_start' => $pStart,
        'period_end' => $pEnd,
        'pay_date' => $payDate,
        'periods' => $periods,
        'warnings' => $combined['warnings'],
        'factors' => $combined['factors'],
    ];
}

function pe_line_eht(PDO $pdo, int $orgId, string $periodStart, string $periodEnd, int $userId, float $thisRemuneration, float $priorRemuneration, ?array $pack): float
{
    $yearFrom = substr($periodStart, 0, 4) . '-01-01';
    $before = pe_ontario_remuneration($pdo, $orgId, $yearFrom, $periodStart, null);
    $dayAfter = date('Y-m-d', strtotime($periodEnd . ' +1 day'));
    $others = pe_ontario_remuneration($pdo, $orgId, $periodStart, $dayAfter, $userId);
    $rules = pe_org_eht($pdo, $orgId);
    $step = sd_ontario_eht_increment(
        $before + $others + $priorRemuneration,
        $thisRemuneration,
        $rules['exemption'],
        $rules['eligible'],
        $rules['group_over_cap'],
        $pack
    );
    return (float)$step['increment'];
}

function pe_period_saved(PDO $pdo, int $orgId, int $userId, int $siteId, string $start, string $end, int $excludeId): array
{
    $sql = 'SELECT COALESCE(SUM(f.total_gross),0) gross, COALESCE(SUM(f.vac),0) vac,
                   COALESCE(SUM(f.cpp),0) cpp, COALESCE(SUM(f.ei),0) ei, COALESCE(SUM(f.income_tax),0) tax
            FROM payroll_finalized f
            INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
            WHERE f.user_id = ? AND f.work_date BETWEEN ? AND ? AND ' . pe_live_sql($pdo, 'f');
    $params = [$orgId, $userId, $start, $end];
    if ($siteId > 0) {
        $sql .= ' AND f.site_id = ?';
        $params[] = $siteId;
    }
    if ($excludeId > 0) {
        $sql .= ' AND f.id <> ?';
        $params[] = $excludeId;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'gross' => (float)($row['gross'] ?? 0),
        'vac' => (float)($row['vac'] ?? 0),
        'cpp' => (float)($row['cpp'] ?? 0),
        'ei' => (float)($row['ei'] ?? 0),
        'tax' => (float)($row['tax'] ?? 0),
    ];
}

/**
 * Recompute one employee and site for the period that contains $workDate,
 * then store the total on the unpaid lines (split by gross) and on
 * payroll_period_stats. A period that already has a payment is left as stored.
 */
function pe_refresh_period_stat(PDO $pdo, int $userId, int $siteId, string $workDate): array
{
    $orgId = (int)pe_scalar($pdo, 'SELECT org_id FROM users WHERE id = ?', [$userId]);
    if ($orgId <= 0) {
        return ['ok' => false, 'error' => 'That employee is not in an organization.'];
    }
    $siteOrg = (int)pe_scalar($pdo, 'SELECT org_id FROM sites WHERE SId = ?', [$siteId]);
    if ($siteOrg !== $orgId) {
        return ['ok' => false, 'error' => 'That store is not in the employee\'s organization.'];
    }
    $mode = pe_period_mode_for_site($pdo, $siteId, $orgId);
    $anchor = pe_period_anchor_for_org($pdo, $orgId);
    [$start, $end] = pe_period_window($workDate, $mode, $anchor);
    $st = $pdo->prepare(
        'SELECT f.* FROM payroll_finalized f
         INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
         WHERE f.user_id = ? AND f.site_id = ? AND f.work_date BETWEEN ? AND ?
           AND ' . pe_live_sql($pdo, 'f') . '
         ORDER BY f.id'
    );
    $st->execute([$orgId, $userId, $siteId, $start, $end]);
    $lines = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$lines) {
        return ['ok' => true, 'updated' => 0, 'locked' => false];
    }
    foreach ($lines as $line) {
        if ((float)($line['paid_amount'] ?? 0) > 0.02) {
            return ['ok' => true, 'updated' => 0, 'locked' => true, 'error' => 'This period already has a payment, so deductions were not rewritten.'];
        }
    }
    $gross = 0.0;
    $vac = 0.0;
    foreach ($lines as $line) {
        $gross = round($gross + (float)$line['total_gross'], 2);
        $vac = round($vac + (float)($line['vac'] ?? 0), 2);
    }
    $paidVac = pe_vacation_paid($pdo, $orgId, []);
    $calc = pe_line($gross, ($paidVac && $gross > 0) ? $vac / $gross : 0, null, [
        'pdo' => $pdo,
        'org_id' => $orgId,
        'site_id' => $siteId,
        'user_id' => $userId,
        'work_date' => $end,
        'pay_date' => $end,
        'ignore_saved_lines' => true,
        'vacation_paid' => $paidVac,
    ]);
    $weights = [];
    foreach ($lines as $line) {
        $weights[] = (float)$line['total_gross'] + ($paidVac ? (float)($line['vac'] ?? 0) : 0);
    }
    $keys = ['cpp', 'cpp2', 'ei', 'income_tax', 'cpp_er', 'cpp2_er', 'ei_er', 'wcb', 'eht', 'pensionable', 'insurable'];
    $shares = [];
    foreach ($keys as $key) {
        $shares[$key] = sd_allocate_money((float)$calc[$key], $weights);
    }
    $updated = pe_write_period_lines($pdo, $orgId, $userId, $siteId, $lines, $shares, (string)$calc['edition']);
    pe_write_period_stats($pdo, $orgId, $userId, $siteId, $start, $end, $calc);
    return ['ok' => (bool)$calc['ok'], 'updated' => $updated, 'locked' => false, 'calc' => $calc, 'error' => (string)$calc['error']];
}

function pe_write_period_lines(PDO $pdo, int $orgId, int $userId, int $siteId, array $lines, array $shares, string $edition): int
{
    $n = 0;
    foreach ($lines as $i => $line) {
        $sets = ['cpp = ?', 'ei = ?', 'income_tax = ?', 'cpp_er = ?', 'ei_er = ?', 'wcb = ?'];
        $params = [
            $shares['cpp'][$i], $shares['ei'][$i], $shares['income_tax'][$i],
            $shares['cpp_er'][$i], $shares['ei_er'][$i], $shares['wcb'][$i],
        ];
        $optional = [
            'cpp2' => 'cpp2',
            'cpp2_er' => 'cpp2_er',
            'eht' => 'eht',
            'pensionable_earnings' => 'pensionable',
            'insurable_earnings' => 'insurable',
        ];
        foreach ($optional as $column => $key) {
            if (pe_has_column($pdo, 'payroll_finalized', $column)) {
                $sets[] = $column . ' = ?';
                $params[] = $shares[$key][$i];
            }
        }
        if (pe_has_column($pdo, 'payroll_finalized', 'deduction_edition')) {
            $sets[] = 'deduction_edition = ?';
            $params[] = substr($edition, 0, 80);
        }
        $params[] = (int)$line['id'];
        $params[] = $userId;
        $params[] = $siteId;
        $sql = 'UPDATE payroll_finalized SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ? AND site_id = ?';
        $own = pe_scalar($pdo, 'SELECT org_id FROM users WHERE id = ?', [$userId]);
        if ((int)$own !== $orgId) {
            continue;
        }
        $pdo->prepare($sql)->execute($params);
        $n++;
    }
    return $n;
}

function pe_write_period_stats(PDO $pdo, int $orgId, int $userId, int $siteId, string $start, string $end, array $calc): void
{
    if (!pe_has_table($pdo, 'payroll_period_stats')) {
        return;
    }
    $pdo->prepare(
        'INSERT INTO payroll_period_stats
            (org_id, user_id, site_id, period_start, period_end, gross, cpp, cpp_er, cpp2, cpp2_er, ei, ei_er, income_tax, wcb, eht, vac, edition)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            gross = VALUES(gross), cpp = VALUES(cpp), cpp_er = VALUES(cpp_er), cpp2 = VALUES(cpp2), cpp2_er = VALUES(cpp2_er),
            ei = VALUES(ei), ei_er = VALUES(ei_er), income_tax = VALUES(income_tax), wcb = VALUES(wcb), eht = VALUES(eht),
            vac = VALUES(vac), edition = VALUES(edition)'
    )->execute([
        $orgId, $userId, $siteId, $start, $end,
        (float)$calc['remuneration'],
        (float)$calc['cpp'], (float)$calc['cpp_er'], (float)$calc['cpp2'], (float)$calc['cpp2_er'],
        (float)$calc['ei'], (float)$calc['ei_er'], (float)$calc['income_tax'],
        (float)$calc['wcb'], (float)$calc['eht'], (float)$calc['vac'],
        substr((string)$calc['edition'], 0, 80),
    ]);
}

/**
 * Period CPP the pay stub uses in place of the sum of the lines, so the basic
 * exemption is counted once. cpp here is the base plus the first additional.
 * cpp2 is separate. The stub adds them when it replaces the line total.
 *
 * @return array<string,array<string,float>>
 */
function pe_read_period_stats($pdo, string $wstart, string $wend, array $userIds, array $siteIds = []): array
{
    if (!$pdo instanceof PDO) {
        return [];
    }
    $start = pe_ymd($wstart);
    $end = pe_ymd($wend);
    if ($start === '' || $end === '') {
        return [];
    }
    $userIds = array_values(array_filter(array_map('intval', $userIds)));
    $siteIds = array_values(array_filter(array_map('intval', $siteIds)));
    if (!$userIds) {
        return [];
    }
    $out = [];
    if (pe_has_table($pdo, 'payroll_period_stats')) {
        $sql = 'SELECT user_id, site_id, cpp, cpp_er, cpp2, cpp2_er FROM payroll_period_stats
                WHERE period_end >= ? AND period_start <= ? AND user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')';
        $params = [$start, $end, ...$userIds];
        if ($siteIds) {
            $sql .= ' AND site_id IN (' . implode(',', array_fill(0, count($siteIds), '?')) . ')';
            $params = array_merge($params, $siteIds);
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = ((int)$row['user_id']) . '|' . ((int)$row['site_id']);
            if (!isset($out[$key])) {
                $out[$key] = ['cpp' => 0.0, 'cpp_er' => 0.0, 'cpp2' => 0.0, 'cpp2_er' => 0.0];
            }
            $out[$key]['cpp'] += (float)$row['cpp'];
            $out[$key]['cpp_er'] += (float)$row['cpp_er'];
            $out[$key]['cpp2'] += (float)$row['cpp2'];
            $out[$key]['cpp2_er'] += (float)$row['cpp2_er'];
        }
    }
    return $out;
}

/**
 * Ontario EHT for one organization and calendar year, from stored remuneration.
 * This does not file the return.
 */
function pe_eht_annual(PDO $pdo, int $orgId, int $year): array
{
    $from = sprintf('%04d-01-01', $year);
    $to = sprintf('%04d-01-01', $year + 1);
    $remuneration = pe_ontario_remuneration($pdo, $orgId, $from, $to, null);
    $rules = pe_org_eht($pdo, $orgId);
    $pack = pe_rates($pdo, $from);
    $tax = sd_ontario_eht($remuneration, $rules['exemption'], $rules['eligible'], $rules['group_over_cap'], $pack['pack'] ?? null);
    $bySite = [];
    if (pe_has_column($pdo, 'sites', 'province')) {
        $st = $pdo->prepare(
            "SELECT s.SId AS site_id, s.nick_name, COALESCE(SUM(f.total_gross),0)" . (pe_vacation_paid($pdo, $orgId, []) ? ' + COALESCE(SUM(f.vac),0)' : '') . " AS pay
             FROM payroll_finalized f
             INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
             INNER JOIN sites s ON s.SId = f.site_id AND s.org_id = u.org_id
             WHERE f.work_date >= ? AND f.work_date < ?
               AND UPPER(TRIM(s.province)) IN ('ON','ONTARIO')
               AND " . pe_live_sql($pdo, 'f') . '
             GROUP BY s.SId, s.nick_name
             ORDER BY s.nick_name'
        );
        $st->execute([$orgId, $from, $to]);
        $bySite = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $tax['year'] = $year;
    $tax['org_id'] = $orgId;
    $tax['sites'] = $bySite;
    $tax['exemption_configured'] = $rules['exemption'];
    $tax['eligible_configured'] = $rules['eligible'];
    $tax['group_over_cap'] = $rules['group_over_cap'];
    return $tax;
}

/**
 * Stored T4 boxes and ROE insurable earnings for one employee, one org, one year.
 * Reads what payroll already stored. It does not recalculate a prior year and
 * it does not transmit anything.
 */
/** Insert a row using only columns that exist. Missing required columns get a zero or an empty string. */
function pe_insert_known(PDO $pdo, string $table, array $data): int
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        throw new RuntimeException('Unknown table.');
    }
    $cols = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
    $have = [];
    foreach ($cols as $col) {
        $have[$col['Field']] = $col;
        if (array_key_exists($col['Field'], $data) || $col['Null'] === 'YES' || $col['Default'] !== null || $col['Extra'] !== '') {
            continue;
        }
        $type = strtolower((string)$col['Type']);
        if (str_contains($type, 'int') || str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) {
            $data[$col['Field']] = 0;
        } elseif (str_contains($type, 'date') || str_contains($type, 'time')) {
            $data[$col['Field']] = date('Y-m-d H:i:s');
        } else {
            $data[$col['Field']] = '';
        }
    }
    $fields = [];
    $holders = [];
    $vals = [];
    foreach ($data as $key => $val) {
        if (!isset($have[$key]) || !preg_match('/^[A-Za-z0-9_]+$/', (string)$key)) {
            continue;
        }
        $fields[] = '`' . $key . '`';
        $holders[] = '?';
        $vals[] = $val;
    }
    if (!$fields) {
        throw new RuntimeException('Could not store a payroll row.');
    }
    $pdo->prepare('INSERT INTO `' . $table . '` (' . implode(',', $fields) . ') VALUES (' . implode(',', $holders) . ')')->execute($vals);
    return (int)$pdo->lastInsertId();
}

function pe_t4_roe(PDO $pdo, int $orgId, int $userId, int $year): array
{
    $from = sprintf('%04d-01-01', $year);
    $to = sprintf('%04d-01-01', $year + 1);
    $empty = [
        'ok' => false, 'year' => $year, 'user_id' => $userId, 'org_id' => $orgId,
        'employment_income' => 0.0, 'cpp' => 0.0, 'cpp2' => 0.0, 'ei' => 0.0, 'tax' => 0.0,
        'pensionable' => 0.0, 'insurable' => 0.0, 'ei_er' => 0.0, 'cpp_er' => 0.0, 'cpp2_er' => 0.0,
        'periods' => [], 'lines_without_edition' => 0,
    ];
    if ($orgId <= 0 || $userId <= 0) {
        return $empty;
    }
    $owns = (int)pe_scalar($pdo, 'SELECT COUNT(*) FROM users WHERE id = ? AND org_id = ?', [$userId, $orgId]);
    if ($owns !== 1) {
        $empty['error'] = 'That employee is not in this organization.';
        return $empty;
    }
    $cpp2 = pe_has_column($pdo, 'payroll_finalized', 'cpp2') ? 'COALESCE(SUM(f.cpp2),0)' : '0';
    $cpp2er = pe_has_column($pdo, 'payroll_finalized', 'cpp2_er') ? 'COALESCE(SUM(f.cpp2_er),0)' : '0';
    $pen = pe_has_column($pdo, 'payroll_finalized', 'pensionable_earnings')
        ? 'COALESCE(SUM(f.pensionable_earnings), SUM(f.total_gross), 0)' : 'COALESCE(SUM(f.total_gross),0)';
    $ins = pe_has_column($pdo, 'payroll_finalized', 'insurable_earnings')
        ? 'COALESCE(SUM(f.insurable_earnings), SUM(f.total_gross), 0)' : 'COALESCE(SUM(f.total_gross),0)';
    $editionMissing = pe_has_column($pdo, 'payroll_finalized', 'deduction_edition')
        ? "SUM(CASE WHEN f.deduction_edition IS NULL OR f.deduction_edition = '' THEN 1 ELSE 0 END)" : 'COUNT(*)';
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(f.total_gross),0) employment_income, COALESCE(SUM(f.vac),0) vac_earned,
                COALESCE(SUM(f.cpp),0) cpp, $cpp2 cpp2, COALESCE(SUM(f.ei),0) ei, COALESCE(SUM(f.income_tax),0) tax,
                $pen pensionable, $ins insurable,
                COALESCE(SUM(f.cpp_er),0) cpp_er, $cpp2er cpp2_er, COALESCE(SUM(f.ei_er),0) ei_er,
                $editionMissing bare
         FROM payroll_finalized f
         INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
         WHERE f.user_id = ? AND f.work_date >= ? AND f.work_date < ?
           AND " . pe_live_sql($pdo, 'f')
    );
    $st->execute([$orgId, $userId, $from, $to]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $periods = [];
    $pst = $pdo->prepare(
        "SELECT f.work_date, f.site_id, COALESCE(f.total_gross,0) gross, COALESCE(f.vac,0) vac,
                " . (pe_has_column($pdo, 'payroll_finalized', 'insurable_earnings') ? 'f.insurable_earnings' : 'NULL') . " insurable,
                COALESCE(f.reg_hours,0) + COALESCE(f.overtime_hours,0) hours
         FROM payroll_finalized f
         INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
         WHERE f.user_id = ? AND f.work_date >= ? AND f.work_date < ?
           AND " . pe_live_sql($pdo, 'f') . '
         ORDER BY f.work_date, f.id'
    );
    $pst->execute([$orgId, $userId, $from, $to]);
    foreach ($pst->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $insurable = $line['insurable'];
        $insurable = ($insurable === null || $insurable === '') ? (float)$line['gross'] : (float)$insurable;
        $periods[] = [
            'work_date' => (string)$line['work_date'],
            'site_id' => (int)$line['site_id'],
            'insurable' => round($insurable, 2),
            'hours' => round((float)$line['hours'], 2),
        ];
    }
    return [
        'ok' => true,
        'year' => $year,
        'user_id' => $userId,
        'org_id' => $orgId,
        'box14_employment_income' => round((float)($row['employment_income'] ?? 0) + (pe_vacation_paid($pdo, $orgId, []) ? (float)($row['vac_earned'] ?? 0) : 0), 2),
        'box16_cpp' => round((float)($row['cpp'] ?? 0), 2),
        'box16a_cpp2' => round((float)($row['cpp2'] ?? 0), 2),
        'box18_ei' => round((float)($row['ei'] ?? 0), 2),
        'box22_tax' => round((float)($row['tax'] ?? 0), 2),
        'box24_insurable' => round((float)($row['insurable'] ?? 0), 2),
        'box26_pensionable' => round((float)($row['pensionable'] ?? 0), 2),
        'cpp_er' => round((float)($row['cpp_er'] ?? 0), 2),
        'cpp2_er' => round((float)($row['cpp2_er'] ?? 0), 2),
        'ei_er' => round((float)($row['ei_er'] ?? 0), 2),
        'lines_without_edition' => (int)($row['bare'] ?? 0),
        'roe_periods' => $periods,
    ];
}
