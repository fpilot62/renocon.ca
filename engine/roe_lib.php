<?php
/**
 * Record of Employment (ROE) creation and ROE Web payroll extract XML (Version 2.0, FileVersion W-2.0).
 *
 * Spec: Service Canada "ROE Web user requirements - Appendix D" (payroll extract XML v2.0).
 * Schema: engine/roe/PayrollExtractXmlV2.xsd (official file, see engine/roe/README.md).
 *
 * This file never submits anything to Service Canada. The export defaults to Issue="D"
 * (ROE Web saves it as a draft). Nothing here writes to payroll_finalized, payments or balances.
 * SINs are read from users at export time and are never stored in roe_records.
 */

const ROE_FILE_VERSION = 'W-2.0';
const ROE_SOFTWARE_VENDOR = 'Renocon Payroll';
const ROE_PRODUCT_NAME = 'Renocon Payroll ROE';
const ROE_PRODUCT_VERSION = '1.0';
const ROE_MAX_PER_FILE = 1200;
const ROE_MAX_FILE_BYTES = 1000000;

function roe_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function roe_xsd_path(): string
{
    return __DIR__ . '/roe/PayrollExtractXmlV2.xsd';
}

/** Block 6 codes. */
function roe_pay_period_types(): array
{
    return [
        'W' => 'Weekly',
        'B' => 'Bi-weekly',
        'S' => 'Semi-monthly',
        'M' => 'Monthly',
        'H' => '13 pay periods per year',
        'E' => 'Semi-monthly non-standard',
        'O' => 'Monthly non-standard',
    ];
}

/** Table 15A.1 / 15C.1: maximum number of pay periods for blocks 15A and 15C. */
function roe_max_periods(string $type): int
{
    $map = ['B' => 27, 'M' => 13, 'O' => 13, 'S' => 25, 'E' => 25, 'H' => 14, 'W' => 53];
    return $map[strtoupper($type)] ?? 0;
}

/** Table 16.1 separation codes. */
function roe_reason_codes(): array
{
    return [
        'A00' => 'Shortage of work/End of contract or season',
        'A01' => 'Employer bankruptcy or receivership',
        'B00' => 'Strike or lockout',
        'D00' => 'Illness or injury',
        'E00' => 'Quit',
        'E02' => 'Quit/Follow spouse',
        'E03' => 'Quit/Return to school',
        'E04' => 'Quit/Health reasons',
        'E05' => 'Quit/Voluntary retirement',
        'E06' => 'Quit/Take another job',
        'E09' => 'Quit/Employer relocation',
        'E10' => 'Quit/Care for a dependant',
        'E11' => 'Quit/To become self-employed',
        'F00' => 'Maternity',
        'G00' => 'Mandatory retirement',
        'G07' => 'Retirement/Approved workforce reduction',
        'H00' => 'Work-Sharing',
        'J00' => 'Apprentice training',
        'K00' => 'Other',
        'K12' => 'Other/Change of payroll frequency',
        'K13' => 'Other/Change of ownership',
        'K14' => 'Other/Requested by Employment Insurance',
        'K15' => "Other/Canadian Forces - Queen's Regulations/Orders",
        'K16' => "Other/At the employee's request",
        'K17' => 'Other/Change of Service Provider',
        'M00' => 'Dismissal',
        'M08' => 'Dismissal/Terminated within probationary period',
        'N00' => 'Leave of absence',
        'P00' => 'Parental',
        'Z00' => 'Compassionate Care/Family Caregiver',
    ];
}

/** Table 17A.1. Rule per field: 'opt', 'blank' or 'req'. */
function roe_vacation_codes(): array
{
    return [
        '1' => ['label' => 'Included with each pay', 'sdt' => 'blank', 'edt' => 'blank', 'amt' => 'blank'],
        '2' => ['label' => 'Paid because no longer working', 'sdt' => 'blank', 'edt' => 'blank', 'amt' => 'req'],
        '3' => ['label' => 'Paid for a vacation leave period', 'sdt' => 'opt', 'edt' => 'opt', 'amt' => 'req'],
        '4' => ['label' => 'Anniversary (paid on a specific date each year)', 'sdt' => 'opt', 'edt' => 'blank', 'amt' => 'req'],
    ];
}

/** Table 17C.1. */
function roe_other_monies_codes(): array
{
    $r = static fn(string $l, string $s, string $e, string $a, bool $ins) => ['label' => $l, 'sdt' => $s, 'edt' => $e, 'amt' => $a, 'insurable' => $ins];
    return [
        'B05' => $r('Bonus (Holiday)', 'opt', 'blank', 'req', true),
        'B06' => $r('Bonus (Production/Incentive)', 'opt', 'opt', 'req', true),
        'B07' => $r('Bonus (Event)', 'opt', 'blank', 'req', true),
        'B08' => $r('Bonus (Staying/Contract complete/End of season)', 'blank', 'blank', 'req', true),
        'B09' => $r('Bonus (Separation or retirement)', 'blank', 'blank', 'req', true),
        'B10' => $r('Bonus (Closure)', 'blank', 'blank', 'req', true),
        'B11' => $r('Bonus (Other)', 'blank', 'blank', 'req', true),
        'E00' => $r('Severance pay', 'blank', 'blank', 'req', false),
        'G00' => $r('Gratuities', 'blank', 'blank', 'req', true),
        'H00' => $r('Honorariums', 'opt', 'opt', 'req', false),
        'I00' => $r('Sick leave credits', 'opt', 'blank', 'req', true),
        'J00' => $r('Retroactive pay adjustment', 'blank', 'blank', 'req', true),
        'O00' => $r('Other', 'opt', 'opt', 'req', true),
        'Q00' => $r('Profit sharing', 'blank', 'blank', 'req', true),
        'R00' => $r('Retiring allowance/Retirement leave credits', 'blank', 'blank', 'req', false),
        'S00' => $r('Settlement pay', 'opt', 'opt', 'req', true),
        'T00' => $r('Payout of banked overtime', 'blank', 'blank', 'req', true),
        'U12' => $r('SUB Maternity/Parental/Compassionate Care/Family Caregiver', 'blank', 'blank', 'opt', false),
        'U13' => $r('SUB Layoff', 'blank', 'blank', 'opt', false),
        'U14' => $r('SUB Illness', 'blank', 'blank', 'opt', false),
        'U15' => $r('SUB Training', 'blank', 'blank', 'opt', false),
        'Y00' => $r('Pay in lieu of notice', 'blank', 'blank', 'req', true),
    ];
}

/* ------------------------------------------------------------------ */
/* Small value helpers                                                  */
/* ------------------------------------------------------------------ */

function roe_valid_date($v): ?string
{
    $v = trim((string)$v);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return null;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $v));
    return checkdate($m, $d, $y) ? $v : null;
}

function roe_add_days(string $date, int $days): string
{
    $dt = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
    return $dt->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

function roe_days_between(string $a, string $b): int
{
    $da = new DateTimeImmutable($a . ' 00:00:00', new DateTimeZone('UTC'));
    $db = new DateTimeImmutable($b . ' 00:00:00', new DateTimeZone('UTC'));
    return (int)round(($db->getTimestamp() - $da->getTimestamp()) / 86400);
}

function roe_last_of_month(string $date): string
{
    return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->format('Y-m-t');
}

function roe_money(float $v): string
{
    return number_format(round($v + 0.0, 2), 2, '.', '');
}

function roe_normalize_bn(string $bn): string
{
    return strtoupper(preg_replace('/[\s\-]+/', '', $bn) ?? '');
}

/** Appendix D block 5. Returns '' when valid, otherwise the problem. */
function roe_bn_problem(string $bn): string
{
    if ($bn === '') {
        return 'CRA payroll account number (BN15) is missing.';
    }
    if (!preg_match('/^[0-9]{9}R[PW][0-9]{4}$/', $bn)) {
        return 'BN15 must be 9 digits, RP, then 4 digits (for example 123456789RP0001).';
    }
    if (!in_array($bn[0], ['1', '7', '8'], true)) {
        return 'BN15 must start with 1, 7 or 8.';
    }
    if (substr($bn, 0, 9) === '000000000') {
        return 'BN15 first 9 digits cannot be all zeros.';
    }
    if (substr($bn, -4) === '0000') {
        return 'BN15 last 4 digits must be greater than 0000.';
    }
    return '';
}

function roe_normalize_sin(string $sin): string
{
    return preg_replace('/\D+/', '', $sin) ?? '';
}

function roe_luhn_ok(string $digits): bool
{
    if ($digits === '' || !ctype_digit($digits)) {
        return false;
    }
    $sum = 0;
    $alt = false;
    for ($i = strlen($digits) - 1; $i >= 0; $i--) {
        $n = (int)$digits[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
        $alt = !$alt;
    }
    return $sum % 10 === 0;
}

function roe_normalize_postal(string $pc): string
{
    return strtoupper(preg_replace('/[\s\-]+/', '', $pc) ?? '');
}

/** Appendix D table 1: the characters ROE Web accepts. */
function roe_charset_ok(string $s): bool
{
    return (bool)preg_match('/^[A-Za-z0-9ÁÀÂÄÇÉÈÊËÍÌÎÏÓÒÔÖÚÙÛÜáàâäçéèêëíìîïóòôöúùûü "\;%!)(*#\-+\/$&@=?:\'~|`^_\[{\]},.]*$/u', $s);
}

function roe_len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function roe_substr(string $s, int $len): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $len, 'UTF-8') : substr($s, 0, $len);
}

