<?php
/** Path: /name_verifier.php */
require_once 'db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$msg = "";
$org_id = $_SESSION['org_id'] ?? 1;

// Fetch all current users for the dropdowns
$db_users = $pdo->query("SELECT id, Name FROM users ORDER BY Name ASC")->fetchAll(PDO::FETCH_ASSOC);

// --- ACTION: SYNC / CREATE USERS ---
if (isset($_POST['sync_names'])) {
    $updated = 0;
    $created = 0;

    foreach ($_POST['cv_names'] as $index => $cv_name) {
        $db_id = $_POST['link_map'][$index] ?? '';
        $should_create = isset($_POST['create_new'][$index]);

        if (!empty($db_id)) {
            // OPTION A: Update existing user to match Clearview Name
            $stmt = $pdo->prepare("UPDATE users SET Name = ? WHERE id = ?");
            $stmt->execute([$cv_name, $db_id]);
            $updated++;
        } elseif ($should_create) {
            // OPTION B: Auto-create new user assigned to Site 4
            $stmt = $pdo->prepare("INSERT INTO users (Name, org_id, primary_restaurant_id, active, user_role, Type) VALUES (?, ?, ?, 1, 1, 'Staff')");
            $stmt->execute([$cv_name, $org_id, 4]); 
            $created++;
        }
    }
    $msg = "Successfully updated $updated users and created $created new users. You can now run the import.";
    // Refresh user list after changes
    $db_users = $pdo->query("SELECT id, Name FROM users ORDER BY Name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$results = [];
if (isset($_POST['verify_names']) || !empty($msg)) {
    $file = $_FILES['csv_file']['tmp_name'] ?? null;
    if ($file) {
        $handle = fopen($file, "r");
        fgetcsv($handle); 

        $unique_cv_names = [];
        $existing_names = array_column($db_users, 'Name');

        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (empty($data[1])) continue;
            $raw_name = trim($data[1]);
            $parts = explode(',', $raw_name);
            $cv_name = (count($parts) == 2) ? trim($parts[1]) . ' ' . trim($parts[0]) : $raw_name;

            if (!in_array($cv_name, $existing_names) && !in_array($cv_name, $unique_cv_names)) {
                $unique_cv_names[] = $cv_name;
            }
        }
        fclose($handle);
        sort($unique_cv_names);
        $results = $unique_cv_names;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Master Sync | SIP</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { background: #f1f5f9; padding: 40px; font-family: 'Inter', sans-serif; }
        .card { border-radius: 15px; border: none; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
        .table thead { background: #f8fafc; text-transform: uppercase; font-size: 11px; letter-spacing: 1px; }
        .cv-name-cell { font-weight: 800; color: #1e293b; }
        .new-badge { background: #ef4444; color: white; font-size: 10px; padding: 2px 6px; border-radius: 4px; margin-left: 10px; }
    </style>
</head>
<body>
<div class="container">
    <?php if ($msg): ?><div class="alert alert-success shadow-sm"><?= $msg ?></div><?php endif; ?>

    <div class="card mb-4">
        <div class="card-body p-5">
            <h3 class="font-weight-bold" style="letter-spacing:-1px;">Master Name Sync</h3>
            <p class="text-muted">Link Clearview names to existing users OR create new profiles automatically.</p>
            
            <form method="POST" enctype="multipart/form-data" class="form-inline mt-4">
                <input type="file" name="csv_file" class="form-control-file mr-3" style="width:300px;" required>
                <button type="submit" name="verify_names" class="btn btn-primary px-4 font-weight-bold">LOAD UNIQUE NAMES</button>
            </form>
        </div>
    </div>

    <?php if (!empty($results)): ?>
    <form method="POST">
        <div class="card">
            <table class="table m-0">
                <thead>
                    <tr>
                        <th width="40%">Clearview Name</th>
                        <th width="40%">Match Existing User</th>
                        <th width="20%" class="text-center">OR Create New?</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $index => $name): ?>
                    <tr>
                        <input type="hidden" name="cv_names[<?= $index ?>]" value="<?= htmlspecialchars($name) ?>">
                        <td class="cv-name-cell align-middle"><?= htmlspecialchars($name) ?></td>
                        <td>
                            <select name="link_map[<?= $index ?>]" class="form-control form-control-sm">
                                <option value="">-- No Match --</option>
                                <?php foreach ($db_users as $u): ?>
                                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['Name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="text-center align-middle">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" name="create_new[<?= $index ?>]" class="custom-control-input" id="new_<?= $index ?>">
                                <label class="custom-control-label font-weight-bold text-danger" for="new_<?= $index ?>">CREATE</label>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="card-footer bg-light text-right p-4">
                <button type="submit" name="sync_names" class="btn btn-success btn-lg px-5 font-weight-bold shadow">SYNC & INITIALIZE USERS</button>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>
</body>
</html>