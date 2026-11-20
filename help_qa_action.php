<?php
/**
 * Path: /help_qa_action.php
 * Superadmin writes for the Questions & answers panel.
 * Sets edited_in_app = 1 so the seed will not overwrite the row.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll_scope.php';
require_once __DIR__ . '/engine/help_qa_lib.php';

ps_require_csrf();
if (empty($_SESSION['user_id'])) {
    header('Location: login_kiosk.php?err=session_expired');
    exit;
}

$action = (string)($_POST['action'] ?? '');
$pageKey = (string)($_POST['page_key'] ?? '');
$email = help_qa_editor_email($pdo);
$back = help_qa_action_back();

if ($action === 'ask_question') {
    if (!help_qa_valid_page_key($pageKey)) {
        http_response_code(400);
        exit('That page key is not valid.');
    }
    if (!help_user_question_table_ready($pdo)) {
        http_response_code(503);
        exit('Asking a question is not set up yet. Run migrations/20261120_help_user_question.sql after a backup.');
    }
    $question = trim((string)($_POST['question'] ?? ''));
    if ($question === '' || strlen($question) > 500) {
        $_SESSION['help_ask_notice'] = 'Type a question of 500 characters or fewer.';
        header('Location: ' . $back);
        exit;
    }
    if ($email === '' || strpos($email, '@') === false) {
        $_SESSION['help_ask_notice'] = 'Your profile has no email address, so the question was not saved.';
        header('Location: ' . $back);
        exit;
    }
    $limit = help_user_question_rate_error($pdo, $email, $pageKey, $question);
    if ($limit !== '') {
        $_SESSION['help_ask_notice'] = $limit;
        header('Location: ' . $back);
        exit;
    }
    $org = help_qa_session_org();
    $pdo->prepare(
        'INSERT INTO help_user_question (app, page_key, org_id, question, asked_by, status)
         VALUES (\'payroll\', ?, ?, ?, ?, \'new\')'
    )->execute([$pageKey, $org > 0 ? $org : null, $question, $email]);
    $_SESSION['help_ask_notice'] = 'Sent. A superadmin can answer it from this page.';
    header('Location: ' . $back);
    exit;
}

if (!help_qa_is_superadmin()) {
    http_response_code(403);
    exit('Superadmin access required.');
}

if ($action === 'answer_user_question' || $action === 'dismiss_user_question') {
    if (!help_user_question_table_ready($pdo)) {
        http_response_code(503);
        exit('User questions are not set up yet. Run migrations/20261120_help_user_question.sql after a backup.');
    }
    $uqId = (int)($_POST['user_question_id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM help_user_question WHERE id = ? AND app = 'payroll'");
    $st->execute([$uqId]);
    $asked = $st->fetch(PDO::FETCH_ASSOC);
    if (!$asked || (string)$asked['status'] !== 'new') {
        http_response_code(404);
        exit('That question is not waiting for an answer.');
    }
    if ($email === '') {
        http_response_code(400);
        exit('Your profile has no email address.');
    }
    if ($action === 'dismiss_user_question') {
        $pdo->prepare(
            "UPDATE help_user_question SET status = 'dismissed', handled_by = ? WHERE id = ? AND app = 'payroll' AND status = 'new'"
        )->execute([$email, $uqId]);
        header('Location: ' . $back);
        exit;
    }
    if (!help_qa_table_ready($pdo)) {
        http_response_code(503);
        exit('help_qa is not set up. Run migrations/20261120_help_qa.sql after a backup.');
    }
    $answer = trim((string)($_POST['answer'] ?? ''));
    if ($answer === '') {
        http_response_code(400);
        exit('Type an answer before saving.');
    }
    $uqPage = (string)$asked['page_key'];
    if (!help_qa_valid_page_key($uqPage)) {
        http_response_code(400);
        exit('That question is not attached to a page.');
    }
    $org = $asked['org_id'];
    $org = ($org === null || $org === '' || (int)$org <= 0) ? null : (int)$org;
    $max = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM help_qa WHERE app = ? AND page_key = ?');
    $max->execute(['payroll', $uqPage]);
    $sort = (int)$max->fetchColumn() + 10;
    $roles = json_encode(['superadmin'], JSON_UNESCAPED_UNICODE);
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
             VALUES (\'payroll\', ?, ?, ?, ?, \'hidden\', NULL, ?, NULL, ?, ?, 1)'
        )->execute([$uqPage, (string)$asked['question'], $answer, $sort, $roles, $org, $email]);
        $qaId = (int)$pdo->lastInsertId();
        $pdo->prepare(
            "UPDATE help_user_question SET status = 'answered', help_qa_id = ?, handled_by = ? WHERE id = ? AND app = 'payroll' AND status = 'new'"
        )->execute([$qaId, $email, $uqId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    header('Location: ' . $back);
    exit;
}

if (!help_qa_table_ready($pdo)) {
    http_response_code(503);
    exit('help_qa is not set up. Run migrations/20261120_help_qa.sql after a backup.');
}
if (!help_qa_valid_page_key($pageKey)) {
    http_response_code(400);
    exit('That page key is not valid.');
}
$id = (int)($_POST['id'] ?? 0);

function help_qa_action_back(): string
{
    $back = 'help_qa.php';
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($ref !== '' && $host !== '') {
        $parts = parse_url($ref);
        if (is_array($parts) && isset($parts['host']) && strcasecmp((string)$parts['host'], $host) === 0) {
            $path = (string)($parts['path'] ?? '');
            $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
            if ($path !== '' && strpos($path, '//') === false) {
                $back = $path . $query . '#helpQaPanel';
            }
        }
    }
    return $back;
}

$owned = null;
if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM help_qa WHERE id = ? AND app = ? AND page_key = ?');
    $st->execute([$id, 'payroll', $pageKey]);
    $owned = $st->fetch(PDO::FETCH_ASSOC);
    if (!$owned) {
        http_response_code(404);
        exit('That question is not on this page.');
    }
}

if ($action === 'delete' && $owned) {
    $pdo->prepare('DELETE FROM help_qa WHERE id = ? AND app = ?')->execute([$id, 'payroll']);
    header('Location: ' . $back);
    exit;
}

if ($action === 'up' || $action === 'down') {
    if (!$owned) {
        http_response_code(400);
        exit('Choose a question to move.');
    }
    $cmp = $action === 'up' ? '<' : '>';
    $dir = $action === 'up' ? 'DESC' : 'ASC';
    $st = $pdo->prepare(
        'SELECT id, sort_order FROM help_qa WHERE app = ? AND page_key = ? AND (sort_order ' . $cmp . ' ? OR (sort_order = ? AND id ' . $cmp . ' ?)) ORDER BY sort_order ' . $dir . ', id ' . $dir . ' LIMIT 1'
    );
    $st->execute(['payroll', $pageKey, (int)$owned['sort_order'], (int)$owned['sort_order'], $id]);
    $neighbor = $st->fetch(PDO::FETCH_ASSOC);
    if ($neighbor) {
        $pdo->prepare('UPDATE help_qa SET sort_order = ?, edited_in_app = 1, updated_by = ? WHERE id = ?')
            ->execute([(int)$neighbor['sort_order'], $email, $id]);
        $pdo->prepare('UPDATE help_qa SET sort_order = ?, edited_in_app = 1, updated_by = ? WHERE id = ?')
            ->execute([(int)$owned['sort_order'], $email, (int)$neighbor['id']]);
    }
    header('Location: ' . $back);
    exit;
}

if ($action === 'reorder') {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['order'] ?? ''))), static fn($n) => $n > 0));
    $place = 10;
    $upd = $pdo->prepare('UPDATE help_qa SET sort_order = ?, edited_in_app = 1, updated_by = ? WHERE id = ? AND app = ? AND page_key = ?');
    foreach ($ids as $rowId) {
        $upd->execute([$place, $email, $rowId, 'payroll', $pageKey]);
        $place += 10;
    }
    header('Location: ' . $back);
    exit;
}

if ($action === 'add' || $action === 'save') {
    $question = trim((string)($_POST['question'] ?? ''));
    $answer = trim((string)($_POST['answer'] ?? ''));
    if ($question === '' || $answer === '' || strlen($question) > 500) {
        http_response_code(400);
        exit('A question (500 characters or fewer) and an answer are required.');
    }
    $status = (string)($_POST['status'] ?? '') === 'hidden' ? 'hidden' : 'live';
    $roles = json_encode(help_qa_normalize_roles($_POST['visible_roles'] ?? ''), JSON_UNESCAPED_UNICODE);
    $keywords = help_qa_normalize_keywords($_POST['keywords'] ?? '');
    $keywordsJson = $keywords ? json_encode($keywords, JSON_UNESCAPED_UNICODE) : null;
    $feature = trim((string)($_POST['requires_feature'] ?? ''));
    $feature = $feature === '' ? null : substr($feature, 0, 64);
    $orgRaw = trim((string)($_POST['org_id'] ?? ''));
    $org = ($orgRaw === '' || (int)$orgRaw <= 0) ? null : (int)$orgRaw;
    if ($action === 'add') {
        $max = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM help_qa WHERE app = ? AND page_key = ?');
        $max->execute(['payroll', $pageKey]);
        $sort = (int)$max->fetchColumn() + 10;
        $pdo->prepare(
            'INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
             VALUES (\'payroll\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([$pageKey, $question, $answer, $sort, $status, $feature, $roles, $keywordsJson, $org, $email !== '' ? $email : null]);
    } else {
        if (!$owned) {
            http_response_code(400);
            exit('Choose a question to save.');
        }
        $pdo->prepare(
            'UPDATE help_qa SET question = ?, answer = ?, status = ?, requires_feature = ?, visible_roles = ?, keywords = ?, org_id = ?, updated_by = ?, edited_in_app = 1 WHERE id = ? AND app = ?'
        )->execute([$question, $answer, $status, $feature, $roles, $keywordsJson, $org, $email !== '' ? $email : null, $id, 'payroll']);
    }
    header('Location: ' . $back);
    exit;
}

http_response_code(400);
exit('Unknown action.');
