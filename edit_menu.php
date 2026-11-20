<?php
session_start();
require_once 'db.php';

// 1. AUTO-CREATE TABLE IF MISSING
$pdo->exec("CREATE TABLE IF NOT EXISTS menu_manager (
    MenuID INT(11) AUTO_INCREMENT PRIMARY KEY,
    MenuTitle VARCHAR(100) NOT NULL,
    ParentID INT(11) DEFAULT 0,
    LinkURL VARCHAR(255) DEFAULT NULL,
    SortOrder INT(11) DEFAULT 0,
    IsActive TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8");

// 2. HANDLE ADD/UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'];
    $url = $_POST['url'];
    $parent = intval($_POST['parent']);
    $sort = intval($_POST['sort']);

    $stmt = $pdo->prepare("INSERT INTO menu_manager (MenuTitle, LinkURL, ParentID, SortOrder) VALUES (?, ?, ?, ?)");
    $stmt->execute([$title, $url, $parent, $sort]);
    echo "<p style='color:green;'>Link Added!</p>";
}

// 3. FETCH EXISTING FOR VIEWING
$items = $pdo->query("SELECT * FROM menu_manager ORDER BY ParentID, SortOrder")->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Menu Manager</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #f4f7f6; }
        .box { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #1e293b; color: white; }
    </style>
</head>
<body>
    <h2>Menu Manager Editor</h2>

    <div class="box">
        <h3>Add New Link</h3>
        <form method="POST">
            <input type="text" name="title" placeholder="Menu Title (e.g. Kiosk)" required>
            <input type="text" name="url" placeholder="URL (e.g. kiosk.php)" required>
            <input type="number" name="parent" placeholder="Parent ID (0 for Main)" value="0">
            <input type="number" name="sort" placeholder="Sort Order" value="1">
            <button type="submit">Save Link</button>
        </form>
    </div>

    <div class="box">
        <h3>Current Menu Structure</h3>
        <table>
            <tr><th>ID</th><th>Title</th><th>URL</th><th>Parent</th><th>Sort</th></tr>
            <?php foreach($items as $i): ?>
            <tr>
                <td><?= $i['MenuID'] ?></td>
                <td><?= htmlspecialchars($i['MenuTitle']) ?></td>
                <td><?= htmlspecialchars($i['LinkURL']) ?></td>
                <td><?= $i['ParentID'] ?></td>
                <td><?= $i['SortOrder'] ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <p><a href="test_menu.php">Run Diagnostic Again</a> | <a href="kiosk.php">Go to Kiosk</a></p>
</body>
</html>