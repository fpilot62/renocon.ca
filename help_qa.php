<?php
/**
 * Path: /help_qa.php
 * All payroll questions, for superadmins. Search uses the question, the
 * answer, and the keywords JSON list. Grouped by page.
 * Export and import use the help_qa column names. Import upserts on
 * (app, page_key, question) and does not skip edited rows: an import is
 * an explicit replace. The SQL seed is the path that never overwrites.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll_scope.php';
require_once __DIR__ . '/engine/menu_generator.php';
require_once __DIR__ . '/engine/help_qa_lib.php';
require_once __DIR__ . '/engine/help_qa_panel.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login_kiosk.php?err=session_expired');
    exit;
}
if (!help_qa_is_superadmin()) {
    http_response_code(403);
    exit('Superadmin access required.');
}
try {
    help_qa_ensure_link($pdo, 'Payroll questions', 'help_qa.php', 10);
} catch (Throwable $e) {
}

$notice = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_POST['action'] ?? '') === 'import') {
    ps_require_csrf();
    if (!help_qa_table_ready($pdo)) {
        $notice = 'help_qa is not set up. Run migrations/20261120_help_qa.sql after a backup.';
    } else {
        $raw = '';
        if (!empty($_FILES['qa_json']['tmp_name']) && is_uploaded_file($_FILES['qa_json']['tmp_name'])) {
            $raw = (string)file_get_contents($_FILES['qa_json']['tmp_name']);
        } else {
            $raw = (string)($_POST['qa_json_text'] ?? '');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $notice = 'The file is not a JSON list.';
        } else {
            $added = 0;
            $updated = 0;
            $skipped = 0;
            $email = help_qa_editor_email($pdo);
            foreach ($data as $item) {
                if (!is_array($item)) {
                    $skipped++;
                    continue;
                }
                $app = trim((string)($item['app'] ?? ''));
                $page = trim((string)($item['page_key'] ?? ''));
                $question = trim((string)($item['question'] ?? ''));
                $answer = trim((string)($item['answer'] ?? ''));
                if ($app !== 'payroll' || !help_qa_valid_page_key($page) || $question === '' || $answer === '' || strlen($question) > 500) {
                    $skipped++;
                    continue;
                }
                $status = (string)($item['status'] ?? '') === 'hidden' ? 'hidden' : 'live';
                $roles = json_encode(help_qa_normalize_roles($item['visible_roles'] ?? ['superadmin']), JSON_UNESCAPED_UNICODE);
                $words = help_qa_normalize_keywords($item['keywords'] ?? []);
                $wordsJson = $words ? json_encode($words, JSON_UNESCAPED_UNICODE) : null;
                $feature = trim((string)($item['requires_feature'] ?? ''));
                $feature = $feature === '' ? null : substr($feature, 0, 64);
                $org = $item['org_id'] ?? null;
                $org = ($org === null || $org === '' || (int)$org <= 0) ? null : (int)$org;
                $sort = (int)($item['sort_order'] ?? 0);
                $edited = (int)($item['edited_in_app'] ?? 0) === 1 ? 1 : 0;
                $by = trim((string)($item['updated_by'] ?? ''));
                if ($by === '') {
                    $by = $email;
                }
                $by = $by === '' ? null : substr($by, 0, 191);
                $created = help_qa_datetime_or_null($item['created_at'] ?? '');
                $updatedAt = help_qa_datetime_or_null($item['updated_at'] ?? '');
                $find = $pdo->prepare('SELECT id FROM help_qa WHERE app = ? AND page_key = ? AND question = ? LIMIT 1');
                $find->execute([$app, $page, $question]);
                $existing = (int)$find->fetchColumn();
                if ($existing > 0) {
                    $pdo->prepare(
                        'UPDATE help_qa SET answer = ?, sort_order = ?, status = ?, requires_feature = ?, visible_roles = ?, keywords = ?, org_id = ?, updated_by = ?, edited_in_app = ? WHERE id = ?'
                    )->execute([$answer, $sort, $status, $feature, $roles, $wordsJson, $org, $by, $edited, $existing]);
                    if ($updatedAt !== null) {
                        $pdo->prepare('UPDATE help_qa SET updated_at = ? WHERE id = ?')->execute([$updatedAt, $existing]);
                    }
                    $updated++;
                } else {
                    $pdo->prepare(
                        'INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP), COALESCE(?, CURRENT_TIMESTAMP))'
                    )->execute([$app, $page, $question, $answer, $sort, $status, $feature, $roles, $wordsJson, $org, $by, $edited, $created, $updatedAt]);
                    $added++;
                }
            }
            $notice = 'Import finished. Added ' . $added . ', updated ' . $updated . ', skipped ' . $skipped . '.';
        }
    }
}

if ((string)($_GET['export'] ?? '') === 'user_questions' && help_user_question_table_ready($pdo)) {
    $st = $pdo->query("SELECT * FROM help_user_question WHERE app = 'payroll' ORDER BY id ASC");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[] = help_user_question_export_row($row);
    }
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="help_user_question_payroll.json"');
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['export']) && help_qa_table_ready($pdo)) {
    $st = $pdo->query("SELECT * FROM help_qa WHERE app = 'payroll' ORDER BY page_key ASC, sort_order ASC, id ASC");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[] = help_qa_export_row($row);
    }
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="help_qa_payroll.json"');
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$rows = [];
if (help_qa_table_ready($pdo)) {
    if ($q === '') {
        $st = $pdo->query("SELECT * FROM help_qa WHERE app = 'payroll' ORDER BY page_key ASC, sort_order ASC, id ASC");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $like = '%' . $q . '%';
        $st = $pdo->prepare(
            'SELECT * FROM help_qa WHERE app = ? AND (question LIKE ? OR answer LIKE ? OR keywords LIKE ? OR page_key LIKE ?)
             ORDER BY page_key ASC, sort_order ASC, id ASC'
        );
        $st->execute(['payroll', $like, $like, $like, $like]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
$groups = [];
foreach ($rows as $row) {
    $groups[(string)$row['page_key']][] = $row;
}
$csrf = ps_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payroll questions</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<style>
body{background:#f1f5f9;padding:90px 16px 40px;font-size:14px;color:#1e293b}
.sheet{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:14px}
.flag{display:inline-block;background:#fef3c7;color:#92400e;border-radius:99px;padding:1px 8px;font-size:11px;font-weight:800;margin-left:6px}
.answer{white-space:pre-wrap}
</style>
</head>
<body>
<?= function_exists('generateMenu') ? generateMenu($pdo) : '' ?>
<div class="container-fluid">
    <h4 class="font-weight-bold">Payroll questions</h4>
    <p class="text-muted">Every page has a Questions &amp; answers panel for superadmins. This page lists them all. Search looks at the question, the answer, and keywords.</p>
    <?php if ($notice !== ''): ?><div class="alert alert-info"><?= help_qa_h($notice) ?></div><?php endif; ?>
    <?php if (!help_qa_table_ready($pdo)): ?>
        <div class="alert alert-warning">The help_qa table is not in this database yet. After a backup, run migrations/20261120_help_qa.sql. It only inserts a question when that question is not already stored.</div>
    <?php endif; ?>
    <form method="get" class="form-inline mb-3">
        <input class="form-control mr-2" name="q" value="<?= help_qa_h($q) ?>" placeholder="Search questions, answers, keywords, pages" style="min-width:320px">
        <button class="btn btn-dark">Search</button>
        <?php if ($q !== ''): ?><a class="btn btn-link" href="help_qa.php">Clear</a><?php endif; ?>
        <a class="btn btn-outline-primary ml-2" href="help_qa.php?export=1">Export JSON</a>
        <a class="btn btn-outline-primary ml-2" href="help_qa.php?export=user_questions">Export user questions</a>
    </form>
    <div class="sheet">
        <h6 class="font-weight-bold">Import JSON</h6>
        <p class="text-muted mb-2">Keys are the help_qa columns: app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app, created_at, updated_at. visible_roles and keywords are JSON lists. A row is updated when app, page_key, and question already match.</p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= help_qa_h($csrf) ?>">
            <input type="hidden" name="action" value="import">
            <input type="file" name="qa_json" accept="application/json,.json">
            <button class="btn btn-sm btn-primary ml-2">Import</button>
        </form>
    </div>
    <div class="sheet">
        <h6 class="font-weight-bold">User questions</h6>
        <p class="text-muted">Questions people sent from a page. Saving an answer creates a hidden question on that page. You can make it live from the list below.</p>
        <?php
        $uqStatus = help_user_question_status_filter((string)($_GET['uq_status'] ?? 'new'));
        $uqRows = help_user_question_list($pdo, $uqStatus, '');
        echo help_qa_user_queue($uqRows, $uqStatus, 'help_qa_action.php', $csrf, 'help_qa.php', false, help_user_question_table_ready($pdo));
        ?>
    </div>
    <?php if (!$groups): ?><p class="text-muted">No questions<?= $q !== '' ? ' match that search' : '' ?>.</p><?php endif; ?>
    <?php foreach ($groups as $page => $items): ?>
        <div class="sheet">
            <h6 class="font-weight-bold mb-3"><a href="<?= help_qa_h($page) ?>"><?= help_qa_h($page) ?></a> <span class="text-muted"><?= count($items) ?></span></h6>
            <?php foreach ($items as $row): ?>
                <div class="mb-3">
                    <div class="font-weight-bold"><?= help_qa_h($row['question']) ?>
                        <?php if ((string)$row['status'] !== 'live'): ?><span class="flag">hidden</span><?php endif; ?>
                        <?php if (trim((string)($row['requires_feature'] ?? '')) !== ''): ?><span class="flag"><?= help_qa_h($row['requires_feature']) ?></span><?php endif; ?>
                    </div>
                    <div class="answer"><?= help_qa_h($row['answer']) ?></div>
                    <?php $words = help_qa_json_list($row['keywords'] ?? '[]'); if ($words): ?>
                        <div class="text-muted small mt-1">Keywords: <?= help_qa_h(implode(', ', $words)) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>
