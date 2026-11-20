<?php
/** Path: /payroll/module_manager.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';

// 1. SECURITY: Only Super Admin (Role 1)
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] != 1) {
    header("Location: login.php"); exit;
}

$msg = "";
$edit_item = null;

// 2. HANDLE DELETE
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM modules WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    header("Location: module_manager.php?msg=deleted"); exit;
}

// 3. HANDLE TOGGLE STATUS (Triggered by clicking the status badge)
if (isset($_GET['toggle'])) {
    $new_status = ($_GET['status'] == 1) ? 0 : 1;
    $stmt = $pdo->prepare("UPDATE modules SET is_active = ? WHERE id = ?");
    $stmt->execute([$new_status, $_GET['toggle']]);
    header("Location: module_manager.php?msg=status_updated"); exit;
}

// 4. HANDLE SAVE (ADD OR UPDATE)
if (isset($_POST['save_module'])) {
    $key = trim($_POST['module_key']);
    $name = trim($_POST['module_name']);
    $id = $_POST['module_id'] ?? null;

    if (!empty($id)) {
        // UPDATE EXISTING
        $stmt = $pdo->prepare("UPDATE modules SET module_key = ?, module_display_name = ? WHERE id = ?");
        $stmt->execute([$key, $name, $id]);
    } else {
        // ADD NEW
        $stmt = $pdo->prepare("INSERT INTO modules (module_key, module_display_name, is_active) VALUES (?, ?, 1)");
        $stmt->execute([$key, $name]);
    }
    header("Location: module_manager.php?msg=saved"); exit;
}

// 5. FETCH DATA FOR EDIT
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM modules WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_item = $stmt->fetch();
}

// 6. FETCH ALL MODULES
$modules = $pdo->query("SELECT * FROM modules ORDER BY module_display_name ASC")->fetchAll(PDO::FETCH_ASSOC);

include 'menu_include.php'; 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>System Module Master</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f7f6; margin: 0; }
        .container { padding: 20px; max-width: 1000px; margin: auto; }
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .form-grid { display: flex; gap: 15px; align-items: flex-end; }
        table { width: 100%; border-collapse: collapse; background: white; }
        th { background: #1e293b; color: white; padding: 15px; text-align: left; text-transform: uppercase; font-size: 13px; letter-spacing: 0.5px; }
        td { padding: 15px; border-bottom: 1px solid #eee; font-size: 15px; }
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; color: white; font-weight: bold; text-decoration: none; display: inline-block; transition: 0.2s; }
        .btn-save { background: #27ae60; }
        .btn-save:hover { background: #219150; }
        .btn-edit { background: #3498db; margin-right: 5px; }
        .btn-edit:hover { background: #2980b9; }
        .btn-del { background: #e74c3c; }
        .btn-del:hover { background: #c0392b; }
        
        /* Status Badge as a Toggle */
        .status-badge { padding: 5px 12px; border-radius: 20px; font-weight: bold; font-size: 11px; text-decoration: none; cursor: pointer; transition: 0.3s; border: 1px solid transparent; }
        .status-1 { background: #d4edda; color: #155724; border-color: #c3e6cb; }
        .status-0 { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        .status-badge:hover { filter: brightness(90%); }
        
        input { padding: 10px; border: 1px solid #ddd; border-radius: 4px; width: 280px; font-size: 14px; }
        label { display:block; font-size:12px; font-weight:bold; color: #444; margin-bottom: 8px; text-transform: uppercase; }
        .action-cell { text-align: right; white-space: nowrap; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h3 style="margin-top:0;"><i class="fas fa-microchip"></i> <?= $edit_item ? 'Update Module' : 'System Module Master' ?></h3>
        <form method="POST" class="form-grid">
            <input type="hidden" name="module_id" value="<?= $edit_item['id'] ?? '' ?>">
            
            <div>
                <label>Module Key (ID)</label>
                <input type="text" name="module_key" placeholder="e.g. inventory_v2" value="<?= htmlspecialchars($edit_item['module_key'] ?? '') ?>" required>
            </div>
            <div>
                <label>Friendly Display Name</label>
                <input type="text" name="module_name" placeholder="e.g. Inventory Pro" value="<?= htmlspecialchars($edit_item['module_display_name'] ?? '') ?>" required>
            </div>
            
            <button type="submit" name="save_module" class="btn btn-save">
                <?= $edit_item ? 'UPDATE MODULE' : 'ADD NEW MODULE' ?>
            </button>
            <?php if ($edit_item): ?>
                <a href="module_manager.php" class="btn" style="background:#6c757d;">CANCEL</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card" style="padding: 0; overflow: hidden;">
        <table>
            <thead>
                <tr>
                    <th>System Key</th>
                    <th>Module Description</th>
                    <th>Status (Click to Toggle)</th>
                    <th class="action-cell">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($modules as $m): ?>
                <tr>
                    <td style="font-family: 'Courier New', monospace; color: #2c3e50;"><strong><?= htmlspecialchars($m['module_key']) ?></strong></td>
                    <td style="font-weight: 500;"><?= htmlspecialchars($m['module_display_name']) ?></td>
                    <td>
                        <a href="?toggle=<?= $m['id'] ?>&status=<?= $m['is_active'] ?>" 
                           class="status-badge status-<?= $m['is_active'] ?>" 
                           title="Flip Status">
                            <i class="fas fa-power-off"></i> <?= $m['is_active'] ? 'ACTIVE' : 'INACTIVE' ?>
                        </a>
                    </td>
                    <td class="action-cell">
                        <a href="?edit=<?= $m['id'] ?>" class="btn btn-edit"><i class="fas fa-edit"></i> Edit</a>
                        <a href="?delete=<?= $m['id'] ?>" class="btn btn-del" onclick="return confirm('Careful! This will remove the module entirely. Continue?')"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>