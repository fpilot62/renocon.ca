<?php
/**
 * Staging guard, scrub, and retarget. No live database.
 * Run: php tests/staging_guard_test.php
 */
require_once __DIR__ . '/../engine/staging.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/../ops/staging_tool.php';
require_once __DIR__ . '/../alarm/api/alarm_invite_lib.php';

$fail = 0;
function check(string $label, bool $cond): void
{
    global $fail;
    if ($cond) {
        echo "ok  $label\n";
        return;
    }
    echo "FAIL $label\n";
    $fail++;
}

payroll_staging_reset();
putenv('STAGING');
putenv('PAYROLL_ENV_FILE');
check('live is not staging', payroll_is_staging() === false);

$liveEnv = tempnam(sys_get_temp_dir(), 'liveenv');
file_put_contents($liveEnv, "STAGING=1\nDB_NAME=admin_payroll\nDB_USER=payroll\nDB_PASS=secret\n");
putenv('PAYROLL_ENV_FILE=' . $liveEnv);
payroll_staging_reset();
check('live env flag does not turn staging on', payroll_is_staging() === false);

$stageEnv = tempnam(sys_get_temp_dir(), 'stageenv');
file_put_contents($stageEnv, "STAGING=1\nDB_NAME=admin_payroll_staging\nDB_USER=payroll_staging\nDB_PASS=secret\n");
putenv('PAYROLL_ENV_FILE=' . $stageEnv);
payroll_staging_reset();
check('staging env turns staging on', payroll_is_staging() === true);

putenv('PAYROLL_ENV_FILE');
putenv('STAGING=1');
payroll_staging_reset();
check('STAGING=1 is staging', payroll_is_staging() === true);

$html = "<!DOCTYPE html><html><head><title>Pay</title></head><body><p>Hi</p></body></html>";
$out = payroll_staging_inject_html($html);
check('banner is red STAGING', str_contains($out, 'STAGING') && str_contains($out, '#b91c1c') && str_contains($out, '<body>'));
check('banner adds noindex', str_contains($out, 'noindex'));
check('json is left alone', payroll_staging_inject_html('{"ok":true}') === '{"ok":true}');
check('banner is not doubled', substr_count(payroll_staging_inject_html($out), 'payroll-staging-banner') === 1);

$blocked = send_mail('person@example.com', 'Subject', '<p>Hi</p>');
check('staging mail is blocked', ($blocked['ok'] ?? true) === false && ($blocked['blocked'] ?? false) === true);

$sms = alarm_invite_send_sms('+12505550100', 'payroll ping');
check('staging sms is blocked', ($sms['error'] ?? '') === 'staging_blocked' && ($sms['ok'] ?? true) === false);

putenv('STAGING');
payroll_staging_reset();
$invalid = send_mail('not-an-email', 'Subject', '<p>Hi</p>');
check('live mail still validates the address', ($invalid['blocked'] ?? false) === false && str_contains((string)($invalid['error'] ?? ''), 'valid email'));

check('sql write to live is refused', staging_sql_targets_live("DROP DATABASE admin_payroll") === true);
check('sql write to staging is allowed', staging_sql_targets_live("DROP DATABASE IF EXISTS `admin_payroll_staging`") === false);
check('show live database is not a write', staging_sql_targets_live("SHOW DATABASES LIKE 'admin_payroll'") === false);
$grants = "GRANT USAGE ON *.* TO 'payroll_staging'@'localhost'\n"
    . "GRANT ALL PRIVILEGES ON `admin_payroll_staging`.* TO 'payroll_staging'@'localhost'\n";
check('staging grants are only the staging schema', staging_grants_are_staging_only($grants) === true);
$wide = $grants . "GRANT ALL PRIVILEGES ON `admin_payroll`.* TO 'payroll_staging'@'localhost'\n";
check('a live grant is rejected', staging_grants_are_staging_only($wide) === false);

