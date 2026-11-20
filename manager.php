<?php
/** Path: /payroll/site_manager.php */
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] != 1) { 
    header("Location: login.php"); exit; 
}

$msg = "";

// HANDLE DELETE
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM sites WHERE SId = ?");
    $stmt->execute([$_GET['delete']]);
    $msg = "Site deleted successfully.";
}

// HANDLE ADD NEW SITE
if (isset($_POST['add_site'])) {
    $sql = "INSERT INTO sites (nick_name, region, province, site_type, module, target_labor_percent) 
            VALUES (?, ?, ?, 'Restaurant', 'clocking', ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$_POST['nick_name'], $_POST['region'], $_POST['region'], $_POST['target']]);
    $msg = "New site added!";
}

// HANDLE UPDATE SITE
if (isset($_POST['update_site'])) {
    $sql = "UPDATE sites SET nick_name = ?, region = ?, province = ?, target_labor_percent = ? WHERE SId = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$_POST['nick_name'], $_POST['region'], $_POST['region'], $_POST['target'], $_POST['site_id']]);
    $msg = "Site updated successfully!";
}

// Fetch all clocking sites
$sites = $pdo->query("SELECT SId, nick_name, region, target_labor_percent FROM sites WHERE module = 'clocking'")->fetchAll();

include 'menu_include.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Site Manager</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f7f6; margin: 0; }
        .container { padding: 30px; }
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #34495e; color: white; padding: 12px; text-align: left; font-size: 13px; }
        td { padding: 12px; border-bottom: 1px solid #eee; }
        input, select { padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .btn-add { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .btn-upd { background: #008ba3; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; }
        .btn-del { background: #e74c3c; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>

<div class="container">
    <?php if($msg) echo "<div class='card' style='color:green; font-weight:bold;'>$msg</div>"; ?>

    <div class="card">
        <h3><i class="fas fa-plus-circle"></i> Add New Restaurant Location</h3>
        <form method="POST" style="display: flex; gap: 15px; align-items: flex-end;">
            <div>
                <label style="font-size:11px; font-weight:bold;">LOCATION NAME</label><br>
                <input type="text" name="nick_name" placeholder="e.g. 3. Downtown" required>
            </div>
            <div>
                <label style="font-size:11px; font-weight:bold;">PROVINCE/REGION</label><br>
                <select name="region">
                    <option value="BC">British Columbia (BC)</option>
                    <option value="AB">Alberta (AB)</option>
                    <option value="ON">Ontario (ON)</option>
                    <option value="QC">Quebec (QC)</option>
                </select>
            </div>
            <div>
                <label style="font-size:11px; font-weight:bold;">LABOR TARGET %</label><br>
                <input type="number" step="0.01" name="target" value="22.00">
            </div>
            <button type="submit" name="add_site" class="btn-add">ADD SITE</button>
        </form>
    </div>

    <div class="card">
        <h3><i class="fas fa-store"></i> Active Locations</h3>
        <table>
            <thead>
                <tr>
                    <th>SId</th>
                    <th>Nick Name</th>
                    <th>Region/Province</th>
                    <th>Target %</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($sites as $s): ?>
                <tr>
                    <form method="POST">
                        <td><?= $s['SId'] ?></td>
                        <td><input type="text" name="nick_name" value="<?= htmlspecialchars($s['nick_name']) ?>"></td>
                        <td>
                            <select name="region">
                                <option value="BC" <?= $s['region'] == 'BC' ? 'selected' : '' ?>>BC</option>
                                <option value="AB" <?= $s['region'] == 'AB' ? 'selected' : '' ?>>AB</option>
                                <option value="ON" <?= $s['region'] == 'ON' ? 'selected' : '' ?>>ON</option>
                            </select>
                        </td>
                        <td><input type="number" step="0.01" name="target" value="<?= $s['target_labor_percent'] ?>"></td>
                        <td>
                            <input type="hidden" name="site_id" value="<?= $s['SId'] ?>">
                            <button type="submit" name="update_site" class="btn-upd">UPDATE</button>
                            <a href="?delete=<?= $s['SId'] ?>" class="btn-del" onclick="return confirm('Delete this site? Data linked to this ID may be affected.')" style="text-decoration:none; font-size:12px;">DELETE</a>
                        </td>
                    </form>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>