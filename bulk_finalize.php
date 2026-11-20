<?php
/**
 * Path: /bulk_finalize.php
 * ACTION: Minimal background script to process IDs.
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// 1. We ONLY need the database connection
require_once 'db.php'; 

// 2. Logic to process the IDs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['shift_ids'])) {
    
    $ids = array_map('intval', $_POST['shift_ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    try {
        $sql = "UPDATE attendance SET status = '1' WHERE id IN ($placeholders) AND status = '4'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        
        $updated = $stmt->rowCount();

        // 3. Send them back to the audit page to see the result
        header("Location: staff_audit.php?status=1&msg=" . $updated . "+shifts+finalized");
        exit;
        
    } catch (Exception $e) {
        die("Database Error: " . $e->getMessage());
    }
} else {
    header("Location: staff_audit.php?err=No+shifts+selected");
    exit;
}
/**
 * 1. SITE FALLBACK: If site_id is 0, use 'primary_site_id' from user profile.
 * 2. OVERNIGHT LOGIC: If clock_out < clock_in, add 24-hour offset for duration calculation.
 * 3. 8-HOUR CEILING: Bulk finalize automatically caps payroll at 8.00h; overflow moves to balance.
 * 4. TRIPLE AUDIT: Every row must display selfies, GPS Google Maps link, and Weekly Hour context.
 * 5. ROUTING: Checked rows go to 'payroll_finalized'; Balance goes to 'payroll_balance'.
 */