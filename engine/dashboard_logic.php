<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once $path_to_root . 'db.php';

if (!isset($_SESSION['user_id'])) { header("Location: " . $path_to_root . "login_kiosk.php"); exit; }

$role_map = [1 => 'admin', 2 => 'admin', 3 => 'supervisor', 4 => 'staff'];
$my_folder = $role_map[$_SESSION['user_level']] ?? 'staff';

if ($my_folder !== $role_context) {
    header("Location: " . $path_to_root . $my_folder . "/index.php");
    exit;
}

$grouped_logs = []; 

// BLOCK STAFF FROM DATA FETCH: Only run query for levels 1, 2, and 3
if ($_SESSION['user_level'] < 4) {
    try {
        $sql = "SELECT a.*, u.Name as StaffName, s.nick_name as SiteDisplayName, DATE(a.clock_in) as log_date
                FROM attendance a
                JOIN users u ON a.user_id = u.id 
                LEFT JOIN sites s ON a.site_id = s.SId
                WHERE 1=1";
        $params = [];

        if ($role_context === "admin" && $_SESSION['user_level'] == 2) {
            $sql .= " AND u.org_id = :org";
            $params[':org'] = $_SESSION['user_org'];
        } elseif ($role_context === "supervisor") {
            if (!empty($_SESSION['user_sites'])) {
                $sql .= " AND a.site_id IN (" . implode(',', array_map('intval', $_SESSION['user_sites'])) . ")";
            } else { $sql .= " AND 1=0"; }
        }

        $stmt = $pdo->prepare($sql . " ORDER BY a.clock_in DESC LIMIT 100");
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { 
            $grouped_logs[$row['log_date']][] = $row; 
        }
    } catch (Exception $e) { die("Logic Error: " . $e->getMessage()); }
}

include 'dashboard_view.php';