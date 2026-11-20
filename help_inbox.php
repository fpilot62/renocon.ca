<?php
/**
 * Path: /help_inbox.php
 * Super Admin inbox for Ask AI questions that the page guide could not answer.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';
require_once 'engine/menu_generator.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$level = (int)($_SESSION['user_level'] ?? 99);
if ($level > 10) {
    http_response_code(403);
    echo 'Super Admin only.';
    exit;
}

function sip_help_inbox_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS sip_help_escalations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME NOT NULL,
            user_id INT NOT NULL DEFAULT 0,
            org_id INT NOT NULL DEFAULT 0,
            page VARCHAR(255) NOT NULL DEFAULT '',
            page_url TEXT,
            question TEXT NOT NULL,
            ai_note TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'open'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

sip_help_inbox_table($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_id'])) {
    $id = (int)$_POST['close_id'];
    if ($id > 0) {
        $st = $pdo->prepare("UPDATE sip_help_escalations SET status = 'closed' WHERE id = ?");
        $st->execute([$id]);
    }
    header('Location: help_inbox.php');
    exit;
}

$rows = $pdo->query(
    "SELECT * FROM sip_help_escalations ORDER BY (status = 'open') DESC, id DESC LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Help inbox | SIP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: Inter, system-ui, sans-serif; background: #f1f5f9; color: #0f172a; }
        .wrap { max-width: 1100px; margin: 24px auto; padding: 0 16px 40px; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #e2e8f0; text-align: left; font-size: 13px; vertical-align: top; }
        th { background: #0f172a; color: #fff; font-size: 11px; text-transform: uppercase; }
        .open { color: #c2410c; font-weight: 800; }
        .closed { color: #64748b; }
        a { color: #1d4ed8; }
        button { background: #0f172a; color: #fff; border: 0; border-radius: 6px; padding: 6px 10px; cursor: pointer; font-weight: 700; }
    </style>
</head>
<body>
<?= generateMenu($pdo); ?>
<div class="wrap">
    <h2>Ask AI → Super Admin</h2>
    <p>Questions the page guide could not answer. Each row has the user’s question and the page they were on.</p>
    <table>
        <thead>
            <tr>
                <th>When</th>
                <th>Status</th>
                <th>Page</th>
                <th>Question</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="5">No questions yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['created_at']) ?><br>user <?= (int)$r['user_id'] ?></td>
                <td class="<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></td>
                <td>
                    <?= htmlspecialchars($r['page']) ?><br>
                    <?php if (!empty($r['page_url'])): ?>
                    <a href="<?= htmlspecialchars($r['page_url']) ?>" target="_blank" rel="noopener">Open page</a>
                    <?php endif; ?>
                </td>
                <td><?= nl2br(htmlspecialchars($r['question'])) ?></td>
                <td>
                    <?php if ($r['status'] === 'open'): ?>
                    <form method="post">
                        <input type="hidden" name="close_id" value="<?= (int)$r['id'] ?>">
                        <button type="submit">Done</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
