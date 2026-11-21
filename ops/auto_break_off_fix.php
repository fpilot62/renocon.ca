<?php
/**
 * Put payroll lines back to the pre-cleanup values, then add only the
 * removed automatic-break hours. Does not restore break rows and does not
 * change the off switch.
 */
const ABF_EDIT_AFTER = '2026-11-21 03:34:00';

function abf_is_auto($source): bool
{
    return strtolower(trim((string)$source)) === 'auto';
}

function abf_is_stat(array $line): bool
{
    $cat = strtolower((string)($line['category'] ?? ''));
    $type = strtolower((string)($line['pay_type'] ?? ''));
    return str_contains($cat, 'statutor') || str_contains($cat, 'holiday') || $type === 'statutory';
}

function abf_is_locked(array $line): bool
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

function abf_span(?string $in, ?string $out): int
{
    if ($in === null || $out === null || trim($in) === '' || trim($out) === '') {
        return 0;
    }
    $span = strtotime($out) - strtotime($in);
    if ($span === false || $span < 0) {
        if ($span !== false && $span < 0) {
            $span += 86400;
        } else {
            return 0;
        }
    }
    return max(0, (int)$span);
}

function abf_money(float $hours, float $rate, float $oldHours, float $oldGross): float
{
    if ($rate > 0) {
        return round($hours * $rate, 2);
    }
    if ($oldHours > 0) {
        return round($oldGross * ($hours / $oldHours), 2);
    }
    return round($oldGross, 2);
}

function abf_near(float $a, float $b, float $tol = 0.001): bool
{
    return abs($a - $b) <= $tol;
}

/**
 * @param array{breaks:list<array<string,mixed>>,lines:list<array<string,mixed>>,shifts:array<int,array<string,mixed>>,names?:array<int,string>} $backup
 * @param array{breaks:list<array<string,mixed>>,lines:list<array<string,mixed>>,shifts:array<int,array<string,mixed>>,names?:array<int,string>} $live
 * @return array{updates:list<array<string,mixed>>,skips:list<array<string,mixed>>,employees:list<array<string,mixed>>,totals:array<string,float>,mismatch:bool}
 */
