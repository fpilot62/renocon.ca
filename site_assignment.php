<?php
/** Path: /site_assignment.php */
require_once 'db.php';
require_once 'engine/menu_generator.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. HANDLE ASSIGNMENT UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_assignment'])) {
    $site_id = (int)$_POST['site_id'];
    $new_org_id = (int)$_POST['new_org_id'];
    
    // Using 'SId' and 'org_id' to match your schema
    $update = $pdo->prepare("UPDATE sites SET org_id = ? WHERE SId = ?");
    $update->execute([$new_org_id, $site_id]);
    $msg = "Site #$site_id updated!";
}

// 2. FETCH DATA
// 1b. HANDLE NEW SITE CREATION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_site'])) {
    $name = $_POST['nick_name'];
    $org  = (int)$_POST['org_id'];
    
    if(!empty($name)) {
        $ins = $pdo->prepare("INSERT INTO sites (nick_name, org_id) VALUES (?, ?)");
        $ins->execute([$name, $org]);
        $msg = "New site '$name' created successfully!";
    }
}

$all_sites = $pdo->query("
    SELECT s.SId, s.nick_name, s.org_id, o.org_name 
    FROM sites s 
    LEFT JOIN organizations o ON s.org_id = o.id 
    ORDER BY s.nick_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$all_orgs = $pdo->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Assign Sites</title>
    <style>
        /* FIX: Pushes content down below the fixed menu */
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; padding-top: 110px; color: #1e293b; }
        
        .container { padding: 20px; max-width: 1100px; margin: 0 auto; }
        .site-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0; }
        .site-table th { background: #f8fafc; padding: 15px; text-align: left; font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 800; border-bottom: 1px solid #e2e8f0; }
        .site-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        
        .btn-update { background: #0f172a; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 700; font-size: 11px; }
        select { padding: 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px; width: 100%; max-width: 250px; }
        
        .alert { background: #dcfce7; color: #166534; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #bbf7d0; font-weight: bold; }
    </style>
</head>
<body>

<?= generateMenu($pdo); ?>

<div class="container">
   <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
    <h2 style="font-weight:900; margin: 0;">Site Assignment Manager</h2>
    
    <form method="POST" style="background: #fff; padding: 12px; border-radius: 10px; border: 1px solid #e2e8f0; display: flex; gap: 10px; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
        <input type="text" name="nick_name" placeholder="Site Name (e.g. 4. Kelowna)" required 
               style="padding: 8px; border-radius: 6px; border: 1px solid #cbd5e1; min-width: 200px; font-size: 13px;">
        
        <select name="org_id" required style="width: auto; padding: 7px; font-size: 13px;">
            <option value="">-- Assign Org --</option>
            <?php foreach ($all_orgs as $org): ?>
                <option value="<?= $org['id'] ?>"><?= htmlspecialchars($org['org_name']) ?></option>
            <?php endforeach; ?>
        </select>
        
        <button type="submit" name="create_site" class="btn-update" style="background: #0284c7; padding: 9px 15px;">+ ADD SITE</button>
    </form>
</div>
    
    <?php if(isset($msg)): ?>
        <div class="alert"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <table class="site-table">
        <thead>
            <tr>
                <th>Site ID</th>
                <th>Site Name</th>
                <th>Current Organization</th>
                <th>Update Assignment</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($all_sites as $site): ?>
            <tr>
                <td style="color:#94a3b8; font-weight:bold;">#<?= $site['SId'] ?></td>
                <td><strong><?= htmlspecialchars($site['nick_name']) ?></strong></td>
                <td>
                    <span style="color: <?= $site['org_name'] ? '#0f172a' : '#94a3b8' ?>; font-weight: 600;">
                        <?= $site['org_name'] ? htmlspecialchars($site['org_name']) : 'NOT ASSIGNED' ?>
                    </span>
                </td>
                <td>
                    <form method="POST" style="display:flex; gap:10px;">
                        <input type="hidden" name="site_id" value="<?= $site['SId'] ?>">
                        <select name="new_org_id">
                            <option value="0">-- Move to... --</option>
                            <?php foreach ($all_orgs as $org): ?>
                                <option value="<?= $org['id'] ?>" <?= ($site['org_id'] == $org['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($org['org_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="update_assignment" class="btn-update">SAVE</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>