<?php
/**
 * Helpers for ops/setup_staging_v1.sh.
 * Prints counts and statuses. Never prints a password, SIN, bank number, or phone.
 *
 *   php ops/staging_tool.php secrets <secret_dir> <env_file> <htpass_file>
 *   php ops/staging_tool.php ensure-db <env_file>
 *   php ops/staging_tool.php recreate-db
 *   php ops/staging_tool.php drop-db <env_file>
 *   php ops/staging_tool.php retarget <staging_app> <env_file>
 *   php ops/staging_tool.php scrub <env_file>
 *   php ops/staging_tool.php protect <staging_app> <htpass_file> <env_file>
 *   php ops/staging_tool.php uapi-ok <json_file>
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../engine/staging.php';

const STAGING_DB_NAME = 'admin_payroll_staging';
const STAGING_DB_USER = 'payroll_staging';
const STAGING_LIVE_DB = 'admin_payroll';

function staging_tool_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function staging_read_env(string $path): array
{
    if (!is_file($path)) {
        staging_tool_fail('Staging config is missing.');
    }
    $cfg = [];
    foreach (preg_split("/\r\n|\n|\r/", (string)file_get_contents($path)) as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);
        if (strlen($val) >= 2) {
            $quote = $val[0];
            if (($quote === '"' || $quote === "'") && str_ends_with($val, $quote)) {
                $val = substr($val, 1, -1);
            }
        }
        if (preg_match('/^[A-Z0-9_]+$/', $key)) {
            $cfg[$key] = $val;
        }
    }
    return $cfg;
}

function staging_assert_staging_config(array $cfg): void
{
    if (($cfg['STAGING'] ?? '') !== '1') {
        staging_tool_fail('Staging config is missing STAGING=1.');
    }
    if (($cfg['DB_NAME'] ?? '') !== STAGING_DB_NAME || ($cfg['DB_NAME'] ?? '') === STAGING_LIVE_DB) {
        staging_tool_fail('Refusing a config whose database is not admin_payroll_staging.');
    }
    if (($cfg['DB_USER'] ?? '') !== STAGING_DB_USER) {
        staging_tool_fail('Refusing a config whose database user is not payroll_staging.');
    }
    if (($cfg['DB_PASS'] ?? '') === '') {
        staging_tool_fail('Staging config has no database password.');
    }
}

function staging_sql_targets_live(string $sql): bool
{
    $compact = preg_replace('/\s+/', ' ', strtolower($sql)) ?? strtolower($sql);
    $stripped = str_replace('admin_payroll_staging', '', $compact);
    $stripped = str_replace('`admin_payroll_staging`', '', $stripped);
    if (!str_contains($stripped, 'admin_payroll')) {
        return false;
    }
    return (bool)preg_match('/\b(drop|create|alter|insert|update|delete|grant|revoke|truncate|replace|load)\b/', $compact);
}

function staging_mysql_bin(): string
{
    $bin = getenv('STAGING_MYSQL');
    return is_string($bin) && $bin !== '' ? $bin : 'mysql';
}

/**
 * @return array{0:int,1:string,2:string}
 */
function staging_mysql_run(string $sql): array
{
    if (staging_sql_targets_live($sql)) {
        staging_tool_fail('Refusing SQL that writes to the live database.');
    }
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open([staging_mysql_bin(), '--batch', '--skip-column-names'], $descriptors, $pipes);
    if (!is_resource($proc)) {
        staging_tool_fail('Could not run mysql.');
    }
    fwrite($pipes[0], $sql);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code, is_string($out) ? $out : '', is_string($err) ? $err : ''];
}

function staging_mysql_exec(string $sql): void
{
    [$code, , $err] = staging_mysql_run($sql);
    if ($code !== 0) {
        $err = trim($err);
        staging_tool_fail($err === '' ? 'mysql failed.' : 'mysql failed: ' . $err);
    }
}

function staging_mysql_query(string $sql): string
{
    [$code, $out, $err] = staging_mysql_run($sql);
    if ($code !== 0) {
        $err = trim($err);
        staging_tool_fail($err === '' ? 'mysql failed.' : 'mysql failed: ' . $err);
    }
    return $out;
}

