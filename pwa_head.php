<?php
/** Path: /pwa_head.php — include inside <head> on payroll pages */
?>
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0891b2">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Payroll">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="apple-touch-icon" href="/pwa/icons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="192x192" href="/pwa/icons/icon-192.png">
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/sw.js?v=20260919', { scope: '/' }).catch(function () {});
  });
}
</script>
