<?php
/**
 * Encrypt existing bank-account and SIN values in place.
 *
 * Does not run from a web request and does not run unless you pass --apply.
 * A dry run only prints counts. It never prints a bank account or a SIN.
 *
 *   php ops/encrypt_sensitive_columns.php
 *   php ops/encrypt_sensitive_columns.php --apply
 *
 * Requires PAYROLL_DATA_KEY in the private env file outside the web root.
 * Take a database backup first. Keep a copy of the key. Without the key the
 * values cannot be read back.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../engine/privacy_lib.php';

function privacy_encrypt_usage(): void
{
    fwrite(STDOUT, "Usage: php ops/encrypt_sensitive_columns.php [--apply]\n");
    fwrite(STDOUT, "Default is a dry run. --apply encrypts plaintext values and can widen short columns to VARCHAR(512).\n");
}

/**
 * @return array{0:bool,1:?string}
 */
function privacy_encrypt_args(array $argv): array
{
    $apply = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--apply') {
            $apply = true;
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            return [false, 'help'];
        }
        return [false, 'Unknown argument.'];
    }
    return [$apply, null];
}

function privacy_encrypt_quote(string $ident): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $ident)) {
        throw new RuntimeException('Unexpected column name.');
    }
    return '`' . $ident . '`';
}

/**
 * @return list<array{column:string,kind:string,type:string,length:?int}>
 */
function privacy_encrypt_live_columns(PDO $pdo, string $table): array
{
    $wanted = privacy_sensitive_column_map()[$table] ?? [];
    $st = $pdo->prepare(
        'SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $st->execute([$table]);
    $live = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = (string)$row['COLUMN_NAME'];
        foreach ($wanted as $candidate => $kind) {
            if (strcasecmp($name, $candidate) === 0) {
                $live[] = [
                    'column' => $name,
                    'kind' => $kind,
                    'type' => strtolower((string)$row['DATA_TYPE']),
                    'length' => $row['CHARACTER_MAXIMUM_LENGTH'] !== null ? (int)$row['CHARACTER_MAXIMUM_LENGTH'] : null,
                ];
            }
        }
    }
    return $live;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    [$apply, $error] = privacy_encrypt_args($argv ?? []);
    if ($error === 'help') {
        privacy_encrypt_usage();
        exit(0);
    }
    if ($error !== null) {
        fwrite(STDERR, $error . "\n");
        privacy_encrypt_usage();
        exit(2);
    }
    try {
        $key = privacy_data_key();
        privacy_key_bytes($key);
        $cfg = payroll_db_config(payroll_resolve_env_file(dirname(__DIR__)));
        $pdo = payroll_pdo($cfg);
    } catch (Throwable $e) {
        fwrite(STDERR, "Stopped before any change: " . $e->getMessage() . "\n");
        exit(1);
    }
    $changed = 0;
    foreach (array_keys(privacy_sensitive_column_map()) as $table) {
        if (!privacy_table_exists($pdo, $table) || !privacy_column_exists($pdo, $table, 'id')) {
            fwrite(STDOUT, $table . " skipped (table or id column not found)\n");
            continue;
        }
        foreach (privacy_encrypt_live_columns($pdo, $table) as $col) {
            $quoted = privacy_encrypt_quote($col['column']);
            $countSql = 'SELECT
                SUM(CASE WHEN ' . $quoted . ' IS NULL OR ' . $quoted . " = '' THEN 1 ELSE 0 END) AS empty_n,
                SUM(CASE WHEN " . $quoted . " LIKE 'enc:v1:%' THEN 1 ELSE 0 END) AS enc_n,
                SUM(CASE WHEN " . $quoted . " IS NOT NULL AND " . $quoted . " <> '' AND " . $quoted . " NOT LIKE 'enc:v1:%' THEN 1 ELSE 0 END) AS plain_n
                FROM " . privacy_encrypt_quote($table);
            $counts = $pdo->query($countSql)->fetch(PDO::FETCH_ASSOC) ?: [];
            $plain = (int)($counts['plain_n'] ?? 0);
            $enc = (int)($counts['enc_n'] ?? 0);
            $empty = (int)($counts['empty_n'] ?? 0);
            $widen = $col['length'] !== null && $col['length'] < 512 && !in_array($col['type'], ['text', 'mediumtext', 'longtext', 'blob', 'mediumblob', 'longblob'], true);
            fwrite(STDOUT, $table . '.' . $col['column'] . ' plaintext=' . $plain . ' already_encrypted=' . $enc . ' empty=' . $empty);
            if ($widen) {
                fwrite(STDOUT, ' widen_to_varchar_512=yes');
            }
            fwrite(STDOUT, "\n");
            if (!$apply || ($plain === 0 && !$widen)) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                if ($widen) {
                    $pdo->exec('ALTER TABLE ' . privacy_encrypt_quote($table) . ' MODIFY ' . $quoted . ' VARCHAR(512) NULL');
                    fwrite(STDOUT, $table . '.' . $col['column'] . " widened to VARCHAR(512)\n");
                }
                $sel = $pdo->query('SELECT id, ' . $quoted . ' AS secret_value FROM ' . privacy_encrypt_quote($table) . ' WHERE ' . $quoted . " IS NOT NULL AND " . $quoted . " <> '' AND " . $quoted . " NOT LIKE 'enc:v1:%'");
                $upd = $pdo->prepare('UPDATE ' . privacy_encrypt_quote($table) . ' SET ' . $quoted . ' = ? WHERE id = ?');
                $n = 0;
                while ($row = $sel->fetch(PDO::FETCH_ASSOC)) {
                    $stored = privacy_encrypt((string)$row['secret_value'], $key);
                    $upd->execute([$stored, (int)$row['id']]);
                    $n++;
                }
                $pdo->commit();
                $changed += $n;
                fwrite(STDOUT, $table . '.' . $col['column'] . ' encrypted=' . $n . "\n");
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                fwrite(STDERR, 'Stopped on ' . $table . '.' . $col['column'] . '. That column was rolled back. ' . $e->getMessage() . "\n");
                exit(1);
            }
        }
    }
    if ($apply) {
        fwrite(STDOUT, 'apply finished. values_encrypted=' . $changed . "\n");
    } else {
        fwrite(STDOUT, "dry-run: no rows were changed\n");
    }
}
