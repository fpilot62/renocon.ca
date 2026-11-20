<?php
/** Path: /security_check.php */

// 1. FORCE ERRORS TO SHOW (This stops the "White Screen of Death")
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$current_page = basename($_SERVER['PHP_SELF']);

// 2. CHECK SESSION
if (!isset($_SESSION['user_id'])) {
    if ($current_page !== 'login_kiosk.php' && $current_page !== 'process_login.php') {
        header("Location: /login_kiosk.php");
        exit;
    }
} else {
    $user_level = (int)$_SESSION['user_level'];

    // 3. HANDLE INDEX REDIRECT
    if ($current_page === 'index.php' || $current_page === 'login_kiosk.php') {
        try {
            // Ensure $pdo exists from engine.php
            if (!isset($pdo)) { throw new Exception("Database connection (PDO) is missing!"); }

            $stmt = $pdo->prepare("SELECT landing_page FROM roles WHERE role_id = ? LIMIT 1");
            
            // FIXED: Removed the extra brackets [[ ]] that caused the crash
            $stmt->execute([$user_level]); 
            $role_data = $stmt->fetch();

            $target = $role_data['landing_page'] ?? 'manage_users.php';
            header("Location: $target");
            exit;
        } catch (Exception $e) {
            die("<div style='background:#fee2e2; border:1px solid #ef4444; padding:20px; color:#b91c1c;'>
                <strong>Security Debug:</strong> " . $e->getMessage() . "</div>");
        }
    }

    // 4. LEVEL PROTECTION
    if (isset($required_level) && $user_level > $required_level) {
        header("Location: unauthorized.php");
        exit;
    }
}