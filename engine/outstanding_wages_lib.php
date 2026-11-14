<?php
/**
 * Outstanding-wages tracking ledger.
 * Writes only admin_outstanding_wages and admin_outstanding_wage_edits.
 * It does not create pay runs, approve payroll, or mark payroll rows paid.
 */

function ow_statuses(): array
{
    return ['Pending', 'Paid', 'Disputed', 'Duplicate'];
}

function ow_fields(): array
{
    return [
        'gross' => 'gross',
        'net' => 'net',
        'employer_deductions' => 'employer_deductions',
        'status' => 'status',
        'notes' => 'notes',
        'portal_record' => 'portal_record',
        'checked_vs_son' => 'checked_vs_son',
        'flagged' => 'flagged',
    ];
}

function ow_norm_label(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value));
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }
    return strtolower($value);
}

function ow_text(string $value): string
{
    return trim(preg_replace('/\s+/u', ' ', $value));
}

function ow_ensure(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $sql = file_get_contents(dirname(__DIR__) . '/migrations/20261114_admin_outstanding_wages.sql');
    if ($sql === false) {
        throw new RuntimeException('The outstanding wages migration file is missing.');
    }
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (preg_split('/;/', (string)$sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
    $ready = true;
}

function ow_parse_money(string $raw, bool $required): array
{
    $raw = trim($raw);
    if ($raw === '') {
        if ($required) {
            return ['ok' => false, 'value' => null, 'error' => 'Gross is required.'];
        }
        return ['ok' => true, 'value' => null, 'error' => ''];
    }
    $neg = false;
    if (preg_match('/^\((.*)\)$/', $raw, $m)) {
        $neg = true;
        $raw = trim($m[1]);
    }
    $raw = str_replace(['$', ',', ' '], '', $raw);
    if (!is_numeric($raw)) {
        return ['ok' => false, 'value' => null, 'error' => 'Amount is not a number.'];
    }
    $n = round((float)$raw, 2);
    if ($neg) {
        $n = -1 * abs($n);
    }
    if ($n < -100000000 || $n > 100000000) {
        return ['ok' => false, 'value' => null, 'error' => 'Amount is out of range.'];
    }
    return ['ok' => true, 'value' => $n, 'error' => ''];
}

function ow_month_number(string $name): int
{
    $name = trim($name);
    if (strcasecmp($name, 'sept') === 0) {
        $name = 'Sep';
    }
    if (!preg_match('/^[A-Za-z]{3,9}$/', $name)) {
        return 0;
    }
    $ts = strtotime($name . ' 1, 2026');
    if ($ts === false) {
        return 0;
    }
    if (strtolower(substr($name, 0, 3)) !== strtolower(date('M', $ts))) {
        return 0;
    }
    return (int)date('n', $ts);
}

function ow_ymd(int $year, int $month, int $day): ?string
{
    if (!checkdate($month, $day, $year)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/** @return array{start:?string,end:?string} */
function ow_parse_period(string $text): array
{
    $empty = ['start' => null, 'end' => null];
    $text = trim($text);
    if ($text === '') {
        return $empty;
    }
    if (preg_match('/(\d{4}-\d{2}-\d{2}).{0,20}(\d{4}-\d{2}-\d{2})/', $text, $m)) {
        if (ow_valid_date($m[1]) && ow_valid_date($m[2])) {
            return ow_ordered_period($m[1], $m[2]);
        }
    }
    if (preg_match('/([A-Za-z]+)\s+(\d{1,2})(?:st|nd|rd|th)?\s*[-–—]\s*(\d{1,2})(?:st|nd|rd|th)?,?\s*(\d{4})/iu', $text, $m)) {
        $month = ow_month_number($m[1]);
        $start = $month ? ow_ymd((int)$m[4], $month, (int)$m[2]) : null;
        $end = $month ? ow_ymd((int)$m[4], $month, (int)$m[3]) : null;
        if ($start && $end) {
            return ow_ordered_period($start, $end);
        }
    }
    if (preg_match('/([A-Za-z]+)\s+(\d{1,2})(?:st|nd|rd|th)?,?\s*(\d{4})?\s*(?:to|through|[-–—])\s*([A-Za-z]+)\s+(\d{1,2})(?:st|nd|rd|th)?,?\s*(\d{4})/iu', $text, $m)) {
        $year = (int)($m[6] !== '' ? $m[6] : 0);
        $startYear = (int)($m[3] !== '' ? $m[3] : $year);
        $m1 = ow_month_number($m[1]);
        $m2 = ow_month_number($m[4]);
        $start = ($m1 && $startYear) ? ow_ymd($startYear, $m1, (int)$m[2]) : null;
        $end = ($m2 && $year) ? ow_ymd($year, $m2, (int)$m[5]) : null;
        if ($start && $end) {
            return ow_ordered_period($start, $end);
        }
    }
    return $empty;
}

function ow_valid_date(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $value));
    return checkdate($m, $d, $y);
}

function ow_ordered_period(string $start, string $end): array
{
    if ($end < $start) {
        return ['start' => $end, 'end' => $start];
    }
    return ['start' => $start, 'end' => $end];
}

function ow_money_key($value): string
{
    if ($value === null || $value === '') {
        return 'null';
    }
    return number_format(round((float)$value, 2), 2, '.', '');
}

function ow_dup_key(array $row): string
{
    $payload = [
        ow_norm_label((string)($row['store'] ?? '')),
        ow_norm_label((string)($row['employee'] ?? '')),
        ow_norm_label((string)($row['pay_period'] ?? '')),
        ow_money_key($row['gross'] ?? null),
        ow_money_key($row['net'] ?? null),
        ow_money_key($row['employer_deductions'] ?? null),
        ow_text((string)($row['portal_record'] ?? '')),
        ow_text((string)($row['notes'] ?? '')),
    ];
    return hash('sha256', json_encode($payload));
}

function ow_csv_rows(string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $fh = fopen('php://temp', 'r+');
    if ($fh === false) {
        throw new RuntimeException('The CSV could not be read.');
    }
    fwrite($fh, $csv);
    rewind($fh);
    $rows = [];
    while (($rec = fgetcsv($fh)) !== false) {
        if ($rec === [null] || $rec === false) {
            continue;
        }
        $rows[] = $rec;
    }
    fclose($fh);
    return $rows;
}

function ow_header_map(array $header): array
{
    $aliases = [
        'store' => 'store',
        'employee' => 'employee',
        'employee name' => 'employee',
        'pay period' => 'pay_period',
        'period' => 'pay_period',
        'gross' => 'gross',
        'net' => 'net',
        'employer deductions' => 'employer_deductions',
        'employer deduction' => 'employer_deductions',
        'er deductions' => 'employer_deductions',
        'payroll record in our portal' => 'portal_record',
        'payroll record' => 'portal_record',
        'portal record' => 'portal_record',
        'notes' => 'notes',
        'note' => 'notes',
    ];
    $labels = [
        'store' => 'Store',
        'employee' => 'Employee',
        'pay_period' => 'Pay period',
        'gross' => 'Gross',
    ];
    $map = [];
    foreach ($header as $i => $col) {
        $key = ow_norm_label((string)$col);
        if (isset($aliases[$key]) && !isset($map[$aliases[$key]])) {
            $map[$aliases[$key]] = (int)$i;
        }
    }
    $missing = [];
    foreach ($labels as $field => $label) {
        if (!isset($map[$field])) {
            $missing[] = $label;
        }
    }
    if ($missing) {
        throw new RuntimeException('The CSV is missing required columns: ' . implode(', ', $missing) . '.');
    }
    return $map;
}

function ow_cell(array $rec, array $map, string $field): string
{
    if (!isset($map[$field])) {
        return '';
    }
    return trim((string)($rec[$map[$field]] ?? ''));
}

/**
 * @return array<int,array<string,mixed>>
 */
function ow_parse_csv(string $csv): array
{
    $table = ow_csv_rows($csv);
    $header = null;
    $headerAt = null;
    foreach ($table as $i => $rec) {
        $joined = trim(implode('', $rec));
        if ($joined === '') {
            continue;
        }
        $header = $rec;
        $headerAt = $i;
        break;
    }
    if ($header === null) {
        throw new RuntimeException('The CSV has no header row.');
    }
    $map = ow_header_map($header);
    $out = [];
    foreach ($table as $i => $rec) {
        if ($headerAt !== null && $i <= $headerAt) {
            continue;
        }
        if (trim(implode('', $rec)) === '') {
            continue;
        }
        $out[] = ow_map_csv_record($rec, $map, $i + 1);
        if (count($out) > 2000) {
            throw new RuntimeException('The file has more than 2000 data rows. Split it and import each part.');
        }
    }
    if (!$out) {
        throw new RuntimeException('The CSV has no data rows.');
    }
    return $out;
}

function ow_map_csv_record(array $rec, array $map, int $line): array
{
    $store = ow_text(ow_cell($rec, $map, 'store'));
    $employee = ow_text(ow_cell($rec, $map, 'employee'));
    $periodText = ow_text(ow_cell($rec, $map, 'pay_period'));
    $gross = ow_parse_money(ow_cell($rec, $map, 'gross'), true);
    $net = ow_parse_money(ow_cell($rec, $map, 'net'), false);
    $er = ow_parse_money(ow_cell($rec, $map, 'employer_deductions'), false);
    $portal = ow_text(ow_cell($rec, $map, 'portal_record'));
    $notes = ow_text(ow_cell($rec, $map, 'notes'));
    $period = ow_parse_period($periodText);
    $errors = [];
    if ($store === '') {
        $errors[] = 'Store is required.';
    }
    if ($employee === '') {
        $errors[] = 'Employee is required.';
    }
    if (!$gross['ok']) {
        $errors[] = $gross['error'];
    }
    if (!$net['ok']) {
        $errors[] = 'Net: ' . $net['error'];
    }
    if (!$er['ok']) {
        $errors[] = 'Employer deductions: ' . $er['error'];
    }
    if (strlen($portal) > 255) {
        $errors[] = 'Payroll record is longer than 255 characters.';
    }
    $row = [
        'line' => $line,
        'store' => $store,
        'employee' => $employee,
        'pay_period' => $periodText,
        'period_start' => $period['start'],
        'period_end' => $period['end'],
        'gross' => $gross['ok'] ? $gross['value'] : null,
        'net' => $net['ok'] ? $net['value'] : null,
        'employer_deductions' => $er['ok'] ? $er['value'] : null,
        'portal_record' => $portal,
        'notes' => $notes,
        'error' => implode(' ', $errors),
    ];
    $row['dup_key'] = $row['error'] === '' ? ow_dup_key($row) : '';
    return $row;
}

/**
 * @param array<string,bool> $existingKeys
 * @param array<int,array<string,mixed>> $rows
 * @return array{insert:array,skipped:array,invalid:array}
 */
function ow_partition_import(array $existingKeys, array $rows): array
{
    $insert = [];
    $skipped = [];
    $invalid = [];
    $seen = [];
    foreach ($rows as $row) {
        if (!empty($row['error'])) {
            $invalid[] = $row;
            continue;
        }
        $key = (string)$row['dup_key'];
        if ($key === '' || isset($existingKeys[$key]) || isset($seen[$key])) {
            $skipped[] = $row;
            continue;
        }
        $seen[$key] = true;
        $insert[] = $row;
    }
    return ['insert' => $insert, 'skipped' => $skipped, 'invalid' => $invalid];
}

function ow_index_by_name(array $records, string $idKey, string $nameKey): array
{
    $out = [];
    foreach ($records as $rec) {
        $name = ow_norm_label((string)($rec[$nameKey] ?? ''));
        $id = (int)($rec[$idKey] ?? 0);
        if ($name === '' || $id <= 0) {
            continue;
        }
        if (!array_key_exists($name, $out)) {
            $out[$name] = $id;
        } elseif ($out[$name] !== $id) {
            $out[$name] = 0;
        }
    }
    return $out;
}

function ow_match_id(string $label, array $index): ?int
{
    $key = ow_norm_label($label);
    if ($key === '' || !isset($index[$key]) || (int)$index[$key] <= 0) {
        return null;
    }
    return (int)$index[$key];
}

function ow_existing_keys(PDO $pdo, int $orgId): array
{
    $st = $pdo->prepare('SELECT dup_key FROM admin_outstanding_wages WHERE org_id = ?');
    $st->execute([$orgId]);
    $keys = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $key) {
        $keys[(string)$key] = true;
    }
    return $keys;
}

/**
 * @return array{inserted:int,skipped:int,invalid:int}
 */
function ow_insert_new(PDO $pdo, int $orgId, int $actorId, array $rows, array $siteIndex, array $userIndex): array
{
    $part = ow_partition_import(ow_existing_keys($pdo, $orgId), $rows);
    $ins = $pdo->prepare(
        'INSERT INTO admin_outstanding_wages
            (org_id, site_id, store_name, employee_name, user_id, pay_period, period_start, period_end,
             gross, net, employer_deductions, status, portal_record, checked_vs_son, notes, flagged,
             dup_key, created_at, updated_at, updated_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,\'Pending\',?,0,?,0,?,NOW(),NOW(),?)'
    );
    $inserted = 0;
    $skipped = count($part['skipped']);
    foreach ($part['insert'] as $row) {
        try {
            $ins->execute([
                $orgId,
                ow_match_id((string)$row['store'], $siteIndex),
                $row['store'],
                $row['employee'],
                ow_match_id((string)$row['employee'], $userIndex),
                $row['pay_period'],
                $row['period_start'],
                $row['period_end'],
                $row['gross'],
                $row['net'],
                $row['employer_deductions'],
                $row['portal_record'],
                $row['notes'] === '' ? null : $row['notes'],
                $row['dup_key'],
                $actorId,
            ]);
            $inserted++;
        } catch (PDOException $e) {
            $skipped++;
        }
    }
    return [
        'inserted' => $inserted,
        'skipped' => $skipped,
        'invalid' => count($part['invalid']),
    ];
}

function ow_parse_edit(string $field, string $raw): array
{
    if (!isset(ow_fields()[$field])) {
        return ['ok' => false, 'value' => null, 'error' => 'That field cannot be edited here.'];
    }
    if ($field === 'gross') {
        return ow_parse_money($raw, true);
    }
    if ($field === 'net' || $field === 'employer_deductions') {
        return ow_parse_money($raw, false);
    }
    if ($field === 'status') {
        $raw = trim($raw);
        if (!in_array($raw, ow_statuses(), true)) {
            return ['ok' => false, 'value' => null, 'error' => 'Status must be Pending, Paid, Disputed, or Duplicate.'];
        }
        return ['ok' => true, 'value' => $raw, 'error' => ''];
    }
    if ($field === 'checked_vs_son' || $field === 'flagged') {
        $on = in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
        return ['ok' => true, 'value' => $on ? 1 : 0, 'error' => ''];
    }
    $text = str_replace(["\r\n", "\r"], "\n", $raw);
    $limit = $field === 'portal_record' ? 255 : 8000;
    if (strlen($text) > $limit) {
        return ['ok' => false, 'value' => null, 'error' => 'That text is too long.'];
    }
    return ['ok' => true, 'value' => $text, 'error' => ''];
}

function ow_field_current(array $row, string $field)
{
    $column = ow_fields()[$field];
    $value = $row[$column] ?? null;
    if ($field === 'gross' || $field === 'net' || $field === 'employer_deductions') {
        if ($value === null || $value === '') {
            return null;
        }
        return round((float)$value, 2);
    }
    if ($field === 'checked_vs_son' || $field === 'flagged') {
        return (int)$value === 1 ? 1 : 0;
    }
    return $value === null ? '' : (string)$value;
}

function ow_same_value(string $field, $before, $after): bool
{
    if ($field === 'gross' || $field === 'net' || $field === 'employer_deductions') {
        if ($before === null || $after === null) {
            return $before === null && $after === null;
        }
        return abs((float)$before - (float)$after) < 0.001;
    }
    return (string)$before === (string)$after;
}

function ow_audit_text(string $field, $value): string
{
    if ($value === null) {
        return '';
    }
    if ($field === 'checked_vs_son' || $field === 'flagged') {
        return ((int)$value === 1) ? 'yes' : 'no';
    }
    if ($field === 'gross' || $field === 'net' || $field === 'employer_deductions') {
        return number_format((float)$value, 2, '.', '');
    }
    return (string)$value;
}

function ow_db_value(string $field, $value)
{
    if ($field === 'notes' && $value === '') {
        return null;
    }
    if (($field === 'net' || $field === 'employer_deductions') && $value === null) {
        return null;
    }
    return $value;
}

/**
 * @return array{changed:bool,row:array}
 */
function ow_save_field(PDO $pdo, int $orgId, int $id, int $actorId, string $field, string $raw): array
{
    $parsed = ow_parse_edit($field, $raw);
    if (!$parsed['ok']) {
        throw new RuntimeException($parsed['error']);
    }
    $column = ow_fields()[$field];
    $st = $pdo->prepare('SELECT * FROM admin_outstanding_wages WHERE id = ? AND org_id = ?');
    $st->execute([$id, $orgId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('That row is not in this organization.');
    }
    $before = ow_field_current($row, $field);
    $after = $parsed['value'];
    if (ow_same_value($field, $before, $after)) {
        return ['changed' => false, 'row' => $row];
    }
    $upd = $pdo->prepare('UPDATE admin_outstanding_wages SET `' . $column . '` = ?, updated_at = NOW(), updated_by = ? WHERE id = ? AND org_id = ?');
    $upd->execute([ow_db_value($field, $after), $actorId, $id, $orgId]);
    $log = $pdo->prepare(
        'INSERT INTO admin_outstanding_wage_edits
            (wage_id, org_id, actor_id, field_name, before_value, after_value, created_at)
         VALUES (?,?,?,?,?,?,NOW())'
    );
    $log->execute([$id, $orgId, $actorId, $field, ow_audit_text($field, $before), ow_audit_text($field, $after)]);
    $st->execute([$id, $orgId]);
    $saved = $st->fetch(PDO::FETCH_ASSOC);
    return ['changed' => true, 'row' => $saved ?: $row];
}

function ow_fetch(PDO $pdo, int $orgId): array
{
    $st = $pdo->prepare('SELECT * FROM admin_outstanding_wages WHERE org_id = ?');
    $st->execute([$orgId]);
    return ow_sort_rows($st->fetchAll(PDO::FETCH_ASSOC));
}

function ow_sort_rows(array $rows): array
{
    usort($rows, static function (array $a, array $b): int {
        $store = strcasecmp((string)$a['store_name'], (string)$b['store_name']);
        if ($store !== 0) {
            return $store;
        }
        $emp = strcasecmp((string)$a['employee_name'], (string)$b['employee_name']);
        if ($emp !== 0) {
            return $emp;
        }
        $as = (string)($a['period_start'] ?? '');
        $bs = (string)($b['period_start'] ?? '');
        if ($as !== $bs) {
            return strcmp($as, $bs);
        }
        $period = strcasecmp((string)$a['pay_period'], (string)$b['pay_period']);
        if ($period !== 0) {
            return $period;
        }
        return ((int)$a['id']) <=> ((int)$b['id']);
    });
    return $rows;
}

function ow_store_key(array $row): string
{
    $site = (int)($row['site_id'] ?? 0);
    if ($site > 0) {
        return 'site-' . $site;
    }
    $name = ow_norm_label((string)($row['store_name'] ?? ''));
    return 'name-' . ($name === '' ? 'unknown' : preg_replace('/[^a-z0-9]+/', '-', $name));
}

function ow_anchor(string $key): string
{
    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $key));
    return 'store-' . trim($slug, '-');
}

/** @return array<int,array<string,mixed>> */
function ow_group_stores(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        $key = ow_store_key($row);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'key' => $key,
                'anchor' => ow_anchor($key),
                'store_name' => (string)$row['store_name'],
                'site_id' => (int)($row['site_id'] ?? 0),
                'rows' => [],
            ];
        }
        $groups[$key]['rows'][] = $row;
    }
    $list = array_values($groups);
    usort($list, static function (array $a, array $b): int {
        return strcasecmp($a['store_name'], $b['store_name']);
    });
    return $list;
}

