<?php
/** Path: active/modules/inventory/inventory_items.php */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Simple check to ensure navigation bar html handles properly if included
include_once dirname(__DIR__) . '/engine/menu_generator.php'; 
$nav_bar_html = function_exists('generateMenu') ? generateMenu($pdo) : ''; 

$message = '';

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add_item') {
        $item = trim($_POST['item'] ?? '');
        $pcs = floatval($_POST['pcs_per_case'] ?? 0);
        $pct = floatval($_POST['pct_of_sales'] ?? 0);
        $unit = trim($_POST['unit'] ?? '');
        $sort = trim($_POST['sort'] ?? '');
        $rate = floatval($_POST['rate'] ?? 0);

        if (!empty($item)) {
            $sql = "INSERT INTO items (Item, PcsPerCase, PercentageOfSales, Unit, Sort, Rate) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([$item, $pcs, $pct, $unit, $sort, $rate])) {
                $message = "<div style='color: #10b981; font-weight: bold; margin-bottom: 15px;'>Item added successfully!</div>";
            }
        } else {
            $message = "<div style='color: #ef4444; font-weight: bold; margin-bottom: 15px;'>Item name is required.</div>";
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete_item') {
        $item_id = intval($_POST['item_id'] ?? 0);
        if ($item_id > 0) {
            $pdo->prepare("DELETE FROM items WHERE ItemId = ?")->execute([$item_id]);
            $message = "<div style='color: #ef4444; font-weight: bold; margin-bottom: 15px;'>Item removed.</div>";
        }
    }
}

// Fetch all items sorted by your sort order column
$items = $pdo->query("SELECT * FROM items ORDER BY IFNULL(Sort, 'zzz') ASC, Item ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Inventory Items Manager</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f1f5f9; margin: 0; padding-top: 20px; }
        .container { max-width: 1100px; margin: 0 auto; background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px; margin-bottom: 20px; }
        .form-row { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr auto; gap: 10px; background: #f8fafc; padding: 15px; border-radius: 6px; border: 1px solid #e2e8f0; margin-bottom: 25px; align-items: end; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: 11px; font-weight: bold; color: #64748b; margin-bottom: 5px; text-transform: uppercase; }
        .form-group input { padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; }
        .btn-add { background: #10b981; color: white; border: none; padding: 9px 15px; border-radius: 4px; font-weight: bold; cursor: pointer; height: 38px; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 14px; }
        th { background: #0f172a; color: white; padding: 12px; font-weight: 600; font-size: 12px; text-transform: uppercase; }
        td { padding: 12px; border-bottom: 1px solid #e2e8f0; color: #334155; }
        tr:hover { background: #f8fafc; }
        .btn-delete { background: none; border: none; color: #ef4444; cursor: pointer; font-size: 16px; }
    </style>
</head>
<body>
<?= $nav_bar_html; ?>

<div class="container">
    <div class="header">
        <h2 style="margin:0; color: #0f172a;"><i class="fa fa-boxes-stacked" style="color:#3b82f6;"></i> Inventory Items Master List</h2>
    </div>

    <?= $message; ?>

    <form method="POST">
        <input type="hidden" name="action" value="add_item">
        <div class="form-row">
            <div class="form-group">
                <label>Item Description / Name</label>
                <input type="text" name="item" required placeholder="e.g. Bulk Flour Bag">
            </div>
            <div class="form-group">
                <label>Pcs Per Case</label>
                <input type="number" step="0.01" name="pcs_per_case" value="1.00">
            </div>
            <div class="form-group">
                <label>% Of Sales</label>
                <input type="number" step="0.01" name="pct_of_sales" value="0.00">
            </div>
            <div class="form-group">
                <label>Unit</label>
                <input type="text" name="unit" placeholder="e.g. KG, Case, Bag">
            </div>
            <div class="form-group">
                <label>Sort Value</label>
                <input type="text" name="sort" placeholder="10, 20...">
            </div>
            <div class="form-group">
                <label>Rate ($)</label>
                <input type="number" step="0.01" name="rate" value="0.00">
            </div>
            <button type="submit" class="btn-add"><i class="fa fa-plus"></i> Add Item</button>
        </div>
    </form>

    <table>
        <thead>
            <tr>
                <th style="width: 80px;">ID</th>
                <th>Item Name</th>
                <th>Pcs/Case</th>
                <th>% Of Sales</th>
                <th>Unit</th>
                <th>Sort Order</th>
                <th>Rate</th>
                <th style="width: 60px; text-align: center;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($items)): ?>
                <tr><td colspan="8" style="text-align: center; color: #94a3b8; padding: 30px;">No items defined yet. Use the tool form above to add your first inventory line item.</td></tr>
            <?php else: foreach($items as $i): ?>
                <tr>
                    <td style="font-weight: bold; color: #64748b;"><?= $i['ItemId'] ?></td>
                    <td style="font-weight: 600; color: #0f172a;"><?= htmlspecialchars($i['Item']) ?></td>
                    <td><?= number_format($i['PcsPerCase'], 2) ?></td>
                    <td><?= number_format($i['PercentageOfSales'], 2) ?>%</td>
                    <td><span style="background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-size: 12px; font-weight: bold;"><?= htmlspecialchars($i['Unit'] ?: 'N/A') ?></span></td>
                    <td><?= htmlspecialchars($i['Sort'] ?? '-') ?></td>
                    <td style="font-weight: bold; color: #10b981;">$<?= number_format($i['Rate'], 2) ?></td>
                    <td style="text-align: center;">
                        <form method="POST" onsubmit="return confirm('Permanently remove this item entry?');" style="display:inline;">
                            <input type="hidden" name="action" value="delete_item">
                            <input type="hidden" name="item_id" value="<?= $i['ItemId'] ?>">
                            <button type="submit" class="btn-delete" title="Delete Item"><i class="fa fa-trash-can"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>