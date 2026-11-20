<?php
/** * Path: /manage_sites.php 
 * FEATURES: Multi-tenant Site Management & SaaS Limit Enforcement
 */
ob_start(); 
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php'; 
require_once 'engine/menu_generator.php'; 
require_once __DIR__ . '/engine/site_status_lib.php';

// 1. SECURITY CHECK
if (!isset($_SESSION['user_id'])) { 
    header("Location: login_kiosk.php"); 
    exit; 
}

$user_org = (int)($_SESSION['org_id'] ?? 0);
$user_level = (int)($_SESSION['user_level'] ?? 99);
$is_super = ($user_level <= 10); 

$limit_error = false;
$flash_ok = '';
$flash_err = '';

// Sort whitelist (Staff Audit pattern). Never interpolate raw input into ORDER BY.
$allowed_sorts = [
    'sid_asc', 'sid_desc', 'nick_asc', 'nick_desc',
    'juris_asc', 'juris_desc', 'addr_asc', 'addr_desc',
    'status_asc', 'status_desc', 'org_asc', 'org_desc',
];
$sort_filter = trim((string)($_GET['sort'] ?? $_POST['sort'] ?? ''));
if (!in_array($sort_filter, $allowed_sorts, true)) {
    $sort_filter = '';
}

$ms_sites_url = static function (array $extra = []) use ($sort_filter): string {
    $qs = $extra;
    if ($sort_filter !== '') {
        $qs['sort'] = $sort_filter;
    }
    return 'manage_sites.php' . ($qs ? ('?' . http_build_query($qs)) : '');
};

// 2. HANDLE NEW SITE CREATION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_site'])) {
    $nick = trim($_POST['nick_name']);
    $org  = $is_super ? (int)$_POST['org_id'] : $user_org;
    
    // --- SAAS LIMIT GATEKEEPER ---
    // Count existing sites and compare to the organization's max_sites limit
    $stmt_limit = $pdo->prepare("
        SELECT max_sites, 
        (SELECT COUNT(*) FROM sites WHERE org_id = o.id) as current_count 
        FROM organizations o WHERE o.id = ?
    ");
    $stmt_limit->execute([$org]);
    $quota = $stmt_limit->fetch(PDO::FETCH_ASSOC);

    if ($quota) {
        $allowed = (int)$quota['max_sites'];
        $current = (int)$quota['current_count'];

        // If limit reached and NOT a Superadmin override
        if ($current >= $allowed && !$is_super) {
            $limit_error = true;
        }
    }

    if (!$limit_error && !empty($nick) && $org > 0) {
        $addr = trim($_POST['street_address']);
        $tz   = trim((string)($_POST['timezone_id'] ?? 'America/Toronto'));
        if ($tz === '') $tz = 'America/Toronto';
        $prov = $_POST['state']; 
        $ctry = $_POST['country'];
        // Keep timezone_id (forms) and Timezone (kiosk/finalize) in sync.
        $ins = $pdo->prepare("INSERT INTO sites (nick_name, street_address, timezone_id, Timezone, org_id, state, country, province) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([$nick, $addr, $tz, $tz, $org, $prov, $ctry, $prov]);
        header('Location: ' . $ms_sites_url(['msg' => 'success']));
        exit;
    }
}

// 2b. INACTIVE / ACTIVATE / DELETE (org fence: aaadmin = own org; admin = any)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['set_site_active']) || isset($_POST['delete_site']))) {
    $sid = (int)($_POST['sid'] ?? 0);
    $got = sip_site_load_for_manage($pdo, $sid, $is_super, $user_org);
    if (!$got['ok']) {
        header('Location: ' . $ms_sites_url(['err' => $got['error'] ?: 'denied']));
        exit;
    }
    if (isset($_POST['delete_site'])) {
        $usage = sip_site_usage($pdo, $sid);
        if ($usage['block'] === 'hours') {
            header('Location: ' . $ms_sites_url(['err' => 'has_hours']));
            exit;
        }
        if ($usage['block'] === 'users') {
            header('Location: ' . $ms_sites_url(['err' => 'has_users']));
            exit;
        }
        sip_site_hard_delete($pdo, $sid, $is_super, $user_org);
        header('Location: ' . $ms_sites_url(['msg' => 'deleted']));
        exit;
    }
    $want_active = ((string)($_POST['is_active'] ?? '1') === '1');
    sip_site_set_active($pdo, $sid, $want_active, $is_super, $user_org);
    header('Location: ' . $ms_sites_url(['msg' => $want_active ? 'activated' : 'inactive']));
    exit;
}

