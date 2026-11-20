<?php
/**
 * CRA source deductions, Option 1 of T4127.
 *
 * The arithmetic lives here. Every dollar amount and rate comes from a dated
 * pack in payroll_engine/rates/ (or from payroll_rate_packs when the database
 * copy is loaded). Pay date picks the pack: January 1, 2026 through June 30, 2026
 * uses the 122nd edition; July 1, 2026 onward uses the 123rd edition merged on
 * top of it.
 *
 * This file does not read employee records and does not write payroll.
 * Quebec provincial tax and QPP are not calculated. A province with no pack
 * gets federal tax, CPP, and EI, and a flag that provincial tax was not calculated.
 */

function sd_money(float $n): float
{
    if (!is_finite($n)) {
        return 0.0;
    }
    $sign = $n < 0 ? -1.0 : 1.0;
    $cents = (int)floor(abs($n) * 100 + 0.5000001);
    return $sign * $cents / 100.0;
}

function sd_rate_files(): array
{
    $dir = __DIR__ . '/rates';
    $files = glob($dir . '/*.php') ?: [];
    sort($files);
    return $files;
}

function sd_builtin_packs(): array
{
    static $packs = null;
    if ($packs !== null) {
        return $packs;
    }
    $packs = [];
    foreach (sd_rate_files() as $file) {
        $pack = require $file;
        if (is_array($pack) && !empty($pack['effective_from'])) {
            $packs[] = $pack;
        }
    }
    usort($packs, static function ($a, $b) {
        return strcmp((string)$a['effective_from'], (string)$b['effective_from']);
    });
    return $packs;
}

/** Merge a later edition onto an earlier one. A later province replaces that province. */
function sd_merge_pack(?array $base, array $over): array
{
    if ($base === null) {
        return $over;
    }
    $out = $base;
    foreach ($over as $key => $value) {
        if ($key === 'provinces' && isset($out['provinces']) && is_array($value)) {
            foreach ($value as $code => $prov) {
                $out['provinces'][$code] = $prov;
            }
            continue;
        }
        if (is_array($value) && isset($out[$key]) && is_array($out[$key]) && sd_is_assoc($value) && sd_is_assoc($out[$key])) {
            $out[$key] = array_replace($out[$key], $value);
            continue;
        }
        $out[$key] = $value;
    }
    return $out;
}

function sd_is_assoc(array $a): bool
{
    if ($a === []) {
        return false;
    }
    return array_keys($a) !== range(0, count($a) - 1);
}

/**
 * Pack in force on a pay date. $extraPacks are rows already loaded from the
 * database (same shape as the PHP files) and win over a built-in pack with
 * the same effective_from.
 */
function sd_pack_for(string $payDate, array $extraPacks = []): ?array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) {
        return null;
    }
    $byDate = [];
    foreach (array_merge(sd_builtin_packs(), $extraPacks) as $pack) {
        $from = (string)($pack['effective_from'] ?? '');
        if ($from === '' || $from > $payDate) {
            continue;
        }
        $byDate[$from] = $pack;
    }
    if (!$byDate) {
        return null;
    }
    ksort($byDate);
    $merged = null;
    foreach ($byDate as $pack) {
        $merged = sd_merge_pack($merged, $pack);
    }
    return $merged;
}

function sd_province_code(string $raw): string
{
    $t = strtoupper(trim($raw));
    $t = str_replace(['.', ','], '', $t);
    $map = [
        'BC' => 'BC', 'B C' => 'BC', 'BRITISH COLUMBIA' => 'BC',
        'ON' => 'ON', 'ONT' => 'ON', 'ONTARIO' => 'ON',
        'SK' => 'SK', 'SASK' => 'SK', 'SASKATCHEWAN' => 'SK',
        'QC' => 'QC', 'QUE' => 'QC', 'QUEBEC' => 'QC', 'QUÉBEC' => 'QC',
    ];
    return $map[$t] ?? $t;
}

function sd_bracket(array $brackets, float $income): array
{
    $pick = $brackets[0];
    foreach ($brackets as $row) {
        if ($income + 0.0000001 >= (float)$row['over']) {
            $pick = $row;
        }
    }
    return $pick;
}

function sd_cpp_exemption(array $pack, int $periods): float
{
    $table = $pack['cpp']['exemption_by_periods'] ?? [];
    if (isset($table[$periods])) {
        return (float)$table[$periods];
    }
    $annual = (float)$pack['cpp']['basic_exemption'];
    if ($periods <= 0) {
        return 0.0;
    }
    // The published table truncates; do the same when P is not listed.
    return floor(($annual / $periods) * 100) / 100;
}

