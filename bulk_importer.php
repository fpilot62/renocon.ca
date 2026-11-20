<?php
/** Path: /bulk_importer.php
 *  Bulk CSV import for Employees, Sites, and Hours (attendance).
 *  Rebuilt 2026-07-20 to match the published templates in /templates/.
 *  2026-07-20: employee CSV collects StartDate (not VacationPercent); vacation
 *  entitlement is derived from tenure + site province by the payroll engine.
 *  2026-07-22: paste-from-spreadsheet path (TSV/CSV) alongside file upload.
 *  2026-07-22: Match columns — map non-template headers before validate/import.
 *  2026-07-26: Sites template; hours either/or punches vs RegularHours; employee exemptions.
 *  Previous versions archived as bulk_importer.php.bak.*
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'importer_lib.php';
require_once 'engine/menu_generator.php';

restrictToAdmin(20); // Admin (20) or Superadmin (10) only

$sess = ['user_level' => (int)$_SESSION['user_level'], 'org_id' => (int)$_SESSION['org_id']];

$result = null;
$mode = '';
$dry = false;
$input_source = 'file';
$paste_data = '';
$step = 'form'; // form | map
$raw_csv = '';
$map_headers = [];
$map_suggest = [];
$map_targets = [];
$map_errors = [];
$force_map = false;

/**
 * Read uploaded file or paste into a single raw text blob.
 * @return array{ok:bool,text:string,errors:string[]}
 */
function pg_bi_read_input(string $input_source, string $paste_data): array {
    if ($input_source === 'paste') {
        if (trim($paste_data) === '') {
            return ['ok' => false, 'text' => '', 'errors' => ['Paste area is empty. Copy header + data rows from Excel or Google Sheets.']];
        }
        return ['ok' => true, 'text' => $paste_data, 'errors' => []];
    }
    if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        return ['ok' => false, 'text' => '', 'errors' => ['No CSV file was uploaded.']];
    }
    $text = @file_get_contents($_FILES['csv_file']['tmp_name']);
    if ($text === false || trim($text) === '') {
        return ['ok' => false, 'text' => '', 'errors' => ['Could not read the uploaded CSV (empty or unreadable).']];
    }
    return ['ok' => true, 'text' => $text, 'errors' => []];
}

/** Build column_map from POST map[target]=source_key. */
function pg_bi_mapping_from_post(array $post_map, string $mode): array {
    $mapping = [];
    foreach (pgcsv_target_fields($mode) as $f) {
        $mapping[$f['key']] = '';
    }
    foreach ($post_map as $tk => $sk) {
        $tk = pgcsv_norm_key((string)$tk);
        $sk = trim((string)$sk);
        if ($sk === '' || $sk === '__none__') {
            $mapping[$tk] = '';
        } else {
            $mapping[$tk] = pgcsv_norm_key($sk);
        }
    }
    return $mapping;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['import_mode'] ?? '';
    $dry  = !empty($_POST['dry_run']);
    $input_source = ($_POST['input_source'] ?? 'file') === 'paste' ? 'paste' : 'file';
    $paste_data = (string)($_POST['paste_data'] ?? '');
    $force_map = !empty($_POST['force_map']);

    if ($mode !== 'employees' && $mode !== 'hours' && $mode !== 'sites') {
        $result = ['errors' => ['Unknown import mode.'], 'inserted' => 0, 'preview' => []];
    } elseif (isset($_POST['confirm_mapping'])) {
        // Step 2: user confirmed Match columns → validate/import with remapped rows
        $raw_csv = (string)($_POST['raw_csv'] ?? '');
        $mapping = pg_bi_mapping_from_post($_POST['map'] ?? [], $mode);
        $map_errors = pgcsv_validate_mapping($mapping, $mode);
        if ($map_errors) {
            $peek = pgcsv_peek($raw_csv);
            $step = 'map';
            $map_headers = $peek['headers'];
            $map_suggest = $mapping;
            $map_targets = pgcsv_target_fields($mode);
        } elseif (trim($raw_csv) === '') {
            $result = ['errors' => ['Mapped data was lost. Re-upload or paste and try again.'], 'inserted' => 0, 'preview' => []];
        } else {
            $result = pg_import_from_text($pdo, $mode, $raw_csv, $sess, $dry, $mapping);
            $step = 'form';
        }
    } elseif (isset($_POST['process_import']) || isset($_POST['prepare_mapping'])) {
        // Step 1: ingest file/paste → skip map if exact headers, else show Match columns
        $got = pg_bi_read_input($input_source, $paste_data);
        if (!$got['ok']) {
            $result = ['errors' => $got['errors'], 'inserted' => 0, 'preview' => []];
        } else {
            $raw_csv = $got['text'];
            // Keep paste box filled when coming from paste
            if ($input_source === 'paste') $paste_data = $raw_csv;

            $peek = pgcsv_peek($raw_csv);
            if ($peek['errors']) {
                $result = ['errors' => $peek['errors'], 'inserted' => 0, 'preview' => []];
            } else {
                $src_keys = array_column($peek['headers'], 'key');
                $exact = pgcsv_headers_exact_match($src_keys, $mode);
                if ($exact && !$force_map && !isset($_POST['prepare_mapping'])) {
                    $result = pg_import_from_text($pdo, $mode, $raw_csv, $sess, $dry, null);
                } else {
                    $step = 'map';
                    $map_headers = $peek['headers'];
                    $map_suggest = pgcsv_suggest_mapping($peek['headers'], $mode);
                    $map_targets = pgcsv_target_fields($mode);
                }
            }
        }
    }
}

