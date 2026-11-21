<?php
/** Path: /alarm/index.php */
ob_start(); 
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../db.php';
require_once __DIR__ . '/api/alarm_public_url.php'; 

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. SESSION IDENTIFICATION
if (!isset($_SESSION['user_id'])) { 
    $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === 'alarmpulse.ecommi.com') {
        header('Location: https://payroll.renocon.ca/login_kiosk.php');
    } else {
        header('Location: ../login_kiosk.php');
    } 
    exit; 
}

$current_user_id   = (int)$_SESSION['user_id'];
$current_user_name = $_SESSION['user_full_name'] ?? 'Staff Member';
$current_org_id    = (int)($_SESSION['org_id'] ?? 0);
$user_role         = (int)($_SESSION['user_role'] ?? 0);

// 2. PROCESS ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ACTION: STOP AND FINISH ALARM
    if (isset($_POST['stop_id'])) {
        $pdo->prepare("UPDATE alarm_tasks SET status = 'Completed' WHERE task_id = ?")->execute([$_POST['stop_id']]);
        header("Location: index.php"); exit;
    }

    // ACTION: ADD TASK
    if (isset($_POST['add_task'])) {
        $email = trim($_POST['share_email']);
        $title = $_POST['title'];
        $addr  = $_POST['address'];
        $date  = $_POST['date'];
        $time  = $_POST['time'];

        if (!empty($email)) {
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->rowCount() == 0) {
                $sql = "INSERT INTO users (Name, email, password, user_role, active, org_id) VALUES (?, ?, ?, ?, 1, ?)";
                $pdo->prepare($sql)->execute([$title . " Staff", $email, password_hash('1234', PASSWORD_DEFAULT), 1000, $current_org_id]);
            }
            
            $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nFrom: RenoCon Alarms <noreply@renocon.ca>";
            $msg = "<html><body><h2>New Task: {$title}</h2><p>Assigned by: {$current_user_name}</p><a href='" . alarmpulse_page_url('') . "'>Accept Task</a></body></html>";
            require_once __DIR__ . '/../engine/staging.php';
            if (!payroll_staging_outbound_blocked('mail')) {
                mail($email, "New Alarm Assignment", $msg, $headers);
            }
        }

        $sql = "INSERT INTO alarm_tasks (created_by, title, assigned_email, task_date, task_time, address, ringtone, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')";
        $pdo->prepare($sql)->execute([$current_user_id, $title, $email, $date, $time, $addr, $_POST['ringtone']]);
        header("Location: index.php?msg=ok"); exit;
    }

    if (isset($_POST['del_id'])) {
        $pdo->prepare("DELETE FROM alarm_tasks WHERE task_id = ?")->execute([$_POST['del_id']]);
        header("Location: index.php"); exit;
    }

    if (isset($_POST['accept_id'])) {
        $pdo->prepare("UPDATE alarm_tasks SET status = 'Active' WHERE task_id = ?")->execute([$_POST['accept_id']]);
        header("Location: index.php"); exit;
    }
}

// 3. FETCH DATA
$tasks = $pdo->query("SELECT t.*, u.Name as creator_name FROM alarm_tasks t LEFT JOIN users u ON t.created_by = u.id ORDER BY t.task_date ASC, t.task_time ASC")->fetchAll(PDO::FETCH_ASSOC);

$u_stmt = $pdo->prepare("SELECT assigned_site_ids FROM users WHERE id = ?");
$u_stmt->execute([$current_user_id]);
$assigned_str = $u_stmt->fetchColumn() ?: '0';
$clean_assigned = preg_replace('/[^0-9,]/', '', $assigned_str);

if ($user_role === 10) {
    $sites = $pdo->query("SELECT SId, nick_name FROM sites ORDER BY nick_name ASC")->fetchAll();
} else {
    $sites = $pdo->query("SELECT SId, nick_name FROM sites WHERE SId IN ($clean_assigned) ORDER BY nick_name ASC")->fetchAll();
}

