<?php
/**
 * AlarmPulse PWA tags for pages in this folder (index.php on both hosts).
 * Asset URLs follow the request path: /alarm/... on payroll.renocon.ca and
 * /... on alarmpulse.ecommi.com. A missing helper falls back to paths
 * relative to the page.
 */
foreach ([__DIR__ . '/alarm_public_url.php', __DIR__ . '/api/alarm_public_url.php'] as $alarmpulseUrlFile) {
  if (is_file($alarmpulseUrlFile)) {
    require_once $alarmpulseUrlFile;
    break;
  }
}
if (function_exists('alarmpulse_asset')) {
  $alarmpulseManifest = alarmpulse_asset('manifest.webmanifest');
  $alarmpulseApple = alarmpulse_asset('pwa/icons/apple-touch-icon.png');
  $alarmpulseIcon = alarmpulse_asset('pwa/icons/icon-192.png');
  $alarmpulseSw = alarmpulse_asset('sw.js');
  $alarmpulseScope = alarmpulse_web_scope();
} else {
  $alarmpulseManifest = 'manifest.webmanifest';
  $alarmpulseApple = 'pwa/icons/apple-touch-icon.png';
  $alarmpulseIcon = 'pwa/icons/icon-192.png';
  $alarmpulseSw = 'sw.js';
  $alarmpulseScope = './';
}
?>
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#dc2626">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="AlarmPulse">
<link rel="manifest" href="<?php echo htmlspecialchars($alarmpulseManifest, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="apple-touch-icon" href="<?php echo htmlspecialchars($alarmpulseApple, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?php echo htmlspecialchars($alarmpulseIcon, ENT_QUOTES, 'UTF-8'); ?>">
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register(<?php echo json_encode($alarmpulseSw, JSON_UNESCAPED_SLASHES); ?>, { scope: <?php echo json_encode($alarmpulseScope, JSON_UNESCAPED_SLASHES); ?> }).catch(function () {});
  });
}
</script>
