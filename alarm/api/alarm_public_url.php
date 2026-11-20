<?php
/**
 * Public AlarmPulse URLs.
 *
 * One base, overridable without a code change:
 *   ALARMPULSE_BASE_URL    default https://alarmpulse.ecommi.com
 *   ALARMPULSE_SIGNUP_URL  default {base}/signup.html
 *
 * https://alarmpulse.ecommi.com/api/X.php is the same file as
 * https://payroll.renocon.ca/alarm/api/X.php. Installed builds keep calling
 * the payroll host. Leave that vhost and path in place.
 *
 * config.php, forgot.php, and index.php live on the server and are not in
 * this repo (config.php holds credentials; do not commit or replace it).
 * After this file is in the api directory, point those three at the helpers:
 *
 *   require_once __DIR__ . '/alarm_public_url.php';
 *   define('UPLOAD_URL', alarmpulse_upload_url());
 *
 *   // forgot.php reset link, and index.php accept-task link:
 *   $link = alarmpulse_page_url('index.php?token=' . rawurlencode($token));
 *
 * Keep the query keys those scripts already use. Only the host and the
 * /alarm prefix change: the branded host serves this folder at /.
 */

function alarmpulse_env(string $name): string {
  $value = getenv($name);
  return is_string($value) ? trim($value) : '';
}

function alarmpulse_base_url(): string {
  $url = alarmpulse_env('ALARMPULSE_BASE_URL');
  if ($url === '') $url = 'https://alarmpulse.ecommi.com';
  return rtrim($url, '/');
}

function alarmpulse_signup_url(): string {
  $url = alarmpulse_env('ALARMPULSE_SIGNUP_URL');
  if ($url !== '') return $url;
  return alarmpulse_base_url() . '/signup.html';
}

function alarmpulse_api_url(): string {
  $base = alarmpulse_base_url();
  if (preg_match('#/api$#', $base)) return $base;
  return $base . '/api';
}

function alarmpulse_upload_url(): string {
  return alarmpulse_base_url() . '/uploads/';
}

function alarmpulse_page_url(string $path): string {
  return alarmpulse_base_url() . '/' . ltrim($path, '/');
}

/**
 * URL path of this alarm folder for the current request.
 * /alarm on payroll.renocon.ca, empty on alarmpulse.ecommi.com.
 */
function alarmpulse_web_prefix(): string {
  $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
  if ($script === '' || $script === '/') return '';
  $dir = str_replace('\\', '/', dirname($script));
  if ($dir === '/' || $dir === '.' || $dir === '') return '';
  if (str_ends_with($dir, '/api')) $dir = substr($dir, 0, -4);
  elseif (str_ends_with($dir, '/admin')) $dir = substr($dir, 0, -6);
  if ($dir === '/' || $dir === '') return '';
  return $dir;
}

function alarmpulse_web_scope(): string {
  $prefix = alarmpulse_web_prefix();
  return $prefix === '' ? '/' : $prefix . '/';
}

function alarmpulse_asset(string $path): string {
  $path = ltrim($path, '/');
  $prefix = alarmpulse_web_prefix();
  if ($prefix === '') return '/' . $path;
  return $prefix . '/' . $path;
}

