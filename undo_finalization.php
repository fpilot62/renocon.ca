<?php
/**
 * Path: /undo_finalization.php
 * Sends unpaid finalized shifts back to audit for the posted attendance ids.
 * Opening this page does not back up or reset the database.
 * Requires a logged-in admin. A recorded payment is left finalized.
 */
require_once __DIR__ . '/engine/attendance_integrity_lib.php';
payroll_require_admin(false, 'Undo finalization is for admins only.');
require_once __DIR__ . '/db.php';
payroll_require_scope_admin();

$level = (int)($_SESSION['user_level'] ?? 99);
$sessionOrg = (int)($_SESSION['org_id'] ?? 0);
$selected = (int)($_POST['org_id'] ?? $_GET['org_id'] ?? 0);
if ($level > 10) {
    if ($selected > 0 && $selected !== $sessionOrg) {
        http_response_code(403);
        exit('That organization is outside your access.');
    }
    $selected = $sessionOrg;
}

$notice = '';
$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $ids = att_posted_ids($_POST);
    if (!$ids) {
        $errors[] = 'No attendance ids were provided.';
    } elseif ((string)($_POST['confirm'] ?? '') !== 'undo') {
        $errors[] = 'Confirm the undo before it runs.';
    } elseif (!att_has_deleted_at($pdo)) {
        $errors[] = 'Run migrations/20261110_attendance_soft_delete.sql before undoing finalization.';
    } else {
        $result = att_undo_finalized_shifts($pdo, $ids, $level, $sessionOrg, $selected);
        $notice = (int)$result['undone'] . ' shift(s) sent back to audit.';
        $errors = $result['skipped'];
    }
}

function undo_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Undo finalization</title>
    <style>
        body { font-family: sans-serif; background: #f8fafc; color: #0f172a; margin: 40px auto; max-width: 640px; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; }
        label { display: block; font-size: 12px; font-weight: 700; margin: 14px 0 6px; }
        textarea, input[type=number] { width: 100%; box-sizing: border-box; padding: 8px; }
        button { margin-top: 16px; background: #0f172a; color: #fff; border: 0; border-radius: 6px; padding: 10px 16px; font-weight: 700; }
        .ok { background: #dcfce7; padding: 10px 12px; border-radius: 8px; }
        .err { background: #fff7ed; padding: 10px 12px; border-radius: 8px; margin-top: 8px; }
    </style>
</head>
<body>
<div class="card">
    <h1>Undo finalization</h1>
    <p>Opening this page does not change payroll and does not start a backup. It sends the listed unpaid shifts back to audit. A shift that already has a payment is left finalized.</p>
    <?php if ($notice !== ''): ?><p class="ok"><?= undo_h($notice) ?></p><?php endif; ?>
    <?php foreach ($errors as $err): ?><p class="err"><?= undo_h((string)$err) ?></p><?php endforeach; ?>
    <form method="POST">
        <?php if ($selected > 0): ?><input type="hidden" name="org_id" value="<?= (int)$selected ?>"><?php endif; ?>
        <label for="ids">Attendance ids</label>
        <textarea id="ids" name="ids" rows="4" placeholder="123, 456"></textarea>
        <label><input type="checkbox" name="confirm" value="undo"> Send these unpaid shifts back to audit</label>
        <button type="submit">Undo finalization</button>
    </form>
</div>
</body>
</html>
