<?php
/**
 * Shared helpers for employee self-serve (login / setup / my_profile).
 * Path: /employee_selfserve_lib.php
 */

if (!function_exists('ess_phone_digits')) {

function ess_phone_digits($value): string {
    return preg_replace('/\D+/', '', (string)$value) ?? '';
}

function ess_phones_match($stored, $input_digits): bool {
    $a = ess_phone_digits($stored);
    $b = ess_phone_digits($input_digits);
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    if (strlen($a) >= 10 && strlen($b) >= 10) {
        return substr($a, -10) === substr($b, -10);
    }
    return false;
}

/** Empty or synthetic @*.import.invalid emails require setup. */
function ess_email_needs_setup($email): bool {
    $email = trim((string)$email);
    if ($email === '') {
        return true;
    }
    return (bool)preg_match('/@[^@\s]+\.import\.invalid$/i', $email);
}

function ess_mask_sin($sin): string {
    $d = ess_phone_digits($sin);
    if ($d === '') {
        return 'Not on file';
    }
    if (strlen($d) < 3) {
        return '***-***-***';
    }
    return '***-***-' . substr($d, -3);
}

function ess_mask_bank_account($account): string {
    $d = ess_phone_digits($account);
    if ($d === '') {
        return 'Not on file';
    }
    if (strlen($d) <= 4) {
        return str_repeat('*', strlen($d));
    }
    return str_repeat('*', max(0, strlen($d) - 4)) . substr($d, -4);
}

function ess_verify_secret(array $user, string $secret): bool {
    if ($secret === '') {
        return false;
    }
    if (isset($user['pin']) && (string)$user['pin'] === $secret) {
        return true;
    }
    $hash = (string)($user['password'] ?? '');
    if ($hash !== '' && strpos($hash, '$2y$') === 0) {
        return password_verify($secret, $hash);
    }
    return $hash !== '' && $hash === $secret;
}

/**
 * Establish the same session keys as login_kiosk.php.
 */
function ess_establish_session(PDO $pdo, array $user): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['user_id']           = (int)$user['id'];
    $_SESSION['user_full_name']    = $user['Name'] ?? '';
    $_SESSION['org_id']            = (int)$user['org_id'];
    $_SESSION['user_level']        = (int)$user['user_role'];
    $_SESSION['user_role']         = (int)$user['user_role'];
    $_SESSION['assigned_site_ids'] = $user['assigned_site_ids'] ?? '';

    $user_lvl = (int)$user['user_role'];
    $org_id   = (int)$user['org_id'];
    $assigned = $user['assigned_site_ids'] ?? '';

    if (!function_exists('sip_sites_active_sql')) {
        require_once __DIR__ . '/engine/site_status_lib.php';
    }
    $active_sql = sip_sites_active_sql('');
    if ($user_lvl <= 10) {
        $_SESSION['access_scope'] = 'all';
        $site_stmt = $pdo->query("SELECT SId as id, nick_name FROM sites WHERE $active_sql ORDER BY nick_name ASC");
    } else {
        $_SESSION['access_scope'] = 'restricted';
        $clean_ids = preg_replace('/[^0-9,]/', '', $assigned);
        if (!empty($clean_ids)) {
            $sql = "SELECT SId as id, nick_name FROM sites WHERE org_id = ? AND SId IN ($clean_ids) AND $active_sql ORDER BY nick_name ASC";
        } else {
            $sql = "SELECT SId as id, nick_name FROM sites WHERE org_id = ? AND $active_sql ORDER BY nick_name ASC";
        }
        $site_stmt = $pdo->prepare($sql);
        $site_stmt->execute([$org_id]);
    }

    $allowed_sites = $site_stmt->fetchAll(PDO::FETCH_ASSOC);
    $_SESSION['inventory_sites'] = $allowed_sites;
    $_SESSION['active_rid'] = !empty($allowed_sites) ? $allowed_sites[0]['id'] : 0;
}

