<?php
/**
 * Path: /payroll_history.php
 * PURPOSE: standalone Historic Payroll Search Engine & Archive Panel
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';
require_once 'engine/menu_generator.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. DYNAMIC INTERNAL SELECTORS (Default to the past 60 days if accessed empty)
$from_date   = $_GET['from_date']   ?? date('Y-m-d', strtotime('-60 days'));
$to_date     = $_GET['to_date']     ?? date('Y-m-d');
$user_filter = $_GET['user_id']     ?? '';

function getArchivedPeriodHeading($dateStr) {
    $dt = new DateTime($dateStr);
    $year = $dt->format('Y');
    $monthName = $dt->format('F');
    $day = (int)$dt->format('j');
    return ($day <= 15) ? "PAY PERIOD RECORD: $monthName 1 – 15, $year" : "PAY PERIOD RECORD: $monthName 16 – " . $dt->format('t') . ", $year";
}

try {
    $staff_list = $pdo->query("SELECT id, Name FROM users ORDER BY Name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Build conditional user filters
    $user_clause = ($user_filter !== '') ? " AND pf.user_id = " . (int)$user_filter : "";
    $q_final = "SELECT pf.work_date, pf.reg_hours as hrs, pf.reg_pay as gross, u.Name as staff_name, 'FINALIZED REGULAR' as stream
                FROM payroll_finalized pf
                LEFT JOIN users u ON pf.user_id = u.id
                WHERE pf.work_date BETWEEN ? AND ? $user_clause";

    $user_clause_bal = ($user_filter !== '') ? " AND pb.user_id = " . (int)$user_filter : "";
    $q_bal = "SELECT pb.work_date, pb.balance_hours as hrs, pb.total_gross as gross, u.Name as staff_name, 'REMAINDER BALANCE' as stream
              FROM payroll_balance pb
              LEFT JOIN users u ON pb.user_id = u.id
              WHERE pb.work_date BETWEEN ? AND ? $user_clause_bal";

    $query = "($q_final) UNION ALL ($q_bal) ORDER BY work_date DESC, staff_name ASC";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$from_date, $to_date, $from_date, $to_date]);
    $raw_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $history_tree = [];
    foreach ($raw_history as $row) {
        if (empty($row['staff_name'])) continue;
        $period_label = getArchivedPeriodHeading($row['work_date']);
        $history_tree[$period_label][] = $row;
    }
} catch (Exception $e) {
    die("History Hub SQL Engine Failure: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Remittance Archive Logs</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; padding-top: 100px; font-size: 11px; color: #1e293b; }
        .filter-card-panel { background: white; padding: 15px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 25px; }
        .period-archive-block { margin-bottom: 25px; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .period-archive-header { background: #0f172a; color: white; padding: 12px 20px; font-weight: bold; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; background: white; }
        th { padding: 10px 15px; border: 1px solid #e2e8f0; background: #f8fafc; text-transform: uppercase; color: #64748b; font-weight: bold; }
        td { padding: 10px 15px; border: 1px solid #e2e8f0; }
        .stream-pill { font-weight: 800; font-size: 8px; padding: 2px 6px; border-radius: 4px; text-transform: uppercase; }
        .stream-regular { background: #e0f2fe; color: #0369a1; }
        .stream-balance { background: #f3e8ff; color: #6b21a8; }
    </style>
</head>
<body>
    <?= generateMenu($pdo) ?>
    <div class="container-fluid px-5">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="font-weight-bold text-dark mb-0"><i class="fa fa-clock-rotate-left text-primary mr-2"></i> Payroll Payout History Logs</h2>
                <span class="text-muted font-weight-bold">Search, query, and review historic corporate settlements and closed balances.</span>
            </div>
            <a href="payroll_master_ledger.php" class="btn btn-sm btn-dark font-weight-bold px-3"><i class="fa fa-arrow-left mr-1"></i> Command Matrix Ledger</a>
        </div>

        <div class="filter-card-panel shadow-sm">
            <form method="GET" class="row align-items-end mx-0">
                <div class="col-md-3 px-1">
                    <label class="small font-weight-bold text-muted text-uppercase mb-1">Search From</label>
                    <input type="date" name="from_date" class="form-control form-control-sm" value="<?=$from_date?>">
                </div>
                <div class="col-md-3 px-1">
                    <label class="small font-weight-bold text-muted text-uppercase mb-1">Search To</label>
                    <input type="date" name="to_date" class="form-control form-control-sm" value="<?=$to_date?>">
                </div>
                <div class="col-md-3 px-1">
                    <label class="small font-weight-bold text-muted text-uppercase mb-1">Filter Employee Roster</label>
                    <select name="user_id" class="form-control form-control-sm font-weight-bold">
                        <option value="">All Roster Members</option>
                        <?php foreach($staff_list as $user): ?>
                            <option value="<?=$user['id']?>" <?=$user_filter==$user['id']?'selected':''?>><?=htmlspecialchars($user['Name'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 px-1">
                    <button type="submit" class="btn btn-primary btn-sm btn-block font-weight-bold"><i class="fa fa-search mr-1"></i> Query History Hub</button>
                </div>
            </form>
        </div>

        <?php if (empty($history_tree)): ?>
            <div class="p-5 text-center bg-white border rounded font-italic text-muted shadow-sm">
                <i class="fa fa-database h3 d-block mb-2 text-muted"></i> No historic ledger matches found inside the selected search boundaries.
            </div>
        <?php else: ?>
            <?php foreach ($history_tree as $period_header => $lines): ?>
                <div class="period-archive-block shadow-sm">
                    <div class="period-archive-header"><i class="fa fa-calendar-check mr-2 text-warning"></i> <?=$period_header?></div>
                    <table>
                        <thead>
                            <tr>
                                <th width="15%">Date Logged</th>
                                <th width="40%">Staff Employee Name</th>
                                <th width="15%">Allocation Type</th>
                                <th width="15%" style="text-align:center;">Hours Documented</th>
                                <th width="15%" style="text-align:right;">Gross Financial Disbursed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lines as $l): ?>
                                <tr>
                                    <td><i class="fa fa-calendar-day text-muted mr-1"></i> <?=$l['work_date']?></td>
                                    <td class="font-weight-bold text-dark"><?=htmlspecialchars($l['staff_name'])?></td>
                                    <td>
                                        <span class="stream-pill stream-<?=$l['stream'] == 'FINALIZED REGULAR' ? 'regular' : 'balance'?>">
                                            <?=$l['stream'] == 'FINALIZED REGULAR' ? 'Regular Chunks' : 'Overtime Balance'?>
                                        </span>
                                    </td>
                                    <td style="text-align:center; font-weight:bold; color:#2563eb;"><?=number_format($l['hrs'], 2)?> hrs</td>
                                   
                                    <td style="text-align:right; font-weight:bold; color:#16a34a;">$<?=number_format((float)($row['gross_total'] ?? 0), 2)?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>
</body>
</html>