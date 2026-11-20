<?php
/** Path: /inventory/save_usage.php
 * Upsert daily sold / waste so a second Save does not duplicate rows.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$rid        = (int)($_POST['rid'] ?? 0);
$usage_date = !empty($_POST['usage_date']) ? trim((string)$_POST['usage_date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $usage_date)) {
    $usage_date = date('Y-m-d');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['usage']) || $rid <= 0) {
    header('Location: usage.php');
    exit;
}

require_once dirname(__DIR__) . '/engine/production_usage_import.php';
$org_id = pui_site_org($pdo, $rid);
if ($org_id <= 0) {
    $org_id = (int)($_SESSION['org_id'] ?? 1);
}

try {
    $pdo->beginTransaction();

    $find = $pdo->prepare(
        'SELECT id FROM Gem_inventory_usage
         WHERE site_id = ? AND item_id = ? AND usage_date = ?
         ORDER BY id ASC'
    );
    $upd = $pdo->prepare(
        'UPDATE Gem_inventory_usage
         SET org_id = ?, sold_qty = ?, waste_qty = ?, actual_qty_pcs = ?
         WHERE id = ?'
    );
    $ins = $pdo->prepare(
        'INSERT INTO Gem_inventory_usage
            (org_id, site_id, item_id, sold_qty, waste_qty, actual_qty_pcs, usage_date)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $del = $pdo->prepare('DELETE FROM Gem_inventory_usage WHERE id = ?');

    foreach ($_POST['usage'] as $item_id => $values) {
        $item_id = (int)$item_id;
        $sold  = (isset($values['sold']) && trim((string)$values['sold']) !== '') ? (float)$values['sold'] : 0.00;
        $waste = (isset($values['waste']) && trim((string)$values['waste']) !== '') ? (float)$values['waste'] : 0.00;
        $total = $sold + $waste;

        $find->execute([$rid, $item_id, $usage_date]);
        $ids = $find->fetchAll(PDO::FETCH_COLUMN);

        if ($ids) {
            $keep = (int)$ids[0];
            $upd->execute([$org_id, $sold, $waste, $total, $keep]);
            for ($i = 1, $n = count($ids); $i < $n; $i++) {
                $del->execute([(int)$ids[$i]]);
            }
            if ($total <= 0) {
                $del->execute([$keep]);
            }
        } elseif ($total > 0) {
            $ins->execute([$org_id, $rid, $item_id, $sold, $waste, $total, $usage_date]);
        }
    }

    $pdo->commit();
    header('Location: usage.php?rid=' . $rid . '&usage_date=' . rawurlencode($usage_date) . '&saved=1');
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "<div style='color:red; font-family:sans-serif; padding:20px;'>";
    echo "<strong>Could not save consumption:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}
