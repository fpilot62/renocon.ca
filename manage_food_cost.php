<?php
/** Path: /manage_food_cost.php — keep old links working */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/db.php';
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
if ($action === 'delete' && $id > 0) {
    $_POST['action'] = 'delete_food_cost';
    $_POST['id'] = (string)$id;
    require __DIR__ . '/save_food_cost_api.php';
    exit;
}
header('Location: food_cost_view.php');
exit;
