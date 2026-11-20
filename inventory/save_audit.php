<?php
/** * Path: /inventory/save_audit.php 
 * PRODUCTION WORKSPACE - INVENTORY AUDIT SAVE ROUTINE
 * Fix: Removed the non-existent 'audited_by' schema reference to align perfectly with the active database layout.
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Root configuration database connector
require_once dirname(__DIR__) . '/db.php'; 

if (session_status() === PHP_SESSION_NONE) { session_start(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rid   = isset($_POST['rid']) ? (int)$_POST['rid'] : 0;
    $items = isset($_POST['items']) ? $_POST['items'] : [];

    if ($rid <= 0) {
        header('Location: physical_count.php?rid=0&err=pick_site');
        exit;
    }

    if ($rid > 0 && !empty($items)) {
        try {
            // Open transaction for massive insert optimization stability
            $pdo->beginTransaction();
            
            // 🔥 ALIGNED TO YOUR EXACT SCHEMA: Only inserts columns that physically exist in phpMyAdmin
            $stmt = $pdo->prepare("INSERT INTO Gem_inventory_audit (site_id, item_id, qty_recorded) VALUES (?, ?, ?)");
            
            $wrote = 0;
            foreach ($items as $item_id => $d) {
                $typed = isset($d['entered_qty']) && trim((string)($d['entered_qty'] ?? '')) !== '';
                $qty = (isset($d['pcs']) && trim((string)$d['pcs']) !== '') ? (float)$d['pcs'] : 0.00;
                /* Empty catalog Save must not create a fake count day. */
                if (!$typed && abs($qty) < 0.0001) {
                    continue;
                }
                $stmt->execute([
                    $rid,
                    (int)$item_id,
                    $qty
                ]);
                $wrote++;
            }
            if ($wrote === 0) {
                $pdo->rollBack();
                header('Location: physical_count.php?rid=' . $rid . '&err=no_qty');
                exit;
            }
            
            $pdo->commit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            die("Critical Inventory Log System Exception: " . $e->getMessage());
        }
    }
    
    // Redirect back to the audit workspace index view smoothly
    header("Location: audit.php?rid=" . $rid);
    exit;
}