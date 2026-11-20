<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../payroll_reset.php';

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}
$ident = trim((string)($body['username'] ?? $body['email'] ?? $body['ident'] ?? ''));

try {
    $result = payroll_request_password_reset($pdo, $ident);
    if (!empty($result['ok'])) {
        echo json_encode(['success' => true, 'message' => $result['message'] ?? 'If that account exists and has an email, we sent a reset link. It expires in 1 hour.']);
        exit;
    }
    $code = (($result['error'] ?? '') === 'Enter your username, PIN, or email.') ? 400 : 500;
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Could not send reset email.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not send reset email.']);
}
