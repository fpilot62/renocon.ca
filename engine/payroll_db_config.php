<?php
/**
 * Database settings for scripts that previously kept a password in source.
 * The live values belong in config/payroll.env, which is not committed.
 * Rotate any password that was stored in daily_sync.php.
 */

const PAYROLL_DB_PASS_PLACEHOLDER = 'replace-with-rotated-password';

function payroll_parse_env(string $text): array
{
    $out = [];
    foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        if ($key === '' || !preg_match('/^[A-Z0-9_]+$/', $key)) {
            continue;
        }
        if (strlen($val) >= 2) {
            $quote = $val[0];
            if (($quote === '"' || $quote === "'") && str_ends_with($val, $quote)) {
                $val = substr($val, 1, -1);
            }
        }
        $out[$key] = $val;
    }
    return $out;
}

function payroll_db_config(string $path): array
{
    if (basename($path) === 'payroll.env.example') {
        throw new RuntimeException('Refusing to load the example config. Copy it to config/payroll.env.');
    }
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Database config file is missing. Copy config/payroll.env.example to config/payroll.env.');
    }
    $cfg = payroll_parse_env((string)file_get_contents($path));
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
        if (!isset($cfg[$key]) || trim((string)$cfg[$key]) === '') {
            throw new RuntimeException('Database config is missing ' . $key . '.');
        }
    }
    if ((string)$cfg['DB_PASS'] === PAYROLL_DB_PASS_PLACEHOLDER) {
        throw new RuntimeException('Database config still has the example password. Put the rotated password in config/payroll.env.');
    }
    if (!isset($cfg['DB_PORT']) || trim((string)$cfg['DB_PORT']) === '') {
        $cfg['DB_PORT'] = '3306';
    }
    return $cfg;
}

function payroll_pdo(array $cfg): PDO
{
    $dsn = 'mysql:host=' . $cfg['DB_HOST']
        . ';port=' . $cfg['DB_PORT']
        . ';dbname=' . $cfg['DB_NAME']
        . ';charset=utf8mb4';
    return new PDO($dsn, (string)$cfg['DB_USER'], (string)$cfg['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
