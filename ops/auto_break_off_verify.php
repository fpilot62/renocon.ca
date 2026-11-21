<?php
/**
 * Compare the pre-cleanup backup with live payroll.
 * SELECT only. The shell creates and drops the temporary database.
 */
function abv_is_auto($source): bool
{
    return strtolower(trim((string)$source)) === 'auto';
}

function abv_is_stat(array $line): bool
{
    $cat = strtolower((string)($line['category'] ?? ''));
    $type = strtolower((string)($line['pay_type'] ?? ''));
    return str_contains($cat, 'statutor') || str_contains($cat, 'holiday') || $type === 'statutory';
}

function abv_is_locked(array $line): bool
{
    $blob = strtolower(trim((string)($line['payment_status'] ?? '') . ' ' . (string)($line['status'] ?? '')));
    if (str_contains($blob, 'cheque')) {
        return true;
    }
    if ((float)($line['paid_amount'] ?? 0) > 0.009) {
        return true;
    }
    $pay = strtolower(trim((string)($line['payment_status'] ?? '')));
    $status = strtolower(trim((string)($line['status'] ?? '')));
    return in_array($pay, ['paid', 'remitted', 'partial'], true) || in_array($status, ['paid', 'remitted'], true);
}

function abv_is_pending(array $line): bool
{
    return !abv_is_stat($line) && !abv_is_locked($line);
}

function abv_hours(int $seconds): float
{
    return round(max(0, $seconds) / 3600, 4);
}

function abv_span(?string $in, ?string $out): int
{
    if ($in === null || $out === null || trim($in) === '' || trim($out) === '') {
        return 0;
    }
    $span = strtotime($out) - strtotime($in);
    if ($span === false) {
        return 0;
    }
    if ($span < 0) {
        $span += 86400;
    }
    return max(0, (int)$span);
}

/**
 * @param array{breaks:list<array<string,mixed>>,lines:list<array<string,mixed>>,shifts:array<int,array<string,mixed>>,names:array<int,string>} $backup
 * @param array{breaks:list<array<string,mixed>>,lines:list<array<string,mixed>>,shifts:array<int,array<string,mixed>>,names:array<int,string>} $live
 */