function abf_plan(array $backup, array $live, ?callable $engine = null): array
{
    $autoByShift = [];
    foreach ($backup['breaks'] as $break) {
        if (!abf_is_auto($break['source'] ?? '')) {
            continue;
        }
        $shiftId = (int)($break['shift_id'] ?? 0);
        $autoByShift[$shiftId] = ($autoByShift[$shiftId] ?? 0) + max(0, (int)($break['seconds'] ?? 0));
    }
    $remainByShift = [];
    foreach ($live['breaks'] as $break) {
        if (abf_is_auto($break['source'] ?? '')) {
            continue;
        }
        $shiftId = (int)($break['shift_id'] ?? 0);
        $remainByShift[$shiftId] = ($remainByShift[$shiftId] ?? 0) + max(0, (int)($break['seconds'] ?? 0));
    }
    $bakById = [];
    $liveById = [];
    $bakByShift = [];
    foreach ($backup['lines'] as $line) {
        $bakById[(int)$line['id']] = $line;
        $bakByShift[(int)($line['attendance_id'] ?? 0)][] = $line;
    }
    foreach ($live['lines'] as $line) {
        $liveById[(int)$line['id']] = $line;
    }
    $names = $live['names'] ?? ($backup['names'] ?? []);
    $updates = [];
    $skips = [];
    $fixedAuto = [];
    $skippedAuto = [];
    foreach ($autoByShift as $shiftId => $autoSeconds) {
        $group = $bakByShift[$shiftId] ?? [];
        $pending = [];
        foreach ($group as $line) {
            if (!abf_is_stat($line) && !abf_is_locked($line)) {
                $pending[] = $line;
            }
        }
        $target = null;
        foreach ($pending as $line) {
            if ($target === null || (float)($line['reg_hours'] ?? 0) > (float)($target['reg_hours'] ?? 0)) {
                $target = $line;
            }
        }
        $shift = $backup['shifts'][$shiftId] ?? ($live['shifts'][$shiftId] ?? []);
        $span = abf_span(
            isset($shift['clock_in']) ? (string)$shift['clock_in'] : null,
            isset($shift['clock_out']) ? (string)$shift['clock_out'] : null
        );
        $others = 0.0;
        if ($target !== null) {
            foreach ($pending as $line) {
                if ((int)$line['id'] !== (int)$target['id']) {
                    $others += (float)($line['reg_hours'] ?? 0);
                }
            }
        }
        $cleanupHours = round(max(0.0, ($span - (int)($remainByShift[$shiftId] ?? 0)) / 3600 - $others), 4);
        $autoHours = $autoSeconds / 3600;
        $userId = (int)($shift['user_id'] ?? ($target['user_id'] ?? 0));
        if ($target === null) {
            $skips[] = [
                'id' => 0,
                'user_id' => $userId,
                'shift_id' => $shiftId,
                'reason' => 'no pending unpaid line on this shift, so the auto-break minutes were not added',
            ];
            $skippedAuto[$userId] = ($skippedAuto[$userId] ?? 0) + $autoHours;
            continue;
        }
        $liveLine = $liveById[(int)$target['id']] ?? null;
        $reason = abf_skip_reason($target, $liveLine, $cleanupHours);
        if ($reason !== '') {
            $skips[] = [
                'id' => (int)$target['id'],
                'user_id' => (int)$target['user_id'],
                'shift_id' => $shiftId,
                'reason' => $reason,
            ];
            $skippedAuto[$userId] = ($skippedAuto[$userId] ?? 0) + $autoHours;
            continue;
        }
        $bakHours = (float)($target['reg_hours'] ?? 0);
        $bakGross = (float)($target['total_gross'] ?? $target['reg_pay'] ?? 0);
        $rate = (float)($target['hourly_rate'] ?? 0);
        $newHours = round($bakHours + $autoHours, 4);
        $gross = abf_money($newHours, $rate, $bakHours, $bakGross);
        $ded = abf_deductions($engine, $gross, $target, $shift);
        $updates[] = [
            'id' => (int)$target['id'],
            'user_id' => (int)$target['user_id'],
            'shift_id' => $shiftId,
            'backup_hours' => $bakHours,
            'current_hours' => (float)($liveLine['reg_hours'] ?? 0),
            'hours' => $newHours,
            'backup_gross' => $bakGross,
            'current_gross' => (float)($liveLine['total_gross'] ?? $liveLine['reg_pay'] ?? 0),
            'gross' => $gross,
            'rate' => $rate,
            'deductions' => $ded,
            'auto_hours' => $autoHours,
        ];
        $fixedAuto[$userId] = ($fixedAuto[$userId] ?? 0) + $autoHours;
    }
    $employees = [];
    $mismatch = false;
    $users = array_unique(array_merge(array_keys($fixedAuto), array_keys($skippedAuto), array_keys($autoByShift) ? array_map(static function ($shiftId) use ($backup, $live) {
        $shift = $backup['shifts'][$shiftId] ?? ($live['shifts'][$shiftId] ?? []);
        return (int)($shift['user_id'] ?? 0);
    }, array_keys($autoByShift)) : []));
    $users = array_values(array_filter($users, static fn($id) => $id > 0));
    sort($users);
    $hoursBefore = 0.0;
    $hoursCurrent = 0.0;
    $hoursAfter = 0.0;
    $grossBefore = 0.0;
    $grossCurrent = 0.0;
    $grossAfter = 0.0;
    $seenLine = [];
    foreach ($bakByShift as $shiftId => $group) {
        if (!isset($autoByShift[$shiftId])) {
            continue;
        }
        foreach ($group as $line) {
            if (abf_is_stat($line)) {
                continue;
            }
            $seenLine[(int)$line['id']] = true;
            $liveLine = $liveById[(int)$line['id']] ?? null;
            $hoursBefore += (float)($line['reg_hours'] ?? 0);
            $hoursCurrent += (float)($liveLine['reg_hours'] ?? 0);
            $grossBefore += (float)($line['total_gross'] ?? $line['reg_pay'] ?? 0);
            $grossCurrent += (float)($liveLine['total_gross'] ?? $liveLine['reg_pay'] ?? 0);
        }
    }
    foreach ($updates as $update) {
        $hoursAfter += $update['hours'];
        $grossAfter += $update['gross'];
        $seenLine[$update['id']] = $seenLine[$update['id']] ?? true;
    }
    foreach ($bakByShift as $shiftId => $group) {
        if (!isset($autoByShift[$shiftId])) {
            continue;
        }
        foreach ($group as $line) {
            if (abf_is_stat($line)) {
                continue;
            }
            $touched = false;
            foreach ($updates as $update) {
                if ($update['id'] === (int)$line['id']) {
                    $touched = true;
                    break;
                }
            }
            if ($touched) {
                continue;
            }
            $hoursAfter += (float)($line['reg_hours'] ?? 0);
            $grossAfter += (float)($line['total_gross'] ?? $line['reg_pay'] ?? 0);
        }
    }
    foreach ($users as $userId) {
        $autoFixed = round((float)($fixedAuto[$userId] ?? 0), 4);
        $added = 0.0;
        $gBefore = 0.0;
        $gCurrent = 0.0;
        $gAfter = 0.0;
        $hBefore = 0.0;
        $hCurrent = 0.0;
        $hAfter = 0.0;
        foreach ($updates as $update) {
            if ((int)$update['user_id'] !== $userId) {
                continue;
            }
            $added += $update['hours'] - $update['backup_hours'];
            $hBefore += $update['backup_hours'];
            $hCurrent += $update['current_hours'];
            $hAfter += $update['hours'];
            $gBefore += $update['backup_gross'];
            $gCurrent += $update['current_gross'];
            $gAfter += $update['gross'];
        }
        foreach ($bakByShift as $shiftId => $group) {
            if (!isset($autoByShift[$shiftId])) {
                continue;
            }
            $shift = $backup['shifts'][$shiftId] ?? [];
            if ((int)($shift['user_id'] ?? 0) !== $userId) {
                continue;
            }
            foreach ($group as $line) {
                if (abf_is_stat($line)) {
                    continue;
                }
                $isUpdate = false;
                foreach ($updates as $update) {
                    if ($update['id'] === (int)$line['id']) {
                        $isUpdate = true;
                        break;
                    }
                }
                if ($isUpdate) {
                    continue;
                }
                $liveLine = $liveById[(int)$line['id']] ?? null;
                $hBefore += (float)($line['reg_hours'] ?? 0);
                $hCurrent += (float)($liveLine['reg_hours'] ?? 0);
                $hAfter += (float)($line['reg_hours'] ?? 0);
                $gBefore += (float)($line['total_gross'] ?? $line['reg_pay'] ?? 0);
                $gCurrent += (float)($liveLine['total_gross'] ?? $liveLine['reg_pay'] ?? 0);
                $gAfter += (float)($line['total_gross'] ?? $line['reg_pay'] ?? 0);
            }
        }
        $added = round($added, 4);
        $gap = round($added - $autoFixed, 4);
        $bad = abs($gap) > 0.01;
        if ($bad) {
            $mismatch = true;
        }
        $employees[] = [
            'user_id' => $userId,
            'name' => (string)($names[$userId] ?? ''),
            'auto_hours' => round($autoFixed, 4),
            'hours_added' => $added,
            'skipped_auto' => round((float)($skippedAuto[$userId] ?? 0), 4),
            'hours_before' => round($hBefore, 4),
            'hours_current' => round($hCurrent, 4),
            'hours_after' => round($hAfter, 4),
            'gross_before' => round($gBefore, 2),
            'gross_current' => round($gCurrent, 2),
            'gross_after' => round($gAfter, 2),
            'mismatch' => $bad,
        ];
    }
    return [
        'updates' => $updates,
        'skips' => $skips,
        'employees' => $employees,
        'totals' => [
            'hours_before' => round($hoursBefore, 4),
            'hours_current' => round($hoursCurrent, 4),
            'hours_after' => round($hoursAfter, 4),
            'gross_before' => round($grossBefore, 2),
            'gross_current' => round($grossCurrent, 2),
            'gross_after' => round($grossAfter, 2),
            'auto_hours' => round(array_sum($fixedAuto), 4),
            'hours_added' => round(array_sum($fixedAuto) > 0 ? array_sum(array_map(static fn($row) => $row['hours_added'], $employees)) : 0, 4),
        ],
        'mismatch' => $mismatch,
    ];
}

