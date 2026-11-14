<?php
/**
 * Superadmin gate and Admin-menu links for pages that are not for other roles.
 *
 * Menu Manager shows a link when UserRole >= the person's role number.
 * Role 10 is Superadmin, 20 is Admin. A link stored as UserRole 10 is
 * therefore visible to superadmins and hidden from everyone else.
 */

function admin_super_is(): bool
{
    if (function_exists('ps_is_superadmin')) {
        return ps_is_superadmin();
    }
    return (int)($_SESSION['user_level'] ?? 99) <= 10;
}

function admin_super_require(bool $json): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $uid = $_SESSION['user_id'] ?? null;
    if ($uid === null || $uid === '') {
        if ($json) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => 'Login required.']);
            exit;
        }
        header('Location: login_kiosk.php?err=session_expired');
        exit;
    }
    if (admin_super_is()) {
        return;
    }
    if ($json) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Superadmin access required.']);
        exit;
    }
    http_response_code(403);
    exit('Superadmin access required.');
}

/**
 * Put $script under the Admin menu with Min. Role = Superadmin (10).
 * Safe to call on every page view. Does not touch payroll or sales tables.
 */
function admin_super_ensure_menu(PDO $pdo, string $title, string $script): void
{
    if (!preg_match('/^[a-z0-9_]+\.php$/', $script)) {
        return;
    }
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM menu_manager')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }
    if (!$cols) {
        return;
    }
    $have = array_flip($cols);
    $parentId = 0;
    $module = '';
    $parent = admin_super_admin_parent($pdo);
    if ($parent) {
        $parentId = (int)($parent['MenuID'] ?? 0);
        if (isset($parent['Module'])) {
            $module = (string)$parent['Module'];
        }
    }
    $st = $pdo->prepare('SELECT * FROM menu_manager WHERE LinkURL LIKE ? ORDER BY MenuID ASC LIMIT 1');
    $st->execute(['%' . $script]);
    $existing = $st->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $sets = [];
        $vals = [];
        if (isset($have['UserRole']) && (int)$existing['UserRole'] !== 10) {
            $sets[] = '`UserRole` = ?';
            $vals[] = 10;
        }
        if (isset($have['ParentID']) && $parentId > 0 && (int)$existing['ParentID'] !== $parentId) {
            $sets[] = '`ParentID` = ?';
            $vals[] = $parentId;
        }
        if (isset($have['IsActive']) && (string)$existing['IsActive'] !== '1') {
            $sets[] = '`IsActive` = ?';
            $vals[] = 1;
        }
        if ($sets) {
            $vals[] = (int)$existing['MenuID'];
            $pdo->prepare('UPDATE menu_manager SET ' . implode(', ', $sets) . ' WHERE MenuID = ?')->execute($vals);
        }
        return;
    }
    $data = [
        'MenuTitle' => $title,
        'LinkURL' => $script,
        'ParentID' => $parentId,
        'IsActive' => 1,
        'UserRole' => 10,
        'Module' => $module,
        'SortOrder' => 900,
    ];
    $fields = [];
    $holders = [];
    $vals = [];
    foreach ($data as $col => $val) {
        if (!isset($have[$col])) {
            continue;
        }
        $fields[] = '`' . str_replace('`', '', $col) . '`';
        $holders[] = '?';
        $vals[] = $val;
    }
    if ($fields) {
        $pdo->prepare('INSERT INTO menu_manager (' . implode(',', $fields) . ') VALUES (' . implode(',', $holders) . ')')->execute($vals);
    }
}

function admin_super_admin_parent(PDO $pdo): ?array
{
    $sql = "SELECT * FROM menu_manager
            WHERE (ParentID = 0 OR ParentID IS NULL)
              AND UPPER(TRIM(MenuTitle)) IN ('ADMIN', 'ADMINISTRATION')
            ORDER BY MenuID ASC LIMIT 1";
    $row = $pdo->query($sql);
    $parent = $row ? $row->fetch(PDO::FETCH_ASSOC) : false;
    if ($parent) {
        return $parent;
    }
    $row = $pdo->query("SELECT * FROM menu_manager
            WHERE (ParentID = 0 OR ParentID IS NULL)
              AND UPPER(MenuTitle) LIKE '%ADMIN%'
            ORDER BY MenuID ASC LIMIT 1");
    $parent = $row ? $row->fetch(PDO::FETCH_ASSOC) : false;
    return $parent ?: null;
}
