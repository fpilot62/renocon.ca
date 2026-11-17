<?php
/**
 * Privacy safeguards. No live payroll data.
 * Run: php tests/privacy_lib_test.php
 */
require_once __DIR__ . '/../engine/privacy_lib.php';
require_once __DIR__ . '/../ops/encrypt_sensitive_columns.php';

$fail = 0;
function check($label, $cond): void
{
    global $fail;
    if ($cond) {
        echo "ok  $label\n";
        return;
    }
    echo "FAIL $label\n";
    $fail++;
}

$now = strtotime('2026-11-17 12:00:00');
$grant = [
    'id' => 4,
    'org_id' => 2,
    'expires_at' => '2026-11-17 18:00:00',
    'revoked_at' => null,
];
$open = privacy_business_decision(5, 1, 2, $grant, 'pay', $now, 20);
check('superadmin with a live grant can see pay', $open['allow'] && $open['support']);
$closed = privacy_business_decision(5, 1, 2, null, 'employee', $now, 20);
check('superadmin without a grant cannot see employees', !$closed['allow'] && $closed['reason'] === 'support');
$expired = privacy_business_decision(5, 1, 2, ['org_id' => 2, 'expires_at' => '2026-11-17 11:00:00', 'revoked_at' => null], 'pay', $now, 20);
check('an expired grant does not open pay', !$expired['allow']);
$revoked = privacy_business_decision(5, 1, 2, ['org_id' => 2, 'expires_at' => '2026-11-17 18:00:00', 'revoked_at' => '2026-11-17 12:30:00'], 'report', $now, 20);
check('a revoked grant does not open reports', !$revoked['allow']);
$other = privacy_business_decision(5, 1, 3, $grant, 'attachment', $now, 20);
check('a grant for one org does not open another', !$other['allow']);
$all = privacy_business_decision(5, 1, 0, null, 'employee', $now, 20);
check('superadmin must choose one org', !$all['allow'] && $all['reason'] === 'choose_org');
$counts = privacy_business_decision(5, 1, 0, null, 'platform', $now, 20);
check('superadmin can see platform counts', $counts['allow'] && !$counts['support']);

$admin = privacy_business_decision(20, 2, 2, null, 'pay', $now, 20);
check('org admin sees their own pay', $admin['allow'] && !$admin['support'] && $admin['reason'] === 'admin');
$cross = privacy_business_decision(20, 2, 3, null, 'employee', $now, 20);
check('org admin cannot see another org', !$cross['allow'] && $cross['reason'] === 'org');
$supervisorPay = privacy_business_decision(25, 2, 2, null, 'pay', $now, 29);
check('supervisor can see own-org pay only where the page already allows it', $supervisorPay['allow'] && $supervisorPay['reason'] === 'supervisor');
$supervisorBank = privacy_business_decision(25, 2, 2, null, 'bank', $now, 29);
check('supervisor cannot see bank numbers', !$supervisorBank['allow']);
$supervisorAudit = privacy_business_decision(25, 2, 2, null, 'employee', $now, 20);
check('staff audit stays with org admins', !$supervisorAudit['allow']);
$staff = privacy_business_decision(30, 2, 2, null, 'pay', $now, 29);
check('staff cannot open payroll pages', !$staff['allow'] && $staff['reason'] === 'role');

check('platform superadmin cannot grant themselves access', !privacy_may_grant(10, 2, 2) && !privacy_may_grant(5, 2, 2));
check('org admin can grant only their org', privacy_may_grant(20, 2, 2) && !privacy_may_grant(20, 2, 3) && !privacy_may_grant(25, 2, 2));
check('grant length is capped at 72 hours', privacy_grant_expiry(72, $now) === $now + 72 * 3600 && privacy_grant_expiry(73, $now) === null && privacy_grant_expiry(0, $now) === null);
check('a reason that looks like a SIN is refused', !privacy_reason_is_safe('Please look at 123-456-789 today'));
check('a reason that looks like an account is refused', !privacy_reason_is_safe('Account 1234567 needs a look'));
check('a short reason is refused', !privacy_reason_is_safe('help me'));
check('a plain reason is accepted', privacy_reason_is_safe('Check the missing clock-out from Tuesday.'));

$note = privacy_owner_message('North Co', '2026-11-17 18:00:00', 9, 'Check the missing clock-out from Tuesday.');
check('owner email names the org and the end time', strpos($note['subject'], 'North Co') !== false && strpos($note['body'], '2026-11-17 18:00:00') !== false);
check('owner email does not carry a sample account', strpos($note['body'], '1234567') === false && strpos($note['body'], 'bank, or SIN values') !== false);

