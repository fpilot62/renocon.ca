<?php
/**
 * Partial-payment review.
 * Reads admin_outstanding_wages and payroll_finalized.
 * Writes only admin_partial_payment_reviews.
 * It does not create pay runs and it does not mark payroll rows paid.
 */

function ppr_methods(): array
{
    return ['Cash', 'Cheque', 'E-Transfer'];
}

function ppr_sql_returns_rows(string $stmt): bool
{
    $head = strtoupper((string)strtok(ltrim($stmt), " \t\r\n"));
    return in_array($head, ['SELECT', 'SHOW', 'EXECUTE', 'EXPLAIN', 'DESCRIBE', 'WITH'], true);
}

function ppr_finish_result($result): void
{
    if (!$result instanceof PDOStatement) {
        return;
    }
    $result->fetchAll();
    $result->closeCursor();
}

/** One statement. A query that returns rows is read to the end before the next statement. */
function ppr_run_statement(PDO $pdo, string $stmt): void
{
    $stmt = trim($stmt);
    if ($stmt === '') {
        return;
    }
    if (ppr_sql_returns_rows($stmt)) {
        ppr_finish_result($pdo->query($stmt));
        return;
    }
    $pdo->exec($stmt);
}

function ppr_exec_sql_file(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('The partial-payment review migration file is missing.');
    }
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (preg_split('/;/', (string)$sql) as $stmt) {
        ppr_run_statement($pdo, $stmt);
    }
}

function ppr_ensure(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }
    ppr_exec_sql_file($pdo, dirname(__DIR__) . '/migrations/20261116_admin_partial_payment_reviews.sql');
    ppr_ensure_column(
        $pdo,
        'admin_partial_payment_reviews',
        'son_sheet_gross',
        'ALTER TABLE admin_partial_payment_reviews ADD COLUMN son_sheet_gross DECIMAL(12,2) NULL DEFAULT NULL AFTER period'
    );
    ppr_ensure_column(
        $pdo,
        'admin_partial_payment_reviews',
        'skip_income_tax',
        'ALTER TABLE admin_partial_payment_reviews ADD COLUMN skip_income_tax TINYINT(1) NULL DEFAULT NULL AFTER son_sheet_gross'
    );
    $ready = true;
}

/** Add one column on the review table when it is missing. The check is read to the end. */
function ppr_ensure_column(PDO $pdo, string $table, string $column, string $alter): void
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        throw new RuntimeException('Bad table or column name.');
    }
    if (!preg_match('/^ALTER\\s+TABLE\\s+`?' . $table . '`?\\s+ADD\\s+COLUMN\\s+`?' . $column . '`?\\b/i', $alter)) {
        throw new RuntimeException('The column change is not an ADD COLUMN on the review table.');
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$table, $column]);
    $have = (int)$st->fetchColumn();
    $st->closeCursor();
    if ($have === 0) {
        $pdo->exec($alter);
    }
}

function ppr_text(string $value): string
{
    $value = str_replace(["\xc2\xa0", ','], [' ', ''], $value);
    return strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
}

function ppr_candidate_text(array $row): string
{
    return ppr_text((string)($row['notes'] ?? '') . ' ' . (string)($row['status'] ?? ''));
}

function ppr_is_candidate(array $row): bool
{
    $text = ppr_candidate_text($row);
    if (preg_match('/half\s+paid/', $text)) {
        return true;
    }
    return (bool)preg_match('/net\s*\$?\s*(?<![0-9])1000(?:\.00)?(?![0-9])\s+paid/', $text);
}

/**
 * Payment written on the son sheet.
 * "net 1000 paid" is 1000. "half paid" is not guessed.
 *
 * @return array{amount:?float,basis:string,unknown:bool}
 */
function ppr_sheet_payment(array $row): array
{
    $text = ppr_candidate_text($row);
    if (preg_match('/net\s*\$?\s*((?<![0-9])1000(?:\.00)?)(?![0-9])\s+paid/', $text, $m)) {
        return ['amount' => round((float)$m[1], 2), 'basis' => 'net 1000 paid', 'unknown' => false];
    }
    if (preg_match('/half\s+paid/', $text)) {
        return ['amount' => null, 'basis' => 'amount unknown, enter it', 'unknown' => true];
    }
    return ['amount' => null, 'basis' => '', 'unknown' => false];
}

