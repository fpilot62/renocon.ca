<?php
// --- START DEBUG BLOCK ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Verify if the required files actually exist
$required_files = ['db.php'];
foreach ($required_files as $file) {
    if (!file_exists($file)) {
        echo "<div style='color:red; background:white; padding:10px; border:2px solid red;'>";
        echo "<strong>FATAL ERROR:</strong> The file <code>$file</code> was not found in: " . getcwd();
        echo "</div>";
    }
}
// --- END DEBUG BLOCK ---

// 1. Core Security - This uses your existing session login
require_once 'db.php'; 
restrictToAdmin(10); 

// 2. Use the shared payroll connection
$pdo_p = $pdo;
$stmt = $pdo_p->query("SELECT * FROM crewpulse_config ORDER BY id DESC LIMIT 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);

// 3. Update Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $logo_name = $config['logo_path'];

    // Handle Upload
    if (!empty($_FILES['logo']['name'])) {
        // We use the absolute server path to avoid 500 errors
        $target_dir = $_SERVER['DOCUMENT_ROOT'] . "/../crewpulse.ca/images/";
        
        // Create folder if it doesn't exist
        if (!file_exists($target_dir)) { mkdir($target_dir, 0755, true); }

        $logo_name = time() . "_" . basename($_FILES["logo"]["name"]);
        move_uploaded_file($_FILES["logo"]["tmp_name"], $target_dir . $logo_name);
    }

    $sql = "UPDATE crewpulse_config SET 
            promo_text = ?, base_price = ?, discount_pct = ?, 
            free_limit = ?, setup_fee = ?, logo_path = ?,
            comp_feature_1 = ?, comp_feature_2 = ?, comp_feature_3 = ?
            WHERE id = ?";
    
    $update = $pdo_p->prepare($sql);
    $update->execute([
        $_POST['promo_text'], $_POST['base_price'], $_POST['discount_pct'], 
        $_POST['free_limit'], $_POST['setup_fee'], $logo_name,
        $_POST['f1'], $_POST['f2'], $_POST['f3'],
        $config['id']
    ]);

    header("Location: admin_deals.php?success=1");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>crewPulse | Interface Manager</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 30px; }
        .box { max-width: 600px; margin: auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); border-top: 5px solid #e67e22; }
        h2 { margin-top: 0; color: #333; }
        label { display: block; margin-top: 15px; font-weight: bold; font-size: 13px; color: #666; }
        input { width: 100%; padding: 12px; margin-top: 5px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .flex { display: flex; gap: 15px; }
        .flex div { flex: 1; }
        .btn { width: 100%; background: #e67e22; color: white; border: none; padding: 15px; margin-top: 25px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 16px; }
        .btn:hover { background: #d35400; }
        .msg { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 20px; text-align: center; }
    </style>
</head>
<body>
    <div class="box">
        <h2>Interface Manager</h2>
        <?php if(isset($_GET['success'])): ?>
            <div class="msg">✓ Website Updated Successfully!</div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <label>Update Logo:</label>
            <input type="file" name="logo">
            <p style="font-size: 11px; color: #888;">Current: <?php echo $config['logo_path']; ?></p>

            <label>Promo Banner Text:</label>
            <input type="text" name="promo_text" value="<?php echo htmlspecialchars($config['promo_text']); ?>">

            <div class="flex">
                <div><label>Base Price ($):</label><input type="number" step="0.01" name="base_price" value="<?php echo $config['base_price']; ?>"></div>
                <div><label>Discount (%):</label><input type="number" name="discount_pct" value="<?php echo $config['discount_pct']; ?>"></div>
            </div>

            <label>Comparison Points:</label>
            <input type="text" name="f1" value="<?php echo htmlspecialchars($config['comp_feature_1']); ?>">
            <input type="text" name="f2" value="<?php echo htmlspecialchars($config['comp_feature_2']); ?>">
            <input type="text" name="f3" value="<?php echo htmlspecialchars($config['comp_feature_3']); ?>">

            <div class="flex">
                <div><label>Seed Employee Limit:</label><input type="number" name="free_limit" value="<?php echo $config['free_limit']; ?>"></div>
                <div><label>One-time Setup Fee:</label><input type="number" step="0.01" name="setup_fee" value="<?php echo $config['setup_fee']; ?>"></div>
            </div>

            <button type="submit" class="btn">Update crewpulse.ca</button>
        </form>
    </div>
</body>
</html>