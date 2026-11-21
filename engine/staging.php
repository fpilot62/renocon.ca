<?php
/**
 * Staging switch for the payroll copy on staging.payroll.ecommi.com.
 *
 * Live stays quiet: nothing here runs unless STAGING=1, the docroot has a
 * .staging marker, or the private env file is the staging database.
 * Mail, SMS, push, bank files, and the direct-deposit download all check
 * payroll_staging_outbound_blocked() before they leave the server.
 */

function payroll_staging_reset(): void
{
    unset($GLOBALS['PAYROLL_STAGING_CACHED'], $GLOBALS['PAYROLL_STAGING_VALUE']);
}

function payroll_staging_flag_on(string $value): bool
{
    return (bool)preg_match('/^(1|true|yes|on)$/i', trim($value));
}

function payroll_staging_env_file_is_on(string $path): bool
{
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return false;
    }
    $cfg = [];
    if (function_exists('payroll_parse_env')) {
        $cfg = payroll_parse_env((string)file_get_contents($path));
    } else {
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
    }
    if (!payroll_staging_flag_on((string)($cfg['STAGING'] ?? ''))) {
        return false;
    }
    // A STAGING flag sitting on the live database must not turn live off.
    return (string)($cfg['DB_NAME'] ?? '') !== 'admin_payroll';
}

function payroll_is_staging(): bool
{
    if (!empty($GLOBALS['PAYROLL_STAGING_CACHED'])) {
        return (bool)$GLOBALS['PAYROLL_STAGING_VALUE'];
    }
    $on = false;
    $flag = getenv('STAGING');
    if (is_string($flag) && payroll_staging_flag_on($flag)) {
        $on = true;
    }
    $root = dirname(__DIR__);
    if (!$on && is_file($root . '/.staging')) {
        $on = true;
    }
    if (!$on) {
        $paths = [];
        $explicit = getenv('PAYROLL_ENV_FILE');
        if (is_string($explicit) && $explicit !== '') {
            $paths[] = $explicit;
        }
        $paths[] = '/home/renocon/private/payroll-staging.env';
        foreach ($paths as $path) {
            if (payroll_staging_env_file_is_on($path)) {
                $on = true;
                break;
            }
        }
    }
    $GLOBALS['PAYROLL_STAGING_CACHED'] = true;
    $GLOBALS['PAYROLL_STAGING_VALUE'] = $on;
    return $on;
}

/**
 * Read one value from the staging env file. db.php on the staging copy calls
 * this so the live password is not left in the docroot.
 */
function staging_db_secret(string $key): string
{
    $path = getenv('PAYROLL_ENV_FILE');
    if (!is_string($path) || $path === '') {
        $path = '/home/renocon/private/payroll-staging.env';
    }
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $text = (string)file_get_contents($path);
    foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $val] = explode('=', $line, 2);
        if (trim($k) !== $key) {
            continue;
        }
        $val = trim($val);
        if (strlen($val) >= 2) {
            $quote = $val[0];
            if (($quote === '"' || $quote === "'") && str_ends_with($val, $quote)) {
                $val = substr($val, 1, -1);
            }
        }
        return $val;
    }
    return '';
}

function payroll_staging_outbound_blocked(string $channel): bool
{
    if (!payroll_is_staging()) {
        return false;
    }
    $channel = preg_replace('/[^a-z]/', '', strtolower($channel)) ?? 'outbound';
    if ($channel === '') {
        $channel = 'outbound';
    }
    error_log('staging blocked ' . $channel);
    return true;
}

function payroll_staging_banner_html(): string
{
    return '<div id="payroll-staging-banner" role="banner" style="position:sticky;top:0;left:0;right:0;z-index:2147483647;background:#b91c1c;color:#ffffff;text-align:center;font:700 18px/1.3 Arial,sans-serif;padding:10px 12px;letter-spacing:.16em;">STAGING'
        . '<div style="letter-spacing:0;font:600 13px/1.4 Arial,sans-serif;margin-top:2px;">Not live payroll. Mail, SMS, payments, and bank files are off.</div></div>';
}

function payroll_staging_inject_html(string $html): string
{
    if ($html === '' || !payroll_is_staging() || str_contains($html, 'id="payroll-staging-banner"')) {
        return $html;
    }
    $trim = ltrim($html);
    if ($trim === '') {
        return $html;
    }
    $first = $trim[0];
    if ($first === '{' || $first === '[') {
        return $html;
    }
    if (strncmp($trim, '%PDF', 4) === 0 || strncmp($trim, 'PK', 2) === 0) {
        return $html;
    }
    $banner = payroll_staging_banner_html();
    $meta = '<meta name="robots" content="noindex, nofollow">';
    if (preg_match('/<head\b[^>]*>/i', $html)) {
        $html = preg_replace('/<head\b[^>]*>/i', '$0' . $meta, $html, 1) ?? $html;
    }
    if (preg_match('/<body\b[^>]*>/i', $html)) {
        return preg_replace('/<body\b[^>]*>/i', '$0' . $banner, $html, 1) ?? ($banner . $html);
    }
    if (preg_match('/<html\b[^>]*>/i', $html)) {
        return preg_replace('/<html\b[^>]*>/i', '$0' . $banner, $html, 1) ?? ($banner . $html);
    }
    if (preg_match('/<(div|p|table|h1|h2|form|br|span|a|ul|section|!DOCTYPE)\b/i', $html)) {
        return $banner . $html;
    }
    return $html;
}

function payroll_staging_response_is_html(): bool
{
    foreach (headers_list() as $header) {
        if (stripos($header, 'content-disposition:') === 0 && stripos($header, 'attachment') !== false) {
            return false;
        }
        if (preg_match('#^content-type:\s*([^;\s]+)#i', $header, $match)) {
            $type = strtolower($match[1]);
            if ($type !== 'text/html' && $type !== 'application/xhtml+xml') {
                return false;
            }
        }
    }
    return true;
}

function payroll_staging_arm(): void
{
    if (PHP_SAPI === 'cli' || !payroll_is_staging()) {
        return;
    }
    if (!headers_sent()) {
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
    }
    ob_start(static function (string $html): string {
        if (!payroll_staging_response_is_html()) {
            return $html;
        }
        return payroll_staging_inject_html($html);
    });
}
