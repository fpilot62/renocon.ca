<?php
/** Path: /supervisor_view.php */
session_start();
require_once 'header.php';

// Guard: Only Role 1 (Super) or 3 (Supervisor)
if (!isset($_SESSION['user_role']) || ($_SESSION['user_role'] != 1 && $_SESSION['user_role'] != 3)) {
    header("Location: login_kiosk.php");
    exit;
}

$org_id = $_SESSION['org_id'];

// Get workers from this Org who are currently clocked in (clock_out_time is NULL)

// Replace your main data query with this one
$sql = "SELECT u.Name as staff_name, a.clock_in, a.status, s.nick_name as site_name
        FROM attendance a
        JOIN users u ON a.user_id = u.id
        JOIN sites s ON a.site_id = s.SId
        WHERE u.org_id = ? AND a.clock_out IS NULL";


$stmt = $pdo->prepare($sql);
$stmt->execute([$org_id]);
$active_workers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Supervisor Live View</title>
    <style>
        body { font-family: sans-serif; background: #f1f5f9; padding: 20px; }
        .container { max-width: 900px; margin: auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { text-align: left; background: #f8fafc; padding: 12px; border-bottom: 2px solid #e2e8f0; }
        td { padding: 12px; border-bottom: 1px solid #f1f5f9; }
        .active-dot { height: 10px; width: 10px; background: #22c55e; border-radius: 50%; display: inline-block; margin-right: 5px; }
    </style>
</head>
<body>

<div class="container">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h1>Supervisor Live View</h1>
        <a href="kiosk.php" style="color:#0891b2; font-weight:bold; text-decoration:none;">Open Kiosk Mode</a>
    </div>

    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Location</th>
                <th>Clock In Time</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($active_workers): ?>
                <?php foreach ($active_workers as $w): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($w['Name']) ?></strong></td>
                    <td><?= htmlspecialchars($w['site_name']) ?></td>
                    <td><span class="active-dot"></span> <?= date('g:i A', strtotime($w['clock_in_time'])) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="3" style="text-align:center; padding:30px; color:#64748b;">No employees currently clocked in.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <br>
    <a href="logout.php" style="color:#ef4444;">Logout</a>
</div>

</body>
</html>