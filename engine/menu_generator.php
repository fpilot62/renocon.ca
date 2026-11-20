<?php
/** Path: /engine/menu_generator.php */

require_once __DIR__ . '/MENU_MIN_ROLE.php';
require_once __DIR__ . '/org_modules_lib.php';
require_once dirname(__DIR__) . '/contact_lib.php';

function sip_menu_with_parents(PDO $pdo, array $all_items): array {
    $visible_ids = [];
    foreach ($all_items as $item) {
        $visible_ids[(int)$item['MenuID']] = true;
    }
    $needed_parents = [];
    foreach ($all_items as $item) {
        $pid = (int)($item['ParentID'] ?? 0);
        while ($pid > 0 && empty($visible_ids[$pid]) && empty($needed_parents[$pid])) {
            $pstmt = $pdo->prepare("SELECT * FROM menu_manager WHERE MenuID = ? AND CAST(IsActive AS UNSIGNED) = 1");
            $pstmt->execute([$pid]);
            $parent = $pstmt->fetch(PDO::FETCH_ASSOC);
            if (!$parent) {
                break;
            }
            $needed_parents[$pid] = $parent;
            $visible_ids[$pid] = true;
            $pid = (int)($parent['ParentID'] ?? 0);
        }
    }
    if ($needed_parents) {
        $all_items = array_merge(array_values($needed_parents), $all_items);
    }
    return $all_items;
}

function sip_menu_tree(array $all_items): array {
    $menu_tree = [];
    foreach ($all_items as $item) {
        if ($item['ParentID'] == 0) {
            $menu_tree[$item['MenuID']] = $item;
            $menu_tree[$item['MenuID']]['children'] = [];
        } else {
            if (isset($menu_tree[$item['ParentID']])) {
                $menu_tree[$item['ParentID']]['children'][$item['MenuID']] = $item;
                $menu_tree[$item['ParentID']]['children'][$item['MenuID']]['grandchildren'] = [];
            } else {
                foreach ($menu_tree as $top_id => $top_item) {
                    if (isset($top_item['children'][$item['ParentID']])) {
                        $menu_tree[$top_id]['children'][$item['ParentID']]['grandchildren'][] = $item;
                    }
                }
            }
        }
    }
    foreach ($menu_tree as $top_id => $top_item) {
        $url = trim((string)($top_item['LinkURL'] ?? ''));
        if (empty($top_item['children']) && ($url === '' || $url === '#')) {
            unset($menu_tree[$top_id]);
        }
    }
    return $menu_tree;
}

