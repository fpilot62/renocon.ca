<?php
/**
 * Read-only outstanding wages report.
 * Reads admin_outstanding_wages. It does not insert, update, or delete
 * wage rows, and it does not mark anyone paid.
 * The only write is the Payroll menu link, the same kind of menu row
 * Payment Status adds. That write is not a wage or pay-run change.
 */

require_once __DIR__ . '/outstanding_wages_lib.php';
require_once __DIR__ . '/xlsx_mini.php';

function orpt_pending_sql(): string
{
    return 'SELECT * FROM admin_outstanding_wages WHERE org_id = ? AND TRIM(status) = ?';
}

function orpt_fetch_pending(PDO $pdo, int $orgId): array
{
    $st = $pdo->prepare(orpt_pending_sql());
    $st->execute([$orgId, 'Pending']);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function orpt_load_user(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $queries = [
        'SELECT id, Name, org_id, user_role, active FROM users WHERE id = ?',
        'SELECT id, Name, org_id, user_role FROM users WHERE id = ?',
        'SELECT id, Name, org_id, user_level AS user_role, active FROM users WHERE id = ?',
        'SELECT id, Name, org_id, user_level AS user_role FROM users WHERE id = ?',
    ];
    foreach ($queries as $sql) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            if (!array_key_exists('active', $row)) {
                $row['active'] = 1;
            }
            $row['id'] = (int)$row['id'];
            $row['org_id'] = (int)($row['org_id'] ?? 0);
            $row['user_role'] = (int)($row['user_role'] ?? 99);
            return $row;
        } catch (Throwable $e) {
            continue;
        }
    }
    return null;
}

