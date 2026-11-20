<?php
/** Path: /engine/auth_check.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

/**
 * @param int $required_level The maximum numerical level allowed to see this page.
 * Example: if $required_level is 20, only levels 10 and 20 can enter.
 */
function protect_page($required_level) {
    // Get user level from session, default to 999 (No access) if not set
    $user_level = isset($_SESSION['user_level']) ? intval($_SESSION['user_level']) : 999;

    // If user level is HIGHER than required (e.g., 40 > 20), they are unauthorized
    if ($user_level > $required_level) {
        header("Location: kiosk.php?error=unauthorized");
        exit();
    }
}
?>