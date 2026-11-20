<?php
/** Path: /admin_dashboard.php */
ob_start();
require_once 'db.php';
require_once 'engine/menu_generator.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Access Control: Superadmin (10) sees all, Admin (20) sees their own
$user_role = $_SESSION['user_role'] ?? 99;
if ($user_role > 25) { header("Location: login.php"); exit; }
$is_super = ($user_role <= 10);

// Create New Organization
if (isset($_POST['create_org']) && $is_super) {
    $token = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare("INSERT INTO organizations (org_name, invite_token) VALUES (?, ?)");
    $stmt->execute([$_POST['org_name'], $token]);
    header("Location: admin_dashboard.php?msg=success"); exit;
}

// Fetch Organizations
$sql = "SELECT o.*, 
        (SELECT COUNT(*) FROM users WHERE org_id = o.id AND active = 1) as staff_count,
        (SELECT COUNT(*) FROM users WHERE org_id = o.id AND active = 2) as pending_count
        FROM organizations o " . ($is_super ? "" : "WHERE o.id = " . (int)$_SESSION['org_id']);
$orgs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <!-- pwa-head -->
    <?php if (file_exists(__DIR__ . '/pwa_head.php')) include __DIR__ . '/pwa_head.php'; ?>

    <title>Control Panel | SIP</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #f1f5f9; padding-top: 90px; font-family: 'Inter', sans-serif; }
        .stat-card { background: white; border-radius: 15px; padding: 25px; border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .invite-box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 10px; border-radius: 8px; font-family: monospace; font-size: 11px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
    </style>
</head>
<body>
<?= generateMenu($pdo); ?>
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="font-weight-bold">Control Panel</h1>
        <?php if($is_super): ?><button class="btn btn-primary" data-toggle="modal" data-target="#newOrg">+ ADD COMPANY</button><?php endif; ?>
    </div>
    <div class="row">
        <?php foreach($orgs as $o): $link = "https://payroll.renocon.ca/register1.php?token=" . ($o['invite_token'] ?? 'none'); ?>
        <div class="col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <h4 class="font-weight-bold"><?= htmlspecialchars($o['org_name']) ?></h4>
                    <span class="badge badge-primary p-2"><?= $o['staff_count'] ?> Staff</span>
                </div>
                <div class="mt-3">
                    <label class="small font-weight-bold text-muted">SECURE INVITE LINK</label>
                    <div class="d-flex align-items-center">
                        <div class="invite-box flex-grow-1 mr-2"><?= $link ?></div>
                        <button class="btn btn-sm btn-dark" onclick="copy('<?= $link ?>')"><i class="fa fa-copy"></i></button>
                    </div>
                </div>
                <a href="employee_hub1.php?org_id=<?= $o['id'] ?>" class="btn btn-block btn-outline-primary mt-4 font-weight-bold">MANAGE TEAM</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="newOrg"><div class="modal-dialog"><form class="modal-content" method="POST">
    <div class="modal-header"><h5>New Organization</h5></div>
    <div class="modal-body"><input type="text" name="org_name" class="form-control" placeholder="Company Name" required></div>
    <div class="modal-footer"><button type="submit" name="create_org" class="btn btn-primary btn-block">CREATE COMPANY</button></div>
</form></div></div>

<script>function copy(t){navigator.clipboard.writeText(t); alert("Link copied!");}</script>
<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>