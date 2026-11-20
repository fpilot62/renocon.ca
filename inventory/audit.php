<?php
/** Path: /inventory/audit.php — last count, usage between, latest count / audit date
 * Marker: inv-audit-recv-20261018
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/_org_sites.php';

function audit_ymd($s): string {
    $s = trim((string)$s);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : '';
}


function audit_last_usage_date(PDO $pdo, int $rid): string {
    if ($rid <= 0) {
        return '';
    }
    $st = $pdo->prepare('SELECT MAX(usage_date) FROM Gem_inventory_usage WHERE site_id = ?');
    $st->execute([$rid]);
    return audit_ymd((string)$st->fetchColumn());
}

function audit_usage_dates(PDO $pdo, int $rid, int $limit = 14): array {
    if ($rid <= 0) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT usage_date AS d
         FROM Gem_inventory_usage
         WHERE site_id = ?
         GROUP BY usage_date
         HAVING COUNT(*) > 0
         ORDER BY usage_date DESC
         LIMIT ' . (int)$limit
    );
    $st->execute([$rid]);
    $out = [];
    foreach ($st as $r) {
        $d = audit_ymd($r['d'] ?? '');
        if ($d !== '') {
            $out[] = $d;
        }
    }
    return $out;
}

function audit_count_dates(PDO $pdo, int $rid): array {
    if ($rid <= 0) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT DATE(count_date) AS d
         FROM Gem_inventory_audit
         WHERE site_id = ?
         GROUP BY DATE(count_date)
         ORDER BY d DESC'
    );
    $st->execute([$rid]);
    $out = [];
    foreach ($st as $r) {
        $d = audit_ymd($r['d'] ?? '');
        if ($d !== '') {
            $out[] = $d;
        }
    }
    return $out;
}

function audit_qty_on_date(PDO $pdo, int $rid, string $date): array {
    if ($rid <= 0 || $date === '') {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT item_id, MAX(qty_recorded) AS qty
         FROM Gem_inventory_audit
         WHERE site_id = ? AND DATE(count_date) = ?
         GROUP BY item_id'
    );
    $st->execute([$rid, $date]);
    $out = [];
    foreach ($st as $r) {
        $out[(int)$r['item_id']] = (float)$r['qty'];
    }
    return $out;
}


$countDates = audit_count_dates($pdo, (int)$rid);
$usageDates = audit_usage_dates($pdo, (int)$rid);
$lastUsage = audit_last_usage_date($pdo, (int)$rid);
$today = date('Y-m-d');

$askedStart = audit_ymd($_GET['start_date'] ?? '');
$askedEnd = audit_ymd($_GET['end_date'] ?? '');
$formAsked = isset($_GET['start_date']) || isset($_GET['end_date']);

$start_date = '';
$end_date = $lastUsage !== '' ? $lastUsage : $today;
foreach ($countDates as $d) {
    if ($d < $end_date && $d < $today) {
        $start_date = $d;
        break;
    }
}

if ($formAsked) {
    $end_date = $askedEnd !== '' ? $askedEnd : $end_date;
    if (isset($_GET['start_date']) && audit_ymd($_GET['start_date']) === '' && trim((string)($_GET['start_date'] ?? '')) === '') {
        $start_date = '';
    } elseif ($askedStart !== '') {
        $start_date = $askedStart;
    }
    /* Same-day start=end with no count on that day (old bookmark / fake count) */
    if ($askedStart !== '' && $askedStart === $askedEnd && !in_array($askedEnd, $countDates, true)) {
        $end_date = $lastUsage !== '' ? $lastUsage : $today;
        $start_date = '';
        foreach ($countDates as $d) {
            if ($d < $end_date && $d < $today) {
                $start_date = $d;
                break;
            }
        }
    }
}

if ($start_date !== '' && $end_date !== '' && $end_date < $start_date) {
    $end_date = $start_date;
}

$hasCountOnEnd = $end_date !== '' && in_array($end_date, $countDates, true);
$hasCountOnStart = $start_date !== '' && in_array($start_date, $countDates, true);
$usageFrom = $start_date !== ''
    ? date('Y-m-d', strtotime($start_date . ' +1 day'))
    : ($lastUsage !== '' ? date('Y-m-01', strtotime($lastUsage)) : '');
$usageDays = 0;
if ($start_date !== '' && $usageFrom !== '' && $end_date !== '' && $usageFrom <= $end_date) {
    $usageDays = (int)round((strtotime($end_date) - strtotime($usageFrom)) / 86400) + 1;
}

