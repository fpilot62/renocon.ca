<?php
/** Path: active/modules/scheduling/settings_view.php */
require_once __DIR__ . '/auth_bridge.php';
require_once __DIR__ . '/sip/db_config.php';

// 1. HANDLE UPDATES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    foreach ($_POST['settings'] as $key => $value) {
        $stmt = $conn->prepare("UPDATE Gem_Settings SET setting_value = ? WHERE setting_key = ?");
        $stmt->bind_param("ss", $value, $key);
        $stmt->execute();
    }
    $message = "Settings updated successfully!";
}

// 2. FETCH CURRENT SETTINGS
$res = $conn->query("SELECT * FROM Gem_Settings ORDER BY setting_key ASC");
$settings = $res->fetch_all(MYSQLI_ASSOC);

include_once __DIR__ . '/engine/menu_generator.php';
?>

<div class="container mt-4" style="max-width: 800px;">
    <div class="card shadow-sm">
        <div class="card-header bg-dark text-white d-flex justify-content-between">
            <h5 class="mb-0">Gem Settings Management</h5>
            <a href="scheduler_view.php" class="btn btn-sm btn-outline-light">Back to Scheduler</a>
        </div>
        <div class="card-body">
            <?php if(isset($message)): ?>
                <div class="alert alert-success"><?= $message ?></div>
            <?php endif; ?>

            <form method="POST">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Setting Description</th>
                            <th style="width: 250px;">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($settings as $s): 
                            // Make labels more readable
                            $label = ucwords(str_replace('_', ' ', $s['setting_key']));
                            $unit = (strpos($s['setting_key'], 'percent') !== false) ? '%' : '$';
                        ?>
                        <tr>
                            <td>
                                <strong><?= $label ?></strong><br>
                                <small class="text-muted"><?= $s['setting_key'] ?></small>
                            </td>
                            <td>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><?= $unit ?></span>
                                    <input type="text" name="settings[<?= $s['setting_key'] ?>]" 
                                           class="form-control" value="<?= htmlspecialchars($s['setting_value']) ?>">
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="text-end">
                    <button type="submit" name="update_settings" class="btn btn-primary">Save All Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>