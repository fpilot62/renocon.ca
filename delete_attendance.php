<?php
/**
 * Path: /delete_attendance.php
 * Soft-deletes attendance rows. Requires an admin session.
 * The previous copy of this file accepted deletes with no login.
 */
require_once __DIR__ . '/engine/attendance_integrity_lib.php';
payroll_require_admin(true, 'Deleting attendance is for admins only.');
require_once __DIR__ . '/db.php';
payroll_require_scope_admin();

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $ids = att_posted_ids($_GET);
    echo json_encode([
        'success' => false,
        'error' => $ids ? 'POST required.' : 'No IDs provided',
    ]);
    exit;
}

$ids = att_posted_ids($_POST);
if (!$ids) {
    echo json_encode(['success' => false, 'error' => 'No IDs provided']);
    exit;
}

if (!att_soft_delete_ready($pdo)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Run migrations/20261110_attendance_soft_delete.sql before deleting attendance.',
    ]);
    exit;
}

$level = (int)($_SESSION['user_level'] ?? 99);
$sessionOrg = (int)($_SESSION['org_id'] ?? 0);
$selected = (int)($_POST['org_id'] ?? 0);
$restrict = true;
$scope = $sessionOrg;
if ($level <= 10) {
    if ($selected > 0) {
        $scope = $selected;
    } else {
        $restrict = false;
    }
} elseif ($selected > 0 && $selected !== $sessionOrg) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'That organization is outside your access.']);
    exit;
} elseif ($scope <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'That organization is outside your access.']);
    exit;
}

$sql = att_soft_delete_sql(count($ids), $restrict);
$params = array_merge([(int)($_SESSION['user_id'] ?? 0)], $ids);
if ($restrict) {
    $params[] = $scope;
    $params[] = $scope;
    $params[] = $scope;
}
$st = $pdo->prepare($sql);
$st->execute($params);
echo json_encode(['success' => true, 'deleted' => $st->rowCount()]);
