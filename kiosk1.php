<?php
/**
 * Path: /kiosk1.php (SANDBOX TESTING TERMINAL)
 * UI Logic: Kiosk Terminal Layout with Custom Exception Reason Capture Prompts
 * DB Sync: Aligned to 'flag_reason' layout keys & auto-refresh drop selectors
 * Sandbox Target: Forms submit directly to process_punch1.php
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php'; 
require_once 'engine/menu_generator.php';
require_once 'engine/org_modules_lib.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) { 
    header("Location: login_kiosk.php"); 
    exit; 
}
$uid = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT id, status, site_id, clock_out FROM attendance 
                       WHERE user_id = ? 
                       ORDER BY (clock_out IS NULL OR TRIM(clock_out) = '' OR clock_out = '0000-00-00 00:00:00') DESC, id DESC LIMIT 1");
$stmt->execute([$uid]);
$last = $stmt->fetch();

$is_shift_open = false;
if ($last) {
    $co = trim((string)$last['clock_out']);
    if ($co === '' || $co === '0000-00-00 00:00:00') { $is_shift_open = true; }
}

if ($is_shift_open) {
    $db_status = strtolower(trim((string)$last['status']));
    $current_attendance_id = (int)$last['id'];
} else {
    $db_status = '4';
    $current_attendance_id = 0;
}

$has_open_break = false;
if ($current_attendance_id > 0) {
    $breakStmt = $pdo->prepare("SELECT id FROM breaks WHERE shift_id = ? AND break_end IS NULL ORDER BY id DESC LIMIT 1");
    $breakStmt->execute([$current_attendance_id]);
    if ($breakStmt->fetch()) { $has_open_break = true; }
}

if ($has_open_break || $db_status === '1' || $db_status === 'on break') {
    $mode = 'BREAK';
} elseif ($is_shift_open && ($db_status === '5' || $db_status === 'transfer-out' || $db_status === 'travel')) {
    $mode = 'TRANSIT';
} elseif ($is_shift_open && ($db_status === '0' || $db_status === 'working' || $db_status === 'clocked in' || $db_status === '6' || $db_status === 'transfer-in')) {
    $mode = 'WORKING';
} else {
    $mode = 'OFF_CLOCK';
}

$url_site_id = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;

if ($mode === 'TRANSIT') {
    $current_site_id = ($url_site_id > 0) ? $url_site_id : '';
} else {
    $current_site_id = ($last && $is_shift_open) ? $last['site_id'] : ($url_site_id > 0 ? $url_site_id : '');
}

$sites = $_SESSION['inventory_sites'] ?? [];
$active_kiosk_site_id = ($mode === 'WORKING' || $mode === 'BREAK' || $mode === 'TRANSIT') ? (int)$current_site_id : ($url_site_id > 0 ? $url_site_id : 0);
$kiosk_resolved_timezone = 'America/Edmonton';

if ($active_kiosk_site_id > 0) {
    $kioskTimeStmt = $pdo->prepare("SELECT Timezone FROM sites WHERE Sid = ? LIMIT 1");
    $kioskTimeStmt->execute([$active_kiosk_site_id]);
    $kioskTimeRow = $kioskTimeStmt->fetch();
    if ($kioskTimeRow && !empty(trim($kioskTimeRow['Timezone']))) {
        $kiosk_resolved_timezone = trim($kioskTimeRow['Timezone']);
    }
}
date_default_timezone_set($kiosk_resolved_timezone);

$kiosk_self = basename($_SERVER['PHP_SELF'] ?? 'kiosk1.php');
if (!in_array($kiosk_self, ['kiosk.php', 'kiosk1.php'], true)) {
    $kiosk_self = 'kiosk1.php';
}
$kiosk_org_id = (int)($_SESSION['org_id'] ?? 0);
$restrain_clockin = sip_org_restrain_clockin($pdo, $kiosk_org_id);
$schedule_margin_min = sip_org_schedule_margin_minutes($pdo, $kiosk_org_id);
$sched_status = ['on_schedule' => true, 'has_row' => false, 'outside_window' => false, 'reason' => ''];
if ($mode === 'OFF_CLOCK' && (int)$url_site_id > 0) {
    $sched_status = sip_employee_schedule_status($pdo, $uid, (int)$url_site_id, $schedule_margin_min);
}
$off_schedule = !empty($sched_status['outside_window']);
$block_clock_in = $off_schedule && $restrain_clockin;
$need_sched_reason = $off_schedule && !$restrain_clockin;
$sched_why = (string)($_GET['sched_why'] ?? ($sched_status['reason'] ?? ''));
$show_reason_box = $need_sched_reason || (isset($_GET['prompt_reason']) && (int)$_GET['prompt_reason'] === 1 && !empty($url_site_id));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sandbox Kiosk Terminal (Testing)</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f1f5f9; color: #1e293b; padding-top: 80px; font-family: 'Inter', sans-serif; }
        .kiosk-card { max-width: 420px; margin: 0 auto; background: white; padding: 30px; border-radius: 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); border: 2px dashed #3b82f6; } /* Dashed border signals test mode */
        #video { width: 100%; border-radius: 12px; transform: scaleX(-1); background: #000; margin-bottom: 20px; border: 3px solid #f1f5f9; }
        .btn-punch { padding: 16px; font-weight: 800; border-radius: 12px; text-transform: uppercase; margin-bottom: 10px; border: none; transition: 0.2s; }
        .transit-banner { background: #0ea5e9; color: white; padding: 10px; border-radius: 10px; margin-bottom: 15px; font-weight: bold; font-size: 14px; }
        select:disabled { background-color: #f8fafc !important; cursor: not-allowed; color: #64748b !important; opacity: 1; }
        .sandbox-indicator { background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; padding: 8px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; font-size: 11px; }
    </style>
</head>
<body>
    <?= generateMenu($pdo) ?>
    <div class="container">
        <div class="kiosk-card text-center">
            
            <div class="sandbox-indicator"><i class="fas fa-flask"></i> Isolated Sandbox Environment Active (<?= htmlspecialchars($kiosk_self) ?>)</div>

            <div class="alert <?= $restrain_clockin ? 'alert-warning' : 'alert-info' ?> text-left" style="border-radius:12px; font-size:12px; margin-bottom:16px;">
                <?php if ($restrain_clockin): ?>
                    <strong>Org rule:</strong> if you are not on the schedule, Clock In is blocked (<?= (int)$schedule_margin_min ?> min early/late margin).
                <?php else: ?>
                    <strong>Org rule:</strong> if you are not on the schedule, type a reason — it is recorded (<?= (int)$schedule_margin_min ?> min early/late margin).
                <?php endif; ?>
            </div>

            <?php if (isset($_GET['error_alert']) || $block_clock_in): ?>
                <div class="alert alert-danger font-weight-bold text-left" style="border-radius:12px; font-size:12px; margin-bottom:20px;">
                    <?php if (($_GET['error_alert'] ?? '') === 'geofence_blocked'): ?>
                        🚨 <strong>Access Denied:</strong> Out of Bounds. Zero-tolerance hard locking is active. (Detected: <?= htmlspecialchars($_GET['distance'] ?? '0') ?>m away).
                    <?php else: ?>
                        <?php if ($sched_why === 'early'): ?>
                            📅 <strong>Clock-in blocked.</strong> You are early. Wait until <?= (int)$schedule_margin_min ?> minutes before your scheduled start.
                        <?php elseif ($sched_why === 'late'): ?>
                            📅 <strong>Clock-in blocked.</strong> You are past the scheduled window (including <?= (int)$schedule_margin_min ?> late minutes). Ask a supervisor.
                        <?php else: ?>
                            📅 <strong>Clock-in blocked.</strong> You are not on today's schedule at this site. Ask a supervisor.
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($show_reason_box): ?>
                <div class="p-3 mb-4 text-left" style="background:#fff7ed; border:1px dashed #f97316; border-radius:12px; font-size:12px;">
                    <strong style="color:#ea580c;"><i class="fas fa-exclamation-triangle"></i> Reason required:</strong><br>
                    <?php if(($_GET['geo_fail'] ?? 0) == 1) echo "• Device location is outside the authorized geofence radius.<br>"; ?>
                    <?php if($need_sched_reason || ($_GET['sched_fail'] ?? 0) == 1): ?>
                        <?php if ($sched_why === 'early'): ?>
                            • You are early for your scheduled shift.<br>
                        <?php elseif ($sched_why === 'late'): ?>
                            • You are late / past the scheduled window.<br>
                        <?php else: ?>
                            • You are not on today's schedule at this site.<br>
                        <?php endif; ?>
                    <?php endif; ?>
                    <p class="text-muted mt-2 mb-0">Type a reason. It is stored on the punch.</p>
                </div>
            <?php endif; ?>

            <?php if($mode === 'TRANSIT'): ?>
                <div class="transit-banner">MODE: IN TRANSIT<br><small>Select your NEW destination site below and click Arrive</small></div>
            <?php endif; ?>

            <video id="video" autoplay playsinline></video>

            <form id="punchForm" action="process_punch1.php" method="POST">
                <input type="hidden" name="action" id="action">
                <input type="hidden" name="image" id="image">
                <input type="hidden" name="lat" id="lat">
                <input type="hidden" name="lng" id="lng">

                <select name="site_id" id="site_id" class="form-control mb-3" onchange="window.location.href='<?= htmlspecialchars($kiosk_self) ?>?site_id='+this.value" <?= ($mode === 'WORKING' || $mode === 'BREAK') ? 'disabled' : 'required' ?>>
                    <option value="">-- Choose Site --</option>
                    <?php foreach($sites as $si): 
                        $resolved_dropdown_site_id = isset($si['Sid']) ? (int)$si['Sid'] : ((int)$si['id'] ?? 0);
                        $selected_marker = ((int)$current_site_id === $resolved_dropdown_site_id) ? 'selected' : '';
                    ?>
                        <option value="<?= $resolved_dropdown_site_id ?>" <?= $selected_marker ?>>
                            <?= htmlspecialchars($si['nick_name'] ?? 'Named Site') ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if($mode === 'WORKING' || $mode === 'BREAK'): ?>
                    <input type="hidden" name="site_id" value="<?= $current_site_id ?>">
                <?php endif; ?>

                <?php if ($show_reason_box && !$block_clock_in): ?>
                    <input type="text" name="flag_reason" id="flag_reason" class="form-control mb-4" placeholder="Type reason for override..." required style="border: 2px solid #ea580c; font-weight:bold;">
                <?php endif; ?>

                <div class="controls">
                    <?php if ($mode === 'OFF_CLOCK' && $block_clock_in): ?>
                        <button type="button" class="btn btn-secondary btn-block btn-punch" disabled>Clock In blocked</button>
                    <?php elseif ($mode === 'OFF_CLOCK'): ?>
                        <button type="button" onclick="doPunch('clock_in', false)" class="btn btn-success btn-block btn-punch">Clock In</button>
                    <?php elseif ($mode === 'TRANSIT'): ?>
                        <button type="button" onclick="doPunch('transfer_in', false)" class="btn btn-primary btn-block btn-punch">Arrive at New Site (Transfer In)</button>
                    <?php elseif ($mode === 'WORKING'): ?>
                        <button type="button" onclick="doPunch('clock_out', true)" class="btn btn-danger btn-block btn-punch">Clock Out</button>
                        <div class="row no-gutters">
                            <div class="col-6 pr-1"><button type="button" onclick="doPunch('transfer_out', true)" class="btn btn-info btn-block btn-punch">Transfer Out</button></div>
                            <div class="col-6 pl-1"><button type="button" onclick="doPunch('break_start', true)" class="btn btn-warning btn-block btn-punch text-white">Break</button></div>
                        </div>
                    <?php elseif ($mode === 'BREAK'): ?>
                        <button type="button" onclick="doPunch('break_end', true)" class="btn btn-primary btn-block btn-punch">Resume Work</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <canvas id="canvas" style="display:none;"></canvas>
    <script>
        const v = document.getElementById('video');
        navigator.mediaDevices.getUserMedia({video: true}).then(s => v.srcObject = s).catch(e => console.log("Camera access disabled"));

        function doPunch(act, skipSiteCheck) {
            const siteSelect = document.getElementById('site_id');
            const reasonInput = document.getElementById('flag_reason');
            
            if (!skipSiteCheck && (!siteSelect || !siteSelect.value)) { 
                return alert("Please select your destination site before completing the action."); 
            }
            if (reasonInput && !reasonInput.value.trim()) { 
                return alert("Policy compliance requires an override justification text string to punch."); 
            }

            if(siteSelect) siteSelect.disabled = false;
            const btn = window.event ? window.event.target : null;
            if (btn && btn.tagName === 'BUTTON') { btn.disabled = true; btn.innerText = "Processing Engine..."; }

            navigator.geolocation.getCurrentPosition(pos => {
                const c = document.getElementById('canvas');
                c.width = v.videoWidth; c.height = v.videoHeight;
                c.getContext('2d').drawImage(v, 0, 0);
                
                document.getElementById('action').value = act;
                document.getElementById('lat').value = pos.coords.latitude;
                document.getElementById('lng').value = pos.coords.longitude;
                document.getElementById('image').value = c.toDataURL('image/jpeg', 0.6);

                document.getElementById('punchForm').submit();
            }, err => {
                alert("GPS Geolocation data capture is required for structural system authorization.");
                if (btn && btn.tagName === 'BUTTON') { btn.disabled = false; btn.innerText = act.replace('_', ' ').toUpperCase(); }
            });
        }
    </script>
</body>
</html>