if (!empty($_GET['msg'])) {
    $map_ok = [
        'success' => 'Site created.',
        'updated' => 'Site updated.',
        'inactive' => 'Site marked Inactive. History is kept; it is hidden from pickers, timesheets, and CSV site lists.',
        'activated' => 'Site is Active again and will show in pickers.',
        'deleted' => 'Site deleted.',
    ];
    $flash_ok = $map_ok[$_GET['msg']] ?? '';
}
if (!empty($_GET['success']) && $_GET['success'] === 'updated') {
    $flash_ok = 'Site updated.';
}
if (!empty($_GET['err'])) {
    $map_err = [
        'has_hours' => 'Cannot delete — this site has attendance/hours. Use Inactive instead so history is kept.',
        'has_users' => 'Cannot delete — employees are still assigned to this site. Unassign them in Employee Hub, or use Inactive.',
        'denied' => 'You can only inactive or delete sites in your organization.',
        'notfound' => 'Site not found.',
    ];
    $flash_err = $map_err[$_GET['err']] ?? 'Action failed.';
}

// 3. DATA FETCHING
// Filter table so users only see their own sites
$site_where = $is_super ? "1=1" : "s.org_id = $user_org";
switch ($sort_filter) {
    case 'sid_asc':      $order_sql = 's.SId ASC'; break;
    case 'sid_desc':     $order_sql = 's.SId DESC'; break;
    case 'nick_desc':    $order_sql = 's.nick_name DESC'; break;
    case 'juris_asc':    $order_sql = 's.country ASC, s.state ASC, s.nick_name ASC'; break;
    case 'juris_desc':   $order_sql = 's.country DESC, s.state DESC, s.nick_name ASC'; break;
    case 'addr_asc':     $order_sql = 's.street_address ASC, s.nick_name ASC'; break;
    case 'addr_desc':    $order_sql = 's.street_address DESC, s.nick_name ASC'; break;
    case 'status_asc':   $order_sql = 's.status ASC, s.nick_name ASC'; break;
    case 'status_desc':  $order_sql = 's.status DESC, s.nick_name ASC'; break;
    case 'org_asc':      $order_sql = $is_super ? 'o.org_name ASC, s.nick_name ASC' : 's.nick_name ASC'; break;
    case 'org_desc':     $order_sql = $is_super ? 'o.org_name DESC, s.nick_name ASC' : 's.nick_name ASC'; break;
    case 'nick_asc':
    default:             $order_sql = 's.nick_name ASC'; break;
}

/** Build Manage Sites URL for sortable column headers (Staff Audit pattern). */
$sites_sort_cols = [
    'sid'    => ['asc' => 'sid_asc',    'desc' => 'sid_desc',    'first' => 'sid_asc'],
    'nick'   => ['asc' => 'nick_asc',   'desc' => 'nick_desc',   'first' => 'nick_asc'],
    'juris'  => ['asc' => 'juris_asc',  'desc' => 'juris_desc',  'first' => 'juris_asc'],
    'addr'   => ['asc' => 'addr_asc',   'desc' => 'addr_desc',   'first' => 'addr_asc'],
    'status' => ['asc' => 'status_asc', 'desc' => 'status_desc', 'first' => 'status_asc'],
];
if ($is_super) {
    $sites_sort_cols['org'] = ['asc' => 'org_asc', 'desc' => 'org_desc', 'first' => 'org_asc'];
}
$sites_sort_meta = [];
foreach ($sites_sort_cols as $col_key => $pair) {
    $is_asc  = ($sort_filter === $pair['asc']);
    $is_desc = ($sort_filter === $pair['desc']);
    if ($is_asc) {
        $next = $pair['desc'];
        $arr  = ' ▲';
    } elseif ($is_desc) {
        $next = $pair['asc'];
        $arr  = ' ▼';
    } else {
        $next = $pair['first'];
        $arr  = '';
    }
    $is_active = ($is_asc || $is_desc);
    $sites_sort_meta[$col_key] = [
        'href'   => '?' . http_build_query(['sort' => $next]),
        'arr'    => $is_active ? $arr : '',
        'active' => $is_active,
    ];
}

