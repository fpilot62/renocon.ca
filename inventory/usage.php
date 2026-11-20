<?php
/** Path: /inventory/usage.php
 * Daily Consumption Log — shows saved sold/waste for the selected site + date.
 * Production PDFs uploaded on Daily Activity Log are read into this page.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/engine/inventory_hot.php';
inv_ensure_hot_column($pdo);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_lvl = (int)($_SESSION['user_level'] ?? 40);
require_once __DIR__ . '/_org_sites.php';

$dateStats = [];
try {
    $dq = $pdo->prepare(
        'SELECT usage_date, COUNT(*) AS line_count, SUM(sold_qty) AS sold
         FROM Gem_inventory_usage
         WHERE site_id = ?
         GROUP BY usage_date
         HAVING COUNT(*) > 0
         ORDER BY usage_date DESC'
    );
    $dq->execute([$rid]);
    foreach ($dq->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dateStats[(string)$row['usage_date']] = $row;
    }
} catch (Throwable $e) {
    $dateStats = [];
}
$entryDates = array_keys($dateStats);
$askedDate = trim((string)($_GET['usage_date'] ?? $_POST['usage_date'] ?? ''));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $askedDate) && isset($dateStats[$askedDate])) {
    $usage_date = $askedDate;
} elseif ($entryDates) {
    $usage_date = $entryDates[0];
} else {
    $usage_date = date('Y-m-d');
}

$stmt = $pdo->prepare("SELECT Id, name, clearview_name, Unit, pcs_in_box, is_hot FROM Gem_inventory_items WHERE (Restaurant_Id = 0 OR Restaurant_Id = ?) AND IsActive = 1 AND Id NOT IN (2,6,12) ORDER BY is_hot DESC, COALESCE(NULLIF(clearview_name,''), name) ASC");
$stmt->execute([$rid]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$saved = [];
$load = $pdo->prepare(
    "SELECT item_id, SUM(sold_qty) AS sold, SUM(waste_qty) AS waste
     FROM Gem_inventory_usage
     WHERE site_id = ? AND usage_date = ?
     GROUP BY item_id"
);
$load->execute([$rid, $usage_date]);
foreach ($load->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $saved[(int)$row['item_id']] = $row;
}

$items = array_values(array_filter($items, static function ($item) use ($saved) {
    $id = (int)$item['Id'];
    if ($id >= 41 && !isset($saved[$id])) {
        return false;
    }
    return true;
}));

require_once dirname(__DIR__) . '/engine/production_usage_import.php';
$prod_pdf = pui_find_production_pdf(dirname(__DIR__) . '/uploads/daily_reports', $rid, $usage_date);
$just_saved = isset($_GET['saved']);
$imported_n = isset($_GET['imported']) ? (int)$_GET['imported'] : 0;

$recentDates = array_slice(array_values($dateStats), 0, 3);

require_once "../engine/menu_generator.php";
$GLOBALS['sip_page_title_html'] = '<div class="sip-page-title-bar" style="max-width:1550px;margin:16px auto 0;padding:0 16px;position:relative;z-index:21;">'
    . '<h1 style="font-weight:900;color:#1e293b;font-size:28px;margin:0;">Daily Consumption Log</h1>'
    . '</div>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Usage Entry | SIP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { background:#f1f5f9; font-family: 'Inter', sans-serif; padding-top: 130px; }
        .page-card { background: white; border-radius: 15px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); border: none; margin-bottom: 80px; }
        .search-wrapper { position: relative; margin-bottom: 20px; z-index: 10; }
        #itemSearch { height: 50px; border-radius: 12px; border: 2px solid #ef4444; padding-left: 45px; font-weight: 600; }
        .qty-input { width: 120px; border: 2px solid #cbd5e1; border-radius: 8px; text-align: center; font-weight: 800; color: #b91c1c; height: 38px; }
        .qty-input:focus { border-color: #ef4444; background: #fef2f2; outline: none; }
        .btn-save-fixed { position: fixed; bottom: 30px; right: 50px; padding: 15px 40px; border-radius: 50px; font-weight: 800; z-index: 1050; background: #ef4444; color: white; border: none; box-shadow: 0 10px 20px rgba(239, 68, 68, 0.3); transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 0.5px; }
        .btn-save-fixed:hover { background: #dc2626; transform: translateY(-3px); }

        .inventory-nav-bar { background: #1e293b; padding: 12px 25px; border-radius: 12px; display: flex; align-items: center; justify-content: space-between; margin-bottom: 30px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .nav-actions { display: flex; gap: 10px; align-items: center; }
        .nav-links-right { display: flex; gap: 20px; }
        .nav-links-right a { color: #94a3b8; font-size: 13px; font-weight: 700; text-decoration: none; transition: 0.3s; }
        .nav-links-right a:hover { color: #fff; }
        .nav-links-right a.active { color: #38bdf8; border-bottom: 2px solid #38bdf8; padding-bottom: 4px; }
        .site-dropdown { background: #fff; border: none; border-radius: 6px; padding: 6px 12px; font-weight: 800; font-size: 13px; color: #1e293b; cursor: pointer; }
        .btn-nav { font-weight: 800; font-size: 11px; text-transform: uppercase; border-radius: 6px; padding: 8px 15px; border: none; display: flex; align-items: center; gap: 8px; text-decoration: none; }
        .btn-delivery { background: #0891b2; color: #fff; }
        .btn-count { background: #f59e0b; color: #1e293b; }
        .usage-date-inline { display:flex; align-items:center; gap:8px; margin:0; color:#e2e8f0; font-size:11px; font-weight:800; text-transform:uppercase; }
        .usage-date-inline input[type="date"], .usage-date-inline select { width:170px; height:34px; border:none; border-radius:6px; font-weight:800; padding:4px 8px; }
        .recent-dates { background:#fff; border:1px solid #cbd5e1; border-radius:10px; padding:12px 14px; margin-bottom:16px; }
        .recent-dates h6 { font-weight:800; font-size:12px; text-transform:uppercase; color:#334155; margin:0 0 10px; }
        .recent-dates table { width:100%; font-size:13px; margin:0; }
        .recent-dates td, .recent-dates th { padding:6px 8px; border-bottom:1px solid #e2e8f0; }
        .recent-dates tr.active { background:#ecfdf5; }
        .recent-dates .now { font-size:11px; font-weight:800; color:#166534; }
        .btn-use-date { background:#0f766e; color:#fff; padding:4px 10px; border-radius:4px; font-size:11px; font-weight:800; text-decoration:none; }
        .usage-note { background:#ecfdf5; border:1px solid #6ee7b7; color:#065f46; border-radius:10px; padding:10px 14px; font-weight:700; font-size:13px; margin-bottom:16px; }
        .usage-empty { background:#fff7ed; border:1px solid #fdba74; color:#9a3412; border-radius:10px; padding:10px 14px; font-weight:700; font-size:13px; margin-bottom:16px; }
        th.sortable { cursor:pointer; user-select:none; white-space:nowrap; }
        th.sortable:hover { color:#0f172a; }
        th.sortable::after { content:' \2195'; opacity:.35; font-size:11px; }
        th.sort-asc::after { content:' \25B2'; opacity:.85; }
        th.sort-desc::after { content:' \25BC'; opacity:.85; }
    </style>
</head>
<body>
    <?= generateMenu($pdo); ?>

    <div class="container-fluid px-5">

        <div class="inventory-nav-bar">
            <div class="nav-actions">
                <?php if (!empty($is_super) && !empty($orgs)): ?>
                <select class="site-dropdown" onchange="if(window.sipMenuSetOrg){sipMenuSetOrg(this.value);}else{location.href='?org_id='+this.value}" title="Organization">
                    <option value="all" <?= (int)$selected_org === 0 ? 'selected' : '' ?>>All orgs</option>
                    <?php foreach ($orgs as $o): ?>
                        <option value="<?= (int)$o['id'] ?>" <?= (int)$selected_org === (int)$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$o['org_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <?= inv_site_dropdown_html() ?>
                <label class="usage-date-inline">Date
                    <select id="usageDatePicker" form="usageForm" name="usage_date" title="Only days with a saved usage entry">
                        <?php if (!$entryDates): ?>
                        <option value="<?= htmlspecialchars($usage_date) ?>"><?= htmlspecialchars($usage_date) ?> — no entries yet</option>
                        <?php else: ?>
                        <?php foreach ($entryDates as $ed): ?>
                        <option value="<?= htmlspecialchars($ed) ?>" <?= $ed === $usage_date ? 'selected' : '' ?>><?= htmlspecialchars(date('M d, Y', strtotime($ed))) ?></option>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </label>

                <a href="index.php?rid=<?= $rid ?>" class="btn-nav btn-delivery">
                    <i class="fas fa-truck-loading"></i> LOG NEW DELIVERY
                </a>
                <a href="physical_count.php?rid=<?= $rid ?>" class="btn-nav btn-count">
                    <i class="fas fa-clipboard-check"></i> PHYSICAL COUNT
                </a>
            </div>

            <div class="nav-links-right">
                <a href="usage_history.php?rid=<?= $rid ?>&end_date=<?= htmlspecialchars($usage_date) ?>&start_date=<?= htmlspecialchars($usage_date) ?>&view_mode=daily" class="btn btn-sm btn-outline-light font-weight-bold mr-3" style="text-transform:uppercase;"><i class="fas fa-history"></i> View Older Logs</a>
                <?php
                    $current_page = basename($_SERVER['PHP_SELF']);
                    $nav_items = [
                        'inventory_report.php' => 'Report',
                        'physical_count.php'   => 'Count',
                        'usage.php'            => 'Usage',
                        'audit.php'            => 'Audit',
                        'waste.php'            => 'Waste',
                        'orders.php'           => 'Orders'
                    ];
                    foreach($nav_items as $url => $label):
                        $active = ($current_page == $url) ? 'active' : '';
                ?>
                    <a href="<?= $url ?>?rid=<?= $rid ?>" class="<?= $active ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (inv_all_sites()): ?>
            <?= inv_pick_site_banner("Pick a restaurant to enter usage. All Sites is a filter only.") ?>
        <?php endif; ?>

        <?php if ($recentDates): ?>
        <div class="recent-dates">
            <h6>Last 3 dates with a usage entry — most recent on top.</h6>
            <table>
                <tr>
                    <th>Date</th>
                    <th>Items</th>
                    <th>Pieces sold</th>
                    <th></th>
                </tr>
                <?php foreach ($recentDates as $rd):
                    $d = (string)$rd['usage_date'];
                    $isOn = ($d === $usage_date);
                ?>
                <tr class="<?= $isOn ? 'active' : '' ?>">
                    <td><?= htmlspecialchars($d) ?></td>
                    <td><?= (int)($rd['line_count'] ?? 0) ?></td>
                    <td><?= number_format((float)$rd['sold'], 2) ?></td>
                    <td>
                        <a class="btn-use-date" href="usage.php?rid=<?= (int)$rid ?>&amp;usage_date=<?= htmlspecialchars($d) ?><?= !empty($selected_org) ? '&amp;org_id='.(int)$selected_org : '' ?>">Use this date</a>
                        <?php if ($isOn): ?><span class="now">← this day</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <div class="search-wrapper">
            <i class="fas fa-search" style="position:absolute; left:20px; top:18px; color:#ef4444;"></i>
            <input type="text" id="itemSearch" class="form-control shadow-sm" placeholder="Search items for consumption (Sales & Waste)...">
        </div>

        <div class="page-card shadow-sm">

            <?php if ($just_saved): ?>
                <div class="usage-note">Saved. The numbers below are what is stored for <?= htmlspecialchars($usage_date) ?>.</div>
            <?php elseif ($imported_n > 0): ?>
                <div class="usage-note">Read <?= (int)$imported_n ?> items from the Production upload for <?= htmlspecialchars($usage_date) ?>.</div>
            <?php elseif ($saved): ?>
                <div class="usage-note">
                    Showing <?= count($saved) ?> saved item<?= count($saved) === 1 ? '' : 's' ?> for <?= htmlspecialchars($usage_date) ?>.
                    Names match the Production Report (stock catalog name is in grey when it is different).
                    Click a column header to sort.
                    <?php if ($prod_pdf): ?>
                        A Production PDF is on file for this day
                        <a href="../daily_report_view.php?action=dl&rid=<?= (int)$rid ?>&ym=<?= htmlspecialchars(substr($usage_date, 0, 7)) ?>&kind=production&date=<?= htmlspecialchars($usage_date) ?>" target="_blank">View PDF</a>.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="usage-empty">
                    No consumption saved for <?= htmlspecialchars($usage_date) ?> yet.
                    Upload that day’s <strong>Production</strong> PDF on
                    <a href="../daily_report_view.php">Daily Activity Log</a>
                    and it will be read into this page, or type quantities here.
                    Production is <strong>pieces</strong>. Invoice deliveries are <strong>boxes × Pcs/Box</strong> (set on Master Items).
                </div>
            <?php endif; ?>
            <p class="text-muted small font-weight-bold mb-3">Usage = pieces from the Production sheet. Log Delivery = boxes from the invoice × Pcs/Box on Master Items (example: 8 boxes × 180 = 1,440 pieces of chicken cut 9).</p>

            <form id="usageForm" action="save_usage.php" method="POST" onsubmit="if(parseInt(this.rid.value||0,10)<=0){alert("Pick a restaurant before saving usage. All Sites is a filter only.");return false;}">
                <input type="hidden" name="rid" value="<?= $rid ?>">
                <table class="table table-hover" id="usageTable">
                    <thead class="text-muted small text-uppercase">
                        <tr style="background:#f8fafc; border-bottom:2px solid #cbd5e1;">
                            <th>Item Name</th>
                            <th class="text-center" width="20%">Sold Qty (pieces)</th>
                            <th class="text-center" width="20%">Internal / Waste</th>
                            <th class="text-right" width="15%">Unit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($items as $item):
                            $sid = (int)$item['Id'];
                            $has = isset($saved[$sid]);
                            $soldShow = $has ? number_format((float)$saved[$sid]['sold'], 2, '.', '') : '';
                            $wasteShow = $has ? number_format((float)$saved[$sid]['waste'], 2, '.', '') : '';
                            $unit = $item['Unit'] ?: 'pcs';
                            $pcsBox = (float)($item['pcs_in_box'] ?? 0);
                            if ($pcsBox > 1) {
                                $unit .= ' · ' . rtrim(rtrim(number_format($pcsBox, 2, '.', ''), '0'), '.') . '/box';
                            }
                            $prod_name = trim((string)($item['clearview_name'] ?? ''));
                            $cat_name = (string)$item['name'];
                            $show_name = $prod_name !== '' ? $prod_name : $cat_name;
                            $show_sub = ($prod_name !== '' && strcasecmp($prod_name, $cat_name) !== 0) ? $cat_name : '';
                        ?>
                        <tr>
                            <td class="align-middle" data-sort="<?= htmlspecialchars(strtoupper($show_name)) ?>">
                                <?= inv_pepper_html(!empty($item['is_hot'])) ?><strong><?= strtoupper(htmlspecialchars($show_name)) ?></strong>
                                <?php if ($show_sub !== ''): ?>
                                    <div class="text-muted small" style="font-weight:600; letter-spacing:0.02em;"><?= strtoupper(htmlspecialchars($show_sub)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center align-middle" data-sort="<?= $has ? htmlspecialchars($soldShow) : '0' ?>">
                                <input type="number" step="0.01" name="usage[<?= $sid ?>][sold]" class="form-control qty-input mx-auto" placeholder="0.00" value="<?= htmlspecialchars($soldShow) ?>">
                            </td>
                            <td class="text-center align-middle" data-sort="<?= $has ? htmlspecialchars($wasteShow) : '0' ?>">
                                <input type="number" step="0.01" name="usage[<?= $sid ?>][waste]" class="form-control qty-input mx-auto" style="color:#64748b;" placeholder="0.00" value="<?= htmlspecialchars($wasteShow) ?>">
                            </td>
                            <td class="text-right align-middle text-muted small font-weight-bold text-uppercase" data-sort="<?= htmlspecialchars(strtoupper($unit)) ?>"><?= htmlspecialchars($unit) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit" class="btn-save-fixed">
                    SAVE CONSUMPTION <i class="fas fa-utensils ml-2"></i>
                </button>
            </form>
        </div>
    </div>

    <script src="../engine/table_sort.js?v=20260917b"></script>
    <script>
        document.getElementById('itemSearch').addEventListener('keyup', function() {
            let filter = this.value.toUpperCase();
            let rows = document.querySelector("#usageTable tbody").rows;
            for (let i = 0; i < rows.length; i++) {
                let name = rows[i].cells[0].textContent.toUpperCase();
                rows[i].style.display = name.includes(filter) ? "" : "none";
            }
        });
        document.getElementById('usageDatePicker').addEventListener('change', function() {
            var d = this.value;
            if (!d) return;
            location.href = 'usage.php?rid=<?= (int)$rid ?>&usage_date=' + encodeURIComponent(d)<?= !empty($selected_org) ? " + '&org_id=" . (int)$selected_org . "'" : '' ?>;
        });
        makeTableSortable('usageTable');
    </script>
</body>
</html>