function abv_report(array $backup, array $live): string
{
    $autoByUser = [];
    $autoByShift = [];
    $autoShifts = [];
    foreach ($backup['breaks'] as $break) {
        if (!abv_is_auto($break['source'] ?? '')) {
            continue;
        }
        $seconds = max(0, (int)($break['seconds'] ?? 0));
        $userId = (int)($break['user_id'] ?? 0);
        $shiftId = (int)($break['shift_id'] ?? 0);
        $autoByUser[$userId] = ($autoByUser[$userId] ?? 0) + $seconds;
        $autoByShift[$shiftId] = ($autoByShift[$shiftId] ?? 0) + $seconds;
        $autoShifts[$shiftId] = true;
    }
    $bakNonAuto = [];
    $liveNonAuto = [];
    foreach ([['bak', $backup['breaks'], &$bakNonAuto], ['live', $live['breaks'], &$liveNonAuto]] as $pack) {
        foreach ($pack[1] as $break) {
            if (abv_is_auto($break['source'] ?? '')) {
                continue;
            }
            $shiftId = (int)($break['shift_id'] ?? 0);
            $pack[2][$shiftId] = ($pack[2][$shiftId] ?? 0) + max(0, (int)($break['seconds'] ?? 0));
        }
    }
    $bakLines = [];
    $liveLines = [];
    foreach ($backup['lines'] as $line) {
        $bakLines[(int)$line['id']] = $line;
    }
    foreach ($live['lines'] as $line) {
        $liveLines[(int)$line['id']] = $line;
    }
    $ids = array_unique(array_merge(array_keys($bakLines), array_keys($liveLines)));
    sort($ids);
    $userDelta = [];
    $userGrossBak = [];
    $userGrossLive = [];
    $shiftDelta = [];
    foreach ($ids as $id) {
        $bak = $bakLines[$id] ?? null;
        $now = $liveLines[$id] ?? null;
        $userId = (int)(($now['user_id'] ?? $bak['user_id'] ?? 0));
        $shiftId = (int)(($now['attendance_id'] ?? $bak['attendance_id'] ?? 0));
        $bakH = $bak ? (float)($bak['reg_hours'] ?? 0) : 0.0;
        $nowH = $now ? (float)($now['reg_hours'] ?? 0) : 0.0;
        $bakG = $bak ? (float)($bak['total_gross'] ?? $bak['reg_pay'] ?? 0) : 0.0;
        $nowG = $now ? (float)($now['total_gross'] ?? $now['reg_pay'] ?? 0) : 0.0;
        $shiftDelta[$shiftId] = ($shiftDelta[$shiftId] ?? 0) + ($nowH - $bakH);
        if (!isset($autoShifts[$shiftId])) {
            continue;
        }
        $userDelta[$userId] = ($userDelta[$userId] ?? 0) + ($nowH - $bakH);
        $userGrossBak[$userId] = ($userGrossBak[$userId] ?? 0) + $bakG;
        $userGrossLive[$userId] = ($userGrossLive[$userId] ?? 0) + $nowG;
    }
    $users = array_keys($autoByUser);
    sort($users, SORT_NUMERIC);
    $dupes = [];
    $seenClock = [];
    foreach ($backup['shifts'] as $shiftId => $shift) {
        $key = (int)($shift['user_id'] ?? 0) . '|' . (int)($shift['site_id'] ?? 0) . '|' . (string)($shift['clock_in'] ?? '');
        $seenClock[$key][] = (int)$shiftId;
    }
    foreach ($seenClock as $idsForClock) {
        if (count($idsForClock) > 1) {
            foreach ($idsForClock as $shiftId) {
                $dupes[$shiftId] = $idsForClock;
            }
        }
    }
    $lines = [];
    $lines[] = 'READ-ONLY auto-break verification';
    $lines[] = 'Rule: flag an employee when payroll hours added exceed auto-break hours removed by more than 0.05 h.';
    $lines[] = 'employees';
    $flagged = 0;
    $removed = 0.0;
    $added = 0.0;
    $grossBefore = 0.0;
    $grossAfter = 0.0;
    $flagText = [];
    foreach ($users as $userId) {
        if ($userId <= 0) {
            continue;
        }
        $autoH = abv_hours((int)($autoByUser[$userId] ?? 0));
        $delta = round((float)($userDelta[$userId] ?? 0), 4);
        $removed += $autoH;
        $added += $delta;
        $grossBefore += (float)($userGrossBak[$userId] ?? 0);
        $grossAfter += (float)($userGrossLive[$userId] ?? 0);
        $name = $live['names'][$userId] ?? ($backup['names'][$userId] ?? '');
        $excess = round($delta - $autoH, 4);
        $flag = $excess > 0.05;
        if ($flag) {
            $flagged++;
        }
        $lines[] = '  employee ' . $userId . ($name !== '' ? ' ' . $name : '')
            . ' auto ' . number_format($autoH, 2, '.', '') . 'h'
            . ' hours added ' . number_format($delta, 2, '.', '')
            . ' gross ' . number_format((float)($userGrossBak[$userId] ?? 0), 2, '.', '')
            . ' -> ' . number_format((float)($userGrossLive[$userId] ?? 0), 2, '.', '')
            . ($flag ? ' FLAG excess ' . number_format($excess, 2, '.', '') . 'h' : '');
        if (!$flag) {
            continue;
        }
        $causes = abv_causes($userId, $autoShifts, $autoByShift, $bakNonAuto, $liveNonAuto, $shiftDelta, $backup, $live, $dupes);
        $flagText[] = 'FLAG employee ' . $userId . ($name !== '' ? ' ' . $name : '')
            . ' excess ' . number_format($excess, 2, '.', '') . 'h';
        foreach ($causes as $cause) {
            $flagText[] = '  ' . $cause;
        }
    }
    $lines[] = 'FLAGGED ' . $flagged;
    foreach ($flagText as $text) {
        $lines[] = $text;
    }
    if ($flagText === []) {
        $lines[] = 'No employee gained more hours than the auto breaks removed.';
    }
    $unchanged = abv_unchanged($autoShifts, $bakLines, $liveLines, $backup);
    $lines[] = 'pending lines not recalculated: ' . count($unchanged);
    if ($unchanged === []) {
        $lines[] = '  (none)';
    }
    foreach ($unchanged as $row) {
        $lines[] = '  line ' . $row['id'] . ' employee ' . $row['user_id'] . ' shift ' . $row['shift_id']
            . ' hours ' . number_format($row['hours'], 2, '.', '')
            . ' reason: ' . $row['reason'];
    }
    $lines[] = 'TOTAL auto-break hours removed: ' . number_format($removed, 2, '.', '');
    $lines[] = 'TOTAL payroll hours added: ' . number_format($added, 2, '.', '');
    $lines[] = 'TOTAL gross before: ' . number_format($grossBefore, 2, '.', '');
    $lines[] = 'TOTAL gross after: ' . number_format($grossAfter, 2, '.', '');
    return implode("\n", $lines);
}

