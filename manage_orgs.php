<?php
/** * Path: /manage_orgs.php
 * FULL FILE - Provisioning, Approval, and Management
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'engine/menu_generator.php';

$user_level = (int)($_SESSION['user_level'] ?? $_SESSION['user_role'] ?? 99);
if (!isset($_SESSION['user_id']) || $user_level > 10) {
    header("Location: login_kiosk.php?err=access_denied");
    exit;
}

$msg = "";
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

if (!empty($_GET['msg'])) {
    $msg = "<div style='color:#166534; padding:12px 16px; background:#dcfce7; border-radius:8px; margin-bottom:16px; font-weight:700; border:1px solid #bbf7d0;'>"
        . htmlspecialchars((string)$_GET['msg'])
        . "</div>";
}

// 2. THE "ONE-CLICK" APPROVAL LOGIC
if (isset($_GET['approve'])) {
    try {
        $oid = (int)$_GET['approve'];

        $stmt = $pdo->prepare("SELECT org_name, max_users, max_sites FROM organizations WHERE id = ?");
        $stmt->execute([$oid]);
        $org = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($org) {
            $site_name = $org['org_name'] . " Main Site";
            $pdo->prepare("INSERT INTO sites (nick_name, org_id) VALUES (?, ?)")
                ->execute([$site_name, $oid]);
            $new_site_id = $pdo->lastInsertId();

            $clean_name = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $org['org_name']));
            $username = $clean_name . "_admin";
            $hashed_password = password_hash('1234', PASSWORD_DEFAULT);

            $user_sql = "INSERT INTO users (Name, user, password, pin, org_id, user_role, primary_site_id, assigned_site_ids, active)
                         VALUES (?, ?, ?, '1234', ?, 20, ?, ?, 1)";
            $pdo->prepare($user_sql)->execute([
                $org['org_name'] . " Admin",
                $username,
                $hashed_password,
                $oid,
                $new_site_id,
                (string)$new_site_id
            ]);

            $pdo->prepare("UPDATE organizations SET status = 'active' WHERE id = ?")->execute([$oid]);
            header("Location: manage_orgs.php?msg=Activated:+$username");
            exit;
        }
    } catch (PDOException $e) {
        $msg = "<div style='color:#991b1b; padding:12px 16px; background:#fee2e2; border-radius:8px; margin-bottom:16px; font-weight:700;'>Provisioning error: "
            . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// 3. HANDLE DELETE
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM organizations WHERE id = ?")->execute([$del_id]);
    header("Location: manage_orgs.php?msg=Deleted");
    exit;
}

// 4. HANDLE ADD/UPDATE — hidden hub_action so Save still posts if the button name is dropped
$hub_action = (string)($_POST['hub_action'] ?? '');
if ($hub_action === '' && isset($_POST['save_org'])) {
    $hub_action = 'save_org';
}
if ($hub_action === 'save_org') {
    $name = trim((string)($_POST['org_name'] ?? ''));
    $m_u = (int)($_POST['max_users'] ?? 10);
    $m_s = (int)($_POST['max_sites'] ?? 5);
    $status = strtolower(trim((string)($_POST['status'] ?? 'active')));
    $id = (int)($_POST['target_id'] ?? 0);
    if ($m_u < 1) { $m_u = 10; }
    if ($m_s < 1) { $m_s = 5; }
    if (!in_array($status, ['active', 'trial', 'suspended'], true)) {
        $status = 'active';
    }
    if ($name === '') {
        $msg = "<div style='color:#991b1b; padding:12px 16px; background:#fee2e2; border-radius:8px; margin-bottom:16px; font-weight:700;'>Enter an organization name.</div>";
    } else {
        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE organizations SET org_name=?, max_users=?, max_sites=?, status=? WHERE id=?")
                    ->execute([$name, $m_u, $m_s, $status, $id]);
                header("Location: manage_orgs.php?msg=" . rawurlencode('Updated: ' . $name));
                exit;
            }
            $pdo->prepare("INSERT INTO organizations (org_name, max_users, max_sites, status) VALUES (?, ?, ?, ?)")
                ->execute([$name, $m_u, $m_s, $status]);
            $new_id = (int)$pdo->lastInsertId();
            header("Location: manage_orgs.php?msg=" . rawurlencode('Saved: ' . $name . ' (#' . $new_id . ')'));
            exit;
        } catch (PDOException $e) {
            $msg = "<div style='color:#991b1b; padding:12px 16px; background:#fee2e2; border-radius:8px; margin-bottom:16px; font-weight:700;'>Could not save organization: "
                . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// 5. FETCH DATA FOR UI
$edit_data = ['org_name' => '', 'max_users' => 10, 'max_sites' => 5, 'status' => 'active'];
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
    $stmt->execute([$edit_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $edit_data = $row;
    }
}
$all_orgs = $pdo->query("SELECT * FROM organizations ORDER BY status DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Organizations | SIP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; padding-top: 110px; color: #1e293b; }
        .wrapper { padding: 20px; max-width: 1200px; margin: auto; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; margin-bottom: 30px; }
        .form-row { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 15px; align-items: flex-end; }
        @media (max-width: 800px) { .form-row { grid-template-columns: 1fr; } }
        label { display: block; font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 8px; }
        input, select { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box; }
        .btn-save { background: #0f172a; color: white; border: none; padding: 12px 25px; border-radius: 8px; font-weight: 700; cursor: pointer; text-transform: uppercase; font-size: 11px; }
        .table-container { background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; padding: 15px; text-align: left; font-size: 10px; text-transform: uppercase; font-weight: 800; color: #64748b; border-bottom: 1px solid #e2e8f0; }
        td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        .badge { padding: 4px 8px; border-radius: 6px; font-size: 10px; font-weight: 900; }
        .badge-trial { background: #fff7ed; color: #c2410c; border: 1px solid #ffedd5; }
        .badge-active { background: #f0fdf4; color: #166534; border: 1px solid #dcfce7; }
        .badge-suspended { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .btn-approve { background: #10b981; color: white; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-weight: 800; font-size: 11px; display: inline-block; }
        .field-note { font-size: 13px; color: #64748b; margin: 0 0 16px; }
    </style>
</head>
<body>
    <?php require_once __DIR__.'/payroll_help.php'; echo '<div style="margin:10px 16px 0;">'.payroll_help_panel('manage_orgs.php').'</div>'; ?>


<?= generateMenu($pdo); ?>

<div class="wrapper">
    <h2 style="font-weight: 900; margin-bottom: 12px; letter-spacing: -1px;">Organization Control Hub</h2>
    <p class="field-note">Super Admin: add a company (name, status, plan limits) and Save. It appears in the list below. Pay-period settings stay per org — AA Group stays 26; AMJ Canada is not changed here. <a href="org_settings.php">Org Settings</a></p>
    <?= $msg ?>

    <div class="card" id="add-organization">
        <h3 style="margin:0 0 16px; font-size:16px;"><?= $edit_id ? 'Edit organization' : 'Add organization' ?></h3>
        <form method="POST" action="manage_orgs.php" id="saveOrgForm">
            <input type="hidden" name="hub_action" value="save_org">
            <input type="hidden" name="target_id" value="<?= $edit_id ?>">
            <div class="form-row">
                <div>
                    <label>Organization name</label>
                    <input type="text" name="org_name" value="<?= htmlspecialchars($edit_data['org_name']) ?>" required placeholder="e.g. New Company Ltd" autocomplete="off">
                </div>
                <div>
                    <label>Max Users</label>
                    <input type="number" name="max_users" value="<?= (int)$edit_data['max_users'] ?>" min="1">
                </div>
                <div>
                    <label>Max Sites</label>
                    <input type="number" name="max_sites" value="<?= (int)$edit_data['max_sites'] ?>" min="1">
                </div>
                <div>
                    <label>Status</label>
                    <select name="status">
                        <option value="trial" <?= $edit_data['status'] == 'trial' ? 'selected' : '' ?>>Trial (Pending)</option>
                        <option value="active" <?= $edit_data['status'] == 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="suspended" <?= $edit_data['status'] == 'suspended' ? 'selected' : '' ?>>Suspended</option>
                    </select>
                </div>
                <button type="submit" class="btn-save"><?= $edit_id ? 'Update' : 'Save organization' ?></button>
            </div>
        </form>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th width="50">ID</th>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Capacity</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_orgs as $o): ?>
                <tr>
                    <td style="color: #94a3b8; font-weight: bold;">#<?= $o['id'] ?></td>
                    <td><strong><?= htmlspecialchars($o['org_name']) ?></strong></td>
                    <td>
                        <span class="badge badge-<?= htmlspecialchars($o['status']) ?>">
                            <?= strtoupper($o['status']) ?>
                        </span>
                    </td>
                    <td class="small text-muted"><?= (int)$o['max_sites'] ?> Sites / <?= (int)$o['max_users'] ?> Users</td>
                    <td style="text-align: right;">
                        <?php if($o['status'] == 'trial'): ?>
                            <a href="manage_orgs.php?approve=<?= $o['id'] ?>" class="btn-approve" onclick="return confirm('Approve this organization? This creates an Admin account.')">APPROVE</a>
                        <?php endif; ?>
                        <a href="manage_orgs.php?edit=<?= $o['id'] ?>" style="margin-left:15px; color:#0f172a; text-decoration:none;"><i class="fa fa-edit"></i></a>
                        <a href="org_settings.php?org_id=<?= (int)$o['id'] ?>" style="margin-left:15px; color:#0369a1; text-decoration:none;" title="Org settings">Settings</a>
                        <a href="manage_orgs.php?delete=<?= $o['id'] ?>" onclick="return confirm('Delete?')" style="margin-left:15px; color:#ef4444;"><i class="fa fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