function staging_quote_sql_string(string $value): string
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $value) . "'";
}

function staging_grants_are_staging_only(string $grants): bool
{
    $saw = false;
    foreach (preg_split("/\r\n|\n|\r/", trim($grants)) as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^GRANT USAGE ON \*\.\* /i', $line)) {
            continue;
        }
        if (preg_match('/^GRANT ALL PRIVILEGES ON `?admin_payroll_staging`?\.\* /i', $line)) {
            $saw = true;
            continue;
        }
        return false;
    }
    return $saw;
}

function staging_ensure_db(string $envFile): void
{
    $cfg = staging_read_env($envFile);
    staging_assert_staging_config($cfg);
    $pass = staging_quote_sql_string($cfg['DB_PASS']);
    $ident = "'payroll_staging'@'localhost'";
    staging_mysql_exec(
        'CREATE DATABASE IF NOT EXISTS `admin_payroll_staging` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $count = trim(staging_mysql_query(
        "SELECT COUNT(*) FROM mysql.user WHERE user='payroll_staging' AND host='localhost'"
    ));
    if ($count === '0') {
        staging_mysql_exec("CREATE USER {$ident} IDENTIFIED BY {$pass}");
    } else {
        staging_mysql_exec("ALTER USER {$ident} IDENTIFIED BY {$pass}");
    }
    staging_mysql_exec("GRANT ALL PRIVILEGES ON `admin_payroll_staging`.* TO {$ident}");
    staging_mysql_exec('FLUSH PRIVILEGES');
    $grants = staging_mysql_query("SHOW GRANTS FOR {$ident}");
    if (!staging_grants_are_staging_only($grants)) {
        staging_tool_fail('Staging database user has a privilege outside admin_payroll_staging.');
    }
    echo "db user payroll_staging granted on admin_payroll_staging only\n";
}