function abv_causes(int $userId, array $autoShifts, array $autoByShift, array $bakNonAuto, array $liveNonAuto, array $shiftDelta, array $backup, array $live, array $dupes): array
{
    $out = [];
    $shiftIds = [];
    foreach ($backup['shifts'] as $shiftId => $shift) {
        if ((int)($shift['user_id'] ?? 0) === $userId) {
            $shiftIds[] = (int)$shiftId;
        }
    }
    foreach ($live['shifts'] as $shiftId => $shift) {
        if ((int)($shift['user_id'] ?? 0) === $userId) {
            $shiftIds[] = (int)$shiftId;
        }
    }
    $shiftIds = array_values(array_unique($shiftIds));
    sort($shiftIds);
    foreach ($shiftIds as $shiftId) {
        $delta = round((float)($shiftDelta[$shiftId] ?? 0), 4);
        $autoH = abv_hours((int)($autoByShift[$shiftId] ?? 0));
        if ($delta <= $autoH + 0.05) {
            continue;
        }
        $bakShift = $backup['shifts'][$shiftId] ?? [];
        $liveShift = $live['shifts'][$shiftId] ?? [];
        $reasons = [];
        $bakPunch = (int)($bakNonAuto[$shiftId] ?? 0);
        $livePunch = (int)($liveNonAuto[$shiftId] ?? 0);
        if ($livePunch + 30 < $bakPunch) {
            $reasons[] = 'punched breaks dropped (' . abv_hours($bakPunch - $livePunch) . 'h of non-auto break time is in the backup and missing live)';
        }
        $bakIn = (string)($bakShift['clock_in'] ?? '');
        $bakOut = (string)($bakShift['clock_out'] ?? '');
        $liveIn = (string)($liveShift['clock_in'] ?? $bakIn);
        $liveOut = (string)($liveShift['clock_out'] ?? $bakOut);
        if ($bakIn !== $liveIn || $bakOut !== $liveOut) {
            $reasons[] = 'clock times differ (backup ' . $bakIn . ' to ' . $bakOut . ', live ' . $liveIn . ' to ' . $liveOut . ')';
        }
        $span = abv_span($bakIn, $bakOut);
        $net = abv_hours(max(0, $span - $livePunch));
        $reasons[] = 'lines were recalculated to the clock span (' . number_format($net, 2, '.', '') . 'h after remaining breaks) instead of adding only the ' . number_format($autoH, 2, '.', '') . 'h auto break';
        if (isset($dupes[$shiftId])) {
            $reasons[] = 'duplicate shifts share this clock-in: ' . implode(', ', $dupes[$shiftId]);
        }
        if (!isset($autoShifts[$shiftId])) {
            $reasons[] = 'this shift had no source=auto break in the backup';
        }
        $out[] = 'shift ' . $shiftId . ' hours added ' . number_format($delta, 2, '.', '')
            . ' auto ' . number_format($autoH, 2, '.', '') . 'h; ' . implode('; ', $reasons);
    }
    if ($out === []) {
        $out[] = 'hours increased across several lines by more than the auto breaks; no single shift showed a larger cause';
    }
    return $out;
}

