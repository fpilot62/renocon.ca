<?php
/**
 * Path: /no_break_request.php
 * Shown after kiosk clock-out when the shift qualifies for an Auto break
 * and the employee has not punched a break. Recording a request holds the
 * Auto break as pending. Dismissing leaves current Auto-break behaviour.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: kiosk.php?err=access_denied');
    exit;
}
require_once 'db.php';
if (is_file(__DIR__ . '/engine/auto_break_lib.php')) {
    require_once __DIR__ . '/engine/auto_break_lib.php';
}
require_once __DIR__ . '/engine/break_waiver_lib.php';

$shiftId = (int)($_GET['shift_id'] ?? $_POST['shift_id'] ?? 0);
$return = (string)($_POST['return'] ?? $_GET['return'] ?? 'kiosk.php');
if (!preg_match('#^[A-Za-z0-9_./?-]+=[^:\\s]*$#', $return) && !preg_match('#^[A-Za-z0-9_]+\.php(?:\?[A-Za-z0-9_&=%.+-]*)?$#', $return)) {
    $return = 'kiosk.php';
}

$shift = bwr_fetch_shift($pdo, $shiftId);
if ($shift === null || (int)($shift['user_id'] ?? 0) !== $userId) {
    header('Location: ' . $return);
    exit;
}
$breaks = bwr_fetch_breaks($pdo, $shiftId);
$existing = bwr_get_by_attendance($pdo, $shiftId);
$minutes = bwr_planned_minutes($shift);
if ($minutes < 1 && $existing) {
    $minutes = (int)$existing['minutes'];
}
$message = bwr_prompt_message($minutes);
$flash = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_no_break'])) {
    if (function_exists('ps_require_csrf')) {
        ps_require_csrf();
    }
    $res = bwr_create_pending($pdo, $shiftId, $userId);
    if (!empty($res['ok'])) {
        $done = true;
        $flash = !empty($res['already']) && ($res['status'] ?? '') !== 'pending'
            ? 'A decision is already on file for this shift.'
            : 'Your request was sent to a manager. The automatic break is held until they review it.';
    } else {
        $flash = (string)($res['error'] ?? 'The request could not be saved.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['skip_request'])) {
    header('Location: ' . $return);
    exit;
}

$csrf = function_exists('ps_csrf_token') ? ps_csrf_token() : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>No-break request</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #f8fafc; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { background: #fff; color: #0f172a; max-width: 420px; width: calc(100% - 32px); border-radius: 16px; padding: 28px 24px; box-shadow: 0 20px 40px rgba(0,0,0,.3); }
        h1 { font-size: 22px; margin: 0 0 12px; }
        p { font-size: 16px; line-height: 1.5; margin: 0 0 20px; }
        .ok { background: #ecfdf5; color: #065f46; padding: 10px 12px; border-radius: 8px; margin-bottom: 16px; }
        .err { background: #fef2f2; color: #991b1b; padding: 10px 12px; border-radius: 8px; margin-bottom: 16px; }
        button { width: 100%; border: none; border-radius: 10px; padding: 16px; font-size: 18px; font-weight: 800; cursor: pointer; margin-bottom: 10px; }
        .primary { background: #0f766e; color: #fff; }
        .secondary { background: #e2e8f0; color: #0f172a; }
        .hint { font-size: 13px; color: #475569; margin-top: 8px; }
    </style>
</head>
<body>
<div class="card">
    <h1>Break deduction</h1>
    <?php if ($done): ?>
        <div class="ok"><?= bwr_h($flash) ?></div>
        <a href="<?= bwr_h($return) ?>"><button type="button" class="secondary">Continue</button></a>
    <?php else: ?>
        <?php if ($flash !== ''): ?><div class="err"><?= bwr_h($flash) ?></div><?php endif; ?>
        <p><?= bwr_h($message) ?></p>
        <form method="post">
            <input type="hidden" name="shift_id" value="<?= (int)$shiftId ?>">
            <input type="hidden" name="return" value="<?= bwr_h($return) ?>">
            <?php if ($csrf !== ''): ?><input type="hidden" name="csrf_token" value="<?= bwr_h($csrf) ?>"><?php endif; ?>
            <button type="submit" name="request_no_break" value="1" class="primary">I took no break</button>
            <button type="submit" name="skip_request" value="1" class="secondary">Continue</button>
        </form>
        <p class="hint">If you continue without tapping “I took no break”, an automatic break may be deducted the way it is today. A manager will review a request before payroll is finalized.</p>
    <?php endif; ?>
</div>
</body>
</html>
