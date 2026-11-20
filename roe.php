<?php
/**
 * Path: /roe.php
 * Record of Employment list: employer (BN15) setup, create a draft from finalized pay lines,
 * and download ROE Web payroll extract files (.BLK, one file per BN; a zip when there are several).
 * Payroll admins only. Nothing is sent to Service Canada from here.
 * Deploy with migrations/20261119_roe.sql. See ops/DEPLOY_ROE.md.
 */
require_once __DIR__ . '/engine/roe_boot.php';

$action = (string)($_POST['action'] ?? '');
$self = 'roe.php?org_id=' . (int)$orgId;

if ($action === 'save_employer') {
    try {
        $bn = roe_normalize_bn((string)($_POST['bn15'] ?? ''));
        $emps = roe_employers($pdo, $orgId);
        if (!isset($emps['by_bn'][$bn])) {
            throw new InvalidArgumentException('That BN is not on any store in this organization.');
        }
        roe_save_employer_setting($pdo, $orgId, $bn, $_POST, $actorId, roe_now());
        roe_flash('Saved ROE settings for ' . $bn . '.', 'ok');
    } catch (Throwable $e) {
        roe_flash($e->getMessage(), 'error');
    }
    header('Location: ' . $self);
    exit;
}

if ($action === 'create') {
    try {
        $userId = (int)($_POST['user_id'] ?? 0);
        $reason = strtoupper(trim((string)($_POST['reason_code'] ?? '')));
        if (!isset(roe_reason_codes()[$reason])) {
            throw new InvalidArgumentException('Choose a reason for issuing the ROE.');
        }
        $last = roe_valid_date($_POST['last_day_paid'] ?? '');
        $res = roe_create_draft($pdo, $orgId, $userId, (string)($_POST['bn15'] ?? ''), $reason, $actorId, roe_now(), $last);
        roe_flash('Draft ROE #' . $res['id'] . ' created from finalized pay lines. Review every block before marking it ready.', 'ok');
        header('Location: roe_edit.php?org_id=' . (int)$orgId . '&id=' . (int)$res['id']);
        exit;
    } catch (Throwable $e) {
        roe_flash($e->getMessage(), 'error');
    }
    header('Location: ' . $self);
    exit;
}

if ($action === 'download') {
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    $issue = (string)($_POST['issue'] ?? 'D') === 'S' ? 'S' : 'D';
    $res = roe_export($pdo, $orgId, $ids, $issue, roe_now());
    if ($res['errors']) {
        roe_flash("Nothing was downloaded.\n" . implode("\n", $res['errors']), 'error');
        header('Location: ' . $self);
        exit;
    }
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    if (count($res['files']) === 1) {
        $f = $res['files'][0];
        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $f['name'] . '"');
        echo $f['xml'];
        exit;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'roe');
    try {
        roe_zip($res['files'], $tmp);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="ROE_' . (preg_replace('/[^0-9]/', '', substr(roe_now(), 0, 16)) ?? '') . '_' . count($res['files']) . 'files.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
    } finally {
        @unlink($tmp);
    }
    exit;
}

$status = (string)($_GET['status'] ?? '');
if (!in_array($status, ['', 'draft', 'ready', 'issued', 'amended', 'void'], true)) {
    $status = '';
}
$employers = roe_employers($pdo, $orgId);
$settings = [];
foreach ($employers['by_bn'] as $bn => $info) {
    $settings[$bn] = roe_employer_setting($pdo, $orgId, $bn);
}
$rows = roe_list($pdo, $orgId, $status);
$users = ps_list_users($pdo, $orgId);
$types = roe_pay_period_types();
$reasons = roe_reason_codes();

