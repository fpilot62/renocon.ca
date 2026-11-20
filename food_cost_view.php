<?php
/** Path: /food_cost_view.php
 * Marker: fc-del-one-20261019 + page-rollback-20261019-list
 */
require_once 'db.php';
require_once 'engine/menu_generator.php';
require_once 'engine/invoice_delivery_import.php';
require_once 'engine/site_status_lib.php';
require_once 'engine/page_rollback_lib.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$current_org_id = (int)($_SESSION['org_id'] ?? 0);
$user_lvl       = (int)($_SESSION['user_level'] ?? 99);
$user_id        = (int)($_SESSION['user_id'] ?? 0);

if (!isset($_SESSION['user_id']) || $user_lvl > 30) {
    header("Location: kiosk.php?err=access_denied_financials");
    exit;
}

$is_super = ($user_lvl <= 10);
$is_admin = ($user_lvl <= 20);
$INVOICE_ROOT = __DIR__ . '/uploads/food_cost_invoices';

$__fence = sip_org_site_fence($pdo, 'sip_org_id');
$is_super = $__fence['is_super'];
$selected_org = $__fence['selected_org'];
$orgs = $__fence['orgs'];
$sites = $__fence['sites'];
$assigned_csv = $__fence['assigned_csv'];
$allowed_ids = $__fence['allowed_ids'];

function fc_invoice_abs(string $root, string $rel): ?string {
    $rel = str_replace('\\', '/', $rel);
    if ($rel === '' || strpos($rel, '..') !== false) {
        return null;
    }
    $realRoot = realpath($root);
    $abs = $root . '/' . ltrim($rel, '/');
    $real = realpath($abs);
    if (!$realRoot || !$real || strpos($real, $realRoot) !== 0 || !is_file($real)) {
        return null;
    }
    return $real;
}

function fc_can_rid(int $rid, bool $is_super, array $allowed_ids): bool {
    if ($rid <= 0) return false;
    if ($is_super) return empty($allowed_ids) || in_array($rid, $allowed_ids, true);
    return in_array($rid, $allowed_ids, true);
}

