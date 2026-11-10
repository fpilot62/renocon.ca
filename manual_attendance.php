<?php
/**
 * Path: /manual_attendance.php
 * PRODUCTION CONSOLE - MANUAL ATTENDANCE SHIFT INJECTOR
 * Fix: Synchronizes rest break inputs to automatically mirror the shift's selected clock-in date.
 * Org: attendance.org_id is sites.org_id for the chosen site (sites.SId), not the admin session org.
 */
require_once __DIR__ . '/engine/attendance_integrity_lib.php';
payroll_require_admin(false, 'Manual attendance is for admins only.');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';
payroll_require_scope_admin();

if (file_exists('engine/menu_generator.php')) {
    require_once 'engine/menu_generator.php';
}

$sess_user_level = isset($_SESSION['user_level']) ? (int)$_SESSION['user_level'] : 99;
$session_org = (int)($_SESSION['org_id'] ?? 0);
$is_super = $sess_user_level <= 10;
if ($is_super) {
    $org_raw = $_POST['org_id'] ?? ($_GET['org_id'] ?? ($_COOKIE['sip_org_id'] ?? ($_COOKIE['sa_org_id'] ?? '')));
    $selected_org = ($org_raw === '' || $org_raw === 'all') ? 0 : (int)$org_raw;
} else {
    $selected_org = $session_org;
}

