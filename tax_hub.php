<?php
/** Path: /tax_hub.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'engine/menu_generator.php';

// 1. Get the Organization ID from the Session
// We use (int) to ensure it is a number and prevent any text-based hacking
$current_org_id = (int)($_SESSION['org_id'] ?? 0);

// 2. Identify if the user is a Superadmin
// If they are level 10 or less, they bypass the fence
$is_super = ((int)($_SESSION['user_level'] ?? 99) <= 10);

try {
    // 1. Capture Session Data
    $current_org_id = (int)($_SESSION['org_id'] ?? 0);
    $user_lvl       = (int)($_SESSION['user_level'] ?? 99);
    
    // Define Superadmin: Role 10 or less
    $is_super = ($user_lvl <= 10);

    // 2. FETCH SITES (For the Dropdown)
    if ($is_super) {
        if (!function_exists('sip_org_site_fence')) { require_once __DIR__ . '/engine/site_status_lib.php'; }
$__fence = sip_org_site_fence($pdo, 'sip_org_id');
$is_super = $__fence['is_super']; $selected_org = $__fence['selected_org']; $orgs = $__fence['orgs']; $sites = $__fence['sites'];

    } else {
        // Lock dropdown to the user's Organization
        $stmt_s = $pdo->prepare("SELECT SId, nick_name FROM sites WHERE org_id = ? ORDER BY nick_name ASC");
        $stmt_s->execute([$current_org_id]);
        $sites = $stmt_s->fetchAll(PDO::FETCH_ASSOC);
    }

    // 3. BUILD THE USER QUERY
    $params = [];
    $where  = ["u.active = 1"];

    // THE FENCE: If not a Superadmin, force the Org ID check
    if (!$is_super) {
        $where[]  = "u.org_id = ?";
        $params[] = $current_org_id;
    }

    // 4. THE SITE FILTER (Optional)
    if ($sel_site !== 'all') {
        // We use a subquery to see if the user has EVER worked at the selected site
        $where[]  = "u.id IN (SELECT DISTINCT user_id FROM attendance WHERE site_id = ?)";
        $params[] = (int)$sel_site;
    }

    // 5. ASSEMBLE SQL
    $sql = "SELECT u.id, u.Name, u.sin, u.street_address, u.city, u.postal_code,
            (SELECT a.site_id FROM attendance a WHERE a.user_id = u.id ORDER BY a.clock_in DESC LIMIT 1) as last_sid,
            (SELECT s.nick_name FROM sites s JOIN attendance a ON s.SId = a.site_id WHERE a.user_id = u.id ORDER BY a.clock_in DESC LIMIT 1) as last_sname
            FROM users u 
            WHERE " . implode(" AND ", $where) . " 
            ORDER BY u.Name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) { 
    die("Tax Hub Query Failed: " . $e->getMessage()); 
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Tax & Compliance Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; color: #1e293b;  padding-top:50px;}
        .container { max-width: 1300px; margin: 30px auto; padding: 0 20px; }
        .card { background: white; border-radius: 12px; padding: 25px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        
        .filter-bar { display: flex; gap: 15px; align-items: flex-end; margin-bottom: 25px; background: #f8fafc; padding: 15px; border-radius: 10px; border: 1px solid #e2e8f0; }
        label { display: block; font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 5px; }
        
        table { width: 100%; border-collapse: collapse; }
        th { background: #1e293b; color: white; padding: 12px; text-align: left; font-size: 11px; text-transform: uppercase; }
        td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; }
        .ready { background: #dcfce7; color: #166534; }
        .missing { background: #fee2e2; color: #991b1b; }
        
        .btn-gen { background: #2563eb; color: white; padding: 8px 15px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 12px; }
        .btn-gen.disabled { background: #cbd5e1; cursor: not-allowed; pointer-events: none; }
    </style>
</head>
<body>

<?php echo generateMenu($pdo); ?>

<div class="container">
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2 style="margin:0;">Tax & Compliance Hub</h2>
            <div style="text-align:right;">
                <a href="employee_hub.php" style="font-size:13px; color:#3b82f6; text-decoration:none;"><i class="fa fa-users"></i> Edit Employee Info</a>
            </div>
        </div>

        <form method="GET" class="filter-bar">
            <div>
                <label>Year</label>
                <select name="year" style="padding:8px; border-radius:6px; border:1px solid #cbd5e1;">
                    <option value="2026" <?= $selected_year=='2026'?'selected':'' ?>>2026</option>
                    <option value="2025" <?= $selected_year=='2025'?'selected':'' ?>>2025</option>
                </select>
            </div>
            <div style="flex-grow:1;">
                <label>Site Filter</label>
                <?php if (!empty($is_super)): ?>
                <select name="org_id" onchange="this.form.submit()">
                    <option value="all" <?= (int)($selected_org ?? 0) === 0 ? 'selected' : '' ?>>All organizations</option>
                    <?php foreach (($orgs ?? []) as $o): ?>
                        <option value="<?= (int)$o['id'] ?>" <?= (int)($selected_org ?? 0) === (int)$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$o['org_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <select name="site_id" style="width:100%; padding:8px; border-radius:6px; border:1px solid #cbd5e1;">
                    <option value="all">All Sites</option>
                    <?php foreach($sites as $s): ?>
                        <option value="<?= $s['SId'] ?>" <?= $sel_site==$s['SId']?'selected':'' ?>><?= $s['nick_name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" style="background:#0f172a; color:white; border:none; padding:9px 25px; border-radius:6px; cursor:pointer; font-weight:bold;">Update List</button>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Employee Name</th>
                    <th>Compliance Status</th>
                    <th>Missing Fields</th>
                    <th>Filing Site</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($users as $u): 
                    $missing = [];
                    if(!$u['sin']) $missing[] = "SIN";
                    if(!$u['street_address']) $missing[] = "Address";
                    if(!$u['city']) $missing[] = "City";
                    if(!$u['postal_code']) $missing[] = "Postal";
                    
                    $is_ready = empty($missing);
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($u['Name']) ?></strong></td>
                    <td>
                        <span class="status-badge <?= $is_ready ? 'ready' : 'missing' ?>">
                            <?= $is_ready ? '<i class="fa fa-check"></i> READY' : '<i class="fa fa-warning"></i> INCOMPLETE' ?>
                        </span>
                    </td>
                    <td style="font-size:11px; color:#ef4444; font-weight:bold;">
                        <?= implode(', ', $missing) ?>
                    </td>
                    <td><span style="font-size:11px; color:#64748b; font-weight:bold;"><?= $u['last_sname'] ?: 'No History' ?></span></td>
                    <td style="text-align: right;">
                        <?php if($u['last_sid']): ?>
                            <a href="generate_t4.php?id=<?= $u['last_sid'] ?>&year=<?= $selected_year ?>&emp_id=<?= $u['id'] ?>" 
                               class="btn-gen <?= !$is_ready ? 'disabled' : '' ?>" target="_blank">
                               Generate T4
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>