function alarmpulse_public_url_selftest(): void {
  $saved = [];
  foreach (['ALARMPULSE_BASE_URL', 'ALARMPULSE_SIGNUP_URL'] as $name) {
    $current = getenv($name);
    $saved[$name] = is_string($current) ? $current : null;
    putenv($name);
  }
  $script = $_SERVER['SCRIPT_NAME'] ?? null;
  $fail = function (string $message): void {
    fwrite(STDERR, "alarm_public_url selftest failed: {$message}\n");
    exit(1);
  };

  if (alarmpulse_base_url() !== 'https://alarmpulse.ecommi.com') $fail('default base');
  if (alarmpulse_signup_url() !== 'https://alarmpulse.ecommi.com/signup.html') $fail('default signup');
  if (alarmpulse_api_url() !== 'https://alarmpulse.ecommi.com/api') $fail('default api');
  if (alarmpulse_upload_url() !== 'https://alarmpulse.ecommi.com/uploads/') $fail('default upload');
  if (alarmpulse_page_url('index.php?accept=1') !== 'https://alarmpulse.ecommi.com/index.php?accept=1') $fail('page url');

  putenv('ALARMPULSE_BASE_URL=https://payroll.renocon.ca/alarm/');
  if (alarmpulse_base_url() !== 'https://payroll.renocon.ca/alarm') $fail('env base keeps the payroll mount');
  if (alarmpulse_signup_url() !== 'https://payroll.renocon.ca/alarm/signup.html') $fail('env base builds the old signup url');
  if (alarmpulse_upload_url() !== 'https://payroll.renocon.ca/alarm/uploads/') $fail('env base builds the old upload url');
  if (alarmpulse_api_url() !== 'https://payroll.renocon.ca/alarm/api') $fail('env base builds the old api url');
  putenv('ALARMPULSE_SIGNUP_URL=https://example.test/join');
  if (alarmpulse_signup_url() !== 'https://example.test/join') $fail('signup env wins');

  $_SERVER['SCRIPT_NAME'] = '/alarm/index.php';
  if (alarmpulse_web_prefix() !== '/alarm' || alarmpulse_asset('sw.js') !== '/alarm/sw.js' || alarmpulse_web_scope() !== '/alarm/') {
    $fail('payroll pages stay under /alarm');
  }
  $_SERVER['SCRIPT_NAME'] = '/index.php';
  if (alarmpulse_web_prefix() !== '' || alarmpulse_asset('manifest.webmanifest') !== '/manifest.webmanifest' || alarmpulse_web_scope() !== '/') {
    $fail('branded host pages are at /');
  }
  $_SERVER['SCRIPT_NAME'] = '/alarm/api/forgot.php';
  if (alarmpulse_web_prefix() !== '/alarm') $fail('payroll api script');
  $_SERVER['SCRIPT_NAME'] = '/api/forgot.php';
  if (alarmpulse_web_prefix() !== '') $fail('branded api script');
  $_SERVER['SCRIPT_NAME'] = '/alarm/admin/index.php';
  if (alarmpulse_web_prefix() !== '/alarm') $fail('payroll admin script');

  $_SERVER['SCRIPT_NAME'] = '/alarm/index.php';
  ob_start();
  include __DIR__ . '/pwa_head.php';
  $payrollHead = ob_get_clean();
  if (strpos($payrollHead, '"/alarm/sw.js"') === false || strpos($payrollHead, '"/alarm/"') === false) $fail('pwa head on payroll');
  if (strpos($payrollHead, 'href="/alarm/manifest.webmanifest"') === false) $fail('pwa manifest on payroll');

  $_SERVER['SCRIPT_NAME'] = '/index.php';
  ob_start();
  include __DIR__ . '/pwa_head.php';
  $brandedHead = ob_get_clean();
  if (strpos($brandedHead, '"/sw.js"') === false || strpos($brandedHead, 'href="/manifest.webmanifest"') === false) $fail('pwa head on branded host');
  if (strpos($brandedHead, '/alarm/') !== false) $fail('branded pwa head must not use /alarm/');

  foreach ($saved as $name => $value) {
    if ($value === null) putenv($name);
    else putenv($name . '=' . $value);
  }
  if ($script === null) unset($_SERVER['SCRIPT_NAME']);
  else $_SERVER['SCRIPT_NAME'] = $script;

  echo "ok\n";
  exit(0);
}

if (PHP_SAPI === 'cli' && isset($argv) && in_array('--selftest', $argv, true) && isset($argv[0]) && realpath((string)$argv[0]) === __FILE__) {
  alarmpulse_public_url_selftest();
}
