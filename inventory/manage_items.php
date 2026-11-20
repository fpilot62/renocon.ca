<?php
/** Path: /inventory/manage_items.php — master items + invoice date prices */
require_once dirname(__DIR__) . '/db.php';
if (empty($_SESSION['user_id'])) {
    header('Location: /login_kiosk.php');
    exit;
}
require_once dirname(__DIR__) . '/engine/menu_generator.php';
require_once dirname(__DIR__) . '/engine/invoice_delivery_import.php';
require_once dirname(__DIR__) . '/engine/inventory_hot.php';
require_once __DIR__ . '/_org_sites.php';
inv_ensure_hot_column($pdo);

$INVOICE_ROOT = dirname(__DIR__) . '/uploads/food_cost_invoices';
$orgId = (int)($selected_org ?? 0);
if ($orgId <= 0 && !empty($sites)) {
    foreach ($sites as $s) {
        if ((int)$s['SId'] === (int)$rid) {
            $orgId = (int)($s['org_id'] ?? 0);
            break;
        }
    }
}

$useDate = trim((string)($_GET['use_date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $useDate)) {
    $useDate = '';
}
$priceFlash = '';
$priceIds = array_map('intval', $_SESSION['invoice_price_ids'] ?? []);

if (isset($_GET['use_this_date'])) {
    if ((int)$rid <= 0) {
        header('Location: manage_items.php?rid=0&org_id=' . (int)$orgId . '&err=pick_site');
        exit;
    }
    if ($useDate === '') {
        $useDate = date('Y-m-d');
    }
    $merged = idi_merge_same_date_invoices($pdo, $INVOICE_ROOT, $rid, $useDate, []);
    $applied = idi_apply_prices_to_catalog($pdo, $orgId, $merged['items'] ?? [], $merged['unmatched'] ?? []);
    $_SESSION['invoice_price_ids'] = $applied['ids'];
    $nInv = (int)($merged['count'] ?? 0);
    header(
        'Location: manage_items.php?rid=' . (int)$rid
        . '&org_id=' . (int)$orgId
        . '&use_date=' . rawurlencode($useDate)
        . '&prices=1&updated=' . (int)$applied['updated']
        . '&added=' . (int)$applied['added']
        . '&invoices=' . $nInv
    );
    exit;
}

if (isset($_GET['prices'])) {
    $priceFlash = (int)($_GET['updated'] ?? 0) . ' prices updated from '
        . htmlspecialchars($useDate !== '' ? $useDate : 'that date')
        . ' invoices';
    if ((int)($_GET['added'] ?? 0) > 0) {
        $priceFlash .= ', ' . (int)$_GET['added'] . ' new item(s) added';
    }
    if ((int)($_GET['invoices'] ?? 0) > 0) {
        $priceFlash .= ' (' . (int)$_GET['invoices'] . ' invoice(s))';
    }
    $priceFlash .= '. Green rows are from the invoice — click Save if you change a name.';
}

if ($orgId <= 0 && !empty($sites)) {
    foreach ($sites as $s) {
        if ((int)$s['SId'] === (int)$rid) {
            $orgId = (int)($s['org_id'] ?? 0);
            break;
        }
    }
}
if ($orgId <= 0) {
    $orgId = 1;
}

if (isset($_GET['toggle_hot'])) {
    $hid = (int)$_GET['toggle_hot'];
    if ($hid > 0) {
        $pdo->prepare('UPDATE Gem_inventory_items SET is_hot = IF(is_hot = 1, 0, 1) WHERE id = ? AND org_id = ?')->execute([$hid, $orgId]);
    }
    header('Location: manage_items.php?rid=' . (int)$rid . '&org_id=' . (int)$orgId . '&use_date=' . rawurlencode($useDate));
    exit;
}

$st = $pdo->prepare(
    'SELECT id, name, purchase_unit, count_unit, pcs_in_box, cost_box, cost_pc, is_hot
     FROM Gem_inventory_items
     WHERE IsActive = 1 AND org_id = ?
     ORDER BY is_hot DESC, name ASC'
);
$st->execute([$orgId]);
$items = $st->fetchAll(PDO::FETCH_ASSOC);
if ($priceIds) {
    usort($items, static function ($a, $b) use ($priceIds) {
        $ah = in_array((int)$a['id'], $priceIds, true) ? 0 : 1;
        $bh = in_array((int)$b['id'], $priceIds, true) ? 0 : 1;
        if ($ah !== $bh) {
            return $ah - $bh;
        }
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });
}

$latestDate = $useDate;
if ($latestDate === '') {
    try {
        $dq = $pdo->prepare(
            'SELECT InvoiceDate FROM FoodCost
             WHERE RId = ? AND Invoice IS NOT NULL AND Invoice <> ""
             ORDER BY InvoiceDate DESC LIMIT 1'
        );
        $dq->execute([$rid]);
        $latestDate = (string)$dq->fetchColumn();
    } catch (Throwable $e) {
        $latestDate = '';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $latestDate)) {
        $latestDate = date('Y-m-d');
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Master Items</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; padding: 20px; background: #f8f9fa; padding-top: 95px; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th { background: #34495e; color: white; padding: 12px; text-align: left; }
        td { padding: 10px; border-bottom: 1px solid #ddd; }
        input[type="text"], input[type="number"] { width: 90%; padding: 5px; border: 1px solid #ccc; border-radius: 3px; }
        .btn-save { background: #27ae60; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; }
        .btn-del { background: #e74c3c; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; text-decoration: none; font-size: 12px; }
        .date-use-bar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:12px; margin:12px 0; }
        .date-use-bar label { font-size:12px; font-weight:800; color:#334155; margin:0; }
        .date-use-bar .toggle { display:flex; align-items:center; gap:6px; background:#ecfdf5; border:1px solid #86efac; border-radius:8px; padding:6px 10px; }
        .btn-use-date { background:#0f766e; color:#fff; border:none; padding:8px 14px; border-radius:6px; font-size:12px; font-weight:800; cursor:pointer; }
        .banner { background:#ecfdf5; border:1px solid #86efac; color:#14532d; padding:10px 14px; border-radius:8px; font-weight:600; margin:10px 0; }
        tr.hit-row { background:#ecfdf5; }
        .site-dropdown { padding:6px 10px; font-weight:700; }
        .inv-hot { font-size:15px; margin-right:4px; }
        a.hot-toggle { text-decoration:none; font-size:16px; opacity:.35; }
        a.hot-toggle.on { opacity:1; }
        tr.hot-row td:first-child { box-shadow: inset 3px 0 0 #dc2626; }
        .item-name { font-weight:700; color:#0f172a; }
    </style>
</head>
<body>
 <?= generateMenu($pdo); ?>
<div class="card">
    <h2>Master Inventory Items</h2>
    <p>This catalog is <strong>per organization</strong>, not per restaurant. Restaurant + Date are only for <strong>Use this date</strong> (invoice Cost/Box). The original 12 master SKUs (ids 1–12) are <strong>hot items</strong> — red pepper only on those 12 rows. Item names are locked (not editable).</p>

    <form class="date-use-bar" method="GET" action="manage_items.php">
        <?php if ($is_super && function_exists('sip_org_select_html')): ?>
            <?= sip_org_select_html($__fence, 'site-dropdown') ?>
        <?php endif; ?>
        <label>Restaurant
            <select class="site-dropdown" name="rid" onchange="this.form.submit()">
                <option value="0" <?= (int)$rid <= 0 ? 'selected' : '' ?>>All Sites</option>
                <?php foreach ($sites as $s): ?>
                    <option value="<?= (int)$s['SId'] ?>" <?= $rid == $s['SId'] ? 'selected' : '' ?>>
                        <?= (int)$s['SId'] ?>. <?= htmlspecialchars($s['nick_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Date
            <input type="date" name="use_date" value="<?= htmlspecialchars($latestDate) ?>">
        </label>
        <label class="toggle">
            <input type="checkbox" name="use_this_date" value="1" <?= isset($_GET['prices']) || isset($_GET['use_this_date']) ? 'checked' : '' ?> onchange="if(this.checked) this.form.submit();">
            Use this date
        </label>
        <button type="submit" class="btn-use-date" name="use_this_date" value="1">Use this date</button>
        <a href="index.php?rid=<?= (int)$rid ?>&amp;use_date=<?= htmlspecialchars($latestDate) ?>&amp;use_this_date=1">Fill Log Delivery for this date</a>
    </form>

    <?php if ($priceFlash): ?>
        <div class="banner"><?= $priceFlash ?></div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Item Name</th>
                <th>Purchase Unit</th>
                <th>Count Unit</th>
                <th>Pcs/Box</th>
                <th>Cost/Box</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $row):
                $isHit = in_array((int)$row['id'], $priceIds, true);
                $isHot = !empty($row['is_hot']);
            ?>
            <form action="update_item_inline.php" method="POST">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
                <input type="hidden" name="rid" value="<?= (int)$rid ?>">
                <tr class="<?= $isHit ? 'hit-row' : '' ?><?= $isHot ? ' hot-row' : '' ?>">
                    <td>
                        <?php if ($isHot): ?><span class="inv-hot" title="Hot item">&#127798;&#65039;</span> <?php endif; ?>
                        <input type="hidden" name="name" value="<?= htmlspecialchars((string)$row['name']) ?>">
                        <span class="item-name"><?= htmlspecialchars((string)$row['name']) ?></span>
                    </td>
                    <td><input type="text" name="purchase_unit" value="<?= htmlspecialchars((string)($row['purchase_unit'] ?? '')) ?>"></td>
                    <td><input type="text" name="count_unit" value="<?= htmlspecialchars((string)($row['count_unit'] ?? '')) ?>"></td>
                    <td><input type="number" step="0.01" name="pcs_in_box" value="<?= htmlspecialchars((string)$row['pcs_in_box']) ?>"></td>
                    <td><input type="number" step="0.01" name="cost_box" value="<?= htmlspecialchars(number_format((float)$row['cost_box'], 2, '.', '')) ?>"></td>
                    <td>
                        <button type="submit" class="btn-save">Save</button>
                        <a href="delete_master_item.php?id=<?= (int)$row['id'] ?>" class="btn-del" onclick="return confirm('Permanently delete this item?')">Delete</a>
                    </td>
                </tr>
            </form>
            <?php endforeach; ?>

            <form action="update_item_inline.php" method="POST">
                <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
                <input type="hidden" name="rid" value="<?= (int)$rid ?>">
                <tr style="background: #e1f5fe;">
                    <td><input type="text" name="name" placeholder="New Item Name..." required></td>
                    <td><input type="text" name="purchase_unit" placeholder="Box"></td>
                    <td><input type="text" name="count_unit" placeholder="Ea"></td>
                    <td><input type="number" step="0.01" name="pcs_in_box" value="1"></td>
                    <td><input type="number" step="0.01" name="cost_box" value="0.00"></td>
                    <td><button type="submit" class="btn-save" style="background:#3498db;">Add New</button></td>
                </tr>
            </form>
        </tbody>
    </table>
    <br>
    <a href="index.php?rid=<?= (int)$rid ?>">← Back to Log Delivery</a>
</div>

</body>
</html>