$opening = $hasCountOnStart ? audit_qty_on_date($pdo, (int)$rid, $start_date) : [];
$physical = $hasCountOnEnd ? audit_qty_on_date($pdo, (int)$rid, $end_date) : [];


$used = [];
if ((int)$rid > 0 && $end_date !== '' && $usageFrom !== '' && $usageFrom <= $end_date) {
    $st = $pdo->prepare(
        'SELECT item_id, SUM(sold_qty + waste_qty) AS qty
         FROM Gem_inventory_usage
         WHERE site_id = ? AND usage_date >= ? AND usage_date <= ?
         GROUP BY item_id'
    );
    $st->execute([(int)$rid, $usageFrom, $end_date]);
    foreach ($st as $r) {
        $used[(int)$r['item_id']] = (float)$r['qty'];
    }
}

$recv = [];
$recvFrom = $start_date !== '' ? $start_date : ($lastUsage !== '' ? date('Y-m-01', strtotime($lastUsage)) : $end_date);
try {
    $st = $pdo->prepare(
        'SELECT d.item_id,
                SUM(d.qty_received * IF(IFNULL(d.pcs_in_box, 0) > 0, d.pcs_in_box, 1)) AS qty
         FROM Gem_inventory_invoice_details d
         INNER JOIN Gem_inventory_invoices h ON h.id = d.invoice_id
         WHERE h.site_id = ? AND h.invoice_date > ? AND h.invoice_date <= ?
         GROUP BY d.item_id'
    );
    $st->execute([(int)$rid, $recvFrom, $end_date]);
    foreach ($st as $r) {
        $recv[(int)$r['item_id']] = (float)$r['qty'];
    }
} catch (Throwable $e) {
    $recv = [];
}

$recvQty = 0.0;
foreach ($recv as $q) {
    $recvQty += (float)$q;
}
$fcUnposted = [];
if ((int)$rid > 0 && $end_date !== '' && $recvFrom !== '' && $recvQty <= 0) {
    try {
        $st = $pdo->prepare(
            'SELECT FCId, InvoiceDate, Amount, InvoiceNumber, Vendor
             FROM FoodCost
             WHERE RId = ? AND InvoiceDate > ? AND InvoiceDate <= ?
             ORDER BY InvoiceDate, FCId'
        );
        $st->execute([(int)$rid, $recvFrom, $end_date]);
        $fcUnposted = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $fcUnposted = [];
    }
}

$itemIds = array_unique(array_merge(array_keys($opening), array_keys($physical), array_keys($used), array_keys($recv)));
$data = [];
if ($itemIds) {
    $in = implode(',', array_map('intval', $itemIds));
    $sql = "SELECT id, name, clearview_name, Unit, is_hot
            FROM Gem_inventory_items
            WHERE IsActive = 1 AND id IN ($in) AND id NOT IN (2, 6, 12)
            ORDER BY is_hot DESC, COALESCE(NULLIF(clearview_name, ''), name) ASC";
    foreach ($pdo->query($sql) as $r) {
        $id = (int)$r['id'];
        $data[] = [
            'Id' => $id,
            'Name' => (string)($r['clearview_name'] ?: $r['name']),
            'Unit' => (string)$r['Unit'],
            'opening_stock' => (float)($opening[$id] ?? 0),
            'total_received' => (float)($recv[$id] ?? 0),
            'total_consumed' => (float)($used[$id] ?? 0),
            'physical_actual' => $hasCountOnEnd ? (float)($physical[$id] ?? 0) : null,
            'is_hot' => (int)($r['is_hot'] ?? 0),
        ];
    }
}

