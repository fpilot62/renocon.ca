<?php
/**
 * Path: /staff_audit.php
 * Logic: Contextual Corridor Display with Dynamic Status Type Swapping, Dual Adjustments, and Shift Cloning
 * Sync: Aligned to 'flag_reason', lowercase 'remarks', Base64 Longtext data, uppercase 'Sid', 
 * and reads frozen rates directly from attendance snapshots.
 * Fix: Complete structural optimization ensuring stable asset loading, type-safe alphanumeric user parameters, 
 * clean route mapping hyperlinks, and automated rest interval date matching.
 * UPDATED LAYOUT INTEGRATION: Added floating allocation overlay matted container desk to keep table data visible behind parameters.
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Pull in the core database layer and session profiles from your db.php
require_once 'db.php';
require_once __DIR__ . '/payroll_scope.php'; 
require_once __DIR__ . '/engine/attendance_integrity_lib.php';

if (file_exists('engine/menu_generator.php')) {
    require_once 'engine/menu_generator.php';
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) { die("Access Denied."); }

/** Placeholder kiosk text is not a real off-schedule explanation. */
function sa_reason_is_placeholder($text) {
    $n = strtolower(trim(preg_replace('/\s+/', ' ', (string)$text)));
    return $n === '' || in_array($n, [
        'shift', 'override', 'off schedule', 'off-schedule',
        'n/a', 'na', 'none', '-', '--',
    ], true);
}

/**
 * Build the Staff Audit Reason cell: real flag_reason / remarks / manager_notes,
 * or a clear "no reason typed" label when the kiosk only stored "shift".
 * @return array{kind:string,text:string}
 */
function aalh_rate_change_href(int $userId, int $orgId, float $fileRate, string $effective): string
{
    if ($userId <= 0) {
        return '';
    }
    $q = ['tab' => 'one', 'user_id' => $userId];
    if ($orgId > 0) {
        $q['org_id'] = $orgId;
    }
    if ($fileRate > 0) {
        $q['new_rate'] = number_format($fileRate, 2, '.', '');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective)) {
        $q['effective_date'] = $effective;
    }
    return 'rate_change.php?' . http_build_query($q);
}

function sa_audit_reason_parts(array $r) {
    $flag = trim((string)($r['flag_reason'] ?? ''));
    $remarks = trim((string)($r['remarks'] ?? ''));
    $notes = trim((string)($r['manager_notes'] ?? ''));
    $flagged = ((int)($r['is_flagged'] ?? 0) === 1) || ($flag !== '');

    $parts = [];
    $add = static function ($text) use (&$parts) {
        $t = trim((string)$text);
        if ($t === '') {
            return;
        }
        foreach ($parts as $p) {
            if (strcasecmp($p, $t) === 0) {
                return;
            }
        }
        $parts[] = $t;
    };

    if ($flag !== '' && !sa_reason_is_placeholder($flag)) {
        $add($flag);
    }
    if (!sa_reason_is_placeholder($remarks)) {
        $add($remarks);
    }
    if (!sa_reason_is_placeholder($notes)) {
        $add($notes);
    }

    if (!empty($parts)) {
        return ['kind' => 'real', 'text' => implode("\n", $parts)];
    }
    if ($flagged) {
        return ['kind' => 'empty', 'text' => 'Off schedule — no reason typed'];
    }
    return ['kind' => 'none', 'text' => ''];
}

// Extract privilege tier levels from your db.php architecture
$sess_user_level = isset($_SESSION['user_level']) ? (int)$_SESSION['user_level'] : 99;
$org_id = (int)($_SESSION['org_id'] ?? 1);

// =========================================================================
// ЁЯФе EMERGENCY BACKGROUND BULK FIXER: RESOLVES OMITTED SITES AUTOMATICALLY
// =========================================================================
try {
    $fixSql = "
        UPDATE attendance a
        INNER JOIN users u ON a.user_id = u.id
        SET a.site_id = u.primary_site_id
        WHERE (a.site_id = '0' OR a.site_id = 'all' OR a.site_id IS NULL)
          AND u.primary_site_id > 0 
          AND a.payout_status = 0
    ";
    if (att_has_deleted_at($pdo)) {
        $fixSql .= " AND a.deleted_at IS NULL";
    }
    $pdo->query($fixSql);
} catch (Exception $e) {}

