<?php
/**
 * Path: /payroll_sheet_sheet.php
 * Sheet Review, sheet view: the source rows of an imported workbook, inline
 * edits of the draft (employee link, store, net, note), and the source cells
 * beside the editor. Admin only and company scoped.
 *
 * Edits go to payroll_sheet_rows (drafts) and payroll_sheet_edit_audit only.
 * Nothing here approves payroll, posts a payment, or marks anyone paid.
 * Deploy with engine/payroll_sheet_edit_lib.php and
 * migrations/20261126_payroll_sheet_edit_source.sql.
 */
if (is_file(__DIR__ . '/engine/privacy_lib.php')) {
    require_once __DIR__ . '/engine/privacy_lib.php';
    if (function_exists('privacy_boot_web')) {
        privacy_boot_web();
    }
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] === '') {
    header('Location: login_kiosk.php?err=session_expired');
    exit;
}
if ((int)($_SESSION['user_level'] ?? 99) > 20) {
    http_response_code(403);
    exit('Sheet review is for admins only.');
}
require_once 'db.php';
require_once 'payroll_scope.php';
require_once __DIR__ . '/engine/payroll_sheet_review_lib.php';
require_once __DIR__ . '/engine/payroll_sheet_grid_lib.php';
require_once __DIR__ . '/engine/payroll_sheet_edit_lib.php';
ps_require_payroll_admin();
if (is_file(__DIR__ . '/engine/menu_generator.php')) {
    require_once __DIR__ . '/engine/menu_generator.php';
}

