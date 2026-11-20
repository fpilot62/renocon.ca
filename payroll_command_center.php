<?php
/** Path: /payroll_command_center.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. DATABASE & MENU (Using db.php as the single source of truth)
require_once 'db.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php'; 
require_once 'engine/menu_generator.php';

// 2. SECURITY CHECK
if (!isset($_SESSION['user_id'])) { 
    header("Location: login_kiosk.php?err=session_expired"); 
    exit; 
}

// 3. SETTINGS & RATES
$cpp_r = 0.0595; 
$ei_r = 0.0163; 
$ei_er_m = 1.4; 
$wcb_r = 0.018; 
$vac_r = 0.04;

// 4. FETCH DATA
$view = $_GET['view'] ?? 'audit'; 
$sql = "SELECT a.*, u.Name, u.official_rate, u.vac_pay_percent, s.nick_name 
        FROM attendance a 
        LEFT JOIN users u ON a.user_id = u.id 
        LEFT JOIN sites s ON a.site_id = s.SId 
        ORDER BY a.clock_in DESC";
$data = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll Command Center</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #3b82f6; --dark: #0f172a; --bg: #f1f5f9; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); margin: 0; padding: 0; color: #1e293b; }
        
        .wrapper { max-width: 1400px; margin: 25px auto; padding: 0 20px; }
        
        /* Header & Toggles */
        .flex-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .view-toggle { background: white; padding: 5px; border-radius: 10px; display: inline-flex; gap: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
        .toggle-btn { padding: 10px 18px; border-radius: 8px; text-decoration: none; color: #64748b; font-weight: 700; font-size: 13px; transition: 0.2s; }
        .active { background: var(--dark); color: white; shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        
        /* Main Table Card */
        .main-card { background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); overflow: hidden; border: 1px solid #e2e8f0; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; padding: 15px; text-align: left; font-size: 11px; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #edf2f7; letter-spacing: 0.05em; }
        td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 14px; vertical-align: middle; }
        
        /* Audit Expansion */
        .audit-details { display: none; background: #f9fafb; padding: 25px; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; }
        .money { font-family: 'JetBrains Mono', monospace; font-weight: bold; }
        .btn-verify { background: var(--primary); color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 12px; }
        
        tr:hover td { background: #fcfcfd; }
        .site-badge { background: #e2e8f0; padding: 4px 8px; border-radius: 5px; font-size: 11px; font-weight: 800; color: #475569; }
    </style>
    <script>
        function toggleAudit(id) {
            var e = document.getElementById(id);
            // Toggle between none and table-row (since it is a TR element)
            e.style.display = (e.style.display === 'table-row') ? 'none' : 'table-row';
        }
    </script>
</head>
<body>

    <?php echo generateMenu($pdo); ?>

    <div class="wrapper">
        <div class="flex-header">
            <h2 style="margin:0; font-weight: 800; letter-spacing: -0.5px;">Payroll Command Center</h2>
            <div class="view-toggle">
                <a href="?view=audit" class="toggle-btn <?= $view=='audit'?'active':'' ?>"><i class="fa fa-search"></i> Verify Audit</a>
                <a href="?view=ledger" class="toggle-btn <?= $view=='ledger'?'active':'' ?>"><i class="fa fa-list-ul"></i> Financial Ledger</a>
            </div>
        </div>

        <div class="main-card">
            <table>
                <thead>
                    <?php if($view == 'audit'): ?>
                        <tr><th>Date</th><th>Employee</th><th>Site</th><th>Net Hrs</th><th>Total Liability</th><th>Action</th></tr>
                    <?php else: ?>
                        <tr><th>Employee</th><th>Date</th><th>Gross Wages</th><th>Vacation</th><th>ER Taxes</th><th>Final Cost</th></tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                    <?php foreach($data as $r): 
                        // Core Calculations
                        $in = strtotime($r['clock_in']);
                        $out = ($r['clock_out'] && $r['clock_out'] != '0000-00-00 00:00:00') ? strtotime($r['clock_out']) : time();
                        
                        // Calculate Breaks
                        $b_stmt = $pdo->prepare("SELECT * FROM breaks WHERE shift_id = ?");
                        $b_stmt->execute([$r['id']]);
                        $total_b_sec = 0; $break_log = "";
                        foreach($b_stmt->fetchAll() as $b) {
                            $be = ($b['break_end']) ? strtotime($b['break_end']) : time();
                            $total_b_sec += ($be - strtotime($b['break_start']));
                            $break_log .= "• " . date('g:i A', strtotime($b['break_start'])) . " - " . ($b['break_end'] ? date('g:i A', strtotime($b['break_end'])) : 'Active') . "<br>";
                        }

                        $net_hrs = ($out - $in - $total_b_sec) / 3600;
                        $gross = $net_hrs * pe_profile_hourly_rate($pdo, (int)($r['user_id'] ?? 0));
                        $vac = $gross * (($r['vac_pay_percent'] ?? 4)/100);
                        $taxes = ($gross * $cpp_r) + ($gross * $ei_r * $ei_er_m) + ($gross * $wcb_r);
                        $total = $gross + $vac + $taxes;
                    ?>
                        <?php if($view == 'audit'): ?>
                            <tr>
                                <td><strong><?= date('M d', $in) ?></strong></td>
                                <td><strong><?= htmlspecialchars($r['Name']) ?></strong></td>
                                <td><span class="site-badge"><?= $r['nick_name'] ?: 'Unassigned' ?></span></td>
                                <td><?= number_format($net_hrs, 2) ?> hrs</td>
                                <td class="money" style="color:#10b981;">$<?= number_format($total, 2) ?></td>
                                <td><button class="btn-verify" onclick="toggleAudit('audit-<?= $r['id'] ?>')">Verify</button></td>
                            </tr>
                            <tr id="audit-<?= $r['id'] ?>" class="audit-details">
                                <td colspan="6">
                                    <div style="display:flex; gap:50px; align-items: flex-start;">
                                        <div>
                                            <label style="font-size:10px; font-weight:800; color:#94a3b8; display:block; margin-bottom:5px;">CLOCK-IN SELFIE</label>
                                            <img src="<?= $r['selfie_in'] ?>" onerror="this.src='https://via.placeholder.com/100?text=No+Photo'" style="width:120px; border-radius:8px; border: 1px solid #e2e8f0;">
                                        </div>
                                        <div>
                                            <label style="font-size:10px; font-weight:800; color:#94a3b8; display:block; margin-bottom:5px;">BREAK HISTORY</label>
                                            <div style="font-size:12px; line-height:1.8; color:#475569;"><?= $break_log ?: 'No breaks recorded' ?></div>
                                        </div>
                                        <div style="background: white; padding: 15px; border-radius: 10px; border: 1px solid #e2e8f0; min-width: 220px;">
                                            <label style="font-size:10px; font-weight:800; color:#94a3b8; display:block; margin-bottom:10px;">COST ANALYSIS</label>
                                            <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:5px;"><span>Base Gross:</span><span class="money">$<?= number_format($gross, 2) ?></span></div>
                                            <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:5px;"><span>Vacation Accrual:</span><span class="money">$<?= number_format($vac, 2) ?></span></div>
                                            <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:10px;"><span>Employer Taxes:</span><span class="money">$<?= number_format($taxes, 2) ?></span></div>
                                            <div style="display:flex; justify-content:space-between; font-size:13px; font-weight:bold; border-top:1px solid #eee; padding-top:8px; color:#0f172a;"><span>Total Burden:</span><span>$<?= number_format($total, 2) ?></span></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($r['Name']) ?></strong></td>
                                <td><?= date('M d', $in) ?></td>
                                <td class="money">$<?= number_format($gross, 2) ?></td>
                                <td class="money">$<?= number_format($vac, 2) ?></td>
                                <td class="money">$<?= number_format($taxes, 2) ?></td>
                                <td class="money" style="color:#10b981;">$<?= number_format($total, 2) ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>