function sd_bpaf(array $pack, float $netIncome): float
{
    $fed = $pack['federal'];
    $max = (float)$fed['bpa_max'];
    $min = (float)$fed['bpa_min'];
    $start = (float)$fed['bpa_phase_start'];
    $end = (float)$fed['bpa_phase_end'];
    if ($netIncome <= $start) {
        return $max;
    }
    if ($netIncome >= $end) {
        return $min;
    }
    $width = $end - $start;
    $span = $max - $min;
    if ($width <= 0) {
        return $max;
    }
    return sd_money($max - ($netIncome - $start) * ($span / $width));
}

function sd_health_premium(array $bands, float $income): float
{
    if ($income <= 0 || !$bands) {
        return 0.0;
    }
    $chosen = null;
    foreach ($bands as $band) {
        $up = $band['up_to'];
        if ($up === null || $income <= (float)$up + 0.0000001) {
            $chosen = $band;
            break;
        }
    }
    if ($chosen === null) {
        $chosen = $bands[count($bands) - 1];
    }
    $cap = (float)($chosen['cap'] ?? 0);
    if ($cap <= 0) {
        return 0.0;
    }
    $raw = (float)$chosen['base'] + (float)$chosen['rate'] * ($income - (float)$chosen['over']);
    return sd_money(min($cap, max(0.0, $raw)));
}

function sd_bc_reduction(array $rule, float $income, float $basicTax): float
{
    $ceiling = (float)$rule['ceiling'];
    if ($income > $ceiling) {
        return 0.0;
    }
    $basic = (float)$rule['basic'];
    if ($income > (float)$rule['floor']) {
        $basic = $basic - ($income - (float)$rule['floor']) * (float)$rule['rate'];
    }
    if ($basic < 0) {
        $basic = 0.0;
    }
    return sd_money(min($basicTax, $basic));
}

function sd_on_reduction(array $rule, float $basicPlusSurtax, int $dependants): float
{
    $personal = (float)$rule['basic'] + $dependants * (float)$rule['per_dependant'];
    $candidate = 2 * $personal - $basicPlusSurtax;
    if ($candidate < 0) {
        return 0.0;
    }
    return sd_money(min($basicPlusSurtax, $candidate));
}

function sd_on_surtax(array $steps, float $basicTax): float
{
    $v1 = 0.0;
    foreach ($steps as $step) {
        $over = (float)$step['over'];
        if ($basicTax > $over) {
            $v1 += (float)$step['rate'] * ($basicTax - $over);
        }
    }
    return sd_money($v1);
}

/**
 * Annual federal and provincial tax for one value of A.
 * $annualBaseCpp is the base-CPP amount used in K2 (already capped).
 * $annualEi is the EI amount used in K2 (already capped).
 */
