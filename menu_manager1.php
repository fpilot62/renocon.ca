<?php
/** Path: /menu_manager.php */
ob_start(); 
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php'; 
require_once 'engine/menu_generator1.php'; 

// 0. FETCH DYNAMIC ROLES FOR THE DROPDOWN & LOOKUP
$role_stmt = $pdo->query("SELECT role_id, role_name FROM roles ORDER BY role_id ASC");
$roles_list = $role_stmt->fetchAll(PDO::FETCH_ASSOC);
$role_lookup = array_column($roles_list, 'role_name', 'role_id');

// 1. Quick Toggle for IsActive
if (isset($_GET['toggle_id'])) {
    $stmt = $pdo->prepare("UPDATE menu_manager SET IsActive = NOT IsActive WHERE MenuID = ?");
    $stmt->execute([(int)$_GET['toggle_id']]);
    header("Location: menu_manager.php"); exit;
}

// 2. Delete Logic
if (isset($_GET['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM menu_manager WHERE MenuID = ?");
    $stmt->execute([(int)$_GET['delete_id']]);
    header("Location: menu_manager.php"); exit;
}

// 3. Fetch data for editing
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_data = ['MenuID'=>'','MenuTitle'=>'','ParentID'=>0,'LinkURL'=>'','description'=>'','SortOrder'=>0,'IsActive'=>1,'Module'=>'','UserRole'=>40];

if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM menu_manager WHERE MenuID = ?");
    $stmt->execute([$edit_id]);
    $edit_data = $stmt->fetch(PDO::FETCH_ASSOC) ?: $edit_data;
}

// 4. Save/Update Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_menu'])) {
    $isActive = isset($_POST['IsActive']) ? 1 : 0;
    $params = [
        $_POST['MenuTitle'], (int)$_POST['ParentID'], $_POST['LinkURL'], 
        $_POST['description'], 
        (int)$_POST['SortOrder'], $isActive, $_POST['Module'], (int)$_POST['UserRole']
    ];

    if (!empty($_POST['MenuID'])) {
        $params[] = $_POST['MenuID'];
        $stmt = $pdo->prepare("UPDATE menu_manager SET MenuTitle=?, ParentID=?, LinkURL=?, description=?, SortOrder=?, IsActive=?, Module=?, UserRole=? WHERE MenuID=?");
    } else {
        $stmt = $pdo->prepare("INSERT INTO menu_manager (MenuTitle, ParentID, LinkURL, description, SortOrder, IsActive, Module, UserRole) VALUES (?,?,?,?,?,?,?,?)");
    }
    $stmt->execute($params);
    header("Location: menu_manager.php"); exit;
}

// 5. SORTING LOGIC (Restored)
$valid_columns = ['MenuTitle', 'ParentTitle', 'LinkURL', 'Module', 'UserRole', 'IsActive', 'SortOrder'];
$sort = in_array($_GET['sort'] ?? '', $valid_columns) ? $_GET['sort'] : 'SortOrder';
$dir = ($_GET['dir'] ?? '') === 'desc' ? 'DESC' : 'ASC';
$next_dir = ($dir === 'ASC') ? 'desc' : 'asc';

// 6. FETCH LIST WITH PARENT JOIN
$list_sql = "SELECT m1.*, m2.MenuTitle as ParentTitle 
             FROM menu_manager m1 
             LEFT JOIN menu_manager m2 ON m1.ParentID = m2.MenuID 
             ORDER BY $sort $dir";
$list = $pdo->query($list_sql)->fetchAll();

