<?php
/**
 * Shim: pe_site_ot_policy() for older payroll_engine.php (pre-OT site overrides).
 * Safe to include after payroll_engine/payroll_engine.php.
 */
if (!function_exists('pe_site_ot_policy')) {
    function pe_site_ot_policy(PDO $pdo, int $site_id): array {
        $weekly = 44.0;
        $daily = null;
        $province = 'ON';
        $src_w = 'province default';
        $src_d = 'province default';
        if (function_exists('pe_province_rule')) {
            $rule = pe_province_rule($pdo, $site_id);
            $weekly = $rule['ot_weekly_hours'];
            $daily = $rule['ot_daily_hours'];
            $province = (string)($rule['province'] ?? 'ON');
        }
        try {
            $q = $pdo->prepare("SELECT ot_weekly_hours, ot_daily_hours, province FROM sites WHERE SId = ? LIMIT 1");
            $q->execute([$site_id]);
            $s = $q->fetch(PDO::FETCH_ASSOC);
            if ($s) {
                if (!empty($s['province'])) {
                    $province = (string)$s['province'];
                }
                if (array_key_exists('ot_weekly_hours', $s) && $s['ot_weekly_hours'] !== null) {
                    $weekly = ((float)$s['ot_weekly_hours'] > 0) ? (float)$s['ot_weekly_hours'] : null;
                    $src_w = 'site override';
                }
                if (array_key_exists('ot_daily_hours', $s) && $s['ot_daily_hours'] !== null) {
                    $daily = ((float)$s['ot_daily_hours'] > 0) ? (float)$s['ot_daily_hours'] : null;
                    $src_d = 'site override';
                }
            }
        } catch (Exception $e) { /* keep province defaults */ }
        $parts = [];
        $parts[] = ($weekly !== null) ? ('weekly ' . rtrim(rtrim(number_format($weekly, 2), '0'), '.') . 'h (' . $src_w . ')') : 'weekly OT off';
        $parts[] = ($daily !== null) ? ('daily ' . rtrim(rtrim(number_format($daily, 2), '0'), '.') . 'h (' . $src_d . ')') : 'daily OT off';
        return [
            'province' => $province,
            'weekly' => $weekly,
            'daily' => $daily,
            'multiplier' => 1.5,
            'weekly_source' => $src_w,
            'daily_source' => $src_d,
            'label' => $province . ': ' . implode(' + ', $parts),
        ];
    }
}