function staging_recreate_db(): void
{
    staging_mysql_exec('DROP DATABASE IF EXISTS `admin_payroll_staging`');
    staging_mysql_exec(
        'CREATE DATABASE `admin_payroll_staging` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    echo "recreated admin_payroll_staging\n";
}

function staging_drop_db(string $envFile): void
{
    if ($envFile !== '' && is_file($envFile)) {
        $cfg = staging_read_env($envFile);
        staging_assert_staging_config($cfg);
    }
    staging_mysql_exec('DROP DATABASE IF EXISTS `admin_payroll_staging`');
    $count = trim(staging_mysql_query(
        "SELECT COUNT(*) FROM mysql.user WHERE user='payroll_staging' AND host='localhost'"
    ));
    if ($count !== '0') {
        staging_mysql_exec("DROP USER 'payroll_staging'@'localhost'");
    }
    echo "dropped admin_payroll_staging and payroll_staging@localhost\n";
}

function staging_write_secrets(string $secretDir, string $envFile, string $htpassFile): void
{
    if (!is_dir($secretDir) && !mkdir($secretDir, 0700, true) && !is_dir($secretDir)) {
        staging_tool_fail('Could not create the secret directory.');
    }
    chmod($secretDir, 0700);
    $dbPassFile = $secretDir . '/db-password';
    $authFile = $secretDir . '/basic-auth.txt';
    $keyFile = $secretDir . '/data-key';
    if (!is_file($dbPassFile)) {
        file_put_contents($dbPassFile, STAGING_DB_USER . "\n" . bin2hex(random_bytes(18)) . "\n");
        chmod($dbPassFile, 0600);
    }
    if (!is_file($authFile)) {
        file_put_contents($authFile, "username=staging\npassword=" . bin2hex(random_bytes(18)) . "\n");
        chmod($authFile, 0600);
    }
    if (!is_file($keyFile)) {
        file_put_contents($keyFile, base64_encode(random_bytes(32)) . "\n");
        chmod($keyFile, 0600);
    }
    $dbLines = file($dbPassFile, FILE_IGNORE_NEW_LINES);
    $dbPass = trim((string)($dbLines[1] ?? ''));
    $key = trim((string)file_get_contents($keyFile));
    if ($dbPass === '' || $key === '') {
        staging_tool_fail('Staging secret files are incomplete.');
    }
    $envDir = dirname($envFile);
    if (!is_dir($envDir) && !mkdir($envDir, 0700, true) && !is_dir($envDir)) {
        staging_tool_fail('Could not create the staging config directory.');
    }
    $body = "STAGING=1\n"
        . "DB_HOST=127.0.0.1\n"
        . "DB_PORT=3306\n"
        . "DB_NAME=" . STAGING_DB_NAME . "\n"
        . "DB_USER=" . STAGING_DB_USER . "\n"
        . "DB_PASS=" . $dbPass . "\n"
        . "PAYROLL_DATA_KEY=" . $key . "\n";
    $tmp = $envFile . '.tmp';
    file_put_contents($tmp, $body);
    chmod($tmp, 0600);
    rename($tmp, $envFile);
    chmod($envFile, 0600);
    $authLines = file($authFile, FILE_IGNORE_NEW_LINES);
    $authPass = '';
    foreach ($authLines as $line) {
        if (str_starts_with((string)$line, 'password=')) {
            $authPass = substr((string)$line, strlen('password='));
        }
    }
    if ($authPass === '') {
        staging_tool_fail('Basic-auth secret is incomplete.');
    }
    $htDir = dirname($htpassFile);
    if (!is_dir($htDir) && !mkdir($htDir, 0750, true) && !is_dir($htDir)) {
        staging_tool_fail('Could not create the htpasswd directory.');
    }
    file_put_contents($htpassFile, 'staging:' . password_hash($authPass, PASSWORD_BCRYPT) . "\n");
    chmod($htpassFile, 0640);
    echo "secrets stored\n";
}

function staging_decode_php_string(string $tokenText): string
{
    if ($tokenText === '' || ($tokenText[0] !== "'" && $tokenText[0] !== '"')) {
        return $tokenText;
    }
    $quote = $tokenText[0];
    $inner = substr($tokenText, 1, -1);
    if ($quote === "'") {
        return str_replace(["\\\\", "\\'"], ["\\", "'"], $inner);
    }
    return stripcslashes($inner);
}

function staging_relative_require(string $fromDir, string $targetFile): string
{
    $from = explode('/', trim(str_replace('\\', '/', $fromDir), '/'));
    $to = explode('/', trim(str_replace('\\', '/', $targetFile), '/'));
    while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
        array_shift($from);
        array_shift($to);
    }
    return str_repeat('../', count($from)) . implode('/', $to);
}

/**
 * @return array{src:string,password:string,replaced:bool}
 */
function staging_rewrite_credentials(string $src): array
{
    $map = [
        'host' => "staging_db_secret('DB_HOST')",
        'db_host' => "staging_db_secret('DB_HOST')",
        'dbhost' => "staging_db_secret('DB_HOST')",
        'user' => "staging_db_secret('DB_USER')",
        'username' => "staging_db_secret('DB_USER')",
        'db_user' => "staging_db_secret('DB_USER')",
        'dbuser' => "staging_db_secret('DB_USER')",
        'pass' => "staging_db_secret('DB_PASS')",
        'password' => "staging_db_secret('DB_PASS')",
        'db_pass' => "staging_db_secret('DB_PASS')",
        'dbpass' => "staging_db_secret('DB_PASS')",
        'dbname' => "staging_db_secret('DB_NAME')",
        'db_name' => "staging_db_secret('DB_NAME')",
        'database' => "staging_db_secret('DB_NAME')",
        'db' => "staging_db_secret('DB_NAME')",
    ];
    $tokens = token_get_all($src);
    $password = '';
    $user = '';
    $n = count($tokens);
    $replaced = false;
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE) {
            continue;
        }
        $name = strtolower(substr($tokens[$i][1], 1));
        if (!isset($map[$name])) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $n || $tokens[$j] !== '=') {
            continue;
        }
        $j++;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $n || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $decoded = staging_decode_php_string($tokens[$j][1]);
        if (str_contains($name, 'pass') && $password === '') {
            $password = $decoded;
        }
        if (str_contains($name, 'user') && $user === '') {
            $user = $decoded;
        }
        $tokens[$j][1] = $map[$name];
        $replaced = true;
    }
    $out = '';
    foreach ($tokens as $token) {
        $out .= is_array($token) ? $token[1] : $token;
    }
    if ($password !== '' && !in_array($password, ['localhost', '127.0.0.1', STAGING_LIVE_DB, STAGING_DB_NAME], true)) {
        $quoted = ["'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $password) . "'"];
        $double = '"' . str_replace(["\\", '"', '$'], ["\\\\", '\\"', '\\$'], $password) . '"';
        $out = str_replace($quoted[0], "staging_db_secret('DB_PASS')", $out);
        $out = str_replace($double, "staging_db_secret('DB_PASS')", $out);
    }
    if ($user !== '' && !in_array($user, ['localhost', '127.0.0.1', 'root'], true)) {
        $out = str_replace("'" . $user . "'", "staging_db_secret('DB_USER')", $out);
        $out = str_replace('"' . $user . '"', "staging_db_secret('DB_USER')", $out);
    }
    $out = preg_replace("/(['\"])admin_payroll\\1/", '$1' . STAGING_DB_NAME . '$1', $out) ?? $out;
    $out = preg_replace('/dbname=admin_payroll(?!_)/', 'dbname=' . STAGING_DB_NAME, $out) ?? $out;
    return ['src' => $out, 'password' => $password, 'replaced' => $replaced];
}

