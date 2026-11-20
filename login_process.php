<?php
$_SESSION['user_id']   = $user['id'];
$_SESSION['user_name'] = $user['Name'];
$_SESSION['org_id']    = $user['org_id'];
$_SESSION['user_role'] = (int)$user['user_role'];

// Direct each role to its own specific "Home"
switch ($_SESSION['user_role']) {
    case 1: header("Location: super_admin.php"); break;
    case 2: header("Location: admin_dashboard.php"); break;
    case 3: header("Location: supervisor_view.php"); break;
    case 4: header("Location: kiosk.php"); break;
    default: header("Location: logout.php"); break;
}
exit;
?>