if (($_GET['action'] ?? '') === 'dl') {
    $fcid = (int)($_GET['fcid'] ?? 0);
    $st = $pdo->prepare('SELECT RId, Invoice FROM FoodCost WHERE FCId = ?');
    $st->execute([$fcid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !fc_can_rid((int)$row['RId'], $is_super, $allowed_ids)) {
        http_response_code(404);
        exit('Invoice not found.');
    }
    $real = fc_invoice_abs($INVOICE_ROOT, (string)($row['Invoice'] ?? ''));
    if (!$real) {
        http_response_code(404);
        exit('File not found.');
    }
    $mime = 'application/pdf';
    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    if (in_array($ext, ['png','jpg','jpeg','webp','gif'], true)) {
        $mime = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($real));
    header('Content-Disposition: inline; filename="' . basename($real) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($real);
    exit;
}

if (($_GET['action'] ?? '') === 'to_inventory') {
    $fcid = (int)($_GET['fcid'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM FoodCost WHERE FCId = ?');
    $st->execute([$fcid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !fc_can_rid((int)$row['RId'], $is_super, $allowed_ids)) {
        header('Location: food_cost_view.php');
        exit;
    }
    $rid = (int)$row['RId'];
    $date = (string)$row['InvoiceDate'];
    $real = fc_invoice_abs($INVOICE_ROOT, (string)($row['Invoice'] ?? ''));
    $read = ['vendor' => (string)($row['Vendor'] ?? ''), 'date' => $date, 'invoice_no' => (string)($row['InvoiceNumber'] ?? ''), 'amount' => (float)$row['Amount'], 'items' => [], 'unmatched' => [], 'message' => 'Invoice opened.', 'text' => ''];
    if ($real) {
        try {
            $read = idi_read_invoice($pdo, $real, $rid, $date);
        } catch (Throwable $e) {
            error_log('to_inventory read: ' . $e->getMessage());
        }
        $vendor = substr((string)($read['vendor'] ?: ($row['Vendor'] ?? 'Sysco')), 0, 255);
        $invNo = substr((string)($read['invoice_no'] ?: ($row['InvoiceNumber'] ?? '')), 0, 50);
        $amount = (float)($read['amount'] ?? 0);
        $invDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($read['date'] ?? '')) ? $read['date'] : $date;
        $up = $pdo->prepare('UPDATE FoodCost SET Vendor = ?, Amount = ?, InvoiceNumber = ?, InvoiceDate = ? WHERE FCId = ?');
        $up->execute([$vendor, $amount, $invNo, $invDate, $fcid]);
        @file_put_contents($real . '.txt', $read['text'] ?? '');
    }
    $_SESSION['invoice_delivery_prefill'] = idi_apply_same_date_prefill($pdo, $INVOICE_ROOT, $rid, [
        'rid' => $rid,
        'vendor' => $read['vendor'] ?: ($row['Vendor'] ?? ''),
        'invoice_num' => $read['invoice_no'] ?: ($row['InvoiceNumber'] ?? ''),
        'date' => $date ?: ($read['date'] ?? date('Y-m-d')),
        'amount' => (float)($read['amount'] ?? $row['Amount']),
        'file' => $row['Invoice'] ?? '',
        'items' => $read['items'] ?? [],
        'unmatched' => $read['unmatched'] ?? [],
        'message' => $read['message'] ?? 'Invoice read.',
        'fcid' => $fcid,
    ]);
    $_SESSION['invoice_delivery_prefill']['same_date_merged'] = 1;
    header('Location: inventory/index.php?rid=' . $rid . '&from_invoice=1&fcid=' . $fcid);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_invoice') {
  try {
    $rid = (int)($_POST['RId'] ?? 0);
    $date = trim((string)($_POST['InvoiceDate'] ?? date('Y-m-d')));
    if ($rid > 0 && $allowed_ids && !in_array($rid, $allowed_ids, true)) {
        $_SESSION['fc_flash'] = 'That restaurant is not in the selected organization.';
        header('Location: food_cost_view.php');
        exit;
    }
    if ($rid <= 0) {
        $_SESSION['fc_flash'] = 'Pick a site before uploading the invoice.';
        header('Location: food_cost_view.php');
        exit;
    }
    if (empty($_FILES['invoice_file']['tmp_name'])) {
        $_SESSION['fc_flash'] = 'Choose a PDF or photo of the invoice.';
        header('Location: food_cost_view.php?rid=' . $rid);
        exit;
    }
    $stored = idi_store_upload(
        $INVOICE_ROOT,
        $rid,
        $date,
        $_FILES['invoice_file']['tmp_name'],
        $_FILES['invoice_file']['name'] ?? 'invoice.pdf'
    );
    if (empty($stored['ok'])) {
        $_SESSION['fc_flash'] = $stored['error'] ?? 'Upload failed.';
        header('Location: food_cost_view.php?rid=' . $rid);
        exit;
    }
    $splitByDate = !empty($_POST['split_by_date']);
    if ($splitByDate) {
        $pack = idi_import_combined_invoices($pdo, $stored['dest'], (string)($stored['rel'] ?? ''), $rid, $date);
        $_SESSION['fc_flash'] = !empty($pack['ok'])
            ? (string)$pack['message']
            : (string)($pack['error'] ?? 'Could not split the combined invoice PDF.');
        $q = 'food_cost_view.php?rid=' . $rid;
        if (!empty($pack['dates'])) {
            $q .= '&start=' . rawurlencode($pack['dates'][0]) . '&end=' . rawurlencode($pack['dates'][count($pack['dates']) - 1]);
        }
        header('Location: ' . $q);
        exit;
    }
    try {
        $read = idi_read_invoice($pdo, $stored['dest'], $rid, $date);
    } catch (Throwable $e) {
        error_log('food_cost idi_read_invoice: ' . $e->getMessage());
        $read = [
            'ok' => false,
            'vendor' => '',
            'date' => $date,
            'invoice_no' => '',
            'amount' => 0.0,
            'items' => [],
            'unmatched' => [],
            'message' => 'Invoice saved. Could not read this file automatically.',
            'text' => '',
        ];
    }
    @file_put_contents($stored['dest'] . '.txt', $read['text'] ?? '');

    $vendor = substr(trim((string)($read['vendor'] ?? '')), 0, 255);
    if ($vendor === '') {
        $vendor = 'Unknown vendor';
    }
    $amount = (float)($read['amount'] ?? 0);
    $invDate = $read['date'] ?: $date;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$invDate)) {
        $invDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
    }
    $invNo = substr((string)($read['invoice_no'] ?? ''), 0, 50);
    if ($invNo === '') {
        $invNo = 'PENDING';
    }

    $ins = $pdo->prepare(
        'INSERT INTO FoodCost (InvoiceDate, RId, Vendor, Amount, InvoiceNumber, Invoice)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([$invDate, $rid, $vendor, $amount, $invNo, $stored['rel'] ?? '']);
    $newFcid = (int)$pdo->lastInsertId();
    idi_apply_invoice_for_site($pdo, $rid, $read['items'] ?? [], $read['unmatched'] ?? []);

    $_SESSION['invoice_delivery_prefill'] = idi_apply_same_date_prefill($pdo, $INVOICE_ROOT, $rid, [
        'rid' => $rid,
        'vendor' => $vendor,
        'invoice_num' => $invNo,
        'date' => $invDate,
        'amount' => $amount,
        'file' => $stored['rel'] ?? '',
        'items' => $read['items'] ?? [],
        'unmatched' => $read['unmatched'] ?? [],
        'message' => $read['message'] ?? '',
        'fcid' => $newFcid,
    ]);
    $_SESSION['invoice_delivery_prefill']['same_date_merged'] = 1;
    header('Location: inventory/index.php?rid=' . $rid . '&from_invoice=1');
    exit;
  } catch (Throwable $e) {
    error_log('food_cost upload: ' . $e->getMessage());
    $_SESSION['fc_flash'] = 'Upload failed. Try a PDF or a smaller photo.';
    header('Location: food_cost_view.php');
    exit;
  }
}

$start_date   = $_GET['start'] ?? date('Y-m-01');
$end_date     = $_GET['end'] ?? date('Y-m-d');
$selected_rid = $_GET['rid'] ?? '';
$fc_flash     = (string)($_SESSION['fc_flash'] ?? '');
unset($_SESSION['fc_flash']);

if ($selected_rid !== '' && $allowed_ids && !in_array((int)$selected_rid, $allowed_ids, true)) {
    $selected_rid = '';
}

$prbOrgId = (int)($selected_org ?? 0);
if ($prbOrgId <= 0 && (int)$selected_rid > 0) {
    $prbOrgId = prb_org_for_site($pdo, (int)$selected_rid);
}
if ($prbOrgId <= 0 && empty($is_super)) {
    $prbOrgId = (int)($current_org_id ?? 0);
}
$prbDays = $prbOrgId > 0 ? prb_org_days($pdo, $prbOrgId) : 7;
$fcRollbackList = [];
if ($is_admin && (int)$selected_rid > 0) {
    foreach (prb_list($pdo, 'food_cost', (int)$selected_rid) as $undo) {
        $sum = prb_fc_summary($undo);
        $sum['confirm'] = prb_fc_confirm($sum);
        $sum['when_label'] = prb_when_label((string)$sum['when']);
        $sum['date_label'] = $sum['date'] !== '' ? date('Y-m-d', strtotime($sum['date'])) : '';
        $sum['amount_label'] = $sum['amount'] !== '' ? number_format((float)$sum['amount'], 2) : '';
        $fcRollbackList[] = $sum;
    }
}

$sql = "SELECT fc.*, fc.FCId AS id, s.nick_name,
        (SELECT SUM(Amount) FROM FoodCost
         WHERE RId = fc.RId AND InvoiceDate = fc.InvoiceDate) as daily_site_total,
        (SELECT SUM(Amount) FROM FoodCost
         WHERE RId = fc.RId
         AND InvoiceDate BETWEEN DATE_FORMAT(fc.InvoiceDate, '%Y-%m-01') AND fc.InvoiceDate) as site_mtd
        FROM FoodCost fc
        JOIN sites s ON fc.RId = s.SId
        WHERE fc.InvoiceDate BETWEEN ? AND ?";

$params = [$start_date, $end_date];
if ($selected_org > 0) {
    $sql .= " AND s.org_id = ?";
    $params[] = $selected_org;
} elseif (!$is_super && $allowed_ids) {
    $sql .= " AND fc.RId IN (" . implode(",", array_map("intval", $allowed_ids)) . ")";
} elseif (!$is_super) {
    $sql .= " AND 1=0";
}
if ($selected_rid) {
    $sql .= " AND fc.RId = ?";
    $params[] = $selected_rid;
}
$sql .= " ORDER BY fc.InvoiceDate DESC, s.nick_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_period_sum = 0;
$fc_sum_daily = 0.0;
$fc_max_mtd = 0.0;
$fc_seen_days = [];
foreach ($records as $r) {
    $total_period_sum += (float)$r['Amount'];
    $gk = (string)$r['InvoiceDate'] . '|' . (string)$r['RId'];
    if (isset($fc_seen_days[$gk])) {
        continue;
    }
    $fc_seen_days[$gk] = true;
    $fc_sum_daily += (float)$r['daily_site_total'];
    $mtd = (float)$r['site_mtd'];
    if ($mtd > $fc_max_mtd) {
        $fc_max_mtd = $mtd;
    }
}
$invoice_count = count($records);
$subtotal_label = $invoice_count === 1 ? '1 invoice' : ($invoice_count . ' invoices');

function fc_money($n) {
    return '$' . number_format((float)$n, 2);
}

function fc_totals_row($amount, $daily, $mtd, $label) {
    return '<tr class="fc-total">'
        . '<th>Total</th>'
        . '<th colspan="2"><span class="sub-note">(' . htmlspecialchars($label) . ')</span></th>'
        . '<th class="money-cell">' . fc_money($amount) . '</th>'
        . '<th></th>'
        . '<th class="money-cell">' . fc_money($daily) . '</th>'
        . '<th class="money-cell">' . fc_money($mtd) . '</th>'
        . '<th></th>'
        . '</tr>';
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Food Cost Daily Audit | SIP</title>
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; padding-top: 130px; }
        .container { padding: 20px; max-width: 1400px; margin: auto; }
        .filter-bar { background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 15px; align-items: flex-end; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .fc-head { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 15px; }
        .fc-head-left { display: flex; align-items: center; min-height: 36px; }
        .fc-head-right { display: flex; align-items: center; gap: 16px; }
        .fc-head h2 { font-weight: 800; margin: 0; color: #0f172a; text-align: right; }
        .fc-head .pg-help-panel { margin: 0; }
        .fc-chip { text-align: right; background: white; padding: 10px 20px; border-radius: 8px; border: 1px solid #e2e8f0; }
        .table-wrap { max-height: calc(100vh - 160px); overflow: auto; background: white; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .rpt-table { border-collapse: separate; border-spacing: 0; width: 100%; background: white; }
        .rpt-table th { background: #0f172a; color: white; padding: 12px; text-align: left; font-size: 11px; text-transform: uppercase; }
        .rpt-table td { padding: 12px; border-bottom: 1px solid #e2e8f0; font-size: 13px; background: #fff; }
        .rpt-table thead th { position: sticky; top: 0; z-index: 5; }
        .rpt-table thead tr.fc-total th,
        .rpt-table tfoot tr.fc-total th {
            background: #e2e8f0; color: #0f172a; text-transform: none;
            font-size: 13px; font-weight: 800; padding: 10px 12px;
        }
        .rpt-table thead tr.fc-total th { top: 42px; z-index: 5; box-shadow: 0 1px 0 #cbd5e1; }
        .dal-filepick { position: relative; display: flex; align-items: center; gap: 6px; cursor: pointer; width: 168px; max-width: 100%; }
        .dal-filepick input[type="file"] { position: absolute; inset: 0; opacity: 0; width: 100%; height: 100%; cursor: pointer; }
        .dal-filepick-btn { flex: 0 0 auto; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; padding: 6px 8px; font-size: 11px; font-weight: 800; color: #0f172a; white-space: nowrap; }
        .dal-filepick-name { font-size: 11px; color: #94a3b8; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
        .sheet-input { width: 100%; border: 1px solid #cbd5e1; padding: 6px; border-radius: 4px; box-sizing: border-box; }
        .new-row { background: #ecfdf5; border-bottom: 2px solid #10b981; }
        .btn-add { background: #10b981; color: white; border: none; padding: 10px; border-radius: 4px; cursor: pointer; width: 100%; font-weight: bold; }
        .btn-up { background: #1d4ed8; color: white; border: none; padding: 10px; border-radius: 4px; cursor: pointer; width: 100%; font-weight: bold; }
        .btn-edit { background:#1d4ed8; color:#fff; border:none; padding:6px 10px; border-radius:4px; font-size:11px; font-weight:800; cursor:pointer; text-decoration:none; display:inline-block; }
        .btn-del { background:#ef4444; color:#fff; border:none; padding:6px 10px; border-radius:4px; font-size:11px; font-weight:800; cursor:pointer; }
        .btn-rollback { background:#0f766e; color:#fff; border:none; padding:8px 14px; border-radius:6px; font-size:12px; font-weight:800; cursor:pointer; }
        .prb-box { background:#f0fdfa; border:1px solid #99f6e4; border-radius:8px; padding:12px 14px; margin:0 0 14px; }
        .prb-box h3 { margin:0 0 4px; font-size:13px; color:#115e59; }
        .prb-note { margin:0 0 8px; font-size:12px; color:#0f766e; font-weight:600; }
        .prb-table { width:100%; border-collapse:collapse; font-size:12px; }
        .prb-table th { text-align:left; color:#64748b; font-size:10px; text-transform:uppercase; padding:4px 6px; }
        .prb-table td { padding:5px 6px; border-top:1px solid #ccfbf1; }
        .prb-table .money-cell { font-weight:800; }
        .prb-box .btn-rollback { margin-top:10px; }
        .act-cell { white-space:nowrap; text-align:center; }
        .act-cell .btn-edit, .act-cell .btn-del { margin:2px; }
        .money-cell { font-family: 'Monaco', monospace; font-weight: 700; }
        .daily-total-badge { background: #fef9c3; color: #854d0e; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 800; border: 1px solid #fde047; display: inline-block; }
        .mtd-badge { background: #dcfce7; color: #166534; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 800; border: 1px solid #bbf7d0; display: inline-block; }
        .flash { background:#fef3c7; border:1px solid #fcd34d; color:#92400e; padding:10px 14px; border-radius:8px; margin-bottom:12px; font-weight:600; }
        .hint { font-size:12px; color:#64748b; margin:0 0 12px; }
        .amt-ro { font-weight: 800; color: #0f172a; }
        .amt-ro.muted { color: #94a3b8; font-weight: 700; }
        .subtotal-row td { background: #ecfdf5; font-weight: 800; border-top: 2px solid #10b981; border-bottom: 2px solid #10b981; }
        .subtotal-row .sub-note, .fc-total .sub-note { font-weight: 600; color: #64748b; font-size: 12px; }

        input.fc-manual-amt { width: 110px; max-width: 100%; padding: 4px 6px; border: 1px solid #cbd5e1; border-radius: 4px; font-weight: 800; font-size: 13px; color: #0f172a; font-family: inherit; }
        input.fc-manual-amt:focus { outline: 2px solid #2563eb; border-color: #2563eb; }
        input.fc-recheck-amt { border-color: #dc2626; background: #fef2f2; }
        .fc-recheck-hint { font-size: 9px; font-weight: 800; color: #dc2626; margin-top: 2px; }
        .fc-file-view { color: #fff; }
        #fcInvPreview { display: none; position: fixed; top: 75px; right: 0; width: min(400px, 96vw); height: calc(100vh - 75px); background: #fff; box-shadow: -10px 0 28px rgba(15,23,42,.22); z-index: 2147483000; flex-direction: column; }
        #fcInvPreview.open { display: flex; }
        body.fc-preview-open { overflow: hidden; }
        .fc-prev-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; padding: 12px 14px; background: #0f172a; color: #fff; }
        .fc-prev-head strong { display: block; font-size: 14px; }
        .fc-prev-head span { display: block; font-size: 11px; color: #cbd5e1; margin-top: 3px; font-weight: 700; }
        .fc-prev-x { background: #334155; color: #fff; border: 0; border-radius: 4px; width: 30px; height: 30px; font-weight: 900; cursor: pointer; flex: 0 0 30px; }
        .fc-prev-x:hover { background: #475569; }
        .fc-prev-body { flex: 1 1 auto; min-height: 0; overflow: auto; background: #0f172a; position: relative; z-index: 1; isolation: isolate; }
        .fc-prev-body img { width: 100%; display: block; background: #fff; }
        .fc-prev-body iframe { display: block; width: 100%; height: 100%; min-height: 0; border: 0; background: #fff; }
        .fc-prev-empty { color: #e2e8f0; padding: 24px 16px; font-weight: 700; }
        .fc-prev-foot { padding: 12px 14px 16px; border-top: 1px solid #e2e8f0; background: #fff; position: relative; z-index: 5; isolation: isolate; }
        .fc-prev-foot label { display: block; font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 6px; }
        #fcPrevAmt { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 2px solid #2563eb; border-radius: 6px; font-size: 20px; font-weight: 800; color: #0f172a; pointer-events: auto; }
        .fc-prev-actions { display: flex; gap: 8px; margin-top: 10px; align-items: center; flex-wrap: wrap; }
        .fc-prev-save { background: #16a34a; color: #fff; border: 0; border-radius: 4px; padding: 8px 14px; font-weight: 800; cursor: pointer; }
        .fc-prev-save:hover { background: #15803d; }
        .fc-prev-full { color: #1d4ed8; font-weight: 800; font-size: 12px; }
        @media (max-width: 720px) { #fcInvPreview { width: 100vw; } }
    </style>
</head>
<body>
    <?= generateMenu($pdo); ?>
    <div class="container">
        <div class="fc-head">
            <div class="fc-head-left" id="fcHelpSlot"></div>
            <div class="fc-head-right">
                <h2>Food Cost Daily Audit</h2>
                <div class="fc-chip">
                    <small style="color:#64748b; font-weight:800; display:block; text-transform:uppercase;">Subtotal (<?= htmlspecialchars($subtotal_label) ?>)</small>
                    <span style="font-size:22px; font-weight:900; color:#059669;">$<?= number_format($total_period_sum, 2) ?></span>
                </div>
            </div>
        </div>
        <script>
        (function () {
            var panel = document.querySelector(".pg-help-from-nav");
            var slot = document.getElementById("fcHelpSlot");
            if (panel && slot) {
                slot.appendChild(panel);
                panel.style.maxWidth = "none";
                panel.style.margin = "0";
                panel.style.padding = "0";
            }
            var ico = document.querySelector("#pgHelpPanel .pg-help-toggle");
            if (ico) {
                ico.title = "Filter by date and site, then upload an invoice. Amount is filled from the file. Totals: Amount is a sum; Daily Site Total sums each store-day once; MTD Site Total is the highest MTD on this list.";
                ico.setAttribute("aria-label", "Help — how to filter and upload invoices");
            }
            var tour = document.getElementById("pgTourStartBtn");
            if (tour) tour.title = "Start audio visual tour";
        })();
        </script>

        <?php if ($fc_flash): ?>
            <div class="flash"><?= htmlspecialchars($fc_flash) ?></div>
        <?php endif; ?>
        <p class="hint">Upload the invoice only (PDF or photo). We fill Vendor and Amount from the file. Double-click a row (or Open file) to see the invoice beside the list and type Amount if it did not read.</p>

        <form method="GET" class="filter-bar">
            <div>
                <label style="font-size:10px; font-weight:800; color:#64748b; display:block; margin-bottom:3px;">START DATE</label>
                <input type="date" name="start" value="<?=$start_date?>" class="sheet-input">
            </div>
            <div>
                <label style="font-size:10px; font-weight:800; color:#64748b; display:block; margin-bottom:3px;">END DATE</label>
                <input type="date" name="end" value="<?=$end_date?>" class="sheet-input">
            </div>
            <?php if (!empty($is_super)): ?>
            <div>
                <label style="font-size:10px; font-weight:800; color:#64748b; display:block; margin-bottom:3px;">ORG</label>
                <select name="org_id" class="sheet-input" style="min-width:200px;" onchange="this.form.submit()">
                    <option value="all" <?= (int)$selected_org === 0 ? 'selected' : '' ?>>All organizations</option>
                    <?php foreach ($orgs as $o): ?>
                        <option value="<?= (int)$o['id'] ?>" <?= (int)$selected_org === (int)$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$o['org_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div>
                <label style="font-size:10px; font-weight:800; color:#64748b; display:block; margin-bottom:3px;">SITE PERMISSIONS</label>
                <select name="rid" class="sheet-input" style="min-width:220px;">
                    <option value="">-- All Assigned Sites --</option>
                    <?php foreach($sites as $s): ?>
                        <option value="<?=$s['SId']?>" <?=$selected_rid == $s['SId'] ? 'selected' : ''?>><?=$s['nick_name']?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" style="background:#0f172a; color:white; border:none; padding:10px 25px; border-radius:4px; cursor:pointer; font-weight:700;">FILTER</button>
        </form>
        <?php if ($is_admin && $prbOrgId > 0): ?>
        <div class="filter-bar" style="margin-top:-8px;">
            <div>
                <label style="font-size:10px; font-weight:800; color:#64748b; display:block; margin-bottom:3px;">ROLLBACK DAYS</label>
                <select id="prbDaysSel" class="sheet-input" title="How long Rollback lasts for this organization. Same on every computer.">
                    <?php foreach (prb_allowed_days() as $d): ?>
                    <option value="<?= (int)$d ?>" <?= (int)$prbDays === (int)$d ? 'selected' : '' ?>><?= (int)$d ?> day<?= $d === 1 ? '' : 's' ?><?= $d === 7 ? ' (default)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($fcRollbackList): ?>
        <div class="prb-box" id="fcRollbackBox">
            <h3>Rollback — this restaurant, <?= (int)$prbDays ?> day<?= $prbDays === 1 ? '' : 's' ?></h3>
            <p class="prb-note">Tick the delete(s) to put back. Other deletes stay. All Sites hides this list.</p>
            <table class="prb-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>Date</th>
                        <th>Vendor</th>
                        <th>Amount</th>
                        <th>Invoice #</th>
                        <th>Who</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($fcRollbackList as $u): ?>
                    <tr>
                        <td><input type="checkbox" class="prb-pick" value="<?= (int)$u['id'] ?>" data-confirm="<?= htmlspecialchars((string)$u['confirm']) ?>" data-line="<?= htmlspecialchars(trim(($u['vendor'] !== '' ? $u['vendor'] : 'invoice') . ($u['amount_label'] !== '' ? ' $' . $u['amount_label'] : '') . ($u['date'] !== '' ? ' ' . date('M j', strtotime($u['date'])) : ''))) ?>"></td>
                        <td><?= htmlspecialchars((string)$u['date_label']) ?></td>
                        <td><?= htmlspecialchars((string)$u['vendor']) ?></td>
                        <td class="money-cell"><?= $u['amount_label'] !== '' ? '$' . htmlspecialchars((string)$u['amount_label']) : '' ?></td>
                        <td><?= htmlspecialchars((string)$u['invoice']) ?></td>
                        <td><?= htmlspecialchars((string)$u['who']) ?></td>
                        <td><?= htmlspecialchars((string)$u['when_label']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="btn-rollback" id="fcRollbackBtn" title="Restore only the ticked deletes for this restaurant.">Rollback selected</button>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="invUploadForm">
            <input type="hidden" name="action" value="upload_invoice">
        </form>
        <div class="table-wrap">
        <table class="rpt-table">
            <thead>
                <tr>
                    <th width="12%">Invoice Date</th>
                    <th width="14%">Site</th>
                    <th width="16%">Vendor</th>
                    <th width="12%">Amount</th>
                    <th width="16%">Invoice file</th>
                    <th width="12%">Daily Site Total</th>
                    <th width="12%">MTD Site Total</th>
                    <th width="10%" style="text-align:center;">Edit / Del</th>
                </tr>
                <?php if (!empty($records)): ?>
                <?= fc_totals_row($total_period_sum, $fc_sum_daily, $fc_max_mtd, $subtotal_label) ?>
                <?php endif; ?>
            </thead>
            <tbody>
                <tr class="new-row">
                    <td><input form="invUploadForm" type="date" name="InvoiceDate" id="new_date" class="sheet-input" value="<?= date('Y-m-d') ?>"></td>
                    <td>
                        <select form="invUploadForm" name="RId" id="new_rid" class="sheet-input">
                            <?php foreach($sites as $s): ?>
                                <option value="<?= $s['SId'] ?>" <?=$selected_rid == $s['SId'] ? 'selected' : ''?>><?= htmlspecialchars($s['nick_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><span class="amt-ro muted">From invoice</span></td>
                    <td><span class="amt-ro muted">From invoice</span></td>
                    <td>
                        <label class="dal-filepick">
                            <input form="invUploadForm" type="file" name="invoice_file" id="new_invoice_file" accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,image/*" required>
                            <span class="dal-filepick-btn">Choose file</span>
                            <span class="dal-filepick-name">empty</span>
                        </label>
                    </td>
                    <td colspan="3">
                        <label style="display:flex;align-items:center;gap:8px;margin:0 0 8px;font-size:12px;font-weight:700;color:#0f172a;cursor:pointer;">
                            <input form="invUploadForm" type="checkbox" name="split_by_date" value="1" style="width:16px;height:16px;">
                            Combined PDF — post each invoice on its invoice date
                        </label>
                        <button form="invUploadForm" type="submit" class="btn-up"><i class="fa fa-upload"></i> UPLOAD INVOICE</button>
                    </td>
                </tr>

                <?php
                $last_group_key = '';
                if (empty($records)): ?>
                    <tr><td colspan="8" style="text-align:center; padding: 50px; color:#94a3b8;">No records found for assigned sites.</td></tr>
                <?php else:
                    foreach ($records as $r):
                        $current_group_key = $r['InvoiceDate'] . $r['RId'];
                        $is_first_of_group = ($current_group_key !== $last_group_key);
                        $last_group_key = $current_group_key;
                    ?>
                    <tr class="fc-data-row" data-id="<?= (int)$r['id'] ?>" data-amount="<?= htmlspecialchars(number_format((float)$r['Amount'], 2, '.', '')) ?>" data-date="<?= htmlspecialchars((string)$r['InvoiceDate']) ?>" data-name="<?= htmlspecialchars((string)$r['nick_name']) ?>" data-vendor="<?= htmlspecialchars((string)$r['Vendor']) ?>">
                        <td><strong><?= date('M d, Y', strtotime($r['InvoiceDate'])) ?></strong></td>
                        <td><span style="color:#2563eb; font-weight:700;"><?= htmlspecialchars($r['nick_name']) ?></span></td>
                        <td><span class="amt-ro"><?= htmlspecialchars($r['Vendor']) ?></span></td>
                        <td class="money-cell"><?php
                            $fcAmt = (float)$r['Amount'];
                            $fcUnread = (abs($fcAmt) < 0.005);
                            $fcAmtStr = htmlspecialchars(number_format($fcAmt, 2, '.', ''));
                        ?><input type="number" step="0.01" class="fc-manual-amt<?= $fcUnread ? ' fc-recheck-amt' : '' ?>" data-id="<?= (int)$r['id'] ?>" data-field="Amount" value="<?= $fcAmtStr ?>" title="Type Amount if the invoice did not read. Double-click to see the file."><?php if ($fcUnread): ?><div class="fc-recheck-hint">Type if unread</div><?php endif; ?></td>
                        <td style="font-size:12px; color:#334155;">
                            <?php if (!empty($r['Invoice'])):
                                $fcExt = strtolower(pathinfo((string)$r['Invoice'], PATHINFO_EXTENSION));
                                $fcPdf = ($fcExt === 'pdf' || $fcExt === '');
                                $fcLab = $fcPdf ? 'PDF' : strtoupper($fcExt === 'jpeg' ? 'JPG' : ($fcExt ?: 'File'));
                                $fcIcon = $fcPdf ? 'fa-file-pdf' : 'fa-file-image';
                            ?>
                                <a class="btn-edit fc-file-view" href="food_cost_view.php?action=dl&fcid=<?= (int)$r['id'] ?>" data-fcid="<?= (int)$r['id'] ?>" data-ext="<?= htmlspecialchars($fcExt) ?>" data-amount="<?= $fcAmtStr ?>" data-date="<?= htmlspecialchars((string)$r['InvoiceDate']) ?>" data-name="<?= htmlspecialchars((string)$r['nick_name']) ?>" data-vendor="<?= htmlspecialchars((string)$r['Vendor']) ?>" onclick="if(window.fcOpenFilePreview){window.fcOpenFilePreview(this);return false;}" title="Click to preview beside the list"><i class="fa <?= $fcIcon ?>"></i> View <?= htmlspecialchars($fcLab) ?></a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="money-cell">
                            <?php if($is_first_of_group): ?>
                                <div class="daily-total-badge">$<?= number_format((float)$r['daily_site_total'], 2) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="money-cell">
                            <?php if($is_first_of_group): ?>
                                <div class="mtd-badge">$<?= number_format((float)$r['site_mtd'], 2) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="act-cell">
                            <?php if (!empty($r['Invoice'])): ?>
                                <a class="btn-edit" style="background:#0f766e;" href="food_cost_view.php?action=to_inventory&fcid=<?= (int)$r['id'] ?>"><i class="fa fa-boxes"></i> Inventory</a>
                            <?php endif; ?>
                            <a class="btn-edit" href="edit_food_cost.php?id=<?= (int)$r['id'] ?>"><i class="fa fa-pen"></i> Edit</a>
                            <button type="button" class="btn-del" onclick="deleteRecord(<?= (int)$r['id'] ?>, <?= htmlspecialchars(json_encode((string)($r['Vendor'] ?? 'invoice')), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode(number_format((float)$r['Amount'], 2, '.', '')), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode(!empty($r['InvoiceDate']) ? date('M j', strtotime((string)$r['InvoiceDate'])) : ''), ENT_QUOTES) ?>)"><i class="fa fa-trash"></i> Del</button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($records)): ?>
            <tfoot>
                <?= fc_totals_row($total_period_sum, $fc_sum_daily, $fc_max_mtd, $subtotal_label) ?>
            </tfoot>
            <?php endif; ?>
        </table>
        </div>
    </div>

<aside id="fcInvPreview" aria-hidden="true">
    <div class="fc-prev-head">
        <div>
            <strong id="fcPrevTitle">Invoice</strong>
            <span id="fcPrevSub"></span>
        </div>
        <button type="button" class="fc-prev-x" id="fcPrevClose" title="Close">&times;</button>
    </div>
    <div class="fc-prev-body" id="fcPrevBody"></div>
    <div class="fc-prev-foot" id="fcPrevFoot">
        <label for="fcPrevAmt">Amount $</label>
        <input type="number" step="0.01" id="fcPrevAmt" placeholder="0.00">
        <div class="fc-prev-actions">
            <button type="button" class="fc-prev-save" id="fcPrevSave">Save figure</button>
            <button type="button" class="fc-prev-x" id="fcPrevClose2" title="Close" style="width:auto;padding:0 10px;height:32px;">Close</button>
            <a class="fc-prev-full" id="fcPrevFull" href="#" target="_blank" rel="noopener">Open full size</a>
        </div>
    </div>
</aside>

<script>
function deleteRecord(id, vendor, amount, date) {
    var vend = vendor || 'invoice';
    var msg = 'Delete this ' + vend + ' invoice' + (amount ? ' $' + amount : '') + (date ? ' on ' + date : '') + '?\n\nOK = delete this row only. Cancel = keep it. Other invoices that day stay.';
    if (!confirm(msg)) return;
    $.post('save_food_cost_api.php', { action: 'delete_food_cost', id: id }, function(res) {
        if(res.success) location.reload();
        else alert(res.error || 'Delete failed');
    }, 'json').fail(function() { alert('Delete failed'); });
}
function rollbackFoodCost() {
    var picks = document.querySelectorAll('#fcRollbackBox .prb-pick:checked');
    if (!picks.length) {
        alert('Tick the invoice(s) to restore.');
        return;
    }
    var ids = [];
    var lines = [];
    var firstConfirm = '';
    picks.forEach(function (cb) {
        ids.push(cb.value);
        lines.push(cb.getAttribute('data-line') || '');
        if (!firstConfirm) firstConfirm = cb.getAttribute('data-confirm') || '';
    });
    var msg = picks.length === 1
        ? (firstConfirm || 'Restore this invoice?')
        : ('Restore these ' + picks.length + ' invoices only?\n\n' + lines.join('\n') + '\n\nOK = put the ticked ones back. Other deletes stay.');
    if (!confirm(msg)) return;
    $.post('save_food_cost_api.php', { action: 'rollback_food_cost', site_id: <?= (int)$selected_rid ?>, ids: ids }, function(res) {
        if(res.success) location.reload();
        else alert(res.error || 'Rollback failed');
    }, 'json').fail(function() { alert('Rollback failed'); });
}
document.addEventListener('DOMContentLoaded', function () {
    var b = document.getElementById('fcRollbackBtn');
    if (b) b.addEventListener('click', rollbackFoodCost);
    var daysSel = document.getElementById('prbDaysSel');
    if (daysSel) {
        daysSel.addEventListener('change', function () {
            $.post('save_food_cost_api.php', {
                action: 'set_rollback_days',
                org_id: <?= (int)$prbOrgId ?>,
                site_id: <?= (int)$selected_rid ?>,
                days: daysSel.value
            }, function (res) {
                if (!res || !res.success) alert((res && res.error) || 'Could not save Rollback days');
            }, 'json').fail(function () { alert('Could not save Rollback days'); });
        });
    }
});
(function () {
    document.querySelectorAll(".dal-filepick input[type=file]").forEach(function (inp) {
        inp.addEventListener("change", function () {
            var name = inp.parentNode.querySelector(".dal-filepick-name");
            if (!name) return;
            name.textContent = (inp.files && inp.files[0]) ? inp.files[0].name : "empty";
        });
    });
})();

(function() {
    function el(id) { return document.getElementById(id); }
    var panel = el('fcInvPreview');
    var bodyEl = el('fcPrevBody');
    var titleEl = el('fcPrevTitle');
    var subEl = el('fcPrevSub');
    var amtEl = el('fcPrevAmt');
    var fullEl = el('fcPrevFull');
    var saveBtn = el('fcPrevSave');
    var state = { href: '', fcid: 0 };

    function focusAmt() {
        if (!amtEl) return;
        try { amtEl.focus(); amtEl.select(); } catch (e) {}
    }
    function closePreview() {
        if (!panel) return;
        panel.classList.remove('open');
        panel.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('fc-preview-open');
        if (bodyEl) bodyEl.innerHTML = '';
    }
    function openPreview(opts) {
        if (!panel || !bodyEl) return;
        state.href = opts.href || '';
        state.fcid = parseInt(opts.fcid || '0', 10) || 0;
        titleEl.textContent = 'Invoice';
        subEl.textContent = [opts.vendor, opts.name, opts.date].filter(Boolean).join(' · ');
        amtEl.value = opts.amount || '';
        if (fullEl) {
            fullEl.href = state.href || '#';
            fullEl.style.display = state.href ? 'inline' : 'none';
        }
        bodyEl.innerHTML = '';
        var ext = (opts.ext || '').toLowerCase();
        if (!state.href) {
            bodyEl.innerHTML = '<div class="fc-prev-empty">No file on this invoice.</div>';
        } else if (ext === 'pdf' || ext === '') {
            var fr = document.createElement('iframe');
            fr.src = state.href;
            fr.title = 'Invoice';
            fr.addEventListener('load', function() { setTimeout(focusAmt, 80); });
            bodyEl.appendChild(fr);
        } else {
            var img = document.createElement('img');
            img.src = state.href;
            img.alt = 'Invoice';
            bodyEl.appendChild(img);
        }
        panel.classList.add('open');
        panel.setAttribute('aria-hidden', 'false');
        document.body.classList.add('fc-preview-open');
        setTimeout(focusAmt, 80);
        setTimeout(focusAmt, 400);
    }
    function fromLink(a) {
        if (!a) return;
        var $row = $(a).closest('tr.fc-data-row');
        openPreview({
            href: a.getAttribute('href') || '',
            ext: a.getAttribute('data-ext') || '',
            fcid: a.getAttribute('data-fcid') || $row.attr('data-id') || '0',
            amount: a.getAttribute('data-amount') || $row.find('input.fc-manual-amt').val() || $row.attr('data-amount') || '',
            date: a.getAttribute('data-date') || $row.attr('data-date') || '',
            name: a.getAttribute('data-name') || $row.attr('data-name') || '',
            vendor: a.getAttribute('data-vendor') || $row.attr('data-vendor') || ''
        });
    }
    window.fcOpenFilePreview = fromLink;

    document.addEventListener('click', function(e) {
        var t = e.target;
        if (t && t.nodeType === 3) t = t.parentElement;
        var a = t && t.closest ? t.closest('a.fc-file-view') : null;
        if (!a) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey) return;
        e.preventDefault();
        e.stopPropagation();
        fromLink(a);
    }, true);
    $(document).on('dblclick', 'tr.fc-data-row td, tr.fc-data-row input.fc-manual-amt', function(e) {
        if ($(e.target).closest('a,button').length && !$(e.target).closest('a.fc-file-view, input.fc-manual-amt').length) return;
        if ($(e.target).closest('.act-cell, .btn-del, a.btn-edit:not(.fc-file-view)').length) return;
        e.preventDefault();
        var $row = $(this).closest('tr.fc-data-row');
        var a = $row.find('a.fc-file-view')[0];
        if (a) fromLink(a);
        else openPreview({
            href: '',
            fcid: $row.attr('data-id') || '0',
            amount: $row.find('input.fc-manual-amt').val() || $row.attr('data-amount') || '',
            date: $row.attr('data-date') || '',
            name: $row.attr('data-name') || '',
            vendor: $row.attr('data-vendor') || ''
        });
    });
    $('#fcPrevClose, #fcPrevClose2').on('click', closePreview);
    $('#fcPrevFoot').on('mousedown', function(e) {
        if (e.target && e.target.id === 'fcPrevAmt') return;
        setTimeout(focusAmt, 0);
    });
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') closePreview();
    });
    function saveAmt(id, val, after) {
        $.post('save_food_cost_api.php', { action: 'update_food_cost', id: id, field: 'Amount', value: val }, function(res) {
            if (res && res.success) { after(); return; }
            alert((res && res.error) || 'Could not save.');
        }, 'json').fail(function() { alert('Could not save.'); });
    }
    $('#fcPrevSave').on('click', function() {
        var id = state.fcid;
        if (!id) { alert('No invoice on this row.'); return; }
        var val = (amtEl.value || '').trim();
        saveAmt(id, val, function() {
            var $row = $('tr.fc-data-row[data-id="' + id + '"]');
            $row.attr('data-amount', val);
            $row.find('input.fc-manual-amt').val(val);
            $row.find('a.fc-file-view').attr('data-amount', val);
            location.reload();
        });
    });
    if (amtEl) amtEl.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); saveBtn.click(); }
    });
    $(document).on('change', '.fc-manual-amt', function() {
        var inp = this;
        var id = parseInt(inp.getAttribute('data-id') || '0', 10);
        if (!id) { alert('No invoice on this row.'); return; }
        saveAmt(id, inp.value, function() { location.reload(); });
    });
})();
</script>
</body>
</html>