// 7. HELPER FOR SORTABLE HEADERS
function sortLink($col, $label, $current_sort, $next_dir) {
    $icon = ($current_sort === $col) ? ($next_dir === 'asc' ? ' <i class="fa-solid fa-caret-down"></i>' : ' <i class="fa-solid fa-caret-up"></i>') : ' <i class="fa-solid fa-sort" style="opacity:0.2"></i>';
    return "<a href='?sort=$col&dir=$next_dir' class='sort-link'>$label$icon</a>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Menu Manager | SIP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; padding-top: 130px; color: #1e293b; }
        .main-container { max-width: 1450px; margin: 0 auto; padding: 0 25px; }
        .form-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 30px; border: 1px solid #e2e8f0; }
        .form-card.editing { border-top: 4px solid #f59e0b; background: #fffcf5; }
        .compact-grid { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .field-group { flex: 1; min-width: 150px; }
        label { font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 5px; }
        input, select { width: 100%; padding: 8px 12px; height: 40px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; }
        .btn-save { background: #0ea5e9; color: white; border: none; height: 40px; padding: 0 25px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s; }
        .btn-save:hover { background: #0284c7; }
        .m-table { width: 100%; background: white; border-collapse: collapse; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
        .m-table th { background: #f1f5f9; padding: 15px; text-align: left; font-size: 11px; color: #64748b; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; }
        .m-table td { padding: 14px 15px; border-bottom: 1px solid #f1f5f9; font-size: 13px; vertical-align: middle; }
        .sort-link { color: #64748b; text-decoration: none !important; display: flex; align-items: center; justify-content: space-between; }
        .sort-link:hover { color: #0ea5e9; }
        .badge { padding: 5px 10px; border-radius: 6px; font-size: 10px; font-weight: 800; text-decoration: none !important; }
        .bg-green { background: #dcfce7; color: #166534; }
        .bg-red { background: #fee2e2; color: #991b1b; }
        .parent-tag { font-size: 10px; color: #94a3b8; background: #f1f5f9; padding: 3px 8px; border-radius: 5px; font-weight: 700; }
        code { color: #0ea5e9; background: #f0f9ff; padding: 2px 5px; border-radius: 4px; font-size: 12px; }
        .desc-text { font-size: 11px; color: #64748b; font-style: italic; max-width: 250px; display: block; line-height: 1.4; }
    </style>
</head>
<body>

<?php echo generateMenu($pdo); ?>

<div class="main-container">
    <div class="form-card <?= $edit_id ? 'editing' : '' ?>">
        <form method="POST">
            <input type="hidden" name="MenuID" value="<?= $edit_data['MenuID'] ?>">
            <div class="compact-grid">
                <div class="field-group"><label>Title</label><input type="text" name="MenuTitle" value="<?= htmlspecialchars($edit_data['MenuTitle']) ?>" required></div>
                <div class="field-group"><label>URL Link</label><input type="text" name="LinkURL" value="<?= htmlspecialchars($edit_data['LinkURL']) ?>"></div>
                <div class="field-group" style="flex:1.5;"><label>Description</label><input type="text" name="description" value="<?= htmlspecialchars($edit_data['description']) ?>"></div>
                
                <div class="field-group">
                    <label>Parent</label>
                    <select name="ParentID">
                        <option value="0">Top Level (Main Nav)</option>
                        <?php 
                        $parents = $pdo->query("SELECT MenuID, MenuTitle FROM menu_manager WHERE ParentID = 0")->fetchAll();
                        foreach($parents as $p) {
                            $selected = ($edit_data['ParentID'] == $p['MenuID']) ? 'selected' : '';
                            echo "<option value='{$p['MenuID']}' $selected>{$p['MenuTitle']}</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="field-group" style="flex:0.5;"><label><?= sortLink('Module', 'Module', $sort, $next_dir) ?></label><input type="text" name="Module" value="<?= htmlspecialchars($edit_data['Module']) ?>"></div>
                
                <div class="field-group">
                    <label>Min. Role</label>
                    <select name="UserRole">
                        <?php foreach($roles_list as $role): ?>
                            <option value="<?= $role['role_id'] ?>" <?= $edit_data['UserRole'] == $role['role_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($role['role_name']) ?> (<?= $role['role_id'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field-group" style="flex:0.3;"><label>Sort</label><input type="number" name="SortOrder" value="<?= $edit_data['SortOrder'] ?>"></div>
                <div class="field-group" style="flex:0.2; text-align:center;">
                    <label>Active</label>
                    <input type="checkbox" name="IsActive" style="width:20px; height:20px;" <?= $edit_data['IsActive'] ? 'checked' : '' ?>>
                </div>
                <div>
                    <button type="submit" name="save_menu" class="btn-save shadow-sm"><?= $edit_id ? 'UPDATE ITEM' : 'SAVE ITEM' ?></button>
                    <?php if($edit_id): ?><a href="menu_manager.php" class="btn btn-light ml-2">CANCEL</a><?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <table class="m-table">
        <thead>
            <tr>
                <th><?= sortLink('MenuTitle', 'Menu Title', $sort, $next_dir) ?></th>
                <th>Description</th>
                <th><?= sortLink('ParentTitle', 'Parent Group', $sort, $next_dir) ?></th>
                <th><?= sortLink('LinkURL', 'Link (URL)', $sort, $next_dir) ?></th>
                <th><?= sortLink('UserRole', 'Min. Role', $sort, $next_dir) ?></th>
                <th><?= sortLink('IsActive', 'Status', $sort, $next_dir) ?></th>
                <th style="text-align:right; padding-right:25px;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($list as $row): ?>
            <tr>
                <td>
                    <?php if($row['ParentID'] > 0): ?> <span style="color: #cbd5e1;">↳</span> <?php endif; ?>
                    <b><?= htmlspecialchars($row['MenuTitle']) ?></b>
                </td>
                <td><span class="desc-text"><?= htmlspecialchars($row['description']) ?></span></td>
                <td>
                    <span class="parent-tag <?= $row['ParentID'] == 0 ? 'bg-primary text-white' : '' ?>">
                        <?= $row['ParentID'] == 0 ? 'TOP LEVEL' : htmlspecialchars($row['ParentTitle']) ?>
                    </span>
                </td>
                <td><code><?= htmlspecialchars($row['LinkURL']) ?: '/' ?></code></td>
                <td><small class="badge badge-light border"><?= htmlspecialchars($role_lookup[$row['UserRole']] ?? $row['UserRole']) ?></small></td>
                <td>
                    <a href="?toggle_id=<?= $row['MenuID'] ?>" class="badge <?= $row['IsActive'] ? 'bg-green' : 'bg-red' ?>">
                        <?= $row['IsActive'] ? 'ACTIVE' : 'INACTIVE' ?>
                    </a>
                </td>
                <td style="text-align:right; padding-right:25px;">
                    <a href="?edit=<?= $row['MenuID'] ?>" class="btn btn-sm btn-outline-info font-weight-bold mr-2 px-3">EDIT</a>
                    <a href="?delete_id=<?= $row['MenuID'] ?>" class="text-muted" onclick="return confirm('Delete forever?')"><i class="fa-solid fa-trash-can"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>