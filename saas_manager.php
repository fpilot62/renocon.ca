<?php
/** Path: /saas_manager.php — Org Modules Mgmt (writes org_modules; drives nav) */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'engine/org_modules_lib.php';
require_once 'engine/menu_generator.php';

$current_org = isset($_SESSION['org_id']) ? (int)$_SESSION['org_id'] : 0;
$current_role = isset($_SESSION['user_role']) ? (int)$_SESSION['user_role'] : 99;

if (!isset($_SESSION['user_id'])) {
    header("Location: login_kiosk.php?err=session_expired");
    exit;
}

if ($current_role != 10 && $current_org != 100000) {
    header("Location: login_kiosk.php?err=access_denied");
    exit;
}

// Catalog shown in UI (canonical keys = org_modules / menu_manager)
$modules = [
    'PAY'         => ['name' => 'Payroll (CSV + runs)', 'icon' => 'fa-file-invoice-dollar', 'desc' => 'Employees, CSV hours import, payroll center, T4/CRA'],
    'clockin'     => ['name' => 'Clock-In / Kiosk', 'icon' => 'fa-clock', 'desc' => 'Punch In, attendance, kiosk'],
    'inventory'   => ['name' => 'Inventory', 'icon' => 'fa-boxes-stacked', 'desc' => 'Stock, deliveries, counts, food cost'],
    'Scheduling'  => ['name' => 'Scheduling', 'icon' => 'fa-calendar-alt', 'desc' => 'Staff scheduler / roster'],
    'operations'  => ['name' => 'Operations', 'icon' => 'fa-clipboard-list', 'desc' => 'Daily sales, maintenance, transfers'],
    'alarm'       => ['name' => 'Alarm / Tasks', 'icon' => 'fa-bell', 'desc' => 'Alarm & task reminders'],
];

$landing_opts = sip_landing_options();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action     = $_POST['action'];
    $contextOrg = (int)($_POST['current_org_context'] ?? 0);

    try {
        if ($action === 'save_landing' && $contextOrg > 0) {
            sip_set_org_default_landing($pdo, $contextOrg, $_POST['default_landing'] ?? 'auto');
            sip_set_org_auto_start_tour($pdo, $contextOrg, !empty($_POST['auto_start_tour']));
            header("Location: saas_manager.php?org_id=$contextOrg&success=1");
            exit;
        }

        // Batch: Provision / Revoke selected modules
        if (($action === 'batch_provision' || $action === 'batch_revoke') && $contextOrg > 0) {
            $selected = $_POST['module_ids'] ?? [];
            if (!is_array($selected)) {
                $selected = [$selected];
            }
            $enable = ($action === 'batch_provision');
            foreach ($selected as $raw) {
                $modID = sip_normalize_module_key($raw);
                if ($modID === '' || !isset($modules[$modID])) {
                    continue;
                }
                sip_set_org_module($pdo, $contextOrg, $modID, $enable, null);
            }
            sip_sync_sites_module_csv($pdo, $contextOrg);
            header("Location: saas_manager.php?org_id=$contextOrg&success=1");
            exit;
        }

        // Single-module Provision / Revoke
        $modID = sip_normalize_module_key($_POST['module_id'] ?? '');
        if ($contextOrg > 0 && $modID !== '') {
            $enable = ($action === 'update');
            sip_set_org_module($pdo, $contextOrg, $modID, $enable, null);
            sip_sync_sites_module_csv($pdo, $contextOrg);
        }
        header("Location: saas_manager.php?org_id=$contextOrg&success=1");
        exit;
    } catch (PDOException $e) {
        die("SaaS Error: " . $e->getMessage());
    }
}