function staging_prepend_loader(string $src, string $requireRel, string $envFile): string
{
    if (preg_match("/['\"\r\n]/", $envFile)) {
        throw new RuntimeException('Staging config path cannot be embedded.');
    }
    $envLine = "putenv('PAYROLL_ENV_FILE=" . $envFile . "');\n"
        . "\$_ENV['PAYROLL_ENV_FILE'] = '" . $envFile . "';\n";
    $loader = "require_once __DIR__ . '/" . $requireRel . "';\n"
        . "putenv('STAGING=1');\n"
        . "\$_ENV['STAGING'] = '1';\n"
        . $envLine;
    if (preg_match('/^<\?php\s*/', $src, $match)) {
        return substr($src, 0, strlen($match[0])) . $loader . substr($src, strlen($match[0]));
    }
    return "<?php\n" . $loader . $src;
}

function staging_path_is_copy(string $app): bool
{
    $real = realpath($app);
    if ($real === false) {
        return false;
    }
    $real = rtrim(str_replace('\\', '/', $real), '/');
    $live = '/home/renocon/public_html/payroll';
    if ($real === $live || str_starts_with($real, $live . '/')) {
        return false;
    }
    if ($real === '/home/renocon/staging_payroll' || str_starts_with($real, '/home/renocon/staging_payroll/')) {
        return true;
    }
    $allow = getenv('STAGING_ALLOW_NONROOT') === '1';
    return $allow && str_starts_with($real, '/tmp/');
}

function staging_retarget_file(string $file, string $app, string $envFile, bool $credentials): void
{
    $src = file_get_contents($file);
    if ($src === false) {
        staging_tool_fail('Could not read ' . basename($file));
    }
    $password = '';
    if ($credentials) {
        $rewritten = staging_rewrite_credentials($src);
        if (!$rewritten['replaced']) {
            staging_tool_fail(basename($file) . ' has no credential assignment to retarget.');
        }
        $src = $rewritten['src'];
        $password = $rewritten['password'];
        $rel = staging_relative_require(dirname($file), $app . '/engine/staging.php');
        $src = staging_prepend_loader($src, $rel, $envFile);
        if ($password !== '' && str_contains($src, $password)) {
            staging_tool_fail(basename($file) . ' still contains the live database password.');
        }
        if (preg_match("/['\"]admin_payroll['\"]/", $src)) {
            staging_tool_fail(basename($file) . ' still names the live database.');
        }
    } else {
        $src = preg_replace("/(['\"])admin_payroll\\1/", '$1' . STAGING_DB_NAME . '$1', $src) ?? $src;
    }
    file_put_contents($file, $src);
}

