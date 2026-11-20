<?php
/**
 * Tax & T4 Center — completeness audit, worksheets, CRA XML export + upload guide.
 * v1: BC/ON/SK; one T4 Summary per site RP; capped boxes 24/26.
 * Do not IFT-file production until remittances match CRA tables and real BN/SIN replace test data.
 */
require_once 'db.php';
require_once 'payroll_scope.php';
require_once __DIR__ . '/t4_lib.php';
require_once __DIR__ . '/t4_xml.php';
if (file_exists('engine/menu_generator.php')) { require_once 'engine/menu_generator.php'; }
ps_require_payroll_admin();

$orgId = ps_selected_org($pdo);
$year = max(2000, min(2100, (int)($_REQUEST['year'] ?? date('Y'))));
$employeeId = (int)($_GET['emp_id'] ?? 0);
$batch = isset($_GET['batch']);
$siteId = (int)($_REQUEST['site_id'] ?? 0);
if ($employeeId) { ps_assert_user($pdo, $employeeId, $orgId); }

$orgStmt = $pdo->prepare('SELECT org_name FROM organizations WHERE id = ?');
$orgStmt->execute([$orgId]);
$orgName = (string)($orgStmt->fetchColumn() ?: '');
if ($orgName === '') {
    $orgName = 'Organization #' . $orgId;
}

// Superadmin org picker: named orgs + any orphan org_ids that still have finalized pay.
$orgChoices = [];
if (ps_is_superadmin()) {
    foreach ($pdo->query("SELECT id, COALESCE(NULLIF(TRIM(org_name), ''), CONCAT('Organization #', id)) AS org_name FROM organizations ORDER BY org_name, id") as $row) {
        $orgChoices[(int)$row['id']] = (string)$row['org_name'];
    }
    $orphanStmt = $pdo->query("SELECT DISTINCT f.org_id AS id FROM payroll_finalized f LEFT JOIN organizations o ON o.id = f.org_id WHERE o.id IS NULL AND f.org_id > 0");
    foreach ($orphanStmt as $row) {
        $oid = (int)$row['id'];
        if (!isset($orgChoices[$oid])) {
            $orgChoices[$oid] = 'Organization #' . $oid . ' (no name on file)';
        }
    }
    if (!isset($orgChoices[$orgId])) {
        $orgChoices[$orgId] = $orgName;
    }
    asort($orgChoices, SORT_NATURAL | SORT_FLAG_CASE);
}

