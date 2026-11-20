<?php
/**
 * Block #1: SECURED Edit User (Full Fields + Transit Rate)
 * Path: /edit_user.php
 */
ini_set('display_errors', 1);
require_once 'db.php';
require_once 'engine/menu_generator.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)($_GET['id'] ?? 0);
$message = "";

/** Coerce empty POST scalars to NULL for INT/DECIMAL columns (MySQL rejects ''). */
function nullable_int($v) {
    if ($v === null || $v === '' || $v === false) return null;
    return (int)$v;
}
function nullable_decimal($v) {
    if ($v === null || $v === '' || $v === false) return null;
    return $v;
}

// 1. Handle Update Request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name              = $_POST['name'] ?? '';
    $email             = $_POST['email'] ?? '';
    $mobile            = $_POST['mobile'] ?? '';
    $org_id            = nullable_int($_POST['org_id'] ?? null);
    $password          = $_POST['password'] ?? '';
    $hourly_rate       = nullable_decimal($_POST['hourly_rate'] ?? null);
    $official_rate     = nullable_decimal($_POST['official_rate'] ?? null);
    $transit_rate      = nullable_decimal($_POST['transit_rate'] ?? null);
    $primary_site_id   = nullable_int($_POST['primary_site_id'] ?? null);

    // Multi-select → comma-separated string (same format as employee_hub.php)
    if (isset($_POST['assigned_site_ids']) && is_array($_POST['assigned_site_ids'])) {
        $ids = array_filter(array_map('intval', $_POST['assigned_site_ids']), function ($id) {
            return $id > 0;
        });
        $assigned_site_ids = implode(',', $ids);
    } else {
        $raw = trim((string)($_POST['assigned_site_ids'] ?? ''));
        $assigned_site_ids = $raw === '' ? '' : preg_replace('/[^0-9,]/', '', $raw);
    }

    $sql = "UPDATE users SET
            Name = ?, email = ?, tel_mobile = ?, org_id = ?,
            hourly_rate = ?, official_rate = ?, transit_rate = ?,
            primary_site_id = ?, assigned_site_ids = ?";

    $params = [$name, $email, $mobile, $org_id, $hourly_rate, $official_rate, $transit_rate, $primary_site_id, $assigned_site_ids];

    if (!empty($password)) {
        $sql .= ", password = ?";
        $params[] = $password;
    }

    $sql .= " WHERE id = ?";
    $params[] = $user_id;

    try {
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute($params)) {
            $message = "<div class='alert success'>User updated successfully.</div>";
        } else {
            $message = "<div class='alert error'>Update failed.</div>";
        }
    } catch (PDOException $e) {
        $message = "<div class='alert error'>Update failed: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// 2. Fetch Existing User Data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$u) { die("User not found."); }

// 3. Sites for dropdowns (same source as employee_hub / daily_report)
$sites = $pdo->query("SELECT SId, nick_name FROM sites ORDER BY nick_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$assigned_selected = array_filter(array_map('intval', explode(',', (string)($u['assigned_site_ids'] ?? ''))));
$primary_selected = (int)($u['primary_site_id'] ?? 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit User | <?= htmlspecialchars($u['Name']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f7f6; padding: 20px; padding-top: 80px; }
        .form-card { background: white; border-radius: 15px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); max-width: 700px; margin: auto; padding: 30px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 11px; font-weight: 900; color: #64748b; margin-bottom: 8px; text-transform: uppercase; }
        .form-group input, .form-group select { width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ddd; box-sizing: border-box; font-size: 14px; }
        .form-group select[multiple] { min-height: 160px; }
        .hint { font-size: 11px; color: #94a3b8; margin-top: 6px; font-weight: 600; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
        .btn-save { background: #3b82f6; color: white; border: none; padding: 15px; width: 100%; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 14px; margin-top: 10px; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; }
        .success { background: #dcfce7; color: #166534; }
        .error { background: #fee2e2; color: #991b1b; }
        .back-btn { display: inline-block; margin-bottom: 20px; color: #64748b; text-decoration: none; font-weight: bold; font-size: 13px; }
        .section-title { color:#1e293b; margin: 25px 0 15px 0; border-bottom: 1px solid #eee; padding-bottom: 10px; }
    </style>
</head>
<body>
    <?= generateMenu($pdo) ?>

    <div class="form-card">
        <a href="staff_audit.php" class="back-btn"><i class="fa fa-arrow-left"></i> Back to Audit Dashboard</a>
        <h2>Edit Staff Profile</h2>
        <?= $message ?>

        <form method="POST">
            <h4 class="section-title">Personal Information</h4>
            <div class="grid-2">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($u['Name']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($u['email']) ?>" required>
                </div>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label>Mobile Number</label>
                    <input type="text" name="mobile" value="<?= htmlspecialchars($u['tel_mobile'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Organization ID</label>
                    <input type="number" name="org_id" value="<?= htmlspecialchars($u['org_id'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Update Password (Leave blank to keep current)</label>
                <input type="password" name="password" placeholder="••••••••">
            </div>

            <h4 class="section-title">Site Permissions</h4>
            <div class="grid-2">
                <div class="form-group">
                    <label>Primary Site (Home)</label>
                    <select name="primary_site_id">
                        <option value="">-- None --</option>
                        <?php foreach ($sites as $s): ?>
                            <option value="<?= (int)$s['SId'] ?>" <?= $primary_selected === (int)$s['SId'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['nick_name']) ?> (<?= (int)$s['SId'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">Empty = no primary site (saved as NULL)</div>
                </div>
                <div class="form-group">
                    <label>Assigned Sites</label>
                    <select name="assigned_site_ids[]" multiple size="8">
                        <?php foreach ($sites as $s): ?>
                            <option value="<?= (int)$s['SId'] ?>" <?= in_array((int)$s['SId'], $assigned_selected, true) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['nick_name']) ?> (<?= (int)$s['SId'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">Hold Ctrl/Cmd to select multiple. Stored as comma-separated IDs.</div>
                </div>
            </div>

            <h4 class="section-title">Payroll Standards</h4>
            <div class="grid-3">
                <div class="form-group">
                    <label>Actual Rate ($)</label>
                    <input type="number" step="0.01" name="hourly_rate" value="<?= htmlspecialchars($u['hourly_rate'] ?? '0.00') ?>">
                </div>
                <div class="form-group">
                    <label>Official Rate ($)</label>
                    <input type="number" step="0.01" name="official_rate" value="<?= htmlspecialchars($u['official_rate'] ?? '0.00') ?>">
                </div>
                <div class="form-group">
                    <label>Transit Rate ($)</label>
                    <input type="number" step="0.01" name="transit_rate" value="<?= htmlspecialchars($u['transit_rate'] ?? '0.00') ?>">
                </div>
            </div>

            <button type="submit" class="btn-save">SAVE CHANGES & UPDATE PROFILE</button>
        </form>
    </div>
</body>
</html>

<?php
/**
 * ==========================================================================================
 * PERMANENT GOLDEN RULES (DO NOT DELETE)
 * ==========================================================================================
 * 1. AUTH GUARD: All pages must check for session 'user_id' at Line 1 for security.
 * 2. SUPERADMIN: Role <= 10 or specific Org IDs bypass all filters.
 * 3. RATES: Users must have 'hourly_rate', 'official_rate', AND 'transit_rate'.
 * 4. 3-SHIFT LOGIC: Payroll must calculate the gap between tr_out and tr_in using transit_rate.
 * 5. PRESERVATION: Never remove existing functional fields when adding new ones.
 * ==========================================================================================
 */
?>
