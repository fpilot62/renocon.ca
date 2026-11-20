<?php
/**
 * Block #1: PHP Core & Security Guard
 * Path: /employee_site_report.php
 */
ini_set('display_errors', 0);
require_once 'db.php';
require_once 'engine/menu_generator.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// SECURITY GUARD: If no user_id in session, kill the process and redirect
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id   = $_GET['uid'] ?? 0;
$site_id   = $_GET['sid'] ?? 0;
$from_date = $_GET['from'] ?? date('Y-m-01');
$to_date   = $_GET['to']   ?? date('Y-m-d');
$current_org_id = (int)($_SESSION['org_id'] ?? 0);

// 1. Fetch User and Site Names for Header
$header_stmt = $pdo->prepare("SELECT u.Name as staff, s.nick_name as site 
                             FROM users u, sites s 
                             WHERE u.id = ? AND s.SId = ?");
$header_stmt->execute([$user_id, $site_id]);
$header = $header_stmt->fetch();

// 2. Fetch All Records for this Specific Employee at this Site
$query = "SELECT a.* FROM attendance a 
          WHERE a.user_id = :uid AND a.site_id = :sid 
          AND DATE(a.clock_in) BETWEEN :from AND :to";

// SUPERADMIN BYPASS: Ensure logic remains consistent
if ($current_org_id > 0 && $current_org_id != 6) {
    $query .= " AND a.org_id = :org_id";
}
$query .= " ORDER BY a.clock_in DESC";

$stmt = $pdo->prepare($query);
$params = [':uid' => $user_id, ':sid' => $site_id, ':from' => $from_date, ':to' => $to_date];
if ($current_org_id > 0 && $current_org_id != 6) { $params[':org_id'] = $current_org_id; }
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_seconds = 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Site Report: <?= htmlspecialchars($header['staff']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f7f6; padding: 20px; padding-top: 80px; }
        .report-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .report-header { border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; margin-bottom: 20px; }
        .report-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .report-table th { text-align: left; background: #1e293b; color: white; padding: 10px; }
        .report-table td { padding: 10px; border-bottom: 1px solid #eee; }
        .total-box { margin-top: 20px; padding: 15px; background: #e0e7ff; color: #3730a3; border-radius: 8px; font-weight: bold; text-align: right; font-size: 16px; }
    </style>
</head>
<body>
    <?= generateMenu($pdo) ?>

    <div class="report-card">
        <div class="report-header">
            <h2><i class="fa fa-file-invoice"></i> Site Audit Report</h2>
            <p><strong>Employee:</strong> <?= htmlspecialchars($header['staff']) ?> (ID: <?= $user_id ?>)</p>
            <p><strong>Site:</strong> <?= htmlspecialchars($header['site']) ?></p>
            <p><strong>Period:</strong> <?= $from_date ?> to <?= $to_date ?></p>
        </div>

        <table class="report-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Clock In</th>
                    <th>Clock Out</th>
                    <th>Duration</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): 
                    $duration = 0;
                    if ($r['clock_out']) {
                        $duration = strtotime($r['clock_out']) - strtotime($r['clock_in']);
                        $total_seconds += $duration;
                    }
                    $hrs = floor($duration / 3600);
                    $mins = floor(($duration % 3600) / 60);
                ?>
                <tr>
                    <td><?= date('Y-m-d', strtotime($r['clock_in'])) ?></td>
                    <td><?= date('H:i', strtotime($r['clock_in'])) ?></td>
                    <td><?= $r['clock_out'] ? date('H:i', strtotime($r['clock_out'])) : '---' ?></td>
                    <td><?= $hrs ?>h <?= $mins ?>m</td>
                    <td><?= ($r['status'] == '4') ? 'Finalized' : 'Working' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="total-box">
            Total Hours on Site: <?= round($total_seconds / 3600, 2) ?> Hours
        </div>
    </div>
</body>
</html>