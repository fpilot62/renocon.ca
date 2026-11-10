<?php
/**
 * Path: /get_staff_audit.php
 * JSON feed of attendance for admins. Requires a logged-in admin.
 * Soft-deleted rows are omitted. An unfiltered request returns open shifts and today's punches.
 */
require_once __DIR__ . '/engine/attendance_integrity_lib.php';
payroll_require_admin(true, 'Staff audit data is for admins only.');
require_once __DIR__ . '/db.php';
payroll_require_scope_admin();

header('Content-Type: application/json; charset=UTF-8');

$level = (int)($_SESSION['user_level'] ?? 99);
$sessionOrg = (int)($_SESSION['org_id'] ?? 0);
$requested = (int)($_GET['org_id'] ?? $_POST['org_id'] ?? 0);
if ($level <= 10) {
    $org = $requested;
} else {
    $org = $sessionOrg;
    if ($requested > 0 && $requested !== $org) {
        http_response_code(403);
        echo json_encode(['success' => false, 'status' => 'error', 'error' => 'That organization is outside your access.']);
        exit;
    }
    if ($org <= 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'status' => 'error', 'error' => 'That organization is outside your access.']);
        exit;
    }
}

$sql = "SELECT a.id, a.site_id, a.clock_in, a.clock_out, a.selfie_in,
               u.Name AS staff_name, COALESCE(s.nick_name, '') AS site_name
        FROM attendance a
        INNER JOIN users u ON u.id = a.user_id
        LEFT JOIN sites s ON s.SId = a.site_id
        WHERE " . att_live_predicate($pdo, 'a');
$params = [];
if ($org > 0) {
    $sql .= ' AND (a.org_id = ? OR u.org_id = ? OR s.org_id = ?)';
    array_push($params, $org, $org, $org);
}

$siteId = (int)($_GET['site_id'] ?? $_POST['site_id'] ?? 0);
if ($siteId > 0) {
    $sql .= ' AND a.site_id = ?';
    $params[] = $siteId;
}
$userId = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
if ($userId > 0) {
    $sql .= ' AND a.user_id = ?';
    $params[] = $userId;
}

$from = trim((string)($_GET['from_date'] ?? $_POST['from_date'] ?? ''));
$to = trim((string)($_GET['to_date'] ?? $_POST['to_date'] ?? ''));
$fromOk = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $from);
$toOk = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $to);
if ($fromOk) {
    $sql .= ' AND DATE(a.clock_in) >= ?';
    $params[] = $from;
}
if ($toOk) {
    $sql .= ' AND DATE(a.clock_in) <= ?';
    $params[] = $to;
}
if (!$fromOk && !$toOk) {
    $sql .= " AND (a.clock_out IS NULL OR a.clock_out = '' OR a.clock_out = '0000-00-00 00:00:00' OR DATE(a.clock_in) = CURDATE())";
}
$sql .= ' ORDER BY a.clock_in DESC LIMIT 100';

$st = $pdo->prepare($sql);
$st->execute($params);
$data = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $data[] = [
        'staff_name' => (string)($row['staff_name'] ?? ''),
        'site_name' => (string)($row['site_name'] ?? ''),
        'site_id' => (int)($row['site_id'] ?? 0),
        'id' => (int)($row['id'] ?? 0),
        'clock_in' => $row['clock_in'],
        'clock_out' => $row['clock_out'],
        'image_data' => att_selfie_data_uri((string)($row['selfie_in'] ?? '')),
    ];
}

echo json_encode(['status' => 'success', 'data' => $data]);
