<?php
/** Path: /variance_report.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'engine/menu_generator.php';

// --- 1. SECURITY & PERMISSIONS ---
$user_lvl = (int)($_SESSION['user_level'] ?? 99);
$session_org = (int)($_SESSION['org_id'] ?? 0);
$is_super = ($user_lvl <= 10);

if ($user_lvl > 30) { header("Location: kiosk.php?err=Unauthorized"); exit; }

// --- 2. GET FILTERS ---
$from_date   = $_GET['from_date'] ?? date('Y-m-01');
$to_date     = $_GET['to_date'] ?? date('Y-m-t');
$sel_site    = $_GET['site_id'] ?? '';
$sel_emp     = $_GET['employee_id'] ?? '';

try {
    // --- 3. SECURE SELECTOR DATA ---
    if ($is_super) {
        if (!function_exists('sip_org_site_fence')) { require_once __DIR__ . '/engine/site_status_lib.php'; }
$__fence = sip_org_site_fence($pdo, 'sip_org_id');
$is_super = $__fence['is_super']; $selected_org = $__fence['selected_org']; $orgs = $__fence['orgs']; $sites = $__fence['sites']; $all_sites = $sites;

        $all_emps  = $pdo->query("SELECT id, Name FROM users WHERE user_role <= 50 ORDER BY Name ASC")->fetchAll();
    } else {
        // LOCK: Only show sites and employees belonging to this Organization
        $stmt_sites = $pdo->prepare("SELECT SId, nick_name FROM sites WHERE org_id = ? ORDER BY nick_name ASC");
        $stmt_sites->execute([$session_org]);
        $all_sites = $stmt_sites->fetchAll();

        $stmt_emps = $pdo->prepare("SELECT id, Name FROM users WHERE org_id = ? AND user_role <= 50 ORDER BY Name ASC");
        $stmt_emps->execute([$session_org]);
        $all_emps = $stmt_emps->fetchAll();
    }

    // --- 4. BUILD SECURE VARIANCE QUERY ---
    $where = ["DATE(a.clock_in) BETWEEN ? AND ?"];
    $params = [$from_date, $to_date];

    // THE FENCE: Mandatory Org Lock for non-supers
    if (!$is_super) {
        $where[] = "a.org_id = ?";
        $params[] = $session_org;
    }

    if ($sel_site) { $where[] = "a.site_id = ?"; $params[] = $sel_site; }
    if ($sel_emp)  { $where[] = "a.user_id = ?"; $params[] = $sel_emp; }

    $sql = "SELECT 
                u.Name as emp_name,
                DATE(a.clock_in) as shift_date,
                st.nick_name as site_name,
                (TIMESTAMPDIFF(SECOND, sch.start_time, sch.end_time) / 3600) as scheduled_hrs,
                (TIMESTAMPDIFF(SECOND, a.clock_in, a.clock_out) / 3600) as actual_gross,
                IFNULL(brk.total_break, 0) as break_hrs
            FROM attendance a
            INNER JOIN Gem_Schedules sch ON a.user_id = sch.employee_id 
                AND DATE(a.clock_in) = DATE(sch.start_time)
            INNER JOIN users u ON a.user_id = u.id
            INNER JOIN sites st ON a.site_id = st.SId
            LEFT JOIN (
                SELECT shift_id, SUM(TIMESTAMPDIFF(SECOND, break_start, break_end))/3600 as total_break 
                FROM breaks GROUP BY shift_id
            ) brk ON a.id = brk.shift_id
            WHERE " . implode(" AND ", $where) . "
            HAVING (actual_gross - break_hrs) > (scheduled_hrs + 0.1) 
            ORDER BY shift_date DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $variances = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Labor Variance Report</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; padding-top: 110px; }
        .container { max-width: 1400px; margin: 0 auto; padding: 0 20px; }
        .card { background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); padding: 25px; }
        
        /* Filter Panel Grid */
        .filter-panel { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); 
            gap: 15px; 
            background: #f1f5f9; 
            padding: 20px; 
            border-radius: 8px; 
            margin-bottom: 25px; 
            align-items: flex-end;
        }
        .input-group { display: flex; flex-direction: column; gap: 6px; }
        .input-group label { font-size: 10px; font-weight: 800; color: #475569; text-transform: uppercase; }
        .form-control { padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; font-family: inherit; }
        
        .v-table { width: 100%; border-collapse: collapse; }
        .v-table th { text-align: left; background: #f8fafc; padding: 12px; font-size: 11px; color: #64748b; border-bottom: 2px solid #e2e8f0; }
        .v-table td { padding: 15px 12px; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
        .badge-red { background: #fee2e2; color: #b91c1c; padding: 4px 8px; border-radius: 4px; font-weight: 800; }
        .btn-apply { background: #0ea5e9; color: white; border: none; padding: 11px; border-radius: 6px; font-weight: 700; cursor: pointer; }
    </style>
</head>
<body>

<?= generateMenu($pdo); ?>

<div class="container">
    <div class="card">
        <h3>Labor Variance Dashboard</h3>
        
        <form method="GET" class="filter-panel">
            <div class="input-group">
                <label>From Date</label>
                <input type="date" name="from_date" class="form-control" value="<?= $from_date ?>">
            </div>
            <div class="input-group">
                <label>To Date</label>
                <input type="date" name="to_date" class="form-control" value="<?= $to_date ?>">
            </div>
            <div class="input-group">
                <label>Site / Project</label>
                <?php if (!empty($is_super)): ?>
                <select name="org_id" onchange="this.form.submit()">
                    <option value="all" <?= (int)($selected_org ?? 0) === 0 ? 'selected' : '' ?>>All organizations</option>
                    <?php foreach (($orgs ?? []) as $o): ?>
                        <option value="<?= (int)$o['id'] ?>" <?= (int)($selected_org ?? 0) === (int)$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$o['org_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <select name="site_id" class="form-control">
                    <option value="">All Sites</option>
                    <?php foreach($all_sites as $s): ?>
                        <option value="<?= $s['SId'] ?>" <?= $sel_site == $s['SId'] ? 'selected' : '' ?>><?= htmlspecialchars($s['nick_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-group">
                <label>Employee</label>
                <select name="employee_id" class="form-control">
                    <option value="">All Employees</option>
                    <?php foreach($all_emps as $e): ?>
                        <option value="<?= $e['id'] ?>" <?= $sel_emp == $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['Name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-apply">Update Report</button>
        </form>

        <table class="v-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Employee</th>
                    <th>Site</th>
                    <th>Scheduled</th>
                    <th>Actual (Net)</th>
                    <th>Overage</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($variances as $v): 
                    $net = $v['actual_gross'] - $v['break_hrs'];
                    $over = $net - $v['scheduled_hrs'];
                ?>
                <tr>
                    <td><?= date('M d, Y', strtotime($v['shift_date'])) ?></td>
                    <td><strong><?= htmlspecialchars($v['emp_name']) ?></strong></td>
                    <td><?= htmlspecialchars($v['site_name']) ?></td>
                    <td><?= number_format($v['scheduled_hrs'], 2) ?> hrs</td>
                    <td><?= number_format($net, 2) ?> hrs</td>
                    <td><span class="badge-red">+<?= number_format($over, 2) ?> hrs</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>