function ess_require_login(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['user_id'])) {
        $org = (int)($_GET['org'] ?? $_SESSION['org_id'] ?? 0);
        $q = $org > 0 ? ('?org=' . $org) : '';
        header('Location: login_employee.php' . $q);
        exit;
    }
}

/** Ensure direct-deposit upload columns exist (safe to call repeatedly). */
function ess_ensure_dd_columns(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $cols = [
        'direct_deposit_file' => "VARCHAR(255) NULL",
        'direct_deposit_orig_name' => "VARCHAR(255) NULL",
        'direct_deposit_uploaded_at' => "DATETIME NULL",
        'direct_deposit_upload_pending' => "TINYINT(1) NOT NULL DEFAULT 0",
        // Pending extract — never written to bank_* until manager Approve
        'dd_pending_transit' => "VARCHAR(20) NULL",
        'dd_pending_institution' => "VARCHAR(10) NULL",
        'dd_pending_account' => "VARCHAR(40) NULL",
        'dd_pending_name' => "VARCHAR(120) NULL",
        'dd_verify_status' => "VARCHAR(40) NULL",
        'dd_extract_method' => "VARCHAR(40) NULL",
        'dd_extract_notes' => "VARCHAR(255) NULL",
        'dd_extract_json' => "MEDIUMTEXT NULL",
        'dd_reviewed_at' => "DATETIME NULL",
        'dd_reviewed_by' => "INT NULL",
    ];
    foreach ($cols as $name => $def) {
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM users LIKE " . $pdo->quote($name))->fetch();
            if (!$chk) {
                $pdo->exec("ALTER TABLE users ADD COLUMN `$name` $def");
            }
        } catch (Throwable $e) {
            // ignore — page still works without DD until DBA adds columns
        }
    }
}

/** Human-readable verification status for UI. */
function ess_dd_status_label(?string $status, $pending_flag = 0): string {
    $status = trim((string)$status);
    if ($status === 'pending_manager_verification' || (!empty($pending_flag) && $status === '')) {
        return 'Pending manager verification';
    }
    if ($status === 'approved') {
        return 'Approved';
    }
    if ($status === 'rejected') {
        return 'Rejected';
    }
    if (!empty($pending_flag)) {
        return 'Pending manager verification';
    }
    return $status !== '' ? $status : '—';
}