function abf_skip_reason(array $backupLine, ?array $liveLine, float $cleanupHours): string
{
    if ($liveLine === null) {
        return 'edited since 03:34 UTC: the line is in the backup and not on live';
    }
    if (abf_is_locked($liveLine) || abf_is_stat($liveLine)) {
        return 'the line is no longer a pending unpaid line';
    }
    $stamp = '';
    foreach (['updated_at', 'modified_at', 'edited_at'] as $col) {
        if (!empty($liveLine[$col])) {
            $stamp = (string)$liveLine[$col];
            break;
        }
    }
    $liveHours = (float)($liveLine['reg_hours'] ?? 0);
    $liveGross = (float)($liveLine['total_gross'] ?? $liveLine['reg_pay'] ?? 0);
    $rate = (float)($backupLine['hourly_rate'] ?? 0);
    $bakGross = (float)($backupLine['total_gross'] ?? $backupLine['reg_pay'] ?? 0);
    $bakHours = (float)($backupLine['reg_hours'] ?? 0);
    $cleanupGross = abf_money($cleanupHours, $rate, $bakHours, $bakGross);
    $hoursMatch = abf_near($liveHours, $cleanupHours);
    $grossMatch = abs($liveGross - $cleanupGross) <= 0.05;
    if ($hoursMatch && $grossMatch) {
        if ($stamp !== '' && strcmp($stamp, ABF_EDIT_AFTER) > 0 && !$hoursMatch) {
            return 'edited since 03:34 UTC (' . $stamp . ')';
        }
        return '';
    }
    $when = ($stamp !== '' && strcmp($stamp, ABF_EDIT_AFTER) > 0) ? ' (' . $stamp . ')' : '';
    return 'edited since 03:34 UTC' . $when . ': live hours ' . $liveHours . ' are not the cleanup clock-span value ' . $cleanupHours;
}

