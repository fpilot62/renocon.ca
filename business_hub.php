<?php
/** Path: /business_hub.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once 'db.php';
require_once 'engine/menu_generator.php';

// 1. SECURITY & ORG LOGIC
$current_user_id = $_SESSION['user_id'] ?? null;
$current_org_id  = $_SESSION['org_id'] ?? 0;
$user_lvl        = (int)($_SESSION['user_level'] ?? 99);

// Allow Superadmin (10) and Admin (20)
if (!$current_user_id || $user_lvl > 20) {
    header("Location: login_kiosk.php?err=access_denied");
    exit;
}

// Superadmin status is determined by being in Org 100000
$is_super = ($current_org_id == 100000 && $user_lvl <= 10);
$status_msg = $_GET['msg'] ?? "";

// 2. LOGIC: ADD/UPDATE SITES
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_site'])) {
    $name = $_POST['nick_name'];
    $addr = $_POST['street_address'];
    $sid  = $_POST['site_id'] ?? null;
    
    // Security: If not super, ignore the posted org_id and force use of their own
    $target_org = $is_super ? ($_POST['target_org_id'] ?? 1) : $current_org_id;

    if ($sid) {
        // Additional security: Only let non-supers update sites belonging to their own org
        if (!$is_super) {
            $sql = "UPDATE sites SET nick_name = ?, street_address = ? WHERE SId = ? AND org_id = ?";
            $pdo->prepare($sql)->execute([$name, $addr, $sid, $current_org_id]);
        } else {
            $sql = "UPDATE sites SET nick_name = ?, street_address = ?, org_id = ? WHERE SId = ?";
            $pdo->prepare($sql)->execute([$name, $addr, $target_org, $sid]);
        }
    } else {
        $sql = "INSERT INTO sites (nick_name, street_address, org_id) VALUES (?, ?, ?)";
        $pdo->prepare($sql)->execute([$name, $addr, $target_org]);
    }
    header("Location: business_hub.php?msg=SiteUpdated");
    exit;
}

// 3. FETCH DATA
try {
    // Organizations List: Only fetch for display if Superadmin
    if ($is_super) {
        $org_rows = $pdo->query("SELECT * FROM organizations ORDER BY org_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Admins only get their own org info for the form labels
        $stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
        $stmt->execute([$current_org_id]);
        $org_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Sites List: Filter based on access level
    if ($is_super) {
        $site_sql = "SELECT s.*, o.org_name FROM sites s LEFT JOIN organizations o ON s.org_id = o.id ORDER BY s.nick_name ASC";
        $site_rows = $pdo->query($site_sql)->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("SELECT s.*, o.org_name FROM sites s LEFT JOIN organizations o ON s.org_id = o.id WHERE s.org_id = ? ORDER BY s.nick_name ASC");
        $stmt->execute([$current_org_id]);
        $site_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) { die("Hub Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Business Hub | SIP 2026</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; color: #1e293b; padding-top:120px; }
        .wrapper { max-width: 1400px; margin: 0 auto; padding: 0 20px; }
        /* Grid layout changes based on user level: 2 columns for Super, 1 column for Admin */
        .grid { display: grid; grid-template-columns: <?= $is_super ? '1fr 2fr' : '1fr' ?>; gap: 25px; align-items: start; }
        .card { background: white; border-radius: 12px; padding: 25px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        h2 { font-size: 18px; margin-top: 0; color: #0f172a; font-weight: 800; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th { text-align: left; font-size: 11px; color: #94a3b8; text-transform: uppercase; padding: 10px; border-bottom: 2px solid #f1f5f9; }
        td { padding: 12px 10px; font-size: 14px; border-bottom: 1px solid #f8fafc; }
        
        .form-group { margin-bottom: 15px; }
        label { display: block; font-size: 11px; font-weight: 800; color: #64748b; margin-bottom: 5px; text-transform: uppercase; }
        input, select { width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        input:focus { outline: none; border-color: #0097a7; box-shadow: 0 0 0 2px rgba(0,151,167,0.1); }
        
        .btn-primary { background: #0097a7; color: white; border: none; padding: 14px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; width: 100%; transition: 0.2s; }
        .btn-primary:hover { background: #007c8a; }
        .badge-org { background: #f1f5f9; padding: 4px 8px; border-radius: 4px; font-size: 11px; color: #64748b; font-weight: bold; }
    </style>
</head>
<body>

<?php echo generateMenu($pdo); ?>

<div class="wrapper">
    <?php if($status_msg): ?>
        <div style="background:#dcfce7; color:#15803d; padding:15px; border-radius:10px; margin-bottom:20px; font-weight:bold;">✔ Action Completed Successfully</div>
    <?php endif; ?>

    <div class="grid">
        <?php if ($is_super): ?>
        <div class="card">
            <h2>Organizations</h2>
            <p style="font-size:12px; color:#94a3b8;">The parent billing entities.</p>
            <table>
                <thead><tr><th>ID</th><th>Org Name</th></tr></thead>
                <tbody>
                    <?php foreach ($org_rows as $org): ?>
                    <tr>
                        <td>#<?= $org['id'] ?></td>
                        <td><strong><?= htmlspecialchars($org['org_name']) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h2>Site Locations</h2>
                <button onclick="resetForm()" style="background:#0f172a; color:white; border:none; padding:8px 15px; border-radius:6px; font-size:12px; cursor:pointer;">+ New Site</button>
            </div>
            
            <table>
                <thead><tr><th>Site Name</th><th>Address</th><th>Org</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($site_rows as $site): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($site['nick_name']) ?></strong></td>
                        <td style="font-size:12px; color:#64748b;"><?= htmlspecialchars($site['street_address'] ?: 'No Address') ?></td>
                        <td><span class="badge-org"><?= htmlspecialchars($site['org_name'] ?? 'N/A') ?></span></td>
                        <td>
                            <button onclick='editSite(<?= json_encode($site) ?>)' style="border:none; background:none; color:#0097a7; cursor:pointer; font-size:16px;"><i class="fa fa-edit"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <hr style="margin:40px 0; border:0; border-top:1px solid #f1f5f9;">

            <form method="POST" id="siteForm">
                <h2 id="formTitle" style="font-size: 22px;">Create/Edit Site</h2>
                <input type="hidden" name="site_id" id="f_sid">
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                    <div class="form-group">
                        <label>Site Name / Nickname</label>
                        <input type="text" name="nick_name" id="f_name" placeholder="e.g. Popeyes Calgary" required>
                    </div>
                    <div class="form-group">
                        <label>Assign to Organization</label>
                        <?php if($is_super): ?>
                            <select name="target_org_id" id="f_org">
                                <?php foreach($org_rows as $o): ?>
                                    <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['org_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" value="<?= htmlspecialchars($org_rows[0]['org_name'] ?? 'N/A') ?>" readonly style="background:#f8fafc; color:#94a3b8; cursor:not-allowed;">
                            <input type="hidden" name="target_org_id" value="<?= $current_org_id ?>">
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label>Street Address</label>
                    <input type="text" name="street_address" id="f_addr" placeholder="123 Street, City, Prov, PC">
                </div>

                <button type="submit" name="save_site" class="btn-primary">Save Site Location</button>
            </form>
        </div>
    </div>
</div>

<script>
function editSite(data) {
    document.getElementById('formTitle').innerText = "Editing: " + data.nick_name;
    document.getElementById('f_sid').value = data.SId;
    document.getElementById('f_name').value = data.nick_name;
    document.getElementById('f_addr').value = data.street_address;
    if (document.getElementById('f_org')) {
        document.getElementById('f_org').value = data.org_id;
    }
    window.scrollTo({ top: document.getElementById('siteForm').offsetTop - 150, behavior: 'smooth' });
}

function resetForm() {
    document.getElementById('formTitle').innerText = "Create New Site";
    document.getElementById('f_sid').value = "";
    document.getElementById('siteForm').reset();
}
</script>

</body>
</html>