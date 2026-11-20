<?php 
ob_start();
/** Path: /finalize_payroll.php 
 * FIXED: Strict Org_ID matching on JOIN to prevent cross-org leaks.
 * FIXED: Null safety for Employee Names.
 */
require_once 'db.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
require_once 'engine/menu_generator.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. SECURITY GATE
$current_org_id = (int)($_SESSION['org_id'] ?? 0);
$user_lvl       = (int)($_SESSION['user_level'] ?? 99);

if (!isset($_SESSION['user_id']) || $user_lvl >= 30) { 
    header("Location: kiosk.php?err=admin_only_access"); 
    exit; 
}

// --- 2. SET EMPLOYER BURDEN RATES ---
$vacation_rate = 0.04;   
$wsib_rate     = 0.025;  
$cpp_ei_rate   = 0.075;  
$burden_multiplier = 1 + ($vacation_rate + $wsib_rate + $cpp_ei_rate);

try {
    /**
     * JOIN FIX: We now ensure the USER belongs to the same ORG as the attendance record.
     * This prevents Gurveer (Org 1) from appearing in Org 10083's ledger.
     */
    $sql = "SELECT a.*, u.Name, u.hourly_rate 
            FROM attendance a
            INNER JOIN users u ON a.user_id = u.id AND u.org_id = a.org_id
            WHERE a.org_id = :oid AND a.is_finalized = 0
            ORDER BY a.clock_in DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['oid' => $current_org_id]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { 
    die("Database Error: " . $e->getMessage()); 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll Ledger | crewPulse</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; padding-top: 130px; color: #1e293b; }
        .demo-bar { background: #e67e22; color: white; text-align: center; padding: 10px; font-weight: bold; position: fixed; top: 0; width: 100%; z-index: 10002; }
        .content-container { padding: 30px 40px; max-width: 1500px; margin: auto; }
        h2 { font-weight: 800; color: #0f172a; margin-bottom: 25px; border-left: 5px solid #0097a7; padding-left: 15px; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        th { background: #1e293b; color: #ffffff; padding: 15px; text-align: left; font-size: 11px; text-transform: uppercase; }
        td { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #334155; }
        .input-hrs { width: 80px; padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px; text-align: center; font-weight: bold; }
        .btn-finalize { background: #0097a7; color: white; border: none; padding: 8px 18px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .neg-val { color: #ef4444; font-weight: bold; }
        .pos-val { color: #10b981; font-weight: bold; }
        .money { font-family: 'Monaco', monospace; }
        .admin-badge { background: #fee2e2; color: #991b1b; padding: 4px 8px; border-radius: 6px; font-size: 10px; font-weight: 800; }
    </style>
</head>
<body>

<?php if (defined('IS_DEMO') && IS_DEMO): ?>
    <div class="demo-bar">🚀 DEMO MODE: Payroll Preview for Org #<?= $current_org_id ?></div>
<?php endif; ?>

<?= generateMenu($pdo); ?>

<div class="content-container">
    <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>Payroll Detailed Ledger</h2>
        <span class="admin-badge"><i class="fa fa-lock"></i> ADMIN ACCESS ONLY (Level <?= $user_lvl ?>)</span>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Employee</th>
                <th>Actual Hrs</th>
                <th>Allocate Hrs</th>
                <th>Base Wage</th>
                <th>Loaded Cost</th>
                <th>Total (Inc. Burden)</th>
                <th>Balance</th>
                <th style="text-align:right;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($records as $row): 
                $db_hrs   = (float)($row['total_hours'] ?? 0);
                $as_of = !empty($row['clock_in']) ? date('Y-m-d', strtotime($row['clock_in'])) : date('Y-m-d');
                $base_w   = pe_profile_hourly_rate($pdo, (int)$row['user_id'], $as_of); 
                $alloc    = $db_hrs; 
                $loaded_w = $base_w * $burden_multiplier;
                $total_c  = $alloc * $loaded_w;
            ?>
            <tr class="payroll-row">
                <td><strong><?= date('M d', strtotime($row['clock_in'])) ?></strong></td>
                <td><strong style="color: #2563eb;"><?= htmlspecialchars($row['Name'] ?? 'Unknown Staff') ?></strong></td>
                <td class="orig-hrs" data-value="<?= $db_hrs ?>"><?= number_format($db_hrs, 2) ?></td>
                <td>
                    <input type="number" step="0.01" class="input-hrs" 
                           value="<?= number_format($alloc, 2) ?>" 
                           data-base-wage="<?= $base_w ?>"
                           data-mult="<?= $burden_multiplier ?>"
                           oninput="updatePayrollCalc(this)">
                </td>
                <td class="money">$<?= number_format($base_w, 2) ?></td>
                <td class="loaded-wage-cell money">$<?= number_format($loaded_w, 2) ?></td>
                <td class="total-cost-cell money <?= $total_c < 0 ? 'neg-val' : 'pos-val' ?>">
                    $<?= number_format($total_c, 2) ?>
                </td>
                <td class="balance-cell">0.00</td>
                <td style="text-align: right;">
                    <form action="process_finalize.php" method="POST" style="margin:0;">
                        <input type="hidden" name="attendance_id" value="<?= $row['id'] ?>">
                        <input type="hidden" name="final_hours" class="hidden-alloc" value="<?= $alloc ?>">
                        <button type="submit" class="btn-finalize" <?= (defined('IS_DEMO') && IS_DEMO) ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : '' ?>>
                            Finalize
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($records)): ?>
                <tr><td colspan="9" style="text-align:center; padding:50px; color:#64748b;">No pending payroll records found for this Org.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function updatePayrollCalc(input) {
    const row = input.closest('.payroll-row');
    const originalHrs = parseFloat(row.querySelector('.orig-hrs').dataset.value) || 0;
    const allocatedHrs = parseFloat(input.value) || 0;
    const baseWage = parseFloat(input.dataset.baseWage) || 0;
    const multiplier = parseFloat(input.dataset.mult) || 1;

    const loadedWage = baseWage * multiplier;
    const totalCost = allocatedHrs * loadedWage;
    const balance = originalHrs - allocatedHrs;

    row.querySelector('.loaded-wage-cell').textContent = '$' + loadedWage.toFixed(2);
    const totalCell = row.querySelector('.total-cost-cell');
    totalCell.textContent = '$' + totalCost.toFixed(2);
    totalCell.className = 'total-cost-cell money ' + (totalCost < 0 ? 'neg-val' : 'pos-val');

    const balanceCell = row.querySelector('.balance-cell');
    balanceCell.textContent = balance.toFixed(2);
    balanceCell.className = 'balance-cell ' + (balance !== 0 ? 'neg-val' : '');

    row.querySelector('.hidden-alloc').value = allocatedHrs;
}
</script>
</body>
</html>
<?php ob_end_flush(); ?>