$success_message = false;
$form_error = '';
$posted_user = (int)($_POST['target_user_id'] ?? 0);
$posted_site = (int)($_POST['target_site_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_manual_shift') {
    $target_user = (int)($_POST['target_user_id'] ?? 0);
    $target_site = (int)($_POST['target_site_id'] ?? 0);
    $clock_in    = trim((string)($_POST['clock_in'] ?? ''));
    $clock_out   = trim((string)($_POST['clock_out'] ?? ''));
    $manager_notes = trim((string)($_POST['manager_notes'] ?? ''));
    $shift_status  = isset($_POST['is_transit']) && $_POST['is_transit'] == '1' ? 5 : 1;

    if ($target_user <= 0 || $target_site <= 0 || $clock_in === '') {
        $form_error = 'Choose an employee, a site, and a clock-in time.';
    } else {
        try {
            $siteStmt = $pdo->prepare('SELECT SId, org_id FROM sites WHERE SId = ? LIMIT 1');
            $siteStmt->execute([$target_site]);
            $siteRow = $siteStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $userStmt = $pdo->prepare('SELECT id, org_id, primary_site_id FROM users WHERE id = ? LIMIT 1');
            $userStmt->execute([$target_user]);
            $userRow = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $decision = att_manual_shift_decision($siteRow, $userRow, $sess_user_level, $session_org, $selected_org);
            if (!$decision['ok']) {
                $form_error = $decision['error'];
            } else {
                $pdo->beginTransaction();

                $insQuery = "INSERT INTO attendance
                    (org_id, site_id, user_id, status, clock_in, clock_out, manager_notes, payout_status, is_processed, selfie_in, selfie_out, remarks)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 'nophoto.jpg', 'nophoto.jpg', ?)";

                $stmt = $pdo->prepare($insQuery);
                $stmt->execute([
                    (int)$decision['org_id'],
                    $target_site,
                    $target_user,
                    $shift_status,
                    str_replace('T', ' ', $clock_in),
                    $clock_out !== '' ? str_replace('T', ' ', $clock_out) : null,
                    $manager_notes,
                    att_manual_remark(),
                ]);

                $new_shift_id = $pdo->lastInsertId();

                if (!empty($_POST['break_start']) && is_array($_POST['break_start'])) {
                    $insBreak = $pdo->prepare("INSERT INTO breaks (shift_id, break_start, break_end) VALUES (?, ?, ?)");
                    foreach ($_POST['break_start'] as $idx => $bStart) {
                        $bEnd = $_POST['break_end'][$idx] ?? '';
                        if (!empty(trim((string)$bStart))) {
                            $insBreak->execute([
                                $new_shift_id,
                                str_replace('T', ' ', trim((string)$bStart)),
                                trim((string)$bEnd) !== '' ? str_replace('T', ' ', trim((string)$bEnd)) : null
                            ]);
                        }
                    }
                }

                $pdo->commit();
                $success_message = true;
                $posted_user = 0;
                $posted_site = 0;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $form_error = 'The shift was not saved.';
        }
    }
}

$orgs = [];
if ($is_super) {
    try {
        $orgStmt = $pdo->query('SELECT id, org_name FROM organizations ORDER BY org_name ASC');
        $orgs = $orgStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $orgs = [];
    }
}

$roster = [];
$sites = [];
if ($selected_org > 0) {
    $rStmt = $pdo->prepare('SELECT id, Name, primary_site_id FROM users WHERE org_id = ? ORDER BY Name ASC');
    $rStmt->execute([$selected_org]);
    $roster = $rStmt->fetchAll(PDO::FETCH_ASSOC);

    $sStmt = $pdo->prepare('SELECT SId, nick_name, org_id FROM sites WHERE org_id = ? ORDER BY nick_name ASC');
    $sStmt->execute([$selected_org]);
    $sites = $sStmt->fetchAll(PDO::FETCH_ASSOC);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manual Shift Injection Module</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; padding-top: 120px; font-size: 12px; color: #1e293b; }
        .form-wrapper { max-width: 780px; margin: 0 auto; padding: 0 20px; }
        .form-card { background: white; border-radius: 12px; padding: 30px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }
        
        h2 { margin: 0 0 5px 0; font-size: 18px; font-weight: 800; color: #0f172a; }
        p.subtitle { margin: 0 0 25px 0; color: #64748b; font-size: 11px; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .input-group { display: flex; flex-direction: column; }
        .input-group label { font-weight: 800; font-size: 10px; text-transform: uppercase; color: #475569; margin-bottom: 6px; letter-spacing: 0.5px; }
        .input-group select, .input-group input, .input-group textarea { padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12px; font-weight: 600; background: white; box-sizing: border-box; }
        .input-group select, .input-group input { height: 38px; }
        
        .breaks-section { border-top: 2px dashed #cbd5e1; margin-top: 25px; padding-top: 20px; }
        .breaks-section h4 { margin: 0 0 15px 0; font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; }
        .break-input-row { display: flex; gap: 12px; align-items: center; margin-bottom: 12px; background: #f8fafc; padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0; }
        
        .btn-submit { background: #27ae60; color: white; border: none; font-weight: bold; font-size: 12px; padding: 12px 24px; border-radius: 6px; cursor: pointer; text-transform: uppercase; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 10px rgba(39,174,96,0.25); transition: background 0.15s; }
        .btn-submit:hover { background: #219653; }
        .btn-add-break { background: #3b82f6; color: white; border: none; padding: 6px 12px; border-radius: 6px; font-weight: bold; font-size: 11px; cursor: pointer; margin-bottom: 20px; }
        
        .success-banner { background: #dcfce7; border: 1px solid #bbf7d0; color: #16a34a; padding: 15px; border-radius: 8px; font-weight: bold; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .success-banner a { background: #16a34a; color: white; text-decoration: none; padding: 4px 10px; border-radius: 4px; font-size: 11px; }
        .error-banner { background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; padding: 15px; border-radius: 8px; font-weight: bold; margin-bottom: 20px; }
    </style>
</head>
<body>

<?php if (function_exists('generateMenu')) echo generateMenu($pdo); ?>

<div class="form-wrapper">
    <?php if ($success_message): ?>
        <div class="success-banner">
            <span><i class="fas fa-check-circle"></i> Shift Log Injected Successfully! Punches and associated break rows committed.</span>
            <a href="staff_audit.php<?= $selected_org > 0 ? '?org_id=' . (int)$selected_org : '' ?>">Go to Audit Panel</a>
        </div>
    <?php endif; ?>
    <?php if ($form_error !== ''): ?>
        <div class="error-banner"><?= htmlspecialchars($form_error) ?></div>
    <?php endif; ?>

    <div class="form-card">
        <h2><i class="fas fa-calendar-plus" style="color:#27ae60;"></i> Manual Attendance Shift Injector Console</h2>
        <p class="subtitle">Bypass operational endpoints to create historical shift data directly inside unfinalized workspaces. The shift is stored under the site's organization.</p>

        <?php if ($is_super): ?>
            <div class="input-group" style="margin-bottom:20px;">
                <label>Organization</label>
                <select title="Organization" onchange="window.location='manual_attendance.php?org_id='+encodeURIComponent(this.value)">
                    <option value="">Choose organization...</option>
                    <?php foreach ($orgs as $o): ?>
                        <option value="<?= (int)$o['id'] ?>" <?= $selected_org === (int)$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$o['org_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <?php if ($selected_org <= 0): ?>
            <p class="subtitle">Choose an organization before adding a shift.</p>
        <?php else: ?>
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_manual_shift">
            <input type="hidden" name="org_id" value="<?= (int)$selected_org ?>">
            
            <div class="form-grid">
                <div class="input-group">
                    <label>Select Staff Member</label>
                    <select name="target_user_id" id="targetUser" required>
                        <option value="">Choose Employee...</option>
                        <?php foreach ($roster as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" data-primary-site="<?= (int)($u['primary_site_id'] ?? 0) ?>" <?= $posted_user === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$u['Name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label>Assigned Work Project Site</label>
                    <select name="target_site_id" id="targetSite" required>
                        <option value="">Choose Location...</option>
                        <?php foreach ($sites as $s): ?>
                            <option value="<?= (int)$s['SId'] ?>" <?= $posted_site === (int)$s['SId'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$s['nick_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-grid">
                <div class="input-group">
                    <label>Main Shift Clock In Punch</label>
                    <input type="datetime-local" id="masterShiftClockIn" name="clock_in" required oninput="synchronizeLiveBreakDates(this.value)" value="<?= htmlspecialchars((string)($_POST['clock_in'] ?? '')) ?>">
                </div>
                <div class="input-group">
                    <label>Main Shift Clock Out Punch</label>
                    <input type="datetime-local" id="masterShiftClockOut" name="clock_out" value="<?= htmlspecialchars((string)($_POST['clock_out'] ?? '')) ?>">
                </div>
            </div>

            <div class="form-grid" style="grid-template-columns: 1fr;">
                <div class="input-group">
                    <label>Operational Shift Options Context</label>
                    <div style="display:flex; gap:20px; align-items:center; margin-top:5px;">
                        <label style="display:flex; align-items:center; gap:6px; color:#1e293b; cursor:pointer;">
                            <input type="radio" name="is_transit" value="0" <?= (($_POST['is_transit'] ?? '0') !== '1') ? 'checked' : '' ?> style="height:16px; width:16px; margin:0;"> Standard On-Site Work Log
                        </label>
                        <label style="display:flex; align-items:center; gap:6px; color:#d97706; cursor:pointer;">
                            <input type="radio" name="is_transit" value="1" <?= (($_POST['is_transit'] ?? '0') === '1') ? 'checked' : '' ?> style="height:16px; width:16px; margin:0;"> Flag Shift as Transit Travel Corridor
                        </label>
                    </div>
                </div>
                <div class="input-group" style="margin-top:10px;">
                    <label>Administrative Manager Audit Notes</label>
                    <textarea name="manager_notes" rows="3" placeholder="Provide background override reasoning data remarks..."><?= htmlspecialchars((string)($_POST['manager_notes'] ?? '')) ?></textarea>
                </div>
            </div>

            <div class="breaks-section">
                <h4><i class="fas fa-mug-hot"></i> Supplemental Shift Rest Breaks</h4>
                <button type="button" class="btn-add-break" onclick="appendFreshBreakRow()"><i class="fas fa-plus"></i> Add Break Row</button>
                <div id="manualBreaksAppendContainer"></div>
            </div>

            <div style="text-align:right; margin-top:30px; border-top:1px solid #e2e8f0; padding-top:20px;">
                <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Save Operational Record</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
let activelySelectedClockInDate = '';

function synchronizeLiveBreakDates(clockInDateTimeValue) {
    if (!clockInDateTimeValue) return;
    
    activelySelectedClockInDate = clockInDateTimeValue.split('T')[0];
    
    const visibleStartInputs = document.querySelectorAll("input[name='break_start[]']");
    const visibleEndInputs = document.querySelectorAll("input[name='break_end[]']");
    
    visibleStartInputs.forEach((input) => {
        if (!input.value) {
            input.value = activelySelectedClockInDate + "T12:00";
        } else {
            let currentHoursMinutes = input.value.split('T')[1] || "12:00";
            input.value = activelySelectedClockInDate + "T" + currentHoursMinutes;
        }
    });

    visibleEndInputs.forEach((input) => {
        if (!input.value) {
            input.value = activelySelectedClockInDate + "T12:30";
        } else {
            let currentHoursMinutes = input.value.split('T')[1] || "12:30";
            input.value = activelySelectedClockInDate + "T" + currentHoursMinutes;
        }
    });
}

function appendFreshBreakRow() {
    const container = document.getElementById('manualBreaksAppendContainer');
    const rowDiv = document.createElement('div');
    rowDiv.className = 'break-input-row';
    
    let targetDayPrefix = activelySelectedClockInDate ? activelySelectedClockInDate : new Date().toISOString().slice(0, 10);
    
    let defaultStartVal = targetDayPrefix + "T12:00";
    let defaultEndVal   = targetDayPrefix + "T12:30";
    
    rowDiv.innerHTML = `
        <div style="flex:1; display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:9px; font-weight:800; color:#64748b; text-transform:uppercase;">Break Start</label>
            <input type="datetime-local" name="break_start[]" value="${defaultStartVal}" required style="height:34px; padding:6px; border:1px solid #cbd5e1; border-radius:6px; font-size:11px; font-weight:600;">
        </div>
        <div style="flex:1; display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:9px; font-weight:800; color:#64748b; text-transform:uppercase;">Break End</label>
            <input type="datetime-local" name="break_end[]" value="${defaultEndVal}" style="height:34px; padding:6px; border:1px solid #cbd5e1; border-radius:6px; font-size:11px; font-weight:600;">
        </div>
        <button type="button" style="background:#ef4444; border:none; color:white; padding:8px 12px; border-radius:6px; margin-top:14px; cursor:pointer;" onclick="this.parentNode.remove()"><i class="fas fa-trash-alt"></i></button>
    `;
    container.appendChild(rowDiv);
}

function applyEmployeePrimarySite(force) {
    const userSel = document.getElementById('targetUser');
    const siteSel = document.getElementById('targetSite');
    if (!userSel || !siteSel) return;
    const opt = userSel.options[userSel.selectedIndex];
    const primary = opt ? (opt.getAttribute('data-primary-site') || '') : '';
    if (!primary || primary === '0') return;
    const match = Array.from(siteSel.options).some(function (o) { return o.value === primary; });
    if (!match) return;
    if (force || !siteSel.value) siteSel.value = primary;
}

document.addEventListener("DOMContentLoaded", function() {
    const clockInField = document.getElementById('masterShiftClockIn');
    if (clockInField && clockInField.value) {
        activelySelectedClockInDate = clockInField.value.split('T')[0];
    }
    const userSel = document.getElementById('targetUser');
    if (userSel) {
        userSel.addEventListener('change', function () { applyEmployeePrimarySite(true); });
        applyEmployeePrimarySite(false);
    }
    if (document.getElementById('manualBreaksAppendContainer')) {
        appendFreshBreakRow();
    }
});
</script>
</body>
</html>
