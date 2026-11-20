<?php
/**
 * Path: /alarm/api/upload.php
 * Method: POST (multipart/form-data)
 * Headers: Authorization: Bearer <token>
 * Form fields: file, task_id
 *
 * Free tier: no uploads allowed — must use attachment_url link
 * Paid tier: up to 10MB per file
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../../db.php';
require_once '../../api/jwt_helper.php';
require_once 'config.php';

$auth    = require_auth();
$uid     = (int)$auth['user_id'];
$task_id = (int)($_POST['task_id'] ?? 0);

// Check paid status
$paidStmt = $pdo->prepare("SELECT is_paid_account FROM alarm_tasks WHERE task_id=? AND created_by=? LIMIT 1");
$paidStmt->execute([$task_id, $uid]);
$row = $paidStmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['success' => false, 'error' => 'Alarm not found or not yours']);
    exit;
}

if (!(int)$row['is_paid_account']) {
    echo json_encode([
        'success'  => false,
        'error'    => 'File uploads require a paid account. Free accounts can paste a link (Google Drive, Dropbox, etc.) in the attachment URL field.',
        'upgrade_required' => true,
    ]);
    exit;
}

if (!isset($_FILES['file'])) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit;
}

$file     = $_FILES['file'];
$max_bytes = MAX_PAID_MB * 1024 * 1024;

if ($file['size'] > $max_bytes) {
    echo json_encode(['success' => false, 'error' => "File too large. Maximum size is " . MAX_PAID_MB . "MB."]);
    exit;
}

$allowed = ['pdf','doc','docx','xls','xlsx','png','jpg','jpeg','gif','txt','csv'];
$ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowed)) {
    echo json_encode(['success' => false, 'error' => "File type .{$ext} not allowed."]);
    exit;
}

// Create upload dir if needed
if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

$filename = 'alarm_' . $task_id . '_' . time() . '.' . $ext;
$dest     = UPLOAD_DIR . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    echo json_encode(['success' => false, 'error' => 'Upload failed']);
    exit;
}

$file_url = UPLOAD_URL . $filename;

// Save to alarm record
$pdo->prepare("UPDATE alarm_tasks SET attachment_file=?, attachment_size=? WHERE task_id=?")
    ->execute([$filename, $file['size'], $task_id]);

echo json_encode([
    'success'  => true,
    'filename' => $filename,
    'url'      => $file_url,
    'size'     => $file['size'],
    'message'  => 'File uploaded successfully',
]);