function staging_retarget_tree(string $app, string $envFile): void
{
    if (!staging_path_is_copy($app)) {
        staging_tool_fail('Refusing to retarget a tree that is not the staging docroot.');
    }
    $cfg = staging_read_env($envFile);
    staging_assert_staging_config($cfg);
    $db = $app . '/db.php';
    if (!is_file($db)) {
        staging_tool_fail('Staging copy has no db.php.');
    }
    staging_retarget_file($db, $app, $envFile, true);
    foreach ([$app . '/db_config.php'] as $one) {
        if (is_file($one)) {
            staging_retarget_file($one, $app, $envFile, true);
        }
    }
    foreach (glob($app . '/*/db_config.php') ?: [] as $one) {
        if (str_contains($one, '/ops/') || str_contains($one, '/tests/')) {
            continue;
        }
        staging_retarget_file($one, $app, $envFile, true);
    }
    $cron = $app . '/forecast_cron.php';
    if (is_file($cron)) {
        staging_retarget_file($cron, $app, $envFile, false);
    }
    $inside = $app . '/config/payroll.env';
    if (is_file($inside)) {
        unlink($inside);
    }
    echo "retargeted staging database settings\n";
}

function staging_qi(string $name, string $driver): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
        throw new RuntimeException('Unexpected column name.');
    }
    return $driver === 'sqlite' ? '"' . $name . '"' : '`' . $name . '`';
}

function staging_scrub_kind(string $column): ?string
{
    $c = strtolower($column);
    if (in_array($c, ['email', 'user_email', 'e_mail', 'owner_email', 'contact_email'], true)) {
        return 'email';
    }
    if (in_array($c, [
        'mobile', 'tel_mobile', 'phone', 'tel', 'cell', 'cellphone', 'mobile_phone',
        'cell_phone', 'phone_number', 'home_phone', 'work_phone', 'telephone', 'push_token',
    ], true)) {
        return 'phone';
    }
    if (in_array($c, ['sin', 'social_insurance', 'social_insurance_number', 'sin_number'], true)) {
        return 'sin';
    }
    if (in_array($c, [
        'bank_account', 'dd_account', 'dd_pending_account', 'account_number',
        'direct_deposit_file', 'direct_deposit_orig_name',
    ], true)) {
        return 'account';
    }
    if (in_array($c, ['bank_transit', 'dd_transit', 'dd_pending_transit', 'transit_number'], true)) {
        return 'transit';
    }
    return null;
}

/**
 * @param list<array{table:string,column:string,type:string,nullable:bool,has_id:bool}> $columns
 * @return array<string,int>
 */
function staging_scrub_apply(PDO $pdo, array $columns): array
{
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $counts = ['email' => 0, 'phone' => 0, 'sin' => 0, 'account' => 0, 'transit' => 0];
    foreach ($columns as $col) {
        $kind = staging_scrub_kind($col['column']);
        if ($kind === null) {
            continue;
        }
        $table = staging_qi($col['table'], $driver);
        $column = staging_qi($col['column'], $driver);
        if ($kind === 'email') {
            $prefix = strtolower($col['table']) === 'users' ? 'staff' : 'row';
            if (!empty($col['has_id'])) {
                $id = staging_qi('id', $driver);
                $expr = $driver === 'sqlite'
                    ? "'{$prefix}+' || {$id} || '@staging.invalid'"
                    : "CONCAT('{$prefix}+', {$id}, '@staging.invalid')";
            } else {
                $expr = "'{$prefix}@staging.invalid'";
            }
            $pdo->exec("UPDATE {$table} SET {$column} = {$expr}");
        } else {
            $type = strtolower($col['type']);
            $numeric = (bool)preg_match('/int|decimal|float|double|numeric|real/', $type);
            if ($numeric) {
                $expr = !empty($col['nullable']) ? 'NULL' : '0';
            } else {
                $expr = "''";
            }
            $pdo->exec("UPDATE {$table} SET {$column} = {$expr}");
        }
        $counts[$kind]++;
    }
    return $counts;
}

/**
 * @return list<array{table:string,column:string,type:string,nullable:bool,has_id:bool}>
 */
