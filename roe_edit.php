<?php
/**
 * Path: /roe_edit.php
 * Edit one ROE, see its validation errors, move it draft -> ready -> issued (serial from ROE Web),
 * void a draft, or start an amendment of an issued ROE. Payroll admins only.
 * The SIN is never shown in full and is never stored on the ROE.
 */
require_once __DIR__ . '/engine/roe_boot.php';

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$roe = roe_get($pdo, $orgId, $id);
if (!$roe) {
    http_response_code(404);
    exit('That ROE is not in this organization.');
}
$self = 'roe_edit.php?org_id=' . (int)$orgId . '&id=' . $id;
$action = (string)($_POST['action'] ?? '');
if ($action !== '') {
    try {
        $now = roe_now();
        if ($action === 'save') {
            roe_save_edit($pdo, $roe, roe_values_from_post($_POST), $actorId, $now);
            roe_flash('Saved. The ROE is a draft until you mark it ready.', 'ok');
        } elseif ($action === 'regenerate') {
            roe_regenerate($pdo, $roe, $actorId, $now, roe_valid_date($_POST['regen_last_day'] ?? ''));
            roe_flash('Blocks 10, 11, 12, 15A and 15C were recalculated from finalized pay lines.', 'ok');
        } elseif ($action === 'ready') {
            roe_save_edit($pdo, $roe, roe_values_from_post($_POST), $actorId, $now);
            roe_set_status($pdo, roe_get($pdo, $orgId, $id), 'ready', $actorId, $now);
            roe_flash('Marked ready. You can now download it from the ROE list.', 'ok');
        } elseif ($action === 'to_draft') {
            roe_set_status($pdo, $roe, 'draft', $actorId, $now);
        } elseif ($action === 'issue') {
            roe_set_status($pdo, $roe, 'issued', $actorId, $now, (string)($_POST['serial_number'] ?? ''));
            roe_flash('Recorded as issued with serial ' . strtoupper(trim((string)$_POST['serial_number'])) . '. It is now locked.', 'ok');
        } elseif ($action === 'void') {
            roe_set_status($pdo, $roe, 'void', $actorId, $now);
            roe_flash('Voided.', 'ok');
        } elseif ($action === 'amend') {
            $newId = roe_amend($pdo, $roe, $actorId, $now);
            roe_flash('Amendment draft #' . $newId . ' created with block 2 = ' . $roe['serial_number'] . '.', 'ok');
            header('Location: roe_edit.php?org_id=' . (int)$orgId . '&id=' . $newId);
            exit;
        }
    } catch (Throwable $e) {
        roe_flash($e->getMessage(), 'error');
    }
    header('Location: ' . $self);
    exit;
}

$chk = roe_check($pdo, $roe, substr(roe_now(), 0, 10));
$sinMask = $chk['sin'] !== '' ? privacy_mask_sin($chk['sin']) : '(none on file)';
unset($chk['sin']);
$editable = in_array((string)$roe['status'], ['draft', 'ready'], true);
$dis = $editable ? '' : ' disabled';
$types = roe_pay_period_types();
$reasons = roe_reason_codes();
$vac = is_array($roe['vacation']) ? $roe['vacation'] : ['code' => '', 'sdt' => '', 'edt' => '', 'amt' => ''];
$sh = array_values($roe['stat_holidays']);
$om = array_values($roe['other_monies']);
$periods = array_values($roe['periods']);
$block17 = roe_block17_insurable_total($roe);
$pp1 = isset($periods[0]) ? (float)$periods[0]['earnings'] : 0.0;
$events = $pdo->prepare('SELECT action, detail, actor_id, created_at FROM roe_events WHERE roe_id = ? AND org_id = ? ORDER BY id DESC');
$events->execute([$id, $orgId]);
$events = $events->fetchAll(PDO::FETCH_ASSOC);
$emp = roe_employee($pdo, $orgId, (int)$roe['user_id'], false);

$f = static function (string $name, string $label, int $max = 0, string $type = 'text', string $col = 'col-md-3') use ($roe, $dis): string {
    $v = (string)($roe[$name] ?? '');
    return '<div class="' . $col . ' mb-2"><label>' . roe_h($label) . '</label><input type="' . $type . '" name="' . $name . '" class="form-control form-control-sm" value="' . roe_h($v) . '"'
        . ($max > 0 ? ' maxlength="' . $max . '"' : '') . $dis . '></div>';
};

