<?php
/** Path: /staff_audit_exceptions.php
 * Read-only: same-day same-start duplicates and hours over 13.
 * Marker: sa-dup-13h-20261017-approve
 * Approve hides rows from Unapproved only. Never deletes attendance.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    header('Location: /login_kiosk.php');
    exit;
}
require_once __DIR__ . '/payroll_scope.php';
if (is_file(__DIR__ . '/engine/menu_generator.php')) {
    require_once __DIR__ . '/engine/menu_generator.php';
}
if (!function_exists('sip_org_pay_period_mode')) {
    require_once __DIR__ . '/engine/org_modules_lib.php';
}
if (!function_exists('sip_load_picker_sites')) {
    require_once __DIR__ . '/engine/site_status_lib.php';
}

$sess_user_level = (int)($_SESSION['user_level'] ?? 99);
$sa_is_super = $sess_user_level <= 10;
$session_org = (int)($_SESSION['org_id'] ?? 0);
$can_dismiss = $sess_user_level <= 30;

function sae_ensure_dismiss_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS staff_audit_exception_dismissed (
            attendance_id INT NOT NULL PRIMARY KEY,
            org_id INT NOT NULL DEFAULT 0,
            dismissed_by INT NOT NULL DEFAULT 0,
            dismissed_at DATETIME NOT NULL,
            KEY org_id (org_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

if (!function_exists('sa_period_bounds')) {
    function sa_period_bounds(string $work_date, string $mode, string $anchor = '2024-01-01'): array {
        if (!function_exists('pe_period_window')) {
            $eng = __DIR__ . '/payroll_engine/payroll_engine.php';
            if (is_file($eng)) {
                require_once $eng;
            }
        }
        if (function_exists('pe_period_window')) {
            return pe_period_window($work_date, $mode, $anchor);
        }
        $ts = strtotime($work_date) ?: time();
        if ($mode === 'monthly') {
            return [date('Y-m-01', $ts), date('Y-m-t', $ts)];
        }
        if ($mode === 'bi_weekly') {
            $d0 = new DateTimeImmutable(preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor) ? $anchor : '2024-01-01');
            $day = substr($work_date, 0, 10);
            $d1 = new DateTimeImmutable(preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? $day : date('Y-m-d'));
            $days = (int)$d0->diff($d1)->format('%r%a');
            $start = $d0->modify((((int)floor($days / 14)) * 14) . ' days')->format('Y-m-d');
            $end = (new DateTimeImmutable($start))->modify('+13 days')->format('Y-m-d');
            return [$start, $end];
        }
        $dayn = (int)date('j', $ts);
        if ($dayn <= 15) {
            return [date('Y-m-01', $ts), date('Y-m-15', $ts)];
        }
        return [date('Y-m-16', $ts), date('Y-m-t', $ts)];
    }
}

$sa_orgs = [];
if ($sa_is_super) {
    try {
        $sa_orgs = $pdo->query('SELECT id, org_name FROM organizations ORDER BY org_name ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $sa_orgs = [];
    }
}

$org_raw = $_GET['org_id'] ?? ($_COOKIE['sip_org_id'] ?? ($_COOKIE['sa_org_id'] ?? 'all'));
if (!$sa_is_super) {
    $selected_org = $session_org;
} else {
    $selected_org = ($org_raw === '' || $org_raw === 'all') ? 0 : (int)$org_raw;
}

$period_org = $selected_org > 0 ? $selected_org : $session_org;
$period_mode = function_exists('sip_org_pay_period_mode')
    ? sip_org_pay_period_mode($pdo, (int)$period_org)
    : 'semi_monthly';
$period_anchor = function_exists('sip_org_pay_period_anchor')
    ? sip_org_pay_period_anchor($pdo, (int)$period_org)
    : '2024-01-01';
[$periodFrom, $periodTo] = sa_period_bounds(date('Y-m-d'), $period_mode, $period_anchor);
$last14From = date('Y-m-d', strtotime('-13 days'));
$last14To = date('Y-m-d');

$from_date = preg_replace('/[^0-9\-]/', '', (string)($_GET['from_date'] ?? ''));
$to_date = preg_replace('/[^0-9\-]/', '', (string)($_GET['to_date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
    $from_date = $last14From;
    $to_date = $last14To;
}

$assigned = (string)($_SESSION['assigned_site_ids'] ?? '');
$picker_org = $selected_org > 0 ? $selected_org : $session_org;
if ($sa_is_super && $selected_org > 0 && function_exists('sip_sites_active_sql')) {
    $activeSql = sip_sites_active_sql('');
    $stSites = $pdo->prepare("SELECT SId, nick_name FROM sites WHERE org_id = ? AND $activeSql ORDER BY nick_name ASC");
    $stSites->execute([$selected_org]);
    $sites = $stSites->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sites = function_exists('sip_load_picker_sites')
        ? sip_load_picker_sites($pdo, $sess_user_level, $picker_org, $assigned)
        : [];
}

$site_raw = isset($_GET['site_id']) ? trim((string)$_GET['site_id']) : 'all';
$site_ids = [];
if ($site_raw !== '' && strtolower($site_raw) !== 'all') {
    $site_ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $site_raw)))));
}
$allowed = [];
foreach ((array)$sites as $s) {
    $sid = (int)($s['SId'] ?? $s['Sid'] ?? $s['id'] ?? 0);
    if ($sid > 0) {
        $allowed[$sid] = true;
    }
}
if ($site_ids && $allowed) {
    $site_ids = array_values(array_filter($site_ids, static function ($id) use ($allowed) {
        return isset($allowed[(int)$id]);
    }));
}

$view = (string)($_GET['view'] ?? 'all');
if (!in_array($view, ['all', 'dup', 'over13', 'overnight'], true)) {
    $view = 'all';
}
$status_type = (string)($_GET['status_type'] ?? 'all');
if (!in_array($status_type, ['all', 'pending', 'finalized'], true)) {
    $status_type = 'all';
}
$approval = strtolower(trim((string)($_GET['approval'] ?? '')));
if ($approval === '' && !empty($_GET['show_dismissed'])) {
    $approval = 'all';
}
if (!in_array($approval, ['unapproved', 'all'], true)) {
    $approval = 'unapproved';
}
$show_all = ($approval === 'all');

sae_ensure_dismiss_table($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_dismiss) {
    $listAction = (string)($_POST['list_action'] ?? '');
    if ($listAction === 'approve') {
        $listAction = 'dismiss';
    } elseif ($listAction === 'unapprove') {
        $listAction = 'restore';
    }
    $postedIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    $done = 0;
    if ($postedIds && in_array($listAction, ['dismiss', 'restore'], true)) {
        $in = implode(',', $postedIds);
        $chk = $pdo->query("SELECT id, org_id FROM attendance WHERE id IN ($in)");
        $ok = [];
        foreach ($chk as $row) {
            $oid = (int)$row['org_id'];
            if (!$sa_is_super && $session_org > 0 && $oid !== $session_org) {
                continue;
            }
            if ($sa_is_super && $selected_org > 0 && $oid !== $selected_org) {
                continue;
            }
            $ok[] = [(int)$row['id'], $oid];
        }
        if ($listAction === 'dismiss') {
            $ins = $pdo->prepare(
                'INSERT INTO staff_audit_exception_dismissed (attendance_id, org_id, dismissed_by, dismissed_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE dismissed_by = VALUES(dismissed_by), dismissed_at = VALUES(dismissed_at)'
            );
            foreach ($ok as $pair) {
                $ins->execute([$pair[0], $pair[1], (int)$_SESSION['user_id']]);
                $done++;
            }
        } else {
            $del = $pdo->prepare('DELETE FROM staff_audit_exception_dismissed WHERE attendance_id = ?');
            foreach ($ok as $pair) {
                $del->execute([$pair[0]]);
                $done++;
            }
        }
    }
    $q = [
        'org_id' => $selected_org > 0 ? (string)$selected_org : 'all',
        'site_id' => $site_ids ? implode(',', $site_ids) : 'all',
        'from_date' => $from_date,
        'to_date' => $to_date,
        'view' => $view,
        'status_type' => $status_type,
        'approval' => $show_all ? 'all' : 'unapproved',
    ];
    if ($listAction === 'dismiss') {
        $q['approved'] = (string)$done;
    } elseif ($listAction === 'restore') {
        $q['unapproved'] = (string)$done;
    }
    header('Location: staff_audit_exceptions.php?' . http_build_query($q));
    exit;
}

$dismissedSet = [];
try {
    $dsql = 'SELECT attendance_id FROM staff_audit_exception_dismissed';
    $dparams = [];
    if (!$sa_is_super && $session_org > 0) {
        $dsql .= ' WHERE org_id = ?';
        $dparams[] = $session_org;
    } elseif ($sa_is_super && $selected_org > 0) {
        $dsql .= ' WHERE org_id = ?';
        $dparams[] = $selected_org;
    }
    $dst = $pdo->prepare($dsql);
    $dst->execute($dparams);
    foreach ($dst as $drow) {
        $dismissedSet[(int)$drow['attendance_id']] = true;
    }
} catch (Throwable $e) {
    $dismissedSet = [];
}

$where = ['DATE(a.clock_in) BETWEEN :from_date AND :to_date'];
$params = [':from_date' => $from_date, ':to_date' => $to_date];
if (!$sa_is_super && $session_org > 0) {
    $where[] = 'a.org_id = :org_id';
    $params[':org_id'] = $session_org;
} elseif ($sa_is_super && $selected_org > 0) {
    $where[] = 'a.org_id = :org_id';
    $params[':org_id'] = $selected_org;
}
if ($status_type === 'pending') {
    $where[] = 'a.payout_status = 0';
} elseif ($status_type === 'finalized') {
    $where[] = 'a.payout_status = 1';
}
if ($site_ids) {
    $ph = [];
    foreach ($site_ids as $i => $sid) {
        $k = ':sid' . $i;
        $ph[] = $k;
        $params[$k] = (int)$sid;
    }
    $where[] = "COALESCE(NULLIF(NULLIF(a.site_id, '0'), 'all'), NULLIF(u.primary_site_id, 0)) IN (" . implode(',', $ph) . ')';
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

$sql = "SELECT a.id, a.user_id, a.org_id, a.site_id, a.clock_in, a.clock_out, a.payout_status, a.status,
               a.transfer_status, a.approved_hours,
               u.Name AS staff_name,
               s.nick_name AS site_name,
               DATE(a.clock_in) AS work_date,
               DATE(a.clock_out) AS out_date,
               DATE_FORMAT(a.clock_in, '%H:%i') AS start_hhmm
        FROM attendance a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN sites s ON s.SId = COALESCE(NULLIF(NULLIF(a.site_id, '0'), 'all'), NULLIF(u.primary_site_id, 0))
        $where_sql
        ORDER BY u.Name ASC, a.clock_in ASC, a.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
$scannedAll = count($raw);
$nApprovedHidden = 0;
foreach ($raw as $r) {
    if (isset($dismissedSet[(int)$r['id']])) {
        $nApprovedHidden++;
    }
}
if (!$show_all) {
    $raw = array_values(array_filter($raw, static function ($r) use ($dismissedSet) {
        return !isset($dismissedSet[(int)$r['id']]);
    }));
}

$breakMap = [];
if ($raw) {
    $ids = array_map(static fn($r) => (int)$r['id'], $raw);
    $chunks = array_chunk($ids, 400);
    foreach ($chunks as $chunk) {
        $in = implode(',', array_map('intval', $chunk));
        $bq = $pdo->query(
            "SELECT shift_id, SUM(TIMESTAMPDIFF(SECOND, break_start, break_end)) AS brk
             FROM breaks
             WHERE shift_id IN ($in) AND break_end IS NOT NULL AND TRIM(break_end) <> ''
             GROUP BY shift_id"
        );
        foreach ($bq as $b) {
            $breakMap[(int)$b['shift_id']] = (int)$b['brk'];
        }
    }
}

foreach ($raw as &$r) {
    $inOk = !empty($r['clock_in']) && $r['clock_in'] !== '0000-00-00 00:00:00';
    $outOk = !empty($r['clock_out']) && $r['clock_out'] !== '0000-00-00 00:00:00';
    $r['open'] = $inOk && !$outOk;
    $r['hours'] = null;
    if ($inOk && $outOk) {
        $sec = strtotime((string)$r['clock_out']) - strtotime((string)$r['clock_in']);
        $sec -= (int)($breakMap[(int)$r['id']] ?? 0);
        if ($sec < 0) {
            $sec = 0;
        }
        $r['hours'] = round($sec / 3600, 2);
    }
    $r['row_over'] = ($r['hours'] !== null && $r['hours'] > 13.0);
    $r['ends_next'] = false;
    if ($inOk && $outOk) {
        $inDay = substr((string)$r['clock_in'], 0, 10);
        $outDay = substr((string)($r['out_date'] ?: $r['clock_out']), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $inDay) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $outDay)) {
            $r['ends_next'] = ($outDay === date('Y-m-d', strtotime($inDay . ' +1 day')));
        }
    }
    $r['approved'] = isset($dismissedSet[(int)$r['id']]);
}
unset($r);

$dayHours = [];
foreach ($raw as $r) {
    if ($r['hours'] === null) {
        continue;
    }
    $dk = (int)$r['user_id'] . '|' . $r['work_date'];
    $dayHours[$dk] = ($dayHours[$dk] ?? 0.0) + (float)$r['hours'];
}

$dupKeys = [];
$dupCount = [];
foreach ($raw as $r) {
    $k = (int)$r['user_id'] . '|' . $r['work_date'] . '|' . $r['start_hhmm'];
    $dupCount[$k] = ($dupCount[$k] ?? 0) + 1;
}
foreach ($dupCount as $k => $n) {
    if ($n >= 2) {
        $dupKeys[$k] = $n;
    }
}

$rows = [];
foreach ($raw as $r) {
    $dk = (int)$r['user_id'] . '|' . $r['work_date'];
    $gk = (int)$r['user_id'] . '|' . $r['work_date'] . '|' . $r['start_hhmm'];
    $r['day_hours'] = round((float)($dayHours[$dk] ?? 0), 2);
    $r['day_over'] = $r['day_hours'] > 13.0;
    $r['is_dup'] = isset($dupKeys[$gk]);
    $r['dup_n'] = (int)($dupKeys[$gk] ?? 1);
    $keep = $r['is_dup'] || $r['row_over'] || $r['day_over'] || !empty($r['ends_next']);
    if ($view === 'dup') {
        $keep = $r['is_dup'];
    } elseif ($view === 'over13') {
        $keep = $r['row_over'] || $r['day_over'];
    } elseif ($view === 'overnight') {
        $keep = !empty($r['ends_next']);
    }
    if ($keep) {
        $rows[] = $r;
    }
}

$nDupGroups = count($dupKeys);
$nDupRows = 0;
$nRow13 = 0;
$nDay13 = 0;
$nOvernight = 0;
$nApprovedShown = 0;
foreach ($raw as $r) {
    if (!empty($r['ends_next'])) {
        $nOvernight++;
    }
}
foreach ($rows as $r) {
    if ($r['is_dup']) {
        $nDupRows++;
    }
    if ($r['row_over']) {
        $nRow13++;
    }
    if ($r['day_over'] && !$r['row_over']) {
        $nDay13++;
    }
    if (!empty($r['approved'])) {
        $nApprovedShown++;
    }
}

function sae_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function sae_time(?string $dt): string
{
    if (!$dt || $dt === '0000-00-00 00:00:00') {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts ? date('M j, g:i A', $ts) : $dt;
}

$org_q = $selected_org > 0 ? (string)$selected_org : 'all';
$site_q = $site_ids ? implode(',', $site_ids) : 'all';
$qs = 'org_id=' . urlencode($org_q)
    . '&site_id=' . urlencode($site_q)
    . '&from_date=' . urlencode($from_date)
    . '&to_date=' . urlencode($to_date)
    . '&status_type=' . urlencode($status_type)
    . '&view=' . urlencode($view)
    . '&approval=' . urlencode($approval);
$flashApproved = (int)($_GET['approved'] ?? $_GET['removed'] ?? 0);
$flashUnapproved = (int)($_GET['unapproved'] ?? $_GET['restored'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Audit — Duplicates, over 13h &amp; overnight</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background:#f1f5f9; font-family: Inter, system-ui, sans-serif; padding-top: 88px; color:#0f172a; }
        .wrap { max-width: 1280px; margin: 0 auto 60px; padding: 0 18px; }
        .card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px 20px; box-shadow:0 8px 24px rgba(15,23,42,.05); }
        h1 { font-size:20px; font-weight:900; margin:0 0 6px; }
        .lede { color:#475569; font-size:13px; margin:0 0 14px; line-height:1.45; }
        .filters { display:flex; flex-wrap:wrap; gap:10px; align-items:end; margin-bottom:12px; }
        .filters label { display:block; font-size:10px; font-weight:800; text-transform:uppercase; color:#64748b; margin-bottom:4px; }
        .filters input, .filters select { height:36px; border:1px solid #cbd5e1; border-radius:8px; padding:0 10px; font-weight:600; }
        .btn { display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 12px; border-radius:8px; font-weight:800; font-size:12px; text-decoration:none; border:none; cursor:pointer; }
        .btn-go { background:#0f766e; color:#fff; }
        .btn-ghost { background:#e2e8f0; color:#0f172a; }
        .btn-back { background:#1d4ed8; color:#fff; }
        .btn-approve { background:#0f766e; color:#fff; }
        .btn-approve:disabled { opacity:.45; cursor:not-allowed; }
        .btn-unapprove { background:#475569; color:#fff; }
        .btn-unapprove:disabled { opacity:.45; cursor:not-allowed; }
        .flash { background:#ecfdf5; border:1px solid #86efac; color:#14532d; padding:10px 12px; border-radius:8px; font-weight:700; font-size:13px; margin-bottom:12px; }
        .list-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0 0 12px; }
        .chips { display:flex; gap:8px; flex-wrap:wrap; margin:10px 0 14px; }
        .chip { background:#f8fafc; border:1px solid #e2e8f0; border-radius:999px; padding:5px 12px; font-size:12px; font-weight:700; }
        .chip.dup { background:#fce7f3; border-color:#f9a8d4; color:#9d174d; }
        .chip.over { background:#fee2e2; border-color:#fca5a5; color:#991b1b; }
        .chip.night { background:#e0f2fe; border-color:#7dd3fc; color:#075985; }
        .chip.ok { background:#dcfce7; border-color:#86efac; color:#14532d; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; padding:8px 8px; border-bottom:2px solid #e2e8f0; }
        td { padding:8px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
        tr.dup td { background:#fdf2f8; }
        tr.over td { background:#fef2f2; }
        tr.dup.over td { background:#ffe4e6; }
        tr.night td { background:#f0f9ff; }
        tr.night.dup td { background:#fdf2f8; }
        tr.night.over td { background:#fef2f2; }
        tr.approved td { opacity:.72; }
        .badge { display:inline-block; font-size:10px; font-weight:800; text-transform:uppercase; padding:2px 7px; border-radius:6px; margin:0 4px 4px 0; }
        .badge.dup { background:#f9a8d4; color:#831843; }
        .badge.row13 { background:#ef4444; color:#fff; }
        .badge.day13 { background:#fb7185; color:#7f1d1d; }
        .badge.open { background:#fde68a; color:#92400e; }
        .badge.night { background:#38bdf8; color:#0c4a6e; }
        .badge.ok { background:#16a34a; color:#fff; }
        .muted { color:#64748b; font-size:12px; }
        .empty { text-align:center; padding:36px; color:#94a3b8; font-style:italic; }
        a.rowlink { color:#1d4ed8; font-weight:700; text-decoration:none; }
    </style>
</head>
<body>
<?= function_exists('generateMenu') ? generateMenu($pdo) : '' ?>
<div class="wrap">
    <div class="card">
        <h1>Duplicates, over 13h &amp; overnight</h1>
        <p class="lede">
            Read-only. <strong>Duplicate</strong> = same employee, same calendar day, <strong>same start time (HH:MM)</strong> — two or more attendance rows.
            Two shifts the same day with different start times are not duplicates.
            <strong>Over 13h (row)</strong> = that punch’s hours (clock-in to clock-out, minus breaks) are over 13.
            <strong>Over 13h (day)</strong> = that employee’s summed hours that calendar day are over 13.
            <strong>Ends next day</strong> = clock-out calendar date is the next day after clock-in (overnight). Shown even if hours are 13 or less and it is not a duplicate.
            Default list is <strong>Unapproved only</strong>. Tick rows → <strong>Approve</strong> to take them off this list. They stay off until you <strong>Show all</strong> and <strong>Unapprove</strong>. Clock-in / attendance punches are <strong>not</strong> deleted.
        </p>

        <form method="GET" class="filters">
            <?php if ($sa_is_super): ?>
            <div>
                <label>Org</label>
                <select name="org_id" onchange="this.form.submit()">
                    <option value="all" <?= $selected_org === 0 ? 'selected' : '' ?>>All organizations</option>
                    <?php foreach ($sa_orgs as $o): ?>
                        <option value="<?= (int)$o['id'] ?>" <?= $selected_org === (int)$o['id'] ? 'selected' : '' ?>><?= sae_h($o['org_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div>
                <label>Restaurant</label>
                <select name="site_id">
                    <option value="all" <?= !$site_ids ? 'selected' : '' ?>>All Sites</option>
                    <?php foreach ((array)$sites as $s):
                        $sid = (int)($s['SId'] ?? $s['id'] ?? 0);
                        if ($sid <= 0) {
                            continue;
                        }
                    ?>
                        <option value="<?= $sid ?>" <?= (count($site_ids) === 1 && (int)$site_ids[0] === $sid) ? 'selected' : '' ?>>
                            <?= $sid ?>. <?= sae_h($s['nick_name'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>From</label>
                <input type="date" name="from_date" value="<?= sae_h($from_date) ?>">
            </div>
            <div>
                <label>To</label>
                <input type="date" name="to_date" value="<?= sae_h($to_date) ?>">
            </div>
            <div>
                <label>Show</label>
                <select name="view">
                    <option value="all" <?= $view === 'all' ? 'selected' : '' ?>>Duplicates, over 13h, overnight</option>
                    <option value="dup" <?= $view === 'dup' ? 'selected' : '' ?>>Duplicates only</option>
                    <option value="over13" <?= $view === 'over13' ? 'selected' : '' ?>>Over 13h only</option>
                    <option value="overnight" <?= $view === 'overnight' ? 'selected' : '' ?>>Ends next day only</option>
                </select>
            </div>
            <div>
                <label>Status</label>
                <select name="status_type">
                    <option value="all" <?= $status_type === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="pending" <?= $status_type === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="finalized" <?= $status_type === 'finalized' ? 'selected' : '' ?>>Finalized</option>
                </select>
            </div>
            <div>
                <label>List</label>
                <select name="approval" onchange="this.form.submit()">
                    <option value="unapproved" <?= !$show_all ? 'selected' : '' ?>>Unapproved only</option>
                    <option value="all" <?= $show_all ? 'selected' : '' ?>>Show all</option>
                </select>
            </div>
            <button class="btn btn-go" type="submit"><i class="fas fa-search"></i> Search</button>
            <a class="btn btn-ghost" href="staff_audit_exceptions.php?org_id=<?= sae_h($org_q) ?>&amp;site_id=all&amp;from_date=<?= sae_h($last14From) ?>&amp;to_date=<?= sae_h($last14To) ?>&amp;view=<?= sae_h($view) ?>&amp;approval=<?= sae_h($approval) ?>">Last 14 days</a>
            <a class="btn btn-ghost" href="staff_audit_exceptions.php?org_id=<?= sae_h($org_q) ?>&amp;site_id=all&amp;from_date=<?= sae_h($periodFrom) ?>&amp;to_date=<?= sae_h($periodTo) ?>&amp;view=<?= sae_h($view) ?>&amp;approval=<?= sae_h($approval) ?>">This pay period</a>
            <a class="btn btn-back" href="staff_audit.php?org_id=<?= sae_h($org_q) ?>&amp;from_date=<?= sae_h($from_date) ?>&amp;to_date=<?= sae_h($to_date) ?>"><i class="fas fa-clipboard-check"></i> Staff Audit</a>
        </form>

        <div class="chips">
            <span class="chip"><?= sae_h($from_date) ?> → <?= sae_h($to_date) ?> · <?= (int)$scannedAll ?> punches scanned</span>
            <span class="chip dup"><?= (int)$nDupGroups ?> duplicate start<?= $nDupGroups === 1 ? '' : 's' ?> (<?= (int)$nDupRows ?> rows)</span>
            <span class="chip over"><?= (int)$nRow13 ?> row<?= $nRow13 === 1 ? '' : 's' ?> over 13h</span>
            <?php if ($nDay13): ?><span class="chip over"><?= (int)$nDay13 ?> more with day total over 13h</span><?php endif; ?>
            <span class="chip night"><?= (int)$nOvernight ?> end<?= $nOvernight === 1 ? 's' : '' ?> next day</span>
            <?php if ($show_all && $nApprovedShown): ?>
                <span class="chip ok"><?= (int)$nApprovedShown ?> approved on this list</span>
            <?php elseif (!$show_all && $nApprovedHidden): ?>
                <span class="chip ok"><?= (int)$nApprovedHidden ?> approved (hidden)</span>
            <?php endif; ?>
        </div>

        <?php if ($flashApproved > 0): ?>
            <div class="flash"><?= (int)$flashApproved ?> row<?= $flashApproved === 1 ? '' : 's' ?> approved. They will not appear on Unapproved only. Punches were not deleted.</div>
        <?php endif; ?>
        <?php if ($flashUnapproved > 0): ?>
            <div class="flash"><?= (int)$flashUnapproved ?> row<?= $flashUnapproved === 1 ? '' : 's' ?> unapproved. They are back on Unapproved only.</div>
        <?php endif; ?>

        <?php if ($can_dismiss): ?>
        <form method="POST" id="saeListForm">
            <div class="list-actions">
                <button type="submit" name="list_action" value="approve" class="btn btn-approve" id="saeApproveBtn" disabled onclick="return saeConfirm('approve')"><i class="fas fa-check"></i> Approve</button>
                <?php if ($show_all): ?>
                    <button type="submit" name="list_action" value="unapprove" class="btn btn-unapprove" id="saeUnapproveBtn" disabled onclick="return saeConfirm('unapprove')"><i class="fas fa-undo"></i> Unapprove</button>
                    <span class="muted">Show all includes approved rows (green tag). Unapprove puts them back on Unapproved only. Punches are never deleted.</span>
                <?php else: ?>
                    <span class="muted">Approve hides checked rows from Unapproved only. Does not delete punches. Use Show all to see or undo.</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <?php if ($can_dismiss): ?><th style="width:36px;"><input type="checkbox" id="saeMaster" title="Select all visible" onclick="saeToggleAll(this)"></th><?php endif; ?>
                    <th>Flag</th>
                    <th>Name</th>
                    <th>Restaurant</th>
                    <th>Date</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Hrs (this row)</th>
                    <th>Hrs (day)</th>
                    <th>Row id</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= $can_dismiss ? 10 : 9 ?>" class="empty"><?= $show_all ? 'No duplicate same-start rows, no hours over 13, and no overnight punches in this range (unapproved or approved).' : 'No unapproved duplicate same-start rows, no hours over 13, and no overnight (ends next day) punches in this range.' ?></td></tr>
            <?php else: foreach ($rows as $r):
                $cls = trim(($r['is_dup'] ? 'dup' : '') . ' ' . (($r['row_over'] || $r['day_over']) ? 'over' : '') . ' ' . (!empty($r['ends_next']) ? 'night' : '') . ' ' . (!empty($r['approved']) ? 'approved' : ''));
                $audit = 'staff_audit.php?org_id=' . urlencode($org_q)
                    . '&user_id=' . (int)$r['user_id']
                    . '&from_date=' . urlencode((string)$r['work_date'])
                    . '&to_date=' . urlencode((string)$r['work_date'])
                    . '&status_type=all&site_id=all';
            ?>
                <tr class="<?= sae_h($cls) ?>">
                    <?php if ($can_dismiss): ?>
                    <td><input type="checkbox" class="sae-row" name="ids[]" value="<?= (int)$r['id'] ?>" onclick="saeSyncBtn()"></td>
                    <?php endif; ?>
                    <td>
                        <?php if (!empty($r['approved'])): ?><span class="badge ok">Approved</span><?php endif; ?>
                        <?php if ($r['is_dup']): ?><span class="badge dup">Duplicate ×<?= (int)$r['dup_n'] ?></span><?php endif; ?>
                        <?php if (!empty($r['ends_next'])): ?><span class="badge night">Ends next day</span><?php endif; ?>
                        <?php if ($r['row_over']): ?><span class="badge row13">Row &gt; 13h</span><?php endif; ?>
                        <?php if ($r['day_over'] && !$r['row_over']): ?><span class="badge day13">Day total &gt; 13h</span><?php endif; ?>
                        <?php if ($r['day_over'] && $r['row_over']): ?><span class="badge day13">Day <?= sae_h(number_format($r['day_hours'], 2)) ?>h</span><?php endif; ?>
                        <?php if (!empty($r['open'])): ?><span class="badge open">Open (no out)</span><?php endif; ?>
                    </td>
                    <td><strong><?= sae_h($r['staff_name'] ?: ('User #' . $r['user_id'])) ?></strong></td>
                    <td><?= sae_h($r['site_name'] ?: '—') ?></td>
                    <td><?= sae_h($r['work_date']) ?></td>
                    <td><?= sae_h($r['start_hhmm']) ?><div class="muted"><?= sae_h(sae_time($r['clock_in'])) ?></div></td>
                    <td><?= sae_h(sae_time($r['clock_out'])) ?></td>
                    <td><?= $r['hours'] === null ? '—' : sae_h(number_format((float)$r['hours'], 2)) ?></td>
                    <td><?= sae_h(number_format((float)$r['day_hours'], 2)) ?></td>
                    <td><a class="rowlink" href="<?= sae_h($audit) ?>"><?= (int)$r['id'] ?></a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php if ($can_dismiss): ?></form><?php endif; ?>
    </div>
</div>
<script>
function saeToggleAll(master) {
    document.querySelectorAll('.sae-row').forEach(function (el) { el.checked = master.checked; });
    saeSyncBtn();
}
function saeSyncBtn() {
    var n = document.querySelectorAll('.sae-row:checked').length;
    var approve = document.getElementById('saeApproveBtn');
    var unapprove = document.getElementById('saeUnapproveBtn');
    if (approve) approve.disabled = n < 1;
    if (unapprove) unapprove.disabled = n < 1;
    var master = document.getElementById('saeMaster');
    var all = document.querySelectorAll('.sae-row');
    if (master && all.length) {
        master.checked = n === all.length;
    }
}
function saeConfirm(action) {
    var n = document.querySelectorAll('.sae-row:checked').length;
    if (n < 1) return false;
    if (action === 'unapprove') {
        return confirm('Unapprove ' + n + ' row' + (n === 1 ? '' : 's') + '? They will appear again on Unapproved only.');
    }
    return confirm('Approve ' + n + ' row' + (n === 1 ? '' : 's') + '? They will leave the Unapproved only list. Punches are NOT deleted.');
}
</script>
</body>
</html>