/* ------------------------------------------------------------------ */
/* Pay period math                                                       */
/* ------------------------------------------------------------------ */

/**
 * Period [start, end] that contains $date.
 * W, B and H need $anchorEnd: any known period end date for that payroll.
 * E and O (non-standard) cannot be derived; they throw.
 */
function roe_period_for(string $type, string $date, ?string $anchorEnd = null): array
{
    $type = strtoupper($type);
    if ($type === 'S') {
        $day = (int)substr($date, 8, 2);
        $ym = substr($date, 0, 8);
        return $day <= 15 ? [$ym . '01', $ym . '15'] : [$ym . '16', roe_last_of_month($date)];
    }
    if ($type === 'M') {
        return [substr($date, 0, 8) . '01', roe_last_of_month($date)];
    }
    $len = ['W' => 7, 'B' => 14, 'H' => 28][$type] ?? 0;
    if ($len > 0) {
        if (roe_valid_date((string)$anchorEnd) === null) {
            throw new InvalidArgumentException('A known pay period end date is needed for ' . roe_pay_period_types()[$type] . ' periods.');
        }
        $diff = roe_days_between((string)$anchorEnd, $date);
        $k = (int)ceil($diff / $len);
        $end = roe_add_days((string)$anchorEnd, $k * $len);
        return [roe_add_days($end, -($len - 1)), $end];
    }
    throw new InvalidArgumentException('Pay periods for type ' . $type . ' (non-standard) cannot be calculated automatically. Enter block 15C by hand.');
}

/** The period just before the one that starts on $start. */
function roe_previous_period(string $type, string $start, ?string $anchorEnd = null): array
{
    return roe_period_for($type, roe_add_days($start, -1), $anchorEnd);
}

/* ------------------------------------------------------------------ */
/* Generator: pay lines -> blocks 10, 11, 12, 15A, 15C                   */
/* ------------------------------------------------------------------ */

/**
 * @param array $lines Pay lines for ONE employee under ONE legal employer (BN15):
 *                     each ['work_date' => Y-m-d, 'hours' => number, 'gross' => number, 'category' => optional].
 * @param string|null $afterDate Last day for which paid on the previous ROE for this employee and BN.
 *                               Lines on or before it are left out (block 10 starts after it).
 */
function roe_compute(array $lines, string $type, ?string $anchorEnd = null, ?string $afterDate = null, ?string $lastDayOverride = null): array
{
    $out = ['ok' => false, 'errors' => [], 'warnings' => [], 'first_day_worked' => null, 'last_day_paid' => null,
        'final_pp_end' => null, 'insurable_hours' => 0, 'hours_exact' => 0.0, 'periods' => [], 'total_earnings' => 0.0];
    $type = strtoupper(trim($type));
    if (!isset(roe_pay_period_types()[$type])) {
        $out['errors'][] = 'Choose the pay period type (block 6) for this employer before generating.';
        return $out;
    }
    $byDate = [];
    $skippedBefore = 0;
    $categories = [];
    foreach ($lines as $l) {
        $d = roe_valid_date(substr((string)($l['work_date'] ?? ''), 0, 10));
        if ($d === null) {
            $out['warnings'][] = 'A pay line without a valid work date was left out.';
            continue;
        }
        if ($afterDate !== null && $d <= $afterDate) {
            $skippedBefore++;
            continue;
        }
        $h = (float)($l['hours'] ?? 0);
        $g = (float)($l['gross'] ?? 0);
        if ($g < 0) {
            $out['warnings'][] = 'Negative gross on ' . $d . ' (' . roe_money($g) . ') is included as an adjustment. Check it.';
        }
        if ($h > 0 && abs($g) < 0.005) {
            $out['warnings'][] = 'Hours with no gross on ' . $d . '. Check that line.';
        }
        $cat = trim((string)($l['category'] ?? ''));
        if ($cat !== '') {
            $categories[$cat] = ($categories[$cat] ?? 0) + 1;
        }
        if (!isset($byDate[$d])) {
            $byDate[$d] = ['hours' => 0.0, 'gross' => 0.0];
        }
        $byDate[$d]['hours'] += $h;
        $byDate[$d]['gross'] += $g;
    }
    if ($skippedBefore > 0) {
        $out['warnings'][] = $skippedBefore . ' pay line(s) on or before ' . $afterDate . ' are covered by an earlier ROE and were left out.';
    }
    $nonRegular = array_diff_key($categories, ['regular' => 1, 'Regular' => 1, 'REGULAR' => 1]);
    if ($nonRegular) {
        $parts = [];
        foreach ($categories as $c => $n) {
            $parts[] = $c . ' (' . $n . ')';
        }
        $out['warnings'][] = 'Pay line categories included: ' . implode(', ', $parts) . '. Confirm each is insurable and sits in the right pay period (regular wages go where earned; bonuses where paid).';
    }
    $paidDates = [];
    foreach ($byDate as $d => $v) {
        if ($v['gross'] > 0 || $v['hours'] > 0) {
            $paidDates[] = $d;
        }
    }
    if (!$paidDates) {
        $out['errors'][] = 'No finalized pay lines were found for this employee under this employer.';
        return $out;
    }
    sort($paidDates);
    $fdw = $paidDates[0];
    $ldp = $paidDates[count($paidDates) - 1];
    if ($lastDayOverride !== null && roe_valid_date($lastDayOverride) !== null) {
        if ($lastDayOverride < $ldp) {
            $out['warnings'][] = 'Last day for which paid was set to ' . $lastDayOverride . ' but pay lines run to ' . $ldp . '. Lines after it are left out.';
        }
        $ldp = $lastDayOverride;
    }
    try {
        [$fStart, $fEnd] = roe_period_for($type, $ldp, $anchorEnd);
        $periods = [];
        $max = roe_max_periods($type);
        $s = $fStart;
        $e = $fEnd;
        for ($i = 1; $i <= $max; $i++) {
            $periods[] = ['nbr' => $i, 'start' => $s, 'end' => $e, 'earnings' => 0.0, 'hours' => 0.0];
            if ($s <= $fdw) {
                break;
            }
            [$s, $e] = roe_previous_period($type, $s, $anchorEnd);
        }
    } catch (InvalidArgumentException $ex) {
        $out['errors'][] = $ex->getMessage();
        return $out;
    }
    $windowStart = $periods[count($periods) - 1]['start'];
    $older = 0;
    foreach ($byDate as $d => $v) {
        if ($d > $ldp) {
            continue;
        }
        if ($d < $windowStart) {
            $older++;
            continue;
        }
        foreach ($periods as &$p) {
            if ($d >= $p['start'] && $d <= $p['end']) {
                $p['earnings'] += $v['gross'];
                $p['hours'] += $v['hours'];
                break;
            }
        }
        unset($p);
    }
    $hours = 0.0;
    $total = 0.0;
    foreach ($periods as &$p) {
        $p['earnings'] = round($p['earnings'], 2);
        $p['hours'] = round($p['hours'], 4);
        $hours += $p['hours'];
        $total += $p['earnings'];
        if ($p['earnings'] < 0) {
            $out['errors'][] = 'Pay period ' . $p['start'] . ' to ' . $p['end'] . ' has negative earnings. Fix the pay lines.';
        }
    }
    unset($p);
    if ($older > 0) {
        $out['warnings'][] = $older . ' work day(s) before ' . $windowStart . ' are outside the last ' . count($periods) . ' pay periods and are not in blocks 15A/15C (as the spec requires).';
    }
    $out['first_day_worked'] = $fdw;
    $out['last_day_paid'] = $ldp;
    $out['final_pp_end'] = $fEnd;
    $out['periods'] = $periods;
    $out['hours_exact'] = round($hours, 4);
    $out['insurable_hours'] = (int)ceil(round($hours, 4) - 1e-9);
    $out['total_earnings'] = round($total, 2);
    $out['ok'] = !$out['errors'];
    return $out;
}

/* ------------------------------------------------------------------ */
/* Validation: Appendix D 8.3 business rules                            */
/* ------------------------------------------------------------------ */

/**
 * @param array  $roe    Decoded roe row: periods, vacation, stat_holidays, other_monies are arrays.
 * @param string $sin    Plain SIN read from users for this check only.
 * @param array  $others Other ROEs for the same employee and BN (id, first_day_worked, last_day_paid, status).
 * @return array{errors:array,warnings:array}
 */
