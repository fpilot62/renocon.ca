<?php
/**
 * Path: /attendance_audit.php
 * Who changed an attendance row. Payroll admins only. One organization at a time.
 */
require_once __DIR__ . '/engine/privacy_lib.php';
privacy_boot_web();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll_scope.php';
require_once __DIR__ . '/engine/menu_generator.php';
require_once __DIR__ . '/engine/attendance_audit_lib.php';

ps_require_payroll_admin();
$actorId = (int)$_SESSION['user_id'];
$isSuper = ps_is_superadmin();
$sessionOrg = ps_org_id();
$requested = (int)($_GET['org_id'] ?? 0);
if ($isSuper) {
    $orgId = $requested > 0 ? $requested : $sessionOrg;
} else {
    $orgId = $sessionOrg;
    if ($requested > 0 && $requested !== $orgId) {
        http_response_code(403);
        exit('That organization is outside your access.');
    }
}
if ($orgId <= 0) {
    http_response_code(403);
    exit('No organization is selected.');
}

$orgs = [];
if ($isSuper) {
    try {
        $orgs = $pdo->query('SELECT id, org_name FROM organizations ORDER BY org_name ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $orgs = [];
    }
}

try {
    $have = array_flip($pdo->query('SHOW COLUMNS FROM menu_manager')->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $exists = $pdo->prepare('SELECT MenuID FROM menu_manager WHERE LinkURL LIKE ? LIMIT 1');
    $exists->execute(['%attendance_audit.php%']);
    if (!$exists->fetchColumn() && $have) {
        $parent = $pdo->query("SELECT MenuID, Module FROM menu_manager WHERE (ParentID = 0 OR ParentID IS NULL) AND UPPER(TRIM(MenuTitle)) IN ('ADMIN','PAYROLL') ORDER BY MenuID ASC LIMIT 1");
        $prow = $parent ? $parent->fetch(PDO::FETCH_ASSOC) : false;
        $data = [
            'MenuTitle' => 'Attendance history',
            'LinkURL' => 'attendance_audit.php',
            'ParentID' => (int)($prow['MenuID'] ?? 0),
            'IsActive' => 1,
            'UserRole' => 20,
            'Module' => (string)($prow['Module'] ?? ''),
            'SortOrder' => 920,
        ];
        $fields = [];
        $holders = [];
        $vals = [];
        foreach ($data as $col => $val) {
            if (!isset($have[$col])) {
                continue;
            }
            $fields[] = '`' . $col . '`';
            $holders[] = '?';
            $vals[] = $val;
        }
        if ($fields) {
            $pdo->prepare('INSERT INTO menu_manager (' . implode(',', $fields) . ') VALUES (' . implode(',', $holders) . ')')->execute($vals);
        }
    }
} catch (Throwable $e) {
}

$employeeId = (int)($_GET['user_id'] ?? 0);
$siteId = (int)($_GET['site_id'] ?? 0);
$attendanceId = (int)($_GET['attendance_id'] ?? 0);
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = '';
}

$users = ps_list_users($pdo, $orgId);
$sites = ps_list_sites($pdo, $orgId);
$rows = [];
$tableReady = true;
try {
    $where = ['org_id = ?'];
    $params = [$orgId];
    if ($attendanceId > 0) {
        $where[] = 'attendance_id = ?';
        $params[] = $attendanceId;
    }
    if ($employeeId > 0) {
        $where[] = 'target_user_id = ?';
        $params[] = $employeeId;
    }
    if ($siteId > 0) {
        $where[] = 'site_id = ?';
        $params[] = $siteId;
    }
    if ($from !== '') {
        $where[] = 'created_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if ($to !== '') {
        $where[] = 'created_at <= ?';
        $params[] = $to . ' 23:59:59';
    }
    $sql = 'SELECT * FROM attendance_audit WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 300';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $tableReady = false;
}

function aa_page_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function aa_page_when(string $utc): string
{
    if ($utc === '') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $dt->setTimezone(new DateTimeZone('America/Edmonton'))->format('M j, Y g:i A');
    } catch (Throwable $e) {
        return $utc;
    }
}

