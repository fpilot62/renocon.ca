<?php
/** Path: /update_staff_assignments.php */
ob_start();
// This handles the silence logic we discussed for engine.php
$silence_css = true; 
require_once 'engine.php';

if (!isset($_SESSION['org_id'])) { die("Session Expired."); }

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['site_id'])) {
    $site_id = intval($_POST['site_id']);
    $user_ids = isset($_POST['employee_ids']) ? array_map('intval', $_POST['employee_ids']) : [];

    try {
        $pdo->beginTransaction();

        // 1. Clear existing assignments for this site
        $pdo->prepare("DELETE FROM site_assignments WHERE site_id = ?")->execute([$site_id]);

        // 2. Insert new assignments with Role Detection
        if (!empty($user_ids)) {
            // We fetch the roles of the users being assigned to set 'is_manager' correctly
            $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
            $role_stmt = $pdo->prepare("SELECT id, user_role FROM users WHERE id IN ($placeholders)");
            $role_stmt->execute($user_ids);
            $user_roles = $role_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $insert = $pdo->prepare("INSERT INTO site_assignments (site_id, user_id, is_manager) VALUES (?, ?, ?)");
            
            foreach ($user_ids as $uid) {
                // If their role in the 'users' table is 'manager', we mark is_manager as 1
                $is_manager = (isset($user_roles[$uid]) && $user_roles[$uid] === 'manager') ? 1 : 0;
                $insert->execute([$site_id, $uid, $is_manager]);
            }
        }

        $pdo->commit();
        header("Location: manage_sites.php?msg=Personnel updated for Site #$site_id");
    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: manage_sites.php?msg=Error: " . urlencode($e->getMessage()));
    }
    exit;
}