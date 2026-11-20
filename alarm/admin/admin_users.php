<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../../db.php';
$alarmJwtLoaded = false;
foreach ([
    __DIR__ . '/alarm_jwt_helper.php',
    __DIR__ . '/../alarm_jwt_helper.php',
    __DIR__ . '/../api/alarm_jwt_helper.php',
] as $alarmJwtFile) {
    if (is_file($alarmJwtFile)) {
        require_once $alarmJwtFile;
        $alarmJwtLoaded = true;
        break;
    }
}
if (!$alarmJwtLoaded) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Auth helper is not available']);
    exit;
}

foreach ([
    __DIR__ . '/alarm_visibility.php',
    __DIR__ . '/../alarm_visibility.php',
    __DIR__ . '/../api/alarm_visibility.php',
] as $alarmVisFile) {
    if (is_file($alarmVisFile)) {
        require_once $alarmVisFile;
        break;
    }
}
foreach ([
    __DIR__ . '/alarm_invite_lib.php',
    __DIR__ . '/../alarm_invite_lib.php',
    __DIR__ . '/../api/alarm_invite_lib.php',
] as $alarmInviteFile) {
    if (is_file($alarmInviteFile)) {
        require_once $alarmInviteFile;
        break;
    }
}
if (!function_exists('alarm_vis_visible')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'People list is not available']);
    exit;
}

function alarm_admin_visible_ids(PDO $pdo, int $actorId, bool $includeInactive): array {
    $directory = alarm_vis_load_directory($pdo);
    $actor = alarm_vis_actor($directory, $actorId);
    if (!$actor) return [];
    $ids = [];
    foreach (alarm_vis_visible($actor, $directory, $includeInactive) as $row) {
        $ids[(int)$row['user_id']] = true;
    }
    return $ids;
}

function alarm_is_manager(int $role): bool {
    return $role === 10 || $role === 20;
}

function alarm_is_super(int $role): bool {
    return $role === 10;
}

function alarm_role_label(int $role): string {
    if ($role === 10) return 'Super Admin';
    if ($role === 20) return 'Admin';
    return 'User';
}

function alarm_normalize_role($raw): int {
    $role = (int)$raw;
    return in_array($role, [1, 10, 20], true) ? $role : 1;
}