if (file_exists('../engine/menu_generator.php')) { include_once '../engine/menu_generator.php'; }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <!-- pwa-head -->
    <?php if (file_exists(__DIR__ . '/pwa_head.php')) include __DIR__ . '/pwa_head.php'; ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alarm Terminal</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { background-color: #f8fafc; font-family: sans-serif; padding-bottom: 50px; }
        .alarm-wrapper { width: 100%; max-width: 500px; margin: 20px auto; padding: 0 15px; }
        .kiosk-card { background: #fff; padding: 25px; border-radius: 16px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
        .alarm-item { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; margin-bottom: 12px; border-left: 6px solid #cbd5e1; position: relative; }
        .status-active { border-left-color: #22c55e !important; }
        .status-pending { border-left-color: #f59e0b !important; }
        .status-completed { border-left-color: #64748b !important; opacity: 0.5; }
        .del-btn { position: absolute; top: 10px; right: 10px; color: #ef4444; border: none; background: none; font-size: 1.2rem; cursor: pointer; }
        label { font-weight: 700; font-size: 11px; text-transform: uppercase; color: #475569; margin-bottom: 5px; display: block; }
        #overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background: #ef4444; color: #fff; z-index: 9999; text-align: center; padding-top: 25%; }
        /* Simple Pulse effect for the "Sound Enabled" warning */
        .pulse { animation: shadow-pulse 2s infinite; }
        @keyframes shadow-pulse { 0% { box-shadow: 0 0 0 0px rgba(34, 197, 94, 0.4); } 100% { box-shadow: 0 0 0 15px rgba(34, 197, 94, 0); } }
    </style>
</head>
<body onclick="enableAudio()"> <?php if (function_exists('generateMenu')) { echo generateMenu($pdo); } ?>

<div class="alarm-wrapper">
    <div id="audio-warning" class="alert alert-warning text-center font-weight-bold pulse">
        ⚠️ CLICK ANYWHERE TO ENABLE ALARM SOUND
    </div>

    <div class="kiosk-card mb-4">
        <h5 class="font-weight-bold mb-3">Assign New Alarm</h5>
        <form method="POST">
            <div class="form-group"><label>Task Title</label><input type="text" name="title" class="form-control" required></div>
            <div class="row">
                <div class="col-6"><div class="form-group"><label>Date</label><input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required></div></div>
                <div class="col-6"><div class="form-group"><label>Time</label><input type="time" name="time" class="form-control" required></div></div>
            </div>
            <div class="form-group">
                <label>Site Location</label>
                <select id="site_picker" class="form-control" onchange="document.getElementById('task_address').value = this.value">
                    <option value="">-- Pick a Site --</option>
                    <?php foreach($sites as $s): ?><option value="<?= htmlspecialchars($s['nick_name']) ?>"><?= $s['nick_name'] ?></option><?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="address" id="task_address">
            <div class="form-group"><label>Staff Email</label><input type="email" name="share_email" class="form-control" placeholder="staff@example.com"></div>
            <div class="form-group"><label>Sound</label><select name="ringtone" class="form-control"><option value="ertugrul.mp3">Ertugrul</option><option value="alarm1.mp3">Standard</option></select></div>
            <button type="submit" name="add_task" class="btn btn-primary btn-block py-3 font-weight-bold shadow-sm">CREATE & NOTIFY</button>
        </form>
    </div>

    <h6 class="font-weight-bold text-muted mb-3" style="font-size: 11px;">SCHEDULE OVERVIEW</h6>
    <div id="alarm-list">
        <?php foreach($tasks as $t): ?>
            <div class="alarm-item shadow-sm status-<?= strtolower($t['status']) ?>" 
                 data-id="<?= $t['task_id'] ?>" data-date="<?= $t['task_date'] ?>" data-time="<?= substr($t['task_time'],0,5) ?>" data-ring="<?= $t['ringtone'] ?>" data-status="<?= $t['status'] ?>" data-title="<?= htmlspecialchars($t['title']) ?>">
                <form method="POST" onsubmit="return confirm('Delete?');"><input type="hidden" name="del_id" value="<?= $t['task_id'] ?>"><button type="submit" class="del-btn">&times;</button></form>
                <h6 class="m-0 font-weight-bold pr-4"><?= htmlspecialchars($t['title']) ?></h6>
                <small class="text-muted d-block mt-1">By: <?= htmlspecialchars($t['creator_name'] ?? 'System') ?></small>
                <small class="text-dark font-weight-bold"><?= date("g:i A", strtotime($t['task_time'])) ?></small>
                <span class="text-muted small mx-1">|</span><small class="text-muted"><?= $t['assigned_email'] ?></small>
                <?php if($t['status'] == 'Pending'): ?>
                    <form method="POST" class="mt-3"><input type="hidden" name="accept_id" value="<?= $t['task_id'] ?>"><button class="btn btn-sm btn-success btn-block font-weight-bold">ACCEPT & SET ALARM</button></form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div id="overlay">
    <h1 id="ring-title" class="display-4 font-weight-bold">ALARM</h1>
    <form id="stop-form" method="POST">
        <input type="hidden" name="stop_id" id="stop_task_id">
        <button type="submit" class="btn btn-light btn-lg rounded-pill px-5 mt-4 font-weight-bold shadow">STOP ALARM</button>
    </form>
</div>

<audio id="player" loop preload="auto"></audio>

<script>
    document.getElementById('site_picker').addEventListener('change', function() { document.getElementById('task_address').value = this.value; });
    const audio = document.getElementById('player');
    const userTz = "America/Edmonton";
    let audioAllowed = false;

    // This function unlocks the audio on the first click
    function enableAudio() {
        if (!audioAllowed) {
            audio.play().then(() => {
                audio.pause(); // Just play/pause to "unlock" the browser
                audioAllowed = true;
                document.getElementById('audio-warning').style.display = 'none';
            }).catch(e => console.log("Still waiting for real click"));
        }
    }

    setInterval(() => {
        const now = new Date();
        document.querySelectorAll('.alarm-item').forEach(card => {
            const curDate = new Intl.DateTimeFormat('en-CA', {timeZone: userTz, year: 'numeric', month: '2-digit', day: '2-digit'}).format(now);
            const curTime = new Intl.DateTimeFormat('en-CA', {timeZone: userTz, hour: '2-digit', minute: '2-digit', hour12: false}).format(now);
            
            if (curDate === card.dataset.date && curTime === card.dataset.time && card.dataset.status === 'Active') {
                if (audio.src.indexOf(card.dataset.ring) === -1) audio.src = 'static/' + card.dataset.ring;
                document.getElementById('ring-title').innerText = card.dataset.title;
                document.getElementById('stop_task_id').value = card.dataset.id;
                document.getElementById('overlay').style.display = 'block';
                
                // Play and catch errors if browser blocks it
                audio.play().catch(error => {
                    console.error("Audio blocked by browser. Click the page!");
                    document.getElementById('audio-warning').style.display = 'block';
                });
            }
        });
    }, 10000);
</script>
</body>
</html>