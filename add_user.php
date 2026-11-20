<?php
/** Path: /add_user.php */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php'; 
require_once 'engine/menu_generator.php';

// Security Check: Only Admin (Level 20) or Superadmin (Level 10) can add users
if (!isset($_SESSION['user_level']) || $_SESSION['user_level'] > 20) {
    die("Access Denied: You do not have permission to view this page.");
}

$msg = "";
$status = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Hash the PIN for security
        $hashed = password_hash($_POST['pin'], PASSWORD_DEFAULT);
        
        $sql = "INSERT INTO users (Name, user, password, pin, user_role, org_id, hourly_rate, active) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['Name'], 
            $_POST['user'], 
            $hashed, 
            $_POST['pin'], 
            (int)$_POST['user_role'], 
            (int)$_POST['org_id'],
            (float)$_POST['hourly_rate']
        ]);

        header("Location: employee_hub.php?msg=Success: Employee Added");
        exit;
    } catch (Exception $e) {
        $msg = "Error: " . $e->getMessage();
        $status = "danger";
    }
}

// Fetch dynamic data for dropdowns
$orgs = $pdo->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC")->fetchAll();
$roles = $pdo->query("SELECT role_id, role_name FROM roles ORDER BY role_id ASC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add New Employee | SIP</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { 
            background: #f1f5f9; 
            padding-top: 120px; /* Clears the fixed 3D Nav Bar */
            font-family: 'Inter', sans-serif; 
        }
        .form-card { 
            max-width: 600px; 
            margin: auto; 
            background: white; 
            padding: 40px; 
            border-radius: 16px; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.05); 
            border-top: 6px solid #3b82f6; /* Accent color matching your Hub blue */
        }
        h3 { color: #0f172a; font-weight: 700; margin-bottom: 25px; }
        label { 
            font-weight: 700; 
            color: #64748b; 
            font-size: 12px; 
            text-transform: uppercase; 
            margin-top: 15px; 
            letter-spacing: 0.5px;
        }
        .form-control { 
            border-radius: 8px; 
            padding: 12px; 
            border: 1px solid #e2e8f0; 
            font-size: 14px; 
            height: auto;
        }
        .form-control:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .btn-create { 
            background: #3b82f6; 
            border: none; 
            font-weight: 800; 
            letter-spacing: 1px; 
            padding: 16px; 
            border-radius: 8px; 
            margin-top: 30px; 
            transition: all 0.3s ease;
            text-transform: uppercase;
        }
        .btn-create:hover { 
            background: #2563eb; 
            transform: translateY(-2px); 
            box-shadow: 0 5px 15px rgba(37, 99, 235, 0.3); 
        }
        .cancel-link { color: #94a3b8; font-size: 13px; text-decoration: none; transition: 0.2s; }
        .cancel-link:hover { color: #64748b; text-decoration: underline; }
    </style>
</head>
<body>

<?php echo generateMenu($pdo); ?>

<div class="container pb-5">
    <div class="form-card">
        <h3>Add New Employee</h3>

        <?php if($msg): ?>
            <div class="alert alert-<?= $status ?> small font-weight-bold shadow-sm"><?= $msg ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="row">
                <div class="col-md-12">
                    <label>Full Name</label>
                    <input type="text" name="Name" class="form-control" placeholder="e.g. Raheel Sheikh" required>
                </div>
                
                <div class="col-md-6">
                    <label>Username</label>
                    <input type="text" name="user" class="form-control" placeholder="raheel" required>
                </div>
                
                <div class="col-md-6">
                    <label>Clock PIN (4 digits)</label>
                    <input type="text" name="pin" class="form-control" maxlength="4" placeholder="1234" required>
                </div>

                <div class="col-md-6">
                    <label>Hourly Rate ($)</label>
                    <input type="number" step="0.01" name="hourly_rate" class="form-control" value="0.00">
                </div>

                <div class="col-md-6">
                    <label>Permission Level (Role)</label>
                    <select name="user_role" class="form-control">
                        <?php foreach($roles as $r): ?>
                            <option value="<?= $r['role_id'] ?>"><?= htmlspecialchars($r['role_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-12">
                    <label>Organization / Site Assignment</label>
                    <select name="org_id" class="form-control">
                        <?php foreach($orgs as $o): ?>
                            <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['org_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-create shadow">Create Employee Profile</button>
            
            <div class="text-center mt-4">
                <a href="employee_hub.php" class="cancel-link">← Cancel and return to Hub</a>
            </div>
        </form>
    </div>
</div>

</body>
</html>