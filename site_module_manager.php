<?php
/**
 * Path: /site_module_manager.php
 *
 * This file was a copy of an older GPS editor (its own comment said
 * Path: /site_manager.php). It wrote sites.lat and sites.lon and showed
 * sites.module as tags. It had no login or role check: only session_start.
 * That session line is kept. Site Accuracy & Geofence (site_manager.php)
 * is the page that edits the geofence, and it refuses user_role above 25.
 *
 * Bookmarks keep their query string, including org_id.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$qs = '';
if (!empty($_SERVER['QUERY_STRING'])) {
    $qs = '?' . $_SERVER['QUERY_STRING'];
}
header('Location: site_manager.php' . $qs, true, 302);
exit;