function abv_unchanged(array $autoShifts, array $bakLines, array $liveLines, array $backup): array
{
    $pendingByShift = [];
    foreach ($bakLines as $line) {
        $shiftId = (int)($line['attendance_id'] ?? 0);
        if (!isset($autoShifts[$shiftId]) || !abv_is_pending($line)) {
            continue;
        }
        $pendingByShift[$shiftId][] = $line;
    }
    $out = [];
    foreach ($pendingByShift as $shiftId => $group) {
        $target = null;
        foreach ($group as $line) {
            if ($target === null || (float)($line['reg_hours'] ?? 0) > (float)($target['reg_hours'] ?? 0)) {
                $target = $line;
            }
        }
        foreach ($group as $line) {
            $id = (int)$line['id'];
            $live = $liveLines[$id] ?? null;
            if ($live === null) {
                $out[] = ['id' => $id, 'user_id' => (int)$line['user_id'], 'shift_id' => $shiftId, 'hours' => (float)$line['reg_hours'], 'reason' => 'the line is in the backup and not on live'];
                continue;
            }
            if (abs((float)($live['reg_hours'] ?? 0) - (float)($line['reg_hours'] ?? 0)) >= 0.0001) {
                continue;
            }
            if ((int)$target['id'] !== $id) {
                $reason = 'only the largest pending line on the shift was rewritten; this line was left as stored';
            } else {
                $shift = $backup['shifts'][$shiftId] ?? [];
                $reason = 'the clock span minus the breaks that remained already matched the stored hours ('
                    . (string)($shift['clock_in'] ?? '') . ' to ' . (string)($shift['clock_out'] ?? '') . '), so the writer left it unchanged';
            }
            $out[] = [
                'id' => $id,
                'user_id' => (int)$line['user_id'],
                'shift_id' => $shiftId,
                'hours' => (float)$line['reg_hours'],
                'reason' => $reason,
            ];
        }
    }
    usort($out, static function ($a, $b) {
        return $a['id'] <=> $b['id'];
    });
    return $out;
}

function abv_self_test(): int
{
    $shift = static function (int $id, int $user, string $in, string $out): array {
        return ['user_id' => $user, 'site_id' => 12, 'clock_in' => $in, 'clock_out' => $out];
    };
    $line = static function (int $id, int $user, int $shift, float $hours, float $gross): array {
        return [
            'id' => $id, 'user_id' => $user, 'attendance_id' => $shift, 'reg_hours' => $hours,
            'total_gross' => $gross, 'category' => 'Regular', 'pay_type' => 'Regular',
            'status' => 'pending', 'payment_status' => 'Unpaid', 'paid_amount' => 0,
        ];
    };
    $backup = [
        'names' => [1 => 'Even', 2 => 'Span', 3 => 'Punch', 4 => 'Sibling'],
        'shifts' => [
            1 => $shift(1, 1, '2026-11-01 08:00:00', '2026-11-01 16:00:00'),
            2 => $shift(2, 2, '2026-11-02 08:00:00', '2026-11-02 16:00:00'),
            3 => $shift(3, 3, '2026-11-03 08:00:00', '2026-11-03 16:00:00'),
            4 => $shift(4, 4, '2026-11-04 08:00:00', '2026-11-04 16:00:00'),
        ],
        'breaks' => [
            ['shift_id' => 1, 'user_id' => 1, 'source' => 'auto', 'seconds' => 1800],
            ['shift_id' => 2, 'user_id' => 2, 'source' => 'auto', 'seconds' => 1800],
            ['shift_id' => 3, 'user_id' => 3, 'source' => 'auto', 'seconds' => 1800],
            ['shift_id' => 3, 'user_id' => 3, 'source' => 'manual', 'seconds' => 900],
            ['shift_id' => 4, 'user_id' => 4, 'source' => 'auto', 'seconds' => 1800],
        ],
        'lines' => [
            $line(101, 1, 1, 7.5, 150),
            $line(201, 2, 2, 4.0, 80),
            $line(301, 3, 3, 7.25, 145),
            $line(401, 4, 4, 7.0, 140),
            $line(402, 4, 4, 0.5, 10),
        ],
    ];
    $live = $backup;
    $live['breaks'] = [
        ['shift_id' => 3, 'user_id' => 3, 'source' => 'manual', 'seconds' => 0],
    ];
    $live['lines'] = [
        $line(101, 1, 1, 8.0, 160),
        $line(201, 2, 2, 8.0, 160),
        $line(301, 3, 3, 8.0, 160),
        $line(401, 4, 4, 7.5, 150),
        $line(402, 4, 4, 0.5, 10),
    ];
    $text = abv_report($backup, $live);
    $fail = 0;
    $need = [
        'FLAG employee 2',
        'clock span',
        'FLAG employee 3',
        'punched breaks dropped',
        'pending lines not recalculated: 1',
        'line 402 employee 4',
        'largest pending line',
        'TOTAL auto-break hours removed: 2.00',
        'TOTAL payroll hours added: 5.75',
        'TOTAL gross before: 525.00',
        'TOTAL gross after: 640.00',
    ];
    foreach ($need as $bit) {
        if (!str_contains($text, $bit)) {
            echo "FAIL missing: $bit\n";
            $fail++;
        }
    }
    if (str_contains($text, 'FLAG employee 1') || str_contains($text, 'FLAG employee 4')) {
        echo "FAIL a matched employee was flagged\n";
        $fail++;
    }
    if ($fail > 0) {
        echo $text . "\n";
        echo $fail . " failed\n";
        return 1;
    }
    echo "ok  matched employee is not flagged\n";
    echo "ok  clock-span excess is flagged\n";
    echo "ok  a dropped punched break is flagged\n";
    echo "ok  the untouched sibling line is listed\n";
    echo "ok  totals match the fixture\n";
    echo "all passed\n";
    return 0;
}

