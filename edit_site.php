<?php
/** Path: /edit_site.php */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'engine/menu_generator.php';
require_once __DIR__ . '/engine/site_status_lib.php';
require_once __DIR__ . '/engine/direct_deposit_lib.php';
try { dd_ensure_site_columns($pdo); } catch (Throwable $e) {}

if (!isset($_SESSION['user_id'])) { die("Session Expired."); }

if (!function_exists('sip_site_has_col')) {
    function sip_site_has_col(PDO $pdo, string $col): bool {
        static $cols = null;
        if ($cols === null) {
            $cols = [];
            foreach ($pdo->query('SHOW COLUMNS FROM sites') as $c) {
                $cols[$c['Field']] = true;
            }
        }
        return isset($cols[$col]);
    }
}

$user_level = (int)($_SESSION['user_level'] ?? $_SESSION['user_role'] ?? 99);
$user_org = (int)($_SESSION['org_id'] ?? 0);
$is_super = ($user_level <= 10);
$sid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($sid <= 0) { die("Invalid Site ID."); }

// Load site first so org fence applies to both GET and POST.
$stmt = $pdo->prepare("SELECT * FROM sites WHERE SId = ?");
$stmt->execute([$sid]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$site) { die("Site not found."); }

// Org fence: superadmin (admin) may edit any site; org admins (aaadmin) only their org.
if (!$is_super) {
    if ($user_org <= 0 || (int)($site['org_id'] ?? 0) !== $user_org) {
        http_response_code(403);
        die("Access denied.");
    }
}

// 0. DELETE (hard delete only if no hours / no assigned users)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_site'])) {
    $usage = sip_site_usage($pdo, $sid);
    if ($usage['block'] === 'hours') {
        header('Location: manage_sites.php?err=has_hours');
        exit;
    }
    if ($usage['block'] === 'users') {
        header('Location: manage_sites.php?err=has_users');
        exit;
    }
    sip_site_hard_delete($pdo, $sid, $is_super, $user_org);
    header('Location: manage_sites.php?msg=deleted');
    exit;
}