function roe_validate(array $roe, string $sin, string $today, array $others = []): array
{
    $err = [];
    $warn = [];
    $txt = static function (string $label, $v, int $max, bool $required) use (&$err): void {
        $v = trim((string)$v);
        if ($v === '') {
            if ($required) {
                $err[] = $label . ' is required.';
            }
            return;
        }
        if (roe_len($v) > $max) {
            $err[] = $label . ' is longer than ' . $max . ' characters.';
        }
        if (!roe_charset_ok($v)) {
            $err[] = $label . ' has characters ROE Web does not accept.';
        }
    };

    $b2 = trim((string)($roe['amended_serial'] ?? ''));
    if ($b2 !== '' && !preg_match('/^[WM][0-9]{8}$/', $b2)) {
        $err[] = 'Block 2: serial number of the ROE amended must be W or M followed by 8 digits.';
    }
    $txt('Block 3 payroll reference', $roe['payroll_ref'] ?? '', 26, false);
    $bn = roe_normalize_bn((string)($roe['bn15'] ?? ''));
    $bnProblem = roe_bn_problem($bn);
    if ($bnProblem !== '') {
        $err[] = 'Block 5: ' . $bnProblem;
    } elseif (substr($bn, 9, 2) === 'RW') {
        $warn[] = 'Block 5 is an RW (ROE Web demo) account. Use it only on the ROE Web demo site.';
    }
    $type = strtoupper(trim((string)($roe['pay_period_type'] ?? '')));
    if (!isset(roe_pay_period_types()[$type])) {
        $err[] = 'Block 6: pay period type is required.';
    }
    $sinDigits = roe_normalize_sin($sin);
    if ($sinDigits === '') {
        $err[] = 'Block 8: the employee has no SIN on file.';
    } elseif (strlen($sinDigits) !== 9 || $sinDigits === '000000000') {
        $err[] = 'Block 8: SIN must be 9 digits and not all zeros.';
    } elseif (!roe_luhn_ok($sinDigits)) {
        $warn[] = 'Block 8: the SIN on file fails the check-digit test. Confirm it with the employee.';
    }
    $txt('Block 9 first name', $roe['emp_first'] ?? '', 20, true);
    $txt('Block 9 initial', $roe['emp_middle'] ?? '', 4, false);
    $txt('Block 9 last name', $roe['emp_last'] ?? '', 28, true);
    $txt('Block 9 address line 1', $roe['emp_addr1'] ?? '', 35, true);
    $txt('Block 9 address line 2 (city)', $roe['emp_addr2'] ?? '', 35, true);
    $txt('Block 9 address line 3 (province, country)', $roe['emp_addr3'] ?? '', 35, false);
    $pc = roe_normalize_postal((string)($roe['emp_postal'] ?? ''));
    if ($pc === '') {
        $err[] = 'Block 9 postal code is required.';
    } elseif (!preg_match('/^[A-Z][0-9][A-Z][0-9][A-Z][0-9]$/', $pc) && !preg_match('/^([0-9]{5}|[0-9]{9})$/', $pc)) {
        $err[] = 'Block 9 postal code must be X9X9X9 (Canada) or 99999 / 999999999 (US).';
    }

    $fdw = roe_valid_date($roe['first_day_worked'] ?? '');
    $ldp = roe_valid_date($roe['last_day_paid'] ?? '');
    $ppe = roe_valid_date($roe['final_pp_end'] ?? '');
    $fiftyAgo = (new DateTimeImmutable($today, new DateTimeZone('UTC')))->modify('-50 years')->format('Y-m-d');
    if ($fdw === null) {
        $err[] = 'Block 10: first day worked is required.';
    } elseif ($fdw < $fiftyAgo) {
        $err[] = 'Block 10: first day worked cannot be more than 50 years ago.';
    }
    if ($ldp === null) {
        $err[] = 'Block 11: last day for which paid is required.';
    } else {
        if ($fdw !== null && $ldp < $fdw) {
            $err[] = 'Block 11: last day for which paid is before the first day worked.';
        }
        if ($ldp > roe_add_days($today, 14)) {
            $err[] = 'Block 11: last day for which paid cannot be more than 14 days from today.';
        }
    }
    if ($ppe === null) {
        $err[] = 'Block 12: final pay period ending date is required.';
    } elseif ($ldp !== null && isset(roe_pay_period_types()[$type])) {
        $gap = roe_days_between($ldp, $ppe);
        $isEom = $ppe === roe_last_of_month($ppe);
        $is15 = substr($ppe, 8, 2) === '15';
        $isFebEnd = substr($ppe, 5, 2) === '02' && $isEom;
        $limits = ['W' => 6, 'B' => 13, 'S' => 15, 'M' => 30, 'H' => 27, 'E' => 15, 'O' => 30];
        if ($gap < 0) {
            $err[] = 'Block 12: final pay period ending date is before the last day for which paid.';
        } elseif ($gap > $limits[$type]) {
            $err[] = 'Block 12: final pay period ending date is more than ' . $limits[$type] . ' days after the last day for which paid.';
        }
        if ($type === 'S' && !$is15 && !$isEom) {
            $err[] = 'Block 12: a semi-monthly period must end on the 15th or the last day of the month.';
        }
        if ($type === 'M' && !$isEom) {
            $err[] = 'Block 12: a monthly period must end on the last day of the month.';
        }
        if ($type === 'E' && ($is15 || $isEom) && !$isFebEnd) {
            $err[] = 'Block 12: a non-standard semi-monthly period cannot end on the 15th or the last day of the month.';
        }
        if ($type === 'O' && $isEom && !$isFebEnd) {
            $err[] = 'Block 12: a non-standard monthly period cannot end on the last day of the month.';
        }
    }
    $txt('Block 13 occupation', $roe['occupation'] ?? '', 40, false);

    $reason = strtoupper(trim((string)($roe['reason_code'] ?? '')));
    if (!isset(roe_reason_codes()[$reason])) {
        $err[] = 'Block 16: choose a reason for issuing this ROE.';
    }
    $recall = strtoupper(trim((string)($roe['recall_code'] ?? ''))) ?: 'U';
    if (!in_array($recall, ['N', 'U', 'Y'], true)) {
        $err[] = 'Block 14: expected recall must be N, U or Y.';
    }
    $recallDate = trim((string)($roe['recall_date'] ?? ''));
    if ($recall === 'Y') {
        if (roe_valid_date($recallDate) === null) {
            $err[] = 'Block 14: a recall date is required when the code is Y.';
        } elseif ($ldp !== null && $recallDate <= $ldp) {
            $err[] = 'Block 14: the recall date must be after the last day for which paid.';
        }
        if ($reason !== '' && in_array($reason[0], ['E', 'G', 'M'], true)) {
            $err[] = 'Block 14: code Y is not allowed for Quit, Mandatory retirement or Dismissal reasons.';
        }
    } elseif ($recallDate !== '') {
        $err[] = 'Block 14: leave the recall date blank unless the code is Y.';
    }

    $hours = (int)($roe['insurable_hours'] ?? 0);
    if ($hours < 1 || $hours > 8904) {
        $err[] = 'Block 15A: total insurable hours must be between 1 and 8904.';
    } elseif ($fdw !== null && $ldp !== null && $ldp >= $fdw && $hours > (roe_days_between($fdw, $ldp) + 1) * 24) {
        $err[] = 'Block 15A: insurable hours are more than 24 hours a day for the period of employment.';
    }

    $periods = is_array($roe['periods'] ?? null) ? array_values($roe['periods']) : [];
    $max = isset(roe_pay_period_types()[$type]) ? roe_max_periods($type) : 53;
    if (!$periods) {
        $err[] = 'Block 15C: insurable earnings by pay period are required.';
    }
    if (count($periods) > $max) {
        $err[] = 'Block 15C: at most ' . $max . ' pay periods for this pay period type.';
    }
    $pp1 = null;
    foreach ($periods as $i => $p) {
        $n = (int)($p['nbr'] ?? 0);
        if ($n !== $i + 1) {
            $err[] = 'Block 15C: pay periods must be numbered 1, 2, 3 in order.';
            break;
        }
        $amt = (float)($p['earnings'] ?? 0);
        if ($amt < 0 || $amt > 9999999.99) {
            $err[] = 'Block 15C: pay period ' . $n . ' amount is out of range.';
        }
        if ($n === 1) {
            $pp1 = $amt;
        }
    }
    $block17 = roe_block17_insurable_total($roe);
    $pp1Export = $pp1 === null ? null : roe_pp1_export_amount($roe, $pp1);
    if ($pp1Export !== null && $pp1Export < 1.00) {
        $err[] = 'Block 15C: pay period 1 must be at least 1.00.';
    }
    if ($block17 > 0) {
        $flag = $roe['block17_in_lines'] ?? null;
        if ($flag === null || $flag === '') {
            $err[] = 'Block 17: say whether the vacation, holiday and other insurable amounts are already in the pay lines, so pay period 1 is not double counted.';
        } elseif ($pp1Export !== null && $pp1Export <= $block17) {
            $err[] = 'Block 15C: pay period 1 must be greater than the insurable amounts in block 17 (' . roe_money($block17) . ').';
        }
    }
    if ($periods && $fdw !== null) {
        $last = $periods[count($periods) - 1];
        if (!empty($last['start']) && $last['start'] <= $fdw && (float)$last['earnings'] <= 0) {
            $err[] = 'Block 15C: the pay period containing the first day worked cannot be 0.00.';
        }
    }

    $txt('Block 16 contact first name', $roe['contact_first'] ?? '', 20, true);
    $txt('Block 16 contact last name', $roe['contact_last'] ?? '', 28, true);
    if (!preg_match('/^[0-9]{3}$/', (string)($roe['contact_area'] ?? ''))) {
        $err[] = 'Block 16: contact area code must be 3 digits.';
    }
    if (!preg_match('/^[0-9]{7}$/', (string)($roe['contact_phone'] ?? ''))) {
        $err[] = 'Block 16: contact phone number must be 7 digits.';
    }
    if (!preg_match('/^[0-9]{0,8}$/', (string)($roe['contact_ext'] ?? ''))) {
        $err[] = 'Block 16: contact extension must be up to 8 digits.';
    }

    $vp = $roe['vacation'] ?? null;
    if (is_array($vp) && trim((string)($vp['code'] ?? '')) !== '') {
        $codes = roe_vacation_codes();
        $c = (string)$vp['code'];
        if (!isset($codes[$c])) {
            $err[] = 'Block 17A: vacation pay code must be 1, 2, 3 or 4.';
        } else {
            roe_check_rule($err, 'Block 17A', $codes[$c], $vp);
        }
    }
    $sh = is_array($roe['stat_holidays'] ?? null) ? array_values($roe['stat_holidays']) : [];
    if (count($sh) > 10) {
        $err[] = 'Block 17B: at most 10 statutory holidays.';
    }
    $seen = [];
    foreach ($sh as $i => $h) {
        $amt = trim((string)($h['amt'] ?? ''));
        if ($amt === '' || (float)$amt < 0.01 || (float)$amt > 9999999.99) {
            $err[] = 'Block 17B: holiday ' . ($i + 1) . ' needs an amount.';
        }
        $dt = trim((string)($h['dt'] ?? ''));
        if ($dt !== '') {
            if (roe_valid_date($dt) === null) {
                $err[] = 'Block 17B: holiday ' . ($i + 1) . ' date is not valid.';
            } elseif ($ldp !== null && $dt <= $ldp) {
                $err[] = 'Block 17B: holiday dates must be after the last day for which paid.';
            }
            if (isset($seen[$dt])) {
                $err[] = 'Block 17B: holiday dates must all be different.';
            }
            $seen[$dt] = true;
        }
    }
    $om = is_array($roe['other_monies'] ?? null) ? array_values($roe['other_monies']) : [];
    if (count($om) > 3) {
        $err[] = 'Block 17C: at most 3 other monies.';
    }
    $codes = roe_other_monies_codes();
    foreach ($om as $i => $o) {
        $c = strtoupper(trim((string)($o['code'] ?? '')));
        if (!isset($codes[$c])) {
            $err[] = 'Block 17C: other monies ' . ($i + 1) . ' code is not in table 17C.1.';
            continue;
        }
        roe_check_rule($err, 'Block 17C ' . $c, $codes[$c], $o);
    }
    $comments = trim((string)($roe['comments'] ?? ''));
    $txt('Block 18 comments', $comments, 160, false);
    if (in_array($reason, ['K00', 'K15'], true) && $comments === '') {
        $err[] = 'Block 18: a comment is required for reason ' . $reason . '.';
    }
    $lang = strtoupper(trim((string)($roe['language'] ?? 'E')));
    if (!in_array($lang, ['E', 'F'], true)) {
        $err[] = 'Language must be E or F.';
    }

    if ($fdw !== null && $ldp !== null) {
        foreach ($others as $o) {
            $oid = (int)($o['id'] ?? 0);
            if ($oid === (int)($roe['id'] ?? -1) || $oid === (int)($roe['amends_roe_id'] ?? -1)) {
                continue;
            }
            if (in_array((string)($o['status'] ?? ''), ['void', 'amended'], true)) {
                continue;
            }
            $of = roe_valid_date($o['first_day_worked'] ?? '');
            $ol = roe_valid_date($o['last_day_paid'] ?? '');
            if ($of !== null && $ol !== null && $fdw <= $ol && $ldp >= $of) {
                $err[] = 'Blocks 10/11 overlap ROE #' . $oid . ' (' . $of . ' to ' . $ol . ') for the same employee and BN.';
            }
        }
    }
    return ['errors' => array_values(array_unique($err)), 'warnings' => array_values(array_unique($warn))];
}