function ow_totals(array $rows): array
{
    $gross = 0.0;
    $net = 0.0;
    $employer = 0.0;
    $employerMissing = 0;
    $netMissing = 0;
    $pendingN = 0;
    $pendingGross = 0.0;
    foreach ($rows as $row) {
        $g = round((float)($row['gross'] ?? 0), 2);
        $gross += $g;
        if ($row['net'] === null || $row['net'] === '') {
            $netMissing++;
        } else {
            $net += (float)$row['net'];
        }
        if (!array_key_exists('employer_deductions', $row) || $row['employer_deductions'] === null || $row['employer_deductions'] === '') {
            $employerMissing++;
        } else {
            $employer += (float)$row['employer_deductions'];
        }
        if ((string)($row['status'] ?? '') === 'Pending') {
            $pendingN++;
            $pendingGross += $g;
        }
    }
    return [
        'count' => count($rows),
        'gross' => round($gross, 2),
        'net' => round($net, 2),
        'net_missing' => $netMissing,
        'employer' => round($employer, 2),
        'employer_missing' => $employerMissing,
        'pending_count' => $pendingN,
        'pending_gross' => round($pendingGross, 2),
    ];
}

function ow_filter_rows(array $rows, string $q, bool $flaggedOnly, string $employee): array
{
    $q = ow_norm_label($q);
    $employee = ow_norm_label($employee);
    $out = [];
    foreach ($rows as $row) {
        if ($flaggedOnly && (int)($row['flagged'] ?? 0) !== 1) {
            continue;
        }
        if ($employee !== '' && ow_norm_label((string)$row['employee_name']) !== $employee) {
            continue;
        }
        if ($q !== '' && strpos(ow_search_haystack($row), $q) === false) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

function ow_search_haystack(array $row): string
{
    return ow_norm_label(implode(' ', [
        (string)($row['store_name'] ?? ''),
        (string)($row['employee_name'] ?? ''),
        (string)($row['pay_period'] ?? ''),
        (string)($row['notes'] ?? ''),
        (string)($row['portal_record'] ?? ''),
        (string)($row['status'] ?? ''),
    ]));
}

function ow_csv_cell(string $value): string
{
    if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
        return "'" . $value;
    }
    return $value;
}

function ow_export_csv(array $rows): string
{
    $fh = fopen('php://temp', 'r+');
    $header = ['Store', 'Employee', 'Pay period', 'Gross', 'Net', 'Employer deductions', 'Status', 'Payroll record in our portal', 'Checked vs son', 'Flagged', 'Notes'];
    fputcsv($fh, $header);
    foreach ($rows as $row) {
        fputcsv($fh, [
            ow_csv_cell((string)$row['store_name']),
            ow_csv_cell((string)$row['employee_name']),
            ow_csv_cell((string)$row['pay_period']),
            ow_money_key($row['gross']),
            $row['net'] === null || $row['net'] === '' ? '' : ow_money_key($row['net']),
            $row['employer_deductions'] === null || $row['employer_deductions'] === '' ? '' : ow_money_key($row['employer_deductions']),
            (string)$row['status'],
            ow_csv_cell((string)($row['portal_record'] ?? '')),
            (int)($row['checked_vs_son'] ?? 0) === 1 ? 'yes' : 'no',
            (int)($row['flagged'] ?? 0) === 1 ? 'yes' : 'no',
            ow_csv_cell((string)($row['notes'] ?? '')),
        ]);
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv === false ? '' : $csv;
}

function ow_history(PDO $pdo, int $orgId, int $id): array
{
    $st = $pdo->prepare(
        'SELECT e.field_name, e.before_value, e.after_value, e.created_at, e.actor_id, u.Name AS actor_name
         FROM admin_outstanding_wage_edits e
         LEFT JOIN users u ON u.id = e.actor_id
         WHERE e.org_id = ? AND e.wage_id = ?
         ORDER BY e.id DESC
         LIMIT 20'
    );
    $st->execute([$orgId, $id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function ow_row_public(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'store_key' => ow_store_key($row),
        'gross' => ow_field_current($row, 'gross'),
        'net' => ow_field_current($row, 'net'),
        'employer_deductions' => ow_field_current($row, 'employer_deductions'),
        'status' => (string)$row['status'],
        'notes' => (string)($row['notes'] ?? ''),
        'portal_record' => (string)$row['portal_record'],
        'checked_vs_son' => (int)$row['checked_vs_son'] === 1 ? 1 : 0,
        'flagged' => (int)$row['flagged'] === 1 ? 1 : 0,
    ];
}

function ow_money_input($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return number_format((float)$value, 2, '.', '');
}