$orgKeep = ((int)($selected_org ?? 0) > 0) ? ('&org_id=' . (int)$selected_org) : '';
require_once dirname(__DIR__) . '/engine/menu_generator.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Audit & Variance Engine | SIP</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f1f5f9; font-family: 'Inter', sans-serif; padding-top: 155px !important; }
        .report-card { background: white; border-radius: 15px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); border: none; margin-bottom: 50px; }
        .thead-dark th { background: #1e293b !important; color: #fff; font-size: 11px; text-transform: uppercase; border: none; letter-spacing: 0.5px; }
        .col-opening { background: #f8fafc; font-weight: 600; }
        .col-theo { background: #eff6ff; font-weight: 800; color: #1e40af; }
        .col-actual { background: #fffbeb; font-weight: 800; color: #92400e; }
        .recv-fc-hint { background:#fff7ed; border:1px solid #fdba74; color:#9a3412; border-radius:8px; padding:12px 14px; margin:0 0 16px; font-size:13px; font-weight:600; }
        .recv-fc-hint a { color:#0f766e; font-weight:800; }
        .v-badge { padding: 6px 12px; border-radius: 8px; font-weight: 900; display: inline-block; min-width: 80px; text-align: center; }
        .v-neg { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
        .v-pos { background: #d1fae5; color: #059669; border: 1px solid #a7f3d0; }
        .filter-panel { background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
        .audit-window { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e3a8a; border-radius: 10px; padding: 12px 16px; font-weight: 700; font-size: 13px; margin-bottom: 20px; }
        .audit-window strong { font-weight: 900; }
    </style>
</head>
<body>
    <?= generateMenu($pdo); ?>

    <div class="container-fluid px-5">
        <?php include "inventory_menu.php"; ?>

        <div class="report-card">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="font-weight-bold m-0" style="color:#1e293b;"><i class="fas fa-chart-pie text-primary mr-2"></i>Audit & Variance Report</h2>
                    <p class="text-muted small mb-0">Last count (A) + Received (B) − production/usage through the last production date (C) = Theoretical. Today is not a count unless you saved Physical Count.</p>
                </div>
                <button class="btn btn-dark btn-sm shadow-sm px-4" style="font-weight:700; border-radius:6px;" onclick="window.print()">
                    <i class="fas fa-print mr-2"></i>PRINT DISCREPANCY SHEET
                </button>
            </div>

            <form method="GET" class="filter-panel row align-items-end" id="auditFilterForm">
                <input type="hidden" name="rid" value="<?= (int)$rid ?>">
                <?php if ((int)($selected_org ?? 0) > 0): ?>
                <input type="hidden" name="org_id" value="<?= (int)$selected_org ?>">
                <?php endif; ?>
                <div class="col-md-4">
                    <label class="small font-weight-bold text-muted text-uppercase mb-2 d-block"><i class="fas fa-clipboard-check text-secondary mr-1"></i>Last count (opening)</label>
                    <select name="start_date" class="form-control font-weight-bold" style="border: 2px solid #cbd5e1; border-radius: 6px;">
                        <option value="" <?= $start_date === '' ? 'selected' : '' ?>>No count on file</option>
                        <?php foreach ($countDates as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>" <?= $d === $start_date ? 'selected' : '' ?>><?= htmlspecialchars(date('M d, Y', strtotime($d))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="small font-weight-bold text-muted text-uppercase mb-2 d-block"><i class="fas fa-calendar-check text-secondary mr-1"></i>Audit / latest count</label>
                    <select name="end_date" class="form-control font-weight-bold" style="border: 2px solid #cbd5e1; border-radius: 6px;">
                        <?php
                        $endOpts = [];
                        if ($lastUsage !== '') { $endOpts[$lastUsage] = date('M d, Y', strtotime($lastUsage)) . ' — last production'; }
                        $endOpts[$today] = date('M d, Y', strtotime($today)) . ' — today';
                        foreach ($usageDates as $d) {
                            if (!isset($endOpts[$d])) { $endOpts[$d] = date('M d, Y', strtotime($d)) . ' — production'; }
                        }
                        foreach ($countDates as $d) {
                            if (!isset($endOpts[$d])) { $endOpts[$d] = date('M d, Y', strtotime($d)) . ' — count'; }
                        }
                        uksort($endOpts, static function ($a, $b) { return strcmp($b, $a); });
                        foreach ($endOpts as $d => $label):
                        ?>
                        <option value="<?= htmlspecialchars($d) ?>" <?= $d === $end_date ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary btn-block font-weight-bold shadow-sm" style="height: calc(1.5em + .75rem + 2px); background:#2563eb; border:none; border-radius:6px; text-transform:uppercase; letter-spacing:0.5px;">
                        <i class="fas fa-sync-alt mr-1"></i> Recalculate
                    </button>
                </div>
            </form>

            <div class="audit-window" id="auditWindow">
                <?php if ($start_date === ''): ?>
                    <strong>Last count</strong> none on file
                    · <strong>Usage / production</strong>
                    <?php if ($lastUsage !== ''): ?>
                        through <?= htmlspecialchars(date('M d, Y', strtotime($end_date))) ?>
                        (last production <?= htmlspecialchars(date('M d, Y', strtotime($lastUsage))) ?>)
                    <?php else: ?>
                        none
                    <?php endif; ?>
                    · <strong>Latest count</strong> none — Current Physical stays blank until you do a Physical Count.
                    Used (C) is production through the audit date, not today.
                <?php else: ?>
                    <strong>Last count</strong> <?= htmlspecialchars(date('M d, Y', strtotime($start_date))) ?>
                    · <strong>Usage / production</strong>
                    <?php if ($usageFrom <= $end_date): ?>
                        <?= htmlspecialchars(date('M d', strtotime($usageFrom))) ?>–<?= htmlspecialchars(date('M d, Y', strtotime($end_date))) ?>
                        (<?= (int)$usageDays ?> day<?= $usageDays === 1 ? '' : 's' ?> after the count)
                    <?php else: ?>
                        none (audit date is the count day)
                    <?php endif; ?>
                    · <strong>Latest count</strong>
                    <?php if ($hasCountOnEnd): ?>
                        <?= htmlspecialchars(date('M d, Y', strtotime($end_date))) ?>
                    <?php else: ?>
                        none on <?= htmlspecialchars(date('M d, Y', strtotime($end_date))) ?> — Current Physical is blank until you count.
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($fcUnposted)): ?>
            <div class="recv-fc-hint">
                Recv (B) is 0 because Food Cost invoices in this window are not posted to Inventory.
                Food Cost stores the PDF total; Audit Received only counts itemized <strong>SAVE TRACKED DELIVERY</strong> lines.
                <ul class="mb-1 mt-2 pl-3">
                    <?php foreach ($fcUnposted as $fc):
                        $fcAmt = number_format((float)($fc['Amount'] ?? 0), 2);
                        $fcDay = !empty($fc['InvoiceDate']) ? date('M j', strtotime((string)$fc['InvoiceDate'])) : '';
                        $fcVend = trim((string)($fc['Vendor'] ?? 'Invoice'));
                        $fcNo = trim((string)($fc['InvoiceNumber'] ?? ''));
                    ?>
                    <li>
                        <?= htmlspecialchars($fcVend) ?> invoice $<?= htmlspecialchars($fcAmt) ?> on <?= htmlspecialchars($fcDay) ?><?= $fcNo !== '' ? ' (#' . htmlspecialchars($fcNo) . ')' : '' ?>
                        is on Food Cost but not posted to Inventory.
                        <a href="../food_cost_view.php?action=to_inventory&amp;fcid=<?= (int)$fc['FCId'] ?>">Inventory</a>
                        — review qtys — SAVE TRACKED DELIVERY.
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <table class="table table-hover mb-0" id="auditTable">
                <thead class="thead-dark">
                    <tr>
                        <th class="pl-4 py-3">Item Name</th>
                        <th class="text-center col-opening py-3">Last count (A)</th>
                        <th class="text-center py-3">Recv (B)</th>
                        <th class="text-center py-3">Used since count (C)</th>
                        <th class="text-center col-theo py-3">Theo Balance</th>
                        <th class="text-center col-actual py-3">Latest count</th>
                        <th class="text-right pr-4 py-3">Variance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$data): ?>
                    <tr><td colspan="7" class="text-center text-muted font-weight-bold py-5">No counted or used items in this window.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($data as $r):
                        $open = (float)$r['opening_stock'];
                        $recvAmt = (float)$r['total_received'];
                        $usedAmt = (float)$r['total_consumed'];
                        $phys = $r['physical_actual'];
                        $theo = ($open + $recvAmt) - $usedAmt;
                        $hasPhys = $phys !== null;
                        $variance = $hasPhys ? ((float)$phys - $theo) : null;
                        $vClass = 'text-muted';
                        if ($variance !== null && abs($variance) >= 0.01) {
                            $vClass = ($variance < 0) ? 'v-neg' : 'v-pos';
                        }
                    ?>
                    <tr>
                        <td class="pl-4 align-middle">
                            <span style="font-size:13px; font-weight:800; color:#0f172a;"><?= strtoupper(htmlspecialchars($r['Name'])) ?></span><br>
                            <small class="text-muted font-weight-bold text-uppercase"><?= htmlspecialchars((string)$r['Unit']) ?></small>
                        </td>
                        <td class="text-center align-middle col-opening"><?= number_format($open, 2) ?></td>
                        <td class="text-center align-middle text-info font-weight-bold">+<?= number_format($recvAmt, 2) ?></td>
                        <td class="text-center align-middle text-danger font-weight-bold">-<?= number_format($usedAmt, 2) ?></td>
                        <td class="text-center align-middle col-theo"><?= number_format($theo, 2) ?></td>
                        <td class="text-center align-middle col-actual"><?= $hasPhys ? number_format((float)$phys, 2) : '—' ?></td>
                        <td class="text-right align-middle pr-4">
                            <?php if ($variance === null): ?>
                            <span class="text-muted font-weight-bold">Count to finish</span>
                            <?php else: ?>
                            <span class="v-badge <?= $vClass ?>"><?= ($variance > 0 ? '+' : '') . number_format($variance, 2) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
