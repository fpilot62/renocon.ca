<?php
/** Path: /site_manager.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'engine/menu_generator.php';

$filterOrg = $_GET['org_id'] ?? ($_SESSION['org_id'] == 100000 ? '' : $_SESSION['org_id']);
$is_super = ($_SESSION['org_id'] == 100000);

// 1. SAVE GPS UPDATES
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_gps'])) {
    $stmt = $pdo->prepare("UPDATE sites SET lat = ?, lon = ? WHERE SId = ?");
    $stmt->execute([$_POST['lat'], $_POST['lon'], $_POST['sid']]);
    header("Location: site_manager.php?org_id=$filterOrg&msg=GPS+Updated");
    exit;
}

// 2. DATA FETCHING
$organizations = $pdo->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$sites = [];
if ($filterOrg) {
    $stmt = $pdo->prepare("SELECT SId, nick_name, module, lat, lon FROM sites WHERE org_id = ? ORDER BY nick_name ASC");
    $stmt->execute([$filterOrg]);
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Site Verification & GPS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; color: #1e293b; }
        .wrapper { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
        .header-card { background: #0f172a; color: white; padding: 25px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 25px; }
        .site-card { background: white; border-radius: 16px; padding: 25px; border: 1px solid #e2e8f0; position: relative; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .verified-border { border-top: 6px solid #10b981; }
        .site-name { font-size: 20px; font-weight: 800; margin: 0 0 5px 0; }
        .gps-input { width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: bold; font-family: monospace; font-size: 13px; }
        .btn-save { background: #0097a7; color: white; border: none; padding: 10px; width: 100%; border-radius: 8px; font-weight: bold; cursor: pointer; margin-top: 10px; }
        .module-tag { font-size: 10px; background: #f0fdf4; color: #15803d; padding: 4px 8px; border-radius: 4px; font-weight: 800; border: 1px solid #d1fae5; }
    </style>
</head>
<body>

<?php echo generateMenu($pdo); ?>

<div class="wrapper">
    <div class="header-card">
        <div>
            <h2 style="margin:0;">Geofencing & Verification</h2>
            <p style="margin:5px 0 0 0; opacity:0.7; font-size:14px;">Set GPS coordinates for site clock-in validation.</p>
        </div>
        <select onchange="location.href='site_manager.php?org_id=' + this.value;" style="padding:10px; border-radius:8px; font-weight:bold;">
            <option value="">-- SELECT ORGANIZATION --</option>
            <?php foreach ($organizations as $o): ?>
                <option value="<?= $o['id'] ?>" <?= $filterOrg == $o['id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['org_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($filterOrg): ?>
    <div class="grid">
        <?php foreach ($sites as $site): 
            $rawModules = $site['module'] ?? '';
            $isVerified = !empty(trim($rawModules));
        ?>
        <div class="site-card <?= $isVerified ? 'verified-border' : '' ?>">
            <p class="site-name"><?= htmlspecialchars($site['nick_name']) ?></p>
            <span style="color:#94a3b8; font-size:11px; font-weight:bold;">ID: #<?= $site['SId'] ?></span>

            <form method="POST" style="margin-top:20px;">
                <input type="hidden" name="sid" value="<?= $site['SId'] ?>">
                <div style="display:flex; gap:10px; margin-bottom:10px;">
                    <div style="flex:1;">
                        <label style="font-size:9px; font-weight:800; color:#94a3b8;">LATITUDE</label>
                        <input type="text" name="lat" class="gps-input" value="<?= number_format((float)$site['lat'], 6) ?>">
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:9px; font-weight:800; color:#94a3b8;">LONGITUDE</label>
                        <input type="text" name="lon" class="gps-input" value="<?= number_format((float)$site['lon'], 6) ?>">
                    </div>
                </div>
                <button type="submit" name="save_gps" class="btn-save">Update GPS Radius</button>
            </form>

            <div style="margin-top:15px; display:flex; gap:5px; flex-wrap:wrap;">
                <?php if($isVerified): ?>
                    <?php foreach(explode(',', $rawModules) as $m): ?>
                        <span class="module-tag"><?= strtoupper(trim($m)) ?></span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span style="font-size:10px; color:#ef4444; font-weight:bold;">NO MODULES ENABLED</span>
                <?php endif; ?>
            </div>
            
            <a href="saas_manager.php?org_id=<?= $filterOrg ?>" style="display:block; text-align:center; margin-top:15px; font-size:11px; color:#3b82f6; text-decoration:none; font-weight:bold;">
                <i class="fa fa-cog"></i> Change Subscriptions
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

</body>
</html>