<?php
/**
 * Per-page Questions & answers (help_qa).
 * The panel is injected from engine/menu_generator.php, so a page does not
 * include it itself. page_key is the script path under the payroll root.
 *
 * visible_roles is a JSON list of role names from Menu Manager
 * (superadmin, admin, site_manager, supervisor, demo, staff).
 * A person sees an entry when their level is at least as strong as one
 * listed role (a smaller user_level number). Adding "admin" to the JSON
 * shows it to admins and superadmins. No code change is required.
 *
 * requires_feature is hidden unless the matching constant is true.
 * FAQ_T4127_LIVE and FAQ_QUICK_ENTRY_LIVE default to false.
 */

if (!defined('FAQ_T4127_LIVE')) {
    define('FAQ_T4127_LIVE', false);
}
if (!defined('FAQ_QUICK_ENTRY_LIVE')) {
    define('FAQ_QUICK_ENTRY_LIVE', false);
}

function help_qa_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function help_qa_page_key(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $script = ltrim($script, '/');
    if (strncmp($script, 'payroll/', 8) === 0) {
        $script = substr($script, 8);
    }
    if ($script === '' || strpos($script, '..') !== false) {
        return '';
    }
    return $script;
}

function help_qa_valid_page_key(string $key): bool
{
    return (bool)preg_match('#^[a-z0-9_./-]+\.php$#', $key) && strpos($key, '..') === false;
}

/** Strongest role first. The number is the weakest user_level that still has that name. */
function help_qa_role_rank(): array
{
    return [
        'superadmin' => 10,
        'admin' => 20,
        'site_manager' => 25,
        'supervisor' => 30,
        'demo' => 35,
        'staff' => 40,
    ];
}

function help_qa_is_superadmin(): bool
{
    return (int)($_SESSION['user_level'] ?? 99) <= 10;
}

function help_qa_session_org(): int
{
    return (int)($_SESSION['org_id'] ?? 0);
}

function help_qa_json_list($raw): array
{
    if (is_array($raw)) {
        $list = $raw;
    } else {
        $decoded = json_decode((string)$raw, true);
        $list = is_array($decoded) ? $decoded : [];
    }
    $out = [];
    foreach ($list as $item) {
        if (!is_string($item) && !is_int($item)) {
            continue;
        }
        $item = trim((string)$item);
        if ($item !== '' && !in_array($item, $out, true)) {
            $out[] = $item;
        }
    }
    return $out;
}

function help_qa_role_sees(array $visibleRoles, int $level): bool
{
    $rank = help_qa_role_rank();
    foreach ($visibleRoles as $role) {
        if (!isset($rank[$role])) {
            continue;
        }
        if ($level <= $rank[$role]) {
            return true;
        }
    }
    return false;
}

function help_qa_feature_open(?string $feature): bool
{
    $feature = trim((string)$feature);
    if ($feature === '') {
        return true;
    }
    if ($feature === 't4127') {
        return FAQ_T4127_LIVE === true;
    }
    if ($feature === 'quick_entry') {
        return FAQ_QUICK_ENTRY_LIVE === true;
    }
    return false;
}

function help_qa_table_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $st = $pdo->query("SHOW TABLES LIKE 'help_qa'");
        $ready = (bool)($st && $st->fetchColumn());
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function help_qa_editor_email(PDO $pdo): string
{
    $fromSession = trim((string)($_SESSION['user_email'] ?? ''));
    if ($fromSession !== '' && strpos($fromSession, '@') !== false) {
        return substr($fromSession, 0, 191);
    }
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id <= 0) {
        return '';
    }
    try {
        $st = $pdo->prepare('SELECT email FROM users WHERE id = ?');
        $st->execute([$id]);
        $email = trim((string)$st->fetchColumn());
    } catch (Throwable $e) {
        return '';
    }
    return substr($email, 0, 191);
}

