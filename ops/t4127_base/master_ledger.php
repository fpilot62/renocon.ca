<?php
/** Path: /master_ledger.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. DATABASE & MENU (Using db.php only)
require_once 'db.php'; 
require_once 'engine/menu_generator.php';

// 2. SECURITY CHECK
if (!isset($_SESSION['user_id'])) { 
    header("Location: login_kiosk.php?err=access_denied"); 
    exit; 
}

// 3. DEFINE CONSTANTS (Matching your other payroll files)
$VAC_RATE = 0.04; 
$CPP_RATE = 0.0595; 
$EI_RATE  = 0.0163; 
$WCB_RATE = 0.018;

// 4. FETCH LEDGER DATA
$selected_site = $_GET['site_filter'] ?? '';

// Role filter safety (ensure only authorized staff data is shown)
$r_filter = isset($_SESSION['user_level']) && $_SESSION['user_level'] <= 10 ? "1=1" : "u.user_level > 15";

try {
    // We aggregate from payroll_records (finalized data)
    $sql = "SELECT p.user_id, u.Name, SUM(p.total_hours) as paid_hrs, SUM(p.gross_pay) as gross 
            FROM payroll_records p
            JOIN users u ON p.user_id = u.id
            WHERE $r_filter"; 

    if (!empty($selected_site)) { 
        $sql .= " AND p.site_id = :sid"; 
    }
    
    $sql .= " GROUP BY p.user_id, u.Name ORDER BY u.Name ASC";

    $ledger_stmt = $pdo->prepare($sql);
    
    if (!empty($selected_site)) { 
        $ledger_stmt->execute(['sid' => $selected_site]); 
    } else { 
        $ledger_stmt->execute(); 
    }
    
    $ledger_data = $ledger_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = $e->getMessage();
    $ledger_data = [];
}

// Fetch sites for a filter dropdown
if (!function_exists('sip_org_site_fence')) { require_once __DIR__ . '/engine/site_status_lib.php'; }
$__fence = sip_org_site_fence($pdo, 'sip_org_id');
$is_super = $__fence['is_super']; $selected_org = $__fence['selected_org']; $orgs = $__fence['orgs']; $sites = $__fence['sites'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Master Ledger | SIP System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; padding: 0; color: #1e293b; }
        .ledger-container { max-width: 1300px; margin: 30px auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
        
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; }
        .filter-bar { display: flex; gap: 10px; margin-bottom: 20px; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #0f172a; color: white; padding: 15px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; }
        td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        
        .money { font-family: 'JetBrains Mono', monospace; font-weight: bold; color: #0f172a; }
        .total-liability { color: #10b981; font-size: 15px; }
        
        tr:hover td { background: #f8fafc; }
        select { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; }
        .btn-filter { background: #0f172a; color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; }
    </style>
</head>
<body>

<?php echo generateMenu($pdo); ?>

<div class="ledger-container">
    <div class="header-flex">
        <h2 style="margin:0;">Master Payroll Ledger</h2>
        <form method="GET" class="filter-bar">
            <select name="site_filter">
                <option value="">All Sites</option>
                <?php foreach($sites as $s): ?>
                    <option value="<?= $s['SId'] ?>" <?= $selected_site == $s['SId'] ? 'selected' : '' ?>><?= $s['nick_name'] ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-filter">Filter</button>
        </form>
    </div>
    
    <?php if (isset($error)): ?>
        <div style="color: #be123c; padding: 15px; background: #fff1f2; border-radius: 8px; margin-bottom: 20px; border: 1px solid #fecaca;">
            <i class="fa fa-exclamation-triangle"></i> Error: <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Total Hours</th>
                <th>Gross Wages</th>
                <th>Vacation (4%)</th>
                <th>ER Taxes (CPP/EI/WCB)</th>
                <th>Total Liability</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($ledger_data)): ?>
                <tr><td colspan="6" style="text-align:center; padding: 50px; color: #94a3b8;">No finalized payroll records found.</td></tr>
            <?php else: ?>
                <?php 
                $g_hours = 0; $g_gross = 0; $g_total = 0;
                foreach ($ledger_data as $row): 
                    $gross = floatval($row['gross']);
                    $vac   = $gross * $VAC_RATE;
                    $taxes = ($gross * $CPP_RATE) + ($gross * $EI_RATE * 1.4) + ($gross * $WCB_RATE);
                    $total = $gross + $vac + $taxes;
                    
                    $g_hours += $row['paid_hrs'];
                    $g_gross += $gross;
                    $g_total += $total;
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['Name']) ?></strong></td>
                    <td><?= number_format($row['paid_hrs'], 2) ?></td>
                    <td class="money">$<?= number_format($gross, 2) ?></td>
                    <td class="money">$<?= number_format($vac, 2) ?></td>
                    <td class="money">$<?= number_format($taxes, 2) ?></td>
                    <td class="money total-liability">$<?= number_format($total, 2) ?></td>
                </tr>
                <?php endforeach; ?>
                
                <tr style="background: #f8fafc; font-weight: bold; border-top: 2px solid #0f172a;">
                    <td>GRAND TOTAL</td>
                    <td><?= number_format($g_hours, 2) ?></td>
                    <td class="money">$<?= number_format($g_gross, 2) ?></td>
                    <td colspan="2"></td>
                    <td class="money total-liability" style="font-size: 18px;">$<?= number_format($g_total, 2) ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>