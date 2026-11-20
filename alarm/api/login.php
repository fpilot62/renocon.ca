<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
/**
 * Path: /alarm/api/login.php
 * Method: POST
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../../db.php';
require_once 'alarm_jwt_helper.php';
require_once 'config.php';
require_once __DIR__ . '/../../app_activity_lib.php';

$body   = json_decode(file_get_contents('php://input'), true);
$action = trim($body['action'] ?? 'login');

try {

    if ($action === 'register') {
        $name     = trim($body['name']     ?? '');
        $email    = trim($body['email']    ?? '');
        $phone    = trim($body['phone']    ?? '');
        $password = $body['password']      ?? '';

        if (empty($name) || empty($email) || empty($password)) {
            echo json_encode(['success' => false, 'error' => 'Name, email and password are required']);
            exit;
        }

        $check = $pdo->prepare("SELECT user_id FROM alarm_users WHERE email = ? LIMIT 1");
        $check->execute([$email]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Email already registered. Please login instead.']);
            exit;
        }

        $hash = md5($password);
        $ins  = $pdo->prepare("INSERT INTO alarm_users (name, email, tel, password, active, role, org_id, created_at) VALUES (?, ?, ?, ?, 1, 1, 0, NOW())");
        $ins->execute([$name, $email, $phone, $hash]);
        $newId = $pdo->lastInsertId();
        app_activity_log($pdo, 'alarmpulse', 'signup', $newId, $name, $email, 'app');

        $token = alarm_jwt_encode(['user_id' => $newId, 'org_id' => 0, 'name' => $name, 'role' => 1]);
        echo json_encode([
            'success' => true,
            'token'   => $token,
            'user'    => ['id' => $newId, 'name' => $name, 'email' => $email, 'phone' => $phone, 'role' => 1]
        ]);
        exit;
    }

    $username = trim($body['username'] ?? '');
    $password = $body['password']      ?? '';

    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Username and password required']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT user_id as id, name as Name, password, role as user_role, org_id, tel as Tel_Mobile, email FROM alarm_users WHERE email = ? AND active = 1 LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $valid = false;
    if ($user) {
        if (strpos($user['password'], '$2y$') === 0) {
            $valid = password_verify($password, $user['password']);
        } else {
            $valid = (md5($password) === $user['password']);
        }
    }

    if (!$valid) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
        exit;
    }

    app_activity_log($pdo, 'alarmpulse', 'login', (int)$user['id'], $user['Name'] ?? '', $user['email'] ?? $username, 'app');

    $token = alarm_jwt_encode([
        'user_id' => (int)$user['id'],
        'org_id'  => (int)$user['org_id'],
        'name'    => $user['Name'],
        'role'    => (int)$user['user_role'],
    ]);

    echo json_encode([
        'success' => true,
        'token'   => $token,
        'user'    => [
            'id'    => (int)$user['id'],
            'name'  => $user['Name'],
            'email' => $user['email'],
            'phone' => $user['Tel_Mobile'],
            'role'  => (int)$user['user_role'],
            'org_id'=> (int)$user['org_id'],
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

/**
 * ============================================================
 * FILE: alarm/api/login.php
 * LAST MODIFIED: June 2026
 * ============================================================
 * OBJECTIVE:
 *   Authenticate AlarmPulse users and return JWT token.
 *   Also handles new user registration.
 *
 * LOGIC:
 *   - LOGIN: queries alarm_users table by email
 *   - Password stored as MD5 hash
 *   - Returns JWT token signed with ALARM_JWT_SECRET
 *   - REGISTER: creates new alarm_users record
 *
 * PROCESS:
 *   1. POST {username, password} → find in alarm_users by email
 *   2. Compare MD5(password) with stored hash
 *   3. Generate JWT via alarm_jwt_encode()
 *   4. Return token + user object
 *
 * CHANGES LOG:
 *   - June 2026: Switched from users table to alarm_users table
 *   - June 2026: Using alarm_jwt_helper.php instead of jwt_helper.php
 *   - June 2026: Password uses MD5 (not bcrypt) for simplicity
 * ============================================================
 */