function ppr_load_payroll_engine(): void
{
    if (function_exists('pe_line') && function_exists('pe_rates')) {
        return;
    }
    $path = dirname(__DIR__) . '/payroll_engine/payroll_engine.php';
    if (!is_file($path)) {
        throw new RuntimeException('payroll_engine/payroll_engine.php is not available.');
    }
    require_once $path;
    if (!function_exists('pe_line') || !function_exists('pe_rates')) {
        throw new RuntimeException('pe_line is not available.');
    }
}

/**
 * CPP, EI, income tax, and net for one period gross.
 * Uses pe_line, the same function finalization uses, with the period CPP
 * exemption from pe_cpp_period_exempt. Does not write payroll rows.
 *
 * @return array{cpp:float,ei:float,income_tax:float,net:float,cpp_exempt:float,engine:string}
 */
/** Saved choice wins. A review that has not been saved yet skips income tax. */
function ppr_skip_income_tax(?array $saved): bool
{
    if ($saved && array_key_exists('skip_income_tax', $saved) && $saved['skip_income_tax'] !== null && $saved['skip_income_tax'] !== '') {
        return (int)$saved['skip_income_tax'] === 1;
    }
    return true;
}

function ppr_period_line(float $gross, float $vacRate, array $rates, float $exempt, int $userId, int $siteId, string $asOf, int $orgId, ?PDO $pdo = null, bool $skipIncomeTax = false): array
{
    $ctx = [
        'user_id' => $userId,
        'site_id' => $siteId,
        'org_id' => $orgId,
        'work_date' => $asOf,
        'cpp_exempt_amount' => $exempt,
    ];
    if ($pdo instanceof PDO) {
        $ctx['pdo'] = $pdo;
    }
    if ($skipIncomeTax) {
        $ctx['force_income_tax'] = 0.0;
    }
    $line = pe_line($gross, $vacRate, $rates, $ctx);
    $cpp = round((float)$line['cpp'], 2);
    $ei = round((float)$line['ei'], 2);
    $tax = $skipIncomeTax ? 0.0 : round((float)$line['income_tax'], 2);
    $net = $skipIncomeTax ? round($gross - $cpp - $ei, 2) : round((float)$line['net'], 2);
    return [
        'cpp' => $cpp,
        'ei' => $ei,
        'income_tax' => $tax,
        'net' => $net,
        'cpp_exempt' => round($exempt, 2),
        'skip_income_tax' => $skipIncomeTax ? 1 : 0,
        'engine' => 'pe_line',
    ];
}

function ppr_compute_period_deductions(PDO $pdo, float $gross, int $userId, int $siteId, string $asOf, int $orgId, bool $skipIncomeTax = true): array
{
    ppr_load_payroll_engine();
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY') && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }
    $asOf = ppr_valid_date($asOf) ? $asOf : date('Y-m-d');
    $rates = pe_rates($pdo);
    $vacRate = function_exists('pe_vacation_rate') ? pe_vacation_rate($pdo, $userId, $siteId, $asOf) : 0.0;
    $exempt = function_exists('pe_cpp_period_exempt') ? pe_cpp_period_exempt($pdo, $siteId, $orgId) : 0.0;
    return ppr_period_line($gross, $vacRate, $rates, $exempt, $userId, $siteId, $asOf, $orgId, $pdo, $skipIncomeTax);
}

function ppr_review_gross(array $wage, ?array $saved): ?float
{
    if ($saved && array_key_exists('son_sheet_gross', $saved) && $saved['son_sheet_gross'] !== null && $saved['son_sheet_gross'] !== '') {
        return round((float)$saved['son_sheet_gross'], 2);
    }
    if (isset($wage['gross']) && $wage['gross'] !== '' && is_numeric((string)$wage['gross'])) {
        return round((float)$wage['gross'], 2);
    }
    return null;
}

/** @return array{0:int,1:int} user id, site id */
function ppr_calc_identity(array $wage, array $lines): array
{
    $userId = (int)($wage['user_id'] ?? 0);
    $siteId = (int)($wage['site_id'] ?? 0);
    $users = [];
    $sites = [];
    foreach ($lines as $line) {
        if (ppr_name_matches((string)($line['employee_name'] ?? ''), (string)($wage['employee_name'] ?? ''))) {
            $uid = (int)($line['user_id'] ?? 0);
            $sid = (int)($line['site_id'] ?? 0);
            if ($uid > 0) {
                $users[$uid] = true;
            }
            if ($sid > 0) {
                $sites[$sid] = true;
            }
        }
    }
    if ($userId <= 0 && count($users) === 1) {
        $userId = (int)array_key_first($users);
    }
    if ($siteId <= 0 && count($sites) === 1) {
        $siteId = (int)array_key_first($sites);
    }
    return [$userId, $siteId];
}

