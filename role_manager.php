<?php
/** Path: /role_manager.php */
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. DATABASE & MENU: Swapped engine.php for your active db.php
require_once 'db.php'; 
require_once 'engine/menu_generator.php'; 

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 2. SECURITY LOCK - Use Level 10 for Super Admin
if (!isset($_SESSION['user_level']) || intval($_SESSION['user_level']) > 10) { 
    header("Location: index.php"); exit; 
}

/** * CONNECTION RECOVERY
 * Ensures $pdo is active if db.php uses a different variable name
 */
if (!isset($pdo)) {
    foreach (get_defined_vars() as $name => $value) {
        if ($value instanceof PDO) {
            $pdo = $value;
            break;
        }
    }
}

// 3. POPULATION LOGIC (For Editing a Role)
$edit_role = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM roles WHERE role_id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_role = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 4. SAVE LOGIC
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_role'])) {
    $id    = intval($_POST['role_id']);
    $name  = $_POST['role_name'];
    $color = $_POST['role_color'];
    $page  = $_POST['landing_page']; 
    
    $check = $pdo->prepare("SELECT role_id FROM roles WHERE role_id = ?");
    $check->execute([$id]);
    
    if ($check->fetch()) {
        $stmt = $pdo->prepare("UPDATE roles SET role_name=?, role_color=?, landing_page=? WHERE role_id=?");
        $stmt->execute([$name, $color, $page, $id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO roles (role_id, role_name, role_color, landing_page) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id, $name, $color, $page]);
    }
    header("Location: role_manager.php?success=1"); exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Role Manager</title>
   <style>
    /* GLOBAL STYLES - Matched to Payroll Hub */
    body { 
        font-family: 'Inter', sans-serif; 
        background: #f1f5f9; 
        margin: 0; 
        padding: 0; 
        color: #1e293b; 
        padding-top: 50px; /* Essential to clear the fixed menu */
    }

    .content-body { 
        max-width: 1400px; 
        margin: 0 auto; 
        padding: 0 20px; 
    }

    /* CARD STYLE - Matched to Site Manager */
    .card { 
        background: white; 
        padding: 30px; 
        border-radius: 16px; 
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); 
        margin-bottom: 30px; 
        border: 1px solid #e2e8f0;
    }

    h3 { 
        color: #0f172a; 
        font-weight: 800; 
        font-size: 22px; 
        margin-bottom: 20px; 
    }

    /* FORM STYLES */
    label { 
        font-size: 11px; 
        font-weight: 800; 
        color: #64748b; 
        text-transform: uppercase; 
        display: block; 
        margin-bottom: 8px; 
    }

    input[type="text"], 
    input[type="number"] { 
        padding: 10px; 
        border: 1px solid #cbd5e1; 
        border-radius: 8px; 
        background: #f8fafc; 
        color: #1e293b; 
        font-weight: 600;
    }

    .btn-save { 
        background: #334155; 
        color: white; 
        border: none; 
        padding: 12px 25px; 
        border-radius: 8px; 
        cursor: pointer; 
        font-weight: bold; 
        transition: background 0.2s; 
    }

    .btn-save:hover { 
        background: #1e293b; 
    }

    /* TABLE STYLES - Light Theme */
    table { 
        width: 100%; 
        border-collapse: collapse; 
        background: white; 
        border-radius: 12px; 
        overflow: hidden; 
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); 
    }

    th { 
        background: #0f172a; 
        color: white; 
        padding: 15px; 
        text-align: left; 
        font-size: 12px; 
        text-transform: uppercase; 
    }

    td { 
        padding: 15px; 
        border-bottom: 1px solid #f1f5f9; 
        color: #334155;
        font-size: 14px;
    }

    .role-pill { 
        padding: 6px 14px; 
        border-radius: 20px; 
        color: white; 
        font-size: 10px; 
        font-weight: 800; 
        text-transform: uppercase; 
        display: inline-block; 
    }

    code { 
        background: #f1f5f9; 
        padding: 4px 8px; 
        border-radius: 6px; 
        color: #475569; 
        font-size: 12px; 
        font-family: monospace;
    }
</style>
</head>
<body>

    <?php echo generateMenu($pdo); ?>

    <div class="content-body">
        <div class="card">
            <h3 style="margin-top:0;"><?= $edit_role ? 'Edit Role' : 'Add New Role Level' ?></h3>
            <form method="POST" class="form-grid">
                <div style="width: 80px;">
                    <label>Level (ID)</label>
                    <input type="number" name="role_id" value="<?= $edit_role['role_id'] ?? '' ?>" required <?= $edit_role ? 'readonly' : '' ?> style="width: 60px;">
                </div>
                <div style="flex: 2; min-width: 200px;">
                    <label>Role Name</label>
                    <input type="text" name="role_name" value="<?= htmlspecialchars($edit_role['role_name'] ?? '') ?>" style="width: 100%;" required>
                </div>
                <div style="flex: 2; min-width: 200px;">
                    <label>Landing Page</label>
                    <input type="text" name="landing_page" value="<?= htmlspecialchars($edit_role['landing_page'] ?? 'index.php') ?>" style="width: 100%;" required>
                </div>
                <div>
                    <label>Tag Color</label>
                    <input type="color" name="role_color" value="<?= $edit_role['role_color'] ?? '#000000' ?>" style="width: 60px; height: 38px; padding: 2px; cursor: pointer;">
                </div>
                <button type="submit" name="save_role" class="btn-save">SAVE ROLE</button>
            </form>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Level</th>
                    <th>Role Label</th>
                    <th>Home Page</th>
                    <th>Hex Code</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $res = $pdo->query("SELECT * FROM roles ORDER BY role_id ASC");
                while ($r = $res->fetch(PDO::FETCH_ASSOC)): ?>
                <tr>
                    <td><strong><?= $r['role_id'] ?></strong></td>
                    <td>
                        <span class="role-pill" style="background: <?= $r['role_color'] ?>;">
                            <?= htmlspecialchars($r['role_name']) ?>
                        </span>
                    </td>
                    <td><small style="color: #94a3b8;"><?= htmlspecialchars($r['landing_page'] ?? '---') ?></small></td>
                    <td><code><?= strtoupper($r['role_color']) ?></code></td>
                    <td><a href="role_manager.php?edit=<?= $r['role_id'] ?>" style="color: #0ea5e9; font-weight: bold; text-decoration: none;">Edit</a></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

</body>
</html>