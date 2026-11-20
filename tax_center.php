<?php
/** Path: /tax_center.php */
require_once 'db.php';
require_once 'engine/menu_generator.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. SECURITY GATE: Level 29 and below (Strictly Level 10 & 20)
$current_org_id = (int)($_SESSION['org_id'] ?? 0);
$user_lvl       = (int)($_SESSION['user_level'] ?? 99);

// Blocks Level 30 (Supervisors) and Level 40 (Staff)
if (!isset($_SESSION['user_id']) || $user_lvl >= 30) { 
    header("Location: login_kiosk.php?err=access_denied_tax"); 
    exit; 
}

// Define Superadmin status
$is_super = ($current_org_id === 100000 && $user_lvl <= 10);

$selected_year = $_GET['year'] ?? '2026';
$sel_site = $_GET['site_id'] ?? 'all';

// 2. FETCH SITES FOR DROPDOWN (RESTRICTED)
if ($is_super) {
    if (!function_exists('sip_org_site_fence')) { require_once __DIR__ . '/engine/site_status_lib.php'; }
$__fence = sip_org_site_fence($pdo, 'sip_org_id');
$is_super = $__fence['is_super']; $selected_org = $__fence['selected_org']; $orgs = $__fence['orgs']; $sites = $__fence['sites'];
    $sites_list = $sites;
} else {
    $sites_stmt = $pdo->prepare("SELECT SId, nick_name FROM sites WHERE org_id = ? ORDER BY nick_name ASC");
    $sites_stmt->execute([$current_org_id]);
    $sites_list = $sites_stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    // 3. FETCH USERS RESTRICTED BY ORG
    $query = "
        SELECT 
            u.id, 
            u.Name, 
            (SELECT site_id FROM attendance WHERE user_id = u.id ORDER BY clock_in DESC LIMIT 1) as latest_site_id,
            (SELECT s.nick_name FROM sites s JOIN attendance a ON s.SId = a.site_id WHERE a.user_id = u.id ORDER BY a.clock_in DESC LIMIT 1) as latest_site_name
        FROM users u 
        WHERE u.active = 1 
    ";

    $params = [];

    // If not superadmin, only show users who have worked at this Org's sites
    if (!$is_super) {
        $query .= " AND EXISTS (
            SELECT 1 FROM attendance att 
            JOIN sites st ON att.site_id = st.SId 
            WHERE att.user_id = u.id AND st.org_id = ?
        )";
        $params[] = $current_org_id;
    }

    if ($sel_site !== 'all') {
        $query .= " AND EXISTS (SELECT 1 FROM attendance WHERE user_id = u.id AND site_id = ?)";
        $params[] = $sel_site;
    }

    $query .= " ORDER BY u.Name ASC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tax Center <?= $selected_year ?> | SIP Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; padding-top: 130px; }
        .container { max-width: 1200px; margin: 30px auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        .filter-bar { background: #f8fafc; padding: 15px; border-radius: 8px; display: flex; gap: 15px; align-items: flex-end; margin-bottom: 20px; border: 1px solid #e2e8f0; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #0f172a; color: white; padding: 12px; text-align: left; font-size: 11px; text-transform: uppercase; }
        td { padding: 12px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        .btn-t4 { background: #2563eb; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 12px; transition: 0.2s; }
        .btn-t4:hover { background: #1d4ed8; }
        label { display: block; font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 5px; }
        select { padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; min-width: 180px; }
        .badge-site { background: #f1f5f9; color: #475569; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .sec-badge { background: #fee2e2; color: #b91c1c; padding: 4px 10px; border-radius: 6px; font-size: 10px; font-weight: 800; }
    </style>
</head>
<body>

<?= generateMenu($pdo); ?>

<div class="container">
    <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin:0; font-weight: 800;"><i class="fa fa-hand-holding-dollar" style="color:#2563eb;"></i> Tax & Compliance Center</h2>
        <span class="sec-badge"><i class="fa fa-lock"></i> SENSITIVE DATA: ADMIN LEVEL <?= $user_lvl ?></span>
    </div>

    <form method="GET" class="filter-bar">
        <div>
            <label>Reporting Year</label>
            <select name="year">
                <option value="2026" <?= $selected_year == '2026' ? 'selected' : '' ?>>2026</option>
                <option value="2025" <?= $selected_year == '2025' ? 'selected' : '' ?>>2025</option>
            </select>
        </div>
        <div>
            <label>Filter by Site</label>
            <?php if (!empty($is_super)): ?>
                <select name="org_id" onchange="this.form.submit()">
                    <option value="all" <?= (int)($selected_org ?? 0) === 0 ? 'selected' : '' ?>>All organizations</option>
                    <?php foreach (($orgs ?? []) as $o): ?>
                        <option value="<?= (int)$o['id'] ?>" <?= (int)($selected_org ?? 0) === (int)$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$o['org_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <select name="site_id">
                <option value="all">-- All <?= $is_super ? 'Global' : 'Your' ?> Sites --</option>
                <?php foreach($sites_list as $s): ?>
                    <option value="<?= $s['SId'] ?>" <?= $sel_site == $s['SId'] ? 'selected' : '' ?>><?= strtoupper($s['nick_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" style="background:#0f172a; color:white; border:none; padding:10px 25px; border-radius:6px; cursor:pointer; font-weight:bold;">Update List</button>
    </form>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Employee Name</th>
                <th>Site (Last Activity)</th>
                <th style="text-align: right;">Documents</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($users)): ?>
                <tr><td colspan="4" style="padding:100px; text-align:center; color:#94a3b8;">No employees found for this criteria.</td></tr>
            <?php else: ?>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><span style="color:#94a3b8; font-family: monospace;">#<?= $u['id'] ?></span></td>
                    <td><strong style="color: #1e293b;"><?= htmlspecialchars($u['Name']) ?></strong></td>
                    <td>
                        <?php if($u['latest_site_name']): ?>
                            <span class="badge-site"><?= htmlspecialchars($u['latest_site_name']) ?></span>
                        <?php else: ?>
                            <em style="color:#cbd5e1;">No local history</em>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;">
                        <?php if ($u['latest_site_id']): ?>
                            <a href="generate_t4.php?id=<?= $u['latest_site_id'] ?>&year=<?= $selected_year ?>&emp_id=<?= $u['id'] ?>" class="btn-t4">
                                <i class="fa fa-file-pdf"></i> Generate T4
                            </a>
                        <?php else: ?>
                            <span style="font-size:11px; color:#ef4444; font-weight: bold;">Missing Work History</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>