roe_page_head('ROE (Record of Employment)');
echo function_exists('generateMenu') ? generateMenu($pdo) : '';
echo privacy_banner_html();
?>
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
        <div>
            <h4 class="font-weight-bold mb-1">ROE (Record of Employment)</h4>
            <p class="text-muted mb-0">Builds ROEs from finalized pay lines and downloads ROE Web payroll extract files (XML v2.0, .BLK), checked against Service Canada's official schema. Upload the file in ROE Web yourself. Nothing is sent from here. Download defaults to <b>Issue = D</b> so ROE Web saves the ROEs as drafts for review.</p>
        </div>
        <?php if ($isSuper && $orgs): ?>
        <form method="get" class="form-inline">
            <select name="org_id" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
                <?php foreach ($orgs as $o): ?>
                <option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === $orgId ? 'selected' : '' ?>><?= roe_h($o['org_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
    </div>
    <?= roe_flash_html() ?>

    <div class="sheet">
        <h6 class="font-weight-bold">Legal employers (CRA payroll accounts on stores)</h6>
        <p class="text-muted mb-2">One ROE Web file is made per BN. Set each employer's pay period type (block 6) and the ROE contact (block 16). Weekly, bi-weekly and 13-per-year need one known pay period end date so the periods line up with your payroll calendar.</p>
        <?php if ($employers['missing']): ?>
        <div class="alert alert-warning mb-2">Stores with no CRA payroll account (sites.payroll_account), so no ROE can be made for pay there:
            <?= roe_h(implode(', ', array_map(static fn($s) => $s['name'] . ' (#' . $s['site_id'] . ')', $employers['missing']))) ?></div>
        <?php endif; ?>
        <table class="table table-sm table-bordered t mb-0">
            <thead><tr><th>BN15</th><th>Stores</th><th>Pay period type</th><th>Known period end</th><th>Contact first</th><th>Contact last</th><th>Area</th><th>Phone</th><th>Ext</th><th></th></tr></thead>
            <tbody>
            <?php $ei = 0; foreach ($employers['by_bn'] as $bn => $info): $s = $settings[$bn]; $ei++; $fid = 'emp-' . $ei; ?>
                <tr>
                    <td><b><?= roe_h($bn) ?></b><?= $info['problem'] !== '' ? '<div class="text-danger">' . roe_h($info['problem']) . '</div>' : '' ?><?= $info['legal'] !== '' ? '<div class="text-muted">' . roe_h($info['legal']) . '</div>' : '' ?></td>
                    <td><?= roe_h(implode(', ', array_map(static fn($x) => $x['name'], $info['sites']))) ?></td>
                    <td><select form="<?= $fid ?>" name="pay_period_type" class="form-control form-control-sm"><option value="">— choose —</option>
                        <?php foreach ($types as $k => $label): ?><option value="<?= $k ?>" <?= ($s['pay_period_type'] ?? '') === $k ? 'selected' : '' ?>><?= $k ?> – <?= roe_h($label) ?></option><?php endforeach; ?>
                    </select></td>
                    <td><input form="<?= $fid ?>" type="date" name="anchor_end" class="form-control form-control-sm" value="<?= roe_h($s['anchor_end'] ?? '') ?>"></td>
                    <td><input form="<?= $fid ?>" name="contact_first" maxlength="20" class="form-control form-control-sm" value="<?= roe_h($s['contact_first'] ?? '') ?>"></td>
                    <td><input form="<?= $fid ?>" name="contact_last" maxlength="28" class="form-control form-control-sm" value="<?= roe_h($s['contact_last'] ?? '') ?>"></td>
                    <td><input form="<?= $fid ?>" name="contact_area" maxlength="3" size="3" class="form-control form-control-sm" value="<?= roe_h($s['contact_area'] ?? '') ?>"></td>
                    <td><input form="<?= $fid ?>" name="contact_phone" maxlength="8" size="8" class="form-control form-control-sm" value="<?= roe_h($s['contact_phone'] ?? '') ?>"></td>
                    <td><input form="<?= $fid ?>" name="contact_ext" maxlength="8" size="4" class="form-control form-control-sm" value="<?= roe_h($s['contact_ext'] ?? '') ?>"></td>
                    <td><form id="<?= $fid ?>" method="post" action="<?= roe_h($self) ?>" class="m-0">
                        <input type="hidden" name="csrf_token" value="<?= roe_h($csrf) ?>">
                        <input type="hidden" name="action" value="save_employer">
                        <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
                        <input type="hidden" name="bn15" value="<?= roe_h($bn) ?>">
                        <button class="btn btn-sm btn-dark">Save</button>
                    </form></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$employers['by_bn']): ?><tr><td colspan="10" class="text-muted">No store in this organization has a CRA payroll account yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="sheet">
        <h6 class="font-weight-bold">New ROE</h6>
        <form method="post" action="<?= roe_h($self) ?>" class="form-row align-items-end">
            <input type="hidden" name="csrf_token" value="<?= roe_h($csrf) ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
            <div class="col-md-3 mb-2"><label>Employee</label><select name="user_id" class="form-control form-control-sm" required><option value="">— choose —</option>
                <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= roe_h($u['Name']) ?> (#<?= (int)$u['id'] ?>)</option><?php endforeach; ?></select></div>
            <div class="col-md-2 mb-2"><label>Legal employer (BN15)</label><select name="bn15" class="form-control form-control-sm" required>
                <?php foreach ($employers['by_bn'] as $bn => $info): ?><option value="<?= roe_h($bn) ?>"><?= roe_h($bn) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3 mb-2"><label>Reason (block 16)</label><select name="reason_code" class="form-control form-control-sm" required><option value="">— choose —</option>
                <?php foreach ($reasons as $k => $label): ?><option value="<?= $k ?>"><?= $k ?> – <?= roe_h($label) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 mb-2"><label>Last day for which paid (optional)</label><input type="date" name="last_day_paid" class="form-control form-control-sm"></div>
            <div class="col-md-2 mb-2"><button class="btn btn-sm btn-primary btn-block">Create draft from pay lines</button></div>
        </form>
        <p class="text-muted mb-0">Blocks 10, 11, 12, 15A and 15C are filled from finalized pay lines at that employer's stores (regular, overtime and travel hours; total gross by work date). Leave the last day blank to use the last paid work day.</p>
    </div>

    <div class="sheet">
        <div class="d-flex justify-content-between mb-2">
            <h6 class="font-weight-bold mb-0">ROEs</h6>
            <div>
                <?php foreach (['' => 'All', 'draft' => 'Draft', 'ready' => 'Ready', 'issued' => 'Issued', 'amended' => 'Amended', 'void' => 'Void'] as $k => $label): ?>
                <a class="btn btn-sm <?= $status === $k ? 'btn-dark' : 'btn-outline-dark' ?>" href="roe.php?org_id=<?= (int)$orgId ?>&amp;status=<?= roe_h($k) ?>"><?= roe_h($label) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <form method="post" action="<?= roe_h($self) ?>">
            <input type="hidden" name="csrf_token" value="<?= roe_h($csrf) ?>">
            <input type="hidden" name="action" value="download">
            <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
            <table class="table table-sm table-bordered t">
                <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.pick').forEach(c=>c.checked=this.checked)"></th><th>#</th><th>Employee</th><th>BN15</th><th>Reason</th><th>First day</th><th>Last day paid</th><th>Final PP end</th><th>Hours</th><th>PP</th><th>Status</th><th>Serial</th><th>Warnings</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php if ($r['status'] === 'ready'): ?><input type="checkbox" class="pick" name="ids[]" value="<?= (int)$r['id'] ?>"><?php endif; ?></td>
                        <td><?= (int)$r['id'] ?></td>
                        <td><?= roe_h($r['employee_name'] ?? ('User #' . $r['user_id'])) ?></td>
                        <td><?= roe_h($r['bn15']) ?></td>
                        <td><?= roe_h($r['reason_code']) ?></td>
                        <td><?= roe_h($r['first_day_worked']) ?></td>
                        <td><?= roe_h($r['last_day_paid']) ?></td>
                        <td><?= roe_h($r['final_pp_end']) ?></td>
                        <td class="money"><?= (int)$r['insurable_hours'] ?></td>
                        <td class="money"><?= count($r['periods']) ?></td>
                        <td class="st-<?= roe_h($r['status']) ?>"><b><?= roe_h(ucfirst((string)$r['status'])) ?></b><?= $r['amended_serial'] !== '' ? '<div class="text-muted">amends ' . roe_h($r['amended_serial']) . '</div>' : '' ?></td>
                        <td><?= roe_h($r['serial_number']) ?></td>
                        <td><?= count($r['gen_warnings']) ?: '' ?></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="roe_edit.php?org_id=<?= (int)$orgId ?>&amp;id=<?= (int)$r['id'] ?>"><?= in_array($r['status'], ['draft', 'ready'], true) ? 'Edit' : 'View' ?></a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="14" class="text-muted">No ROEs yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <div class="form-inline">
                <select name="issue" class="form-control form-control-sm mr-2">
                    <option value="D" selected>Issue = D: ROE Web saves as draft (review there)</option>
                    <option value="S">Issue = S: ROE Web submits on upload</option>
                </select>
                <button class="btn btn-sm btn-success">Download selected ready ROEs (.BLK)</button>
                <span class="text-muted ml-2">Each file is validated against the official schema first. Several BNs come as a zip with one .BLK per BN.</span>
            </div>
        </form>
    </div>
</div>
</body></html>