roe_page_head('ROE #' . $id);
echo function_exists('generateMenu') ? generateMenu($pdo) : '';
echo privacy_banner_html();
?>
<div class="container-fluid">
    <p><a href="roe.php?org_id=<?= (int)$orgId ?>">&larr; All ROEs</a></p>
    <h4 class="font-weight-bold">ROE #<?= $id ?> · <?= roe_h($emp['display_name'] ?? ('User #' . $roe['user_id'])) ?> · <span class="st-<?= roe_h($roe['status']) ?>"><?= roe_h(ucfirst((string)$roe['status'])) ?></span>
        <?= $roe['serial_number'] !== '' ? ' · Serial ' . roe_h($roe['serial_number']) : '' ?></h4>
    <?= roe_flash_html() ?>

    <div class="sheet">
        <?php if ($chk['errors']): ?>
            <b class="text-danger">Must fix before it can be marked ready (<?= count($chk['errors']) ?>)</b>
            <ul class="err mb-2"><?php foreach ($chk['errors'] as $m): ?><li><?= roe_h($m) ?></li><?php endforeach; ?></ul>
        <?php else: ?>
            <b class="text-success">Passes the ROE Web business rules this app checks.</b> The XML is also checked against the official schema at download.
        <?php endif; ?>
        <?php $warnAll = array_values(array_unique(array_merge($chk['warnings'], $roe['gen_warnings']))); if ($warnAll): ?>
            <div class="mt-2"><b class="text-warning">Check these</b><ul class="warn mb-0"><?php foreach ($warnAll as $m): ?><li><?= roe_h($m) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
    </div>

    <form method="post" action="<?= roe_h($self) ?>">
    <input type="hidden" name="csrf_token" value="<?= roe_h($csrf) ?>">
    <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="sheet">
        <h6 class="font-weight-bold">Employer and employee (blocks 2–9, 13)</h6>
        <div class="form-row">
            <div class="col-md-2 mb-2"><label>Block 2 amends serial</label><input class="form-control form-control-sm" value="<?= roe_h($roe['amended_serial']) ?>" disabled></div>
            <?= $f('payroll_ref', 'Block 3 payroll reference', 26, 'text', 'col-md-2') ?>
            <div class="col-md-2 mb-2"><label>Block 5 BN15</label><input class="form-control form-control-sm" value="<?= roe_h($roe['bn15']) ?>" disabled></div>
            <div class="col-md-2 mb-2"><label>Block 6 pay period type</label><select name="pay_period_type" class="form-control form-control-sm"<?= $dis ?>><option value="">— choose —</option>
                <?php foreach ($types as $k => $label): ?><option value="<?= $k ?>" <?= $roe['pay_period_type'] === $k ? 'selected' : '' ?>><?= $k ?> – <?= roe_h($label) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 mb-2"><label>Block 8 SIN (from employee file)</label><input class="form-control form-control-sm" value="<?= roe_h($sinMask) ?>" disabled></div>
            <?= $f('occupation', 'Block 13 occupation', 40, 'text', 'col-md-2') ?>
        </div>
        <div class="form-row">
            <?= $f('emp_first', 'Block 9 legal first name', 20, 'text', 'col-md-2') ?>
            <?= $f('emp_middle', 'Initial', 4, 'text', 'col-md-1') ?>
            <?= $f('emp_last', 'Legal last name', 28, 'text', 'col-md-2') ?>
            <?= $f('emp_addr1', 'Address line 1 (street)', 35, 'text', 'col-md-2') ?>
            <?= $f('emp_addr2', 'Line 2 (city)', 35, 'text', 'col-md-2') ?>
            <?= $f('emp_addr3', 'Line 3 (province, country)', 35, 'text', 'col-md-2') ?>
            <?= $f('emp_postal', 'Postal code', 10, 'text', 'col-md-1') ?>
        </div>
        <p class="text-muted mb-0">Block 9 is a copy taken when the draft was made. Fix the employee file too, so the T4 and the next ROE match.</p>
    </div>

    <div class="sheet">
        <h6 class="font-weight-bold">Dates, recall, reason (blocks 10–12, 14, 16, 18, 20)</h6>
        <div class="form-row">
            <?= $f('first_day_worked', 'Block 10 first day worked', 0, 'date', 'col-md-2') ?>
            <?= $f('last_day_paid', 'Block 11 last day for which paid', 0, 'date', 'col-md-2') ?>
            <?= $f('final_pp_end', 'Block 12 final pay period ending', 0, 'date', 'col-md-2') ?>
            <div class="col-md-2 mb-2"><label>Block 14 expected recall</label><select name="recall_code" class="form-control form-control-sm"<?= $dis ?>>
                <?php foreach (['U' => 'U – Unknown', 'N' => 'N – Not returning', 'Y' => 'Y – Expected date of recall'] as $k => $label): ?><option value="<?= $k ?>" <?= ($roe['recall_code'] ?: 'U') === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
            <?= $f('recall_date', 'Recall date (only for Y)', 0, 'date', 'col-md-2') ?>
            <div class="col-md-2 mb-2"><label>Language (B20)</label><select name="language" class="form-control form-control-sm"<?= $dis ?>><option value="E" <?= $roe['language'] !== 'F' ? 'selected' : '' ?>>English</option><option value="F" <?= $roe['language'] === 'F' ? 'selected' : '' ?>>Français</option></select></div>
        </div>
        <div class="form-row">
            <div class="col-md-4 mb-2"><label>Block 16 reason</label><select name="reason_code" class="form-control form-control-sm"<?= $dis ?>><option value="">— choose —</option>
                <?php foreach ($reasons as $k => $label): ?><option value="<?= $k ?>" <?= $roe['reason_code'] === $k ? 'selected' : '' ?>><?= $k ?> – <?= roe_h($label) ?></option><?php endforeach; ?></select></div>
            <?= $f('contact_first', 'Contact first name', 20, 'text', 'col-md-2') ?>
            <?= $f('contact_last', 'Contact last name', 28, 'text', 'col-md-2') ?>
            <?= $f('contact_area', 'Area code', 3, 'text', 'col-md-1') ?>
            <?= $f('contact_phone', 'Phone (7 digits)', 8, 'text', 'col-md-2') ?>
            <?= $f('contact_ext', 'Ext', 8, 'text', 'col-md-1') ?>
        </div>
        <div class="form-row">
            <div class="col-md-12 mb-2"><label>Block 18 comments (max 160; required for K00 and K15; do not repeat information already on the ROE)</label>
                <input name="comments" maxlength="160" class="form-control form-control-sm" value="<?= roe_h($roe['comments']) ?>"<?= $dis ?>></div>
        </div>
    </div>

    <div class="sheet">
        <h6 class="font-weight-bold">Insurable hours and earnings (blocks 15A, 15C)</h6>
        <div class="form-row">
            <?= $f('insurable_hours', 'Block 15A total insurable hours (whole, rounded up)', 4, 'number', 'col-md-3') ?>
            <div class="col-md-3 mb-2"><label>Hours from pay lines (exact)</label><input class="form-control form-control-sm" value="<?= roe_h($roe['hours_exact']) ?>" disabled></div>
            <div class="col-md-3 mb-2"><label>Generated</label><input class="form-control form-control-sm" value="<?= roe_h($roe['generated_at']) ?>" disabled></div>
        </div>
        <p class="text-muted">PP 1 is the final pay period. Up to <?= roe_max_periods((string)$roe['pay_period_type']) ?: 53 ?> periods for this pay period type, or fewer since the first day worked. Enter 0.00 for a period with no insurable earnings. Clear an amount to drop that row.</p>
        <table class="table table-sm table-bordered t" style="max-width:720px">
            <thead><tr><th>PP</th><th>Start</th><th>End</th><th>Hours</th><th>Insurable earnings</th></tr></thead>
            <tbody>
            <?php foreach ($periods as $i => $p): ?>
                <tr><td><?= (int)$p['nbr'] ?></td>
                    <td><input type="hidden" name="pp_start[]" value="<?= roe_h($p['start'] ?? '') ?>"><?= roe_h($p['start'] ?? '') ?></td>
                    <td><input type="hidden" name="pp_end[]" value="<?= roe_h($p['end'] ?? '') ?>"><?= roe_h($p['end'] ?? '') ?></td>
                    <td class="money"><input type="hidden" name="pp_hours[]" value="<?= roe_h($p['hours'] ?? 0) ?>"><?= roe_h($p['hours'] ?? 0) ?></td>
                    <td><input name="pp_amt[]" class="form-control form-control-sm money" value="<?= roe_h(roe_money((float)$p['earnings'])) ?>"<?= $dis ?>></td></tr>
            <?php endforeach; ?>
            <?php if ($editable): ?>
                <tr><td>+</td><td colspan="3" class="text-muted">Add a pay period by hand (non-standard pay periods)</td><td><input type="hidden" name="pp_start[]" value=""><input type="hidden" name="pp_end[]" value=""><input type="hidden" name="pp_hours[]" value="0"><input name="pp_amt[]" class="form-control form-control-sm money" value=""></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <p class="mb-0">PP 1 as exported: <b><?= roe_h(roe_money(roe_pp1_export_amount($roe, $pp1))) ?></b><?= $block17 > 0 ? ' (block 17 insurable amounts: ' . roe_h(roe_money($block17)) . ')' : '' ?></p>
    </div>

    <div class="sheet">
        <h6 class="font-weight-bold">Separation payments (blocks 17A, 17B, 17C)</h6>
        <div class="form-row">
            <div class="col-md-4 mb-2"><label>17A vacation pay code</label><select name="vp_code" class="form-control form-control-sm"<?= $dis ?>><option value="">— none —</option>
                <?php foreach (roe_vacation_codes() as $k => $r): ?><option value="<?= $k ?>" <?= (string)$vac['code'] === (string)$k ? 'selected' : '' ?>><?= $k ?> – <?= roe_h($r['label']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 mb-2"><label>Start</label><input type="date" name="vp_sdt" class="form-control form-control-sm" value="<?= roe_h($vac['sdt'] ?? '') ?>"<?= $dis ?>></div>
            <div class="col-md-2 mb-2"><label>End</label><input type="date" name="vp_edt" class="form-control form-control-sm" value="<?= roe_h($vac['edt'] ?? '') ?>"<?= $dis ?>></div>
            <div class="col-md-2 mb-2"><label>Amount</label><input name="vp_amt" class="form-control form-control-sm money" value="<?= roe_h($vac['amt'] ?? '') ?>"<?= $dis ?>></div>
        </div>
        <label class="mt-2">17B statutory holiday pay (dates after the last day for which paid; up to 10)</label>
        <div class="form-row">
            <?php for ($i = 0; $i < max(3, count($sh) + 1) && $i < 10; $i++): ?>
                <div class="col-md-2 mb-2"><input type="date" name="sh_dt[]" class="form-control form-control-sm" value="<?= roe_h($sh[$i]['dt'] ?? '') ?>"<?= $dis ?>></div>
                <div class="col-md-2 mb-2"><input name="sh_amt[]" placeholder="Amount" class="form-control form-control-sm money" value="<?= roe_h($sh[$i]['amt'] ?? '') ?>"<?= $dis ?>></div>
            <?php endfor; ?>
        </div>
        <label class="mt-2">17C other monies (up to 3)</label>
        <?php for ($i = 0; $i < 3; $i++): $o = $om[$i] ?? []; ?>
        <div class="form-row">
            <div class="col-md-4 mb-2"><select name="om_code[]" class="form-control form-control-sm"<?= $dis ?>><option value="">— none —</option>
                <?php foreach (roe_other_monies_codes() as $k => $r): ?><option value="<?= $k ?>" <?= ($o['code'] ?? '') === $k ? 'selected' : '' ?>><?= $k ?> – <?= roe_h($r['label']) ?><?= $r['insurable'] ? ' (insurable)' : '' ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 mb-2"><input type="date" name="om_sdt[]" class="form-control form-control-sm" value="<?= roe_h($o['sdt'] ?? '') ?>"<?= $dis ?>></div>
            <div class="col-md-2 mb-2"><input type="date" name="om_edt[]" class="form-control form-control-sm" value="<?= roe_h($o['edt'] ?? '') ?>"<?= $dis ?>></div>
            <div class="col-md-2 mb-2"><input name="om_amt[]" placeholder="Amount" class="form-control form-control-sm money" value="<?= roe_h($o['amt'] ?? '') ?>"<?= $dis ?>></div>
        </div>
        <?php endfor; ?>
        <div class="form-row">
            <div class="col-md-8 mb-2"><label>Are the insurable amounts above (17A codes 2–4, all 17B, insurable 17C) already inside the finalized pay lines used for PP 1?</label>
                <select name="block17_in_lines" class="form-control form-control-sm"<?= $dis ?>>
                    <option value="" <?= $roe['block17_in_lines'] === null || $roe['block17_in_lines'] === '' ? 'selected' : '' ?>>— answer when block 17 has insurable amounts —</option>
                    <option value="1" <?= (string)$roe['block17_in_lines'] === '1' ? 'selected' : '' ?>>Yes, already in the pay lines (do not add again)</option>
                    <option value="0" <?= (string)$roe['block17_in_lines'] === '0' ? 'selected' : '' ?>>No, add them to PP 1 on export</option>
                </select></div>
        </div>
    </div>

    <?php if ($editable): ?>
    <div class="sheet">
        <button class="btn btn-primary" name="action" value="save">Save draft</button>
        <?php if (!$chk['errors']): ?><button class="btn btn-success" name="action" value="ready">Mark ready to download</button><?php endif; ?>
        <?php if ($roe['status'] === 'ready'): ?><button class="btn btn-outline-secondary" name="action" value="to_draft">Back to draft</button><?php endif; ?>
        <button class="btn btn-outline-danger float-right" name="action" value="void" onclick="return confirm('Void this ROE? It will not be exported.')">Void</button>
    </div>
    <?php endif; ?>
    </form>

    <?php if ($editable): ?>
    <div class="sheet">
        <form method="post" action="<?= roe_h($self) ?>" class="form-inline">
            <input type="hidden" name="csrf_token" value="<?= roe_h($csrf) ?>">
            <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="regenerate">
            <label class="mr-2">Last day for which paid (optional)</label><input type="date" name="regen_last_day" class="form-control form-control-sm mr-2">
            <button class="btn btn-sm btn-outline-primary" onclick="return confirm('Recalculate blocks 10, 11, 12, 15A and 15C from pay lines? Your edits to those blocks are replaced.')">Recalculate from pay lines</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($roe['status'] === 'ready'): ?>
    <div class="sheet">
        <form method="post" action="roe.php?org_id=<?= (int)$orgId ?>" class="form-inline mb-2">
            <input type="hidden" name="csrf_token" value="<?= roe_h($csrf) ?>">
            <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
            <input type="hidden" name="action" value="download">
            <input type="hidden" name="ids[]" value="<?= $id ?>">
            <select name="issue" class="form-control form-control-sm mr-2"><option value="D" selected>Issue = D (draft in ROE Web)</option><option value="S">Issue = S (submit on upload)</option></select>
            <button class="btn btn-sm btn-success">Download this ROE (.BLK)</button>
        </form>
        <form method="post" action="<?= roe_h($self) ?>" class="form-inline">
            <input type="hidden" name="csrf_token" value="<?= roe_h($csrf) ?>">
            <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="issue">
            <label class="mr-2">After ROE Web issues it, record its serial number:</label>
            <input name="serial_number" maxlength="9" placeholder="W12345678" class="form-control form-control-sm mr-2" required>
            <button class="btn btn-sm btn-dark">Record as issued</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($roe['status'] === 'issued'): ?>
    <div class="sheet">
        <form method="post" action="<?= roe_h($self) ?>">
            <input type="hidden" name="csrf_token" value="<?= roe_h($csrf) ?>">
            <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-sm btn-outline-dark" name="action" value="amend">Start an amendment (block 2 = <?= roe_h($roe['serial_number']) ?>)</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="sheet">
        <h6 class="font-weight-bold">History</h6>
        <table class="table table-sm mb-0"><?php foreach ($events as $ev): ?><tr><td><?= roe_h($ev['created_at']) ?></td><td><?= roe_h($ev['action']) ?></td><td><?= roe_h($ev['detail']) ?></td><td>user #<?= (int)$ev['actor_id'] ?></td></tr><?php endforeach; ?></table>
    </div>
</div>
</body></html>