function staging_discover_columns(PDO $pdo): array
{
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
            ->fetchAll(PDO::FETCH_COLUMN);
        $out = [];
        foreach ($tables as $table) {
            if (!is_string($table) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
                continue;
            }
            $info = $pdo->query('PRAGMA table_info(' . staging_qi($table, 'sqlite') . ')')->fetchAll(PDO::FETCH_ASSOC);
            $hasId = false;
            foreach ($info as $row) {
                if (strtolower((string)$row['name']) === 'id') {
                    $hasId = true;
                }
            }
            foreach ($info as $row) {
                $out[] = [
                    'table' => $table,
                    'column' => (string)$row['name'],
                    'type' => strtolower((string)$row['type']),
                    'nullable' => (int)$row['notnull'] === 0,
                    'has_id' => $hasId,
                ];
            }
        }
        return $out;
    }
    $current = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($current !== STAGING_DB_NAME) {
        throw new RuntimeException('Refusing to read columns from ' . $current);
    }
    $sql = 'SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE, c.IS_NULLABLE,
                   EXISTS(
                     SELECT 1 FROM information_schema.COLUMNS i
                     WHERE i.TABLE_SCHEMA = c.TABLE_SCHEMA AND i.TABLE_NAME = c.TABLE_NAME AND i.COLUMN_NAME = \'id\'
                   ) AS has_id
            FROM information_schema.COLUMNS c
            INNER JOIN information_schema.TABLES t
              ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
            WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = \'BASE TABLE\'';
    $out = [];
    foreach ($pdo->query($sql) as $row) {
        $out[] = [
            'table' => (string)$row['TABLE_NAME'],
            'column' => (string)$row['COLUMN_NAME'],
            'type' => strtolower((string)$row['DATA_TYPE']),
            'nullable' => strtoupper((string)$row['IS_NULLABLE']) === 'YES',
            'has_id' => (int)$row['has_id'] === 1,
        ];
    }
    return $out;
}

function staging_scrub_verify(PDO $pdo): void
{
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    foreach (staging_discover_columns($pdo) as $col) {
        $kind = staging_scrub_kind($col['column']);
        if ($kind === null) {
            continue;
        }
        $table = staging_qi($col['table'], $driver);
        $column = staging_qi($col['column'], $driver);
        if ($kind === 'email') {
            $prefix = strtolower($col['table']) === 'users' ? 'staff+' : 'row+';
            if (empty($col['has_id'])) {
                $prefix = strtolower($col['table']) === 'users' ? 'staff@' : 'row@';
            }
            $like = $driver === 'sqlite'
                ? "{$column} NOT LIKE " . $pdo->quote($prefix . '%staging.invalid')
                : "{$column} NOT LIKE " . $pdo->quote($prefix . '%staging.invalid');
            $left = (int)$pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$column} IS NOT NULL AND {$column} <> '' AND {$like}")->fetchColumn();
            if ($left !== 0) {
                throw new RuntimeException('Scrub left a real email in place.');
            }
            continue;
        }
        $cast = $driver === 'sqlite' ? "CAST({$column} AS TEXT)" : "CAST({$column} AS CHAR)";
        $left = (int)$pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$column} IS NOT NULL AND {$cast} <> '' AND {$cast} <> '0'")->fetchColumn();
        if ($left !== 0) {
            throw new RuntimeException('Scrub left a ' . $kind . ' value in place.');
        }
    }
}