function ppr_review_for(PDO $pdo, int $orgId, array $wage, array $lines, ?array $saved): array
{
    $gross = ppr_review_gross($wage, $saved);
    [$userId, $siteId] = ppr_calc_identity($wage, $lines);
    $bounds = ppr_period_bounds($wage);
    $asOf = $bounds['end'] ?: ($bounds['start'] ?: date('Y-m-d'));
    $deductions = null;
    $calcError = '';
    $skipTax = ppr_skip_income_tax($saved);
    if ($gross !== null) {
        try {
            $deductions = ppr_compute_period_deductions($pdo, $gross, $userId, $siteId, $asOf, $orgId, $skipTax);
        } catch (Throwable $e) {
            $calcError = $e->getMessage();
        }
    }
    $view = ppr_build_view($wage, $lines, $saved, $deductions);
    if ($calcError !== '') {
        $view['flags'][] = 'Net was not calculated. ' . $calcError;
    }
    if ($userId <= 0 && $gross !== null && $calcError === '') {
        $view['flags'][] = 'No single employee match, so contract and EI-exempt rules were not applied.';
    }
    return $view;
}

function ppr_first_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '') {
        return '';
    }
    $parts = preg_split('/\s+/u', $name);
    return (string)($parts[0] ?? '');
}

function ppr_name_matches(string $fullName, string $employeeName): bool
{
    $want = ppr_first_name($employeeName);
    $got = ppr_first_name($fullName);
    if ($want === '' || $got === '') {
        return false;
    }
    return strcasecmp($want, $got) === 0;
}

function ppr_like_pattern(string $employeeName): string
{
    $first = ppr_first_name($employeeName);
    $first = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $first);
    return $first . '%';
}

function ppr_valid_date(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $value));
    return checkdate($m, $d, $y);
}

/** @return array{start:?string,end:?string} */
function ppr_period_bounds(array $row): array
{
    $start = substr((string)($row['period_start'] ?? ''), 0, 10);
    $end = substr((string)($row['period_end'] ?? ''), 0, 10);
    if (ppr_valid_date($start) && ppr_valid_date($end)) {
        if ($end < $start) {
            return ['start' => $end, 'end' => $start];
        }
        return ['start' => $start, 'end' => $end];
    }
    if (function_exists('ow_parse_period')) {
        $parsed = ow_parse_period((string)($row['pay_period'] ?? ''));
        $start = (string)($parsed['start'] ?? '');
        $end = (string)($parsed['end'] ?? '');
        if (ppr_valid_date($start) && ppr_valid_date($end)) {
            return ['start' => $start, 'end' => $end];
        }
    }
    return ['start' => null, 'end' => null];
}

function ppr_line_net(array $line): float
{
    $gross = (float)($line['total_gross'] ?? 0);
    $ded = (float)($line['cpp'] ?? 0) + (float)($line['ei'] ?? 0) + (float)($line['income_tax'] ?? 0);
    return round(max(0, $gross - $ded), 2);
}

function ppr_line_unpaid(array $line): float
{
    return round(max(0, ppr_line_net($line) - (float)($line['paid_amount'] ?? 0)), 2);
}

function ppr_zero_cpp(array $line): bool
{
    return abs(round((float)($line['cpp'] ?? 0), 2)) < 0.005;
}

