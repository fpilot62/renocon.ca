<?php
/**
 * Path: /payroll_ask_ai.php
 * Ask AI for payroll pages. Answers from payroll_help.php (written guides).
 * If the question is beyond that map, store it for Super Admin with page URL.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Please sign in to ask.']);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll_help.php';

$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true);
if (!is_array($body)) {
    $body = $_POST;
}
$question = trim((string)($body['question'] ?? ''));
$page = trim((string)($body['page'] ?? ''));
$pageUrl = trim((string)($body['page_url'] ?? ''));
if ($pageUrl === '') {
    $pageUrl = (string)($_SERVER['HTTP_REFERER'] ?? '');
}
if ($question === '' || strlen($question) > 800) {
    echo json_encode(['ok' => false, 'error' => 'Ask a short question about this page.']);
    exit;
}

function sip_ask_words(string $text): array {
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/i', ' ', $text);
    $parts = preg_split('/\s+/', trim((string)$text)) ?: [];
    $stop = ['the','and','for','this','that','with','from','what','how','why','can','you','are','is','a','an','to','of','in','on','it','my','we'];
    $out = [];
    foreach ($parts as $w) {
        if (strlen($w) < 3 || in_array($w, $stop, true)) {
            continue;
        }
        $out[] = $w;
    }
    return array_values(array_unique($out));
}

function sip_ask_blob(array $entry): string {
    $bits = [
        (string)($entry['title'] ?? ''),
        (string)($entry['purpose'] ?? ''),
        implode(' ', $entry['how'] ?? []),
        implode(' ', $entry['calc'] ?? []),
        implode(' ', $entry['notes'] ?? []),
    ];
    foreach ($entry['tour'] ?? [] as $step) {
        $bits[] = (string)($step['title'] ?? '');
        $bits[] = (string)($step['body'] ?? '');
    }
    return implode(' ', $bits);
}

function sip_ask_score(array $words, string $blob): int {
    $hits = 0;
    $low = strtolower($blob);
    foreach ($words as $w) {
        if (strpos($low, $w) !== false) {
            $hits++;
        }
    }
    return $hits;
}

function sip_ask_answer(array $entry): string {
    $lines = [];
    if (!empty($entry['title'])) {
        $lines[] = '**' . $entry['title'] . '**';
    }
    if (!empty($entry['purpose'])) {
        $lines[] = $entry['purpose'];
    }
    if (!empty($entry['how'])) {
        $lines[] = 'How to use:';
        foreach (array_slice($entry['how'], 0, 6) as $i => $step) {
            $lines[] = ($i + 1) . '. ' . $step;
        }
    }
    if (!empty($entry['calc'])) {
        $lines[] = implode(' ', array_slice($entry['calc'], 0, 2));
    }
    return trim(implode("\n", $lines));
}

function sip_ask_ensure_table(PDO $pdo): void {
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

$all = payroll_help_content();
$words = sip_ask_words($question);
$bestKey = $page;
$bestScore = -1;
$current = $all[$page] ?? payroll_help_fallback_entry($page);
$currentScore = sip_ask_score($words, sip_ask_blob($current));
$bestKey = $page;
$bestScore = $currentScore;
foreach ($all as $key => $entry) {
    $score = sip_ask_score($words, sip_ask_blob($entry));
    if ($page !== '' && $key === $page) {
        $score += 1;
    }
    if ($score > $bestScore) {
        $bestScore = $score;
        $bestKey = $key;
    }
}
$entry = $all[$bestKey] ?? $current;
$answer = sip_ask_answer($entry);
$wantHuman = (bool)preg_match('/\b(human|agent|super\s*admin|someone|person|call me|talk to)\b/i', $question);
$beyond = $wantHuman || $bestScore < 2 || $answer === '';

$escalated = false;
if ($beyond) {
    try {
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            sip_ask_ensure_table($pdo);
            $ins = $pdo->prepare(
                'INSERT INTO sip_help_escalations (created_at, user_id, org_id, page, page_url, question, ai_note, status)
                 VALUES (NOW(), ?, ?, ?, ?, ?, ?, \'open\')'
            );
            $ins->execute([
                (int)$_SESSION['user_id'],
                (int)($_SESSION['org_id'] ?? 0),
                $page !== '' ? $page : $bestKey,
                $pageUrl,
                $question,
                $answer !== '' ? ('Best match: ' . ($entry['title'] ?? $bestKey) . ' (score ' . $bestScore . ')') : 'No matching guide',
            ]);
            $escalated = true;
        }
    } catch (Throwable $e) {
        $escalated = false;
    }
}

if ($beyond) {
    $answer = $escalated
        ? "I don't have a complete answer in the page guide, so I sent this to Super Admin with a link to this page and your question.\n\nThey will follow up. You can also try the ⓘ help icon or ► audio visual tour on this screen."
        : "I don't have that in the written guide. Use the ⓘ help icon or ► audio visual tour on this page, or ask Super Admin.";
}

echo json_encode([
    'ok' => true,
    'answer' => $answer,
    'mode' => $beyond ? 'escalated' : 'help-map',
    'llm_connected' => false,
    'escalated' => $escalated,
    'sources' => [
        [
            'page' => $bestKey,
            'title' => $entry['title'] ?? $bestKey,
            'hint' => $beyond ? 'Sent to Super Admin' : 'From this page guide',
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