function help_qa_rows_for_page(PDO $pdo, string $pageKey): array
{
    if (!help_qa_table_ready($pdo) || !help_qa_valid_page_key($pageKey)) {
        return [];
    }
    $st = $pdo->prepare('SELECT * FROM help_qa WHERE app = ? AND page_key = ? ORDER BY sort_order ASC, id ASC');
    $st->execute(['payroll', $pageKey]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function help_qa_reader_can_see(array $row, int $level, int $orgId): bool
{
    $roles = help_qa_json_list($row['visible_roles'] ?? '[]');
    if (!help_qa_role_sees($roles, $level)) {
        return false;
    }
    $rowOrg = $row['org_id'];
    if ($rowOrg !== null && $rowOrg !== '' && (int)$rowOrg !== $orgId && $level > 10) {
        return false;
    }
    $feature = trim((string)($row['requires_feature'] ?? ''));
    $hidden = (string)($row['status'] ?? '') !== 'live';
    if ($feature !== '' && !help_qa_feature_open($feature)) {
        return false;
    }
    if ($hidden && !($feature !== '' && help_qa_feature_open($feature))) {
        return false;
    }
    return true;
}

function help_qa_normalize_roles($raw): array
{
    if (is_string($raw)) {
        $trim = trim($raw);
        if ($trim !== '' && $trim[0] === '[') {
            $list = help_qa_json_list($trim);
        } else {
            $list = array_values(array_filter(array_map('trim', explode(',', $trim)), static fn($v) => $v !== ''));
        }
    } else {
        $list = help_qa_json_list($raw);
    }
    $known = help_qa_role_rank();
    $out = [];
    foreach ($list as $role) {
        $role = strtolower($role);
        if (isset($known[$role]) && !in_array($role, $out, true)) {
            $out[] = $role;
        }
    }
    return $out ?: ['superadmin'];
}

function help_qa_normalize_keywords($raw): array
{
    if (is_string($raw)) {
        $trim = trim($raw);
        if ($trim === '') {
            return [];
        }
        if ($trim[0] === '[') {
            $list = help_qa_json_list($trim);
        } else {
            $list = array_map('trim', explode(',', $trim));
        }
    } else {
        $list = help_qa_json_list($raw);
    }
    $out = [];
    foreach ($list as $word) {
        $word = trim((string)$word);
        if ($word === '' || strlen($word) > 80) {
            continue;
        }
        if (!in_array($word, $out, true)) {
            $out[] = $word;
        }
        if (count($out) >= 30) {
            break;
        }
    }
    return $out;
}

function help_qa_ensure_link(PDO $pdo, string $title, string $script, int $role): void
{
    if (!help_qa_valid_page_key($script)) {
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
    $st = $pdo->prepare('SELECT MenuID FROM menu_manager WHERE LinkURL LIKE ? ORDER BY MenuID ASC LIMIT 1');
    $st->execute(['%' . $script]);
    if ($st->fetchColumn()) {
        return;
    }
    $parentId = 0;
    $module = '';
    foreach (["'HELP'", "'ADMIN'", "'ADMINISTRATION'"] as $titleSql) {
        try {
            $q = $pdo->query(
                'SELECT * FROM menu_manager WHERE (ParentID = 0 OR ParentID IS NULL) AND UPPER(TRIM(MenuTitle)) = ' . $titleSql . ' ORDER BY MenuID ASC LIMIT 1'
            );
            $parent = $q ? $q->fetch(PDO::FETCH_ASSOC) : false;
        } catch (Throwable $e) {
            $parent = false;
        }
        if ($parent) {
            $parentId = (int)($parent['MenuID'] ?? 0);
            $module = (string)($parent['Module'] ?? '');
            break;
        }
    }
    if ($parentId <= 0) {
        try {
            $q = $pdo->query("SELECT * FROM menu_manager WHERE (ParentID = 0 OR ParentID IS NULL) AND UPPER(MenuTitle) LIKE '%HELP%' ORDER BY MenuID ASC LIMIT 1");
            $parent = $q ? $q->fetch(PDO::FETCH_ASSOC) : false;
        } catch (Throwable $e) {
            $parent = false;
        }
        if ($parent) {
            $parentId = (int)($parent['MenuID'] ?? 0);
            $module = (string)($parent['Module'] ?? '');
        }
    }
    $data = [
        'MenuTitle' => $title,
        'LinkURL' => $script,
        'ParentID' => $parentId,
        'IsActive' => 1,
        'UserRole' => $role,
        'Module' => $module,
        'SortOrder' => 905,
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

function help_qa_export_row(array $row): array
{
    $roles = help_qa_json_list($row['visible_roles'] ?? '[]');
    $keywords = help_qa_json_list($row['keywords'] ?? '[]');
    $org = $row['org_id'];
    return [
        'app' => (string)$row['app'],
        'page_key' => (string)$row['page_key'],
        'question' => (string)$row['question'],
        'answer' => (string)$row['answer'],
        'sort_order' => (int)$row['sort_order'],
        'status' => (string)$row['status'] === 'hidden' ? 'hidden' : 'live',
        'requires_feature' => ($row['requires_feature'] ?? '') !== '' && $row['requires_feature'] !== null ? (string)$row['requires_feature'] : null,
        'visible_roles' => $roles,
        'keywords' => $keywords,
        'org_id' => ($org === null || $org === '') ? null : (int)$org,
        'updated_by' => ($row['updated_by'] ?? '') !== '' && $row['updated_by'] !== null ? (string)$row['updated_by'] : null,
        'edited_in_app' => (int)($row['edited_in_app'] ?? 0) === 1 ? 1 : 0,
        'created_at' => (string)($row['created_at'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? ''),
    ];
}

function help_qa_datetime_or_null($v): ?string
{
    $v = trim((string)$v);
    if ($v === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $v);
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d H:i:s');
    }
    $dt = DateTime::createFromFormat(DateTime::ATOM, $v);
    return $dt instanceof DateTime ? $dt->format('Y-m-d H:i:s') : null;
}

function help_qa_action_url(): string
{
    $page = help_qa_page_key();
    $depth = $page === '' ? 0 : substr_count($page, '/');
    return str_repeat('../', $depth) . 'help_qa_action.php';
}

function help_user_question_table_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $st = $pdo->query("SHOW TABLES LIKE 'help_user_question'");
        $ready = (bool)($st && $st->fetchColumn());
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function help_user_question_status_filter(string $raw): string
{
    return in_array($raw, ['new', 'answered', 'dismissed', 'all'], true) ? $raw : 'new';
}

/**
 * A person may send one question every 30 seconds, and at most 8 in an hour.
 * The same wording still waiting on this page is not stored again.
 * Returns an error sentence, or '' when the question may be saved.
 */
function help_user_question_rate_error(PDO $pdo, string $email, string $pageKey, string $question): string
{
    $recent = $pdo->prepare(
        "SELECT created_at FROM help_user_question
         WHERE app = 'payroll' AND asked_by = ?
         ORDER BY id DESC LIMIT 1"
    );
    $recent->execute([$email]);
    $last = (string)$recent->fetchColumn();
    if ($last !== '') {
        $then = strtotime($last);
        if ($then !== false && (time() - $then) < 30) {
            return 'Please wait a moment before asking another question.';
        }
    }
    $hour = $pdo->prepare(
        "SELECT COUNT(*) FROM help_user_question
         WHERE app = 'payroll' AND asked_by = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)"
    );
    $hour->execute([$email]);
    if ((int)$hour->fetchColumn() >= 8) {
        return 'You have asked several questions in the last hour. Try again later.';
    }
    $dup = $pdo->prepare(
        "SELECT COUNT(*) FROM help_user_question
         WHERE app = 'payroll' AND page_key = ? AND asked_by = ? AND question = ? AND status = 'new'"
    );
    $dup->execute([$pageKey, $email, $question]);
    if ((int)$dup->fetchColumn() > 0) {
        return 'You already asked that on this page. It is still waiting for an answer.';
    }
    return '';
}

function help_user_question_list(PDO $pdo, string $status, string $pageKey = ''): array
{
    if (!help_user_question_table_ready($pdo)) {
        return [];
    }
    $status = help_user_question_status_filter($status);
    $sql = "SELECT * FROM help_user_question WHERE app = 'payroll'";
    $params = [];
    if ($pageKey !== '') {
        $sql .= ' AND page_key = ?';
        $params[] = $pageKey;
    }
    if ($status !== 'all') {
        $sql .= ' AND status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY id DESC LIMIT 200';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function help_user_question_export_row(array $row): array
{
    $org = $row['org_id'] ?? null;
    $link = $row['help_qa_id'] ?? null;
    $handled = $row['handled_by'] ?? null;
    return [
        'id' => (int)$row['id'],
        'app' => (string)$row['app'],
        'page_key' => (string)$row['page_key'],
        'org_id' => ($org === null || $org === '') ? null : (int)$org,
        'question' => (string)$row['question'],
        'asked_by' => (string)$row['asked_by'],
        'status' => (string)$row['status'],
        'help_qa_id' => ($link === null || $link === '') ? null : (int)$link,
        'handled_by' => ($handled === null || $handled === '') ? null : (string)$handled,
        'created_at' => (string)($row['created_at'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? ''),
    ];
}
