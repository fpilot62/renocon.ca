<?php
/** Path: /menu_manager.php */
ob_start(); 
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php'; 
require_once 'engine/menu_generator.php'; 

try {
    $pdo->exec("ALTER TABLE menu_manager ADD COLUMN NavBar VARCHAR(10) NOT NULL DEFAULT 'main'");
} catch (Throwable $e) {
}

$mini_n = (int)$pdo->query("SELECT COUNT(*) FROM menu_manager WHERE NavBar = 'mini'")->fetchColumn();
if ($mini_n === 0) {
    $pdo->exec("UPDATE menu_manager SET NavBar = 'main' WHERE NavBar IS NULL OR NavBar = ''");
    $items = $pdo->query("SELECT * FROM menu_manager")->fetchAll(PDO::FETCH_ASSOC);
    $id_map = [];
    $orig_parent = [];
    foreach ($items as $row) {
        $old_id = (int)$row['MenuID'];
        $orig_parent[$old_id] = (int)($row['ParentID'] ?? 0);
        unset($row['MenuID']);
        $row['NavBar'] = 'mini';
        $fields = array_keys($row);
        $sql = 'INSERT INTO menu_manager (' . implode(',', $fields) . ') VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')';
        $ins = $pdo->prepare($sql);
        $ins->execute(array_values($row));
        $id_map[$old_id] = (int)$pdo->lastInsertId();
    }
    $upd = $pdo->prepare("UPDATE menu_manager SET ParentID = ? WHERE MenuID = ?");
    foreach ($id_map as $old_id => $new_id) {
        $old_parent = $orig_parent[$old_id] ?? 0;
        $upd->execute([$old_parent ? ($id_map[$old_parent] ?? 0) : 0, $new_id]);
    }
}

// --- 0. SETTINGS & LOOKUPS ---
$current_parent_filter = isset($_GET['parent_search']) ? (int)$_GET['parent_search'] : -1;
$bar_filter = $_GET['bar'] ?? '';
if ($bar_filter !== 'main' && $bar_filter !== 'mini') {
    $bar_filter = '';
}
$bar_q = $bar_filter ? '&bar=' . urlencode($bar_filter) : '';

$role_stmt = $pdo->query("SELECT role_id, role_name FROM roles ORDER BY role_id ASC");
$roles_list = $role_stmt->fetchAll(PDO::FETCH_ASSOC);
$role_lookup = array_column($roles_list, 'role_name', 'role_id');

// --- 1. TOGGLE ACTIVE STATE ---
if (isset($_GET['toggle_id'])) {
    $stmt = $pdo->prepare("UPDATE menu_manager SET IsActive = NOT IsActive WHERE MenuID = ?");
    $stmt->execute([(int)$_GET['toggle_id']]);
    header("Location: menu_manager.php?parent_search=" . $current_parent_filter . $bar_q); 
    exit;
}

// --- 2. DELETE LOGIC ---
if (isset($_GET['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM menu_manager WHERE MenuID = ?");
    $stmt->execute([(int)$_GET['delete_id']]);
    header("Location: menu_manager.php?parent_search=" . $current_parent_filter . $bar_q); 
    exit;
}

// --- 3. FETCH DATA FOR EDITING ---
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_data = ['MenuID'=>'','MenuTitle'=>'','ParentID'=>0,'LinkURL'=>'','description'=>'','SortOrder'=>0,'IsActive'=>1,'Module'=>'','UserRole'=>40,'NavBar'=>'main'];

if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM menu_manager WHERE MenuID = ?");
    $stmt->execute([$edit_id]);
    $edit_data = $stmt->fetch(PDO::FETCH_ASSOC) ?: $edit_data;
}

// --- 4. SAVE / UPDATE LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_menu'])) {
    $isActive = isset($_POST['IsActive']) ? 1 : 0;
    $pf = (int)$_POST['parent_state']; // Carry the filter state from the hidden input
    $bf = (($_POST['bar_state'] ?? '') === 'mini') ? 'mini' : ((($_POST['bar_state'] ?? '') === 'main') ? 'main' : '');
    $nav_bar = (($_POST['NavBar'] ?? 'main') === 'mini') ? 'mini' : 'main';
    
    $params = [
        $_POST['MenuTitle'], (int)$_POST['ParentID'], $_POST['LinkURL'], 
        $_POST['description'], (int)$_POST['SortOrder'], $isActive, 
        $_POST['Module'], (int)$_POST['UserRole'], $nav_bar
    ];

    if (!empty($_POST['MenuID'])) {
        $params[] = $_POST['MenuID'];
        $stmt = $pdo->prepare("UPDATE menu_manager SET MenuTitle=?, ParentID=?, LinkURL=?, description=?, SortOrder=?, IsActive=?, Module=?, UserRole=?, NavBar=? WHERE MenuID=?");
    } else {
        $stmt = $pdo->prepare("INSERT INTO menu_manager (MenuTitle, ParentID, LinkURL, description, SortOrder, IsActive, Module, UserRole, NavBar) VALUES (?,?,?,?,?,?,?,?,?)");
    }
    $stmt->execute($params);
    header("Location: menu_manager.php?parent_search=" . $pf . ($bf ? '&bar=' . $bf : '')); 
    exit;
}

