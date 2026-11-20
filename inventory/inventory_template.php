<?php
/** Path: /inventory/inventory_template.php */
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. DATABASE & SESSION
require_once dirname(__DIR__) . '/db.php'; 
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 2. CONTEXT & PERMISSIONS
$user_lvl       = (int)($_SESSION['user_level'] ?? 99);
$current_org_id = (int)($_SESSION['org_id'] ?? 0);
$assigned_sites = $_SESSION['assigned_site_ids'] ?? '';
$rid            = isset($_GET['rid']) ? (int)$_GET['rid'] : 0; 

// 3. FETCH SITES (Needed for the Shared Header)
$site_sql = ($user_lvl <= 10) ? "SELECT SId as id, nick_name FROM sites" : "SELECT SId as id, nick_name FROM sites WHERE org_id = $current_org_id";
$sites = $pdo->query($site_sql . " ORDER BY nick_name ASC")->fetchAll(PDO::FETCH_ASSOC);
if ($rid === 0 && !empty($sites)) { $rid = $sites[0]['id']; }

// 4. SHARED FORECAST LOGIC (Testing with $8,000/day)
$project_days = isset($_GET['project_days']) ? (int)$_GET['project_days'] : 7;
$daily_test_forecast = 8000; 
$total_forecast = $daily_test_forecast * $project_days;

require_once "../engine/menu_generator.php"; 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inventory Page Title | SIP</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Shared Styles specific to this page can go here, 
           global inventory styles are in inventory_header.php */
        .page-card { background: white; border-radius: 15px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); border: none; }
    </style>
</head>
<body>
    <?= generateMenu($pdo); ?>
    
    <?php include "inventory_header.php"; ?>

    <div class="container-fluid px-5">
        <div class="page-card">
            <h2 class="font-weight-bold">Page Title</h2>
            <p class="text-muted">Currently testing with $<?= number_format($daily_test_forecast) ?>/day forecast.</p>
            
            </div>
    </div>
</body>
</html>