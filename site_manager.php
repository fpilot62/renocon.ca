<?php
/** Path: /site_manager.php */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'engine/menu_generator.php';

// --- 1. SECURITY & PERMISSIONS ---
$current_user_id = $_SESSION['user_id'] ?? null;
$current_role    = isset($_SESSION['user_role']) ? (int)$_SESSION['user_role'] : 99;
$user_org_id     = isset($_SESSION['org_id'])    ? (int)$_SESSION['org_id'] : 0;

if (!$current_user_id || $current_role > 25) {
    header("Location: login.php?err=access_denied");
    exit;
}

// --- 2. UPDATE RADIUS LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_radius'])) {
    $newRadius = (int)$_POST['geo_radius_meters'];
    $siteId    = (int)$_POST['site_id'];
    
    if ($current_role <= 10) {
        $updateStmt = $pdo->prepare("UPDATE sites SET geo_radius_meters = ? WHERE SId = ?");
        $updateStmt->execute([$newRadius, $siteId]);
    } else {
        $updateStmt = $pdo->prepare("UPDATE sites SET geo_radius_meters = ? WHERE SId = ? AND org_id = ?");
        $updateStmt->execute([$newRadius, $siteId, $user_org_id]);
    }
    
    header("Location: site_manager.php?org_id=" . ($_GET['org_id'] ?? '') . "&success=1");
    exit;
}

// --- 3. DATA FETCHING (Using lat_in / lng_in from attendance) ---
if ($current_role <= 10) {
    $organizations = $pdo->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $filterOrg = $_GET['org_id'] ?? $user_org_id; 
} else {
    $stmt = $pdo->prepare("SELECT id, org_name FROM organizations WHERE id = ?");
    $stmt->execute([$user_org_id]);
    $organizations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $filterOrg = $user_org_id; 
}

