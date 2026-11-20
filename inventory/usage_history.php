<?php
/** Path: /inventory/usage_history.php */
require_once "../db.php";
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (isset($_GET['rid'])) {
    $_SESSION['active_rid'] = (int)$_GET['rid'];
}
$rid = (int)($_SESSION['active_rid'] ?? 6);

$view_mode  = ($_GET['view_mode'] ?? 'summary') === 'daily' ? 'daily' : 'summary';
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date   = $_GET['end_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = date('Y-m-d');
}

$dayStmt = $pdo->prepare("SELECT DISTINCT usage_date FROM Gem_inventory_usage WHERE site_id = ? ORDER BY usage_date ASC");
$dayStmt->execute([$rid]);
$days_with_data = $dayStmt->fetchAll(PDO::FETCH_COLUMN);
$days_with_data = array_values(array_filter($days_with_data));

$anchor = $end_date;
$prev_day = null;
$next_day = null;
$anchor_index = null;
foreach ($days_with_data as $i => $d) {
    if ($d < $anchor) {
        $prev_day = $d;
    }
    if ($d > $anchor && $next_day === null) {
        $next_day = $d;
    }
    if ($d === $anchor) {
        $anchor_index = $i;
    }
}

if ($view_mode === 'summary') {
    $sql = "SELECT COALESCE(NULLIF(i.clearview_name,''), i.Name) as ItemName, i.Unit, SUM(u.sold_qty) as total_sold, SUM(u.waste_qty) as total_waste, SUM(u.actual_qty_pcs) as total_consumed
            FROM Gem_inventory_usage u
            JOIN Gem_inventory_items i ON u.item_id = i.Id
            WHERE u.site_id = ? AND u.usage_date BETWEEN ? AND ?
            GROUP BY u.item_id ORDER BY i.Name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$rid, $start_date, $end_date]);
} else {
    $sql = "SELECT u.*, COALESCE(NULLIF(i.clearview_name,''), i.Name) as ItemName, i.Unit, u.sold_qty as total_sold, u.waste_qty as total_waste, u.actual_qty_pcs as total_consumed
            FROM Gem_inventory_usage u
            JOIN Gem_inventory_items i ON u.item_id = i.Id
            WHERE u.site_id = ? AND u.usage_date = ?
            ORDER BY i.Name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$rid, $end_date]);
}
$report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$site_name = '';
try {
    $sn = $pdo->prepare("SELECT nick_name FROM sites WHERE SId = ?");
    $sn->execute([$rid]);
    $site_name = (string)$sn->fetchColumn();
} catch (Exception $e) {
    $site_name = '';
}

function uh_day_url($rid, $day, $view_mode) {
    return 'usage_history.php?' . http_build_query([
        'rid' => $rid,
        'view_mode' => $view_mode,
        'start_date' => $day,
        'end_date' => $day,
    ]);
}

