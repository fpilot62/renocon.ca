<?php
ob_start();
/** * Path: /finalized_archive.php 
 * FULL CODE - Multi-Tenant Strict + Assigned Sites Jail
 */
require_once 'db.php';
if (file_exists('engine/menu_generator.php')) { require_once 'engine/menu_generator.php'; }
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. SECURITY & ORG CHECK
if (!function_exists('ps_topbar_org') && is_file(__DIR__ . '/payroll_scope.php')) { require_once __DIR__ . '/payroll_scope.php'; }
$current_org_id = function_exists('ps_topbar_org') && ps_topbar_org() > 0 ? ps_topbar_org() : (int)($_SESSION['org_id'] ?? 0);
$user_lvl       = (int)($_SESSION['user_level'] ?? 99);
$is_restricted  = ($_SESSION['access_scope'] === 'restricted');
$assigned_sites = preg_replace('/[^0-9,]/', '', $_SESSION['assigned_site_ids'] ?? '');

if ($user_lvl > 30) { header("Location: kiosk.php?err=Unauthorized"); exit; }

// 2. Get Filter Inputs
$search_user_id = $_GET['user_id'] ?? '';
$search_site    = $_GET['site_id'] ?? '';
$search_status  = $_GET['status'] ?? 'all';
$start_date     = $_GET['start'] ?? date('Y-m-01');
$end_date       = $_GET['end'] ?? date('Y-m-d');

