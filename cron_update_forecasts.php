<?php
/** Path: /sip/active/modules/scheduling/cron_update_forecasts.php */
require_once $_SERVER['DOCUMENT_ROOT'] . "/engine.php";

// 1. Get Global Settings
$settings = $pdo->query("SELECT setting_key, setting_value FROM Gem_Settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$avg_base_rate = floatval($settings['average_base_rate'] ?? 16.50);
$target_labor_pct = floatval($settings['target_labor_percent'] ?? 21.5) / 100;

if (!function_exists('sip_org_pay_period_mode')) {
    $lib = __DIR__ . '/engine/org_modules_lib.php';
    if (is_file($lib)) { require_once $lib; }
}

// 2. Loop through all sites
$sites = $pdo->query("SELECT SId, org_id FROM sites")->fetchAll(PDO::FETCH_ASSOC);

foreach ($sites as $site_row) {
    $rid = (int)$site_row['SId'];
    $site_org = (int)($site_row['org_id'] ?? 0);
    $ahead = 14;
    if ($site_org > 0 && function_exists('sip_org_pay_period_mode') && sip_org_pay_period_mode($pdo, $site_org) === 'bi_weekly') {
        $ahead = 28; // current + next pay period
    }
    // Look ahead far enough that scheduler / period forecast have rows
    for ($i = 0; $i < $ahead; $i++) {
        $date = date('Y-m-d', strtotime("+$i days"));
        
        // Calculate Growth Factor (Last 7 days vs LY)
        $v_stmt = $pdo->prepare("SELECT SUM(pos_sales) FROM daily_report WHERE RId = ? AND report_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
        $v_stmt->execute([$rid]);
        $curr_7 = floatval($v_stmt->fetchColumn());

        $ly_v_stmt = $pdo->prepare("SELECT SUM(pos_sales) FROM daily_report WHERE RId = ? AND report_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 YEAR + INTERVAL 7 DAY) AND DATE_SUB(CURDATE(), INTERVAL 1 YEAR + INTERVAL 1 DAY)");
        $ly_v_stmt->execute([$rid]);
        $ly_7 = floatval($ly_v_stmt->fetchColumn());

        $growth = ($ly_7 > 0) ? ($curr_7 / $ly_7) : 1.0;

        // Get LY Sales for "A"
        $ly_day_stmt = $pdo->prepare("SELECT pos_sales FROM daily_report WHERE RId = ? AND report_date = DATE_SUB(?, INTERVAL 1 YEAR)");
        $ly_day_stmt->execute([$rid, $date]);
        $ly_sales_a = floatval($ly_day_stmt->fetchColumn());

        $forecast = $ly_sales_a * $growth;
        $target_hrs = ($forecast * $target_labor_pct) / $avg_base_rate;

        // Save to DB
        $upsert = $pdo->prepare("INSERT INTO Gem_Daily_Forecasts (rid, forecast_date, forecasted_sales, target_hours, growth_factor_used) 
                                 VALUES (?, ?, ?, ?, ?) 
                                 ON DUPLICATE KEY UPDATE forecasted_sales=VALUES(forecasted_sales), target_hours=VALUES(target_hours)");
        $upsert->execute([$rid, $date, $forecast, $target_hrs, $growth]);
    }
}
echo "Forecasts updated successfully.";