// --- 5. SORTING & LIST FETCHING ---
$valid_columns = ['MenuTitle', 'ParentTitle', 'LinkURL', 'Module', 'UserRole', 'IsActive', 'SortOrder', 'NavBar'];
$sort = in_array($_GET['sort'] ?? '', $valid_columns) ? $_GET['sort'] : 'SortOrder';
$dir = ($_GET['dir'] ?? '') === 'desc' ? 'DESC' : 'ASC';
$next_dir = ($dir === 'ASC') ? 'desc' : 'asc';

// Build the Filter SQL
$where_sql = "";
$query_params = [];
$wheres = [];
if ($current_parent_filter !== -1) {
    $wheres[] = "m1.ParentID = ?";
    $query_params[] = $current_parent_filter;
}
if ($bar_filter !== '') {
    $wheres[] = "m1.NavBar = ?";
    $query_params[] = $bar_filter;
}
if ($wheres) {
    $where_sql = " WHERE " . implode(' AND ', $wheres) . " ";
}

$list_sql = "SELECT m1.*, m2.MenuTitle as ParentTitle 
             FROM menu_manager m1 
             LEFT JOIN menu_manager m2 ON m1.ParentID = m2.MenuID 
             $where_sql
             ORDER BY $sort $dir";

$stmt = $pdo->prepare($list_sql);
$stmt->execute($query_params);
$list = $stmt->fetchAll();