function orpt_role_name(PDO $pdo, int $roleId): string
{
    if ($roleId <= 0) {
        return '';
    }
    try {
        $st = $pdo->prepare('SELECT role_name FROM roles WHERE role_id = ?');
        $st->execute([$roleId]);
        $name = $st->fetchColumn();
        return is_string($name) ? $name : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Platform superadmin is role number 10 and anything stronger.
 * Menu Manager uses that same number. A role title does not turn
 * that account into an organization owner.
 */
function orpt_is_platform_superadmin(int $level): bool
{
    return $level <= 10;
}

function orpt_is_org_owner_or_admin(int $level, string $roleName): bool
{
    if ($level <= 10 || $level >= 30) {
        return false;
    }
    $name = strtolower(trim($roleName));
    $named = [
        'owner',
        'admin',
        'org owner',
        'organization owner',
        'org admin',
        'organization admin',
    ];
    if (in_array($name, $named, true)) {
        return true;
    }
    // Admin is 20. Roles between superadmin and site manager (25) count
    // as organization owner or admin. Site managers do not.
    return $level <= 20;
}

/**
 * @param array<string,mixed> $user
 * @param array<int,int> $grantedOrgIds
 * @return array{ok:bool,http:int,error:string,kind:string,org_id:int,choices:array<int,int>,login:bool}
 */
function orpt_access_decision(array $user, string $roleName, int $requestedOrg, array $grantedOrgIds): array
{
    $deny = static function (int $http, string $error) {
        return [
            'ok' => false,
            'http' => $http,
            'error' => $error,
            'kind' => 'denied',
            'org_id' => 0,
            'choices' => [],
            'login' => $http === 401,
        ];
    };
    $id = (int)($user['id'] ?? 0);
    if ($id <= 0) {
        return $deny(401, 'Login required.');
    }
    if (array_key_exists('active', $user) && (int)$user['active'] !== 1) {
        return $deny(403, 'This account is not active.');
    }
    $level = (int)($user['user_role'] ?? $user['user_level'] ?? 99);
    $home = (int)($user['org_id'] ?? 0);
    $granted = [];
    foreach ($grantedOrgIds as $orgId) {
        $orgId = (int)$orgId;
        if ($orgId > 0) {
            $granted[$orgId] = $orgId;
        }
    }
    $granted = array_values($granted);
    sort($granted);

    if (orpt_is_platform_superadmin($level)) {
        if (!$granted) {
            return $deny(403, 'Platform superadmin does not include organization payroll. A support-access grant is required.');
        }
        if ($requestedOrg > 0 && !in_array($requestedOrg, $granted, true)) {
            return $deny(403, 'That organization is outside your access.');
        }
        if ($requestedOrg > 0) {
            return [
                'ok' => true,
                'http' => 200,
                'error' => '',
                'kind' => 'support',
                'org_id' => $requestedOrg,
                'choices' => [],
                'login' => false,
            ];
        }
        if (count($granted) === 1) {
            return [
                'ok' => true,
                'http' => 200,
                'error' => '',
                'kind' => 'support',
                'org_id' => $granted[0],
                'choices' => [],
                'login' => false,
            ];
        }
        return [
            'ok' => true,
            'http' => 200,
            'error' => '',
            'kind' => 'support-choose',
            'org_id' => 0,
            'choices' => $granted,
            'login' => false,
        ];
    }

    if (!orpt_is_org_owner_or_admin($level, $roleName)) {
        return $deny(403, 'Outstanding report is for organization owners and admins.');
    }
    if ($home <= 0) {
        return $deny(403, 'No organization is assigned to this account.');
    }
    if ($requestedOrg > 0 && $requestedOrg !== $home) {
        return $deny(403, 'That organization is outside your access.');
    }
    return [
        'ok' => true,
        'http' => 200,
        'error' => '',
        'kind' => 'member',
        'org_id' => $home,
        'choices' => [],
        'login' => false,
    ];
}

function orpt_support_table_names(): array
{
    return [
        'admin_support_access',
        'support_access_grants',
        'org_support_grants',
        'payroll_support_access',
        'support_access',
    ];
}

function orpt_table_exists(PDO $pdo, string $table): bool
{
    if (!in_array($table, orpt_support_table_names(), true)) {
        return false;
    }
    try {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Org ids a platform account may see because a support grant says so.
 * Unknown helpers and unknown tables are ignored. Nothing here writes.
 *
 * @return array<int,int>
 */
function orpt_granted_org_ids(PDO $pdo, int $userId, int $requestedOrg): array
{
    if ($userId <= 0) {
        return [];
    }
    $ids = [];
    foreach (['ps_support_org_ids', 'sip_support_org_ids', 'payroll_support_org_ids', 'ps_support_access_orgs'] as $fn) {
        if (!function_exists($fn)) {
            continue;
        }
        try {
            $got = $fn($pdo, $userId);
        } catch (Throwable $e) {
            continue;
        }
        if (is_array($got)) {
            foreach ($got as $orgId) {
                $ids[] = (int)$orgId;
            }
        }
    }
    foreach (orpt_support_table_names() as $table) {
        if (!orpt_table_exists($pdo, $table)) {
            continue;
        }
        $ids = array_merge($ids, orpt_grants_from_table($pdo, $table, $userId));
    }
    if ($requestedOrg > 0) {
        foreach (['ps_has_support_access', 'sip_has_support_access', 'payroll_has_support_access'] as $fn) {
            if (!function_exists($fn)) {
                continue;
            }
            try {
                if ($fn($pdo, $userId, $requestedOrg)) {
                    $ids[] = $requestedOrg;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
    }
    $out = [];
    foreach ($ids as $orgId) {
        if ($orgId > 0) {
            $out[$orgId] = $orgId;
        }
    }
    return array_values($out);
}

/** @return array<int,int> */
function orpt_grants_from_table(PDO $pdo, string $table, int $userId): array
{
    if (!in_array($table, orpt_support_table_names(), true) || $userId <= 0) {
        return [];
    }
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $have = [];
    foreach ($cols as $col) {
        if (!is_array($col)) {
            continue;
        }
        $field = (string)($col['Field'] ?? '');
        if (preg_match('/^[A-Za-z0-9_]+$/', $field)) {
            $have[strtolower($field)] = $field;
        }
    }
    $userCol = $have['user_id'] ?? $have['granted_user_id'] ?? $have['granted_to'] ?? $have['actor_id'] ?? null;
    $orgCol = $have['org_id'] ?? null;
    if ($userCol === null || $orgCol === null) {
        return [];
    }
    $select = '`' . $orgCol . '` AS org_id';
    if (isset($have['status'])) {
        $select .= ', `' . $have['status'] . '` AS grant_status';
    }
    if (isset($have['expires_at'])) {
        $select .= ', `' . $have['expires_at'] . '` AS grant_expires';
    } elseif (isset($have['expires_on'])) {
        $select .= ', `' . $have['expires_on'] . '` AS grant_expires';
    }
    $where = ['`' . $userCol . '` = ?'];
    if (isset($have['revoked'])) {
        $where[] = '`' . $have['revoked'] . '` = 0';
    }
    if (isset($have['is_revoked'])) {
        $where[] = '`' . $have['is_revoked'] . '` = 0';
    }
    if (isset($have['active'])) {
        $where[] = '`' . $have['active'] . '` = 1';
    }
    try {
        $st = $pdo->prepare('SELECT ' . $select . ' FROM `' . $table . '` WHERE ' . implode(' AND ', $where));
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $today = date('Y-m-d');
    $ids = [];
    foreach ($rows as $row) {
        if (isset($row['grant_status'])) {
            $status = strtolower(trim((string)$row['grant_status']));
            if (!in_array($status, ['active', 'granted', 'open', 'approved', '1', 'yes'], true)) {
                continue;
            }
        }
        if (array_key_exists('grant_expires', $row) && $row['grant_expires'] !== null && $row['grant_expires'] !== '') {
            $exp = substr((string)$row['grant_expires'], 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) && $exp < $today) {
                continue;
            }
        }
        $orgId = (int)($row['org_id'] ?? 0);
        if ($orgId > 0) {
            $ids[] = $orgId;
        }
    }
    return $ids;
}

function orpt_date($value): ?string
{
    $value = substr(trim((string)$value), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $value));
    if (!checkdate($m, $d, $y)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

function orpt_money($value): string
{
    return number_format(round((float)$value, 2), 2);
}

/**
 * CPP, EI, and WCB when the note spells them out.
 * Otherwise the employer_deductions column. The two are not added together.
 *
 * @return array<string,mixed>
 */
function orpt_employer_of(array $row): array
{
    $parts = ow_er_breakdown((string)($row['notes'] ?? ''));
    $has = $parts['er_cpp'] !== null || $parts['er_ei'] !== null || $parts['wcb'] !== null;
    if ($has) {
        return [
            'source' => 'parts',
            'cpp' => $parts['er_cpp'],
            'ei' => $parts['er_ei'],
            'wcb' => $parts['wcb'],
            'lump' => null,
            'amount' => round(($parts['er_cpp'] ?? 0) + ($parts['er_ei'] ?? 0) + ($parts['wcb'] ?? 0), 2),
            'lump_missing' => false,
        ];
    }
    $lump = ow_amount($row['employer_deductions'] ?? null);
    return [
        'source' => 'lump',
        'cpp' => null,
        'ei' => null,
        'wcb' => null,
        'lump' => $lump,
        'amount' => $lump ?? 0.0,
        'lump_missing' => $lump === null,
    ];
}

/** @return array<string,mixed> */
function orpt_line_cost(array $row): array
{
    $base = ow_row_cost($row);
    $employer = orpt_employer_of($row);
    $gross = round((float)($row['gross'] ?? 0), 2);
    if (!empty($base['invoice'])) {
        $total = $base['total_cost'];
    } else {
        $total = round($gross + ($base['vacation'] ?? 0) + (float)$employer['amount'], 2);
    }
    return [
        'gross' => $gross,
        'vacation' => $base['vacation'],
        'net' => $base['net'],
        'employer' => $employer,
        'total' => $total,
        'invoice' => !empty($base['invoice']),
        'since' => orpt_date($row['period_start'] ?? null),
    ];
}

function orpt_blank_sum(): array
{
    return [
        'gross' => 0.0,
        'net' => 0.0,
        'net_missing' => 0,
        'total' => 0.0,
        'total_missing' => 0,
        'cpp' => 0.0,
        'ei' => 0.0,
        'wcb' => 0.0,
        'lump' => 0.0,
        'cpp_rows' => 0,
        'ei_rows' => 0,
        'wcb_rows' => 0,
        'lump_rows' => 0,
        'parts_rows' => 0,
        'lump_missing' => 0,
        'since' => null,
        'count' => 0,
    ];
}

function orpt_sum_lines(array $rows): array
{
    $sum = orpt_blank_sum();
    foreach ($rows as $row) {
        $line = orpt_line_cost($row);
        $sum['count']++;
        $sum['gross'] += $line['gross'];
        if ($line['net'] === null) {
            $sum['net_missing']++;
        } else {
            $sum['net'] += $line['net'];
        }
        if ($line['total'] === null) {
            $sum['total_missing']++;
        } else {
            $sum['total'] += $line['total'];
        }
        $employer = $line['employer'];
        if ($employer['source'] === 'parts') {
            $sum['parts_rows']++;
            if ($employer['cpp'] !== null) {
                $sum['cpp'] += $employer['cpp'];
                $sum['cpp_rows']++;
            }
            if ($employer['ei'] !== null) {
                $sum['ei'] += $employer['ei'];
                $sum['ei_rows']++;
            }
            if ($employer['wcb'] !== null) {
                $sum['wcb'] += $employer['wcb'];
                $sum['wcb_rows']++;
            }
        } elseif (!empty($employer['lump_missing'])) {
            $sum['lump_missing']++;
        } else {
            $sum['lump'] += (float)$employer['lump'];
            $sum['lump_rows']++;
        }
        if ($line['since'] !== null && ($sum['since'] === null || $line['since'] < $sum['since'])) {
            $sum['since'] = $line['since'];
        }
    }
    foreach (['gross', 'net', 'total', 'cpp', 'ei', 'wcb', 'lump'] as $key) {
        $sum[$key] = round($sum[$key], 2);
    }
    return $sum;
}

function orpt_employer_text(array $sum): string
{
    if ((int)$sum['parts_rows'] > 0) {
        $bits = [
            'CPP ' . ((int)$sum['cpp_rows'] > 0 ? orpt_money($sum['cpp']) : '—'),
            'EI ' . ((int)$sum['ei_rows'] > 0 ? orpt_money($sum['ei']) : '—'),
            'WCB ' . ((int)$sum['wcb_rows'] > 0 ? orpt_money($sum['wcb']) : '—'),
        ];
        if ((int)$sum['lump_rows'] > 0) {
            $bits[] = 'other ' . orpt_money($sum['lump']);
        }
        return implode(' · ', $bits);
    }
    if ((int)$sum['lump_rows'] === 0) {
        return '—';
    }
    return orpt_money($sum['lump']);
}

function orpt_net_text(array $sum): string
{
    if ((int)$sum['count'] > 0 && (int)$sum['net_missing'] === (int)$sum['count']) {
        return '—';
    }
    $text = orpt_money($sum['net']);
    if ((int)$sum['net_missing'] > 0) {
        $n = (int)$sum['net_missing'];
        $text .= ' (' . $n . ' ' . ($n === 1 ? 'line has' : 'lines have') . ' no net)';
    }
    return $text;
}

function orpt_total_text(array $sum): string
{
    if ((int)$sum['count'] > 0 && (int)$sum['total_missing'] === (int)$sum['count']) {
        return '—';
    }
    $text = orpt_money($sum['total']);
    if ((int)$sum['total_missing'] > 0) {
        $n = (int)$sum['total_missing'];
        $text .= ' (' . $n . ' ' . ($n === 1 ? 'line is' : 'lines are') . ' incomplete)';
    }
    return $text;
}

function orpt_clip(string $text, int $limit = 8000): string
{
    if (strlen($text) <= $limit) {
        return $text;
    }
    return substr($text, 0, $limit - 3) . '...';
}

function orpt_notes(array $rows): string
{
    $parts = [];
    $flagged = false;
    foreach ($rows as $row) {
        if ((int)($row['flagged'] ?? 0) === 1) {
            $flagged = true;
        }
        $note = trim(preg_replace('/\s+/u', ' ', (string)($row['notes'] ?? '')));
        if ($note !== '' && !in_array($note, $parts, true)) {
            $parts[] = $note;
        }
    }
    $text = implode(' · ', $parts);
    if ($flagged) {
        $text = $text === '' ? 'Flagged' : ('Flagged · ' . $text);
    }
    return orpt_clip($text);
}

/** @return array{key:string,name_key:string,user_id:int} */
function orpt_employee_identity(array $row): array
{
    $uid = (int)($row['user_id'] ?? 0);
    $nameKey = ow_norm_label((string)($row['employee_name'] ?? ''));
    if ($uid > 0) {
        return ['key' => 'u' . $uid, 'name_key' => $nameKey, 'user_id' => $uid];
    }
    $slug = $nameKey === '' ? 'unknown' : substr(hash('sha256', $nameKey), 0, 12);
    return ['key' => 'n' . $slug, 'name_key' => $nameKey, 'user_id' => 0];
}

function orpt_display_name(array $rows): string
{
    $best = '';
    foreach ($rows as $row) {
        $name = trim((string)($row['employee_name'] ?? ''));
        if ($name !== '' && strlen($name) > strlen($best)) {
            $best = $name;
        }
    }
    return $best !== '' ? $best : 'Unknown employee';
}

function orpt_store_list(array $rows): string
{
    $seen = [];
    $names = [];
    foreach ($rows as $row) {
        $name = trim((string)($row['store_name'] ?? ''));
        $key = ow_norm_label($name);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $names[] = $name;
    }
    usort($names, 'strcasecmp');
    return implode(', ', $names);
}

/** @return array<int,array{key:string,name:string}> */
function orpt_store_options(array $rows): array
{
    $opts = [];
    foreach ($rows as $row) {
        $key = ow_store_key($row);
        if (!isset($opts[$key])) {
            $name = trim((string)($row['store_name'] ?? ''));
            $opts[$key] = ['key' => $key, 'name' => $name !== '' ? $name : 'Store'];
        }
    }
    $list = array_values($opts);
    usort($list, static function (array $a, array $b): int {
        return strcasecmp($a['name'], $b['name']);
    });
    return $list;
}

/** @return array<int,array<string,mixed>> */
function orpt_group_employees(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        $id = orpt_employee_identity($row);
        $key = $id['key'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'key' => $key,
                'name_key' => $id['name_key'],
                'user_id' => $id['user_id'],
                'rows' => [],
            ];
        }
        $groups[$key]['rows'][] = $row;
        if ($groups[$key]['name_key'] === '' && $id['name_key'] !== '') {
            $groups[$key]['name_key'] = $id['name_key'];
        }
    }
    $byName = [];
    foreach ($groups as $key => $group) {
        if ($group['user_id'] > 0 && $group['name_key'] !== '') {
            $byName[$group['name_key']][] = $key;
        }
    }
    $merge = [];
    foreach ($groups as $key => $group) {
        if ($group['user_id'] > 0 || $group['name_key'] === '') {
            continue;
        }
        $matches = $byName[$group['name_key']] ?? [];
        if (count($matches) === 1) {
            $merge[$key] = $matches[0];
        }
    }
    foreach ($merge as $from => $dest) {
        foreach ($groups[$from]['rows'] as $row) {
            $groups[$dest]['rows'][] = $row;
        }
        unset($groups[$from]);
    }
    $list = array_values($groups);
    usort($list, static function (array $a, array $b): int {
        return strcasecmp(orpt_display_name($a['rows']), orpt_display_name($b['rows']));
    });
    return $list;
}

function orpt_present_group(array $group): array
{
    $rows = $group['rows'];
    $sum = orpt_sum_lines($rows);
    return [
        'key' => (string)$group['key'],
        'name' => orpt_display_name($rows),
        'stores' => orpt_store_list($rows),
        'periods' => count($rows),
        'gross_text' => orpt_money($sum['gross']),
        'net_text' => orpt_net_text($sum),
        'employer_text' => orpt_employer_text($sum),
        'total_text' => orpt_total_text($sum),
        'since' => (string)($sum['since'] ?? ''),
        'notes' => orpt_notes($rows),
        'sum' => $sum,
    ];
}

function orpt_detail_record(array $row): array
{
    $line = orpt_line_cost($row);
    $sum = orpt_sum_lines([$row]);
    return [
        'employee' => trim((string)($row['employee_name'] ?? '')),
        'store' => trim((string)($row['store_name'] ?? '')),
        'pay_period' => trim((string)($row['pay_period'] ?? '')),
        'period_start' => (string)($line['since'] ?? ''),
        'period_end' => (string)(orpt_date($row['period_end'] ?? null) ?? ''),
        'gross' => $line['gross'],
        'vacation' => $line['vacation'],
        'net' => $line['net'],
        'employer' => orpt_employer_text($sum),
        'total' => $line['total'],
        'notes' => orpt_clip(trim((string)($row['notes'] ?? ''))),
    ];
}

function orpt_pending_only(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (trim((string)($row['status'] ?? '')) === 'Pending') {
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * @return array<string,mixed>
 */
function orpt_report(array $rows, string $storeKey, string $employeeKey): array
{
    $pending = orpt_pending_only($rows);
    $stores = orpt_store_options($pending);
    $allowedStores = [];
    foreach ($stores as $store) {
        $allowedStores[$store['key']] = true;
    }
    if ($storeKey !== '' && !isset($allowedStores[$storeKey])) {
        $storeKey = '';
    }
    $filtered = $pending;
    if ($storeKey !== '') {
        $filtered = [];
        foreach ($pending as $row) {
            if (ow_store_key($row) === $storeKey) {
                $filtered[] = $row;
            }
        }
    }
    $groups = orpt_group_employees($filtered);
    $known = [];
    foreach ($groups as $group) {
        $known[$group['key']] = true;
    }
    if ($employeeKey !== '' && !isset($known[$employeeKey])) {
        $employeeKey = '';
    }
    $employees = [];
    $detailRows = [];
    foreach ($groups as $group) {
        if ($employeeKey !== '' && $group['key'] !== $employeeKey) {
            continue;
        }
        $employees[] = orpt_present_group($group);
        foreach ($group['rows'] as $row) {
            $detailRows[] = $row;
        }
    }
    $detailRows = ow_sort_rows($detailRows);
    $totals = orpt_sum_lines($detailRows);
    $detail = [];
    foreach ($detailRows as $row) {
        $detail[] = orpt_detail_record($row);
    }
    $people = count($employees);
    return [
        'store' => $storeKey,
        'employee' => $employeeKey,
        'stores' => $stores,
        'employees' => $employees,
        'detail' => $detail,
        'totals' => $totals,
        'totals_row' => [
            'Total',
            $people === 1 ? '1 employee' : ($people . ' employees'),
            orpt_money($totals['gross']),
            orpt_net_text($totals),
            orpt_employer_text($totals),
            orpt_total_text($totals),
            (string)($totals['since'] ?? ''),
            '',
        ],
    ];
}

function orpt_summary_headers(): array
{
    return ['Name', 'Store(s)', 'Gross outstanding', 'Net owed', 'Employer contributions', 'Total cost', 'Since', 'Notes'];
}

function orpt_summary_sheet(array $report): array
{
    $rows = [orpt_summary_headers()];
    foreach ($report['employees'] as $emp) {
        $rows[] = [
            $emp['name'],
            $emp['stores'],
            $emp['gross_text'],
            $emp['net_text'],
            $emp['employer_text'],
            $emp['total_text'],
            $emp['since'] === '' ? '—' : $emp['since'],
            $emp['notes'],
        ];
    }
    $total = $report['totals_row'];
    $total[6] = $total[6] === '' ? '—' : $total[6];
    $rows[] = $total;
    return $rows;
}

function orpt_detail_sheet(array $report): array
{
    $rows = [[
        'Employee',
        'Store',
        'Pay period',
        'Period start',
        'Period end',
        'Gross',
        'Vacation pay',
        'Net',
        'Employer contributions',
        'Total cost',
        'Notes',
    ]];
    foreach ($report['detail'] as $line) {
        $rows[] = [
            $line['employee'],
            $line['store'],
            $line['pay_period'],
            $line['period_start'],
            $line['period_end'],
            $line['gross'],
            $line['vacation'],
            $line['net'],
            $line['employer'],
            $line['total'],
            $line['notes'],
        ];
    }
    return $rows;
}

function orpt_send_xlsx(int $orgId, array $report, array $demandRows = []): void
{
    $bytes = xlsx_build([
        'Summary' => orpt_summary_sheet($report),
        'Detail' => orpt_detail_sheet($report),
        'Immediate Demand' => orpt_demand_sheet($demandRows),
    ]);
    $name = 'outstanding-report-org-' . $orgId . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
}

function orpt_query(int $orgId, string $store, string $employee, bool $export): string
{
    $q = ['org_id' => $orgId];
    if ($store !== '') {
        $q['store'] = $store;
    }
    if ($employee !== '') {
        $q['employee'] = $employee;
    }
    if ($export) {
        $q['export'] = 'xlsx';
    }
    return 'outstanding_report.php?' . http_build_query($q);
}

/** @param array<int,int> $ids @return array<int,array{id:int,name:string}> */
function orpt_org_names(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
        return $id > 0;
    })));
    $found = [];
    if ($ids) {
        try {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare('SELECT id, org_name FROM organizations WHERE id IN (' . $marks . ')');
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $found[(int)$row['id']] = trim((string)($row['org_name'] ?? ''));
            }
        } catch (Throwable $e) {
            $found = [];
        }
    }
    $out = [];
    foreach ($ids as $id) {
        $name = $found[$id] ?? '';
        $out[] = ['id' => $id, 'name' => $name !== '' ? $name : ('Organization ' . $id)];
    }
    usort($out, static function (array $a, array $b): int {
        return strcasecmp($a['name'], $b['name']);
    });
    return $out;
}

function orpt_org_label(PDO $pdo, int $orgId): string
{
    $rows = orpt_org_names($pdo, [$orgId]);
    return $rows[0]['name'] ?? ('Organization ' . $orgId);
}

/**
 * Payroll menu, Min. Role Admin (20). Superadmin still sees the link
 * because a smaller role number sees every weaker Min. Role. The page
 * itself refuses the wage rows unless a support grant allows them.
 */
function orpt_ensure_menu(PDO $pdo): void
{
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM menu_manager')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }
    if (!$cols) {
        return;
    }
    $have = array_flip($cols);
    $st = $pdo->prepare('SELECT * FROM menu_manager WHERE LinkURL LIKE ? ORDER BY MenuID ASC LIMIT 1');
    $st->execute(['%outstanding_report.php%']);
    $existing = $st->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $sets = [];
        $vals = [];
        if (isset($have['UserRole']) && (int)$existing['UserRole'] !== 20) {
            $sets[] = '`UserRole` = ?';
            $vals[] = 20;
        }
        if (isset($have['IsActive']) && (string)$existing['IsActive'] !== '1' && (int)$existing['IsActive'] !== 1) {
            $sets[] = '`IsActive` = ?';
            $vals[] = 1;
        }
        if ($sets) {
            $vals[] = (int)$existing['MenuID'];
            $pdo->prepare('UPDATE menu_manager SET ' . implode(', ', $sets) . ' WHERE MenuID = ?')->execute($vals);
        }
        return;
    }
    $parent = orpt_menu_parent($pdo);
    $data = [
        'MenuTitle' => 'Outstanding report',
        'LinkURL' => 'outstanding_report.php',
        'ParentID' => $parent['id'],
        'IsActive' => 1,
        'UserRole' => 20,
        'Module' => $parent['module'],
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

/** @return array{id:int,module:string} */
function orpt_menu_parent(PDO $pdo): array
{
    foreach (['payroll_payment_status.php', 'payroll_master_ledger.php', 'payroll_report.php', 'payroll_reports.php'] as $script) {
        try {
            $st = $pdo->prepare('SELECT * FROM menu_manager WHERE LinkURL LIKE ? ORDER BY MenuID ASC LIMIT 1');
            $st->execute(['%' . $script]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $row = false;
        }
        if ($row && (int)($row['ParentID'] ?? 0) > 0) {
            return ['id' => (int)$row['ParentID'], 'module' => (string)($row['Module'] ?? '')];
        }
    }
    $titles = ["'PAYROLL'", "'ADMIN'", "'ADMINISTRATION'"];
    foreach ($titles as $title) {
        try {
            $q = $pdo->query(
                'SELECT * FROM menu_manager WHERE (ParentID = 0 OR ParentID IS NULL) AND UPPER(TRIM(MenuTitle)) = ' . $title . ' ORDER BY MenuID ASC LIMIT 1'
            );
            $row = $q ? $q->fetch(PDO::FETCH_ASSOC) : false;
        } catch (Throwable $e) {
            $row = false;
        }
        if ($row) {
            return ['id' => (int)($row['MenuID'] ?? 0), 'module' => (string)($row['Module'] ?? '')];
        }
    }
    return ['id' => 0, 'module' => ''];
}

function orpt_demand_migration_path(): string
{
    return dirname(__DIR__) . '/migrations/20261117_admin_immediate_demand.sql';
}

/** Creates admin_immediate_demand when it is missing. Does not change other tables. */
function orpt_ensure_demand(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    ow_exec_sql_file($pdo, orpt_demand_migration_path());
    $ready = true;
}

/**
 * @return array{ok:bool,errors:array<int,string>,row:array<string,mixed>,form:array<string,mixed>}
 */
function orpt_demand_input(array $post): array
{
    $name = trim(preg_replace('/\s+/u', ' ', (string)($post['employee_name'] ?? '')));
    $store = trim(preg_replace('/\s+/u', ' ', (string)($post['store_name'] ?? '')));
    $period = trim(preg_replace('/\s+/u', ' ', (string)($post['pay_period'] ?? '')));
    $notes = str_replace(["\r\n", "\r"], "\n", (string)($post['notes'] ?? ''));
    $cash = in_array(strtolower(trim((string)($post['cash_flag'] ?? ''))), ['1', 'true', 'yes', 'on'], true);
    $gross = ow_parse_money((string)($post['gross'] ?? ''), true);
    $vacation = ow_parse_money((string)($post['vacation_pay'] ?? ''), false);
    $employer = ow_parse_money((string)($post['employer_contribution'] ?? ''), false);
    $net = ow_parse_money((string)($post['net_cash'] ?? ''), false);
    $wageId = (int)($post['wage_id'] ?? 0);
    $errors = [];
    if ($name === '') {
        $errors[] = 'Name is required.';
    }
    if (strlen($name) > 160) {
        $errors[] = 'Name is too long.';
    }
    if (strlen($store) > 160) {
        $errors[] = 'Store is too long.';
    }
    if (strlen($period) > 160) {
        $errors[] = 'Pay period is too long.';
    }
    if (strlen($notes) > 8000) {
        $errors[] = 'Notes are too long.';
    }
    if (!$gross['ok']) {
        $errors[] = $gross['error'] !== '' ? $gross['error'] : 'Gross is required.';
    }
    if (!$vacation['ok']) {
        $errors[] = 'Vacation pay is not a number.';
    }
    if (!$cash && !$employer['ok']) {
        $errors[] = 'Employer contribution is not a number.';
    }
    if (!$net['ok']) {
        $errors[] = 'Net cash is not a number.';
    }
    if ($wageId < 0) {
        $wageId = 0;
    }
    $form = [
        'id' => (int)($post['demand_id'] ?? 0),
        'wage_id' => $wageId > 0 ? $wageId : 0,
        'employee_name' => $name,
        'store_name' => $store,
        'pay_period' => $period,
        'gross' => trim((string)($post['gross'] ?? '')),
        'vacation_pay' => trim((string)($post['vacation_pay'] ?? '')),
        'employer_contribution' => $cash ? '' : trim((string)($post['employer_contribution'] ?? '')),
        'net_cash' => trim((string)($post['net_cash'] ?? '')),
        'cash_flag' => $cash ? 1 : 0,
        'notes' => $notes,
    ];
    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'row' => [
            'employee_name' => $name,
            'store_name' => $store,
            'pay_period' => $period,
            'gross' => $gross['ok'] ? $gross['value'] : null,
            'vacation_pay' => $vacation['ok'] ? $vacation['value'] : null,
            'employer_contribution' => $cash ? null : ($employer['ok'] ? $employer['value'] : null),
            'net_cash' => $net['ok'] ? $net['value'] : null,
            'cash_flag' => $cash ? 1 : 0,
            'notes' => trim($notes) === '' ? null : $notes,
            'wage_id' => $wageId > 0 ? $wageId : null,
        ],
        'form' => $form,
    ];
}

/** Employer contribution is CPP + EI + WCB, and it is blank on a cash or contractor row. */
function orpt_demand_line(array $row): array
{
    $cash = (int)($row['cash_flag'] ?? 0) === 1;
    $gross = round((float)($row['gross'] ?? 0), 2);
    $vacation = ow_amount($row['vacation_pay'] ?? null);
    $employer = $cash ? null : ow_amount($row['employer_contribution'] ?? null);
    $net = ow_amount($row['net_cash'] ?? null);
    $burden = round($gross + ($vacation ?? 0) + ($employer ?? 0), 2);
    return [
        'gross' => $gross,
        'vacation' => $vacation,
        'employer' => $employer,
        'burden' => $burden,
        'net' => $net,
        'cash' => $cash,
    ];
}

function orpt_demand_money_text($value, bool $known): string
{
    if (!$known) {
        return '—';
    }
    return orpt_money($value);
}

function orpt_demand_totals(array $rows): array
{
    $gross = 0.0;
    $vacation = 0.0;
    $vacationRows = 0;
    $employer = 0.0;
    $employerRows = 0;
    $burden = 0.0;
    $net = 0.0;
    $netRows = 0;
    foreach ($rows as $row) {
        $line = orpt_demand_line($row);
        $gross += $line['gross'];
        $burden += $line['burden'];
        if ($line['vacation'] !== null) {
            $vacation += $line['vacation'];
            $vacationRows++;
        }
        if ($line['employer'] !== null) {
            $employer += $line['employer'];
            $employerRows++;
        }
        if ($line['net'] !== null) {
            $net += $line['net'];
            $netRows++;
        }
    }
    $count = count($rows);
    return [
        'count' => $count,
        'gross_text' => orpt_money($gross),
        'vacation_text' => orpt_demand_money_text($vacation, $vacationRows > 0),
        'employer_text' => orpt_demand_money_text($employer, $employerRows > 0),
        'burden_text' => orpt_money($burden),
        'net_text' => orpt_demand_money_text($net, $netRows > 0),
        'people_text' => $count === 1 ? '1 employee' : ($count . ' employees'),
    ];
}

function orpt_demand_present(array $row): array
{
    $line = orpt_demand_line($row);
    $notes = trim((string)($row['notes'] ?? ''));
    return [
        'id' => (int)($row['id'] ?? 0),
        'name' => trim((string)($row['employee_name'] ?? '')),
        'store' => trim((string)($row['store_name'] ?? '')),
        'pay_period' => trim((string)($row['pay_period'] ?? '')),
        'gross_text' => orpt_money($line['gross']),
        'vacation_text' => $line['vacation'] === null ? '—' : orpt_money($line['vacation']),
        'employer_text' => $line['employer'] === null ? '—' : orpt_money($line['employer']),
        'burden_text' => orpt_money($line['burden']),
        'net_text' => $line['net'] === null ? '—' : orpt_money($line['net']),
        'cash_text' => $line['cash'] ? 'Cash' : '',
        'notes' => orpt_clip($notes),
        'wage_id' => (int)($row['wage_id'] ?? 0),
    ];
}

function orpt_demand_form_values(?array $row): array
{
    if ($row === null) {
        return [
            'id' => 0,
            'wage_id' => 0,
            'employee_name' => '',
            'store_name' => '',
            'pay_period' => '',
            'gross' => '',
            'vacation_pay' => '',
            'employer_contribution' => '',
            'net_cash' => '',
            'cash_flag' => 0,
            'notes' => '',
        ];
    }
    $cash = (int)($row['cash_flag'] ?? 0) === 1;
    return [
        'id' => (int)($row['id'] ?? 0),
        'wage_id' => (int)($row['wage_id'] ?? 0),
        'employee_name' => (string)($row['employee_name'] ?? ''),
        'store_name' => (string)($row['store_name'] ?? ''),
        'pay_period' => (string)($row['pay_period'] ?? ''),
        'gross' => array_key_exists('gross', $row) ? ow_money_input($row['gross']) : '',
        'vacation_pay' => ow_money_input($row['vacation_pay'] ?? null),
        'employer_contribution' => $cash ? '' : ow_money_input($row['employer_contribution'] ?? null),
        'net_cash' => ow_money_input($row['net_cash'] ?? null),
        'cash_flag' => $cash ? 1 : 0,
        'notes' => (string)($row['notes'] ?? ''),
    ];
}

function orpt_demand_headers(): array
{
    return ['Name', 'Store', 'Pay period', 'Gross', 'Vacation pay', 'Employer contribution', 'Total burden', 'Net cash', 'Cash', 'Notes', 'Outstanding row'];
}

function orpt_demand_sheet(array $rows): array
{
    $sheet = [orpt_demand_headers()];
    foreach ($rows as $row) {
        $shown = orpt_demand_present($row);
        $sheet[] = [
            $shown['name'],
            $shown['store'],
            $shown['pay_period'],
            $shown['gross_text'],
            $shown['vacation_text'],
            $shown['employer_text'],
            $shown['burden_text'],
            $shown['net_text'],
            $shown['cash_text'],
            $shown['notes'],
            $shown['wage_id'] > 0 ? (string)$shown['wage_id'] : '',
        ];
    }
    $totals = orpt_demand_totals($rows);
    $sheet[] = [
        'Total',
        $totals['people_text'],
        '',
        $totals['gross_text'],
        $totals['vacation_text'],
        $totals['employer_text'],
        $totals['burden_text'],
        $totals['net_text'],
        '',
        '',
        '',
    ];
    return $sheet;
}

/** @return array<int,array<string,mixed>> */
function orpt_demand_wage_options(array $pendingRows): array
{
    $out = [];
    foreach (ow_sort_rows($pendingRows) as $row) {
        $employer = orpt_employer_of($row);
        $amount = null;
        if ($employer['source'] === 'parts') {
            $amount = $employer['amount'];
        } elseif (empty($employer['lump_missing'])) {
            $amount = $employer['lump'];
        }
        $name = trim((string)($row['employee_name'] ?? ''));
        $store = trim((string)($row['store_name'] ?? ''));
        $period = trim((string)($row['pay_period'] ?? ''));
        $out[] = [
            'id' => (int)($row['id'] ?? 0),
            'label' => trim($name . ' · ' . $store . ' · ' . $period, ' ·'),
            'employee_name' => $name,
            'store_name' => $store,
            'pay_period' => $period,
            'gross' => ow_money_input($row['gross'] ?? null),
            'vacation_pay' => ow_money_input($row['vacation_pay'] ?? null),
            'employer_contribution' => $amount === null ? '' : ow_money_input($amount),
            'net_cash' => ow_money_input($row['net'] ?? null),
        ];
    }
    return $out;
}

function orpt_demand_fetch(PDO $pdo, int $orgId): array
{
    $st = $pdo->prepare('SELECT * FROM admin_immediate_demand WHERE org_id = ?');
    $st->execute([$orgId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }
    usort($rows, static function (array $a, array $b): int {
        $name = strcasecmp((string)($a['employee_name'] ?? ''), (string)($b['employee_name'] ?? ''));
        if ($name !== 0) {
            return $name;
        }
        return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    });
    return $rows;
}

function orpt_demand_wage_in_org(PDO $pdo, int $orgId, int $wageId): bool
{
    if ($wageId <= 0) {
        return true;
    }
    $st = $pdo->prepare('SELECT id FROM admin_outstanding_wages WHERE id = ? AND org_id = ?');
    $st->execute([$wageId, $orgId]);
    return (bool)$st->fetchColumn();
}

function orpt_demand_save(PDO $pdo, int $orgId, int $actorId, int $id, array $row): void
{
    $values = [
        $row['wage_id'],
        $row['store_name'],
        $row['employee_name'],
        $row['pay_period'],
        $row['gross'],
        $row['vacation_pay'],
        $row['employer_contribution'],
        $row['net_cash'],
        $row['cash_flag'],
        $row['notes'],
        $actorId,
    ];
    if ($id > 0) {
        $own = $pdo->prepare('SELECT id FROM admin_immediate_demand WHERE id = ? AND org_id = ?');
        $own->execute([$id, $orgId]);
        if (!$own->fetchColumn()) {
            throw new RuntimeException('That immediate demand row is not in this organization.');
        }
        $sql = 'UPDATE admin_immediate_demand
            SET wage_id = ?, store_name = ?, employee_name = ?, pay_period = ?, gross = ?, vacation_pay = ?,
                employer_contribution = ?, net_cash = ?, cash_flag = ?, notes = ?, updated_at = NOW(), updated_by = ?
            WHERE id = ? AND org_id = ?';
        $values[] = $id;
        $values[] = $orgId;
        $pdo->prepare($sql)->execute($values);
        return;
    }
    $sql = 'INSERT INTO admin_immediate_demand
        (org_id, wage_id, store_name, employee_name, pay_period, gross, vacation_pay, employer_contribution, net_cash, cash_flag, notes, created_at, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)';
    $insert = array_merge([$orgId], $values);
    $pdo->prepare($sql)->execute($insert);
}

function orpt_demand_remove(PDO $pdo, int $orgId, int $id): void
{
    if ($id <= 0) {
        throw new RuntimeException('That immediate demand row is not in this organization.');
    }
    $st = $pdo->prepare('DELETE FROM admin_immediate_demand WHERE id = ? AND org_id = ?');
    $st->execute([$id, $orgId]);
    if ($st->rowCount() < 1) {
        throw new RuntimeException('That immediate demand row is not in this organization.');
    }
}
