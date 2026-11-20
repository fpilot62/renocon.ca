<?php
/** Path: /inventory/process_delivery.php
 * Save a reviewed delivery from inventory/index.php (qty + rate).
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once dirname(__DIR__) . '/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /login_kiosk.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$rid = (int)($_POST['site_id'] ?? 0);
$vendor = trim((string)($_POST['vendor'] ?? ''));
$invoiceNum = trim((string)($_POST['invoice_num'] ?? ''));
$date = trim((string)($_POST['date'] ?? ''));
$qtys = $_POST['item_qty'] ?? [];
$rates = $_POST['item_rate'] ?? [];

if ($rid <= 0) {
    header('Location: index.php?rid=0&err=pick_site');
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    header('Location: index.php?rid=' . $rid . '&err=site_date');
    exit;
}

$org = 0;
$st = $pdo->prepare('SELECT org_id FROM sites WHERE SId = ?');
$st->execute([$rid]);
$org = (int)$st->fetchColumn();
if ($org <= 0) {
    $org = (int)($_SESSION['org_id'] ?? 0);
}

$lines = [];
$total = 0.0;
if (is_array($qtys)) {
    foreach ($qtys as $itemId => $rawQty) {
        $itemId = (int)$itemId;
        $qty = (float)$rawQty;
        if ($itemId <= 0 || $qty <= 0) {
            continue;
        }
        $rate = isset($rates[$itemId]) ? (float)$rates[$itemId] : 0.0;
        $ext = round($qty * $rate, 2);
        $total += $ext;
        $lines[] = [$itemId, $qty, $rate, $ext];
    }
}

if (!$lines) {
    header('Location: index.php?rid=' . $rid . '&err=no_qty');
    exit;
}

try {
    $pdo->beginTransaction();
    $ins = $pdo->prepare(
        'INSERT INTO Gem_inventory_invoices
            (org_id, site_id, vendor, invoice_number, invoice_date, total_amount, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([$org, $rid, $vendor, $invoiceNum, $date, round($total, 2), 'posted']);
    $invoiceId = (int)$pdo->lastInsertId();

    $det = $pdo->prepare(
        'INSERT INTO Gem_inventory_invoice_details
            (invoice_id, item_id, org_id, site_id, qty_received, pcs_in_box, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $pcsSt = $pdo->prepare('SELECT pcs_in_box FROM Gem_inventory_items WHERE id = ?');
    $updCost = $pdo->prepare('UPDATE Gem_inventory_items SET cost_box = ?, cost_pc = ? WHERE id = ? AND ? > 0');
    foreach ($lines as $row) {
        [$itemId, $qty, $rate, $ext] = $row;
        $pcsSt->execute([$itemId]);
        $pcs = (float)$pcsSt->fetchColumn();
        if ($pcs <= 0) {
            $pcs = 1.0;
        }
        // qty_received stays in boxes; report does boxes × pcs_in_box = pieces
        $det->execute([$invoiceId, $itemId, $org, $rid, $qty, $pcs, $ext]);
        if ($rate > 0) {
            $costPc = round($rate / $pcs, 4);
            $updCost->execute([round($rate, 2), $costPc, $itemId, $rate]);
        }
    }

    $fc = $pdo->prepare('INSERT INTO FoodCost (InvoiceDate, RId, Vendor, Amount, InvoiceNumber) VALUES (?, ?, ?, ?, ?)');
    $fc->execute([$date, $rid, $vendor, round($total, 2), $invoiceNum]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: index.php?rid=' . $rid . '&err=save');
    exit;
}

unset($_SESSION['invoice_delivery_prefill']);
header('Location: index.php?rid=' . $rid . '&saved=1&lines=' . count($lines));
exit;