/** Line ids that share user, work date, and gross with another line. */
function ppr_duplicate_ids(array $lines): array
{
    $groups = [];
    foreach ($lines as $line) {
        $day = substr((string)($line['work_date'] ?? ''), 0, 10);
        $gross = number_format(round((float)($line['total_gross'] ?? 0), 2), 2, '.', '');
        $key = (int)($line['user_id'] ?? 0) . '|' . $day . '|' . $gross;
        $groups[$key][] = (int)($line['id'] ?? 0);
    }
    $ids = [];
    foreach ($groups as $group) {
        if (count($group) < 2) {
            continue;
        }
        foreach ($group as $id) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/**
 * Calendar dates in the period with no finalized line.
 * A period longer than 31 days is not expanded.
 *
 * @return array{known:bool,dates:array<int,string>}
 */
function ppr_missing_dates(?string $start, ?string $end, array $lines): array
{
    if ($start === null || $end === null || !ppr_valid_date($start) || !ppr_valid_date($end) || $end < $start) {
        return ['known' => false, 'dates' => []];
    }
    $have = [];
    foreach ($lines as $line) {
        $day = substr((string)($line['work_date'] ?? ''), 0, 10);
        if (ppr_valid_date($day)) {
            $have[$day] = true;
        }
    }
    $missing = [];
    $cur = strtotime($start . ' 12:00:00');
    $last = strtotime($end . ' 12:00:00');
    $guard = 0;
    while ($cur !== false && $last !== false && $cur <= $last && $guard < 31) {
        $ymd = date('Y-m-d', $cur);
        if (!isset($have[$ymd])) {
            $missing[] = $ymd;
        }
        $cur = strtotime('+1 day', $cur);
        $guard++;
    }
    if ($guard >= 31 && $cur !== false && $last !== false && $cur <= $last) {
        return ['known' => false, 'dates' => []];
    }
    return ['known' => true, 'dates' => $missing];
}

function ppr_build_view(array $wage, array $lines, ?array $saved, ?array $deductions = null): array
{
    $sheet = ppr_sheet_payment($wage);
    $bounds = ppr_period_bounds($wage);
    $kept = [];
    foreach ($lines as $line) {
        if (ppr_name_matches((string)($line['employee_name'] ?? ''), (string)($wage['employee_name'] ?? ''))) {
            $kept[] = $line;
        }
    }
    $dup = array_flip(ppr_duplicate_ids($kept));
    $missing = ppr_missing_dates($bounds['start'], $bounds['end'], $kept);
    $users = [];
    $totals = ['gross' => 0.0, 'cpp' => 0.0, 'ei' => 0.0, 'tax' => 0.0, 'net' => 0.0, 'paid' => 0.0, 'unpaid' => 0.0, 'count' => 0];
    $zeroIds = [];
    $annotated = [];
    foreach ($kept as $line) {
        $id = (int)($line['id'] ?? 0);
        $net = ppr_line_net($line);
        $unpaid = ppr_line_unpaid($line);
        $zero = ppr_zero_cpp($line);
        if ($zero) {
            $zeroIds[] = $id;
        }
        $users[(int)($line['user_id'] ?? 0)] = true;
        $totals['gross'] += (float)($line['total_gross'] ?? 0);
        $totals['cpp'] += (float)($line['cpp'] ?? 0);
        $totals['ei'] += (float)($line['ei'] ?? 0);
        $totals['tax'] += (float)($line['income_tax'] ?? 0);
        $totals['net'] += $net;
        $totals['paid'] += (float)($line['paid_amount'] ?? 0);
        $totals['unpaid'] += $unpaid;
        $totals['count']++;
        $line['net'] = $net;
        $line['unpaid'] = $unpaid;
        $line['duplicate'] = isset($dup[$id]);
        $line['zero_cpp'] = $zero;
        $annotated[] = $line;
    }
    foreach ($totals as $key => $value) {
        if ($key === 'count') {
            continue;
        }
        $totals[$key] = round($value, 2);
    }
    $proposed = $sheet['amount'];
    $computedNet = null;
    if (is_array($deductions) && isset($deductions['net']) && is_numeric((string)$deductions['net'])) {
        $computedNet = round((float)$deductions['net'], 2);
    }
    $shiftNotes = ppr_shift_notes($saved['shift_notes'] ?? null);
    $amount = $proposed;
    $method = 'Cash';
    $date = '';
    $site = (string)($wage['store_name'] ?? '');
    $verified = 0;
    $notes = '';
    if ($saved) {
        if ($saved['amount'] !== null && $saved['amount'] !== '') {
            $amount = round((float)$saved['amount'], 2);
        }
        if (in_array((string)($saved['method'] ?? ''), ppr_methods(), true)) {
            $method = (string)$saved['method'];
        }
        $date = substr((string)($saved['payment_date'] ?? ''), 0, 10);
        if (!ppr_valid_date($date)) {
            $date = '';
        }
        if (trim((string)($saved['site_paid_from'] ?? '')) !== '') {
            $site = (string)$saved['site_paid_from'];
        }
        $verified = (int)($saved['verified'] ?? 0) === 1 ? 1 : 0;
        $notes = (string)($saved['notes'] ?? '');
    }
    $payAmount = $amount;
    $short = $payAmount !== null && $computedNet !== null && ($computedNet + 0.009 < $payAmount);
    $gross = ppr_review_gross($wage, $saved);
    $flags = [];
    if ($dup) {
        $flags[] = 'Duplicate lines share a person, date, and gross.';
    }
    if (!$missing['known']) {
        $flags[] = 'Pay period dates are missing, so missing days were not listed.';
    } elseif ($missing['dates']) {
        $flags[] = 'Missing finalized lines on ' . count($missing['dates']) . ' date(s) in the period.';
    }
    if ($zeroIds) {
        $flags[] = 'CPP is zero on ' . count($zeroIds) . ' line(s).';
    }
    if (!empty($sheet['unknown']) && $payAmount === null) {
        $flags[] = 'amount unknown, enter it';
    }
    if ($short) {
        $flags[] = 'Computed net is less than the payment.';
    }
    if (count($users) > 1) {
        $flags[] = 'More than one employee matches this first name.';
    }
    if (!$kept && $missing['known']) {
        $flags[] = 'No finalized payroll lines in this period.';
    }
    return [
        'wage' => $wage,
        'sheet' => $sheet,
        'bounds' => $bounds,
        'lines' => $annotated,
        'totals' => $totals,
        'missing_dates' => $missing['dates'],
        'period_known' => $missing['known'],
        'short' => $short,
        'deductions' => $deductions,
        'gross' => $gross,
        'flags' => $flags,
        'shift_notes' => $shiftNotes,
        'form' => [
            'payment_date' => $date,
            'site_paid_from' => $site,
            'amount' => $amount,
            'method' => $method,
            'verified' => $verified,
            'notes' => $notes,
            'son_sheet_gross' => $gross,
            'skip_income_tax' => ppr_skip_income_tax($saved) ? 1 : 0,
        ],
    ];
}

function ppr_shift_notes($raw): array
{
    if (is_array($raw)) {
        $data = $raw;
    } else {
        $data = json_decode((string)$raw, true);
    }
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $id => $bits) {
        $id = (int)$id;
        if ($id <= 0 || !is_array($bits)) {
            continue;
        }
        $note = trim((string)($bits['site_note'] ?? ''));
        if (strlen($note) > 255) {
            $note = substr($note, 0, 255);
        }
        $out[$id] = [
            'verified' => ((string)($bits['verified'] ?? '') === '1' || (int)($bits['verified'] ?? 0) === 1) ? 1 : 0,
            'site_note' => $note,
        ];
    }
    return $out;
}

function ppr_clip(string $value, int $max): string
{
    $value = trim($value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max, 'UTF-8');
    }
    return substr($value, 0, $max);
}