function abv_fail(string $message): void
{
    echo $message . "\n";
    exit(1);
}

function abv_cnf(): array
{
    $out = ['user' => 'root', 'password' => '', 'socket' => '', 'host' => 'localhost'];
    $path = (getenv('HOME') ?: '') . '/.my.cnf';
    if (!is_file($path)) {
        return $out;
    }
    $section = '';
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        if (preg_match('/^\[(.+)\]$/', $line, $match)) {
            $section = $match[1];
            continue;
        }
        if (!in_array($section, ['client', 'mysql'], true) || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");
        if ($key === 'user') {
            $out['user'] = $value;
        } elseif ($key === 'password') {
            $out['password'] = $value;
        } elseif ($key === 'socket') {
            $out['socket'] = $value;
        } elseif ($key === 'host') {
            $out['host'] = $value;
        }
    }
    return $out;
}

function abv_pdo(array $cnf, string $db): PDO
{
    $socket = $cnf['socket'] !== '' ? ';unix_socket=' . $cnf['socket'] : '';
    $pdo = new PDO(
        'mysql:host=' . $cnf['host'] . $socket . ';dbname=' . $db . ';charset=utf8mb4',
        $cnf['user'],
        $cnf['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $pdo;
}

function abv_select(PDO $pdo, string $sql, array $params = []): array
{
    if (!preg_match('/^\s*(SELECT|SHOW)\b/i', $sql)) {
        abv_fail('Refusing to run anything except SELECT: ' . $sql);
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function abv_columns(PDO $pdo, string $table): array
{
    $rows = abv_select($pdo, 'SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
    $names = [];
    foreach ($rows as $row) {
        $names[] = (string)$row['Field'];
    }
    return $names;
}

function abv_pick(array $cols, array $cands): ?string
{
    $by = [];
    foreach ($cols as $col) {
        $by[strtolower($col)] = $col;
    }
    foreach ($cands as $cand) {
        if (isset($by[strtolower($cand)])) {
            return $by[strtolower($cand)];
        }
    }
    return null;
}

function abv_load(PDO $pdo): array
{
    $breaks = abv_columns($pdo, 'breaks');
    $attendance = abv_columns($pdo, 'attendance');
    $finalized = abv_columns($pdo, 'payroll_finalized');
    echo 'detected breaks columns: ' . implode(', ', $breaks) . "\n";
    echo 'detected attendance columns: ' . implode(', ', $attendance) . "\n";
    $source = abv_pick($breaks, ['source', 'break_source']);
    $shiftId = abv_pick($breaks, ['shift_id', 'attendance_id']);
    $start = abv_pick($breaks, ['break_start', 'start_time']);
    $end = abv_pick($breaks, ['break_end', 'end_time']);
    $attId = abv_pick($attendance, ['id']);
    $userId = abv_pick($attendance, ['user_id', 'employee_id']);
    $siteId = abv_pick($attendance, ['site_id']);
    $clockIn = abv_pick($attendance, ['clock_in', 'work_date', 'shift_date']);
    $clockOut = abv_pick($attendance, ['clock_out']);
    echo 'detected breaks source column: ' . ($source ?? 'NOT FOUND') . "\n";
    echo 'detected attendance clock column: ' . ($clockIn ?? 'NOT FOUND') . "\n";
    if ($source === null || $shiftId === null || $start === null || $end === null || $attId === null || $userId === null || $clockIn === null) {
        abv_fail('The backup or live schema is missing a column needed to compare breaks and shifts.');
    }
    $q = static function (string $name): string {
        return '`' . str_replace('`', '', $name) . '`';
    };
    $breakRows = abv_select($pdo, 'SELECT b.' . $q($shiftId) . ' AS shift_id, a.' . $q($userId) . ' AS user_id, b.' . $q($source) . ' AS source,
        GREATEST(TIMESTAMPDIFF(SECOND, b.' . $q($start) . ', b.' . $q($end) . '), 0) AS seconds
        FROM breaks b INNER JOIN attendance a ON a.' . $q($attId) . ' = b.' . $q($shiftId));
    $shiftSql = 'SELECT a.' . $q($attId) . ' AS id, a.' . $q($userId) . ' AS user_id, '
        . ($siteId ? 'a.' . $q($siteId) : '0') . ' AS site_id, a.' . $q($clockIn) . ' AS clock_in, '
        . ($clockOut ? 'a.' . $q($clockOut) : "''") . ' AS clock_out FROM attendance a';
    $shiftRows = abv_select($pdo, $shiftSql);
    $shifts = [];
    foreach ($shiftRows as $row) {
        $shifts[(int)$row['id']] = $row;
    }
    $want = ['id', 'user_id', 'attendance_id', 'reg_hours', 'total_gross', 'reg_pay', 'category', 'pay_type', 'status', 'payment_status', 'paid_amount'];
    $have = [];
    foreach ($want as $col) {
        $found = abv_pick($finalized, [$col]);
        if ($found !== null) {
            $have[] = 'f.`' . str_replace('`', '', $found) . '` AS `' . $col . '`';
        }
    }
    if (abv_pick($finalized, ['id']) === null) {
        abv_fail('payroll_finalized has no id column.');
    }
    $lineRows = abv_select($pdo, 'SELECT ' . implode(', ', $have) . ' FROM payroll_finalized f');
    $names = [];
    try {
        $userCols = abv_columns($pdo, 'users');
        $nameCol = abv_pick($userCols, ['Name', 'name']);
        $idCol = abv_pick($userCols, ['id']);
        if ($nameCol !== null && $idCol !== null) {
            foreach (abv_select($pdo, 'SELECT `' . $idCol . '` AS id, `' . $nameCol . '` AS name FROM users') as $row) {
                $names[(int)$row['id']] = (string)$row['name'];
            }
        }
    } catch (Throwable $e) {
        $names = [];
    }
    return ['breaks' => $breakRows, 'lines' => $lineRows, 'shifts' => $shifts, 'names' => $names];
}

if (getenv('VERIFY_SQLITE') === '1') {
    exit(abv_self_test());
}
$verifyDb = getenv('VERIFY_DB') ?: '';
$liveDb = getenv('LIVE_DB') ?: 'admin_payroll';
if ($verifyDb === '' || $verifyDb === $liveDb || $verifyDb === 'admin_payroll') {
    abv_fail('Refusing to read the comparison from the live database name.');
}
$cnf = abv_cnf();
$backup = abv_load(abv_pdo($cnf, $verifyDb));
$live = abv_load(abv_pdo($cnf, $liveDb));
if ($live['names'] !== []) {
    $backup['names'] = $live['names'] + $backup['names'];
}
echo abv_report($backup, $live) . "\n";
echo "No changes were written to $liveDb.\n";