// =========================================================================
// тЪб UNIFIED POST ACTION HANDLER: DELETIONS, BREAKS, AND NEW DUPLICATION INJECTOR
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // AJAX ACTION CALL: Clones an attendance record cleanly along with its child breaks
    if ($_POST['action'] === 'duplicate_shift' && !empty($_POST['shift_id'])) {
        header('Content-Type: application/json');
        $target_id = (int)$_POST['shift_id'];
        
        try {
            $srcSql = "SELECT * FROM attendance WHERE id = ?";
            if (att_has_deleted_at($pdo)) {
                $srcSql .= " AND deleted_at IS NULL";
            }
            $srcStmt = $pdo->prepare($srcSql);
            $srcStmt->execute([$target_id]);
            $src = $srcStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$src) {
                echo json_encode(['success' => false, 'error' => 'Original attendance shift row not found.']);
                exit;
            }
            
            $insQuery = "INSERT INTO attendance 
                (org_id, site_id, flag_reason, is_flagged, user_id, status, clock_in, clock_out, travel_time_hours, approved_hours, hourly_rate, official_rate, manager_notes, lat_in, lng_in, lat_out, lng_out, is_processed, payout_status, remarks, transfer_status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)";
            
            $insStmt = $pdo->prepare($insQuery);
            $insStmt->execute([
                $src['org_id'],
                $src['site_id'],
                $src['flag_reason'],
                $src['is_flagged'],
                $src['user_id'],
                $src['status'],
                $src['clock_in'],
                $src['clock_out'],
                $src['travel_time_hours'],
                $src['approved_hours'],
                $src['hourly_rate'],
                $src['official_rate'],
                trim(($src['manager_notes'] ?? '') . ' (Duplicated Copy)'),
                $src['lat_in'],
                $src['lng_in'],
                $src['lat_out'],
                $src['lng_out'],
                $src['is_processed'],
                $src['remarks'],
                $src['transfer_status']
            ]);
            
            $new_shift_id = $pdo->lastInsertId();
            
            $brkSrc = $pdo->prepare("SELECT * FROM breaks WHERE shift_id = ?");
            $brkSrc->execute([$target_id]);
            $breaks = $brkSrc->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($breaks as $b) {
                $insBrk = $pdo->prepare("INSERT INTO breaks (shift_id, break_start, break_end, start_selfie, end_selfie, start_lat, start_lng, end_lat, end_lng) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insBrk->execute([$new_shift_id, $b['break_start'], $b['break_end'], $b['start_selfie'], $b['end_selfie'], $b['start_lat'], $b['start_lng'], $b['end_lat'], $b['end_lng']]);
            }
            
            echo json_encode(['success' => true]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // Handles checkbox groupings and direct trash icon actions flawlessly
    if ($_POST['action'] === 'bulk_delete') {
        $ids_to_clean = [];
        if (!empty($_POST['shift_ids'])) {
            $ids_to_clean = is_array($_POST['shift_ids']) ? $_POST['shift_ids'] : [$_POST['shift_ids']];
        }
        
        if (!empty($ids_to_clean)) {
            $clean_ids = array_values(array_filter(array_map('intval', $ids_to_clean), static function ($id) {
                return $id > 0;
            }));
            if (!$clean_ids) {
                header("Location: " . $_SERVER['REQUEST_URI']);
                exit;
            }
            if (!att_soft_delete_ready($pdo)) {
                die("Run migrations/20261110_attendance_soft_delete.sql before deleting attendance.");
            }
            $restrict = true;
            $scope_org = (int)($_SESSION['org_id'] ?? 0);
            if ($sess_user_level <= 10) {
                $org_raw_del = $_GET['org_id'] ?? ($_POST['org_id'] ?? ($_COOKIE['sip_org_id'] ?? ($_COOKIE['sa_org_id'] ?? 'all')));
                if ($org_raw_del === '' || $org_raw_del === 'all' || (int)$org_raw_del <= 0) {
                    $restrict = false;
                } else {
                    $scope_org = (int)$org_raw_del;
                }
            } elseif ($scope_org <= 0) {
                die('That organization is outside your access.');
            }
            $delQuery = att_soft_delete_sql(count($clean_ids), $restrict);
            $delParams = array_merge([(int)($_SESSION['user_id'] ?? 0)], $clean_ids);
            if ($restrict) {
                $delParams[] = $scope_org;
                $delParams[] = $scope_org;
                $delParams[] = $scope_org;
            }
            $delStmt = $pdo->prepare($delQuery);
            $delStmt->execute($delParams);

            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
    }
    
    // Real-time update processor for break configurations
    if ($_POST['action'] === 'update_breaks' && !empty($_POST['shift_id'])) {
        $shift_id = (int)$_POST['shift_id'];
        
        // Wipe existing child links to overwrite cleanly
        $pdo->prepare("DELETE FROM breaks WHERE shift_id = ?")->execute([$shift_id]);
        
        if (!empty($_POST['break_start'])) {
            foreach ($_POST['break_start'] as $idx => $bStartStr) {
                $bEndStr = $_POST['break_end'][$idx] ?? '';
                if (!empty(trim($bStartStr))) {
                    $insB = $pdo->prepare("INSERT INTO breaks (shift_id, break_start, break_end) VALUES (?, ?, ?)");
                    $insB->execute([
                        $shift_id,
                        trim($bStartStr),
                        !empty(trim($bEndStr)) ? trim($bEndStr) : null
                    ]);
                }
            }
        }
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// 1. Capture Multi-Filters & Enforce Broad Sanitized Array Key Mappings
$site_filter     = isset($_GET['site_id']) ? trim((string)$_GET['site_id']) : 'all';
$employee_filter = isset($_GET['user_id']) ? trim((string)$_GET['user_id']) : 'all';
$from_date       = $_GET['from_date'] ?? '';
$to_date         = $_GET['to_date'] ?? '';
$group_by        = $_GET['group_by'] ?? 'none'; 
$status_type     = isset($_GET['status_type']) ? trim((string)$_GET['status_type']) : 'pending';
$period_mode     = 'semi_monthly';
if (!function_exists('sip_org_pay_period_mode')) {
    require_once __DIR__ . '/engine/org_modules_lib.php';
}
if (function_exists('sip_org_pay_period_mode')) {
    $period_mode = sip_org_pay_period_mode($pdo, (int)$org_id);
}
$period_anchor = function_exists('sip_org_pay_period_anchor') ? sip_org_pay_period_anchor($pdo, (int)$org_id) : '2024-01-01';

/** Pay-period [start,end] for Staff Audit nav + period totals (aligned with payroll_master_ledger). */
if (!function_exists('sa_period_bounds')) {
    function sa_period_bounds(string $work_date, string $mode, string $anchor = '2024-01-01'): array {
        if (!function_exists('pe_period_window')) {
            $eng = __DIR__ . '/payroll_engine/payroll_engine.php';
            if (is_file($eng)) { require_once $eng; }
        }
        if (function_exists('pe_period_window')) {
            return pe_period_window($work_date, $mode, $anchor);
        }
        $ts = strtotime($work_date);
        if ($ts === false) {
            $ts = time();
        }
        if ($mode === 'monthly') {
            return [date('Y-m-01', $ts), date('Y-m-t', $ts)];
        }
        if ($mode === 'bi_weekly') {
            $d0 = new DateTimeImmutable(preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor) ? $anchor : '2024-01-01');
            $day = substr($work_date, 0, 10);
            $d1 = new DateTimeImmutable(preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? $day : date('Y-m-d'));
            $days = (int)$d0->diff($d1)->format('%r%a');
            $start = $d0->modify((((int)floor($days / 14)) * 14) . ' days')->format('Y-m-d');
            $end = (new DateTimeImmutable($start))->modify('+13 days')->format('Y-m-d');
            return [$start, $end];
        }
        $dayn = (int)date('j', $ts);
        if ($dayn <= 15) {
            return [date('Y-m-01', $ts), date('Y-m-15', $ts)];
        }
        return [date('Y-m-16', $ts), date('Y-m-t', $ts)];
    }
}

if (!function_exists('sa_load_staff_options')) {
    /**
     * Staff dropdown = employees of the selected restaurant(s).
     * Combination: org (if set) + home restaurant (primary_site_id OR primary_restaurant_id).
     * Do not use assigned_site_ids — that lists people who can punch at many stores.
     */
    function sa_load_staff_options(PDO $pdo, int $org_id, array $site_ids): array {
        $sql = 'SELECT id, user, Name FROM users WHERE 1=1';
        $params = [];
        if ($org_id > 0) {
            $sql .= ' AND org_id = ?';
            $params[] = $org_id;
        }
        if (!empty($site_ids)) {
            $parts = [];
            foreach ($site_ids as $sid) {
                $sid = (int)$sid;
                if ($sid <= 0) {
                    continue;
                }
                $parts[] = '(primary_site_id = ? OR primary_restaurant_id = ?)';
                $params[] = $sid;
                $params[] = $sid;
            }
            if ($parts) {
                $sql .= ' AND (' . implode(' OR ', $parts) . ')';
            }
        }
        $sql .= ' ORDER BY Name ASC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('sa_remember_org_cookie')) {
    function sa_remember_org_cookie(int $selected_org): void {
        if (headers_sent()) {
            return;
        }
        setcookie('sa_org_id', $selected_org > 0 ? (string)$selected_org : 'all', [
            'expires' => time() + 86400 * 120,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
}

// STRIP ENFORCEMENT STABILIZER: Cleans parameters from URL strings
if (!empty($from_date)) { $from_date = trim(preg_replace('/[^0-9\-]/', '', $from_date)); }
if (!empty($to_date)) { $to_date = trim(preg_replace('/[^0-9\-]/', '', $to_date)); }

if (!function_exists('sip_load_picker_sites')) {
    require_once __DIR__ . '/engine/site_status_lib.php';
}
if (!function_exists('sip_org_id_from_site_ids')) {
    require_once __DIR__ . '/engine/org_modules_lib.php';
}

$sa_is_super = ($sess_user_level <= 10);
$sa_orgs = [];
if ($sa_is_super) {
    try {
        $sa_orgs = $pdo->query('SELECT id, org_name FROM organizations ORDER BY org_name ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $sa_orgs = [];
    }
}
$org_raw = $_GET['org_id'] ?? ($_COOKIE['sip_org_id'] ?? ($_COOKIE['sa_org_id'] ?? 'all'));
if (!$sa_is_super) {
    $selected_org = (int)($_SESSION['org_id'] ?? 0);
} else {
    $selected_org = ($org_raw === '' || $org_raw === 'all') ? 0 : (int)$org_raw;
}

$sa_aa_org = 4;
try {
    $aaId = $pdo->query("SELECT id FROM organizations WHERE org_name = 'AA Group' LIMIT 1")->fetchColumn();
    if ($aaId) {
        $sa_aa_org = (int)$aaId;
    }
} catch (Throwable $e) {
}
$sa_show_aa_labor = $sa_is_super
    || ((int)$selected_org === $sa_aa_org)
    || ((int)$org_id === $sa_aa_org);

$assigned_for_picker = (string)($_SESSION['assigned_site_ids'] ?? '');
if ($assigned_for_picker === '' && !empty($_SESSION['user_id'])) {
    try {
        $asStmt = $pdo->prepare('SELECT assigned_site_ids FROM users WHERE id = ? LIMIT 1');
        $asStmt->execute([(int)$_SESSION['user_id']]);
        $assigned_for_picker = (string)($asStmt->fetchColumn() ?: '');
        $_SESSION['assigned_site_ids'] = $assigned_for_picker;
    } catch (Exception $e) { /* keep empty → all org sites */ }
}

$picker_org = $selected_org > 0 ? $selected_org : (int)($_SESSION['org_id'] ?? 0);
if ($sa_is_super && $selected_org > 0 && function_exists('sip_sites_active_sql')) {
    $activeSql = sip_sites_active_sql('');
    $stSites = $pdo->prepare("SELECT SId, SId AS Sid, SId AS id, nick_name FROM sites WHERE org_id = ? AND $activeSql ORDER BY nick_name ASC");
    $stSites->execute([$selected_org]);
    $sites = $stSites->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sites = sip_load_picker_sites(
        $pdo,
        $sess_user_level,
        $picker_org,
        $assigned_for_picker
    );
}

$site_raw = isset($_GET['site_id']) ? trim((string)$_GET['site_id']) : null;
$site_ids = [];
if ($site_raw !== null && $site_raw !== '' && strtolower($site_raw) !== 'all') {
    $site_ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $site_raw)), static fn($v) => $v > 0)));
}

// Choosing a site without an org (superadmin) must still narrow to that site's company.
if ($sa_is_super && $selected_org <= 0 && !empty($site_ids) && function_exists('sip_org_id_from_site_ids')) {
    $inferred = sip_org_id_from_site_ids($pdo, $site_ids, 0);
    if ($inferred > 0) {
        $selected_org = $inferred;
        if (function_exists('sip_sites_active_sql')) {
            $activeSql = sip_sites_active_sql('');
            $stSites = $pdo->prepare("SELECT SId, SId AS Sid, SId AS id, nick_name FROM sites WHERE org_id = ? AND $activeSql ORDER BY nick_name ASC");
            $stSites->execute([$selected_org]);
            $sites = $stSites->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

if ($sa_is_super) {
    sa_remember_org_cookie($selected_org);
}

$allowed_sids = [];
foreach ((array)$sites as $__s) {
    $sid = (int)($__s['Sid'] ?? $__s['id'] ?? $__s['SId'] ?? 0);
    if ($sid > 0) {
        $allowed_sids[$sid] = true;
    }
}
if (!empty($site_ids) && $allowed_sids) {
    $site_ids = array_values(array_filter($site_ids, static function ($id) use ($allowed_sids) {
        return isset($allowed_sids[(int)$id]);
    }));
}

$starter_sites = false;
$default_pair_ids = [];
$default_pair_names = [];
$pickFrom = (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$from_date)) ? $from_date : '';
$pickTo   = (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$to_date)) ? $to_date : '';
if ($pickFrom === '' || $pickTo === '') {
    [$pickFrom, $pickTo] = sa_period_bounds(date('Y-m-d'), $period_mode, $period_anchor);
}
$starter_org = $selected_org > 0 ? $selected_org : (int)$org_id;
if (is_array($sites) && count($sites) > 2) {
    $default_pair_ids = ps_pick_starter_sites($pdo, $starter_org, $sites, (string)$pickFrom, (string)$pickTo, 'attendance', 2);
    foreach ($sites as $__s) {
        $rid = (int)($__s['Sid'] ?? $__s['id'] ?? 0);
        if (in_array($rid, $default_pair_ids, true)) {
            $default_pair_names[] = (string)($__s['nick_name'] ?? '');
        }
    }
}
if ($site_raw === null && !empty($default_pair_ids)) {
    $site_ids = $default_pair_ids;
    $starter_sites = true;
} elseif ($site_raw !== null && strtolower((string)$site_raw) !== 'all' && empty($site_ids) && !empty($default_pair_ids)) {
    $site_ids = $default_pair_ids;
    $starter_sites = true;
}
$site_filter = empty($site_ids) ? 'all' : implode(',', $site_ids);

$staff_org = $selected_org > 0 ? $selected_org : ($sa_is_super ? 0 : (int)($_SESSION['org_id'] ?? 0));
$staff = sa_load_staff_options($pdo, $staff_org, $site_ids);

if (!function_exists('sip_org_pay_period_mode')) {
    require_once __DIR__ . '/engine/org_modules_lib.php';
}
$period_org_id = $selected_org > 0 ? $selected_org : (int)$org_id;
$req_org = (int)($_GET['org_filter'] ?? $_GET['org_id'] ?? 0);
if ($sess_user_level <= 10 && $req_org > 0) {
    $period_org_id = $req_org;
} elseif ($selected_org > 0) {
    $period_org_id = $selected_org;
} elseif (!empty($site_ids) && function_exists('sip_org_id_from_site_ids')) {
    $period_org_id = sip_org_id_from_site_ids($pdo, $site_ids, $period_org_id);
}
if (function_exists('sip_org_pay_period_mode')) {
    $period_mode = sip_org_pay_period_mode($pdo, $period_org_id);
}
$period_anchor = function_exists('sip_org_pay_period_anchor') ? sip_org_pay_period_anchor($pdo, $period_org_id) : '2024-01-01';

// 2. Query Logic - Shows records across requested parameters
$where = [];
$params = [];

if ($status_type === 'finalized') {
    $where[] = "a.payout_status = 1";
} elseif ($status_type === 'all') {
    // Open pass
} else {
    $where[] = "a.payout_status = 0";
}
if (att_has_deleted_at($pdo)) {
    $where[] = "a.deleted_at IS NULL";
}

if ($sess_user_level > 10 && isset($_SESSION['org_id']) && $_SESSION['org_id'] > 0) {
    $where[] = "a.org_id = :org_id";
    $params[':org_id'] = (int)$_SESSION['org_id'];
} elseif ($sa_is_super && $selected_org > 0) {
    $where[] = "a.org_id = :org_id";
    $params[':org_id'] = (int)$selected_org;
}

if (!empty($from_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) {
    $where[] = "DATE(a.clock_in) >= :from_date";
    $params[':from_date'] = $from_date;
}
if (!empty($to_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
    $where[] = "DATE(a.clock_in) <= :to_date";
    $params[':to_date'] = $to_date;
}
if (!empty($site_ids)) {
    // Match display JOIN: imported/legacy rows may show site via primary_site_id
    // while a.site_id is 0/all/null — strict a.site_id alone yields an empty list.
    $sidPh = [];
    foreach ($site_ids as $i => $sidVal) {
        $k = ':sid' . $i;
        $sidPh[] = $k;
        $params[$k] = (int)$sidVal;
    }
    $where[] = "COALESCE(NULLIF(NULLIF(a.site_id, '0'), 'all'), NULLIF(u.primary_site_id, 0)) IN (" . implode(',', $sidPh) . ")";
}

// USER RUNTIME RESOLVER: match staff already narrowed to org + site (avoids the wrong Navpreet).
if ($employee_filter !== 'all' && $employee_filter !== '') {
    $resolvedId = 0;
    foreach ($staff as $u) {
        if ((string)$employee_filter === (string)$u['id'] || (string)$employee_filter === (string)($u['user'] ?? '')) {
            $resolvedId = (int)$u['id'];
            break;
        }
    }
    if ($resolvedId > 0) {
        $where[] = "a.user_id = :uid";
        $params[':uid'] = $resolvedId;
    } else {
        $employee_filter = 'all';
    }
}

$sort_filter = $_GET['sort'] ?? 'date_desc';
$allowed_sorts = [
    'name_asc', 'name_desc', 'site_asc', 'site_desc',
    'duration_asc', 'duration_desc', 'date_asc', 'date_desc',
];
if (!in_array($sort_filter, $allowed_sorts, true)) {
    $sort_filter = 'date_desc';
}
if ($group_by === 'staff') {
    $order_sql = "u.Name ASC, a.clock_in DESC";
} elseif ($group_by === 'date') {
    $order_sql = "DATE(a.clock_in) DESC, a.clock_in DESC";
} else {
    switch ($sort_filter) {
        case 'name_asc':  $order_sql = "u.Name ASC, a.user_id ASC, a.clock_in DESC"; break;
        case 'name_desc': $order_sql = "u.Name DESC, a.user_id ASC, a.clock_in DESC"; break;
        case 'site_asc':  $order_sql = "s.nick_name ASC, a.clock_in DESC"; break;
        case 'site_desc': $order_sql = "s.nick_name DESC, a.clock_in DESC"; break;
        case 'duration_asc':  $order_sql = "TIMESTAMPDIFF(SECOND, a.clock_in, IFNULL(NULLIF(a.clock_out, '0000-00-00 00:00:00'), NOW())) ASC"; break;
        case 'duration_desc': $order_sql = "TIMESTAMPDIFF(SECOND, a.clock_in, IFNULL(NULLIF(a.clock_out, '0000-00-00 00:00:00'), NOW())) DESC"; break;
        case 'date_asc':  $order_sql = "a.clock_in ASC"; break;
        case 'date_desc':
        default:          $order_sql = "a.clock_in DESC"; break;
    }
}

$page_size_allowed = [10, 20, 30, 50, 100, 'all'];
$sa_page_all_cap = 5000;
$sa_page_raw = isset($_GET['page_size']) ? strtolower(trim((string)$_GET['page_size'])) : '20';
if ($sa_page_raw === 'all') {
    $page_size_key = 'all';
    $page_size = $sa_page_all_cap;
} else {
    $page_size = (int)$sa_page_raw;
    $page_size_key = (string)$page_size;
    if (!in_array($page_size, [10, 20, 30, 50, 100], true)) {
        $page_size = 20;
        $page_size_key = '20';
    }
}
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}

if (!function_exists('sa_audit_url')) {
    function sa_audit_url(array $base, array $overrides = []): string {
        $qs = array_merge($base, $overrides);
        $qs = array_filter($qs, static function ($v) {
            return $v !== '' && $v !== null;
        });
        return '?' . http_build_query($qs);
    }
}

/** Build Staff Audit URL preserving filters; used by sortable column headers. */
$audit_sort_cols = [
    'staff'    => ['asc' => 'name_asc',     'desc' => 'name_desc',     'first' => 'name_asc'],
    'site'     => ['asc' => 'site_asc',     'desc' => 'site_desc',     'first' => 'site_asc'],
    'punches'  => ['asc' => 'date_asc',     'desc' => 'date_desc',     'first' => 'date_desc'],
    'duration' => ['asc' => 'duration_asc', 'desc' => 'duration_desc', 'first' => 'duration_desc'],
];
$audit_filter_qs = [
    'from_date'   => $from_date,
    'to_date'     => $to_date,
    'org_id'      => ($sa_is_super && $selected_org > 0) ? (string)$selected_org : '',
    'user_id'     => $employee_filter,
    'site_id'     => $site_filter,
    'status_type' => $status_type,
    'group_by'    => $group_by,
    'period_mode' => $period_mode,
    'page_size'   => $page_size_key,
    'sort'        => $sort_filter,
];
$audit_sort_meta = [];
foreach ($audit_sort_cols as $col_key => $pair) {
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
    $qs = array_merge($audit_filter_qs, ['sort' => $next, 'group_by' => 'none']);
    // Drop empty date params so URLs stay clean
    $qs = array_filter($qs, static function ($v) {
        return $v !== '' && $v !== null;
    });
    $is_active = ($is_asc || $is_desc) && ($group_by === 'none');
    $audit_sort_meta[$col_key] = [
        'href'   => '?' . http_build_query($qs),
        'arr'    => $is_active ? $arr : '',
        'active' => $is_active,
    ];
}

$rows = [];
$total_rows = 0;
$offset = 0;
$total_pages = 1;
$aggregated_total_hours = 0.0;
$group_totals_lookup = [];

try {
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    $from_join = "FROM attendance a
              LEFT JOIN users u ON a.user_id = u.id
              LEFT JOIN sites s ON COALESCE(
                  NULLIF(NULLIF(a.site_id, '0'), 'all'),
                  NULLIF(u.primary_site_id, 0)
              ) = s.Sid";

    $countStmt = $pdo->prepare("SELECT COUNT(*) $from_join $where_clause");
    $countStmt->execute($params);
    $total_rows = (int)$countStmt->fetchColumn();

    $total_pages = max(1, (int)ceil(($total_rows > 0 ? $total_rows : 1) / $page_size));
    if ($total_rows === 0) {
        $total_pages = 1;
        $page = 1;
    } elseif ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset = ($page - 1) * $page_size;

    // Never SELECT selfie LONGTEXT blobs here — they are served per-row via audit_selfie.php.
    $query = "SELECT a.id, a.user_id, a.site_id, a.status, a.clock_in, a.clock_out,
                     a.transfer_status, a.lat_in, a.lng_in, a.lat_out, a.lng_out,
                     a.flag_reason, a.is_flagged, a.Remarks AS remarks, a.manager_notes, a.payout_status,
                     u.Name as staff_name, u.Email as user_email, u.Tel_Mobile as mobile, u.org_id AS staff_org_id,
                     s.nick_name as site_name, s.target_lat, s.target_lng, s.geo_radius_meters
              $from_join
              $where_clause
              ORDER BY $order_sql
              LIMIT " . (int)$page_size . " OFFSET " . (int)$offset;

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $net_sec_sql = "GREATEST(0,
        TIMESTAMPDIFF(SECOND, a.clock_in, IFNULL(NULLIF(a.clock_out, '0000-00-00 00:00:00'), NOW()))
        - IF(TRIM(IFNULL(a.status,'0')) = '5', 0, IFNULL((
            SELECT SUM(TIMESTAMPDIFF(SECOND, b.break_start, b.break_end))
            FROM breaks b
            WHERE b.shift_id = a.id AND b.break_end IS NOT NULL AND TRIM(b.break_end) <> ''
          ), 0))
    )";
    $totStmt = $pdo->prepare("SELECT COALESCE(SUM($net_sec_sql), 0) $from_join $where_clause");
    $totStmt->execute($params);
    $total_accumulated_seconds = (int)$totStmt->fetchColumn();
    $aggregated_total_hours = round($total_accumulated_seconds / 3600, 2);

    if ($group_by !== 'none') {
        if ($group_by === 'date') {
            $gExpr = "DATE(a.clock_in)";
        } elseif ($group_by === 'staff') {
            $gExpr = "COALESCE(u.Name, 'Unknown Worker')";
        } else {
            $gExpr = "COALESCE(s.nick_name, 'Unassigned')";
        }
        $gStmt = $pdo->prepare("SELECT $gExpr AS g_key, COALESCE(SUM($net_sec_sql), 0) AS total_seconds $from_join $where_clause GROUP BY $gExpr");
        $gStmt->execute($params);
        foreach ($gStmt->fetchAll(PDO::FETCH_ASSOC) as $gr) {
            if ($group_by === 'date') {
                $gKey = date('l, M j, Y', strtotime((string)$gr['g_key'] . ' 12:00:00'));
            } else {
                $gKey = (string)$gr['g_key'];
            }
            $group_totals_lookup[$gKey] = (int)$gr['total_seconds'];
        }
    } 

    if (!empty($from_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) {
        [$pStart, $pEnd] = sa_period_bounds($from_date, $period_mode, $period_anchor);
    } else {
        [$pStart, $pEnd] = sa_period_bounds(date('Y-m-d'), $period_mode, $period_anchor);
    }

    $batchSQL = "SELECT user_id, SUM(TIMESTAMPDIFF(SECOND, clock_in, clock_out)) as total_seconds 
                 FROM attendance 
                 WHERE DATE(clock_in) BETWEEN ? AND ? AND status != '4'";
    if (att_has_deleted_at($pdo)) {
        $batchSQL .= " AND deleted_at IS NULL";
    }
    $batchParams = [$pStart, $pEnd];

    if ($sess_user_level > 10 && isset($_SESSION['org_id']) && $_SESSION['org_id'] > 0) {
        $batchSQL .= " AND org_id = ?";
        $batchParams[] = (int)$_SESSION['org_id'];
    } elseif (!empty($sa_is_super) && !empty($selected_org) && (int)$selected_org > 0) {
        $batchSQL .= " AND org_id = ?";
        $batchParams[] = (int)$selected_org;
    }
    $batchSQL .= " GROUP BY user_id";

    $periodBatch = $pdo->prepare($batchSQL);
    $periodBatch->execute($batchParams);
    $batchData = $periodBatch->fetchAll(PDO::FETCH_KEY_PAIR);

    if (!isset($sites) || !is_array($sites)) {
        if (!function_exists('sip_load_picker_sites')) {
            require_once __DIR__ . '/engine/site_status_lib.php';
        }
        $assigned_for_picker = (string)($_SESSION['assigned_site_ids'] ?? '');
        $sites = sip_load_picker_sites(
            $pdo,
            $sess_user_level,
            (int)($_SESSION['org_id'] ?? 0),
            $assigned_for_picker
        );
    }

    if (!isset($staff) || !is_array($staff)) {
        $staff_org = !empty($selected_org) ? (int)$selected_org : (($sess_user_level > 10) ? (int)($_SESSION['org_id'] ?? 0) : 0);
        $staff = sa_load_staff_options($pdo, $staff_org, is_array($site_ids) ? $site_ids : []);
    }

    $gemSettingsStmt = $pdo->prepare("SELECT setting_key, setting_value FROM Gem_Settings WHERE org_id = ?");
    $gemSettingsStmt->execute([$org_id]);
    $globalPolicyCache = $gemSettingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $siteOverridesCache = [];
    $siteSettingsStmt = $pdo->query("SELECT site_id, setting_key, setting_value FROM Site_Settings");
    while ($sRow = $siteSettingsStmt->fetch(PDO::FETCH_ASSOC)) {
        $siteOverridesCache[$sRow['site_id']][$sRow['setting_key']] = $sRow['setting_value'];
    }

} catch (Exception $e) { 
    $rows = [];
    die("Data Load Error: " . $e->getMessage()); 
}

$manual_org_mismatch = 0;
$warn_org = 0;
if (!empty($sa_is_super) && (int)$selected_org > 0) {
    $warn_org = (int)$selected_org;
} elseif (empty($sa_is_super)) {
    $warn_org = (int)($_SESSION['org_id'] ?? 0);
}
if ($warn_org > 0) {
    try {
        $mismatchStmt = $pdo->prepare(att_manual_mismatch_count_sql(att_has_deleted_at($pdo)));
        $mismatchStmt->execute([att_manual_remark(), $warn_org, $warn_org, $warn_org]);
        $manual_org_mismatch = (int)$mismatchStmt->fetchColumn();
    } catch (Throwable $e) {
        $manual_org_mismatch = 0;
    }
}

if (!function_exists('getDistance')) {
    function getDistance($lat1, $lng1, $lat2, $lng2) {
        if (empty($lat1) || empty($lng1) || empty($lat2) || empty($lng2)) return null;
        $lat1 = (float)$lat1; $lng1 = (float)$lng1;
        $lat2 = (float)$lat2; $lng2 = (float)$lng2;
        $earth_radius = 6371000; 
        $dLat = deg2rad($lat2 - $lat1); $dLon = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
        return $earth_radius * (2 * atan2(sqrt($a), sqrt(1-$a)));
    }
}

$showing_from = ($total_rows === 0) ? 0 : $offset + 1;
$showing_to = ($total_rows === 0) ? 0 : min($offset + count($rows), $total_rows);
if (!isset($total_pages)) {
    $total_pages = 1;
}

if (!function_exists('sa_render_pager')) {
    function sa_render_pager(int $page, int $total_pages, int $showing_from, int $showing_to, int $total_rows, $page_size, array $filter_qs, array $sizes): void {
        echo '<div class="sa-pager">';
        echo '<div class="sa-pager-meta">Showing ' . (int)$showing_from . '–' . (int)$showing_to . ' of ' . (int)$total_rows . '</div>';
        echo '<div class="sa-pager-size"><label>Per page</label><select onchange="saChangePageSize(this)" title="Rows per page">';
        foreach ($sizes as $sz) {
            $val = (string)$sz;
            $label = ($val === 'all') ? 'All' : $val;
            $sel = ((string)$page_size === $val) ? ' selected' : '';
            echo '<option value="' . htmlspecialchars($val) . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
        }
        echo '</select></div>';
        echo '<nav class="sa-pager-nav" aria-label="Staff audit pages">';
        if ($page > 1) {
            echo '<a class="sa-page-btn" href="' . htmlspecialchars(sa_audit_url($filter_qs, ['page' => $page - 1])) . '">Prev</a>';
        } else {
            echo '<span class="sa-page-btn is-disabled">Prev</span>';
        }
        $want = [1];
        for ($i = max(2, $page - 2); $i <= min($total_pages - 1, $page + 2); $i++) {
            $want[] = $i;
        }
        if ($total_pages > 1) {
            $want[] = $total_pages;
        }
        $want = array_values(array_unique($want));
        $prevN = 0;
        foreach ($want as $n) {
            if ($prevN && $n > $prevN + 1) {
                echo '<span class="sa-page-gap">…</span>';
            }
            if ($n === $page) {
                echo '<span class="sa-page-btn is-current">' . (int)$n . '</span>';
            } else {
                echo '<a class="sa-page-btn" href="' . htmlspecialchars(sa_audit_url($filter_qs, ['page' => $n])) . '">' . (int)$n . '</a>';
            }
            $prevN = $n;
        }
        if ($page < $total_pages) {
            echo '<a class="sa-page-btn" href="' . htmlspecialchars(sa_audit_url($filter_qs, ['page' => $page + 1])) . '">Next</a>';
        } else {
            echo '<span class="sa-page-btn is-disabled">Next</span>';
        }
        echo '</nav></div>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Audit Dashboard</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Dense Staff Audit layout — keep all features, less chrome */
        body.staff-audit-page {
            font-family: Inter, system-ui, sans-serif;
            background: #f1f5f9;
            margin: 0;
            padding-top: 88px;
            font-size: 11px;
            color: #0f172a;
            max-width: 100%;
            overflow-x: hidden;
            box-sizing: border-box;
        }
        body.staff-audit-page *, body.staff-audit-page *::before, body.staff-audit-page *::after { box-sizing: border-box; }
        .sa-page { max-width: 100%; padding: 0 12px 72px; }

        /* Two collapsible ribbons: Navigation + Filters/Actions */
        .sa-ribbons { display: flex; flex-direction: column; gap: 6px; margin: 0 0 10px; }
        .sa-ribbon {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }
        .sa-ribbon-inner {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 10px;
            padding: 6px 8px;
        }
        .sa-ribbon-toggle {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #475569;
            padding: 3px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 700;
            font-size: 10px;
            line-height: 1.2;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            flex: 0 0 auto;
            height: 26px;
        }
        .sa-ribbon-toggle:hover { background: #f1f5f9; color: #0f172a; }
        .sa-ribbon-toggle .sa-ribbon-chev {
            display: inline-block;
            font-size: 9px;
            transition: transform 0.15s ease;
            color: #64748b;
        }
        .sa-ribbon.is-collapsed .sa-ribbon-toggle .sa-ribbon-chev { transform: rotate(-90deg); }
        .sa-ribbon-title { white-space: nowrap; }
        .sa-ribbon-body {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 12px;
            flex: 1 1 auto;
            min-width: 0;
        }
        .sa-ribbon-sep {
            width: 1px;
            align-self: stretch;
            min-height: 22px;
            background: #e2e8f0;
            flex: 0 0 auto;
        }

        /* Help panel embedded in nav ribbon — hide stock bar; keep body + scripts */
        .sa-help-wrap { margin: 0; width: 100%; order: 99; flex: 1 1 100%; }
        .sa-help-wrap .pg-help-panel { margin: 0; }
        .sa-help-wrap .pg-help-bar { display: none !important; }
        .sa-help-wrap .pg-help-body {
            margin-top: 6px;
            padding: 10px 12px;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
            border-radius: 0 0 6px 6px;
            font-size: 12px;
        }

        .sa-ctx-btns { display: inline-flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .sa-ico-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            height: 26px;
            padding: 0 8px;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #334155;
            font-weight: 700;
            font-size: 11px;
            cursor: pointer;
            line-height: 1;
            white-space: nowrap;
        }
        .sa-ico-btn:hover { background: #f1f5f9; }
        .sa-ico-btn.about { background: #eff6ff; border-color: #bfdbfe; color: #1e40af; }
        .sa-ico-btn.about:hover { background: #dbeafe; }
        .sa-ico-btn.tour { background: #0f172a; border-color: #0f172a; color: #fff; }
        .sa-ico-btn.tour:hover { background: #1e293b; }
        .sa-ico-btn.ask { background: #fff; border-color: #93c5fd; color: #1d4ed8; }
        .sa-ico-btn.ask:hover { background: #eff6ff; }
        .sa-ico-btn i { font-size: 11px; }
        .sa-btn-lbl { font-size: 11px; }

        .sa-ribbon .nav-period-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 14px;
            align-items: center;
            margin: 0;
            padding: 0;
            background: transparent;
            box-shadow: none;
            border: none;
        }
        .sa-nav-group { display: flex; align-items: center; gap: 3px; }
        .sa-nav-label {
            font-weight: 700;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
            margin-right: 2px;
        }
        .sa-nav-btn {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #334155;
            padding: 3px 7px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 700;
            font-size: 10px;
            line-height: 1.3;
            height: 26px;
        }
        .sa-nav-btn:hover { background: #f1f5f9; }
        .sa-nav-btn.period { background: #f8fafc; border-color: #e2e8f0; color: #1e40af; }
        .sa-nav-value {
            min-width: 56px;
            text-align: center;
            font-weight: 700;
            font-size: 10px;
            color: #0f172a;
            background: transparent;
            border: none;
            padding: 3px 4px;
            cursor: pointer;
            border-radius: 3px;
            height: 26px;
            line-height: 1.4;
        }
        .sa-nav-value:hover { background: #f1f5f9; }
        .sa-nav-value.period { min-width: 100px; color: #1e40af; }

        .filter-section {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            flex: 1 1 auto;
            margin: 0;
            padding: 0;
            background: transparent;
            box-shadow: none;
            border: none;
        }
        .filter-section form {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 8px;
            align-items: center;
        }
        .filter-section label {
            display: block;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #64748b;
            margin: 0 0 1px;
            line-height: 1.1;
        }
        .filter-field { display: flex; flex-direction: column; justify-content: flex-end; min-height: 36px; }
        .filter-section select,
        .filter-section input[type="date"] {
            padding: 4px 6px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            background: #fff;
            font-size: 11px;
            font-weight: 600;
            color: #1e293b;
            height: 28px;
            max-width: 140px;
        }
        .filter-section select[name="status_type"] {
            border-color: #93c5fd;
            background: #f8fafc;
            color: #1e40af;
            max-width: 160px;
        }
        .btn-search {
            background: #2563eb;
            color: #fff;
            border: none;
            padding: 0 12px;
            height: 28px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 700;
            font-size: 11px;
            align-self: flex-end;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .summary-hours-pill {
            background: #eff6ff;
            color: #1e40af;
            padding: 4px 10px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 700;
            font-size: 11px;
            border: 1px solid #bfdbfe;
            height: 28px;
            white-space: nowrap;
            box-shadow: none;
        }
        .summary-hours-pill .sa-chip-label {
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #64748b;
        }
        .sa-toolbar-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
            margin-left: auto;
        }
        .sa-toolbar-actions .btn-green,
        .sa-toolbar-actions .btn-red,
        .sa-toolbar-actions .btn-blue,
        .sa-toolbar-actions .btn-muted {
            padding: 0 10px;
            height: 28px;
            font-size: 11px;
            border-radius: 4px;
            margin: 0;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-muted {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            font-weight: 700;
        }
        .btn-muted.active { background: #e0e7ff; border-color: #a5b4fc; color: #3730a3; }

        /* Collapsed ribbons: icon-only / single compact row; controls stay usable */
        .sa-ribbon.is-collapsed .sa-ribbon-inner { padding: 4px 6px; gap: 6px; }
        .sa-ribbon.is-collapsed .sa-btn-lbl,
        .sa-ribbon.is-collapsed .sa-nav-label,
        .sa-ribbon.is-collapsed .sa-chip-label,
        .sa-ribbon.is-collapsed .filter-field label { display: none; }
        .sa-ribbon.is-collapsed .sa-ribbon-title { display: none; }
        .sa-ribbon.is-collapsed .sa-ribbon-sep { display: none; }
        .sa-ribbon.is-collapsed .sa-ico-btn {
            width: 26px;
            padding: 0;
            justify-content: center;
        }
        .sa-ribbon.is-collapsed .filter-field { min-height: 0; }
        .sa-ribbon.is-collapsed .filter-section select,
        .sa-ribbon.is-collapsed .filter-section input[type="date"] {
            height: 26px;
            font-size: 10px;
            max-width: 118px;
            padding: 2px 4px;
        }
        .sa-ribbon.is-collapsed .btn-search,
        .sa-ribbon.is-collapsed .sa-toolbar-actions .btn-green,
        .sa-ribbon.is-collapsed .sa-toolbar-actions .btn-red,
        .sa-ribbon.is-collapsed .sa-toolbar-actions .btn-blue,
        .sa-ribbon.is-collapsed .sa-toolbar-actions .btn-muted {
            width: 28px;
            padding: 0;
            justify-content: center;
            height: 26px;
        }
        .sa-ribbon.is-collapsed .summary-hours-pill { padding: 2px 8px; height: 26px; gap: 4px; }
        .sa-ribbon.is-collapsed .sa-nav-value { min-width: 48px; font-size: 9px; }
        .sa-ribbon.is-collapsed .sa-nav-value.period { min-width: 84px; }
        .sa-ribbon.is-collapsed .sa-help-wrap .pg-help-body[hidden],
        .sa-ribbon.is-collapsed .sa-help-wrap .pg-help-body { display: none !important; }

        .org-mismatch-warn { background:#fff7ed; border:1px solid #fdba74; color:#9a3412; padding:10px 12px; border-radius:8px; margin:0 0 12px 0; font-weight:700; font-size:12px; }
        .container {
            position: relative;
            background: #fff;
            margin: 0;
            padding: 0;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            margin-bottom: 24px;
            max-width: 100%;
            overflow: hidden;
        }
        .audit-table-wrap {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
        }
        #auditTable {
            width: 100%;
            min-width: 920px;
            border-collapse: separate;
            border-spacing: 0;
        }
        #auditTable th {
            background: #0f172a;
            color: #e2e8f0;
            padding: 6px 8px;
            text-align: left;
            text-transform: uppercase;
            font-size: 9px;
            letter-spacing: 0.03em;
            font-weight: 700;
            position: sticky;
            top: 0;
            z-index: 5;
            border-bottom: 1px solid #1e293b;
            white-space: nowrap;
        }
        #auditTable th.sortable { cursor: pointer; user-select: none; }
        #auditTable th.sortable a { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 2px; }
        #auditTable th.sortable:hover { background: #1e293b; }
        #auditTable th.sortable.active { color: #93c5fd; }
        #auditTable th.sortable .arr { font-size: 9px; opacity: 0.95; letter-spacing: -1px; }
        #auditTable td {
            padding: 6px 8px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
            background: #fff;
        }
        #auditTable tbody tr:hover td { background: #f8fafc; }
        #auditTable tbody tr:hover td.col-action,
        #auditTable tbody tr:hover td.col-check { background: #f8fafc; }

        /* Sticky first cols + Action */
        #auditTable th.col-check,
        #auditTable td.col-check {
            position: sticky;
            left: 0;
            z-index: 4;
            width: 28px;
            min-width: 28px;
            background: #fff;
            box-shadow: 1px 0 0 #e2e8f0;
        }
        #auditTable th.col-check { z-index: 6; background: #0f172a; }
        #auditTable th.col-action,
        #auditTable td.col-action {
            position: sticky;
            right: 0;
            z-index: 4;
            background: #fff;
            box-shadow: -2px 0 6px rgba(15, 23, 42, 0.08);
            white-space: nowrap;
            text-align: center;
            min-width: 88px;
        }
        #auditTable th.col-action { z-index: 6; background: #0f172a; box-shadow: -2px 0 6px rgba(0,0,0,0.25); }

        /* Selfies / GPS: hide whole columns when toggled off */
        body.hide-selfies #auditTable .col-selfie { display: none; }
        body.hide-gps #auditTable .col-gps { display: none; }
        #auditTable th.col-reason,
        #auditTable td.col-reason {
            min-width: 72px;
            max-width: 220px;
            width: 88px;
            vertical-align: top;
            font-size: 12px;
            line-height: 1.35;
        }
        .reason-details { margin: 0; }
        .reason-details summary {
            cursor: pointer;
            color: #2563eb;
            font-weight: 700;
            font-size: 11px;
            list-style: none;
            user-select: none;
        }
        .reason-details summary::-webkit-details-marker { display: none; }
        .reason-details summary:hover { text-decoration: underline; }
        .reason-details[open] summary { margin-bottom: 6px; }
        .reason-box {
            background: #fef2f2;
            color: #7f1d1d;
            padding: 6px 8px;
            border-radius: 4px;
            border-left: 3px solid #dc2626;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .reason-box.kind-empty {
            background: #fff7ed;
            color: #9a3412;
            border-left-color: #f97316;
            font-style: italic;
        }
        .reason-none {
            color: #94a3b8;
            font-style: italic;
            font-size: 11px;
        }
        .selfie-thumb {
            width: 28px;
            height: 28px;
            object-fit: cover;
            border-radius: 3px;
            border: 1px solid #e2e8f0;
            cursor: zoom-in;
        }
        .selfie-pair { display: flex; gap: 3px; }
        .selfie-missing {
            width: 28px;
            height: 28px;
            border-radius: 3px;
            background: #f1f5f9;
            border: 1px dashed #cbd5e1;
            color: #94a3b8;
            font-size: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sa-pager {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px 14px;
            padding: 8px 10px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            margin: 0 0 8px;
        }
        .sa-pager-bottom { margin: 8px 0 0; }
        .sa-pager-meta { font-weight: 700; color: #334155; font-size: 11px; }
        .sa-pager-size { display: inline-flex; align-items: center; gap: 6px; }
        .sa-pager-size label { font-size: 9px; font-weight: 700; text-transform: uppercase; color: #64748b; }
        .sa-pager-size select {
            height: 26px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            padding: 0 6px;
            background: #fff;
        }
        .sa-pager-nav { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 4px; margin-left: auto; }
        .sa-page-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 26px;
            padding: 0 8px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            background: #fff;
            color: #1e40af;
            font-weight: 700;
            font-size: 11px;
            text-decoration: none;
        }
        .sa-page-btn.is-current { background: #2563eb; border-color: #2563eb; color: #fff; }
        .sa-page-btn.is-disabled { color: #94a3b8; background: #f8fafc; cursor: default; }
        .sa-page-gap { color: #94a3b8; padding: 0 2px; }

        .staff-cell { max-width: 160px; }
        .staff-link {
            text-decoration: none;
            color: #1d4ed8;
            font-weight: 700;
            font-size: 12px;
            line-height: 1.25;
            display: block;
        }
        .staff-meta {
            font-size: 9px;
            color: #94a3b8;
            line-height: 1.3;
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 150px;
        }
        .staff-cell:hover .staff-meta,
        .staff-cell:focus-within .staff-meta {
            white-space: normal;
            overflow: visible;
            color: #64748b;
        }
        .email-link { color: #64748b; text-decoration: none; }
        .email-link:hover { color: #2563eb; text-decoration: underline; }
        .action-icons {
            margin-top: 4px;
            display: flex;
            gap: 8px;
            font-size: 12px;
            align-items: center;
        }
        .mpe-row-link {
            font-size: 10px;
            font-weight: 800;
            color: #15803d;
            text-decoration: none;
            white-space: nowrap;
        }
        .mpe-row-link:hover { text-decoration: underline; color: #166534; }

        .btn-green {
            background: #16a34a;
            color: #fff;
            border: none;
            padding: 4px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 700;
            text-decoration: none;
            display: inline-block;
            font-size: 11px;
        }
        .btn-red {
            background: #dc2626;
            color: #fff;
            border: none;
            padding: 4px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 700;
            margin-left: 0;
        }
        .btn-finalize {
            padding: 4px 10px !important;
            font-size: 11px !important;
            white-space: nowrap;
        }
        .badge-transfer, .badge-normal, .badge-error {
            color: #fff;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 8px;
            font-weight: 700;
            display: inline-block;
            text-align: center;
            text-transform: uppercase;
        }
        .badge-transfer { background: #d97706; }
        .badge-normal { background: #475569; }
        .badge-error { background: #ef4444; }

        .status-indicator-badge {
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 8px;
            font-weight: 700;
            display: inline-block;
            margin-top: 3px;
            text-transform: uppercase;
        }
        .status-payout-pending { background: #fef3c7; color: #b45309; border: 1px solid #fcd34d; }
        .status-payout-finalized { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }

        .time-box {
            display: inline-block;
            min-width: 96px;
            width: auto;
            font-size: 10px;
            padding: 1px 4px;
            border: 1px solid #e2e8f0;
            border-radius: 3px;
            text-align: center;
            background: #f8fafc;
            color: #334155;
            font-weight: 500;
        }
        .gps-cell { white-space: nowrap; }
        .gps-line { display: flex; align-items: center; gap: 4px; margin-top: 3px; }
        .gps-line:first-child { margin-top: 0; }
        .gps-io { font-size: 9px; font-weight: 800; color: #64748b; min-width: 22px; }
        .gps-ico {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 4px;
            font-size: 11px;
            text-decoration: none;
            border: 1px solid transparent;
            cursor: help;
        }
        .gps-ico.map { color: #2563eb; background: #eff6ff; border-color: #bfdbfe; cursor: pointer; }
        .gps-ico.ok { color: #15803d; background: #f0fdf4; border-color: #bbf7d0; }
        .gps-ico.warn { color: #c2410c; background: #fff7ed; border-color: #fed7aa; }
        .gps-ico.bad { color: #b91c1c; background: #fef2f2; border-color: #fecaca; }
        .gps-ico.muted { color: #64748b; background: #f8fafc; border-color: #e2e8f0; }
        .gps-dist { font-size: 9px; color: #94a3b8; margin-left: 2px; }

        .group-header { background: #f1f5f9 !important; font-weight: bold; color: #0f172a; border-top: 1px solid #e2e8f0; height: 32px; cursor: pointer; user-select: none; }
        .group-header:hover { background: #e2e8f0 !important; }
        .group-header td { padding: 6px 8px; font-size: 11px; font-weight: 700; color: #1e293b; }
        .toggle-icon { margin-right: 6px; transition: transform 0.15s ease; display: inline-block; color: #64748b; }
        .collapsed-group .toggle-icon { transform: rotate(-90deg); }
        .hidden-row { display: none !important; }
        .group-badge-hours {
            background: #2563eb;
            color: #fff;
            padding: 1px 7px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            float: right;
            margin-top: 1px;
        }
        .sticky-copy-box { display: inline-block; }
        .btn-copy-loading { background: #cbd5e1 !important; color: #94a3b8 !important; cursor: not-allowed !important; pointer-events: none !important; opacity: 0.6; }
        .btn-copy-loading i { animation: spin 1s linear infinite; }

        .break-editor-modal { display: none; position: fixed; z-index: 999999; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.55); backdrop-filter: blur(5px); align-items: center; justify-content: center; }
        .modal-content-card { background: white; width: 100%; max-width: 520px; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); border: 1px solid #cbd5e1; padding: 24px; animation: modalSlide 0.2s ease-out; }
        .break-input-row { display: flex; gap: 8px; align-items: center; margin-bottom: 10px; background: #f8fafc; padding: 8px; border-radius: 8px; border: 1px solid #e2e8f0; }
        .modal-btn-save { background: #2563eb; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; }
        .modal-btn-cancel { background: #e2e8f0; color: #475569; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; margin-right: 8px; }
        .btn-add-row { background: #10b981; color: white; border: none; padding: 6px 12px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 11px; margin-bottom: 15px; }
        .icon-action-trigger { cursor: pointer; transition: color 0.1s ease; }
        .icon-action-trigger:hover { color: #2563eb !important; }
        #status-msg-alert { position: fixed; top: 100px; right: 20px; padding: 10px 16px; border-radius: 6px; display: none; z-index: 999999; color: white; font-weight: bold; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        @keyframes modalSlide { from { transform: translateY(15px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* Ask AI FAB: left side so it never covers sticky Finalize */
        body.staff-audit-page .pg-ask-fab {
            left: 16px;
            right: auto;
            bottom: 16px;
            padding: 10px 14px;
            font-size: 12px;
            z-index: 10050;
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.28);
        }
        body.staff-audit-page .pg-ask-panel {
            left: 16px;
            right: auto;
        }
        @media (max-width: 900px) {
            .sa-toolbar-actions { margin-left: 0; width: 100%; }
            .filter-section select, .filter-section input[type="date"] { max-width: 120px; }
            #auditTable { min-width: 860px; }
            .sa-ribbon-sep { display: none; }
        }
        @media print {
            body.staff-audit-page { padding-top: 0; }
            .sa-ribbons, .sa-help-wrap, .pg-ask-fab, .pg-ask-panel { display: none !important; }
            .container { border: none; box-shadow: none; }
            #auditTable th.col-action, #auditTable td.col-action,
            #auditTable th.col-check, #auditTable td.col-check { position: static; box-shadow: none; }
        }
    </style>
</head>
<body class="staff-audit-page">

    <?php 
    if (function_exists('generateMenu')) echo generateMenu($pdo); 
    session_write_close();
    ?>

    <div id="status-msg-alert"></div>

    <div class="sa-page">

    <div id="imgModal" style="display: none; position: fixed; z-index: 99999; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.85); align-items: center; justify-content: center; cursor: zoom-out;">
        <img id="modalImg" src="" style="max-width: 90vw; max-height: 85vh; border: 4px solid white; border-radius: 8px; box-shadow: 0 20px 40px rgba(0,0,0,0.6);">
    </div>

    <div id="breakModal" class="break-editor-modal">
        <div class="modal-content-card">
            <h5 style="margin-top:0; font-weight:800; color:#1e293b; font-size:14px; margin-bottom:6px;"><i class="fas fa-clock" style="color:#2563eb;"></i> Adjust Shift Break Windows</h5>
            <p style="color:#64748b; font-size:11px; margin-bottom:15px;">Modify existing timestamps or append supplementary rest intervals below. Leave end-times open if active.</p>
            <form id="breakForm" method="POST" action="staff_audit.php">
                <input type="hidden" name="action" value="update_breaks">
                <input type="hidden" name="shift_id" id="modalShiftId">
                <div id="modalBreakContainer"></div>
                <button type="button" class="btn-add-row" onclick="addBlankBreakRow()"><i class="fas fa-plus"></i> Add New Break Row</button>
                <div style="text-align:right;">
                    <button type="button" class="modal-btn-cancel" onclick="closeBreakModal()">Cancel</button>
                    <button type="submit" class="modal-btn-save">Save Adjustments</button>
                </div>
            </form>
        </div>
    </div>

    <div id="bulkFinalizerModal" class="break-editor-modal">
        <div class="modal-content-card">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #cbd5e1; padding-bottom: 8px; margin-bottom: 15px;">
                <h5 style="margin:0; font-weight:800; color:#0f172a; font-size:14px;"><i class="fas fa-network-wired" style="color:#2563eb; margin-right: 4px;"></i> Bulk Pay Allocation Desk Workspace</h5>
                <button type="button" onclick="closeAllocationModal()" style="font-size:20px; font-weight:bold; cursor:pointer; background:none; border:none; color:#64748b;">├Ч</button>
            </div>
            <p style="color:#64748b; font-size:11px; margin-bottom:20px; margin-top:0;">Configure split parameters, issue lump-sum bonuses, and securely process batch records formulas.</p>
            
            <form method="POST" action="bulk_allocation_desk.php">
                <div class="input-group" style="margin-bottom: 15px;">
                    <label style="display:block; font-weight:800; font-size:10px; text-transform:uppercase; color:#1e40af; margin-bottom:6px;"><i class="fas fa-balance-scale" style="margin-right:3px;"></i> Overtime Policy</label>
                    <select name="ot_mode" id="otModeSelector" onchange="toggleOtModeLayers(this.value)" style="width:100%; padding:8px; border-radius:6px; border:1px solid #93c5fd; background:#eff6ff; font-weight:600; font-size:11px; height:36px;">
                        <option value="per_shift" selected>Per-shift daily cap (current method) &mdash; split each shift at the cap below</option>
                        <option value="site_policy">Work-site policy &mdash; ON: weekly 44h &middot; SK: daily 8h + weekly 40h (set in Edit Site)</option>
                        <option value="weekly_44">Weekly 44 hours (Ontario default) &mdash; weekly only</option>
                        <option value="weekly_40">Weekly 40 hours (more generous policy) &mdash; weekly only</option>
                        <option value="daily8_weekly_44">Daily 8h + Weekly 44h &mdash; combined, no double counting</option>
                        <option value="daily8_weekly_40">Daily 8h + Weekly 40h (Saskatchewan default) &mdash; combined, no double counting</option>
                    </select>
                    <div id="otModeExplainer" style="font-size:10px; color:#64748b; margin-top:5px; line-height:1.5;"></div>
                </div>

                <div class="input-group" id="formulaInputWrapper" style="margin-bottom: 15px;">
                    <label style="display:block; font-weight:800; font-size:10px; text-transform:uppercase; color:#475569; margin-bottom:6px;">Select Target Action Formula</label>
                    <select name="target_action_formula" id="formulaDropdownSelector" onchange="toggleBonusInputLayer(this.value)" style="width:100%; padding:8px; border-radius:6px; border:1px solid #cbd5e1; font-weight:600; font-size:11px; height:36px;">
                        <option value="overtime">Overtime after Hrs in A</option>
                        <option value="transit">Transit Time</option>
                        <option value="bonus">Bonus</option>
                        <option value="balance" selected>Balance after Hrs in A</option>
                    </select>
                </div>
                
                <div class="input-group" id="dailyCapInputWrapper" style="margin-bottom: 15px;">
                    <label style="display:block; font-weight:800; font-size:10px; text-transform:uppercase; color:#475569; margin-bottom:6px;">Regular Hours Cap Split Trigger Limit</label>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <?php
                        $ba_org = (isset($selected_org) && (int)$selected_org > 0) ? (int)$selected_org : (int)($org_id ?? 0);
                        $ba_cap = function_exists('sip_org_bulk_hours_cap') ? sip_org_bulk_hours_cap($pdo, $ba_org) : 12;
                        ?>
                        <input type="number" step="0.01" name="regular_hours_limit" value="<?= htmlspecialchars(number_format((float)$ba_cap, 2, '.', '')) ?>" style="width:100px; text-align:center; padding:6px; border:1px solid #cbd5e1; border-radius:6px; font-weight:600; height:34px;">
                        <span style="font-weight:700; color:#64748b; font-size:11px;">Hours from Org Settings (change here for this run only)</span>
                    </div>
                </div>
                
                <div class="input-group" id="bonusLumpSumInputWrapper" style="display:none; margin-bottom: 15px;">
                    <label style="display:block; font-weight:800; font-size:10px; text-transform:uppercase; color:#10b981; margin-bottom:6px;">Specify Custom Flat Cash Bonus Override Amount ($)</label>
                    <input type="number" step="0.01" name="bonus_lump_sum" value="0.00" style="width:140px; padding:6px; border:1px solid #10b981; border-radius:6px; color:#15803d; font-weight:600; height:34px;">
                </div>
                
                <div style="text-align:right; margin-top:25px; border-top:1px solid #cbd5e1; padding-top:15px;">
                    <button type="button" class="modal-btn-cancel" onclick="closeAllocationModal()">Cancel</button>
                    <button type="submit" name="action" value="execute_bulk_pay_matrix" class="modal-btn-save" style="background:#2563eb;"><i class="fas fa-bolt" style="margin-right:4px;"></i> Execute Pay Matrix Process</button>
                </div>
                <div id="modal_hidden_ids_container"></div>
            </form>
        </div>
    </div>
<!-- Two collapsible ribbons: Navigation/context + Filters/actions -->
    <div class="sa-ribbons">
        <div class="sa-ribbon sa-ribbon-nav" id="saRibbonNav" data-sa-key="nav">
            <div class="sa-ribbon-inner">
                <button type="button" class="sa-ribbon-toggle" aria-expanded="true" aria-controls="saRibbonNavBody" title="Collapse or expand navigation" onclick="saToggleRibbon('nav')">
                    <i class="fas fa-chevron-down sa-ribbon-chev" aria-hidden="true"></i>
                    <span class="sa-ribbon-title">Navigation</span>
                </button>
                <div class="sa-ribbon-body" id="saRibbonNavBody">
                    <div class="sa-ctx-btns">
                        <button type="button" class="sa-ico-btn about" onclick="saOpenAbout()" title="About this page"><i class="fas fa-info-circle" aria-hidden="true"></i><span class="sa-btn-lbl">About</span></button>
                        <button type="button" class="sa-ico-btn tour" onclick="saStartTour()" title="Start guided tour"><i class="fas fa-play" aria-hidden="true"></i><span class="sa-btn-lbl">Start Tour</span></button>
                        <button type="button" class="sa-ico-btn ask" onclick="window.pgAskOpen&&window.pgAskOpen()" title="Ask AI"><i class="fas fa-star" aria-hidden="true"></i><span class="sa-btn-lbl">Ask AI</span></button>
                    </div>
                    <span class="sa-ribbon-sep" aria-hidden="true"></span>
                    <div class="nav-period-bar">
                        <div class="sa-nav-group">
                            <span class="sa-nav-label"><i class="fas fa-calendar-day"></i> Day</span>
                            <button type="button" class="sa-nav-btn" onclick="navigateDay('prev')" title="Previous day"><i class="fas fa-chevron-left"></i></button>
                            <button type="button" id="pgDayLabel" class="sa-nav-value" onclick="navigateDay('today')" title="Go to today">Today</button>
                            <button type="button" class="sa-nav-btn" onclick="navigateDay('next')" title="Next day"><i class="fas fa-chevron-right"></i></button>
                        </div>
                        <div class="sa-nav-group">
                            <span class="sa-nav-label"><i class="fas fa-calendar-week"></i> Pay Period</span>
                            <button type="button" class="sa-nav-btn period" onclick="navigatePayPeriod('prev')" title="Previous pay period"><i class="fas fa-chevron-left"></i></button>
                            <button type="button" id="pgPeriodLabel" class="sa-nav-value period" onclick="navigatePayPeriod('current')" title="Current pay period">Current</button>
                            <button type="button" class="sa-nav-btn period" onclick="navigatePayPeriod('next')" title="Next pay period"><i class="fas fa-chevron-right"></i></button>
                        </div>
                    </div>
                </div>
                <div class="sa-help-wrap"><?php require_once __DIR__.'/payroll_help.php'; echo payroll_help_panel('staff_audit.php'); ?></div>
            </div>
        </div>

        <div class="sa-ribbon sa-ribbon-filters" id="saRibbonFilters" data-sa-key="filters">
            <div class="sa-ribbon-inner">
                <button type="button" class="sa-ribbon-toggle" aria-expanded="true" aria-controls="saRibbonFiltersBody" title="Collapse or expand filters" onclick="saToggleRibbon('filters')">
                    <i class="fas fa-chevron-down sa-ribbon-chev" aria-hidden="true"></i>
                    <span class="sa-ribbon-title">Filters</span>
                </button>
                <div class="sa-ribbon-body" id="saRibbonFiltersBody">
                    <div class="filter-section">
                        <form method="GET" class="filter-form">
                            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort_filter) ?>">
                            <div class="filter-field"><label>From</label><input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>" title="From date"></div>
                            <div class="filter-field"><label>To</label><input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>" title="To date"></div>
                            <div class="filter-field"><label>Pay cycle</label>
                                <select name="period_mode" title="Pay period cycle for period navigation">
                                    <option value="semi_monthly" <?= $period_mode === 'semi_monthly' ? 'selected' : '' ?>>Semi-monthly</option>
                                    <option value="bi_weekly" <?= $period_mode === 'bi_weekly' ? 'selected' : '' ?>>Bi-weekly</option>
                                    <option value="monthly" <?= $period_mode === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                </select>
                            </div>
                            <?php if (!empty($sa_is_super)): ?>
                            <div class="filter-field"><label>Org</label>
                                <select name="org_id" title="Organization" onchange="this.form.submit()">
                                    <option value="all" <?= (int)$selected_org === 0 ? 'selected' : '' ?>>All organizations</option>
                                    <?php foreach ($sa_orgs as $o): ?>
                                        <option value="<?= (int)$o['id'] ?>" <?= (int)$selected_org === (int)$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$o['org_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="filter-field"><label>Restaurant</label>
                                <select name="site_id" title="Restaurant" onchange="this.form.submit()">
                                    <option value="all" <?= empty($site_ids) ? 'selected' : '' ?>>All Sites</option>
                                    <?php if (!empty($default_pair_ids)):
                                        $pair_csv = implode(',', $default_pair_ids);
                                        $pair_sel = ((string)$site_filter === $pair_csv) ? 'selected' : '';
                                        $pair_label = 'Starter (' . htmlspecialchars(implode(' + ', $default_pair_names)) . ') — 2 of ' . count($sites);
                                        echo "<option value='" . htmlspecialchars($pair_csv) . "' {$pair_sel}>{$pair_label}</option>";
                                    endif; ?>
                                    <?php foreach($sites as $s):
                                        $resolved_sid = isset($s['Sid']) ? $s['Sid'] : ($s['id'] ?? 0);
                                        $selected = (count($site_ids) === 1 && (string)$site_ids[0] === (string)$resolved_sid) ? 'selected' : '';
                                        echo "<option value='{$resolved_sid}' {$selected}>" . htmlspecialchars($s['nick_name'] ?? 'Unnamed Site') . "</option>";
                                    endforeach; ?>
                                </select>
                                <?php if (!empty($site_ids) && is_array($sites) && count($sites) > count($site_ids)): ?>
                                    <div class="small text-muted" style="margin-top:4px;">
                                        Showing <?= count($site_ids) ?> of <?= count($sites) ?> sites<?= !empty($default_pair_names) && $starter_sites ? ' (' . htmlspecialchars(implode(' + ', $default_pair_names)) . ')' : '' ?>
                                        — choose more, or All Sites.
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="filter-field"><label>Staff</label>
                                <select name="user_id" title="Staff of the selected restaurant">
                                    <option value="all">All Staff</option>
                                    <?php foreach($staff as $u):
                                        $selected = ((string)$employee_filter === (string)$u['user'] || (string)$employee_filter === (string)$u['id']) ? 'selected' : '';
                                        $display_pill = htmlspecialchars($u['Name']) . " (" . htmlspecialchars($u['user'] ?? $u['id']) . ")";
                                        echo "<option value='" . htmlspecialchars($u['user'] ?? $u['id']) . "' {$selected}>{$display_pill}</option>";
                                    endforeach; ?>
                                </select>
                                <?php if (!empty($site_ids)): ?>
                                    <div class="small text-muted" style="margin-top:4px;">Employees of this restaurant only (<?= count($staff) ?>)</div>
                                <?php endif; ?>
                            </div>
                            <div class="filter-field"><label><i class="fas fa-filter"></i> Status</label>
                                <select name="status_type" title="Status">
                                    <option value="pending" <?= $status_type === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="finalized" <?= $status_type === 'finalized' ? 'selected' : '' ?>>Finalized</option>
                                    <option value="all" <?= $status_type === 'all' ? 'selected' : '' ?>>All</option>
                                </select>
                            </div>
                            <div class="filter-field"><label>Group</label>
                                <select name="group_by" title="Group by">
                                    <option value="none" <?= $group_by==='none'?'selected':'' ?>>None</option>
                                    <option value="date" <?= $group_by==='date'?'selected':'' ?>>Date</option>
                                    <option value="staff" <?= $group_by==='staff'?'selected':'' ?>>Staff</option>
                                </select>
                            </div>
                            <div class="filter-field"><label>Per page</label>
                                <select name="page_size" title="Rows per page">
                                    <?php foreach ($page_size_allowed as $sz): ?>
                                        <?php $sa_sz = (string)$sz; $sa_lbl = ($sa_sz === 'all') ? 'All' : $sa_sz; ?>
                                        <option value="<?= htmlspecialchars($sa_sz) ?>" <?= ((string)$page_size_key === $sa_sz) ? 'selected' : '' ?>><?= htmlspecialchars($sa_lbl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn-search" title="Search"><i class="fas fa-search"></i><span class="sa-btn-lbl"> Search</span></button>
                        </form>

                        <div class="summary-hours-pill" title="Filtered subset work duration">
                            <i class="fas fa-stopwatch"></i>
                            <span class="sa-chip-label">Duration</span>
                            <span><?= $aggregated_total_hours ?>h</span>
                        </div>
                    </div>

                    <div class="sa-toolbar-actions">
                        <a href="staff_audit_exceptions.php?org_id=<?= htmlspecialchars((string)(($sa_is_super && $selected_org > 0) ? $selected_org : ($selected_org ?: "all"))) ?>&amp;from_date=<?= htmlspecialchars((string)$from_date) ?>&amp;to_date=<?= htmlspecialchars((string)$to_date) ?>&amp;site_id=all" class="btn-blue" style="background:#be123c;color:#fff;text-decoration:none;" title="Same-start duplicates and hours over 13"><i class="fas fa-clone"></i><span class="sa-btn-lbl"> Dup / over 13h</span></a>
                        <?php if (!empty($sa_show_aa_labor)): ?>
                        <a href="aa_labor_hours_import.php" class="btn-blue" style="background:#0f766e;color:#fff;text-decoration:none;" title="Upload AA Labor Hours Detail.xlsx as-is"><i class="fas fa-file-excel"></i><span class="sa-btn-lbl"> AA Labor Hours</span></a>
                        <?php endif; ?>
                        <button type="button" id="toggleSelfiesBtn" class="btn-muted active" onclick="toggleSelfiesCol()" title="Hide selfie photos" aria-pressed="true"><i class="fas fa-camera"></i><span class="sa-btn-lbl"> Hide Selfies</span></button>
                        <button type="button" id="toggleGpsBtn" class="btn-muted active" onclick="toggleGpsCol()" title="Hide punch-in and punch-out map pins" aria-pressed="true"><i class="fas fa-map-marker-alt"></i><span class="sa-btn-lbl"> Hide GPS</span></button>
                        <button type="button" onclick="window.print()" class="btn-blue" style="background:#0284c7; color:white; border:none; cursor:pointer; font-weight:bold;" title="Print">
                            <i class="fas fa-print"></i><span class="sa-btn-lbl"> Print</span>
                        </button>
                        <button type="button" onclick="exportToCSV()" class="btn-green" style="background:#0d9488;" title="Export CSV">
                            <i class="fas fa-file-excel"></i><span class="sa-btn-lbl"> CSV</span>
                        </button>
                        <button type="button" onclick="bulkFinalize()" class="btn-green" title="Finalize selected shifts"><i class="fas fa-check"></i><span class="sa-btn-lbl"> Finalize Selected</span></button>
                        <button type="button" onclick="bulkDelete()" class="btn-red" title="Delete selected"><i class="fas fa-trash-alt"></i><span class="sa-btn-lbl"> Delete</span></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
    <?php if (!empty($manual_org_mismatch)): ?>
        <div class="org-mismatch-warn" role="status"><?= (int)$manual_org_mismatch ?> manual shift<?= ((int)$manual_org_mismatch === 1) ? '' : 's' ?> for this organization's employees or sites are stored under a different organization, so this filter does not list them.</div>
    <?php endif; ?>
    <?php sa_render_pager($page, $total_pages, $showing_from, $showing_to, $total_rows, $page_size_key, $audit_filter_qs, $page_size_allowed); ?>
    <div class="audit-table-wrap">
    <table id="auditTable">
        <thead>
            <tr>
                <th class="col-check" width="28"><input type="checkbox" id="masterCheck"></th>
                <th class="col-selfie">Selfies</th>
                <th class="sortable<?= !empty($audit_sort_meta['staff']['active']) ? ' active' : '' ?>">
                    <a href="<?= htmlspecialchars($audit_sort_meta['staff']['href']) ?>" title="Sort by staff member">Staff<span class="arr"><?= htmlspecialchars($audit_sort_meta['staff']['arr']) ?></span></a>
                </th>
                <th class="sortable<?= !empty($audit_sort_meta['site']['active']) ? ' active' : '' ?>">
                    <a href="<?= htmlspecialchars($audit_sort_meta['site']['href']) ?>" title="Sort by site">Site<span class="arr"><?= htmlspecialchars($audit_sort_meta['site']['arr']) ?></span></a>
                </th>
                <th class="sortable<?= !empty($audit_sort_meta['punches']['active']) ? ' active' : '' ?>">
                    <a href="<?= htmlspecialchars($audit_sort_meta['punches']['href']) ?>" title="Sort by punch time">Punches<span class="arr"><?= htmlspecialchars($audit_sort_meta['punches']['arr']) ?></span></a>
                </th>
                <th class="col-reason">Reason</th>
                <th>Breaks</th>
                <th class="sortable<?= !empty($audit_sort_meta['duration']['active']) ? ' active' : '' ?>">
                    <a href="<?= htmlspecialchars($audit_sort_meta['duration']['href']) ?>" title="Sort by duration">Duration<span class="arr"><?= htmlspecialchars($audit_sort_meta['duration']['arr']) ?></span></a>
                </th>
                <th class="col-gps" width="90">
                    GPS
                    <div class="sticky-copy-box">
                        <button type="button" id="globalCopyBtn" class="btn-copy-loading" style="font-weight:bold; font-size:9px; padding:3px 6px; border-radius:4px; border:none; background:#475569; color:white;">
                            <i class="fas fa-spinner"></i> …
                        </button>
                    </div>
                </th>
                <th class="col-action">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $last_group = null;
            $group_counter = 0;
            if (!empty($rows)):
                foreach ($rows as $r): 
                    $status_val = trim((string)($r['status'] ?? '0'));
                    
                    $is_transit = ($status_val === '5' || $status_val === 'travel' || $status_val === 'transfer-out');
                    $is_split   = ($status_val === '6');

                    $display_name = !empty($r['staff_name']) ? (string)$r['staff_name'] : "Unknown Worker";
                    $current_group = ($group_by == 'date') ? date('l, M j, Y', strtotime($r['clock_in'] ?? 'now')) : ($group_by == 'staff' ? $display_name : ($r['site_name'] ?? 'Unassigned'));
                    
                    if ($group_by !== 'none' && $current_group !== $last_group): 
                        $group_counter++;
                        $group_id = "group-bucket-" . $group_counter;
                        
                        $sub_seconds = $group_totals_lookup[$current_group] ?? 0;
                        $sub_hours = round($sub_seconds / 3600, 2);
                    ?>
                        <tr class="group-header" onclick="toggleGroupAccordion('<?= $group_id ?>', this)">
                            <td colspan="10">
                                <span class="group-badge-hours"><i class="fas fa-business-time"></i> Total: <?= $sub_hours ?> Hours</span>
                                <span class="toggle-icon"><i class="fas fa-chevron-down"></i></span>
                                <i class="fas fa-folder-open" style="color:#2563eb; margin-right:4px;"></i> <?= htmlspecialchars((string)$current_group) ?>
                            </td>
                        </tr>
                    <?php $last_group = $current_group; endif;

                    $in_time_str = $r['clock_in'] ?? '';
                    $out_time_str = $r['clock_out'] ?? '';
                    $in_ts = !empty($in_time_str) ? strtotime($in_time_str) : time();
                    $raw_transfer_status = trim($r['transfer_status'] ?? '');
                    $transfer_status     = strtolower($raw_transfer_status);

                    if ($status_val === '4' && (empty($out_time_str) || $out_time_str == '0000-00-00 00:00:00')) {
                        $label_in = "ABSENT"; $label_out = "NOT WRK"; $badge_class = "badge-error";
                    } elseif ($is_transit) {
                        $label_in  = "DEPART"; $label_out = "ARRIVE"; $badge_class = "badge-transfer";
                    } elseif ($is_split) {
                        $label_in  = "SPLIT-IN"; $label_out = "SPLIT-OUT"; $badge_class = "badge-transfer";
                    } else {
                        $label_in  = "IN"; $label_out = "OUT"; $badge_class = "badge-normal";
                    }

                    if ($is_transit && (empty($out_time_str) || $out_time_str == '0000-00-00 00:00:00')) { $out_ts = time(); } 
                    else { $out_ts = (!empty($out_time_str) && $out_time_str != '0000-00-00 00:00:00') ? strtotime($out_time_str) : 0; }

                    $all_breaks = [];
                    if (!$is_transit && !$is_split && !empty($r['id'])) {
                        $bListStmt = $pdo->prepare("SELECT break_start, break_end FROM breaks WHERE shift_id = ? ORDER BY id ASC");
                        $bListStmt->execute([$r['id']]);
                        $all_breaks = $bListStmt->fetchAll(PDO::FETCH_ASSOC);
                    }

                    $break_sec = 0;
                    foreach ($all_breaks as $b) {
                        if (!empty($b['break_end'])) { $break_sec += abs(strtotime($b['break_end']) - strtotime($b['break_start'])); }
                    }
                    
                    $net_sec = ($out_ts > 0) ? ($out_ts - $in_ts - $break_sec) : (time() - $in_ts - $break_sec);
                    if ($net_sec < 0) $net_sec = 0;
                    
                    $uid = $r['user_id'] ?? 0;
                    $period_total = isset($batchData[$uid]) ? ((float)$batchData[$uid] / 3600) : 0.0;
                    
                    $lat_in = $r['lat_in'] ?? null; $lng_in = $r['lng_in'] ?? null;
                    $lat_out = $r['lat_out'] ?? null; $lng_out = $r['lng_out'] ?? null;
                    $target_lat = $r['target_lat'] ?? null; $target_lng = $r['target_lng'] ?? null;
                    $dist_in = getDistance($lat_in, $lng_in, $target_lat, $target_lng);
                    
                    $map_url_in = (!empty($lat_in) && !empty($lng_in))
                        ? ("https://maps.google.com/?q=" . trim((string)$lat_in) . "," . trim((string)$lng_in))
                        : '';
                    $map_url_out = (!empty($lat_out) && !empty($lng_out))
                        ? ("https://maps.google.com/?q=" . trim((string)$lat_out) . "," . trim((string)$lng_out))
                        : '';
                    $map_url = $map_url_in;
                    if ($map_url_in && $map_url_out) {
                        $map_url = "https://www.google.com/maps/dir/" . trim((string)$lat_in) . "," . trim((string)$lng_in) . "/" . trim((string)$lat_out) . "," . trim((string)$lng_out);
                    } elseif ($map_url_out) {
                        $map_url = $map_url_out;
                    }
                    
                    $row_bg_style = '';
                    if ($is_transit) { $row_bg_style = 'style="background: #fffbeb;"'; }
                    elseif ($is_split) { $row_bg_style = 'style="background: #f0fdf4;"'; }
                    
                    $row_class_tag = ($group_by !== 'none') ? 'data-parent-group="' . $group_id . '" class="child-shift-row"' : '';
                ?>
                <tr id="row-<?= $r['id'] ?>" <?= $row_class_tag ?> <?= $row_bg_style ?>>
                    <td class="col-check"><input type="checkbox" class="rec-check" value="<?= $r['id'] ?>"></td>
                    <td class="col-selfie">
                        <div class="selfie-pair">
                            <?php
                            $sid = (int)($r['id'] ?? 0);
                            $ph = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
                            foreach (['in', 'out'] as $sk) {
                                $thumbUrl = 'audit_selfie.php?id=' . $sid . '&k=' . $sk . '&t=1';
                                $fullUrl  = 'audit_selfie.php?id=' . $sid . '&k=' . $sk;
                                echo '<img src="' . $ph . '" data-src="' . htmlspecialchars($thumbUrl) . '" data-full="' . htmlspecialchars($fullUrl) . '" class="selfie-thumb zoomable-image" alt="' . htmlspecialchars(ucfirst($sk)) . '" width="28" height="28" loading="lazy">';
                            }
                            ?>
                        </div>
                    </td>
                    <td>
                        <div class="staff-cell">
                            <a href="edit_user.php?id=<?= $r['user_id'] ?>" class="staff-link"><?= htmlspecialchars($display_name) ?></a>
                            <div class="staff-meta" title="ID <?= (int)$r['user_id'] ?> · <?= htmlspecialchars((string)($r['mobile'] ?? '')) ?> · <?= htmlspecialchars((string)($r['user_email'] ?? '')) ?>">
                                #<?= (int)$r['user_id'] ?><?php if (!empty($r['mobile'])): ?> · <?= htmlspecialchars((string)$r['mobile']) ?><?php endif; ?><?php if (!empty($r['user_email'])): ?> · <a href="mailto:<?= htmlspecialchars((string)$r['user_email']) ?>" class="email-link"><?= htmlspecialchars((string)$r['user_email']) ?></a><?php endif; ?>
                            </div>
                            <div class="action-icons">
                                <?php if ($sess_user_level < 30 && (int)($r['user_id'] ?? 0) > 0 && (int)($r['site_id'] ?? 0) > 0):
                                    $mpeOrg = (int)($r['staff_org_id'] ?? 0);
                                    $mpeDate = date('Y-m-d', $in_ts);
                                    $mpeHref = 'manual_pay_entry.php?lock=1'
                                        . '&user_id=' . (int)$r['user_id']
                                        . '&site_id=' . (int)$r['site_id']
                                        . '&entry_date=' . urlencode($mpeDate)
                                        . ($mpeOrg > 0 ? '&org_id=' . $mpeOrg : '');
                                ?>
                                <a class="mpe-row-link" target="_blank" href="<?= htmlspecialchars($mpeHref) ?>" title="Manual pay for <?= htmlspecialchars($display_name) ?> — employee and site are filled in">Manual Pay</a>
                                <?php endif; ?>
                                <a href="edit_shift.php?id=<?= $r['id'] ?>" style="color:#ca8a04; text-decoration:none;" title="Modify Master Punch Times"><i class="fas fa-pencil-alt icon-action-trigger"></i></a>
                                <span style="color:#3b82f6; cursor:pointer;" title="Adjust Rest/Break Windows" onclick="openBreakModal(<?= $r['id'] ?>, <?= htmlspecialchars(json_encode($all_breaks)) ?>)"><i class="fas fa-clock icon-action-trigger"></i></span>
                                <span style="color:#10b981; cursor:pointer;" title="Duplicate Shift as New Entry" onclick="duplicateShiftRow(<?= $r['id'] ?>)"><i class="fas fa-copy icon-action-trigger"></i></span>
                                <span style="color:#dc2626; cursor:pointer;" title="Delete Shift Record" onclick="deleteShift(<?= $r['id'] ?>)"><i class="fas fa-trash-alt icon-action-trigger"></i></span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if ($is_transit): ?>
                            <span style="color:#b45309; font-weight:700; font-size:10px;"><i class="fas fa-truck-moving"></i> Travel: <?= htmlspecialchars((string)($r['site_name'] ?? 'Origin')) ?></span>
                        <?php elseif ($is_split): ?>
                            <span style="color:#15803d; font-weight:700; font-size:10px;"><i class="fas fa-pie-chart"></i> Split layer</span>
                        <?php elseif (!empty($r['site_name'])): ?>
                            <?= htmlspecialchars((string)$r['site_name']) ?>
                        <?php else: ?>
                            <span style="color: #ef4444; font-weight: 600; background: #fee2e2; padding: 2px 6px; border-radius: 4px;"><i class="fas fa-exclamation-triangle"></i> No Primary Site</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($raw_transfer_status) && $transfer_status !== 'none' && $transfer_status !== 'travel'): ?>
                            <div style="background: #fff7ed; color: #c2410c; border: 1px solid #f97316; padding: 4px 8px; border-radius: 5px; font-weight: bold; font-size: 10px; margin-bottom: 6px; display: inline-block;">
                                <i class="fas fa-route"></i> Route: <?= htmlspecialchars($raw_transfer_status) ?>
                            </div><br>
                        <?php endif; ?>

                        <span class="<?= $badge_class ?>"><?= $label_in ?></span>
                        <div class="time-box"><?= date('M j, h:i A', $in_ts) ?></div><br>
                        <div style="margin-top:5px;">
                            <span class="<?= $badge_class ?>"><?= $label_out ?></span>
                            <div class="time-box"><?= ($out_ts ? date('M j, h:i A', $out_ts) : '--:--') ?></div>
                        </div>
                    </td>
                    <td class="col-reason">
                        <?php
                        $reason_disp = sa_audit_reason_parts($r);
                        $rateHref = '';
                        if ($sess_user_level <= 25 && stripos($reason_disp['text'], 'Rate flag:') !== false) {
                            $fileRate = 0.0;
                            if (preg_match('/file rate ([0-9]+(?:\.[0-9]+)?)/i', $reason_disp['text'], $rm)) {
                                $fileRate = (float)$rm[1];
                            }
                            $eff = '';
                            if (!empty($r['clock_in'])) {
                                $effTs = strtotime((string)$r['clock_in']);
                                $eff = $effTs ? date('Y-m-d', $effTs) : '';
                            }
                            $rateHref = aalh_rate_change_href((int)($r['user_id'] ?? 0), (int)($r['staff_org_id'] ?? 0), $fileRate, $eff);
                        }
                        if ($reason_disp['kind'] === 'none'):
                        ?>
                            <span class="reason-none">—</span>
                        <?php else: ?>
                            <?php if ($rateHref !== ''): ?>
                                <a class="mpe-row-link" href="<?= htmlspecialchars($rateHref) ?>">Change rate</a>
                            <?php endif; ?>
                            <details class="reason-details">
                                <summary>View</summary>
                                <div class="reason-box kind-<?= htmlspecialchars($reason_disp['kind']) ?>">
                                    <?= nl2br(htmlspecialchars($reason_disp['text'])) ?>
                                </div>
                            </details>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="break-wrapper">
                            <?php 
                            if ($is_transit):
                                echo "<span style='color:#b45309; font-style:italic; font-weight:bold;'>Transit Segment</span>";
                            elseif ($is_split):
                                echo "<span style='color:#15803d; font-style:italic; font-weight:bold;'>Allocated Log Block</span>";
                            elseif (empty($all_breaks)):
                                echo "<span style='color:#94a3b8; font-style:italic;'>No Breaks</span>";
                            else:
                                foreach ($all_breaks as $index => $b):
                                    $b_start = strtotime($b['break_start']);
                                    $b_end   = !empty($b['break_end']) ? strtotime($b['break_end']) : null;
                                    $b_dur   = $b_end ? round(($b_end - $b_start) / 60) . "m" : "Open";
                                    $hide_style = ($index >= 2) ? 'style="display:none;" class="hidden-break-item"' : '';
                                    ?>
                                    <div class="break-badge" <?= $hide_style ?>>
                                        #<?= $index + 1 ?>: <?= date('M j, h:i A', $b_start) ?> тЖТ <?= $b_end ? date('M j, h:i A', $b_end) : 'Open' ?> (<strong><?= $b_dur ?></strong>)
                                    </div>
                                <?php endforeach; ?>
                                <?php if (count($all_breaks) > 2): ?>
                                    <span class="break-toggle-btn" onclick="toggleBreaks(this)">Show More (+<?= count($all_breaks) - 2 ?>)</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($is_transit): ?>
                            <strong style="color:#d97706; font-size:12px;"><i class="fas fa-clock"></i> <?= floor($net_sec/3600) ?>h <?= floor(($net_sec%3600)/60) ?>m Travel</strong>
                        <?php elseif ($is_split): ?>
                            <strong style="color:#16a34a; font-size:12px;"><i class="fas fa-check"></i> <?= floor($net_sec/3600) ?>h <?= floor(($net_sec%3600)/60) ?>m Segment</strong>
                        <?php else: ?>
                            <strong><?= floor($net_sec/3600) ?>h <?= floor(($net_sec%3600)/60) ?>m Work</strong>
                        <?php endif; ?><br>
                        <small style="color:#64748b">Period Total: <?= round($period_total, 2) ?>h</small>
                        <br>
                        <?php if ((int)($r['payout_status'] ?? 0) === 1): ?>
                            <span class="status-indicator-badge status-payout-finalized"><i class="fas fa-check-double"></i> Paid / Finalized</span>
                        <?php else: ?>
                            <span class="status-indicator-badge status-payout-pending"><i class="fas fa-history"></i> Pending Audit</span>
                        <?php endif; ?>
                    </td>
                    <td class="gps-cell col-gps">
                        <?php
                        $dist_in = (float)($dist_in ?? 0); $max_r = (float)($r['geo_radius_meters'] ?? 200);
                        $local_tolerance_pct = 1.00;
                        if (isset($siteOverridesCache[$r['site_id']]['site_geo_tolerance_pct'])) { $local_tolerance_pct = (float)$siteOverridesCache[$r['site_id']]['site_geo_tolerance_pct']; }
                        elseif (isset($globalPolicyCache['global_geo_tolerance_pct'])) { $local_tolerance_pct = (float)$globalPolicyCache['global_geo_tolerance_pct']; }
                        $tolerance_factor = $local_tolerance_pct / 100;
                        $orange_threshold = $max_r * (1 + $tolerance_factor);
                        if ($dist_in <= $max_r) {
                            $pin_class = "ok"; $pin_icon = "fa-check-circle";
                            $pin_title = "Inside radius (" . round($dist_in) . "m / " . round($max_r) . "m)";
                        } elseif ($dist_in > $max_r && $dist_in <= $orange_threshold) {
                            $pin_class = "warn"; $pin_icon = "fa-exclamation-circle";
                            $pin_title = "Warning buffer " . $local_tolerance_pct . "% (" . round($dist_in) . "m / " . round($max_r) . "m)";
                        } else {
                            $pin_class = "bad"; $pin_icon = "fa-exclamation-triangle";
                            $pin_title = "Geofence breach (" . round($dist_in) . "m / " . round($max_r) . "m)";
                        }
                        ?>
                        <div class="gps-line">
                            <span class="gps-io">IN</span>
                            <?php if ($map_url_in): ?>
                                <a href="<?= htmlspecialchars($map_url_in) ?>" target="_blank" class="gps-ico map" title="Open punch-in map"><i class="fas fa-map-marker-alt"></i></a>
                                <?php if (!$is_transit && !$is_split): ?>
                                    <span class="gps-ico <?= $pin_class ?>" title="<?= htmlspecialchars($pin_title) ?>"><i class="fas <?= $pin_icon ?>"></i></span>
                                    <span class="gps-dist" title="<?= htmlspecialchars($pin_title) ?>"><?= round($dist_in) ?>m</span>
                                <?php elseif ($is_split): ?>
                                    <span class="gps-ico ok" title="System pre-approved split"><i class="fas fa-clone"></i></span>
                                <?php else: ?>
                                    <span class="gps-ico warn" title="Transit GPS active"><i class="fas fa-satellite-dish"></i></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="gps-ico bad" title="No punch-in GPS"><i class="fas fa-exclamation-triangle"></i></span>
                            <?php endif; ?>
                        </div>
                        <div class="gps-line">
                            <span class="gps-io">OUT</span>
                            <?php if ($map_url_out): ?>
                                <a href="<?= htmlspecialchars($map_url_out) ?>" target="_blank" class="gps-ico map" title="Open punch-out map"><i class="fas fa-map-marker-alt"></i></a>
                            <?php else: ?>
                                <span class="reason-none">—</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="col-action"><a href="finalize_shift.php?id=<?= $r['id'] ?>&corridor_route=<?= urlencode($is_transit ? (!empty($raw_transfer_status) && $transfer_status !== 'travel' ? $raw_transfer_status : $r['site_name']) : '') ?>" class="btn-green btn-finalize">Finalize</a></td>
                </tr>
                <?php endforeach; 
            else:
                $empty_hint = 'No records found for the selected criteria.';
                if ($status_type === 'pending') {
                    $empty_hint .= ' Tip: aagroup / imported hours are often already Finalized — switch Status to Finalized or All, and use Bi-weekly pay cycle with the Pay Period arrows.';
                } elseif ($period_mode === 'semi_monthly' && (int)($period_org_id ?? $org_id) === 4) {
                    $empty_hint .= ' Tip: AA Group pays biweekly — set Pay cycle to Bi-weekly, then use Pay Period navigation.';
                }
                ?>
                <tr><td colspan="10" style="text-align:center; padding:40px; color:#94a3b8; font-style:italic;"><?= htmlspecialchars($empty_hint) ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div><!-- .audit-table-wrap -->
    <div class="sa-pager-bottom">
    <?php sa_render_pager($page, $total_pages, $showing_from, $showing_to, $total_rows, $page_size_key, $audit_filter_qs, $page_size_allowed); ?>
    </div>
</div><!-- .container -->
</div><!-- .sa-page -->

    <script>
        let activeShiftDateContext = '';

        function saHydrateSelfies() {
            if (document.body.classList.contains('hide-selfies')) return;
            document.querySelectorAll('#auditTable img.selfie-thumb[data-src]').forEach(function(img) {
                if (img.getAttribute('data-loaded') === '1') return;
                var src = img.getAttribute('data-src');
                if (src) {
                    img.src = src;
                    img.setAttribute('data-loaded', '1');
                }
            });
        }
        function saChangePageSize(sel) {
            var u = new URL(window.location.href);
            u.searchParams.set('page_size', sel.value);
            u.searchParams.set('page', '1');
            window.location.href = u.pathname + '?' + u.searchParams.toString();
        }
        function saSyncSelfiesBtn() {
            var btn = document.getElementById('toggleSelfiesBtn');
            var showing = !document.body.classList.contains('hide-selfies');
            if (btn) {
                btn.classList.toggle('active', showing);
                btn.setAttribute('aria-pressed', showing ? 'true' : 'false');
                btn.title = showing ? 'Hide selfie photos' : 'Show selfie photos';
                btn.innerHTML = showing
                    ? '<i class="fas fa-camera"></i><span class="sa-btn-lbl"> Hide Selfies</span>'
                    : '<i class="fas fa-camera"></i><span class="sa-btn-lbl"> Show Selfies</span>';
            }
            return showing;
        }
        function toggleSelfiesCol() {
            document.body.classList.toggle('hide-selfies');
            var showing = saSyncSelfiesBtn();
            try { localStorage.setItem('sa_show_selfies', showing ? '1' : '0'); } catch (e) {}
            if (showing) saHydrateSelfies();
        }
        function saSyncGpsBtn() {
            var btn = document.getElementById('toggleGpsBtn');
            var showing = !document.body.classList.contains('hide-gps');
            if (btn) {
                btn.classList.toggle('active', showing);
                btn.setAttribute('aria-pressed', showing ? 'true' : 'false');
                btn.title = showing ? 'Hide punch-in and punch-out map pins' : 'Show punch-in and punch-out map pins';
                btn.innerHTML = showing
                    ? '<i class="fas fa-map-marker-alt"></i><span class="sa-btn-lbl"> Hide GPS</span>'
                    : '<i class="fas fa-map-marker-alt"></i><span class="sa-btn-lbl"> Show GPS</span>';
            }
            return showing;
        }
        function toggleGpsCol() {
            document.body.classList.toggle('hide-gps');
            var showing = saSyncGpsBtn();
            try { localStorage.setItem('sa_show_gps', showing ? '1' : '0'); } catch (e) {}
        }

        function saRibbonEl(key) {
            return document.getElementById(key === 'filters' ? 'saRibbonFilters' : 'saRibbonNav');
        }
        function saApplyRibbonState(key, collapsed) {
            var el = saRibbonEl(key);
            if (!el) return;
            el.classList.toggle('is-collapsed', !!collapsed);
            var btn = el.querySelector('.sa-ribbon-toggle');
            if (btn) {
                btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                btn.title = collapsed ? 'Expand ' + (key === 'filters' ? 'filters' : 'navigation') : 'Collapse ' + (key === 'filters' ? 'filters' : 'navigation');
            }
        }
        function saToggleRibbon(key) {
            var el = saRibbonEl(key);
            if (!el) return;
            var collapsed = !el.classList.contains('is-collapsed');
            saApplyRibbonState(key, collapsed);
            try { localStorage.setItem('sa_ribbon_' + key, collapsed ? '0' : '1'); } catch (e) {}
        }
        function saOpenAbout() {
            var nav = saRibbonEl('nav');
            if (nav && nav.classList.contains('is-collapsed')) {
                saApplyRibbonState('nav', false);
                try { localStorage.setItem('sa_ribbon_nav', '1'); } catch (e) {}
            }
            var b = document.querySelector('#pgHelpPanel .pg-help-toggle');
            if (b) { b.click(); return; }
            var p = document.getElementById('pgHelpBody');
            if (p) {
                if (p.hasAttribute('hidden')) p.removeAttribute('hidden');
                else p.setAttribute('hidden', '');
            }
        }
        function saStartTour() {
            var b = document.getElementById('pgTourStartBtn');
            if (b) { b.click(); return; }
            if (window.pgTourStart) window.pgTourStart();
        }

        document.addEventListener("DOMContentLoaded", function() {
            var showSelfies = true;
            try {
                if (localStorage.getItem('sa_show_selfies') === '0') showSelfies = false;
            } catch (e) {}
            document.body.classList.toggle('hide-selfies', !showSelfies);
            if (saSyncSelfiesBtn()) saHydrateSelfies();
            var showGps = true;
            try {
                if (localStorage.getItem('sa_show_gps') === '0') showGps = false;
            } catch (e) {}
            document.body.classList.toggle('hide-gps', !showGps);
            saSyncGpsBtn();
            try {
                // localStorage: '1' = expanded (default), '0' = collapsed
                ['nav', 'filters'].forEach(function(key) {
                    var v = localStorage.getItem('sa_ribbon_' + key);
                    if (v === '0') saApplyRibbonState(key, true);
                    else saApplyRibbonState(key, false);
                });
            } catch (e) {}
        });

        document.addEventListener("DOMContentLoaded", function() {
            var modal = document.getElementById('imgModal');
            var modalImg = document.getElementById('modalImg');

            document.querySelectorAll('.zoomable-image').forEach(function(img) {
                img.addEventListener('click', function() {
                    if (!modal || !modalImg) return;
                    var full = this.getAttribute('data-full') || this.src || '';
                    if (!full || full.indexOf('nophoto') !== -1 || full.indexOf('data:image/gif') === 0) return;
                    modalImg.src = full;
                    modal.style.display = 'flex';
                });
            });

            if (modal) { modal.addEventListener('click', function() { this.style.display = 'none'; }); }

            const copyBtn = document.getElementById('globalCopyBtn');
            const tableBody = document.querySelector("#auditTable tbody");
            
            if (copyBtn && tableBody && tableBody.rows.length > 0 && !tableBody.rows[0].innerText.includes('No records found')) {
                copyBtn.classList.remove('btn-copy-loading');
                copyBtn.innerHTML = '<i class="fas fa-copy"></i> Copy';
                copyBtn.style.background = '#0284c7'; copyBtn.style.pointerEvents = 'auto'; copyBtn.style.cursor = 'pointer';
                copyBtn.onclick = function() { exportToClipboardAction(); };
            } else if (copyBtn) {
                copyBtn.innerHTML = '<i class="fas fa-ban"></i> Empty';
            }

            var masterCheck = document.getElementById('masterCheck');
            if (masterCheck) {
                masterCheck.onclick = function() {
                    var checkboxes = document.querySelectorAll('.rec-check:not(.hidden-row * )');
                    checkboxes.forEach(function(cb) { cb.checked = masterCheck.checked; });
                };
            }
        });

        function switchIntervalMode(mode) {
            const today = new Date();
            let fromDate = "";
            let toDate = "";

            if (mode === 'daily') {
                fromDate = today.toISOString().split('T')[0];
                toDate = fromDate;
            } else if (mode === 'monthly') {
                const y = today.getFullYear(), m = today.getMonth();
                fromDate = new Date(y, m, 1).toISOString().split('T')[0];
                toDate = new Date(y, m + 1, 0).toISOString().split('T')[0];
            } else { 
                const currentDayIndex = today.getDay();
                const distanceToMonday = currentDayIndex === 0 ? -6 : 1 - currentDayIndex;
                const monday = new Date(today.setDate(today.getDate() + distanceToMonday));
                const sunday = new Date(monday); sunday.setDate(sunday.getDate() + 6);
                
                fromDate = monday.toISOString().split('T')[0];
                toDate = sunday.toISOString().split('T')[0];
            }

            window.location.href = window.location.pathname + 
                                  "?audit_mode=" + mode + 
                                  "&from_date=" + fromDate + 
                                  "&to_date=" + toDate + 
                                  "&org_id=<?= urlencode(($sa_is_super && $selected_org > 0) ? (string)$selected_org : 'all') ?>" +
                                  "&user_id=<?= urlencode($employee_filter) ?>" + 
                                  "&site_id=<?= urlencode($site_filter) ?>" + 
                                  "&status_type=<?= urlencode($status_type) ?>" + 
                                  "&group_by=<?= urlencode($group_by) ?>" +
                                  "&period_mode=<?= urlencode($period_mode) ?>" +
                                  "&sort=<?= urlencode($sort_filter) ?>" +
                                  "&page_size=<?= (int)$page_size ?>";
        }

        function duplicateShiftRow(shiftId) {
            if (confirm("Are you sure you want to duplicate this exact shift record as a fresh Pending entry log?")) {
                var xhr = new XMLHttpRequest();
                xhr.open("POST", window.location.pathname + window.location.search, true);
                xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        try {
                            var res = JSON.parse(xhr.responseText);
                            if (res.success) {
                                var alertBox = document.getElementById('status-msg-alert');
                                alertBox.innerText = 'Shift Cloned Successfully!';
                                alertBox.style.background = '#16a34a';
                                alertBox.style.display = 'block';
                                setTimeout(function() { 
                                    alertBox.style.display = 'none'; 
                                    window.location.reload(); 
                                }, 1200);
                            } else {
                                alert('Duplication Error Fallback: ' + (res.error || 'Unknown Exception'));
                            }
                        } catch (e) {
                            window.location.reload();
                        }
                    }
                };
                xhr.send("action=duplicate_shift&shift_id=" + parseInt(shiftId));
            }
        }

        function openBreakModal(shiftId, existingBreaks) {
            document.getElementById('modalShiftId').value = shiftId;
            const container = document.getElementById('modalBreakContainer');
            container.innerHTML = ''; 
            activeShiftDateContext = window.getShiftDateFallback(shiftId);

            if(existingBreaks && existingBreaks.length > 0) {
                existingBreaks.forEach(function(b) {
                    appendBreakRowTemplate(b.break_start, b.break_end || '');
                });
            } else {
                addBlankBreakRow(); 
            }
            document.getElementById('breakModal').style.display = 'flex';
        }

        function appendBreakRowTemplate(startVal, endVal) {
            const container = document.getElementById('modalBreakContainer');
            const div = document.createElement('div');
            div.className = 'break-input-row';
            
            let sFormatted = '';
            let eFormatted = '';
            
            if (startVal) {
                sFormatted = startVal.replace(' ', 'T');
            } else if (activeShiftDateContext) {
                sFormatted = activeShiftDateContext + 'T12:00';
            }
            
            if (endVal) {
                eFormatted = endVal.replace(' ', 'T');
            } else if (activeShiftDateContext) {
                eFormatted = activeShiftDateContext + 'T12:30';
            }
            
            div.innerHTML = `
                <div style="flex:1;">
                    <label style="font-size:9px; color:#475569; font-weight:bold; display:block; margin-bottom:2px;">Break Start</label>
                    <input type="datetime-local" name="break_start[]" value="${sFormatted}" class="form-control" style="font-size:11px; height:32px;" required>
                </div>
                <div style="flex:1;">
                    <label style="font-size:9px; color:#475569; font-weight:bold; display:block; margin-bottom:2px;">Break End</label>
                    <input type="datetime-local" name="break_end[]" value="${eFormatted}" class="form-control" style="font-size:11px; height:32px;">
                </div>
                <button type="button" style="background:#ef4444; border:none; color:white; padding:4px 8px; border-radius:6px; margin-top:14px; cursor:pointer;" onclick="this.parentNode.remove()"><i class="fas fa-trash-alt"></i></button>
            `;
            container.appendChild(div);
        }

        function addBlankBreakRow() {
            appendBreakRowTemplate('', '');
        }

        window.getShiftDateFallback = function(shiftId) {
            const tr = document.getElementById('row-' + shiftId);
            if (!tr) return document.getElementsByName('from_date')[0].value || new Date().toISOString().slice(0,10);
            
            const dateTd = tr.querySelector('td:nth-child(5) .time-box');
            if (dateTd && dateTd.innerText) {
                let text = dateTd.innerText.trim();
                let match = text.match(/([a-zA-Z]+)\s+(\d+)/);
                if (match) {
                    let months = {Jan:'01',Feb:'02',Mar:'03',Apr:'04',May:'05',Jun:'06',Jul:'07',Aug:'08',Sep:'09',Oct:'10',Nov:'11',Dec:'12'};
                    let mStr = months[match[1].slice(0,3)];
                    let dStr = match[2].padStart(2, '0');
                    let yearContext = document.getElementsByName('from_date')[0].value ? document.getElementsByName('from_date')[0].value.slice(0,4) : '2026';
                    if (mStr && dStr) return yearContext + '-' + mStr + '-' + dStr;
                }
            }
            return document.getElementsByName('from_date')[0].value || new Date().toISOString().slice(0,10);
        };

        function closeBreakModal() {
            document.getElementById('breakModal').style.display = 'none';
        }

        function toggleGroupAccordion(groupId, headerElement) {
            headerElement.classList.toggle('collapsed-group');
            const childrenRows = document.querySelectorAll(`tr[data-parent-group="${groupId}"]`);
            childrenRows.forEach(row => {
                row.classList.toggle('hidden-row');
            });
        }

        function exportToClipboardAction() {
            var textParts = [];
            var rows = document.querySelectorAll("#auditTable tr:not(.hidden-row)");
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].classList.contains('group-header') || rows[i].cells.length < 5) continue;
                var cols = rows[i].querySelectorAll("td");
                let lineData = [
                    cols[2] ? cols[2].innerText.replace(/\n/g, " ").trim() : "",
                    cols[3] ? cols[3].innerText.trim() : "",
                    cols[4] ? cols[4].innerText.replace(/\n/g, " | ").trim() : "",
                    cols[5] ? cols[5].innerText.replace(/\n/g, " ").trim() : "",
                    cols[7] ? cols[7].innerText.replace(/\n/g, " ").trim() : ""
                ];
                textParts.push(lineData.join("\t"));
            }
            var clipboardContent = textParts.join("\n");
            navigator.clipboard.writeText(clipboardContent).then(function() {
                alert("ЁЯУЛ Production records copied smoothly to clipboard!");
            }).catch(function(err) { alert("Clipboard allocation exception."); });
        }

        function bulkDelete() {
            let checked = document.querySelectorAll('.rec-check:checked');
            let ids = Array.from(checked).map(cb => cb.value);
            if (ids.length === 0) return alert("Select records first.");
            if (confirm(`Remove ${ids.length} selected shift(s) from Staff Audit and payroll? The rows are kept for recovery.`)) {
                let form = document.createElement('form');
                form.method = 'POST'; form.action = window.location.pathname + window.location.search; 
                let actionInput = document.createElement('input'); actionInput.type = 'hidden'; actionInput.name = 'action'; actionInput.value = 'bulk_delete'; form.appendChild(actionInput);
                ids.forEach(id => {
                    let input = document.createElement('input'); input.type = 'hidden'; input.name = 'shift_ids[]'; input.value = id; form.appendChild(input);
                });
                document.body.appendChild(form); form.submit();
            }
        }

        // =========================================================================
        // ЁЯОн DYNAMIC MODAL INITIALIZER INTERFACE HOOK
        // =========================================================================
        function bulkFinalize() {
            let checked = document.querySelectorAll('.rec-check:checked');
            let ids = Array.from(checked).map(cb => cb.value);
            if (ids.length === 0) return alert("Please mark target checkmark records from your list view first.");
            
            const container = document.getElementById('modal_hidden_ids_container');
            container.innerHTML = '';
            var csv = document.createElement('input');
            csv.type = 'hidden';
            csv.name = 'record_ids_csv';
            csv.value = ids.map(function (id) { return parseInt(id, 10); }).filter(function (n) { return n > 0; }).join(',');
            container.appendChild(csv);

            // Smoothly open the translucent matted window panel on top of the live table
            document.getElementById('bulkFinalizerModal').style.display = 'flex';
        }

        function closeAllocationModal() {
            document.getElementById('bulkFinalizerModal').style.display = 'none';
        }

        function toggleBonusInputLayer(chosenFormula) {
            const wrapper = document.getElementById('bonusLumpSumInputWrapper');
            if (chosenFormula === 'bonus') {
                wrapper.style.display = 'block';
            } else {
                wrapper.style.display = 'none';
            }
        }

        // Weekly OT policy modes hide the per-shift formula/cap controls (they do not apply);
        // the per-shift mode keeps the original prompt exactly as before.
        function toggleOtModeLayers(mode) {
            const isPerShift = (mode === 'per_shift');
            document.getElementById('formulaInputWrapper').style.display = isPerShift ? 'block' : 'none';
            document.getElementById('dailyCapInputWrapper').style.display = isPerShift ? 'block' : 'none';
            if (!isPerShift) { document.getElementById('bonusLumpSumInputWrapper').style.display = 'none'; }

            const explains = {
                per_shift: 'Original behavior: hours over the cap (e.g. 8/day) on EACH shift follow the formula selected below.',
                site_policy: 'Each shift uses its own work site\u2019s overtime policy (weekly and/or daily thresholds, configurable under Manage Sites \u2192 Edit Site). Week = Monday\u2013Sunday; already-finalized hours in the week count toward the threshold.',
                weekly_44: 'Regular up to 44 hours per Monday\u2013Sunday week per employee per site; hours beyond pay overtime at 1.5\u00d7. Already-finalized hours in the week count toward the 44.',
                weekly_40: 'Regular up to 40 hours per Monday\u2013Sunday week per employee per site; hours beyond pay overtime at 1.5\u00d7. Already-finalized hours in the week count toward the 40.',
                daily8_weekly_44: 'Hours over 8 in a day become OT first; remaining regular hours over 44 in the Monday\u2013Sunday week also become OT. No hour is counted twice.',
                daily8_weekly_40: 'Hours over 8 in a day become OT first; remaining regular hours over 40 in the Monday\u2013Sunday week also become OT. No hour is counted twice.'
            };
            document.getElementById('otModeExplainer').textContent = explains[mode] || '';
        }
        document.addEventListener('DOMContentLoaded', function () {
            var sel = document.getElementById('otModeSelector');
            if (sel) { toggleOtModeLayers(sel.value); }
        });

        function deleteShift(id) {
            if (confirm("Remove this shift from Staff Audit and payroll? The row is kept for recovery.")) {
                let form = document.createElement('form'); 
                form.method = 'POST'; 
                form.action = window.location.pathname + window.location.search; 
                
                let actionInput = document.createElement('input'); 
                actionInput.type = 'hidden'; 
                actionInput.name = 'action'; 
                actionInput.value = 'bulk_delete'; 
                form.appendChild(actionInput);
                
                let idInput = document.createElement('input'); 
                idInput.type = 'hidden'; 
                idInput.name = 'shift_ids[]'; 
                idInput.value = parseInt(id); 
                form.appendChild(idInput);
                
                document.body.appendChild(form); 
                form.submit();
            }
        }

        function toggleBreaks(btn) {
            var wrapper = btn.parentNode;
            var hiddenItems = wrapper.getElementsByClassName('hidden-break-item');
            for (var i = 0; i < hiddenItems.length; i++) { hiddenItems[i].style.display = hiddenItems[i].style.display === 'none' ? 'block' : 'none'; }
            btn.innerText = btn.innerText.includes('Show More') ? 'Hide Extra Breaks' : 'Show More (+' + hiddenItems.length + ')';
        }

        function exportToCSV() {
            var csv = [];
            var rows = document.querySelectorAll("#auditTable tr:not(.hidden-row)");
            var headers = ["Staff Member", "Site (Clocked-In)", "Punches", "Reason", "Breaks", "Duration (Shift | Period)", "Geo Pins"];
            csv.push(headers.map(h => '"' + h + '"').join(","));
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].classList.contains('group-header') || rows[i].cells.length < 5) continue;
                var row = [], cols = rows[i].querySelectorAll("td");
                let staffText = cols[2] ? cols[2].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim() : ""; row.push('"' + staffText.replace(/"/g, '""') + '"');
                let siteText = cols[3] ? cols[3].innerText.trim() : ""; row.push('"' + siteText.replace(/"/g, '""') + '"');
                let punchesText = cols[4] ? cols[4].innerText.replace(/(\r\n|\n|\r)/gm, " | ").trim() : ""; row.push('"' + punchesText.replace(/"/g, '""') + '"');
                let reasonText = cols[5] ? cols[5].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim() : ""; row.push('"' + reasonText.replace(/"/g, '""') + '"');
                let breaksText = cols[6] ? cols[6].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim() : ""; row.push('"' + breaksText.replace(/"/g, '""') + '"');
                let durationText = cols[7] ? cols[7].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim() : ""; row.push('"' + durationText.replace(/"/g, '""') + '"');
                let gpsText = cols[8] ? cols[8].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim() : ""; row.push('"' + gpsText.replace(/"/g, '""') + '"');
                csv.push(row.join(","));         
            }
            var csvContent = "\uFEFF" + csv.join("\n");
            var csvFile = new Blob([csvContent], {type: "text/csv;charset=utf-8;"});
            var downloadLink = document.createElement("a"); downloadLink.download = "Staff_Audit_Report_" + new Date().toISOString().slice(0,10) + ".csv"; downloadLink.href = window.URL.createObjectURL(csvFile); downloadLink.style.display = "none"; document.body.appendChild(downloadLink); downloadLink.click(); document.body.removeChild(downloadLink);
        }
        // === DAY & PAY-PERIOD NAVIGATION (semi_monthly | bi_weekly | monthly) ===
        var pgPeriodMode = <?= json_encode($period_mode) ?>;
        function pgToYMD(d){
            return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
        }
        function pgGoToRange(fromDate, toDate){
            var pmEl = document.getElementsByName('period_mode')[0];
            var pm = (pmEl && pmEl.value) ? pmEl.value : pgPeriodMode;
            window.location.href = window.location.pathname +
                "?from_date=" + fromDate +
                "&to_date=" + toDate +
                "&org_id=<?= urlencode(($sa_is_super && $selected_org > 0) ? (string)$selected_org : 'all') ?>" +
                "&user_id=<?= urlencode($employee_filter) ?>" +
                "&site_id=<?= urlencode($site_filter) ?>" +
                "&status_type=<?= urlencode($status_type) ?>" +
                "&group_by=<?= urlencode($group_by) ?>" +
                "&period_mode=" + encodeURIComponent(pm) +
                "&sort=<?= urlencode($sort_filter) ?>" +
                "&page_size=<?= (int)$page_size ?>";
        }
        function pgPayPeriodFor(d){
            var mode = pgPeriodMode;
            var pmEl = document.getElementsByName('period_mode')[0];
            if (pmEl && pmEl.value) mode = pmEl.value;
            var y=d.getFullYear(), m=d.getMonth(), day=d.getDate(), start, end;
            if (mode === 'monthly') {
                start = new Date(y, m, 1);
                end = new Date(y, m + 1, 0);
                return {start:start, end:end};
            }
            if (mode === 'bi_weekly') {
                // Same epoch as payroll_master_ledger: 2024-01-01, 14-day blocks
                var utcDay = Date.UTC(y, m, day);
                var epoch = Date.UTC(2024, 0, 1);
                var block = Math.floor((utcDay - epoch) / 86400000 / 14);
                var startUtc = epoch + block * 14 * 86400000;
                var endUtc = startUtc + 13 * 86400000;
                start = new Date(startUtc + 12 * 3600000); // noon avoid DST edge
                end = new Date(endUtc + 12 * 3600000);
                start = new Date(start.getFullYear(), start.getMonth(), start.getDate());
                end = new Date(end.getFullYear(), end.getMonth(), end.getDate());
                return {start:start, end:end};
            }
            // semi_monthly default
            if(day<=15){ start=new Date(y,m,1); end=new Date(y,m,15); }
            else { start=new Date(y,m,16); end=new Date(y,m+1,0); }
            return {start:start, end:end};
        }
        function navigateDay(direction){
            var fromVal = document.getElementsByName('from_date')[0].value;
            var d = (direction === 'today') ? new Date() : (fromVal ? new Date(fromVal+'T12:00:00') : new Date());
            if(direction==='prev') d.setDate(d.getDate()-1);
            else if(direction==='next') d.setDate(d.getDate()+1);
            var ymd = pgToYMD(d);
            pgGoToRange(ymd, ymd);
        }
        function navigatePayPeriod(direction){
            var fromVal = document.getElementsByName('from_date')[0].value;
            var ref = (direction === 'current') ? new Date() : (fromVal ? new Date(fromVal+'T12:00:00') : new Date());
            var period = pgPayPeriodFor(ref);
            if(direction==='prev'){ var b=new Date(period.start); b.setDate(b.getDate()-1); period=pgPayPeriodFor(b); }
            else if(direction==='next'){ var a=new Date(period.end); a.setDate(a.getDate()+1); period=pgPayPeriodFor(a); }
            pgGoToRange(pgToYMD(period.start), pgToYMD(period.end));
        }
        document.addEventListener("DOMContentLoaded", function(){
            var fromVal = document.getElementsByName('from_date')[0].value;
            var dayLbl = document.getElementById('pgDayLabel');
            var perLbl = document.getElementById('pgPeriodLabel');
            var opts = {month:'short', day:'numeric'};
            if(dayLbl){ dayLbl.innerText = fromVal ? new Date(fromVal+'T12:00:00').toLocaleDateString(undefined, opts) : 'Today'; }
            if(perLbl){
                var ref = fromVal ? new Date(fromVal+'T12:00:00') : new Date();
                var p = pgPayPeriodFor(ref);
                perLbl.innerText = p.start.toLocaleDateString(undefined, opts) + ' – ' + p.end.toLocaleDateString(undefined, opts);
            }
        });
    </script>
</body>
</html>