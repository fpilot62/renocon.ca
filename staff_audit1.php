<?php
/**
 * Path: /staff_audit1.php
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
require_once __DIR__ . '/engine/attendance_audit_lib.php'; 

if (file_exists('engine/menu_generator.php')) {
    require_once 'engine/menu_generator.php';
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) { die("Access Denied."); }

// Extract privilege tier levels from your db.php architecture
$sess_user_level = isset($_SESSION['user_level']) ? (int)$_SESSION['user_level'] : 99;
$org_id = (int)($_SESSION['org_id'] ?? 1);

// =========================================================================
// 🔥 EMERGENCY BACKGROUND BULK FIXER: RESOLVES OMITTED SITES AUTOMATICALLY
// =========================================================================
try {
    $fixIdStmt = $pdo->query("
        SELECT a.id FROM attendance a
        INNER JOIN users u ON a.user_id = u.id
        WHERE (a.site_id = '0' OR a.site_id = 'all' OR a.site_id IS NULL)
          AND u.primary_site_id > 0
          AND a.payout_status = 0
    ");
    $fixIds = array_map('intval', $fixIdStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $fixBefore = aa_befores($pdo, $fixIds);
    if ($fixIds) {
        $pdo->query("
            UPDATE attendance a
            INNER JOIN users u ON a.user_id = u.id
            SET a.site_id = u.primary_site_id
            WHERE (a.site_id = '0' OR a.site_id = 'all' OR a.site_id IS NULL)
              AND u.primary_site_id > 0
              AND a.payout_status = 0
        ");
        aa_log_ids($pdo, 'update', $fixIds, $fixBefore, 'Filled a missing site from the employee primary site');
    }
} catch (Exception $e) {}

// =========================================================================
// ⚡ UNIFIED POST ACTION HANDLER: DELETIONS, BREAKS, AND NEW DUPLICATION INJECTOR
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // AJAX ACTION CALL: Clones an attendance record cleanly along with its child breaks
    if ($_POST['action'] === 'duplicate_shift' && !empty($_POST['shift_id'])) {
        header('Content-Type: application/json');
        $target_id = (int)$_POST['shift_id'];
        
        try {
            $srcStmt = $pdo->prepare("SELECT * FROM attendance WHERE id = ?");
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
            
            $new_shift_id = (int)$pdo->lastInsertId();
            aa_log($pdo, 'create', $new_shift_id, null, null, 'Duplicated shift');
            
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
            $clean_ids = array_map('intval', $ids_to_clean);
            $placeholders = implode(',', array_fill(0, count($clean_ids), '?'));
            
            if ($sess_user_level <= 10) {
                $delQuery = "DELETE FROM attendance WHERE id IN ($placeholders)";
            } else {
                $delQuery = "DELETE FROM attendance WHERE id IN ($placeholders) AND org_id = " . (int)$org_id;
            }
            
            $aaBefore = aa_befores($pdo, $clean_ids);
            $delStmt = $pdo->prepare($delQuery);
            $delStmt->execute($clean_ids);
            aa_log_ids($pdo, 'delete', $clean_ids, $aaBefore, 'Staff audit delete');
            
            // Clean out corresponding child breaks automatically
            $pdo->prepare("DELETE FROM breaks WHERE shift_id IN ($placeholders)")->execute($clean_ids);
            
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

// STRIP ENFORCEMENT STABILIZER: Cleans parameters from URL strings
if (!empty($from_date)) { $from_date = trim(preg_replace('/[^0-9\-]/', '', $from_date)); }
if (!empty($to_date)) { $to_date = trim(preg_replace('/[^0-9\-]/', '', $to_date)); }

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

if ($sess_user_level > 10 && isset($_SESSION['org_id']) && $_SESSION['org_id'] > 0) {
    $where[] = "a.org_id = :org_id";
    $params[':org_id'] = (int)$_SESSION['org_id'];
}

if (!empty($from_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) {
    $where[] = "DATE(a.clock_in) >= :from_date";
    $params[':from_date'] = $from_date;
}
if (!empty($to_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
    $where[] = "DATE(a.clock_in) <= :to_date";
    $params[':to_date'] = $to_date;
}
if ($site_filter !== 'all' && $site_filter !== '') { 
    $where[] = "a.site_id = :sid"; 
    $params[':sid'] = (int)$site_filter; 
}

// USER RUNTIME RESOLVER: Safe alphanumeric textual string vs numeric ID parser logic
if ($employee_filter !== 'all' && $employee_filter !== '') { 
    if (is_numeric($employee_filter)) {
        $where[] = "a.user_id = :uid";
        $params[':uid'] = (int)$employee_filter;
    } else {
        $uLook = $pdo->prepare("SELECT id FROM users WHERE user = ? OR Name = ? LIMIT 1");
        $uLook->execute([$employee_filter, $employee_filter]);
        $resolvedUser = $uLook->fetch(PDO::FETCH_ASSOC);
        
        if ($resolvedUser) {
            $where[] = "a.user_id = :uid";
            $params[':uid'] = (int)$resolvedUser['id'];
        } else {
            $where[] = "a.user_id = 0";
        }
    }
}

$sort_filter = $_GET['sort'] ?? 'date_desc';
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
        case 'duration_desc': $order_sql = "TIMESTAMPDIFF(SECOND, a.clock_in, a.clock_out) DESC"; break;
        case 'date_asc':  $order_sql = "a.clock_in ASC"; break;
        case 'date_desc':
        default:          $order_sql = "a.clock_in DESC"; break;
    }
}

try {
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    $query = "SELECT a.*, u.Name as staff_name, u.Email as user_email, u.Tel_Mobile as mobile, 
                     s.nick_name as site_name, s.target_lat, s.target_lng, s.geo_radius_meters  
              FROM attendance a 
              LEFT JOIN users u ON a.user_id = u.id 
              LEFT JOIN sites s ON COALESCE(
                  NULLIF(NULLIF(a.site_id, '0'), 'all'), 
                  NULLIF(u.primary_site_id, 0)
              ) = s.Sid 
              $where_clause 
              ORDER BY $order_sql";
              
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC); 

    $day = (int)date('j');
    $pStart = ($day <= 15) ? date('Y-m-01') : date('Y-m-16');
    $pEnd   = ($day <= 15) ? date('Y-m-15') : date('Y-m-t');

    $batchSQL = "SELECT user_id, SUM(TIMESTAMPDIFF(SECOND, clock_in, clock_out)) as total_seconds 
                 FROM attendance 
                 WHERE DATE(clock_in) BETWEEN ? AND ? AND status != '4'";
    $batchParams = [$pStart, $pEnd];

    if ($sess_user_level > 10 && isset($_SESSION['org_id']) && $_SESSION['org_id'] > 0) {
        $batchSQL .= " AND org_id = ?";
        $batchParams[] = (int)$_SESSION['org_id'];
    }
    $batchSQL .= " GROUP BY user_id";

    $periodBatch = $pdo->prepare($batchSQL);
    $periodBatch->execute($batchParams);
    $batchData = $periodBatch->fetchAll(PDO::FETCH_KEY_PAIR);

    $sites = $_SESSION['inventory_sites'] ?? [];
    
    if ($sess_user_level > 10 && isset($_SESSION['org_id']) && $_SESSION['org_id'] > 0) {
        $staffStmt = $pdo->prepare("SELECT id, user, Name FROM users WHERE org_id = ? ORDER BY Name ASC");
        $staffStmt->execute([(int)$_SESSION['org_id']]);
        $staff = $staffStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $staff = $pdo->query("SELECT id, user, Name FROM users ORDER BY Name ASC")->fetchAll(PDO::FETCH_ASSOC);
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

$total_accumulated_seconds = 0;
$group_totals_lookup = [];

if (!empty($rows)) {
    foreach ($rows as $sumRow) {
        $s_in  = !empty($sumRow['clock_in']) ? strtotime($sumRow['clock_in']) : 0;
        $s_out = (!empty($sumRow['clock_out']) && $sumRow['clock_out'] != '0000-00-00 00:00:00') ? strtotime($sumRow['clock_out']) : time();
        
        $row_breaks_seconds = 0;
        if (trim((string)($sumRow['status'] ?? '0')) !== '5' && !empty($sumRow['id'])) {
            $bCalcStmt = $pdo->prepare("SELECT break_start, break_end FROM breaks WHERE shift_id = ?");
            $bCalcStmt->execute([$sumRow['id']]);
            foreach ($bCalcStmt->fetchAll(PDO::FETCH_ASSOC) as $cbRow) {
                if (!empty($cbRow['break_end'])) { $row_breaks_seconds += abs(strtotime($cbRow['break_end']) - strtotime($cbRow['break_start'])); }
            }
        }
        
        $net_row_diff = ($s_out >= $s_in) ? (($s_out - $s_in) - $row_breaks_seconds) : 0;
        if ($net_row_diff < 0) $net_row_diff = 0;
        
        $total_accumulated_seconds += $net_row_diff;
        
        // Populate group allocations
        $d_name = !empty($sumRow['staff_name']) ? (string)$sumRow['staff_name'] : "Unknown Worker";
        $g_key = ($group_by == 'date') ? date('l, M j, Y', strtotime($sumRow['clock_in'] ?? 'now')) : ($group_by == 'staff' ? $d_name : ($sumRow['site_name'] ?? 'Unassigned'));
        
        if (!isset($group_totals_lookup[$g_key])) { $group_totals_lookup[$g_key] = 0; }
        $group_totals_lookup[$g_key] += $net_row_diff;
    }
}
$aggregated_total_hours = round($total_accumulated_seconds / 3600, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Audit Dashboard</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f7f6; margin: 0; padding-top: 115px; font-size: 11px; }
        .filter-section { background: white; margin: 0 20px 20px; padding: 15px; border-radius: 8px; display: flex; gap: 12px; align-items: flex-end; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .container { position: relative; background: white; margin: 0 20px; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 40px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #2c3e50; color: #ecf0f1; padding: 10px; text-align: left; text-transform: uppercase; font-size: 10px; }
        
        .group-header { background: #e2e8f0 !important; font-weight: bold; color: #0f172a; border-top: 2px solid #cbd5e1; height: 38px; cursor: pointer; transition: background 0.2s; user-select: none; }
        .group-header:hover { background: #cbd5e1 !important; }
        .group-header td { padding: 10px; font-size: 12px; font-weight: 700; color: #1e293b; }
        .toggle-icon { margin-right: 8px; transition: transform 0.2s ease-in-out; display: inline-block; color: #64748b; }
        .collapsed-group .toggle-icon { transform: rotate(-90deg); }
        .hidden-row { display: none !important; }

        .group-badge-hours { background: #3b82f6; color: white; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: bold; float: right; margin-top: 1px; box-shadow: 0 2px 4px rgba(59,130,246,0.2); }

        td { padding: 10px; border-bottom: 1px solid #eee; vertical-align: top; }
        .selfie-thumb { width: 45px; height: 45px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd; cursor: zoom-in; margin-bottom: 2px; }
        .staff-info { font-size: 9px; color: #64748b; line-height: 1.3; margin-top: 4px; }
        .staff-link { text-decoration: none; color: #2563eb; font-weight: bold; font-size: 12px; }
        .email-link { color: #6366f1; text-decoration: none; }
        .btn-green { background: #27ae60; color: white; border: none; padding: 8px 12px; border-radius: 5px; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-block; }
        .btn-red { background: #dc2626; color: white; border: none; padding: 8px 12px; border-radius: 5px; cursor: pointer; font-weight: bold; margin-left: 8px; }
        .badge-transfer { background: #d97706; color: white; padding: 2px 6px; border-radius: 4px; font-size: 9px; font-weight: bold; display: inline-block; text-align: center; text-transform: uppercase; }
        .badge-normal { background: #475569; color: white; padding: 2px 6px; border-radius: 4px; font-size: 9px; font-weight: bold; display: inline-block; text-align: center; text-transform: uppercase; }
        .badge-error { background: #ef4444; color: white; padding: 2px 6px; border-radius: 4px; font-size: 9px; font-weight: bold; display: inline-block; text-align: center; text-transform: uppercase; }
        
        .status-indicator-badge { padding: 2px 6px; border-radius: 4px; font-size: 9px; font-weight: bold; display: inline-block; margin-top: 4px; text-transform: uppercase; }
        .status-payout-pending { background: #fef3c7; color: #d97706; border: 1px solid #f59e0b; }
        .status-payout-finalized { background: #dcfce7; color: #16a34a; border: 1px solid #22c55e; }

        .time-box { display: inline-block; width: 110px; font-size: 10px; padding: 2px; border: 1px solid #cbd5e1; border-radius: 4px; text-align: center; background: #f8fafc; color: #334155; font-weight: 500; }
        select, input[type="date"] { padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; background: white; font-size: 11px; font-weight: 600; color: #1e293b; }
        .summary-hours-pill { background: linear-gradient(135deg, #1e3a8a, #3b82f6); color: white; padding: 8px 16px; border-radius: 10px; display: inline-flex; align-items: center; gap: 10px; font-weight: bold; font-size: 12px; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3); border: 1px solid rgba(255, 255, 255, 0.1); }
        .sticky-copy-box { position: -webkit-sticky; position: sticky; left: 20px; display: inline-block; z-index: 10; }
        .btn-copy-loading { background: #cbd5e1 !important; color: #94a3b8 !important; cursor: not-allowed !important; pointer-events: none !important; opacity: 0.6; }
        .btn-copy-loading i { animation: spin 1s linear infinite; }
        
        /* 🎭 TRANSLUCENT OVERLAY SANDBOX VIEWPORT DEFINITIONS */
        .break-editor-modal { display: none; position: fixed; z-index: 999999; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.55); backdrop-filter: blur(5px); align-items: center; justify-content: center; }
        .modal-content-card { background: white; width: 100%; max-width: 520px; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); border: 1px solid #cbd5e1; padding: 24px; animation: modalSlide 0.2s ease-out; }
        .break-input-row { display: flex; gap: 8px; align-items: center; margin-bottom: 10px; background: #f8fafc; padding: 8px; border-radius: 8px; border: 1px solid #e2e8f0; }
        .modal-btn-save { background: #2563eb; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; }
        .modal-btn-cancel { background: #e2e8f0; color: #475569; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; margin-right: 8px; }
        .btn-add-row { background: #10b981; color: white; border: none; padding: 6px 12px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 11px; margin-bottom: 15px; }
        .icon-action-trigger { cursor: pointer; transition: color 0.1s ease; }
        .icon-action-trigger:hover { color: #2563eb !important; }
        #status-msg-alert { position: fixed; top: 140px; right: 30px; padding: 12px 20px; border-radius: 6px; display: none; z-index: 999999; color: white; font-weight: bold; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        @keyframes modalSlide { from { transform: translateY(15px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>

    <?php 
    if (function_exists('generateMenu')) echo generateMenu($pdo); 
    session_write_close();
    ?>

    <div id="status-msg-alert"></div>

    <div id="imgModal" style="display: none; position: fixed; z-index: 99999; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.85); align-items: center; justify-content: center; cursor: zoom-out;">
        <img id="modalImg" src="" style="max-width: 90vw; max-height: 85vh; border: 4px solid white; border-radius: 8px; box-shadow: 0 20px 40px rgba(0,0,0,0.6);">
    </div>

    <div id="breakModal" class="break-editor-modal">
        <div class="modal-content-card">
            <h5 style="margin-top:0; font-weight:800; color:#1e293b; font-size:14px; margin-bottom:6px;"><i class="fas fa-clock" style="color:#2563eb;"></i> Adjust Shift Break Windows</h5>
            <p style="color:#64748b; font-size:11px; margin-bottom:15px;">Modify existing timestamps or append supplementary rest intervals below. Leave end-times open if active.</p>
            <form id="breakForm" method="POST" action="staff_audit1.php">
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
                <button type="button" onclick="closeAllocationModal()" style="font-size:20px; font-weight:bold; cursor:pointer; background:none; border:none; color:#64748b;">×</button>
            </div>
            <p style="color:#64748b; font-size:11px; margin-bottom:20px; margin-top:0;">Configure split parameters, issue lump-sum bonuses, and securely process batch records formulas.</p>
            
            <form method="POST" action="bulk_allocation_desk.php">
                <div id="modal_hidden_ids_container"></div>
                
                <div class="input-group" style="margin-bottom: 15px;">
                    <label style="display:block; font-weight:800; font-size:10px; text-transform:uppercase; color:#475569; margin-bottom:6px;">Select Target Action Formula</label>
                    <select name="target_action_formula" id="formulaDropdownSelector" onchange="toggleBonusInputLayer(this.value)" style="width:100%; padding:8px; border-radius:6px; border:1px solid #cbd5e1; font-weight:600; font-size:11px; height:36px;">
                        <option value="overtime">Overtime after Hrs in A</option>
                        <option value="transit">Transit Time</option>
                        <option value="bonus">Bonus</option>
                        <option value="balance" selected>Balance after Hrs in A</option>
                    </select>
                </div>
                
                <div class="input-group" style="margin-bottom: 15px;">
                    <label style="display:block; font-weight:800; font-size:10px; text-transform:uppercase; color:#475569; margin-bottom:6px;">Regular Hours Cap Split Trigger Limit</label>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <input type="number" step="0.01" name="regular_hours_limit" value="8.00" style="width:100px; text-align:center; padding:6px; border:1px solid #cbd5e1; border-radius:6px; font-weight:600; height:34px;">
                        <span style="font-weight:700; color:#64748b; font-size:11px;">Hours Baseline Cap Target</span>
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
            </form>
        </div>
    </div>

    <div class="filter-section">
        <form method="GET" style="display: flex; gap: 12px; align-items: flex-end;">
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort_filter) ?>">
            <div><label>From</label><br><input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>"></div>
            <div><label>To</label><br><input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>"></div>
            <div><label>Staff</label><br>
                <select name="user_id">
                    <option value="all">All Staff</option>
                    <?php foreach($staff as $u): 
                        $selected = ((string)$employee_filter === (string)$u['user'] || (string)$employee_filter === (string)$u['id']) ? 'selected' : '';
                        $display_pill = htmlspecialchars($u['Name']) . " (" . htmlspecialchars($u['user'] ?? $u['id']) . ")";
                        echo "<option value='" . htmlspecialchars($u['user'] ?? $u['id']) . "' {$selected}>{$display_pill}</option>";
                    endforeach; ?>
                </select>
            </div>
            <div><label>Site</label><br>
                <select name="site_id">
                    <option value="all">All Sites</option>
                    <?php foreach($sites as $s): 
                        $resolved_sid = isset($s['Sid']) ? $s['Sid'] : ($s['id'] ?? 0);
                        $selected = ((string)$site_filter === (string)$resolved_sid) ? 'selected' : '';
                        echo "<option value='{$resolved_sid}' {$selected}>" . htmlspecialchars($s['nick_name'] ?? 'Unnamed Site') . "</option>";
                    endforeach; ?>
                </select>
            </div>
            
            <div><label style="color:#2563eb; font-weight:bold;"><i class="fas fa-filter"></i> Status Type</label><br>
                <select name="status_type" style="border:1px solid #2563eb; background:#f0f9ff; color:#1e40af;">
                    <option value="pending" <?= $status_type === 'pending' ? 'selected' : '' ?>>Pending (Unfinalized)</option>
                    <option value="finalized" <?= $status_type === 'finalized' ? 'selected' : '' ?>>Finalized (Paid)</option>
                    <option value="all" <?= $status_type === 'all' ? 'selected' : '' ?>>All Records (Everything)</option>
                </select>
            </div>

            <div><label>Group By</label><br>
                <select name="group_by">
                    <option value="none" <?= $group_by==='none'?'selected':'' ?>>No Grouping</option>
                    <option value="date" <?= $group_by==='date'?'selected':'' ?>>Date</option>
                    <option value="staff" <?= $group_by==='staff'?'selected':'' ?>>Staff Member</option>
                </select>
            </div>
            <button type="submit" style="background:#3498db; color:white; border:none; padding:7px 15px; border-radius:4px; cursor:pointer; font-weight:bold;"><i class="fas fa-search"></i> Search</button>
        </form>

        <div style="margin-left:15px; margin-bottom:2px;">
            <div class="summary-hours-pill">
                <i class="fas fa-stopwatch" style="font-size:15px; color:#60a5fa;"></i>
                <div>
                    <span style="font-size:9px; color:#93c5fd; display:block; font-weight:500; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:-2px;">Filtered Subset Work Duration</span>
                    <span style="font-size:14px; letter-spacing:-0.3px;"><?= $aggregated_total_hours ?> <small style="font-size:10px; color:#93c5fd; font-weight:500;">Hours</small></span>
                </div>
            </div>
        </div>

        <div style="margin-left:auto; display: flex;">
            <button type="button" onclick="window.print()" class="btn-blue" style="background:#0284c7; color:white; border:none; padding:8px 12px; border-radius:5px; cursor:pointer; font-weight:bold; margin-right:8px;">
                <i class="fas fa-print"></i> Print Records
            </button>
            <button type="button" onclick="exportToCSV()" class="btn-green" style="background:#0d9488; color:white; border:none; padding:8px 12px; border-radius:5px; cursor:pointer; font-weight:bold; margin-right:8px;">
                <i class="fas fa-file-excel"></i> Export CSV
            </button>
            <button onclick="bulkFinalize()" class="btn-green">Finalize Selected</button>
            <button onclick="bulkDelete()" class="btn-red"><i class="fas fa-trash-alt"></i> Delete Selected</button>
        </div>
    </div>

    <div class="container">
    <table id="auditTable">
        <thead>
            <tr>
                <th width="30"><input type="checkbox" id="masterCheck"></th>
                <th>Selfies</th>
                <th>Staff Member</th>
                <th>Site (Clocked-In Location)</th>
                <th>Punches</th>
                <th>Breaks</th>
                <th>Duration</th>
                <th width="140">
                    <div class="sticky-copy-box">
                        <button type="button" id="globalCopyBtn" class="btn-copy-loading" style="font-weight:bold; font-size:10px; padding:4px 8px; border-radius:4px; border:none; background:#475569; color:white;">
                            <i class="fas fa-spinner"></i> Loading...
                        </button>
                    </div>
                </th>
                <th>Action</th>
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
                            <td colspan="9">
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
                    
                    $map_url = "https://maps.google.com/?q=" . trim((string)$lat_in) . "," . trim((string)$lng_in);
                    if (!empty($lat_out)) { 
                        $map_url = "https://www.google.com/maps/dir/" . trim((string)$lat_in) . "," . trim((string)$lng_in) . "/" . trim((string)$lat_out) . "," . trim((string)$lng_out); 
                    }
                    
                    $row_bg_style = '';
                    if ($is_transit) { $row_bg_style = 'style="background: #fffbeb;"'; }
                    elseif ($is_split) { $row_bg_style = 'style="background: #f0fdf4;"'; }
                    
                    $row_class_tag = ($group_by !== 'none') ? 'data-parent-group="' . $group_id . '" class="child-shift-row"' : '';
                ?>
                <tr id="row-<?= $r['id'] ?>" <?= $row_class_tag ?> <?= $row_bg_style ?>>
                    <td><input type="checkbox" class="rec-check" value="<?= $r['id'] ?>"></td>
                    <td>
                        <div style="display:flex; gap:4px;">
                            <img src="<?= htmlspecialchars((string)($r['selfie_in'] ?? 'nophoto.jpg')) ?>" class="selfie-thumb zoomable-image" style="cursor:zoom-in;">
                            <img src="<?= htmlspecialchars((string)($r['selfie_out'] ?? 'nophoto.jpg')) ?>" class="selfie-thumb zoomable-image" style="cursor:zoom-in;">
                        </div>
                    </td>
                    <td>
                        <a href="edit_user.php?id=<?= $r['user_id'] ?>" class="staff-link"><?= htmlspecialchars($display_name) ?></a>
                        <div class="staff-info">
                            ID: <?= $r['user_id'] ?> | Cell: <?= htmlspecialchars((string)($r['mobile'] ?? '')) ?><br>
                            <a href="mailto:<?= htmlspecialchars((string)$r['user_email'] ?? '') ?>" class="email-link"><?= htmlspecialchars((string)$r['user_email'] ?? '') ?></a>
                        </div>
                        <div class="action-icons" style="margin-top: 8px; display: flex; gap: 12px; font-size: 13px;">
                            <a href="edit_shift.php?id=<?= $r['id'] ?>" style="color:#eab308; text-decoration:none;" title="Modify Master Punch Times"><i class="fas fa-pencil-alt icon-action-trigger"></i></a>
                            
                            <span style="color:#3b82f6; cursor:pointer;" title="Adjust Rest/Break Windows" onclick="openBreakModal(<?= $r['id'] ?>, <?= htmlspecialchars(json_encode($all_breaks)) ?>)"><i class="fas fa-clock icon-action-trigger"></i></span>
                            
                            <span style="color:#10b981; cursor:pointer;" title="Duplicate Shift as New Entry" onclick="duplicateShiftRow(<?= $r['id'] ?>)"><i class="fas fa-copy icon-action-trigger"></i></span>
                            
                            <span style="color:#dc2626; cursor:pointer;" title="Delete Shift Record" onclick="deleteShift(<?= $r['id'] ?>)"><i class="fas fa-trash-alt icon-action-trigger"></i></span>
                        </div>
                    </td>
                    <td>
                        <?php if ($is_transit): ?>
                            <span style="color: #d97706; font-weight: bold; background: #fef3c7; padding: 3px 8px; border-radius: 4px; border: 1px dashed #f59e0b;"><i class="fas fa-truck-moving"></i> Travel Corridor: From <?= htmlspecialchars((string)($r['site_name'] ?? 'Origin Site')) ?></span>
                        <?php elseif ($is_split): ?>
                            <span style="color: #16a34a; font-weight: bold; background: #dcfce7; padding: 3px 8px; border-radius: 4px; border: 1px dashed #22c55e;"><i class="fas fa-pie-chart"></i> Split Allocation Layer</span>
                        <?php elseif (!empty($r['site_name'])): ?>
                            <?= htmlspecialchars((string)$r['site_name']) ?>
                        <?php else: ?>
                            <span style="color: #ef4444; font-weight: 600; background: #fee2e2; padding: 2px 6px; border-radius: 4px;">⚠️ No Primary Site</span>
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

                        <?php 
                        $captured_reason_string = trim((string)($r['flag_reason'] ?? ($r['remarks'] ?? ($r['notes'] ?? ''))));
                        if (!empty($captured_reason_string)): 
                        ?>
                            <div style="margin-top: 8px; font-size: 10px; background: #fee2e2; color: #991b1b; padding: 6px 10px; border-radius: 6px; border-left: 4px solid #dc2626; font-weight: bold; max-width: 220px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                                <i class="fas fa-exclamation-circle" style="color:#dc2626;"></i> Variance Note:<br>
                                <span style="font-weight: 500; color:#334155; display:block; margin-top:2px; font-style:italic;">"<?= htmlspecialchars($captured_reason_string) ?>"</span>
                            </div>
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
                                        #<?= $index + 1 ?>: <?= date('M j, h:i A', $b_start) ?> → <?= $b_end ? date('M j, h:i A', $b_end) : 'Open' ?> (<strong><?= $b_dur ?></strong>)
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
                    <td>
                        <?php if($lat_in): ?>
                            <a href="<?= htmlspecialchars($map_url) ?>" target="_blank" style="text-decoration:none; font-weight:bold; color:#2563eb;"><i class="fas fa-map-marked-alt"></i> Route Map</a><br>
                            
                            <?php if (!$is_transit && !$is_split): 
                                $dist_in = (float)($dist_in ?? 0); $max_r = (float)($r['geo_radius_meters'] ?? 200);
                                $local_tolerance_pct = 1.00; 
                                if (isset($siteOverridesCache[$r['site_id']]['site_geo_tolerance_pct'])) { $local_tolerance_pct = (float)$siteOverridesCache[$r['site_id']]['site_geo_tolerance_pct']; } 
                                elseif (isset($globalPolicyCache['global_geo_tolerance_pct'])) { $local_tolerance_pct = (float)$globalPolicyCache['global_geo_tolerance_pct']; }

                                $tolerance_factor = $local_tolerance_pct / 100;
                                $orange_threshold = $max_r * (1 + $tolerance_factor);

                                if ($dist_in <= $max_r) { $pin_color = "#16a34a"; $pin_icon  = "✅ Inside Radius"; } 
                                elseif ($dist_in > $max_r && $dist_in <= $orange_threshold) { $pin_color = "#ea580c"; $pin_icon  = "⚠️ Warning Buffer (" . $local_tolerance_pct . "%)"; } 
                                else { $pin_color = "#dc2626"; $pin_icon  = "🚨 Geofence Breach"; }
                            ?>
                                <span style="color: <?= $pin_color ?>; font-weight: 800; display: block; margin-top: 3px; font-size: 10px;">
                                    <?= $pin_icon ?> (<?= round($dist_in) ?>m / <?= round($max_r) ?>m)
                                </span>
                            <?php elseif ($is_split): ?>
                                <small style="color:#16a34a; font-weight:bold;"><i class="fas fa-clone"></i> System Pre-Approved</small>
                            <?php else: ?>
                                <small style="color:#d97706; font-weight:bold;"><i class="fas fa-satellite-dish"></i> Transit GPS Active</small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#dc2626; font-weight:bold;"><i class="fas fa-exclamation-triangle"></i> No GPS Data</span>
                        <?php endif; ?>
                    </td>
                    <td><a href="finalize_shift.php?id=<?= $r['id'] ?>&corridor_route=<?= urlencode($is_transit ? (!empty($raw_transfer_status) && $transfer_status !== 'travel' ? $raw_transfer_status : $r['site_name']) : '') ?>" class="btn-green" style="text-decoration:none; display:inline-block; padding:5px 10px;">Finalize</a></td>
                </tr>
                <?php endforeach; 
            else: ?>
                <tr><td colspan="9" style="text-align:center; padding:40px; color:#94a3b8; font-style:italic;">No records found for the selected criteria.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

    <script>
        let activeShiftDateContext = '';

        document.addEventListener("DOMContentLoaded", function() {
            var modal = document.getElementById('imgModal');
            var modalImg = document.getElementById('modalImg');

            document.querySelectorAll('.zoomable-image').forEach(function(img) {
                img.addEventListener('click', function() {
                    if (modal && modalImg && this.src && !this.src.includes('nophoto.jpg')) {
                        modalImg.src = this.src; modal.style.display = 'flex';
                    }
                });
            });

            if (modal) { modal.addEventListener('click', function() { this.style.display = 'none'; }); }

            const copyBtn = document.getElementById('globalCopyBtn');
            const tableBody = document.querySelector("#auditTable tbody");
            
            if (copyBtn && tableBody && tableBody.rows.length > 0 && !tableBody.rows[0].innerText.includes('No records found')) {
                copyBtn.classList.remove('btn-copy-loading');
                copyBtn.innerHTML = '<i class="fas fa-copy"></i> Copy Dataset';
                copyBtn.style.background = '#0284c7'; copyBtn.style.pointerEvents = 'auto'; copyBtn.style.cursor = 'pointer';
                copyBtn.onclick = function() { exportToClipboardAction(); };
            } else if (copyBtn) {
                copyBtn.innerHTML = '<i class="fas fa-ban"></i> Empty Table';
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
                                  "&user_id=<?= urlencode($employee_filter) ?>" + 
                                  "&site_id=<?= urlencode($site_filter) ?>" + 
                                  "&status_type=<?= urlencode($status_type) ?>" + 
                                  "&group_by=<?= urlencode($group_by) ?>";
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
                    cols[6] ? cols[6].innerText.replace(/\n/g, " ").trim() : ""
                ];
                textParts.push(lineData.join("\t"));
            }
            var clipboardContent = textParts.join("\n");
            navigator.clipboard.writeText(clipboardContent).then(function() {
                alert("📋 Production records copied smoothly to clipboard!");
            }).catch(function(err) { alert("Clipboard allocation exception."); });
        }

        function bulkDelete() {
            let checked = document.querySelectorAll('.rec-check:checked');
            let ids = Array.from(checked).map(cb => cb.value);
            if (ids.length === 0) return alert("Select records first.");
            if (confirm(`Are you sure you want to permanently delete all ${ids.length} selected records?`)) {
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
        // 🎭 DYNAMIC MODAL INITIALIZER INTERFACE HOOK
        // =========================================================================
        function bulkFinalize() {
            let checked = document.querySelectorAll('.rec-check:checked');
            let ids = Array.from(checked).map(cb => cb.value);
            if (ids.length === 0) return alert("Please mark target checkmark records from your list view first.");
            
            const container = document.getElementById('modal_hidden_ids_container');
            container.innerHTML = '';
            
            ids.forEach(id => {
                let input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'record_ids[]';
                input.value = parseInt(id);
                container.appendChild(input);
            });

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

        function deleteShift(id) {
            if (confirm("Are you sure you want to delete this shift entry from the database?")) {
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
            var headers = ["Staff Member", "Site (Clocked-In)", "Punches", "Breaks", "Duration (Shift | Period)", "Geo Pins"];
            csv.push(headers.map(h => '"' + h + '"').join(","));
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].classList.contains('group-header') || rows[i].cells.length < 5) continue;
                var row = [], cols = rows[i].querySelectorAll("td");
                let staffText = cols[2] ? cols[2].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim() : ""; row.push('"' + staffText.replace(/"/g, '""') + '"');
                let siteText = cols[3] ? cols[3].innerText.trim() : ""; row.push('"' + siteText.replace(/"/g, '""') + '"');
                let punchesText = cols[4] ? cols[4].innerText.replace(/(\r\n|\n|\r)/gm, " | ").trim() : ""; row.push('"' + punchesText.replace(/"/g, '""') + '"');
                let breaksText = cols[5] ? cols[5].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim() : ""; row.push('"' + breaksText.replace(/"/g, '""') + '"');
                let durationText = cols[6] ? cols[6].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim() : ""; row.push('"' + durationText.replace(/"/g, '""') + '"');
                let gpsText = cols[7] ? cols[7].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim() : ""; row.push('"' + gpsText.replace(/"/g, '""') + '"');
                csv.push(row.join(","));         
            }
            var csvContent = "\uFEFF" + csv.join("\n");
            var csvFile = new Blob([csvContent], {type: "text/csv;charset=utf-8;"});
            var downloadLink = document.createElement("a"); downloadLink.download = "Staff_Audit_Report_" + new Date().toISOString().slice(0,10) + ".csv"; downloadLink.href = window.URL.createObjectURL(csvFile); downloadLink.style.display = "none"; document.body.appendChild(downloadLink); downloadLink.click(); document.body.removeChild(downloadLink);
        }
    </script>
</body>
</html>