try {
    // 3. DATA FETCHING - Jailed to Org
    // Build Site Dropdown (Strict Org Filter)
    $site_sql = "SELECT SId, nick_name FROM sites WHERE org_id = $current_org_id";
    if ($is_restricted && !empty($assigned_sites)) {
        $site_sql .= " AND SId IN ($assigned_sites)";
    }
    $sites = $pdo->query($site_sql . " ORDER BY nick_name ASC")->fetchAll();

    // 4. Build Main Query
    // We use INNER JOIN on users and sites to ensure data belongs to the correct Org
    $query = "SELECT a.*, u.Name, s.nick_name 
              FROM attendance a 
              INNER JOIN users u ON a.user_id = u.id AND u.org_id = $current_org_id
              INNER JOIN sites s ON a.site_id = s.SId AND s.org_id = $current_org_id
              WHERE a.org_id = ?";

    $params = [$current_org_id];

    // Jailing for restricted managers
    if ($is_restricted && !empty($assigned_sites)) {
        $query .= " AND a.site_id IN ($assigned_sites)";
    }

    if ($search_user_id) { $query .= " AND a.user_id = ?"; $params[] = $search_user_id; }
    if ($search_site)    { $query .= " AND a.site_id = ?"; $params[] = $search_site; }

    if ($search_status === 'finalized') { $query .= " AND a.payout_status = 1"; }
    elseif ($search_status === 'pending') { $query .= " AND a.payout_status = 0"; }

    $query .= " AND a.clock_in BETWEEN ? AND ? ORDER BY a.clock_in DESC";
    $params[] = $start_date . " 00:00:00";
    $params[] = $end_date . " 23:59:59";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) { die("System Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Attendance Manager | crewPulse</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #f1f5f9; padding-top: 130px; font-family: 'Inter', sans-serif; }
        .demo-bar { background: #e67e22; color: white; text-align: center; padding: 10px; font-weight: bold; position: fixed; top: 0; width: 100%; z-index: 10002; }
        .filter-card, .table-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .status-badge { font-size: 10px; font-weight: 800; padding: 3px 8px; border-radius: 4px; text-transform: uppercase; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-finalized { background: #dcfce7; color: #166534; }
        .edit-icon { color: #94a3b8; cursor: pointer; transition: 0.2s; margin-left: 8px; }
        .edit-icon:hover { color: #3b82f6; }
    </style>
</head>
<body>
    <?php if (defined('IS_DEMO') && IS_DEMO): ?>
        <div class="demo-bar">🚀 DEMO MODE: Viewing Archive for Org #<?= $current_org_id ?></div>
    <?php endif; ?>

    <?= function_exists('generateMenu') ? generateMenu($pdo) : ''; ?>

    <div class="container-fluid px-5">
        <div class="filter-card">
            <form method="GET" class="form-row align-items-end">
                <div class="col-md-2">
                    <label class="small font-weight-bold text-muted">SITE</label>
                    <select name="site_id" id="site_filter" class="form-control form-control-sm" onchange="loadEmployees(this.value)">
                        <option value="">All Sites</option>
                        <?php foreach($sites as $s): ?>
                            <option value="<?= $s['SId'] ?>" <?= $search_site == $s['SId'] ? 'selected' : '' ?>><?= $s['nick_name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="small font-weight-bold text-muted">EMPLOYEE</label>
                    <select name="user_id" id="employee_filter" class="form-control form-control-sm">
                        <option value="">All Employees</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="small font-weight-bold text-muted">STATUS</label>
                    <select name="status" class="form-control form-control-sm">
                        <option value="all" <?= $search_status == 'all' ? 'selected' : '' ?>>All Records</option>
                        <option value="pending" <?= $search_status == 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="finalized" <?= $search_status == 'finalized' ? 'selected' : '' ?>>Finalized</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="d-flex">
                        <input type="date" name="start" value="<?= $start_date ?>" class="form-control form-control-sm mr-1">
                        <input type="date" name="end" value="<?= $end_date ?>" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-sm btn-primary btn-block font-weight-bold shadow-sm">SEARCH</button>
                </div>
            </form>
        </div>

        <div class="table-card">
            <table class="table table-hover table-sm">
                <thead class="thead-light">
                    <tr>
                        <th>Date</th>
                        <th>Employee</th>
                        <th>Site</th>
                        <th>Status</th>
                        <th>Shift Timing</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($results as $s): $is_final = ($s['payout_status'] == 1); ?>
                    <tr>
                        <td class="align-middle"><?= date('M d, Y', strtotime($s['clock_in'])) ?></td>
                        <td class="align-middle"><strong><?= htmlspecialchars($s['Name'] ?? 'Deleted User') ?></strong></td>
                        <td class="align-middle"><?= htmlspecialchars($s['nick_name'] ?? 'Unknown') ?></td>
                        <td class="align-middle">
                            <span class="status-badge <?= $is_final ? 'badge-finalized' : 'badge-pending' ?>">
                                <?= $is_final ? 'Finalized' : 'Pending' ?>
                            </span>
                        </td>
                        <td class="align-middle">
                            <?= date('H:i', strtotime($s['clock_in'])) ?> - <?= $s['clock_out'] && $s['clock_out'] != '0000-00-00 00:00:00' ? date('H:i', strtotime($s['clock_out'])) : '--:--' ?>
                            <?php if (!(defined('IS_DEMO') && IS_DEMO)): ?>
                                <i class="fa fa-pencil-alt edit-icon" onclick="openTimeModal(<?= $s['id'] ?>, '<?= $s['clock_in'] ?>', '<?= $s['clock_out'] ?>')"></i>
                            <?php endif; ?>
                        </td>
                        <td class="text-right align-middle">
                            <?php if (defined('IS_DEMO') && IS_DEMO): ?>
                                <button class="btn btn-xs btn-secondary" disabled>LOCKED</button>
                            <?php else: ?>
                                <?php if ($is_final): ?>
                                    <a href="unfinalize_action.php?id=<?= $s['id'] ?>" class="btn btn-xs btn-outline-danger" onclick="return confirm('Revert?')">REVERT</a>
                                <?php else: ?>
                                    <a href="finalize_shift.php?id=<?= $s['id'] ?>" class="btn btn-xs btn-success">FINALIZE</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($results)): ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted">No archive records found for this selection.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal fade" id="timeModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header"><h6 class="modal-title font-weight-bold">Update Shift Time</h6></div>
                <div class="modal-body">
                    <input type="hidden" id="edit_id">
                    <div class="form-group">
                        <label class="small font-weight-bold">CLOCK IN</label>
                        <input type="datetime-local" id="edit_in" class="form-control form-control-sm">
                    </div>
                    <div class="form-group">
                        <label class="small font-weight-bold">CLOCK OUT</label>
                        <input type="datetime-local" id="edit_out" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-sm" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="saveNewTimes()">Save</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>

<script>
function loadEmployees(siteId) {
    const empSelect = document.getElementById('employee_filter');
    empSelect.innerHTML = '<option value="">Loading...</option>';
    fetch(`engine/get_site_employees.php?site_id=${siteId}`)
        .then(res => res.json())
        .then(data => {
            empSelect.innerHTML = '<option value="">All Employees</option>';
            data.forEach(emp => {
                const opt = document.createElement('option');
                opt.value = emp.id;
                opt.textContent = emp.Name;
                if(emp.id == "<?= $search_user_id ?>") opt.selected = true;
                empSelect.appendChild(opt);
            });
        });
}

function openTimeModal(id, cin, cout) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_in').value = cin.replace(' ', 'T').substring(0, 16);
    document.getElementById('edit_out').value = (cout && cout !== '0000-00-00 00:00:00') ? cout.replace(' ', 'T').substring(0, 16) : '';
    $('#timeModal').modal('show');
}

function saveNewTimes() {
    const payload = {
        id: document.getElementById('edit_id').value,
        clock_in: document.getElementById('edit_in').value,
        clock_out: document.getElementById('edit_out').value
    };
    $.post('engine/update_shift_time.php', payload, function() { location.reload(); });
}

window.onload = () => loadEmployees(document.getElementById('site_filter').value);
</script>
</body>
</html>
<?php ob_end_flush(); ?>