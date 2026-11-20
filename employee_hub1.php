<?php
ob_start(); 
/** * Path: /employee_hub.php 
 * FEATURES: Bulk Updates, Superadmin Org-Switching, and SaaS Admin Provisioning.
 */

ini_set('display_errors', 1); error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php'; 
require_once 'engine/menu_generator.php';

// 1. SECURITY & PERMISSIONS
$user_level = (int)($_SESSION['user_level'] ?? 99);
$session_org = (int)($_SESSION['org_id'] ?? 0);
$is_super = ($user_level <= 10);

if ($user_level > 30) { header("Location: kiosk.php?err=Unauthorized"); exit; }
if(!defined('IS_DEMO')) define('IS_DEMO', false);

// 2. BACKEND ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (IS_DEMO) { header("Location: employee_hub.php?err=demo_restricted"); exit; }

    // --- BULK SITE UPDATE ---
    if (isset($_POST['bulk_update_sites'])) {
        $user_ids = $_POST['selected_users'] ?? [];
        $new_sites = isset($_POST['bulk_allocated_sites']) ? implode(',', $_POST['bulk_allocated_sites']) : '';
        if (!empty($user_ids)) {
            $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
            $sql = "UPDATE users SET assigned_site_ids = ? WHERE id IN ($placeholders)";
            $pdo->prepare($sql)->execute(array_merge([$new_sites], $user_ids));
            header("Location: employee_hub.php?msg=bulk_saved"); exit;
        }
    }

    // --- SAVE/UPDATE USER ---
    $uid = $_POST['user_id'] ?? null;
    if (isset($_POST['update_user']) || isset($_POST['add_user'])) {
        $sites_str = isset($_POST['allocated_sites']) ? implode(',', $_POST['allocated_sites']) : '';
        $primary_site = !empty($_POST['primary_restaurant_id']) ? $_POST['primary_restaurant_id'] : NULL;
        $target_org = !empty($_POST['org_id']) ? (int)$_POST['org_id'] : $session_org;

        $params = [
            $_POST['name'], $_POST['email'], $_POST['user'], $_POST['pin'], 
            (float)$_POST['hourly_rate'], (float)$_POST['official_rate'], 
            (int)$_POST['user_role'], (int)($_POST['active'] ?? 1), 
            $sites_str, $primary_site, $_POST['remarks'], $target_org
        ];

        try {
            if (isset($_POST['update_user'])) {
                $sql = "UPDATE users SET Name=?, email=?, user=?, pin=?, hourly_rate=?, official_rate=?, 
                        user_role=?, active=?, assigned_site_ids=?, primary_restaurant_id=?, Remarks=?, org_id=?";
                if(!empty($_POST['password'])) { 
                    $sql .= ", password = ?"; $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT); 
                }
                $sql .= " WHERE id = ?"; $params[] = $uid;
                $pdo->prepare($sql)->execute($params);
            } else {
                $sql = "INSERT INTO users (Name, email, user, pin, hourly_rate, official_rate, user_role, active, 
                        assigned_site_ids, primary_restaurant_id, Remarks, org_id, password) 
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";
                $params[] = password_hash($_POST['password'] ?: '1234', PASSWORD_DEFAULT);
                $pdo->prepare($sql)->execute($params);
            }
            header("Location: employee_hub.php?msg=saved"); exit;
        } catch (PDOException $e) {
            die("Database Error: " . $e->getMessage());
        }
    }
}