$sites = [];
if ($filterOrg) {
    $sql = "SELECT s.SId, s.nick_name, s.target_lat, s.target_lng, s.geo_radius_meters,
            (SELECT lat_in FROM attendance WHERE site_id = s.SId AND lat_in IS NOT NULL AND lat_in != 0 ORDER BY clock_in DESC LIMIT 1) as live_lat,
            (SELECT lng_in FROM attendance WHERE site_id = s.SId AND lng_in IS NOT NULL AND lng_in != 0 ORDER BY clock_in DESC LIMIT 1) as live_lon
            FROM sites s 
            WHERE s.org_id = ? 
            ORDER BY s.nick_name ASC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$filterOrg]);
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getDistance($lat1, $lon1, $lat2, $lon2) {
    if (!$lat1 || !$lon1 || !$lat2 || !$lon2) return 0;
    $earth_radius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Site Accuracy & Geofence | SIP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; color: #1e293b; padding-top: 100px; }
        .wrapper { max-width: 1400px; margin: 0 auto; padding: 0 20px; }
        .header-card { background: #0f172a; color: white; padding: 30px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .header-card select { padding: 10px 15px; border-radius: 8px; border: none; font-weight: bold; cursor: pointer; min-width: 200px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 25px; padding-bottom: 50px; }
        .site-card { background: white; border-radius: 16px; padding: 25px; border-left: 8px solid #cbd5e1; position: relative; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .is-verified { border-left-color: #10b981; } 
        .is-failing { border-left-color: #ef4444; }
        .status-badge { position: absolute; top: 20px; right: 20px; padding: 6px 12px; border-radius: 20px; font-size: 10px; font-weight: 800; text-transform: uppercase; }
        .status-verified { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .status-warning { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .site-name { font-size: 24px; font-weight: 800; margin-bottom: 15px; color: #0f172a; }
        .radius-form { background: #f8fafc; padding: 15px; border-radius: 12px; display: flex; align-items: center; justify-content: space-between; margin-top: 20px; border: 1px solid #e2e8f0; }
        .radius-input { width: 70px; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-weight: 800; text-align: center; }
        .btn-save { background: #334155; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: bold; }
        .check-box { padding: 15px; border-radius: 12px; display: flex; align-items: center; gap: 15px; margin-top: 15px; }
        .status-ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .status-fail { background: #fff1f2; color: #991b1b; border: 1px solid #fecaca; }
    </style>
</head>
<body>

<?= generateMenu($pdo); ?>

<div class="wrapper">
    <div class="header-card">
        <div>
            <h2 style="margin:0;">Site Accuracy & Geofence</h2>
            <p style="margin:5px 0 0 0; opacity:0.8; font-size:14px;">Comparing Office Target vs. Latest Mobile App Punch.</p>
        </div>
        
        <?php if ($current_role <= 10): ?>
            <select onchange="location.href='site_manager.php?org_id=' + this.value;">
                <option value="">-- SELECT ORGANIZATION --</option>
                <?php foreach ($organizations as $o): ?>
                    <option value="<?= $o['id'] ?>" <?= $filterOrg == $o['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($o['org_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </div>

    <?php if ($filterOrg): ?>
    <div class="grid">
    <?php foreach ($sites as $site): 
        $hasLivePin = (!empty($site['live_lat']) && (float)$site['live_lat'] != 0);
        $currentRadius = (!empty($site['geo_radius_meters'])) ? (int)$site['geo_radius_meters'] : 15;
        
        $driftMeters = ($hasLivePin && !empty($site['target_lat'])) 
            ? getDistance($site['target_lat'], $site['target_lng'], $site['live_lat'], $site['live_lon']) 
            : 0;
            
        $isSecure = ($driftMeters <= $currentRadius);
    ?>
    <div class="site-card <?= (!$isSecure && $hasLivePin) ? 'is-failing' : ($hasLivePin ? 'is-verified' : '') ?>">
        
        <span class="status-badge <?= ($hasLivePin && $isSecure) ? 'status-verified' : 'status-warning' ?>">
            <?= $hasLivePin ? ($isSecure ? '✓ Verified Position' : '⚠️ Out of Bounds') : 'No App Data' ?>
        </span>

        <p class="site-name"><?= htmlspecialchars($site['nick_name']) ?></p>
        
        <div style="font-size:13px; color:#64748b; margin-bottom:15px;">
            <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f1f5f9;">
                <span>Office Target:</span> 
                <strong><?= number_format((float)($site['target_lat'] ?? 0), 4) ?>, <?= number_format((float)($site['target_lng'] ?? 0), 4) ?></strong>
            </div>
            <div style="display:flex; justify-content:space-between; padding:8px 0;">
                <span>Latest App Punch (IN):</span> 
                <strong><?= $hasLivePin ? number_format((float)$site['live_lat'], 4) . ', ' . number_format((float)$site['live_lon'], 4) : 'N/A' ?></strong>
            </div>
        </div>

        <form method="POST" class="radius-form">
            <input type="hidden" name="site_id" value="<?= $site['SId'] ?>">
            <label style="font-size: 10px; font-weight: 800; color: #64748b;">GEOFENCE (M):</label>
            <input type="number" name="geo_radius_meters" class="radius-input" value="<?= $currentRadius ?>">
            <button type="submit" name="update_radius" class="btn-save">Update</button>
        </form>

        <div class="check-box <?= ($hasLivePin && !$isSecure) ? 'status-fail' : 'status-ok' ?>">
            <i class="fa <?= ($hasLivePin && !$isSecure) ? 'fa-circle-xmark' : 'fa-shield-halved' ?>" style="font-size: 20px;"></i>
            <div>
                <strong style="font-size:14px;">Security Status</strong><br>
                <small>
                    <?php if ($hasLivePin): ?>
                        Drift: <strong><?= number_format($driftMeters, 2) ?>m</strong> / Allowed: <?= $currentRadius ?>m
                    <?php else: ?>
                        Awaiting mobile clock-in to verify site accuracy.
                    <?php endif; ?>
                </small>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php else: ?>
        <div style="text-align:center; padding: 100px; color: #64748b;">
            <i class="fa fa-folder-open" style="font-size: 50px; opacity: 0.3;"></i>
            <p>Select an organization to audit site accuracy.</p>
        </div>
    <?php endif; ?>
</div>
</body>
</html>