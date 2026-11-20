<?php
/**
 * Path: /payroll_engine/payroll_engine.php
 * SINGLE SOURCE OF TRUTH for payroll CALCULATION.
 *
 * Design: "compute once, store, read forever."
 *   - This engine is called at FINALIZE time (save_split_payroll.php, bulk_allocation_desk.php)
 *     to compute employee deductions, employer contributions, and vacation for each pay line.
 *   - The computed values are STORED in payroll_finalized / payroll_balance.
 *   - Downstream pages (ledger, paystubs, T4) READ the stored values — they do NOT recalc,
 *     so historical documents never change when rates change next year.
 *
 * If a rate or rule changes, change it HERE only. Every finalize path uses this file, so
 * the numbers can never drift between the per-row saver, the bulk desk, and reports.
 *
 * All rate sources and defaults match what the savers used before this refactor, so swapping
 * to the engine produces byte-identical numbers (verified). Nothing about the math changes —
 * only where it lives.
 *
 * organizations.withhold_income_tax=0 → pe_line sets income_tax=0 (CPP/EI unchanged).
 * ctx.pay_periods → CPP uses basic exemption YBE/P (cpp_period_exempt); omit for per-day flat.
 * users.employee_type=contract → pe_line / pe_refresh_period_stat skip CPP and EI (EE+ER).
 * Hourly and monthly keep current CPP/EI. Period YBE (cpp_exempt amount) is unchanged.
 * users.ei_exempt=1 → EI employee and EI employer are 0. CPP stays on the current rules.
 * If both Contract and EI Exempt, Contract still zeros both.
 */

