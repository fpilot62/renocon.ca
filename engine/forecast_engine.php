<?php
/** Path: /home/admin/domains/payroll.renocon.ca/public_html/engine/forecast_engine.php */

// 1. DECLARE GLOBALS FIRST
global $db, $rid, $start_wk, $end_wk;
global $dailyForecast, $weeklyTotalForecast, $weeklyTargetHours, $base_rate, $lab_pct;

// 2. RUN ENGINE ONLY IF DATA EXISTS
if (isset($rid) && isset($start_wk) && isset($end_wk)) {
    
    $db = $db ?? $conn; // Ensure we have the connection
    $current_site_id = $rid;

    // 1. Calculate Growth Factor (Last 7 vs Prev 7)
    $q_f = "SELECT 
            (SELECT SUM(pos_sales) FROM daily_report WHERE restaurant_id = ? AND report_date BETWEEN CURDATE() - INTERVAL 7 DAY AND CURDATE() - INTERVAL 1 DAY) as last_7,
            (SELECT SUM(pos_sales) FROM daily_report WHERE restaurant_id = ? AND report_date BETWEEN CURDATE() - INTERVAL 14 DAY AND CURDATE() - INTERVAL 8 DAY) as prev_7";
    
    $stmt_f = $db->prepare($q_f);
    $stmt_f->bind_param("ii", $current_site_id, $current_site_id);
    $stmt_f->execute();
    $res_f = $stmt_f->get_result()->fetch_assoc();
    
    $last7 = (float)($res_f['last_7'] ?? 0);
    $prev7 = (float)($res_f['prev_7'] ?? 0);
    $factor = ($prev7 > 0) ? ($last7 / $prev7) : 1.05;

    // 2. Populate Forecast Table
   
        $period = new DatePeriod(new DateTime($start_wk), new DateInterval('P1D'), (new DateTime($end_wk))->modify('+1 day'));
foreach ($period as $date) {
    $t_date = $date->format('Y-m-d');
    
    // We use $db_growth (1.2) here to inflate last year's sales
    $q_ins = "INSERT INTO daily_forecast (site_id, forecast_date, target_date, growth_factor, ly_sales, projected_sales)
              SELECT ?, CURDATE(), ?, ?, IFNULL(pos_sales, 0), (IFNULL(pos_sales, 1350) * ?)
              FROM (SELECT 1) as dummy
              LEFT JOIN daily_report ON restaurant_id = ? AND report_date = DATE_SUB(?, INTERVAL 1 YEAR)
              ON DUPLICATE KEY UPDATE projected_sales = VALUES(projected_sales)";
    
    $stmt_ins = $db->prepare($q_ins);
    // Bind $db_growth (1.2) to the multipliers
    $stmt_ins->bind_param("isddis", $current_site_id, $t_date, $db_growth, $db_growth, $current_site_id, $t_date);
    $stmt_ins->execute();
}
        ///////////
   

  // 3. Fetch Site Targets from Gem_Settings
    $q_t = "SELECT setting_key, setting_value FROM Gem_Settings WHERE org_id = (SELECT org_id FROM sites WHERE SId = ? LIMIT 1)";
    $stmt_t = $db->prepare($q_t);
    $stmt_t->bind_param("i", $current_site_id);
    $stmt_t->execute();
    $res_t = $stmt_t->get_result();
    
    $settings = [];
    while($row = $res_t->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    // Pull from your DB (image_4e16fe.jpg)
    $db_factor = (float)($settings['sales_growth_factor'] ?? 1.0);
    $base_rate = (float)($settings['average_base_rate'] ?? 18.50);
    $lab_pct   = (float)($settings['target_labor_percent'] ?? 21.5) / 100;
    $burden    = (float)($settings['employer_burden_multiplier'] ?? 1.1177); 

    // Re-run population if we want to use the DB growth factor instead of the calculated one
    // Final Target Calculation including Burden
    $weeklyTargetHours = ($base_rate > 0) ? (($weeklyTotalForecast * $lab_pct) / ($base_rate * $burden)) : 0;////
    

    // 4. Load Results for UI
    $dailyForecast = [];
    $weeklyTotalForecast = 0;
    $q_ui = "SELECT target_date, projected_sales FROM daily_forecast WHERE site_id = ? AND target_date BETWEEN ? AND ?";
    $stmt_ui = $db->prepare($q_ui);
    $stmt_ui->bind_param("iss", $current_site_id, $start_wk, $end_wk);
    $stmt_ui->execute();
    $res_ui = $stmt_ui->get_result();
    
    while($row = $res_ui->fetch_assoc()) {
        $val = (float)$row['projected_sales'];
        $dailyForecast[$row['target_date']] = $val;
        $weeklyTotalForecast += $val;
    }
    
    $weeklyTargetHours = ($base_rate > 0) ? (($weeklyTotalForecast * $lab_pct) / $base_rate) : 0;
    // Add 12% for employer deductions (CPP, EI, WCB)
$payroll_burden = 1.12; 
$weeklyTargetHours = ($base_rate > 0) ? (($weeklyTotalForecast * $lab_pct) / ($base_rate * $payroll_burden)) : 0;
}