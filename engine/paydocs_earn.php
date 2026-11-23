<?php
/**
 * Pay-stub earnings classifiers. No database.
 * Statutory holiday pay is peeled out of Regular. Stat Day Worked stays its own line.
 */

function paydocs_is_stat_worked(string $cat, string $label): bool {
    $t = strtolower($cat . ' ' . $label);
    return (bool)preg_match('/stat\s*day\s*worked|stat\s*worked|worked\s*(the\s*)?(stat|holiday)|holiday\s*premium|holiday\s*worked|hours\s*worked\s*@\s*1\.5/', $t);
}
function paydocs_is_public_holiday(string $cat, string $label): bool {
    if (paydocs_is_stat_worked($cat, $label)) return false;
    $t = strtolower($cat . ' ' . $label);
    return (bool)preg_match('/public\s*holiday|stat\s*holiday|statutory|\bph\b|stat\s*pay|holiday\s*pay/', $t);
}
function paydocs_is_bonus(string $cat, string $label): bool {
    $t = strtolower($cat . ' ' . $label);
    return (bool)preg_match('/bonus/', $t);
}
function paydocs_is_car_allowance(string $cat, string $label): bool {
    $t = strtolower($cat . ' ' . $label);
    return (bool)preg_match('/\b(?:car|auto|automobile|vehicle|motor\s+vehicle)\s+allowance\b/', $t);
}
/** Pull bonus, car allowance, and statutory holiday pay out of Regular so each prints on its own stub line. */
function paydocs_peel_special(array &$bucket, string $cat, string $label, float $gross, float $bonusPay, float $regPay, bool $bonusAlreadySummed = false, float $regHours = 0.0): void {
    if (!isset($bucket['car_allow'])) $bucket['car_allow'] = 0.0;
    if (!isset($bucket['ph_h'])) $bucket['ph_h'] = 0.0;
    if (paydocs_is_car_allowance($cat, $label)) {
        $bucket['car_allow'] += $gross;
        if ($regPay > 0.004) $bucket['reg_pay'] -= $regPay;
        return;
    }
    if (paydocs_is_public_holiday($cat, $label)) {
        if ($regPay > 0.004 && isset($bucket['reg_pay'])) $bucket['reg_pay'] -= $regPay;
        if ($regHours > 0.004 && isset($bucket['reg_h'])) $bucket['reg_h'] -= $regHours;
        if ($regHours > 0.004) $bucket['ph_h'] += $regHours;
        return;
    }
    if (!$bonusAlreadySummed) $bucket['bonus'] += $bonusPay;
    if (paydocs_is_bonus($cat, $label) && $bonusPay < 0.005) {
        $bucket['bonus'] += $gross;
        if ($regPay > 0.004) $bucket['reg_pay'] -= $regPay;
    }
}