function roe_check_rule(array &$err, string $label, array $rule, array $row): void
{
    $sdt = trim((string)($row['sdt'] ?? ''));
    $edt = trim((string)($row['edt'] ?? ''));
    $amt = trim((string)($row['amt'] ?? ''));
    foreach (['sdt' => [$sdt, 'start date'], 'edt' => [$edt, 'end date']] as $k => [$v, $name]) {
        if ($rule[$k] === 'blank' && $v !== '') {
            $err[] = $label . ': ' . $name . ' must be blank for this code.';
        } elseif ($v !== '' && roe_valid_date($v) === null) {
            $err[] = $label . ': ' . $name . ' is not a valid date.';
        }
    }
    if ($sdt !== '' && $edt !== '' && $edt < $sdt) {
        $err[] = $label . ': end date is before the start date.';
    }
    if ($rule['amt'] === 'blank' && $amt !== '') {
        $err[] = $label . ': amount must be blank for this code.';
    }
    if ($rule['amt'] === 'req' && $amt === '') {
        $err[] = $label . ': amount is required.';
    }
    if ($amt !== '' && (!preg_match('/^[0-9]+(\.[0-9]{1,2})?$/', $amt) || (float)$amt < 0.01 || (float)$amt > 9999999.99)) {
        $err[] = $label . ': amount must be between 0.01 and 9999999.99 with up to 2 decimals.';
    }
}

/** Insurable amounts that block 17 says must also be in 15C PP1 (17A codes 2-4, all of 17B, insurable 17C codes). */
function roe_block17_insurable_total(array $roe): float
{
    $t = 0.0;
    $vp = $roe['vacation'] ?? null;
    if (is_array($vp) && in_array((string)($vp['code'] ?? ''), ['2', '3', '4'], true)) {
        $t += (float)($vp['amt'] ?? 0);
    }
    foreach ((array)($roe['stat_holidays'] ?? []) as $h) {
        $t += (float)($h['amt'] ?? 0);
    }
    $codes = roe_other_monies_codes();
    foreach ((array)($roe['other_monies'] ?? []) as $o) {
        $c = strtoupper(trim((string)($o['code'] ?? '')));
        if (isset($codes[$c]) && $codes[$c]['insurable']) {
            $t += (float)($o['amt'] ?? 0);
        }
    }
    return round($t, 2);
}

/** PP1 as exported: block 17 insurable amounts are added unless the admin said they are already in the pay lines. */
function roe_pp1_export_amount(array $roe, float $pp1): float
{
    $in = $roe['block17_in_lines'] ?? null;
    if ($in === null || $in === '' || (int)$in === 1) {
        return round($pp1, 2);
    }
    return round($pp1 + roe_block17_insurable_total($roe), 2);
}

/* ------------------------------------------------------------------ */
/* XML                                                                   */
/* ------------------------------------------------------------------ */

/**
 * Build one ROE Web payroll extract file. Every ROE must have the same BN15.
 * Each item: ['roe' => decoded roe row, 'sin' => plain SIN digits].
 */
