<?php
/**
 * Path: /api/login.php
 * Method: POST
 * Body: { "username": "...", "password": "..." }
 * Returns: { "success": true, "token": "...", "user": { id, name, role, org_id, sites[] } }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../db.php';
require_once 'jwt_helper.php';
require_once __DIR__ . '/../app_activity_lib.php';
require_once __DIR__ . '/../engine/login_auth_lib.php';

$body = json_decode(file_get_contents('php://input'), true);
$username = trim($body['username'] ?? '');
$password = $body['password'] ?? '';

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Username and password required']);
    exit;
}

try {
    $user = sip_find_login_user($pdo, sip_normalize_login_ident($username));
    $auth = $user ? sip_login_secret_kind($user, $password) : false;
    if (!$auth) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
        exit;
    }

    $extra = $pdo->prepare("SELECT hourly_rate, transit_rate, official_rate, primary_site_id, assigned_site_ids FROM users WHERE id = ? LIMIT 1");
    $extra->execute([(int)$user['id']]);
    $row = $extra->fetch(PDO::FETCH_ASSOC) ?: [];
    $user = array_merge($user, $row);

    $uid     = (int)$user['id'];
    $org_id  = (int)$user['org_id'];
    $user_lvl = (int)$user['user_role'];
    $assigned = $user['assigned_site_ids'] ?? '';

    // Load allowed sites (mirrors login_kiosk.php logic)
    if ($user_lvl <= 10) {
        $site_stmt = $pdo->query("SELECT Sid, nick_name, Timezone, target_lat, target_lng, geo_radius_meters FROM sites ORDER BY nick_name ASC");
    } else {
        $clean_ids = preg_replace('/[^0-9,]/', '', $assigned);
        if (!empty($clean_ids)) {
            $sql = "SELECT Sid, nick_name, Timezone, target_lat, target_lng, geo_radius_meters FROM sites WHERE org_id = ? AND Sid IN ($clean_ids) ORDER BY nick_name ASC";
        } else {
            $sql = "SELECT Sid, nick_name, Timezone, target_lat, target_lng, geo_radius_meters FROM sites WHERE org_id = ? ORDER BY nick_name ASC";
        }
        $site_stmt = $pdo->prepare($sql);
        $site_stmt->execute([$org_id]);
    }
    $sites = $site_stmt->fetchAll(PDO::FETCH_ASSOC);

    app_activity_log($pdo, 'crewpulse', 'login', $uid, $user['Name'] ?? '', $username, 'app');

    $token = jwt_encode([
        'user_id'  => $uid,
        'org_id'   => $org_id,
        'name'     => $user['Name'],
        'role'     => $user_lvl,
        'auth'     => $auth,
    ]);

    echo json_encode([
        'success' => true,
        'token'   => $token,
        'user'    => [
            'id'           => $uid,
            'name'         => $user['Name'],
            'role'         => $user_lvl,
            'org_id'       => $org_id,
            'hourly_rate'  => (float)$user['hourly_rate'],
            'transit_rate' => (float)$user['transit_rate'],
            'official_rate'=> (float)$user['official_rate'],
            'primary_site_id' => (int)$user['primary_site_id'],
            'auth'            => $auth,
        ],
        'auth' => $auth,
        'sites' => $sites,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
