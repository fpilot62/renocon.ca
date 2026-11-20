<?php
/** Path: active/modules/payroll/payroll_dashboard.php */
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// AUTHENTICATION WALL
if (!isset($_SESSION['user_level']) || !isset($_SESSION['org_id'])) {
    header("Location: /login.php?error=unauthorized");
    exit();
}

require_once __DIR__ . '/db.php';
include_once __DIR__ . '/engine/menu_generator.php'; 
$nav_bar_html = function_exists('generateMenu') ? generateMenu($pdo) : '';

$user_lvl = (int)$_SESSION['user_level'];
$is_super = ($user_lvl <= 10);
$current_org_id = $is_super ? intval($_GET['org_filter'] ?? $_SESSION['org_id'] ?? 1) : (int)$_SESSION['org_id'];

// Fetch Organizations Map for Superadmin selection labels
$org_names_map = [];
if ($is_super) {
    $org_stmt = $pdo->query("SELECT id, org_name FROM organizations WHERE org_name IS NOT NULL AND org_name != '' ORDER BY id ASC");
    while ($row = $org_stmt->fetch(PDO::FETCH_ASSOC)) {
        $org_names_map[(int)$row['id']] = strtoupper($row['org_name']);
    }
}

// Fetch Pending/Active attendance rows for the viewing organization context
$query = "SELECT a.*, u.Name FROM attendance a 
          JOIN users u ON a.user_id = u.id 
          WHERE u.org_id = ? AND a.status != 'Finalized' 
          ORDER BY a.clock_in DESC";
$stmt = $pdo->prepare($query);
$stmt->execute([$current_org_id]);
$attendance_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payroll Verification Center</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f1f5f9; margin: 0; padding: 0; }
        .main-content { padding: 100px 20px 40px 20px; display: flex; flex-direction: column; align-items: center; }
        .wrapper { width: 100%; max-width: 1150px; background: white; padding: 35px; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .heading-row { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px; margin-bottom: 25px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { background: #0f172a; color: white; padding: 12px 10px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        td { padding: 12px 10px; border-bottom: 1px solid #e2e8f0; color: #334155; }
        .btn-action { background: #3b82f6; color: white; border: none; padding: 8px 14px; border-radius: 5px; font-weight: bold; cursor: pointer; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; }
        .btn-finalize { background: #0f172a; }
        .btn-finalize:hover { background: #1e293b; }
    </style>
</head>
<body>
<?= $nav_bar_html; ?>

<div class="main-content">
    <div class="wrapper">
        <div class="heading-row">
            <div>
                <h2 style="margin:0; color:#0f172a;"><i class="fa fa-receipt" style="color:#3b82f6;"></i> Payroll Timesheet Processing Center</h2>
                <p style="margin:5px 0 0 0; font-size:13px; color:#64748b;">Review open logs, manage granular allocations, or execute macro split routines on groups.</p>
            </div>
            
            <?php if ($is_super): ?>
                <div style="background:#f8fafc; border:1px solid #cbd5e1; padding:8px 12px; border-radius:6px; display:flex; align-items:center; gap:10px;">
                    <label style="font-size:11px; font-weight:bold; color:#475569; text-transform:uppercase;">View Enterprise Group:</label>
                    <select onchange="location.href='?org_filter='+this.value" style="padding:6px 10px; border-radius:4px; border:1px solid #cbd5e1; font-weight:bold; color:#2563eb; cursor:pointer;">
                        <?php foreach($org_names_map as $oId => $oLabel): ?>
                            <option value="<?=$oId?>" <?=$current_org_id === $oId ? 'selected' : ''?>><?=$oLabel?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>

        <form method="POST" action="bulk_allocation_desk.php">
            
            <div style="margin-bottom: 20px; display:flex; gap:10px;">
                <button type="submit" class="btn-action" style="background:#65a30d;">
                    <i class="fa fa-arrow-right"></i> Proceed to Bulk Allocation Desk
                </button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width:40px; text-align:center;"><input type="checkbox" id="master_checkbox" onclick="toggleAllRows(this)"></th>
                        <th>Staff Name</th>
                        <th>Clock In</th>
                        <th>Clock Out</th>
                        <th style="text-align:center;">Total Hours</th>
                        <th style="text-align:center; width:140px;">Direct Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attendance_rows)): ?>
                        <tr><td colspan="6" style="text-align:center; padding:30px; color:#94a3b8; font-style:italic;">No pending attendance logs located for this entity.</td></tr>
                    <?php else: foreach($attendance_rows as $row): ?>
                        <tr>
                            <td style="text-align:center;"><input type="checkbox" name="record_ids[]" class="record-checkbox" value="<?=$row['id']?>"></td>
                            <td style="font-weight:bold; color:#0f172a;"><?=strtoupper($row['Name'])?></td>
                            <td><?=date('M j, Y - g:ia', strtotime($row['clock_in']))?></td>
                            <td>
                                <?php if (!empty($row['clock_out'])): ?>
                                    <?= date('M j, Y - g:ia', strtotime($row['clock_out'])) ?>
                                <?php else: ?>
                                    <span style="background: #ecfdf5; color: #059669; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; border: 1px solid #a7f3d0;"><i class="fa fa-circle-play animate-pulse"></i> ACTIVE / WORKING</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center; font-weight:bold; color:#2563eb;"><?= number_format(floatval($row['approved_hours'] ?? 0), 2) ?>h</td>
                            <td style="text-align:center;">
                                <a href="finalize_shift.php?id=<?=$row['id']?>" class="btn-action btn-finalize"><i class="fa fa-calculator"></i> Finalize Line</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </form>
    </div>
</div>

<script>
function toggleAllRows(master) { 
    document.querySelectorAll('.record-checkbox').forEach(cb => cb.checked = master.checked); 
}
</form>
</body>
</html>