function sip_menu_render_tree(array $menu_tree, int $pending_count): string {
    $html = '';
    foreach ($menu_tree as $top_item) {
        $has_children = !empty($top_item['children']);
        $badge_html = "";
        if (strtoupper($top_item['MenuTitle']) === 'EMPLOYEE HUB' && $pending_count > 0) {
            $badge_html = '<span class="badge-notify">' . $pending_count . '</span>';
        }
        $html .= '<li class="menu-item' . ($has_children ? ' has-kids' : '') . '">';
        $html .= '<a href="' . $top_item['LinkURL'] . '" class="nav-link">' . $top_item['MenuTitle'] . $badge_html . '</a>';
        if ($has_children) {
            $html .= '<ul class="dropdown">';
            foreach ($top_item['children'] as $child) {
                $child_badge = "";
                if (strtoupper($child['MenuTitle']) === 'EMPLOYEE HUB' && $pending_count > 0) {
                    $child_badge = '<span class="badge-notify">' . $pending_count . '</span>';
                }
                $has_grandchildren = !empty($child['grandchildren']);
                $html .= '<li class="menu-item' . ($has_grandchildren ? ' has-kids' : '') . '">';
                $html .= '<a href="' . $child['LinkURL'] . '" class="nav-link">' . $child['MenuTitle'] . $child_badge . '</a>';
                if ($has_grandchildren) {
                    $html .= '<ul class="dropdown" style="left: 98%; top: 0; border-radius: 12px;">';
                    foreach ($child['grandchildren'] as $gc) {
                        $html .= '<li><a href="' . $gc['LinkURL'] . '" class="nav-link">' . $gc['MenuTitle'] . '</a></li>';
                    }
                    $html .= '</ul>';
                }
                $html .= '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</li>';
    }
    return $html;
}

function generateMenu($pdo) {
    $erp_embed = (isset($_GET['embed']) && (string) $_GET['embed'] === '1')
        || (isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'iframe');
    if ($erp_embed) {
        return '';
    }
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    
    $user_id   = $_SESSION['user_id'] ?? 0;
    $org_id    = $_SESSION['org_id'] ?? 0;
    $user_lvl  = (int)($_SESSION['user_level'] ?? $_SESSION['user_role'] ?? 100);
    $user_name = $_SESSION['user_full_name'] ?? 'Staff Member';

    // Superadmin org picker — same cookie (sip_org_id) as Usage / Food Cost / inventory.
    $menu_org_html = '';
    if ($user_lvl <= 10 && $user_id) {
        if (!function_exists('sip_org_site_fence')) {
            require_once __DIR__ . '/site_status_lib.php';
        }
        try {
            $__menu_fence = sip_org_site_fence($pdo, 'sip_org_id');
        } catch (Throwable $e) {
            $__menu_fence = ['is_super' => false, 'orgs' => [], 'selected_org' => 0];
        }
        if (!empty($__menu_fence['is_super']) && !empty($__menu_fence['orgs'])) {
            $sel = (int)($__menu_fence['selected_org'] ?? 0);
            $menu_org_html = '<select class="sip-menu-org" title="Organization" onchange="sipMenuSetOrg(this.value)">';
            $menu_org_html .= '<option value="all"' . ($sel === 0 ? ' selected' : '') . '>All orgs</option>';
            foreach ((array)$__menu_fence['orgs'] as $o) {
                $oid = (int)($o['id'] ?? 0);
                $menu_org_html .= '<option value="' . $oid . '"' . ($sel === $oid ? ' selected' : '') . '>'
                    . htmlspecialchars((string)($o['org_name'] ?? '')) . '</option>';
            }
            $menu_org_html .= '</select>';
        }
    }
    
    // --- 1. NEW: COUNT PENDING REGISTRATIONS FOR BADGE ---
    $pending_count = 0;
    if ($user_lvl <= 20) { // Only check for Admins/Superadmins
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE active = 0 AND org_id = ?");
        $count_stmt->execute([$org_id]);
        $pending_count = (int)$count_stmt->fetchColumn();
    }

    // 2. FETCH THE ACTUAL ROLE NAME
    $role_id = $_SESSION['user_role'] ?? 0;
    $role_stmt = $pdo->prepare("SELECT role_name FROM roles WHERE role_id = ?");
    $role_stmt->execute([$role_id]);
    $user_role_name = $role_stmt->fetchColumn() ?: 'User';

    // 3. FETCH ACTIVE MENU ITEMS — LOCKED Min. Role only (see MENU_MIN_ROLE.php).
    // Do not call sip_filter_menu_items_for_session() here.
    $all_items = sip_menu_fetch_for_login($pdo, $user_lvl);
    sip_menu_must_not_apply_module_filter();

    // Hide Login chrome when already authenticated (Module empty = always-visible otherwise)
    if ((int)$user_id > 0) {
        $all_items = array_values(array_filter($all_items, function ($item) {
            $title = strtoupper(trim((string)($item['MenuTitle'] ?? '')));
            if ($title === 'LOGIN') {
                return false;
            }
            $url = strtolower((string)($item['LinkURL'] ?? ''));
            return (strpos($url, 'login_kiosk.php') === false && !preg_match('#(^|/)login\.php(\?|$)#', $url));
        }));
    }

    // Retired: User Management is folded into Employee Hub (all roles, including superadmin catalog).
    $all_items = array_values(array_filter($all_items, function ($item) {
        $url = strtolower((string)($item['LinkURL'] ?? ''));
        $title = strtoupper(trim((string)($item['MenuTitle'] ?? '')));
        if (strpos($url, 'manage_users.php') !== false) {
            return false;
        }
        if ($title === 'MANAGE USERS' || $title === 'USER MANAGEMENT') {
            return false;
        }
        return true;
    }));

    // Keep folder parents when a child is visible but the parent failed the
    // role gate (e.g. Daily Attendance under Admin). Otherwise the link is
    // dropped and supervisors never see what Menu Manager assigned them.
    $main_src = array_values(array_filter($all_items, function ($item) {
        return strtolower(trim((string)($item['NavBar'] ?? 'main'))) !== 'mini';
    }));
    $mini_src = array_values(array_filter($all_items, function ($item) {
        return strtolower(trim((string)($item['NavBar'] ?? ''))) === 'mini';
    }));
    $desktop_items = sip_menu_with_parents($pdo, $main_src ?: $all_items);
    $phone_items = sip_menu_with_parents($pdo, $mini_src ?: $main_src ?: $all_items);
    $menu_tree = sip_menu_tree($desktop_items);
    $phone_tree = sip_menu_tree($phone_items);

        // PWA head tags for pages that only include the menu (skip /alarm/ — own PWA)

    $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $is_alarm = (strpos($script_dir, '/alarm') !== false);
    if (!$is_alarm && file_exists(__DIR__ . '/../pwa_head.php')) {
        ob_start();
        include __DIR__ . '/../pwa_head.php';
        echo ob_get_clean();
    }

    $html = '
    <script>(function(){try{var m=(document.cookie.match(/(?:^|; )sip_nav_mode=([^;]*)/)||[])[1];m=m?decodeURIComponent(m):"";if(m!=="mini"&&m!=="desktop"){m=window.matchMedia("(max-width:1024px)").matches?"mini":"desktop";}document.documentElement.classList.add(m==="mini"?"sip-nav-mini":"sip-nav-desktop");}catch(e){}})();</script>
    <style>
        @import url(\'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap\');
        :root { --nav-bg: #ffffff; --nav-text: #334155; --brand-blue: #2563eb; --border: #cbd5e1; --danger: #ef4444; --sip-nav-h: 75px; }
        .top-nav { background: #fff; height: 75px; display: flex; align-items: center; justify-content: flex-start; padding: 0 16px; border-bottom: 1px solid var(--border); box-shadow: 0 4px 15px rgba(0,0,0,0.05); position: fixed; top: 0; left: 0; right: 0; z-index: 9999; font-family: \'Inter\', sans-serif; gap: 8px; overflow: visible; }
        .nav-scroll { flex: 1 1 0%; min-width: 0; height: 100%; overflow-x: scroll; overflow-y: hidden; -webkit-overflow-scrolling: touch; overscroll-behavior-x: contain; touch-action: pan-x; scrollbar-gutter: stable; scrollbar-width: auto; scrollbar-color: #64748b #e2e8f0; }
        .sip-nav-shift { display: none; flex-shrink: 0; width: 34px; height: 34px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: #0f172a; font-size: 22px; line-height: 1; cursor: pointer; align-items: center; justify-content: center; }
        html.sip-nav-desktop .sip-nav-shift { display: inline-flex; }
        html.sip-nav-desktop .sip-nav-shift.is-off { opacity: .28; pointer-events: none; }
        .nav-scroll::-webkit-scrollbar { height: 6px; }
        .nav-scroll::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 99px; }
        .nav-group { display: flex; gap: 10px; list-style: none; margin: 0; padding: 0; height: 100%; align-items: center; flex: 0 0 auto; width: max-content; min-width: max-content; }
        .menu-item { position: relative; list-style: none; height: 100%; display: flex; align-items: center; flex-shrink: 0; }
        .nav-link { text-decoration: none; color: var(--nav-text); font-size: 13px; font-weight: 800; padding: 10px 15px; border-radius: 8px; transition: 0.2s; text-transform: uppercase; display: flex; align-items: center; white-space: nowrap; }
        .menu-item:hover > .nav-link { color: var(--brand-blue); background: rgba(37, 99, 235, 0.05); }
        .badge-notify { background: var(--danger); color: white; font-size: 10px; padding: 2px 6px; border-radius: 50px; margin-left: 6px; font-weight: 800; vertical-align: middle; }
        .dropdown { position: absolute; top: 75px; left: 0; background: #ffffff; min-width: 250px; border: 1px solid var(--border); border-radius: 0 0 12px 12px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); display: none; list-style: none; margin: 0; padding: 10px 0; z-index: 10000; }
        .menu-item:hover > .dropdown { display: block; }
        .dropdown .nav-link { border-radius: 0; font-size: 14px; padding: 10px 20px; font-weight: 600; color: #475569; border-left: 4px solid transparent; display: flex; justify-content: space-between; align-items: center; text-transform: none; }
        .user-section { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
        .sip-menu-org { background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:7px 10px; font-weight:800; font-size:12px; color:#0f172a; max-width:160px; cursor:pointer; }
        .sip-nav-burger, .sip-nav-mode, .sip-icon-btn { display: none; border: 1px solid #cbd5e1; background: #fff; border-radius: 8px; color: #0f172a; cursor: pointer; font-family: Inter, sans-serif; }
        .sip-nav-burger { width: 42px; height: 42px; align-items: center; justify-content: center; flex-direction: column; flex-shrink: 0; }
        .sip-nav-burger span { display: block; width: 18px; height: 2px; background: #0f172a; margin: 2px 0; border-radius: 1px; }
        .sip-nav-mode, .sip-icon-btn { width: 40px; height: 40px; padding: 0; align-items: center; justify-content: center; text-decoration: none; flex-shrink: 0; }
        .sip-nav-mode svg, .sip-icon-btn svg { display: block; }
        html.sip-nav-desktop .sip-nav-mode, html.sip-nav-mini .sip-nav-mode, html.sip-nav-desktop .sip-icon-btn, html.sip-nav-mini .sip-icon-btn { display: inline-flex; }
        html.sip-nav-desktop .sip-icon-to-main, html.sip-nav-mini .sip-icon-to-mini { display: none; }
        .sip-logout { color: #dc2626; border-color: #fecaca; }
        .sip-user-chip { width: 40px; height: 40px; border-radius: 50%; background: #0f172a; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; letter-spacing: .04em; text-decoration: none; flex-shrink: 0; position: relative; }
        .sip-user-chip .sip-user-fullname { display: none; position: absolute; top: calc(100% + 8px); right: 0; background: #0f172a; color: #fff; padding: 7px 10px; border-radius: 8px; font-size: 12px; font-weight: 700; white-space: nowrap; z-index: 10080; box-shadow: 0 8px 20px rgba(15,23,42,.25); }
        .sip-user-chip:hover .sip-user-fullname, .sip-user-chip:focus .sip-user-fullname { display: block; }
        html.sip-nav-mini .sip-nav-burger { display: inline-flex; }
        html.sip-nav-mini .top-nav { height: auto; min-height: 56px; padding: 8px 10px; padding-top: calc(8px + env(safe-area-inset-top, 0px)); }
        .nav-phone { display: none; }
        html.sip-nav-mini .nav-scroll { display: none; overflow: visible; }
        html.sip-nav-mini.sip-nav-open { overflow: hidden; }
        html.sip-nav-mini.sip-nav-open .nav-scroll { display: none; }
        html.sip-nav-mini.sip-nav-open .nav-phone { display: flex; flex-direction: column; align-items: stretch; gap: 0; position: fixed; top: var(--sip-nav-h, 56px); left: 0; right: 0; bottom: 0; height: auto; width: auto; min-width: 0; background: #fff; overflow-x: hidden; overflow-y: auto; z-index: 10001; padding: 8px 0 96px; border-top: 1px solid var(--border); touch-action: pan-y; }
        html.sip-nav-mini .menu-item { height: auto; display: block; align-items: stretch; flex-shrink: 1; }
        html.sip-nav-mini .nav-link { width: 100%; box-sizing: border-box; padding: 14px 16px; font-size: 15px; white-space: normal; }
        html.sip-nav-mini .menu-item:hover > .dropdown { display: none; }
        html.sip-nav-mini .menu-item.sip-open > .dropdown { display: block; position: static; min-width: 0; border: 0; box-shadow: none; border-radius: 0; padding: 0 0 0 12px; background: #f8fafc; }
        html.sip-nav-mini .has-kids > .nav-link::after { content: "▾"; margin-left: auto; padding-left: 8px; opacity: .45; }
        html.sip-nav-mini .user-section { gap: 6px; }
        html.sip-nav-mini .sip-menu-org { max-width: 110px; padding: 6px; }
        html.sip-nav-desktop .dropdown.sip-dd-fixed { position: fixed; z-index: 10050; }
        html.sip-nav-desktop .top-nav { overflow: visible; }
        html.sip-nav-desktop .nav-scroll { flex: 1 1 0%; min-width: 0; overflow-x: scroll; }
        @media (max-width: 1024px) {
            html.sip-nav-desktop .top-nav { padding: 0 10px; height: 64px; }
            html.sip-nav-desktop .nav-link { padding: 10px; font-size: 12px; }
            html.sip-nav-desktop .user-section { gap: 6px; }
        }
    </style>

    <nav class="top-nav" id="sipTopNav">
        <button type="button" class="sip-nav-burger" aria-label="Open menu" aria-expanded="false"><span></span><span></span><span></span></button>
        <button type="button" class="sip-nav-shift sip-nav-shift-left" aria-label="Scroll menu left">&lsaquo;</button>
        <div class="nav-scroll" id="sipNavScroll">
        <ul class="nav-group nav-main">';

    $html .= sip_menu_render_tree($menu_tree, $pending_count);

    $name_bits = preg_split('/\s+/', trim((string)$user_name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($name_bits) >= 2) {
        $initials = strtoupper(substr($name_bits[0], 0, 1) . substr($name_bits[count($name_bits) - 1], 0, 1));
    } else {
        $initials = strtoupper(substr((string)$user_name, 0, 2)) ?: '?';
    }
    $safe_name = htmlspecialchars($user_name);
    $safe_role = htmlspecialchars($user_role_name);
    $icon_phone = '<svg class="sip-icon-to-mini" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><rect x="7" y="2" width="10" height="20" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="18" r="1.2" fill="currentColor"/><path d="M9 6h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    $icon_desktop = '<svg class="sip-icon-to-main" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><rect x="2" y="4" width="20" height="13" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 20h8M12 17v3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M6 8h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    $icon_logout = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M10 7V5a2 2 0 0 1 2-2h7v18h-7a2 2 0 0 1-2-2v-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M4 12h10M11 8l4 4-4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    $profile_chip = ((int)$user_id > 0)
        ? '<a href="/my_profile.php" class="sip-user-chip" title="' . $safe_name . ' — My profile" aria-label="My profile, ' . $safe_name . '"><span>' . htmlspecialchars($initials) . '</span><span class="sip-user-fullname">' . $safe_name . ' · ' . $safe_role . '</span></a>'
        : '<span class="sip-user-chip" title="' . $safe_name . '"><span>' . htmlspecialchars($initials) . '</span><span class="sip-user-fullname">' . $safe_name . '</span></span>';

    $html .= '
        </ul>
        </div>
        <button type="button" class="sip-nav-shift sip-nav-shift-right" aria-label="Scroll menu right">&rsaquo;</button>
        <ul class="nav-group nav-phone">' . sip_menu_render_tree($phone_tree, $pending_count) . '</ul>
        <div class="user-section">
            <button type="button" class="sip-nav-mode" title="Switch to Mini bar (phone menu)" aria-label="Switch to Mini bar (phone menu)">' . $icon_phone . $icon_desktop . '</button>
            ' . $menu_org_html . '
            ' . $profile_chip . '
            <a href="/logout.php" class="sip-icon-btn sip-logout" title="Log out" aria-label="Log out">' . $icon_logout . '</a>
        </div>
    <div class="ecommi-devby" style="position:fixed;left:10px;bottom:6px;z-index:40;font-size:10px;line-height:1.3;color:#94a3b8;pointer-events:auto;font-family:system-ui,-apple-system,sans-serif;">Developed by <a href="https://ecommi.com" target="_blank" rel="noopener noreferrer" style="color:#64748b;text-decoration:underline;">eComm i</a></div>
    </nav>';

    // Support chat launcher (new window) + hours — once per page for any screen with the staff nav.
    $tawkLib = dirname(__DIR__) . '/tawk_embed.php';
    if (is_file($tawkLib)) {
        require_once $tawkLib;
        if (function_exists('payroll_tawk_embed')) {
            $html .= payroll_tawk_embed();
        }
    }

    $alertLib = __DIR__ . '/dal_daily_upload_alert.php';
    if (is_file($alertLib)) {
        require_once $alertLib;
        if (function_exists('sip_dal_missing_upload_alert')) {
            $html .= sip_dal_missing_upload_alert($pdo);
        }
    }

    // Optional page title sits above About this page (Usage sets this).
    if (!empty($GLOBALS['sip_page_title_html'])) {
        $html .= (string)$GLOBALS['sip_page_title_html'];
    }

    // Written page tutorial first (About this page). Audio tour reads that text.
    $helpLib = dirname(__DIR__) . '/payroll_help.php';
    if (is_file($helpLib)) {
        require_once $helpLib;
        if (function_exists('payroll_help_panel')) {
            $html .= '<div class="pg-help-from-nav" style="max-width:1550px;margin:8px auto 0;padding:0 16px;position:relative;z-index:20;">';
            $html .= payroll_help_panel();
            $html .= '</div>';
        }
    }

    $html .= <<<'JS'
<script>
(function () {
  var KEY = 'sip_nav_mode';
  function cookie() {
    var m = document.cookie.match(/(?:^|; )sip_nav_mode=([^;]*)/);
    return m ? decodeURIComponent(m[1]) : '';
  }
  function save(v) {
    document.cookie = KEY + '=' + encodeURIComponent(v) + ';path=/;max-age=' + (86400 * 180) + ';samesite=lax';
  }
  function current() {
    var c = cookie();
    if (c === 'mini' || c === 'desktop') return c;
    return window.matchMedia('(max-width:1024px)').matches ? 'mini' : 'desktop';
  }
  function size() {
    var nav = document.getElementById('sipTopNav');
    if (nav) document.documentElement.style.setProperty('--sip-nav-h', nav.offsetHeight + 'px');
  }
  function arrows() {
    var scroller = document.getElementById('sipNavScroll');
    if (!scroller) return;
    var max = scroller.scrollWidth - scroller.clientWidth;
    var left = document.querySelector('.sip-nav-shift-left');
    var right = document.querySelector('.sip-nav-shift-right');
    if (left) left.classList.toggle('is-off', scroller.scrollLeft <= 2);
    if (right) right.classList.toggle('is-off', max <= 4 || scroller.scrollLeft >= max - 2);
  }
  function apply(m) {
    document.documentElement.classList.toggle('sip-nav-mini', m === 'mini');
    document.documentElement.classList.toggle('sip-nav-desktop', m !== 'mini');
    document.documentElement.classList.remove('sip-nav-open');
    var btn = document.querySelector('.sip-nav-mode');
    if (btn) {
      btn.title = m === 'mini' ? 'Switch to Main bar (desktop menu)' : 'Switch to Mini bar (phone menu)';
      btn.setAttribute('aria-label', btn.title);
    }
    var burger = document.querySelector('.sip-nav-burger');
    if (burger) burger.setAttribute('aria-expanded', 'false');
    size();
    arrows();
  }
  function ready() {
    apply(current());
    var burger = document.querySelector('.sip-nav-burger');
    if (burger) {
      burger.addEventListener('click', function () {
        var open = document.documentElement.classList.toggle('sip-nav-open');
        burger.setAttribute('aria-expanded', open ? 'true' : 'false');
        size();
      });
    }
    var modeBtn = document.querySelector('.sip-nav-mode');
    if (modeBtn) {
      modeBtn.addEventListener('click', function () {
        var next = document.documentElement.classList.contains('sip-nav-mini') ? 'desktop' : 'mini';
        save(next);
        apply(next);
      });
    }
    var nav = document.getElementById('sipTopNav');
    var scroller = document.getElementById('sipNavScroll');
    if (nav) {
      nav.addEventListener('click', function (e) {
        if (!document.documentElement.classList.contains('sip-nav-mini')) return;
        var link = e.target.closest('a.nav-link');
        if (!link) return;
        var li = link.parentElement;
        if (!li || !li.classList.contains('has-kids')) return;
        e.preventDefault();
        li.classList.toggle('sip-open');
      });
    }
    function pinDropdown(li) {
      var dd = li && li.querySelector(':scope > .dropdown');
      if (!dd) return;
      var r = li.getBoundingClientRect();
      var nested = li.parentElement && li.parentElement.classList.contains('dropdown');
      dd.classList.add('sip-dd-fixed');
      if (nested) {
        dd.style.top = Math.max(8, r.top) + 'px';
        dd.style.left = Math.min(window.innerWidth - 260, r.right - 8) + 'px';
      } else {
        dd.style.top = r.bottom + 'px';
        dd.style.left = Math.max(8, Math.min(r.left, window.innerWidth - 260)) + 'px';
      }
    }
    function unpinDropdown(li) {
      var dd = li && li.querySelector(':scope > .dropdown');
      if (!dd) return;
      dd.classList.remove('sip-dd-fixed');
      dd.style.top = '';
      dd.style.left = '';
    }
    if (nav) {
      nav.addEventListener('mouseover', function (e) {
        if (!document.documentElement.classList.contains('sip-nav-desktop')) return;
        var li = e.target.closest('.menu-item.has-kids');
        if (li && nav.contains(li)) pinDropdown(li);
      });
      nav.addEventListener('mouseout', function (e) {
        var li = e.target.closest('.menu-item.has-kids');
        if (!li) return;
        var to = e.relatedTarget;
        if (to && li.contains(to)) return;
        unpinDropdown(li);
      });
    }
    if (scroller) {
      scroller.addEventListener('wheel', function (e) {
        if (!document.documentElement.classList.contains('sip-nav-desktop')) return;
        if (scroller.scrollWidth <= scroller.clientWidth + 1) return;
        if (Math.abs(e.deltaY) <= Math.abs(e.deltaX)) {
          return;
        }
        e.preventDefault();
        scroller.scrollLeft += e.deltaY;
      }, { passive: false });
      scroller.addEventListener('scroll', function () {
        arrows();
        nav.querySelectorAll('.dropdown.sip-dd-fixed').forEach(function (dd) {
          var li = dd.parentElement;
          if (li) pinDropdown(li);
        });
      });
    }
    document.querySelectorAll('.sip-nav-shift').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!scroller) return;
        scroller.scrollBy({ left: btn.classList.contains('sip-nav-shift-right') ? 260 : -260, behavior: 'smooth' });
      });
    });
    window.addEventListener('resize', function () { size(); arrows(); });
    arrows();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
  else ready();
})();
</script>
JS;

    if ($menu_org_html !== '') {
        $html .= <<<'JS'
<script>
function sipMenuSetOrg(id) {
  var max = 86400 * 120;
  var parts = ';path=/;max-age=' + max + ';samesite=lax';
  document.cookie = 'sip_org_id=' + encodeURIComponent(id) + parts;
  document.cookie = 'dal_org_id=' + encodeURIComponent(id) + parts;
  document.cookie = 'sa_org_id=' + encodeURIComponent(id) + parts;
  var u = new URL(window.location.href);
  u.searchParams.set('org_id', id);
  ['rid','site_id','RId','user_id'].forEach(function(k){ u.searchParams.delete(k); });
  window.location.href = u.toString();
}
</script>
JS;
    }

    if (function_exists('contact_card_html')) {
        $html .= contact_card_html($pdo);
    }

    return $html;
}
