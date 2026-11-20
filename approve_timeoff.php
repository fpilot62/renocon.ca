<?php
/** Path: /approve_timeoff.php */
session_start();
require_once 'db.php';

// 1. SECURITY: Admin/Manager only
if (!isset($_SESSION['user_level']) || $_SESSION['user_level'] > 2) {
    die("Unauthorized Access.");
}

// 2. HANDLE APPROVAL/DENIAL
if (isset($_GET['action']) && isset($_GET['id'])) {
    $status = ($_GET['action'] == 'approve') ? 'Approved' : 'Denied';
    $stmt = $pdo->prepare("UPDATE time_off_requests SET status = ? WHERE id = ?");
    $stmt->execute([$status, $_GET['id']]);
    header("Location: approve_timeoff.php?msg=Request $status");
    exit;
}

// 3. FETCH PENDING REQUESTS
$requests = $pdo->query("SELECT r.*, u.Name FROM time_off_requests r 
                         JOIN users u ON r.user_id = u.id 
                         WHERE r.status = 'pending' 
                         ORDER BY r.created_at DESC")->fetchAll();

include 'menu_include.php'; 
?>
<!DOCTYPE html>
<html>
<head>
    <!-- pwa-head -->
    <?php if (file_exists(__DIR__ . '/pwa_head.php')) include __DIR__ . '/pwa_head.php'; ?>

    <title>Manage Time Off Requests</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .req-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 900px; margin: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { text-align: left; background: #0097a7; color: white; padding: 12px; }
        td { padding: 12px; border-bottom: 1px solid #eee; }
        .btn { padding: 6px 12px; border-radius: 4px; text-decoration: none; color: white; font-weight: bold; font-size: 12px; }
        .btn-approve { background: #2ecc71; margin-right: 5px; }
        .btn-deny { background: #e74c3c; }
    </style>
</head>
<body>

<div class="req-card">
    <h2>Pending Time Off Requests</h2>
    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Dates</th>
                <th>Reason</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($requests as $r): ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['Name']) ?></strong></td>
                <td><?= $r['start_date'] ?> to <?= $r['end_date'] ?></td>
                <td><?= htmlspecialchars($r['reason']) ?></td>
                <td>
                    <a href="approve_timeoff.php?action=approve&id=<?= $r['id'] ?>" class="btn btn-approve">APPROVE</a>
                    <a href="approve_timeoff.php?action=approve&id=<?= $r['id'] ?>" class="btn btn-deny">DENY</a>
                </td>
            </tr>
            <?php endforeach; if(empty($requests)) echo "<tr><td colspan='4'>No pending requests.</td></tr>"; ?>
        </tbody>
    </table>
</div>

</body>
</html>