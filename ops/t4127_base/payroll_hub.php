<?php
ob_start();
/** Path: /payroll_hub.php 
 * FULL CODE - Multi-Tenant Strict + Assigned Sites Jail
 */
require_once 'db.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php'; 
require_once 'engine/menu_generator.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. SECURITY GATE
$current_user_id = $_SESSION['user_id'] ?? null;
$current_org_id  = (int)($_SESSION['org_id'] ?? 0);
$user_lvl        = (int)($_SESSION['user_level'] ?? 99);

if (!$current_user_id || $user_lvl >= 30) {
    header("Location: login_kiosk.php?err=access_denied_hub");
    exit;
}

// Check if restricted manager
$is_restricted = ($_SESSION['access_scope'] === 'restricted');
$assigned_sites = preg_replace('/[^0-9,]/', '', $_SESSION['assigned_site_ids'] ?? '');

// SHARED TAX CONSTANTS
$CPP_RATE   = 0.0595; 
$EI_RATE    = 0.0163; 
$EI_ER_MULT = 1.4; 
$WCB_RATE   = 0.018; 
$VAC_RATE   = 0.04;

// 2. CAPTURE FILTERS
$view       = $_GET['view'] ?? 'audit'; 
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-d');
$sel_site   = $_GET['site_id'] ?? 'all';