function sd_annual_tax(
    array $pack,
    float $A,
    float $employment,
    float $annualBaseCpp,
    float $annualEi,
    ?float $tc,
    ?float $tcp,
    string $province,
    int $dependants,
    bool $taxExempt,
    float $hd,
    float $k3,
    float $k3p
): array {
    $fed = $pack['federal'];
    $lowest = (float)$fed['lowest_rate'];
    $claim = $tc === null ? sd_bpaf($pack, $A + $hd) : (float)$tc;
    $k1 = sd_money($lowest * $claim);
    $k2 = sd_money($lowest * $annualBaseCpp) + sd_money($lowest * $annualEi);
    $k4base = min(max(0.0, $employment), (float)$fed['cea']);
    $k4 = sd_money($lowest * $k4base);
    $bracket = sd_bracket($fed['brackets'], max(0.0, $A));
    $t3 = 0.0;
    if (!$taxExempt && $A > 0) {
        $t3 = sd_money((float)$bracket['rate'] * $A - (float)$bracket['constant'] - $k1 - $k2 - $k3 - $k4);
        if ($t3 < 0) {
            $t3 = 0.0;
        }
    }
    $t1 = $t3;

    $prov = $pack['provinces'][$province] ?? null;
    $supported = $prov !== null;
    $t4 = 0.0;
    $v1 = 0.0;
    $v2 = 0.0;
    $s = 0.0;
    $k1p = 0.0;
    $k2p = 0.0;
    $tcpUsed = 0.0;
    if ($supported) {
        $tcpUsed = $tcp === null ? (float)$prov['bpa'] : (float)$tcp;
        $pLowest = (float)$prov['lowest_rate'];
        $k1p = sd_money($pLowest * $tcpUsed);
        $k2p = sd_money($pLowest * $annualBaseCpp) + sd_money($pLowest * $annualEi);
        if (!$taxExempt && $A > 0) {
            $pb = sd_bracket($prov['brackets'], $A);
            $t4 = sd_money((float)$pb['rate'] * $A - (float)$pb['constant'] - $k1p - $k2p - $k3p);
            if ($t4 < 0) {
                $t4 = 0.0;
            }
        }
        if (!empty($prov['surtax']) && !$taxExempt) {
            $v1 = sd_on_surtax($prov['surtax'], $t4);
        }
        if (!empty($prov['health_premium'])) {
            $v2 = sd_health_premium($prov['health_premium'], max(0.0, $A));
        }
        $red = $prov['reduction'] ?? null;
        if ($red && !$taxExempt) {
            if (($red['type'] ?? '') === 'bc') {
                $s = sd_bc_reduction($red, $A, $t4);
            } elseif (($red['type'] ?? '') === 'on') {
                $s = sd_on_reduction($red, sd_money($t4 + $v1), $dependants);
            }
        }
    }
    $t2 = sd_money($t4 + $v1 + $v2 - $s);
    if ($t2 < 0) {
        $t2 = 0.0;
    }
    return [
        't1' => $t1,
        't2' => $t2,
        't3' => $t3,
        't4' => $t4,
        'v1' => $v1,
        'v2' => $v2,
        's' => $s,
        'k1' => $k1,
        'k2' => $k2,
        'k4' => $k4,
        'k1p' => $k1p,
        'k2p' => $k2p,
        'tc' => $claim,
        'tcp' => $tcpUsed,
        'a' => sd_money($A),
        'province_supported' => $supported,
        'annual_base_cpp' => $annualBaseCpp,
        'annual_ei' => $annualEi,
    ];
}

/**
 * One pay.
 *
 * Required: pay_date, periods (P), periodic_gross (I).
 * Province defaults to BC when omitted only inside tests that pass it.
 * tc / tcp null means "no TD1 on file": federal BPAF formula, provincial basic amount.
 * tc 0 is a filed claim of zero, which is not the same as null.
 * tax_exempt is claim code E. Ontario Health Premium is still calculated.
 * bonus (B) is a non-periodic payment this period (a lump-sum vacation payout,
 * a bonus, or retroactive pay). Vacation paid as a percentage of every cheque
 * belongs in periodic_gross, not in bonus.
 */