function roe_build_xml(array $items, string $issue = 'D'): string
{
    if (!$items) {
        throw new InvalidArgumentException('No ROEs to export.');
    }
    $issue = strtoupper($issue) === 'S' ? 'S' : 'D';
    $bns = [];
    foreach ($items as $it) {
        $bns[roe_normalize_bn((string)$it['roe']['bn15'])] = true;
    }
    if (count($bns) !== 1) {
        throw new InvalidArgumentException('One file per CRA payroll account number. Split the ROEs by BN first.');
    }
    if (count($items) > ROE_MAX_PER_FILE) {
        throw new InvalidArgumentException('At most ' . ROE_MAX_PER_FILE . ' ROEs per file.');
    }
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;
    $root = $doc->createElement('ROEHEADER');
    $root->setAttribute('FileVersion', ROE_FILE_VERSION);
    $root->setAttribute('SoftwareVendor', ROE_SOFTWARE_VENDOR);
    $root->setAttribute('ProductName', ROE_PRODUCT_NAME);
    $root->setAttribute('ProductVersion', ROE_PRODUCT_VERSION);
    $doc->appendChild($root);
    $el = static function (DOMElement $parent, string $name, ?string $value = null) use ($doc): DOMElement {
        $e = $doc->createElement($name);
        if ($value !== null) {
            $e->appendChild($doc->createTextNode($value));
        }
        $parent->appendChild($e);
        return $e;
    };
    $opt = static function (DOMElement $parent, string $name, $value) use ($el): void {
        $value = trim((string)$value);
        if ($value !== '') {
            $el($parent, $name, $value);
        }
    };
    foreach ($items as $it) {
        $r = $it['roe'];
        $lang = strtoupper((string)($r['language'] ?? 'E')) === 'F' ? 'F' : 'E';
        $roe = $el($root, 'ROE');
        $roe->setAttribute('PrintingLanguage', $lang);
        $roe->setAttribute('Issue', $issue);
        $opt($roe, 'B2', $r['amended_serial'] ?? '');
        $opt($roe, 'B3', $r['payroll_ref'] ?? '');
        $el($roe, 'B5', roe_normalize_bn((string)$r['bn15']));
        $el($roe, 'B6', strtoupper((string)$r['pay_period_type']));
        $el($roe, 'B8', roe_normalize_sin((string)$it['sin']));
        $b9 = $el($roe, 'B9');
        $el($b9, 'FN', trim((string)$r['emp_first']));
        $opt($b9, 'MN', $r['emp_middle'] ?? '');
        $el($b9, 'LN', trim((string)$r['emp_last']));
        $el($b9, 'A1', trim((string)$r['emp_addr1']));
        $el($b9, 'A2', trim((string)$r['emp_addr2']));
        $opt($b9, 'A3', $r['emp_addr3'] ?? '');
        $el($b9, 'PC', roe_normalize_postal((string)$r['emp_postal']));
        $el($roe, 'B10', (string)$r['first_day_worked']);
        $el($roe, 'B11', (string)$r['last_day_paid']);
        $el($roe, 'B12', (string)$r['final_pp_end']);
        $opt($roe, 'B13', $r['occupation'] ?? '');
        $b14 = $el($roe, 'B14');
        $recall = strtoupper(trim((string)($r['recall_code'] ?? ''))) ?: 'U';
        $el($b14, 'CD', $recall);
        if ($recall === 'Y') {
            $el($b14, 'DT', (string)$r['recall_date']);
        }
        $el($roe, 'B15A', (string)(int)$r['insurable_hours']);
        $b15c = $el($roe, 'B15C');
        foreach (array_values((array)$r['periods']) as $p) {
            $pp = $el($b15c, 'PP');
            $pp->setAttribute('nbr', (string)(int)$p['nbr']);
            $amt = (int)$p['nbr'] === 1 ? roe_pp1_export_amount($r, (float)$p['earnings']) : (float)$p['earnings'];
            $el($pp, 'AMT', roe_money($amt));
        }
        $b16 = $el($roe, 'B16');
        $el($b16, 'CD', strtoupper((string)$r['reason_code']));
        $el($b16, 'FN', trim((string)$r['contact_first']));
        $el($b16, 'LN', trim((string)$r['contact_last']));
        $el($b16, 'AC', (string)$r['contact_area']);
        $el($b16, 'TEL', (string)$r['contact_phone']);
        $opt($b16, 'EXT', $r['contact_ext'] ?? '');
        $vp = $r['vacation'] ?? null;
        if (is_array($vp) && trim((string)($vp['code'] ?? '')) !== '') {
            $b17a = $el($roe, 'B17A');
            $v = $el($b17a, 'VP');
            $v->setAttribute('nbr', '1');
            $el($v, 'CD', (string)$vp['code']);
            $opt($v, 'SDT', $vp['sdt'] ?? '');
            $opt($v, 'EDT', $vp['edt'] ?? '');
            if (trim((string)($vp['amt'] ?? '')) !== '') {
                $el($v, 'AMT', roe_money((float)$vp['amt']));
            }
        }
        $sh = array_values((array)($r['stat_holidays'] ?? []));
        if ($sh) {
            $b17b = $el($roe, 'B17B');
            foreach ($sh as $i => $h) {
                $s = $el($b17b, 'SH');
                $s->setAttribute('nbr', (string)($i + 1));
                $opt($s, 'DT', $h['dt'] ?? '');
                $el($s, 'AMT', roe_money((float)$h['amt']));
            }
        }
        $om = array_values((array)($r['other_monies'] ?? []));
        if ($om) {
            $b17c = $el($roe, 'B17C');
            foreach ($om as $i => $o) {
                $m = $el($b17c, 'OM');
                $m->setAttribute('nbr', (string)($i + 1));
                $el($m, 'CD', strtoupper((string)$o['code']));
                $opt($m, 'SDT', $o['sdt'] ?? '');
                $opt($m, 'EDT', $o['edt'] ?? '');
                if (trim((string)($o['amt'] ?? '')) !== '') {
                    $el($m, 'AMT', roe_money((float)$o['amt']));
                }
            }
        }
        $opt($roe, 'B18', $r['comments'] ?? '');
        $el($roe, 'B20', $lang);
    }
    $xml = $doc->saveXML();
    if ($xml === false) {
        throw new RuntimeException('The XML could not be written.');
    }
    return $xml;
}

/** @return array<int,string> Schema errors; empty when the file passes the official XSD. */
function roe_schema_errors(string $xml, ?string $xsd = null): array
{
    $xsd = $xsd ?? roe_xsd_path();
    if (!is_file($xsd)) {
        return ['The ROE Web schema file is missing: ' . basename($xsd)];
    }
    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $doc = new DOMDocument();
    $out = [];
    if (!$doc->loadXML($xml, LIBXML_NONET)) {
        $out[] = 'The file is not well-formed XML.';
    } elseif (!$doc->schemaValidate($xsd)) {
        foreach (libxml_get_errors() as $e) {
            $out[] = 'Line ' . $e->line . ': ' . trim($e->message);
        }
        if (!$out) {
            $out[] = 'The file does not pass the ROE Web schema.';
        }
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $out;
}

/** Group export items by BN and split each group into files of at most 1200 ROEs. */
function roe_split_files(array $items): array
{
    $byBn = [];
    foreach ($items as $it) {
        $byBn[roe_normalize_bn((string)$it['roe']['bn15'])][] = $it;
    }
    ksort($byBn);
    $files = [];
    foreach ($byBn as $bn => $list) {
        foreach (array_chunk($list, ROE_MAX_PER_FILE) as $i => $chunk) {
            $files[] = ['bn' => (string)$bn, 'seq' => $i + 1, 'items' => $chunk];
        }
    }
    return $files;
}

function roe_file_name(string $bn, int $seq, string $stamp): string
{
    $bn = preg_replace('/[^A-Z0-9]/', '', strtoupper($bn)) ?: 'BN';
    return 'ROE_' . $bn . '_' . (preg_replace('/[^0-9]/', '', $stamp) ?? '') . '_' . $seq . '.BLK';
}

/** Zip several .BLK files (one per BN) for a single download. */
function roe_zip(array $files, string $path): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('The download archive could not be created.');
    }
    foreach ($files as $f) {
        $zip->addFromString($f['name'], $f['xml']);
    }
    $zip->close();
}

/* ------------------------------------------------------------------ */
/* Row <-> array                                                         */
/* ------------------------------------------------------------------ */

function roe_json_fields(): array
{
    return ['periods' => 'periods_json', 'vacation' => 'vacation_json', 'stat_holidays' => 'stat_holidays_json',
        'other_monies' => 'other_monies_json', 'gen_warnings' => 'warnings_json'];
}

function roe_decode_row(array $row): array
{
    foreach (roe_json_fields() as $k => $col) {
        $v = json_decode((string)($row[$col] ?? ''), true);
        $row[$k] = is_array($v) ? $v : ($k === 'vacation' ? null : []);
    }
    return $row;
}

/* ------------------------------------------------------------------ */
/* Database reads                                                        */
/* ------------------------------------------------------------------ */

function roe_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    $key = spl_object_id($pdo) . '|' . $table;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return [];
    }
    $cols = [];
    try {
        $st = $pdo->query('SELECT * FROM ' . $table . ' LIMIT 0');
        for ($i = 0; $i < $st->columnCount(); $i++) {
            $m = $st->getColumnMeta($i);
            if (isset($m['name'])) {
                $cols[$m['name']] = true;
            }
        }
    } catch (Throwable $e) {
        return [];
    }
    return $cache[$key] = $cols;
}

function roe_storage_ready(PDO $pdo): bool
{
    return isset(roe_table_columns($pdo, 'roe_records')['periods_json'])
        && isset(roe_table_columns($pdo, 'roe_employer_settings')['bn15'])
        && isset(roe_table_columns($pdo, 'roe_events')['roe_id']);
}

function roe_pick_col(array $cols, array $candidates): ?string
{
    foreach ($candidates as $c) {
        if (isset($cols[$c])) {
            return $c;
        }
    }
    return null;
}

/** Legal employers (BN15) for an org, from sites.payroll_account. */
function roe_employers(PDO $pdo, int $orgId): array
{
    $cols = roe_table_columns($pdo, 'sites');
    $bnCol = roe_pick_col($cols, ['payroll_account', 'cra_payroll_account', 'bn15']);
    $legal = isset($cols['corp_legal_name']) ? ', corp_legal_name' : '';
    $st = $pdo->prepare('SELECT SId, nick_name' . ($bnCol ? ', ' . $bnCol . ' AS bn' : '') . $legal . ' FROM sites WHERE org_id = ? ORDER BY nick_name');
    $st->execute([$orgId]);
    $out = ['by_bn' => [], 'missing' => [], 'bn_column' => $bnCol];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $bn = roe_normalize_bn((string)($s['bn'] ?? ''));
        $site = ['site_id' => (int)$s['SId'], 'name' => (string)$s['nick_name'], 'legal' => (string)($s['corp_legal_name'] ?? '')];
        if ($bn === '') {
            $out['missing'][] = $site;
            continue;
        }
        if (!isset($out['by_bn'][$bn])) {
            $out['by_bn'][$bn] = ['bn' => $bn, 'problem' => roe_bn_problem($bn), 'sites' => [], 'legal' => $site['legal']];
        }
        $out['by_bn'][$bn]['sites'][] = $site;
    }
    ksort($out['by_bn']);
    return $out;
}