// 3. DATA FETCHING
try {
    // Fetch Sites for Dropdown (Jailed to Org + Assigned Sites)
    $site_sql = "SELECT SId, nick_name FROM sites WHERE org_id = ?";
    if ($is_restricted && !empty($assigned_sites)) {
        $site_sql .= " AND SId IN ($assigned_sites)";
    }
    $site_sql .= " ORDER BY nick_name ASC";
    $stmt = $pdo->prepare($site_sql);
    $stmt->execute([$current_org_id]);
    $sites = $stmt->fetchAll();

    // Main Payroll Query
    $sql = "SELECT a.*, u.Name, u.hourly_rate, u.official_rate, s.nick_name 
            FROM attendance a 
            INNER JOIN users u ON a.user_id = u.id 
            LEFT JOIN sites s ON a.site_id = s.SId 
            WHERE a.org_id = :org_id 
            AND DATE(a.clock_in) >= :start 
            AND DATE(a.clock_in) <= :end";
    
    $params = [
        ':org_id' => $current_org_id,
        ':start'  => $start_date, 
        ':end'    => $end_date
    ];

    // Jail for restricted managers
    if ($is_restricted && !empty($assigned_sites)) {
        $sql .= " AND a.site_id IN ($assigned_sites)";
    }

    if ($sel_site !== 'all') {
        $sql .= " AND a.site_id = :sid";
        $params[':sid'] = $sel_site;
    }

    $sql .= " ORDER BY a.clock_in DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) { die("Hub Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll Hub | crewPulse</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; color: #1e293b; padding-top: 130px; }
        .demo-bar { background: #e67e22; color: white; text-align: center; padding: 10px; font-weight: bold; position: fixed; top: 0; width: 100%; z-index: 10002; }
        .hub-wrapper { max-width: 1500px; margin: 10px auto; padding: 0 20px; }
        .hub-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 20px; }
        .view-tabs { display: flex; gap: 5px; background: #e2e8f0; padding: 5px; border-radius: 10px; }
        .tab-link { padding: 10px 20px; border-radius: 7px; text-decoration: none; color: #64748b; font-weight: 700; font-size: 13px; transition: 0.2s; }
        .tab-link.active { background: #0f172a; color: white; }
        .filter-card { background: white; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 20px; display: flex; gap: 15px; align-items: flex-end; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .filter-group { display: flex; flex-direction: column; gap: 5px; }
        .filter-group label { font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; }
        input, select { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; }
        .main-card { background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; padding: 15px; text-align: left; font-size: 11px; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #edf2f7; }
        td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        .money { font-family: 'JetBrains Mono', monospace; font-weight: bold; }
        .audit-details { display: none; background: #f9fafb; padding: 20px; border-top: 1px solid #e2e8f0; }
        .btn-verify { background: #3b82f6; color: white; border: none; padding: 6px 12px; border-radius: 5px; cursor: pointer; font-size: 12px; font-weight: bold; }
        .admin-only-label { background: #fee2e2; color: #991b1b; padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 800; }
    </style>
    <script>
        function toggleAudit(id) {
            var e = document.getElementById(id);
            e.style.display = (e.style.display === 'table-row') ? 'none' : 'table-row';
        }
    </script>
</head>
<body>

<?php if (defined('IS_DEMO') && IS_DEMO): ?>
    <div class="demo-bar">🚀 DEMO MODE: Viewing Payroll for Org #<?= $current_org_id ?></div>
<?php endif; ?>

<?= generateMenu($pdo); ?>

<div class="hub-wrapper">
    <div class="hub-header">
        <div>
            <h2 style="margin:0; font-weight: 800;">Payroll Hub <span class="admin-only-label">FINANCIAL ADMIN</span></h2>
            <p style="color:#64748b; margin:0; font-size:14px;">Reviewing period: <?= date('M d', strtotime($start_date)) ?> - <?= date('M d', strtotime($end_date)) ?></p>
        </div>
        <div class="view-tabs">
            <a href="?view=audit&start_date=<?=$start_date?>&end_date=<?=$end_date?>&site_id=<?=$sel_site?>" class="tab-link <?= $view=='audit'?'active':'' ?>"><i class="fa fa-camera"></i> Audit</a>
            <a href="?view=ledger&start_date=<?=$start_date?>&end_date=<?=$end_date?>&site_id=<?=$sel_site?>" class="tab-link <?= $view=='ledger'?'active':'' ?>"><i class="fa fa-file-invoice"></i> Ledger</a>
            <a href="?view=summary&start_date=<?=$start_date?>&end_date=<?=$end_date?>&site_id=<?=$sel_site?>" class="tab-link <?= $view=='summary'?'active':'' ?>"><i class="fa fa-chart-pie"></i> Summary</a>
        </div>
    </div>

    <form method="GET" class="filter-card">
        <input type="hidden" name="view" value="<?= $view ?>">
        <div class="filter-group">
            <label>From</label>
            <input type="date" name="start_date" value="<?= $start_date ?>">
        </div>
        <div class="filter-group">
            <label>To</label>
            <input type="date" name="end_date" value="<?= $end_date ?>">
        </div>
        <div class="filter-group" style="flex-grow:1;">
            <label>Site Location</label>
            <select name="site_id">
                <option value="all">All Locations</option>
                <?php foreach($sites as $s): ?>
                    <option value="<?= $s['SId'] ?>" <?= $sel_site == $s['SId'] ? 'selected' : '' ?>><?= $s['nick_name'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" style="background:#0f172a; color:white; border:none; padding:10px 25px; border-radius:8px; cursor:pointer; font-weight:bold;">Update View</button>
    </form>

    <div class="main-card">
        <table>
            <thead>
                <?php if($view == 'audit'): ?>
                    <tr><th>Date</th><th>Employee</th><th>Site</th><th>Net Hrs</th><th>Total Liability</th><th>Action</th></tr>
                <?php elseif($view == 'ledger'): ?>
                    <tr><th>Employee</th><th>Date</th><th>Gross</th><th>Vacation</th><th>ER Taxes</th><th>Total Cost</th></tr>
                <?php else: ?>
                    <tr><th>Employee Name</th><th>Total Hours</th><th>Gross Total</th><th>Total Loaded Liability</th></tr>
                <?php endif; ?>
            </thead>
            <tbody>
                <?php 
                $grand_totals = []; 
                foreach($data as $r): 
                    $in = strtotime($r['clock_in']);
                    $out = ($r['clock_out'] && $r['clock_out'] != '0000-00-00 00:00:00') ? strtotime($r['clock_out']) : time();
                    
                    // Calculation logic
                    $net_hrs = ($out - $in) / 3600;
                    $rate = pe_profile_hourly_rate($pdo, (int)$r['user_id']);
                    $gross = $net_hrs * $rate;
                    $vac = $gross * $VAC_RATE;
                    $taxes = ($gross * $CPP_RATE) + ($gross * $EI_RATE * $EI_ER_MULT) + ($gross * $WCB_RATE);
                    $total_liability = $gross + $vac + $taxes;

                    $eid = $r['user_id'];
                    if(!isset($grand_totals[$eid])) { $grand_totals[$eid] = ['name'=>$r['Name'], 'hrs'=>0, 'gross'=>0, 'total'=>0]; }
                    $grand_totals[$eid]['hrs'] += $net_hrs;
                    $grand_totals[$eid]['gross'] += $gross;
                    $grand_totals[$eid]['total'] += $total_liability;
                ?>
                    
                    <?php if($view == 'audit'): ?>
                        <tr>
                            <td><?= date('M d', $in) ?></td>
                            <td><strong><?= htmlspecialchars($r['Name'] ?? 'Unknown Staff') ?></strong></td>
                            <td><span style="font-size:11px; font-weight:bold; background:#f1f5f9; padding:3px 8px; border-radius:4px;"><?= $r['nick_name'] ?></span></td>
                            <td><?= number_format($net_hrs, 2) ?>h</td>
                            <td class="money" style="color:#10b981;">$<?= number_format($total_liability, 2) ?></td>
                            <td><button class="btn-verify" onclick="toggleAudit('row-<?= $r['id'] ?>')">Verify</button></td>
                        </tr>
                        <tr id="row-<?= $r['id'] ?>" class="audit-details">
                            <td colspan="6">
                                <div style="display:flex; gap:30px;">
                                    <img src="<?= $r['selfie_in'] ?>" style="width:100px; border-radius:8px; border:1px solid #ddd;" onerror="this.src='https://via.placeholder.com/100?text=No+Photo'">
                                    <div>
                                        <label style="font-size:10px; font-weight:bold; color:#94a3b8;">CALCULATION BREAKDOWN</label>
                                        <div style="font-size:12px;">
                                            Rate: $<?= number_format($rate, 2) ?> | Gross: $<?= number_format($gross, 2) ?><br>
                                            Employer Burden (CPP/EI/WCB): $<?= number_format($taxes, 2) ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php elseif($view == 'ledger'): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($r['Name'] ?? 'Unknown Staff') ?></strong></td>
                            <td><?= date('M d', $in) ?></td>
                            <td class="money">$<?= number_format($gross, 2) ?></td>
                            <td class="money">$<?= number_format($vac, 2) ?></td>
                            <td class="money">$<?= number_format($taxes, 2) ?></td>
                            <td class="money" style="color:#10b981;">$<?= number_format($total_liability, 2) ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php if($view == 'summary'): ?>
                    <?php foreach($grand_totals as $sum): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($sum['name'] ?? 'Unknown') ?></strong></td>
                            <td><?= number_format($sum['hrs'], 2) ?> hrs</td>
                            <td class="money">$<?= number_format($sum['gross'], 2) ?></td>
                            <td class="money" style="color:#10b981;">$<?= number_format($sum['total'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if(empty($data)): ?>
                    <tr><td colspan="6" style="text-align:center; padding:50px; color:#94a3b8;">No payroll records found for this selection.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
<?php ob_end_flush(); ?>