// --- 6. HELPER FUNCTIONS ---
function sortLink($col, $label, $current_sort, $next_dir, $pf, $bar = '') {
    $icon = ($current_sort === $col) ? ($next_dir === 'asc' ? ' <i class="fa-solid fa-caret-down"></i>' : ' <i class="fa-solid fa-caret-up"></i>') : ' <i class="fa-solid fa-sort" style="opacity:0.2"></i>';
    $bar_q = ($bar === 'main' || $bar === 'mini') ? "&bar=$bar" : '';
    return "<a href='?sort=$col&dir=$next_dir&parent_search=$pf$bar_q' class='sort-link'>$label$icon</a>";
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
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; padding-top: 85px !important; color: #1e293b; }
        .main-container { max-width: 1550px; margin: 0 auto; padding: 20px 25px; }
        .form-card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 25px; border: 1px solid #e2e8f0; }
        .form-card.editing { border-left: 5px solid #f59e0b; background: #fffcf5; }
        .compact-grid { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .field-group { flex: 1; min-width: 140px; }
        label { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 4px; }
        input, select { width: 100%; padding: 6px 10px; height: 38px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; }
        .btn-save { background: #0ea5e9; color: white; border: none; height: 38px; padding: 0 20px; border-radius: 6px; font-weight: 700; transition: 0.2s; }
        .btn-save:hover { background: #0284c7; }
        .m-table { width: 100%; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border-collapse: collapse; }
        .m-table th { background: #f1f5f9; padding: 12px 15px; font-size: 11px; color: #64748b; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; }
        .m-table td { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; font-size: 13px; vertical-align: middle; }
        .sort-link { color: #64748b; text-decoration: none !important; display: flex; align-items: center; justify-content: space-between; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 10px; font-weight: 800; text-decoration: none !important; }
        .bg-green { background: #dcfce7; color: #166534; }
        .bg-red { background: #fee2e2; color: #991b1b; }
        .parent-tag { font-size: 10px; color: #94a3b8; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-weight: 700; }
        code { color: #0ea5e9; background: #f0f9ff; padding: 2px 4px; border-radius: 4px; font-size: 11px; }
        .desc-text { font-size: 11px; color: #94a3b8; font-style: italic; }
        .min-role-lock { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; border-radius: 10px; padding: 12px 16px; margin: 0 0 18px; font-size: 13px; line-height: 1.45; }
        .min-role-lock strong { display: block; font-size: 11px; letter-spacing: .04em; text-transform: uppercase; margin-bottom: 4px; }
        .min-role-lock-note { display: block; margin-top: 4px; color: #0f766e; font-size: 10px; line-height: 1.35; font-weight: 600; }
    </style>
</head>
<body>

<?= generateMenu($pdo); ?>

<div class="main-container">
    <div class="min-role-lock">
        <strong>Locked rule — Min. Role</strong>
        A person sees a menu link only when the link is Active and their role number is less than or equal to Min. Role
        (<code>UserRole &gt;= login role</code>). Staff (40) cannot see Supervisor (30) or Admin (20) links.
        Superadmin still sees everything because their number is 10. The Module box is a label only — it must not hide a link that already passed Min. Role.
        This rule is stored in <code>engine/MENU_MIN_ROLE.php</code> and must not be overruled.
        <strong style="margin-top:10px;">Main bar vs Mini bar</strong>
        Each link belongs to the desktop <em>Main bar</em> or the phone <em>Mini bar</em>. The mini bar is a copy of the menu — turn items Inactive or delete them on Mini bar to slim the phone nav. Phones default to Mini bar; use the Main bar / Mini bar button to switch.
    </div>
    <div class="form-card <?= $edit_id ? 'editing' : '' ?>">
        <div class="d-flex align-items-center mb-4 pb-3" style="border-bottom: 1px solid #f1f5f9;">
            <div style="font-size: 11px; font-weight: 900; color: #1e293b; text-transform: uppercase; margin-right: 15px;">
                <i class="fa-solid fa-filter text-info mr-1"></i> Current Group View:
            </div>
            <form method="GET" class="d-flex align-items-center">
                <select name="bar" style="width: 160px; font-weight: 700; margin-right: 10px;" onchange="this.form.submit()">
                    <option value="" <?= $bar_filter === '' ? 'selected' : '' ?>>ALL BARS</option>
                    <option value="main" <?= $bar_filter === 'main' ? 'selected' : '' ?>>MAIN BAR</option>
                    <option value="mini" <?= $bar_filter === 'mini' ? 'selected' : '' ?>>MINI BAR</option>
                </select>
                <select name="parent_search" style="width: 260px; font-weight: 700;" onchange="this.form.submit()">
                    <option value="-1" <?= $current_parent_filter === -1 ? 'selected' : '' ?>>--- SHOW ALL ITEMS ---</option>
                    <option value="0" <?= $current_parent_filter === 0 ? 'selected' : '' ?>>TOP LEVEL</option>
                    <?php 
                    $parent_sql = "SELECT MenuID, MenuTitle, NavBar FROM menu_manager WHERE ParentID = 0";
                    if ($bar_filter !== '') {
                        $parent_sql .= " AND NavBar = " . $pdo->quote($bar_filter);
                    }
                    $parent_sql .= " ORDER BY NavBar ASC, MenuTitle ASC";
                    $search_parents = $pdo->query($parent_sql)->fetchAll();
                    foreach($search_parents as $sp): ?>
                        <option value="<?= $sp['MenuID'] ?>" <?= $current_parent_filter == $sp['MenuID'] ? 'selected' : '' ?>>
                            <?= strtoupper($sp['NavBar'] ?? 'main') === 'MINI' ? 'MINI' : 'MAIN' ?> · CHILDREN OF: <?= strtoupper($sp['MenuTitle']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if($current_parent_filter !== -1 || $bar_filter !== ''): ?>
                    <a href="menu_manager.php" class="ml-3 small font-weight-bold text-danger">Clear Filter</a>
                <?php endif; ?>
            </form>
        </div>

        <form method="POST">
            <input type="hidden" name="MenuID" value="<?= $edit_data['MenuID'] ?>">
            <input type="hidden" name="parent_state" value="<?= $current_parent_filter ?>">
            <input type="hidden" name="bar_state" value="<?= htmlspecialchars($bar_filter) ?>">
            
            <div class="compact-grid">
                <div class="field-group"><label>Title</label><input type="text" name="MenuTitle" value="<?= htmlspecialchars($edit_data['MenuTitle']) ?>" required></div>
                <div class="field-group"><label>Link URL</label><input type="text" name="LinkURL" value="<?= htmlspecialchars($edit_data['LinkURL']) ?>"></div>
                <div class="field-group" style="flex:1.5;"><label>Description</label><input type="text" name="description" value="<?= htmlspecialchars($edit_data['description']) ?>"></div>
                
                <div class="field-group">
                    <label>Parent Category</label>
                    <select name="ParentID">
                        <option value="0">Top Level (Standalone)</option>
                        <?php
                        $all_parents = $pdo->query("SELECT MenuID, MenuTitle, NavBar FROM menu_manager WHERE ParentID = 0 ORDER BY NavBar ASC, MenuTitle ASC")->fetchAll();
                        foreach($all_parents as $p): ?>
                            <option value="<?= $p['MenuID'] ?>" <?= $edit_data['ParentID'] == $p['MenuID'] ? 'selected' : '' ?>>
                                <?= (($p['NavBar'] ?? 'main') === 'mini' ? 'Mini' : 'Main') ?> · <?= htmlspecialchars($p['MenuTitle']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field-group" style="flex:0.6;">
                    <label>Bar</label>
                    <select name="NavBar">
                        <option value="main" <?= ($edit_data['NavBar'] ?? 'main') !== 'mini' ? 'selected' : '' ?>>Main bar</option>
                        <option value="mini" <?= ($edit_data['NavBar'] ?? '') === 'mini' ? 'selected' : '' ?>>Mini bar</option>
                    </select>
                </div>

                <div class="field-group"><label>Min. Role</label>
                    <select name="UserRole">
                        <?php foreach($roles_list as $role): ?>
                            <option value="<?= $role['role_id'] ?>" <?= $edit_data['UserRole'] == $role['role_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($role['role_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="min-role-lock-note">Locked: weakest role that can see this link. Stronger roles (smaller numbers) also see it. Staff cannot see Supervisor links. Do not overrule this with Module.</small>
                </div>

                <div class="field-group" style="flex:0.4;"><label>Module</label><input type="text" name="Module" value="<?= htmlspecialchars($edit_data['Module']) ?>" title="Label only — does not hide a link that passed Min. Role">
                    <small class="min-role-lock-note">Label only. Does not hide the link.</small>
                </div>
                <div class="field-group" style="flex:0.3;"><label>Sort</label><input type="number" name="SortOrder" value="<?= $edit_data['SortOrder'] ?>"></div>
                
                <div class="field-group" style="flex:0.2; text-align:center;">
                    <label>Active</label>
                    <input type="checkbox" name="IsActive" style="width:18px; height:18px;" <?= $edit_data['IsActive'] ? 'checked' : '' ?>>
                </div>

                <div style="min-width: 180px;">
                    <button type="submit" name="save_menu" class="btn-save shadow-sm"><?= $edit_id ? 'UPDATE ITEM' : 'ADD TO MENU' ?></button>
                    <?php if($edit_id): ?><a href="menu_manager.php?parent_search=<?= $current_parent_filter ?><?= $bar_q ?>" class="ml-2 small text-muted">Cancel</a><?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <table class="m-table">
        <thead>
            <tr>
                <th><?= sortLink('MenuTitle', 'Title', $sort, $next_dir, $current_parent_filter, $bar_filter) ?></th>
                <th>Description</th>
                <th><?= sortLink('ParentTitle', 'Parent', $sort, $next_dir, $current_parent_filter, $bar_filter) ?></th>
                <th><?= sortLink('NavBar', 'Bar', $sort, $next_dir, $current_parent_filter, $bar_filter) ?></th>
                <th>URL Path</th>
                <th><?= sortLink('UserRole', 'Role', $sort, $next_dir, $current_parent_filter, $bar_filter) ?></th>
                <th><?= sortLink('IsActive', 'Status', $sort, $next_dir, $current_parent_filter, $bar_filter) ?></th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($list as $row): ?>
            <tr>
                <td style="font-weight:700;">
                    <?php if($row['ParentID'] > 0): ?> <span style="color: #cbd5e1; font-weight: 300;">↳</span> <?php endif; ?>
                    <?= htmlspecialchars($row['MenuTitle']) ?>
                </td>
                <td><span class="desc-text"><?= htmlspecialchars($row['description']) ?></span></td>
                <td>
                    <span class="parent-tag <?= $row['ParentID'] == 0 ? 'text-info' : '' ?>">
                        <?= $row['ParentID'] == 0 ? 'TOP' : htmlspecialchars($row['ParentTitle']) ?>
                    </span>
                </td>
                <td>
                    <span class="badge <?= ($row['NavBar'] ?? 'main') === 'mini' ? 'bg-red' : 'bg-green' ?>">
                        <?= ($row['NavBar'] ?? 'main') === 'mini' ? 'MINI BAR' : 'MAIN BAR' ?>
                    </span>
                </td>
                <td><code><?= htmlspecialchars($row['LinkURL']) ?: '/' ?></code></td>
                <td><small class="badge badge-light border"><?= htmlspecialchars($role_lookup[$row['UserRole']] ?? $row['UserRole']) ?></small></td>
                <td>
                    <a href="?toggle_id=<?= $row['MenuID'] ?>&parent_search=<?= $current_parent_filter ?><?= $bar_q ?>" class="badge <?= $row['IsActive'] ? 'bg-green' : 'bg-red' ?>">
                        <?= $row['IsActive'] ? 'ACTIVE' : 'INACTIVE' ?>
                    </a>
                </td>
                <td style="text-align:right;">
                    <a href="?edit=<?= $row['MenuID'] ?>&parent_search=<?= $current_parent_filter ?><?= $bar_q ?>" class="btn btn-sm btn-outline-info font-weight-bold px-3 mr-1">EDIT</a>
                    <a href="?delete_id=<?= $row['MenuID'] ?>&parent_search=<?= $current_parent_filter ?><?= $bar_q ?>" class="text-danger ml-2" onclick="return confirm('Delete this menu item?')"><i class="fa-solid fa-trash"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($list)): ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">No menu items found in this group.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>