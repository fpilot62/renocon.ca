<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require_once __DIR__ . '/sip/db_config.php';


$uid = $_GET['user_id'] ?? 0;

// 1. Get the assigned site IDs from the users table (usually 'id' there)
$res = mysqli_query($conn, "SELECT assigned_site_ids FROM users WHERE id = '$uid' LIMIT 1");
$user = mysqli_fetch_assoc($res);

if ($user && !empty($user['assigned_site_ids'])) {
    $ids = $user['assigned_site_ids'];
    
    // FIXED: Changed 'id' to 'SId' to match your database
    $site_sql = "SELECT SId as id, nick_name as name FROM sites WHERE SId IN ($ids)";
    $site_result = mysqli_query($conn, $site_sql);
    
    if (!$site_result) {
        die(json_encode(["data" => [], "error" => mysqli_error($conn)]));
    }

    $sites = [];
    while ($row = mysqli_fetch_assoc($site_result)) {
        $sites[] = $row;
    }
    echo json_encode(["data" => $sites]);
} else {
    echo json_encode(["data" => [], "message" => "No sites assigned"]);
}
?>