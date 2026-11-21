<?php
/**
 * Loaded only from the staging docroot (.user.ini auto_prepend_file).
 * Live does not reference this file. The marker makes CLI jobs on this
 * tree take the same path, because PHP-FPM is the only SAPI that reads .user.ini.
 */
if (PHP_SAPI === 'cli') {
    return;
}
require_once __DIR__ . '/staging.php';
$stagingRoot = dirname(__DIR__);
if (is_file($stagingRoot . '/.staging')) {
    putenv('STAGING=1');
    $_ENV['STAGING'] = '1';
    $_SERVER['STAGING'] = '1';
    $envFile = '/home/renocon/private/payroll-staging.env';
    if (is_file($envFile)) {
        putenv('PAYROLL_ENV_FILE=' . $envFile);
        $_ENV['PAYROLL_ENV_FILE'] = $envFile;
    }
    payroll_staging_reset();
}
payroll_staging_arm();