function alarm_ensure_orgs(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS alarm_orgs (
        org_id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        owner_user_id INT NOT NULL DEFAULT 0,
        active TINYINT NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $count = (int)$pdo->query("SELECT COUNT(*) FROM alarm_orgs")->fetchColumn();
    if ($count === 0) {
        $pdo->prepare("INSERT INTO alarm_orgs (name, owner_user_id, active) VALUES (?, 0, 1)")->execute(['AlarmPulse']);
        $defaultId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE alarm_users SET org_id = ? WHERE org_id IS NULL OR org_id = 0")->execute([$defaultId]);
    }
}

function alarm_fetch_org(PDO $pdo, int $orgId): ?array {
    if ($orgId < 1) return null;
    $stmt = $pdo->prepare("SELECT org_id, name, owner_user_id, active, created_at FROM alarm_orgs WHERE org_id = ? LIMIT 1");
    $stmt->execute([$orgId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function alarm_org_member_count(PDO $pdo, int $orgId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM alarm_users WHERE org_id = ?");
    $stmt->execute([$orgId]);
    return (int)$stmt->fetchColumn();
}

function alarm_public_org(PDO $pdo, array $row): array {
    $orgId = (int)$row['org_id'];
    return [
        'org_id' => $orgId,
        'name' => (string)$row['name'],
        'owner_user_id' => (int)$row['owner_user_id'],
        'active' => (int)$row['active'] === 1 ? 1 : 0,
        'created_at' => (string)($row['created_at'] ?? ''),
        'member_count' => alarm_org_member_count($pdo, $orgId),
    ];
}

function alarm_fetch_user(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT u.user_id, u.name, u.email, u.tel, u.active, u.role, u.org_id, u.timezone, u.created_at, o.name AS org_name
        FROM alarm_users u
        LEFT JOIN alarm_orgs o ON o.org_id = u.org_id
        WHERE u.user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function alarm_public_user(array $row): array {
    $role = (int)($row['role'] ?? 1);
    return [
        'user_id'    => (int)$row['user_id'],
        'name'       => (string)($row['name'] ?? ''),
        'email'      => (string)($row['email'] ?? ''),
        'tel'        => (string)($row['tel'] ?? ''),
        'active'     => (int)$row['active'] === 1 ? 1 : 0,
        'role'       => $role,
        'role_label' => alarm_role_label($role),
        'org_id'     => (int)($row['org_id'] ?? 0),
        'org_name'   => (string)($row['org_name'] ?? ''),
        'timezone'   => (string)($row['timezone'] ?? 'America/Edmonton'),
        'created_at' => (string)($row['created_at'] ?? ''),
    ];
}

function alarm_can_manage_user(int $actorRole, int $actorOrg, array $target): bool {
    if (alarm_is_super($actorRole)) return true;
    return (int)$target['role'] !== 10 && (int)$target['org_id'] === $actorOrg;
}

function alarm_can_assign_role(int $actorRole, int $newRole): bool {
    if (alarm_is_super($actorRole)) return in_array($newRole, [1, 10, 20], true);
    return $newRole === 1 || $newRole === 20;
}

function alarm_resolve_org_id(int $actorRole, int $actorOrg, $requested): int {
    $orgId = (int)$requested;
    if (alarm_is_super($actorRole)) return $orgId;
    return $actorOrg;
}

function alarm_count_super_admins(PDO $pdo, int $exceptId = 0): int {
    $sql = "SELECT COUNT(*) FROM alarm_users WHERE role = 10 AND active = 1";
    $params = [];
    if ($exceptId > 0) {
        $sql .= " AND user_id <> ?";
        $params[] = $exceptId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

$auth = alarm_require_auth();
$actorId   = (int)($auth['user_id'] ?? 0);
$actorRole = (int)($auth['role'] ?? 0);

if ($actorId < 1 || !alarm_is_manager($actorRole)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admins only']);
    exit;
}

try {
    alarm_ensure_orgs($pdo);
    alarm_vis_ensure_schema($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not prepare teams']);
    exit;
}

$actor = alarm_fetch_user($pdo, $actorId);
if (!$actor) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
$actorOrg = (int)($actor['org_id'] ?? 0);

$body = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    $body = is_array($decoded) ? $decoded : $_POST;
}
$action = strtolower(trim((string)($body['action'] ?? $_GET['action'] ?? 'list')));

try {
    if ($action === 'list_orgs') {
        if (alarm_is_super($actorRole)) {
            $rows = $pdo->query("SELECT org_id, name, owner_user_id, active, created_at FROM alarm_orgs ORDER BY name ASC, org_id ASC")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare("SELECT org_id, name, owner_user_id, active, created_at FROM alarm_orgs WHERE org_id = ? LIMIT 1");
            $stmt->execute([$actorOrg]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $orgs = [];
        foreach ($rows as $row) $orgs[] = alarm_public_org($pdo, $row);
        echo json_encode([
            'success' => true,
            'me' => alarm_public_user($actor),
            'orgs' => $orgs,
        ]);
        exit;
    }

    if ($action === 'create_org') {
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Team name is required']);
            exit;
        }
        $dup = $pdo->prepare("SELECT org_id FROM alarm_orgs WHERE LOWER(TRIM(name)) = ? LIMIT 1");
        $dup->execute([strtolower($name)]);
        if ($dup->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'A team with that name already exists']);
            exit;
        }
        $ins = $pdo->prepare("INSERT INTO alarm_orgs (name, owner_user_id, active, created_at) VALUES (?, ?, 1, NOW())");
        $ins->execute([$name, $actorId]);
        $orgId = (int)$pdo->lastInsertId();

        if (!alarm_is_super($actorRole) && $actorOrg < 1) {
            $pdo->prepare("UPDATE alarm_users SET org_id = ?, role = 20 WHERE user_id = ?")->execute([$orgId, $actorId]);
            $pdo->prepare("UPDATE alarm_orgs SET owner_user_id = ? WHERE org_id = ?")->execute([$actorId, $orgId]);
            $actor = alarm_fetch_user($pdo, $actorId);
        }

        echo json_encode(['success' => true, 'org' => alarm_public_org($pdo, alarm_fetch_org($pdo, $orgId)), 'me' => alarm_public_user($actor)]);
        exit;
    }

    if ($action === 'update_org') {
        $orgId = (int)($body['org_id'] ?? 0);
        $org = alarm_fetch_org($pdo, $orgId);
        if (!$org) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Team not found']);
            exit;
        }
        if (!alarm_is_super($actorRole) && $orgId !== $actorOrg) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You can only edit your own team']);
            exit;
        }
        $name = array_key_exists('name', $body) ? trim((string)$body['name']) : (string)$org['name'];
        $active = array_key_exists('active', $body) ? ((int)$body['active'] === 1 ? 1 : 0) : (int)$org['active'];
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Team name is required']);
            exit;
        }
        $dup = $pdo->prepare("SELECT org_id FROM alarm_orgs WHERE LOWER(TRIM(name)) = ? AND org_id <> ? LIMIT 1");
        $dup->execute([strtolower($name), $orgId]);
        if ($dup->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'A team with that name already exists']);
            exit;
        }
        $pdo->prepare("UPDATE alarm_orgs SET name = ?, active = ? WHERE org_id = ?")->execute([$name, $active, $orgId]);
        echo json_encode(['success' => true, 'org' => alarm_public_org($pdo, alarm_fetch_org($pdo, $orgId))]);
        exit;
    }

    if ($action === 'list') {
        $filterOrg = alarm_resolve_org_id($actorRole, $actorOrg, $body['org_id'] ?? $_GET['org_id'] ?? $actorOrg);
        if (!alarm_is_super($actorRole)) $filterOrg = $actorOrg;
        if ($filterOrg > 0) {
            $stmt = $pdo->prepare("SELECT u.user_id, u.name, u.email, u.tel, u.active, u.role, u.org_id, u.timezone, u.created_at, o.name AS org_name
                FROM alarm_users u LEFT JOIN alarm_orgs o ON o.org_id = u.org_id
                WHERE u.org_id = ? ORDER BY u.role DESC, u.name ASC, u.user_id ASC");
            $stmt->execute([$filterOrg]);
        } else {
            $stmt = $pdo->query("SELECT u.user_id, u.name, u.email, u.tel, u.active, u.role, u.org_id, u.timezone, u.created_at, o.name AS org_name
                FROM alarm_users u LEFT JOIN alarm_orgs o ON o.org_id = u.org_id
                ORDER BY o.name ASC, u.role DESC, u.name ASC");
        }
        $users = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $users[] = alarm_public_user($row);
        $allowed = alarm_admin_visible_ids($pdo, $actorId, true);
        $users = array_values(array_filter($users, function ($row) use ($allowed) {
            return isset($allowed[(int)$row['user_id']]);
        }));
        echo json_encode([
            'success' => true,
            'me' => alarm_public_user($actor),
            'org_id' => $filterOrg,
            'users' => $users,
        ]);
        exit;
    }

    if ($action === 'create') {
        $name     = trim((string)($body['name'] ?? ''));
        $email    = strtolower(trim((string)($body['email'] ?? '')));
        $tel      = trim((string)($body['tel'] ?? $body['phone'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $role     = alarm_normalize_role($body['role'] ?? 1);
        $active   = isset($body['active']) ? ((int)$body['active'] === 1 ? 1 : 0) : 1;
        $orgId    = alarm_resolve_org_id($actorRole, $actorOrg, $body['org_id'] ?? $actorOrg);

        if ($name === '' || $email === '' || $password === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Name, email, and password are required']);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Enter a valid email']);
            exit;
        }
        if (strlen($password) < 4) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Password must be at least 4 characters']);
            exit;
        }
        if (!alarm_can_assign_role($actorRole, $role)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You cannot assign that role']);
            exit;
        }
        if (!alarm_is_super($actorRole) && $role === 10) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You cannot create a Super Admin']);
            exit;
        }
        if ($orgId < 1 || !alarm_fetch_org($pdo, $orgId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Choose a team for this user']);
            exit;
        }
        if (!alarm_is_super($actorRole) && $orgId !== $actorOrg) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You can only add users to your team']);
            exit;
        }

        $check = $pdo->prepare("SELECT user_id FROM alarm_users WHERE LOWER(TRIM(email)) = ? LIMIT 1");
        $check->execute([$email]);
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Email already registered']);
            exit;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $userCols = alarm_vis_table_columns($pdo, 'alarm_users');
        if (isset($userCols['reports_to'])) {
            $reportsTo = $role === 10 ? null : $actorId;
            $ins = $pdo->prepare("INSERT INTO alarm_users (name, email, tel, password, active, role, org_id, reports_to, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $ins->execute([$name, $email, $tel, $hash, $active, $role, $orgId, $reportsTo]);
        } else {
            $ins = $pdo->prepare("INSERT INTO alarm_users (name, email, tel, password, active, role, org_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $ins->execute([$name, $email, $tel, $hash, $active, $role, $orgId]);
        }
        $newId = (int)$pdo->lastInsertId();
        if (function_exists('alarm_invite_claim_user')) alarm_invite_claim_user($pdo, $newId, $email, $tel);
        if ($role === 20) {
            $pdo->prepare("UPDATE alarm_orgs SET owner_user_id = ? WHERE org_id = ? AND owner_user_id = 0")->execute([$newId, $orgId]);
        }
        echo json_encode(['success' => true, 'user' => alarm_public_user(alarm_fetch_user($pdo, $newId))]);
        exit;
    }

    if ($action === 'update') {
        $userId = (int)($body['user_id'] ?? 0);
        $target = $userId > 0 ? alarm_fetch_user($pdo, $userId) : null;
        if (!$target) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'User not found']);
            exit;
        }
        if (!alarm_can_manage_user($actorRole, $actorOrg, $target) || !isset(alarm_admin_visible_ids($pdo, $actorId, true)[$userId])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You cannot edit this user']);
            exit;
        }

        $name   = array_key_exists('name', $body) ? trim((string)$body['name']) : (string)$target['name'];
        $email  = array_key_exists('email', $body) ? strtolower(trim((string)$body['email'])) : strtolower(trim((string)$target['email']));
        $tel    = array_key_exists('tel', $body) ? trim((string)$body['tel']) : (array_key_exists('phone', $body) ? trim((string)$body['phone']) : (string)$target['tel']);
        $role   = array_key_exists('role', $body) ? alarm_normalize_role($body['role']) : (int)$target['role'];
        $active = array_key_exists('active', $body) ? ((int)$body['active'] === 1 ? 1 : 0) : (int)$target['active'];
        $orgId  = alarm_resolve_org_id($actorRole, $actorOrg, $body['org_id'] ?? $target['org_id']);

        if ($name === '' || $email === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Name and email are required']);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Enter a valid email']);
            exit;
        }
        if (!alarm_can_assign_role($actorRole, $role)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You cannot assign that role']);
            exit;
        }
        if ($userId === $actorId && $active !== 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'You cannot deactivate your own account']);
            exit;
        }
        if ((int)$target['role'] === 10 && ($role !== 10 || $active !== 1) && alarm_count_super_admins($pdo, $userId) < 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Keep at least one active Super Admin']);
            exit;
        }
        if ($orgId < 1 || !alarm_fetch_org($pdo, $orgId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Choose a team for this user']);
            exit;
        }

        $dup = $pdo->prepare("SELECT user_id FROM alarm_users WHERE LOWER(TRIM(email)) = ? AND user_id <> ? LIMIT 1");
        $dup->execute([$email, $userId]);
        if ($dup->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Email already registered']);
            exit;
        }

        $upd = $pdo->prepare("UPDATE alarm_users SET name = ?, email = ?, tel = ?, role = ?, active = ?, org_id = ? WHERE user_id = ?");
        $upd->execute([$name, $email, $tel, $role, $active, $orgId, $userId]);

        $password = (string)($body['password'] ?? '');
        if ($password !== '') {
            if (strlen($password) < 4) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Password must be at least 4 characters']);
                exit;
            }
            $pw = $pdo->prepare("UPDATE alarm_users SET password = ?, reset_token = NULL, token_expiry = NULL WHERE user_id = ?");
            $pw->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        }

        if (function_exists('alarm_invite_claim_user')) alarm_invite_claim_user($pdo, $userId, $email, $tel);
        echo json_encode(['success' => true, 'user' => alarm_public_user(alarm_fetch_user($pdo, $userId))]);
        exit;
    }

    if ($action === 'set_password') {
        $userId = (int)($body['user_id'] ?? 0);
        $password = (string)($body['password'] ?? '');
        $target = $userId > 0 ? alarm_fetch_user($pdo, $userId) : null;
        if (!$target) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'User not found']);
            exit;
        }
        if (!alarm_can_manage_user($actorRole, $actorOrg, $target) || !isset(alarm_admin_visible_ids($pdo, $actorId, true)[$userId])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You cannot change this password']);
            exit;
        }
        if (strlen($password) < 4) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Password must be at least 4 characters']);
            exit;
        }
        $pw = $pdo->prepare("UPDATE alarm_users SET password = ?, reset_token = NULL, token_expiry = NULL WHERE user_id = ?");
        $pw->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        echo json_encode(['success' => true, 'message' => 'Password updated', 'user' => alarm_public_user($target)]);
        exit;
    }

    if ($action === 'set_active') {
        $userId = (int)($body['user_id'] ?? 0);
        $active = (int)($body['active'] ?? 0) === 1 ? 1 : 0;
        $target = $userId > 0 ? alarm_fetch_user($pdo, $userId) : null;
        if (!$target) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'User not found']);
            exit;
        }
        if (!alarm_can_manage_user($actorRole, $actorOrg, $target) || !isset(alarm_admin_visible_ids($pdo, $actorId, true)[$userId])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You cannot change this user']);
            exit;
        }
        if ($userId === $actorId && $active !== 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'You cannot deactivate your own account']);
            exit;
        }
        if ((int)$target['role'] === 10 && $active !== 1 && alarm_count_super_admins($pdo, $userId) < 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Keep at least one active Super Admin']);
            exit;
        }
        $upd = $pdo->prepare("UPDATE alarm_users SET active = ? WHERE user_id = ?");
        $upd->execute([$active, $userId]);
        echo json_encode(['success' => true, 'user' => alarm_public_user(alarm_fetch_user($pdo, $userId))]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save']);
}
