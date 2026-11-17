<?php
/**
 * Path: /privacy_support.php
 * Organization admins grant time-limited platform support access.
 * Platform superadmins see organization counts and can revoke a grant.
 * This page does not show pay, bank, SIN, or employee records.
 */
require_once __DIR__ . '/engine/privacy_lib.php';
privacy_boot_web();
require_once __DIR__ . '/engine/attendance_integrity_lib.php';
payroll_require_admin(false, 'Support access is for organization admins.');
require_once 'db.php';
if (is_file(__DIR__ . '/payroll_scope.php')) {
    require_once __DIR__ . '/payroll_scope.php';
}
payroll_require_scope_admin();
if (is_file(__DIR__ . '/engine/menu_generator.php')) {
    require_once __DIR__ . '/engine/menu_generator.php';
}

$level = privacy_session_level();
$sessionOrg = privacy_session_org();
$actorId = (int)($_SESSION['user_id'] ?? 0);
$isPlatform = $level <= 10;
$error = '';
$notice = (string)($_SESSION['privacy_support_flash'] ?? '');
unset($_SESSION['privacy_support_flash']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    privacy_require_csrf();
    try {
        if (isset($_POST['grant_access'])) {
            $hours = (int)($_POST['hours'] ?? 0);
            $reason = (string)($_POST['reason'] ?? '');
            privacy_create_grant($pdo, $sessionOrg, $actorId, $level, $sessionOrg, $hours, $reason);
            $_SESSION['privacy_support_flash'] = 'Support access was granted. The organization owner was emailed when an address was on file.';
            header('Location: privacy_support.php');
            exit;
        }
        if (isset($_POST['revoke_access'])) {
            privacy_revoke_grant($pdo, (int)($_POST['grant_id'] ?? 0), $actorId, $level, $sessionOrg);
            $_SESSION['privacy_support_flash'] = 'Support access was revoked.';
            header('Location: privacy_support.php');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$ready = false;
$grants = [];
$counts = [];
try {
    $ready = privacy_table_exists($pdo, 'privacy_support_grants');
    if ($ready) {
        if ($isPlatform) {
            $grants = $pdo->query(
                'SELECT g.id, g.org_id, g.granted_by, g.reason, g.starts_at, g.expires_at, g.revoked_at, g.notify_note, o.org_name
                 FROM privacy_support_grants g
                 LEFT JOIN organizations o ON o.id = g.org_id
                 ORDER BY g.id DESC
                 LIMIT 50'
            )->fetchAll(PDO::FETCH_ASSOC);
            $counts = privacy_org_counts($pdo);
        } else {
            $st = $pdo->prepare(
                'SELECT g.id, g.org_id, g.granted_by, g.reason, g.starts_at, g.expires_at, g.revoked_at, g.notify_note, o.org_name
                 FROM privacy_support_grants g
                 LEFT JOIN organizations o ON o.id = g.org_id
                 WHERE g.org_id = ?
                 ORDER BY g.id DESC
                 LIMIT 50'
            );
            $st->execute([$sessionOrg]);
            $grants = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {
    $error = $error !== '' ? $error : 'Support access storage is not ready.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll support access</title>
    <style>
        body { font-family: sans-serif; background: #f8fafc; color: #0f172a; margin: 24px auto; max-width: 880px; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
        label { display: block; font-size: 12px; font-weight: 700; margin: 12px 0 6px; }
        input, textarea { width: 100%; box-sizing: border-box; padding: 8px; }
        button { margin-top: 12px; background: #0f172a; color: #fff; border: 0; border-radius: 6px; padding: 8px 14px; font-weight: 700; }
        table { border-collapse: collapse; width: 100%; font-size: 13px; }
        td, th { border-bottom: 1px solid #e2e8f0; text-align: left; padding: 6px; vertical-align: top; }
        .ok { background: #dcfce7; padding: 10px 12px; border-radius: 8px; }
        .err { background: #fff7ed; padding: 10px 12px; border-radius: 8px; }
    </style>
</head>
<body>
<?php if (function_exists('generateMenu')) echo generateMenu($pdo); ?>
<?= privacy_banner_html() ?>
<div class="card">
    <h1>Payroll support access</h1>
    <p>A platform superadmin does not see an organization's pay, bank accounts, SINs, employees, reports, or attachments unless an organization admin grants access here. Access lasts at most 72 hours, is written to the access log, and shows a banner on every payroll page while it is active.</p>
    <?php if ($notice !== ''): ?><p class="ok"><?= privacy_h($notice) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="err"><?= privacy_h($error) ?></p><?php endif; ?>
    <?php if (!$ready): ?>
        <p class="err">Run migrations/20261117_privacy_safeguards.sql after a backup. Until that table exists, support access cannot be granted and platform superadmins stay out of organization records.</p>
    <?php endif; ?>
</div>

<?php if ($isPlatform): ?>
<div class="card">
    <h2>Organizations</h2>
    <p>Counts only. Open a payroll page for an organization after its admin has granted access.</p>
    <table>
        <thead><tr><th>Organization</th><th>Employees</th><th>Sites</th></tr></thead>
        <tbody>
        <?php foreach ($counts as $org): ?>
            <tr>
                <td><?= privacy_h($org['org_name']) ?></td>
                <td><?= (int)$org['employee_count'] ?></td>
                <td><?= (int)$org['site_count'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php elseif ($ready && privacy_may_grant($level, $sessionOrg, $sessionOrg)): ?>
<div class="card">
    <h2>Grant access</h2>
    <form method="POST">
        <?= privacy_csrf_field() ?>
        <label for="hours">Hours (1 to 72)</label>
        <input id="hours" name="hours" type="number" min="1" max="72" value="4" required>
        <label for="reason">Reason</label>
        <textarea id="reason" name="reason" rows="3" required placeholder="What the platform admin needs to look at. Do not type a SIN, bank account, or pay amount."></textarea>
        <button type="submit" name="grant_access" value="1">Grant support access</button>
    </form>
</div>
<?php endif; ?>

<?php if ($ready): ?>
<div class="card">
    <h2><?= $isPlatform ? 'Grants' : 'Your organization' ?></h2>
    <table>
        <thead><tr><th>Organization</th><th>Ends</th><th>Reason</th><th>Notice</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($grants as $grant): ?>
            <tr>
                <td><?= privacy_h((string)($grant['org_name'] ?? ('Org ' . (int)$grant['org_id']))) ?></td>
                <td><?= privacy_h((string)$grant['expires_at']) ?><?= !empty($grant['revoked_at']) ? ' (revoked)' : '' ?></td>
                <td><?= privacy_h((string)$grant['reason']) ?></td>
                <td><?= privacy_h((string)($grant['notify_note'] ?? '')) ?></td>
                <td>
                    <?php if (empty($grant['revoked_at'])): ?>
                    <form method="POST">
                        <?= privacy_csrf_field() ?>
                        <input type="hidden" name="grant_id" value="<?= (int)$grant['id'] ?>">
                        <button type="submit" name="revoke_access" value="1">Revoke</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$grants): ?><tr><td colspan="5">No grants yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
</body>
</html>