// 1. HANDLE UPDATE (Operational fields + CRA payroll identity)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_site'])) {
    // Overtime policy overrides: '' = inherit province default (NULL), '0' = off, number = hours
    $ot_weekly = ($_POST['ot_weekly_hours'] ?? '') === '' ? null : (float)$_POST['ot_weekly_hours'];
    $ot_daily  = ($_POST['ot_daily_hours']  ?? '') === '' ? null : (float)$_POST['ot_daily_hours'];

    $state = strtoupper(trim((string)($_POST['state'] ?? '')));
    $country = strtoupper(trim((string)($_POST['country'] ?? 'CA')));
    // Keep province in sync for Canadian sites (payroll/T4 reads province; form writes state)
    $province = in_array($country, ['CA', 'CAN'], true) ? $state : strtoupper(trim((string)($_POST['province'] ?? $state)));
    $payroll_account = strtoupper(preg_replace('/[\s\-]/', '', (string)($_POST['payroll_account'] ?? '')));
    if ($payroll_account !== '' && !preg_match('/^\d{9}RP\d{4}$/', $payroll_account)) {
        die('Invalid CRA payroll account. Use 15 characters: #########RP#### (example 123456789RP0001).');
    }

    $timezone_id = trim((string)($_POST['timezone_id'] ?? 'America/Toronto'));
    if ($timezone_id === '') $timezone_id = 'America/Toronto';

    $site_status = (isset($_POST['site_status']) && $_POST['site_status'] === 'Inactive') ? 'Inactive' : 'Active';

    $unit_suite = trim((string)($_POST['unit_suite'] ?? ''));
    $logo_url = trim((string)($_POST['logo_url'] ?? ''));
    $paystub_layout = (($_POST['paystub_layout'] ?? '') === 'bramalea') ? 'bramalea' : 'legacy';
    $paystub_pay_method = (($_POST['paystub_pay_method'] ?? '') === 'debit') ? 'debit' : 'cheque';
    $paystub_ytd_calendar = isset($_POST['paystub_ytd_calendar']) ? 1 : 0;
    $paystub_show_zero_stat = isset($_POST['paystub_show_zero_stat']) ? 1 : 0;

    $params = [
        $_POST['nick_name'], $_POST['corp_legal_name'], $payroll_account !== '' ? $payroll_account : null,
        trim((string)($_POST['wsib_number'] ?? '')) ?: null,
        ($_POST['wsib_percent'] ?? '') !== '' ? (float)$_POST['wsib_percent'] : null,
        $_POST['street_address'], trim((string)($_POST['city'] ?? '')), trim((string)($_POST['zipcode'] ?? '')),
        $province, $timezone_id, $timezone_id, $state, $country, $site_status,
        (float)$_POST['target_labor_percent'], (float)$_POST['forecast_buffer'],
        (float)$_POST['average_base_rate'], (float)$_POST['site_growth_factor'],
        $_POST['Group'], (float)$_POST['target_lat'], (float)$_POST['target_lng'],
        (int)$_POST['geo_radius_meters'],
        $ot_weekly, $ot_daily
    ];

    $sql = "UPDATE sites SET 
            nick_name = ?, corp_legal_name = ?, payroll_account = ?,
            wsib_number = ?, wsib_percent = ?,
            street_address = ?, city = ?, zipcode = ?, province = ?,
            timezone_id = ?, Timezone = ?, state = ?, country = ?, status = ?,
            target_labor_percent = ?, forecast_buffer = ?, average_base_rate = ?, 
            site_growth_factor = ?, `Group` = ?, target_lat = ?, target_lng = ?, 
            geo_radius_meters = ?, ot_weekly_hours = ?, ot_daily_hours = ?";

    if (sip_site_has_col($pdo, 'unit_suite')) {
        $sql .= ", unit_suite = ?";
        $params[] = $unit_suite !== '' ? $unit_suite : null;
    }
    if (sip_site_has_col($pdo, 'logo_url')) {
        $sql .= ", logo_url = ?";
        $params[] = $logo_url !== '' ? $logo_url : null;
    }
    if (sip_site_has_col($pdo, 'paystub_layout')) {
        $sql .= ", paystub_layout = ?";
        $params[] = $paystub_layout;
    }
    if (sip_site_has_col($pdo, 'paystub_pay_method')) {
        $sql .= ", paystub_pay_method = ?";
        $params[] = $paystub_pay_method;
    }
    if (sip_site_has_col($pdo, 'paystub_ytd_calendar')) {
        $sql .= ", paystub_ytd_calendar = ?";
        $params[] = $paystub_ytd_calendar;
    }
    if (sip_site_has_col($pdo, 'paystub_show_zero_stat')) {
        $sql .= ", paystub_show_zero_stat = ?";
        $params[] = $paystub_show_zero_stat;
    }
    if (sip_site_has_col($pdo, 'dd_originator_id') && array_key_exists('dd_bank', $_POST)) {
        $bankClean = dd_clean_site_bank($_POST);
        if ($bankClean['error'] !== '') {
            die($bankClean['error']);
        }
        foreach ($bankClean['values'] as $bankCol => $bankVal) {
            if (!sip_site_has_col($pdo, $bankCol)) {
                continue;
            }
            $sql .= ", `$bankCol` = ?";
            $params[] = $bankVal;
        }
    }

    // Rule 1: Only Superadmins can change Organization ID
    if ($is_super && isset($_POST['org_id'])) {
        $sql .= ", org_id = ?";
        $params[] = (int)$_POST['org_id'];
    }

    $sql .= " WHERE SId = ?";
    $params[] = $sid;
    if (!$is_super) {
        $sql .= " AND org_id = ?";
        $params[] = $user_org;
    }

    $update = $pdo->prepare($sql);
    $update->execute($params);
    header("Location: manage_sites.php?success=updated");
    exit;
}

