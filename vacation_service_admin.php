<?php
/**
 * Path: /vacation_service_admin.php
 * Set each employee's HIRE DATE per SITE, driving the Ontario ESA vacation rate
 * (4% under 5 years of service at that site, 6% at 5+). Each site is a separate legal
 * employer, so service is per (user_id, site_id).
 *
 * AUTO-CAPTURE: the employee's first day worked at a site (their earliest attendance
 * clock_in there) is used as the start date. One click fills every missing record.
 * You can still override any date by hand.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';
require_once 'payroll_scope.php';
if (file_exists('engine/menu_generator.php')) { require_once 'engine/menu_generator.php'; }
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ps_require_payroll_admin();
$org_id = ps_scope_org_id();

$msg = '';

// ---- BULK set start date for all checked (user_id:site_id) rows -------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_set'])) {
    $bulk_date = $_POST['bulk_start_date'] ?? '';
    $pairs = $_POST['rows'] ?? [];  // values like "userId:siteId"
    if ($bulk_date !== '' && !empty($pairs)) {
        $ins = $pdo->prepare(
            "INSERT INTO employee_company_service (user_id, site_id, start_date, source)
             VALUES (?,?,?, 'manual')
             ON DUPLICATE KEY UPDATE start_date=VALUES(start_date), source='manual'"
        );
        $n = 0;
        foreach ($pairs as $p) {
            $parts = explode(':', $p);
            if (count($parts) === 2) {
                ps_assert_user($pdo, (int)$parts[0], $org_id);
                ps_assert_site($pdo, (int)$parts[1], $org_id);
                $ins->execute([(int)$parts[0], (int)$parts[1], $bulk_date]);
                $n++;
            }
        }
        $msg = "Bulk update: set start date $bulk_date on $n selected record(s).";
    } else {
        $msg = "Pick a date and tick at least one row for a bulk update.";
    }
}

// ---- AUTO-CAPTURE: first shift date per (user, site) -> start_date ------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_capture'])) {
    // earliest clock_in per (user_id, site_id) from attendance
    if ($org_id > 0) {
        $cap = $pdo->prepare(
            "SELECT a.user_id, a.site_id, MIN(DATE(a.clock_in)) AS first_day
             FROM attendance a
             INNER JOIN users u ON u.id=a.user_id AND u.org_id=?
             INNER JOIN sites s ON s.SId=a.site_id AND s.org_id=u.org_id
             WHERE a.clock_in IS NOT NULL AND a.site_id IS NOT NULL AND a.user_id IS NOT NULL
             GROUP BY a.user_id, a.site_id"
        );
        $cap->execute([$org_id]);
        $rows = $cap->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = $pdo->query(
            "SELECT user_id, site_id, MIN(DATE(clock_in)) AS first_day
             FROM attendance
             WHERE clock_in IS NOT NULL AND site_id IS NOT NULL AND user_id IS NOT NULL
             GROUP BY user_id, site_id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    $ins = $pdo->prepare(
        "INSERT INTO employee_company_service (user_id, site_id, start_date, source)
         VALUES (?,?,?, 'auto')
         ON DUPLICATE KEY UPDATE
            start_date = IF(source='manual', start_date, VALUES(start_date))"
        // never overwrite a manually-set date; only fill/refresh auto ones
    );
    $count = 0;
    foreach ($rows as $r) {
        if (empty($r['first_day'])) continue;
        $ins->execute([(int)$r['user_id'], (int)$r['site_id'], $r['first_day']]);
        $count++;
    }
    $msg = "Auto-capture complete: $count employee/site start dates set from first shift worked. (Manually-set dates were left untouched.)";
}

// ---- manual save (insert/update one service record) --------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_service'])) {
    $uid  = (int)($_POST['user_id'] ?? 0);
    $sid  = (int)($_POST['site_id'] ?? 0);
    $sd   = $_POST['start_date'] ?? '';
    $vp   = (float)($_POST['vac_percent'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));
    if ($uid > 0 && $sid > 0 && $sd !== '') {
        ps_assert_user($pdo, $uid, $org_id);
        ps_assert_site($pdo, $sid, $org_id);
        $stmt = $pdo->prepare(
            "INSERT INTO employee_company_service (user_id, site_id, start_date, vac_percent, source, note)
             VALUES (?,?,?,?, 'manual', ?)
             ON DUPLICATE KEY UPDATE start_date=VALUES(start_date), vac_percent=VALUES(vac_percent), source='manual', note=VALUES(note)"
        );
        $stmt->execute([$uid, $sid, $sd, $vp, $note]);
        $msg = "Saved service record (manual).";
    } else {
        $msg = "Please provide employee, site, and a start date.";
    }
}

// ---- ESA rate helper ---------------------------------------------------------
function esa_rate(string $start_date, float $override = 0.0): array {
    if ($override > 0) return [$override, 'manual override'];
    $start = strtotime($start_date);
    if (!$start) return [4.0, 'no date'];
    $years = (time() - $start) / (365.25 * 86400);
    return $years >= 5.0 ? [6.0, sprintf('%.1f yrs', $years)] : [4.0, sprintf('%.1f yrs', $years)];
}

// ---- lookups -----------------------------------------------------------------
$users = ps_list_users($pdo, $org_id);
if ($org_id > 0) {
    $sq = $pdo->prepare("SELECT Sid, nick_name, corp_legal_name FROM sites WHERE org_id=? ORDER BY nick_name ASC");
    $sq->execute([$org_id]);
    $sites = $sq->fetchAll(PDO::FETCH_ASSOC);
    $wq = $pdo->prepare(
        "SELECT DISTINCT a.user_id, a.site_id FROM attendance a
         INNER JOIN users u ON u.id=a.user_id AND u.org_id=?
         INNER JOIN sites s ON s.SId=a.site_id AND s.org_id=u.org_id
         WHERE a.site_id IS NOT NULL AND a.user_id IS NOT NULL"
    );
    $wq->execute([$org_id]);
    $worked = $wq->fetchAll(PDO::FETCH_ASSOC);
    $svcQ = $pdo->prepare(
        "SELECT ecs.* FROM employee_company_service ecs
         INNER JOIN users u ON u.id=ecs.user_id AND u.org_id=?
         INNER JOIN sites s ON s.SId=ecs.site_id AND s.org_id=u.org_id"
    );
    $svcQ->execute([$org_id]);
    $svcRows = $svcQ->fetchAll(PDO::FETCH_ASSOC);
} else {
    if (!function_exists('sip_org_site_fence')) { require_once __DIR__ . '/engine/site_status_lib.php'; }
$__fence = sip_org_site_fence($pdo, 'sip_org_id');
$is_super = $__fence['is_super']; $selected_org = $__fence['selected_org']; $orgs = $__fence['orgs']; $sites = $__fence['sites'];
    foreach ($sites as &$__s) {
        if (!isset($__s['Sid'])) { $__s['Sid'] = $__s['SId'] ?? $__s['id'] ?? 0; }
        if (!isset($__s['corp_legal_name'])) { $__s['corp_legal_name'] = ''; }
    }
    unset($__s);

    $worked = $pdo->query(
        "SELECT DISTINCT a.user_id, a.site_id FROM attendance a WHERE a.site_id IS NOT NULL AND a.user_id IS NOT NULL"
    )->fetchAll(PDO::FETCH_ASSOC);
    $svcRows = $pdo->query("SELECT * FROM employee_company_service")->fetchAll(PDO::FETCH_ASSOC);
}

// existing service records keyed user|site
$svc = [];
foreach ($svcRows as $r) {
    $svc[$r['user_id'] . '|' . $r['site_id']] = $r;
}
$user_name = []; foreach ($users as $u) { $user_name[$u['id']] = $u['Name']; }
$site_name = []; foreach ($sites as $s) { $site_name[$s['Sid']] = $s['nick_name'] . ($s['corp_legal_name'] ? ' ('.$s['corp_legal_name'].')' : ''); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Vacation Service Dates</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family:'Inter',system-ui,sans-serif; background:#f1f5f9; padding:30px 20px 60px; color:#1e293b; font-size:13px; }
        .wrap { max-width:1080px; margin:0 auto; }
        h2 { font-weight:800; color:#0f172a; font-size:20px; }
        .sub { color:#64748b; font-size:12px; margin-bottom:20px; }
        .card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:20px; margin-bottom:22px; box-shadow:0 2px 6px rgba(0,0,0,.03); }
        .card h4 { font-size:12px; text-transform:uppercase; color:#475569; letter-spacing:.5px; margin:0 0 14px; }
        label { font-weight:700; font-size:10px; text-transform:uppercase; color:#64748b; }
        .form-control-sm { font-size:13px; font-weight:600; }
        table { width:100%; border-collapse:collapse; }
        th { background:#475569; color:#fff; font-size:10px; text-transform:uppercase; padding:9px; text-align:left; }
        td { border-bottom:1px solid #e2e8f0; padding:9px; }
        .rate-6 { color:#16a34a; font-weight:800; }
        .rate-4 { color:#2563eb; font-weight:800; }
        .missing { background:#fef2f2; }
        .badge-set { background:#dcfce7; color:#15803d; font-weight:800; font-size:9px; padding:2px 7px; border-radius:8px; }
        .badge-miss{ background:#fee2e2; color:#b91c1c; font-weight:800; font-size:9px; padding:2px 7px; border-radius:8px; }
        .badge-auto{ background:#dbeafe; color:#1d4ed8; font-weight:700; font-size:8px; padding:1px 5px; border-radius:6px; margin-left:4px; }
        .badge-man { background:#fef9c3; color:#854d0e; font-weight:700; font-size:8px; padding:1px 5px; border-radius:6px; margin-left:4px; }
        .btn-cap { background:#0f172a; color:#fff; font-weight:800; font-size:12px; border:none; padding:10px 18px; border-radius:8px; }
        .btn-cap:hover { background:#1e293b; color:#fff; }
        .bulk-bar { display:flex; align-items:center; gap:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; margin-bottom:14px; }
        th.sortable:hover { background:#334155; }
        .arr { font-size:10px; }
    </style>
</head>
<body>
<?= function_exists('generateMenu') ? generateMenu($pdo) : '' ?>
<div class="wrap">
    <h2><i class="fa fa-umbrella-beach text-primary"></i> Vacation Service Dates (per site)</h2>
    <div class="sub">Ontario ESA: 4% vacation pay under 5 years at a site, 6% at 5+ years. Each site is a separate legal employer, so each employee has a start date per site. Use auto-capture to set start dates from each person's first shift at a site.</div>

    <?php if ($msg): ?><div class="alert alert-info py-2"><?=htmlspecialchars($msg)?></div><?php endif; ?>

    <div class="card" style="border-left:4px solid #0f172a;">
        <h4>One-click auto-capture</h4>
        <div class="d-flex align-items-center" style="gap:16px;">
            <form method="POST" action="" class="mb-0">
                <input type="hidden" name="auto_capture" value="1">
                <button type="submit" class="btn-cap"><i class="fa fa-bolt"></i> Auto-capture start dates from first shift</button>
            </form>
            <div class="text-muted" style="font-size:12px;">
                Sets each employee's start date at each site to their earliest shift there. Safe to run anytime — it never overwrites a date you set by hand.
            </div>
        </div>
    </div>

    <div class="card">
        <h4>Add / update a single record (manual)</h4>
        <form method="POST" action="" class="form-row align-items-end">
            <input type="hidden" name="save_service" value="1">
            <div class="col-md-3 mb-2">
                <label>Employee</label>
                <select name="user_id" class="form-control form-control-sm" required>
                    <option value="">— select —</option>
                    <?php foreach ($users as $u): ?><option value="<?=$u['id']?>"><?=htmlspecialchars($u['Name'])?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 mb-2">
                <label>Site (employer)</label>
                <?php if (!empty($is_super)): ?>
                <select name="org_id" onchange="this.form.submit()">
                    <option value="all" <?= (int)($selected_org ?? 0) === 0 ? 'selected' : '' ?>>All organizations</option>
                    <?php foreach (($orgs ?? []) as $o): ?>
                        <option value="<?= (int)$o['id'] ?>" <?= (int)($selected_org ?? 0) === (int)$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$o['org_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <select name="site_id" class="form-control form-control-sm" required>
                    <option value="">— select —</option>
                    <?php foreach ($sites as $s): ?><option value="<?=$s['Sid']?>"><?=htmlspecialchars($s['nick_name'])?><?= $s['corp_legal_name'] ? ' — '.htmlspecialchars($s['corp_legal_name']) : '' ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 mb-2">
                <label>Start date (this site)</label>
                <input type="date" name="start_date" class="form-control form-control-sm" required>
            </div>
            <div class="col-md-2 mb-2">
                <label>Override % (0=auto)</label>
                <input type="number" step="0.01" min="0" name="vac_percent" value="0" class="form-control form-control-sm">
            </div>
            <div class="col-md-2 mb-2">
                <button type="submit" class="btn btn-sm btn-primary btn-block font-weight-bold">Save</button>
            </div>
        </form>
        <div class="text-muted small mt-1">Override is for special cases (e.g. a contract above the ESA minimum). Leave 0 to let the system pick 4% or 6% automatically.</div>
    </div>

    <div class="card">
        <h4>Employees who have worked, by site — service status</h4>

        <!-- bulk set bar -->
        <form method="POST" action="" id="bulkForm">
            <input type="hidden" name="bulk_set" value="1">
            <div class="bulk-bar">
                <label style="margin:0;">Bulk set start date for checked rows:</label>
                <input type="date" name="bulk_start_date" class="form-control form-control-sm" style="width:auto;">
                <button type="submit" class="btn btn-sm btn-dark font-weight-bold" onclick="return confirmBulk()">Apply to checked</button>
                <span id="checkedCount" class="text-muted" style="font-size:11px;">0 selected</span>
            </div>

            <table id="svcTable">
                <thead><tr>
                    <th style="width:34px;"><input type="checkbox" id="checkAll" title="Select all"></th>
                    <th class="sortable" data-col="0">Employee <span class="arr"></span></th>
                    <th class="sortable" data-col="1">Site (employer) <span class="arr"></span></th>
                    <th class="sortable" data-col="2">Start date <span class="arr"></span></th>
                    <th class="sortable" data-col="3">Years <span class="arr"></span></th>
                    <th class="sortable" data-col="4">Current rate <span class="arr"></span></th>
                    <th class="sortable" data-col="5">Status <span class="arr"></span></th>
                </tr></thead>
                <tbody>
                <?php if (empty($worked)): ?>
                    <tr><td colspan="7" class="text-muted py-4">No attendance records found to list employee/site pairs.</td></tr>
                <?php else: foreach ($worked as $w):
                    $key = $w['user_id'] . '|' . $w['site_id'];
                    $rec = $svc[$key] ?? null;
                    $rate = 0; $detail = '';
                    if ($rec) { [$rate,$detail] = esa_rate($rec['start_date'], (float)$rec['vac_percent']); }
                    $years_val = $rec ? (float)((time() - strtotime($rec['start_date'])) / (365.25*86400)) : -1;
                    $pair = (int)$w['user_id'] . ':' . (int)$w['site_id'];
                ?>
                    <tr class="<?=$rec ? '' : 'missing'?>">
                        <td><input type="checkbox" class="rowchk" name="rows[]" value="<?=$pair?>"></td>
                        <td data-sort="<?=htmlspecialchars($user_name[$w['user_id']] ?? '')?>"><?=htmlspecialchars($user_name[$w['user_id']] ?? ('User #'.$w['user_id']))?></td>
                        <td data-sort="<?=htmlspecialchars($site_name[$w['site_id']] ?? '')?>"><?=htmlspecialchars($site_name[$w['site_id']] ?? ('Site #'.$w['site_id']))?></td>
                        <td data-sort="<?=$rec ? htmlspecialchars($rec['start_date']) : '0000'?>">
                            <?php if ($rec): ?>
                                <?=htmlspecialchars($rec['start_date'])?>
                                <?php if (($rec['source'] ?? '')==='auto'): ?><span class="badge-auto">AUTO</span><?php else: ?><span class="badge-man">MANUAL</span><?php endif; ?>
                            <?php else: ?><span class="text-danger">— not set —</span><?php endif; ?>
                        </td>
                        <td data-sort="<?=$years_val?>"><?= $rec ? htmlspecialchars($detail) : '' ?></td>
                        <td data-sort="<?=$rate?>"><?= $rec ? '<span class="rate-'.(int)$rate.'">'.number_format($rate,1).'%</span>' : '' ?></td>
                        <td data-sort="<?=$rec ? '1' : '0'?>"><?= $rec ? '<span class="badge-set">SET</span>' : '<span class="badge-miss">NEEDS DATE</span>' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </form>
    </div>
</div>

<script>
    // ---- checkbox selection ----
    const checkAll = document.getElementById('checkAll');
    const rowChks = () => Array.from(document.querySelectorAll('.rowchk'));
    function updateCount(){ document.getElementById('checkedCount').textContent = rowChks().filter(c=>c.checked).length + ' selected'; }
    if (checkAll) checkAll.addEventListener('change', ()=>{ rowChks().forEach(c=>{ if(c.closest('tr').style.display!=='none') c.checked = checkAll.checked; }); updateCount(); });
    rowChks().forEach(c=>c.addEventListener('change', updateCount));
    function confirmBulk(){
        const n = rowChks().filter(c=>c.checked).length;
        const d = document.querySelector('input[name=bulk_start_date]').value;
        if (n===0){ alert('Tick at least one row first.'); return false; }
        if (!d){ alert('Pick a date first.'); return false; }
        return confirm('Set start date '+d+' on '+n+' selected record(s)? This overwrites existing dates (including manual ones).');
    }

    // ---- click-to-sort headers ----
    let sortState = {col:-1, dir:1};
    document.querySelectorAll('#svcTable th.sortable').forEach(th=>{
        th.style.cursor='pointer';
        th.addEventListener('click', ()=>{
            const col = +th.dataset.col;
            sortState.dir = (sortState.col===col) ? -sortState.dir : 1;
            sortState.col = col;
            const tbody = document.querySelector('#svcTable tbody');
            const rows = Array.from(tbody.querySelectorAll('tr')).filter(r=>r.querySelector('td[data-sort]'));
            rows.sort((a,b)=>{
                let av = a.children[col+1].dataset.sort ?? a.children[col+1].textContent;
                let bv = b.children[col+1].dataset.sort ?? b.children[col+1].textContent;
                // real date (YYYY-MM-DD)? compare as dates (avoid parseFloat eating the year only)
                const dateRe = /^\d{4}-\d{2}-\d{2}$/;
                if (dateRe.test(av) && dateRe.test(bv)) return (av < bv ? -1 : av > bv ? 1 : 0) * sortState.dir;
                if (dateRe.test(av) && !dateRe.test(bv)) return -1 * sortState.dir; // dated before "not set"
                if (!dateRe.test(av) && dateRe.test(bv)) return  1 * sortState.dir;
                const an = parseFloat(av), bn = parseFloat(bv);
                if (!isNaN(an) && !isNaN(bn)) return (an-bn)*sortState.dir;
                return String(av).localeCompare(String(bv))*sortState.dir;
            });
            rows.forEach(r=>tbody.appendChild(r));
            document.querySelectorAll('#svcTable .arr').forEach(s=>s.textContent='');
            th.querySelector('.arr').textContent = sortState.dir===1 ? ' ▲' : ' ▼';
        });
    });
    updateCount();
</script>
</body>
</html>
