<?php
/** Path: /timeoff_requests.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once 'sip/db_config.php'; 
require_once 'auth_bridge.php';

// 1. DATABASE CONNECTION
try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    $pdo_bridge = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) { die("Connection Failed: " . $e->getMessage()); }

// 2. SECURITY & ROLE CHECK (Supervisor and above <= 30)
$my_id   = $_SESSION['user_id'] ?? 0;
$my_lvl  = isset($_SESSION['user_level']) ? (int)$_SESSION['user_level'] : 99;
$my_oid  = isset($_SESSION['org_id']) ? (int)$_SESSION['org_id'] : 0;

if ($my_id == 0 || $my_lvl > 30) { 
    header("Location: kiosk.php?err=Unauthorized");
    exit; 
}

$is_super = ($my_oid === 100000 && $my_lvl <= 10);

// 3. HANDLE ACTIONS (Approve/Deny) - Securely filtered by Org
if (isset($_GET['action']) && isset($_GET['req_id'])) {
    $status = ($_GET['action'] === 'approve') ? 'approved' : 'denied';
    $req_id = (int)$_GET['req_id'];

    // Ensure a Manager can only approve/deny requests within their own Org
    $update_sql = "UPDATE time_off_requests SET status = ? WHERE id = ?";
    if (!$is_super) { $update_sql .= " AND org_id = ?"; }
    
    $stmt = $pdo_bridge->prepare($update_sql);
    $params = [$status, $req_id];
    if (!$is_super) { $params[] = $my_oid; }
    
    $stmt->execute($params);
    header("Location: timeoff_requests.php?msg=StatusUpdated");
    exit;
}

// 4. PREPARE FILTERS
$selected_org  = $_GET['org_id'] ?? 'all';
$selected_site = $_GET['site_id'] ?? 'all';

// Fetch Dropdown Data
if ($is_super) {
    $org_list = $pdo_bridge->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC")->fetchAll();
    $sites = $pdo_bridge->query("SELECT SId, nick_name FROM sites ORDER BY nick_name ASC")->fetchAll();
} else {
    $org_list = []; 
    $stmt = $pdo_bridge->prepare("SELECT SId, nick_name FROM sites WHERE org_id = ? ORDER BY nick_name ASC");
    $stmt->execute([$my_oid]);
    $sites = $stmt->fetchAll();
}

// 5. MAIN DATA FETCH (The Multi-Tenant Wall)
$sql = "SELECT r.*, u.Name, s.nick_name, o.org_name
        FROM time_off_requests r 
        LEFT JOIN users u ON r.user_id = u.id 
        LEFT JOIN sites s ON r.site_id = s.SId 
        LEFT JOIN organizations o ON r.org_id = o.id
        WHERE 1=1";

$query_params = [];

if (!$is_super) {
    $sql .= " AND r.org_id = ?";
    $query_params[] = $my_oid;
} elseif ($selected_org !== 'all') {
    $sql .= " AND r.org_id = ?";
    $query_params[] = (int)$selected_org;
}

if ($selected_site !== 'all') { 
    $sql .= " AND r.site_id = ?"; 
    $query_params[] = (int)$selected_site; 
}

$sql .= " ORDER BY (r.status='pending') DESC, r.id DESC";
$stmt = $pdo_bridge->prepare($sql);
$stmt->execute($query_params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once 'engine/menu_generator.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Time Off Management | SIP</title>
    <style>
        :root { --accent: #0097a7; --dark: #1e293b; --border: #e2e8f0; }
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; padding-top: 80px; }
        .card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); max-width: 1400px; margin: 0 auto; }
        .filter-bar { display: flex; gap: 15px; margin-bottom: 25px; background: #ffffff; padding: 15px; border-radius: 8px; border: 1px solid var(--border); align-items: center; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; background: var(--dark); color: white; padding: 15px; font-size: 11px; text-transform: uppercase; }
        td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
        .status { font-weight: 800; text-transform: uppercase; font-size: 10px; padding: 4px 10px; border-radius: 20px; display: inline-block; border: 1px solid; }
        .pending { background: #fffbeb; color: #92400e; border-color: #fef3c7; }
        .approved { background: #ecfdf5; color: #065f46; border-color: #d1fae5; }
        .denied { background: #fef2f2; color: #991b1b; border-color: #fee2e2; }
        .btn-action { text-decoration: none; font-weight: 800; font-size: 11px; padding: 5px 10px; border-radius: 4px; transition: 0.2s; }
    </style>
</head>
<body>

<?= generateMenu($pdo_bridge); ?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin:0; font-weight: 800; color: var(--dark);">Time Off Management</h2>
        <span style="font-size: 13px; font-weight: 600; color: #94a3b8;"><?= count($requests) ?> Requests</span>
    </div>

    <form method="GET" class="filter-bar">
        <?php if ($is_super): ?>
        <select name="org_id" onchange="this.form.submit()">
            <option value="all">-- All Organizations --</option>
            <?php foreach ($org_list as $o): ?>
                <option value="<?= $o['id'] ?>" <?= $selected_org == $o['id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['org_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <select name="site_id" onchange="this.form.submit()">
            <option value="all">-- All Sites --</option>
            <?php foreach ($sites as $s): ?>
                <option value="<?= $s['SId'] ?>" <?= $selected_site == $s['SId'] ? 'selected' : '' ?>><?= htmlspecialchars($s['nick_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <a href="timeoff_requests.php" style="font-size: 12px; color: var(--accent); font-weight: bold; text-decoration: none;">Reset</a>
    </form>

    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Site</th>
                <th>Dates</th>
                <th>Status</th>
                <th style="text-align: right;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($requests)): ?>
                <tr><td colspan="5" style="text-align:center; padding: 50px; color: #94a3b8;">No requests found for your organization.</td></tr>
            <?php else: foreach ($requests as $r): ?>
                <tr>
                    <td><strong style="color: var(--dark);"><?= htmlspecialchars($r['Name'] ?? 'Unknown') ?></strong></td>
                    <td><?= htmlspecialchars($r['nick_name'] ?? 'General') ?></td>
                    <td><?= date("M d", strtotime($r['start_date'])) ?> - <?= date("M d, Y", strtotime($r['end_date'])) ?></td>
                    <td><span class="status <?= $r['status'] ?>"><?= $r['status'] ?></span></td>
                    <td style="text-align: right;">
                        <?php if($r['status'] === 'pending'): ?>
                            <a href="?action=approve&req_id=<?= $r['id'] ?>" class="btn-action" style="color:#059669; border:1px solid #059669; margin-right:5px;">APPROVE</a>
                            <a href="?action=deny&req_id=<?= $r['id'] ?>" class="btn-action" style="color:#dc2626; border:1px solid #dc2626;">DENY</a>
                        <?php else: ?>
                            <span style="color: #94a3b8; font-size: 11px;">PROCESSED</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>