function aa_page_diff(?string $before, ?string $after): string
{
    $b = json_decode((string)$before, true);
    $a = json_decode((string)$after, true);
    if (!is_array($b) && !is_array($a)) {
        return '';
    }
    $b = is_array($b) ? $b : [];
    $a = is_array($a) ? $a : [];
    $keys = array_unique(array_merge(array_keys($b), array_keys($a)));
    $lines = [];
    foreach ($keys as $key) {
        $old = $b[$key] ?? null;
        $new = $a[$key] ?? null;
        if ($old == $new) {
            continue;
        }
        $lines[] = $key . ': ' . aa_page_h(is_scalar($old) || $old === null ? (string)$old : json_encode($old))
            . ' → ' . aa_page_h(is_scalar($new) || $new === null ? (string)$new : json_encode($new));
    }
    return implode('<br>', $lines);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Attendance history</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<style>
body{background:#f1f5f9;padding:90px 16px 40px;font-size:13px;color:#1e293b}
.sheet{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:14px}
table.t th{background:#0f172a;color:#fff;font-size:11px}
</style>
</head>
<body>
<?= function_exists('generateMenu') ? generateMenu($pdo) : '' ?>
<div class="container-fluid">
    <h4 class="font-weight-bold">Attendance history</h4>
    <p class="text-muted">Who created, changed, finalized, or removed a punch. Times are Mountain time. The stored clock is UTC.</p>
    <?php if (!$tableReady): ?>
        <div class="alert alert-warning">Attendance history is not set up yet. After a backup, run migrations/20261120_attendance_audit.sql.</div>
    <?php endif; ?>
    <div class="sheet">
        <form method="get" class="form-row align-items-end">
            <?php if ($isSuper && $orgs): ?>
            <div class="col-md-2 mb-2"><label>Organization</label>
                <select name="org_id" class="form-control form-control-sm">
                    <?php foreach ($orgs as $o): ?>
                    <option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === $orgId ? 'selected' : '' ?>><?= aa_page_h($o['org_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
            <?php endif; ?>
            <div class="col-md-2 mb-2"><label>Employee</label>
                <select name="user_id" class="form-control form-control-sm">
                    <option value="0">All</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $employeeId ? 'selected' : '' ?>><?= aa_page_h($u['Name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 mb-2"><label>Site</label>
                <select name="site_id" class="form-control form-control-sm">
                    <option value="0">All</option>
                    <?php foreach ($sites as $s): ?>
                    <option value="<?= (int)$s['SId'] ?>" <?= (int)$s['SId'] === $siteId ? 'selected' : '' ?>><?= aa_page_h($s['nick_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 mb-2"><label>From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= aa_page_h($from) ?>"></div>
            <div class="col-md-2 mb-2"><label>To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= aa_page_h($to) ?>"></div>
            <div class="col-md-1 mb-2"><label>Punch</label><input name="attendance_id" class="form-control form-control-sm" value="<?= $attendanceId > 0 ? (int)$attendanceId : '' ?>"></div>
            <div class="col-md-1 mb-2"><button class="btn btn-sm btn-dark btn-block">Filter</button></div>
        </form>
    </div>
    <div class="sheet">
        <table class="table table-sm table-bordered t">
            <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Punch</th><th>Employee</th><th>Site</th><th>IP</th><th>Page</th><th>Before → after</th><th>Note</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= aa_page_h(aa_page_when((string)$r['created_at'])) ?></td>
                    <td><?= aa_page_h($r['actor_name'] ?: ('user #' . (int)$r['actor_user_id'])) ?><div class="text-muted">role <?= aa_page_h($r['actor_role']) ?></div></td>
                    <td><?= aa_page_h($r['action']) ?></td>
                    <td>#<?= (int)$r['attendance_id'] ?></td>
                    <td>#<?= (int)$r['target_user_id'] ?></td>
                    <td>#<?= (int)$r['site_id'] ?></td>
                    <td><?= aa_page_h($r['ip']) ?></td>
                    <td><?= aa_page_h($r['source_page']) ?></td>
                    <td><?= aa_page_diff($r['before_json'] ?? '', $r['after_json'] ?? '') ?></td>
                    <td><?= aa_page_h($r['note']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="10" class="text-muted">No history for this filter.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
