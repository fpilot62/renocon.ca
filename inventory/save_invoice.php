<?php
/** Path: /inventory/save_invoice.php */
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. DATABASE CONNECTION
require_once dirname(__DIR__) . '/db.php';
$db = $pdo;

// 2. CHECK IF DATA WAS POSTED
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['items'])) {
    
    $rid = (int)$_POST['rid'];
    $vendor_id = (int)$_POST['vendor_id'];
    $invoice_no = $_POST['invoice_no'];
    $delivery_date = $_POST['delivery_date'];
    $org_id = $_SESSION['org_id'] ?? 1;

    // A. Create the Main Invoice Entry (The Header)
    $stmt1 = $db->prepare("INSERT INTO Gem_inventory_invoices 
        (invoice_no, vendor_id, site_id, org_id, delivery_date, total_amount) 
        VALUES (?, ?, ?, ?, ?, 0)");
    $stmt1->execute([$invoice_no, $vendor_id, $rid, $org_id, $delivery_date]);
    
    $invoice_id = $db->lastInsertId();

    // B. Prepare to insert the individual items (The Details)
    $stmt2 = $db->prepare("INSERT INTO Gem_inventory_invoice_details 
        (invoice_id, item_id, site_id, qty_received, pcs_in_box) 
        VALUES (?, ?, ?, ?, ?)");
    
    $item_count = 0;

    foreach ($_POST['items'] as $item_id => $data) {
        $qty_boxes = (float)$data['qty_boxes'];
        $total_pcs = (float)$data['total_pcs'];

        // Only save items where a quantity was actually entered
        if ($qty_boxes > 0) {
            $pcs_per_box = $total_pcs / $qty_boxes;
            
            $stmt2->execute([
                $invoice_id, 
                $item_id, 
                $rid, 
                $qty_boxes, 
                $pcs_per_box
            ]);
            $item_count++;
        }
    }

    // 3. SUCCESS MESSAGE & REDIRECT
    echo "
    <div style='font-family:sans-serif; text-align:center; padding:80px; background:#f8fafc; height:100vh;'>
        <div style='background:white; display:inline-block; padding:40px; border-radius:15px; shadow: 0 10px 15px rgba(0,0,0,0.1); border:1px solid #e2e8f0;'>
            <h1 style='color:#10b981; margin-bottom:10px;'>Invoice #$invoice_no Saved!</h1>
            <p style='color:#64748b;'>Successfully added <b>$item_count</b> items to the inventory for Site $rid.</p>
            <hr style='border:0; border-top:1px solid #f1f5f9; margin:20px 0;'>
            <a href='index.php' style='background:#0ea5e9; color:white; padding:12px 25px; border-radius:8px; text-decoration:none; font-weight:bold; display:inline-block;'>Go to Dashboard</a>
            <a href='add_invoice.php?rid=$rid' style='margin-left:15px; color:#64748b; font-weight:bold;'>Add Another Invoice</a>
        </div>
    </div>";

} else {
    // If someone tries to access this page directly without submitting the form
    header("Location: add_invoice.php");
    exit;
}
?>