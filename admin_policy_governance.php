<?php
/**
 * Path: /admin_policy_governance.php
 * UI Engine: Multi-Tenant Governance Rules Control Panel
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Access Layer Validation: Restrict to Admins/Super Admins
if (!isset($_SESSION['user_id']) || (isset($_SESSION['user_level']) && (int)$_SESSION['user_level'] > 10)) {
    die("Access Denied: Administrative Clearance Required.");
}

$org_id = (int)($_SESSION['org_id'] ?? 1);
$msg = "";

// =========================================================================
// 📥 HANDLER: SAVE CHANGES ON FORM SUBMISSION
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_policies') {
    try {
        $pdo->beginTransaction();

        // 1. Save Organization Global Default Settings
        $global_geo = $_POST['global_geo_policy'] ?? 'allow_reason';
        $global_sched = $_POST['global_schedule_policy'] ?? 'allow_reason';
        $global_tol = number_format((float)($_POST['global_geo_tolerance_pct'] ?? 1.00), 2, '.', '');

        $keys = [
            'global_geo_policy' => $global_geo,
            'global_schedule_policy' => $global_sched,
            'global_geo_tolerance_pct' => $global_tol
        ];

        foreach ($keys as $k => $v) {
            $check = $pdo->prepare("SELECT id FROM Gem_Settings WHERE org_id = ? AND setting_key = ? LIMIT 1");
            $check->execute([$org_id, $k]);
            if ($check->fetch()) {
                $upd = $pdo->prepare("UPDATE Gem_Settings SET setting_value = ? WHERE org_id = ? AND setting_key = ?");
                $upd->execute([$v, $org_id, $k]);
            } else {
                $ins = $pdo->prepare("INSERT INTO Gem_Settings (setting_key, setting_value, org_id, province) VALUES (?, ?, ?, 'BC')");
                $ins->execute([$k, $v, $org_id]);
            }
        }

        // 2. Save Site-Specific Override Settings
        if (isset($_POST['site_overrides']) && is_array($_POST['site_overrides'])) {
            foreach ($_POST['site_overrides'] as $sid => $policy_pack) {
                $sid = (int)$sid;
                $s_geo = $policy_pack['geo_policy'] ?? 'use_global';
                $s_sched = $policy_pack['schedule_policy'] ?? 'use_global';
                $s_tol = number_format((float)($policy_pack['geo_tolerance_pct'] ?? 1.00), 2, '.', '');

                $site_keys = [
                    'site_geo_policy' => $s_geo,
                    'site_schedule_policy' => $s_sched,
                    'site_geo_tolerance_pct' => $s_tol
                ];

                foreach ($site_keys as $sk => $sv) {
                    // If set to use global default, wipe any active site-level overrides to clean up space
                    if ($sv === 'use_global') {
                        $del = $pdo->prepare("DELETE FROM Site_Settings WHERE site_id = ? AND setting_key = ?");
                        $del->execute([$sid, $sk]);
                        continue;
                    }

                    $checkS = $pdo->prepare("SELECT id FROM Site_Settings WHERE site_id = ? AND setting_key = ? LIMIT 1");
                    $checkS->execute([$sid, $sk]);
                    if ($checkS->fetch()) {
                        $updS = $pdo->prepare("UPDATE Site_Settings SET setting_value = ? WHERE site_id = ? AND setting_key = ?");
                        $updS->execute([$sv, $sid, $sk]);
                    } else {
                        $insS = $pdo->prepare("INSERT INTO Site_Settings (site_id, setting_key, setting_value) VALUES (?, ?, ?)");
                        $insS->execute([$sid, $sk, $sv]);
                    }
                }
            }
        }

        $pdo->commit();
        $msg = "<div class='alert-success'>✨ Governance policies successfully saved and propagated system-wide!</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "<div class='alert-danger'>❌ System Failure Saving Changes: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// =========================================================================
// 📊 DATA FETCH: LOAD EXISTING MATRIX CONFIGURATIONS
// =========================================================================
// Load Org-wide Global configurations
$gemStmt = $pdo->prepare("SELECT setting_key, setting_value FROM Gem_Settings WHERE org_id = ?");
$gemStmt->execute([$org_id]);
$global = $gemStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Load all Active physical Sites mapped to the organization
$sitesStmt = $pdo->prepare("SELECT Sid, nick_name, geo_radius_meters FROM sites WHERE owner_id = ? ORDER BY nick_name ASC");
$sitesStmt->execute([$org_id]);
$all_sites = $sitesStmt->fetchAll(PDO::FETCH_ASSOC);

// Load all currently stored overrides out of Site_Settings
$siteSetQuery = $pdo->query("SELECT site_id, setting_key, setting_value FROM Site_Settings");
$rawOverrides = $siteSetQuery->fetchAll(PDO::FETCH_ASSOC);
$overrides = [];
foreach ($rawOverrides as $row) {
    $overrides[$row['site_id']][$row['setting_key']] = $row['setting_value'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SaaS Compliance Rules Governance Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 40px; }
        .box { max-width: 1100px; margin: 0 auto; background: white; padding: 35px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; }
        h2 { margin-top:0; font-size: 22px; color: #0f172a; border-bottom: 2px solid #e2e8f0; padding-bottom: 12px; display:flex; align-items:center; gap:10px; }
        h3 { font-size:15px; text-transform:uppercase; color:#475569; letter-spacing:0.5px; margin-top:25px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; background: #f1f5f9; padding: 20px; border-radius: 8px; border: 1px solid #cbd5e1; }
        label { font-weight: 700; font-size: 12px; color: #334155; display:block; margin-bottom: 6px; text-transform: uppercase; }
        select, input { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; background: white; font-weight: bold; color:#1e293b; box-sizing: border-box; }
        table { width:100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #cbd5e1; padding: 12px; text-align: left; font-size:12px; }
        th { background: #475569; color: white; text-transform: uppercase; font-size: 10px; letter-spacing: 0.5px; }
        .alert-success { background:#dcfce7; color:#15803d; border: 1px solid #bbf7d0; padding:15px; border-radius:6px; font-weight:bold; margin-bottom:20px; }
        .alert-danger { background:#fee2e2; color:#991b1b; border: 1px solid #fecaca; padding:15px; border-radius:6px; font-weight:bold; margin-bottom:20px; }
        .btn-submit { background: #0f172a; color:white; border:none; padding:15px 30px; font-weight:bold; border-radius:6px; cursor:pointer; width:100%; margin-top:30px; text-transform:uppercase; letter-spacing:0.5px; font-size:14px; }
        .btn-submit:hover { background: #1e293b; }
        .badge-override { background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; font-weight: bold; font-size: 9px; }
    </style>
</head>
<body>

<div class="box">
    <h2><i class="fas fa-shield-halved" style="color:#2563eb;"></i> SaaS Policy Governance Desk</h2>
    <?= $msg ?>

    <form method="POST">
        <input type="hidden" name="action" value="update_policies">

        <h3>🏢 Corporate Global Default Threshold Criteria</h3>
        <div class="grid-3">
            <div>
                <label>Global Geofence Rule</label>
                <select name="global_geo_policy">
                    <option value="allow_reason" <?= ($global['global_geo_policy'] ?? '') === 'allow_reason'?'selected':'' ?>>Capture Justification Reason</option>
                    <option value="block" <?= ($global['global_geo_policy'] ?? '') === 'block'?'selected':'' ?>>Block Punch (Zero Tolerance)</option>
                    <option value="allow_free" <?= ($global['global_geo_policy'] ?? '') === 'allow_free'?'selected':'' ?>>Allow Free (No Restrictions)</option>
                </select>
            </div>
            <div>
                <label>Global Schedule Rule (5-Min Limit)</label>
                <select name="global_schedule_policy">
                    <option value="allow_reason" <?= ($global['global_schedule_policy'] ?? '') === 'allow_reason'?'selected':'' ?>>Capture Justification Reason</option>
                    <option value="block" <?= ($global['global_schedule_policy'] ?? '') === 'block'?'selected':'' ?>>Block Punch (Zero Tolerance)</option>
                    <option value="allow_free" <?= ($global['global_schedule_policy'] ?? '') === 'allow_free'?'selected':'' ?>>Allow Free (No Restrictions)</option>
                </select>
            </div>
            <div>
                <label>Global Distance Tolerance Buffer (%)</label>
                <input type="number" step="0.01" name="global_geo_tolerance_pct" value="<?= htmlspecialchars($global['global_geo_tolerance_pct'] ?? '1.00') ?>">
            </div>
        </div>

        <h3>📍 Location-Specific Override Criteria Rules Matrix</h3>
        <table>
            <thead>
                <tr>
                    <th>Site Profile Description</th>
                    <th width="280">Local Geofence Policy</th>
                    <th width="280">Local Schedule Policy</th>
                    <th width="160">Local Radius Tolerance Buffer (%)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_sites as $s): 
                    $sid = $s['Sid'];
                    $s_geo = $overrides[$sid]['site_geo_policy'] ?? 'use_global';
                    $s_sched = $overrides[$sid]['site_schedule_policy'] ?? 'use_global';
                    $s_tol = $overrides[$sid]['site_geo_tolerance_pct'] ?? '1.00';
                ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($s['nick_name']) ?></strong><br>
                        <small style="color:#64748b;">Base Perimeter: <?= $s['geo_radius_meters'] ?>m</small>
                        <?= ($s_geo !== 'use_global' || $s_sched !== 'use_global') ? '<span class="badge-override">Override Active</span>' : '' ?>
                    </td>
                    <td>
                        <select name="site_overrides[<?= $sid ?>][geo_policy]">
                            <option value="use_global" <?= $s_geo === 'use_global'?'selected':'' ?>>🌐 Use Company Default Policy</option>
                            <option value="allow_reason" <?= $s_geo === 'allow_reason'?'selected':'' ?>>Capture Justification Reason</option>
                            <option value="block" <?= $s_geo === 'block'?'selected':'' ?>>Block Punch (Zero Tolerance)</option>
                            <option value="allow_free" <?= $s_geo === 'allow_free'?'selected':'' ?>>Allow Free (No Restrictions)</option>
                        </select>
                    </td>
                    <td>
                        <select name="site_overrides[<?= $sid ?>][schedule_policy]">
                            <option value="use_global" <?= $s_sched === 'use_global'?'selected':'' ?>>🌐 Use Company Default Policy</option>
                            <option value="allow_reason" <?= $s_sched === 'allow_reason'?'selected':'' ?>>Capture Justification Reason</option>
                            <option value="block" <?= $s_sched === 'block'?'selected':'' ?>>Block Punch (Zero Tolerance)</option>
                            <option value="allow_free" <?= $s_sched === 'allow_free'?'selected':'' ?>>Allow Free (No Restrictions)</option>
                        </select>
                    </td>
                    <td>
                        <input type="number" step="0.01" name="site_overrides[<?= $sid ?>][geo_tolerance_pct]" value="<?= htmlspecialchars($s_tol) ?>">
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Save Governance Rules Matrix</button>
    </form>
</div>

</body>
</html>