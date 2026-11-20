<?php
/**
 * Path: /api/settings.php
 * Method: GET  → fetch org + site policies
 * Method: POST → update a policy setting
 * Headers: Authorization: Bearer <token>  (admin only: role <= 10)
 *
 * GET Response: { org_settings: {...}, sites: [ { site_id, name, settings: {...} } ] }
 *
 * POST Body (org-level):
 *   { "scope": "org", "key": "global_geo_policy", "value": "block" }
 *
 * POST Body (site-level):
 *   { "scope": "site", "site_id": 5, "key": "site_geo_policy", "value": "allow_reason" }
 *
 * Valid policy keys & values:
 *   global_geo_policy / site_geo_policy        → allow_free | allow_reason | block
 *   global_schedule_policy / site_schedule_policy → allow_free | allow_reason | block
 *   global_geo_tolerance_pct / site_geo_tolerance_pct → numeric (e.g. 1.5 = 1.5%)
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../db.php';
require_once 'jwt_helper.php';

$auth   = require_auth();
$uid    = (int)$auth['user_id'];
$org_id = (int)$auth['org_id'];
$role   = (int)$auth['role'];

if ($role > 10) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

$ORG_KEYS  = ['global_geo_policy', 'global_schedule_policy', 'global_geo_tolerance_pct'];
$SITE_KEYS = ['site_geo_policy',   'site_schedule_policy',   'site_geo_tolerance_pct'];
$VALID_POLICIES = ['allow_free', 'allow_reason', 'block'];

try {

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // ---- Org-level settings ----
        $gemStmt = $pdo->prepare("SELECT setting_key, setting_value FROM Gem_Settings WHERE org_id = ?");
        $gemStmt->execute([$org_id]);
        $org_settings_raw = $gemStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Defaults if not set
        $org_settings = [
            'global_geo_policy'        => $org_settings_raw['global_geo_policy']        ?? 'allow_reason',
            'global_schedule_policy'   => $org_settings_raw['global_schedule_policy']   ?? 'allow_reason',
            'global_geo_tolerance_pct' => $org_settings_raw['global_geo_tolerance_pct'] ?? '1.00',
        ];

        // ---- All sites for this org with their overrides ----
        $siteStmt = $pdo->prepare("SELECT Sid, nick_name FROM sites WHERE org_id = ? ORDER BY nick_name ASC");
        $siteStmt->execute([$org_id]);
        $sites = $siteStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sites as &$site) {
            $ssStmt = $pdo->prepare("SELECT setting_key, setting_value FROM Site_Settings WHERE site_id = ?");
            $ssStmt->execute([$site['Sid']]);
            $raw = $ssStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $site['settings'] = [
                'site_geo_policy'        => $raw['site_geo_policy']        ?? null,
                'site_schedule_policy'   => $raw['site_schedule_policy']   ?? null,
                'site_geo_tolerance_pct' => $raw['site_geo_tolerance_pct'] ?? null,
            ];
        }

        echo json_encode(['success' => true, 'org_settings' => $org_settings, 'sites' => $sites]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body  = json_decode(file_get_contents('php://input'), true);
        $scope = trim($body['scope'] ?? '');
        $key   = trim($body['key']   ?? '');
        $value = trim($body['value'] ?? '');

        if ($scope === 'org') {
            if (!in_array($key, $ORG_KEYS)) {
                echo json_encode(['success' => false, 'error' => 'Invalid org setting key']);
                exit;
            }
            if (in_array($key, ['global_geo_policy','global_schedule_policy']) && !in_array($value, $VALID_POLICIES)) {
                echo json_encode(['success' => false, 'error' => 'Invalid policy value. Use: allow_free, allow_reason, block']);
                exit;
            }
            // Upsert into Gem_Settings
            $check = $pdo->prepare("SELECT id FROM Gem_Settings WHERE org_id = ? AND setting_key = ?");
            $check->execute([$org_id, $key]);
            if ($check->fetch()) {
                $upd = $pdo->prepare("UPDATE Gem_Settings SET setting_value = ? WHERE org_id = ? AND setting_key = ?");
                $upd->execute([$value, $org_id, $key]);
            } else {
                $ins = $pdo->prepare("INSERT INTO Gem_Settings (org_id, setting_key, setting_value) VALUES (?, ?, ?)");
                $ins->execute([$org_id, $key, $value]);
            }
            echo json_encode(['success' => true, 'message' => "Org setting '$key' updated to '$value'"]);

        } elseif ($scope === 'site') {
            $target_site_id = (int)($body['site_id'] ?? 0);
            if ($target_site_id === 0) {
                echo json_encode(['success' => false, 'error' => 'site_id required for site scope']);
                exit;
            }
            if (!in_array($key, $SITE_KEYS)) {
                echo json_encode(['success' => false, 'error' => 'Invalid site setting key']);
                exit;
            }
            if (in_array($key, ['site_geo_policy','site_schedule_policy']) && !in_array($value, $VALID_POLICIES)) {
                echo json_encode(['success' => false, 'error' => 'Invalid policy value. Use: allow_free, allow_reason, block']);
                exit;
            }
            // Upsert into Site_Settings
            $check = $pdo->prepare("SELECT id FROM Site_Settings WHERE site_id = ? AND setting_key = ?");
            $check->execute([$target_site_id, $key]);
            if ($check->fetch()) {
                $upd = $pdo->prepare("UPDATE Site_Settings SET setting_value = ? WHERE site_id = ? AND setting_key = ?");
                $upd->execute([$value, $target_site_id, $key]);
            } else {
                $ins = $pdo->prepare("INSERT INTO Site_Settings (site_id, setting_key, setting_value) VALUES (?, ?, ?)");
                $ins->execute([$target_site_id, $key, $value]);
            }
            echo json_encode(['success' => true, 'message' => "Site $target_site_id setting '$key' updated to '$value'"]);

        } else {
            echo json_encode(['success' => false, 'error' => 'scope must be org or site']);
        }
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
