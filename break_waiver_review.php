<?php
/**
 * Path: /break_waiver_review.php
 * Manager review of no-break requests for assigned stores.
 * Approve = no break deducted. Reject = Auto break minutes applied.
 * Custom = manager-entered minutes applied as a manual break.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$userId = (int)($_SESSION['user_id'] ?? 0);
$userLvl = (int)($_SESSION['user_level'] ?? 99);
if ($userId <= 0 || $userLvl > 30) {
    header('Location: kiosk.php?err=access_denied');
    exit;
}
require_once 'db.php';
if (is_file(__DIR__ . '/engine/auto_break_lib.php')) {
    require_once __DIR__ . '/engine/auto_break_lib.php';
}
require_once __DIR__ . '/engine/break_waiver_lib.php';
if (is_file(__DIR__ . '/engine/menu_generator.php')) {
    require_once __DIR__ . '/engine/menu_generator.php';
}

$isSuper = ($userLvl <= 10);
$currentOrg = (int)($_SESSION['org_id'] ?? 0);
$assignedCsv = '';
if (!$isSuper) {
    $u = $pdo->prepare('SELECT assigned_site_ids FROM users WHERE id = ?');
    $u->execute([$userId]);
    $assignedCsv = (string)($u->fetchColumn() ?? '');
}
if ($isSuper) {
    $orgFilter = (int)($_GET['org_id'] ?? $_POST['org_id'] ?? $currentOrg);
    if ($orgFilter <= 0) {
        $orgFilter = $currentOrg;
    }
    $sitesStmt = $pdo->query('SELECT SId, nick_name, org_id FROM sites ORDER BY nick_name ASC');
    $allSites = $sitesStmt ? $sitesStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if ($orgFilter > 0) {
        $allSites = array_values(array_filter($allSites, static function ($s) use ($orgFilter) {
            return (int)$s['org_id'] === $orgFilter;
        }));
    }
} else {
    $orgFilter = $currentOrg;
    if ($assignedCsv !== '') {
        $st = $pdo->prepare('SELECT SId, nick_name, org_id FROM sites WHERE org_id = ? AND FIND_IN_SET(SId, ?) ORDER BY nick_name ASC');
        $st->execute([$currentOrg, $assignedCsv]);
    } else {
        $st = $pdo->prepare('SELECT SId, nick_name, org_id FROM sites WHERE org_id = ? ORDER BY nick_name ASC');
        $st->execute([$currentOrg]);
    }
    $allSites = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$allowedIds = array_map(static fn($s) => (int)$s['SId'], $allSites);
$siteId = (int)($_POST['site_id'] ?? $_GET['site_id'] ?? 0);
if ($siteId > 0 && !in_array($siteId, $allowedIds, true)) {
    $siteId = 0;
}
$status = (string)($_GET['status'] ?? $_POST['status'] ?? 'pending');
if ($status !== 'all' && !bwr_valid_status($status)) {
    $status = 'pending';
}
$from = (string)($_GET['from_date'] ?? $_POST['from_date'] ?? '');
$to = (string)($_GET['to_date'] ?? $_POST['to_date'] ?? '');
$flash = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['decide'])) {
    if (function_exists('ps_require_csrf')) {
        ps_require_csrf();
    }
    $decision = (string)($_POST['decision'] ?? '');
    $ids = [];
    if (!empty($_POST['request_ids']) && is_array($_POST['request_ids'])) {
        foreach ($_POST['request_ids'] as $id) {
            $ids[] = (int)$id;
        }
    }
    $single = (int)($_POST['request_id'] ?? 0);
    if ($single > 0) {
        $ids[] = $single;
    }
    $ids = array_values(array_unique(array_filter($ids)));
    $minutes = (int)($_POST['minutes'] ?? 0);
    $note = (string)($_POST['note'] ?? '');
    if ($ids === []) {
        $flashErr = 'Select at least one request.';
    } else {
        $ok = 0;
        $errors = [];
        foreach ($ids as $id) {
            $res = bwr_decide($pdo, $id, $decision, $minutes, $userId, $note, $orgFilter, $isSuper, $assignedCsv);
            if (!empty($res['ok'])) {
                $ok++;
            } else {
                $errors[] = (string)($res['error'] ?? 'Could not save a decision.');
            }
        }
        if ($ok > 0) {
            $flash = $ok === 1 ? 'Saved 1 decision. Payroll was not finalized.' : ('Saved ' . $ok . ' decisions. Payroll was not finalized.');
        }
        if ($errors !== []) {
            $flashErr = implode(' ', array_unique($errors));
        }
    }
}

$listSites = $siteId > 0 ? [$siteId] : $allowedIds;
$rows = ($orgFilter > 0 && $listSites) ? bwr_list($pdo, $orgFilter, $listSites, $status, $from, $to, 200) : [];
$highlight = (int)($_GET['request_id'] ?? 0);
$menu = function_exists('generateMenu') ? generateMenu($pdo) : '';
$csrf = function_exists('ps_csrf_token') ? ps_csrf_token() : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>No-break request review</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f7f6; margin: 0; padding-top: 70px; color: #0f172a; }
        .wrap { max-width: 1200px; margin: 20px auto; padding: 0 16px 40px; }
        .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,.06); padding: 20px; margin-bottom: 20px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        .hint { color: #475569; margin-bottom: 16px; }
        label { font-weight: 600; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; text-align: left; font-size: 13px; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; color: #64748b; }
        .ok { background: #ecfdf5; color: #065f46; padding: 10px 12px; border-radius: 6px; margin-bottom: 12px; }
        .err { background: #fef2f2; color: #991b1b; padding: 10px 12px; border-radius: 6px; margin-bottom: 12px; }
        .filters { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; }
        .filters .field { min-width: 140px; }
        .filters input, .filters select { width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; }
        .row-highlight { background: #fff7ed; }
        .mins { width: 70px; }
        .note { width: 140px; }
        .actions button { margin: 0 4px 4px 0; }
    </style>
</head>
<body>
<?= $menu ?>
<div class="wrap">
    <div class="card">
        <h1>No-break request review</h1>
        <p class="hint">Employees can request no automatic break deduction at clock-out. Approve leaves the shift with no break. Reject applies the automatic break minutes. Custom applies the minutes you type as a manager-entered break. Shifts with a pending request cannot be finalized.</p>
        <?php if ($flash !== ''): ?><div class="ok"><?= bwr_h($flash) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="err"><?= bwr_h($flashErr) ?></div><?php endif; ?>
        <form method="get" class="filters">
            <?php if ($isSuper): ?>
            <div class="field"><label>Organization id</label><input type="number" name="org_id" value="<?= (int)$orgFilter ?>"></div>
            <?php endif; ?>
            <div class="field"><label>Store</label>
                <select name="site_id">
                    <option value="0">All allowed stores</option>
                    <?php foreach ($allSites as $s): ?>
                    <option value="<?= (int)$s['SId'] ?>" <?= $siteId === (int)$s['SId'] ? 'selected' : '' ?>><?= bwr_h($s['nick_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Status</label>
                <select name="status">
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    <option value="custom" <?= $status === 'custom' ? 'selected' : '' ?>>Custom</option>
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
                </select>
            </div>
            <div class="field"><label>From</label><input type="date" name="from_date" value="<?= bwr_h($from) ?>"></div>
            <div class="field"><label>To</label><input type="date" name="to_date" value="<?= bwr_h($to) ?>"></div>
            <div class="field"><button class="btn btn-sm btn-primary" type="submit">Show requests</button></div>
        </form>
    </div>

    <div class="card">
        <h2 style="font-size:16px;margin-top:0;"><?= count($rows) ?> request<?= count($rows) === 1 ? '' : 's' ?></h2>
        <?php if (!$rows): ?>
            <p class="hint" style="margin:0;">No no-break requests match this filter.</p>
        <?php else: ?>
        <form method="post">
            <?php if ($csrf !== ''): ?><input type="hidden" name="csrf_token" value="<?= bwr_h($csrf) ?>"><?php endif; ?>
            <?php if ($isSuper): ?><input type="hidden" name="org_id" value="<?= (int)$orgFilter ?>"><?php endif; ?>
            <input type="hidden" name="site_id" value="<?= (int)$siteId ?>">
            <input type="hidden" name="status" value="<?= bwr_h($status) ?>">
            <input type="hidden" name="from_date" value="<?= bwr_h($from) ?>">
            <input type="hidden" name="to_date" value="<?= bwr_h($to) ?>">
            <table>
                <thead>
                    <tr>
                        <th></th>
                        <th>Employee</th>
                        <th>Store</th>
                        <th>Shift</th>
                        <th>Requested</th>
                        <th>Status</th>
                        <th>Minutes</th>
                        <th>Note</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row):
                    $pending = ((string)$row['status'] === 'pending');
                    ?>
                    <tr class="<?= $highlight === (int)$row['id'] ? 'row-highlight' : '' ?>" id="req-<?= (int)$row['id'] ?>">
                        <td><?php if ($pending): ?><input type="checkbox" name="request_ids[]" value="<?= (int)$row['id'] ?>"><?php endif; ?></td>
                        <td><?= bwr_h($row['employee_name'] ?? ('#' . (int)$row['user_id'])) ?></td>
                        <td><?= bwr_h($row['site_name'] ?? ('Site #' . (int)$row['site_id'])) ?></td>
                        <td><?= bwr_h((string)($row['clock_in'] ?? '')) ?><br><?= bwr_h((string)($row['clock_out'] ?? '')) ?></td>
                        <td><?= bwr_h($row['requested_at']) ?></td>
                        <td><?= bwr_h($row['status']) ?></td>
                        <td><?= (int)$row['minutes'] ?></td>
                        <td><?= bwr_h((string)($row['note'] ?? '')) ?></td>
                        <td class="actions">
                            <?php if ($pending): ?>
                            <input type="number" class="mins" name="minutes" form="decide-<?= (int)$row['id'] ?>" min="1" max="240" placeholder="<?= (int)$row['minutes'] ?: 30 ?>" title="Minutes for Reject or Custom">
                            <input type="text" class="note" name="note" form="decide-<?= (int)$row['id'] ?>" placeholder="Optional note">
                            <button class="btn btn-sm btn-success" type="submit" form="decide-<?= (int)$row['id'] ?>" name="decision" value="approved">Approve</button>
                            <button class="btn btn-sm btn-warning" type="submit" form="decide-<?= (int)$row['id'] ?>" name="decision" value="rejected">Reject</button>
                            <button class="btn btn-sm btn-secondary" type="submit" form="decide-<?= (int)$row['id'] ?>" name="decision" value="custom">Custom</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:12px;">
                <label>Bulk minutes (for Reject or Custom)</label>
                <input type="number" name="minutes" min="1" max="240" class="mins" placeholder="30">
                <label style="margin-left:8px;">Note</label>
                <input type="text" name="note" class="note" placeholder="Optional note">
                <input type="hidden" name="decision" value="approved">
                <button class="btn btn-sm btn-success" type="submit" name="decide" value="1">Bulk approve selected</button>
            </div>
        </form>
        <?php foreach ($rows as $row):
            if ((string)$row['status'] !== 'pending') { continue; } ?>
        <form method="post" id="decide-<?= (int)$row['id'] ?>" style="display:none;">
            <?php if ($csrf !== ''): ?><input type="hidden" name="csrf_token" value="<?= bwr_h($csrf) ?>"><?php endif; ?>
            <?php if ($isSuper): ?><input type="hidden" name="org_id" value="<?= (int)$orgFilter ?>"><?php endif; ?>
            <input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>">
            <input type="hidden" name="site_id" value="<?= (int)$siteId ?>">
            <input type="hidden" name="status" value="<?= bwr_h($status) ?>">
            <input type="hidden" name="from_date" value="<?= bwr_h($from) ?>">
            <input type="hidden" name="to_date" value="<?= bwr_h($to) ?>">
            <input type="hidden" name="decide" value="1">
        </form>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
