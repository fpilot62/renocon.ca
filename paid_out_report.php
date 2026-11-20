<?php
/** Path: /paid_out_report.php — read-only Paid Out report from Daily Activity. */
require_once 'db.php';
require_once 'engine/menu_generator.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$current_org_id = (int)($_SESSION['org_id'] ?? 0);
$user_lvl       = (int)($_SESSION['user_level'] ?? 99);
$user_id        = (int)($_SESSION['user_id'] ?? 0);

if (!$user_id || $user_lvl > 30) {
    header("Location: kiosk.php?err=access_denied");
    exit;
}

$is_super = ($user_lvl <= 10);
$POR_ROOT = __DIR__ . '/uploads/daily_reports';

$assigned_csv = '';
if (!$is_super) {
    $u_stmt = $pdo->prepare("SELECT assigned_site_ids FROM users WHERE id = ?");
    $u_stmt->execute([$user_id]);
    $assigned_csv = $u_stmt->fetchColumn() ?? '0';
}

if ($is_super) {
    $all_sites = $pdo->query("SELECT SId, nick_name, org_id FROM sites ORDER BY nick_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT SId, nick_name, org_id FROM sites WHERE org_id = ? AND FIND_IN_SET(SId, ?) ORDER BY nick_name ASC");
    $stmt->execute([(int)$current_org_id, (string)$assigned_csv]);
    $all_sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date   = $_GET['end'] ?? date('Y-m-d');
$selected_rid = $_GET['rid'] ?? '';
$org_raw = $_GET['org_id'] ?? ($_COOKIE['sip_org_id'] ?? ($_COOKIE['dal_org_id'] ?? 'all'));
if (!$is_super) {
    $selected_org = $current_org_id;
} else {
    $selected_org = ($org_raw === '' || $org_raw === 'all') ? 0 : (int)$org_raw;
}

$orgs = [];
if ($is_super) {
    $orgs = $pdo->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$sites = [];
foreach ($all_sites as $s) {
    if ($is_super && $selected_org > 0 && (int)($s['org_id'] ?? 0) !== $selected_org) {
        continue;
    }
    $sites[] = $s;
}
$allowed_rids = array_map(static function ($s) { return (int)$s['SId']; }, $sites);
if ($selected_rid !== '' && !in_array((int)$selected_rid, $allowed_rids, true)) {
    $selected_rid = '';
}

function por_find_kind($root, $rid, $date, $kind) {
    $ym = substr((string)$date, 0, 7);
    $dir = rtrim((string)$root, '/') . '/' . (int)$rid . '/' . $ym;
    if (!is_dir($dir)) {
        return null;
    }
    $names = [];
    if ($kind === 'paidout') {
        $names[] = $date . '_paidout_report';
    } else {
        $names[] = $date . '_pos_report';
        $names[] = $date . '_weekly_pos_report';
        $names[] = $date . '_monthly_pos_report';
    }
    foreach ($names as $base) {
        foreach (glob($dir . '/' . $base . '.*') ?: [] as $p) {
            if (strtolower((string)pathinfo($p, PATHINFO_EXTENSION)) === 'json') {
                continue;
            }
            if (is_file($p)) {
                return $p;
            }
        }
    }
    return null;
}

function por_ext($path) {
    return strtolower((string)pathinfo((string)$path, PATHINFO_EXTENSION));
}

function por_is_image($ext) {
    return in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
}

function por_dl_url($rid, $date, $kind) {
    $ym = substr((string)$date, 0, 7);
    return 'daily_report_view.php?' . http_build_query([
        'action' => 'dl',
        'rid' => (int)$rid,
        'ym' => $ym,
        'kind' => $kind,
        'date' => $date,
        'period' => 'daily',
    ]);
}

function por_pos_paid_out($path) {
    if (!$path || !is_file($path)) {
        return null;
    }
    $json = preg_replace('/\.[^.]+$/', '', $path) . '.json';
    if (!is_file($json)) {
        return null;
    }
    $j = json_decode((string)@file_get_contents($json), true);
    if (!is_array($j) || !array_key_exists('paid_out_read', $j)) {
        return null;
    }
    return $j['paid_out_read'] === null || $j['paid_out_read'] === '' ? null : round((float)$j['paid_out_read'], 2);
}

$sql = "SELECT dr.report_id, dr.restaurant_id, dr.report_date, dr.pos_sales, dr.paid_out, dr.remarks, s.nick_name
    FROM daily_report dr
    INNER JOIN sites s ON dr.restaurant_id = s.SId
    WHERE dr.report_date BETWEEN ? AND ?";
$params = [$start_date, $end_date];
if (!$is_super) {
    $sql .= " AND FIND_IN_SET(dr.restaurant_id, ?)";
    $params[] = $assigned_csv;
}
if ($is_super && $selected_org > 0) {
    $sql .= " AND s.org_id = ?";
    $params[] = $selected_org;
}
if ($selected_rid !== '') {
    $sql .= " AND dr.restaurant_id = ?";
    $params[] = (int)$selected_rid;
}
$sql .= " ORDER BY s.nick_name ASC, dr.report_date DESC, dr.report_id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rows = [];
foreach ($raw as $r) {
    $rid = (int)$r['restaurant_id'];
    $date = substr((string)$r['report_date'], 0, 10);
    $rmk = (string)($r['remarks'] ?? '');
    if (stripos($rmk, 'week_split') !== false || stripos($rmk, 'mtd_split') !== false) {
        continue;
    }
    $actual = round((float)($r['paid_out'] ?? 0), 2);
    $paidFile = por_find_kind($POR_ROOT, $rid, $date, 'paidout');
    if ($actual <= 0 && !$paidFile) {
        continue;
    }
    $posFile = por_find_kind($POR_ROOT, $rid, $date, 'pos');
    $posPaid = por_pos_paid_out($posFile);
    $mismatch = ($posPaid !== null && abs($posPaid - $actual) > 0.01);
    $rows[] = [
        'name' => (string)$r['nick_name'],
        'date' => $date,
        'rid' => $rid,
        'id' => (int)$r['report_id'],
        'pos_paid' => $posPaid,
        'actual' => $actual,
        'paid_file' => $paidFile,
        'paid_ext' => $paidFile ? por_ext($paidFile) : '',
        'mismatch' => $mismatch,
    ];
}

$groups = [];
foreach ($rows as $row) {
    $ym = substr($row['date'], 0, 7);
    $key = $row['rid'] . '|' . $ym;
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'name' => $row['name'],
            'rid' => $row['rid'],
            'ym' => $ym,
            'label' => $row['name'] . ' · ' . date('M Y', strtotime($ym . '-01')),
            'pos_sum' => 0.0,
            'actual_sum' => 0.0,
            'rows' => [],
        ];
    }
    $groups[$key]['rows'][] = $row;
    if ($row['pos_paid'] !== null) {
        $groups[$key]['pos_sum'] += (float)$row['pos_paid'];
    }
    $groups[$key]['actual_sum'] += (float)$row['actual'];
}
uasort($groups, static function ($a, $b) {
    $n = strcasecmp((string)$a['name'], (string)$b['name']);
    if ($n !== 0) {
        return $n;
    }
    return strcmp((string)$b['ym'], (string)$a['ym']);
});

