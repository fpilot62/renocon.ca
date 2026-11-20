<?php
/** Path: /edit_shift.php */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once __DIR__ . '/engine/attendance_audit_lib.php';
require_once 'engine/menu_generator.php';

// 1. SECURITY
if (!isset($_SESSION['user_level']) || $_SESSION['user_level'] > 20) {
    die("Access Denied: Admin privileges required.");
}

$shift_id = $_GET['id'] ?? null;
$msg = "";
$status = "";

if (!$shift_id) { die("Invalid Shift ID"); }

// 2. HANDLE UPDATES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_full_shift'])) {
    try {
        $pdo->beginTransaction();

        // Update Master Attendance Entry
        $aaBefore = aa_snapshot($pdo, (int)$shift_id);
        $stmt = $pdo->prepare("UPDATE attendance SET clock_in = ?, clock_out = ?, site_id = ? WHERE id = ?");
        $stmt->execute([$_POST['c_in'], $_POST['c_out'] ?: null, $_POST['site_id'], $shift_id]);
        aa_log($pdo, 'update', (int)$shift_id, $aaBefore, null, 'Edit shift times');

        // Wipe existing child break lines to commit modified values accurately
        $delBreaks = $pdo->prepare("DELETE FROM breaks WHERE shift_id = ?");
        $delBreaks->execute([$shift_id]);

        // Re-insert break lines from array matrices dynamically
        if (isset($_POST['b_start']) && is_array($_POST['b_start'])) {
            foreach ($_POST['b_start'] as $index => $b_start) {
                if (!empty(trim($b_start))) {
                    $b_end = $_POST['b_end'][$index] ?? '';
                    $insB = $pdo->prepare("INSERT INTO breaks (shift_id, break_start, break_end) VALUES (?, ?, ?)");
                    $insB->execute([$shift_id, $b_start, !empty(trim($b_end)) ? $b_end : null]);
                }
            }
        }
        
        $pdo->commit();
        $msg = "Success: Shift, Site, and Rest Break entries updated cleanly.";
        $status = "success";
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "Error: " . $e->getMessage();
        $status = "danger";
    }
}

// 3. FETCH DATA
$stmt = $pdo->prepare("SELECT a.*, u.Name FROM attendance a JOIN users u ON a.user_id = u.id WHERE a.id = ?");
$stmt->execute([$shift_id]);
$shift = $stmt->fetch();

if (!$shift) { die("Shift Not Found"); }

// Fetch All Sites for the dropdown
$sites_stmt = $pdo->query("SELECT SId, nick_name FROM sites ORDER BY nick_name ASC");
$all_sites = $sites_stmt->fetchAll();

