<?php
/**
 * Employee Hub live search: the ?ajax=list fragment uses the same templates and escaping as the page.
 * Loads only the EH_LIST_PARTS block from employee_hub.php (no database, no session).
 * Run: php tests/employee_hub_live_search_test.php
 */
$fail = 0;
function check($label, $cond): void
{
    global $fail;
    if ($cond) {
        echo "ok  $label\n";
        return;
    }
    echo "FAIL $label\n";
    $fail++;
}

$src = file_get_contents(__DIR__ . '/../employee_hub.php');
$a = strpos($src, "if (!function_exists('eh_edit_payload'))");
$b = strpos($src, '// EH_LIST_PARTS_END');
check('list parts block found', $a !== false && $b !== false && $b > $a);

// Page helpers the block calls (real ones live earlier in employee_hub.php / payroll_engine).
function eh_format_assigned_sites($csv, array $site_by_id): string { return 'Site <b>"x"</b> (#' . $csv . ')'; }
function eh_site_option_label(array $s, bool $show_org = false): string { return (string)($s['nick_name'] ?? ''); }
eval('?>' . "<?php\n" . substr($src, $a, $b - $a));

$evil = '"><img src=x onerror=alert(1)>';
$users = [[
    'id' => '7', 'Name' => $evil, 'email' => "a'b@x.com<script>", 'user' => '<u>', 'org_id' => '2"', 'org_name' => '<Org>',
    'assigned_site_ids' => '5', 'role_name' => '<Role>', 'employee_type' => 'hourly', 'hourly_rate' => '18.5',
    'rate_effective_date' => '<d>', 'active' => 1, 'live_status' => null, 'direct_deposit_file' => '', 'ei_exempt' => 0,
]];
$rows = eh_list_rows_html($users, 1, []);
check('name is escaped in rows', strpos($rows, $evil) === false && strpos($rows, '&quot;&gt;&lt;img src=x') !== false);
check('email, username, org, role, rate date escaped', strpos($rows, '<script>') === false && strpos($rows, '<u>') === false
    && strpos($rows, '<Org>') === false && strpos($rows, '<Role>') === false && strpos($rows, '<d>') === false);
check('site label escaped', strpos($rows, '<b>') === false && strpos($rows, '&lt;b&gt;') !== false);
check('row ids are ints', strpos($rows, 'value="7"') !== false && strpos($rows, '(#2)') !== false);
check('Edit profile payload is attribute-escaped JSON', preg_match('/onclick="editUser\(([^"]*)\)"/', $rows, $m) === 1
    && json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true)['Name'] === $evil);
check('org column only for superadmin', strpos(eh_list_rows_html($users, 20, []), '&lt;Org&gt;') === false && strpos($rows, '&lt;Org&gt;') !== false);
check('empty list shows the no-match row', strpos(eh_list_rows_html([], 20, []), 'No employees match this search.') !== false
    && strpos(eh_list_rows_html([], 20, []), 'colspan="10"') !== false && strpos(eh_list_rows_html([], 1, []), 'colspan="11"') !== false);

$ctx = [
    'total_match' => 3, 'shown_from' => 1, 'shown_to' => 2, 'search_term' => $evil, 'target_site_filter' => 'all',
    'target_org_filter' => 'all', 'site_by_id' => [], 'org_total' => 9, 'total_pages' => 2, 'page' => 1,
    'per_page_opt' => '50', 'user_level' => 1, 'sort_key' => 'name', 'sort_dir' => 'asc',
];
$showing = eh_list_showing_html($ctx);
check('showing text has counts', strpos($showing, 'Showing 1') !== false && strpos($showing, 'of 3 employees') !== false && strpos($showing, '(9 in all orgs)') !== false);
check('search term escaped in showing text', strpos($showing, $evil) === false && strpos($showing, '&quot;&gt;&lt;img') !== false);
$pager = eh_list_pager_html($ctx);
check('pager links keep search, escaped', strpos($pager, 'Next') !== false && strpos($pager, '<img') === false && strpos($pager, 'search=%22%3E%3Cimg') !== false);
check('no pager on one page', trim(eh_list_pager_html(['total_pages' => 1] + $ctx)) === '');

$json = json_encode(['rows' => $rows, 'showing' => $showing], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
check('JSON body has no raw < or >', $json !== false && strpbrk($json, '<>') === false);

// Source checks: one query path, endpoint after the access rules, before any HTML.
$guard = strpos($src, "osg_page(['level' => 30");
$scope = strpos($src, "\$_SESSION['access_scope'] === 'restricted'");
$query = strpos($src, '$stmt->execute($query_params);');
$ajax = strpos($src, "(string)(\$_GET['ajax'] ?? '') === 'list'");
$html = strpos($src, '<!DOCTYPE html>');
check('endpoint runs after the org-scope guard, scope rules and the shared query', $guard < $scope && $scope < $query && $query < $ajax);
check('endpoint exits before the page HTML', $ajax < $html && strpos(substr($src, $ajax, $html - $ajax), 'exit;') !== false);
check('still a single list query (no copy for ajax)', substr_count($src, 'FROM users u') === 2);
check('page and fragment share the same renderers', substr_count($src, 'eh_list_rows_html($users') === 2 && substr_count($src, 'eh_list_showing_html($eh_list_ctx)') === 2 && substr_count($src, 'eh_list_pager_html($eh_list_ctx)') === 2);
check('search box no longer reloads per key', strpos($src, 'onkeyup="searchDebounce') === false && strpos($src, 'AbortController') !== false && strpos($src, 'history.replaceState') !== false);

echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);