/** Resolve CLI binary with absolute paths (PHP/Apache often has empty PATH). */
function ess_dd_bin(string $name): string {
    $candidates = [
        '/usr/bin/' . $name,
        '/usr/local/bin/' . $name,
        $name,
    ];
    foreach ($candidates as $c) {
        if ($c === $name) {
            $found = trim((string)@shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
            if ($found !== '' && is_executable($found)) {
                return $found;
            }
            continue;
        }
        if (is_executable($c)) {
            return $c;
        }
    }
    return '';
}

/**
 * Best-effort text extraction from PDF / image.
 * Uses pdftotext, then OCR (tesseract) for images or image-only PDFs.
 * @return array{text:string,method:string,notes:string}
 */
function ess_dd_extract_text(string $abs_path): array {
    $ext = strtolower(pathinfo($abs_path, PATHINFO_EXTENSION));
    $text = '';
    $method = 'none';
    $notes = [];

    $pdftotext = ess_dd_bin('pdftotext');
    $tesseract = ess_dd_bin('tesseract');
    $pdftoppm = ess_dd_bin('pdftoppm');

    if ($ext === 'pdf' && $pdftotext !== '') {
        $cmd = escapeshellarg($pdftotext) . ' -layout -enc UTF-8 '
            . escapeshellarg($abs_path) . ' - 2>/dev/null';
        $out = (string)@shell_exec($cmd);
        $out = trim($out);
        if (strlen(preg_replace('/\s+/', '', $out)) >= 20) {
            $text = $out;
            $method = 'pdftotext';
            $notes[] = 'PDF text layer';
        } else {
            $notes[] = 'PDF text layer empty/thin';
        }
    }

    // OCR path for images, or scanned PDFs with little text
    $need_ocr = ($text === '' && in_array($ext, ['jpg', 'jpeg', 'png'], true))
        || ($ext === 'pdf' && $text === '');

    if ($need_ocr && $tesseract !== '') {
        $ocr_targets = [];
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $ocr_targets[] = $abs_path;
        } elseif ($ext === 'pdf' && $pdftoppm !== '') {
            $tmp = sys_get_temp_dir() . '/dd_ocr_' . bin2hex(random_bytes(4));
            @mkdir($tmp, 0700, true);
            $prefix = $tmp . '/page';
            $cmd = escapeshellarg($pdftoppm) . ' -png -r 200 -f 1 -l 2 '
                . escapeshellarg($abs_path) . ' ' . escapeshellarg($prefix) . ' 2>/dev/null';
            @shell_exec($cmd);
            $pages = glob($prefix . '-*.png') ?: [];
            sort($pages);
            $ocr_targets = array_slice($pages, 0, 2);
            $notes[] = 'Rendered PDF pages for OCR';
        }

        $chunks = [];
        foreach ($ocr_targets as $img) {
            $cmd = escapeshellarg($tesseract) . ' ' . escapeshellarg($img)
                . ' stdout -l eng --psm 6 2>/dev/null';
            $chunk = trim((string)@shell_exec($cmd));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
        }
        if (!empty($ocr_targets) && isset($tmp) && is_dir($tmp)) {
            foreach (glob($tmp . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmp);
        }
        if (!empty($chunks)) {
            $text = implode("\n", $chunks);
            $method = ($method === 'pdftotext') ? 'pdftotext+tesseract' : 'tesseract';
            $notes[] = 'OCR applied';
        } else {
            $notes[] = 'OCR produced no text';
        }
    } elseif ($need_ocr && $tesseract === '') {
        $notes[] = 'OCR unavailable (tesseract not installed)';
    }

    if ($text === '' && $ext === 'pdf') {
        // Last resort: scrape printable strings from PDF bytes
        $raw = @file_get_contents($abs_path, false, null, 0, 2_000_000);
        if (is_string($raw) && $raw !== '') {
            if (preg_match_all('/\((?:\\\\.|[^\\\\)]){3,}\)/', $raw, $m)) {
                $parts = [];
                foreach ($m[0] as $tok) {
                    $s = substr($tok, 1, -1);
                    $s = stripcslashes($s);
                    if (preg_match('/[A-Za-z0-9]/', $s)) {
                        $parts[] = $s;
                    }
                }
                if (!empty($parts)) {
                    $text = implode(' ', $parts);
                    $method = 'pdf_strings';
                    $notes[] = 'Raw PDF string scrape';
                }
            }
        }
    }

    if ($text === '') {
        $notes[] = 'No text extracted — manager must enter values';
    }

    return [
        'text' => $text,
        'method' => $method,
        'notes' => implode('; ', $notes),
    ];
}

/**
 * Parse Canadian banking fields from free text (best-effort).
 * @return array{transit:?string,institution:?string,account:?string,name:?string,notes:string}
 */
function ess_dd_parse_banking_fields(string $text): array {
    $transit = null;
    $institution = null;
    $account = null;
    $name = null;
    $hints = [];

    $norm = preg_replace("/[ \t]+/", ' ', $text);
    $norm = str_replace(["\r\n", "\r"], "\n", (string)$norm);

    // Labeled transit (5 digits)
    if (preg_match('/\b(?:transit|branch\s*transit|branch)\s*(?:number|no\.?|#)?\s*[:\-]?\s*(\d{5})\b/i', $norm, $m)) {
        $transit = $m[1];
        $hints[] = 'transit labeled';
    }
    // Labeled institution / bank number (3 digits)
    if (preg_match('/\b(?:institution|bank\s*(?:number|no\.?|#)|financial\s*institution)\s*(?:number|no\.?|#)?\s*[:\-]?\s*(\d{3})\b/i', $norm, $m)) {
        $institution = $m[1];
        $hints[] = 'institution labeled';
    }
    // Labeled account
    if (preg_match('/\b(?:account|acct)\s*(?:number|no\.?|#)?\s*[:\-]?\s*([0-9][0-9\-\s]{4,22}[0-9])\b/i', $norm, $m)) {
        $account = preg_replace('/\D+/', '', $m[1]);
        $hints[] = 'account labeled';
    }
    // Account holder / name on form
    if (preg_match('/\b(?:account\s*holder(?:\'?s)?\s*name|name\s*of\s*(?:account\s*)?holder|payee\s*name|employee\s*name)\s*[:\-]?\s*([A-Za-z][A-Za-z .\'\-]{2,60})/i', $norm, $m)) {
        $name = trim(preg_replace('/\s+/', ' ', $m[1]));
        $name = preg_replace('/\s+(Transit|Institution|Account|Bank|SIN|Address).*$/i', '', $name);
        $hints[] = 'name labeled';
    }

    // MICR-style: ⑆transit⑆ institution  or  transit-institution
    if ($transit === null || $institution === null) {
        if (preg_match('/[⑆⦿]\s*(\d{5})\s*[⑆⦿]\s*(\d{3})\b/u', $norm, $m)
            || preg_match('/\b(\d{5})\s*[-–]\s*(\d{3})\b/', $norm, $m)
            || preg_match('/\b(\d{5})\s+(\d{3})\s+(\d{5,12})\b/', $norm, $m)
        ) {
            if ($transit === null) {
                $transit = $m[1];
                $hints[] = 'transit from MICR/pattern';
            }
            if ($institution === null) {
                $institution = $m[2];
                $hints[] = 'institution from MICR/pattern';
            }
            if ($account === null && isset($m[3])) {
                $account = $m[3];
                $hints[] = 'account from triple pattern';
            }
        }
    }

    // Fallback: known Canadian FI codes near a 5-digit transit
    if ($transit === null || $institution === null) {
        $known_fi = '001|002|003|004|006|010|016|030|039|177|219|240|260|270|290|294|301|303|309|310|320|338|340|509|540|568|614|621|623|809|815|819|828|839|865|879|889';
        if (preg_match('/\b(\d{5})\b[^0-9]{0,12}\b(' . $known_fi . ')\b/', $norm, $m)
            || preg_match('/\b(' . $known_fi . ')\b[^0-9]{0,12}\b(\d{5})\b/', $norm, $m2)
        ) {
            if (isset($m[1]) && strlen($m[1]) === 5) {
                if ($transit === null) {
                    $transit = $m[1];
                }
                if ($institution === null) {
                    $institution = $m[2];
                }
            } elseif (isset($m2[1])) {
                if ($institution === null) {
                    $institution = $m2[1];
                }
                if ($transit === null) {
                    $transit = $m2[2];
                }
            }
            $hints[] = 'transit/FI proximity';
        }
    }

    // Fallback account: longest digit run 7–12 that isn't transit/institution
    if ($account === null && preg_match_all('/\b(\d{7,12})\b/', $norm, $all)) {
        foreach ($all[1] as $cand) {
            if ($cand === $transit || $cand === $institution) {
                continue;
            }
            if (strlen($cand) === 5 || strlen($cand) === 3) {
                continue;
            }
            $account = $cand;
            $hints[] = 'account longest digit run';
            break;
        }
    }

    if ($transit !== null) {
        $transit = str_pad(preg_replace('/\D+/', '', $transit), 5, '0', STR_PAD_LEFT);
        if (strlen($transit) > 5) {
            $transit = substr($transit, -5);
        }
    }
    if ($institution !== null) {
        $institution = str_pad(preg_replace('/\D+/', '', $institution), 3, '0', STR_PAD_LEFT);
        if (strlen($institution) > 3) {
            $institution = substr($institution, -3);
        }
    }
    if ($account !== null) {
        $account = preg_replace('/\D+/', '', $account);
        if ($account === '') {
            $account = null;
        }
    }
    if ($name !== null) {
        $name = trim($name);
        if (strlen($name) < 2 || strlen($name) > 100) {
            $name = null;
        }
    }

    $found = array_filter([$transit, $institution, $account, $name]);
    if (empty($found)) {
        $hints[] = 'no fields auto-detected';
    }

    return [
        'transit' => $transit,
        'institution' => $institution,
        'account' => $account,
        'name' => $name,
        'notes' => implode('; ', $hints),
    ];
}

/**
 * Run extract+parse on a stored file. Does not touch live bank_* columns.
 * @return array{transit:?string,institution:?string,account:?string,name:?string,method:string,notes:string}
 */
function ess_dd_auto_extract(string $abs_path): array {
    $raw = ess_dd_extract_text($abs_path);
    $parsed = ess_dd_parse_banking_fields($raw['text']);
    $notes = trim($raw['notes'] . ($parsed['notes'] !== '' ? ' · ' . $parsed['notes'] : ''));
    if (strlen($notes) > 240) {
        $notes = substr($notes, 0, 237) . '...';
    }
    return [
        'transit' => $parsed['transit'],
        'institution' => $parsed['institution'],
        'account' => $parsed['account'],
        'name' => $parsed['name'],
        'method' => $raw['method'],
        'notes' => $notes,
    ];
}

/**
 * Manager Approve: copy pending (or posted override) values into live bank fields.
 * Does not change employee Name — name is for verification only.
 * @return array{ok:bool,error?:string}
 */
function ess_dd_manager_approve(PDO $pdo, int $target_id, int $reviewer_id, array $fields, ?int $org_scope = null): array {
    ess_ensure_dd_columns($pdo);
    $transit = preg_replace('/\D+/', '', (string)($fields['transit'] ?? ''));
    $institution = preg_replace('/\D+/', '', (string)($fields['institution'] ?? ''));
    $account = preg_replace('/\D+/', '', (string)($fields['account'] ?? ''));
    $pend_name = trim((string)($fields['name'] ?? ''));

    if (strlen($transit) < 4 || strlen($transit) > 5) {
        return ['ok' => false, 'error' => 'Transit must be 4–5 digits.'];
    }
    $transit = str_pad($transit, 5, '0', STR_PAD_LEFT);
    if (strlen($institution) < 2 || strlen($institution) > 3) {
        return ['ok' => false, 'error' => 'Institution must be 2–3 digits.'];
    }
    $institution = str_pad($institution, 3, '0', STR_PAD_LEFT);
    if (strlen($account) < 5 || strlen($account) > 18) {
        return ['ok' => false, 'error' => 'Account must be 5–18 digits.'];
    }
    if (strlen($pend_name) > 120) {
        $pend_name = substr($pend_name, 0, 120);
    }

    $sql = "UPDATE users SET
                bank_transit=?, bank_institution=?, bank_account=?,
                dd_pending_transit=?, dd_pending_institution=?, dd_pending_account=?, dd_pending_name=?,
                direct_deposit_upload_pending=0,
                dd_verify_status='approved',
                dd_reviewed_at=NOW(), dd_reviewed_by=?
            WHERE id=?";
    $params = [$transit, $institution, $account, $transit, $institution, $account,
               $pend_name !== '' ? $pend_name : null, $reviewer_id, $target_id];
    if ($org_scope !== null) {
        $sql .= " AND org_id=?";
        $params[] = $org_scope;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->rowCount() < 1) {
        // May already match — verify row exists / scope
        $chk = $pdo->prepare("SELECT id FROM users WHERE id=?" . ($org_scope !== null ? " AND org_id=?" : "") . " LIMIT 1");
        $chk->execute($org_scope !== null ? [$target_id, $org_scope] : [$target_id]);
        if (!$chk->fetch()) {
            return ['ok' => false, 'error' => 'Employee not found or out of scope.'];
        }
    }
    ess_append_remark(
        $pdo,
        $target_id,
        'DD APPROVED',
        'Banking updated from upload (T=' . $transit . ' I=' . $institution
            . ' A=***' . substr($account, -4)
            . ($pend_name !== '' ? ' Name=' . $pend_name : '')
            . ') by user #' . $reviewer_id
    );
    return ['ok' => true];
}

/**
 * Manager Reject: clear pending flag; do NOT touch live bank_*.
 * @return array{ok:bool,error?:string}
 */
function ess_dd_manager_reject(PDO $pdo, int $target_id, int $reviewer_id, string $reason = '', ?int $org_scope = null): array {
    ess_ensure_dd_columns($pdo);
    $reason = trim($reason);
    if (strlen($reason) > 200) {
        $reason = substr($reason, 0, 200);
    }
    $sql = "UPDATE users SET
                direct_deposit_upload_pending=0,
                dd_verify_status='rejected',
                dd_reviewed_at=NOW(), dd_reviewed_by=?,
                dd_extract_notes = TRIM(CONCAT(COALESCE(dd_extract_notes,''), IF(COALESCE(dd_extract_notes,'')='', '', ' · '), ?))
            WHERE id=?";
    $note_bit = $reason !== '' ? ('Rejected: ' . $reason) : 'Rejected by manager';
    $params = [$reviewer_id, $note_bit, $target_id];
    if ($org_scope !== null) {
        $sql .= " AND org_id=?";
        $params[] = $org_scope;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $chk = $pdo->prepare("SELECT id FROM users WHERE id=?" . ($org_scope !== null ? " AND org_id=?" : "") . " LIMIT 1");
    $chk->execute($org_scope !== null ? [$target_id, $org_scope] : [$target_id]);
    if (!$chk->fetch()) {
        return ['ok' => false, 'error' => 'Employee not found or out of scope.'];
    }
    ess_append_remark(
        $pdo,
        $target_id,
        'DD REJECTED',
        ($reason !== '' ? $reason : 'Form rejected') . ' by user #' . $reviewer_id
    );
    return ['ok' => true];
}

/** Private storage root (sibling of public_html / live_mirror). */
function ess_dd_storage_root(): string {
    // Prefer KnownHost domain private folder when present
    $kh = '/home/admin/domains/payroll.renocon.ca/private_uploads/direct_deposit';
    if (is_dir('/home/admin/domains/payroll.renocon.ca')) {
        return $kh;
    }
    return dirname(__DIR__) . '/private_uploads/direct_deposit';
}

function ess_dd_allowed_mimes(): array {
    return [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];
}

/**
 * Store uploaded direct deposit form for a user. Returns [ok, error|path_meta].
 * @return array{ok:bool,error?:string}
 */
function ess_dd_store_upload(PDO $pdo, array $user, array $file): array {
    ess_ensure_dd_columns($pdo);
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'No file uploaded.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed. Try again.'];
    }
    $max = 8 * 1024 * 1024;
    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > $max) {
        return ['ok' => false, 'error' => 'File must be under 8 MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: '';
    $map = ess_dd_allowed_mimes();
    if (!isset($map[$mime])) {
        return ['ok' => false, 'error' => 'Only PDF, JPG, or PNG files are allowed.'];
    }
    $ext = $map[$mime];
    $org_id = (int)$user['org_id'];
    $uid = (int)$user['id'];
    $root = ess_dd_storage_root();
    $dir = $root . '/org_' . $org_id;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        return ['ok' => false, 'error' => 'Could not create secure storage folder.'];
    }
    @chmod($dir, 0750);

    $rel = 'org_' . $org_id . '/user_' . $uid . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $abs = $root . '/' . $rel;
    if (!move_uploaded_file($file['tmp_name'], $abs)) {
        return ['ok' => false, 'error' => 'Could not save the file.'];
    }
    @chmod($abs, 0640);

    $orig = basename((string)($file['name'] ?? ('direct_deposit.' . $ext)));
    $orig = preg_replace('/[^\w.\- ()\[\]]+/', '_', $orig) ?: ('direct_deposit.' . $ext);
    if (strlen($orig) > 200) {
        $orig = substr($orig, 0, 200);
    }

    // Remove previous file if present
    $old = trim((string)($user['direct_deposit_file'] ?? ''));
    if ($old !== '' && strpos($old, '..') === false) {
        $old_abs = $root . '/' . ltrim($old, '/');
        if (is_file($old_abs)) {
            @unlink($old_abs);
        }
    }

    // Auto-extract banking fields (best-effort). Never write live bank_* here.
    $extract = ['transit' => null, 'institution' => null, 'account' => null, 'name' => null, 'method' => 'none', 'notes' => 'extract skipped'];
    try {
        $extract = ess_dd_auto_extract($abs);
    } catch (Throwable $e) {
        $extract['notes'] = 'extract error — queued for manager';
        $extract['method'] = 'error';
    }

    $stmt = $pdo->prepare(
        "UPDATE users SET direct_deposit_file=?, direct_deposit_orig_name=?,
                direct_deposit_uploaded_at=NOW(), direct_deposit_upload_pending=1,
                dd_verify_status='pending_manager_verification',
                dd_pending_transit=?, dd_pending_institution=?, dd_pending_account=?, dd_pending_name=?,
                dd_extract_method=?, dd_extract_notes=?, dd_extract_json=?,
                dd_reviewed_at=NULL, dd_reviewed_by=NULL
         WHERE id=?"
    );
    $json = json_encode([
        'transit' => $extract['transit'],
        'institution' => $extract['institution'],
        'account' => $extract['account'],
        'name' => $extract['name'],
        'method' => $extract['method'],
        'notes' => $extract['notes'],
        'extracted_at' => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    $stmt->execute([
        $rel,
        $orig,
        $extract['transit'],
        $extract['institution'],
        $extract['account'],
        $extract['name'],
        $extract['method'],
        $extract['notes'] !== '' ? $extract['notes'] : null,
        $json,
        $uid,
    ]);

    $bits = [];
    if (!empty($extract['transit'])) {
        $bits[] = 'T=' . $extract['transit'];
    }
    if (!empty($extract['institution'])) {
        $bits[] = 'I=' . $extract['institution'];
    }
    if (!empty($extract['account'])) {
        $bits[] = 'A=***' . substr((string)$extract['account'], -4);
    }
    $sum = empty($bits) ? 'no fields auto-detected' : implode(' ', $bits);
    ess_append_remark(
        $pdo,
        $uid,
        'DIRECT DEPOSIT UPLOAD',
        'File submitted: ' . $orig . ' · pending manager verification · extract(' . $extract['method'] . '): ' . $sum
    );

    return ['ok' => true, 'extract' => $extract];
}

function ess_dd_absolute_path(string $rel): ?string {
    $rel = str_replace('\\', '/', $rel);
    if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/') {
        return null;
    }
    if (!preg_match('#^org_\d+/user_\d+_[A-Za-z0-9._-]+$#', $rel)) {
        return null;
    }
    $abs = ess_dd_storage_root() . '/' . $rel;
    return is_file($abs) ? $abs : null;
}

/** Load the signed-in user's row only (id = session user_id). */
function ess_load_self(PDO $pdo): ?array {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return null;
    }
    ess_ensure_dd_columns($pdo);
    $stmt = $pdo->prepare(
        "SELECT id, Name, email, user, pin, password, user_role, org_id, assigned_site_ids, active,
                Tel_Mobile, sin, street_address, city, province, postal_code,
                bank_transit, bank_institution, bank_account, Remarks,
                given_name, surname,
                direct_deposit_file, direct_deposit_orig_name,
                direct_deposit_uploaded_at, direct_deposit_upload_pending,
                dd_pending_transit, dd_pending_institution, dd_pending_account, dd_pending_name,
                dd_verify_status, dd_extract_method, dd_extract_notes, dd_extract_json,
                dd_reviewed_at
         FROM users WHERE id = ? LIMIT 1"
    );
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ess_find_by_mobile(PDO $pdo, int $org_id, string $mobile_digits): array {
    $digits = ess_phone_digits($mobile_digits);
    if ($org_id <= 0 || strlen($digits) < 7) {
        return [];
    }
    $last4 = substr($digits, -4);
    $stmt = $pdo->prepare(
        "SELECT id, Name, email, user, pin, password, user_role, org_id, assigned_site_ids, active, Tel_Mobile
         FROM users
         WHERE org_id = ? AND active = 1
           AND Tel_Mobile IS NOT NULL AND Tel_Mobile <> ''
           AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(Tel_Mobile,'-',''),' ',''),'(',''),')',''),'+','') LIKE ?
         LIMIT 25"
    );
    $stmt->execute([$org_id, '%' . $last4]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $matches = [];
    foreach ($candidates as $row) {
        if (ess_phones_match($row['Tel_Mobile'] ?? '', $digits)) {
            $matches[] = $row;
        }
    }
    return $matches;
}