function sd_calculate(array $in, ?array $pack = null): array
{
    $payDate = (string)($in['pay_date'] ?? '');
    if ($pack === null) {
        $pack = sd_pack_for($payDate, $in['rate_packs'] ?? []);
    }
    $zero = sd_empty_result($payDate, $pack);
    if ($pack === null) {
        $zero['ok'] = false;
        $zero['error'] = 'No deduction rates are on file for ' . $payDate . '.';
        return $zero;
    }
    $P = (int)($in['periods'] ?? 0);
    if ($P <= 0) {
        $zero['ok'] = false;
        $zero['error'] = 'Pay periods per year must be set (52, 26, 24, 12, or the 53/27 variant).';
        return $zero;
    }
    $pm = (int)($in['pm'] ?? 12);
    if ($pm < 0) {
        $pm = 0;
    }
    if ($pm > 12) {
        $pm = 12;
    }
    $province = sd_province_code((string)($in['province'] ?? ''));
    $I = sd_money((float)($in['periodic_gross'] ?? 0));
    $B = sd_money((float)($in['bonus'] ?? 0));
    $B1 = sd_money((float)($in['ytd_bonus'] ?? 0));
    if ($I < 0) {
        $I = 0.0;
    }
    if ($B < 0) {
        $B = 0.0;
    }
    $F = sd_money((float)($in['rpp'] ?? 0));
    $F2 = sd_money((float)($in['f2'] ?? 0));
    $F3 = sd_money((float)($in['bonus_rpp'] ?? 0));
    $F4 = sd_money((float)($in['ytd_bonus_rpp'] ?? 0));
    $U1 = sd_money((float)($in['union_dues'] ?? 0));
    $HD = sd_money((float)($in['hd'] ?? 0));
    $F1 = sd_money((float)($in['f1'] ?? 0));
    $L = sd_money((float)($in['additional_tax'] ?? 0));
    $K3 = sd_money((float)($in['k3'] ?? 0));
    $K3P = sd_money((float)($in['k3p'] ?? 0));
    $ytdF5b = sd_money((float)($in['ytd_f5b'] ?? 0));
    $D = sd_money((float)($in['ytd_cpp'] ?? 0));
    $D2 = sd_money((float)($in['ytd_cpp2'] ?? 0));
    $D1 = sd_money((float)($in['ytd_ei'] ?? 0));
    $piYtd = sd_money((float)($in['ytd_pensionable'] ?? 0));
    $dependants = max(0, (int)($in['ontario_dependants'] ?? 0));
    $taxExempt = !empty($in['tax_exempt']);
    $tc = array_key_exists('tc', $in) ? $in['tc'] : null;
    $tcp = array_key_exists('tcp', $in) ? $in['tcp'] : null;
    if ($tc !== null) {
        $tc = sd_money((float)$tc);
    }
    if ($tcp !== null) {
        $tcp = sd_money((float)$tcp);
    }

    $cpp = $pack['cpp'];
    $eiPack = $pack['ei'];
    $pi = sd_money($I + $B);
    $exemption = sd_cpp_exemption($pack, $P);
    $maxTotal = sd_money((float)$cpp['max_total'] * $pm / 12);
    $maxBase = sd_money((float)$cpp['max_base'] * $pm / 12);
    $maxSecond = sd_money((float)$cpp['max_second'] * $pm / 12);
    $cppExempt = !empty($in['cpp_exempt']) || $pm === 0;
    $eiExempt = !empty($in['ei_exempt']);

    $C = 0.0;
    if (!$cppExempt && $pi > 0) {
        $ex = $I > 0 ? $exemption : 0.0;
        $raw = sd_money((float)$cpp['total_rate'] * max(0.0, $pi - $ex));
        $room = sd_money($maxTotal - $D);
        if ($room < 0) {
            $room = 0.0;
        }
        $C = min($raw, $room);
    }
    $C2 = 0.0;
    if (!$cppExempt && $pi > 0) {
        $w = max($piYtd, sd_money((float)$cpp['ympe'] * $pm / 12));
        $raw2 = sd_money(($piYtd + $pi - $w) * (float)$cpp['second_rate']);
        if ($raw2 < 0) {
            $raw2 = 0.0;
        }
        $room2 = sd_money($maxSecond - $D2);
        if ($room2 < 0) {
            $room2 = 0.0;
        }
        $C2 = min($raw2, $room2);
    }

    $eiRate = (float)$eiPack['employee_rate'];
    $eiMax = (float)$eiPack['max_employee'];
    if ($province === 'QC') {
        $eiRate = (float)$eiPack['quebec_employee_rate'];
        $eiMax = (float)$eiPack['quebec_max_employee'];
    }
    $EI = 0.0;
    if (!$eiExempt && $pi > 0) {
        $rawEi = sd_money($eiRate * $pi);
        $roomEi = sd_money($eiMax - $D1);
        if ($roomEi < 0) {
            $roomEi = 0.0;
        }
        $EI = min($rawEi, $roomEi);
    }
    $multiple = (float)$eiPack['employer_multiple'];
    $eiEr = sd_money(sd_money(($D1 + $EI) * $multiple) - sd_money($D1 * $multiple));
    if ($eiEr < 0) {
        $eiEr = 0.0;
    }

    $ratioFirst = (float)$cpp['total_rate'] > 0 ? (float)$cpp['first_additional_rate'] / (float)$cpp['total_rate'] : 0.0;
    $ratioBase = (float)$cpp['total_rate'] > 0 ? (float)$cpp['base_rate'] / (float)$cpp['total_rate'] : 0.0;
    $F5 = ($C == 0.0 && $C2 == 0.0) ? 0.0 : sd_money($C * $ratioFirst + $C2);
    $F5A = 0.0;
    $F5B = 0.0;
    if ($pi > 0 && $F5 > 0) {
        $F5A = sd_money($F5 * (($pi - $B) / $pi));
        $F5B = sd_money($F5 - $F5A);
    }

    $credits = sd_credit_bases(
        $pack, $P, $pm, $I, $B, $B1, $C, $EI, $D, $D1, $exemption, $eiRate, $maxBase, $maxTotal, $eiMax, true
    );
    $creditsWithout = $B > 0
        ? sd_credit_bases($pack, $P, $pm, $I, 0.0, $B1, $C, $EI, $D, $D1, $exemption, $eiRate, $maxBase, $maxTotal, $eiMax, false)
        : $credits;

    $core = sd_money($P * sd_money($I - $F - $F2 - $F5A - $U1) - $HD - $F1);
    $bonusNow = sd_money(max(0.0, $B - $F3 - $F5B));
    $bonusPrior = sd_money(max(0.0, $B1 - $F4 - $ytdF5b));
    $Awith = sd_money(max(0.0, $core) + $bonusNow + $bonusPrior);
    $Awithout = sd_money(max(0.0, $core) + $bonusPrior);
    $empWith = sd_money($P * $I + $B + $B1);
    $empWithout = sd_money($P * $I + $B1);

    $flat = (float)$pack['federal']['bonus_flat_threshold'];
    $useFlatBonus = $B > 0 && $Awith <= $flat;

    $with = sd_annual_tax(
        $pack, $Awith, $empWith, $credits['annual_base'], $credits['annual_ei'],
        $tc, $tcp, $province, $dependants, $taxExempt, $HD, $K3, $K3P
    );
    if ($B > 0) {
        $without = sd_annual_tax(
            $pack, $Awithout, $empWithout, $creditsWithout['annual_base'], $creditsWithout['annual_ei'],
            $tc, $tcp, $province, $dependants, $taxExempt, $HD, $K3, $K3P
        );
    } else {
        $without = $with;
    }

    if ($core < 0 && $B <= 0) {
        $tax = $L;
        $tb = 0.0;
    } elseif ($useFlatBonus) {
        $tb = sd_money((float)$pack['federal']['bonus_flat_rate'] * max(0.0, $B));
        $periodic = $taxExempt ? 0.0 : sd_money(($without['t1'] + $without['t2']) / $P);
        $tax = sd_money($periodic + $tb + $L);
    } else {
        $tb = 0.0;
        if ($B > 0) {
            $tb = sd_money(($with['t1'] + $with['t2']) - ($without['t1'] + $without['t2']));
            if ($tb < 0) {
                $tb = 0.0;
            }
        }
        $annualForPeriod = $B > 0 ? ($without['t1'] + $without['t2']) : ($with['t1'] + $with['t2']);
        $tax = sd_money($annualForPeriod / $P + $tb + $L);
        if ($tax < $L) {
            $tax = $L;
        }
    }

    $cppBase = sd_money($C * $ratioBase);
    $cppFirst = sd_money($C - $cppBase);

    $result = sd_empty_result($payDate, $pack);
    $result['ok'] = true;
    $result['province'] = $province;
    $result['province_supported'] = $province === '' ? false : (bool)$with['province_supported'];
    $result['cpp'] = $C;
    $result['cpp_base'] = $cppBase;
    $result['cpp_additional'] = $cppFirst;
    $result['cpp2'] = $C2;
    $result['ei'] = $EI;
    $result['income_tax'] = $tax;
    $result['tax_bonus'] = $tb;
    $result['cpp_er'] = $C;
    $result['cpp2_er'] = $C2;
    $result['ei_er'] = $eiEr;
    $result['pensionable'] = $cppExempt ? 0.0 : $pi;
    $result['insurable'] = $eiExempt ? 0.0 : $pi;
    $result['federal_annual'] = $with['t1'];
    $result['provincial_annual'] = $with['t2'];
    $result['ohp_annual'] = $with['v2'];
    $result['factors'] = [
        'P' => $P,
        'PM' => $pm,
        'I' => $I,
        'B' => $B,
        'PI' => $pi,
        'exemption' => $exemption,
        'C' => $C,
        'C2' => $C2,
        'EI' => $EI,
        'F5' => $F5,
        'F5A' => $F5A,
        'F5B' => $F5B,
        'A' => $with['a'],
        'A_without' => $without['a'],
        'K1' => $with['k1'],
        'K2' => $with['k2'],
        'K2_without' => $without['k2'],
        'K4' => $with['k4'],
        'TC' => $with['tc'],
        'TCP' => $with['tcp'],
        'T1' => $with['t1'],
        'T1_without' => $without['t1'],
        'T2' => $with['t2'],
        'T2_without' => $without['t2'],
        'T3' => $with['t3'],
        'T4' => $with['t4'],
        'V1' => $with['v1'],
        'V2' => $with['v2'],
        'S' => $with['s'],
        'TB' => $tb,
        'annual_base_cpp' => $with['annual_base_cpp'],
        'annual_ei' => $with['annual_ei'],
        'edition' => (string)$pack['edition'],
    ];
    if ($province === 'QC') {
        $result['warnings'][] = 'Quebec provincial income tax and QPP are not calculated. EI uses the Quebec premium rate. File Quebec deductions with Revenu Québec.';
    } elseif ($province !== '' && !$with['province_supported']) {
        $result['warnings'][] = 'No provincial tax table is loaded for ' . $province . '. Federal tax, CPP, and EI were calculated. Provincial tax was not.';
    }
    return $result;
}