$app = sys_get_temp_dir() . '/payroll-staging-retarget-' . getmypid();
@mkdir($app . '/engine', 0700, true);
@mkdir($app . '/sip', 0700, true);
file_put_contents($app . '/engine/staging.php', file_get_contents(__DIR__ . '/../engine/staging.php'));
file_put_contents($app . '/db.php', <<<'PHP'
<?php
$host = 'localhost';
$user = 'payroll_live';
$pass = 'live-secret-not-for-staging';
$dbname = 'admin_payroll';
$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
$pdo = new PDO($dsn, $user, $pass);
PHP);
file_put_contents($app . '/sip/db_config.php', <<<'PHP'
<?php
$host = 'localhost';
$user = 'payroll_live';
$pass = 'live-secret-not-for-staging';
$dbname = 'admin_payroll';
PHP);
file_put_contents($app . '/forecast_cron.php', <<<'PHP'
<?php
$dest_db = "admin_payroll";
$src_db = "fpilot_penticton";
PHP);
putenv('STAGING_ALLOW_NONROOT=1');
$env = $app . '/staging.env';
file_put_contents($env, "STAGING=1\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_NAME=admin_payroll_staging\nDB_USER=payroll_staging\nDB_PASS=staging-secret\nPAYROLL_DATA_KEY=abc\n");
staging_retarget_tree($app, $env);
$db = file_get_contents($app . '/db.php');
$sip = file_get_contents($app . '/sip/db_config.php');
$cron = file_get_contents($app . '/forecast_cron.php');
check('db.php lost the live password', !str_contains($db, 'live-secret-not-for-staging'));
check('db.php reads the staging secret', str_contains($db, "staging_db_secret('DB_PASS')") && str_contains($db, 'engine/staging.php'));
check('db.php does not quote the live database', !preg_match("/['\"]admin_payroll['\"]/", $db));
check('nested config is retargeted', !str_contains($sip, 'live-secret-not-for-staging') && str_contains($sip, "staging_db_secret('DB_NAME')"));
check('forecast destination is staging', str_contains($cron, 'admin_payroll_staging') && str_contains($cron, 'fpilot_penticton'));
check('live tree is not retargeted', (function () use ($app) {
    $live = '/home/renocon/public_html/payroll';
    return staging_path_is_copy($live) === false && staging_path_is_copy($app) === true;
})());

$sqlite = $app . '/scrub.sqlite';
$pdo = new PDO('sqlite:' . $sqlite);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, tel_mobile TEXT, sin TEXT, bank_account TEXT, bank_transit TEXT, transit_rate TEXT)');
$pdo->exec("INSERT INTO users VALUES (7, 'person@example.com', '2505550100', '123456789', '998877', '12345', '18.50')");
$pdo->exec('CREATE TABLE sites (id INTEGER PRIMARY KEY, dd_account TEXT, dd_transit TEXT, email TEXT)');
$pdo->exec("INSERT INTO sites VALUES (3, '111222', '99999', 'owner@example.com')");
$counts = staging_scrub_apply($pdo, staging_discover_columns($pdo));
staging_scrub_verify($pdo);
$row = $pdo->query('SELECT * FROM users WHERE id = 7')->fetch(PDO::FETCH_ASSOC);
$site = $pdo->query('SELECT * FROM sites WHERE id = 3')->fetch(PDO::FETCH_ASSOC);
check('staff email is replaced', ($row['email'] ?? '') === 'staff+7@staging.invalid');
check('phone sin account and transit are blank', ($row['tel_mobile'] ?? 'x') === '' && ($row['sin'] ?? 'x') === '' && ($row['bank_account'] ?? 'x') === '' && ($row['bank_transit'] ?? 'x') === '');
check('pay rate is kept', ($row['transit_rate'] ?? '') === '18.50');
check('site bank numbers are blank', ($site['dd_account'] ?? 'x') === '' && ($site['dd_transit'] ?? 'x') === '');
check('other emails use the invalid domain', ($site['email'] ?? '') === 'row+3@staging.invalid');
check('scrub counted the columns', $counts['email'] === 2 && $counts['phone'] === 1 && $counts['sin'] === 1 && $counts['account'] === 2 && $counts['transit'] === 2);

$pages = [
    'mailer.php' => "payroll_staging_outbound_blocked('mail')",
    'contact_lib.php' => "payroll_staging_outbound_blocked('mail')",
    'direct_deposit_file.php' => "payroll_staging_outbound_blocked('eft')",
    'payroll_compliance_desk.php' => "payroll_staging_outbound_blocked('eft')",
    'alarm/api/alarm_invite_lib.php' => "payroll_staging_outbound_blocked('sms')",
    'alarm/api/cron_notify.php' => "payroll_staging_outbound_blocked('push')",
];
foreach ($pages as $rel => $needle) {
    $src = file_get_contents(__DIR__ . '/../' . $rel);
    check($rel . ' has the staging guard', str_contains($src, $needle));
}

@unlink($liveEnv);
@unlink($stageEnv);

if ($fail > 0) {
    echo $fail . " failed\n";
    exit(1);
}
echo "all passed\n";