/**
 * @param array<int,int> $allowedLineIds
 * @return array{ok:bool,error:string,fields:array}
 */
function ppr_parse_save(array $post, array $allowedLineIds): array
{
    $errors = [];
    $date = substr(trim((string)($post['payment_date'] ?? '')), 0, 10);
    if ($date === '') {
        $date = null;
    } elseif (!ppr_valid_date($date)) {
        $errors[] = 'Payment date must be a real YYYY-MM-DD date.';
        $date = null;
    }
    $site = ppr_clip((string)($post['site_paid_from'] ?? ''), 160);
    $amountRaw = str_replace(['$', ',', ' '], '', trim((string)($post['amount'] ?? '')));
    $amount = null;
    if ($amountRaw === '') {
        $amount = null;
    } elseif (!is_numeric($amountRaw)) {
        $errors[] = 'Amount must be a number.';
    } else {
        $amount = round((float)$amountRaw, 2);
        if ($amount < 0 || $amount > 1000000) {
            $errors[] = 'Amount is out of range.';
        }
    }
    $grossRaw = str_replace(['$', ',', ' '], '', trim((string)($post['son_sheet_gross'] ?? '')));
    $sonGross = null;
    if ($grossRaw === '' || !is_numeric($grossRaw)) {
        $errors[] = 'Son-sheet gross must be a number.';
    } else {
        $sonGross = round((float)$grossRaw, 2);
        if ($sonGross < 0 || $sonGross > 1000000) {
            $errors[] = 'Son-sheet gross is out of range.';
        }
    }
    $method = trim((string)($post['method'] ?? ''));
    if (!in_array($method, ppr_methods(), true)) {
        $errors[] = 'Method must be Cash, Cheque, or E-Transfer.';
    }
    $notes = ppr_clip((string)($post['notes'] ?? ''), 4000);
    $allowed = [];
    foreach ($allowedLineIds as $id) {
        $allowed[(int)$id] = true;
    }
    $posted = $post['shift'] ?? [];
    if (!is_array($posted)) {
        $posted = [];
    }
    $shifts = [];
    foreach ($posted as $id => $bits) {
        $id = (int)$id;
        if ($id <= 0 || !isset($allowed[$id]) || !is_array($bits)) {
            continue;
        }
        $shifts[(string)$id] = [
            'verified' => ((string)($bits['verified'] ?? '') === '1') ? 1 : 0,
            'site_note' => ppr_clip((string)($bits['site_note'] ?? ''), 255),
        ];
    }
    return [
        'ok' => $errors === [],
        'error' => implode(' ', $errors),
        'fields' => [
            'payment_date' => $date,
            'site_paid_from' => $site,
            'amount' => $amount,
            'method' => $method,
            'verified' => ((string)($post['verified'] ?? '') === '1') ? 1 : 0,
            'notes' => $notes,
            'shift_notes' => $shifts,
            'son_sheet_gross' => $sonGross,
            'skip_income_tax' => ((string)($post['skip_income_tax'] ?? '') === '1') ? 1 : 0,
        ],
    ];
}

