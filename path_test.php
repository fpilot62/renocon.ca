<?php
/** Path: /payroll/test_menu.php */
require_once 'db.php';

echo "<h2>Menu Manager Diagnostic</h2>";

try {
    // 1. Check Table Existence
    $check = $pdo->query("SHOW TABLES LIKE 'menu_manager'");
    if($check->rowCount() > 0) {
        echo "<p style='color:green;'>✅ Table 'menu_manager' exists.</p>";
    } else {
        echo "<p style='color:red;'>❌ Table 'menu_manager' NOT found. Please check your table name.</p>";
    }

    // 2. Check for Data
    $data = $pdo->query("SELECT COUNT(*) FROM menu_manager");
    $count = $data->fetchColumn();
    echo "<p>Total links found in database: <strong>$count</strong></p>";

    // 3. Check Admin Links
    $admin_check = $pdo->query("SELECT MenuTitle FROM menu_manager WHERE MenuTitle LIKE '%Manager%'");
    if($row = $admin_check->fetch()) {
        echo "<p style='color:blue;'>ℹ️ Found Admin link: " . htmlspecialchars($row['MenuTitle']) . "</p>";
    }

} catch (PDOException $e) {
    echo "<p style='color:red;'>Database Error: " . $e->getMessage() . "</p>";
}
?>