/** Employee identity for blocks 8 and 9. The SIN is opened only when $withSin is true. */
function roe_employee(PDO $pdo, int $orgId, int $userId, bool $withSin): ?array
{
    $cols = roe_table_columns($pdo, 'users');
    $map = [
        'first' => roe_pick_col($cols, ['legal_first_name', 'first_name', 'firstname', 'fname', 'FirstName']),
        'middle' => roe_pick_col($cols, ['legal_middle_name', 'middle_name', 'middle_initial', 'mname']),
        'last' => roe_pick_col($cols, ['legal_last_name', 'last_name', 'lastname', 'lname', 'LastName']),
        'street' => roe_pick_col($cols, ['street_address', 'address', 'address1']),
        'city' => roe_pick_col($cols, ['city']),
        'province' => roe_pick_col($cols, ['province', 'state']),
        'postal' => roe_pick_col($cols, ['postal_code', 'zipcode', 'zip']),
        'occupation' => roe_pick_col($cols, ['job_title', 'position', 'designation', 'occupation']),
        'hire' => roe_pick_col($cols, ['hire_date', 'start_date', 'date_of_joining', 'joining_date']),
        'sin' => roe_pick_col($cols, ['sin', 'SIN', 'social_insurance_number', 'social_insurance', 'sin_number']),
    ];
    $sel = ['id', 'Name'];
    foreach ($map as $k => $c) {
        if ($c !== null && ($k !== 'sin' || $withSin)) {
            $sel[] = $c . ' AS f_' . $k;
        }
    }
    $st = $pdo->prepare('SELECT ' . implode(', ', $sel) . ' FROM users WHERE id = ? AND org_id = ?');
    $st->execute([$userId, $orgId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return null;
    }
    $warn = [];
    $first = trim((string)($u['f_first'] ?? ''));
    $last = trim((string)($u['f_last'] ?? ''));
    if ($first === '' || $last === '') {
        $parts = preg_split('/\s+/', trim((string)$u['Name'])) ?: [];
        if (count($parts) >= 2) {
            $last = (string)array_pop($parts);
            $first = implode(' ', $parts);
        } else {
            $first = $first !== '' ? $first : (string)($parts[0] ?? '');
        }
        $warn[] = 'Legal first and last name are not stored separately. They were split from the display name. Confirm the legal name.';
    }
    $prov = strtoupper(trim((string)($u['f_province'] ?? '')));
    $emp = [
        'user_id' => (int)$u['id'],
        'display_name' => (string)$u['Name'],
        'first' => $first,
        'middle' => trim((string)($u['f_middle'] ?? '')),
        'last' => $last,
        'addr1' => trim((string)($u['f_street'] ?? '')),
        'addr2' => trim((string)($u['f_city'] ?? '')),
        'addr3' => $prov !== '' ? $prov . ' CANADA' : '',
        'postal' => roe_normalize_postal((string)($u['f_postal'] ?? '')),
        'occupation' => trim((string)($u['f_occupation'] ?? '')),
        'hire_date' => roe_valid_date(substr((string)($u['f_hire'] ?? ''), 0, 10)),
        'sin' => '',
        'has_sin_column' => $map['sin'] !== null,
        'warnings' => $warn,
    ];
    if ($emp['addr1'] === '' || $emp['addr2'] === '' || $emp['postal'] === '') {
        $emp['warnings'][] = 'The employee address on file is incomplete (street, city or postal code).';
    }
    if ($map['sin'] === null) {
        $emp['warnings'][] = 'The users table has no SIN column.';
    } elseif ($withSin) {
        $stored = (string)($u['f_sin'] ?? '');
        if ($stored !== '' && function_exists('privacy_open_sensitive')) {
            $stored = privacy_open_sensitive($stored);
        }
        $emp['sin'] = roe_normalize_sin($stored);
        if ($emp['sin'] === '') {
            $emp['warnings'][] = 'The employee has no SIN on file.';
        }
    }
    return $emp;
}

/** Finalized pay lines for one employee at the stores of one BN. */
function roe_fetch_lines(PDO $pdo, int $orgId, int $userId, string $bn): array
{
    $emps = roe_employers($pdo, $orgId);
    $bn = roe_normalize_bn($bn);
    $siteIds = array_map(static fn($s) => (int)$s['site_id'], $emps['by_bn'][$bn]['sites'] ?? []);
    if (!$siteIds) {
        return [];
    }
    $f = roe_table_columns($pdo, 'payroll_finalized');
    $hours = [];
    foreach (['reg_hours', 'overtime_hours', 'travel_hours'] as $c) {
        if (isset($f[$c])) {
            $hours[] = 'COALESCE(f.' . $c . ', 0)';
        }
    }
    $hoursSql = $hours ? implode(' + ', $hours) : '0';
    $cat = isset($f['category']) ? 'f.category' : "''";
    $sql = 'SELECT f.id, f.work_date, f.site_id, (' . $hoursSql . ') AS hours, COALESCE(f.total_gross, 0) AS gross, ' . $cat . ' AS category
            FROM payroll_finalized f
            INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
            INNER JOIN sites s ON s.SId = f.site_id AND s.org_id = u.org_id
            WHERE f.user_id = ? AND f.site_id IN (' . implode(',', array_fill(0, count($siteIds), '?')) . ')';
    if (function_exists('att_finalized_live_predicate')) {
        $sql .= ' AND ' . att_finalized_live_predicate($pdo, 'f');
    }
    $sql .= ' ORDER BY f.work_date ASC, f.id ASC';
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$orgId, $userId], $siteIds));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['work_date'] = substr((string)$r['work_date'], 0, 10);
    }
    unset($r);
    return $rows;
}

