<?php
/** Path: /edit_food_cost.php */
require_once 'db.php';
require_once 'engine/menu_generator.php';
require_once 'engine/site_status_lib.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_lvl = (int)($_SESSION['user_level'] ?? 99);
if (empty($_SESSION['user_id']) || $user_lvl > 30) {
    header('Location: kiosk.php?err=unauthorized');
    exit;
}

$fence = sip_org_site_fence($pdo, 'sip_org_id');
$is_super = !empty($fence['is_super']);
$allowed_ids = $fence['allowed_ids'] ?? [];
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: food_cost_view.php');
    exit;
}

$st = $pdo->prepare(
    'SELECT fc.*, s.nick_name, s.org_id
     FROM FoodCost fc
     JOIN sites s ON fc.RId = s.SId
     WHERE fc.FCId = ?'
);
$st->execute([$id]);
$fc = $st->fetch(PDO::FETCH_ASSOC);
if (!$fc) {
    header('Location: food_cost_view.php');
    exit;
}
$rid = (int)$fc['RId'];
if (!$is_super && $allowed_ids && !in_array($rid, $allowed_ids, true)) {
    header('Location: food_cost_view.php');
    exit;
}

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $date = trim((string)($_POST['InvoiceDate'] ?? ''));
    $invNo = substr(trim((string)($_POST['InvoiceNumber'] ?? '')), 0, 50);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $flash = 'Pick a valid date.';
    } else {
        $up = $pdo->prepare('UPDATE FoodCost SET InvoiceDate = ?, InvoiceNumber = ? WHERE FCId = ?');
        $up->execute([$date, $invNo, $id]);
        header('Location: food_cost_view.php?rid=' . $rid);
        exit;
    }
    $fc['InvoiceDate'] = $date;
    $fc['InvoiceNumber'] = $invNo;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Food Cost | SIP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f1f5f9; font-family: 'Inter', sans-serif; margin: 0; padding-top: 100px; }
        .card { max-width: 480px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
        h3 { margin-top: 0; color: #0f172a; font-weight: 800; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; }
        label { display: block; font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; margin: 15px 0 5px; }
        input { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; font-size: 14px; }
        button { width: 100%; background: #0f172a; color: white; border: none; padding: 14px; border-radius: 6px; font-weight: bold; cursor: pointer; margin-top: 20px; }
        .cancel { display: block; text-align: center; margin-top: 15px; color: #94a3b8; text-decoration: none; font-size: 13px; }
        .flash { background:#fef3c7; border:1px solid #fcd34d; color:#92400e; padding:10px 12px; border-radius:8px; font-weight:700; }
    </style>
</head>
<body>
    <?= generateMenu($pdo); ?>
    <div class="card">
        <h3>Edit Food Cost</h3>
        <p style="font-size: 12px; color: #64748b;">Restaurant: <strong><?= htmlspecialchars((string)$fc['nick_name']) ?></strong></p>
        <?php if ($flash): ?><div class="flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <label>Invoice Date</label>
            <input type="date" name="InvoiceDate" value="<?= htmlspecialchars((string)$fc['InvoiceDate']) ?>" required>
            <label>Vendor (from invoice)</label>
            <p style="margin:0; font-weight:800;"><?= htmlspecialchars((string)($fc['Vendor'] ?? '')) ?></p>
            <label>Amount (from invoice)</label>
            <p style="margin:0; font-weight:800;">$<?= number_format((float)$fc['Amount'], 2) ?></p>
            <label>Invoice #</label>
            <input type="text" name="InvoiceNumber" value="<?= htmlspecialchars((string)($fc['InvoiceNumber'] ?? '')) ?>">
            <button type="submit">SAVE</button>
            <a href="food_cost_view.php?rid=<?= (int)$rid ?>" class="cancel">Cancel</a>
        </form>
    </div>
</body>
</html>