$key = base64_encode(random_bytes(32));
$secret = '001234567';
$stored = privacy_encrypt($secret, $key);
check('ciphertext is marked and hides the digits', privacy_is_encrypted($stored) && strpos($stored, '1234567') === false);
check('decrypt returns the original', privacy_decrypt($stored, $key) === $secret);
check('encrypting twice does not nest', privacy_encrypt($stored, $key) === $stored);
check('legacy plaintext is left for the migration to pick up', privacy_open_sensitive('001234567') === '001234567');
check('empty stays empty', privacy_encrypt('', $key) === '' && privacy_mask_account('') === '' && privacy_mask_sin('') === '');
check('account mask keeps only the last four', privacy_mask_account('0012-34567') === '•••••4567');
check('SIN mask keeps only the last three', privacy_mask_sin('123-456-789') === '***-***-789');
$short = false;
try {
    privacy_key_bytes('too-short');
} catch (RuntimeException $e) {
    $short = true;
}
check('a short data key is refused', $short);
$placeholder = false;
try {
    privacy_key_bytes(PAYROLL_DATA_KEY_PLACEHOLDER);
} catch (RuntimeException $e) {
    $placeholder = true;
}
check('the example data key is refused', $placeholder);
check('stored values are classified without revealing them', privacy_classify_stored_value('') === 'empty' && privacy_classify_stored_value($stored) === 'encrypted' && privacy_classify_stored_value('001234567') === 'plaintext');

$params = privacy_cookie_params(true, false);
check('session cookie is secure and httponly', $params['secure'] === true && $params['httponly'] === true && $params['samesite'] === 'Lax');
$local = privacy_cookie_params(false, true);
check('local HTTP testing can leave the secure flag off', $local['secure'] === false && $local['httponly'] === true);
check('production HTTP still sets the secure flag', privacy_cookie_params(false, false)['secure'] === true);
check('idle timeout is enforced', privacy_timeout_decision(100, 100, 100 + PRIVACY_IDLE_SECONDS + 1, true) === 'idle');
check('absolute timeout is enforced', privacy_timeout_decision(100, 100 + PRIVACY_IDLE_SECONDS, 100 + PRIVACY_ABSOLUTE_SECONDS + 1, true) === 'absolute');
check('a signed-out request is not timed out', privacy_timeout_decision(1, 1, 999999, false) === 'ok');
check('https redirect stays on this host', privacy_https_redirect_target('payroll.renocon.ca', '/staff_audit.php') === 'https://payroll.renocon.ca/staff_audit.php');
check('https redirect rejects a crafted host', privacy_https_redirect_target('evil.example', 'https://evil.example') === null && privacy_https_redirect_target('payroll.renocon.ca@evil.example', '/') === null);
check('csrf rejects a missing or swapped token', !privacy_csrf_ok('', 'abc') && !privacy_csrf_ok('abc', 'abd') && privacy_csrf_ok('abc', 'abc'));

