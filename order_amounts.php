<?php
/**
 * Path: /order_amounts.php
 * Superadmin food-order calculator for PK BC Food INC (org 2).
 * Reads daily sales only. It does not write sales or payroll.
 * Menu: Admin → Order Amounts, Min. Role Superadmin (UserRole 10).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';
require_once 'payroll_scope.php';
require_once __DIR__ . '/engine/admin_super_lib.php';
require_once __DIR__ . '/engine/order_amounts_lib.php';
ps_require_payroll_admin();
if (!ps_is_superadmin()) {
    http_response_code(403);
    exit('Superadmin access required.');
}
if (is_file(__DIR__ . '/engine/menu_generator.php')) {
    require_once __DIR__ . '/engine/menu_generator.php';
}
try {
    admin_super_ensure_menu($pdo, 'Order Amounts', 'order_amounts.php');
} catch (Throwable $e) {
    // The page still opens if the menu row cannot be added.
}

function oa_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$today = date('Y-m-d');
$food = oa_food_pct($_GET['food_pct'] ?? null);
$weeks = oa_weeks($_GET['weeks'] ?? 8);
$fromIn = trim((string)($_GET['from'] ?? ''));
$toIn = trim((string)($_GET['to'] ?? ''));
$useWeeks = isset($_GET['use_weeks']) || ($fromIn === '' && $toIn === '');
$buffer = (string)($_GET['buffer'] ?? '0') === '1';
[$from, $to, $weeks] = oa_resolve_window($weeks, $fromIn, $toIn, $today, $useWeeks);

$orgName = 'PK BC Food INC';
$error = '';
$pack = null;
try {
    $nameSt = $pdo->prepare('SELECT org_name FROM organizations WHERE id = ?');
    $nameSt->execute([oa_org_id()]);
    $found = $nameSt->fetchColumn();
    if (is_string($found) && trim($found) !== '') {
        $orgName = trim($found);
    }
    $pack = oa_load($pdo, $from, $to, $today, $food);
} catch (Throwable $e) {
    $error = $e->getMessage();
}
$weekNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order Amounts</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f1f5f9; padding: 90px 16px 40px; color: #1e293b; font-size: 13px; }
        .sheet { background: #fff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
        table.orders th { background: #0f172a; color: #fff; font-size: 11px; text-transform: uppercase; }
        table.orders td, table.orders th { vertical-align: middle; }
        .money { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .use-this { background: #ecfdf5; font-weight: 800; }
        .flag { display: inline-block; border-radius: 999px; padding: 2px 8px; font-size: 11px; font-weight: 800; margin: 0 4px 4px 0; }
        .flag-stale { background: #fee2e2; color: #991b1b; }
        .flag-thin { background: #ffedd5; color: #9a3412; }
        .flag-empty { background: #e2e8f0; color: #334155; }
        .detail td { background: #f8fafc; color: #475569; font-size: 12px; }
    </style>
</head>
<body>
<?= function_exists('generateMenu') ? generateMenu($pdo) : '' ?>
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
        <div>
            <h4 class="font-weight-bold mb-1">Order Amounts</h4>
            <p class="text-muted mb-0"><?= oa_h($orgName) ?> (org <?= (int)oa_org_id() ?>). Daily Activity Report sales only. Weekly and monthly summary rows are skipped. This page does not save anything.</p>
        </div>
    </div>

    <form method="GET" class="sheet">
        <div class="form-row align-items-end">
            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold text-uppercase text-muted">Food %</label>
                <input class="form-control form-control-sm" type="number" name="food_pct" min="0" max="100" step="0.01" value="<?= oa_h(rtrim(rtrim(number_format($food, 2, '.', ''), '0'), '.')) ?>">
            </div>
            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold text-uppercase text-muted">Lookback weeks</label>
                <input class="form-control form-control-sm" type="number" name="weeks" min="1" max="104" step="1" value="<?= (int)$weeks ?>">
            </div>
            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold text-uppercase text-muted">From</label>
                <input class="form-control form-control-sm" type="date" name="from" value="<?= oa_h($from) ?>">
            </div>
            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold text-uppercase text-muted">To</label>
                <input class="form-control form-control-sm" type="date" name="to" value="<?= oa_h($to) ?>">
            </div>
            <div class="col-md-2 mb-2">
                <label class="d-block small font-weight-bold text-uppercase text-muted">First order buffer</label>
                <input type="hidden" name="buffer" value="0">
                <label class="mb-0"><input type="checkbox" name="buffer" value="1" <?= $buffer ? 'checked' : '' ?>> Include 1-day buffer</label>
            </div>
            <div class="col-md-2 mb-2">
                <button class="btn btn-sm btn-primary btn-block" name="apply" value="1">Show orders</button>
                <button class="btn btn-sm btn-outline-secondary btn-block mt-1" name="use_weeks" value="1">Use last <?= (int)$weeks ?> weeks</button>
            </div>
        </div>
        <p class="mb-0 text-muted">Sites: Penticton 6, Westbank 11, Vernon 19, Merritt 20, Salmon Arm 21, Popeyes Harvey 23. Temp (103) is excluded. Zero-sales days are ignored. A store with no positive daily sales in the 14 days ending <?= oa_h($today) ?> is flagged.</p>
    </form>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= oa_h($error) ?></div>
    <?php elseif ($pack): ?>
        <div class="sheet">
            <h5 class="mb-1"><?= oa_h($from) ?> to <?= oa_h($to) ?> · <?= (int)$pack['span_days'] ?> calendar days · food <?= oa_h(rtrim(rtrim(number_format($food, 2, '.', ''), '0'), '.')) ?>%</h5>
            <p class="mb-2"><?= $buffer ? 'The green cells are the first Monday and first Friday orders, with the one-day buffer.' : 'The green cells are the ongoing Monday and Friday orders. The buffer columns are the first order only.' ?></p>
            <p class="text-muted mb-3">Monday order is Mon+Tue+Wed+Thu average sales times the food percent. Friday order is Fri+Sat+Sun average sales times the food percent. The first Monday adds Friday’s average times the food percent. The first Friday adds Monday’s average times the food percent. Week total is the Monday order plus the Friday order.</p>
            <div class="table-responsive">
                <table class="table table-sm table-bordered orders mb-0">
                    <thead>
                        <tr>
                            <th>Store</th>
                            <th class="money">Days</th>
                            <th class="money">Avg daily sales</th>
                            <th class="money">Monday order</th>
                            <th class="money">Friday order</th>
                            <th class="money">First Monday</th>
                            <th class="money">First Friday</th>
                            <th class="money">Week total</th>
                            <th>Flags</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pack['stores'] as $store): ?>
                        <tr>
                            <td>
                                <strong><?= oa_h($store['name']) ?></strong>
                                <span class="text-muted">#<?= (int)$store['site_id'] ?></span>
                            </td>
                            <td class="money"><?= (int)$store['days'] ?></td>
                            <td class="money"><?= oa_h(oa_money($store['avg_daily'])) ?></td>
                            <td class="money <?= !$buffer ? 'use-this' : '' ?>"><?= oa_h(oa_money($store['monday_order'])) ?></td>
                            <td class="money <?= !$buffer ? 'use-this' : '' ?>"><?= oa_h(oa_money($store['friday_order'])) ?></td>
                            <td class="money <?= $buffer ? 'use-this' : '' ?>"><?= oa_h(oa_money($store['first_monday'])) ?></td>
                            <td class="money <?= $buffer ? 'use-this' : '' ?>"><?= oa_h(oa_money($store['first_friday'])) ?></td>
                            <td class="money"><?= oa_h(oa_money($store['week_total'])) ?></td>
                            <td>
                                <?php if (!$store['in_org']): ?><span class="flag flag-empty">Not in org <?= (int)oa_org_id() ?></span><?php endif; ?>
                                <?php if ($store['empty']): ?><span class="flag flag-empty">No daily sales in this window</span><?php endif; ?>
                                <?php if ($store['little_data']): ?><span class="flag flag-thin">Little data (<?= (int)$store['days'] ?> days)</span><?php endif; ?>
                                <?php if ($store['stale']): ?><span class="flag flag-stale">No sales in the last 14 days</span><?php endif; ?>
                                <?php if ($store['latest']): ?><span class="text-muted">Latest <?= oa_h($store['latest']) ?></span><?php endif; ?>
                            </td>
                        </tr>
                        <tr class="detail">
                            <td colspan="9">
                                <?php foreach ($weekNames as $n => $label): ?>
                                    <?= oa_h($label) ?>
                                    <?= (int)$store['weekday_n'][$n] ?>d
                                    <?= oa_h($store['weekday_n'][$n] ? oa_money($store['weekday_avg'][$n]) : '—') ?>
                                    <?php if ($n < 7): ?> · <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if ($store['missing_weekdays'] && !$store['empty']): ?>
                                    <span class="flag flag-thin">No non-zero <?= oa_h(implode(', ', $store['missing_weekdays'])) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