function staging_scrub(string $envFile): void
{
    $cfg = staging_read_env($envFile);
    staging_assert_staging_config($cfg);
    $hook = getenv('STAGING_SCRUB_DSN');
    if (is_string($hook) && str_starts_with($hook, 'sqlite:') && getenv('STAGING_ALLOW_NONROOT') === '1') {
        $pdo = new PDO($hook);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } else {
        $dsn = 'mysql:host=' . $cfg['DB_HOST'] . ';port=' . ($cfg['DB_PORT'] ?? '3306')
            . ';dbname=' . STAGING_DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, STAGING_DB_USER, $cfg['DB_PASS'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $actual = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($actual !== STAGING_DB_NAME) {
            staging_tool_fail('Scrub connected to the wrong database.');
        }
    }
    $counts = staging_scrub_apply($pdo, staging_discover_columns($pdo));
    staging_scrub_verify($pdo);
    echo 'scrub'
        . ' email_columns=' . $counts['email']
        . ' phone_columns=' . $counts['phone']
        . ' sin_columns=' . $counts['sin']
        . ' account_columns=' . $counts['account']
        . ' transit_columns=' . $counts['transit']
        . "\n";
}

function staging_htaccess_block(string $htpass, string $envFile): string
{
    $htpass = str_replace(["\n", "\r"], '', $htpass);
    $envFile = str_replace(["\n", "\r"], '', $envFile);
    return "# BEGIN payroll-staging-guard\n"
        . "Options -Indexes\n"
        . "SetEnv STAGING 1\n"
        . "SetEnv PAYROLL_ENV_FILE {$envFile}\n"
        . "<IfModule mod_headers.c>\n"
        . "  Header set X-Robots-Tag \"noindex, nofollow, noarchive\"\n"
        . "</IfModule>\n"
        . "AuthType Basic\n"
        . "AuthName \"Payroll staging\"\n"
        . "AuthUserFile {$htpass}\n"
        . "<IfModule mod_authz_core.c>\n"
        . "  <RequireAny>\n"
        . "    Require expr %{REQUEST_URI} =~ m#^/\\.well-known/acme-challenge/#\n"
        . "    Require valid-user\n"
        . "  </RequireAny>\n"
        . "</IfModule>\n"
        . "<FilesMatch \"^(\\.staging|\\.user\\.ini)$\">\n"
        . "  Require all denied\n"
        . "</FilesMatch>\n"
        . "# END payroll-staging-guard\n";
}

function staging_protect(string $app, string $htpass, string $envFile): void
{
    if (!staging_path_is_copy($app)) {
        staging_tool_fail('Refusing to write protection outside the staging docroot.');
    }
    file_put_contents($app . '/.staging', "1\n");
    chmod($app . '/.staging', 0644);
    $prepend = $app . '/engine/staging_prepend.php';
    file_put_contents($app . '/.user.ini', 'auto_prepend_file=' . $prepend . "\n");
    chmod($app . '/.user.ini', 0644);
    file_put_contents($app . '/robots.txt', "User-agent: *\nDisallow: /\n");
    chmod($app . '/robots.txt', 0644);
    $ht = $app . '/.htaccess';
    $existing = is_file($ht) ? (string)file_get_contents($ht) : '';
    $existing = preg_replace('/# BEGIN payroll-staging-guard.*?# END payroll-staging-guard\n?/s', '', $existing) ?? $existing;
    file_put_contents($ht, staging_htaccess_block($htpass, $envFile) . ltrim($existing));
    chmod($ht, 0644);
    echo "protected staging docroot\n";
}

function staging_uapi_ok(string $jsonFile): void
{
    $raw = (string)file_get_contents($jsonFile);
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        staging_tool_fail('cPanel uapi did not return JSON.');
    }
    $status = $json['result']['status'] ?? $json['status'] ?? null;
    if ((int)$status === 1) {
        echo "uapi ok\n";
        return;
    }
    $errors = $json['result']['errors'] ?? [];
    $text = strtolower(json_encode($errors) ?: '');
    if (str_contains($text, 'does not exist') || str_contains($text, 'could not find')) {
        echo "uapi already gone\n";
        exit(0);
    }
    staging_tool_fail('cPanel uapi failed.');
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $cmd = $argv[1] ?? '';
    try {
        switch ($cmd) {
            case 'secrets':
                staging_write_secrets($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '');
                break;
            case 'ensure-db':
                staging_ensure_db($argv[2] ?? '');
                break;
            case 'recreate-db':
                staging_recreate_db();
                break;
            case 'drop-db':
                staging_drop_db($argv[2] ?? '');
                break;
            case 'retarget':
                staging_retarget_tree($argv[2] ?? '', $argv[3] ?? '');
                break;
            case 'scrub':
                staging_scrub($argv[2] ?? '');
                break;
            case 'protect':
                staging_protect($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '');
                break;
            case 'uapi-ok':
                staging_uapi_ok($argv[2] ?? '');
                break;
            default:
                staging_tool_fail('Usage: php ops/staging_tool.php secrets|ensure-db|recreate-db|drop-db|retarget|scrub|protect|uapi-ok');
        }
    } catch (Throwable $e) {
        staging_tool_fail($e->getMessage());
    }
}