/**
 * K2 / K2P bases.
 * $includeCurrentBonus false drops the current bonus's CPP and EI from the
 * annualized credit, which is step 2 of the bonus method in chapter 4.
 */
function sd_credit_bases(
    array $pack,
    int $P,
    int $pm,
    float $I,
    float $B,
    float $B1,
    float $C,
    float $EI,
    float $D,
    float $D1,
    float $exemption,
    float $eiRate,
    float $maxBase,
    float $maxTotal,
    float $eiMax,
    bool $includeCurrentBonus
): array {
    $cpp = $pack['cpp'];
    $ratioBase = (float)$cpp['total_rate'] > 0 ? (float)$cpp['base_rate'] / (float)$cpp['total_rate'] : 0.0;
    $rate = (float)$cpp['total_rate'];
    $cRegular = $I > 0 ? sd_money($rate * max(0.0, $I - $exemption)) : 0.0;
    $cBonus = $includeCurrentBonus ? sd_money($rate * $B) : 0.0;
    $cPrior = sd_money($rate * $B1);
    $basePieces = sd_money($P * sd_money($cRegular * $ratioBase) + sd_money($cBonus * $ratioBase) + sd_money($cPrior * $ratioBase));
    $fromYtd = sd_money($D * $ratioBase);
    $annualBase = min($maxBase, max($basePieces, $fromYtd));
    if (sd_money($D + $C) + 0.001 >= $maxTotal && $maxTotal > 0) {
        $annualBase = $maxBase;
    }

    $eiRegular = sd_money($eiRate * $I);
    $eiBonus = $includeCurrentBonus ? sd_money($eiRate * $B) : 0.0;
    $eiPrior = sd_money($eiRate * $B1);
    $eiPieces = sd_money($P * $eiRegular + $eiBonus + $eiPrior);
    $annualEi = min($eiMax, max($eiPieces, $D1));
    if (sd_money($D1 + $EI) + 0.001 >= $eiMax && $eiMax > 0) {
        $annualEi = $eiMax;
    }
    return ['annual_base' => $annualBase, 'annual_ei' => $annualEi];
}

