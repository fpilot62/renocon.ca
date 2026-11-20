<?php
/**
 * Serve one clock-in/out selfie as a real image request (not a base64 blob in HTML).
 * Usage: audit_selfie.php?id=123&k=in&t=1
 *   k = in|out
 *   t = 1 for a small thumbnail
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id']) || (int)($_SESSION['user_level'] ?? 99) >= 30) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Access Denied.';
    exit;
}

$aid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$kind = strtolower(trim((string)($_GET['k'] ?? 'in')));
$thumb = isset($_GET['t']) && (string)$_GET['t'] === '1';
if ($aid < 1 || !in_array($kind, ['in', 'out'], true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Bad request.';
    exit;
}

$col = ($kind === 'out') ? 'selfie_out' : 'selfie_in';
$sess_user_level = isset($_SESSION['user_level']) ? (int)$_SESSION['user_level'] : 99;
$org_id = (int)($_SESSION['org_id'] ?? 0);

try {
    $sql = "SELECT `$col` AS blob_val, org_id FROM attendance WHERE id = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$aid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Lookup failed.';
    exit;
}

if (!$row) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not found.';
    exit;
}
if ($sess_user_level > 10 && $org_id > 0 && (int)($row['org_id'] ?? 0) !== $org_id) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Access Denied.';
    exit;
}

$raw = (string)($row['blob_val'] ?? '');
$empty = ($raw === '' || strcasecmp($raw, 'nophoto') === 0 || strcasecmp($raw, 'nophoto.jpg') === 0);

$gif1 = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

function sa_selfie_send(string $bytes, string $mime, bool $thumb, int $maxPx = 72): void
{
    if ($thumb && function_exists('imagecreatefromstring') && $bytes !== '') {
        $src = @imagecreatefromstring($bytes);
        if ($src !== false) {
            $w = imagesx($src);
            $h = imagesy($src);
            if ($w > 0 && $h > 0) {
                $scale = min(1.0, $maxPx / max($w, $h));
                $nw = max(1, (int)round($w * $scale));
                $nh = max(1, (int)round($h * $scale));
                $dst = imagecreatetruecolor($nw, $nh);
                if ($dst !== false) {
                    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                    imagedestroy($src);
                    header('Content-Type: image/jpeg');
                    header('Cache-Control: private, max-age=3600');
                    header('X-Content-Type-Options: nosniff');
                    imagejpeg($dst, null, 72);
                    imagedestroy($dst);
                    return;
                }
            }
            imagedestroy($src);
        }
    }
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (string)strlen($bytes));
    echo $bytes;
}

if ($empty) {
    header('Content-Type: image/gif');
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . (string)strlen($gif1));
    echo $gif1;
    exit;
}

$bytes = '';
$mime = 'image/jpeg';

if (stripos($raw, 'data:image/') === 0) {
    if (preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,#', $raw, $m)) {
        $mime = strtolower($m[1]);
        $b64 = substr($raw, strlen($m[0]));
        $bytes = base64_decode($b64, true);
        if ($bytes === false) {
            $bytes = '';
        }
    }
} elseif (strlen($raw) < 260 && strpos($raw, "\0") === false && !preg_match('/[\r\n]/', $raw)) {
    $rel = ltrim(str_replace('\\', '/', $raw), '/');
    if (strpos($rel, '..') === false) {
        $candidates = [
            __DIR__ . '/' . $rel,
            __DIR__ . '/selfies/' . basename($rel),
        ];
        foreach ($candidates as $path) {
            $real = realpath($path);
            $root = realpath(__DIR__);
            if ($real && $root && strncmp($real, $root, strlen($root)) === 0 && is_file($real) && filesize($real) > 0) {
                $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
                $mime = ($ext === 'png') ? 'image/png' : (($ext === 'gif') ? 'image/gif' : (($ext === 'webp') ? 'image/webp' : 'image/jpeg'));
                $bytes = (string)file_get_contents($real);
                break;
            }
        }
    }
} elseif (strncmp($raw, '/9j/', 4) === 0 || preg_match('#^[A-Za-z0-9+/=\s]{32,}$#', substr($raw, 0, 80))) {
    $decoded = base64_decode($raw, true);
    if ($decoded !== false && $decoded !== '') {
        $bytes = $decoded;
    }
} elseif (strlen($raw) >= 3 && $raw[0] === "\xFF" && $raw[1] === "\xD8") {
    $bytes = $raw;
}

if ($bytes === '') {
    header('Content-Type: image/gif');
    header('Cache-Control: private, max-age=300');
    header('Content-Length: ' . (string)strlen($gif1));
    echo $gif1;
    exit;
}

sa_selfie_send($bytes, $mime, $thumb);
