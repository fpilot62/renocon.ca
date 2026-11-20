<?php
/** Path: /payroll/payroll_settings.php */
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] != 1) { 
    header("Location: login.php"); exit; 
}

$org_id = $_SESSION['org_id'];
$msg = "";

if (isset($_POST['save_settings'])) {
    $logo_path = $_POST['current_logo'];

    // Handle File Upload
    if (!empty($_FILES['logo']['name'])) {
        $target_dir = "uploads/logos/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        
        $file_ext = pathinfo($_FILES["logo"]["name"], PATHINFO_EXTENSION);
        $logo_path = $target_dir . "org_" . $org_id . "." . $file_ext;
        move_uploaded_file($_FILES["logo"]["tmp_name"], $logo_path);
    }

    $sql = "UPDATE organizations SET logo_url = ?, safety_policy_text = ? WHERE id = ?";
    $pdo->prepare($sql)->execute([$logo_path, $_POST['policy_text'], $org_id]);
    $msg = "Branding and Policy updated!";
}

$settings = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
$settings->execute([$org_id]);
$org = $settings->fetch();

include 'menu_include.php';
?>
<div style="max-width:800px; margin: 40px auto; font-family: sans-serif; background:white; padding:30px; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1);">
    <h2><i class="fas fa-brush"></i> Organization Branding</h2>
    <?php if($msg) echo "<p style='color:green;'>$msg</p>"; ?>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="current_logo" value="<?= $org['logo_url'] ?>">
        
        <div style="margin-bottom:20px;">
            <label style="display:block; font-weight:bold;">Company Logo</label>
            <?php if($org['logo_url']): ?>
                <img src="<?= $org['logo_url'] ?>" style="height:50px; margin-bottom:10px; display:block;">
            <?php endif; ?>
            <input type="file" name="logo" accept="image/*">
        </div>

        <div style="margin-bottom:20px;">
            <label style="display:block; font-weight:bold;">Safety Policy Manual</label>
            <textarea name="policy_text" style="width:100%; height:200px; padding:10px;"><?= htmlspecialchars($org['safety_policy_text'] ?? '') ?></textarea>
        </div>

        <button type="submit" name="save_settings" style="background:#008ba3; color:white; border:none; padding:12px 25px; border-radius:4px; cursor:pointer; font-weight:bold;">SAVE SETTINGS</button>
    </form>
</div>