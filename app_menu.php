<?php
/** Path: /app_menu.php */
header('Content-Type: application/json');
require_once 'db.php';

$user_lvl = $_GET['lvl'] ?? 100; // Pass the level from the app

try {
    // Fetch only active items the user is allowed to see
    $sql = "SELECT MenuID, ParentID, MenuTitle, LinkURL FROM menu_manager 
            WHERE IsActive = 1 AND UserRole >= :lvl 
            ORDER BY ParentID ASC, SortOrder ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['lvl' => $user_lvl]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // This sends the raw list to the app. 
    // Flutter is very good at turning lists into menus.
    echo json_encode($items);
} catch (PDOException $e) {
    echo json_encode([]);
}