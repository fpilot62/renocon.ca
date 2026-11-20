<?php
/** Path: /inventory/bulk_import.php */
include("../sip/db_config.php");
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// --- CSV TEMPLATE GENERATOR ---
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="inventory_template.csv"');
    $output = fopen('php://output', 'w');
    // Header row matching your exact requested fields
    fputcsv($output, [
        'Name', 'Unit', 'Qty Per Unit', 'Pcs In Bag', 'Pcs In Box', 
        'Conversion Rate', 'Sort', 'No Of Bags', 'Cost Box', 'Cost Pc'
    ]);
    // Sample Popeyes Data
    fputcsv($output, ['CHICKEN BONELESS BITES', 'LB', '20.00', '2', '40', '1.0', '1', '2', '80.00', '2.00']);
    fclose($output);
    exit;
}

require_once dirname(__DIR__) . '/db.php';

include("../engine/menu_generator.php");

// --- PROCESSING THE IMPORT ---
if(isset($_POST["import"])) {
    $fileName = $_FILES["file"]["tmp_name"];
    if ($_FILES["file"]["size"] > 0) {
        $file = fopen($fileName, "r");
        fgetcsv($file); // Skip header row
        
        // Dynamic Restaurant ID (defaults to 6/Penticton if not in session)
        $restaurant_id = $_SESSION['restaurant_id'] ?? 6; 

        $sql = "INSERT INTO Gem_inventory_items 
                (Restaurant_Id, Name, Unit, Qty_Per_Unit, Pcs_In_Bag, Pcs_In_Box, Conversion_Rate, Sort, No_Of_Bags, Cost_Box, Cost_Pc) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                Unit=VALUES(Unit), 
                Qty_Per_Unit=VALUES(Qty_Per_Unit),
                Cost_Box=VALUES(Cost_Box), 
                Cost_Pc=VALUES(Cost_Pc),
                Updated_At=CURRENT_TIMESTAMP";
        
        $stmt = $pdo->prepare($sql);

        while (($col = fgetcsv($file, 10000, ",")) !== FALSE) {
            $stmt->execute([
                $restaurant_id, $col[0], $col[1], $col[2], $col[3], 
                $col[4], $col[5], $col[6], $col[7], $col[8], $col[9]
            ]);
        }
        fclose($file);
        $message = "Import Successful! " . date('H:i:s');
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Bulk Import | Inventory</title>
    <style>
        body { margin:0; padding-top:80px; font-family:'Inter', sans-serif; background:#f1f5f9; }
        .card { max-width:650px; margin:auto; background:white; padding:40px; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.08); }
        .btn-template { color:#008294; text-decoration:none; font-weight:700; border:2px solid #008294; padding:10px 20px; border-radius:8px; display:inline-block; margin-bottom:30px; }
        .btn-template:hover { background:#008294; color:white; }
        .upload-section { border:3px dashed #e2e8f0; padding:40px; text-align:center; border-radius:12px; margin-bottom:25px; background:#f8fafc; }
        .btn-submit { background:#008294; color:white; border:none; padding:18px; width:100%; border-radius:8px; font-weight:800; cursor:pointer; font-size:16px; transition: 0.2s; }
        .btn-submit:hover { background:#006d7c; transform: translateY(-1px); }
    </style>
</head>
<body>
    <?= generateMenu($pdo); ?>

    <div class="card">
        <h2 style="margin:0; color:#1e293b;">Bulk Item Importer</h2>
        <p style="color:#64748b; margin-bottom:25px;">Upload your restaurant's inventory list using our standardized format.</p>
        
        <a href="?download_template=1" class="btn-template">Step 1: Download CSV Template</a>

        <form method="post" enctype="multipart/form-data">
            <div class="upload-section">
                <input type="file" name="file" accept=".csv" required style="font-size:14px;">
                <p style="font-size:12px; color:#94a3b8; margin-top:15px;">Note: Item Names must be unique per restaurant.</p>
            </div>
            <button type="submit" name="import" class="btn-submit">Step 2: Sync Inventory Now</button>
        </form>

        <?php if(isset($message)): ?>
            <div style="margin-top:25px; padding:15px; background:#f0fdf4; color:#166534; border-radius:8px; text-align:center; font-weight:700; border:1px solid #bbf7d0;">
                ✓ <?= $message ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>