$filterOrg = $_GET['org_id'] ?? 'all';
$organizations = $pdo->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$activeStatus = [];
$currentLanding = 'auto';
$autoStartTour = false;
if ($filterOrg !== 'all') {
    $enabled = sip_org_enabled_modules($pdo, (int)$filterOrg) ?: [];
    foreach ($modules as $id => $info) {
        $activeStatus[$id] = in_array($id, $enabled, true);
    }
    $currentLanding = sip_org_default_landing($pdo, (int)$filterOrg);
    $autoStartTour = sip_org_auto_start_tour($pdo, (int)$filterOrg);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SaaS Feature Manager</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; padding-top: 110px; color: #1e293b; }
        .container { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 12px; }
        .org-picker { background: #fff; padding: 15px 25px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: flex; align-items: center; gap: 15px; }
        .hint { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 20px; }
        .landing-card { background: white; padding: 22px 24px; border-radius: 16px; border: 1px solid #e2e8f0; margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; justify-content: space-between; }
        .landing-card label { display: block; font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 8px; }
        .landing-card .note { font-size: 12px; color: #64748b; max-width: 520px; line-height: 1.45; }
        .batch-bar { background: white; padding: 16px 20px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 16px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; }
        .batch-bar .left { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .batch-bar .count { font-size: 13px; color: #64748b; font-weight: 600; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px; }
        .card { background: white; padding: 28px; border-radius: 16px; border: 1px solid #e2e8f0; text-align: center; position: relative; }
        .card.active { border-top: 6px solid #0097a7; box-shadow: 0 10px 20px rgba(0,0,0,0.05); }
        .card.inactive { opacity: 0.75; background: #f8fafc; }
        .card.selected { outline: 2px solid #0284c7; outline-offset: 1px; }
        .pick { position: absolute; top: 14px; left: 14px; display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; color: #475569; }
        .pick input { width: 16px; height: 16px; cursor: pointer; }
        .icon-circle { width: 70px; height: 70px; background: #f1f5f9; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 28px; color: #475569; }
        .active .icon-circle { background: #e0f2fe; color: #0284c7; }
        .btn { width: 100%; padding: 12px; border-radius: 8px; border: none; font-weight: bold; cursor: pointer; font-size: 14px; }
        .btn-enable { background: #0097a7; color: white; }
        .btn-disable { background: #fee2e2; color: #991b1b; }
        .btn-save { background: #0f172a; color: white; padding: 12px 22px; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; }
        .btn-batch { padding: 10px 16px; border-radius: 8px; border: none; font-weight: 700; cursor: pointer; font-size: 13px; }
        .btn-batch-on { background: #0097a7; color: white; }
        .btn-batch-off { background: #fee2e2; color: #991b1b; }
        .btn-link { background: transparent; border: 1px solid #cbd5e1; color: #475569; padding: 8px 12px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 12px; }
        .status-pill { font-size: 10px; font-weight: 800; text-transform: uppercase; padding: 4px 12px; border-radius: 20px; margin-bottom: 10px; display: inline-block; }
        .pill-active { background: #dcfce7; color: #15803d; }
        .pill-inactive { background: #e2e8f0; color: #64748b; }
        select { padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-weight: 600; outline: none; }
        code { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 11px; }
    </style>
</head>
<body>

<?php echo generateMenu($pdo); ?>

<div class="container">
    <div class="header-flex">
        <h2 style="margin:0; font-weight: 800;">Org Module Entitlements</h2>
        <div class="org-picker">
            <label style="font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase;">Manage Client:</label>
            <select onchange="location.href='saas_manager.php?org_id=' + this.value;">
                <option value="all">-- Select Organization --</option>
                <?php foreach ($organizations as $o): ?>
                    <?php if ((int)$o['id'] === 100000) continue; ?>
                    <option value="<?= (int)$o['id'] ?>" <?= (string)$filterOrg === (string)$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['org_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="hint">
        <strong>Payroll Lite:</strong> enable only <code>PAY</code>, set Default Landing = <strong>CSV Import</strong> (preferred) or Payroll Hub. Leave Auto-start guided tours Off.
        <strong>Restaurant:</strong> enable clockin (+ others), landing = Kiosk (or Auto).
        <strong>Schedule-only:</strong> enable Scheduling, landing = Scheduling.
        Staff (role ≥ 40) always land on kiosk when clockin is on. Nav updates immediately after Provision/Revoke.
        Check multiple modules, then use Provision/Revoke selected — or use the per-card buttons.
    </div>

    <?php if (!empty($_GET['success'])): ?>
        <div style="background:#dcfce7;color:#166534;padding:12px 16px;border-radius:10px;margin-bottom:16px;font-weight:700;">Saved. Ask the client to refresh the page.</div>
    <?php endif; ?>

    <?php if ($filterOrg === 'all'): ?>
        <div style="text-align: center; padding: 100px; background: white; border-radius: 20px; border: 2px dashed #cbd5e1; color: #94a3b8;">
            <i class="fa fa-arrow-up" style="font-size: 40px; margin-bottom: 20px;"></i>
            <h3>Select an Organization to manage their paid features.</h3>
        </div>
    <?php else: ?>
        <div class="landing-card">
            <div style="flex:1; min-width:240px;">
                <label>Default landing page (after login)</label>
                <p class="note" style="margin:0;">
                    Used for admins / managers when the chosen module is enabled.
                    <strong>CSV Import</strong> → <code>bulk_importer.php</code> (requires PAY).
                    If the landing module is revoked, login falls back to the first enabled module (kiosk → scheduling → payroll hub).
                    Staff with clockin always go to the kiosk.
                </p>
            </div>
            <form method="POST" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
                <input type="hidden" name="action" value="save_landing">
                <input type="hidden" name="current_org_context" value="<?= (int)$filterOrg ?>">
                <div>
                    <label for="default_landing">Landing</label>
                    <select name="default_landing" id="default_landing">
                        <?php foreach ($landing_opts as $key => $info): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $currentLanding === $key ? 'selected' : '' ?>>
                                <?= htmlspecialchars($info['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <label style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; color:#334155; padding-bottom:4px; cursor:pointer;">
                    <input type="checkbox" name="auto_start_tour" value="1" <?= $autoStartTour ? 'checked' : '' ?> style="width:16px; height:16px;">
                    Auto-start guided tours
                </label>
                <button type="submit" class="btn-save">Save Landing</button>
            </form>
            <p class="note" style="width:100%; margin:0;">
                Auto-start guided tours defaults <strong>Off</strong>. When Off, users still open tours with <strong>Start Tour</strong>. When On, the tour runs once per user/page until dismissed.
            </p>
        </div>

        <form method="POST" id="batchModulesForm">
            <input type="hidden" name="current_org_context" value="<?= (int)$filterOrg ?>">
            <div class="batch-bar">
                <div class="left">
                    <button type="button" class="btn-link" id="selectAllBtn">Select all</button>
                    <button type="button" class="btn-link" id="clearSelBtn">Clear</button>
                    <span class="count" id="selCount">0 selected</span>
                </div>
                <div class="left">
                    <button type="submit" name="action" value="batch_provision" class="btn-batch btn-batch-on" id="batchProvisionBtn" disabled>Provision selected</button>
                    <button type="submit" name="action" value="batch_revoke" class="btn-batch btn-batch-off" id="batchRevokeBtn" disabled>Revoke selected</button>
                </div>
            </div>

            <div class="grid">
                <?php foreach ($modules as $id => $info):
                    $isAssigned = $activeStatus[$id] ?? false;
                ?>
                <div class="card <?= $isAssigned ? 'active' : 'inactive' ?>">
                    <label class="pick">
                        <input type="checkbox" name="module_ids[]" value="<?= htmlspecialchars($id) ?>" class="mod-check" form="batchModulesForm">
                        Select
                    </label>
                    <span class="status-pill <?= $isAssigned ? 'pill-active' : 'pill-inactive' ?>">
                        <?= $isAssigned ? 'Subscribed' : 'Not Active' ?>
                    </span>
                    <div class="icon-circle"><i class="fa <?= $info['icon'] ?>"></i></div>
                    <h3 style="margin-bottom: 5px;"><?= htmlspecialchars($info['name']) ?></h3>
                    <p style="font-size: 12px; color: #64748b; margin-bottom: 8px;"><?= htmlspecialchars($info['desc']) ?></p>
                    <p style="font-size: 11px; color: #94a3b8; margin-bottom: 20px;">Key: <code><?= htmlspecialchars($id) ?></code></p>
                    <!-- Single-module actions stay outside batch form -->
                    <button type="submit" formaction="" form="single_<?= htmlspecialchars($id) ?>" name="action" value="<?= $isAssigned ? 'revoke' : 'update' ?>" class="btn <?= $isAssigned ? 'btn-disable' : 'btn-enable' ?>">
                        <?= $isAssigned ? 'Revoke Access' : 'Provision Module' ?>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </form>

        <?php foreach ($modules as $id => $info): ?>
        <form method="POST" id="single_<?= htmlspecialchars($id) ?>" style="display:none;">
            <input type="hidden" name="module_id" value="<?= htmlspecialchars($id) ?>">
            <input type="hidden" name="current_org_context" value="<?= (int)$filterOrg ?>">
        </form>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
(function () {
    var checks = Array.prototype.slice.call(document.querySelectorAll('.mod-check'));
    var countEl = document.getElementById('selCount');
    var provBtn = document.getElementById('batchProvisionBtn');
    var revBtn = document.getElementById('batchRevokeBtn');
    if (!checks.length) return;

    function refresh() {
        var n = 0;
        checks.forEach(function (c) {
            var card = c.closest('.card');
            if (c.checked) {
                n++;
                if (card) card.classList.add('selected');
            } else if (card) {
                card.classList.remove('selected');
            }
        });
        if (countEl) countEl.textContent = n + ' selected';
        if (provBtn) provBtn.disabled = n === 0;
        if (revBtn) revBtn.disabled = n === 0;
    }

    checks.forEach(function (c) { c.addEventListener('change', refresh); });
    var allBtn = document.getElementById('selectAllBtn');
    var clearBtn = document.getElementById('clearSelBtn');
    if (allBtn) allBtn.addEventListener('click', function () {
        checks.forEach(function (c) { c.checked = true; });
        refresh();
    });
    if (clearBtn) clearBtn.addEventListener('click', function () {
        checks.forEach(function (c) { c.checked = false; });
        refresh();
    });
    refresh();
})();
</script>

</body>
</html>
