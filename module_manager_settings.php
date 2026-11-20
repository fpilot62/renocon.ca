<?php
/** Path: /module_manager_settings.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'engine/menu_generator.php';

// --- THE POWER KEY SECURITY CHECK ---
$current_org = isset($_SESSION['org_id']) ? (int)$_SESSION['org_id'] : 0;
$current_lvl = isset($_SESSION['user_level']) ? (int)$_SESSION['user_level'] : 99;

// 1. Check if logged in
if (!isset($_SESSION['user_id'])) {
    die("Access Denied: Please log in.");
}

// 2. Access Granted IF: Level 10 OR Org 100000
$is_super = ($current_lvl === 10);
$is_master = ($current_org === 100000);

if (!$is_super && !$is_master) {
    // This helps us debug if it still fails
    echo "Access Denied. <br>Your Org: $current_org <br>Your Level: $current_lvl";
    exit;
}
// --- END CHECK ---

$msg = "";

// 2. HANDLE ADDING A NEW MODULE
if (isset($_POST['add_module'])) {
    $key = trim($_POST['module_key']);
    $name = trim($_POST['module_name']);
    
    if (!empty($key) && !empty($name)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO modules (module_key, module_display_name, is_active) VALUES (?, ?, 1)");
            $stmt->execute([$key, $name]);
            $msg = "<div style='color:green; padding:15px; background:#e8f5e9; border-radius:5px; margin-bottom:20px;'>✔ Module '$name' created!</div>";
        } catch (PDOException $e) {
            $msg = "<div style='color:red; padding:15px; background:#ffebee; border-radius:5px; margin-bottom:20px;'>Error: " . $e->getMessage() . "</div>";
        }
    }
}

// 3. HANDLE TOGGLE ACTIVE STATUS
if (isset($_GET['toggle'])) {
    $id = intval($_GET['toggle']);
    $current = intval($_GET['status']);
    $new_status = ($current == 1) ? 0 : 1;
    
    $stmt = $pdo->prepare("UPDATE modules SET is_active = ? WHERE id = ?");
    $stmt->execute([$new_status, $id]);
    header("Location: module_manager_settings.php");
    exit;
}

// 4. FETCH ALL MODULES
$modules = $pdo->query("SELECT * FROM modules ORDER BY module_display_name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Module Manager | SaaS Control</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; margin: 0; }
        .container { padding: 30px; max-width: 900px; margin: auto; }
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin-bottom: 25px; }
        h2 { color: #1e293b; margin-top: 0; border-bottom: 2px solid #eee; padding-bottom: 10px; font-size: 18px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #1e293b; color: white; font-size: 12px; text-transform: uppercase; }
        .input-field { padding: 10px; border: 1px solid #ddd; border-radius: 4px; margin-right: 10px; width: 35%; }
        .btn-add { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .status-badge { padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; text-decoration: none; display: inline-block; }
        .status-active { background: #dcfce7; color: #166534; }
        .status-inactive { background: #fee2e2; color: #991b1b; }
        .btn-toggle { background:#334155; color:white; padding: 5px 10px; border-radius: 4px; text-decoration: none; font-size: 11px; }
        code { background: #f1f5f9; padding: 2px 5px; border-radius: 4px; color: #e74c3c; font-weight: bold; }
    </style>
</head>
<body style="margin:0; padding:0;">

<?php echo generateMenu($pdo); ?>

<div class="container">
    <h1 style="color:#1e293b;">System Module Master</h1>
    <?= $msg ?>

    <div class="card">
        <h2>Define New Module</h2>
        <form method="POST">
            <input type="text" name="module_key" class="input-field" placeholder="Key (e.g. hr_manager)" required>
            <input type="text" name="module_name" class="input-field" placeholder="Display Name (e.g. HR Management)" required>
            <button type="submit" name="add_module" class="btn-add">Add to System</button>
        </form>
    </div>

    <div class="card">
        <h2>Global Module List</h2>
        <table>
            <thead>
                <tr>
                    <th>Module Key</th>
                    <th>Display Name</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($modules as $m): ?>
                <tr>
                    <td><code><?= htmlspecialchars($m['module_key']) ?></code></td>
                    <td><strong><?= htmlspecialchars($m['module_display_name']) ?></strong></td>
                    <td>
                        <?php if($m['is_active']): ?>
                            <span class="status-badge status-active">ACTIVE</span>
                        <?php else: ?>
                            <span class="status-badge status-inactive">INACTIVE</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="?toggle=<?= $m['id'] ?>&status=<?= $m['is_active'] ?>" class="btn-toggle">
                            Toggle Status
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>