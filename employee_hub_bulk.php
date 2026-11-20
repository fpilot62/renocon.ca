<?php
/** Path: /employee_hub_bulk.php */
ob_start();
require_once 'db.php';
require_once 'engine/menu_generator.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. SAVE ALL CHANGES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_save'])) {
    foreach ($_POST['emp'] as $id => $data) {
        $sql = "UPDATE users SET 
                Name = ?, email = ?, pin = ?, 
                hourly_rate = ?, official_rate = ?, 
                active = ?, user_role = ? 
                WHERE id = ?";
        $pdo->prepare($sql)->execute([
            $data['name'], $data['email'], $data['pin'],
            $data['h_rate'], $data['o_rate'],
            $data['active'], $data['role'],
            $id
        ]);
    }
    header("Location: employee_hub.php?msg=bulk_updated"); exit;
}

$users = $pdo->query("SELECT * FROM users ORDER BY active DESC, Name ASC")->fetchAll(PDO::FETCH_ASSOC);
// Fetch roles for the dropdowns
$roles = $pdo->query("SELECT role_id, role_name FROM Gem_Roles WHERE org_id = 1")->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Bulk Employee Edit</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; padding-top: 90px; }
        .bulk-table { width: 100%; border-collapse: collapse; background: white; border-radius: 10px; overflow: hidden; }
        .bulk-table th { background: #0f172a; color: white; padding: 12px; font-size: 11px; text-transform: uppercase; text-align: left; }
        .bulk-table td { padding: 8px; border-bottom: 1px solid #e2e8f0; }
        .bulk-table input, .bulk-table select { width: 100%; border: 1px solid #cbd5e1; padding: 6px; border-radius: 4px; font-size: 13px; }
        .save-bar { position: fixed; bottom: 0; left: 0; width: 100%; background: white; padding: 15px; box-shadow: 0 -5px 15px rgba(0,0,0,0.1); text-align: center; z-index: 1000; }
        .btn-save { background: #10b981; color: white; border: none; padding: 12px 40px; border-radius: 8px; font-weight: 800; cursor: pointer; font-size: 16px; }
    </style>
</head>
<body>
<?= generateMenu($pdo); ?>

<div style="max-width: 1500px; margin: auto; padding: 20px 20px 100px;">
    <h1 style="font-weight: 900;">Multi-User Bulk Edit</h1>
    
    <form method="POST">
        <table class="bulk-table">
            <thead>
                <tr>
                    <th style="width: 200px;">Name</th>
                    <th style="width: 200px;">Email</th>
                    <th style="width: 80px;">PIN</th>
                    <th style="width: 100px;">Reg Rate</th>
                    <th style="width: 100px;">Bal Rate</th>
                    <th style="width: 150px;">Role</th>
                    <th style="width: 100px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($users as $u): ?>
                <tr>
                    <td><input type="text" name="emp[<?=$u['id']?>][name]" value="<?= htmlspecialchars($u['Name']) ?>"></td>
                    <td><input type="email" name="emp[<?=$u['id']?>][email]" value="<?= htmlspecialchars($u['email']) ?>"></td>
                    <td><input type="text" name="emp[<?=$u['id']?>][pin]" value="<?= $u['pin'] ?>"></td>
                    <td><input type="number" step="0.01" name="emp[<?=$u['id']?>][h_rate]" value="<?= $u['hourly_rate'] ?>"></td>
                    <td><input type="number" step="0.01" name="emp[<?=$u['id']?>][o_rate]" value="<?= $u['official_rate'] ?>"></td>
                    <td>
                        <select name="emp[<?=$u['id']?>][role]">
                            <?php foreach($roles as $r): ?>
                                <option value="<?= $r['role_id'] ?>" <?= $u['user_role'] == $r['role_id'] ? 'selected' : '' ?>>
                                    <?= strtoupper($r['role_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="emp[<?=$u['id']?>][active]">
                            <option value="1" <?= $u['active'] == 1 ? 'selected' : '' ?>>Active</option>
                            <option value="0" <?= $u['active'] == 0 ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="save-bar">
            <button type="submit" name="bulk_save" class="btn-save">💾 SAVE ALL CHANGES</button>
        </div>
    </form>
</div>
</body>
</html>