<?php
/** Path: /home/admin/domains/payroll.renocon.ca/public_html/auth_bridge.php */

// 1. Start the standard PHP session (no custom name, no custom path)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. UNIFY VARIABLES
// If they logged in via the portal (which uses user_id), 
// we copy it to UserID (which the scheduler uses).
if (isset($_SESSION['user_id']) && !isset($_SESSION['UserID'])) {
    $_SESSION['UserID'] = $_SESSION['user_id'];
}

// If they logged in via the portal (which uses user_role), 
// we copy it to user_level (which the menu generator uses).
if (isset($_SESSION['user_role']) && !isset($_SESSION['user_level'])) {
    $_SESSION['user_level'] = $_SESSION['user_role'];
}

// 3. SECURITY GATE
// If there is no active session, send them back to the login page.
if (!isset($_SESSION['UserID'])) {
    // Note: We use an absolute path to the login file
    header("Location: /login_kiosk.php");
    exit;
}

// 4. (Optional) DEBUG - Comment this out once working
// echo "Logged in as User: " . $_SESSION['UserID'];
?>