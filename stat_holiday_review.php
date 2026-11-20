<?php
/**
 * Ontario public-holiday eligibility review. Org-scoped.
 * A pending row is not a pay line and cannot be paid until someone decides.
 */
$guard = __DIR__ . '/engine/orgscope_guard.php';
if (is_file($guard)) {
    require_once $guard;
    if (function_exists('osg_page')) {
        osg_page(['level' => 10]);
    }
}
require_once 'db.php';
require_once 'payroll_scope.php';
require_once __DIR__ . '/payroll_engine/stat_holidays.php';
ps_require_payroll_admin();
$orgId = ps_selected_org($pdo);
sh_ensure_holiday_review($pdo);
$from = (string)($_GET['from_date'] ?? $_POST['from_date'] ?? '');
$to = (string)($_GET['to_date'] ?? $_POST['to_date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = ''; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $to = ''; }
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_id'], $_POST['decision'])) {
    ps_require_csrf();
    $ok = sh_apply_review_decision(
        $pdo,
        $orgId,
        (int)$_POST['review_id'],
        (string)$_POST['decision'],
        (int)($_SESSION['user_id'] ?? 0),
        sh_date_only($_POST['end_date'] ?? '')
    );
    $message = $ok ? 'Decision saved.' : 'That decision was not saved.';
}
$sql = "SELECT r.*, u.Name AS employee, s.nick_name AS site
          FROM stat_holiday_review r
          JOIN users u ON u.id=r.user_id AND u.org_id=r.org_id
          LEFT JOIN sites s ON s.SId=r.site_id AND s.org_id=r.org_id
         WHERE r.org_id=?";
$params = [$orgId];
if ($from !== '' && $to !== '') {
    $sql .= ' AND r.holiday_date BETWEEN ? AND ?';
    $params[] = $from;
    $params[] = $to;
}
$sql .= " ORDER BY r.holiday_date, s.nick_name, u.Name";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$pending = 0;
foreach ($rows as $row) {
    if (in_array($row['status'], ['pending', 'leave_wait'], true)) {
        $pending++;
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Holiday eligibility review</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<style>body{background:#f1f5f9;padding:24px;font-family:Inter,Arial}.wrap{max-width:1200px;margin:auto}</style>
</head><body><div class="wrap">
<h2>Holiday eligibility review</h2>
<p class="text-muted">Ontario last-and-first questions. A pending row is not on the pay ledger and cannot be paid until you decide. Quit asks for an end date so later holidays are excluded.</p>
<?php if ($message): ?><div class="alert alert-info"><?=htmlspecialchars($message)?></div><?php endif; ?>
<p><strong><?=$pending?> pending</strong> in this list. <a href="payroll_master_ledger.php?org_id=<?=$orgId?>">Back to the pay ledger</a></p>
<table class="table table-sm table-bordered bg-white"><thead class="thead-dark"><tr>
<th>Holiday</th><th>Site</th><th>Employee</th><th>Amount</th><th>Why</th><th>Status</th><th>Decide</th>
</tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7" class="text-muted p-3">No reviews for this organization.</td></tr><?php endif; ?>
<?php foreach ($rows as $row):
    $flags = explode(',', (string)$row['flags']);
    $open = in_array($row['status'], ['pending', 'leave_wait'], true);
?>
<tr>
<td><?=htmlspecialchars($row['holiday_date'].' '.$row['holiday_name'])?></td>
<td><?=htmlspecialchars((string)$row['site'])?></td>
<td><?=htmlspecialchars((string)$row['employee'])?></td>
<td>$<?=number_format((float)$row['amount'], 2)?></td>
<td><?=htmlspecialchars(str_replace('_', ' ', (string)$row['flags']))?></td>
<td><?=htmlspecialchars((string)$row['status'])?><?php if ($row['decided_at']): ?><div class="small text-muted"><?=htmlspecialchars((string)$row['decided_by'])?> · <?=htmlspecialchars((string)$row['decided_at'])?></div><?php endif; ?></td>
<td><?php if ($open): ?>
<form method="post" class="m-0">
<input type="hidden" name="csrf_token" value="<?=htmlspecialchars(ps_csrf_token())?>">
<input type="hidden" name="review_id" value="<?=(int)$row['id']?>">
<input type="hidden" name="from_date" value="<?=htmlspecialchars($from)?>">
<input type="hidden" name="to_date" value="<?=htmlspecialchars($to)?>">
<?php if (in_array('no_shift_after', $flags, true)): ?>
<select name="decision" class="form-control form-control-sm mb-1">
<option value="quit">Quit — no stat</option>
<option value="leave_wait">On vacation/leave — pay when the first shift back exists</option>
<option value="leave_pay">On vacation/leave — pay now</option>
<option value="employed">Still employed — pay</option>
</select>
<label class="small mb-1">End date if they quit <input type="date" name="end_date" class="form-control form-control-sm"></label>
<?php else: ?>
<select name="decision" class="form-control form-control-sm mb-1">
<option value="reasonable">Reasonable cause — pay</option>
<option value="no_cause">No reasonable cause — no stat</option>
</select>
<?php endif; ?>
<button class="btn btn-sm btn-primary">Save</button>
</form>
<?php else: ?><?=htmlspecialchars((string)$row['decision'])?><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
</div></body></html>
