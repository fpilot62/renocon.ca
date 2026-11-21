<?php
/**
 * Path: /payroll_hours_report.php
 * PURPOSE: Specialized Hours Allocation Matrix Report mapped to org_name Schema
 * STATUS: 100% Operational
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';
require_once 'engine/menu_generator.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Extract user privilege contexts from your active architecture
$sess_user_level = isset($_SESSION['user_level']) ? (int)$_SESSION['user_level'] : 99;
if (!function_exists('ps_topbar_org') && is_file(__DIR__ . '/payroll_scope.php')) { require_once __DIR__ . '/payroll_scope.php'; }
$user_org_id     = function_exists('ps_topbar_org') && ps_topbar_org() > 0 ? ps_topbar_org() : (int)($_SESSION['org_id'] ?? $_SESSION['company_id'] ?? 1);

// Capture dynamic input values from the dashboard control header bar
$from_date       = $_GET['from_date'] ?? date('Y-m-01');
$to_date         = $_GET['to_date']   ?? date('Y-m-t');

// Enforce multi-tenant permission bounds around the org filter selection
if ($sess_user_level <= 10) {
    if (isset($_GET['org_id']) && $_GET['org_id'] !== '' && $_GET['org_id'] !== 'all') {
        $selected_org_id = (int)$_GET['org_id'];
    } else {
        $selected_org_id = (function_exists('ps_topbar_org') && ps_topbar_org() > 0) ? ps_topbar_org() : 'all';
    }
} else {
    $selected_org_id = $user_org_id;
}

// ✨ SCHEMATIC ALIGNMENT FIX: Pulls database values using your exact column key: org_name
$organizations = [];
try {
    $orgQuery = $pdo->query("SELECT id, org_name as name FROM organizations ORDER BY org_name ASC");
    $organizations = $orgQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Structural emergency fallback configuration
    try {
        $orgQuery = $pdo->query("SELECT DISTINCT org_id as id, CONCAT('Branch Office #', org_id) as name FROM attendance ORDER BY org_id ASC");
        $organizations = $orgQuery->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $ex) {
        $organizations = [['id' => 1, 'name' => 'Primary Tenant Workspace Row']];
    }
}

try {
    // Construct database filters matching your exact parameters
    $where = ["DATE(a.clock_in) BETWEEN :from_date AND :to_date", "a.status != '4'"];
    $params = [
        ':from_date' => $from_date,
        ':to_date'   => $to_date
    ];

    if ($selected_org_id !== 'all') {
        $where[] = "a.org_id = :org_id";
        $params[':org_id'] = (int)$selected_org_id;
    }
    
    $where_clause = implode(" AND ", $where);

    $query = "
        SELECT 
            a.user_id,
            u.Name as employee_name,
            a.status,
            a.clock_in,
            a.clock_out,
            COALESCE(a.travel_time_hours, 0) as manual_travel,
            a.id as shift_id
        FROM attendance a
        INNER JOIN users u ON a.user_id = u.id
        WHERE $where_clause
        ORDER BY u.Name ASC, a.clock_in ASC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $report_data = [];

    foreach ($shifts as $s) {
        $uid = $s['user_id'];
        $name = $s['employee_name'];
        $status_val = trim((string)$s['status']);
        
        if (!isset($report_data[$uid])) {
            $report_data[$uid] = [
                'name' => $name,
                'reg_hours' => 0.00,
                'ot_hours' => 0.00,
                'travel_hours' => 0.00,
                'total_hours' => 0.00
            ];
        }

        $in_ts = strtotime($s['clock_in']);
        $out_ts = (!empty($s['clock_out']) && $s['clock_out'] !== '0000-00-00 00:00:00') ? strtotime($s['clock_out']) : $in_ts;
        
        $break_sec = 0;
        $bStmt = $pdo->prepare("SELECT break_start, break_end FROM breaks WHERE shift_id = ?");
        $bStmt->execute([$s['shift_id']]);
        foreach ($bStmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
            if (!empty($b['break_end'])) {
                $break_sec += abs(strtotime($b['break_end']) - strtotime($b['break_start']));
            }
        }

        $net_seconds = $out_ts - $in_ts - $break_sec;
        if ($net_seconds < 0) $net_seconds = 0;
        $hours = $net_seconds / 3600;

        if ($status_val === '5' || $status_val === 'travel') {
            $report_data[$uid]['travel_hours'] += $hours;
            $report_data[$uid]['total_hours'] += $hours;
        } else {
            if ($hours > 8) {
                $reg = 8.00;
                $ot = $hours - 8.00;
            } else {
                $reg = $hours;
                $ot = 0.00;
            }
            
            if ((float)$s['manual_travel'] > 0) {
                $report_data[$uid]['travel_hours'] += (float)$s['manual_travel'];
                $report_data[$uid]['total_hours']  += (float)$s['manual_travel'];
            }

            $report_data[$uid]['reg_hours'] += $reg;
            $report_data[$uid]['ot_hours']  += $ot;
            $report_data[$uid]['total_hours'] += $hours;
        }
    }
} catch (Exception $e) {
    die("Report Logic Mismatch: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll Hours Categorization Matrix</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; padding-top: 95px; color: #1e293b; font-size: 11px; }
        .control-panel-card { background: white; border-radius: 12px; border: 1px solid #cbd5e1; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); padding: 18px; margin-bottom: 20px; }
        .table-matrix th { background: #0f172a; color: white; padding: 12px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        .table-matrix td { padding: 12px; vertical-align: middle; background: white; border-bottom: 1px solid #e2e8f0; font-weight: bold; }
    </style>
</head>
<body>
    <?= generateMenu($pdo) ?>
    
    <div class="container-fluid px-5">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="font-weight-bold text-dark mb-1"><i class="fa fa-chart-pie mr-2 text-primary"></i>Payroll Hours Categorization Report</h4>
                <p class="text-muted mb-0">Itemized audit of regular shifts, calculated overtime bounds, and travel parameters by company branch layout.</p>
            </div>
            <div>
                <button onclick="window.print()" class="btn btn-sm btn-outline-dark font-weight-bold mr-2"><i class="fa fa-print mr-1"></i> Print Matrix</button>
                <button onclick="exportTableToCSV()" class="btn btn-sm btn-success font-weight-bold"><i class="fa fa-file-excel mr-1"></i> Export Excel Sheet</button>
            </div>
        </div>

        <div class="control-panel-card shadow-sm">
            <form method="GET" action="" class="row align-items-end mb-0">
                <div class="col-md-3">
                    <label class="font-weight-bold small text-muted text-uppercase mb-1">Start Filter Range</label>
                    <input type="date" name="from_date" class="form-control form-control-sm font-weight-bold text-dark" value="<?=$from_date?>">
                </div>
                <div class="col-md-3">
                    <label class="font-weight-bold small text-muted text-uppercase mb-1">End Filter Range</label>
                    <input type="date" name="to_date" class="form-control form-control-sm font-weight-bold text-dark" value="<?=$to_date?>">
                </div>
                
                <div class="col-md-3">
                    <label class="font-weight-bold small text-primary text-uppercase mb-1"><i class="fa fa-building"></i> Filter Organization Company</label>
                    <?php if ($sess_user_level <= 10): ?>
                        <select name="org_id" class="form-control form-control-sm font-weight-bold text-dark" style="border: 1px solid #3b82f6; background: #f0f9ff;">
                            <option value="all">All Corporate Branches Combined</option>
                            <?php foreach ($organizations as $org): ?>
                                <option value="<?=$org['id']?>" <?= (string)$selected_org_id === (string)$org['id'] ? 'selected' : '' ?>><?= htmlspecialchars($org['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" class="form-control form-control-sm font-weight-bold text-muted bg-light" value="Locked to Current Workspace Row" readonly>
                        <input type="hidden" name="org_id" value="<?=$user_org_id?>">
                    <?php endif; ?>
                </div>

                <div class="col-md-3">
                    <button type="submit" class="btn btn-sm btn-primary btn-block font-weight-bold" style="height:31px;"><i class="fa fa-sync mr-1"></i> Regenerate Matrix Dataset</button>
                </div>
            </form>
        </div>

        <div class="card shadow-sm border-0 rounded-lg overflow-hidden">
            <table class="table table-matrix mb-0 text-center" id="hoursReportTable">
                <thead>
                    <tr>
                        <th class="text-left">Employee Name</th>
                        <th>Regular Hours</th>
                        <th>Overtime (OT) Hours</th>
                        <th>Travel / Transit Hours</th>
                        <th class="text-right">Combined Billable Hours</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($report_data)): ?>
                        <tr><td colspan="5" class="py-4 text-muted font-weight-normal">No hours documented matching this organization range parameters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($report_data as $emp_id => $metrics): ?>
                            <tr>
                                <td class="text-left text-dark font-weight-bold"><i class="fa fa-user-circle mr-2 text-muted"></i><?= htmlspecialchars($metrics['name']) ?></td>
                                <td class="text-secondary"><?= number_format($metrics['reg_hours'], 2) ?> hrs</td>
                                <td class="text-danger"><?= number_format($metrics['ot_hours'], 2) ?> hrs</td>
                                <td class="text-warning"><?= number_format($metrics['travel_hours'], 2) ?> hrs</td>
                                <td class="text-right text-success" style="font-size:12px;">= <?= number_format($metrics['total_hours'], 2) ?> hrs</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function exportTableToCSV() {
            var csv = [];
            var rows = document.querySelectorAll("#hoursReportTable tr");
            for (var i = 0; i < rows.length; i++) {
                var row = [], cols = rows[i].querySelectorAll("td, th");
                for (var j = 0; j < cols.length; j++) {
                    row.push('"' + cols[j].innerText.trim() + '"');
                }
                csv.push(row.join(","));
            }
            var csvFile = new Blob(["\uFEFF" + csv.join("\n")], { type: "text/csv;charset=utf-8;" });
            var downloadLink = document.createElement("a");
            downloadLink.download = "Payroll_Hours_Matrix_Report_" + new Date().toISOString().slice(0,10) + ".csv";
            downloadLink.href = window.URL.createObjectURL(csvFile);
            downloadLink.click();
        }
    </script>
</body>
</html>