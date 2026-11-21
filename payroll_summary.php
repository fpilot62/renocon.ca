<?php
/** Path: /payroll_summary.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'engine/menu_generator.php'; 

// 1. SECURITY & ORG CHECK
if (!function_exists('ps_topbar_org') && is_file(__DIR__ . '/payroll_scope.php')) { require_once __DIR__ . '/payroll_scope.php'; }
$current_org_id = function_exists('ps_topbar_org') && ps_topbar_org() > 0 ? ps_topbar_org() : (int)($_SESSION['org_id'] ?? 0);
$user_lvl       = (int)($_SESSION['user_level'] ?? 99);

if (!isset($_SESSION['user_id']) || $user_lvl > 30) { 
    header("Location: kiosk.php?err=Unauthorized"); 
    exit; 
}

$is_super = ($user_lvl <= 10);

// 2. DATE RANGE LOGIC
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date   = $_GET['end_date'] ?? date('Y-m-d');

$grouped_by_date = [];

try {
    $params = [];
    $where = ["t.work_date BETWEEN ? AND ?"];
    $params[] = $start_date;
    $params[] = $end_date;

    if (!$is_super || $current_org_id > 0) {
        $where[] = "s.org_id = ?"; 
        $params[] = $current_org_id;
    }

    // 3. FETCH DATA (Including attendance_id and user_id)
    $sql = "SELECT t.*, u.Name as staff_name, u.id as employee_uid, s.nick_name as site_name, s.street_address as site_addr 
            FROM payroll_finalized t 
            INNER JOIN users u ON t.user_id = u.id 
            INNER JOIN sites s ON t.site_id = s.SId 
            WHERE " . implode(" AND ", $where) . "
            ORDER BY t.work_date DESC, u.Name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $date_key = $row['work_date'];
        $grouped_by_date[$date_key][] = $row;
    }
} catch (PDOException $e) { 
    die("Database Error: " . $e->getMessage()); 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SIP Global | Daily Payroll Summary</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f1f5f9; color: #1e293b; font-family: 'Inter', sans-serif; margin: 0; padding-top: 130px; }
        .dashboard-container { max-width: 1100px; margin: 0 auto; padding: 20px; }
        .filter-bar { background: white; padding: 20px; border-radius: 15px; margin-bottom: 25px; border: 1px solid #e2e8f0; }
        .date-header { background: #1e293b; color: white; padding: 12px 20px; border-radius: 10px; margin-bottom: 15px; font-weight: 700; display: flex; justify-content: space-between; align-items: center; }
        .staff-card { background: white; border: 1px solid #e2e8f0; padding: 15px 25px; margin-bottom: 10px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; transition: 0.2s; }
        .staff-card:hover { border-color: #2563eb; transform: translateX(5px); }
        .id-badge { font-size: 10px; background: #f1f5f9; color: #64748b; padding: 2px 6px; border-radius: 4px; margin-right: 5px; border: 1px solid #e2e8f0; }
        .amt-net { font-weight: 800; color: #10b981; font-size: 1.1rem; }
        .print-template { display: none; }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>

<?= generateMenu($pdo); ?>
<div class="dashboard-container">
    <div class="mb-3 d-flex justify-content-between align-items-start no-print">
        <div>
            <h4 class="font-weight-bold text-dark mb-0">Daily Payroll Summary</h4>
            <p class="text-muted small mb-0">This is a <strong>daily hours &amp; pay preview</strong> by employee/site for the date range — review labour before cheques. QB Online CSV / QB Desktop IIF export those rows. Not CRA remittance and not Staff Audit finalize.</p>
        </div>
        <div class="ml-3 text-nowrap">
            <a href="export_quickbooks.php?start=<?= $start_date ?>&end=<?= $end_date ?>" class="btn btn-success mr-2">
                <i class="fa fa-cloud-download-alt"></i> QB ONLINE (CSV)
            </a>
            <a href="export_quickbooks_desktop.php?start=<?= $start_date ?>&end=<?= $end_date ?>" class="btn btn-outline-success">
                <i class="fa fa-desktop"></i> QB DESKTOP (IIF)
            </a>
        </div>
    </div>
    <div class="filter-bar shadow-sm">
        <form method="GET" class="form-inline justify-content-center">
            <label class="mr-2 small font-weight-bold">FROM:</label>
            <input type="date" name="start_date" class="form-control mr-3" value="<?= $start_date ?>">
            <label class="mr-2 small font-weight-bold">TO:</label>
            <input type="date" name="end_date" class="form-control mr-3" value="<?= $end_date ?>">
            <button type="submit" class="btn btn-primary font-weight-bold px-4">Update View</button>
        </form>
    </div>

    <?php if (empty($grouped_by_date)): ?>
        <div class="bg-white p-5 rounded text-center border shadow-sm">
            <h4 class="text-muted">No finalized records found for this range.</h4>
        </div>
    <?php else: ?>
        <?php foreach ($grouped_by_date as $date => $entries): ?>
            <div class="date-section mb-5">
                <div class="date-header shadow-sm">
                    <span><i class="fa fa-calendar-check mr-2"></i> <?= date('l, F j, Y', strtotime($date)) ?></span>
                </div>

                <?php foreach ($entries as $r): 
                    $hrs = (float)$r['reg_hours'];
                    if ($hrs <= 0) continue; 

                    // On-the-fly math fix
                    $gross = ((float)$r['total_gross'] <= 0) ? ($hrs * (float)($r['hourly_rate'] ?? 0)) : (float)$r['total_gross'];
                    $deductions = (float)($r['cpp'] ?? 0) + (float)($r['ei'] ?? 0) + (float)($r['tax'] ?? 0);
                    $net = $gross - $deductions;
                    $id = "stub_" . $r['id'];
                ?>
                    <div class="staff-card shadow-sm">
                        <div style="flex: 2;">
                            <div class="d-flex align-items-center mb-1">
                                <span class="id-badge" title="Employee User ID">UID: <?= $r['employee_uid'] ?></span>
                                <span class="id-badge" title="Attendance Log ID">AID: <?= $r['attendance_id'] ?></span>
                                <p style="margin:0; font-weight:800; font-size:16px;"><?= htmlspecialchars($r['staff_name']) ?></p>
                            </div>
                            <small class="text-muted font-weight-bold text-uppercase"><?= htmlspecialchars($r['site_name']) ?></small>
                        </div>
                        
                        <div style="flex: 1; text-align:center;">
                            <span class="badge badge-light border px-3 py-2 font-weight-bold"><?= number_format($hrs, 2) ?> Hrs</span>
                        </div>

                        <div style="flex: 2; text-align:right;">
                            <span class="amt-net mr-3">$<?= number_format($net, 2) ?></span>
                            
                            <button class="btn btn-sm btn-outline-danger mr-2" onclick="revertToAudit(<?= $r['id'] ?>)" title="Move back to Audit">
                                <i class="fa fa-undo"></i>
                            </button>
                            
                            <button class="btn btn-sm btn-dark" onclick="printStub('<?= $id ?>')">
                                <i class="fa fa-print"></i>
                            </button>
                        </div>
                    </div>

                    <div id="<?= $id ?>" class="print-template">
                        <div style="border: 2px solid #000; padding: 40px; border-radius: 10px; font-family: sans-serif;">
                            <div class="d-flex justify-content-between mb-4">
                                <div>
                                    <h3 style="margin:0; font-weight:bold;"><?= htmlspecialchars($r['site_name']) ?></h3>
                                    <p style="font-size:11px; margin:0; color:#666;">UID: <?= $r['employee_uid'] ?> | AID: <?= $r['attendance_id'] ?></p>
                                </div>
                                <div style="text-align:right;">
                                    <h4 style="margin:0; color:#2563eb; font-weight:bold;">PAY VOUCHER</h4>
                                    <p style="font-size:13px; margin:0;">Work Date: <?= $date ?></p>
                                </div>
                            </div>
                            <hr>
                            <table class="table table-bordered table-sm">
                                <tr><th width="40%">Employee Name</th><td><?= htmlspecialchars($r['staff_name']) ?></td></tr>
                                <tr><th>Hours Worked</th><td><?= number_format($hrs, 2) ?></td></tr>
                                <tr><th>Gross Pay</th><td>$<?= number_format($gross, 2) ?></td></tr>
                                <tr class="text-danger"><th>Deductions</th><td>-$<?= number_format($deductions, 2) ?></td></tr>
                                <tr class="table-primary"><th><strong class="h5">NET PAYABLE</strong></th><td><strong class="h5">$<?= number_format($net, 2) ?></strong></td></tr>
                            </table>
                            <p style="font-size:10px; text-align:center; color:#999; margin-top:30px;">This is a computer-generated statement verified by SIP Global Payroll.</p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function revertToAudit(payrollId) {
    if (!confirm("This will delete this payroll record and unlock the original attendance entry. Proceed?")) return;
    const params = new URLSearchParams();
    params.append('payroll_id', payrollId);
    fetch('revert_payroll.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    }).then(res => res.json()).then(data => {
        if (data.status === 'success') { 
            alert("Shift reverted successfully. It is now back in Staff Audit.");
            location.reload(); 
        }
        else { alert("Error: " + data.message); }
    }).catch(err => alert("Error connecting to revert_payroll.php"));
}

function printStub(id) {
    const content = document.getElementById(id).innerHTML;
    const pWin = window.open('', '_blank', 'width=850,height=800');
    pWin.document.write('<html><head><title>Payroll Voucher</title>');
    pWin.document.write('<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">');
    pWin.document.write('</head><body style="padding:40px;">' + content + '</body></html>');
    pWin.document.close();
    setTimeout(() => { pWin.focus(); pWin.print(); }, 500);
}
</script>
</body>
</html>