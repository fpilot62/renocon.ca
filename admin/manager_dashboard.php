<?php
// 1. SECURITY BOUNCER - MUST BE AT THE VERY TOP
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Only Level 1 (Super Admin) is allowed here
if (!isset($_SESSION['user_level']) || $_SESSION['user_level'] != 1) {
    header("Location: index.php"); 
    exit;
}

// 2. DATABASE CONNECTION
require_once '../db.php'; 

// 3. DATE FILTERS (Default to last 7 days)
$f_start = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$f_end   = $_GET['end_date']   ?? date('Y-m-d');
$f_org   = $_GET['org_filter'] ?? 'all';

// 4. FETCH DATA (Master Query)
try {
    $sql = "SELECT a.*, u.Name as StaffName, s.nick_name as SiteDisplayName, o.org_name, DATE(a.clock_in) as log_date
            FROM attendance a
            JOIN users u ON a.user_id = u.id 
            LEFT JOIN sites s ON a.site_id = s.SId
            LEFT JOIN organizations o ON u.org_id = o.id
            WHERE DATE(a.clock_in) BETWEEN :start AND :end";

    $params = [':start' => $f_start, ':end' => $f_end];

    // Filter by Organization if selected
    if ($f_org !== 'all') {
        $sql .= " AND u.org_id = :oid";
        $params[':oid'] = $f_org;
    }

    $sql .= " ORDER BY a.clock_in DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $grouped_logs = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $grouped_logs[$row['log_date']][] = $row;
    }

    // Fetch list of organizations for the dropdown filter
    $org_list = $pdo->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("Master Dashboard Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Master Manager Dashboard</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; margin: 0; }
        .master-container { padding: 30px; max-width: 1300px; margin: auto; }
        .filter-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 25px; display: flex; gap: 15px; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 5px; }
        select, input { padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .btn-filter { background: #0097a7; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        
        .date-strip { background: #263238; color: white; padding: 10px 15px; font-weight: bold; border-radius: 4px 4px 0 0; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; background: white; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        th { background: #eceff1; color: #546e7a; padding: 12px; text-align: left; font-size: 0.85em; text-transform: uppercase; }
        td { padding: 12px; border-bottom: 1px solid #f1f1f1; }
        .org-badge { background: #e8f5e9; color: #2e7d32; padding: 3px 8px; border-radius: 12px; font-size: 0.8em; font-weight: bold; }
        .thumb { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 1px solid #ddd; }
    </style>
</head>
<body>

    <?php 
        $path_to_root = "../"; 
        include '../engine/menu_generator.php'; 
    ?>

    <div class="master-container">
        <h1>★ Global Manager Overview</h1>

        <form class="filter-card" method="GET">
            <div class="filter-group">
                <label>Organization</label>
                <select name="org_filter">
                    <option value="all">-- All Companies --</option>
                    <?php foreach($org_list as $o): ?>
                        <option value="<?= $o['id'] ?>" <?= ($f_org == $o['id']) ? 'selected' : '' ?>><?= $o['org_name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Start Date</label>
                <input type="date" name="start_date" value="<?= $f_start ?>">
            </div>
            <div class="filter-group">
                <label>End Date</label>
                <input type="date" name="end_date" value="<?= $f_end ?>">
            </div>
            <button type="submit" class="btn-filter">Update View</button>
        </form>

        <?php if(empty($grouped_logs)): ?>
            <div style="text-align:center; padding:50px; background:white; border-radius:8px;">No records found for these dates/filters.</div>
        <?php else: ?>
            <?php foreach ($grouped_logs as $date => $logs): ?>
                <div class="date-strip"><?= date('l, F j, Y', strtotime($date)) ?></div>
                <table>
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Staff Name</th>
                            <th>Location</th>
                            <th>Clock In</th>
                            <th>Clock Out</th>
                            <th>Photo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $l): ?>
                        <tr>
                            <td><span class="org-badge"><?= htmlspecialchars($l['org_name'] ?? 'N/A') ?></span></td>
                            <td><b><?= htmlspecialchars($l['StaffName']) ?></b></td>
                            <td><?= htmlspecialchars($l['SiteDisplayName'] ?? 'Main Office') ?></td>
                            <td><?= date('g:i A', strtotime($l['clock_in'])) ?></td>
                            <td><?= $l['clock_out'] ? date('g:i A', strtotime($l['clock_out'])) : '<span style="color:red">Active</span>' ?></td>
                            <td>
                                <?php if($l['selfie_in']): ?>
                                    <img src="<?= $path_to_root . $l['selfie_in'] ?>" class="thumb">
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>