function ppr_fetch_candidates(PDO $pdo, int $orgId): array
{
    $st = $pdo->prepare(
        'SELECT id, org_id, site_id, store_name, employee_name, user_id, pay_period,
                period_start, period_end, gross, net, status, notes
         FROM admin_outstanding_wages
         WHERE org_id = ?
         ORDER BY store_name ASC, employee_name ASC, id ASC'
    );
    $st->execute([$orgId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->closeCursor();
    $out = [];
    foreach ($rows as $row) {
        if (ppr_is_candidate($row)) {
            $out[] = $row;
        }
    }
    return $out;
}

function ppr_fetch_lines(PDO $pdo, int $orgId, string $employeeName, string $start, string $end): array
{
    if (!ppr_valid_date($start) || !ppr_valid_date($end) || ppr_first_name($employeeName) === '') {
        return [];
    }
    $live = function_exists('att_finalized_live_predicate') ? att_finalized_live_predicate($pdo, 'f') : '1=1';
    $sql = "SELECT f.id, f.user_id, f.site_id, f.work_date, f.total_gross, f.cpp, f.ei, f.income_tax,
                   f.paid_amount, f.payment_status, u.Name AS employee_name, s.nick_name AS site_name
            FROM payroll_finalized f
            INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
            LEFT JOIN sites s ON s.SId = f.site_id
            WHERE u.Name LIKE ? AND f.work_date BETWEEN ? AND ? AND {$live}
            ORDER BY f.work_date ASC, f.id ASC";
    $st = $pdo->prepare($sql);
    $st->execute([$orgId, ppr_like_pattern($employeeName), $start, $end]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->closeCursor();
    $out = [];
    foreach ($rows as $row) {
        if (ppr_name_matches((string)$row['employee_name'], $employeeName)) {
            $out[] = $row;
        }
    }
    return $out;
}

function ppr_load_reviews(PDO $pdo, int $orgId): array
{
    $st = $pdo->prepare('SELECT * FROM admin_partial_payment_reviews WHERE org_id = ?');
    $st->execute([$orgId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->closeCursor();
    $out = [];
    foreach ($rows as $row) {
        $out[(int)$row['wage_id']] = $row;
    }
    return $out;
}

function ppr_save_review(PDO $pdo, int $orgId, array $wage, array $fields, int $actorId): void
{
    $json = json_encode($fields['shift_notes'], JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Shift notes could not be stored.');
    }
    $sql = 'INSERT INTO admin_partial_payment_reviews
        (org_id, wage_id, employee_name, period, son_sheet_gross, skip_income_tax, payment_date, site_paid_from, amount, method, verified, notes, shift_notes, updated_by, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE
            employee_name = VALUES(employee_name),
            period = VALUES(period),
            son_sheet_gross = VALUES(son_sheet_gross),
            skip_income_tax = VALUES(skip_income_tax),
            payment_date = VALUES(payment_date),
            site_paid_from = VALUES(site_paid_from),
            amount = VALUES(amount),
            method = VALUES(method),
            verified = VALUES(verified),
            notes = VALUES(notes),
            shift_notes = VALUES(shift_notes),
            updated_by = VALUES(updated_by),
            updated_at = NOW()';
    $pdo->prepare($sql)->execute([
        $orgId,
        (int)$wage['id'],
        ppr_clip((string)($wage['employee_name'] ?? ''), 160),
        ppr_clip((string)($wage['pay_period'] ?? ''), 160),
        $fields['son_sheet_gross'],
        (int)$fields['skip_income_tax'],
        $fields['payment_date'],
        $fields['site_paid_from'],
        $fields['amount'],
        $fields['method'],
        (int)$fields['verified'],
        $fields['notes'],
        $json,
        $actorId,
    ]);
}

/**
 * Admin menu, under a Review child when that group exists.
 * UserRole 10 hides the link from everyone except superadmins.
 */
function ppr_ensure_menu(PDO $pdo): void
{
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM menu_manager');
        $names = $cols ? $cols->fetchAll(PDO::FETCH_COLUMN) : [];
        if ($cols instanceof PDOStatement) {
            $cols->closeCursor();
        }
    } catch (Throwable $e) {
        return;
    }
    if (!$names) {
        return;
    }
    $have = array_flip($names);
    $parentId = 0;
    $module = '';
    if (function_exists('admin_super_admin_parent')) {
        $admin = admin_super_admin_parent($pdo);
        if ($admin) {
            $parentId = (int)($admin['MenuID'] ?? 0);
            $module = (string)($admin['Module'] ?? '');
        }
    }
    if ($parentId > 0) {
        $review = $pdo->prepare(
            "SELECT * FROM menu_manager
             WHERE ParentID = ? AND UPPER(TRIM(MenuTitle)) = 'REVIEW'
             ORDER BY MenuID ASC LIMIT 1"
        );
        $review->execute([$parentId]);
        $row = $review->fetch(PDO::FETCH_ASSOC);
        $review->closeCursor();
        if (!$row) {
            $any = $pdo->query("SELECT * FROM menu_manager WHERE UPPER(TRIM(MenuTitle)) = 'REVIEW' ORDER BY MenuID ASC LIMIT 1");
            $row = $any ? $any->fetch(PDO::FETCH_ASSOC) : false;
            if ($any instanceof PDOStatement) {
                $any->closeCursor();
            }
        }
        if ($row) {
            $parentId = (int)$row['MenuID'];
            if (isset($row['Module']) && (string)$row['Module'] !== '') {
                $module = (string)$row['Module'];
            }
        }
    }
    $st = $pdo->prepare('SELECT * FROM menu_manager WHERE LinkURL LIKE ? ORDER BY MenuID ASC LIMIT 1');
    $st->execute(['%partial_payments_review.php%']);
    $existing = $st->fetch(PDO::FETCH_ASSOC);
    $st->closeCursor();
    if ($existing) {
        $sets = [];
        $vals = [];
        if (isset($have['UserRole']) && (int)$existing['UserRole'] !== 10) {
            $sets[] = '`UserRole` = ?';
            $vals[] = 10;
        }
        if (isset($have['ParentID']) && $parentId > 0 && (int)$existing['ParentID'] !== $parentId) {
            $sets[] = '`ParentID` = ?';
            $vals[] = $parentId;
        }
        if (isset($have['IsActive']) && (string)$existing['IsActive'] !== '1') {
            $sets[] = '`IsActive` = ?';
            $vals[] = 1;
        }
        if ($sets) {
            $vals[] = (int)$existing['MenuID'];
            $pdo->prepare('UPDATE menu_manager SET ' . implode(', ', $sets) . ' WHERE MenuID = ?')->execute($vals);
        }
        return;
    }
    $data = [
        'MenuTitle' => 'Partial payments',
        'LinkURL' => 'partial_payments_review.php',
        'ParentID' => $parentId,
        'IsActive' => 1,
        'UserRole' => 10,
        'Module' => $module,
        'SortOrder' => 910,
    ];
    $fields = [];
    $holders = [];
    $vals = [];
    foreach ($data as $col => $val) {
        if (!isset($have[$col])) {
            continue;
        }
        $fields[] = '`' . str_replace('`', '', $col) . '`';
        $holders[] = '?';
        $vals[] = $val;
    }
    if ($fields) {
        $pdo->prepare('INSERT INTO menu_manager (' . implode(',', $fields) . ') VALUES (' . implode(',', $holders) . ')')->execute($vals);
    }
}
