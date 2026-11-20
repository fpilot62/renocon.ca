<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 1) { die("Unauthorized"); }

$id = $_GET['id'] ?? null;
$stmt = $pdo->prepare("SELECT * FROM punches WHERE id = ?");
$stmt->execute([$id]);
$punch = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_type = $_POST['punch_type'];
    $new_time = $_POST['punch_time'];
    
    $update = $pdo->prepare("UPDATE punches SET punch_type = ?, punch_time = ? WHERE id = ?");
    $update->execute([$new_type, $new_time, $id]);
    header("Location: manager.php");
    exit;
}
?>
<!DOCTYPE html>
<html>
<head><title>Edit Punch</title></head>
<body style="font-family:sans-serif; padding:40px;">
    <h2>Edit Punch Record #<?php echo $id; ?></h2>
    <form method="POST">
        <label>Type:</label><br>
        <select name="punch_type">
            <option value="in" <?php if($punch['punch_type']=='in') echo 'selected'; ?>>In</option>
            <option value="out" <?php if($punch['punch_type']=='out') echo 'selected'; ?>>Out</option>
            <option value="break_start" <?php if($punch['punch_type']=='break_start') echo 'selected'; ?>>Break Start</option>
            <option value="break_end" <?php if($punch['punch_type']=='break_end') echo 'selected'; ?>>Break End</option>
        </select><br><br>
        
        <label>Timestamp:</label><br>
        <input type="datetime-local" name="punch_time" value="<?php echo date('Y-m-d\TH:i', strtotime($punch['punch_time'])); ?>"><br><br>
        
        <button type="submit" style="padding:10px 20px; background:#2980b9; color:white; border:none; border-radius:4px; cursor:pointer;">Update Record</button>
        <a href="manager.php">Cancel</a>
    </form>
</body>
</html>