require_once "../engine/menu_generator.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><title>Usage History | SIP</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .day-skip { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin: 6px 0 18px; }
        .day-skip .btn-day {
            min-width: 168px; font-weight: 800; border-radius: 10px; padding: 10px 14px;
            border: 2px solid #cbd5e1; background: #fff; color: #0f172a;
        }
        .day-skip .btn-day:hover { background:#e2e8f0; text-decoration:none; color:#0f172a; }
        .day-skip .btn-day.is-off { opacity:.45; pointer-events:none; }
        .day-skip .now {
            font-weight: 800; color:#0f172a; min-width: 210px; text-align:center; line-height:1.25;
        }
        .day-skip .now small { display:block; font-weight:700; color:#64748b; }
        .day-hint { color:#64748b; font-size:13px; font-weight:600; margin-bottom:12px; }
        th.sortable { cursor:pointer; user-select:none; }
        th.sortable::after { content:' \2195'; opacity:.35; font-size:11px; }
        th.sort-asc::after { content:' \25B2'; opacity:.85; }
        th.sort-desc::after { content:' \25BC'; opacity:.85; }
    </style>
</head>
<body style="padding-top: 160px;">
    <?= generateMenu($pdo); ?>
    <?php include "inventory_header.php"; ?>

    <div class="container-fluid px-5">
        <div class="card shadow-sm p-4">
            <h2 class="font-weight-bold">Consumption History</h2>
            <p class="day-hint">Use <strong>▼</strong> / <strong>▲</strong> to jump to the previous or next day that already has usage. Empty days are skipped.</p>

            <div class="day-skip">
                <?php if ($prev_day): ?>
                    <a class="btn-day" href="<?= htmlspecialchars(uh_day_url($rid, $prev_day, $view_mode)) ?>">
                        ▼ <?= date('M j, Y', strtotime($prev_day)) ?>
                    </a>
                <?php else: ?>
                    <span class="btn-day is-off">▼ No earlier day</span>
                <?php endif; ?>

                <div class="now">
                    <?= date('D, M j, Y', strtotime($anchor)) ?>
                    <small>
                        <?php if ($anchor_index !== null): ?>
                            Day <?= $anchor_index + 1 ?> of <?= count($days_with_data) ?> with usage<?= $site_name !== '' ? ' · ' . htmlspecialchars($site_name) : '' ?>
                        <?php elseif ($days_with_data): ?>
                            No usage on this day · <?= count($days_with_data) ?> other day<?= count($days_with_data) === 1 ? '' : 's' ?> have numbers
                        <?php else: ?>
                            No usage days for this restaurant yet
                        <?php endif; ?>
                    </small>
                </div>

                <?php if ($next_day): ?>
                    <a class="btn-day" href="<?= htmlspecialchars(uh_day_url($rid, $next_day, $view_mode)) ?>">
                        ▲ <?= date('M j, Y', strtotime($next_day)) ?>
                    </a>
                <?php else: ?>
                    <span class="btn-day is-off">▲ No later day</span>
                <?php endif; ?>
            </div>

            <form method="GET" class="row mb-4 align-items-end">
                <input type="hidden" name="rid" value="<?= $rid ?>">
                <div class="col-md-3">
                    <label class="small font-weight-bold">Mode</label>
                    <select name="view_mode" class="form-control">
                        <option value="summary" <?= $view_mode == 'summary' ? 'selected' : '' ?>>Summary (Date Range)</option>
                        <option value="daily" <?= $view_mode == 'daily' ? 'selected' : '' ?>>Daily (Single Date)</option>
                    </select>
                </div>
                <?php if($view_mode == 'summary'): ?>
                <div class="col-md-3">
                    <label class="small font-weight-bold">From</label>
                    <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
                </div>
                <?php endif; ?>
                <div class="col-md-3">
                    <label class="small font-weight-bold">To / Single Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-block">Filter</button>
                </div>
            </form>

            <table class="table table-hover" id="historyTable">
                <thead class="thead-light">
                    <tr>
                        <th>Item Name</th>
                        <th class="text-center text-success">Sold</th>
                        <th class="text-center text-danger">Waste</th>
                        <th class="text-center">Total Consumed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$report_data): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">
                            No usage on <?= htmlspecialchars(date('M j, Y', strtotime($anchor))) ?>.
                            <?php if ($next_day || $prev_day): ?>
                                Use ▼ or ▲ to open a day that has numbers.
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach($report_data as $row): ?>
                    <tr>
                        <td><strong><?= strtoupper(htmlspecialchars($row['ItemName'])) ?></strong></td>
                        <td class="text-center"><?= number_format($row['total_sold'], 2) ?></td>
                        <td class="text-center"><?= number_format($row['total_waste'], 2) ?></td>
                        <td class="text-center font-weight-bold"><?= number_format($row['total_consumed'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<script src="../engine/table_sort.js?v=20260917b"></script>
<script>makeTableSortable('historyTable');</script>
</body>
</html>
