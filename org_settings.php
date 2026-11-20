<?php
/** Path: /org_settings.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'engine/org_modules_lib.php';
require_once 'engine/menu_generator.php';

// 1. SECURITY: Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login_kiosk.php"); exit;
}

$org_id = (int)$_SESSION['org_id'];
$user_level = (int)($_SESSION['user_level'] ?? $_SESSION['user_role'] ?? 99);
$can_edit_landing = ($user_level <= 20); // Admin / Superadmin
$is_super = ($user_level <= 10);
$msg = "";
$landing_opts = sip_landing_options();
$all_orgs = [];
if ($is_super) {
    try {
        $all_orgs = $pdo->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $all_orgs = [];
    }
    $pick = (int)($_POST['settings_org_id'] ?? $_GET['org_id'] ?? 0);
    if ($pick > 0) {
        $org_id = $pick;
    }
}

if (!empty($_GET['msg']) && $_GET['msg'] === 'created') {
    $msg = "<div style='color:#166534; padding:15px; background:#dcfce7; border-radius:8px; margin-bottom:20px; font-weight:bold; border:1px solid #bbf7d0;'>✔ Organization created. It is in the list below — pick it to edit settings. Pay periods were not changed for AA Group or AMJ Canada.</div>";
}

// Super Admin: create a new organization (not an update of the selected one)
$create_action = (string)($_POST['hub_action'] ?? '');
if ($is_super && ($create_action === 'create_org' || isset($_POST['create_org']))) {
    $new_name = trim((string)($_POST['new_org_name'] ?? ''));
    $m_u = (int)($_POST['new_max_users'] ?? 10);
    $m_s = (int)($_POST['new_max_sites'] ?? 5);
    $status = strtolower(trim((string)($_POST['new_status'] ?? 'active')));
    if ($m_u < 1) { $m_u = 10; }
    if ($m_s < 1) { $m_s = 5; }
    if (!in_array($status, ['active', 'trial', 'suspended'], true)) {
        $status = 'active';
    }
    if ($new_name === '') {
        $msg = "<div style='color:#991b1b; padding:15px; background:#fee2e2; border-radius:8px; margin-bottom:20px; font-weight:bold;'>Enter an organization name.</div>";
    } else {
        try {
            $ins = $pdo->prepare("INSERT INTO organizations (org_name, max_users, max_sites, status) VALUES (?, ?, ?, ?)");
            $ins->execute([$new_name, $m_u, $m_s, $status]);
            $new_id = (int)$pdo->lastInsertId();
            header('Location: org_settings.php?org_id=' . $new_id . '&msg=created');
            exit;
        } catch (PDOException $e) {
            $msg = "<div style='color:#991b1b; padding:15px; background:#fee2e2; border-radius:8px; margin-bottom:20px; font-weight:bold;'>Could not create organization: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// 2. HANDLE UPDATE ORGANIZATION NAME (+ optional landing for admins)
if (isset($_POST['update_org'])) {
    $new_name = trim($_POST['org_name'] ?? '');
    if (!empty($new_name)) {
        try {
            $stmt = $pdo->prepare("UPDATE organizations SET org_name = ? WHERE id = ?");
            $stmt->execute([$new_name, $org_id]);
            $_SESSION['org_name'] = $new_name;
            if ($can_edit_landing && isset($_POST['default_landing'])) {
                sip_set_org_default_landing($pdo, $org_id, $_POST['default_landing']);
            }
            if ($can_edit_landing) {
                // Checkbox: absent from POST when unchecked
                sip_set_org_auto_start_tour($pdo, $org_id, !empty($_POST['auto_start_tour']));
                if (isset($_POST['pay_period_mode'])) {
                    sip_set_org_pay_period($pdo, $org_id, $_POST['pay_period_mode']);
                }
                if (isset($_POST['pay_period_anchor'])) {
                    sip_set_org_pay_period_anchor($pdo, $org_id, $_POST['pay_period_anchor']);
                }
                if (isset($_POST['restrain_clockin']) || isset($_POST['schedule_margin_minutes'])) {
                    sip_set_org_clockin_policy(
                        $pdo,
                        $org_id,
                        $_POST['restrain_clockin'] ?? '0',
                        $_POST['schedule_margin_minutes'] ?? 5
                    );
                }
                if (isset($_POST['gdrive_folder_url']) || isset($_POST['gdrive_folder_label'])) {
                    sip_set_org_gdrive_folder(
                        $pdo,
                        $org_id,
                        $_POST['gdrive_folder_url'] ?? '',
                        $_POST['gdrive_folder_label'] ?? ''
                    );
                }
                if (isset($_POST['bulk_hours_cap'])) {
                    $cap_in = (string)$_POST['bulk_hours_cap'];
                    if ($cap_in === 'other') {
                        $cap_in = (string)($_POST['bulk_hours_cap_other'] ?? '12');
                    }
                    sip_set_org_bulk_hours_cap($pdo, $org_id, $cap_in);
                }
            }
            $msg = "<div style='color:#166534; padding:15px; background:#dcfce7; border-radius:8px; margin-bottom:20px; font-weight:bold; border:1px solid #bbf7d0;'>✔ Company settings updated!</div>";
        } catch (PDOException $e) {
            $msg = "<div style='color:#991b1b; padding:15px; background:#fee2e2; border-radius:8px; margin-bottom:20px; font-weight:bold;'>Error: " . $e->getMessage() . "</div>";
        }
    }
}

// 3. FETCH ORG DETAILS
$stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
$stmt->execute([$org_id]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$org && $is_super && !empty($all_orgs)) {
    $org_id = (int)$all_orgs[0]['id'];
    $stmt->execute([$org_id]);
    $org = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$org) {
    $org = ['org_name' => 'Unknown', 'status' => 'active', 'max_users' => 1, 'max_sites' => 1, 'plan_expiry' => date('Y-m-d', strtotime('+1 year'))];
}

$currentLanding = sip_org_default_landing($pdo, $org_id);
$autoStartTour = sip_org_auto_start_tour($pdo, $org_id);
$payPeriodMode = sip_org_pay_period_mode($pdo, $org_id);
$payPeriods = sip_org_pay_periods($pdo, $org_id);
$payPeriodAnchor = sip_org_pay_period_anchor($pdo, $org_id);
$restrainClockin = sip_org_restrain_clockin($pdo, $org_id);
$scheduleMarginMin = sip_org_schedule_margin_minutes($pdo, $org_id);
$gdriveFolder = sip_org_gdrive_folder($pdo, $org_id);
$bulkHoursCap = function_exists('sip_org_bulk_hours_cap') ? sip_org_bulk_hours_cap($pdo, $org_id) : 12.0;
$bulkCapIsOther = abs($bulkHoursCap - 8) >= 0.001 && abs($bulkHoursCap - 12) >= 0.001;

// Count Usage
$current_users = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ?");
$current_users->execute([$org_id]);
$u_count = $current_users->fetchColumn();

$current_sites = $pdo->prepare("SELECT COUNT(*) FROM sites WHERE org_id = ?");
$current_sites->execute([$org_id]);
$s_count = $current_sites->fetchColumn();

// Limits (Fallback to 1 if not set to avoid division by zero)
$max_u = (int)($org['max_users'] ?? 5);
$max_s = (int)($org['max_sites'] ?? 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Org Settings | <?= htmlspecialchars($org['org_name']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; color: #1e293b; }
        .container { max-width: 900px; margin: 40px auto; padding: 0 20px; }
        .card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 30px; border: 1px solid #e2e8f0; }
        h2 { color: #0f172a; margin-top: 0; font-weight: 800; font-size: 20px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px; }
        .info-box { background: #f8fafc; padding: 20px; border-radius: 10px; border: 1px solid #e2e8f0; }
        .info-box label { display: block; font-size: 10px; color: #94a3b8; text-transform: uppercase; font-weight: 800; margin-bottom: 10px; }
        .info-box span { font-size: 22px; color: #1e293b; font-weight: 800; }
        .usage-bar { height: 8px; background: #e2e8f0; border-radius: 10px; margin-top: 15px; overflow: hidden; }
        .usage-fill { height: 100%; background: #0097a7; border-radius: 10px; }
        input[type="text"], input[type="number"], select { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; margin-top: 10px; box-sizing: border-box; }
        button { background: #0f172a; color: white; padding: 12px 25px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; margin-top: 15px; }
        .field-note { font-size: 12px; color: #64748b; margin-top: 8px; line-height: 1.4; }
        .add-org-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 12px; align-items: end; }
        @media (max-width: 800px) { .add-org-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<?php echo generateMenu($pdo); ?>
    <?php require_once __DIR__.'/payroll_help.php'; echo '<div style="margin:10px 16px 0;">'.payroll_help_panel('org_settings.php').'</div>'; ?>


<div class="container">
    <?= $msg ?>

    <div class="card">
        <h2>Subscription & Usage</h2>
        <div class="info-grid">
            <div class="info-box">
                <label>Staff Members</label>
                <span><?= $u_count ?> / <?= $max_u ?></span>
                <div class="usage-bar">
                    <div class="usage-fill" style="width: <?= min(100, ($u_count / $max_u) * 100) ?>%;"></div>
                </div>
            </div>
            <div class="info-box">
                <label>Active Sites</label>
                <span><?= $s_count ?> / <?= $max_s ?></span>
                <div class="usage-bar">
                    <div class="usage-fill" style="width: <?= min(100, ($s_count / $max_s) * 100) ?>%;"></div>
                </div>
            </div>
            <div class="info-box">
                <label>Current Plan</label>
                <span style="color: #059669;"><?= strtoupper($org['status'] ?? 'Active') ?></span>
            </div>
            <div class="info-box">
                <label>Billing Cycle Ends</label>
                <span><?= date('M d, Y', strtotime($org['plan_expiry'] ?? '+1 year')) ?></span>
            </div>
        </div>
    </div>

    <?php if ($is_super): ?>
    <div class="card" id="add-organization">
        <h2>Add organization</h2>
        <p class="field-note" style="margin-top:0;">Super Admin: create a company here. It will appear in this list and on Employee Hub. Does not change AA Group (26) or AMJ Canada. Full list with edit/delete: <a href="manage_orgs.php">Manage Organizations</a>.</p>
        <form method="POST" action="org_settings.php" id="createOrgForm">
            <input type="hidden" name="hub_action" value="create_org">
            <div class="add-org-grid">
                <div>
                    <label style="font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase;">Organization name</label>
                    <input type="text" name="new_org_name" required placeholder="e.g. New Company Ltd" autocomplete="off">
                </div>
                <div>
                    <label style="font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase;">Status</label>
                    <select name="new_status">
                        <option value="active" selected>Active</option>
                        <option value="trial">Trial</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
                <div>
                    <label style="font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase;">Max users</label>
                    <input type="number" name="new_max_users" value="10" min="1">
                </div>
                <div>
                    <label style="font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase;">Max sites</label>
                    <input type="number" name="new_max_sites" value="5" min="1">
                </div>
                <button type="submit">Save organization</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>Company Profile</h2>
        <form method="POST">
            <?php if ($is_super && $all_orgs): ?>
            <label style="font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase;">Organization to edit</label>
            <select name="settings_org_id" onchange="var q=this.value; window.location='org_settings.php?org_id='+encodeURIComponent(q);">
                <?php foreach ($all_orgs as $o): ?>
                    <option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === (int)$org_id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($o['org_name']) ?> (#<?= (int)$o['id'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-note">Super Admin: pick the company whose pay cycle you are setting (e.g. AA Group). This does not change other orgs.</p>
            <?php else: ?>
            <input type="hidden" name="settings_org_id" value="<?= (int)$org_id ?>">
            <?php endif; ?>
            <label style="font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase;">Legal Company Name</label>
            <input type="text" name="org_name" value="<?= htmlspecialchars($org['org_name']) ?>" required>

            <?php if ($can_edit_landing): ?>
            <label style="font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; display:block; margin-top:18px;">Default landing page (after login)</label>
            <select name="default_landing">
                <?php foreach ($landing_opts as $key => $info): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= $currentLanding === $key ? 'selected' : '' ?>>
                        <?= htmlspecialchars($info['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-note">
                Admins/managers land here when the module is enabled. Staff (role ≥ 40) still go to the kiosk whenever Clock-In is enabled for this org.
                Superadmin can also set this under Clients → Org Modules Mgmt.
                <strong>CSV Import</strong> opens Payroll → CSV Import (<code>bulk_importer.php</code>) when PAY is enabled.
            </p>

            <label style="font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; display:block; margin-top:18px;">Payroll frequency</label>
            <select name="pay_period_mode">
                <?php foreach (sip_pay_period_choices() as $key => $info): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= $payPeriodMode === $key ? 'selected' : '' ?>>
                        <?= htmlspecialchars($info['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-note">Biweekly (26), semi-monthly (24), or monthly (12). Ledger, Staff Audit, reports, and stored CPP always read this organization — not the login company. Currently <strong><?= (int)$payPeriods ?></strong> periods / year.</p>

            <label style="font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; display:block; margin-top:18px;">Bulk allocation regular hours cap</label>
            <select name="bulk_hours_cap" id="bulkHoursCap" onchange="document.getElementById('bulkHoursOtherWrap').style.display=this.value==='other'?'block':'none'">
                <option value="8" <?= abs($bulkHoursCap - 8) < 0.001 ? 'selected' : '' ?>>8 hours</option>
                <option value="12" <?= abs($bulkHoursCap - 12) < 0.001 ? 'selected' : '' ?>>12 hours</option>
                <option value="other" <?= $bulkCapIsOther ? 'selected' : '' ?>>Other</option>
            </select>
            <div id="bulkHoursOtherWrap" style="display:<?= $bulkCapIsOther ? 'block' : 'none' ?>;">
                <input type="number" name="bulk_hours_cap_other" min="0" max="24" step="0.25" value="<?= htmlspecialchars(number_format($bulkHoursCap, 2, '.', '')) ?>">
            </div>
            <p class="field-note">This is the Regular Hours Cap when Bulk Pay Allocation opens. Usual choices are 8 or 12. Pick Other for a different number up to 24. Staff can still type a different cap for one run.</p>

            <label style="font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; display:block; margin-top:18px;">If an employee is not on the schedule, restrain clock-in?</label>
            <select name="restrain_clockin" id="restrain_clockin">
                <option value="1" <?= $restrainClockin ? 'selected' : '' ?>>Yes — block the punch</option>
                <option value="0" <?= !$restrainClockin ? 'selected' : '' ?>>No — ask for a reason and record it</option>
            </select>
            <p class="field-note" id="restrain_note">Yes: kiosk (after Login) shows why and will not accept Clock In. No: kiosk still asks for a reason and stores it on the punch.</p>

            <label style="font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; display:block; margin-top:18px;">Late / early margin (minutes)</label>
            <input type="number" name="schedule_margin_minutes" min="0" max="240" step="1" value="<?= (int)$scheduleMarginMin ?>">
            <p class="field-note">Minutes to accommodate before the scheduled start and after the scheduled end. Example: 15 means clock-in is on-schedule from 15 minutes early through 15 minutes after end.</p>

            <label style="font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; display:block; margin-top:18px;">Bi-weekly first Monday</label>
            <input type="date" name="pay_period_anchor" value="<?= htmlspecialchars($payPeriodAnchor) ?>">
            <p class="field-note">14-day blocks start on this Monday. Example for AA Group: Aug 10–23, 2026 is one period (includes Sunday Aug 23).</p>

            <label style="font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; display:block; margin-top:18px;">Default Google Drive folder</label>
            <input type="text" name="gdrive_folder_label" placeholder="1.Software / aa group" value="<?= htmlspecialchars((string)($gdriveFolder['label'] ?? '')) ?>">
            <input type="url" name="gdrive_folder_url" placeholder="https://drive.google.com/drive/folders/..." value="<?= htmlspecialchars((string)($gdriveFolder['url'] ?? '')) ?>" style="margin-top:8px;">
            <p class="field-note">Shared company folder for reports you send this org. AA Group default is <strong>1.Software / aa group</strong>.<?php if (!empty($gdriveFolder['url'])): ?> <a href="<?= htmlspecialchars($gdriveFolder['url']) ?>" target="_blank" rel="noopener">Open folder</a><?php endif; ?></p>

            <label style="display:flex; align-items:flex-start; gap:10px; margin-top:18px; cursor:pointer;">
                <input type="checkbox" name="auto_start_tour" value="1" <?= $autoStartTour ? 'checked' : '' ?> style="width:18px; height:18px; margin-top:2px;">
                <span>
                    <span style="font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; display:block;">Auto-start guided tours</span>
                    <span class="field-note" style="margin:4px 0 0;">Off by default. When on, the page tour opens once per user/page until dismissed. <strong>Start Tour</strong> always works manually.</span>
                </span>
            </label>
            <?php else: ?>
            <p class="field-note" style="margin-top:14px;">
                Current landing: <strong><?= htmlspecialchars($landing_opts[$currentLanding]['label'] ?? $currentLanding) ?></strong>
                (admins can change this).
                Auto-start guided tours: <strong><?= $autoStartTour ? 'On' : 'Off' ?></strong>.
                Payroll frequency: <strong><?= (int)$payPeriods ?></strong> (<?= htmlspecialchars($payPeriodMode) ?>).
                Restrain off-schedule clock-in: <strong><?= $restrainClockin ? 'Yes — block' : 'No — reason required' ?></strong>.
                Late / early margin: <strong><?= (int)$scheduleMarginMin ?></strong> minutes.
                Bulk allocation cap: <strong><?= htmlspecialchars(number_format($bulkHoursCap, 2, '.', '')) ?></strong> hours.
            </p>
            <?php endif; ?>

            <button type="submit" name="update_org">Save Changes</button>
        </form>
    </div>
</div>

</body>
</html>