// 3. DATA FETCHING
$user_where = $is_super ? "1=1" : " u.org_id = $session_org ";
$users = $pdo->query("SELECT u.*, r.role_name FROM users u LEFT JOIN roles r ON u.user_role = r.role_id WHERE $user_where ORDER BY u.Name ASC")->fetchAll(PDO::FETCH_ASSOC);
$all_roles = $pdo->query("SELECT role_id, role_name FROM roles ORDER BY role_id ASC")->fetchAll(PDO::FETCH_ASSOC);
$orgs = $pdo->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC")->fetchAll(PDO::FETCH_ASSOC);
// Fetch ALL sites so JS can filter them based on the selected Org in the modal
$all_sites_js = $pdo->query("SELECT SId, nick_name, org_id FROM sites ORDER BY nick_name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Employee Hub | crewPulse</title>
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; padding-top: 100px; }
        .container { max-width: 1600px; margin: auto; padding: 20px; }
        .audit-table { width: 100%; background: white; border-radius: 12px; border-collapse: collapse; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .audit-table th { background: #f8fafc; padding: 15px; text-align: left; font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; }
        .audit-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.9); z-index:9999; justify-content:center; align-items:center; }
        .modal-content { background:white; padding:40px; border-radius:20px; width:1000px; max-height:90vh; overflow-y:auto; }
        .form-group label { display:block; font-size:10px; font-weight:800; color:#64748b; margin-bottom:5px; text-transform:uppercase; }
        .form-group input, .form-group select, .form-group textarea { width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px; font-size: 14px; }
        .bulk-bar { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%); background: #1e293b; color: white; padding: 15px 35px; border-radius: 50px; display: none; z-index: 10000; box-shadow: 0 10px 30px rgba(0,0,0,0.5); align-items: center; gap: 20px; }
        .site-group-box { transition: 0.3s opacity; }
    </style>
</head>
<body>

<?php echo generateMenu($pdo); ?>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 style="font-weight:900; letter-spacing:-1.5px;">Employee Hub</h1>
            <input type="text" id="hubSearch" class="form-control" style="width:350px; border-radius:50px;" placeholder="Search employees..." onkeyup="filterHub()">
        </div>
        <button class="btn btn-success" style="padding:15px 30px; font-weight:800; border-radius:12px;" onclick="openAdd()">+ NEW STAFF</button>
    </div>

    <form id="mainForm">
    <table class="audit-table" id="hubTable">
        <thead>
            <tr>
                <th width="40"><input type="checkbox" onclick="toggleAll(this)"></th>
                <th>Employee</th>
                <th>Assigned Site IDs</th>
                <th>Role</th>
                <th>Rate</th>
                <th>Status</th>
                <th class="text-right">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($users as $u): ?>
            <tr class="staff-row">
                <td><input type="checkbox" name="selected_users[]" value="<?= $u['id'] ?>" class="user-cb" onchange="checkBulk()"></td>
                <td><strong><?= htmlspecialchars($u['Name']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($u['email']) ?></small></td>
                <td style="font-size:11px; color:#2563eb; font-weight:700;"><i class="fa fa-map-pin"></i> <?= htmlspecialchars($u['assigned_site_ids'] ?: 'None') ?></td>
                <td><span class="badge badge-light" style="font-size:10px;"><?= strtoupper($u['role_name'] ?? 'Staff') ?></span></td>
                <td><strong>$<?= number_format($u['hourly_rate'], 2) ?></strong></td>
                <td><span style="font-weight:800; color:<?= $u['active'] ? '#16a34a' : '#dc2626' ?>;"><?= $u['active'] ? 'ACTIVE' : 'OFF' ?></span></td>
                <td class="text-right"><button type="button" class="btn btn-dark btn-sm" onclick="editUser(<?= htmlspecialchars(json_encode($u)) ?>)">EDIT</button></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </form>
</div>

<div id="editOverlay" class="modal-overlay">
    <div class="modal-content shadow-lg">
        <h2 id="mTitle" style="font-weight:900; margin-bottom:25px;">Employee Profile</h2>
        <form method="POST">
            <input type="hidden" name="user_id" id="f_id">
            
            <div class="form-group mb-4">
                <label style="color:#2563eb; font-weight:900;">Organization Scope</label>
                <select name="org_id" id="f_org_id" required onchange="filterSitesByOrg(this.value)" style="border: 2px solid #2563eb;">
                    <?php foreach($orgs as $o): ?>
                        <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['org_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:15px;">
                <div class="form-group"><label>Full Name</label><input type="text" name="name" id="f_name" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" id="f_email"></div>
                <div class="form-group"><label>Status</label><select name="active" id="f_active"><option value="1">Active</option><option value="0">Off</option></select></div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:15px; margin-top:15px;">
                <div class="form-group"><label>Username</label><input type="text" name="user" id="f_user" required></div>
                <div class="form-group"><label>Clock PIN</label><input type="text" name="pin" id="f_pin"></div>
                <div class="form-group"><label>Password</label><input type="password" name="password" placeholder="Leave blank to keep"></div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:15px; margin-top:15px;">
                <div class="form-group"><label>Role</label>
                    <select name="user_role" id="f_role">
                        <?php foreach($all_roles as $r): ?><option value="<?=$r['role_id']?>"><?=$r['role_name']?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Hourly Rate</label><input type="number" step="0.01" name="hourly_rate" id="f_rate"></div>
                <div class="form-group"><label>Official Rate</label><input type="number" step="0.01" name="official_rate" id="f_official"></div>
            </div>

            <div id="siteAllocationGroup" style="display:grid; grid-template-columns:1fr 1.5fr; gap:15px; margin-top:15px;">
                <div class="form-group"><label>Primary Site</label><select name="primary_restaurant_id" id="f_primary"><option value="">-- Select --</option></select></div>
                <div class="form-group"><label>Assigned Site IDs</label><select name="allocated_sites[]" id="f_allocated" multiple></select></div>
            </div>

            <div class="form-group mt-3"><label>Remarks</label><textarea name="remarks" id="f_remarks" rows="2"></textarea></div>

            <div style="margin-top:30px; display:flex; gap:10px;">
                <button type="submit" name="update_user" id="f_submit" class="btn btn-success" style="flex:2; background:#10b981; color:white; border:none; padding:15px; font-weight:800;">SAVE PROFILE</button>
                <button type="button" class="btn" onclick="closeEdit()" style="flex:1; background:#64748b; color:white; border:none;">CANCEL</button>
            </div>
        </form>
    </div>
</div>

<script>
const allSites = <?= json_encode($all_sites_js) ?>;
let sitePicker;

document.addEventListener('DOMContentLoaded', () => {
    sitePicker = new TomSelect('#f_allocated', { plugins: ['remove_button'], create: false });
    
    // Check for SaaS Provisioning URL Parameters
    const params = new URLSearchParams(window.location.search);
    const targetOrg = params.get('add_for_org');
    const targetRole = params.get('role');

    if(targetOrg) {
        openAdd();
        document.getElementById('f_org_id').value = targetOrg;
        
        // SaaS Logic: If adding a brand new Admin (Role 20)
        if(targetRole == '20') {
            document.getElementById('f_role').value = 20;
            document.getElementById('mTitle').innerText = "Register Organization Admin";
            document.getElementById('siteAllocationGroup').style.display = 'none'; // Hide sites (org is empty)
        } else {
            document.getElementById('f_role').value = targetRole;
            filterSitesByOrg(targetOrg);
        }
    }
});

function filterSitesByOrg(orgId) {
    const filtered = allSites.filter(s => s.org_id == orgId);
    
    // Primary Site rebuild
    const primary = document.getElementById('f_primary');
    primary.innerHTML = '<option value="">-- Select --</option>';
    filtered.forEach(s => { primary.innerHTML += `<option value="${s.SId}">${s.nick_name}</option>`; });

    // Multi-select rebuild
    sitePicker.clear();
    sitePicker.clearOptions();
    filtered.forEach(s => { sitePicker.addOption({ value: s.SId, text: s.nick_name }); });
}

function editUser(u) {
    document.getElementById('editOverlay').style.display = 'flex';
    document.getElementById('siteAllocationGroup').style.display = 'grid';
    document.getElementById('f_id').value = u.id;
    document.getElementById('f_name').value = u.Name;
    document.getElementById('f_email').value = u.email;
    document.getElementById('f_active').value = u.active;
    document.getElementById('f_user').value = u.user;
    document.getElementById('f_pin').value = u.pin;
    document.getElementById('f_role').value = u.user_role;
    document.getElementById('f_rate').value = u.hourly_rate;
    document.getElementById('f_official').value = u.official_rate;
    document.getElementById('f_org_id').value = u.org_id;
    document.getElementById('f_remarks').value = u.Remarks || "";

    filterSitesByOrg(u.org_id);
    setTimeout(() => {
        document.getElementById('f_primary').value = u.primary_restaurant_id || "";
        if(u.assigned_site_ids) sitePicker.setValue(u.assigned_site_ids.split(','));
    }, 100);
}

function openAdd() {
    document.getElementById('editOverlay').style.display = 'flex';
    document.querySelector('#editOverlay form').reset();
    document.getElementById('f_submit').name = "add_user";
    document.getElementById('mTitle').innerText = "Add New Employee";
    document.getElementById('siteAllocationGroup').style.display = 'grid';
    
    const sessionOrg = "<?= $session_org ?>";
    document.getElementById('f_org_id').value = sessionOrg;
    filterSitesByOrg(sessionOrg);
}

function closeEdit() { document.getElementById('editOverlay').style.display = 'none'; }
function filterHub() {
    let q = document.getElementById('hubSearch').value.toLowerCase();
    document.querySelectorAll('.staff-row').forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(q) ? "" : "none";
    });
}
function toggleAll(m) { document.querySelectorAll('.user-cb').forEach(c => c.checked = m.checked); }
</script>
</body>
</html>