$app = dirname(__DIR__);
$private = payroll_resolve_env_file($app, '');
check('default secret file is outside the app directory', !payroll_path_inside_app($private, $app) && substr($private, -11) === 'payroll.env');
$inside = $app . '/config/payroll.env';
$insideRefused = false;
try {
    payroll_resolve_env_file($app, $inside);
} catch (RuntimeException $e) {
    $insideRefused = true;
}
check('a secret file inside the web root is refused', $insideRefused);
$example = file_get_contents($app . '/config/payroll.env.example');
check('example data key is a placeholder', strpos($example, PAYROLL_DATA_KEY_PLACEHOLDER) !== false);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE organizations (id INTEGER PRIMARY KEY, org_name TEXT)');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, org_id INTEGER, Name TEXT, Email TEXT, user_level INTEGER)');
$pdo->exec('CREATE TABLE sites (SId INTEGER PRIMARY KEY, org_id INTEGER)');
$pdo->exec('CREATE TABLE privacy_support_grants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    org_id INTEGER NOT NULL,
    granted_by INTEGER NOT NULL,
    reason TEXT NOT NULL,
    starts_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    revoked_at TEXT NULL,
    revoked_by INTEGER NULL,
    owner_notified_at TEXT NULL,
    notify_note TEXT NULL,
    created_at TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE privacy_access_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    actor_id INTEGER NOT NULL,
    actor_level INTEGER NOT NULL,
    org_id INTEGER NOT NULL,
    action TEXT NOT NULL,
    resource TEXT NOT NULL,
    grant_id INTEGER NULL,
    ip TEXT NULL,
    note TEXT NULL
)');
$pdo->exec("INSERT INTO organizations (id, org_name) VALUES (2, 'North Co')");
$pdo->exec("INSERT INTO users (id, org_id, Name, Email, user_level) VALUES (9, 2, 'Owner', 'owner@example.com', 20)");
$pdo->exec('INSERT INTO sites (SId, org_id) VALUES (6, 2)');
$_SESSION['user_id'] = 9;
$_SESSION['user_level'] = 20;
$_SESSION['org_id'] = 2;
$sentTo = [];
$saved = privacy_create_grant($pdo, 2, 9, 20, 2, 4, 'Check the missing clock-out from Tuesday.', $now, function (string $to, string $subject, string $body) use (&$sentTo): bool {
    $sentTo[] = [$to, $subject, $body];
    return true;
});
check('org admin grant is active and emails the owner', (int)$saved['org_id'] === 2 && $sentTo && $sentTo[0][0] === 'owner@example.com' && strpos($sentTo[0][1], 'North Co') !== false);
$seen = privacy_business_decision(5, 1, 2, $saved, 'pay', $now, 20);
check('the saved grant opens pay for the platform superadmin', $seen['allow'] && $seen['support']);
privacy_revoke_grant($pdo, (int)$saved['id'], 9, 20, 2, $now + 60);
$after = privacy_active_grant($pdo, 2, $now + 120);
check('the owner can revoke the grant', $after === null);
$log = $pdo->query('SELECT action FROM privacy_access_log ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
check('grant and revoke are logged', in_array('grant', $log, true) && in_array('revoke', $log, true));
$self = false;
try {
    privacy_create_grant($pdo, 2, 1, 5, 2, 2, 'Check the missing clock-out from Tuesday.', $now);
} catch (RuntimeException $e) {
    $self = true;
}
check('the platform superadmin cannot create a grant', $self);

$root = dirname(__DIR__);
$pages = [
    'staff_audit.php' => 'employee',
    'get_staff_audit.php' => 'attachment',
    'delete_attendance.php' => 'employee',
    'undo_finalization.php' => 'employee',
    'manual_attendance.php' => 'employee',
    'order_amounts.php' => 'pay',
    'outstanding_wages.php' => 'pay',
    'payroll_master_ledger.php' => 'pay',
    'payroll_payment_status.php' => 'pay',
    'payroll_sheet_review.php' => 'report',
    'generate_pay_documents.php' => 'pay',
    'privacy_support.php' => 'privacy_boot_web',
];
foreach ($pages as $page => $marker) {
    $src = file_get_contents($root . '/' . $page);
    check($page . ' boots the privacy gate', strpos($src, 'privacy_boot_web') !== false);
    if ($marker !== 'privacy_boot_web') {
        check($page . ' enforces business access', strpos($src, 'privacy_require_business') !== false && strpos($src, "'" . $marker . "'") !== false);
    }
}
$auditPage = file_get_contents($root . '/staff_audit.php');
check('staff audit requires an admin and a csrf token', strpos($auditPage, 'payroll_require_admin') !== false && strpos($auditPage, 'privacy_require_csrf') !== false && strpos($auditPage, 'PRIVACY_CSRF') !== false);
check('staff audit cookie is httponly', strpos($auditPage, "'httponly' => true") !== false && strpos($auditPage, "'httponly' => false") === false);
check('staff audit does not query every organization', strpos($auditPage, "\$where[] = '1=0'") !== false);
$deletePage = file_get_contents($root . '/delete_attendance.php');
check('delete cannot skip the organization filter', strpos($deletePage, '$restrict = true') !== false && strpos($deletePage, '$restrict = false') === false);
$paydocs = file_get_contents($root . '/generate_pay_documents.php');
check('pay documents mask bank numbers and keep MICR separate', strpos($paydocs, 'privacy_mask_account') !== false && strpos($paydocs, 'account_micr') !== false);
$support = file_get_contents($root . '/privacy_support.php');
check('support page can grant and revoke', strpos($support, 'privacy_create_grant') !== false && strpos($support, 'privacy_revoke_grant') !== false && strpos($support, 'privacy_org_counts') !== false);
$script = file_get_contents($root . '/ops/encrypt_sensitive_columns.php');
check('encrypt script is manual and does not print values', strpos($script, '--apply') !== false && strpos($script, 'dry-run') !== false && strpos($script, 'secret_value') !== false && strpos($script, 'echo $') === false);
[$apply, $err] = privacy_encrypt_args(['script']);
check('encrypt script defaults to dry run', $apply === false && $err === null);
[$apply, $err] = privacy_encrypt_args(['script', '--apply']);
check('encrypt script apply is explicit', $apply === true && $err === null);
[$apply, $err] = privacy_encrypt_args(['script', '--force']);
check('encrypt script rejects unknown flags', $err !== null);

if ($fail > 0) {
    echo $fail . " failed\n";
    exit(1);
}
echo "all passed\n";