$site_sql = "SELECT s.*, o.org_name FROM sites s LEFT JOIN organizations o ON s.org_id = o.id WHERE $site_where ORDER BY $order_sql";
$site_rows = $pdo->query($site_sql)->fetchAll(PDO::FETCH_ASSOC);

// Fetch Orgs for the dropdown
if ($is_super) {
    $orgs = $pdo->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT id, org_name, max_sites FROM organizations WHERE id = ?");
    $stmt->execute([$user_org]);
    $orgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$jurisdictions = [
    "CA" => ["AB"=>"Alberta","BC"=>"British Columbia","MB"=>"Manitoba","NB"=>"New Brunswick","NL"=>"Newfoundland","NS"=>"Nova Scotia","ON"=>"Ontario","PE"=>"PEI","QC"=>"Quebec","SK"=>"Saskatchewan","NT"=>"NW Territories","NU"=>"Nunavut","YT"=>"Yukon"],
    "US" => ["AL"=>"Alabama","AK"=>"Alaska","AZ"=>"Arizona","AR"=>"Arkansas","CA"=>"California","CO"=>"Colorado","CT"=>"Connecticut","DE"=>"Delaware","FL"=>"Florida","GA"=>"Georgia","HI"=>"Hawaii","ID"=>"Idaho","IL"=>"Illinois","IN"=>"Indiana","IA"=>"Iowa","KS"=>"Kansas","KY"=>"Kentucky","LA"=>"Louisiana","ME"=>"Maine","MD"=>"Maryland","MA"=>"Massachusetts","MI"=>"Michigan","MN"=>"Minnesota","MS"=>"Mississippi","MO"=>"Missouri","MT"=>"Montana","NE"=>"Nebraska","NV"=>"Nevada","NH"=>"New Hampshire","NJ"=>"New Jersey","NM"=>"New Mexico","NY"=>"New York","NC"=>"North Carolina","ND"=>"North Dakota","OH"=>"Ohio","OK"=>"Oklahoma","OR"=>"Oregon","PA"=>"Pennsylvania","RI"=>"Rhode Island","SC"=>"South Carolina","SD"=>"South Dakota","TN"=>"Tennessee","TX"=>"Texas","UT"=>"Utah","VT"=>"Vermont","VA"=>"Virginia","WA"=>"Washington","WV"=>"West Virginia","WI"=>"Wisconsin","WY"=>"Wyoming"]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Sites | SIP Global</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; padding-top: 110px; color: #1e293b; }
        .main-container { max-width: 1650px; margin: 0 auto; padding: 0 20px; }
        .card-custom { background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        label { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 5px; display: block; }
        .form-control { height: 40px; font-size: 13px; border-radius: 8px; }
        .btn-add { background: #0f172a; color: white; border: none; font-weight: 800; border-radius: 8px; height: 40px; }
        .badge-geo { font-size: 10px; font-weight: 800; padding: 4px 8px; border-radius: 5px; text-transform: uppercase; }
        .alert-limit { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; padding: 15px; border-radius: 8px; font-weight: bold; margin-bottom: 20px; }
        .alert-ok { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; padding: 12px 15px; border-radius: 8px; font-weight: 700; margin-bottom: 16px; font-size: 13px; }
        .alert-err { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 12px 15px; border-radius: 8px; font-weight: 700; margin-bottom: 16px; font-size: 13px; }
        tr.site-inactive td { opacity: 0.72; background: #f8fafc; }
        .badge-active { background: #d1fae5; color: #065f46; font-size: 10px; font-weight: 800; padding: 4px 8px; border-radius: 5px; text-transform: uppercase; }
        .badge-inactive { background: #fef3c7; color: #92400e; font-size: 10px; font-weight: 800; padding: 4px 8px; border-radius: 5px; text-transform: uppercase; }
        .btn-inactive { color: #92400e; border-color: #f59e0b; }
        .site-actions { display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap; align-items: center; }
        #sitesTable th.sortable { cursor: pointer; user-select: none; }
        #sitesTable th.sortable a { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 2px; }
        #sitesTable th.sortable:hover { background: #e2e8f0; }
        #sitesTable th.sortable.active { color: #1d4ed8; }
        #sitesTable th.sortable .arr { font-size: 9px; opacity: 0.95; letter-spacing: -1px; }
    </style>
</head>
<body>

<?php echo generateMenu($pdo); ?>
    <?php require_once __DIR__.'/payroll_help.php'; echo '<div style="margin:10px 16px 0;">'.payroll_help_panel('manage_sites.php').'</div>'; ?>


<div class="main-container">

    <?php if ($flash_ok): ?>
        <div class="alert-ok"><i class="fa fa-check-circle"></i> <?= htmlspecialchars($flash_ok) ?></div>
    <?php endif; ?>
    <?php if ($flash_err): ?>
        <div class="alert-err"><i class="fa fa-exclamation-circle"></i> <?= htmlspecialchars($flash_err) ?></div>
    <?php endif; ?>

    <?php if ($limit_error): ?>
        <div class="alert-limit">
            <i class="fa fa-exclamation-circle"></i> SITE LIMIT REACHED: 
            Your organization is limited to <?= $allowed ?> sites. Please upgrade your plan to add more.
        </div>
    <?php endif; ?>

    <div class="card-custom">
        <h5 style="font-weight:900; margin-bottom:20px;">Register Location</h5>
        <form method="POST" class="row align-items-end">
            <?php if ($sort_filter !== ''): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort_filter) ?>"><?php endif; ?>
            <div class="col-md-2">
                <label>Nickname</label>
                <input type="text" name="nick_name" class="form-control" placeholder="Store Name" required>
            </div>
            <div class="col-md-2">
                <label>Street Address</label>
                <input type="text" name="street_address" class="form-control" placeholder="123 Main St">
            </div>
            <div class="col-md-1">
                <label>Country</label>
                <select name="country" id="countrySelect" class="form-control" onchange="populateStates()">
                    <option value="CA">Canada</option>
                    <option value="US">USA</option>
                </select>
            </div>
            <div class="col-md-2">
                <label id="stateLabel">State / Prov</label>
                <div id="stateInputWrapper"></div>
            </div>
            <div class="col-md-2">
                <label>Timezone</label>
                <select name="timezone_id" class="form-control" required>
                    <option value="America/Toronto">Eastern (Toronto)</option>
                    <option value="America/Halifax">Atlantic (Halifax)</option>
                    <option value="America/Winnipeg">Central (Winnipeg)</option>
                    <option value="America/Regina">Saskatchewan (Regina)</option>
                    <option value="America/Edmonton">Mountain (Edmonton)</option>
                    <option value="America/Vancouver">Pacific (Vancouver)</option>
                    <option value="Asia/Karachi">Karachi / Islamabad</option>
                    <option value="Asia/Dubai">Dubai</option>
                </select>
            </div>
            <div class="col-md-2">
                <label>Organization</label>
                <select name="org_id" class="form-control" required <?= !$is_super ? 'disabled' : '' ?>>
                    <?php foreach ($orgs as $o): ?>
                        <option value="<?= $o['id'] ?>" <?= $o['id'] == $user_org ? 'selected' : '' ?>><?= htmlspecialchars($o['org_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if(!$is_super): ?><input type="hidden" name="org_id" value="<?= $user_org ?>"><?php endif; ?>
            </div>
            <div class="col-md-1">
                <button type="submit" name="create_site" class="btn-add btn-block">ADD</button>
            </div>
        </form>
    </div>

    <div class="card-custom p-0 overflow-hidden">
        <table id="sitesTable" class="table mb-0">
            <thead class="bg-light">
                <tr>
                    <th class="pl-4 sortable<?= !empty($sites_sort_meta['sid']['active']) ? ' active' : '' ?>">
                        <a href="<?= htmlspecialchars($sites_sort_meta['sid']['href']) ?>" title="Sort by SID">SID<span class="arr"><?= htmlspecialchars($sites_sort_meta['sid']['arr']) ?></span></a>
                    </th>
                    <th class="sortable<?= !empty($sites_sort_meta['nick']['active']) ? ' active' : '' ?>">
                        <a href="<?= htmlspecialchars($sites_sort_meta['nick']['href']) ?>" title="Sort by site nickname">Site Nickname<span class="arr"><?= htmlspecialchars($sites_sort_meta['nick']['arr']) ?></span></a>
                    </th>
                    <?php if($is_super): ?>
                    <th class="sortable<?= !empty($sites_sort_meta['org']['active']) ? ' active' : '' ?>">
                        <a href="<?= htmlspecialchars($sites_sort_meta['org']['href']) ?>" title="Sort by organization">Organization<span class="arr"><?= htmlspecialchars($sites_sort_meta['org']['arr']) ?></span></a>
                    </th>
                    <?php endif; ?>
                    <th class="sortable<?= !empty($sites_sort_meta['juris']['active']) ? ' active' : '' ?>">
                        <a href="<?= htmlspecialchars($sites_sort_meta['juris']['href']) ?>" title="Sort by jurisdiction">Jurisdiction<span class="arr"><?= htmlspecialchars($sites_sort_meta['juris']['arr']) ?></span></a>
                    </th>
                    <th class="sortable<?= !empty($sites_sort_meta['addr']['active']) ? ' active' : '' ?>">
                        <a href="<?= htmlspecialchars($sites_sort_meta['addr']['href']) ?>" title="Sort by address">Address<span class="arr"><?= htmlspecialchars($sites_sort_meta['addr']['arr']) ?></span></a>
                    </th>
                    <th class="sortable<?= !empty($sites_sort_meta['status']['active']) ? ' active' : '' ?>">
                        <a href="<?= htmlspecialchars($sites_sort_meta['status']['href']) ?>" title="Sort by status">Status<span class="arr"><?= htmlspecialchars($sites_sort_meta['status']['arr']) ?></span></a>
                    </th>
                    <th class="text-right pr-4">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($site_rows as $row):
                    $row_active = sip_site_status_is_active($row['status'] ?? 'Active');
                    $nick_js = htmlspecialchars($row['nick_name'] ?? '', ENT_QUOTES);
                ?>
                <tr class="<?= $row_active ? '' : 'site-inactive' ?>">
                    <td class="pl-4 text-muted small">#<?= $row['SId'] ?></td>
                    <td><strong><?= htmlspecialchars($row['nick_name']) ?></strong></td>
                    <?php if($is_super): ?><td><span class="badge badge-dark"><?= htmlspecialchars($row['org_name']) ?></span></td><?php endif; ?>
                    <td><span class="badge-geo"><?= $row['country'] ?> - <?= $row['state'] ?></span></td>
                    <td class="small text-muted"><?= htmlspecialchars($row['street_address']) ?></td>
                    <td>
                        <?php if ($row_active): ?>
                            <span class="badge-active">Active</span>
                        <?php else: ?>
                            <span class="badge-inactive">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right pr-4">
                        <div class="site-actions">
                            <a href="edit_site.php?id=<?= (int)$row['SId'] ?>" class="btn btn-sm btn-outline-secondary font-weight-bold">EDIT</a>
                            <form method="POST" style="display:inline;margin:0;">
                                <input type="hidden" name="sid" value="<?= (int)$row['SId'] ?>">
                                <input type="hidden" name="is_active" value="<?= $row_active ? '0' : '1' ?>">
                                <?php if ($sort_filter !== ''): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort_filter) ?>"><?php endif; ?>
                                <button type="submit" name="set_site_active" value="1" class="btn btn-sm <?= $row_active ? 'btn-outline-warning btn-inactive' : 'btn-outline-success' ?> font-weight-bold">
                                    <?= $row_active ? 'INACTIVE' : 'ACTIVATE' ?>
                                </button>
                            </form>
                            <form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('Permanently delete site &quot;<?= $nick_js ?>&quot;?\n\nThis cannot be undone. If this site has hours, cancel and click Inactive instead.');">
                                <input type="hidden" name="sid" value="<?= (int)$row['SId'] ?>">
                                <?php if ($sort_filter !== ''): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort_filter) ?>"><?php endif; ?>
                                <button type="submit" name="delete_site" value="1" class="btn btn-sm btn-outline-danger font-weight-bold">DELETE</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    const jurisData = <?= json_encode($jurisdictions) ?>;
    function populateStates() {
        const country = document.getElementById('countrySelect').value;
        const wrapper = document.getElementById('stateInputWrapper');
        if (jurisData[country]) {
            let html = `<select name="state" class="form-control">`;
            for (let code in jurisData[country]) {
                html += `<option value="${code}">${code}</option>`;
            }
            html += `</select>`;
            wrapper.innerHTML = html;
        } else {
            wrapper.innerHTML = `<input type="text" name="state" class="form-control" placeholder="Region">`;
        }
    }
    populateStates();
</script>
</body>
</html>
<?php ob_end_flush(); ?>