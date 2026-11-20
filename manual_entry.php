<?php
/** Path: /payroll/manual_entry.php */
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] != 1) { 
    header("Location: login.php"); exit; 
}

$msg = "";

// Handle Manual Form Submission
if (isset($_POST['add_manual'])) {
    $user_id = $_POST['user_id'];
    $site_id = $_POST['site_id'];
    $work_date = $_POST['work_date'];
    $hours = (float)$_POST['hours'];
    
    // Calculate start/end times based on the date and hours provided
    $start_time = $work_date . " 09:00:00"; // Default start
    $end_timestamp = strtotime($start_time) + ($hours * 3600);
    $end_time = date("Y-m-d H:i:s", $end_timestamp);

    $sql = "INSERT INTO shifts (user_id, site_id, shift_start, shift_end, status) VALUES (?, ?, ?, ?, 'Completed')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $site_id, $start_time, $end_time]);
    $msg = "Manual record added successfully!";
}

// Fetch Employees and Sites for dropdowns
$employees = $pdo->query("SELECT id, email FROM users WHERE user_role = 0")->fetchAll();
$sites = $pdo->query("SELECT SId, nick_name FROM sites WHERE module = 'clocking'")->fetchAll();

include 'menu_include.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manual Payroll Entry</title>
    <style>
        .container { padding: 30px; max-width: 800px; margin: auto; font-family: sans-serif; }
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 13px; }
        select, input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
        .btn-submit { background: #008ba3; color: white; border: none; padding: 12px; border-radius: 4px; cursor: pointer; font-weight: bold; width: 100%; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h2><i class="fas fa-edit"></i> Manual Shift Entry</h2>
        <p>Use this to add records for employees who did not use the Kiosk.</p>
        <?php if($msg) echo "<p style='color:green;'>$msg</p>"; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Employee</label>
                <select name="user_id" required>
                    <?php foreach($employees as $e): ?>
                        <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['email']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Work Location</label>
                <select name="site_id" required>
                    <?php foreach($sites as $s): ?>
                        <option value="<?= $s['SId'] ?>"><?= htmlspecialchars($s['nick_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Date Worked</label>
                <input type="date" name="work_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label>Total Hours Worked</label>
                <input type="number" step="0.01" name="hours" placeholder="e.g. 8.5" required>
            </div>
            <button type="submit" name="add_manual" class="btn-submit">SAVE TO PAYROLL</button>
        </form>
    </div>
</div>
</body>
</html>