$b_stmt = $pdo->prepare("SELECT * FROM breaks WHERE shift_id = ? ORDER BY break_start ASC");
$b_stmt->execute([$shift_id]);
$breaks = $b_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Shift | <?= htmlspecialchars($shift['Name']) ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { background: #f1f5f9; padding-top: 130px; font-family: 'Inter', sans-serif; }
        .edit-card { max-width: 700px; margin: auto; background: white; padding: 35px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); border-top: 6px solid #0ea5e9; }
        h2 { font-weight: 800; color: #0f172a; margin-bottom: 25px; }
        h5 { font-weight: 700; font-size: 14px; text-transform: uppercase; color: #64748b; margin-top: 30px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; }
        label { font-weight: 700; color: #475569; font-size: 11px; text-transform: uppercase; margin-top: 15px; }
        .form-control { border-radius: 8px; padding: 12px; font-size: 14px; height: auto; border: 1px solid #cbd5e1; }
        .btn-save { background: #0f172a; border: none; font-weight: 800; padding: 16px; border-radius: 8px; margin-top: 30px; transition: 0.3s; color: white; }
        .btn-save:hover { background: #1e293b; color: white; }
        .back-link { display: block; text-align: center; margin-top: 20px; color: #94a3b8; font-size: 13px; text-decoration: none; }
        .break-row-container { display: flex; gap: 10px; align-items: center; background: #f8fafc; padding: 12px; border-radius: 8px; margin-bottom: 12px; border-left: 4px solid #f59e0b; }
    </style>
</head>
<body>

<?php echo generateMenu($pdo); ?>

<div class="container pb-5">
    <div class="edit-card">
        <h2>Edit Shift Details</h2>
        <p class="text-muted">Adjusting times and site for <strong><?= htmlspecialchars($shift['Name']) ?></strong></p>

        <?php if($msg): ?>
            <div class="alert alert-<?= $status ?> small font-weight-bold"><?= $msg ?></div>
        <?php endif; ?>

        <form method="POST">
            <h5>Location Assignment</h5>
            <div class="form-group">
                <label>Assigned Site</label>
                <select name="site_id" class="form-control" required>
                    <option value="999" <?= ($shift['site_id'] == 999) ? 'selected' : '' ?>>-- UNASSIGNED / MIGRATION --</option>
                    <?php foreach($all_sites as $site): ?>
                        <option value="<?= $site['SId'] ?>" <?= ($shift['site_id'] == $site['SId']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($site['nick_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <h5>Main Attendance</h5>
            <div class="row">
                <div class="col-md-6">
                    <label>Clock In</label>
                    <input type="datetime-local" id="c_in" name="c_in" class="form-control" value="<?= date('Y-m-d\TH:i', strtotime($shift['clock_in'])) ?>" required>
                </div>
                <div class="col-md-6">
                    <label>Clock Out</label>
                    <input type="datetime-local" name="c_out" class="form-control" value="<?= $shift['clock_out'] ? date('Y-m-d\TH:i', strtotime($shift['clock_out'])) : '' ?>">
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 30px;">
                <h5 style="margin: 0; border: none; padding: 0;">Break Logs Management</h5>
                <button type="button" class="btn btn-sm btn-success font-weight-bold" onclick="addNewBreakField()"><i class="fas fa-plus"></i> Add Break Row</button>
            </div>
            <div id="breaksMasterList" style="margin-top: 15px;">
                <?php foreach($breaks as $b): ?>
                    <div class="break-row-container">
                        <div style="flex:1;">
                            <label style="margin:0 0 4px 0;">Break Start</label>
                            <input type="datetime-local" name="b_start[]" class="form-control bg-white" value="<?= date('Y-m-d\TH:i', strtotime($b['break_start'])) ?>" required>
                        </div>
                        <div style="flex:1;">
                            <label style="margin:0 0 4px 0;">Break End</label>
                            <input type="datetime-local" name="b_end[]" class="form-control bg-white" value="<?= $b['break_end'] ? date('Y-m-d\TH:i', strtotime($b['break_end'])) : '' ?>">
                        </div>
                        <button type="button" class="btn btn-danger" style="margin-top:26px; padding:10px 14px;" onclick="this.parentNode.remove()"><i class="fas fa-trash-alt"></i></button>
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" name="update_full_shift" class="btn btn-block btn-save shadow">Update Shift Records</button>
            <a href="staff_audit.php" class="back-link">← Return to Staff Audit</a>
        </form>
    </div>
</div>

<script>
function addNewBreakField() {
    // 1. Get the Clock In input element
    const clockInInput = document.getElementById('c_in');
    let defaultDate = '';

    // 2. Extract just the date part (YYYY-MM-DD) if Clock In has a value
    if (clockInInput && clockInInput.value) {
        defaultDate = clockInInput.value.split('T')[0]; // Splits 'YYYY-MM-DDTHH:MM' and takes the date
    }

    // 3. Fallback to today's date if Clock In happens to be empty
    if (!defaultDate) {
        defaultDate = new Date().toISOString().split('T')[0];
    }

    // 4. Set default times (e.g., 12:00 for start, 12:30 for end) so inputs aren't half-empty
    const defaultStart = defaultDate + 'T12:00';
    const defaultEnd = defaultDate + 'T12:30';

    const container = document.getElementById('breaksMasterList');
    const row = document.createElement('div');
    row.className = 'break-row-container';
    row.innerHTML = `
        <div style="flex:1;">
            <label style="margin:0 0 4px 0;">Break Start</label>
            <input type="datetime-local" name="b_start[]" class="form-control bg-white" value="${defaultStart}" required>
        </div>
        <div style="flex:1;">
            <label style="margin:0 0 4px 0;">Break End</label>
            <input type="datetime-local" name="b_end[]" class="form-control bg-white" value="${defaultEnd}">
        </div>
        <button type="button" class="btn btn-danger" style="margin-top:26px; padding:10px 14px;" onclick="this.parentNode.remove()"><i class="fas fa-trash-alt"></i></button>
    `;
    container.appendChild(row);
}
</script>
</body>
</html>