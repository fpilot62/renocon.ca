<?php
/**
 * Org module entitlements — shared by menu_generator, saas_manager, login.
 *
 * Canonical keys (modules.module_key / org_modules.module_key / menu_manager.Module):
 *   PAY, clockin, inventory, Scheduling, operations, alarm
 *
 * Legacy aliases (sites.module, old menus, saas UI) are normalized to the above.
 */

if (!function_exists('sip_normalize_module_key')) {

function sip_normalize_module_key($raw) {
    $k = strtolower(trim((string)$raw));
    if ($k === '') {
        return '';
    }
    static $map = [
        'pay' => 'PAY',
        'payroll' => 'PAY',
        'clockin' => 'clockin',
        'clocking' => 'clockin',
        'attendance' => 'clockin',
        'kiosk' => 'clockin',
        'inventory' => 'inventory',
        'scheduling' => 'Scheduling',
        'schedule' => 'Scheduling',
        'operations' => 'operations',
        'ops' => 'operations',
        'alarm' => 'alarm',
        'audit' => 'audit',
    ];
    return $map[$k] ?? $raw;
}

/** Map canonical key → legacy sites.module CSV token (best-effort sync). */
function sip_site_module_token($canonical) {
    static $map = [
        'PAY' => 'payroll',
        'clockin' => 'clocking',
        'inventory' => 'inventory',
        'Scheduling' => 'scheduling',
        'operations' => 'operations',
        'alarm' => 'alarm',
        'audit' => 'audit',
    ];
    return $map[$canonical] ?? strtolower($canonical);
}

/**
 * Active module keys for an org.
 * - Master org 100000: all active catalog modules
 * - Orgs with org_modules rows: distinct active keys (expiry null or future)
 * - Orgs with zero rows: all active catalog modules (legacy open)
 */
function sip_org_enabled_modules(PDO $pdo, $org_id) {
    $org_id = (int)$org_id;
    $catalog = [];
    try {
        foreach ($pdo->query("SELECT module_key FROM modules WHERE is_active = 1") as $row) {
            $nk = sip_normalize_module_key($row['module_key']);
            if ($nk !== '') {
                $catalog[$nk] = true;
            }
        }
    } catch (Throwable $e) {
        // modules table missing — open access
        return null;
    }

    if ($org_id === 100000) {
        return array_keys($catalog);
    }

    try {
        // Any entitlement row (even inactive) means org is managed — not legacy-open.
        $any = $pdo->prepare("SELECT 1 FROM org_modules WHERE org_id = ? LIMIT 1");
        $any->execute([$org_id]);
        $has_rows = (bool)$any->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT module_key FROM org_modules
             WHERE org_id = ?
               AND is_active = 1
               AND (expiry_date IS NULL OR expiry_date >= CURDATE())"
        );
        $stmt->execute([$org_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return null;
    }

    // Zero entitlement rows → legacy open (all catalog). Managed org with none active → [].
    if (!$has_rows) {
        return array_keys($catalog);
    }

    $enabled = [];
    foreach ($rows as $raw) {
        $nk = sip_normalize_module_key($raw);
        if ($nk !== '') {
            $enabled[$nk] = true;
        }
    }
    return array_keys($enabled);
}

function sip_org_has_module(PDO $pdo, $org_id, $module_key) {
    $enabled = sip_org_enabled_modules($pdo, $org_id);
    if ($enabled === null) {
        return true;
    }
    $want = sip_normalize_module_key($module_key);
    if ($want === '') {
        return true;
    }
    return in_array($want, $enabled, true);
}

/**
 * Login / Help only — not a free pass for untagged product links.
 */
function sip_menu_is_core_chrome(array $item) {
    $title = strtoupper(trim((string)($item['MenuTitle'] ?? '')));
    $url = strtolower((string)($item['LinkURL'] ?? ''));
    if ($title === 'LOGIN' || $title === 'HELP') {
        return true;
    }
    if (strpos($url, 'help_center.php') !== false || strpos($url, 'login_kiosk.php') !== false) {
        return true;
    }
    return preg_match('#(^|/)login\.php(\?|$)#', $url) === 1;
}

function sip_menu_is_superadmin_tool(array $item) {
    $url = strtolower((string)($item['LinkURL'] ?? ''));
    foreach ([
        'saas_manager.php', 'menu_manager.php', 'module_manager_settings.php',
        'manage_orgs.php', 'undo_finalization', 'generate_backup.php',
        'role_manager.php', 'site_module_manager.php', 'site_manager.php',
        'org_settings.php',
    ] as $needle) {
        if (strpos($url, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/** Org has only PAY among product modules (Payroll Lite). */
function sip_org_is_pay_lite($enabled_modules) {
    if (!is_array($enabled_modules)) {
        return false;
    }
    $product = ['PAY', 'clockin', 'inventory', 'Scheduling', 'operations', 'alarm'];
    $on = [];
    foreach ($enabled_modules as $k) {
        $nk = sip_normalize_module_key($k);
        if (in_array($nk, $product, true)) {
            $on[$nk] = true;
        }
    }
    return count($on) === 1 && isset($on['PAY']);
}

/** Site Picker nav (Google map pin) — always keep for PAY-lite admins. */
function sip_menu_is_site_picker(array $item) {
    $title = strtoupper(trim((string)($item['MenuTitle'] ?? '')));
    $url = strtolower((string)($item['LinkURL'] ?? ''));
    if ($title === 'SITE PICKER') {
        return true;
    }
    return strpos($url, 'site_picker') !== false;
}

/**
 * Payroll Lite allowlist: CSV import, staff audit/finalize, employee hub,
 * payroll center, sites, Site Picker, payroll reports, help. No clock-in,
 * inventory, estimates, QuickBooks/bank-style, or unused hubs.
 */
function sip_menu_pay_lite_allowed(array $item) {
    $url = strtolower((string)($item['LinkURL'] ?? ''));
    $title = strtoupper(trim((string)($item['MenuTitle'] ?? '')));
    $parent = (int)($item['ParentID'] ?? 0);

    // Always allow Site Picker (before deny needles) so aaadmin keeps map pins.
    if (sip_menu_is_site_picker($item)) {
        return true;
    }

    foreach ([
        'business_hub.php', 'tax_hub.php', 'export_quickbooks.php',
        'estimate/', 'inventory/', 'add_invoice',
        'bookkeep', '/bank', 'bank_', 'admin_dashboard.php', 'generate_backup.php',
        'kiosk.php', 'manual_attendance.php', 'timeoff_', 'register1.php',
        'food_cost', 'daily_report_view.php', 'scheduler_view.php', 'alarm/',
    ] as $deny) {
        if ($deny !== '' && strpos($url, $deny) !== false) {
            return false;
        }
    }
    if (strpos($title, 'QUICKBOOK') !== false || strpos($title, 'BUSINESS HUB') !== false || strpos($title, 'TAX HUB') !== false) {
        return false;
    }
    if (in_array($title, ['CLOCK IN', 'OPERATIONS', 'SCHEDULE', 'INVENTORY', 'ALARM', 'ADMIN', 'ESTIMATES'], true)) {
        return false;
    }

    if ($parent === 0) {
        return in_array($title, ['EMPLOYEES', 'PAYROLL', 'CLIENTS', 'SITES', 'REPORTS', 'HELP'], true);
    }

    if ($url === '' || $url === '#') {
        return in_array($title, ['ADMIN TUTORIAL', 'EMPLOYEES', 'PAYROLL', 'CLIENTS', 'SITES', 'REPORTS', 'HELP'], true);
    }

    foreach ([
        'employee_hub.php', 'bulk_finalize.php', 'staff_audit.php',
        'payroll_master_ledger.php', 'payroll_cra_payments.php', 'generate_t4.php',
        'generate_stat_shifts.php', 'bulk_importer.php', 'vacation_service_admin.php',
        'manage_sites.php', 'edit_site.php', 'site_picker_google.php', 'payroll_report.php', 'payroll_reports.php', 'payroll_ytd.php',
        'payroll_summary.php', 'payroll_outstanding_remittance.php', 'balance_reports.php',
        'help_center.php',
    ] as $allow) {
        if (strpos($url, $allow) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Whether a menu_manager row should show for this org.
 * Entitlement-managed orgs: empty Module is core chrome only (Login/Help),
 * not a free pass for untagged product links.
 */
function sip_menu_item_allowed(array $item, $enabled_or_null) {
    if ($enabled_or_null === null) {
        return true;
    }
    if (!is_array($enabled_or_null)) {
        return true;
    }
    if (sip_menu_is_site_picker($item)) {
        return true;
    }
    $mod = trim((string)($item['Module'] ?? ''));
    if ($mod === '') {
        return sip_menu_is_core_chrome($item);
    }
    $nk = sip_normalize_module_key($mod);
    if (in_array($nk, $enabled_or_null, true)) {
        return true;
    }
    // Daily Activity / Food Cost are tagged operations but ship with payroll.
    if ($nk === 'operations' && in_array('PAY', $enabled_or_null, true)) {
        return true;
    }
    return false;
}

/**
 * LOCKED OUT OF THE LEFT MENU (2026-09-10).
 * Do not call this from generateMenu / MENU_MIN_ROLE.php.
 * Min. Role (UserRole >= login role + Active) is the only left-menu gate.
 * This function hid Operations / Daily Report from Supervisors when the org
 * lacked an "operations" module; Superadmin skipped it and still saw the links.
 * Kept only for non-menu entitlement checks. Do not wire it back into the nav.
 */
function sip_filter_menu_items_for_session(array $items, $enabled_modules, $user_lvl, $access_scope) {
    if ((int)$user_lvl <= 10) {
        return array_values($items);
    }
    $restricted = strtolower(trim((string)$access_scope)) !== 'all';
    $pay_lite = sip_org_is_pay_lite($enabled_modules);
    $out = [];
    foreach ($items as $item) {
        if ((int)($item['IsActive'] ?? 1) !== 1) {
            continue;
        }
        if ($enabled_modules !== null && !sip_menu_item_allowed($item, $enabled_modules)) {
            continue;
        }
        if ($restricted && sip_menu_is_superadmin_tool($item)) {
            continue;
        }
        if ($pay_lite && !sip_menu_pay_lite_allowed($item)) {
            continue;
        }
        $out[] = $item;
    }
    return $out;
}

/**
 * Upsert org_modules for one key. $enable true → active; false → inactive.
 * First revoke/provision on a legacy-open org (no rows) seeds entitlements so
 * revoke actually sticks instead of remaining "all catalog enabled".
 */
function sip_set_org_module(PDO $pdo, $org_id, $module_key, $enable, $expiry = null) {
    $org_id = (int)$org_id;
    $key = sip_normalize_module_key($module_key);
    if ($key === '' || $org_id <= 0) {
        return;
    }

    $any = $pdo->prepare("SELECT 1 FROM org_modules WHERE org_id = ? LIMIT 1");
    $any->execute([$org_id]);
    $has_rows = (bool)$any->fetchColumn();

    // Legacy-open → first revoke: seed other catalog keys active, target inactive.
    if (!$has_rows && !$enable) {
        $catalog = [];
        try {
            foreach ($pdo->query("SELECT module_key FROM modules WHERE is_active = 1") as $row) {
                $nk = sip_normalize_module_key($row['module_key']);
                if ($nk !== '') {
                    $catalog[$nk] = true;
                }
            }
        } catch (Throwable $e) {
            $catalog = [];
        }
        $ins = $pdo->prepare("INSERT INTO org_modules (org_id, module_key, is_active, expiry_date) VALUES (?, ?, ?, ?)");
        foreach (array_keys($catalog) as $ck) {
            $ins->execute([$org_id, $ck, ($ck === $key) ? 0 : 1, $expiry]);
        }
        if (!isset($catalog[$key])) {
            $ins->execute([$org_id, $key, 0, $expiry]);
        }
        return;
    }

    $stmt = $pdo->prepare("SELECT id FROM org_modules WHERE org_id = ? AND module_key = ? ORDER BY id ASC");
    $stmt->execute([$org_id, $key]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($ids) {
        $keep = (int)$ids[0];
        $upd = $pdo->prepare("UPDATE org_modules SET is_active = ?, expiry_date = ? WHERE id = ?");
        $upd->execute([$enable ? 1 : 0, $expiry, $keep]);
        // Collapse duplicate rows for same org+key
        if (count($ids) > 1) {
            $del = $pdo->prepare("DELETE FROM org_modules WHERE org_id = ? AND module_key = ? AND id <> ?");
            $del->execute([$org_id, $key, $keep]);
        }
        return;
    }

    // Insert active, or insert inactive when managing an already-seeded org.
    $ins = $pdo->prepare("INSERT INTO org_modules (org_id, module_key, is_active, expiry_date) VALUES (?, ?, ?, ?)");
    $ins->execute([$org_id, $key, $enable ? 1 : 0, $expiry]);
}

/**
 * Best-effort mirror of org entitlements onto sites.module CSV (legacy readers).
 * org_modules remains source of truth; never fatal if sites.module is short.
 */
function sip_sync_sites_module_csv(PDO $pdo, $org_id) {
    $enabled = sip_org_enabled_modules($pdo, $org_id);
    if ($enabled === null) {
        return;
    }
    $tokens = [];
    foreach ($enabled as $k) {
        $tokens[] = sip_site_module_token($k);
    }
    $tokens = array_values(array_unique(array_filter($tokens)));
    $csv = implode(',', $tokens);

    // Prefer primary token only if CSV still oversized for legacy VARCHAR.
    $maxLen = 512;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM sites LIKE 'module'")->fetch(PDO::FETCH_ASSOC);
        if (!empty($col['Type']) && preg_match('/varchar\((\d+)\)/i', $col['Type'], $m)) {
            $maxLen = (int)$m[1];
        }
    } catch (Throwable $e) {
        // ignore — use default
    }
    if (strlen($csv) > $maxLen) {
        $csv = $tokens[0] ?? '';
        if (strlen($csv) > $maxLen) {
            $csv = substr($csv, 0, $maxLen);
        }
    }

    try {
        $upd = $pdo->prepare("UPDATE sites SET `module` = ? WHERE org_id = ?");
        $upd->execute([$csv, (int)$org_id]);
    } catch (Throwable $e) {
        // Legacy mirror must never block provision/revoke.
        error_log('sip_sync_sites_module_csv org ' . (int)$org_id . ': ' . $e->getMessage());
    }
}

/**
 * Org post-login landing options (key => label, url, required module).
 * Keys are stored on organizations.default_landing.
 */
function sip_landing_options() {
    return [
        'auto' => [
            'label' => 'First available enabled module',
            'url' => null,
            'module' => null,
        ],
        'kiosk' => [
            'label' => 'Clock In / Kiosk',
            'url' => 'kiosk.php',
            'module' => 'clockin',
        ],
        'payroll_hub' => [
            'label' => 'Payroll Hub',
            'url' => 'payroll_hub.php',
            'module' => 'PAY',
        ],
        'csv_import' => [
            'label' => 'CSV Import',
            'url' => 'bulk_importer.php',
            'module' => 'PAY',
        ],
        'Scheduling' => [
            'label' => 'Scheduling',
            'url' => 'sip/active/modules/scheduling/scheduler_view.php',
            'module' => 'Scheduling',
        ],
    ];
}

function sip_org_default_landing(PDO $pdo, $org_id) {
    $org_id = (int)$org_id;
    try {
        $stmt = $pdo->prepare("SELECT default_landing FROM organizations WHERE id = ? LIMIT 1");
        $stmt->execute([$org_id]);
        $raw = $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 'auto';
    }
    $key = trim((string)$raw);
    $opts = sip_landing_options();
    return isset($opts[$key]) ? $key : 'auto';
}

function sip_set_org_default_landing(PDO $pdo, $org_id, $landing_key) {
    $org_id = (int)$org_id;
    $opts = sip_landing_options();
    $key = isset($opts[$landing_key]) ? $landing_key : 'auto';
    if ($org_id <= 0) {
        return;
    }
    $upd = $pdo->prepare("UPDATE organizations SET default_landing = ? WHERE id = ?");
    $upd->execute([$key, $org_id]);
}

/** Prefer kiosk for staff / low-privilege when clock-in is on (restaurant workflow). */
function sip_is_staff_role($user_role) {
    return (int)$user_role >= 40;
}

function sip_landing_url_for_key($key) {
    $opts = sip_landing_options();
    if (!isset($opts[$key]) || empty($opts[$key]['url'])) {
        return null;
    }
    $url = $opts[$key]['url'];
    if ($key === 'payroll_hub' && !file_exists(__DIR__ . '/../payroll_hub.php')) {
        return 'employee_hub.php';
    }
    return $url;
}

/**
 * Resolve post-login destination for an org + role.
 * - Staff (role >= 40): force kiosk when clockin enabled
 * - Else: org default_landing if that module is enabled
 * - Else: first enabled among kiosk → Scheduling → payroll hub
 * - Else: roles.landing_page, then employee_hub.php
 */
function sip_resolve_login_destination(PDO $pdo, $org_id, $user_role) {
    $org_id = (int)$org_id;
    $role = (int)$user_role;
    $has_clockin = sip_org_has_module($pdo, $org_id, 'clockin');

    if (sip_is_staff_role($role) && $has_clockin) {
        return 'kiosk.php';
    }

    $opts = sip_landing_options();
    $preferred = sip_org_default_landing($pdo, $org_id);

    if ($preferred !== 'auto' && isset($opts[$preferred])) {
        $mod = $opts[$preferred]['module'];
        if ($mod === null || sip_org_has_module($pdo, $org_id, $mod)) {
            $url = sip_landing_url_for_key($preferred);
            if ($url) {
                return $url;
            }
        }
    }

    foreach (['kiosk', 'Scheduling', 'payroll_hub'] as $fallback_key) {
        $mod = $opts[$fallback_key]['module'];
        if ($mod === null || sip_org_has_module($pdo, $org_id, $mod)) {
            $url = sip_landing_url_for_key($fallback_key);
            if ($url) {
                return $url;
            }
        }
    }

    try {
        $stmt = $pdo->prepare("SELECT landing_page FROM roles WHERE role_id = ? LIMIT 1");
        $stmt->execute([$role]);
        $role_page = trim((string)$stmt->fetchColumn());
        if ($role_page !== '') {
            return ltrim($role_page, '/');
        }
    } catch (Throwable $e) {
        // ignore
    }

    return 'employee_hub.php';
}

/**
 * Ensure organizations.auto_start_tour exists (TINYINT 0/1, default 0 = Off).
 * Safe to call repeatedly; never throws to callers.
 */
function sip_ensure_auto_start_tour_column(PDO $pdo) {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec(
            "ALTER TABLE organizations
             ADD COLUMN auto_start_tour TINYINT(1) NOT NULL DEFAULT 0
             COMMENT '1 = auto-start guided tours on first page visit'"
        );
    } catch (Throwable $e) {
        // Column already exists (or non-fatal) — ignore.
    }
}

/** Org-level: auto-start guided tours? Default Off when unset / missing column. */
function sip_org_auto_start_tour(PDO $pdo, $org_id) {
    $org_id = (int)$org_id;
    if ($org_id <= 0) {
        return false;
    }
    sip_ensure_auto_start_tour_column($pdo);
    try {
        $stmt = $pdo->prepare("SELECT auto_start_tour FROM organizations WHERE id = ? LIMIT 1");
        $stmt->execute([$org_id]);
        return ((int)$stmt->fetchColumn()) === 1;
    } catch (Throwable $e) {
        return false;
    }
}

function sip_set_org_auto_start_tour(PDO $pdo, $org_id, $enabled) {
    $org_id = (int)$org_id;
    if ($org_id <= 0) {
        return;
    }
    sip_ensure_auto_start_tour_column($pdo);
    $val = $enabled ? 1 : 0;
    $upd = $pdo->prepare("UPDATE organizations SET auto_start_tour = ? WHERE id = ?");
    $upd->execute([$val, $org_id]);
}

/** Pay-frequency choices: periods/year used to annualize tax (period pay × P). */
function sip_pay_period_choices(): array {
    return [
        'bi_weekly'    => ['label' => 'Biweekly — 26 periods / year', 'periods' => 26],
        'semi_monthly' => ['label' => 'Semi-monthly — 24 periods / year', 'periods' => 24],
        'monthly'      => ['label' => 'Monthly — 12 periods / year', 'periods' => 12],
        'weekly'       => ['label' => 'Weekly — 52 periods / year', 'periods' => 52],
    ];
}

function sip_gem_setting_get(PDO $pdo, int $org_id, string $key): ?string {
    if ($org_id <= 0 || $key === '') {
        return null;
    }
    try {
        $st = $pdo->prepare("SELECT setting_value FROM Gem_Settings WHERE org_id = ? AND setting_key = ? LIMIT 1");
        $st->execute([$org_id, $key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null) ? null : (string)$v;
    } catch (Throwable $e) {
        return null;
    }
}

function sip_gem_setting_set(PDO $pdo, int $org_id, string $key, string $val): void {
    if ($org_id <= 0 || $key === '') {
        return;
    }
    $existing = sip_gem_setting_get($pdo, $org_id, $key);
    if ($existing !== null) {
        $upd = $pdo->prepare("UPDATE Gem_Settings SET setting_value = ? WHERE org_id = ? AND setting_key = ?");
        $upd->execute([$val, $org_id, $key]);
        return;
    }
    $ins = $pdo->prepare("INSERT INTO Gem_Settings (org_id, setting_key, setting_value) VALUES (?, ?, ?)");
    $ins->execute([$org_id, $key, $val]);
}

/** If every site belongs to one org, return that org_id; otherwise $fallback. */
function sip_org_id_from_site_ids(PDO $pdo, array $site_ids, int $fallback = 0): int {
    $ids = array_values(array_unique(array_filter(array_map('intval', $site_ids), static function ($v) {
        return $v > 0;
    })));
    if (!$ids) {
        return $fallback;
    }
    try {
        $in = implode(',', $ids);
        $orgs = $pdo->query("SELECT DISTINCT org_id FROM sites WHERE SId IN ($in) AND org_id > 0")->fetchAll(PDO::FETCH_COLUMN);
        $orgs = array_values(array_unique(array_map('intval', $orgs)));
        if (count($orgs) === 1) {
            return $orgs[0];
        }
    } catch (Throwable $e) {
        return $fallback;
    }
    return $fallback;
}

function sip_org_pay_period_mode(PDO $pdo, $org_id): string {
    $org_id = (int)$org_id;
    $choices = sip_pay_period_choices();
    $mode = strtolower(trim((string)(sip_gem_setting_get($pdo, $org_id, 'pay_period_mode') ?? sip_gem_setting_get($pdo, $org_id, 'period_mode') ?? '')));
    if (isset($choices[$mode])) {
        return $mode;
    }
    $p = (int)(sip_gem_setting_get($pdo, $org_id, 'pay_periods_per_year') ?? 0);
    foreach ($choices as $k => $info) {
        if ((int)$info['periods'] === $p) {
            return $k;
        }
    }
    // AA Group (org 4) pays biweekly even if Gem_Settings is missing.
    if ($org_id === 4) {
        return 'bi_weekly';
    }
    return 'semi_monthly';
}

function sip_org_pay_periods(PDO $pdo, $org_id): int {
    $org_id = (int)$org_id;
    $explicit = (int)(sip_gem_setting_get($pdo, $org_id, 'pay_periods_per_year') ?? 0);
    if (in_array($explicit, [12, 24, 26, 52], true)) {
        return $explicit;
    }
    $mode = sip_org_pay_period_mode($pdo, $org_id);
    $choices = sip_pay_period_choices();
    return (int)($choices[$mode]['periods'] ?? 24);
}

function sip_org_has_pay_period_setting(PDO $pdo, $org_id): bool {
    $org_id = (int)$org_id;
    $mode = sip_gem_setting_get($pdo, $org_id, 'pay_period_mode');
    if ($mode === null) {
        $mode = sip_gem_setting_get($pdo, $org_id, 'period_mode');
    }
    $p = sip_gem_setting_get($pdo, $org_id, 'pay_periods_per_year');
    return ($mode !== null && $mode !== '') || ($p !== null && $p !== '');
}

function sip_set_org_pay_period(PDO $pdo, $org_id, $mode): void {
    $org_id = (int)$org_id;
    $choices = sip_pay_period_choices();
    $mode = strtolower(trim((string)$mode));
    if (!isset($choices[$mode])) {
        $mode = 'semi_monthly';
    }
    $p = (string)(int)$choices[$mode]['periods'];
    sip_gem_setting_set($pdo, $org_id, 'pay_period_mode', $mode);
    sip_gem_setting_set($pdo, $org_id, 'pay_periods_per_year', $p);
}

function sip_org_pay_period_anchor(PDO $pdo, $org_id): string {
    $org_id = (int)$org_id;
    $a = '';
    if (function_exists('sip_gem_setting_get')) {
        $a = trim((string)(sip_gem_setting_get($pdo, $org_id, 'pay_period_anchor') ?? ''));
    }
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $a) ? $a : '2024-01-01';
}

function sip_set_org_pay_period_anchor(PDO $pdo, $org_id, $anchor): void {
    $org_id = (int)$org_id;
    $anchor = trim((string)$anchor);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) {
        $anchor = '2024-01-01';
    }
    sip_gem_setting_set($pdo, $org_id, 'pay_period_anchor', $anchor);
}

function sip_period_window(string $work_date, string $mode, string $anchor = '2024-01-01'): array {
    if (function_exists('pe_period_window')) {
        return pe_period_window($work_date, $mode, $anchor);
    }
    $day = substr($work_date, 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        $day = date('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) {
        $anchor = '2024-01-01';
    }
    if ($mode === 'monthly') {
        $ts = strtotime($day) ?: time();
        return [date('Y-m-01', $ts), date('Y-m-t', $ts)];
    }
    if ($mode === 'weekly' || $mode === 'bi_weekly') {
        $span = ($mode === 'weekly') ? 7 : 14;
        $d0 = new DateTimeImmutable($anchor);
        $d1 = new DateTimeImmutable($day);
        $days = (int)$d0->diff($d1)->format('%r%a');
        $block = (int)floor($days / $span);
        $start = $d0->modify(($block * $span) . ' days');
        $end = $start->modify('+' . ($span - 1) . ' days');
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }
    $ts = strtotime($day) ?: time();
    $dom = (int)date('j', $ts);
    if ($dom <= 15) {
        return [date('Y-m-01', $ts), date('Y-m-15', $ts)];
    }
    return [date('Y-m-16', $ts), date('Y-m-t', $ts)];
}

function sip_period_label(string $start, string $end, string $mode): string {
    $a = strtotime($start) ?: time();
    $b = strtotime($end) ?: $a;
    if ($mode === 'monthly') {
        return date('F Y', $a);
    }
    if ($mode === 'weekly') {
        return 'Week: ' . date('M j', $a) . ' – ' . date('M j, Y', $b);
    }
    if ($mode === 'bi_weekly') {
        return date('M j', $a) . ' – ' . date('M j, Y', $b);
    }
    return date('M j', $a) . ' – ' . date('M j, Y', $b);
}

function sip_period_mode_label(string $mode): string {
    $choices = sip_pay_period_choices();
    return (string)($choices[$mode]['label'] ?? $mode);
}

/** Recent pay periods for a dropdown, newest first. */
function sip_list_pay_periods(string $mode, string $anchor, string $around = '', int $back = 20, int $forward = 3): array {
    $around = preg_match('/^\d{4}-\d{2}-\d{2}$/', $around) ? $around : date('Y-m-d');
    $step = 16;
    if ($mode === 'weekly') {
        $step = 7;
    } elseif ($mode === 'bi_weekly') {
        $step = 14;
    } elseif ($mode === 'monthly') {
        $step = 32;
    }
    $cursor = date('Y-m-d', strtotime($around . ' -' . ($back * $step) . ' days'));
    $limit = date('Y-m-d', strtotime($around . ' +' . ($forward * $step) . ' days'));
    $out = [];
    $seen = [];
    $guard = 0;
    while ($cursor <= $limit && $guard++ < 90) {
        [$start, $end] = sip_period_window($cursor, $mode, $anchor);
        $key = $start . '_' . $end;
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $out[] = [
                'key' => $key,
                'start' => $start,
                'end' => $end,
                'label' => sip_period_label($start, $end, $mode),
            ];
        }
        $cursor = date('Y-m-d', strtotime($end . ' +1 day'));
    }
    return array_reverse($out);
}

function sip_org_gdrive_folder(PDO $pdo, $org_id): array {
    $org_id = (int)$org_id;
    $url = trim((string)(sip_gem_setting_get($pdo, $org_id, 'gdrive_folder_url') ?? ''));
    $label = trim((string)(sip_gem_setting_get($pdo, $org_id, 'gdrive_folder_label') ?? ''));
    return ['url' => $url, 'label' => $label];
}

function sip_set_org_gdrive_folder(PDO $pdo, $org_id, $url, $label = ''): void {
    $org_id = (int)$org_id;
    $url = trim((string)$url);
    $label = trim((string)$label);
    if ($url !== '' && !preg_match('#^https://(drive|docs)\.google\.com/#i', $url)) {
        $url = '';
    }
    sip_gem_setting_set($pdo, $org_id, 'gdrive_folder_url', $url);
    sip_gem_setting_set($pdo, $org_id, 'gdrive_folder_label', $label);
}

function sip_truthy_setting($raw): bool {
    $v = strtolower(trim((string)$raw));
    return in_array($v, ['1', 'yes', 'y', 'true', 'on', 'block'], true);
}

/** Org record: Yes = kiosk blocks off-schedule punches. */
function sip_org_restrain_clockin(PDO $pdo, $org_id): bool {
    $org_id = (int)$org_id;
    $explicit = sip_gem_setting_get($pdo, $org_id, 'restrain_clockin');
    if ($explicit !== null && $explicit !== '') {
        return sip_truthy_setting($explicit);
    }
    $pol = strtolower(trim((string)(sip_gem_setting_get($pdo, $org_id, 'global_schedule_policy') ?? '')));
    if ($pol === 'block') {
        return true;
    }
    if ($pol === 'allow_reason' || $pol === 'allow_free') {
        return false;
    }
    return sip_truthy_setting(sip_gem_setting_get($pdo, $org_id, 'enforce_schedule') ?? '');
}

/** Minutes early before start / late after end still count as on-schedule. */
function sip_org_schedule_margin_minutes(PDO $pdo, $org_id): int {
    $org_id = (int)$org_id;
    $raw = sip_gem_setting_get($pdo, $org_id, 'schedule_margin_minutes');
    if ($raw === null || $raw === '') {
        $raw = sip_gem_setting_get($pdo, $org_id, 'penalty_minutes');
    }
    if ($raw === null || $raw === '') {
        return 5;
    }
    $m = (int)$raw;
    if ($m < 0) {
        $m = 0;
    }
    if ($m > 240) {
        $m = 240;
    }
    return $m;
}

function sip_set_org_clockin_policy(PDO $pdo, $org_id, $restrain, $margin): void {
    $org_id = (int)$org_id;
    $yes = sip_truthy_setting($restrain);
    $m = (int)$margin;
    if ($m < 0) {
        $m = 0;
    }
    if ($m > 240) {
        $m = 240;
    }
    sip_gem_setting_set($pdo, $org_id, 'restrain_clockin', $yes ? '1' : '0');
    sip_gem_setting_set($pdo, $org_id, 'schedule_margin_minutes', (string)$m);
    sip_gem_setting_set($pdo, $org_id, 'global_schedule_policy', $yes ? 'block' : 'allow_reason');
    sip_gem_setting_set($pdo, $org_id, 'enforce_schedule', $yes ? '1' : '0');
}

/**
 * @return array{on_schedule:bool,has_row:bool,outside_window:bool,reason:string}
 */
function sip_employee_schedule_status(PDO $pdo, $employee_id, $site_id, $margin_minutes, $now = null): array {
    $employee_id = (int)$employee_id;
    $site_id = (int)$site_id;
    $now = $now ? (string)$now : date('Y-m-d H:i:s');
    $empty = ['on_schedule' => false, 'has_row' => false, 'outside_window' => true, 'reason' => 'not_scheduled'];
    if ($employee_id <= 0 || $site_id <= 0) {
        return $empty;
    }
    $day = date('Y-m-d', strtotime($now) ?: time());
    try {
        $st = $pdo->prepare("SELECT start_time, end_time FROM Gem_Schedules WHERE employee_id = ? AND RId = ? AND DATE(start_time) = ? ORDER BY start_time ASC");
        $st->execute([$employee_id, $site_id, $day]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $empty;
    }
    if (!$rows) {
        return $empty;
    }
    $now_ts = strtotime($now) ?: time();
    $pad = max(0, (int)$margin_minutes) * 60;
    $first_start = strtotime((string)$rows[0]['start_time']) ?: $now_ts;
    foreach ($rows as $row) {
        $start = strtotime((string)$row['start_time']);
        if ($start === false) {
            continue;
        }
        $end = !empty($row['end_time']) ? strtotime((string)$row['end_time']) : $start;
        if ($end === false || $end < $start) {
            $end = $start;
        }
        if ($now_ts >= ($start - $pad) && $now_ts <= ($end + $pad)) {
            return ['on_schedule' => true, 'has_row' => true, 'outside_window' => false, 'reason' => ''];
        }
    }
    return [
        'on_schedule' => false,
        'has_row' => true,
        'outside_window' => true,
        'reason' => ($now_ts < $first_start) ? 'early' : 'late',
    ];
}

function sip_org_bulk_hours_cap(PDO $pdo, $org_id): float {
    $org_id = (int)$org_id;
    $raw = $org_id > 0 ? sip_gem_setting_get($pdo, $org_id, 'bulk_hours_cap') : null;
    if ($raw === null || $raw === '' || !is_numeric($raw)) {
        return 12.0;
    }
    $h = round((float)$raw, 2);
    if ($h < 0) {
        $h = 0.0;
    }
    if ($h > 24) {
        $h = 24.0;
    }
    return $h;
}

function sip_set_org_bulk_hours_cap(PDO $pdo, $org_id, $hours): void {
    $org_id = (int)$org_id;
    if ($org_id <= 0) {
        return;
    }
    $h = round((float)$hours, 2);
    if ($h < 0) {
        $h = 0.0;
    }
    if ($h > 24) {
        $h = 24.0;
    }
    sip_gem_setting_set($pdo, $org_id, 'bulk_hours_cap', number_format($h, 2, '.', ''));
}

function sip_kiosk_return_page(): string {
    $path = parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
    $ref = basename((string)$path);
    return in_array($ref, ['kiosk.php', 'kiosk1.php'], true) ? $ref : 'kiosk.php';
}

} // function_exists guard
