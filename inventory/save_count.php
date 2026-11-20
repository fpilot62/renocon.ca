<?php
/** Path: /inventory/save_count.php */
require_once dirname(__DIR__) . '/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rid = (int)$_POST['rid'];
    if ($rid <= 0) {
        header('Location: physical_count.php?rid=0&err=pick_site');
        exit;
    }
    $org_id = (int)($_SESSION['org_id'] ?? 1);
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $count_date = $_POST['count_date'] ?? date('Y-m-d');

    try {
        $pdo->beginTransaction();

        // Using ON DUPLICATE KEY UPDATE so if they count the same item twice on the same day, it just updates.
        $sql = "INSERT INTO Gem_inventory_counts (org_id, site_id, item_id, qty_on_hand, counted_by, count_date) 
                VALUES (?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE qty_on_hand = VALUES(qty_on_hand), counted_by = VALUES(counted_by)";
        
        $stmt = $pdo->prepare($sql);

        foreach ($_POST['count'] as $item_id => $data) {
            $final_qty = (float)$data['final_qty'];
            
            // Only save if a value was actually entered (avoids zeroing out everything)
            if ($data['qty'] !== '') {
                $stmt->execute([
                    $org_id, 
                    $rid, 
                    $item_id, 
                    $final_qty, 
                    $user_id, 
                    $count_date
                ]);
            }
        }

        $pdo->commit();
        // Send them to the audit page to see the variance results
        header("Location: audit.php?rid=$rid&msg=InventorySaved");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die("Error saving physical count: " . $e->getMessage());
    }
}