function sd_empty_result(string $payDate, ?array $pack): array
{
    return [
        'ok' => false,
        'error' => '',
        'pay_date' => $payDate,
        'edition' => $pack['edition'] ?? '',
        'province' => '',
        'province_supported' => false,
        'cpp' => 0.0,
        'cpp_base' => 0.0,
        'cpp_additional' => 0.0,
        'cpp2' => 0.0,
        'ei' => 0.0,
        'income_tax' => 0.0,
        'tax_bonus' => 0.0,
        'cpp_er' => 0.0,
        'cpp2_er' => 0.0,
        'ei_er' => 0.0,
        'pensionable' => 0.0,
        'insurable' => 0.0,
        'federal_annual' => 0.0,
        'provincial_annual' => 0.0,
        'ohp_annual' => 0.0,
        'factors' => [],
        'warnings' => [],
    ];
}

/**
 * Ontario Employer Health Tax for a calendar year.
 * $remuneration is total Ontario remuneration before the exemption.
 * The rate is chosen from that total. The tax is rate times (remuneration minus exemption).
 * $exemptionShare is this employer's allocated piece of the $1,000,000 exemption.
 * $eligible false, or $groupOverCap true (associated group over $5,000,000), means no exemption.
 * A registered charity that is still eligible with payroll over $5,000,000 should leave
 * $groupOverCap false and pass its own exemption amount.
 */
