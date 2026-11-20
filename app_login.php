<?php
header("Content-Type: application/json");
require_once 'db.php';

$login_user = $_POST['username'] ?? ''; 
$login_pass = $_POST['password'] ?? ''; 

try {
    // 1. Just find if the user exists
    $stmt = $pdo->prepare("SELECT id, user_role FROM users WHERE user = ?");
    $stmt->execute([$login_user]);
    $userData = $stmt->fetch();

    // 2. EMERGENCY BYPASS: If they type 7265, let them in
    if ($userData && ($login_pass === '7265' || $login_pass === 'admin7265')) {
        $role_id = (int)$userData['user_role'];
        
        $orgs = ($role_id === 10) ? $pdo->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC")->fetchAll(PDO::FETCH_ASSOC) : [];
        $sites = $pdo->query("SELECT SId, nick_name, org_id, target_lat, target_lng FROM sites")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "user_id" => $userData['id'],
            "role" => $role_id,
            "orgs" => $orgs,
            "sites" => $sites
        ]);
        exit;
    }

    echo json_encode(["success" => false, "message" => "Bypass Failed: Check Username '$login_user'"]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => "Connection Error: " . $e->getMessage()]);
}