<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll_scope.php';
require_once __DIR__ . '/payroll_engine/stat_holidays.php';
if (is_file(__DIR__ . '/engine/orgscope_guard.php')) {
    require_once __DIR__ . '/engine/orgscope_guard.php';
    osg_page(['level' => 10]);
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ps_require_payroll_admin();
$orgId = ps_selected_org($pdo);
$actor = (int)($_SESSION['user_id'] ?? 0);
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_require_csrf();
    if (isset($_POST['bulk_approve'])) {
        $n = sh_bulk_approve_unflagged($pdo, $orgId, $actor);
        $msg = $n . ' unflagged stat lines approved. Nothing was marked paid.';
    } elseif (isset($_POST['approve_line'])) {
        $lineId = (int)($_POST['line_id'] ?? 0);
        $amount = ($_POST['amount'] ?? '') === '' ? null : (float)$_POST['amount'];
        $hours = ($_POST['hours'] ?? '') === '' ? null : (float)$_POST['hours'];
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($reason === 'other') {
            $reason = trim((string)($_POST['reason_other'] ?? ''));
        }
        if (sh_approve_stat_line($pdo, $orgId, $lineId, $actor, $amount, $hours, $reason)) {
            $msg = 'Line approved. It can be paid on the next pay run. It is not paid yet.';
        } else {
            $err = 'Could not approve. If the amount differs from the calculated amount, pick a reason.';
        }
    }
}

$rows = [];
try {
    $st = $pdo->prepare(
        "SELECT f.id, f.user_id, f.work_date, f.reg_hours, f.total_gross, f.comments, f.status,
                COALESCE(f.stat_calculated_amount, f.total_gross) AS calculated_amount,
                COALESCE(f.stat_flag, '') AS stat_flag,
                u.Name AS employee, s.nick_name AS site_name
           FROM payroll_finalized f
           INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
           INNER JOIN sites s ON s.SId = f.site_id AND s.org_id = u.org_id
          WHERE f.category = 'Statutory Holiday' AND f.stat_approval_status = 'pending'
          ORDER BY (COALESCE(f.stat_flag, '') = '') ASC, f.work_date, u.Name"
    );
    $st->execute([$orgId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $err = 'Stat approval is not installed on this database yet.';
}
$reasons = ['quit', 'on vacation/leave - pay on return', 'missed shift without reasonable cause', 'other'];
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Stat holiday approval</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css"></head>
<body class="p-3">
<h1 class="h4">Stat holiday approval</h1>
<p class="text-muted">Every new stat line starts here. Approving it does not pay it. Flagged lines (no shift after the holiday, or a missed shift) need their own approval.</p>
<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<form method="post" onsubmit="return confirm('Approve every unflagged line at the amount shown? Flagged lines stay on this list.');">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ps_csrf_token()) ?>">
    <button class="btn btn-primary mb-3" name="bulk_approve" value="1">Approve all unflagged</button>
</form>
<table class="table table-sm">
<thead><tr><th>Holiday</th><th>Employee</th><th>Site</th><th>Flag</th><th>Calculated</th><th>Amount</th><th>Hours</th><th>Reason if changed</th><th></th></tr></thead>
<tbody>
<?php foreach ($rows as $r):
    $flag = trim((string)$r['stat_flag']);
    if ($flag === '' && preg_match('/FLAG:\s*([^.]+)/', (string)$r['comments'], $m)) {
        $flag = trim($m[1]);
    }
?>
<tr style="<?= $flag !== '' ? 'background:#fde68a' : '' ?>">
<td colspan="9" style="padding:0;">
<form method="post" class="form-inline" style="display:flex; gap:8px; align-items:center; padding:6px;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ps_csrf_token()) ?>">
    <input type="hidden" name="line_id" value="<?= (int)$r['id'] ?>">
    <span style="width:110px;"><?= htmlspecialchars($r['work_date']) ?></span>
    <span style="width:160px;"><?= htmlspecialchars($r['employee']) ?></span>
    <span style="width:120px;"><?= htmlspecialchars($r['site_name']) ?></span>
    <span style="width:220px; font-weight:bold;"><?= htmlspecialchars($flag) ?></span>
    <span style="width:90px;">$<?= number_format((float)$r['calculated_amount'], 2) ?></span>
    <input class="form-control form-control-sm" style="width:90px;" name="amount" value="<?= htmlspecialchars(number_format((float)$r['total_gross'], 2, '.', '')) ?>">
    <input class="form-control form-control-sm" style="width:70px;" name="hours" value="<?= htmlspecialchars(number_format((float)$r['reg_hours'], 2, '.', '')) ?>">
    <select class="form-control form-control-sm" style="width:240px;" name="reason">
        <option value="">Amount unchanged</option>
        <?php foreach ($reasons as $reason): ?><option><?= htmlspecialchars($reason) ?></option><?php endforeach; ?>
    </select>
    <input class="form-control form-control-sm" style="width:180px;" name="reason_other" placeholder="Reason if Other">
    <button class="btn btn-sm btn-success" name="approve_line" value="1">Approve</button>
</form>
</td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="9">No stat lines are waiting for approval.</td></tr><?php endif; ?>
</tbody></table>
</body></html>
