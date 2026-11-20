<?php
/** Path: /submit_timeoff.php */
ob_start();
session_start();
require_once 'db.php'; 

// 1. Session & Permission Check
if (!isset($_SESSION['user_id'])) { 
    header("Location: login_kiosk.php"); 
    exit; 
}

$uid = $_SESSION['user_id'];
$user_level = $_SESSION['user_level'] ?? 0; // Level 10+ = Super Admin

try {
    // 2. Fetch User Info (Name, Assigned Sites, and Org ID)
    $u_stmt = $pdo->prepare("SELECT Name, assigned_site_ids, org_id FROM users WHERE id = ?");
    $u_stmt->execute([$uid]);
    $user_info = $u_stmt->fetch();
    
    if (!$user_info) { die("User profile not found."); }

    $my_org = $user_info['org_id'] ?? 0;
    $allowed_sites = [];

    // 3. Organization Isolation Logic
    if ($user_level >= 10) {
        // Super Admin: Full list of all organizations' sites
        if (!function_exists('sip_org_site_fence')) { require_once __DIR__ . '/engine/site_status_lib.php'; }
$__fence = sip_org_site_fence($pdo, 'sip_org_id');
$is_super = $__fence['is_super']; $selected_org = $__fence['selected_org']; $orgs = $__fence['orgs']; $sites = $__fence['sites'];
        $allowed_sites = $sites;
    } else {
        // Site Managers & Admins: Only see assigned sites WITHIN their organization
        $site_ids_raw = $user_info['assigned_site_ids'] ?? '';
        
        // Clean spaces and split comma-separated IDs into an array
        $site_ids_array = array_filter(array_map('trim', explode(',', $site_ids_raw)));
        
        if (!empty($site_ids_array)) {
            $placeholders = implode(',', array_fill(0, count($site_ids_array), '?'));
            
            // Query ensures SId is in their list AND belongs to their org_id
            $s_stmt = $pdo->prepare("SELECT SId, nick_name FROM sites 
                                     WHERE SId IN ($placeholders) 
                                     AND org_id = ? 
                                     ORDER BY nick_name ASC");
            
            // Execute with the array of site IDs plus the org_id as the final parameter
            $params = array_merge($site_ids_array, [$my_org]);
            $s_stmt->execute($params);
            $allowed_sites = $s_stmt->fetchAll();
        }
    }
} catch (Exception $e) { 
    die("Permission Error: " . $e->getMessage()); 
}

// 4. Include the Top Bar Menu
include 'engine/menu_generator.php'; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <!-- pwa-head -->
    <?php if (file_exists(__DIR__ . '/pwa_head.php')) include __DIR__ . '/pwa_head.php'; ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Time Off</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; color: #1e293b; margin: 0; padding: 0; }
        .container { padding: 20px; text-align: center; }
        .form-card { max-width: 500px; margin: 40px auto; background: white; padding: 30px; border-radius: 20px; box-shadow: 0 10px 15px rgba(0,0,0,0.1); }
        select, input, textarea { 
            width: 100%; 
            padding: 12px; 
            margin-top: 10px; 
            border-radius: 8px; 
            border: 1px solid #cbd5e1; 
            font-size: 16px; 
            box-sizing: border-box; 
        }
        label { display: block; text-align: left; font-weight: 600; margin-top: 15px; color: #475569; }
        .btn-submit { 
            width: 100%; 
            padding: 15px; 
            border: none; 
            border-radius: 10px; 
            font-size: 18px; 
            font-weight: bold; 
            cursor: pointer; 
            color: white; 
            background: #2563eb; 
            margin-top: 25px; 
            transition: background 0.2s;
        }
        .btn-submit:hover { background: #1d4ed8; }
        .user-badge { color: #64748b; font-size: 0.9em; margin-bottom: 20px; }
    </style>
</head>
<body>

<?= generateMenu($pdo); ?>
<div class="container">
    <div class="form-card">
        <h2>Request Time Off</h2>
        <div class="user-badge">
            Logged in as: <strong><?= htmlspecialchars($user_info['Name'] ?? 'User') ?></strong>
        </div>

        <form action="process_timeoff.php" method="POST">
            <label for="site_id">Work Site</label>
            <?php if (!empty($is_super)): ?>
                <select name="org_id" onchange="this.form.submit()">
                    <option value="all" <?= (int)($selected_org ?? 0) === 0 ? 'selected' : '' ?>>All organizations</option>
                    <?php foreach (($orgs ?? []) as $o): ?>
                        <option value="<?= (int)$o['id'] ?>" <?= (int)($selected_org ?? 0) === (int)$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$o['org_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <select name="site_id" id="site_id" required>
                <option value="">-- Choose Your Site --</option>
                <?php if (empty($allowed_sites)): ?>
                    <option value="" disabled>No sites assigned to your organization</option>
                <?php else: ?>
                    <?php foreach ($allowed_sites as $site): ?>
                        <option value="<?= $site['SId'] ?>"><?= htmlspecialchars($site['nick_name']) ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>

            <label for="start_date">Start Date</label>
            <input type="date" name="start_date" id="start_date" required>

            <label for="end_date">End Date</label>
            <input type="date" name="end_date" id="end_date" required>

            <label for="reason">Reason / Comments</label>
            <textarea name="reason" id="reason" rows="3" placeholder="Optional details..."></textarea>

            <button type="submit" class="btn-submit">SUBMIT REQUEST</button>
        </form>
    </div>
</div>

</body>
</html>