// 2. ORG DROPDOWN (superadmin sees all; org admin only their org)
if ($is_super) {
    $orgs = $pdo->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $orgStmt = $pdo->prepare("SELECT id, org_name FROM organizations WHERE id = ?");
    $orgStmt->execute([$user_org]);
    $orgs = $orgStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Effective overtime policy (site override else province default) for the info line.
// Live June engine may lack pe_site_ot_policy after clone restore — load shim if needed.
$peFile = __DIR__ . '/payroll_engine/payroll_engine.php';
if (is_file($peFile)) {
    require_once $peFile;
}
$peShim = __DIR__ . '/payroll_engine/pe_site_ot_policy.inc.php';
if (is_file($peShim)) {
    require_once $peShim;
}
if (function_exists('pe_site_ot_policy')) {
    $effective_ot = pe_site_ot_policy($pdo, $sid);
} else {
    $effective_ot = ['label' => 'Province default'];
}
$ot_weekly_raw = $site['ot_weekly_hours'] ?? null;
$ot_daily_raw  = $site['ot_daily_hours'] ?? null;
$has_paystub_fields = sip_site_has_col($pdo, 'paystub_layout');
$unit_suite_val = (string)($site['unit_suite'] ?? '');
$logo_url_val = (string)($site['logo_url'] ?? '');
$paystub_layout_val = strtolower(trim((string)($site['paystub_layout'] ?? 'legacy')));
if ($paystub_layout_val !== 'bramalea') $paystub_layout_val = 'legacy';
$paystub_pay_method_val = strtolower(trim((string)($site['paystub_pay_method'] ?? 'cheque')));
if ($paystub_pay_method_val !== 'debit') $paystub_pay_method_val = 'cheque';
$paystub_ytd_val = (int)($site['paystub_ytd_calendar'] ?? 1) === 1;
$paystub_zero_stat_val = (int)($site['paystub_show_zero_stat'] ?? 0) === 1;
$has_dd_fields = sip_site_has_col($pdo, 'dd_originator_id');
$dd_banks = function_exists('dd_bank_catalog') ? dd_bank_catalog() : [];
$dd_bank_val = strtolower(trim((string)($site['dd_bank'] ?? '')));
if (!isset($dd_banks[$dd_bank_val])) $dd_bank_val = '';
$dd_seq_val = max(1, (int)($site['dd_file_seq'] ?? 1));
$ot_weekly_val = $ot_weekly_raw !== null && $ot_weekly_raw !== '' ? rtrim(rtrim(number_format((float)$ot_weekly_raw, 2), '0'), '.') : '';
$ot_daily_val  = $ot_daily_raw !== null && $ot_daily_raw !== '' ? rtrim(rtrim(number_format((float)$ot_daily_raw, 2), '0'), '.')  : '';
$site_is_active = sip_site_status_is_active($site['status'] ?? 'Active');
$site_usage = sip_site_usage($pdo, $sid);

// 3. JURISDICTION DATA
$jurisdictions = [
    "CA" => ["AB"=>"Alberta","BC"=>"British Columbia","MB"=>"Manitoba","NB"=>"New Brunswick","NL"=>"Newfoundland","NS"=>"Nova Scotia","ON"=>"Ontario","PE"=>"PEI","QC"=>"Quebec","SK"=>"Saskatchewan","NT"=>"NW Territories","NU"=>"Nunavut","YT"=>"Yukon"],
    "US" => ["AL"=>"Alabama","AK"=>"Alaska","AZ"=>"Arizona","AR"=>"Arkansas","CA"=>"California","CO"=>"Colorado","CT"=>"Connecticut","DE"=>"Delaware","FL"=>"Florida","GA"=>"Georgia","HI"=>"Hawaii","ID"=>"Idaho","IL"=>"Illinois","IN"=>"Indiana","IA"=>"Iowa","KS"=>"Kansas","KY"=>"Kentucky","LA"=>"Louisiana","ME"=>"Maine","MD"=>"Maryland","MA"=>"Massachusetts","MI"=>"Michigan","MN"=>"Minnesota","MS"=>"Mississippi","MO"=>"Missouri","MT"=>"Montana","NE"=>"Nebraska","NV"=>"Nevada","NH"=>"New Hampshire","NJ"=>"New Jersey","NM"=>"New Mexico","NY"=>"New York","NC"=>"North Carolina","ND"=>"North Dakota","OH"=>"Ohio","OK"=>"Oklahoma","OR"=>"Oregon","PA"=>"Pennsylvania","RI"=>"Rhode Island","SC"=>"South Carolina","SD"=>"South Dakota","TN"=>"Tennessee","TX"=>"Texas","UT"=>"Utah","VT"=>"Vermont","VA"=>"Virginia","WA"=>"Washington","WV"=>"West Virginia","WI"=>"Wisconsin","WY"=>"Wyoming"],
    "AU" => ["NSW"=>"New South Wales", "VIC"=>"Victoria", "QLD"=>"Queensland", "WA"=>"Western Australia", "SA"=>"South Australia", "TAS"=>"Tasmania", "ACT"=>"Capital Territory", "NT"=>"Northern Territory"],
    "PK" => ["PB"=>"Punjab", "SD"=>"Sindh", "KP"=>"Khyber Pakhtunkhwa", "BL"=>"Balochistan", "ICT"=>"Islamabad", "AJK"=>"Azad Kashmir", "GB"=>"Gilgit-Baltistan"],
    "IN" => ["MH"=>"Maharashtra", "DL"=>"Delhi", "KA"=>"Karnataka", "TN"=>"Tamil Nadu", "UP"=>"Uttar Pradesh", "GJ"=>"Gujarat", "WB"=>"West Bengal"]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Site | SIP Global</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; padding-top: 100px; padding-bottom: 50px; }
        .edit-card { max-width: 1000px; margin: 0 auto; background: white; padding: 40px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
        label { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 5px; display: block; }
        .form-control { border-radius: 8px; height: 44px; font-size: 14px; }
        .section-header { font-size: 14px; font-weight: 900; color: #2563eb; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; margin-bottom: 20px; margin-top: 30px; text-transform: uppercase; letter-spacing: 1px; }
        .btn-save { background: #0f172a; color: white; border: none; padding: 12px 40px; border-radius: 8px; font-weight: 700; width: 100%; transition: 0.2s; }
        .btn-save:hover { background: #1e293b; transform: translateY(-1px); }
        .legal-hint { font-size: 11px; color: #94a3b8; margin-top: 4px; font-weight: 500; text-transform: none; }
    </style>
</head>
<body>
<?php echo generateMenu($pdo); ?>
<div class="container">
    <div class="edit-card">
        <h4 class="mb-1">🌍 Global Site Management</h4>
        <p class="text-muted small mb-4">SID: #<?= $sid ?> | Organization: <?= $site['org_id'] ?></p>
        
        <form method="POST" action="edit_site.php?id=<?= $sid ?>">
            
            <div class="section-header" style="margin-top: 0;">1. Identity & Location</div>
            <div class="row">
                <div class="col-md-4"><label>Site Nickname</label><input type="text" name="nick_name" class="form-control" value="<?= htmlspecialchars($site['nick_name']) ?>" required></div>
                <div class="col-md-4"><label>Group / Region</label><input type="text" name="Group" class="form-control" value="<?= htmlspecialchars($site['Group'] ?? '') ?>" placeholder="e.g. West Coast"></div>
                <div class="col-md-4">
                    <label>Organization</label>
                    <select name="org_id" class="form-control" <?= !$is_super ? 'disabled' : '' ?>>
                        <?php foreach($orgs as $o): ?>
                            <option value="<?= $o['id'] ?>" <?= ($site['org_id'] == $o['id']) ? 'selected' : '' ?>><?= htmlspecialchars($o['org_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-4">
                    <label>Status</label>
                    <select name="site_status" class="form-control">
                        <option value="Active" <?= $site_is_active ? 'selected' : '' ?>>Active — shown in pickers</option>
                        <option value="Inactive" <?= !$site_is_active ? 'selected' : '' ?>>Inactive — hide from pickers, keep history</option>
                    </select>
                    <div class="legal-hint">Preferred when the site has hours. Inactive sites stay on reports and past timesheets but drop out of Site Picker, timesheet filters, kiosk, and CSV site lists.</div>
                </div>
            </div>

            <div class="row mt-3">
                <div class="col-md-8">
                    <label>Legal Corporation Name</label>
                    <input type="text" name="corp_legal_name" class="form-control" value="<?= htmlspecialchars($site['corp_legal_name'] ?? '') ?>" placeholder="e.g. 12345678 Ontario Inc. o/a Westbank Grill">
                    <div class="legal-hint">Registered legal entity for this site. Appears on cheques, paystubs &amp; T4s. Distinct from the nickname above.</div>
                </div>
                <div class="col-md-4">
                    <label>CRA Payroll Account (BN15)</label>
                    <input type="text" name="payroll_account" class="form-control" maxlength="15"
                           value="<?= htmlspecialchars($site['payroll_account'] ?? '') ?>"
                           placeholder="123456789RP0001" pattern="\d{9}[Rr][Pp]\d{4}" style="text-transform:uppercase; letter-spacing:0.04em;">
                    <div class="legal-hint">Required for T4 XML — 9-digit BN + RP + 4-digit account. Each site files its own T4 Summary.</div>
                </div>
            </div>

            <div class="row mt-3">
                <div class="col-md-4">
                    <label>WSIB / WCB Number</label>
                    <input type="text" name="wsib_number" class="form-control" maxlength="50"
                           value="<?= htmlspecialchars($site['wsib_number'] ?? '') ?>"
                           placeholder="Account number for this location">
                    <div class="legal-hint">Company/location account — not per employee.</div>
                </div>
                <div class="col-md-4">
                    <label>WSIB / WCB %</label>
                    <input type="number" step="0.001" min="0" max="100" name="wsib_percent" class="form-control"
                           value="<?= isset($site['wsib_percent']) && $site['wsib_percent'] !== null && $site['wsib_percent'] !== '' ? htmlspecialchars((string)$site['wsib_percent']) : '' ?>"
                           placeholder="e.g. 2.50">
                    <div class="legal-hint">Premium % for this location. Blank = use global Payroll Settings WCB rate.</div>
                </div>
            </div>

            <div class="row mt-3">
                <div class="col-md-2">
                    <label>Country</label>
                    <select name="country" id="countrySelect" class="form-control" onchange="populateStates()">
                        <option value="CA" <?= ($site['country'] == 'CA') ? 'selected' : '' ?>>Canada</option>
                        <option value="US" <?= ($site['country'] == 'US') ? 'selected' : '' ?>>United States</option>
                        <option value="AU" <?= ($site['country'] == 'AU') ? 'selected' : '' ?>>Australia</option>
                        <option value="PK" <?= ($site['country'] == 'PK') ? 'selected' : '' ?>>Pakistan</option>
                        <option value="IN" <?= ($site['country'] == 'IN') ? 'selected' : '' ?>>India</option>
                    </select>
                </div>
                <div class="col-md-2"><label id="stateLabel">Province</label><div id="stateInputWrapper"></div></div>
                <div class="col-md-4"><label>Street Address</label><input type="text" name="street_address" class="form-control" value="<?= htmlspecialchars($site['street_address'] ?? '') ?>"></div>
                <div class="col-md-2"><label>City</label><input type="text" name="city" class="form-control" value="<?= htmlspecialchars($site['city'] ?? '') ?>"></div>
                <div class="col-md-2"><label>Postal Code</label><input type="text" name="zipcode" class="form-control" value="<?= htmlspecialchars($site['zipcode'] ?? '') ?>" style="text-transform:uppercase;"></div>
            </div>
            <?php if (sip_site_has_col($pdo, 'unit_suite')): ?>
            <div class="row mt-3">
                <div class="col-md-4">
                    <label>Unit / Suite</label>
                    <input type="text" name="unit_suite" class="form-control" value="<?= htmlspecialchars($unit_suite_val) ?>" placeholder="e.g. Unit 1 &amp; 2">
                    <div class="legal-hint">Optional second address line on the paystub (printed under street).</div>
                </div>
            </div>
            <?php endif; ?>

            <div class="section-header">2. Operational & Financial Targets</div>
            <div class="row">
                <div class="col-md-3"><label>Target Labor %</label><input type="number" step="0.01" name="target_labor_percent" class="form-control" value="<?= $site['target_labor_percent'] ?>"></div>
                <div class="col-md-3"><label>Avg Base Rate ($)</label><input type="number" step="0.01" name="average_base_rate" class="form-control" value="<?= $site['average_base_rate'] ?>"></div>
                <div class="col-md-3"><label>Growth Factor</label><input type="number" step="0.01" name="site_growth_factor" class="form-control" value="<?= $site['site_growth_factor'] ?>"></div>
                <div class="col-md-3"><label>Forecast Buffer %</label><input type="number" step="0.01" name="forecast_buffer" class="form-control" value="<?= $site['forecast_buffer'] ?>"></div>
            </div>

            <div class="section-header">3. Overtime Policy (Company Policy — Provincial Defaults)</div>
            <div class="row">
                <div class="col-md-4">
                    <label>Weekly OT Threshold (hours/week)</label>
                    <select name="ot_weekly_hours" class="form-control">
                        <option value="" <?= $ot_weekly_val === '' ? 'selected' : '' ?>>Province default (ON 44 · SK 40)</option>
                        <option value="44" <?= $ot_weekly_val === '44' ? 'selected' : '' ?>>44 hours / week (ON statutory default)</option>
                        <option value="40" <?= $ot_weekly_val === '40' ? 'selected' : '' ?>>40 hours / week (more generous policy)</option>
                        <option value="0" <?= $ot_weekly_val === '0' ? 'selected' : '' ?>>Off — no weekly overtime</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label>Daily OT Threshold (hours/day)</label>
                    <select name="ot_daily_hours" class="form-control">
                        <option value="" <?= $ot_daily_val === '' ? 'selected' : '' ?>>Province default (ON off · SK 8)</option>
                        <option value="8" <?= $ot_daily_val === '8' ? 'selected' : '' ?>>8 hours / day</option>
                        <option value="0" <?= $ot_daily_val === '0' ? 'selected' : '' ?>>Off — no daily overtime</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label>Currently In Effect</label>
                    <div class="form-control" style="background:#f8fafc; font-weight:700; font-size:12px; line-height:30px; color:#1e40af;"><?= htmlspecialchars($effective_ot['label']) ?></div>
                </div>
            </div>
            <div class="legal-hint" style="margin-top:6px;">
                Used by Finalize Selected (weekly modes) and the payroll engine: regular hours up to the weekly threshold
                per Monday&ndash;Sunday week per employee; hours beyond pay 1.5&times;. If a daily threshold is set, daily overtime
                is split first and never double-counted against the weekly limit. These are configurable company policies with
                provincial defaults — not legal advice.
            </div>

            <?php if ($has_paystub_fields): ?>
            <div class="section-header">4. Paystub (printed pay documents)</div>
            <div class="row">
                <div class="col-md-4">
                    <label>Paystub style</label>
                    <select name="paystub_layout" class="form-control">
                        <option value="legacy" <?= $paystub_layout_val === 'legacy' ? 'selected' : '' ?>>Legacy (Renocon earnings statement)</option>
                        <option value="bramalea" <?= $paystub_layout_val === 'bramalea' ? 'selected' : '' ?>>Bramalea (Employee Paystub + YTD)</option>
                    </select>
                    <div class="legal-hint">Legal name + address above print on the stub. Bramalea style matches Qty / Rate / Current / YTD.</div>
                </div>
                <div class="col-md-4">
                    <label>Cheque / Debit label</label>
                    <select name="paystub_pay_method" class="form-control">
                        <option value="cheque" <?= $paystub_pay_method_val === 'cheque' ? 'selected' : '' ?>>Cheque #</option>
                        <option value="debit" <?= $paystub_pay_method_val === 'debit' ? 'selected' : '' ?>>Debit</option>
                    </select>
                    <div class="legal-hint">What prints next to “Cheque number” on the stub. Debit = direct deposit.</div>
                </div>
                <div class="col-md-4">
                    <label>Logo (optional)</label>
                    <input type="text" name="logo_url" class="form-control" value="<?= htmlspecialchars($logo_url_val) ?>" placeholder="uploads/logos/site.png">
                    <div class="legal-hint">Local file path under payroll, or leave blank. Used on Bramalea stubs.</div>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-6">
                    <label style="text-transform:none; font-size:13px; font-weight:700; color:#0f172a;">
                        <input type="checkbox" name="paystub_ytd_calendar" value="1" <?= $paystub_ytd_val ? 'checked' : '' ?>>
                        YTD = calendar year (finalized pay to cheque / period end)
                    </label>
                    <div class="legal-hint">On by default. Uncheck to show current-period amounts in the YTD column.</div>
                </div>
                <div class="col-md-6">
                    <label style="text-transform:none; font-size:13px; font-weight:700; color:#0f172a;">
                        <input type="checkbox" name="paystub_show_zero_stat" value="1" <?= $paystub_zero_stat_val ? 'checked' : '' ?>>
                        Show Public Holiday / Stat Day Worked even if $0
                    </label>
                    <div class="legal-hint">Bramalea PDF always lists these lines. Leave off to hide empty rows.</div>
                </div>
            </div>
            <?php endif; ?>

            <?php
            $gps_section = 4;
            if ($has_paystub_fields) $gps_section++;
            if ($has_dd_fields) $gps_section++;
            ?>
            <?php if ($has_dd_fields): ?>
            <div class="section-header"><?= $has_paystub_fields ? '5' : '4' ?>. Direct deposit — this site's bank</div>
            <p class="legal-hint" style="margin-top:-12px; margin-bottom:14px;">RBC, TD, and BMO take the same payroll deposit file. These values are filled in from the bank's setup letter and are used automatically when you download the direct deposit file. The institution number is this site's bank, not the employee's.</p>
            <div class="row">
                <div class="col-md-4">
                    <label>Bank</label>
                    <select name="dd_bank" id="ddBank" class="form-control">
                        <option value="" <?= $dd_bank_val === '' ? 'selected' : '' ?>>Not set</option>
                        <?php foreach ($dd_banks as $code => $info): ?>
                            <option value="<?= htmlspecialchars($code) ?>" <?= $dd_bank_val === $code ? 'selected' : '' ?>><?= htmlspecialchars($info['label']) ?><?= $info['institution'] !== '' ? ' (' . $info['institution'] . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label>Institution #</label>
                    <input id="ddInst" type="text" name="dd_institution" class="form-control" inputmode="numeric" maxlength="3" value="<?= htmlspecialchars((string)($site['dd_institution'] ?? '')) ?>" placeholder="003">
                    <div class="legal-hint">RBC 003, TD 004, BMO 001.</div>
                </div>
                <div class="col-md-4">
                    <label>Transit #</label>
                    <input type="text" name="dd_transit" class="form-control" inputmode="numeric" maxlength="5" value="<?= htmlspecialchars((string)($site['dd_transit'] ?? '')) ?>" placeholder="12345">
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-4">
                    <label>Account number</label>
                    <input type="text" name="dd_account" class="form-control" inputmode="numeric" maxlength="12" value="<?= htmlspecialchars((string)($site['dd_account'] ?? '')) ?>">
                    <div class="legal-hint">The account the bank debits, and where returned deposits go.</div>
                </div>
                <div class="col-md-4">
                    <label>Originator number</label>
                    <input type="text" name="dd_originator_id" class="form-control" maxlength="10" value="<?= htmlspecialchars((string)($site['dd_originator_id'] ?? '')) ?>" autocomplete="off">
                    <div class="legal-hint">The 10-character number the bank assigned to this company.</div>
                </div>
                <div class="col-md-4">
                    <label>Data centre</label>
                    <input type="text" name="dd_data_centre" class="form-control" inputmode="numeric" maxlength="5" value="<?= htmlspecialchars((string)($site['dd_data_centre'] ?? '')) ?>" placeholder="00310">
                    <div class="legal-hint">The 5-digit destination code on the same bank letter.</div>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-4">
                    <label>Short name on statements</label>
                    <input type="text" name="dd_originator_short" class="form-control" maxlength="15" value="<?= htmlspecialchars((string)($site['dd_originator_short'] ?? '')) ?>" placeholder="Store name">
                    <div class="legal-hint">Up to 15 characters. This is what employees see on their bank statement.</div>
                </div>
                <div class="col-md-4">
                    <label>Long name</label>
                    <input type="text" name="dd_originator_long" class="form-control" maxlength="30" value="<?= htmlspecialchars((string)($site['dd_originator_long'] ?? '')) ?>" placeholder="Legal company name">
                    <div class="legal-hint">Up to 30 characters. Leave blank to use the legal name above.</div>
                </div>
                <div class="col-md-4">
                    <label>Next file number</label>
                    <input type="number" name="dd_file_seq" class="form-control" min="1" max="9999" value="<?= (int)$dd_seq_val ?>">
                    <div class="legal-hint">Goes up by one each time a file is downloaded. The bank rejects a number it has already seen.</div>
                </div>
            </div>
            <?php endif; ?>

            <div class="section-header"><?= (int)$gps_section ?>. GPS Geofencing & Timezone</div>
            <div class="row">
                <div class="col-md-3"><label>Latitude</label><input type="text" name="target_lat" class="form-control" value="<?= $site['target_lat'] ?>"></div>
                <div class="col-md-3"><label>Longitude</label><input type="text" name="target_lng" class="form-control" value="<?= $site['target_lng'] ?>"></div>
                <div class="col-md-3"><label>Radius (Meters)</label><input type="number" name="geo_radius_meters" class="form-control" value="<?= $site['geo_radius_meters'] ?>"></div>
                <div class="col-md-3">
                    <label>Timezone</label>
                    <?php
                    $tz_cur = trim((string)($site['timezone_id'] ?? '')) ?: trim((string)($site['Timezone'] ?? ''));
                    $tz_opts = [
                        'America/Toronto' => 'Eastern — Toronto (ET)',
                        'America/Halifax' => 'Atlantic — Halifax (AT)',
                        'America/St_Johns' => 'Newfoundland — St. John’s',
                        'America/Winnipeg' => 'Central — Winnipeg (CT)',
                        'America/Regina' => 'Saskatchewan — Regina (no DST)',
                        'America/Edmonton' => 'Mountain — Edmonton (MT)',
                        'America/Vancouver' => 'Pacific — Vancouver (PT)',
                        'Asia/Karachi' => 'Karachi / Islamabad',
                        'Asia/Dubai' => 'Dubai (GST)',
                    ];
                    ?>
                    <select name="timezone_id" class="form-control" required>
                        <?php foreach ($tz_opts as $tz_val => $tz_label): ?>
                            <option value="<?= htmlspecialchars($tz_val) ?>" <?= ($tz_cur === $tz_val) ? 'selected' : '' ?>><?= htmlspecialchars($tz_label) ?></option>
                        <?php endforeach; ?>
                        <?php if ($tz_cur !== '' && !isset($tz_opts[$tz_cur])): ?>
                            <option value="<?= htmlspecialchars($tz_cur) ?>" selected><?= htmlspecialchars($tz_cur) ?> (current)</option>
                        <?php endif; ?>
                    </select>
                    <small class="text-muted">Site-local clock for kiosk and hours import (not server time).</small>
                </div>
            </div>

            <hr class="my-5">
            <div class="row">
                <div class="col-md-4"><a href="manage_sites.php" class="btn btn-light btn-block font-weight-bold" style="height:48px; line-height:32px;">CANCEL</a></div>
                <div class="col-md-8"><button type="submit" name="update_site" class="btn-save">SAVE ALL GLOBAL SETTINGS</button></div>
            </div>
        </form>
        <form method="POST" action="edit_site.php?id=<?= $sid ?>" class="mt-4 pt-3" style="border-top:1px dashed #fecaca;"
              onsubmit="return confirm('Permanently delete this site?\n\nThis cannot be undone. If it has hours, cancel and set Status to Inactive instead.');">
            <div class="legal-hint" style="color:#991b1b;margin-bottom:10px;">
                Delete only if there are no attendance/hours
                <?php if ($site_usage['hours'] > 0): ?>
                    — <strong>this site has <?= (int)$site_usage['hours'] ?> hour record(s). Use Inactive.</strong>
                <?php elseif ($site_usage['users'] > 0): ?>
                    — <strong><?= (int)$site_usage['users'] ?> employee(s) still assigned. Unassign them or use Inactive.</strong>
                <?php else: ?>
                    (none found — delete is allowed).
                <?php endif; ?>
            </div>
            <button type="submit" name="delete_site" value="1" class="btn btn-outline-danger font-weight-bold">DELETE SITE</button>
        </form>
    </div>
</div>

<script>
    const data = <?= json_encode($jurisdictions) ?>;
    const currentCountry = "<?= htmlspecialchars($site['country'] ?? 'CA') ?>";
    const currentState = "<?= htmlspecialchars(($site['province'] ?: $site['state']) ?? '') ?>";

    function populateStates() {
        const country = document.getElementById('countrySelect').value;
        const wrapper = document.getElementById('stateInputWrapper');
        const label = document.getElementById('stateLabel');
        
        if(country === 'GB') label.innerText = "County / Region";
        else if(country === 'AE') label.innerText = "Emirate";
        else label.innerText = "State / Province";

        if (data[country]) {
            let html = `<select name="state" class="form-control">`;
            for (let code in data[country]) {
                let selected = (code === currentState) ? 'selected' : '';
                html += `<option value="${code}" ${selected}>${data[country][code]} (${code})</option>`;
            }
            html += `</select>`;
            wrapper.innerHTML = html;
        } else {
            wrapper.innerHTML = `<input type="text" name="state" class="form-control" value="${currentState}" placeholder="Type Region">`;
        }
    }
    populateStates();
    var ddInstMap = {rbc:'003', td:'004', bmo:'001'};
    var ddBank = document.getElementById('ddBank');
    if (ddBank) {
        ddBank.addEventListener('change', function(){
            var n = ddInstMap[ddBank.value] || '';
            var inst = document.getElementById('ddInst');
            if (inst && n) inst.value = n;
        });
    }
</script>
</body>
</html>