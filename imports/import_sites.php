<?php
/** Path: /import_sites.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';

// Security: Level 1 only
if (!isset($_SESSION['user_level']) || $_SESSION['user_level'] != 1) {
    die("Access Denied.");
}

$report = "";

if (isset($_POST['submit_import'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    
    if (($handle = fopen($file, "r")) !== FALSE) {
        // Skip the header row
        fgetcsv($handle, 1000, ",");
        
        $count = 0;
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO sites (site_name, nick_name, org_id) VALUES (?, ?, ?)");
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                // data[0] = Name, data[1] = Nickname, data[2] = Org ID
                $stmt->execute([$data[0], $data[1], $data[2]]);
                $count++;
            }
            $pdo->commit();
            $report = "<div class='alert success'>Successfully imported $count sites!</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $report = "<div class='alert error'>Error: " . $e->getMessage() . "</div>";
        }
        fclose($handle);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Bulk Site Import</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f7f6; padding: 40px; }
        .import-card { max-width: 500px; margin: auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .file-input { margin: 20px 0; padding: 20px; border: 2px dashed #cbd5e1; border-radius: 8px; text-align: center; }
        .btn-upload { background: #0097a7; color: white; border: none; padding: 12px 25px; border-radius: 6px; cursor: pointer; width: 100%; font-weight: bold; }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .success { background: #dcfce7; color: #166534; }
        .error { background: #fee2e2; color: #991b1b; }
        .sample-table { width: 100%; font-size: 12px; border-collapse: collapse; margin-top: 10px; }
        .sample-table td, .sample-table th { border: 1px solid #ddd; padding: 5px; }
    </style>
</head>
<body>

<div class="import-card">
    <h2>Bulk Site Importer</h2>
    <p style="font-size: 14px; color: #64748b;">Upload a CSV file to add multiple sites at once.</p>
    
    <?= $report ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="file-input">
            <input type="file" name="csv_file" accept=".csv" required>
        </div>
        <button type="submit" name="submit_import" class="btn-upload">START IMPORT</button>
    </form>

    <div style="margin-top: 30px;">
        <h4 style="margin-bottom: 5px;">Expected CSV Format:</h4>
        <table class="sample-table">
            <tr><th>site_name</th><th>nick_name</th><th>org_id</th></tr>
            <tr><td>Bankers Hall Suite 200</td><td>Bankers Hall</td><td>1</td></tr>
            <tr><td>Penticton Main St</td><td>Penticton</td><td>2</td></tr>
        </table>
    </div>
    
    <a href="manage_sites.php" style="display:block; text-align:center; margin-top:20px; color:#64748b; text-decoration:none; font-size:13px;">← Back to Site Manager</a>
</div>

</body>
</html>