/** Match active users by users.email or users.user (case-insensitive), scoped to org. */
function ess_find_by_email(PDO $pdo, int $org_id, string $email): array {
    $email = strtolower(trim($email));
    if ($org_id <= 0 || $email === '' || strpos($email, '@') === false) {
        return [];
    }
    $stmt = $pdo->prepare(
        "SELECT id, Name, email, user, pin, password, user_role, org_id, assigned_site_ids, active, Tel_Mobile
         FROM users
         WHERE org_id = ? AND active = 1
           AND (
             LOWER(TRIM(email)) = ?
             OR LOWER(TRIM(`user`)) = ?
           )
         LIMIT 25"
    );
    $stmt->execute([$org_id, $email, $email]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ess_org_name(PDO $pdo, int $org_id): string {
    if ($org_id <= 0) {
        return '';
    }
    $stmt = $pdo->prepare("SELECT org_name FROM organizations WHERE id = ? LIMIT 1");
    $stmt->execute([$org_id]);
    return (string)($stmt->fetchColumn() ?: '');
}

function ess_append_remark(PDO $pdo, int $user_id, string $label, string $note): void {
    $note = trim($note);
    if ($note === '' || $user_id <= 0) {
        return;
    }
    $stamp = date('Y-m-d H:i');
    $line = '[' . $label . ' ' . $stamp . '] ' . $note;
    $stmt = $pdo->prepare(
        "UPDATE users SET Remarks = TRIM(CONCAT(COALESCE(Remarks,''), IF(COALESCE(Remarks,'')='', '', '\n'), ?))
         WHERE id = ?"
    );
    $stmt->execute([$line, $user_id]);
}

function ess_post_login_destination(PDO $pdo, array $user): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $email = trim((string) ($user['email'] ?? ''));
    $phone = ess_phone_digits($user['Tel_Mobile'] ?? '');
    if ($email === '' || ess_email_needs_setup($email) || strlen($phone) < 7) {
        $_SESSION['contact_prompt'] = 1;
    }
    if (!function_exists('sip_resolve_login_destination')) {
        require_once __DIR__ . '/engine/org_modules_lib.php';
    }
    return sip_resolve_login_destination($pdo, (int)$user['org_id'], (int)$user['user_role']);
}

} // function_exists guard
