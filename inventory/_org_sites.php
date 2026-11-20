<?php
/** Shared inventory org + site picker. Superadmin picks org; others see their org only.
 * rid=0 is All Sites (filter only). Never treat 0 as a restaurant.
 * Marker: inv-all-sites-20261016
 */
if (!function_exists('sip_org_site_fence')) {
    require_once dirname(__DIR__) . '/engine/site_status_lib.php';
}
$__fence = sip_org_site_fence($pdo, 'sip_org_id');
$is_super = $__fence['is_super'];
$selected_org = $__fence['selected_org'];
$orgs = $__fence['orgs'];
$sites = [];
foreach ($__fence['sites'] as $s) {
    $id = (int)($s['SId'] ?? $s['id'] ?? $s['Sid'] ?? 0);
    $sites[] = [
        'SId' => $id,
        'id' => $id,
        'Sid' => $id,
        'nick_name' => (string)($s['nick_name'] ?? ''),
        'org_id' => (int)($s['org_id'] ?? 0),
    ];
}
$allowed_ids = $__fence['allowed_ids'];

$rid_in = null;
if (array_key_exists('rid', $_GET)) {
    $rid_in = $_GET['rid'];
} elseif (array_key_exists('rid', $_POST)) {
    $rid_in = $_POST['rid'];
} elseif (array_key_exists('site_id', $_POST)) {
    $rid_in = $_POST['site_id'];
}
if ($rid_in === null) {
    $rid = (int)($sites[0]['id'] ?? 0);
} else {
    $rid = (int)$rid_in;
    if ($rid > 0 && $allowed_ids && !in_array($rid, $allowed_ids, true)) {
        $rid = (int)($sites[0]['id'] ?? 0);
    }
}

$inv_org_q = ((int)($selected_org ?? 0) > 0) ? ('&org_id=' . (int)$selected_org) : '';

if (!function_exists('inv_all_sites')) {
    function inv_all_sites(): bool {
        global $rid;
        return (int)$rid <= 0;
    }
}

if (!function_exists('inv_query_site_ids')) {
    function inv_query_site_ids(): array {
        global $rid, $allowed_ids, $sites;
        if ((int)$rid > 0) {
            return [(int)$rid];
        }
        $ids = [];
        foreach ((array)$allowed_ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if (!$ids) {
            foreach ((array)$sites as $s) {
                $id = (int)($s['id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        return array_values(array_unique($ids));
    }
}

if (!function_exists('inv_site_nick')) {
    function inv_site_nick(int $siteId): string {
        global $sites;
        foreach ((array)$sites as $s) {
            if ((int)($s['id'] ?? 0) === $siteId) {
                return (string)($s['nick_name'] ?? '');
            }
        }
        return $siteId > 0 ? ('Site ' . $siteId) : '';
    }
}

if (!function_exists('inv_org_dropdown_html')) {
    function inv_org_dropdown_html(string $keep_qs = ''): string {
        global $is_super, $orgs, $selected_org;
        if (empty($is_super) || empty($orgs)) {
            return '';
        }
        $extra = $keep_qs === '' ? '' : '&' . ltrim($keep_qs, '&');
        $html = '<select class="site-dropdown" title="Organization" onchange="if(window.sipMenuSetOrg){sipMenuSetOrg(this.value);}else{location.href=\'?org_id=\'+this.value+\'' . htmlspecialchars($extra, ENT_QUOTES) . '\';}">';
        $html .= '<option value="all"' . ((int)$selected_org === 0 ? ' selected' : '') . '>All orgs</option>';
        foreach ((array)$orgs as $o) {
            $oid = (int)($o['id'] ?? 0);
            $html .= '<option value="' . $oid . '"' . ((int)$selected_org === $oid ? ' selected' : '') . '>'
                . htmlspecialchars((string)($o['org_name'] ?? '')) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }
}

if (!function_exists('inv_site_dropdown_html')) {
    function inv_site_dropdown_html(string $keep_qs = ''): string {
        global $sites, $rid, $inv_org_q;
        $extra = $keep_qs !== '' ? $keep_qs : (string)$inv_org_q;
        $html = '<select class="site-dropdown" title="Restaurant" onchange="location.href=\'?rid=\'+this.value+\''
            . htmlspecialchars($extra, ENT_QUOTES) . '\'">';
        $html .= '<option value="0"' . ((int)$rid <= 0 ? ' selected' : '') . '>All Sites</option>';
        foreach ((array)$sites as $s) {
            $sid = (int)($s['SId'] ?? $s['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $html .= '<option value="' . $sid . '"' . ((int)$rid === $sid ? ' selected' : '') . '>'
                . $sid . '. ' . htmlspecialchars((string)($s['nick_name'] ?? '')) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }
}

if (!function_exists('inv_pick_site_banner')) {
    function inv_pick_site_banner(string $extra = ''): string {
        $msg = $extra !== '' ? $extra : 'Pick a restaurant. All Sites is a filter only — it does not save a delivery or count.';
        return '<div class="banner warn" style="background:#fff7ed;border:1px solid #fdba74;color:#9a3412;padding:12px 16px;border-radius:10px;margin-bottom:16px;font-weight:600;">'
            . htmlspecialchars($msg) . '</div>';
    }
}