function abf_deductions(?callable $engine, float $gross, array $line, array $shift): array
{
    if ($engine === null) {
        return [];
    }
    $ded = $engine($gross, $line, $shift);
    return is_array($ded) ? $ded : [];
}

function abf_format(array $plan, bool $lines): string
{
    $out = [];
    $out[] = 'AUTO BREAK FIX — add back only the removed auto-break hours';
    $out[] = 'employees';
    if ($plan['employees'] === []) {
        $out[] = '  (none)';
    }
    foreach ($plan['employees'] as $row) {
        $out[] = '  employee ' . $row['user_id'] . ($row['name'] !== '' ? ' ' . $row['name'] : '')
            . ' auto ' . number_format($row['auto_hours'], 2, '.', '') . 'h'
            . ' hours added ' . number_format($row['hours_added'], 2, '.', '') . 'h'
            . ' gross before ' . number_format($row['gross_before'], 2, '.', '')
            . ' current ' . number_format($row['gross_current'], 2, '.', '')
            . ' after ' . number_format($row['gross_after'], 2, '.', '')
            . ($row['mismatch'] ? ' MISMATCH' : ' OK')
            . ($row['skipped_auto'] > 0 ? ' skipped auto ' . number_format($row['skipped_auto'], 2, '.', '') . 'h' : '');
    }
    $t = $plan['totals'];
    $out[] = 'TOTAL hours before cleanup: ' . number_format($t['hours_before'], 2, '.', '');
    $out[] = 'TOTAL hours current: ' . number_format($t['hours_current'], 2, '.', '');
    $out[] = 'TOTAL hours after fix: ' . number_format($t['hours_after'], 2, '.', '');
    $out[] = 'TOTAL auto hours applied: ' . number_format($t['auto_hours'], 2, '.', '');
    $out[] = 'TOTAL hours added: ' . number_format($t['hours_added'], 2, '.', '');
    $out[] = 'TOTAL gross before cleanup: ' . number_format($t['gross_before'], 2, '.', '');
    $out[] = 'TOTAL gross current: ' . number_format($t['gross_current'], 2, '.', '');
    $out[] = 'TOTAL gross after fix: ' . number_format($t['gross_after'], 2, '.', '');
    $out[] = 'skipped lines: ' . count($plan['skips']);
    foreach ($plan['skips'] as $skip) {
        $out[] = '  line ' . $skip['id'] . ' employee ' . $skip['user_id'] . ' shift ' . $skip['shift_id'] . ' reason: ' . $skip['reason'];
    }
    if ($lines) {
        $out[] = 'lines';
        foreach ($plan['updates'] as $update) {
            $out[] = '  line ' . $update['id'] . ' employee ' . $update['user_id']
                . ' hours ' . number_format($update['backup_hours'], 4, '.', '')
                . ' -> ' . number_format($update['current_hours'], 4, '.', '')
                . ' -> ' . number_format($update['hours'], 4, '.', '')
                . ' gross ' . number_format($update['backup_gross'], 2, '.', '')
                . ' -> ' . number_format($update['current_gross'], 2, '.', '')
                . ' -> ' . number_format($update['gross'], 2, '.', '');
        }
    }
    if ($plan['mismatch']) {
        $out[] = 'MISMATCH: an employee is more than 0.01 h away from the auto-break hours. go will not commit.';
    } else {
        $out[] = 'Every corrected employee matches within 0.01 h.';
    }
    return implode("\n", $out);
}