$hdr_employees = pgcsv_template_header('employees');
$hdr_hours = pgcsv_template_header('hours');
$hdr_sites = pgcsv_template_header('sites');

if (!function_exists('sip_sites_active_sql')) {
    require_once __DIR__ . '/engine/site_status_lib.php';
}
$bi_org = (int)$sess['org_id'];
$bi_active_sql = sip_sites_active_sql('');
if (((int)$sess['user_level'] <= 10)) {
    $bi_sites = $pdo->query("SELECT SId, nick_name, org_id FROM sites WHERE $bi_active_sql ORDER BY nick_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $bi_st = $pdo->prepare("SELECT SId, nick_name, org_id FROM sites WHERE org_id = ? AND $bi_active_sql ORDER BY nick_name ASC");
    $bi_st->execute([$bi_org]);
    $bi_sites = $bi_st->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Bulk CSV Import | SIP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { background: #f1f5f9; padding-top: 110px; padding-bottom: 96px; font-family: 'Inter', sans-serif; color: #0f172a; }
        .importer-card { background: white; padding: 30px; border-radius: 14px; box-shadow: 0 10px 25px rgba(0,0,0,0.06); max-width: 860px; margin: 20px auto 96px; }
        h2 { font-weight: 800; }
        .template-box { background: #f0f7f9; border: 1px solid #cde4eb; padding: 18px 20px; border-radius: 10px; margin: 20px 0; }
        .btn-dl { display: inline-block; padding: 10px 16px; background: #008ba3; color: white; text-decoration: none; border-radius: 6px; font-weight: 700; margin: 4px 8px 4px 0; font-size: 12px; border: none; cursor: pointer; font-family: inherit; }
        .btn-dl:hover { background: #006b7d; color: white; }
        .upload-area { border: 2px dashed #27ae60; padding: 26px; text-align: center; border-radius: 10px; background: #fafafa; margin: 20px 0; }
        .paste-area { border: 2px dashed #008ba3; padding: 18px 20px; border-radius: 10px; background: #f8fafc; margin: 20px 0; text-align: left; }
        .paste-area textarea { width: 100%; min-height: 180px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px; box-sizing: border-box; resize: vertical; }
        .source-tabs { display: flex; gap: 8px; margin: 16px 0 0; }
        .source-tabs button { flex: 1; padding: 10px 12px; border: 1px solid #cbd5e1; background: #f8fafc; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; color: #334155; font-family: inherit; }
        .source-tabs button.active { background: #27ae60; color: white; border-color: #27ae60; }
        .source-tabs button.active[data-source="paste"] { background: #008ba3; border-color: #008ba3; }
        /* Clearance: Ask AI FAB is fixed bottom-right at z-index ~2e9 and can steal clicks on RUN IMPORT */
        .btn-submit { width: 100%; background: #27ae60; color: white; border: none; padding: 15px; border-radius: 8px; font-size: 15px; font-weight: 800; cursor: pointer; letter-spacing: 1px; }
        .btn-submit:hover { background: #1f8b4d; }
        .btn-submit:disabled { background: #94a3b8; cursor: not-allowed; }
        .btn-secondary { width: 100%; background: #e2e8f0; color: #0f172a; border: none; padding: 12px; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer; margin-top: 8px; font-family: inherit; }
        .btn-secondary:hover { background: #cbd5e1; }
        #importClientErr { display: none; }
        .msg-err { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 14px 18px; border-radius: 8px; margin: 18px 0; font-size: 13px; }
        .msg-ok  { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 14px 18px; border-radius: 8px; margin: 18px 0; font-size: 13px; }
        .msg-dry { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; padding: 14px 18px; border-radius: 8px; margin: 18px 0; font-size: 13px; }
        .msg-map { background: #f0fdfa; border: 1px solid #99f6e4; color: #115e59; padding: 14px 18px; border-radius: 8px; margin: 18px 0; font-size: 13px; }
        table.preview { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 10px; }
        table.preview th, table.preview td { border: 1px solid #e2e8f0; padding: 6px 10px; text-align: left; }
        table.preview th { background: #f8fafc; }
        table.map-table { width: 100%; border-collapse: collapse; font-size: 13px; margin: 12px 0 18px; }
        table.map-table th, table.map-table td { border: 1px solid #e2e8f0; padding: 8px 10px; text-align: left; vertical-align: middle; }
        table.map-table th { background: #f8fafc; font-size: 12px; }
        table.map-table select { width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; font-family: inherit; }
        table.map-table .req { color: #b45309; font-weight: 700; }
        table.map-table .opt { color: #94a3b8; font-size: 11px; }
        label.radio { display: block; margin: 6px 0; font-size: 14px; }
        .hint { font-size: 12px; color: #64748b; }
        ul.errlist { margin: 8px 0 0 18px; padding: 0; }
        .vac-note { background: #fffbeb; border: 1px solid #fde68a; color: #78350f; padding: 12px 16px; border-radius: 8px; margin: 14px 0; font-size: 12px; line-height: 1.45; }
        #copyHdrStatus { font-size: 12px; color: #166534; margin-left: 8px; }
        .map-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .map-actions .btn-submit { flex: 1; min-width: 180px; }
        .map-actions .btn-secondary { flex: 0 0 auto; width: auto; margin-top: 0; padding: 15px 18px; }
    </style>
</head>
<body>
<?php echo generateMenu($pdo); ?>

<div style="max-width:860px;margin:0 auto;padding:0 10px;"><?php require_once __DIR__.'/payroll_help.php'; echo payroll_help_panel('bulk_importer.php'); ?></div>

<div class="importer-card">
    <h2><i class="fas fa-file-import" style="color:#27ae60;"></i> Bulk CSV Import</h2>
    <p class="hint">Import new employees or historical hours via file upload or paste from Excel/Google Sheets. Non-template headers open a <b>Match columns</b> step. Everything is validated first &mdash; if any row has a problem, nothing is imported.</p>

    <?php if ($step === 'map'): ?>
        <div class="msg-map" id="matchColumnsPanel">
            <strong><i class="fas fa-table-columns"></i> Match columns</strong>
            <p style="margin:8px 0 0;">Your file headers do not exactly match the template. Map each SIP field to a column from your file, then continue to validate or import.
            Detected <?= (int)count($map_headers) ?> source column(s). Suggestions are pre-filled from similar names (e.g. <code>E-mail</code> &rarr; Email, <code>Hire Date</code> &rarr; StartDate).</p>
        </div>

        <?php if ($map_errors): ?>
            <div class="msg-err">
                <strong><i class="fas fa-triangle-exclamation"></i> Fix mapping before continuing.</strong>
                <ul class="errlist">
                    <?php foreach ($map_errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" id="mapForm">
            <input type="hidden" name="import_mode" value="<?= htmlspecialchars($mode) ?>">
            <input type="hidden" name="input_source" value="<?= htmlspecialchars($input_source) ?>">
            <input type="hidden" name="raw_csv" value="<?= htmlspecialchars($raw_csv) ?>">
            <label class="radio" style="margin-bottom:12px;">
                <input type="checkbox" name="dry_run" value="1" <?= $dry ? 'checked' : '' ?>>
                <b>Validate only (dry run)</b> &mdash; check the data and show a preview without saving anything
            </label>

            <table class="map-table">
                <thead>
                    <tr>
                        <th style="width:38%;">Our field</th>
                        <th>Your column</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($map_targets as $f):
                    $sel = $map_suggest[$f['key']] ?? '';
                ?>
                    <tr>
                        <td>
                            <code><?= htmlspecialchars($f['label']) ?></code>
                            <?php if ($f['required']): ?>
                                <span class="req" title="Required">*</span>
                            <?php else: ?>
                                <span class="opt">optional<?= $f['key'] === 'assignedsiteids' ? ' (defaults to PrimarySiteID)' : '' ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <select name="map[<?= htmlspecialchars($f['key']) ?>]">
                                <option value="__none__">— not mapped —</option>
                                <?php foreach ($map_headers as $h): ?>
                                    <option value="<?= htmlspecialchars($h['key']) ?>" <?= $sel === $h['key'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($h['raw']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($mode === 'hours'): ?>
                <p class="hint">Hours: map WorkDate, EmployeeEmail, SiteName, and either ClockIn+ClockOut <b>or</b> RegularHours.</p>
            <?php elseif ($mode === 'sites'): ?>
                <p class="hint">Sites: map SiteName (required). <b>Timezone</b> strongly recommended (America/Toronto etc.); Province used as fallback. BN15, WSIB, address optional. Org from your session.</p>
            <?php else: ?>
                <p class="hint">Users: map FullName, Email, StartDate, HourlyRate, and PrimarySiteName. Tel_Mobile needed for temp password/PIN. Role optional; blank = staff (40). Org is your logged-in organization.</p>
            <?php endif; ?>

            <div class="map-actions">
                <button type="submit" name="confirm_mapping" value="1" class="btn-submit">CONTINUE WITH MAPPING</button>
                <a class="btn-secondary" href="/bulk_importer.php" style="text-decoration:none;display:inline-flex;align-items:center;">Cancel</a>
            </div>
        </form>

    <?php else: ?>

    <div class="template-box">
        <strong><i class="fas fa-download"></i> Step 1: Templates</strong>
        <p class="hint" style="margin:6px 0 10px;">Three CSVs only. Example and NOTE rows are ignored on import. Different header names are fine &mdash; you will Match columns next.</p>
        <a href="/templates/employee_import_template.csv?v=users3" class="btn-dl" download="users_import_template.csv"><i class="fas fa-users"></i> Users template</a>
        <a href="/templates/sites_import_template.csv?v=tz" class="btn-dl" download="sites_import_template.csv"><i class="fas fa-building"></i> Sites template</a>
        <a href="/templates/hours_import_template.csv?v=eitheror" class="btn-dl" download="hours_import_template.csv"><i class="fas fa-clock"></i> Hours template</a>
        <button type="button" class="btn-dl" id="btnCopyHeaders" style="background:#0f766e;"><i class="fas fa-clipboard"></i> Copy headers</button>
        <span id="copyHdrStatus" aria-live="polite"></span>
        <p class="hint" style="margin:12px 0 0;"><b>Users:</b> optional <b>Role</b> (blank = staff 40). Lower RoleID = more access (10 superadmin &rarr; 20 admin &rarr; 25 site manager &rarr; 30 supervisor &rarr; 40 staff); allow when <code>user_role &lt;= required</code>. Org from your login (no OrgID). Password/PIN from phone last-4. Superadmin (10) only if you are already a superadmin.</p>
    </div>

    <div class="vac-note">
        <strong>Import order: Sites &rarr; Users &rarr; Hours.</strong>
        Users live in one sheet (everyone is a <code>users</code> row; Role sets access). WSIB #/% go on Sites (not Users). Hours: <b>ClockIn+ClockOut OR RegularHours</b> (see NOTE in the CSV); punches use the site timezone.
        <p style="margin:10px 0 0;"><b>Active locations</b> (use these SiteName values; Inactive sites are hidden):
            <?php if (!$bi_sites): ?>
                <span class="hint">none — add or Activate sites under <a href="/manage_sites.php">Manage Sites</a>.</span>
            <?php else: ?>
                <?php foreach ($bi_sites as $bs): ?>
                    <code title="SId <?= (int)$bs['SId'] ?>"><?= htmlspecialchars($bs['nick_name']) ?></code>
                <?php endforeach; ?>
            <?php endif; ?>
        </p>
    </div>

    <form method="POST" enctype="multipart/form-data" id="importForm">
        <strong>Step 2: Select Import Type</strong>
        <label class="radio"><input type="radio" name="import_mode" value="employees" <?= ($mode === '' || $mode === 'employees') ? 'checked' : '' ?>>
            <b>Users</b> &mdash; creates login, PIN, org/site assignment, StartDate / service record, pay rates, Role, exemptions</label>
        <label class="radio"><input type="radio" name="import_mode" value="sites" <?= $mode === 'sites' ? 'checked' : '' ?>>
            <b>Sites</b> &mdash; create/update locations (BN15, address, Timezone, WSIB number &amp; %)</label>
        <label class="radio"><input type="radio" name="import_mode" value="hours" <?= $mode === 'hours' ? 'checked' : '' ?>>
            <b>Hours</b> &mdash; attendance from punches <i>or</i> RegularHours (ready for Bulk Finalize)</label>
        <p class="hint">Import Sites then Users then Hours. Hours match users by Email within your organization.</p>

        <strong>Step 3: Provide data</strong>
        <input type="hidden" name="input_source" id="input_source" value="<?= htmlspecialchars($input_source) ?>">
        <div class="source-tabs" role="tablist" aria-label="Data source">
            <button type="button" class="<?= $input_source !== 'paste' ? 'active' : '' ?>" data-source="file" id="tabFile"><i class="fas fa-file-csv"></i> Upload file</button>
            <button type="button" class="<?= $input_source === 'paste' ? 'active' : '' ?>" data-source="paste" id="tabPaste"><i class="fas fa-paste"></i> Paste from spreadsheet</button>
        </div>

        <div class="upload-area" id="panelFile" style="<?= $input_source === 'paste' ? 'display:none;' : '' ?>">
            <strong>Upload CSV File</strong><br><br>
            <input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv,application/vnd.ms-excel,.txt" <?= $input_source !== 'paste' ? 'required' : 'disabled' ?>>
        </div>

        <div class="paste-area" id="panelPaste" style="<?= $input_source === 'paste' ? '' : 'display:none;' ?>">
            <strong>Paste from spreadsheet</strong>
            <p class="hint" style="margin:8px 0 10px;">In Excel or Google Sheets, select the header row plus your data rows, copy, and paste here. Tab-separated paste from Excel is fine; comma-separated CSV text also works. If headers differ from the template, you will get a Match columns step.</p>
            <textarea name="paste_data" id="paste_data" placeholder="Paste header + data rows here…" <?= $input_source === 'paste' ? 'required' : 'disabled' ?>><?= htmlspecialchars($paste_data) ?></textarea>
        </div>

        <label class="radio" style="margin-bottom:8px;">
            <input type="checkbox" name="dry_run" value="1" <?= ($dry || $result === null) ? 'checked' : '' ?>>
            <b>Validate only (dry run)</b> &mdash; check the data and show a preview without saving anything
        </label>
        <label class="radio" style="margin-bottom:14px;">
            <input type="checkbox" name="force_map" value="1" <?= $force_map ? 'checked' : '' ?>>
            Always open <b>Match columns</b> (even when headers already match the template)
        </label>

        <div class="msg-err" id="importClientErr" role="alert"></div>
        <button type="submit" name="process_import" value="1" id="btnRunImport" class="btn-submit">RUN IMPORT</button>
        <p class="hint" style="margin-top:10px;">If the green button seems unresponsive: press <b>Enter</b> after focusing a field, use <b>Paste from spreadsheet</b>, or Skip/Escape any open guided tour. A browser PDF extension can also block clicks — try another browser or disable it temporarily.</p>
    </form>

    <?php if ($result !== null): ?>
        <?php if (!empty($result['errors'])): ?>
            <div class="msg-err">
                <strong><i class="fas fa-triangle-exclamation"></i> Import blocked &mdash; nothing was saved.</strong>
                <ul class="errlist">
                    <?php foreach ($result['errors'] as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php elseif ($dry): ?>
            <div class="msg-dry"><strong><i class="fas fa-circle-check"></i> Validation passed.</strong>
                <?= count($result['preview']) ?> row(s) are ready. Untick &ldquo;Validate only&rdquo; and run again to import.</div>
        <?php else: ?>
            <div class="msg-ok"><strong><i class="fas fa-circle-check"></i> Success:</strong>
                imported <?= (int)$result['inserted'] ?> record(s).
                <?php if ($mode === 'hours'): ?>Review them on <a href="/staff_audit.php">Staff Audit</a>.
                <?php elseif ($mode === 'sites'): ?>Review them under <a href="/manage_sites.php">Manage Sites</a>.
                <?php else: ?>Review them in the <a href="/employee_hub.php">Employee Hub</a> and confirm StartDates in <a href="/vacation_service_admin.php">Vacation Service</a>.
                <?php endif; ?></div>
        <?php endif; ?>

        <?php if (!empty($result['preview'])): ?>
            <table class="preview">
                <thead><tr><th>CSV Line</th><th>Employee</th><th>Details</th></tr></thead>
                <tbody>
                <?php foreach ($result['preview'] as $p): ?>
                    <tr><td><?= (int)$p['line'] ?></td><td><?= htmlspecialchars($p['label']) ?></td><td><?= htmlspecialchars($p['detail']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>

    <?php endif; /* step form */ ?>
</div>
<script>
(function () {
    var HDR = {
        employees: <?= json_encode($hdr_employees, JSON_UNESCAPED_SLASHES) ?>,
        hours: <?= json_encode($hdr_hours, JSON_UNESCAPED_SLASHES) ?>,
        sites: <?= json_encode($hdr_sites, JSON_UNESCAPED_SLASHES) ?>
    };
    var form = document.getElementById('importForm');
    var inputSource = document.getElementById('input_source');
    var panelFile = document.getElementById('panelFile');
    var panelPaste = document.getElementById('panelPaste');
    var csvFile = document.getElementById('csv_file');
    var pasteData = document.getElementById('paste_data');
    var tabs = document.querySelectorAll('.source-tabs button');
    var btnCopy = document.getElementById('btnCopyHeaders');
    var clientErr = document.getElementById('importClientErr');

    function showClientErr(msg) {
        if (!clientErr) { window.alert(msg); return; }
        clientErr.textContent = msg;
        clientErr.style.display = 'block';
        try { clientErr.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {}
    }
    function clearClientErr() {
        if (!clientErr) return;
        clientErr.textContent = '';
        clientErr.style.display = 'none';
    }

    function setSource(src) {
        if (!inputSource) return;
        inputSource.value = src;
        tabs.forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-source') === src);
        });
        var isPaste = src === 'paste';
        if (panelFile) panelFile.style.display = isPaste ? 'none' : '';
        if (panelPaste) panelPaste.style.display = isPaste ? '' : 'none';
        /* Disable inactive controls so hidden required fields cannot block submit silently */
        if (csvFile) {
            csvFile.disabled = isPaste;
            csvFile.required = !isPaste;
        }
        if (pasteData) {
            pasteData.disabled = !isPaste;
            pasteData.required = isPaste;
        }
        clearClientErr();
        if (!isPaste && csvFile) csvFile.focus();
        else if (pasteData) pasteData.focus();
    }
    tabs.forEach(function (b) {
        b.addEventListener('click', function () { setSource(b.getAttribute('data-source')); });
    });

    if (form) {
        form.addEventListener('submit', function (e) {
            clearClientErr();
            var src = inputSource ? inputSource.value : 'file';
            if (src === 'paste') {
                if (!pasteData || !String(pasteData.value || '').trim()) {
                    e.preventDefault();
                    showClientErr('Paste area is empty. Paste header + data rows, or switch to Upload file.');
                    if (pasteData) pasteData.focus();
                    return;
                }
            } else {
                if (!csvFile || !csvFile.files || !csvFile.files.length) {
                    e.preventDefault();
                    showClientErr('Choose a CSV file first (or switch to Paste from spreadsheet).');
                    if (csvFile) csvFile.focus();
                    return;
                }
            }
            /* Dismiss tour tip if still open so it cannot cover the post-submit UI */
            try {
                var tip = document.querySelector('.pg-tour-tip');
                if (tip && tip.style.display !== 'none') {
                    var skip = tip.querySelector('[data-act="skip"]');
                    if (skip) skip.click();
                }
            } catch (err) {}
        });
    }

    if (btnCopy) {
        btnCopy.addEventListener('click', function () {
            var modeEl = document.querySelector('input[name="import_mode"]:checked');
            var mode = modeEl ? modeEl.value : 'employees';
            var text = HDR[mode] || HDR.employees;
            var status = document.getElementById('copyHdrStatus');
            function ok() {
                var label = mode === 'hours' ? 'Hours' : (mode === 'sites' ? 'Sites' : 'Users');
                if (status) status.textContent = 'Copied ' + label + ' header.';
            }
            function fail() {
                if (status) status.textContent = '';
                window.prompt('Copy this header row:', text);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(ok).catch(fail);
            } else {
                fail();
            }
        });
    }
})();
</script>
</body>
</html>
