<?php
/**
 * Path: /partial_payments_review.php
 * Superadmin review of partial payments before anything is recorded.
 * Menu: Admin → Review (when that group exists) → Partial payments.
 * Min. Role Superadmin (UserRole 10).
 * Writes only admin_partial_payment_reviews.
 * Deploy with migrations/20261116_admin_partial_payment_reviews.sql.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';
require_once 'payroll_scope.php';
require_once __DIR__ . '/engine/admin_super_lib.php';
require_once __DIR__ . '/engine/attendance_integrity_lib.php';
require_once __DIR__ . '/engine/outstanding_wages_lib.php';
require_once __DIR__ . '/engine/partial_payments_review_lib.php';

ps_require_payroll_admin();
if (!ps_is_superadmin()) {
    http_response_code(403);
    exit('Superadmin access required.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_require_csrf();
}
if (is_file(__DIR__ . '/engine/menu_generator.php')) {
    require_once __DIR__ . '/engine/menu_generator.php';
}

function ppr_h($value): string
{
    $value = str_replace(["\r\n", "\r", "\n"], ' ', (string)$value);
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ppr_money($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    return number_format((float)$value, 2);
}

try {
    ppr_ensure($pdo);
    ppr_ensure_menu($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Partial payment review storage is not ready. ' . ppr_h($e->getMessage()));
}

$actorId = (int)$_SESSION['user_id'];
$orgId = 2;
$postedOrg = (int)($_POST['org_id'] ?? $_GET['org_id'] ?? 2);
if ($postedOrg !== 2) {
    http_response_code(403);
    exit('This review is for organization 2 only.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_review') {
    $wageId = (int)($_POST['wage_id'] ?? 0);
    $wage = null;
    foreach (ppr_fetch_candidates($pdo, $orgId) as $row) {
        if ((int)$row['id'] === $wageId) {
            $wage = $row;
            break;
        }
    }
    if (!$wage) {
        $_SESSION['ppr_flash'] = 'That outstanding-wage row is not a partial-payment candidate.';
        header('Location: partial_payments_review.php');
        exit;
    }
    $bounds = ppr_period_bounds($wage);
    $lines = [];
    if ($bounds['start'] && $bounds['end']) {
        $lines = ppr_fetch_lines($pdo, $orgId, (string)$wage['employee_name'], $bounds['start'], $bounds['end']);
    }
    $ids = array_map(static fn($line) => (int)$line['id'], $lines);
    $parsed = ppr_parse_save($_POST, $ids);
    if (!$parsed['ok']) {
        $_SESSION['ppr_flash'] = $parsed['error'];
    } else {
        try {
            ppr_save_review($pdo, $orgId, $wage, $parsed['fields'], $actorId);
            $_SESSION['ppr_flash'] = 'Review saved for ' . (string)$wage['employee_name'] . '. Payroll was not changed.';
        } catch (Throwable $e) {
            $_SESSION['ppr_flash'] = 'Save failed: ' . $e->getMessage();
        }
    }
    header('Location: partial_payments_review.php#wage-' . $wageId);
    exit;
}

$flash = (string)($_SESSION['ppr_flash'] ?? '');
unset($_SESSION['ppr_flash']);
$candidates = ppr_fetch_candidates($pdo, $orgId);
$saved = ppr_load_reviews($pdo, $orgId);
$views = [];
foreach ($candidates as $wage) {
    $bounds = ppr_period_bounds($wage);
    $lines = [];
    if ($bounds['start'] && $bounds['end']) {
        $lines = ppr_fetch_lines($pdo, $orgId, (string)$wage['employee_name'], $bounds['start'], $bounds['end']);
    }
    $views[] = ppr_review_for($pdo, $orgId, $wage, $lines, $saved[(int)$wage['id']] ?? null);
}
$csrf = ps_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Partial payments review</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f1f5f9; padding: 90px 16px 40px; color: #1e293b; font-size: 13px; }
        .sheet { background: #fff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
        .banner { background: #fef3c7; border: 1px solid #d97706; color: #78350f; border-radius: 12px; padding: 14px 16px; margin-bottom: 16px; font-weight: 700; }
        table.ledger th { background: #0f172a; color: #fff; font-size: 11px; text-transform: uppercase; }
        table.ledger td, table.ledger th { vertical-align: middle; }
        .money { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .flag { display: inline-block; background: #fee2e2; color: #991b1b; border-radius: 999px; padding: 2px 8px; margin: 2px 4px 2px 0; font-weight: 700; }
        .ok-flag { background: #dcfce7; color: #166534; }
        .store-collapse { background: none; border: 0; color: #0f172a; font-weight: 800; font-size: 16px; padding: 0; text-align: left; }
        .store-block.collapsed .store-body { display: none; }
        table.sortable th { cursor: pointer; white-space: nowrap; }
        table.sortable th .arrow { font-size: 10px; margin-left: 4px; opacity: 0.35; }
        table.sortable th.sort-asc .arrow, table.sortable th.sort-desc .arrow { opacity: 1; }
        tr.dup td { background: #fff7ed; }
        .note { min-width: 180px; }
    </style>
</head>
<body>
<?= function_exists('generateMenu') ? generateMenu($pdo) : '' ?>
<div class="container-fluid">
    <div class="banner" id="review-banner">
        This page records a review only. Saving does not write payroll_finalized or payroll_payments, and it does not mark anyone paid.
    </div>
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
        <div>
            <h4 class="font-weight-bold mb-1">Partial payments review</h4>
            <p class="text-muted mb-0">Organization 2. The son sheet supplies the gross only. CPP and EI are calculated from that gross by pe_line, including the pay-period CPP exemption. Income tax on this page starts at zero for every person, so net is gross minus CPP minus EI. Untick “No income tax” on a row to use pe_line’s tax as well. “net 1000 paid” stays $1,000.00. “half paid” does not guess an amount. Finalized payroll rows are not changed.</p>
        </div>
        <div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="collapse-all">Collapse all</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="expand-all">Expand all</button>
        </div>
    </div>
    <?php if ($flash !== ''): ?><div class="alert alert-info"><?= ppr_h($flash) ?></div><?php endif; ?>

    <div class="sheet">
        <h5>Candidates</h5>
        <?php if (!$views): ?>
            <p class="mb-0 text-muted">No outstanding-wage rows for organization 2 mention “net 1000 paid” or “half paid”.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0 sortable" id="candidate-summary">
                    <thead><tr>
                        <th data-sort-key="store" data-sort="text">Store<span class="arrow"></span></th>
                        <th data-sort-key="employee" data-sort="text">Employee<span class="arrow"></span></th>
                        <th data-sort-key="period" data-sort="period">Period<span class="arrow"></span></th>
                        <th class="money" data-sort-key="gross" data-sort="num">Son-sheet gross<span class="arrow"></span></th>
                        <th class="money" data-sort-key="cpp" data-sort="num">CPP<span class="arrow"></span></th>
                        <th class="money" data-sort-key="ei" data-sort="num">EI<span class="arrow"></span></th>
                        <th class="money" data-sort-key="tax" data-sort="num">Income tax<span class="arrow"></span></th>
                        <th class="money" data-sort-key="net" data-sort="num">Computed net<span class="arrow"></span></th>
                        <th class="money" data-sort-key="pay" data-sort="num">Payment per sheet<span class="arrow"></span></th>
                        <th class="money" data-sort-key="lines" data-sort="num">Finalized lines<span class="arrow"></span></th>
                        <th data-sort-key="flags" data-sort="text">Flags<span class="arrow"></span></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($views as $view):
                        $wage = $view['wage'];
                        $sheet = $view['sheet'];
                        $ded = is_array($view['deductions'] ?? null) ? $view['deductions'] : null;
                        ?>
                        <tr data-store="<?= ppr_h($wage['store_name']) ?>"
                            data-employee="<?= ppr_h($wage['employee_name']) ?>"
                            data-period="<?= ppr_h($wage['pay_period']) ?>"
                            data-period-start="<?= ppr_h((string)($view['bounds']['start'] ?? '')) ?>"
                            data-gross="<?= $view['gross'] === null ? '' : ppr_h(number_format((float)$view['gross'], 2, '.', '')) ?>"
                            data-cpp="<?= $ded ? ppr_h(number_format((float)$ded['cpp'], 2, '.', '')) : '' ?>"
                            data-ei="<?= $ded ? ppr_h(number_format((float)$ded['ei'], 2, '.', '')) : '' ?>"
                            data-tax="<?= $ded ? ppr_h(number_format((float)$ded['income_tax'], 2, '.', '')) : '' ?>"
                            data-net="<?= $ded ? ppr_h(number_format((float)$ded['net'], 2, '.', '')) : '' ?>"
                            data-pay="<?= $sheet['amount'] === null ? '' : ppr_h(number_format((float)$sheet['amount'], 2, '.', '')) ?>"
                            data-lines="<?= (int)$view['totals']['count'] ?>"
                            data-flags="<?= ppr_h(implode(' ', $view['flags'])) ?>">
                            <td><a href="#wage-<?= (int)$wage['id'] ?>"><?= ppr_h($wage['store_name']) ?></a><?php if ($wage['site_id']): ?> <span class="text-muted">#<?= (int)$wage['site_id'] ?></span><?php endif; ?></td>
                            <td><?= ppr_h($wage['employee_name']) ?></td>
                            <td><?= ppr_h($wage['pay_period']) ?><?php if ($view['bounds']['start']): ?><div class="text-muted"><?= ppr_h($view['bounds']['start']) ?> – <?= ppr_h($view['bounds']['end']) ?></div><?php endif; ?></td>
                            <td class="money"><?= ppr_money($view['gross']) ?></td>
                            <td class="money"><?= $ded ? ppr_money($ded['cpp']) : '—' ?></td>
                            <td class="money"><?= $ded ? ppr_money($ded['ei']) : '—' ?></td>
                            <td class="money"><?= $ded ? ppr_money($ded['income_tax']) : '—' ?></td>
                            <td class="money"><?= $ded ? ppr_money($ded['net']) : '—' ?></td>
                            <td class="money"><?= ppr_money($sheet['amount']) ?><?php if ($sheet['basis'] !== ''): ?><div class="text-muted"><?= ppr_h($sheet['basis']) ?></div><?php endif; ?></td>
                            <td class="money"><?= (int)$view['totals']['count'] ?></td>
                            <td><?php if (!$view['flags']): ?><span class="flag ok-flag">None</span><?php else: foreach ($view['flags'] as $flag): ?><span class="flag"><?= ppr_h($flag) ?></span><?php endforeach; endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php foreach ($views as $view):
        $wage = $view['wage'];
        $form = $view['form'];
        $tot = $view['totals'];
        $wid = (int)$wage['id'];
        ?>
        <div class="sheet store-block" id="wage-<?= $wid ?>" data-store-key="<?= $wid ?>">
            <button type="button" class="store-collapse" aria-expanded="true"><span class="caret">▾</span> <?= ppr_h($wage['store_name']) ?> · <?= ppr_h($wage['employee_name']) ?> · <?= ppr_h($wage['pay_period']) ?></button>
            <div class="store-body mt-3">
                <?php $ded = is_array($view['deductions'] ?? null) ? $view['deductions'] : null; ?>
                <p class="mb-2">Payment per sheet <strong><?= ppr_money($view['sheet']['amount']) ?></strong> <span class="text-muted"><?= ppr_h($view['sheet']['basis']) ?></span> · status <?= ppr_h($wage['status']) ?><?php if ($ded): ?> · CPP exemption <?= ppr_money($ded['cpp_exempt']) ?><?php endif; ?></p>
                <?php if (trim((string)($wage['notes'] ?? '')) !== ''): ?><p class="mb-2 text-muted">Notes: <?= ppr_h($wage['notes']) ?></p><?php endif; ?>
                <?php foreach ($view['flags'] as $flag): ?><span class="flag"><?= ppr_h($flag) ?></span><?php endforeach; ?>
                <?php if ($view['missing_dates']): ?>
                    <details class="mt-2">
                        <summary>Dates in the period with no finalized line (<?= count($view['missing_dates']) ?>)</summary>
                        <p class="mb-0 mt-1"><?= ppr_h(implode(', ', $view['missing_dates'])) ?></p>
                    </details>
                <?php endif; ?>

                <form method="POST" class="mt-3" action="partial_payments_review.php#wage-<?= $wid ?>">
                    <input type="hidden" name="csrf_token" value="<?= ppr_h($csrf) ?>">
                    <input type="hidden" name="org_id" value="2">
                    <input type="hidden" name="action" value="save_review">
                    <input type="hidden" name="wage_id" value="<?= $wid ?>">
                    <div class="form-row">
                        <div class="form-group col-md-2">
                            <label class="small font-weight-bold text-uppercase text-muted">Son-sheet gross</label>
                            <input class="form-control form-control-sm" name="son_sheet_gross" inputmode="decimal" required value="<?= $form['son_sheet_gross'] === null ? '' : ppr_h(number_format((float)$form['son_sheet_gross'], 2, '.', '')) ?>">
                        </div>
                        <div class="form-group col-md-2">
                            <label class="small font-weight-bold text-uppercase text-muted">CPP</label>
                            <div class="form-control form-control-sm bg-light"><?= $ded ? ppr_money($ded['cpp']) : '—' ?></div>
                        </div>
                        <div class="form-group col-md-2">
                            <label class="small font-weight-bold text-uppercase text-muted">EI</label>
                            <div class="form-control form-control-sm bg-light"><?= $ded ? ppr_money($ded['ei']) : '—' ?></div>
                        </div>
                        <div class="form-group col-md-2">
                            <label class="small font-weight-bold text-uppercase text-muted">Income tax</label>
                            <div class="form-control form-control-sm bg-light"><?= $ded ? ppr_money($ded['income_tax']) : '—' ?><?php if ((int)$form['skip_income_tax'] === 1): ?><div class="text-muted">No income tax</div><?php endif; ?></div>
                        </div>
                        <div class="form-group col-md-2">
                            <label class="small font-weight-bold text-uppercase text-muted">Computed net</label>
                            <div class="form-control form-control-sm bg-light font-weight-bold"><?= $ded ? ppr_money($ded['net']) : '—' ?></div>
                        </div>
                    </div>
                    <p class="text-muted">CPP and EI come from pe_line. With “No income tax” ticked, tax is 0 and net is gross minus CPP minus EI. Change the gross or the tick and save to recalculate. Payroll is not updated.</p>
                    <div class="form-group">
                        <input type="hidden" name="skip_income_tax" value="0">
                        <label class="mb-0 font-weight-bold"><input type="checkbox" name="skip_income_tax" value="1" <?= (int)$form['skip_income_tax'] === 1 ? 'checked' : '' ?>> No income tax (CPP + EI only)</label>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-2">
                            <label class="small font-weight-bold text-uppercase text-muted">Payment date</label>
                            <input type="date" class="form-control form-control-sm" name="payment_date" value="<?= ppr_h($form['payment_date']) ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label class="small font-weight-bold text-uppercase text-muted">Site paid from</label>
                            <input class="form-control form-control-sm" name="site_paid_from" maxlength="160" value="<?= ppr_h($form['site_paid_from']) ?>">
                        </div>
                        <div class="form-group col-md-2">
                            <label class="small font-weight-bold text-uppercase text-muted">Amount paid</label>
                            <input class="form-control form-control-sm" name="amount" inputmode="decimal" value="<?= $form['amount'] === null ? '' : ppr_h(number_format((float)$form['amount'], 2, '.', '')) ?>">
                        </div>
                        <div class="form-group col-md-2">
                            <label class="small font-weight-bold text-uppercase text-muted">Method</label>
                            <select class="form-control form-control-sm" name="method">
                                <?php foreach (ppr_methods() as $method): ?>
                                    <option <?= $form['method'] === $method ? 'selected' : '' ?>><?= ppr_h($method) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label class="d-block small font-weight-bold text-uppercase text-muted">Verified</label>
                            <label class="mb-0"><input type="checkbox" name="verified" value="1" <?= (int)$form['verified'] === 1 ? 'checked' : '' ?>> Reviewed and ready to record later</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="small font-weight-bold text-uppercase text-muted">Review notes</label>
                        <textarea class="form-control form-control-sm" name="notes" rows="2" maxlength="4000"><?= htmlspecialchars((string)$form['notes'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>

                    <h6 class="mt-2">Finalized payroll lines</h6>
                    <p class="text-muted">Stored gross, CPP, EI, tax, and paid amounts. These are not recalculated from the son-sheet gross.</p>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered ledger mb-2 sortable">
                            <thead><tr>
                                <th data-sort-key="id" data-sort="num">Id<span class="arrow"></span></th>
                                <th data-sort-key="date" data-sort="text">Date<span class="arrow"></span></th>
                                <th data-sort-key="site" data-sort="text">Site<span class="arrow"></span></th>
                                <th class="money" data-sort-key="gross" data-sort="num">Gross<span class="arrow"></span></th>
                                <th class="money" data-sort-key="cpp" data-sort="num">CPP<span class="arrow"></span></th>
                                <th class="money" data-sort-key="ei" data-sort="num">EI<span class="arrow"></span></th>
                                <th class="money" data-sort-key="tax" data-sort="num">Income tax<span class="arrow"></span></th>
                                <th class="money" data-sort-key="net" data-sort="num">Net<span class="arrow"></span></th>
                                <th class="money" data-sort-key="paid" data-sort="num">Paid<span class="arrow"></span></th>
                                <th data-sort-key="status" data-sort="text">Payment status<span class="arrow"></span></th>
                                <th>Verified</th>
                                <th>Correct site</th>
                            </tr></thead>
                            <tbody>
                            <?php if (!$view['lines']): ?>
                                <tr><td colspan="12" class="text-muted">No live finalized lines for this first name in the period.</td></tr>
                            <?php else: foreach ($view['lines'] as $line):
                                $lid = (int)$line['id'];
                                $shift = $view['shift_notes'][$lid] ?? ['verified' => 0, 'site_note' => ''];
                                $day = substr((string)$line['work_date'], 0, 10);
                                ?>
                                <tr class="<?= !empty($line['duplicate']) ? 'dup' : '' ?>"
                                    data-id="<?= $lid ?>"
                                    data-date="<?= ppr_h($day) ?>"
                                    data-site="<?= ppr_h((string)($line['site_name'] ?? '')) ?>"
                                    data-gross="<?= ppr_h(number_format((float)$line['total_gross'], 2, '.', '')) ?>"
                                    data-cpp="<?= ppr_h(number_format((float)$line['cpp'], 2, '.', '')) ?>"
                                    data-ei="<?= ppr_h(number_format((float)$line['ei'], 2, '.', '')) ?>"
                                    data-tax="<?= ppr_h(number_format((float)$line['income_tax'], 2, '.', '')) ?>"
                                    data-net="<?= ppr_h(number_format((float)$line['net'], 2, '.', '')) ?>"
                                    data-paid="<?= ppr_h(number_format((float)$line['paid_amount'], 2, '.', '')) ?>"
                                    data-status="<?= ppr_h((string)($line['payment_status'] ?? '')) ?>">
                                    <td><?= $lid ?><?php if (!empty($line['duplicate'])): ?><div class="flag">Duplicate</div><?php endif; ?></td>
                                    <td><?= ppr_h($day) ?></td>
                                    <td><?= ppr_h((string)($line['site_name'] ?? '')) ?><?php if ($line['site_id']): ?> <span class="text-muted">#<?= (int)$line['site_id'] ?></span><?php endif; ?><div class="text-muted"><?= ppr_h((string)$line['employee_name']) ?></div></td>
                                    <td class="money"><?= ppr_money($line['total_gross']) ?></td>
                                    <td class="money"><?= ppr_money($line['cpp']) ?><?php if (!empty($line['zero_cpp'])): ?><div class="flag">Zero CPP</div><?php endif; ?></td>
                                    <td class="money"><?= ppr_money($line['ei']) ?></td>
                                    <td class="money"><?= ppr_money($line['income_tax']) ?></td>
                                    <td class="money"><?= ppr_money($line['net']) ?></td>
                                    <td class="money"><?= ppr_money($line['paid_amount']) ?><div class="text-muted">left <?= ppr_money($line['unpaid']) ?></div></td>
                                    <td><?= ppr_h((string)($line['payment_status'] ?? '')) ?></td>
                                    <td class="text-center">
                                        <input type="hidden" name="shift[<?= $lid ?>][verified]" value="0">
                                        <input type="checkbox" name="shift[<?= $lid ?>][verified]" value="1" <?= (int)$shift['verified'] === 1 ? 'checked' : '' ?>>
                                    </td>
                                    <td><input class="form-control form-control-sm note" name="shift[<?= $lid ?>][site_note]" maxlength="255" value="<?= ppr_h($shift['site_note']) ?>" placeholder="Correct site?"></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                            <?php if ($view['lines']): ?>
                            <tfoot>
                                <tr>
                                    <td colspan="3"><strong><?= (int)$tot['count'] ?> line(s)</strong> · unpaid net <?= ppr_money($tot['unpaid']) ?><?php if ($view['short']): ?> <span class="flag">Below son-sheet payment</span><?php endif; ?></td>
                                    <td class="money"><strong><?= ppr_money($tot['gross']) ?></strong></td>
                                    <td class="money"><strong><?= ppr_money($tot['cpp']) ?></strong></td>
                                    <td class="money"><strong><?= ppr_money($tot['ei']) ?></strong></td>
                                    <td class="money"><strong><?= ppr_money($tot['tax']) ?></strong></td>
                                    <td class="money"><strong><?= ppr_money($tot['net']) ?></strong></td>
                                    <td class="money"><strong><?= ppr_money($tot['paid']) ?></strong></td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                    <button class="btn btn-warning font-weight-bold" type="submit">Save review</button>
                    <span class="text-muted ml-2">This writes the review table only.</span>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<script>
(function () {
    var keyName = 'ppr-collapse';
    function readCollapsed() {
        try { return JSON.parse(window.localStorage.getItem(keyName) || '{}') || {}; }
        catch (e) { return {}; }
    }
    function writeCollapsed(map) {
        try { window.localStorage.setItem(keyName, JSON.stringify(map)); } catch (e) {}
    }
    function setCollapsed(block, collapsed, map) {
        block.classList.toggle('collapsed', collapsed);
        var btn = block.querySelector('.store-collapse');
        if (btn) {
            btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            var caret = btn.querySelector('.caret');
            if (caret) caret.textContent = collapsed ? '▸' : '▾';
        }
        if (map) {
            var key = block.getAttribute('data-store-key');
            if (collapsed) map[key] = 1;
            else delete map[key];
        }
    }
    var collapsed = readCollapsed();
    document.querySelectorAll('.store-block').forEach(function (block) {
        setCollapsed(block, !!collapsed[block.getAttribute('data-store-key')], null);
        var btn = block.querySelector('.store-collapse');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var map = readCollapsed();
            setCollapsed(block, !block.classList.contains('collapsed'), map);
            writeCollapsed(map);
        });
    });
    document.getElementById('collapse-all').addEventListener('click', function () {
        var map = {};
        document.querySelectorAll('.store-block').forEach(function (block) { setCollapsed(block, true, map); });
        writeCollapsed(map);
    });
    document.getElementById('expand-all').addEventListener('click', function () {
        document.querySelectorAll('.store-block').forEach(function (block) { setCollapsed(block, false, null); });
        writeCollapsed({});
    });
    function sortValue(row, key, type) {
        if (type === 'period') {
            return { start: row.getAttribute('data-period-start') || '', text: (row.getAttribute('data-period') || '').toLowerCase() };
        }
        if (type === 'num') {
            var raw = row.getAttribute('data-' + key);
            if (raw === null || raw === '') return null;
            var n = parseFloat(raw);
            return isFinite(n) ? n : null;
        }
        return (row.getAttribute('data-' + key) || '').toLowerCase();
    }
    function compareSort(a, b, key, type, dir) {
        var av = sortValue(a, key, type);
        var bv = sortValue(b, key, type);
        var sign = dir === 'desc' ? -1 : 1;
        if (type === 'period') {
            if (av.start && bv.start && av.start !== bv.start) return av.start < bv.start ? -sign : sign;
            if (av.start && !bv.start) return -1;
            if (!av.start && bv.start) return 1;
            if (av.text === bv.text) return 0;
            return av.text < bv.text ? -sign : sign;
        }
        if (type === 'num') {
            if (av === null && bv === null) return 0;
            if (av === null) return 1;
            if (bv === null) return -1;
            if (av === bv) return 0;
            return av < bv ? -sign : sign;
        }
        if (av === bv) return 0;
        return av < bv ? -sign : sign;
    }
    document.querySelectorAll('table.sortable').forEach(function (table) {
        table.querySelectorAll('thead th[data-sort-key]').forEach(function (th) {
            th.addEventListener('click', function () {
                var key = th.getAttribute('data-sort-key');
                var type = th.getAttribute('data-sort') || 'text';
                var dir = th.classList.contains('sort-asc') ? 'desc' : 'asc';
                table.querySelectorAll('thead th').forEach(function (other) {
                    other.classList.remove('sort-asc', 'sort-desc');
                    var arrow = other.querySelector('.arrow');
                    if (arrow) arrow.textContent = '';
                });
                th.classList.add(dir === 'asc' ? 'sort-asc' : 'sort-desc');
                var arrow = th.querySelector('.arrow');
                if (arrow) arrow.textContent = dir === 'asc' ? '▲' : '▼';
                var body = table.tBodies[0];
                if (!body) return;
                var rows = Array.prototype.slice.call(body.rows).filter(function (row) { return row.hasAttribute('data-' + key) || row.hasAttribute('data-id') || row.hasAttribute('data-employee'); });
                rows.sort(function (a, b) { return compareSort(a, b, key, type, dir); });
                rows.forEach(function (row) { body.appendChild(row); });
            });
        });
    });
})();
</script>
</body>
</html>