function abf_self_test(): int
{
    $shift = static function (int $id, int $user): array {
        return [
            'user_id' => $user,
            'site_id' => 12,
            'org_id' => 2,
            'clock_in' => '2026-11-02 08:00:00',
            'clock_out' => '2026-11-02 16:00:00',
        ];
    };
    $line = static function (int $id, int $user, int $shiftId, float $hours): array {
        return [
            'id' => $id,
            'user_id' => $user,
            'attendance_id' => $shiftId,
            'reg_hours' => $hours,
            'hourly_rate' => 20,
            'total_gross' => round($hours * 20, 2),
            'category' => 'Regular',
            'pay_type' => 'Regular',
            'status' => 'pending',
            'payment_status' => 'Unpaid',
            'paid_amount' => 0,
        ];
    };
    $backup = [
        'names' => [2 => 'Span', 4 => 'Sibling', 9 => 'Edited'],
        'shifts' => [2 => $shift(2, 2), 4 => $shift(4, 4), 9 => $shift(9, 9)],
        'breaks' => [
            ['shift_id' => 2, 'source' => 'auto', 'seconds' => 1800],
            ['shift_id' => 4, 'source' => 'auto', 'seconds' => 1800],
            ['shift_id' => 9, 'source' => 'auto', 'seconds' => 1800],
        ],
        'lines' => [
            $line(201, 2, 2, 4.0),
            $line(401, 4, 4, 7.0),
            $line(402, 4, 4, 0.5),
            $line(901, 9, 9, 7.5),
        ],
    ];
    $live = $backup;
    $live['breaks'] = [];
    $live['lines'] = [
        $line(201, 2, 2, 8.0),
        $line(401, 4, 4, 7.5),
        $line(402, 4, 4, 0.5),
        $line(901, 9, 9, 9.0),
    ];
    $live['lines'][3]['updated_at'] = '2026-11-21 05:00:00';
    $engine = static function (float $gross): array {
        return ['cpp' => round($gross * 0.01, 2), 'ei' => 1.0, 'income_tax' => 2.0, 'wcb' => 0.5, 'vac' => 0.0, 'cpp_er' => 0.2, 'ei_er' => 1.4];
    };
    $plan = abf_plan($backup, $live, $engine);
    $text = abf_format($plan, true);
    $fail = 0;
    $byId = [];
    foreach ($plan['updates'] as $update) {
        $byId[$update['id']] = $update;
    }
    if (!isset($byId[201]) || abs($byId[201]['hours'] - 4.5) > 0.0001 || abs($byId[201]['gross'] - 90) > 0.001) {
        echo "FAIL user 2 should return to 4.5 h / 90 gross\n";
        $fail++;
    }
    if (!isset($byId[201]) || abs($byId[201]['deductions']['cpp'] - 0.9) > 0.001) {
        echo "FAIL deductions were not recomputed from the corrected gross\n";
        $fail++;
    }
    if (isset($byId[402])) {
        echo "FAIL the smaller line should stay untouched\n";
        $fail++;
    }
    if (isset($byId[901])) {
        echo "FAIL an edited line was included\n";
        $fail++;
    }
    $skipped = array_column($plan['skips'], 'id');
    if (!in_array(901, $skipped, true)) {
        echo "FAIL the edited line was not listed\n";
        $fail++;
    }
    foreach ($plan['employees'] as $row) {
        if ($row['user_id'] === 9) {
            continue;
        }
        if ($row['mismatch'] || abs($row['hours_added'] - $row['auto_hours']) > 0.01) {
            echo "FAIL employee {$row['user_id']} does not match\n";
            $fail++;
        }
    }
    if (!str_contains($text, 'TOTAL gross before cleanup:') || !str_contains($text, 'TOTAL gross current:') || !str_contains($text, 'TOTAL gross after fix:')) {
        echo "FAIL totals are missing\n";
        $fail++;
    }
    if ($plan['mismatch']) {
        echo "FAIL the fixture was treated as a mismatch\n";
        $fail++;
    }
    if ($fail > 0) {
        echo $text . "\n";
        echo $fail . " failed\n";
        return 1;
    }
    echo "ok  a capped line gets only the auto-break minutes back\n";
    echo "ok  deductions follow the corrected gross\n";
    echo "ok  the smaller line is not rewritten\n";
    echo "ok  a line edited after 03:34 is skipped\n";
    echo "ok  corrected employees match within 0.01 h\n";
    echo "all passed\n";
    return 0;
}

