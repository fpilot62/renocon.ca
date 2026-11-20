<?php
/** Path: /inventory/index.php — Log New Delivery, with invoice prefill. Marker: inv-all-sites-20261016 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/db.php';
if (empty($_SESSION['user_id'])) {
    header('Location: /login_kiosk.php');
    exit;
}
require_once dirname(__DIR__) . '/engine/menu_generator.php';
require_once dirname(__DIR__) . '/engine/invoice_delivery_import.php';

$user_lvl = (int)($_SESSION['user_level'] ?? 99);
$org_id   = (int)($_SESSION['org_id'] ?? 0);
$assigned = $_SESSION['assigned_site_ids'] ?? '';
require_once __DIR__ . '/_org_sites.php';

$INVOICE_ROOT = dirname(__DIR__) . '/uploads/food_cost_invoices';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_invoice') {
    $upRid = (int)($_POST['site_id'] ?? $rid);
    $date = trim((string)($_POST['date'] ?? date('Y-m-d')));
    if ($upRid <= 0) {
        header('Location: index.php?rid=0' . $inv_org_q . '&err=pick_site');
        exit;
    }
    if (empty($_FILES['invoice_file']['tmp_name'])) {
        header('Location: index.php?rid=' . $upRid . '&err=nofile');
        exit;
    }
    $stored = idi_store_upload(
        $INVOICE_ROOT,
        $upRid,
        $date,
        $_FILES['invoice_file']['tmp_name'],
        $_FILES['invoice_file']['name'] ?? 'invoice.pdf'
    );
    if (empty($stored['ok'])) {
        header('Location: index.php?rid=' . $upRid . '&err=upload');
        exit;
    }
    $read = idi_read_invoice($pdo, $stored['dest'], $upRid, $date);
    @file_put_contents($stored['dest'] . '.txt', $read['text'] ?? '');
    idi_apply_invoice_for_site($pdo, $upRid, $read['items'] ?? [], $read['unmatched'] ?? []);
    $_SESSION['invoice_delivery_prefill'] = idi_apply_same_date_prefill($pdo, $INVOICE_ROOT, $upRid, [
        'rid' => $upRid,
        'vendor' => ($read['vendor'] ?? '') !== '' ? $read['vendor'] : 'Unknown vendor',
        'invoice_num' => ($read['invoice_no'] ?? '') !== '' ? $read['invoice_no'] : 'PENDING',
        'date' => $read['date'] ?: $date,
        'amount' => $read['amount'] ?? 0,
        'file' => $stored['rel'] ?? '',
        'items' => $read['items'] ?? [],
        'unmatched' => $read['unmatched'] ?? [],
        'message' => $read['message'] ?? '',
    ]);
    $_SESSION['invoice_delivery_prefill']['same_date_merged'] = 1;
    header('Location: index.php?rid=' . $upRid . '&from_invoice=1');
    exit;
}

$recentInvoices = [];
try {
    $rq = $pdo->prepare(
        'SELECT FCId, InvoiceDate, Vendor, Amount, InvoiceNumber, Invoice, RId
         FROM FoodCost
         WHERE RId = ? AND Invoice IS NOT NULL AND Invoice <> ""
         ORDER BY InvoiceDate DESC, FCId DESC
         LIMIT 3'
    );
    foreach (inv_query_site_ids() as $sid) {
        $rq->execute([(int)$sid]);
        foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['nick_name'] = inv_site_nick((int)($row['RId'] ?? $sid));
            $recentInvoices[] = $row;
        }
    }
    usort($recentInvoices, static function ($a, $b) {
        $ad = (string)($a['InvoiceDate'] ?? '');
        $bd = (string)($b['InvoiceDate'] ?? '');
        if ($ad !== $bd) {
            return strcmp($bd, $ad);
        }
        return ((int)($b['FCId'] ?? 0)) <=> ((int)($a['FCId'] ?? 0));
    });
} catch (Throwable $e) {
    $recentInvoices = [];
}

if (isset($_GET['use_this_date'])) {
    if ((int)$rid <= 0) {
        header('Location: index.php?rid=0' . $inv_org_q . '&err=pick_site');
        exit;
    }
    $useDate = trim((string)($_GET['use_date'] ?? $_GET['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $useDate)) {
        $useDate = date('Y-m-d');
    }
    $merged = idi_merge_same_date_invoices($pdo, $INVOICE_ROOT, $rid, $useDate, []);
    idi_apply_invoice_for_site($pdo, $rid, $merged['items'] ?? [], $merged['unmatched'] ?? []);
    $_SESSION['invoice_delivery_prefill'] = [
        'rid' => $rid,
        'vendor' => $merged['vendor'] !== '' ? $merged['vendor'] : 'Sysco',
        'invoice_num' => $merged['invoice_nos'][0] ?? 'PENDING',
        'date' => $useDate,
        'amount' => $merged['amount'],
        'items' => $merged['items'],
        'unmatched' => $merged['unmatched'],
        'message' => $merged['message'],
        'fcids' => $merged['fcids'],
        'same_date_invoices' => $merged['invoice_nos'],
        'same_date_merged' => 1,
        'use_this_date' => 1,
    ];
    header('Location: index.php?rid=' . $rid . '&from_invoice=1&use_date=' . rawurlencode($useDate));
    exit;
}

$reqFcid = (int)($_GET['fcid'] ?? 0);
if ($reqFcid > 0 && (int)$rid <= 0) {
    $look = $pdo->prepare('SELECT RId FROM FoodCost WHERE FCId = ?');
    $look->execute([$reqFcid]);
    $fcRid = (int)$look->fetchColumn();
    if ($fcRid > 0 && (!$allowed_ids || in_array($fcRid, $allowed_ids, true))) {
        header('Location: index.php?rid=' . $fcRid . $inv_org_q . '&fcid=' . $reqFcid);
        exit;
    }
}
if ($reqFcid > 0) {
    $st = $pdo->prepare('SELECT * FROM FoodCost WHERE FCId = ? AND RId = ?');
    $st->execute([$reqFcid, $rid]);
    $fcRow = $st->fetch(PDO::FETCH_ASSOC);
    if ($fcRow) {
        $abs = $INVOICE_ROOT . '/' . ltrim(str_replace('\\', '/', (string)$fcRow['Invoice']), '/');
        $read = [
            'vendor' => (string)($fcRow['Vendor'] ?? ''),
            'invoice_no' => (string)($fcRow['InvoiceNumber'] ?? ''),
            'date' => (string)$fcRow['InvoiceDate'],
            'amount' => (float)$fcRow['Amount'],
            'items' => [],
            'unmatched' => [],
            'message' => 'Loaded invoice #' . ($fcRow['InvoiceNumber'] ?: $reqFcid) . '.',
        ];
        if (is_file($abs)) {
            try {
                $read = idi_read_invoice($pdo, $abs, $rid, (string)$fcRow['InvoiceDate']);
            } catch (Throwable $e) {
                error_log('index fcid read: ' . $e->getMessage());
            }
        }
        idi_apply_invoice_for_site($pdo, $rid, $read['items'] ?? [], $read['unmatched'] ?? []);
        $_SESSION['invoice_delivery_prefill'] = idi_apply_same_date_prefill($pdo, $INVOICE_ROOT, $rid, [
            'rid' => $rid,
            'vendor' => $read['vendor'] ?: (string)($fcRow['Vendor'] ?? ''),
            'invoice_num' => $read['invoice_no'] ?: (string)($fcRow['InvoiceNumber'] ?? ''),
            'date' => (string)($fcRow['InvoiceDate'] ?: $read['date'] ?: date('Y-m-d')),
            'amount' => (float)($read['amount'] ?? $fcRow['Amount']),
            'file' => (string)($fcRow['Invoice'] ?? ''),
            'items' => $read['items'] ?? [],
            'unmatched' => $read['unmatched'] ?? [],
            'message' => $read['message'] ?? 'Invoice loaded.',
            'fcid' => $reqFcid,
            'same_date_merged' => 1,
        ]);
        $_SESSION['invoice_delivery_prefill']['same_date_merged'] = 1;
        if (!isset($_GET['from_invoice'])) {
            header('Location: index.php?rid=' . $rid . '&from_invoice=1&fcid=' . $reqFcid);
            exit;
        }
    }
}

$prefill = $_SESSION['invoice_delivery_prefill'] ?? null;
if (is_array($prefill) && (int)($prefill['rid'] ?? 0) !== $rid) {
    $prefill = null;
}
$fromInvoice = isset($_GET['from_invoice']) && $prefill;
if ($fromInvoice && empty($prefill['same_date_merged'])) {
    $prefill = idi_apply_same_date_prefill($pdo, $INVOICE_ROOT, $rid, $prefill);
    $prefill['same_date_merged'] = 1;
    $_SESSION['invoice_delivery_prefill'] = $prefill;
}
$activeFcid = (int)((is_array($prefill) ? ($prefill['fcid'] ?? 0) : 0) ?: $reqFcid);
$activeFcids = [];
if (is_array($prefill) && !empty($prefill['fcids']) && is_array($prefill['fcids'])) {
    $activeFcids = array_map('intval', $prefill['fcids']);
} elseif ($activeFcid > 0) {
    $activeFcids = [$activeFcid];
}
$prefillItems = (is_array($prefill) && is_array($prefill['items'] ?? null)) ? $prefill['items'] : [];
$prefillVendor = is_array($prefill) ? (string)($prefill['vendor'] ?? '') : '';
$prefillInv = is_array($prefill) ? (string)($prefill['invoice_num'] ?? '') : '';
$prefillDate = is_array($prefill) && !empty($prefill['date'])
    ? (string)$prefill['date']
    : (string)($recentInvoices[0]['InvoiceDate'] ?? date('Y-m-d'));
$saved = isset($_GET['saved']);

$sql = "SELECT id AS Id, name AS Name, Unit, cost_pc, cost_box, pcs_in_box FROM Gem_inventory_items
        WHERE (Restaurant_Id = 0 OR Restaurant_Id = :r) AND IsActive = 1
        ORDER BY Name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute(['r' => $rid]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pcsById = [];
foreach ($items as $it) {
    $iid = (int)($it['Id'] ?? $it['id'] ?? 0);
    $pcsById[$iid] = (float)($it['pcs_in_box'] ?? 0) > 0 ? (float)$it['pcs_in_box'] : 1.0;
}
if ($prefillItems) {
    usort($items, static function ($a, $b) use ($prefillItems) {
        $aid = (int)($a['Id'] ?? $a['id'] ?? 0);
        $bid = (int)($b['Id'] ?? $b['id'] ?? 0);
        $ah = isset($prefillItems[$aid]) ? 0 : 1;
        $bh = isset($prefillItems[$bid]) ? 0 : 1;
        if ($ah !== $bh) {
            return $ah - $bh;
        }
        return strcasecmp((string)($a['Name'] ?? ''), (string)($b['Name'] ?? ''));
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Log Delivery | SIP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { background:#f1f5f9; font-family: 'Inter', sans-serif; padding-top: 95px; }
        .delivery-card { background: white; border-radius: 15px; padding: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); margin-bottom: 50px; border: 1px solid #e2e8f0; }
        .search-container { position: sticky; top: 75px; z-index: 1000; background: #f1f5f9; padding: 15px 0; }
        #itemSearch { height: 50px; border-radius: 12px; border: 2px solid #0ea5e9; padding-left: 45px; font-weight: 600; }
        .qty-input, .rate-input { width: 120px; font-weight: 800; color: #2563eb; text-align: center; border: 2px solid #cbd5e1; border-radius: 6px; }
        .qty-input:focus, .rate-input:focus { border-color: #2563eb; background: #eff6ff; }
        .btn-save-fixed { position: fixed; bottom: 30px; right: 50px; padding: 15px 40px; border-radius: 50px; font-weight: 800; z-index: 1001; box-shadow: 0 10px 25px rgba(40,167,69,0.3); text-transform: uppercase; letter-spacing: 0.5px; }
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
        .hit-row { background: #ecfdf5 !important; }
        .banner { background:#ecfdf5; border:1px solid #86efac; color:#14532d; padding:12px 16px; border-radius:10px; margin-bottom:16px; font-weight:600; }
        .banner.warn { background:#fff7ed; border-color:#fdba74; color:#9a3412; }
        .banner.ok { background:#dbeafe; border-color:#93c5fd; color:#1e3a8a; }
        .unmatched { font-size:13px; color:#9a3412; margin:8px 0 0; }
        .upload-bar { background:#f8fafc; border:1px dashed #94a3b8; border-radius:10px; padding:12px; margin-bottom:16px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .recent-box { background:#fff; border:1px solid #cbd5e1; border-radius:10px; padding:12px 14px; margin-bottom:16px; }
        .recent-box h6 { font-weight:800; font-size:12px; text-transform:uppercase; color:#334155; margin:0 0 10px; }
        .recent-box table { width:100%; font-size:13px; margin:0; }
        .recent-box td, .recent-box th { padding:6px 8px; border-bottom:1px solid #e2e8f0; }
        .recent-box tr.active { background:#ecfdf5; }
        .recent-box .now { font-size:11px; font-weight:800; color:#166534; }
        .btn-open { background:#1d4ed8; color:#fff; padding:4px 10px; border-radius:4px; font-size:11px; font-weight:800; text-decoration:none; }
        .btn-use { background:#0f766e; color:#fff; padding:4px 10px; border-radius:4px; font-size:11px; font-weight:800; text-decoration:none; }
        .filled-list { font-size:13px; margin:8px 0 0; color:#14532d; }
        .filled-list span { display:inline-block; background:#dcfce7; border:1px solid #86efac; border-radius:6px; padding:2px 8px; margin:0 6px 6px 0; font-weight:700; }
        .date-use-bar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:0 0 12px; }
        .date-use-bar label { font-size:12px; font-weight:800; color:#334155; margin:0; }
        .date-use-bar .toggle { display:flex; align-items:center; gap:6px; background:#ecfdf5; border:1px solid #86efac; border-radius:8px; padding:6px 10px; }
        .btn-use-date { background:#0f766e; color:#fff; border:none; padding:7px 14px; border-radius:6px; font-size:12px; font-weight:800; cursor:pointer; }
    </style>
</head>
<body>
    <?= generateMenu($pdo); ?>
    <div class="container mt-4">
        <div class="inventory-nav-bar">
            <div class="nav-actions">
                <?= inv_org_dropdown_html() ?>
                <?= inv_site_dropdown_html() ?>
                <a href="index.php?rid=<?= $rid ?><?= htmlspecialchars($inv_org_q) ?>" class="btn-nav btn-delivery">
                    <i class="fas fa-truck-loading"></i> LOG NEW DELIVERY
                </a>
                <a href="physical_count.php?rid=<?= $rid ?><?= htmlspecialchars($inv_org_q) ?>" class="btn-nav btn-count">
                    <i class="fas fa-clipboard-check"></i> PHYSICAL COUNT
                </a>
            </div>
            <div class="nav-links-right">
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
                    <a href="<?= $url ?>?rid=<?= $rid ?><?= htmlspecialchars($inv_org_q) ?>" class="<?= $active ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (isset($_GET['err']) && $_GET['err'] === 'pick_site'): ?>
            <?= inv_pick_site_banner() ?>
        <?php endif; ?>
        <?php if (inv_all_sites()): ?>
            <?= inv_pick_site_banner('All Sites — last invoices below are from every restaurant in this org. Pick a restaurant to upload or save a delivery.') ?>
        <?php endif; ?>
        <?php if ($saved): ?>
            <div class="banner ok">Delivery saved (<?= (int)($_GET['lines'] ?? 0) ?> lines). Food Cost also has the total.</div>
        <?php endif; ?>
        <?php if ($fromInvoice): ?>
            <div class="banner"><?= htmlspecialchars((string)($prefill['message'] ?? 'Invoice read.')) ?> Green rows are at the top — check them, then Save.</div>
            <?php if (!empty($prefillItems)): ?>
                <p class="filled-list">Invoice qty is <strong>boxes</strong>. Usage is <strong>pieces</strong> (boxes × Pcs/Box from Master Items).
                    <?php foreach ($prefillItems as $pid => $hit):
                        $pcs = $pcsById[(int)$pid] ?? 1.0;
                        $boxes = (float)($hit['qty'] ?? 0);
                        $pcsOut = round($boxes * $pcs, 2);
                    ?>
                        <span><?= htmlspecialchars((string)($hit['name'] ?? 'item')) ?>
                            <?= htmlspecialchars((string)$boxes) ?> box
                            × <?= htmlspecialchars(rtrim(rtrim(number_format($pcs, 2, '.', ''), '0'), '.')) ?>
                            = <?= htmlspecialchars(rtrim(rtrim(number_format($pcsOut, 2, '.', ''), '0'), '.')) ?> pcs
                            @ $<?= number_format((float)$hit['rate'], 2) ?>/box</span>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <?php if (!empty($prefill['same_date_invoices']) && count($prefill['same_date_invoices']) > 1): ?>
                <p class="filled-list">Same-date invoices included:
                    <?= htmlspecialchars(implode(' + ', $prefill['same_date_invoices'])) ?>
                </p>
            <?php endif; ?>
            <?php if (!empty($prefill['unmatched'])): ?>
                <p class="unmatched">Not matched (we will tune after this test file):
                    <?php foreach (array_slice($prefill['unmatched'], 0, 12) as $u): ?>
                        <?= htmlspecialchars($u['name']) ?>
                        (<?= htmlspecialchars((string)$u['qty']) ?> @ $<?= number_format((float)$u['rate'], 2) ?>);
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($recentInvoices): ?>
        <div class="recent-box">
            <h6><?= inv_all_sites() ? 'Last invoices for all restaurants in this org — Open PDF and Use this still work per invoice. Date / Use this date needs a restaurant.' : 'Last 3 invoices for this restaurant — tick Use this date to fill every invoice on that day. Same items add together.' ?></h6>
            <form class="date-use-bar" method="GET" action="index.php">
                <input type="hidden" name="rid" value="<?= (int)$rid ?>">
                <label>Date
                    <input type="date" name="use_date" value="<?= htmlspecialchars((string)($_GET['use_date'] ?? $prefillDate)) ?>">
                </label>
                <label class="toggle">
                    <input type="checkbox" name="use_this_date" value="1" <?= (!empty($prefill['use_this_date']) || isset($_GET['use_date'])) && $fromInvoice ? 'checked' : '' ?> onchange="if(this.checked) this.form.submit();">
                    Use this date
                </label>
                <button type="submit" class="btn-use-date" name="use_this_date" value="1">Use this date</button>
                <a class="btn-use" href="manage_items.php?rid=<?= (int)$rid ?>&amp;use_date=<?= htmlspecialchars((string)($_GET['use_date'] ?? $prefillDate)) ?>&amp;use_this_date=1">Add / modify prices from this date</a>
            </form>
            <table>
                <tr>
                    <th>Date</th>
                    <?php if (inv_all_sites()): ?><th>Restaurant</th><?php endif; ?>
                    <th>Vendor</th>
                    <th>Invoice #</th>
                    <th>Amount</th>
                    <th></th>
                </tr>
                <?php foreach ($recentInvoices as $inv):
                    $iid = (int)$inv['FCId'];
                    $isActive = in_array($iid, $activeFcids, true);
                ?>
                <tr class="<?= $isActive ? 'active' : '' ?>">
                    <td><?= htmlspecialchars((string)$inv['InvoiceDate']) ?></td>
                    <?php if (inv_all_sites()): ?>
                    <td><?= htmlspecialchars((string)($inv['nick_name'] ?? inv_site_nick((int)($inv['RId'] ?? 0)))) ?></td>
                    <?php endif; ?>
                    <td><?= htmlspecialchars((string)($inv['Vendor'] ?: '—')) ?></td>
                    <td><?= htmlspecialchars((string)($inv['InvoiceNumber'] ?: '—')) ?></td>
                    <td>$<?= number_format((float)$inv['Amount'], 2) ?></td>
                    <td>
                        <a class="btn-open" href="../food_cost_view.php?action=dl&fcid=<?= $iid ?>" target="_blank" rel="noopener">Open PDF</a>
                        <a class="btn-use" href="index.php?rid=<?= (int)($inv['RId'] ?? $rid) ?>&fcid=<?= $iid ?><?= htmlspecialchars($inv_org_q) ?>">Use this</a>
                        <?php if ($activeFcid === $iid): ?>
                            <span class="now">← filling the form</span>
                        <?php elseif ($isActive): ?>
                            <span class="now">← same date, items added</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <form class="upload-bar" method="POST" enctype="multipart/form-data" onsubmit="return invNeedRestaurant(this);">
            <input type="hidden" name="action" value="upload_invoice">
            <input type="hidden" name="site_id" value="<?= $rid ?>">
            <input type="hidden" name="date" value="<?= htmlspecialchars($prefillDate) ?>">
            <strong style="font-size:13px;">Upload invoice</strong>
            <input type="file" name="invoice_file" accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,image/*" required>
            <button type="submit" class="btn btn-primary btn-sm font-weight-bold">READ INTO THIS PAGE</button>
            <span class="text-muted small">PDF or photo. Quantities and rates fill below.</span>
        </form>

        <?php if (!$recentInvoices): ?>
        <form class="date-use-bar recent-box" method="GET" action="index.php">
            <input type="hidden" name="rid" value="<?= (int)$rid ?>">
            <label>Date
                <input type="date" name="use_date" value="<?= htmlspecialchars($prefillDate) ?>">
            </label>
            <label class="toggle">
                <input type="checkbox" name="use_this_date" value="1" <?= !empty($prefill['use_this_date']) ? 'checked' : '' ?> onchange="if(this.checked) this.form.submit();">
                Use this date
            </label>
            <button type="submit" class="btn-use-date" name="use_this_date" value="1">Use this date</button>
            <a class="btn-use" href="manage_items.php?rid=<?= (int)$rid ?>&amp;use_date=<?= htmlspecialchars($prefillDate) ?>&amp;use_this_date=1">Add / modify prices from this date</a>
        </form>
        <?php endif; ?>

        <form action="process_delivery.php" method="POST" onsubmit="return invNeedRestaurant(this);">
            <input type="hidden" name="site_id" value="<?= $rid ?>">
            <div class="delivery-card">
                <div class="row mb-4">
                    <div class="col-md-4"><label class="small font-weight-bold text-muted">VENDOR DISTRIBUTOR</label><input type="text" name="vendor" class="form-control" placeholder="Specify supplier name..." value="<?= htmlspecialchars($prefillVendor) ?>" required></div>
                    <div class="col-md-4"><label class="small font-weight-bold text-muted">INVOICE SERIAL NUMBER</label><input type="text" name="invoice_num" class="form-control" placeholder="Specify invoice code..." value="<?= htmlspecialchars($prefillInv) ?>" required></div>
                    <div class="col-md-4"><label class="small font-weight-bold text-muted">DELIVERY DATE</label><input type="date" name="date" class="form-control" value="<?= htmlspecialchars($prefillDate) ?>" required></div>
                </div>
                <div class="search-container">
                    <i class="fas fa-search" style="position:absolute; left:20px; top:32px; color:#0ea5e9;"></i>
                    <input type="text" id="itemSearch" class="form-control shadow-sm" placeholder="Type to search and filter restaurant materials on-the-fly...">
                </div>
                <table class="table table-hover mt-3" id="itemsTable">
                    <thead>
                        <tr class="table-borderless" style="background:#f8fafc; border-bottom:2px solid #cbd5e1;">
                            <th>Inventory Item Description Name</th>
                            <th class="text-center">Cost / Box (invoice)</th>
                            <th class="text-center">Boxes</th>
                            <th class="text-center">Pcs / Box</th>
                            <th class="text-center">Pieces</th>
                            <th class="text-right">Unit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($items as $item):
                            $id = (int)($item['Id'] ?? $item['id'] ?? 0);
                            $hit = $prefillItems[$id] ?? null;
                            $qtyVal = $hit ? (float)$hit['qty'] : '';
                            $pcs = $pcsById[$id] ?? 1.0;
                            $boxCost = (float)($item['cost_box'] ?? 0);
                            $rateVal = $hit && (float)$hit['rate'] > 0
                                ? (float)$hit['rate']
                                : ($boxCost > 0 ? $boxCost : (float)$item['cost_pc']);
                            $pcsOut = $qtyVal !== '' ? round((float)$qtyVal * $pcs, 2) : '';
                        ?>
                        <tr class="<?= $hit ? 'hit-row' : '' ?>" data-pcs="<?= htmlspecialchars((string)$pcs) ?>">
                            <td style="vertical-align: middle;"><strong><?= strtoupper(htmlspecialchars($item['Name'])) ?></strong></td>
                            <td class="text-center">
                                <input type="number" step="0.01" name="item_rate[<?= $id ?>]" class="form-control rate-input mx-auto" value="<?= $rateVal !== '' ? htmlspecialchars((string)$rateVal) : '' ?>" placeholder="0.00">
                            </td>
                            <td class="text-center">
                                <input type="number" step="0.01" name="item_qty[<?= $id ?>]" class="form-control qty-input mx-auto" value="<?= $qtyVal !== '' ? htmlspecialchars((string)$qtyVal) : '' ?>" placeholder="0">
                            </td>
                            <td class="text-center text-muted font-weight-bold" style="vertical-align: middle;"><?= htmlspecialchars(rtrim(rtrim(number_format($pcs, 2, '.', ''), '0'), '.')) ?></td>
                            <td class="text-center pcs-out font-weight-bold" style="vertical-align: middle; color:#0f766e;"><?= $pcsOut !== '' ? htmlspecialchars(rtrim(rtrim(number_format((float)$pcsOut, 2, '.', ''), '0'), '.')) : '—' ?></td>
                            <td class="text-right text-muted font-weight-bold" style="vertical-align: middle; text-transform:uppercase;"><?= htmlspecialchars($item['Unit'] ?: 'pcs') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" class="btn btn-success btn-save-fixed shadow-lg">SAVE TRACKED DELIVERY <i class="fas fa-check-circle ml-2"></i></button>
        </form>
    </div>
    <script>
        function invNeedRestaurant(form) {
            var el = form.querySelector('[name="site_id"]');
            if (el && parseInt(el.value || '0', 10) <= 0) {
                alert('Pick a restaurant before saving. All Sites is a filter only.');
                return false;
            }
            return true;
        }
        document.getElementById('itemSearch').addEventListener('keyup', function() {
            let filter = this.value.toUpperCase();
            let rows = document.querySelector("#itemsTable tbody").rows;
            for (let row of rows) {
                row.style.display = row.cells[0].textContent.toUpperCase().includes(filter) ? "" : "none";
            }
        });
        document.querySelectorAll('#itemsTable .qty-input').forEach(function(inp) {
            inp.addEventListener('input', function() {
                var row = this.closest('tr');
                var pcs = parseFloat(row.getAttribute('data-pcs') || '1') || 1;
                var boxes = parseFloat(this.value || '0') || 0;
                var out = row.querySelector('.pcs-out');
                if (out) {
                    out.textContent = boxes > 0 ? String(Math.round(boxes * pcs * 100) / 100) : '—';
                }
            });
        });
    </script>
</body>
</html>
