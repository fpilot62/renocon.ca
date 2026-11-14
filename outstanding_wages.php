<?php
/**
 * Path: /outstanding_wages.php
 * Superadmin tracking ledger. It does not create pay runs, approve payroll,
 * or mark anyone paid in the payroll tables.
 * Menu: Admin → Outstanding Wages, Min. Role Superadmin (UserRole 10).
 * Deploy with migrations/20261114_admin_outstanding_wages.sql.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';
require_once 'payroll_scope.php';
require_once __DIR__ . '/engine/admin_super_lib.php';
require_once __DIR__ . '/engine/outstanding_wages_lib.php';

$ajax = ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['ajax'] ?? '') === '1');
if ($ajax) {
    admin_super_require(true);
    ps_require_csrf();
} else {
    ps_require_payroll_admin();
    if (!ps_is_superadmin()) {
        http_response_code(403);
        exit('Superadmin access required.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        ps_require_csrf();
    }
}
if (is_file(__DIR__ . '/engine/menu_generator.php')) {
    require_once __DIR__ . '/engine/menu_generator.php';
}

function ow_h($value): string
{
    $value = str_replace(["\r\n", "\r", "\n"], ' ', (string)$value);
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ow_fail(string $message, bool $json): void
{
    if ($json) {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => $message]);
        exit;
    }
    $_SESSION['ow_flash'] = $message;
}

try {
    ow_ensure($pdo);
    admin_super_ensure_menu($pdo, 'Outstanding Wages', 'outstanding_wages.php');
} catch (Throwable $e) {
    if ($ajax) {
        ow_fail('Outstanding wages storage is not ready. ' . $e->getMessage(), true);
    }
    http_response_code(500);
    exit('Outstanding wages storage is not ready. ' . ow_h($e->getMessage()));
}

$actorId = (int)$_SESSION['user_id'];
$orgs = [];
try {
    $orgs = $pdo->query('SELECT id, org_name FROM organizations ORDER BY org_name ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $orgs = [];
}
$requested = (int)($_POST['org_id'] ?? $_GET['org_id'] ?? 0);
$orgId = 0;
foreach ($orgs as $org) {
    if ((int)$org['id'] === $requested) {
        $orgId = $requested;
    }
}
if ($orgId <= 0) {
    foreach ($orgs as $org) {
        if ((int)$org['id'] === 2) {
            $orgId = 2;
        }
    }
}
if ($orgId <= 0 && $orgs) {
    $orgId = (int)$orgs[0]['id'];
}
if ($requested > 0 && $requested !== $orgId) {
    if ($ajax) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'That organization is outside your access.']);
        exit;
    }
    http_response_code(403);
    exit('That organization is outside your access.');
}
if ($orgId <= 0) {
    exit('No organization is available.');
}

function ow_indexes(PDO $pdo, int $orgId): array
{
    $sites = $pdo->prepare('SELECT SId, nick_name FROM sites WHERE org_id = ?');
    $sites->execute([$orgId]);
    $users = $pdo->prepare('SELECT id, Name FROM users WHERE org_id = ?');
    $users->execute([$orgId]);
    return [
        ow_index_by_name($sites->fetchAll(PDO::FETCH_ASSOC), 'SId', 'nick_name'),
        ow_index_by_name($users->fetchAll(PDO::FETCH_ASSOC), 'id', 'Name'),
    ];
}

function ow_totals_payload(PDO $pdo, int $orgId, array $saved): array
{
    $rows = ow_fetch($pdo, $orgId);
    $groups = ow_group_stores($rows);
    $store = null;
    $key = ow_store_key($saved);
    foreach ($groups as $group) {
        if ($group['key'] === $key) {
            $store = ow_totals($group['rows']);
            $store['key'] = $group['key'];
        }
    }
    return ['store' => $store, 'grand' => ow_totals($rows)];
}

if ($ajax && (string)($_POST['action'] ?? '') === 'save') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $saved = ow_save_field(
            $pdo,
            $orgId,
            (int)($_POST['id'] ?? 0),
            $actorId,
            (string)($_POST['field'] ?? ''),
            (string)($_POST['value'] ?? '')
        );
        $totals = ow_totals_payload($pdo, $orgId, $saved['row']);
        echo json_encode([
            'ok' => true,
            'changed' => $saved['changed'],
            'row' => ow_row_public($saved['row']),
            'store' => $totals['store'],
            'grand' => $totals['grand'],
        ]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($ajax && (string)($_POST['action'] ?? '') === 'history') {
    header('Content-Type: application/json; charset=UTF-8');
    $id = (int)($_POST['id'] ?? 0);
    $own = $pdo->prepare('SELECT id FROM admin_outstanding_wages WHERE id = ? AND org_id = ?');
    $own->execute([$id, $orgId]);
    if (!$own->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'That row is not in this organization.']);
        exit;
    }
    echo json_encode(['ok' => true, 'edits' => ow_history($pdo, $orgId, $id)]);
    exit;
}

if (($_GET['export'] ?? '') === 'csv') {
    $rows = ow_filter_rows(
        ow_fetch($pdo, $orgId),
        (string)($_GET['q'] ?? ''),
        (string)($_GET['flagged'] ?? '') === '1',
        (string)($_GET['employee'] ?? '')
    );
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="outstanding-wages-org-' . $orgId . '.csv"');
    echo ow_export_csv($rows);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_csv'])) {
    try {
        $file = $_FILES['csv'] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The CSV did not upload.');
        }
        if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
            throw new RuntimeException('The CSV is larger than 2 MB.');
        }
        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            throw new RuntimeException('Upload a .csv file.');
        }
        $raw = (string)file_get_contents((string)$file['tmp_name']);
        $parsed = ow_parse_csv($raw);
        $part = ow_partition_import(ow_existing_keys($pdo, $orgId), $parsed);
        $token = bin2hex(random_bytes(8));
        $_SESSION['ow_preview'][$token] = [
            'org_id' => $orgId,
            'name' => (string)($file['name'] ?? 'upload.csv'),
            'rows' => $parsed,
        ];
        header('Location: outstanding_wages.php?org_id=' . $orgId . '&preview=' . urlencode($token));
        exit;
    } catch (Throwable $e) {
        $_SESSION['ow_flash'] = $e->getMessage();
        header('Location: outstanding_wages.php?org_id=' . $orgId);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    $token = (string)($_POST['preview_token'] ?? '');
    $staged = $_SESSION['ow_preview'][$token] ?? null;
    try {
        if (!is_array($staged) || (int)$staged['org_id'] !== $orgId) {
            throw new RuntimeException('That preview expired. Upload the CSV again.');
        }
        [$siteIndex, $userIndex] = ow_indexes($pdo, $orgId);
        $result = ow_insert_new($pdo, $orgId, $actorId, $staged['rows'], $siteIndex, $userIndex);
        unset($_SESSION['ow_preview'][$token]);
        $_SESSION['ow_flash'] = 'Inserted ' . $result['inserted'] . ' row(s). Skipped ' . $result['skipped'] . ' exact duplicate(s). Left out ' . $result['invalid'] . ' invalid row(s). Payroll was not changed.';
    } catch (Throwable $e) {
        $_SESSION['ow_flash'] = $e->getMessage();
    }
    header('Location: outstanding_wages.php?org_id=' . $orgId);
    exit;
}

$preview = null;
$previewToken = (string)($_GET['preview'] ?? '');
if ($previewToken !== '' && isset($_SESSION['ow_preview'][$previewToken]) && (int)$_SESSION['ow_preview'][$previewToken]['org_id'] === $orgId) {
    $preview = $_SESSION['ow_preview'][$previewToken];
    $preview['part'] = ow_partition_import(ow_existing_keys($pdo, $orgId), $preview['rows']);
}
$flash = (string)($_SESSION['ow_flash'] ?? '');
unset($_SESSION['ow_flash']);
$rows = ow_fetch($pdo, $orgId);
$groups = ow_group_stores($rows);
$grand = ow_totals($rows);
$csrf = ps_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Outstanding Wages</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f1f5f9; padding: 90px 16px 40px; color: #1e293b; font-size: 13px; }
        .sheet { background: #fff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
        table.ledger th { background: #0f172a; color: #fff; font-size: 11px; text-transform: uppercase; }
        table.ledger td, table.ledger th { vertical-align: middle; }
        .money, .amt { text-align: right; font-variant-numeric: tabular-nums; }
        .amt { width: 110px; }
        .sub td { background: #e2e8f0; font-weight: 800; }
        .emp-btn { background: none; border: 0; padding: 0; font-weight: 800; color: #1d4ed8; text-align: left; }
        .emp-btn:hover { text-decoration: underline; }
        .saving { outline: 2px solid #93c5fd; }
        .saved { outline: 2px solid #86efac; }
        tr.hidden-row { display: none; }
        .note { width: 180px; }
    </style>
</head>
<body>
<?= function_exists('generateMenu') ? generateMenu($pdo) : '' ?>
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
        <div>
            <h4 class="font-weight-bold mb-1">Outstanding Wages</h4>
            <p class="text-muted mb-0">Tracking ledger only. Saving a status here does not create a pay run, approve payroll, or mark anyone paid in payroll.</p>
        </div>
        <a class="btn btn-outline-dark font-weight-bold" id="export-link" href="outstanding_wages.php?org_id=<?= (int)$orgId ?>&amp;export=csv">Export CSV</a>
    </div>
    <?php if ($flash !== ''): ?><div class="alert alert-info"><?= ow_h($flash) ?></div><?php endif; ?>

    <form method="GET" class="sheet">
        <div class="form-row align-items-end">
            <div class="col-md-4 mb-2">
                <label class="small font-weight-bold text-uppercase text-muted">Organization</label>
                <select name="org_id" class="form-control form-control-sm font-weight-bold" onchange="this.form.submit()">
                    <?php foreach ($orgs as $org): ?>
                        <option value="<?= (int)$org['id'] ?>" <?= (int)$org['id'] === $orgId ? 'selected' : '' ?>><?= ow_h($org['org_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 mb-2">
                <label class="small font-weight-bold text-uppercase text-muted">Search</label>
                <input id="q" class="form-control form-control-sm" type="search" placeholder="Employee, store, period, notes">
            </div>
            <div class="col-md-2 mb-2">
                <label class="d-block small font-weight-bold text-uppercase text-muted">Flagged</label>
                <label class="mb-0"><input id="flagged-only" type="checkbox"> Flagged only</label>
            </div>
            <div class="col-md-2 mb-2">
                <button type="button" class="btn btn-sm btn-outline-secondary btn-block" id="clear-filter">Clear person filter</button>
            </div>
        </div>
        <p class="mb-0 text-muted" id="filter-note">Click an employee name to show only that person. Totals below follow the rows on screen. The store summary is the full ledger and updates when an amount is saved.</p>
    </form>

    <div class="sheet">
        <h5>Import CSV</h5>
        <p class="mb-2">Columns: Store, Employee, Pay period, Gross, Net, Employer deductions, Payroll record in our portal, Notes. Nothing is saved until you confirm the preview. Exact duplicate rows are skipped.</p>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= ow_h($csrf) ?>">
            <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
            <input type="file" name="csv" accept=".csv,text/csv" required>
            <button class="btn btn-sm btn-primary" name="upload_csv" value="1">Preview import</button>
        </form>
        <?php if ($preview): ?>
            <?php $part = $preview['part']; ?>
            <hr>
            <p><strong>Preview <?= ow_h($preview['name']) ?></strong> — <?= count($part['insert']) ?> to insert, <?= count($part['skipped']) ?> exact duplicate(s), <?= count($part['invalid']) ?> invalid. Payroll has not changed.</p>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead><tr><th>Line</th><th>Store</th><th>Employee</th><th>Pay period</th><th>Gross</th><th>Net</th><th>Employer deductions</th><th>Result</th></tr></thead>
                    <tbody>
                    <?php
                    $skipLines = [];
                    foreach ($part['skipped'] as $skippedRow) {
                        $skipLines[(int)$skippedRow['line']] = true;
                    }
                    foreach (array_slice($preview['rows'], 0, 80) as $prow): ?>
                        <tr>
                            <td><?= (int)$prow['line'] ?></td>
                            <td><?= ow_h($prow['store']) ?></td>
                            <td><?= ow_h($prow['employee']) ?></td>
                            <td><?= ow_h($prow['pay_period']) ?><?php if ($prow['period_start']): ?> <span class="text-muted"><?= ow_h($prow['period_start']) ?>–<?= ow_h($prow['period_end']) ?></span><?php endif; ?></td>
                            <td><?= $prow['gross'] === null ? '—' : number_format((float)$prow['gross'], 2) ?></td>
                            <td><?= $prow['net'] === null ? '—' : number_format((float)$prow['net'], 2) ?></td>
                            <td><?= $prow['employer_deductions'] === null ? '—' : number_format((float)$prow['employer_deductions'], 2) ?></td>
                            <td><?= $prow['error'] !== '' ? ow_h($prow['error']) : (isset($skipLines[(int)$prow['line']]) ? 'Skip duplicate' : 'Insert') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= ow_h($csrf) ?>">
                <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
                <input type="hidden" name="preview_token" value="<?= ow_h($previewToken) ?>">
                <button class="btn btn-warning font-weight-bold" name="confirm_import" value="1">Confirm and insert <?= count($part['insert']) ?> row(s)</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="sheet">
        <h5>Stores</h5>
        <?php if (!$groups): ?>
            <p class="mb-0 text-muted">No outstanding wage rows for this organization yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0" id="store-summary">
                    <thead><tr><th>Store</th><th class="money">Rows</th><th class="money">Gross</th><th class="money">Net</th><th class="money">Pending</th></tr></thead>
                    <tbody>
                    <?php foreach ($groups as $group): $tot = ow_totals($group['rows']); ?>
                        <tr data-summary="<?= ow_h($group['key']) ?>">
                            <td><a href="#<?= ow_h($group['anchor']) ?>"><?= ow_h($group['store_name']) ?></a><?php if ($group['site_id']): ?> <span class="text-muted">#<?= (int)$group['site_id'] ?></span><?php endif; ?></td>
                            <td class="money" data-sum="count"><?= (int)$tot['count'] ?></td>
                            <td class="money"><span data-sum="gross"><?= number_format($tot['gross'], 2) ?></span></td>
                            <td class="money"><span data-sum="net"><?= number_format($tot['net'], 2) ?></span> <span class="text-muted" data-sum="net-missing"><?php if ($tot['net_missing']): ?>(<?= (int)$tot['net_missing'] ?> blank)<?php endif; ?></span></td>
                            <td class="money"><span data-sum="pending-n"><?= (int)$tot['pending_count'] ?></span> · <span data-sum="pending-gross"><?= number_format($tot['pending_gross'], 2) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="sub" id="grand-summary">
                            <td>All stores</td>
                            <td class="money"><span data-sum="count"><?= (int)$grand['count'] ?></span></td>
                            <td class="money"><span data-sum="gross"><?= number_format($grand['gross'], 2) ?></span></td>
                            <td class="money"><span data-sum="net"><?= number_format($grand['net'], 2) ?></span></td>
                            <td class="money"><span data-sum="pending-n"><?= (int)$grand['pending_count'] ?></span> · <span data-sum="pending-gross"><?= number_format($grand['pending_gross'], 2) ?></span></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php foreach ($groups as $group): $tot = ow_totals($group['rows']); ?>
        <div class="sheet store-block" id="<?= ow_h($group['anchor']) ?>" data-store-key="<?= ow_h($group['key']) ?>">
            <h5 class="mb-2"><?= ow_h($group['store_name']) ?><?php if ($group['site_id']): ?> <span class="text-muted">#<?= (int)$group['site_id'] ?></span><?php endif; ?></h5>
            <div class="table-responsive">
                <table class="table table-sm table-bordered ledger mb-0">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Pay period</th>
                            <th>Gross</th>
                            <th>Net</th>
                            <th>Employer deductions</th>
                            <th>Status</th>
                            <th>Payroll record in our portal</th>
                            <th>Checked vs son's records</th>
                            <th>Flagged</th>
                            <th>Notes</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($group['rows'] as $row): ?>
                        <tr class="ow-row"
                            data-id="<?= (int)$row['id'] ?>"
                            data-store-key="<?= ow_h($group['key']) ?>"
                            data-employee="<?= ow_h($row['employee_name']) ?>"
                            data-store="<?= ow_h($row['store_name']) ?>"
                            data-period="<?= ow_h($row['pay_period']) ?>"
                            data-notes="<?= ow_h((string)($row['notes'] ?? '')) ?>"
                            data-portal="<?= ow_h($row['portal_record']) ?>"
                            data-status="<?= ow_h($row['status']) ?>"
                            data-gross="<?= ow_h(ow_money_input($row['gross'])) ?>"
                            data-net="<?= ow_h(ow_money_input($row['net'])) ?>"
                            data-employer="<?= ow_h(ow_money_input($row['employer_deductions'])) ?>"
                            data-flagged="<?= (int)$row['flagged'] === 1 ? '1' : '0' ?>">
                            <td><button type="button" class="emp-btn"><?= ow_h($row['employee_name']) ?></button><?php if ($row['user_id']): ?> <span class="text-muted">#<?= (int)$row['user_id'] ?></span><?php endif; ?></td>
                            <td><?= ow_h($row['pay_period']) ?><?php if ($row['period_start']): ?><div class="text-muted"><?= ow_h($row['period_start']) ?> – <?= ow_h($row['period_end']) ?></div><?php endif; ?></td>
                            <td><input class="form-control form-control-sm amt" data-field="gross" value="<?= ow_h(ow_money_input($row['gross'])) ?>"></td>
                            <td><input class="form-control form-control-sm amt" data-field="net" value="<?= ow_h(ow_money_input($row['net'])) ?>" placeholder="blank"></td>
                            <td><input class="form-control form-control-sm amt" data-field="employer_deductions" value="<?= ow_h(ow_money_input($row['employer_deductions'])) ?>" placeholder="blank"></td>
                            <td>
                                <select class="form-control form-control-sm" data-field="status">
                                    <?php foreach (ow_statuses() as $status): ?>
                                        <option value="<?= ow_h($status) ?>" <?= $row['status'] === $status ? 'selected' : '' ?>><?= ow_h($status) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input class="form-control form-control-sm" data-field="portal_record" value="<?= ow_h($row['portal_record']) ?>"></td>
                            <td class="text-center"><input type="checkbox" data-field="checked_vs_son" <?= (int)$row['checked_vs_son'] === 1 ? 'checked' : '' ?>></td>
                            <td class="text-center"><input type="checkbox" data-field="flagged" <?= (int)$row['flagged'] === 1 ? 'checked' : '' ?>></td>
                            <td><input class="form-control form-control-sm note" data-field="notes" value="<?= ow_h((string)($row['notes'] ?? '')) ?>"></td>
                            <td><button type="button" class="btn btn-sm btn-outline-secondary hist">Log</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="sub store-sub">
                            <td colspan="2">Subtotal <span data-sub="count"><?= (int)$tot['count'] ?></span></td>
                            <td class="money" data-sub="gross"><?= number_format($tot['gross'], 2) ?></td>
                            <td class="money" data-sub="net"><?= number_format($tot['net'], 2) ?></td>
                            <td class="money" data-sub="employer"><?= number_format($tot['employer'], 2) ?></td>
                            <td colspan="2" data-sub="pending"><?= (int)$tot['pending_count'] ?> pending · <?= number_format($tot['pending_gross'], 2) ?></td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($groups): ?>
        <div class="sheet" id="grand-visible">
            <strong>On screen:</strong>
            <span data-vis="count"><?= (int)$grand['count'] ?></span> rows,
            gross <span data-vis="gross"><?= number_format($grand['gross'], 2) ?></span>,
            net <span data-vis="net"><?= number_format($grand['net'], 2) ?></span>,
            pending <span data-vis="pending"><?= (int)$grand['pending_count'] ?> · <?= number_format($grand['pending_gross'], 2) ?></span>
        </div>
    <?php endif; ?>
</div>
<script>
(function () {
    var CSRF = <?= json_encode($csrf) ?>;
    var ORG = <?= (int)$orgId ?>;
    var person = '';
    var timers = {};

    function money(n) {
        var v = Number(n);
        if (!isFinite(v)) v = 0;
        return v.toLocaleString('en-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
    function norm(s) {
        return String(s || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }
    function rowValue(row, field) {
        var el = row.querySelector('[data-field="' + field + '"]');
        if (!el) return '';
        if (el.type === 'checkbox') return el.checked ? '1' : '0';
        return el.value;
    }
    function visibleRows() {
        return Array.prototype.filter.call(document.querySelectorAll('tr.ow-row'), function (row) {
            return !row.classList.contains('hidden-row');
        });
    }
    function totalsOf(rows) {
        var t = {count: 0, gross: 0, net: 0, netMissing: 0, employer: 0, pendingN: 0, pendingGross: 0};
        rows.forEach(function (row) {
            t.count++;
            var g = parseFloat(row.getAttribute('data-gross'));
            if (!isFinite(g)) g = 0;
            t.gross += g;
            var net = row.getAttribute('data-net');
            if (net === '') t.netMissing++;
            else t.net += parseFloat(net) || 0;
            var er = row.getAttribute('data-employer');
            if (er !== '') t.employer += parseFloat(er) || 0;
            if (row.getAttribute('data-status') === 'Pending') {
                t.pendingN++;
                t.pendingGross += g;
            }
        });
        return t;
    }
    function paint(el, t) {
        if (!el) return;
        var count = el.querySelector('[data-sub="count"], [data-vis="count"]');
        if (count) count.textContent = String(t.count);
        ['gross', 'net', 'employer'].forEach(function (name) {
            var node = el.querySelector('[data-sub="' + name + '"], [data-vis="' + name + '"]');
            if (node) node.textContent = money(t[name]);
        });
        var subPending = el.querySelector('[data-sub="pending"]');
        if (subPending) subPending.textContent = t.pendingN + ' pending · ' + money(t.pendingGross);
        var visPending = el.querySelector('[data-vis="pending"]');
        if (visPending) visPending.textContent = t.pendingN + ' · ' + money(t.pendingGross);
    }
    function fillSummary(root, t) {
        if (!root) return;
        root.querySelector('[data-sum="count"]').textContent = String(t.count);
        root.querySelector('[data-sum="gross"]').textContent = money(t.gross);
        root.querySelector('[data-sum="net"]').textContent = money(t.net);
        var miss = root.querySelector('[data-sum="net-missing"]');
        if (miss) miss.textContent = t.netMissing ? '(' + t.netMissing + ' blank)' : '';
        root.querySelector('[data-sum="pending-n"]').textContent = String(t.pendingN);
        root.querySelector('[data-sum="pending-gross"]').textContent = money(t.pendingGross);
    }
    function applyFilter() {
        var q = norm(document.getElementById('q').value);
        var flagged = document.getElementById('flagged-only').checked;
        var want = norm(person);
        document.querySelectorAll('tr.ow-row').forEach(function (row) {
            var hay = norm([
                row.getAttribute('data-employee'),
                row.getAttribute('data-store'),
                row.getAttribute('data-period'),
                row.getAttribute('data-notes'),
                row.getAttribute('data-portal'),
                row.getAttribute('data-status')
            ].join(' '));
            var show = true;
            if (flagged && row.getAttribute('data-flagged') !== '1') show = false;
            if (want && norm(row.getAttribute('data-employee')) !== want) show = false;
            if (q && hay.indexOf(q) === -1) show = false;
            row.classList.toggle('hidden-row', !show);
        });
        document.querySelectorAll('.store-block').forEach(function (block) {
            var rows = Array.prototype.filter.call(block.querySelectorAll('tr.ow-row'), function (row) {
                return !row.classList.contains('hidden-row');
            });
            block.style.display = rows.length ? '' : 'none';
            paint(block, totalsOf(rows));
        });
        paint(document.getElementById('grand-visible'), totalsOf(visibleRows()));
        var note = document.getElementById('filter-note');
        note.textContent = want
            ? 'Showing ' + person + '. Clear the person filter to see every employee again.'
            : 'Click an employee name to show only that person. Totals below follow the rows on screen. The store summary is the full ledger and updates when an amount is saved.';
        var exp = new URL('outstanding_wages.php', window.location.href);
        exp.searchParams.set('org_id', String(ORG));
        exp.searchParams.set('export', 'csv');
        if (q) exp.searchParams.set('q', document.getElementById('q').value.trim());
        if (flagged) exp.searchParams.set('flagged', '1');
        if (want) exp.searchParams.set('employee', person);
        document.getElementById('export-link').href = exp.pathname + exp.search;
    }
    function remember(row) {
        row.setAttribute('data-gross', rowValue(row, 'gross'));
        row.setAttribute('data-net', rowValue(row, 'net'));
        row.setAttribute('data-employer', rowValue(row, 'employer_deductions'));
        row.setAttribute('data-status', rowValue(row, 'status'));
        row.setAttribute('data-notes', rowValue(row, 'notes'));
        row.setAttribute('data-portal', rowValue(row, 'portal_record'));
        row.setAttribute('data-flagged', rowValue(row, 'flagged'));
    }
    function save(el) {
        var row = el.closest('tr');
        var field = el.getAttribute('data-field');
        var value = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
        if (el.getAttribute('data-last') === value) return;
        el.classList.add('saving');
        var body = new FormData();
        body.append('ajax', '1');
        body.append('action', 'save');
        body.append('csrf_token', CSRF);
        body.append('org_id', String(ORG));
        body.append('id', row.getAttribute('data-id'));
        body.append('field', field);
        body.append('value', value);
        fetch('outstanding_wages.php', {method: 'POST', body: body, credentials: 'same-origin'})
            .then(function (res) {
                return res.text().then(function (text) {
                    var data;
                    try { data = JSON.parse(text); } catch (e) { data = {ok: false, error: text || 'Save failed'}; }
                    if (!res.ok || !data.ok) throw new Error(data.error || 'Save failed');
                    return data;
                });
            })
            .then(function (data) {
                var pub = data.row;
                var input = row.querySelector('[data-field="' + field + '"]');
                if (input && input.type !== 'checkbox' && (field === 'gross' || field === 'net' || field === 'employer_deductions')) {
                    input.value = pub[field] === null ? '' : Number(pub[field]).toFixed(2);
                }
                remember(row);
                input.setAttribute('data-last', input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value);
                if (data.store) {
                    fillSummary(document.querySelector('#store-summary tr[data-summary="' + data.store.key + '"]'), {
                        count: data.store.count,
                        gross: data.store.gross,
                        net: data.store.net,
                        netMissing: data.store.net_missing,
                        pendingN: data.store.pending_count,
                        pendingGross: data.store.pending_gross
                    });
                }
                if (data.grand) {
                    fillSummary(document.getElementById('grand-summary'), {
                        count: data.grand.count,
                        gross: data.grand.gross,
                        net: data.grand.net,
                        netMissing: data.grand.net_missing,
                        pendingN: data.grand.pending_count,
                        pendingGross: data.grand.pending_gross
                    });
                }
                applyFilter();
                el.classList.remove('saving');
                el.classList.add('saved');
                setTimeout(function () { el.classList.remove('saved'); }, 800);
            })
            .catch(function (err) {
                el.classList.remove('saving');
                window.alert(err.message);
            });
    }
    document.getElementById('q').addEventListener('input', applyFilter);
    document.getElementById('flagged-only').addEventListener('change', applyFilter);
    document.getElementById('clear-filter').addEventListener('click', function () {
        person = '';
        applyFilter();
    });
    document.querySelectorAll('.emp-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var name = btn.closest('tr').getAttribute('data-employee');
            person = norm(person) === norm(name) ? '' : name;
            applyFilter();
        });
    });
    document.querySelectorAll('[data-field]').forEach(function (el) {
        el.setAttribute('data-last', el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value);
        var event = (el.type === 'checkbox' || el.tagName === 'SELECT') ? 'change' : 'change';
        el.addEventListener(event, function () {
            if (el.type === 'checkbox' || el.tagName === 'SELECT') {
                save(el);
                return;
            }
            var key = el.closest('tr').getAttribute('data-id') + ':' + el.getAttribute('data-field');
            clearTimeout(timers[key]);
            timers[key] = setTimeout(function () { save(el); }, 400);
        });
        if (el.type !== 'checkbox' && el.tagName !== 'SELECT') {
            el.addEventListener('blur', function () {
                var key = el.closest('tr').getAttribute('data-id') + ':' + el.getAttribute('data-field');
                clearTimeout(timers[key]);
                save(el);
            });
        }
    });
    document.querySelectorAll('button.hist').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('tr');
            var body = new FormData();
            body.append('ajax', '1');
            body.append('action', 'history');
            body.append('csrf_token', CSRF);
            body.append('org_id', String(ORG));
            body.append('id', row.getAttribute('data-id'));
            fetch('outstanding_wages.php', {method: 'POST', body: body, credentials: 'same-origin'})
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.ok) throw new Error(data.error || 'No log');
                    if (!data.edits.length) {
                        window.alert('No edits yet.');
                        return;
                    }
                    window.alert(data.edits.map(function (ed) {
                        var who = ed.actor_name || ('user ' + ed.actor_id);
                        return ed.created_at + ' ' + who + ' ' + ed.field_name + ': ' + (ed.before_value || 'blank') + ' → ' + (ed.after_value || 'blank');
                    }).join('\n'));
                })
                .catch(function (err) { window.alert(err.message); });
        });
    });
})();
</script>
</body>
</html>
