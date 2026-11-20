<?php
/** Path: /bulk_assign_site.php */
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Security: Only admins can bulk assign
if (!isset($_SESSION['user_level']) || (int)$_SESSION['user_level'] > 20) {
    die("Access Denied.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['shift_ids'])) {
    $target_site_id = (int)$_POST['target_site_id'];
    $ids = $_POST['shift_ids']; // Array of attendance IDs

    if ($target_site_id > 0 && !empty($ids)) {
        try {
            // Prepare placeholders for the IN clause (?,?,?)
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            $sql = "UPDATE attendance SET site_id = ? WHERE id IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            
            // Combine target_site_id with the array of IDs for execution
            $params = array_merge([$target_site_id], $ids);
            
            if ($stmt->execute($params)) {
                // Redirect back to audit page with success message
                header("Location: staff_audit.php?msg=BulkSuccess");
                exit;
            }
        } catch (PDOException $e) {
            die("Database Error: " . $e->getMessage());
        }
    }
}

// If something went wrong or direct access, go back
header("Location: staff_audit.php");
exit;