$byRest = [];
foreach ($groups as $g) {
    $rid = (int)$g['rid'];
    if (!isset($byRest[$rid])) {
        $byRest[$rid] = [
            'name' => $g['name'],
            'rid' => $rid,
            'pos_sum' => 0.0,
            'actual_sum' => 0.0,
            'months' => [],
        ];
    }
    $byRest[$rid]['months'][] = $g;
    $byRest[$rid]['pos_sum'] += (float)$g['pos_sum'];
    $byRest[$rid]['actual_sum'] += (float)$g['actual_sum'];
}
$pagePos = 0.0;
$pageAct = 0.0;
foreach ($byRest as $rest) {
    $pagePos += (float)$rest['pos_sum'];
    $pageAct += (float)$rest['actual_sum'];
}

function por_sum_row($cls, $label, $pos, $act) {
    $mis = (abs((float)$pos - (float)$act) > 0.01) ? ' por-mismatch' : '';
    return '<tr class="' . htmlspecialchars($cls . $mis) . '">'
        . '<td colspan="2">' . htmlspecialchars($label) . '</td>'
        . '<td>$' . number_format((float)$pos, 2) . '</td>'
        . '<td>$' . number_format((float)$act, 2) . '</td>'
        . '<td></td></tr>';
}

$dalQs = http_build_query(array_filter([
    'org_id' => $is_super ? ($selected_org > 0 ? $selected_org : 'all') : null,
    'start' => $start_date,
    'end' => $end_date,
    'rid' => $selected_rid !== '' ? $selected_rid : null,
], static function ($v) { return $v !== null && $v !== ''; }));

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Paid Out Report | SIP</title>
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; padding-top: 130px; }
        .container { padding: 25px; max-width: 1100px; margin: auto; }
        .dal-head { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 15px; }
        .tool-bar { display: block; width: 100%; background: #fff; padding: 8px; border-radius: 8px; margin-bottom: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); box-sizing: border-box; }
        .tool-section { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; padding: 8px 10px; width: 100%; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; box-sizing: border-box; }
        .tool-field { display: flex; flex-direction: column; }
        .tool-field label { font-size: 11px; font-weight: 800; color: #334155; margin-bottom: 4px; text-transform: uppercase; }
        .sheet-input { border: 1px solid #cbd5e1; padding: 6px; border-radius: 4px; box-sizing: border-box; }
        .tool-org { width: 150px; } .tool-site { width: 160px; } .tool-date { width: 132px; }
        .btn-load { background: #0f172a; color: #fff; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-weight: 800; font-size: 11px; height: 30px; text-decoration: none; display: inline-flex; align-items: center; }
        .por-back { color: #1d4ed8; font-weight: 800; font-size: 13px; text-decoration: none; }
        .por-table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 4px 6px rgba(0,0,0,0.08); }
        .por-table th { background: #0f172a; color: #fff; padding: 12px 8px; text-align: left; font-size: 11px; text-transform: uppercase; }
        .por-table td { border-bottom: 1px solid #f1f5f9; padding: 10px 8px; font-size: 13px; vertical-align: middle; }
        .por-table tr.por-mismatch td { background: #fee2e2; color: #991b1b; font-weight: 800; }
        .por-table tr.por-group td { background: #e2e8f0; color: #0f172a; font-weight: 800; font-size: 13px; border-top: 2px solid #0f172a; }
        .por-table tr.por-group.por-mismatch td { background: #fee2e2; color: #991b1b; }
        .por-table tr.por-subtotal td { background: #f1f5f9; color: #0f172a; font-weight: 800; }
        .por-table tr.por-subtotal.por-mismatch td { background: #fee2e2; color: #991b1b; }
        .por-table tr.por-rest-subtotal td { background: #cbd5e1; color: #0f172a; font-weight: 800; }
        .por-table tr.por-rest-subtotal.por-mismatch td { background: #fecaca; color: #991b1b; }
        .por-table tr.por-total td { background: #0f172a; color: #fff; font-weight: 800; }
        .por-thumb { max-width: 120px; max-height: 80px; border: 1px solid #e2e8f0; border-radius: 4px; cursor: pointer; background: #fff; display: block; }
        .file-view { color: #1d4ed8; font-weight: 800; text-decoration: none; }
        .file-missing { color: #94a3b8; font-weight: 700; }
        .por-note { font-size: 12px; color: #64748b; margin: 0 0 10px; }
        #dalPosPreview { display: none; position: fixed; top: 75px; right: 0; width: min(720px, 70vw); min-width: 360px; max-width: 100vw; height: calc(100vh - 75px); background: #fff; box-shadow: -10px 0 28px rgba(15,23,42,.22); z-index: 2147483000; flex-direction: column; }
        #dalPosPreview.open { display: flex; }
        .dal-prev-resize { position: absolute; left: 0; top: 0; bottom: 0; width: 10px; cursor: ew-resize; z-index: 9; background: linear-gradient(90deg, rgba(37,99,235,.35), transparent); }
        .dal-prev-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; padding: 10px 12px 10px 16px; background: #0f172a; color: #fff; flex: 0 0 auto; margin: 0; }
        .dal-prev-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
        .dal-prev-tools button, .dal-prev-zoom-pct { background: #334155; color: #fff; border: 0; border-radius: 4px; height: 30px; min-width: 30px; padding: 0 8px; font-weight: 800; font-size: 12px; cursor: pointer; }
        .dal-prev-body { flex: 1 1 auto; min-height: 0; overflow: auto; background: #0f172a; }
        .dal-prev-stage { transform-origin: top left; min-height: 100%; }
        .dal-prev-body img { width: 100%; display: block; background: #fff; }
        .dal-prev-body iframe { display: block; width: 100%; height: 100%; min-height: calc(100vh - 180px); border: 0; background: #fff; }
        .dal-prev-x { background: #334155; color: #fff; border: 0; border-radius: 4px; width: 30px; height: 30px; font-weight: 900; cursor: pointer; }
    </style>
</head>
<body>
<?= generateMenu($pdo); ?>
<div class="container">
    <div class="dal-head">
        <h2>Paid Out Report</h2>
        <a class="por-back" href="daily_report_view.php?<?= htmlspecialchars($dalQs) ?>">← Daily Activity</a>
    </div>
    <p class="por-note">Read-only. Pink row = POS paid-out does not match the amount saved on the day. Open Daily Activity to type a figure or upload a slip.</p>
    <div class="tool-bar">
        <form method="GET" class="tool-section">
            <?php if ($is_super): ?>
            <div class="tool-field">
                <label>Org</label>
                <select name="org_id" class="sheet-input tool-org" onchange="this.form.submit()">
                    <option value="all" <?= $selected_org === 0 ? 'selected' : '' ?>>All organizations</option>
                    <?php foreach ($orgs as $o): ?>
                        <option value="<?= (int)$o['id'] ?>" <?= $selected_org === (int)$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['org_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="tool-field">
                <label>Start</label>
                <input type="date" name="start" value="<?= htmlspecialchars($start_date) ?>" class="sheet-input tool-date">
            </div>
            <div class="tool-field">
                <label>End</label>
                <input type="date" name="end" value="<?= htmlspecialchars($end_date) ?>" class="sheet-input tool-date">
            </div>
            <div class="tool-field">
                <label>Site</label>
                <select name="rid" class="sheet-input tool-site" onchange="this.form.submit()">
                    <option value="">All Sites</option>
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= (int)$s['SId'] ?>" <?= (string)$selected_rid === (string)$s['SId'] ? 'selected' : '' ?>><?= htmlspecialchars($s['nick_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-load">Filter</button>
        </form>
    </div>
    <table class="por-table">
        <thead>
            <tr>
                <th>Restaurant</th>
                <th>Date</th>
                <th title="POS Paid Out tender (not the two-column Net Paid Out / Mastercard line)">POS Paid out amt</th>
                <th>Actual paid out amount</th>
                <th>Paid out receipt</th>
            </tr>
        </thead>
        <tbody>
        <!-- por-subtotal-v1 -->
        <?php if (!$byRest): ?>
            <tr><td colspan="5" class="file-missing">No paid-out amounts or receipts in this range.</td></tr>
        <?php endif; ?>
        <?php foreach ($byRest as $rest):
            foreach ($rest['months'] as $g):
            $gMis = abs((float)$g['pos_sum'] - (float)$g['actual_sum']) > 0.01;
            $monthLabel = date('M Y', strtotime($g['ym'] . '-01'));
        ?>
            <tr class="por-group<?= $gMis ? ' por-mismatch' : '' ?>" data-rid="<?= (int)$g['rid'] ?>" data-ym="<?= htmlspecialchars($g['ym']) ?>">
                <td colspan="2"><?= htmlspecialchars($g['label']) ?></td>
                <td>$<?= number_format((float)$g['pos_sum'], 2) ?></td>
                <td>$<?= number_format((float)$g['actual_sum'], 2) ?></td>
                <td></td>
            </tr>
        <?php foreach ($g['rows'] as $row):
            $dl = $row['paid_file'] ? por_dl_url($row['rid'], $row['date'], 'paidout') : '';
            $isImg = por_is_image($row['paid_ext']);
        ?>
            <tr class="<?= $row['mismatch'] ? 'por-mismatch' : '' ?>" data-rid="<?= (int)$row['rid'] ?>" data-date="<?= htmlspecialchars($row['date']) ?>">
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><?= htmlspecialchars(date('M d, Y', strtotime($row['date']))) ?></td>
                <td title="POS Paid Out tender line"><?= $row['pos_paid'] === null ? '<span class="file-missing">—</span>' : '$' . number_format($row['pos_paid'], 2) ?></td>
                <td>$<?= number_format($row['actual'], 2) ?></td>
                <td>
                    <?php if (!$dl): ?>
                        <span class="file-missing">—</span>
                    <?php elseif ($isImg): ?>
                        <a class="file-view por-open" href="<?= htmlspecialchars($dl) ?>" data-ext="<?= htmlspecialchars($row['paid_ext']) ?>" data-kind="paidout" data-rid="<?= (int)$row['rid'] ?>" data-date="<?= htmlspecialchars($row['date']) ?>" title="Open receipt">
                            <img class="por-thumb" src="<?= htmlspecialchars($dl) ?>" alt="Paid out receipt">
                        </a>
                    <?php else: ?>
                        <a class="file-view por-open" href="<?= htmlspecialchars($dl) ?>" data-ext="<?= htmlspecialchars($row['paid_ext'] ?: 'pdf') ?>" data-kind="paidout" data-rid="<?= (int)$row['rid'] ?>" data-date="<?= htmlspecialchars($row['date']) ?>">View PDF</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
            <?= por_sum_row('por-subtotal', 'Subtotal · ' . $g['name'] . ' · ' . $monthLabel, $g['pos_sum'], $g['actual_sum']) ?>
        <?php endforeach; ?>
            <?= por_sum_row('por-rest-subtotal', 'Subtotal · ' . $rest['name'], $rest['pos_sum'], $rest['actual_sum']) ?>
        <?php endforeach; ?>
        <?php if ($byRest): ?>
            <?= por_sum_row('por-total', 'Total', $pagePos, $pageAct) ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<aside id="dalPosPreview" aria-hidden="true">
    <div class="dal-prev-resize" id="dalPrevResize" title="Drag to widen"></div>
    <div class="dal-prev-head">
        <div>
            <strong id="dalPrevTitle">Paid out receipt</strong>
            <span id="dalPrevSub"></span>
        </div>
        <div class="dal-prev-tools">
            <button type="button" id="dalPrevZoomOut">−</button>
            <span class="dal-prev-zoom-pct" id="dalPrevZoomPct">100%</span>
            <button type="button" id="dalPrevZoomIn">+</button>
            <button type="button" id="dalPrevZoomFit">Fit</button>
            <button type="button" id="dalPrevWider">Wider</button>
            <button type="button" id="dalPrevFullW">Full</button>
            <button type="button" class="dal-prev-x" id="dalPrevClose">&times;</button>
        </div>
    </div>
    <div class="dal-prev-body" id="dalPrevBody"></div>
</aside>
<script>
(function () {
    function el(id) { return document.getElementById(id); }
    var panel = el('dalPosPreview');
    var bodyEl = el('dalPrevBody');
    var zoomPct = el('dalPrevZoomPct');
    var resizeHandle = el('dalPrevResize');
    var state = { zoom: 1, width: 0 };
    var supportsZoom = (function () {
        try {
            var t = document.createElement('div');
            t.style.zoom = '2';
            return t.style.zoom === '2' && !/firefox/i.test(navigator.userAgent || '');
        } catch (e) { return false; }
    })();
    function clamp(n, lo, hi) { return Math.max(lo, Math.min(hi, n)); }
    function setPanelWidth(px) {
        var min = 360, max = Math.max(min, window.innerWidth - 8);
        px = clamp(Math.round(px), min, max);
        state.width = px;
        panel.style.width = px + 'px';
        try { localStorage.setItem('dalPrevWidth', String(px)); } catch (e) {}
    }
    function applyZoom(z) {
        z = Math.round(clamp(z, 0.4, 4) * 100) / 100;
        state.zoom = z;
        var stage = bodyEl.querySelector('.dal-prev-stage');
        if (stage) {
            if (supportsZoom) { stage.style.zoom = String(z); stage.style.transform = ''; }
            else { stage.style.transform = 'scale(' + z + ')'; stage.style.transformOrigin = 'top left'; stage.style.width = (100 / z) + '%'; }
        }
        if (zoomPct) zoomPct.textContent = Math.round(z * 100) + '%';
        try { localStorage.setItem('dalPrevZoom', String(z)); } catch (e) {}
    }
    function closePreview() {
        panel.classList.remove('open');
        panel.setAttribute('aria-hidden', 'true');
        bodyEl.innerHTML = '';
    }
    function openPreview(a) {
        var href = a.getAttribute('href') || '';
        var ext = (a.getAttribute('data-ext') || '').toLowerCase();
        var isPdf = ext === 'pdf' || ext === '';
        el('dalPrevSub').textContent = (a.closest('tr').querySelector('td') || {}).textContent + ' · ' + (a.getAttribute('data-date') || '');
        bodyEl.innerHTML = '';
        var stage = document.createElement('div');
        stage.className = 'dal-prev-stage';
        if (isPdf) {
            var fr = document.createElement('iframe');
            fr.src = href + (href.indexOf('#') === -1 ? '#toolbar=1&zoom=page-width' : '');
            stage.appendChild(fr);
        } else {
            var img = document.createElement('img');
            img.src = href;
            img.alt = 'Paid out receipt';
            stage.appendChild(img);
        }
        bodyEl.appendChild(stage);
        var saved = 0;
        try { saved = parseInt(localStorage.getItem('dalPrevWidth') || '0', 10) || 0; } catch (e) {}
        setPanelWidth(saved || Math.min(720, Math.round(window.innerWidth * 0.55)));
        panel.classList.add('open');
        panel.setAttribute('aria-hidden', 'false');
        var z = 1;
        try { z = parseFloat(localStorage.getItem('dalPrevZoom') || '1') || 1; } catch (e) {}
        applyZoom(z);
    }
    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a.por-open') : null;
        if (!a) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey) return;
        e.preventDefault();
        openPreview(a);
    }, true);
    $('#dalPrevClose').on('click', closePreview);
    $('#dalPrevZoomOut').on('click', function () { applyZoom(state.zoom - 0.2); });
    $('#dalPrevZoomIn').on('click', function () { applyZoom(state.zoom + 0.2); });
    $('#dalPrevZoomFit').on('click', function () { applyZoom(1); });
    $('#dalPrevWider').on('click', function () { setPanelWidth((state.width || panel.offsetWidth) + 160); });
    $('#dalPrevFullW').on('click', function () { setPanelWidth(window.innerWidth - 8); });
    if (resizeHandle) {
        var drag = null;
        resizeHandle.addEventListener('mousedown', function (e) {
            e.preventDefault();
            drag = { startX: e.clientX, startW: panel.offsetWidth };
        });
        document.addEventListener('mousemove', function (e) {
            if (drag) setPanelWidth(drag.startW + (drag.startX - e.clientX));
        });
        document.addEventListener('mouseup', function () { drag = null; });
    }
    $(document).on('keydown', function (e) { if (e.key === 'Escape') closePreview(); });
})();
</script>
</body>
</html>
