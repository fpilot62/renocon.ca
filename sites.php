<?php
/** Path: /payroll/sites.php */
session_start();
require_once 'db.php';

// 1. Get the current Organization ID 
$org_id = $_SESSION['org_id'] ?? 1;

// 2. Handle New Site Creation (Included org_id in INSERT)
if (isset($_POST['add_site'])) {
    $name = $_POST['nick_name'];
    $stmt = $pdo->prepare("INSERT INTO sites (nick_name, org_id, module) VALUES (?, ?, 'clocking')");
    $stmt->execute([$name, $org_id]);
    header("Location: sites.php?msg=Site+Added"); exit;
}

// 3. Fetch ONLY sites belonging to this Organization
$stmt = $pdo->prepare("SELECT * FROM sites WHERE org_id = ? ORDER BY SId DESC");
$stmt->execute([$org_id]);
$sites = $stmt->fetchAll();

include 'menu_include.php';
?>
<div style="padding:20px; font-family:sans-serif;">
    <h2>Manage Your Locations</h2>
    
    <form method="POST" style="margin-bottom:30px; background:#f9f9f9; padding:15px; border-radius:8px;">
        <input type="text" name="nick_name" placeholder="Site Name (e.g. Westbank)" required style="padding:8px;">
        <button type="submit" name="add_site" style="background:#27ae60; color:white; border:none; padding:8px 15px; cursor:pointer;">+ Add Site</button>
    </form>

    <table border="1" style="width:100%; border-collapse:collapse; background:white;">
        <tr style="background:#eee;">
            <th style="padding:10px;">ID</th>
            <th>Site Name</th>
            <th>Module</th>
        </tr>
        <?php foreach($sites as $s): ?>
        <tr>
            <td style="padding:10px; text-align:center;"><?= $s['SId'] ?></td>
            <td style="padding:10px;"><?= htmlspecialchars($s['nick_name']) ?></td>
            <td style="padding:10px;"><?= $s['module'] ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>