function abf_fail(string $message): void
{
    echo $message . "\n";
    exit(1);
}

function abf_cnf(): array
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

function abf_pdo(array $cnf, string $db): PDO
{
    $socket = $cnf['socket'] !== '' ? ';unix_socket=' . $cnf['socket'] : '';
    return new PDO(
        'mysql:host=' . $cnf['host'] . $socket . ';dbname=' . $db . ';charset=utf8mb4',
        $cnf['user'],
        $cnf['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function abf_query(PDO $pdo, string $sql, array $params = []): array
{
    if (!preg_match('/^\s*(SELECT|SHOW)\b/i', ltrim($sql))) {
        abf_fail('Refusing to run anything except SELECT while reading: ' . $sql);
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function abf_columns(PDO $pdo, string $table): array
{
    $rows = abf_query($pdo, 'SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
    $names = [];
    foreach ($rows as $row) {
        $names[] = (string)$row['Field'];
    }
    return $names;
}

function abf_pick(array $cols, array $cands): ?string
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

function abf_load(PDO $pdo): array
{
    $breaks = abf_columns($pdo, 'breaks');
    $attendance = abf_columns($pdo, 'attendance');
    $finalized = abf_columns($pdo, 'payroll_finalized');
    echo 'detected breaks columns: ' . implode(', ', $breaks) . "\n";
    echo 'detected attendance columns: ' . implode(', ', $attendance) . "\n";
    $q = static fn(string $name): string => '`' . str_replace('`', '', $name) . '`';
    $source = abf_pick($breaks, ['source', 'break_source']);
    $shiftKey = abf_pick($breaks, ['shift_id', 'attendance_id']);
    $start = abf_pick($breaks, ['break_start', 'start_time']);
    $end = abf_pick($breaks, ['break_end', 'end_time']);
    $attId = abf_pick($attendance, ['id']);
    $userId = abf_pick($attendance, ['user_id', 'employee_id']);
    $clockIn = abf_pick($attendance, ['clock_in', 'work_date']);
    $clockOut = abf_pick($attendance, ['clock_out']);
    $siteId = abf_pick($attendance, ['site_id']);
    $orgId = abf_pick($attendance, ['org_id']);
    echo 'detected breaks source column: ' . ($source ?? 'NOT FOUND') . "\n";
    echo 'detected attendance clock column: ' . ($clockIn ?? 'NOT FOUND') . "\n";
    if ($source === null || $shiftKey === null || $start === null || $end === null || $attId === null || $userId === null || $clockIn === null) {
        abf_fail('A required break or attendance column is missing.');
    }
    $breakRows = abf_query($pdo, 'SELECT b.' . $q($shiftKey) . ' AS shift_id, a.' . $q($userId) . ' AS user_id, b.' . $q($source) . ' AS source,
        GREATEST(TIMESTAMPDIFF(SECOND, b.' . $q($start) . ', b.' . $q($end) . '), 0) AS seconds
        FROM breaks b INNER JOIN attendance a ON a.' . $q($attId) . ' = b.' . $q($shiftKey));
    $shiftSql = 'SELECT a.' . $q($attId) . ' AS id, a.' . $q($userId) . ' AS user_id, a.' . $q($clockIn) . ' AS clock_in, '
        . ($clockOut ? 'a.' . $q($clockOut) : "''") . ' AS clock_out, '
        . ($siteId ? 'a.' . $q($siteId) : '0') . ' AS site_id, '
        . ($orgId ? 'a.' . $q($orgId) : '0') . ' AS org_id FROM attendance a';
    $shifts = [];
    foreach (abf_query($pdo, $shiftSql) as $row) {
        $shifts[(int)$row['id']] = $row;
    }
    $want = ['id', 'user_id', 'attendance_id', 'reg_hours', 'hourly_rate', 'total_gross', 'reg_pay', 'category', 'pay_type', 'status', 'payment_status', 'paid_amount', 'work_date', 'org_id', 'site_id', 'updated_at', 'modified_at', 'edited_at'];
    $have = [];
    foreach ($want as $col) {
        $found = abf_pick($finalized, [$col]);
        if ($found !== null) {
            $have[] = 'f.' . $q($found) . ' AS ' . $q($col);
        }
    }
    if (abf_pick($finalized, ['id']) === null) {
        abf_fail('payroll_finalized has no id column.');
    }
    $lines = abf_query($pdo, 'SELECT ' . implode(', ', $have) . ' FROM payroll_finalized f');
    $names = [];
    try {
        $userCols = abf_columns($pdo, 'users');
        $nameCol = abf_pick($userCols, ['Name', 'name']);
        $idCol = abf_pick($userCols, ['id']);
        if ($nameCol !== null && $idCol !== null) {
            foreach (abf_query($pdo, 'SELECT ' . $q($idCol) . ' AS id, ' . $q($nameCol) . ' AS name FROM users') as $row) {
                $names[(int)$row['id']] = (string)$row['name'];
            }
        }
    } catch (Throwable $e) {
        $names = [];
    }
    return ['breaks' => $breakRows, 'lines' => $lines, 'shifts' => $shifts, 'names' => $names, 'finalized_columns' => $finalized];
}

function abf_engine(PDO $pdo, float $gross, array $line, array $shift): array
{
    $app = getenv('PAYROLL_APP') ?: '';
    $engine = $app !== '' ? $app . '/payroll_engine/payroll_engine.php' : '';
    if ($engine !== '' && is_file($engine) && !function_exists('pe_line')) {
        require_once $engine;
    }
    if (!function_exists('pe_line')) {
        return [];
    }
    $userId = (int)($line['user_id'] ?? $shift['user_id'] ?? 0);
    $siteId = (int)($line['site_id'] ?? $shift['site_id'] ?? 0);
    $orgId = (int)($line['org_id'] ?? $shift['org_id'] ?? 0);
    $day = substr((string)($line['work_date'] ?? $shift['clock_in'] ?? ''), 0, 10);
    $vac = function_exists('pe_vacation_rate') ? (float)pe_vacation_rate($pdo, $userId, $siteId, $day) : 0.0;
    $rates = function_exists('pe_rates') ? pe_rates($pdo) : [];
    $ded = pe_line($gross, $vac, $rates, [
        'pdo' => $pdo,
        'user_id' => $userId,
        'site_id' => $siteId,
        'org_id' => $orgId,
        'work_date' => $day,
    ]);
    return is_array($ded) ? $ded : [];
}

function abf_apply(PDO $live, array $plan, array $columns): void
{
    $map = [
        'reg_hours' => 'hours',
        'reg_pay' => 'gross',
        'total_gross' => 'gross',
        'cpp' => 'cpp',
        'ei' => 'ei',
        'income_tax' => 'income_tax',
        'wcb' => 'wcb',
        'vac' => 'vac',
        'cpp_er' => 'cpp_er',
        'ei_er' => 'ei_er',
        'cpp2' => 'cpp2',
        'cpp2_er' => 'cpp2_er',
    ];
    $have = [];
    foreach ($columns as $col) {
        $have[strtolower($col)] = $col;
    }
    foreach ($plan['updates'] as $update) {
        $sets = [];
        $params = [];
        $ded = $update['deductions'];
        $rowMap = $map;
        if ($ded === []) {
            foreach (['cpp', 'ei', 'income_tax', 'wcb', 'vac', 'cpp_er', 'ei_er', 'cpp2', 'cpp2_er'] as $drop) {
                unset($rowMap[$drop]);
            }
        }
        $values = [
            'hours' => $update['hours'],
            'gross' => $update['gross'],
            'cpp' => round((float)($ded['cpp'] ?? 0) + (float)($ded['cpp2'] ?? 0), 2),
            'ei' => (float)($ded['ei'] ?? 0),
            'income_tax' => (float)($ded['income_tax'] ?? 0),
            'wcb' => (float)($ded['wcb'] ?? 0),
            'vac' => (float)($ded['vac'] ?? 0),
            'cpp_er' => round((float)($ded['cpp_er'] ?? 0) + (float)($ded['cpp2_er'] ?? 0), 2),
            'ei_er' => (float)($ded['ei_er'] ?? 0),
            'cpp2' => (float)($ded['cpp2'] ?? 0),
            'cpp2_er' => (float)($ded['cpp2_er'] ?? 0),
        ];
        foreach ($rowMap as $col => $key) {
            if (!isset($have[$col]) || !array_key_exists($key, $values)) {
                continue;
            }
            $sets[] = '`' . $have[$col] . '` = ?';
            $params[] = $values[$key];
        }
        if ($sets === []) {
            continue;
        }
        $params[] = $update['id'];
        $sql = 'UPDATE payroll_finalized SET ' . implode(', ', $sets) . ' WHERE id = ?';
        if (isset($have['paid_amount'])) {
            $sql .= ' AND COALESCE(`' . $have['paid_amount'] . '`,0) <= 0.02';
        }
        $st = $live->prepare($sql);
        $st->execute($params);
        if ($st->rowCount() < 1) {
            abf_fail('Line ' . $update['id'] . ' was not updated. It may have been paid since the plan was built.');
        }
    }
}

function abf_main(): void
{
    $mode = getenv('FIX_MODE') ?: 'check';
    $verifyDb = getenv('VERIFY_DB') ?: '';
    $liveDb = getenv('LIVE_DB') ?: 'admin_payroll';
    if ($verifyDb === '' || $verifyDb === $liveDb || $verifyDb === 'admin_payroll') {
        abf_fail('Refusing to use the live database as the backup copy.');
    }
    $cnf = abf_cnf();
    $backupPdo = abf_pdo($cnf, $verifyDb);
    $livePdo = abf_pdo($cnf, $liveDb);
    $backup = abf_load($backupPdo);
    $live = abf_load($livePdo);
    if ($live['names'] !== []) {
        $backup['names'] = $live['names'] + $backup['names'];
    }
    $engine = static function (float $gross, array $line, array $shift) use ($livePdo): array {
        return abf_engine($livePdo, $gross, $line, $shift);
    };
    $plan = abf_plan($backup, $live, $engine);
    echo abf_format($plan, $mode === 'dry' || $mode === 'go') . "\n";
    if ($mode !== 'go') {
        echo "No changes were written to $liveDb.\n";
        exit($plan['mismatch'] ? 1 : 0);
    }
    if ($plan['mismatch']) {
        abf_fail('go refused: an employee is more than 0.01 h away from the auto-break hours. Nothing was committed.');
    }
    try {
        $livePdo->beginTransaction();
        abf_apply($livePdo, $plan, $live['finalized_columns']);
        $livePdo->commit();
    } catch (Throwable $e) {
        if ($livePdo->inTransaction()) {
            $livePdo->rollBack();
        }
        abf_fail('go failed and rolled back: ' . $e->getMessage());
    }
    echo "Committed the corrected hours. Auto breaks stay deleted and the switch was not changed.\n";
}

if (getenv('FIX_SQLITE') === '1') {
    exit(abf_self_test());
}
if (PHP_SAPI === 'cli' && isset($argv[0]) && @realpath($argv[0]) === @realpath(__FILE__)) {
    abf_main();
}
