<?php
/**
 * LOCKED CANONICAL FILE — Menu Manager "Min. Role"
 * Path: /engine/MENU_MIN_ROLE.php
 *
 * Abdul Hafeez Sheikh / RenoCon / eComm i — locked 2026-09-10.
 * This file exists so later agents and edits cannot hide menu links with a
 * second rule (org modules, PAY-lite, "operations" entitlement, etc.).
 *
 * =====================================================================
 * DO NOT OVERRIDE. DO NOT ADD ANOTHER FILTER ON TOP OF THIS FILE.
 * =====================================================================
 *
 * THE ONLY left-menu permission rule is Menu Manager **Min. Role**:
 *
 *   SELECT ... FROM menu_manager
 *   WHERE CAST(IsActive AS UNSIGNED) = 1
 *     AND UserRole >= :lvl
 *
 *   :lvl = the logged-in person's role number (users.user_role).
 *
 * Role numbers (smaller number = more power):
 *   10 Superadmin
 *   20 Admin
 *   25 Site Manager
 *   30 Supervisor
 *   35 Demo
 *   40 Staff
 * 1000 Alarm
 *
 * What that means in plain language:
 *   - Min. Role on a link is the weakest role that may see it.
 *   - Stronger roles (smaller numbers) also see it.
 *   - Weaker roles (larger numbers) do not.
 *   - Staff (40) sees only Min. Role 40 or higher (Staff, Alarm).
 *     Staff cannot see Supervisor (30), Site Manager (25), Admin (20),
 *     or Superadmin (10) links.
 *   - Supervisor (30) sees Min. Role 30 and higher (Supervisor + Staff + …).
 *   - Superadmin (10) sees every active link because every UserRole >= 10.
 *
 * The link must also be Active (IsActive = 1).
 *
 * Orphan parent folders: if a child passed Min. Role but its parent folder
 * did not (example: Daily Attendance Report under an Admin-only folder),
 * generateMenu still shows the parent folder so the child is not lost.
 * That rule lives in engine/menu_generator.php and must stay.
 *
 * The Module column on Menu Manager is a label only. It MUST NOT hide a
 * row that already passed Min. Role. Do not call
 * sip_filter_menu_items_for_session() from generateMenu.
 *
 * generateMenu (engine/menu_generator.php) is required to use
 * sip_menu_fetch_for_login() from this file. The copy at /menu_generator.php
 * is a wrapper — do not put a second SQL gate there.
 */

if (!defined('SIP_MENU_MIN_ROLE_LOCKED')) {
    define('SIP_MENU_MIN_ROLE_LOCKED', true);
}

if (!function_exists('sip_menu_min_role_roles')) {

function sip_menu_min_role_roles(): array
{
    return [
        10   => 'Superadmin',
        20   => 'Admin',
        25   => 'Site Manager',
        30   => 'Supervisor',
        35   => 'Demo',
        40   => 'Staff',
        1000 => 'Alarm',
    ];
}

/** Locked WHERE fragment. Callers must not append module / PAY-lite ANDs. */
function sip_menu_min_role_sql(): array
{
    return [
        'where' => 'CAST(IsActive AS UNSIGNED) = 1 AND UserRole >= :lvl',
        'param' => 'lvl',
    ];
}

function sip_menu_fetch_for_login(PDO $pdo, int $user_lvl): array
{
    $gate = sip_menu_min_role_sql();
    $sql = 'SELECT * FROM menu_manager WHERE ' . $gate['where']
         . ' ORDER BY ParentID ASC, SortOrder ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$gate['param'] => $user_lvl]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Later agents: do not call sip_filter_menu_items_for_session() from the
 * left menu. That function hid Operations / Daily Report / Food Cost from
 * Supervisors when the company did not have an "operations" module.
 * Superadmin skipped that filter, so only Supervisors lost the links.
 */
function sip_menu_must_not_apply_module_filter(): void
{
}

function sip_menu_min_role_plain_english(): string
{
    return 'Min. Role is the weakest role that can see a menu link. '
        . 'Stronger roles (smaller numbers) also see it. Weaker roles '
        . '(larger numbers) do not. Staff cannot see Supervisor links. '
        . 'The Module box does not hide a link that already passed Min. Role.';
}

}