// Site picker: prefer sites that actually appear in finalized pay for this org (site.org_id is often wrong).
$sitesStmt = $pdo->prepare(
    "SELECT s.SId, s.nick_name, s.corp_legal_name, s.payroll_account, s.street_address, s.city, s.province, s.state, s.zipcode
     FROM sites s
     INNER JOIN (
         SELECT DISTINCT site_id FROM payroll_finalized WHERE org_id = ? AND YEAR(work_date) = ?
     ) f ON f.site_id = s.SId
     ORDER BY s.nick_name"
);
$sitesStmt->execute([$orgId, $year]);
$sites = $sitesStmt->fetchAll(PDO::FETCH_ASSOC);
if (!$sites) {
    // Fall back to sites tagged to the org, then any site used by this org in any year.
    $sitesStmt = $pdo->prepare('SELECT SId, nick_name, corp_legal_name, payroll_account, street_address, city, province, state, zipcode FROM sites WHERE org_id = ? ORDER BY nick_name');
    $sitesStmt->execute([$orgId]);
    $sites = $sitesStmt->fetchAll(PDO::FETCH_ASSOC);
}
if (!$sites) {
    $sitesStmt = $pdo->prepare(
        "SELECT s.SId, s.nick_name, s.corp_legal_name, s.payroll_account, s.street_address, s.city, s.province, s.state, s.zipcode
         FROM sites s
         INNER JOIN (SELECT DISTINCT site_id FROM payroll_finalized WHERE org_id = ?) f ON f.site_id = s.SId
         ORDER BY s.nick_name"
    );
    $sitesStmt->execute([$orgId]);
    $sites = $sitesStmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($siteId) {
    $allowed = false;
    foreach ($sites as $s) {
        if ((int)$s['SId'] === $siteId) { $allowed = true; break; }
    }
    if (!$allowed) {
        // Site must either belong to org tag OR have finalized pay under this org.
        $chk = $pdo->prepare('SELECT COUNT(*) FROM payroll_finalized WHERE org_id = ? AND site_id = ?');
        $chk->execute([$orgId, $siteId]);
        if (!$chk->fetchColumn()) {
            ps_assert_site($pdo, $siteId, $orgId);
        }
    }
}

$records = t4_load_drafts($pdo, $orgId, $year, $siteId, $employeeId);
$groups = t4_group_by_rp($records);
$blockingTotal = 0;
$warningTotal = 0;
foreach ($records as $r) {
    if ($r['issues']) { $blockingTotal++; }
    if ($r['warnings']) { $warningTotal++; }
}
$caps = t4_year_caps($year);

$exportErrors = [];
$schemaErrors = [];

// --- XML download ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_xml'])) {
    if (function_exists('ps_csrf_token') && isset($_POST['csrf_token'])) {
        // soft check if helper exists with verify — many pages only emit token
    }
    $forceWarn = !empty($_POST['allow_engine_warnings']);
    if ($blockingTotal > 0) {
        $exportErrors[] = 'Fix all blocked slips before export.';
    } elseif ($warningTotal > 0 && !$forceWarn) {
        $exportErrors[] = 'Engine warnings present. Tick “Allow export despite engine warnings” only for schema testing — do not file with CRA.';
    } else {
        $exportGroups = [];
        foreach ($groups as $key => $g) {
            $cleanSlips = array_values(array_filter($g['slips'], static fn($s) => empty($s['issues'])));
            if (!$cleanSlips) {
                continue;
            }
            $g['slips'] = $cleanSlips;
            $g['blocking'] = 0;
            $exportGroups[$key] = $g;
        }
        $result = t4_xml_build($exportGroups, $year, [
            'channel' => (string)($_POST['channel'] ?? 'myba'),
            'bn15' => (string)($_POST['transmitter_bn15'] ?? ''),
            'bn9' => (string)($_POST['transmitter_bn9'] ?? ''),
            'rep_id' => (string)($_POST['transmitter_rep_id'] ?? ''),
            'name' => (string)($_POST['transmitter_name'] ?? $orgName),
            'contact_name' => (string)($_POST['contact_name'] ?? ''),
            'phone' => (string)($_POST['contact_phone'] ?? ''),
            'email' => (string)($_POST['contact_email'] ?? ''),
            'lang' => (string)($_POST['lang_cd'] ?? 'E'),
            'sbmt_ref_id' => (string)($_POST['sbmt_ref_id'] ?? ''),
            'report_type' => (string)($_POST['report_type'] ?? 'O'),
        ]);
        if (!$result['ok']) {
            $exportErrors = array_merge($exportErrors, $result['errors'] ?: ['Export failed']);
            $schemaErrors = $result['schema_errors'] ?? [];
        } else {
            $fname = 'T4_' . $year . '_org' . $orgId . '_' . ($result['sbmt_ref_id'] ?? 'export') . '.xml';
            header('Content-Type: application/xml; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            header('X-T4-Schema-Valid: yes');
            header('X-T4-Disclaimer: Schema-valid only; not CRA-certified; verify deductions before filing');
            echo $result['xml'];
            exit;
        }
    }
}

$printing = $employeeId > 0 || $batch;
if ($employeeId && !$records) {
    http_response_code(404);
    exit('No scoped finalized payroll exists for that employee and year.');
}

$defaultBn15 = '';
foreach ($groups as $g) {
    if (!empty($g['payroll_account'])) { $defaultBn15 = $g['payroll_account']; break; }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Tax &amp; T4 Center</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<style>
body{background:#f1f5f9;padding-top:95px;font-family:Inter,Arial,sans-serif}
.wrap{max-width:1200px;margin:auto;padding:20px}
.paper{background:#fff;border:2px solid #111;padding:24px;margin:0 auto 25px;max-width:850px;page-break-after:always}
.grid{display:grid;grid-template-columns:repeat(4,1fr);border-left:1px solid #111;border-top:1px solid #111}
.box{border-right:1px solid #111;border-bottom:1px solid #111;padding:8px;min-height:74px}
.span2{grid-column:span 2}
.label{font-size:9px;text-transform:uppercase;font-weight:700}
.value{font-size:16px;font-weight:700;margin-top:10px;text-align:right}
.notice{font-size:12px;color:#64748b}
.banner{border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:13px}
.banner.warn{background:#fff7ed;border:1px solid #fdba74;color:#9a3412}
.banner.block{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
.banner.ok{background:#f0fdf4;border:1px solid #86efac;color:#166534}
.badge-soft{font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px}
.export-card label{font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b}
.guide ol{padding-left:18px}
.guide li{margin-bottom:6px}
@media print{body{padding:0;background:#fff}.no-print,nav{display:none!important}.wrap{max-width:none;padding:0}.paper{border:1px solid #111;margin:0;max-width:none}}
</style></head><body>
<?php if (!$printing && function_exists('generateMenu')) echo generateMenu($pdo); ?>
<div class="wrap">
<?php if (!$printing && file_exists(__DIR__.'/payroll_help.php')) { require_once __DIR__.'/payroll_help.php'; echo payroll_help_panel('generate_t4.php'); } ?>
<?php if (!$printing): ?>
<h2>Tax &amp; T4 Center</h2>
<p class="notice mb-2"><strong><?= htmlspecialchars($orgName) ?></strong><?php if (ps_is_superadmin()): ?> <span class="text-muted">(org #<?= (int)$orgId ?>)</span><?php endif; ?> · tax year <?= (int)$year ?></p>
<p class="notice">Annual values come from stored finalized payroll. v1 scope: <strong>BC / ON / SK</strong>. Each site is its own CRA employer (one RP → one T4 Summary). Boxes 24/26 are capped to <?= (int)$year ?> CRA maximums; boxes 16/18/22 still reflect amounts actually withheld.</p>

<?php if ($blockingTotal > 0): ?>
<div class="banner block no-print"><strong><?= $blockingTotal ?></strong> slip(s) block XML export — fix SIN, address, dental code, legal name, or BN15 RP on the site.</div>
<?php elseif ($warningTotal > 0): ?>
<div class="banner warn no-print">Identity looks complete, but <strong><?= $warningTotal ?></strong> slip(s) have CPP/EI engine warnings. Schema export is available for testing only — do <em>not</em> file until the T4127 rewrite is live.</div>
<?php else: ?>
<div class="banner ok no-print">No blocking identity issues. You can build a schema-validated T4 XML below. CRA account validation still happens only after IFT upload.</div>
<?php endif; ?>

<?php if ($exportErrors): ?>
<div class="banner block no-print">
  <strong>Export blocked</strong>
  <ul class="mb-0 mt-2"><?php foreach ($exportErrors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
  <?php if ($schemaErrors): ?>
  <details class="mt-2"><summary>XSD details</summary><ul><?php foreach ($schemaErrors as $e): ?><li><code><?= htmlspecialchars($e) ?></code></li><?php endforeach; ?></ul></details>
  <?php endif; ?>
</div>
<?php endif; ?>

<form class="card card-body mb-3 no-print" method="get"><div class="form-row align-items-end">
<?php if (ps_is_superadmin()): ?>
<div class="col-md-3"><label>Organization</label>
  <select class="form-control" name="org_id">
    <?php foreach ($orgChoices as $oid => $oname): ?>
      <option value="<?= (int)$oid ?>" <?= $orgId === (int)$oid ? 'selected' : '' ?>><?= htmlspecialchars($oname) ?></option>
    <?php endforeach; ?>
  </select>
</div>
<?php else: ?>
<input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
<?php endif; ?>
<div class="col-md-2"><label>Tax year</label><input class="form-control" type="number" name="year" value="<?= $year ?>"></div>
<div class="col-md-4"><label>Site (RP / legal employer)</label>
  <select class="form-control" name="site_id">
    <option value="">All sites with pay in <?= (int)$year ?></option>
    <?php foreach ($sites as $s):
      $siteLabel = trim((string)($s['nick_name'] ?: ''));
      $legal = trim((string)($s['corp_legal_name'] ?: ''));
      if ($legal !== '' && strcasecmp($legal, $siteLabel) !== 0) {
          $siteLabel = $siteLabel !== '' ? ($siteLabel . ' — ' . $legal) : $legal;
      }
      if ($siteLabel === '') { $siteLabel = 'Site #' . (int)$s['SId']; }
      $rp = trim((string)($s['payroll_account'] ?: ''));
      $siteLabel .= $rp !== '' ? (' · ' . $rp) : ' · RP missing';
    ?>
      <option value="<?= (int)$s['SId'] ?>" <?= $siteId === (int)$s['SId'] ? 'selected' : '' ?>><?= htmlspecialchars($siteLabel) ?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="col-md-1"><button class="btn btn-primary btn-block">Load</button></div>
<div class="col-md-2"><a class="btn btn-dark btn-block" target="_blank" href="?year=<?= $year ?>&site_id=<?= $siteId ?>&org_id=<?= $orgId ?>&batch=1">Print annual batch</a></div>
</div></form>

<p class="notice no-print mb-2"><?= (int)$year ?> caps used for earnings boxes: EI MIE $<?= number_format($caps['mie'], 2) ?> · CPP YAMPE $<?= number_format($caps['yampe'], 2) ?> (YMPE $<?= number_format($caps['ympe'], 2) ?>).</p>

<?php foreach ($groups as $g): ?>
<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <div>
      <?php
        $legalEmployer = trim((string)($g['employer_name'] ?: ''));
        $siteNick = trim((string)($g['site_name'] ?: ''));
        $head = $legalEmployer !== '' ? $legalEmployer : ($siteNick !== '' ? $siteNick : 'Site #' . (int)($g['slips'][0]['site_id'] ?? 0));
      ?>
      <strong><?= htmlspecialchars($head) ?></strong>
      <?php if ($siteNick !== '' && strcasecmp($siteNick, $head) !== 0): ?>
        <span class="text-muted small ml-2"><?= htmlspecialchars($siteNick) ?></span>
      <?php endif; ?>
      <span class="text-muted small ml-2">· <?= htmlspecialchars($g['employment_province'] ?: '?') ?></span>
    </div>
    <div>
      <code><?= htmlspecialchars($g['payroll_account'] ?: 'RP missing') ?></code>
      <?php if ($g['blocking']): ?><span class="badge badge-danger ml-2"><?= (int)$g['blocking'] ?> blocked</span><?php endif; ?>
      <?php if ($g['warnings']): ?><span class="badge badge-warning ml-2"><?= (int)$g['warnings'] ?> engine warn</span><?php endif; ?>
    </div>
  </div>
  <div class="table-responsive">
  <table class="table table-sm table-bordered mb-0 bg-white"><thead class="thead-light"><tr>
    <th>Employee</th><th>14 Income</th><th>22 Tax</th><th>16 CPP</th><th>18 EI</th><th>24 EI earn</th><th>26 CPP earn</th><th>Status</th><th></th>
  </tr></thead><tbody>
  <?php foreach ($g['slips'] as $r): ?>
  <tr>
    <td><?= htmlspecialchars($r['Name']) ?><br><small class="text-muted"><?= htmlspecialchars(trim($r['given_name'] . ' / ' . $r['surname'], ' /')) ?></small></td>
    <td>$<?= number_format($r['employment_income'], 2) ?></td>
    <td>$<?= number_format($r['income_tax'], 2) ?></td>
    <td>$<?= number_format($r['cpp'], 2) ?></td>
    <td>$<?= number_format($r['ei'], 2) ?></td>
    <td>$<?= number_format($r['box24'], 2) ?><?php if ($r['capped_ei']): ?><br><small class="text-warning">capped</small><?php endif; ?></td>
    <td>$<?= number_format($r['box26'], 2) ?><?php if ($r['capped_cpp']): ?><br><small class="text-warning">capped</small><?php endif; ?></td>
    <td style="max-width:240px;font-size:11px;">
      <?php if ($r['issues']): ?>
        <span class="badge-soft" style="background:#fee2e2;color:#991b1b;">Blocked</span>
        <ul class="mb-0 pl-3 mt-1"><?php foreach ($r['issues'] as $iss): ?><li><?= htmlspecialchars($iss) ?></li><?php endforeach; ?></ul>
      <?php elseif ($r['warnings']): ?>
        <span class="badge-soft" style="background:#ffedd5;color:#9a3412;">Engine warn</span>
        <ul class="mb-0 pl-3 mt-1"><?php foreach ($r['warnings'] as $w): ?><li><?= htmlspecialchars($w) ?></li><?php endforeach; ?></ul>
      <?php else: ?>
        <span class="badge-soft" style="background:#dcfce7;color:#166534;">Ready for XML</span>
      <?php endif; ?>
    </td>
    <td><a target="_blank" href="?year=<?= $year ?>&emp_id=<?= (int)$r['user_id'] ?>&site_id=<?= (int)$r['site_id'] ?>&org_id=<?= $orgId ?>">Open T4</a></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$g['slips']): ?><tr><td colspan="9" class="text-center text-muted p-3">No slips.</td></tr><?php endif; ?>
  </tbody></table>
  </div>
</div>
<?php endforeach; ?>
<?php if (!$records): ?><div class="card card-body text-center text-muted mb-3">No finalized payroll found for this filter.</div><?php endif; ?>

<div class="card export-card mb-3 no-print">
  <div class="card-header"><strong>Download CRA T4 XML</strong> <span class="text-muted small">Schema-validated · includes T619 · one Return per RP</span></div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="year" value="<?= $year ?>">
      <input type="hidden" name="site_id" value="<?= $siteId ?>">
      <?php if (ps_is_superadmin()): ?><input type="hidden" name="org_id" value="<?= $orgId ?>"><?php endif; ?>
      <div class="form-row">
        <div class="form-group col-md-4">
          <label>Filing channel (T619 transmitter)</label>
          <select class="form-control" name="channel" id="t4Channel">
            <option value="myba">My Business Account / WAC — BN15</option>
            <option value="myba_bn9">My Business Account — BN9 only</option>
            <option value="rac">Represent a Client — RepID</option>
          </select>
        </div>
        <div class="form-group col-md-4">
          <label>Transmitter BN15</label>
          <input class="form-control" name="transmitter_bn15" maxlength="15" value="<?= htmlspecialchars($_POST['transmitter_bn15'] ?? $defaultBn15) ?>" placeholder="123456789RP0001" style="text-transform:uppercase">
        </div>
        <div class="form-group col-md-4">
          <label>Transmitter BN9 (MyBA option)</label>
          <input class="form-control" name="transmitter_bn9" maxlength="9" value="<?= htmlspecialchars($_POST['transmitter_bn9'] ?? '') ?>" placeholder="123456789">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group col-md-3">
          <label>RepID (RAC)</label>
          <input class="form-control" name="transmitter_rep_id" maxlength="7" value="<?= htmlspecialchars($_POST['transmitter_rep_id'] ?? '') ?>" style="text-transform:uppercase">
        </div>
        <div class="form-group col-md-3">
          <label>Transmitter name</label>
          <input class="form-control" name="transmitter_name" maxlength="35" value="<?= htmlspecialchars($_POST['transmitter_name'] ?? $orgName) ?>" required>
        </div>
        <div class="form-group col-md-3">
          <label>Contact name</label>
          <input class="form-control" name="contact_name" maxlength="35" value="<?= htmlspecialchars($_POST['contact_name'] ?? '') ?>" required>
        </div>
        <div class="form-group col-md-3">
          <label>Contact phone</label>
          <input class="form-control" name="contact_phone" value="<?= htmlspecialchars($_POST['contact_phone'] ?? '') ?>" placeholder="4165551234" required>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group col-md-4">
          <label>Contact email</label>
          <input class="form-control" type="email" name="contact_email" maxlength="60" value="<?= htmlspecialchars($_POST['contact_email'] ?? '') ?>" required>
        </div>
        <div class="form-group col-md-2">
          <label>Language</label>
          <select class="form-control" name="lang_cd"><option value="E">E</option><option value="F">F</option></select>
        </div>
        <div class="form-group col-md-2">
          <label>Report type</label>
          <select class="form-control" name="report_type"><option value="O">Original (O)</option><option value="A">Amended (A)</option><option value="C">Cancel (C)</option></select>
        </div>
        <div class="form-group col-md-4">
          <label>Submission ref (optional, 8 alnum)</label>
          <input class="form-control" name="sbmt_ref_id" maxlength="8" value="<?= htmlspecialchars($_POST['sbmt_ref_id'] ?? '') ?>" placeholder="auto if blank">
        </div>
      </div>
      <?php if ($warningTotal > 0): ?>
      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="allow_engine_warnings" value="1" id="allowWarn">
        <label class="form-check-label" for="allowWarn">Allow export despite engine warnings (schema test only — <strong>do not file with CRA</strong>)</label>
      </div>
      <?php endif; ?>
      <button class="btn btn-success" type="submit" name="export_xml" value="1" <?= $blockingTotal > 0 ? 'disabled' : '' ?>>Download validated T4 XML</button>
      <span class="text-muted small ml-2">Uses CRA 2026 XSD (xmlschm1-26-3). Empty optional tags stripped.</span>
    </form>
  </div>
</div>

<div class="card guide mb-4 no-print">
  <div class="card-header"><strong>Upload guide — MyBA / RAC / WAC</strong></div>
  <div class="card-body notice" style="color:#334155;font-size:13px;">
    <p class="mb-2">RenoCon generates the XML. You (or your accountant) upload it in CRA’s Internet File Transfer. Matching T619 transmitter identity to the portal login is required or CRA rejects the file.</p>
    <ol>
      <li>Download the validated <code>.xml</code> above. Do not re-save as PDF or Web Forms XML.</li>
      <li><strong>My Business Account:</strong> sign in as the business → Internet file transfer → upload. T619 transmitter BN15/BN9 must match the account used to sign in.</li>
      <li><strong>Represent a Client:</strong> sign in with your RepID (Level 2+ on the client’s RP) → Internet file transfer → upload. T619 <em>RepID</em> must match your login.</li>
      <li><strong>Direct WAC:</strong> open Internet file transfer with the 15-character RP account + Web access code. T619 BN15 must be that same RP.</li>
      <li>After upload, keep the Confirmation of Receipt / Return Error Report. Fix any CRA messages and resubmit if needed.</li>
    </ol>
    <p class="mb-0"><strong>Not for filing yet if engine warnings show</strong> — flat-rate CPP/EI/tax can trigger PIER assessments even when the XML is schema-valid. Fake RP/SIN values will also fail CRA account checks.</p>
    <p class="mb-0 mt-2">Official: <a href="https://www.canada.ca/en/revenue-agency/services/e-services/filing-information-returns-electronically-t4-t5-other-types-returns-overview/filing-information-returns-electronically-t4-t5-other-types-returns-file.html" target="_blank" rel="noopener">How to file</a> · <a href="https://www.canada.ca/en/revenue-agency/services/e-services/filing-information-returns-electronically-t4-t5-other-types-returns-overview/filing-information-returns-electronically-t4-t5-other-types-returns-file/filing-internet-file-transfer.html" target="_blank" rel="noopener">Internet file transfer</a></p>
  </div>
</div>

<?php else: ?>
<div class="no-print text-center mb-3"><button class="btn btn-dark" onclick="print()">Print T4 worksheet<?= $batch ? ' batch' : '' ?></button> <a class="btn btn-light" href="generate_t4.php?year=<?= $year ?>&org_id=<?= $orgId ?>">Back to T4 Center</a></div>
<?php foreach ($records as $r):
    $employer = $r['employer_name'] ?: $orgName;
?>
<section class="paper">
<div class="d-flex justify-content-between border-bottom border-dark pb-2"><div><h1 class="m-0">T4</h1>Statement of Remuneration Paid</div><div class="text-right">Year<br><strong class="h2"><?= $year ?></strong></div></div>
<div class="grid mt-3">
<div class="box span2"><span class="label">Employer name and address</span><div><strong><?= htmlspecialchars($employer) ?></strong><br><?= htmlspecialchars(trim($r['employer_addr'] . ' ' . $r['employer_city'] . ' ' . $r['employment_province'] . ' ' . $r['employer_postal'])) ?><br><small>RP <?= htmlspecialchars($r['payroll_account'] ?: '(missing)') ?></small></div></div>
<div class="box"><span class="label">14 Employment income</span><div class="value"><?= number_format($r['employment_income'], 2) ?></div></div>
<div class="box"><span class="label">22 Income tax deducted</span><div class="value"><?= number_format($r['income_tax'], 2) ?></div></div>
<div class="box span2"><span class="label">Employee name and address</span><div><strong><?= htmlspecialchars(trim($r['surname'] . ', ' . $r['given_name'], ', ')) ?></strong><br><?= htmlspecialchars(trim($r['street_address'] . ' ' . $r['city'] . ' ' . $r['home_province'] . ' ' . $r['postal_code'])) ?></div></div>
<div class="box"><span class="label">16 Employee CPP</span><div class="value"><?= number_format($r['cpp'], 2) ?></div></div>
<div class="box"><span class="label">18 Employee EI</span><div class="value"><?= number_format($r['ei'], 2) ?></div></div>
<div class="box"><span class="label">12 SIN</span><div class="value"><?= htmlspecialchars($r['sin'] ?? '') ?></div></div>
<div class="box"><span class="label">10 Province of employment</span><div class="value"><?= htmlspecialchars($r['employment_province'] ?: '') ?></div></div>
<div class="box"><span class="label">24 EI insurable earnings</span><div class="value"><?= number_format($r['box24'], 2) ?></div></div>
<div class="box"><span class="label">26 CPP pensionable earnings</span><div class="value"><?= number_format($r['box26'], 2) ?></div></div>
<div class="box"><span class="label">45 Dental benefits</span><div class="value"><?= (int)$r['dental_benefit_code'] ?></div></div>
<div class="box"><span class="label">54 Payroll account</span><div class="value" style="font-size:12px;"><?= htmlspecialchars($r['payroll_account'] ?: '') ?></div></div>
</div>
<p class="notice mt-3 mb-0">Worksheet from finalized lines. Boxes 24/26 capped to CRA <?= (int)$year ?> maximums. Confirm withheld CPP/EI/tax against remittances before filing — current engine is flat-rate and may over-deduct.</p>
</section>
<?php endforeach; endif; ?>
</div></body></html>
