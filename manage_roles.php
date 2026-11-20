<?php
/** Path: /manage_roles.php */
require_once 'db.php';

if (!isset($_SESSION['user_level']) || $_SESSION['user_level'] > 10) {
    header("Location: index.php"); exit;
}

$roles = $pdo->query("SELECT * FROM roles ORDER BY role_id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body { background: #0f172a; font-family: 'Inter', sans-serif; padding: 40px; color: white; }
        .role-card { background: white; color: #1e293b; padding: 20px; border-radius: 12px; max-width: 600px; margin: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
    </style>
</head>
<body>
    <div class="role-card">
        <h2>System Roles</h2>
        <table>
            <thead>
                <tr>
                    <th>Level</th>
                    <th>Role Name</th>
                    <th>Color</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $r): ?>
                <tr>
                    <td><strong><?= $r['role_id'] ?></strong></td>
                    <td><?= htmlspecialchars($r['role_name']) ?></td>
                    <td><div style="width: 20px; height: 20px; border-radius: 4px; background: <?= $r['role_color'] ?>;"></div></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><a href="manage_users.php">← Back to Employees</a></p>
    </div>
</body>
</html>