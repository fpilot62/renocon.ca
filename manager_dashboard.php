<?php
/** Path: /manager_dashboard.php */
date_default_timezone_set('America/Edmonton'); 

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
require_once 'engine/menu_generator.php'; 

// 1. STRICT SECURITY GATE (Level 29 and below ONLY)
$current_org_id = isset($_SESSION['org_id']) ? (int)$_SESSION['org_id'] : 0;
$user_lvl       = isset($_SESSION['user_level']) ? (int)$_SESSION['user_level'] : 99;

// Blocks Level 30 (Managers), 40 (Supervisors), and 50 (Staff)
if (!isset($_SESSION['user_id']) || $user_lvl > 29) { 
    header("Location: kiosk.php?err=Unauthorized_Level"); 
    exit; 
}

$is_super = ($current_org_id === 100000 && $user_lvl <= 10);

// 2. DISTANCE CALCULATOR (For GPS Auditing)
function getDistance($lat1, $lon1, $lat2, $lon2) {
    if (!$lat1 || !$lat2 || !$lon1 || !$lon2) return 0;
    $earth_radius = 6371000; 
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}

// Employer cost on each row comes from pe_line.

$grouped_sites = [];

// 4. DATA FETCH WITH ORG FILTER (Fixes Banker's Hall Leak)
try {
    $params = [];
    $where = ["1=1"];

    if (!$is_super) {
        $where[] = "s.org_id = ?"; 
        $params[] = $current_org_id;
    }

    $sql = "SELECT a.*, u.Name as emp_name, u.official_rate, u.vac_pay_percent, 
                   s.nick_name as site_name, s.target_lat, s.target_lng, s.geo_radius_meters
            FROM attendance a
            INNER JOIN sites s ON a.site_id = s.SId 
            LEFT JOIN users u ON a.user_id = u.id 
            WHERE " . implode(" AND ", $where) . "
            ORDER BY a.clock_in DESC LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($records as $r) { 
        $siteKey = $r['site_name'] ?: 'Unassigned';
        $grouped_sites[$siteKey][] = $r; 
    }
} catch (Exception $e) { 
    die("Security Error: " . $e->getMessage()); 
}
?>
<!DOCTYPE html>
<html>
<head>
    <!-- pwa-head -->
    <?php if (file_exists(__DIR__ . '/pwa_head.php')) include __DIR__ . '/pwa_head.php'; ?>

    <title>Executive Payroll Audit | SIP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin:0; color: #1e293b; padding-top: 130px; }
        .site-section { background: white; border-radius: 12px; margin: 20px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); overflow: hidden; }
        .site-header { background: #0f172a; color: white; padding: 15px 20px; font-weight: 800; display: flex; justify-content: space-between; align-items: center; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; padding: 12px; text-align: left; border-bottom: 2px solid #e2e8f0; font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 700; }
        td { padding: 14px; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
        .money { font-family: 'Monaco', monospace; font-weight: 700; }
        .details-row { background: #f8fafc; display: none; }
        .btn-view { background: #0f172a; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 700; font-size: 11px; }
    </style>
    <script>function toggleDetails(id){ var el = document.getElementById('details-'+id); el.style.display = (el.style.display==='table-row')?'none':'table-row'; }</script>
</head>
<body>

<?= generateMenu($pdo); ?>

<div style="padding: 20px;">
    <h2 style="margin:0; font-weight: 800; font-size: 28px;">Executive Payroll Audit</h2>
    <p style="color: #64748b; margin-top: 5px;">Strict Access: Admin Only (Org ID: <?= $current_org_id ?>)</p>
</div>

<?php if (empty($grouped_sites)): ?>
    <div style="margin: 20px; padding: 50px; background: white; border-radius: 12px; text-align: center; border: 1px dashed #cbd5e1;">
        <h3>No records available for your administrative level.</h3>
    </div>
<?php endif; ?>

<?php foreach ($grouped_sites as $siteName => $rows): ?>
    <div class="site-section">
        <div class="site-header">
            <span>📍 <?= strtoupper(htmlspecialchars($siteName)) ?></span>
            <span style="font-size: 12px; opacity: 0.8;"><?= count($rows) ?> Total Shifts</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Staff Member</th><th>Time In/Out</th><th>Hrs</th><th>GPS</th><th>Gross Pay</th><th>Total Liability</th><th>Audit</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): 
                    $rowID = "row_" . $r['id'];
                    $dist = getDistance($r['lat_in'], $r['lng_in'], $r['target_lat'], $r['target_lng']);
                    $in_t = strtotime($r['clock_in']);
                    $out_t = ($r['clock_out'] && $r['clock_out'] != '0000-00-00 00:00:00') ? strtotime($r['clock_out']) : time();
                    $net_hrs = max(0, ($out_t - $in_t) / 3600);
                    $day = date('Y-m-d', $in_t);
                    $uid = (int)($r['user_id'] ?? 0);
                    $sid = (int)($r['site_id'] ?? 0);
                    $gross = $net_hrs * pe_profile_hourly_rate($pdo, $uid, $day);
                    $mgr = pe_line($gross, pe_vacation_rate($pdo, $uid, $sid, $day), pe_rates($pdo), [
                        'pdo' => $pdo, 'user_id' => $uid, 'site_id' => $sid, 'org_id' => (int)($r['org_id'] ?? 0), 'work_date' => $day,
                    ]);
                    $vac_pay = (float)$mgr['vac'];
                    $er_cpp = (float)$mgr['cpp_er'] + (float)($mgr['cpp2_er'] ?? 0);
                    $er_ei = (float)$mgr['ei_er'];
                    $er_wcb = (float)$mgr['wcb'];
                    $total_liability = (float)$mgr['pay_gross'] + $er_cpp + $er_ei + $er_wcb;
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($r['emp_name']) ?></strong></td>
                    <td><?= date('M j, g:i A', $in_t) ?></td>
                    <td><?= number_format($net_hrs, 2) ?></td>
                    <td><?= round($dist) ?>m</td>
                    <td class="money">$<?= number_format($gross, 2) ?></td>
                    <td class="money" style="color:#2563eb;">$<?= number_format($total_liability, 2) ?></td>
                    <td><button class="btn-view" onclick="toggleDetails('<?= $rowID ?>')">DETAILS</button></td>
                </tr>
                <tr class="details-row" id="details-<?= $rowID ?>">
                    <td colspan="7" style="background:#f8fafc; padding: 20px; border-left: 4px solid #0f172a;">
                        <b>Employer Burden Breakdown:</b><br>
                        Gross: $<?= number_format($gross, 2) ?> | 
                        Vacation (<?= $r['vac_pay_percent'] ?>%): $<?= number_format($vac_pay, 2) ?> | 
                        CPP: $<?= number_format($er_cpp, 2) ?> | 
                        EI: $<?= number_format($er_ei, 2) ?> | 
                        WCB: $<?= number_format($er_wcb, 2) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; ?>
</body>
</html>