function roe_employer_setting(PDO $pdo, int $orgId, string $bn): array
{
    $st = $pdo->prepare('SELECT * FROM roe_employer_settings WHERE org_id = ? AND bn15 = ?');
    $st->execute([$orgId, roe_normalize_bn($bn)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

function roe_get(PDO $pdo, int $orgId, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM roe_records WHERE id = ? AND org_id = ?');
    $st->execute([$id, $orgId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? roe_decode_row($r) : null;
}

function roe_list(PDO $pdo, int $orgId, string $status = ''): array
{
    $sql = 'SELECT r.*, u.Name AS employee_name FROM roe_records r LEFT JOIN users u ON u.id = r.user_id AND u.org_id = r.org_id WHERE r.org_id = ?';
    $p = [$orgId];
    if ($status !== '') {
        $sql .= ' AND r.status = ?';
        $p[] = $status;
    }
    $sql .= ' ORDER BY r.id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return array_map('roe_decode_row', $st->fetchAll(PDO::FETCH_ASSOC));
}

function roe_others(PDO $pdo, int $orgId, int $userId, string $bn): array
{
    $st = $pdo->prepare('SELECT id, first_day_worked, last_day_paid, status, serial_number FROM roe_records WHERE org_id = ? AND user_id = ? AND bn15 = ?');
    $st->execute([$orgId, $userId, roe_normalize_bn($bn)]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Last day for which paid on the latest issued ROE for this employee and BN, or null. */
function roe_previous_last_day(PDO $pdo, int $orgId, int $userId, string $bn, int $exceptId = 0): ?string
{
    $best = null;
    foreach (roe_others($pdo, $orgId, $userId, $bn) as $o) {
        if ((int)$o['id'] === $exceptId || (string)$o['status'] !== 'issued') {
            continue;
        }
        $d = roe_valid_date($o['last_day_paid'] ?? '');
        if ($d !== null && ($best === null || $d > $best)) {
            $best = $d;
        }
    }
    return $best;
}

/* ------------------------------------------------------------------ */
/* Database writes (roe_* tables only)                                   */
/* ------------------------------------------------------------------ */

function roe_save_employer_setting(PDO $pdo, int $orgId, string $bn, array $in, int $actorId, string $now): void
{
    $bn = roe_normalize_bn($bn);
    $type = strtoupper(trim((string)($in['pay_period_type'] ?? '')));
    if ($type !== '' && !isset(roe_pay_period_types()[$type])) {
        throw new InvalidArgumentException('Unknown pay period type.');
    }
    $anchor = trim((string)($in['anchor_end'] ?? ''));
    if ($anchor !== '' && roe_valid_date($anchor) === null) {
        throw new InvalidArgumentException('The known pay period end date is not a valid date.');
    }
    $vals = [
        'pay_period_type' => $type,
        'anchor_end' => $anchor !== '' ? $anchor : null,
        'contact_first' => roe_substr(trim((string)($in['contact_first'] ?? '')), 20),
        'contact_last' => roe_substr(trim((string)($in['contact_last'] ?? '')), 28),
        'contact_area' => substr(preg_replace('/\D+/', '', (string)($in['contact_area'] ?? '')) ?? '', 0, 3),
        'contact_phone' => substr(preg_replace('/\D+/', '', (string)($in['contact_phone'] ?? '')) ?? '', 0, 7),
        'contact_ext' => substr(preg_replace('/\D+/', '', (string)($in['contact_ext'] ?? '')) ?? '', 0, 8),
    ];
    $have = roe_employer_setting($pdo, $orgId, $bn);
    if ($have) {
        $set = [];
        foreach (array_keys($vals) as $k) {
            $set[] = $k . ' = ?';
        }
        $pdo->prepare('UPDATE roe_employer_settings SET ' . implode(', ', $set) . ', updated_by = ?, updated_at = ? WHERE id = ?')
            ->execute(array_merge(array_values($vals), [$actorId, $now, (int)$have['id']]));
    } else {
        $pdo->prepare('INSERT INTO roe_employer_settings (org_id, bn15, ' . implode(', ', array_keys($vals)) . ', updated_by, updated_at) VALUES (?, ?' . str_repeat(', ?', count($vals)) . ', ?, ?)')
            ->execute(array_merge([$orgId, $bn], array_values($vals), [$actorId, $now]));
    }
}

function roe_log(PDO $pdo, int $roeId, int $orgId, int $actorId, string $action, string $detail, string $now): void
{
    $pdo->prepare('INSERT INTO roe_events (roe_id, org_id, actor_id, action, detail, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$roeId, $orgId, $actorId, $action, roe_substr($detail, 1000), $now]);
}

function roe_periods_for_storage(array $periods): string
{
    return (string)json_encode(array_map(static fn($p) => [
        'nbr' => (int)$p['nbr'], 'start' => (string)($p['start'] ?? ''), 'end' => (string)($p['end'] ?? ''),
        'earnings' => round((float)$p['earnings'], 2), 'hours' => round((float)($p['hours'] ?? 0), 4),
    ], array_values($periods)));
}

/**
 * Create a draft ROE from payroll_finalized. Returns ['id' => int, 'compute' => array].
 * Throws when the employee or BN is outside this organization.
 */
function roe_create_draft(PDO $pdo, int $orgId, int $userId, string $bn, string $reason, int $actorId, string $now, ?string $lastDay = null): array
{
    $bn = roe_normalize_bn($bn);
    $emps = roe_employers($pdo, $orgId);
    if (!isset($emps['by_bn'][$bn])) {
        throw new InvalidArgumentException('That CRA payroll account is not on any store in this organization.');
    }
    $emp = roe_employee($pdo, $orgId, $userId, false);
    if ($emp === null) {
        throw new InvalidArgumentException('That employee is outside this organization.');
    }
    $set = roe_employer_setting($pdo, $orgId, $bn);
    $type = strtoupper((string)($set['pay_period_type'] ?? ''));
    $prev = roe_previous_last_day($pdo, $orgId, $userId, $bn);
    $calc = roe_compute(roe_fetch_lines($pdo, $orgId, $userId, $bn), $type, $set['anchor_end'] ?? null, $prev, $lastDay);
    $warnings = array_merge($emp['warnings'], $calc['warnings'], $calc['errors']);
    if ($emp['hire_date'] && $calc['first_day_worked'] && $emp['hire_date'] !== $calc['first_day_worked'] && $prev === null) {
        $warnings[] = 'Hire date on file is ' . $emp['hire_date'] . ' but the first pay line is ' . $calc['first_day_worked'] . '. Block 10 uses the first pay line; check it.';
    }
    if ($emps['by_bn'][$bn]['problem'] !== '') {
        $warnings[] = 'Block 5: ' . $emps['by_bn'][$bn]['problem'];
    }
    $vals = [
        'org_id' => $orgId, 'user_id' => $userId, 'bn15' => $bn, 'payroll_ref' => (string)$userId,
        'pay_period_type' => $type, 'anchor_end' => $set['anchor_end'] ?? null,
        'first_day_worked' => $calc['first_day_worked'], 'last_day_paid' => $calc['last_day_paid'], 'final_pp_end' => $calc['final_pp_end'],
        'occupation' => roe_substr($emp['occupation'], 40), 'reason_code' => strtoupper($reason), 'recall_code' => 'U', 'recall_date' => null,
        'insurable_hours' => $calc['insurable_hours'], 'hours_exact' => $calc['hours_exact'],
        'periods_json' => roe_periods_for_storage($calc['periods']), 'vacation_json' => null, 'stat_holidays_json' => '[]', 'other_monies_json' => '[]',
        'block17_in_lines' => null, 'comments' => '',
        'contact_first' => (string)($set['contact_first'] ?? ''), 'contact_last' => (string)($set['contact_last'] ?? ''),
        'contact_area' => (string)($set['contact_area'] ?? ''), 'contact_phone' => (string)($set['contact_phone'] ?? ''), 'contact_ext' => (string)($set['contact_ext'] ?? ''),
        'language' => 'E',
        'emp_first' => $emp['first'], 'emp_middle' => roe_substr($emp['middle'], 4), 'emp_last' => $emp['last'],
        'emp_addr1' => $emp['addr1'], 'emp_addr2' => $emp['addr2'], 'emp_addr3' => $emp['addr3'], 'emp_postal' => $emp['postal'],
        'status' => 'draft', 'serial_number' => '', 'amended_serial' => '', 'amends_roe_id' => null,
        'warnings_json' => json_encode(array_values(array_unique($warnings))), 'generated_at' => $now,
        'created_by' => $actorId, 'created_at' => $now, 'updated_by' => $actorId, 'updated_at' => $now,
    ];
    $pdo->prepare('INSERT INTO roe_records (' . implode(', ', array_keys($vals)) . ') VALUES (' . implode(', ', array_fill(0, count($vals), '?')) . ')')
        ->execute(array_values($vals));
    $id = (int)$pdo->lastInsertId();
    roe_log($pdo, $id, $orgId, $actorId, 'create', 'Draft from payroll_finalized; ' . count($calc['periods']) . ' pay periods', $now);
    return ['id' => $id, 'compute' => $calc];
}

/** Recompute blocks 10-12, 15A and 15C on a draft from the current pay lines. */
function roe_regenerate(PDO $pdo, array $roe, int $actorId, string $now, ?string $lastDay = null): array
{
    if (!in_array((string)$roe['status'], ['draft', 'ready'], true)) {
        throw new InvalidArgumentException('Only a draft or ready ROE can be regenerated.');
    }
    $orgId = (int)$roe['org_id'];
    $set = roe_employer_setting($pdo, $orgId, (string)$roe['bn15']);
    $type = strtoupper((string)(($roe['pay_period_type'] ?? '') !== '' ? $roe['pay_period_type'] : ($set['pay_period_type'] ?? '')));
    $anchor = ($roe['anchor_end'] ?? null) ?: ($set['anchor_end'] ?? null);
    $amends = (int)($roe['amends_roe_id'] ?? 0);
    $prev = roe_previous_last_day($pdo, $orgId, (int)$roe['user_id'], (string)$roe['bn15'], $amends);
    if ($amends > 0) {
        $orig = roe_get($pdo, $orgId, $amends);
        if ($orig && roe_valid_date($orig['first_day_worked'] ?? '') !== null) {
            $prev = roe_add_days((string)$orig['first_day_worked'], -1);
        }
    }
    $calc = roe_compute(roe_fetch_lines($pdo, $orgId, (int)$roe['user_id'], (string)$roe['bn15']), $type, $anchor, $prev, $lastDay);
    $pdo->prepare('UPDATE roe_records SET pay_period_type = ?, anchor_end = ?, first_day_worked = ?, last_day_paid = ?, final_pp_end = ?,
                   insurable_hours = ?, hours_exact = ?, periods_json = ?, warnings_json = ?, generated_at = ?, status = ?, updated_by = ?, updated_at = ?
                   WHERE id = ? AND org_id = ?')
        ->execute([$type, $anchor, $calc['first_day_worked'], $calc['last_day_paid'], $calc['final_pp_end'], $calc['insurable_hours'], $calc['hours_exact'],
            roe_periods_for_storage($calc['periods']), json_encode(array_values(array_unique(array_merge($calc['warnings'], $calc['errors'])))),
            $now, 'draft', $actorId, $now, (int)$roe['id'], $orgId]);
    roe_log($pdo, (int)$roe['id'], $orgId, $actorId, 'regenerate', count($calc['periods']) . ' pay periods', $now);
    return $calc;
}

/** Fields an admin may edit on a draft. */
function roe_editable_fields(): array
{
    return ['payroll_ref', 'pay_period_type', 'first_day_worked', 'last_day_paid', 'final_pp_end', 'occupation', 'reason_code',
        'recall_code', 'recall_date', 'insurable_hours', 'comments', 'contact_first', 'contact_last', 'contact_area', 'contact_phone',
        'contact_ext', 'language', 'emp_first', 'emp_middle', 'emp_last', 'emp_addr1', 'emp_addr2', 'emp_addr3', 'emp_postal', 'block17_in_lines'];
}

/** Turn the edit form POST into column values. Pure; no DB. */
function roe_values_from_post(array $post): array
{
    $v = [];
    foreach (roe_editable_fields() as $f) {
        $v[$f] = trim((string)($post[$f] ?? ''));
    }
    $v['pay_period_type'] = strtoupper($v['pay_period_type']);
    $v['reason_code'] = strtoupper($v['reason_code']);
    $v['recall_code'] = strtoupper($v['recall_code']) ?: 'U';
    $v['language'] = strtoupper($v['language']) === 'F' ? 'F' : 'E';
    $v['emp_postal'] = roe_normalize_postal($v['emp_postal']);
    foreach (['contact_area', 'contact_phone', 'contact_ext'] as $f) {
        $v[$f] = preg_replace('/\D+/', '', $v[$f]) ?? '';
    }
    foreach (['first_day_worked', 'last_day_paid', 'final_pp_end', 'recall_date'] as $f) {
        $v[$f] = $v[$f] === '' ? null : $v[$f];
    }
    $v['insurable_hours'] = (int)$v['insurable_hours'];
    $v['block17_in_lines'] = $v['block17_in_lines'] === '' ? null : ((int)$v['block17_in_lines'] === 1 ? 1 : 0);

    $periods = [];
    $starts = (array)($post['pp_start'] ?? []);
    $ends = (array)($post['pp_end'] ?? []);
    $hrs = (array)($post['pp_hours'] ?? []);
    $n = 0;
    foreach ((array)($post['pp_amt'] ?? []) as $i => $a) {
        $a = trim((string)$a);
        if ($a === '') {
            continue;
        }
        $n++;
        $periods[] = ['nbr' => $n, 'start' => (string)($starts[$i] ?? ''), 'end' => (string)($ends[$i] ?? ''),
            'earnings' => round((float)$a, 2), 'hours' => round((float)($hrs[$i] ?? 0), 4)];
    }
    $v['periods'] = $periods;
    $vp = ['code' => trim((string)($post['vp_code'] ?? '')), 'sdt' => trim((string)($post['vp_sdt'] ?? '')),
        'edt' => trim((string)($post['vp_edt'] ?? '')), 'amt' => trim((string)($post['vp_amt'] ?? ''))];
    $v['vacation'] = $vp['code'] === '' ? null : $vp;
    $sh = [];
    foreach ((array)($post['sh_amt'] ?? []) as $i => $a) {
        $a = trim((string)$a);
        $d = trim((string)(((array)($post['sh_dt'] ?? []))[$i] ?? ''));
        if ($a === '' && $d === '') {
            continue;
        }
        $sh[] = ['dt' => $d, 'amt' => $a];
    }
    $v['stat_holidays'] = $sh;
    $om = [];
    foreach ((array)($post['om_code'] ?? []) as $i => $c) {
        $c = strtoupper(trim((string)$c));
        if ($c === '') {
            continue;
        }
        $om[] = ['code' => $c, 'sdt' => trim((string)(((array)($post['om_sdt'] ?? []))[$i] ?? '')),
            'edt' => trim((string)(((array)($post['om_edt'] ?? []))[$i] ?? '')), 'amt' => trim((string)(((array)($post['om_amt'] ?? []))[$i] ?? ''))];
    }
    $v['other_monies'] = $om;
    return $v;
}

function roe_save_edit(PDO $pdo, array $roe, array $vals, int $actorId, string $now): void
{
    if (!in_array((string)$roe['status'], ['draft', 'ready'], true)) {
        throw new InvalidArgumentException('This ROE is ' . $roe['status'] . ' and cannot be edited. Create an amendment instead.');
    }
    $cols = [];
    $params = [];
    foreach (roe_editable_fields() as $f) {
        $cols[] = $f . ' = ?';
        $params[] = $vals[$f];
    }
    $cols[] = 'periods_json = ?';
    $params[] = roe_periods_for_storage($vals['periods']);
    $cols[] = 'vacation_json = ?';
    $params[] = $vals['vacation'] === null ? null : json_encode($vals['vacation']);
    $cols[] = 'stat_holidays_json = ?';
    $params[] = json_encode(array_values($vals['stat_holidays']));
    $cols[] = 'other_monies_json = ?';
    $params[] = json_encode(array_values($vals['other_monies']));
    $cols[] = "status = 'draft'";
    $cols[] = 'updated_by = ?';
    $params[] = $actorId;
    $cols[] = 'updated_at = ?';
    $params[] = $now;
    $params[] = (int)$roe['id'];
    $params[] = (int)$roe['org_id'];
    $pdo->prepare('UPDATE roe_records SET ' . implode(', ', $cols) . ' WHERE id = ? AND org_id = ?')->execute($params);
    roe_log($pdo, (int)$roe['id'], (int)$roe['org_id'], $actorId, 'edit', 'Saved edits', $now);
}

/** Validate a stored ROE with the employee's current SIN. */
function roe_check(PDO $pdo, array $roe, string $today): array
{
    $emp = roe_employee($pdo, (int)$roe['org_id'], (int)$roe['user_id'], true);
    $sin = (string)($emp['sin'] ?? '');
    $res = roe_validate($roe, $sin, $today, roe_others($pdo, (int)$roe['org_id'], (int)$roe['user_id'], (string)$roe['bn15']));
    if ($emp === null) {
        $res['errors'][] = 'The employee is no longer in this organization.';
    }
    $res['sin'] = $sin;
    return $res;
}

function roe_set_status(PDO $pdo, array $roe, string $to, int $actorId, string $now, string $serial = ''): void
{
    $from = (string)$roe['status'];
    $allowed = [
        'draft' => ['ready', 'void'],
        'ready' => ['draft', 'issued', 'void'],
        'issued' => [],
    ];
    if (!in_array($to, $allowed[$from] ?? [], true)) {
        throw new InvalidArgumentException('A ' . $from . ' ROE cannot move to ' . $to . '.');
    }
    if ($to === 'ready' || $to === 'issued') {
        $chk = roe_check($pdo, $roe, substr($now, 0, 10));
        if ($chk['errors']) {
            throw new InvalidArgumentException('Fix the errors first: ' . implode(' ', $chk['errors']));
        }
    }
    $serial = strtoupper(trim($serial));
    if ($to === 'issued') {
        if (!preg_match('/^[A-Z][0-9]{8}$/', $serial)) {
            throw new InvalidArgumentException('Enter the serial number ROE Web gave this ROE (a letter then 8 digits).');
        }
        $pdo->prepare('UPDATE roe_records SET status = ?, serial_number = ?, issued_by = ?, issued_at = ?, updated_by = ?, updated_at = ? WHERE id = ? AND org_id = ?')
            ->execute([$to, $serial, $actorId, $now, $actorId, $now, (int)$roe['id'], (int)$roe['org_id']]);
        if ((int)($roe['amends_roe_id'] ?? 0) > 0) {
            $pdo->prepare("UPDATE roe_records SET status = 'amended', updated_by = ?, updated_at = ? WHERE id = ? AND org_id = ? AND status = 'issued'")
                ->execute([$actorId, $now, (int)$roe['amends_roe_id'], (int)$roe['org_id']]);
            roe_log($pdo, (int)$roe['amends_roe_id'], (int)$roe['org_id'], $actorId, 'status', 'issued -> amended by ROE #' . (int)$roe['id'], $now);
        }
    } else {
        $pdo->prepare('UPDATE roe_records SET status = ?, updated_by = ?, updated_at = ? WHERE id = ? AND org_id = ?')
            ->execute([$to, $actorId, $now, (int)$roe['id'], (int)$roe['org_id']]);
    }
    roe_log($pdo, (int)$roe['id'], (int)$roe['org_id'], $actorId, 'status', $from . ' -> ' . $to . ($serial !== '' ? ' ' . $serial : ''), $now);
}

/** New draft that amends an issued ROE (block 2 = the original serial number). */
function roe_amend(PDO $pdo, array $roe, int $actorId, string $now): int
{
    if ((string)$roe['status'] !== 'issued' || trim((string)$roe['serial_number']) === '') {
        throw new InvalidArgumentException('Only an issued ROE with a serial number can be amended.');
    }
    $tableCols = roe_table_columns($pdo, 'roe_records');
    $copy = [];
    foreach ($roe as $k => $v) {
        if ($k !== 'id' && isset($tableCols[$k]) && !in_array($k, ['issued_at', 'issued_by'], true)) {
            $copy[$k] = $v;
        }
    }
    $copy['status'] = 'draft';
    $copy['amended_serial'] = (string)$roe['serial_number'];
    $copy['serial_number'] = '';
    $copy['amends_roe_id'] = (int)$roe['id'];
    $copy['created_by'] = $actorId;
    $copy['created_at'] = $now;
    $copy['updated_by'] = $actorId;
    $copy['updated_at'] = $now;
    $pdo->prepare('INSERT INTO roe_records (' . implode(', ', array_keys($copy)) . ') VALUES (' . implode(', ', array_fill(0, count($copy), '?')) . ')')
        ->execute(array_values($copy));
    $id = (int)$pdo->lastInsertId();
    roe_log($pdo, $id, (int)$roe['org_id'], $actorId, 'amend', 'Amends ROE #' . (int)$roe['id'] . ' ' . $roe['serial_number'], $now);
    return $id;
}

/**
 * Build export files for the chosen ROEs. Each ROE must pass roe_validate and each file the official XSD.
 * @return array{files:array,errors:array} files: list of ['name','xml','count','bn']
 */
function roe_export(PDO $pdo, int $orgId, array $ids, string $issue, string $now): array
{
    $items = [];
    $errors = [];
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        $roe = roe_get($pdo, $orgId, $id);
        if (!$roe) {
            $errors[] = 'ROE #' . $id . ' was not found in this organization.';
            continue;
        }
        if ((string)$roe['status'] !== 'ready') {
            $errors[] = 'ROE #' . $id . ' is ' . $roe['status'] . '. Only ready ROEs are exported.';
            continue;
        }
        $chk = roe_check($pdo, $roe, substr($now, 0, 10));
        if ($chk['errors']) {
            $errors[] = 'ROE #' . $id . ': ' . implode(' ', $chk['errors']);
            continue;
        }
        $items[] = ['roe' => $roe, 'sin' => $chk['sin']];
    }
    if ($errors || !$items) {
        return ['files' => [], 'errors' => $errors ?: ['Choose at least one ROE.']];
    }
    $files = [];
    $stamp = substr(preg_replace('/[^0-9]/', '', $now) ?? '', 0, 12);
    foreach (roe_split_files($items) as $f) {
        $xml = roe_build_xml($f['items'], $issue);
        $schema = roe_schema_errors($xml);
        if ($schema) {
            return ['files' => [], 'errors' => array_merge(['The file for BN ' . $f['bn'] . ' failed the ROE Web schema.'], $schema)];
        }
        if (strlen($xml) > ROE_MAX_FILE_BYTES) {
            return ['files' => [], 'errors' => ['The file for BN ' . $f['bn'] . ' is over 1 MB. Export fewer ROEs at a time.']];
        }
        $files[] = ['name' => roe_file_name($f['bn'], $f['seq'], $stamp), 'xml' => $xml, 'count' => count($f['items']), 'bn' => $f['bn']];
    }
    foreach ($items as $it) {
        roe_log($pdo, (int)$it['roe']['id'], $orgId, (int)($_SESSION['user_id'] ?? 0), 'export', 'Issue=' . (strtoupper($issue) === 'S' ? 'S' : 'D'), $now);
    }
    return ['files' => $files, 'errors' => []];
}