if (!function_exists('pe_rates')) {

require_once __DIR__ . '/t4127_bridge.php';


/**
 * Current flat rates, read once from payroll_settings (with the same defaults the savers used).
 * Pass the result around so we hit the DB once per request.
 */
function pe_rates(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $s = [];
    try {
        $row = $pdo->query("SELECT * FROM payroll_settings LIMIT 1");
        $s = $row ? ($row->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Exception $e) { $s = []; }
    $cache = [
        'cpp'    => isset($s['cpp_rate'])           ? (float)$s['cpp_rate']           : 0.0595,
        'ei'     => isset($s['ei_rate'])            ? (float)$s['ei_rate']            : 0.0163,
        'fed'    => isset($s['fed_tax_rate'])       ? (float)$s['fed_tax_rate']       : 0.15,
        'wcb'    => isset($s['wcb_rate'])           ? (float)$s['wcb_rate']           : 0.02,
        'ei_mult'=> isset($s['ei_employer_factor']) ? (float)$s['ei_employer_factor'] : 1.4,
    ];
    return $cache;
}

/**
 * Standing employment designation. hourly|monthly|contract.
 * Missing column / unknown value → hourly (normal CPP/EI).
 */
function pe_normalize_employee_type($raw): string {
    $t = strtolower(trim((string)$raw));
    if (in_array($t, ['monthly', 'salaried', 'salary', 'month'], true)) {
        return 'monthly';
    }
    if (in_array($t, ['contract', 'contractor', 'contracted', '1099'], true)) {
        return 'contract';
    }
    return 'hourly';
}

function pe_employee_type_label(string $type): string {
    $type = pe_normalize_employee_type($type);
    if ($type === 'monthly') return 'Monthly';
    if ($type === 'contract') return 'Contract';
    return 'Hourly';
}

function pe_employee_type(PDO $pdo, int $user_id): string {
    static $cache = [];
    if ($user_id <= 0) {
        return 'hourly';
    }
    if (array_key_exists($user_id, $cache)) {
        return $cache[$user_id];
    }
    $type = 'hourly';
    try {
        $st = $pdo->prepare('SELECT employee_type FROM users WHERE id = ? LIMIT 1');
        $st->execute([$user_id]);
        $type = pe_normalize_employee_type($st->fetchColumn() ?: 'hourly');
    } catch (Throwable $e) {
        $type = 'hourly';
    }
    $cache[$user_id] = $type;
    return $type;
}

function pe_user_skips_cpp_ei(PDO $pdo, int $user_id): bool {
    return pe_employee_type($pdo, $user_id) === 'contract';
}

/**
 * Profile checkbox users.ei_exempt. Skips EI employee and employer only.
 * Does not change CPP. Contract (pe_user_skips_cpp_ei) still zeros both.
 */
function pe_user_skips_ei(PDO $pdo, int $user_id): bool {
    static $cache = [];
    if ($user_id <= 0) {
        return false;
    }
    if (array_key_exists($user_id, $cache)) {
        return $cache[$user_id];
    }
    $skip = false;
    try {
        $st = $pdo->prepare('SELECT ei_exempt FROM users WHERE id = ? LIMIT 1');
        $st->execute([$user_id]);
        $skip = ((int)$st->fetchColumn()) === 1;
    } catch (Throwable $e) {
        $skip = false;
    }
    $cache[$user_id] = $skip;
    return $skip;
}

/**
 * Dated wage history. effective_date = first work day the rate applies.
 * recorded_at = when an admin saved the change.
 */
function pe_ensure_user_rate_history(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_rate_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        official_rate DECIMAL(10,2) DEFAULT NULL,
        transit_rate DECIMAL(10,2) DEFAULT NULL,
        effective_date DATE NOT NULL,
        recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        recorded_by INT DEFAULT NULL,
        note VARCHAR(255) DEFAULT NULL,
        source VARCHAR(40) NOT NULL DEFAULT 'rate_change',
        KEY idx_urh_user_eff (user_id, effective_date, recorded_at),
        KEY idx_urh_recorded (recorded_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pe_seed_user_rate_history(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    pe_ensure_user_rate_history($pdo);
    $pdo->exec("INSERT INTO user_rate_history
        (user_id, hourly_rate, official_rate, transit_rate, effective_date, recorded_at, note, source)
        SELECT u.id, u.hourly_rate, u.official_rate, u.transit_rate,
               COALESCE(NULLIF(u.StartDate, '0000-00-00'), '2020-01-01'), NOW(),
               'Opening profile rate', 'seed'
        FROM users u
        LEFT JOIN user_rate_history h ON h.user_id = u.id
        WHERE h.id IS NULL AND u.hourly_rate IS NOT NULL");
}

function pe_record_user_rate(
    PDO $pdo,
    int $user_id,
    float $hourly,
    ?float $official,
    ?float $transit,
    string $effective_date,
    ?int $recorded_by,
    string $note = '',
    string $source = 'rate_change'
): int {
    pe_ensure_user_rate_history($pdo);
    $st = $pdo->prepare("INSERT INTO user_rate_history
        (user_id, hourly_rate, official_rate, transit_rate, effective_date, recorded_at, recorded_by, note, source)
        VALUES (?,?,?,?,?,NOW(),?,?,?)");
    $st->execute([
        $user_id,
        round($hourly, 2),
        $official,
        $transit,
        $effective_date,
        $recorded_by,
        $note !== '' ? $note : null,
        $source,
    ]);
    $id = (int)$pdo->lastInsertId();
    if ($effective_date <= date('Y-m-d')) {
        $sets = ['hourly_rate = ?'];
        $params = [round($hourly, 2)];
        if ($official !== null) {
            $sets[] = 'official_rate = ?';
            $params[] = round((float)$official, 2);
        }
        if ($transit !== null) {
            $sets[] = 'transit_rate = ?';
            $params[] = round((float)$transit, 2);
        }
        $params[] = $user_id;
        $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }
    return $id;
}

/**
 * Change the effective date of the current profile rate. Does not change hourly, official, or transit.
 */
function pe_move_rate_effective_date(
    PDO $pdo,
    int $user_id,
    string $effective_date,
    ?int $recorded_by,
    string $note = '',
    string $source = 'rate_change'
): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective_date)) {
        return false;
    }
    pe_ensure_user_rate_history($pdo);
    $st = $pdo->prepare("SELECT h.id, h.effective_date
        FROM user_rate_history h
        JOIN users u ON u.id = h.user_id
        WHERE h.user_id = ?
          AND ABS(h.hourly_rate - u.hourly_rate) < 0.001
        ORDER BY h.recorded_at DESC, h.id DESC
        LIMIT 1");
    $st->execute([$user_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if ((string)$row['effective_date'] === $effective_date) {
            return false;
        }
        $note_out = $note !== ''
            ? $note
            : ('Effective date changed from ' . $row['effective_date'] . ' to ' . $effective_date . '. Rate unchanged.');
        $upd = $pdo->prepare('UPDATE user_rate_history SET effective_date = ?, recorded_at = NOW(), recorded_by = ?, note = ? WHERE id = ?');
        $upd->execute([$effective_date, $recorded_by, $note_out, (int)$row['id']]);
        return true;
    }
    $ust = $pdo->prepare('SELECT hourly_rate, official_rate, transit_rate FROM users WHERE id = ? LIMIT 1');
    $ust->execute([$user_id]);
    $u = $ust->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return false;
    }
    $ins = $pdo->prepare("INSERT INTO user_rate_history
        (user_id, hourly_rate, official_rate, transit_rate, effective_date, recorded_at, recorded_by, note, source)
        VALUES (?,?,?,?,?,NOW(),?,?,?)");
    $ins->execute([
        $user_id,
        round((float)$u['hourly_rate'], 2),
        $u['official_rate'] === null || $u['official_rate'] === '' ? null : round((float)$u['official_rate'], 2),
        $u['transit_rate'] === null || $u['transit_rate'] === '' ? null : round((float)$u['transit_rate'], 2),
        $effective_date,
        $recorded_by,
        $note !== '' ? $note : 'Effective date set. Rate unchanged.',
        $source,
    ]);
    return true;
}

function pe_rate_row_as_of(PDO $pdo, int $user_id, ?string $as_of = null): array {
    pe_ensure_user_rate_history($pdo);
    $as_of = ($as_of && preg_match('/^\d{4}-\d{2}-\d{2}/', $as_of)) ? substr($as_of, 0, 10) : date('Y-m-d');
    try {
        $st = $pdo->prepare("SELECT hourly_rate, official_rate, transit_rate, effective_date
            FROM user_rate_history
            WHERE user_id = ? AND effective_date <= ?
            ORDER BY effective_date DESC, recorded_at DESC, id DESC
            LIMIT 1");
        $st->execute([$user_id, $as_of]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    } catch (Exception $e) { /* fall back to profile */ }
    $st = $pdo->prepare("SELECT hourly_rate, official_rate, transit_rate FROM users WHERE id = ? LIMIT 1");
    $st->execute([$user_id]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: ['hourly_rate' => 0, 'official_rate' => 0, 'transit_rate' => 0];
    $u['effective_date'] = null;
    return $u;
}

/**
 * Employee hourly wage as of a work date.
 * Uses user_rate_history (latest row with effective_date <= work date),
 * then falls back to users.hourly_rate. Empty profile returns 0.00.
 */
function pe_profile_hourly_rate(PDO $pdo, int $user_id, ?string $as_of = null): float {
    $row = pe_rate_row_as_of($pdo, $user_id, $as_of);
    return round((float)($row['hourly_rate'] ?? 0), 2);
}

/**
 * Pay rate for one line, still from the employee profile / dated history:
 *   regular / balance -> hourly_rate
 *   overtime          -> hourly_rate * 1.5
 *   travel / transit  -> transit_rate if set, else hourly_rate
 */
function pe_profile_pay_rate(PDO $pdo, int $user_id, string $kind = 'regular', ?string $as_of = null): float {
    $row = pe_rate_row_as_of($pdo, $user_id, $as_of);
    $hourly = round((float)($row['hourly_rate'] ?? 0), 2);
    $k = strtolower(trim($kind));
    if ($k === 'overtime' || $k === 'ot') {
        return round($hourly * 1.5, 2);
    }
    if ($k === 'travel' || $k === 'transit') {
        $transit = round((float)($row['transit_rate'] ?? 0), 2);
        if ($transit <= 0) {
            $st = $pdo->prepare("SELECT transit_rate FROM users WHERE id = ? LIMIT 1");
            $st->execute([$user_id]);
            $transit = round((float)($st->fetchColumn() ?: 0), 2);
        }
        return $transit > 0 ? $transit : $hourly;
    }
    return $hourly;
}

/**
 * Per-SITE Ontario ESA vacation rate (as a fraction, e.g. 0.04 or 0.06).
 * 4% under 5 years of service at THIS site, 6% at 5+. Service is tracked per
 * (user_id, site_id) because each site is a separate legal employer (separate CRA number).
 * A manual override (employee_company_service.vac_percent > 0) wins. Falls back to 4%
 * (the ESA minimum) when no service record exists yet.
 */
function pe_vacation_rate(PDO $pdo, int $user_id, int $site_id, string $work_date): float {
    $province = 'ON';
    try {
        $pq = $pdo->prepare("SELECT UPPER(TRIM(province)) AS province FROM sites WHERE SId = ? LIMIT 1");
        $pq->execute([$site_id]);
        $prov = $pq->fetchColumn();
        if ($prov) $province = (string)$prov;
    } catch (Exception $e) { /* keep ON */ }

    try {
        $q = $pdo->prepare("SELECT start_date, vac_percent FROM employee_company_service WHERE user_id=? AND site_id=? LIMIT 1");
        $q->execute([$user_id, $site_id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $row = null; }

    $skLow = 3.0 / 52.0;
    $skHigh = 4.0 / 52.0;
    if (!$row) {
        return ($province === 'SK') ? $skLow : 0.04;
    }
    if ((float)$row['vac_percent'] > 0) {
        return (float)$row['vac_percent'] / 100.0;
    }
    $start = strtotime((string)($row['start_date'] ?? ''));
    $asof  = strtotime($work_date) ?: time();
    if (!$start) {
        return ($province === 'SK') ? $skLow : 0.04;
    }
    $years = ($asof - $start) / (365.25 * 86400);
    if ($province === 'SK') {
        return ($years >= 10.0) ? $skHigh : $skLow;
    }
    return ($years >= 5.0) ? 0.06 : 0.04;
}

/**
 * Whether this site's org withholds employee income tax.
 * Default true (withhold) when column/org missing — other orgs unchanged.
 */
function pe_org_withholds_income_tax(PDO $pdo, int $site_id = 0, int $org_id = 0): bool {
    static $cache = [];
    $key = $org_id > 0 ? ('o' . $org_id) : ('s' . $site_id);
    if (isset($cache[$key])) return $cache[$key];
    try {
        if ($org_id <= 0 && $site_id > 0) {
            $q = $pdo->prepare("SELECT org_id FROM sites WHERE SId=? LIMIT 1");
            $q->execute([$site_id]);
            $org_id = (int)($q->fetchColumn() ?: 0);
        }
        if ($org_id <= 0) {
            return $cache[$key] = true;
        }
        $q = $pdo->prepare("SELECT COALESCE(withhold_income_tax,1) FROM organizations WHERE id=? LIMIT 1");
        $q->execute([$org_id]);
        $v = $q->fetchColumn();
        if ($v === false) return $cache[$key] = true;
        return $cache[$key] = ((int)$v === 1);
    } catch (Exception $e) {
        return $cache[$key] = true;
    }
}


/**
 * Whether this site's org pays vacation % on every cheque (earnings) vs accrue-only.
 * Default accrue — other orgs unchanged.
 */
function pe_org_pays_vacation_each_period(PDO $pdo, int $site_id = 0, int $org_id = 0): bool {
    static $cache = [];
    $key = $org_id > 0 ? ('o' . $org_id) : ('s' . $site_id);
    if (isset($cache[$key])) return $cache[$key];
    try {
        if ($org_id <= 0 && $site_id > 0) {
            $q = $pdo->prepare("SELECT org_id FROM sites WHERE SId=? LIMIT 1");
            $q->execute([$site_id]);
            $org_id = (int)($q->fetchColumn() ?: 0);
        }
        if ($org_id <= 0) {
            return $cache[$key] = false;
        }
        $q = $pdo->prepare("SELECT COALESCE(vacation_payout_mode,'accrue') FROM organizations WHERE id=? LIMIT 1");
        $q->execute([$org_id]);
        $v = $q->fetchColumn();
        if ($v === false) return $cache[$key] = false;
        return $cache[$key] = (strtolower(trim((string)$v)) === 'pay_each_period');
    } catch (Exception $e) {
        return $cache[$key] = false;
    }
}

/**
 * Employee net for a stored finalize line.
 * Gross is wages. When the org pays vacation each period, vac is added to the cheque.
 */
function pe_employee_net(PDO $pdo, float $gross, float $cpp, float $ei, float $tax, float $vac, int $site_id = 0, int $org_id = 0, float $cpp2 = 0.0): float {
    $pay = $gross;
    if (pe_org_pays_vacation_each_period($pdo, $site_id, $org_id)) {
        $pay += $vac;
    }
    return max(0.0, round($pay - $cpp - $cpp2 - $ei - $tax, 2));
}

/**
 * Pay-period calendar window for a work date. Same rules as the Master Ledger.
 * @return array{0:string,1:string} [start, end] Y-m-d
 */
function pe_period_anchor_for_org(PDO $pdo, int $org_id): string {
    if (!function_exists('sip_org_pay_period_anchor')) {
        $lib = dirname(__DIR__) . '/engine/org_modules_lib.php';
        if (is_file($lib)) { require_once $lib; }
    }
    if ($org_id > 0 && function_exists('sip_org_pay_period_anchor')) {
        return sip_org_pay_period_anchor($pdo, $org_id);
    }
    return '2024-01-01';
}

function pe_period_window(string $work_date, string $mode, string $anchor = '2024-01-01'): array {
    $day = substr($work_date, 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        $day = date('Y-m-d');
    }
    if ($mode === 'monthly') {
        $ts = strtotime($day) ?: time();
        return [date('Y-m-01', $ts), date('Y-m-t', $ts)];
    }
    if ($mode === 'weekly' || $mode === 'bi_weekly') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) {
            $anchor = '2024-01-01';
        }
        $span = ($mode === 'weekly') ? 7 : 14;
        $d0 = new DateTimeImmutable($anchor);
        $d1 = new DateTimeImmutable($day);
        $days = (int)$d0->diff($d1)->format('%r%a');
        $block = (int)floor($days / $span);
        $start = $d0->modify(($block * $span) . ' days');
        $end = $start->modify('+' . ($span - 1) . ' days');
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }
    $ts = strtotime($day) ?: time();
    $dom = (int)date('j', $ts);
    if ($dom <= 15) {
        return [date('Y-m-01', $ts), date('Y-m-15', $ts)];
    }
    return [date('Y-m-16', $ts), date('Y-m-t', $ts)];
}

function pe_period_mode_for_site(PDO $pdo, int $site_id, int $org_id = 0): string {
    if ($org_id <= 0 && $site_id > 0) {
        try {
            $q = $pdo->prepare("SELECT org_id FROM sites WHERE SId=? LIMIT 1");
            $q->execute([$site_id]);
            $org_id = (int)($q->fetchColumn() ?: 0);
        } catch (Throwable $e) { $org_id = 0; }
    }
    if (!function_exists('sip_org_pay_period_mode')) {
        $lib = dirname(__DIR__) . '/engine/org_modules_lib.php';
        if (is_file($lib)) { require_once $lib; }
    }
    if ($org_id > 0 && function_exists('sip_org_pay_period_mode')) {
        return sip_org_pay_period_mode($pdo, $org_id);
    }
    return ($org_id === 4) ? 'bi_weekly' : 'semi_monthly';
}

function pe_cpp_period_exempt(PDO $pdo, int $site_id = 0, int $org_id = 0): float {
    if ($org_id <= 0 && $site_id > 0) {
        try {
            $q = $pdo->prepare("SELECT org_id FROM sites WHERE SId=? LIMIT 1");
            $q->execute([$site_id]);
            $org_id = (int)($q->fetchColumn() ?: 0);
        } catch (Throwable $e) { $org_id = 0; }
    }
    if (!function_exists('sip_org_has_pay_period_setting')) {
        $lib = dirname(__DIR__) . '/engine/org_modules_lib.php';
        if (is_file($lib)) { require_once $lib; }
    }
    if ($org_id <= 0 || !function_exists('sip_org_has_pay_period_setting') || !sip_org_has_pay_period_setting($pdo, $org_id)) {
        if ($org_id !== 4) { return 0.0; }
    }
    $periods = 26;
    if (function_exists('sip_org_pay_periods')) {
        $periods = (int)sip_org_pay_periods($pdo, $org_id);
    }
    if ($periods <= 0) { return 0.0; }
    return round(3500.0 / $periods, 2);
}

/**
 * Calculate CPP once for this employee + site + pay period, store it, and
 * clear per-shift cpp so dates do not each carry their own exemption.
 * Ledger / pay / stubs READ this row — they do not recalculate.
 */
function pe_refresh_period_stat(PDO $pdo, int $user_id, int $site_id, string $work_date): ?array {
    if ($user_id <= 0 || $site_id <= 0 || $work_date === '') { return null; }
    $org_id = 0;
    try {
        $q = $pdo->prepare("SELECT org_id FROM sites WHERE SId=? LIMIT 1");
        $q->execute([$site_id]);
        $org_id = (int)($q->fetchColumn() ?: 0);
    } catch (Throwable $e) { $org_id = 0; }
    $mode = pe_period_mode_for_site($pdo, $site_id, $org_id);
    $anchor = pe_period_anchor_for_org($pdo, $org_id);
    [$start, $end] = pe_period_window($work_date, $mode, $anchor);

    $sum = $pdo->prepare(
        "SELECT COALESCE(SUM(total_gross),0) AS gross, COALESCE(SUM(vac),0) AS vac,
                COALESCE(SUM(ei),0) AS ei, COALESCE(SUM(income_tax),0) AS tax
         FROM payroll_finalized
         WHERE user_id=? AND site_id=? AND work_date BETWEEN ? AND ?"
    );
    $sum->execute([$user_id, $site_id, $start, $end]);
    $s = $sum->fetch(PDO::FETCH_ASSOC) ?: [];
    $gross = round((float)$s['gross'], 2);
    $vac = round((float)$s['vac'], 2);
    $ei = round((float)$s['ei'], 2);
    $tax = round((float)$s['tax'], 2);
    if ($gross <= 0 && $vac <= 0) {
        $pdo->prepare("DELETE FROM payroll_period_stat WHERE user_id=? AND site_id=? AND period_start=? AND period_end=?")
            ->execute([$user_id, $site_id, $start, $end]);
        return null;
    }
    $rates = pe_rates($pdo);
    $vacRate = ($gross > 0.009) ? ($vac / $gross) : 0.0;
    $calc = pe_t4127_line($pdo, $gross, $vacRate, $rates, [
        'user_id' => $user_id,
        'site_id' => $site_id,
        'org_id' => $org_id,
        'work_date' => $end,
        'pay_date' => $end,
    ]);
    if ($calc === null) {
        $pensionable = $gross;
        if (pe_org_pays_vacation_each_period($pdo, $site_id, $org_id)) {
            $pensionable = round($gross + $vac, 2);
        }
        $exempt = pe_cpp_period_exempt($pdo, $site_id, $org_id);
        $cpp = round(max(0.0, $pensionable - $exempt) * $rates['cpp'], 2);
        $cpp2 = 0.0;
        $wcb = 0.0;
        $eht = 0.0;
        $edition = '';
    } else {
        $pensionable = (float)$calc['pensionable'];
        $exempt = pe_cpp_period_exempt($pdo, $site_id, $org_id);
        $cpp = (float)$calc['cpp'];
        $cpp2 = (float)$calc['cpp2'];
        $ei = (float)$calc['ei'];
        $tax = (float)$calc['income_tax'];
        $wcb = (float)$calc['wcb'];
        $eht = (float)$calc['eht'];
        $edition = (string)$calc['edition'];
    }
    $skipCppEi = pe_user_skips_cpp_ei($pdo, $user_id);
    $skipEiOnly = !$skipCppEi && pe_user_skips_ei($pdo, $user_id);
    if ($skipCppEi) {
        $cpp = 0.0;
        $cpp2 = 0.0;
        $ei = 0.0;
    } elseif ($skipEiOnly) {
        $ei = 0.0;
    }
    $net = pe_employee_net($pdo, $gross, $cpp, $ei, $tax, $vac, $site_id, $org_id, $cpp2);

    $pdo->prepare(
        "INSERT INTO payroll_period_stat
            (org_id, site_id, user_id, period_start, period_end, pensionable, cpp_exempt, cpp, cpp_er, ei, income_tax, vac, gross, net)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            org_id=VALUES(org_id), pensionable=VALUES(pensionable), cpp_exempt=VALUES(cpp_exempt),
            cpp=VALUES(cpp), cpp_er=VALUES(cpp_er), ei=VALUES(ei), income_tax=VALUES(income_tax),
            vac=VALUES(vac), gross=VALUES(gross), net=VALUES(net)"
    )->execute([$org_id, $site_id, $user_id, $start, $end, $pensionable, $exempt, $cpp, $cpp, $ei, $tax, $vac, $gross, $net]);
    if ($edition !== '' && pe_t4127_col($pdo, 'payroll_period_stat', 'deduction_edition')) {
        $pdo->prepare('UPDATE payroll_period_stat SET deduction_edition=? WHERE user_id=? AND site_id=? AND period_start=? AND period_end=?')
            ->execute([$edition, $user_id, $site_id, $start, $end]);
    }
    if (pe_t4127_col($pdo, 'payroll_period_stat', 'cpp2')) {
        $pdo->prepare('UPDATE payroll_period_stat SET cpp2=?, cpp2_er=? WHERE user_id=? AND site_id=? AND period_start=? AND period_end=?')
            ->execute([$cpp2, $cpp2, $user_id, $site_id, $start, $end]);
    }

    // CPP for the period lives on payroll_period_stat (ledgers add it back).
    // EI, tax, WCB, and CPP2 are spread across the lines so a sum of the lines matches.
    $lineRows = $pdo->prepare('SELECT id, total_gross, COALESCE(paid_amount,0) AS paid_amount FROM payroll_finalized WHERE user_id=? AND site_id=? AND work_date BETWEEN ? AND ? ORDER BY id');
    $lineRows->execute([$user_id, $site_id, $start, $end]);
    $ids = [];
    $weights = [];
    $locked = false;
    foreach ($lineRows->fetchAll(PDO::FETCH_ASSOC) as $ln) {
        if ((float)$ln['paid_amount'] > 0.02) {
            $locked = true;
        }
        $ids[] = (int)$ln['id'];
        $weights[] = (float)$ln['total_gross'];
    }
    if (!$locked && $ids) {
        $eiShares = pe_t4127_allocate($ei, $weights);
        $taxShares = pe_t4127_allocate($tax, $weights);
        $wcbShares = pe_t4127_allocate($wcb, $weights);
        $cpp2Shares = pe_t4127_allocate($cpp2, $weights);
        $eiEr = is_array($calc) ? (float)$calc['ei_er'] : round($ei * (float)$rates['ei_mult'], 2);
        $eiErShares = pe_t4127_allocate((float)$eiEr, $weights);
        $hasCpp2 = pe_t4127_col($pdo, 'payroll_finalized', 'cpp2');
        $hasEht = pe_t4127_col($pdo, 'payroll_finalized', 'eht');
        $hasEdition = pe_t4127_col($pdo, 'payroll_finalized', 'deduction_edition');
        foreach ($ids as $i => $id) {
            $sets = 'cpp=0, cpp_er=0, ei=?, ei_er=?, income_tax=?, wcb=?';
            $params = [$eiShares[$i], $eiErShares[$i], $taxShares[$i], $wcbShares[$i]];
            if ($hasCpp2) {
                $sets .= ', cpp2=?, cpp2_er=?';
                $params[] = $cpp2Shares[$i];
                $params[] = $cpp2Shares[$i];
            }
            if ($hasEht) {
                $ehtShares = $ehtShares ?? pe_t4127_allocate($eht, $weights);
                $sets .= ', eht=?';
                $params[] = $ehtShares[$i];
            }
            if ($hasEdition) {
                $sets .= ', deduction_edition=?';
                $params[] = substr($edition, 0, 80);
            }
            $params[] = $id;
            $pdo->prepare("UPDATE payroll_finalized SET $sets WHERE id=?")->execute($params);
        }
    }

    return [
        'org_id'=>$org_id,'site_id'=>$site_id,'user_id'=>$user_id,
        'period_start'=>$start,'period_end'=>$end,
        'pensionable'=>$pensionable,'cpp_exempt'=>$exempt,
        'cpp'=>$cpp,'cpp_er'=>$cpp,'cpp2'=>$cpp2,'ei'=>$ei,'income_tax'=>$tax,
        'vac'=>$vac,'gross'=>$gross,'net'=>$net,'wcb'=>$wcb,'eht'=>$eht,
        'edition'=>$edition,'locked'=>$locked,
    ];
}

function pe_read_period_stats(PDO $pdo, string $from_date, string $to_date, array $user_ids = [], array $site_ids = []): array {
    $sql = "SELECT * FROM payroll_period_stat WHERE period_start <= ? AND period_end >= ?";
    $p = [$to_date, $from_date];
    if ($user_ids) {
        $sql .= " AND user_id IN (" . implode(',', array_fill(0, count($user_ids), '?')) . ")";
        foreach ($user_ids as $u) { $p[] = (int)$u; }
    }
    if ($site_ids) {
        $sql .= " AND site_id IN (" . implode(',', array_fill(0, count($site_ids), '?')) . ")";
        foreach ($site_ids as $s) { $p[] = (int)$s; }
    }
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $out = [];
    foreach ($st as $row) {
        $k = ((int)$row['user_id']) . '|' . ((int)$row['site_id']);
        if (!isset($out[$k])) {
            $out[$k] = ['cpp'=>0.0,'cpp_er'=>0.0,'cpp2'=>0.0,'cpp2_er'=>0.0,'ei'=>0.0,'tax'=>0.0,'vac'=>0.0,'gross'=>0.0,'net'=>0.0,'rows'=>[]];
        }
        $out[$k]['cpp'] += (float)$row['cpp'];
        $out[$k]['cpp_er'] += (float)$row['cpp_er'];
        $out[$k]['cpp2'] += (float)($row['cpp2'] ?? 0);
        $out[$k]['cpp2_er'] += (float)($row['cpp2_er'] ?? 0);
        $out[$k]['ei'] += (float)$row['ei'];
        $out[$k]['tax'] += (float)$row['income_tax'];
        $out[$k]['vac'] += (float)$row['vac'];
        $out[$k]['gross'] += (float)$row['gross'];
        $out[$k]['net'] += (float)$row['net'];
        $out[$k]['rows'][] = $row;
    }
    return $out;
}

/**
 * Compute the full cost breakdown for ONE pay line.
 *
 * @param float  $gross      the line's gross pay
 * @param float  $vac_rate   vacation fraction for this line (from pe_vacation_rate)
 * @param array  $rates      from pe_rates()
 * @param array  $ctx        optional: pdo, site_id, org_id — used for withhold_income_tax
 * @return array  cpp, ei, income_tax, cpp_er, ei_er, wcb, vac, ee_total, er_total, net
 */
function pe_line(float $gross, float $vac_rate, array $rates, array $ctx = []): array {
    $pdoCtx = $ctx['pdo'] ?? null;
    if ($pdoCtx instanceof PDO) {
        $t4127 = pe_t4127_line($pdoCtx, $gross, $vac_rate, $rates, $ctx);
        if (is_array($t4127)) {
            return $t4127;
        }
    }
    $base = round(max(0.0, $gross), 2);
    $vac  = round($base * $vac_rate, 2);
    if (array_key_exists('force_vac_rate', $ctx)) {
        $vac = round($base * (float)$ctx['force_vac_rate'], 2);
    }
    $pdoCtx = $ctx['pdo'] ?? null;
    $site_id = (int)($ctx['site_id'] ?? 0);
    $org_id = (int)($ctx['org_id'] ?? 0);
    $payVac = false;
    if ($pdoCtx instanceof PDO && function_exists('pe_org_pays_vacation_each_period')) {
        $payVac = pe_org_pays_vacation_each_period($pdoCtx, $site_id, $org_id);
    }
    // When paying each period, vac % is wages: increases gross and flows through CPP/EI/WCB.
    $pay_gross = $payVac ? round($base + $vac, 2) : $base;

    // Shift lines do NOT take the Year's Basic Exemption. That is calculated once
    // per pay period and stored on payroll_period_stat (pe_refresh_period_stat).
    $cppBase = $pay_gross;
    if (array_key_exists('cpp_exempt_amount', $ctx)) {
        $cppBase = max(0.0, $pay_gross - (float)$ctx['cpp_exempt_amount']);
    }
    $cpp = round($cppBase * $rates['cpp'], 2);
    $ei  = round($pay_gross * $rates['ei'],  2);
    $tax = round($pay_gross * $rates['fed'], 2);
    // Org policy: skip income tax only (CPP/EI/WCB unchanged).
    if ($pdoCtx instanceof PDO) {
        if (!pe_org_withholds_income_tax($pdoCtx, $site_id, $org_id)) {
            $tax = 0.0;
        }
    }
    if (array_key_exists('force_income_tax', $ctx)) {
        $tax = round((float)$ctx['force_income_tax'], 2);
    }
    $uid = (int)($ctx['user_id'] ?? 0);
    $skipCppEi = !empty($ctx['skip_cpp_ei']);
    if (!$skipCppEi && $pdoCtx instanceof PDO && $uid > 0 && pe_user_skips_cpp_ei($pdoCtx, $uid)) {
        $skipCppEi = true;
    }
    if ($skipCppEi) {
        $cpp = 0.0;
        $ei = 0.0;
    } elseif ($pdoCtx instanceof PDO && $uid > 0 && pe_user_skips_ei($pdoCtx, $uid)) {
        $ei = 0.0;
    }
    $cpp_er = $cpp;                                  // employer matches CPP 1:1
    $ei_er  = round($ei * $rates['ei_mult'], 2);     // employer EI premium (e.g. 1.4x)
    $wcb    = round($pay_gross * $rates['wcb'], 2);
    $vac_er = $payVac ? 0.0 : $vac;                  // paid vac is not an extra ER accrual
    $ee_total = round($cpp + $ei + $tax, 2);
    $er_total = round($cpp_er + $ei_er + $wcb + $vac_er, 2);
    return [
        'cpp'        => $cpp,
        'cpp2'       => 0.0,
        'ei'         => $ei,
        'income_tax' => $tax,
        'cpp_er'     => $cpp_er,
        'cpp2_er'    => 0.0,
        'ei_er'      => $ei_er,
        'wcb'        => $wcb,
        'vac'        => $vac,
        'vac_paid'   => $payVac ? $vac : 0.0,
        'vac_accrued'=> $payVac ? 0.0 : $vac,
        'pay_gross'  => $pay_gross,
        'base_gross' => $base,
        'vac_payout' => $payVac,
        'ee_total'   => $ee_total,
        'er_total'   => $er_total,
        'net'        => round($pay_gross - $ee_total, 2),
    ];
}

/**
 * Convenience: compute a line straight from its context (does the vacation lookup for you).
 */
function pe_line_for(PDO $pdo, int $user_id, int $site_id, string $work_date, float $gross): array {
    $rates    = pe_rates($pdo);
    $vac_rate = pe_vacation_rate($pdo, $user_id, $site_id, $work_date);
    $out = pe_line($gross, $vac_rate, $rates, [
        'pdo' => $pdo,
        'user_id' => $user_id,
        'site_id' => $site_id,
        'work_date' => $work_date,
    ]);
    $out['vac_rate'] = $vac_rate;
    return $out;
}

/**
 * The employment-standards rule row for a SITE's province (sites.province).
 * Falls back to Ontario's confirmed values if the site/province/table isn't found,
 * so a missing row never silently produces zero-OT.
 */
function pe_province_rule(PDO $pdo, int $site_id): array {
    $on_default = [
        'province'=>'ON','ot_daily_hours'=>null,'ot_weekly_hours'=>44.0,
        'ot_multiplier'=>1.5,'ot_method'=>'weekly_only','verified'=>1,
    ];
    try {
        $q = $pdo->prepare(
            "SELECT pr.* FROM sites s
             JOIN province_rules pr ON pr.province = s.province
             WHERE s.Sid = ? LIMIT 1"
        );
        $q->execute([$site_id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $row = null; }
    if (!$row) return $on_default;
    return [
        'province'        => $row['province'],
        'ot_daily_hours'  => $row['ot_daily_hours'] !== null ? (float)$row['ot_daily_hours'] : null,
        'ot_weekly_hours' => $row['ot_weekly_hours'] !== null ? (float)$row['ot_weekly_hours'] : null,
        'ot_multiplier'   => (float)$row['ot_multiplier'],
        'ot_method'       => $row['ot_method'],
        'verified'        => (int)$row['verified'],
    ];
}

/**
 * Split a work week's hours into REGULAR and OVERTIME per the site's province rule.
 *
 * @param array $hours_by_day  ordered list of daily hour totals for the work week,
 *                             e.g. [8, 10, 8, 12, 9] (one entry per worked day)
 * @return array ['reg'=>float, 'ot'=>float, 'province'=>string, 'verified'=>int, 'method'=>string]
 *
 * Methods:
 *   weekly_only      -> ot = max(0, week_total - weekly_threshold)
 *   daily_and_weekly -> daily OT (hours over daily threshold each day) PLUS weekly OT on the
 *                       remaining regular hours over the weekly threshold (no double count)
 *   greater_of       -> ot = max(total daily OT, weekly OT)  [Alberta]
 *
 * NOTE: this returns HOURS only. The caller prices regular hours at the base rate and OT at
 * base * ot_multiplier. BC's second daily tier (2x after 12h) is left for a future refinement;
 * placeholder provinces are flagged verified=0 so callers can warn before trusting the split.
 */
function pe_overtime(PDO $pdo, int $site_id, array $hours_by_day): array {
    $rule = pe_province_rule($pdo, $site_id);
    $total = array_sum(array_map('floatval', $hours_by_day));
    $wk = $rule['ot_weekly_hours'];
    $dy = $rule['ot_daily_hours'];

    $daily_ot = 0.0;
    if ($dy !== null) {
        foreach ($hours_by_day as $h) { $daily_ot += max(0.0, (float)$h - $dy); }
    }
    $weekly_ot = ($wk !== null) ? max(0.0, $total - $wk) : 0.0;

    switch ($rule['ot_method']) {
        case 'weekly_only':
            $ot = $weekly_ot;
            break;
        case 'greater_of': // Alberta
            $ot = max($daily_ot, $weekly_ot);
            break;
        case 'daily_and_weekly': // BC-style: daily OT, then weekly OT on hours not already daily-OT
            $reg_after_daily = $total - $daily_ot;
            $extra_weekly = ($wk !== null) ? max(0.0, $reg_after_daily - $wk) : 0.0;
            $ot = $daily_ot + $extra_weekly;
            break;
        default:
            $ot = $weekly_ot;
    }
    $ot  = round(min($ot, $total), 2);
    $reg = round($total - $ot, 2);
    return ['reg'=>$reg, 'ot'=>$ot, 'province'=>$rule['province'], 'verified'=>$rule['verified'], 'method'=>$rule['ot_method']];
}

} // end function_exists guard
