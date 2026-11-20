<?php
/** * Path: /finalize_shift.php
 * STATUS: Patched so the split rows actually POST.
 * CHANGE LOG (this revision):
 *   - Added name= to every hours/rate input (they had none, so they never submitted).
 *     Naming scheme the saver (save_split_payroll.php) reads:
 *        Row 0 (primary/transit): hours_0 / rate_0          + hidden kind_0
 *        Overtime row:            hours_ot / rate_ot
 *        Baseline row:            hours_baseline / rate_baseline
 *        Custom split rows:       hours_dynamic_<id> / rate_dynamic_<id>
 *     Checkboxes/labels already had names (route_finalized_* / label_*).
 *   - No layout or calculation changes; the JS still drives the on-screen totals.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. SAFE SESSION INITIALIZATION
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// 2. AUTHENTICATION & MANAGER AUTHORIZATION WALL
if (!isset($_SESSION['user_level']) || !isset($_SESSION['org_id'])) {
    header("Location: /login_kiosk.php?error=unauthorized");
    exit();
}

$user_lvl = (int)$_SESSION['user_level'];
if ($user_lvl > 20) { // Manager clearance threshold parameters rule check
    die("<div style='padding:20px; font-family:sans-serif; color:#991b1b; background:#fee2e2; border-radius:6px; font-weight:bold;'>Security Access Denied: Your account role does not have authorization to finalize payroll entries.</div>");
}

// 3. SECURE CORE DIRECTORY CONNECTIONS
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
if (is_file(__DIR__ . '/break_waiver_finalize_guard.php')) {
    require_once __DIR__ . '/break_waiver_finalize_guard.php';
}
if (file_exists('engine/menu_generator.php')) {
    require_once 'engine/menu_generator.php';
}

$attendance_id = (int)($_GET['id'] ?? 0);
$corridor_route = trim((string)($_GET['corridor_route'] ?? ''));

if (!$attendance_id) {
    die("<div style='padding:20px; font-family:sans-serif; color:#991b1b; background:#fee2e2; border-radius:6px; font-weight:bold;'>Fatal Error: Invalid or Missing Attendance Record ID Link Reference.</div>");
}

try {
    // 4. LOAD OPERATIONAL GLOBAL SETTINGS
    $settings_query = $pdo->query("SELECT * FROM payroll_settings LIMIT 1");
    $settings = $settings_query ? $settings_query->fetch(PDO::FETCH_ASSOC) : [];

    $fed_rate = isset($settings['fed_tax_rate']) ? (float)$settings['fed_tax_rate'] : 0.15;
    $cpp_rate = isset($settings['cpp_rate']) ? (float)$settings['cpp_rate'] : 0.0595;
    $ei_rate  = isset($settings['ei_rate']) ? (float)$settings['ei_rate'] : 0.0163;
    $wcb_rate = isset($settings['wcb_rate']) ? (float)$settings['wcb_rate'] : 0.02;
    $ei_mult  = isset($settings['ei_employer_factor']) ? (float)$settings['ei_employer_factor'] : 1.4;

    // 5. EXTRACT SHIFT DATA
    $sql = "SELECT a.id, a.user_id, a.clock_in, a.clock_out, a.approved_hours, a.status, a.transfer_status,
                   u.Name, u.hourly_rate, u.official_rate, u.transit_rate, u.ei_exempt, u.employee_type,
                   s.Timezone
            FROM attendance a 
            LEFT JOIN users u ON a.user_id = u.id 
            LEFT JOIN sites s ON a.site_id = s.Sid
            WHERE a.id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$attendance_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$data) {
        die("<div style='padding:20px; font-family:sans-serif; color:#991b1b; background:#fee2e2; border-radius:6px; font-weight:bold;'>Shift Error: Targeted record entry not found inside active table index.</div>");
    }

    // 6. ENFORCE LOCALIZED TIMEZONE SYNC
    $resolved_shift_timezone = !empty(trim($data['Timezone'] ?? '')) ? trim($data['Timezone']) : 'America/Edmonton';
    date_default_timezone_set($resolved_shift_timezone);

    // 7. TIME DATA CALCULATION COMPILING
    if (isset($data['approved_hours']) && (float)$data['approved_hours'] > 0) {
        $net_audit_hrs = (float)$data['approved_hours'];
    } else {
        $seconds = 0;
        if (!empty($data['clock_in']) && !empty($data['clock_out'])) {
            $seconds = strtotime($data['clock_out']) - strtotime($data['clock_in']);
            if ($seconds < 0) { $seconds += 86400; } 
        }
        
        $break_stmt = $pdo->prepare("SELECT SUM(ABS(TIMESTAMPDIFF(SECOND, break_start, break_end))) / 3600 FROM breaks WHERE shift_id = ?");
        $break_stmt->execute([$attendance_id]);
        $break_val = $break_stmt->fetchColumn();
        
        $net_audit_hrs = max(0, ($seconds / 3600) - (float)($break_val ?? 0));
    }

    // 8. TRANSIT MODIFIER EVALUATION SWITCH
    $status_val = trim((string)($data['status'] ?? '0'));
    $t_stat_val = strtolower(trim((string)($data['transfer_status'] ?? '')));
    $is_transit = ($status_val === '5' || $t_stat_val === 'travel' || strpos($t_stat_val, '→') !== false || !empty($corridor_route));

    $work_as_of = !empty($data['clock_in']) ? date('Y-m-d', strtotime($data['clock_in'])) : date('Y-m-d');
    if ($is_transit) {
        $worker_base_rate = pe_profile_pay_rate($pdo, (int)$data['user_id'], 'travel', $work_as_of);
        $default_ledger_label = !empty($corridor_route) ? "Travel Route: " . $corridor_route : "Transit Corridor Drive Time Line";
    } else {
        $worker_base_rate = pe_profile_hourly_rate($pdo, (int)$data['user_id'], $work_as_of);
        $default_ledger_label = "Regular Core Payroll Line";
    }

    $skip_cpp = function_exists('pe_user_skips_cpp_ei') && pe_user_skips_cpp_ei($pdo, (int)$data['user_id']);
    $skip_ei = $skip_cpp || ((int)($data['ei_exempt'] ?? 0) === 1);

} catch (PDOException $e) { 
    die("<div style='padding:20px; font-family:monospace; color:#991b1b; background:#fee2e2;'>Database Core Exception Error: " . htmlspecialchars($e->getMessage()) . "</div>"); 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Finalize Shift Profile Engine</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f1f5f9; padding-top: 110px; color: #334155; margin: 0; font-size: 13px; }
        .wrapper { max-width: 1200px; margin: 0 auto 50px auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        h2 { margin-top: 0; color: #0f172a; font-size: 18px; font-weight: bold; border-bottom: 2px solid #e2e8f0; padding-bottom: 12px; }
        .control-panel { background: #f8fafc; border: 1px solid #cbd5e1; padding: 15px; border-radius: 6px; margin-bottom: 20px; display: flex; gap: 20px; align-items: center; justify-content: space-between; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; background: white; }
        td, th { border: 1px solid #cbd5e1; padding: 12px; vertical-align: middle; }
        th { background: #475569; color: white; font-size: 11px; text-transform: uppercase; text-align: left; letter-spacing: 0.5px; }
        .text-input { width: 92%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-weight: bold; color: #1e293b; font-size: 12px; }
        .calc-input { width: 85%; padding: 8px; font-weight: bold; text-align: right; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; }
        .tax-container { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-top: 30px; }
        .tax-box { background: #f8fafc; padding: 20px; border-radius: 6px; border: 1px solid #e2e8f0; }
        .tax-box h4 { margin: 0 0 15px; color: #0f172a; border-bottom: 2px solid #cbd5e1; padding-bottom: 10px; font-size: 13px; text-transform: uppercase; }
        .row-item { display: flex; justify-content: space-between; margin-bottom: 10px; font-family: monospace; font-size: 13px; font-weight: bold; }
        .total-row-item { border-top: 2px dashed #cbd5e1; padding-top: 12px; margin-top: 12px; font-weight: 900; font-size: 15px; }
        .btn-save { margin-top: 35px; padding: 14px; background: #10b981; color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; width: 100%; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px; }
        .btn-save:hover { background: #059669; }
        .route-checkbox { width: 18px; height: 18px; cursor: pointer; }
    </style>
</head>
<body>

<?php if (function_exists('generateMenu')) echo generateMenu($pdo); ?>

<div class="wrapper shadow-sm">
    <h2><i class="fa fa-calculator text-primary mr-1"></i> Finalize Shift Execution Desk: <span style="color:#2563eb;"><?= htmlspecialchars($data['Name'] ?? 'Unknown Staff Profile') ?></span></h2>
    
    <div class="control-panel">
        <label style="font-weight: bold; font-size: 13px; display: flex; align-items: center; gap: 10px; cursor: pointer; color: #1e293b; margin-bottom:0;">
            <input type="checkbox" id="auto_calc_overtime" onchange="toggleSpreadsheetFormulaMode()" style="width:20px; height:20px; cursor:pointer;">
            <i class="fas fa-calculator text-primary"></i> Run Automated Spreadsheet Split Formulas (Cap Core at 80 Hrs & Auto Calculate 1.5× Overtime Premium)
        </label>
        <button type="button" id="add_split_btn" onclick="addNewBalanceRow()" class="btn btn-sm btn-purple text-white font-weight-bold p-2" style="background:#7c3aed;"><i class="fas fa-plus-circle"></i> Add Custom Balance Split</button>
    </div>

    <form action="save_split_payroll.php" method="POST">
        <input type="hidden" name="attendance_id" value="<?= $attendance_id ?>">
        <input type="hidden" name="kind_0" value="<?= $is_transit ? 'transit' : 'regular' ?>">
        <input type="hidden" id="cpp_rate" value="<?= $cpp_rate ?>">
        <input type="hidden" id="ei_rate" value="<?= $ei_rate ?>">
        <input type="hidden" id="fed_rate" value="<?= $fed_rate ?>">
        <input type="hidden" id="wcb_rate" value="<?= $wcb_rate ?>">
        <input type="hidden" id="ei_mult" value="<?= $ei_mult ?>">
        <input type="hidden" id="skip_cpp" value="<?= !empty($skip_cpp) ? '1' : '0' ?>">
        <input type="hidden" id="skip_ei" value="<?= !empty($skip_ei) ? '1' : '0' ?>">
        <input type="hidden" id="audit_total_val" value="<?= $net_audit_hrs ?>">
        <input type="hidden" id="base_hourly_rate" value="<?= $worker_base_rate ?>">

        <table id="payroll_split_table">
            <thead>
                <tr>
                    <th width="80" style="text-align: center;">Send To Finalized</th>
                    <th width="380">Pay Allocation Layer / Ledger Title Label Description</th>
                    <th width="150">Hours Volume</th>
                    <th width="150">Hourly Rate ($)</th>
                    <th width="180" style="text-align: right;">Calculated Gross Pay</th>
                    <th width="80" style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody id="split_table_body">
                <tr style="background:<?= $is_transit ? '#fffbeb' : '#f0fdf4' ?>;" class="payroll-row" id="tr_row_0">
                    <td align="center">
                        <input type="checkbox" name="route_finalized_0" value="1" class="route-checkbox" checked onchange="calculatePayrollMatrix()">
                    </td>
                    <td><input type="text" name="label_0" class="text-input" value="<?= htmlspecialchars($default_ledger_label) ?>" style="border-left: 3px solid <?= $is_transit ? '#d97706' : '#10b981' ?>;"></td>
                    <td><input type="number" step="any" name="hours_0" class="calc-input hours-field" value="<?= number_format($net_audit_hrs, 2, '.', '') ?>" oninput="calculatePayrollMatrix()"></td>
                    <td><input type="number" step="any" name="rate_0" class="calc-input rate-field" value="<?= number_format($worker_base_rate, 2, '.', '') ?>" oninput="calculatePayrollMatrix()"></td>
                    <td align="right" class="gross-display font-weight-bold text-success" style="font-size:14px;">$0.00</td>
                    <td align="center"><span class="badge badge-success"><?= $is_transit ? 'Transit' : 'Primary' ?></span></td>
                </tr>
                
                <tr style="background:#eff6ff; display:none;" class="payroll-row" id="tr_row_1_ot_layer">
                    <td align="center">
                        <input type="checkbox" name="route_finalized_ot" id="route_finalized_ot" value="1" class="route-checkbox" checked onchange="calculatePayrollMatrix()">
                    </td>
                    <td><input type="text" name="label_ot" class="text-input" value="Automated Overtime Bonus" style="border-left: 3px solid #3b82f6;"></td>
                    <td><input type="number" step="any" name="hours_ot" class="calc-input hours-field" id="ot_hrs_input_val" value="0.00" oninput="calculatePayrollMatrix()"></td>
                    <td><input type="number" step="any" name="rate_ot" class="calc-input rate-field" id="ot_rate_input_val" value="<?= number_format($worker_base_rate * 1.5, 2, '.', '') ?>" oninput="calculatePayrollMatrix()"></td>
                    <td align="right" class="gross-display font-weight-bold text-primary" style="font-size:14px;">$0.00</td>
                    <td align="center"><span class="badge badge-primary">Formula Run</span></td>
                </tr>

                <tr style="background:#fffbeb;" class="payroll-row" id="tr_baseline_row">
                    <td align="center">
                        <input type="checkbox" name="route_finalized_baseline" value="1" class="route-checkbox" onchange="calculatePayrollMatrix()">
                    </td>
                    <td><input type="text" name="label_baseline" class="text-input" value="Primary Bank / Balance Desk Remaining" style="border-left: 3px solid #d97706;"></td>
                    <td><input type="number" step="any" name="hours_baseline" class="calc-input hours-field" id="baseline_hrs_display" value="0.00" oninput="calculatePayrollMatrix()"></td>
                    <td><input type="number" step="any" name="rate_baseline" class="calc-input rate-field" value="<?= number_format($worker_base_rate, 2, '.', '') ?>" oninput="calculatePayrollMatrix()"></td>
                    <td align="right" class="gross-display font-weight-bold text-warning" style="font-size:14px;">$0.00</td>
                    <td align="center"><span class="badge badge-warning">Baseline</span></td>
                </tr>
            </tbody>
        </table>

        <div class="tax-container">
            <div class="tax-box">
                <h4>Employee Deductions Ledger Run <small class="text-muted" style="font-size:10px;">Tax Bracket Baseline: <?= $fed_rate*100 ?>%</small></h4>
                <div class="row-item"><span>Canada Pension Plan (CPP):</span> <span id="ee_cpp">-$0.00</span></div>
                <div class="row-item"><span>Employment Insurance (EI):</span> <span id="ee_ei">-$0.00</span></div>
                <div class="row-item"><span>Federal/Provincial Income Tax:</span> <span id="ee_fed" class="text-danger">-$0.00</span></div>
                <div class="row-item total-row-item"><span>NET WORKER TAKE-HOME PAYOUT:</span> <span id="net_pay" class="text-success">$0.00</span></div>
            </div>
            
            <div class="tax-box">
                <h4>Employer Corporate Burden Premiums</h4>
                <div class="row-item"><span>CPP Corporate Match Premium:</span> <span id="er_cpp">+$0.00</span></div>
                <div class="row-item"><span>EI Corporate Premium Match (<?= $ei_mult ?>x):</span> <span id="er_ei">+$0.00</span></div>
                <div class="row-item"><span>WCB BC Premium Assessment (<?= $wcb_rate*100 ?>%):</span> <span id="er_wcb">+$0.00</span></div>
                <div class="row-item total-row-item"><span>TOTAL TARGET LABOUR REVENUE COST:</span> <span id="total_cost" class="text-dark">$0.00</span></div>
            </div>
        </div>

        <button type="submit" class="btn-save"><i class="fas fa-check-circle"></i> Approve &amp; Lock Shift Financial Records</button>
    </form>
</div>

<script>
function toggleSpreadsheetFormulaMode() {
    const isAutoChecked = document.getElementById('auto_calc_overtime').checked;
    const totalHrs = parseFloat(document.getElementById('audit_total_val').value) || 0;
    const addBtn = document.getElementById('add_split_btn');
    const otRow = document.getElementById('tr_row_1_ot_layer');
    const tableBody = document.getElementById('split_table_body');

    if (isAutoChecked) {
        const dynamicRows = tableBody.querySelectorAll('.custom-dynamic-row');
        dynamicRows.forEach(row => row.remove());
        
        if(addBtn) addBtn.style.display = 'none'; 
        if(otRow) otRow.style.display = 'table-row';
        setOtRowSubmittable(true);  // formula on -> OT row participates

        const regHrs = Math.min(80, totalHrs);
        const hrsRow0 = document.querySelector('#tr_row_0 .hours-field');
        if(hrsRow0) hrsRow0.value = regHrs.toFixed(2);
    } else {
        if(otRow) otRow.style.display = 'none';
        if(addBtn) addBtn.style.display = 'inline-flex';
        setOtRowSubmittable(false); // formula off -> OT row must NOT submit (kills phantom overtime)
        
        const hrsRow0 = document.querySelector('#tr_row_0 .hours-field');
        if(hrsRow0) hrsRow0.value = totalHrs.toFixed(2);
    }
    calculatePayrollMatrix();
}

// Disabled form fields are not submitted. When the auto-split formula is off we disable
// (and zero) the hidden overtime row so it can never post a stale/phantom overtime line.
function setOtRowSubmittable(on) {
    const otCheck = document.getElementById('route_finalized_ot');
    const otHrs   = document.getElementById('ot_hrs_input_val');
    const otRate  = document.getElementById('ot_rate_input_val');
    const otLabel = document.querySelector('input[name="label_ot"]');
    [otCheck, otHrs, otRate, otLabel].forEach(function(el){ if(el){ el.disabled = !on; } });
    if (!on && otHrs) { otHrs.value = '0.00'; }
}

function addNewBalanceRow() {
    const tableBody = document.getElementById('split_table_body');
    const baseRate = parseFloat(document.getElementById('base_hourly_rate').value) || 0;
    const baselineRow = document.getElementById('tr_baseline_row');
    const uniqueId = Date.now();

    const newRow = document.createElement('tr');
    newRow.className = 'payroll-row custom-dynamic-row';
    newRow.id = 'tr_row_dynamic_' + uniqueId;
    newRow.style.background = '#f5f3ff';
    newRow.innerHTML = `
        <td align="center">
            <input type="checkbox" name="route_finalized_dynamic_${uniqueId}" value="1" class="route-checkbox" checked onchange="calculatePayrollMatrix()">
        </td>
        <td><input type="text" name="label_dynamic_${uniqueId}" class="text-input" value="Custom Balance Split" style="border-left: 3px solid #7c3aed;"></td>
        <td><input type="number" step="any" name="hours_dynamic_${uniqueId}" class="calc-input hours-field" value="0.00" oninput="calculatePayrollMatrix()"></td>
        <td><input type="number" step="any" name="rate_dynamic_${uniqueId}" class="calc-input rate-field" value="${baseRate.toFixed(2)}" oninput="calculatePayrollMatrix()"></td>
        <td align="right" class="gross-display font-weight-bold text-purple" style="font-size:14px;">$0.00</td>
        <td align="center"><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove(); calculatePayrollMatrix();"><i class="fas fa-trash-alt"></i></button></td>
    `;
    
    tableBody.insertBefore(newRow, baselineRow);
    calculatePayrollMatrix();
}

function calculatePayrollMatrix() {
    const totalHrs = parseFloat(document.getElementById('audit_total_val').value) || 0;
    const isAutoChecked = document.getElementById('auto_calc_overtime').checked;
    const baseRate = parseFloat(document.getElementById('base_hourly_rate').value) || 0;
    
    let accumulatedHoursSum = 0;
    let taxBasisGross = 0;

    const row0 = document.getElementById('tr_row_0');
    let h0 = parseFloat(row0.querySelector('.hours-field').value) || 0;
    let r0 = parseFloat(row0.querySelector('.rate-field').value) || baseRate;
    let g0 = h0 * r0;
    row0.querySelector('.gross-display').innerText = '$' + g0.toFixed(2);
    
    if (row0.querySelector('.route-checkbox').checked) { taxBasisGross += g0; }
    accumulatedHoursSum += h0;

    if (isAutoChecked) {
        const baselineOriginalBudgetMax = totalHrs * baseRate;
        const remainingCashBudgetPool = Math.max(0, baselineOriginalBudgetMax - g0);
        const otRow = document.getElementById('tr_row_1_ot_layer');
        let rOT = parseFloat(document.getElementById('ot_rate_input_val').value) || (baseRate * 1.5);
        
        let compressedOtHoursOutput = rOT > 0 ? (remainingCashBudgetPool / rOT) : 0;
        document.getElementById('ot_hrs_input_val').value = compressedOtHoursOutput.toFixed(2);
        
        let gOT = compressedOtHoursOutput * rOT;
        otRow.querySelector('.gross-display').innerText = '$' + gOT.toFixed(2);
        if (otRow.querySelector('.route-checkbox').checked) { taxBasisGross += gOT; }

        document.getElementById('baseline_hrs_display').value = "0.00";
        document.getElementById('tr_baseline_row').querySelector('.gross-display').innerText = "$0.00";
    } else {
        const dynamicRows = document.querySelectorAll('.custom-dynamic-row');
        dynamicRows.forEach(row => {
            let hDynamic = parseFloat(row.querySelector('.hours-field').value) || 0;
            let rDynamic = parseFloat(row.querySelector('.rate-field').value) || baseRate;
            let gDynamic = hDynamic * rDynamic;
            accumulatedHoursSum += hDynamic;
            row.querySelector('.gross-display').innerText = '$' + gDynamic.toFixed(2);
            if (row.querySelector('.route-checkbox').checked) { taxBasisGross += gDynamic; }
        });

        const remainingHoursRemainder = Math.max(0, totalHrs - accumulatedHoursSum);
        document.getElementById('baseline_hrs_display').value = remainingHoursRemainder.toFixed(2);
        
        const baselineRow = document.getElementById('tr_baseline_row');
        let rBaseline = parseFloat(baselineRow.querySelector('.rate-field').value) || baseRate;
        let gBaseline = remainingHoursRemainder * rBaseline;
        baselineRow.querySelector('.gross-display').innerText = '$' + gBaseline.toFixed(2);
        if (baselineRow.querySelector('.route-checkbox').checked) { taxBasisGross += gBaseline; }
    }

    let cpp = taxBasisGross * parseFloat(document.getElementById('cpp_rate').value);
    let ei  = taxBasisGross * parseFloat(document.getElementById('ei_rate').value);
    if (document.getElementById('skip_cpp') && document.getElementById('skip_cpp').value === '1') { cpp = 0; ei = 0; }
    else if (document.getElementById('skip_ei') && document.getElementById('skip_ei').value === '1') { ei = 0; }
    const fed = taxBasisGross * parseFloat(document.getElementById('fed_rate').value);
    const wcb = taxBasisGross * parseFloat(document.getElementById('wcb_rate').value);
    const eiEr = ei * parseFloat(document.getElementById('ei_mult').value);

    document.getElementById('ee_cpp').innerText = '-$' + cpp.toFixed(2);
    document.getElementById('ee_ei').innerText = '-$' + ei.toFixed(2);
    document.getElementById('ee_fed').innerText = '-$' + fed.toFixed(2);
    document.getElementById('net_pay').innerText = '$' + (taxBasisGross - cpp - ei - fed).toFixed(2);

    document.getElementById('er_cpp').innerText = '+$' + cpp.toFixed(2);
    document.getElementById('er_ei').innerText = '+$' + eiEr.toFixed(2);
    document.getElementById('er_wcb').innerText = '+$' + wcb.toFixed(2);
    document.getElementById('total_cost').innerText = '$' + (taxBasisGross + cpp + eiEr + wcb).toFixed(2);
}

window.onload = function() {
    setOtRowSubmittable(false); // formula defaults off -> OT row disabled, cannot submit
    calculatePayrollMatrix();
};
</script>
</body>
</html>