function pe_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$actorId = (int)$_SESSION['user_id'];
$isSuper = function_exists('ps_is_superadmin') && ps_is_superadmin();
$homeOrg = function_exists('ps_selected_org') ? (int)ps_selected_org($pdo) : (int)($_SESSION['org_id'] ?? 0);
$requestedOrg = (int)($_POST['org_id'] ?? $_GET['org_id'] ?? 0);
if (!psr_may_view_org($isSuper, $homeOrg, $requestedOrg > 0 ? $requestedOrg : $homeOrg)) {
    http_response_code(403);
    exit('That company is outside your access.');
}
$orgId = $requestedOrg > 0 ? $requestedOrg : $homeOrg;
if ($orgId <= 0) {
    exit('No company is selected.');
}
if (function_exists('privacy_require_business')) {
    privacy_require_business(
        $pdo,
        (int)$orgId,
        'report',
        'payroll_sheet_sheet.php',
        false,
        20,
        (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') ? 'update' : 'view'
    );
}
try {
    psr_ensure($pdo);
    psr_ensure_grid_columns($pdo);
    pse_ensure($pdo);
} catch (Throwable $e) {
    exit('Sheet view storage is not ready. ' . pe_h($e->getMessage()));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_require_csrf();
}
$csrf = ps_csrf_token();
$flash = (string)($_SESSION['pse_flash'] ?? '');
unset($_SESSION['pse_flash']);
$warnings = [];

$sheetFilter = trim((string)($_GET['sheet'] ?? $_POST['sheet'] ?? ''));
$nameFilter = trim((string)($_GET['name'] ?? $_POST['name'] ?? ''));
$textFilter = trim((string)($_GET['q'] ?? ''));
$selectedId = (int)($_GET['row'] ?? $_POST['row_id'] ?? 0);
$pageNo = max(1, (int)($_GET['page'] ?? 1));
$perPage = 40;

function pe_url(array $over, int $orgId): string
{
    global $sheetFilter, $nameFilter, $textFilter, $pageNo;
    $q = ['org_id' => $orgId];
    if ($sheetFilter !== '') {
        $q['sheet'] = $sheetFilter;
    }
    if ($nameFilter !== '') {
        $q['name'] = $nameFilter;
    }
    if ($textFilter !== '') {
        $q['q'] = $textFilter;
    }
    if ($pageNo > 1) {
        $q['page'] = $pageNo;
    }
    foreach ($over as $k => $v) {
        if ($v === null) {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }
    return 'payroll_sheet_sheet.php?' . http_build_query($q);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_row'])) {
    try {
        $rowId = (int)($_POST['row_id'] ?? 0);
        $post = [
            'row_version' => $_POST['row_version'] ?? '',
            'user_id' => $_POST['user_id'] ?? '',
            'site_id' => $_POST['site_id'] ?? '',
            'net' => $_POST['net'] ?? '',
            'net_unknown' => !empty($_POST['net_unknown']) ? '1' : '',
            'draft_notes' => $_POST['draft_notes'] ?? '',
        ];
        $n = pse_apply_edit($pdo, $orgId, $rowId, $actorId, $post);
        $_SESSION['pse_flash'] = $n > 0
            ? 'Saved ' . $n . ' change(s) to the draft. Payroll was not changed and nobody was marked paid.'
            : 'Nothing changed.';
        header('Location: ' . pe_url(['row' => $rowId], $orgId));
        exit;
    } catch (Throwable $e) {
        $warnings[] = $e->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['link_person'])) {
    try {
        $siteRaw = (int)($_POST['site_id'] ?? 0);
        $res = pse_apply_person_link($pdo, $orgId, (string)($_POST['sheet_name'] ?? ''), (int)($_POST['user_id'] ?? 0), $siteRaw > 0 ? $siteRaw : null, $actorId);
        $_SESSION['pse_flash'] = 'Linked ' . $res['linked'] . ' row(s). Already linked: ' . $res['unchanged']
            . '. Skipped because they have an active approval: ' . $res['skipped_locked'] . '. Payroll was not changed and nobody was marked paid.';
        header('Location: ' . pe_url(['row' => (int)($_POST['row_id'] ?? 0) ?: null], $orgId));
        exit;
    } catch (Throwable $e) {
        $warnings[] = $e->getMessage();
    }
}

$users = pse_org_users($pdo, $orgId);
$sites = pse_org_sites($pdo, $orgId);

$locked = [];
$lk = $pdo->prepare('SELECT DISTINCT row_id FROM payroll_sheet_approval_effects WHERE org_id = ? AND undone_at IS NULL');
$lk->execute([$orgId]);
foreach ($lk->fetchAll(PDO::FETCH_COLUMN) as $rid) {
    $locked[(int)$rid] = true;
}

$all = $pdo->prepare(
    'SELECT r.*, u.Name AS linked_name, s.nick_name AS site_name
     FROM payroll_sheet_rows r
     LEFT JOIN users u ON u.id = r.user_id AND u.org_id = r.org_id
     LEFT JOIN sites s ON s.SId = r.site_id AND s.org_id = r.org_id
     WHERE r.org_id = ? AND r.deleted_at IS NULL
     ORDER BY r.source_sheet, r.source_row, r.id'
);
$all->execute([$orgId]);
$allRows = $all->fetchAll(PDO::FETCH_ASSOC);

$sheetNames = [];
$people = [];
foreach ($allRows as $r) {
    $sheetNames[$r['source_sheet']] = ($sheetNames[$r['source_sheet']] ?? 0) + 1;
    if (($r['row_kind'] ?? '') !== 'employee_record') {
        continue;
    }
    $key = psr_norm_name($r['employee_name']);
    if (!isset($people[$key])) {
        $people[$key] = ['name' => (string)$r['employee_name'], 'rows' => 0, 'linked' => 0];
    }
    $people[$key]['rows']++;
    if ((int)$r['user_id'] > 0) {
        $people[$key]['linked']++;
    }
}
uasort($people, static function ($a, $b) {
    return strcmp(psr_norm_name($a['name']), psr_norm_name($b['name']));
});
if ($sheetFilter === '' && count($sheetNames) > 0) {
    $sheetFilter = (string)array_key_first($sheetNames);
}

$nameKey = psr_norm_name($nameFilter);
$textKey = strtolower($textFilter);
$shown = [];
foreach ($allRows as $r) {
    if ($sheetFilter !== '' && $r['source_sheet'] !== $sheetFilter) {
        continue;
    }
    if ($nameKey !== '' && psr_norm_name($r['employee_name']) !== $nameKey && psr_norm_name($r['linked_name'] ?? '') !== $nameKey) {
        continue;
    }
    if ($textKey !== '') {
        $hay = strtolower($r['employee_name'] . ' ' . $r['store_name'] . ' ' . ($r['linked_name'] ?? '') . ' ' . implode(' ', array_map('pse_cell_text', pse_row_raw_cells($r))));
        if (strpos($hay, $textKey) === false) {
            continue;
        }
    }
    $shown[] = $r;
}
$pages = max(1, (int)ceil(count($shown) / $perPage));
$pageNo = min($pageNo, $pages);
$pageRows = array_slice($shown, ($pageNo - 1) * $perPage, $perPage);

$selected = null;
foreach ($allRows as $r) {
    if ((int)$r['id'] === $selectedId) {
        $selected = $r;
        break;
    }
}
$rawCols = [];
foreach ($pageRows as $r) {
    foreach (array_keys(pse_row_raw_cells($r)) as $col) {
        $rawCols[$col] = true;
    }
}
$rawCols = array_keys($rawCols);
usort($rawCols, static function ($a, $b) {
    return pse_col_index($a) <=> pse_col_index($b);
});
$window = null;
$audit = [];
$sameName = 0;
if ($selected) {
    $window = pse_source_window($pdo, $orgId, $selected, 6);
    $audit = pse_audit_fetch($pdo, $orgId, (int)$selected['id'], 20);
    $sameName = count(pse_rows_for_name($pdo, $orgId, (string)$selected['employee_name']));
}
$header = pse_known_headers($sheetFilter);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sheet view</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { background:#f1f5f9; padding:90px 16px 40px; font-size:13px; color:#1e293b; }
        .box { background:#fff; border:1px solid #cbd5e1; border-radius:12px; padding:14px; margin-bottom:14px; }
        .rows-wrap { max-height: 74vh; overflow:auto; border:1px solid #cbd5e1; background:#fff; }
        table.srows { border-collapse: separate; border-spacing:0; margin:0; }
        table.srows th { position:sticky; top:0; background:#0f172a; color:#fff; font-size:11px; z-index:2; white-space:nowrap; }
        table.srows td { white-space:nowrap; vertical-align:top; }
        table.srows tr.sel td { background:#fef9c3; }
        table.srows tr.locked td { color:#64748b; }
        .pending { color:#9a3412; font-weight:700; }
        .side { position: sticky; top: 70px; max-height: calc(100vh - 90px); overflow:auto; }
        table.src td, table.src th { padding:2px 6px; font-size:12px; white-space:nowrap; }
        table.src th { background:#e2e8f0; }
        table.src tr.selrow td { border-top:2px solid #b45309; border-bottom:2px solid #b45309; }
        table.src td.hl { background:#fde047; font-weight:700; }
        table.src td.ev { background:#fdba74; font-weight:700; }
        table.src td.num { text-align:right; }
        table.src td.rn { background:#e2e8f0; color:#475569; text-align:right; }
    </style>
</head>
<body>
<?= function_exists('generateMenu') ? generateMenu($pdo) : '' ?>
<?= function_exists('privacy_banner_html') ? privacy_banner_html() : '' ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h4 class="font-weight-bold mb-1">Sheet view</h4>
            <p class="mb-0">The imported sheet, row by row. Edits here change the draft only. They do not approve payroll or mark anyone paid, and every change is logged.</p>
        </div>
        <a class="btn btn-outline-dark font-weight-bold" href="payroll_sheet_review.php?org_id=<?= (int)$orgId ?>">Back to Sheet review</a>
    </div>
    <?php if ($flash !== ''): ?><div class="alert alert-info"><?= pe_h($flash) ?></div><?php endif; ?>
    <?php if ($warnings): ?><div class="alert alert-warning"><?php foreach ($warnings as $w): ?><div><?= pe_h($w) ?></div><?php endforeach; ?></div><?php endif; ?>

    <form method="GET" class="box">
        <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
        <div class="form-row align-items-end">
            <div class="col-md-3"><label class="mb-0">Sheet</label>
                <select name="sheet" class="form-control form-control-sm">
                    <?php foreach ($sheetNames as $sn => $cnt): ?>
                        <option value="<?= pe_h($sn) ?>" <?= $sn === $sheetFilter ? 'selected' : '' ?>><?= pe_h($sn) ?> (<?= (int)$cnt ?> rows)</option>
                    <?php endforeach; ?>
                </select></div>
            <div class="col-md-3"><label class="mb-0">Sheet name</label>
                <select name="name" class="form-control form-control-sm">
                    <option value="">All names</option>
                    <?php foreach ($people as $p): ?>
                        <option value="<?= pe_h($p['name']) ?>" <?= psr_norm_name($p['name']) === $nameKey ? 'selected' : '' ?>><?= pe_h($p['name']) ?> (<?= (int)$p['rows'] ?><?= $p['linked'] < $p['rows'] ? ', ' . (int)($p['rows'] - $p['linked']) . ' not linked' : '' ?>)</option>
                    <?php endforeach; ?>
                </select></div>
            <div class="col-md-3"><label class="mb-0">Search</label>
                <input name="q" class="form-control form-control-sm" value="<?= pe_h($textFilter) ?>" placeholder="name, store, any cell"></div>
            <div class="col-md-3"><button class="btn btn-sm btn-dark">Show rows</button>
                <a class="btn btn-sm btn-outline-secondary" href="payroll_sheet_sheet.php?org_id=<?= (int)$orgId ?>">Clear</a></div>
        </div>
    </form>

    <div class="row">
        <div class="<?= $selected ? 'col-xl-7' : 'col-12' ?>">
            <div class="box">
                <div class="d-flex justify-content-between mb-2">
                    <strong><?= count($shown) ?> row(s)<?= $nameFilter !== '' ? ' for ' . pe_h($nameFilter) : '' ?>. Page <?= (int)$pageNo ?> of <?= (int)$pages ?>.</strong>
                    <span>
                        <?php if ($pageNo > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= pe_h(pe_url(['page' => $pageNo - 1, 'row' => null], $orgId)) ?>">Previous</a><?php endif; ?>
                        <?php if ($pageNo < $pages): ?><a class="btn btn-sm btn-outline-secondary" href="<?= pe_h(pe_url(['page' => $pageNo + 1, 'row' => null], $orgId)) ?>">Next</a><?php endif; ?>
                    </span>
                </div>
                <div class="rows-wrap">
                <table class="table table-sm table-bordered srows">
                    <thead><tr>
                        <th>Edit</th><th>Sheet row</th>
                        <?php foreach ($rawCols as $col): ?><th><?= pe_h($col) ?><?= isset($header[$col]) ? ' ' . pe_h($header[$col]) : '' ?></th><?php endforeach; ?>
                        <th>Linked employee</th><th>Store</th><th>Net (draft)</th><th>Note</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($pageRows as $r):
                        $raw = pse_row_raw_cells($r);
                        $isLocked = isset($locked[(int)$r['id']]);
                    ?>
                        <tr class="<?= (int)$r['id'] === $selectedId ? 'sel' : '' ?> <?= $isLocked ? 'locked' : '' ?>">
                            <td><a href="<?= pe_h(pe_url(['row' => (int)$r['id']], $orgId)) ?>#editor"><?= ($r['row_kind'] ?? '') === 'employee_record' ? 'Select' : 'View' ?></a></td>
                            <td><?= (int)$r['source_row'] ?></td>
                            <?php foreach ($rawCols as $col): ?><td><?= pe_h(pse_cell_text($raw[$col] ?? null)) ?></td><?php endforeach; ?>
                            <td><?= (int)$r['user_id'] > 0 ? pe_h($r['linked_name'] ?? '') . ' #' . (int)$r['user_id'] : '<span class="pending">not linked</span>' ?></td>
                            <td><?= pe_h($r['site_name'] ?? '') !== '' ? pe_h($r['site_name']) : pe_h($r['store_name']) ?></td>
                            <td><?= $r['net'] === null ? '<span class="pending">unknown</span>' : pe_h(number_format((float)$r['net'], 2)) ?><?= $isLocked ? ' (approved)' : '' ?></td>
                            <td><?= pe_h(function_exists('mb_substr') ? mb_substr((string)$r['draft_notes'], 0, 40) : substr((string)$r['draft_notes'], 0, 40)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$pageRows): ?><tr><td colspan="<?= 6 + count($rawCols) ?>">No rows match.</td></tr><?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <?php if ($selected): ?>
        <div class="col-xl-5">
            <div class="side">
            <div class="box" id="editor">
                <h5>Row <?= (int)$selected['source_row'] ?> of <?= pe_h($selected['source_sheet']) ?>: <?= pe_h($selected['employee_name']) ?></h5>
                <div class="text-muted mb-2">Period <?= pe_h($selected['period_start'] ?? '') ?> to <?= pe_h($selected['period_end'] ?? '') ?>. Source store: <?= pe_h($selected['store_name']) ?: 'not set' ?>. Source status: <?= pe_h($selected['reported_status']) ?>.</div>
                <?php if (isset($locked[(int)$selected['id']])): ?>
                    <div class="alert alert-secondary">This row has an active approval, so it cannot be edited here. Undo the approval on Sheet review first.</div>
                <?php elseif (($selected['row_kind'] ?? '') !== 'employee_record'): ?>
                    <div class="alert alert-secondary">This is not an employee row. It is shown for reference only.</div>
                <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= pe_h($csrf) ?>">
                    <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
                    <input type="hidden" name="row_id" value="<?= (int)$selected['id'] ?>">
                    <input type="hidden" name="row_version" value="<?= (int)($selected['row_version'] ?? 1) ?>">
                    <input type="hidden" name="sheet" value="<?= pe_h($sheetFilter) ?>">
                    <input type="hidden" name="name" value="<?= pe_h($nameFilter) ?>">
                    <div class="form-group mb-2"><label class="mb-0">Employee</label>
                        <select name="user_id" class="form-control form-control-sm">
                            <option value="">Not linked</option>
                            <?php foreach ($users as $uid => $uname): ?>
                                <option value="<?= (int)$uid ?>" <?= (int)$selected['user_id'] === $uid ? 'selected' : '' ?>><?= pe_h($uname) ?> #<?= (int)$uid ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="form-group mb-2"><label class="mb-0">Store</label>
                        <select name="site_id" class="form-control form-control-sm">
                            <option value="">Not set</option>
                            <?php foreach ($sites as $sid => $sname): ?>
                                <option value="<?= (int)$sid ?>" <?= (int)$selected['site_id'] === $sid ? 'selected' : '' ?>><?= pe_h($sname) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="form-row">
                        <div class="col-6"><label class="mb-0">Net</label>
                            <input name="net" class="form-control form-control-sm" value="<?= $selected['net'] === null ? '' : pe_h(number_format((float)$selected['net'], 2, '.', '')) ?>" placeholder="blank = unknown"></div>
                        <div class="col-6 pt-4"><label class="mb-0"><input type="checkbox" name="net_unknown" value="1" <?= $selected['net'] === null ? 'checked' : '' ?>> Net is unknown</label></div>
                    </div>
                    <div class="form-group mt-2 mb-2"><label class="mb-0">Note</label>
                        <textarea name="draft_notes" rows="2" class="form-control form-control-sm"><?= pe_h($selected['draft_notes']) ?></textarea></div>
                    <button class="btn btn-sm btn-primary" name="save_row" value="1">Save draft edit</button>
                    <span class="text-muted">Draft only. Not approved, not paid.</span>
                </form>
                <?php if ($sameName > 1 || (int)$selected['user_id'] === 0): ?>
                <hr>
                <form method="POST" onsubmit="return confirm('Link all <?= (int)$sameName ?> rows named <?= pe_h(addslashes((string)$selected['employee_name'])) ?> to the chosen employee?');">
                    <input type="hidden" name="csrf_token" value="<?= pe_h($csrf) ?>">
                    <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
                    <input type="hidden" name="row_id" value="<?= (int)$selected['id'] ?>">
                    <input type="hidden" name="sheet_name" value="<?= pe_h($selected['employee_name']) ?>">
                    <input type="hidden" name="sheet" value="<?= pe_h($sheetFilter) ?>">
                    <input type="hidden" name="name" value="<?= pe_h($nameFilter) ?>">
                    <label class="mb-0">Link all <?= (int)$sameName ?> row(s) named "<?= pe_h($selected['employee_name']) ?>" to</label>
                    <div class="form-row">
                        <div class="col-7"><select name="user_id" class="form-control form-control-sm" required>
                            <option value="">Choose an employee</option>
                            <?php foreach ($users as $uid => $uname): ?><option value="<?= (int)$uid ?>"><?= pe_h($uname) ?> #<?= (int)$uid ?></option><?php endforeach; ?>
                        </select></div>
                        <div class="col-5"><select name="site_id" class="form-control form-control-sm">
                            <option value="">Keep each row's store</option>
                            <?php foreach ($sites as $sid => $sname): ?><option value="<?= (int)$sid ?>"><?= pe_h($sname) ?></option><?php endforeach; ?>
                        </select></div>
                    </div>
                    <button class="btn btn-sm btn-outline-primary mt-1" name="link_person" value="1">Link all rows for this name</button>
                </form>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="box">
                <h6 class="mb-1">Source: <?= pe_h($window['sheet']) ?>, row <?= (int)$window['selected_row'] ?></h6>
                <div class="text-muted mb-2"><?= pe_h($window['note']) ?> Yellow cells are the ones this draft was read from. Orange cells are cash events read from the sheet.</div>
                <div class="rows-wrap" style="max-height:320px">
                <table class="table-bordered src">
                    <thead><tr><th>#</th><?php foreach ($window['columns'] as $col): ?><th><?= pe_h($col) ?><?= isset($header[$col]) ? ' ' . pe_h($header[$col]) : '' ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($window['rows'] as $wr): ?>
                        <tr class="<?= $wr['selected'] ? 'selrow' : '' ?>">
                            <td class="rn"><?= (int)$wr['n'] ?></td>
                            <?php foreach ($window['columns'] as $col):
                                $cell = $wr['cells'][$col] ?? null;
                                $cls = [];
                                if ($cell && $cell['num']) { $cls[] = 'num'; }
                                if ($wr['selected'] && in_array($col, $wr['ev'], true)) { $cls[] = 'ev'; }
                                elseif ($wr['selected'] && in_array($col, $wr['hl'], true) && $cell) { $cls[] = 'hl'; }
                            ?>
                                <td class="<?= pe_h(implode(' ', $cls)) ?>" <?= $cell && $cell['f'] !== '' ? 'title="' . pe_h($cell['f']) . '"' : '' ?>><?= $cell ? pe_h($cell['v']) : '' ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <div class="box">
                <h6>Change log for this row</h6>
                <?php if (!$audit): ?><div class="text-muted">No edits yet.</div><?php endif; ?>
                <?php foreach ($audit as $a): ?>
                    <div><?= pe_h($a['created_at']) ?> by <?= pe_h($a['actor_name'] ?: ('user #' . (int)$a['actor_id'])) ?>:
                        <?= pe_h($a['field_name']) ?> <?= $a['old_value'] === null ? 'unknown' : '"' . pe_h($a['old_value']) . '"' ?> to <?= $a['new_value'] === null ? 'unknown' : '"' . pe_h($a['new_value']) . '"' ?>
                        <span class="text-muted">(<?= pe_h($a['action']) ?>)</span></div>
                <?php endforeach; ?>
            </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