function sd_ontario_eht(float $remuneration, float $exemptionShare, bool $eligible, bool $groupOverCap, ?array $pack = null): array
{
    if ($pack === null) {
        $pack = sd_pack_for('2026-01-01');
    }
    $rules = $pack['eht_on'];
    $remuneration = sd_money(max(0.0, $remuneration));
    $share = sd_money(max(0.0, $exemptionShare));
    $cap = (float)$rules['exemption'];
    if ($share > $cap) {
        $share = $cap;
    }
    $exemption = ($eligible && !$groupOverCap) ? min($share, $remuneration) : 0.0;
    $rate = 0.0;
    foreach ($rules['tiers'] as $tier) {
        $rate = (float)$tier['rate'];
        if ($tier['up_to'] === null || $remuneration <= (float)$tier['up_to'] + 0.0000001) {
            break;
        }
    }
    $taxable = sd_money(max(0.0, $remuneration - $exemption));
    return [
        'remuneration' => $remuneration,
        'exemption' => sd_money($exemption),
        'taxable' => $taxable,
        'rate' => $rate,
        'tax' => sd_money($taxable * $rate),
        'eligible' => $eligible && !$groupOverCap,
    ];
}

/** This pay's EHT is the annual tax after the pay minus the annual tax before it. */
function sd_ontario_eht_increment(float $ytdBefore, float $thisPay, float $exemptionShare, bool $eligible, bool $groupOverCap, ?array $pack = null): array
{
    $before = sd_ontario_eht($ytdBefore, $exemptionShare, $eligible, $groupOverCap, $pack);
    $after = sd_ontario_eht($ytdBefore + $thisPay, $exemptionShare, $eligible, $groupOverCap, $pack);
    $after['this_pay'] = sd_money(max(0.0, $thisPay));
    $after['increment'] = sd_money($after['tax'] - $before['tax']);
    $after['ytd_before'] = $before['remuneration'];
    return $after;
}

/**
 * WSIB (Ontario), WorkSafeBC, or Saskatchewan WCB.
 * $ratePercent is the site's own rate, as a percent: 1.23 means 1.23% of assessable earnings.
 * The maximum assessable earnings come from the pack for the province. The rate does not.
 */
/**
 * Split a money total across weights. The last non-zero weight absorbs the
 * rounding remainder so the pieces add back to the total.
 *
 * @param list<float> $weights
 * @return list<float>
 */
function sd_allocate_money(float $total, array $weights): array
{
    $total = sd_money($total);
    $clean = [];
    $sum = 0.0;
    foreach ($weights as $i => $w) {
        $clean[$i] = max(0.0, (float)$w);
        $sum += $clean[$i];
    }
    $out = array_fill(0, count($weights), 0.0);
    if ($sum <= 0 || $total == 0.0) {
        return $out;
    }
    $running = 0.0;
    $last = null;
    foreach ($clean as $i => $w) {
        if ($w <= 0) {
            continue;
        }
        $last = $i;
    }
    foreach ($clean as $i => $w) {
        if ($w <= 0) {
            continue;
        }
        if ($i === $last) {
            $out[$i] = sd_money($total - $running);
        } else {
            $share = sd_money($total * $w / $sum);
            $out[$i] = $share;
            $running = sd_money($running + $share);
        }
    }
    return $out;
}

function sd_workers_comp(string $province, float $earnings, float $ytdAssessable, float $ratePercent, bool $exempt, ?array $pack = null): array
{
    $code = sd_province_code($province);
    if ($pack === null) {
        $pack = sd_pack_for('2026-01-01');
    }
    $max = $pack['assessable_max'][$code] ?? null;
    if ($exempt || $max === null || $earnings <= 0 || $ratePercent <= 0) {
        return [
            'assessable' => 0.0,
            'premium' => 0.0,
            'max' => $max === null ? 0.0 : (float)$max,
            'rate_percent' => $ratePercent,
            'exempt' => $exempt,
            'capped' => false,
            'supported' => $max !== null,
        ];
    }
    $room = sd_money((float)$max - max(0.0, $ytdAssessable));
    if ($room < 0) {
        $room = 0.0;
    }
    $assessable = sd_money(min(max(0.0, $earnings), $room));
    return [
        'assessable' => $assessable,
        'premium' => sd_money($assessable * $ratePercent / 100),
        'max' => (float)$max,
        'rate_percent' => $ratePercent,
        'exempt' => false,
        'capped' => $assessable + 0.001 < sd_money($earnings),
        'supported' => true,
    ];
}
