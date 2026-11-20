<?php
/**
 * Path: /direct_deposit_pending.php
 * Manager queue: Direct deposit approvals — review extract, edit, Approve / Reject.
 * Approve copies verified values into live bank_* fields.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/employee_selfserve_lib.php';
require_once __DIR__ . '/engine/menu_generator.php';

ess_ensure_dd_columns($pdo);

$user_level = (int)($_SESSION['user_level'] ?? 99);
$session_org = (int)($_SESSION['org_id'] ?? 0);
$reviewer_id = (int)($_SESSION['user_id'] ?? 0);
if ($reviewer_id <= 0 || $user_level > 30) {
    header('Location: kiosk.php?err=Unauthorized');
    exit;
}

$org_scope = ($user_level <= 10) ? null : $session_org;
$msg = '';
$error = '';
$focus_id = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tid = (int)($_POST['user_id'] ?? 0);
    $action = (string)($_POST['dd_action'] ?? '');
    if ($tid > 0 && $action === 'approve') {
        $res = ess_dd_manager_approve($pdo, $tid, $reviewer_id, [
            'transit' => $_POST['transit'] ?? '',
            'institution' => $_POST['institution'] ?? '',
            'account' => $_POST['account'] ?? '',
            'name' => $_POST['account_name'] ?? '',
        ], $org_scope);
        if (!empty($res['ok'])) {
            $msg = 'Approved — live bank fields updated.';
            $focus_id = 0;
        } else {
            $error = $res['error'] ?? 'Approve failed.';
            $focus_id = $tid;
        }
    } elseif ($tid > 0 && $action === 'reject') {
        $res = ess_dd_manager_reject(
            $pdo,
            $tid,
            $reviewer_id,
            (string)($_POST['reject_reason'] ?? ''),
            $org_scope
        );
        if (!empty($res['ok'])) {
            $msg = 'Rejected — live bank fields unchanged.';
            $focus_id = 0;
        } else {
            $error = $res['error'] ?? 'Reject failed.';
            $focus_id = $tid;
        }
    } elseif ($tid > 0 && $action === 'save_pending') {
        // Save edits without approving
        $transit = preg_replace('/\D+/', '', (string)($_POST['transit'] ?? ''));
        $institution = preg_replace('/\D+/', '', (string)($_POST['institution'] ?? ''));
        $account = preg_replace('/\D+/', '', (string)($_POST['account'] ?? ''));
        $aname = trim((string)($_POST['account_name'] ?? ''));
        if (strlen($transit) > 5) {
            $transit = substr($transit, -5);
        }
        if (strlen($institution) > 3) {
            $institution = substr($institution, -3);
        }
        if (strlen($aname) > 120) {
            $aname = substr($aname, 0, 120);
        }
        $sql = "UPDATE users SET dd_pending_transit=?, dd_pending_institution=?, dd_pending_account=?, dd_pending_name=?
                WHERE id=? AND direct_deposit_upload_pending=1";
        $params = [
            $transit !== '' ? $transit : null,
            $institution !== '' ? $institution : null,
            $account !== '' ? $account : null,
            $aname !== '' ? $aname : null,
            $tid,
        ];
        if ($org_scope !== null) {
            $sql .= " AND org_id=?";
            $params[] = $org_scope;
        }
        $pdo->prepare($sql)->execute($params);
        $msg = 'Pending values saved (not applied to payroll yet).';
        $focus_id = $tid;
    }
}

$select = "SELECT u.id, u.Name, u.email, u.org_id, o.org_name,
                  u.bank_transit, u.bank_institution, u.bank_account,
                  u.direct_deposit_file, u.direct_deposit_orig_name, u.direct_deposit_uploaded_at,
                  u.direct_deposit_upload_pending, u.dd_verify_status,
                  u.dd_pending_transit, u.dd_pending_institution, u.dd_pending_account, u.dd_pending_name,
                  u.dd_extract_method, u.dd_extract_notes, u.dd_reviewed_at
           FROM users u
           LEFT JOIN organizations o ON o.id = u.org_id";

if ($user_level <= 10) {
    $rows = $pdo->query(
        $select . "
         WHERE u.direct_deposit_file IS NOT NULL AND u.direct_deposit_file <> ''
         ORDER BY u.direct_deposit_upload_pending DESC,
                  FIELD(COALESCE(u.dd_verify_status,''),'pending_manager_verification','','approved','rejected'),
                  u.direct_deposit_uploaded_at DESC
         LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare(
        $select . "
         WHERE u.org_id = ?
           AND u.direct_deposit_file IS NOT NULL AND u.direct_deposit_file <> ''
         ORDER BY u.direct_deposit_upload_pending DESC,
                  FIELD(COALESCE(u.dd_verify_status,''),'pending_manager_verification','','approved','rejected'),
                  u.direct_deposit_uploaded_at DESC
         LIMIT 200"
    );
    $stmt->execute([$session_org]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pending_n = 0;
foreach ($rows as $r) {
    if (!empty($r['direct_deposit_upload_pending'])
        || ($r['dd_verify_status'] ?? '') === 'pending_manager_verification') {
        $pending_n++;
    }
}

$focus = null;
if ($focus_id > 0) {
    foreach ($rows as $r) {
        if ((int)$r['id'] === $focus_id) {
            $focus = $r;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Direct deposit approvals</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');
        body { font-family: 'Inter', system-ui, sans-serif; background: #f1f5f9; margin: 0; color: #0f172a; padding-top: 90px; }
        .wrap { max-width: 1100px; margin: 0 auto 40px; padding: 0 16px; }
        h1 { font-size: 1.4rem; font-weight: 800; margin: 0 0 6px; }
        .sub { color: #64748b; font-size: 13px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
        th, td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 13px; vertical-align: top; }
        th { background: #f8fafc; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 800; }
        .badge-pending { background: #fef3c7; color: #b45309; }
        .badge-ok { background: #ecfdf5; color: #047857; }
        .badge-rej { background: #fef2f2; color: #b91c1c; }
        .pay-btn { display: inline-block; padding: 7px 12px; background: #0891b2; color: #fff; border: none; border-radius: 8px; font-weight: 800; font-size: 12px; cursor: pointer; text-decoration: none; }
        .pay-btn-outline { background: #fff; color: #0891b2; border: 1px solid #0891b2; }
        .pay-btn-danger { background: #fff; color: #dc2626; border: 1px solid #fecaca; }
        .pay-btn-success { background: #059669; color: #fff; }
        .alert-ok { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; padding: 10px 12px; border-radius: 8px; margin-bottom: 14px; font-size: 13px; }
        .alert-err { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 10px 12px; border-radius: 8px; margin-bottom: 14px; font-size: 13px; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; margin: 18px 0; }
        .card h2 { margin: 0 0 12px; font-size: 15px; font-weight: 800; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-group { display: flex; flex-direction: column; gap: 4px; }
        .form-group.full { grid-column: 1 / -1; }
        label { font-size: 12px; font-weight: 700; color: #475569; }
        input, textarea { padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 15px; font-family: inherit; }
        .muted { color: #94a3b8; font-size: 12px; }
        .mono { font-family: ui-monospace, monospace; font-size: 13px; }
        .compare { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
        .box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; font-size: 13px; }
        @media (max-width: 700px) { .grid, .compare { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<?= generateMenu($pdo) ?>
<div class="wrap">
    <h1>Direct deposit approvals</h1>
    <p class="sub">
        Employees upload a void cheque or direct-deposit form from My Profile.
        Review the extract, edit if needed, then Approve to apply to payroll.
        <?= (int)$pending_n ?> awaiting verification · auto-extract is best-effort.
    </p>
    <?php if ($msg !== ''): ?><div class="alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert-err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($focus): ?>
    <?php
        $is_pending = !empty($focus['direct_deposit_upload_pending'])
            || ($focus['dd_verify_status'] ?? '') === 'pending_manager_verification';
    ?>
    <div class="card" id="verify">
        <h2>Verify — <?= htmlspecialchars($focus['Name'] ?? '') ?></h2>
        <p class="muted">
            Uploaded <?= !empty($focus['direct_deposit_uploaded_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime($focus['direct_deposit_uploaded_at']))) : '—' ?>
            · <?= htmlspecialchars($focus['direct_deposit_orig_name'] ?? '') ?>
            · extract: <?= htmlspecialchars($focus['dd_extract_method'] ?? 'none') ?>
            <?php if (!empty($focus['dd_extract_notes'])): ?> · <?= htmlspecialchars($focus['dd_extract_notes']) ?><?php endif; ?>
            · <a href="direct_deposit_download.php?user_id=<?= (int)$focus['id'] ?>">Download form</a>
        </p>

        <div class="compare">
            <div class="box">
                <strong>Live payroll (current)</strong><br>
                T <?= htmlspecialchars($focus['bank_transit'] ?: '—') ?>
                · I <?= htmlspecialchars($focus['bank_institution'] ?: '—') ?>
                · A <?= htmlspecialchars(ess_mask_bank_account($focus['bank_account'] ?? '')) ?>
            </div>
            <div class="box">
                <strong>Extracted / pending</strong><br>
                T <?= htmlspecialchars($focus['dd_pending_transit'] ?: '—') ?>
                · I <?= htmlspecialchars($focus['dd_pending_institution'] ?: '—') ?>
                · A <?= htmlspecialchars($focus['dd_pending_account'] ?: '—') ?>
                <?php if (!empty($focus['dd_pending_name'])): ?><br>Name: <?= htmlspecialchars($focus['dd_pending_name']) ?><?php endif; ?>
            </div>
        </div>

        <?php if ($is_pending): ?>
        <form method="POST">
            <input type="hidden" name="user_id" value="<?= (int)$focus['id'] ?>">
            <div class="grid">
                <div class="form-group">
                    <label>Transit (5 digits)</label>
                    <input class="mono" name="transit" required maxlength="5" pattern="\d{4,5}"
                           value="<?= htmlspecialchars($focus['dd_pending_transit'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Institution (3 digits)</label>
                    <input class="mono" name="institution" required maxlength="3" pattern="\d{2,3}"
                           value="<?= htmlspecialchars($focus['dd_pending_institution'] ?? '') ?>">
                </div>
                <div class="form-group full">
                    <label>Account number</label>
                    <input class="mono" name="account" required maxlength="18" pattern="\d{5,18}"
                           value="<?= htmlspecialchars($focus['dd_pending_account'] ?? '') ?>">
                </div>
                <div class="form-group full">
                    <label>Name on account (optional)</label>
                    <input name="account_name" maxlength="120"
                           value="<?= htmlspecialchars($focus['dd_pending_name'] ?? '') ?>">
                </div>
                <div class="form-group full">
                    <label>Reject reason (if rejecting)</label>
                    <textarea name="reject_reason" rows="2" maxlength="200" placeholder="e.g. Unreadable form — please re-upload"></textarea>
                </div>
            </div>
            <div class="actions" style="margin-top:14px">
                <button type="submit" name="dd_action" value="approve" class="pay-btn pay-btn-success">Approve &amp; apply to payroll</button>
                <button type="submit" name="dd_action" value="save_pending" class="pay-btn pay-btn-outline">Save edits only</button>
                <button type="submit" name="dd_action" value="reject" class="pay-btn pay-btn-danger"
                        onclick="return confirm('Reject this upload? Live bank fields will not change.');">Reject</button>
                <a class="pay-btn pay-btn-outline" href="direct_deposit_pending.php">Close</a>
            </div>
        </form>
        <?php else: ?>
            <p class="muted">Status: <?= htmlspecialchars(ess_dd_status_label($focus['dd_verify_status'] ?? '', $focus['direct_deposit_upload_pending'] ?? 0)) ?>
                <?php if (!empty($focus['dd_reviewed_at'])): ?>
                    · reviewed <?= htmlspecialchars(date('Y-m-d H:i', strtotime($focus['dd_reviewed_at']))) ?>
                <?php endif; ?>
            </p>
            <a class="pay-btn pay-btn-outline" href="direct_deposit_pending.php">Back to list</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <?php if ($user_level <= 10): ?><th>Org</th><?php endif; ?>
                <th>Submitted</th>
                <th>Extracted</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="<?= $user_level <= 10 ? 6 : 5 ?>" style="color:#64748b; text-align:center; padding:28px;">No bank forms submitted yet.</td></tr>
        <?php else: foreach ($rows as $r):
            $st = ess_dd_status_label($r['dd_verify_status'] ?? '', $r['direct_deposit_upload_pending'] ?? 0);
            $pending = !empty($r['direct_deposit_upload_pending'])
                || ($r['dd_verify_status'] ?? '') === 'pending_manager_verification';
            $badge = $pending ? 'badge-pending' : (($r['dd_verify_status'] ?? '') === 'rejected' ? 'badge-rej' : 'badge-ok');
        ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($r['Name'] ?? '') ?></strong><br>
                    <span style="color:#64748b;font-size:12px;"><?= htmlspecialchars($r['email'] ?? '') ?></span>
                </td>
                <?php if ($user_level <= 10): ?>
                    <td><?= htmlspecialchars($r['org_name'] ?? '') ?> (#<?= (int)$r['org_id'] ?>)</td>
                <?php endif; ?>
                <td><?= !empty($r['direct_deposit_uploaded_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime($r['direct_deposit_uploaded_at']))) : '—' ?></td>
                <td class="mono">
                    <?= htmlspecialchars(trim(($r['dd_pending_transit'] ?: '—') . ' / ' . ($r['dd_pending_institution'] ?: '—') . ' / ' . ($r['dd_pending_account'] ? ('***' . substr($r['dd_pending_account'], -4)) : '—'))) ?>
                    <div class="muted"><?= htmlspecialchars($r['dd_extract_method'] ?? '') ?></div>
                </td>
                <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($st) ?></span></td>
                <td>
                    <div class="actions">
                        <a class="pay-btn" href="direct_deposit_pending.php?user_id=<?= (int)$r['id'] ?>#verify"><?= $pending ? 'Verify' : 'View' ?></a>
                        <a class="pay-btn pay-btn-outline" href="direct_deposit_download.php?user_id=<?= (int)$r['id'] ?>">File</a>
                    </div>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <p style="margin-top:16px"><a class="pay-btn pay-btn-outline" href="employee_hub.php">← Back to staff list</a></p>
</div>
</body>
</html>
