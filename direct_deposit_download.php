<?php
/**
 * Path: /direct_deposit_download.php?user_id=
 * Secure download of direct deposit upload.
 * Self: own file only. Admin (role <= 30): same org (superadmin: any).
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/employee_selfserve_lib.php';

ess_ensure_dd_columns($pdo);

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Login required');
}

$viewer_id = (int)$_SESSION['user_id'];
$viewer_lvl = (int)($_SESSION['user_level'] ?? 99);
$viewer_org = (int)($_SESSION['org_id'] ?? 0);
$target_id = (int)($_GET['user_id'] ?? $viewer_id);
if ($target_id <= 0) {
    http_response_code(400);
    exit('Missing user');
}

$stmt = $pdo->prepare(
    "SELECT id, org_id, direct_deposit_file, direct_deposit_orig_name
     FROM users WHERE id = ? LIMIT 1"
);
$stmt->execute([$target_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row || empty($row['direct_deposit_file'])) {
    http_response_code(404);
    exit('No file on file');
}

$allowed = false;
if ($target_id === $viewer_id) {
    $allowed = true;
} elseif ($viewer_lvl <= 10) {
    $allowed = true;
} elseif ($viewer_lvl <= 30 && (int)$row['org_id'] === $viewer_org) {
    $allowed = true;
}

if (!$allowed) {
    http_response_code(403);
    exit('Not authorized');
}

$abs = ess_dd_absolute_path((string)$row['direct_deposit_file']);
if (!$abs) {
    http_response_code(404);
    exit('File missing');
}

$orig = (string)($row['direct_deposit_orig_name'] ?: basename($abs));
$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$mime = 'application/octet-stream';
if ($ext === 'pdf') {
    $mime = 'application/pdf';
} elseif ($ext === 'jpg' || $ext === 'jpeg') {
    $mime = 'image/jpeg';
} elseif ($ext === 'png') {
    $mime = 'image/png';
}

while (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $orig) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($abs);
exit;
