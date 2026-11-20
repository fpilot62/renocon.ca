<?php
/** Path: /register1.php */
require_once 'db.php';

$msg = "";
require_once 'engine/menu_generator.php';
// Fetch organizations so the user can pick where they belong
$orgs = $pdo->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = $_POST['name'];
    $email   = $_POST['email'];
    $pass    = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $org_id  = $_POST['org_id']; // This ensures it goes to the right Admin

    try {
        $sql = "INSERT INTO users (Name, email, password, org_id, active, user_role) VALUES (?, ?, ?, ?, 0, 1)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $email, $pass, $org_id]);
        
        $msg = "<div class='alert alert-success'>Registration submitted! Please wait for your Manager to approve your account.</div>";
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger'>Error: Email might already be registered.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Registration | SIP</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { background: #f1f5f9; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .reg-card { background: white; padding: 40px; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); width: 100%; max-width: 450px; }
    </style>
</head>
<body>
<?php echo generateMenu($pdo); ?>
<div class="reg-card">
    <h2 class="font-weight-bold mb-4">Register Account</h2>
    <?= $msg ?>
    <form method="POST">
        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="name" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Organization / Company</label>
            <select name="org_id" class="form-control" required>
                <option value="">-- Select Your Company --</option>
                <?php foreach($orgs as $o): ?>
                    <option value="<?= $o['id'] ?>"><?= $o['org_name'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Choose Password</label>
            <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary btn-block py-2 font-weight-bold">SUBMIT FOR APPROVAL</button>
        <div class="text-center mt-3">
            <a href="login_kiosk.php" class="small">Back to Login</a>
        </div>
    </form>
</div>

</body>
</html>