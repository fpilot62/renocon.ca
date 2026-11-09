<?php
/**
 * Path: /payroll_sheet_review.php
 * Admin review of an imported sheet. Draft saves do not change payroll.
 * Approve posts only the previewed safe rows. Deploy with
 * migrations/20261108_payroll_sheet_review.sql and engine/payroll_sheet_review_lib.php.
 * Uploads are stored outside the web root. Do not commit source workbooks.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] === '') {
    header('Location: login_kiosk.php?err=session_expired');
    exit;
}
$sess_level = (int)($_SESSION['user_level'] ?? 99);
if ($sess_level > 20) {
    http_response_code(403);
    exit('Sheet review is for admins only.');
}
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';
require_once 'payroll_scope.php';
require_once __DIR__ . '/engine/payroll_sheet_review_lib.php';
require_once __DIR__ . '/engine/payroll_sheet_grid_lib.php';
ps_require_payroll_admin();
if (is_file(__DIR__ . '/engine/menu_generator.php')) {
    require_once __DIR__ . '/engine/menu_generator.php';
}

function psr_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function psr_money($value, bool $known): string
{
    if (!$known) {
        return 'Pending';
    }
    return number_format((float)$value, 2);
}
function psr_total_money(array $total, string $field): string
{
    if ($field === 'remaining_known') {
        return $total['remaining_lines'] > 0 ? number_format((float)$total['remaining_known'], 2) : 'Pending';
    }
    return number_format((float)$total[$field], 2);
}

$actorId = (int)$_SESSION['user_id'];
$isSuper = function_exists('ps_is_superadmin') && ps_is_superadmin();
$homeOrg = function_exists('ps_selected_org') ? (int)ps_selected_org($pdo) : (int)($_SESSION['org_id'] ?? 0);
$orgs = [];
if ($isSuper) {
    try {
        $orgs = $pdo->query('SELECT id, org_name FROM organizations ORDER BY org_name ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $orgs = [];
    }
}
$requestedOrg = (int)($_POST['org_id'] ?? $_GET['org_id'] ?? 0);
if (!psr_may_view_org($isSuper, $homeOrg, $requestedOrg > 0 ? $requestedOrg : $homeOrg)) {
    http_response_code(403);
    exit('That company is outside your access.');
}
$orgId = $requestedOrg > 0 && psr_may_view_org($isSuper, $homeOrg, $requestedOrg) ? $requestedOrg : $homeOrg;
if ($orgId <= 0) {
    exit('No company is selected.');
}
try {
    psr_ensure($pdo);
    psr_ensure_grid_columns($pdo);
    psr_ensure_menu($pdo);
} catch (Throwable $e) {
    exit('Sheet review storage is not ready. ' . psr_h($e->getMessage()));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_require_csrf();
}
$flash = (string)($_SESSION['psr_flash'] ?? '');
unset($_SESSION['psr_flash']);
$warnings = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stage_workbook'])) {
    try {
        $stored = psr_store_upload($_FILES['workbook'] ?? []);
        $token = bin2hex(random_bytes(8));
        $_SESSION['psr_stage'][$token] = ['org_id' => $orgId, 'stored' => $stored, 'kind' => 'workbook', 'original' => (string)($_FILES['workbook']['name'] ?? 'workbook')];
        if (!empty($_FILES['payments']['name'])) {
            $_SESSION['psr_stage'][$token]['events'] = psr_store_upload($_FILES['payments']);
        }
        header('Location: payroll_sheet_review.php?org_id=' . $orgId . '&stage=' . urlencode($token));
        exit;
    } catch (Throwable $e) {
        $warnings[] = $e->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stage_proposal'])) {
    try {
        $stored = psr_store_upload($_FILES['proposal'] ?? []);
        $token = bin2hex(random_bytes(8));
        $_SESSION['psr_stage'][$token] = ['org_id' => $orgId, 'stored' => $stored, 'kind' => 'proposal', 'original' => (string)($_FILES['proposal']['name'] ?? 'proposal')];
        header('Location: payroll_sheet_review.php?org_id=' . $orgId . '&stage=' . urlencode($token));
        exit;
    } catch (Throwable $e) {
        $warnings[] = $e->getMessage();
    }
}
$stage = null;
$stageRows = [];
$stageEvents = [];
$stageDups = [];
$stageToken = (string)($_GET['stage'] ?? $_POST['stage_token'] ?? '');
if ($stageToken !== '' && isset($_SESSION['psr_stage'][$stageToken]) && (int)$_SESSION['psr_stage'][$stageToken]['org_id'] === $orgId) {
    $stage = $_SESSION['psr_stage'][$stageToken];
    try {
        $stageRows = psr_parse_upload(psr_read_private($stage['stored']), $stage['kind']);
        if (!empty($stage['events'])) {
            $stageEvents = psr_parse_upload(psr_read_private($stage['events']), 'events');
        }
        if ($stage['kind'] === 'workbook') {
            $have = $pdo->prepare('SELECT source_sheet, source_row, row_kind, employee_name, store_name, period_start, period_end FROM payroll_sheet_rows WHERE org_id = ?');
            $have->execute([$orgId]);
            $stageDups = psr_duplicate_findings($have->fetchAll(PDO::FETCH_ASSOC), $stageRows);
        }
    } catch (Throwable $e) {
        $warnings[] = $e->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['commit_stage']) && $stage) {
    try {
        if ($stage['kind'] === 'workbook') {
            if ($stageDups && empty($_POST['import_duplicates'])) {
                throw new RuntimeException('Duplicates were found. Import again only if you intend to keep both copies.');
            }
            psr_commit_workbook($pdo, $orgId, $actorId, $stage['stored'], (string)$stage['original'], $stageRows, empty($_POST['import_duplicates']));
            if ($stageEvents) {
                psr_commit_events($pdo, $orgId, $actorId, $stageEvents);
            }
        } else {
            $planId = psr_save_plan($pdo, $orgId, $actorId, [
                'plan_date' => $stageRows[0]['plan_date'] ?? date('Y-m-d'),
                'title' => 'Proposal upload',
                'available_amount' => '',
                'note' => 'Uploaded proposal. This is not an actual payment.',
            ]);
            foreach ($stageRows as $prow) {
                psr_save_plan_line($pdo, $orgId, $actorId, $planId, $prow);
            }
        }
        unset($_SESSION['psr_stage'][$stageToken]);
        $_SESSION['psr_flash'] = 'Upload saved as a draft. Payroll was not changed.';
        header('Location: payroll_sheet_review.php?org_id=' . $orgId);
        exit;
    } catch (Throwable $e) {
        $warnings[] = $e->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_draft'])) {
    try {
        psr_save_draft_row($pdo, $orgId, (int)$_POST['row_id'], $actorId, $_POST);
        $_SESSION['psr_flash'] = 'Draft saved. Payroll was not changed.';
        header('Location: payroll_sheet_review.php?org_id=' . $orgId . '&edit=' . (int)$_POST['row_id']);
        exit;
    } catch (Throwable $e) {
        $warnings[] = $e->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_plan'])) {
    try {
        psr_save_plan($pdo, $orgId, $actorId, $_POST);
        $_SESSION['psr_flash'] = 'Plan saved as a proposal. Nobody was marked paid.';
        header('Location: payroll_sheet_review.php?org_id=' . $orgId . '#plans');
        exit;
    } catch (Throwable $e) {
        $warnings[] = $e->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_plan_line'])) {
    try {
        psr_save_plan_line($pdo, $orgId, $actorId, (int)$_POST['plan_id'], $_POST);
        $_SESSION['psr_flash'] = 'Proposed line saved. It is not an actual payment.';
        header('Location: payroll_sheet_review.php?org_id=' . $orgId . '#plans');
        exit;
    } catch (Throwable $e) {
        $warnings[] = $e->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['link_identity'])) {
    try {
        psr_link_identity($pdo, $orgId, (int)$_POST['user_id'], (int)$_POST['other_org_id'], (int)$_POST['other_user_id'], $actorId, $isSuper);
        $_SESSION['psr_flash'] = 'The other company is linked for a side-by-side view. Its total is not added to this company.';
        header('Location: payroll_sheet_review.php?org_id=' . $orgId);
        exit;
    } catch (Throwable $e) {
        $warnings[] = $e->getMessage();
    }
}
$previewPlans = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_approval'])) {
    $ids = array_map('intval', (array)($_POST['row_ids'] ?? []));
    try {
        $previewPlans = psr_build_plans($pdo, $orgId, $ids);
        $key = bin2hex(random_bytes(16));
        $_SESSION['psr_preview'] = ['org_id' => $orgId, 'key' => $key, 'hash' => psr_plan_hash($previewPlans), 'row_ids' => $ids];
    } catch (Throwable $e) {
        $warnings[] = $e->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_approval'])) {
    $saved = $_SESSION['psr_preview'] ?? null;
    try {
        if (!$saved || (int)$saved['org_id'] !== $orgId || (string)$saved['key'] !== (string)($_POST['idempotency_key'] ?? '')) {
            throw new RuntimeException('Preview this approval again before confirming it.');
        }
        $fresh = psr_build_plans($pdo, $orgId, $saved['row_ids']);
        if (psr_plan_hash($fresh) !== $saved['hash']) {
            throw new RuntimeException('The draft changed after the preview. Preview it again. Nothing was posted.');
        }
        $done = psr_apply_approval($pdo, $orgId, $actorId, $saved['row_ids'], $saved['key']);
        unset($_SESSION['psr_preview']);
        $_SESSION['psr_flash'] = !empty($done['repeated'])
            ? 'That approval was already confirmed. It was not posted again.'
            : 'Approval ' . (int)$done['approval_id'] . ' posted ' . (int)$done['result']['created'] . ' new line(s), linked ' . (int)$done['result']['linked'] . ', and recorded $' . number_format((float)$done['result']['amount'], 2) . ' of confirmed actual payments.';
        header('Location: payroll_sheet_review.php?org_id=' . $orgId);
        exit;
    } catch (Throwable $e) {
        $warnings[] = $e->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['undo_approval'])) {
    try {
        psr_undo_approval($pdo, $orgId, $actorId, (int)$_POST['approval_id']);
        $_SESSION['psr_flash'] = 'Approval undone. Existing wages and deductions were not rewritten. Only payments this approval created were reversed, and only lines this approval created were removed when that was safe.';
        header('Location: payroll_sheet_review.php?org_id=' . $orgId);
        exit;
    } catch (Throwable $e) {
        $warnings[] = $e->getMessage();
    }
}

$filter = [
    'user_id' => (int)($_GET['user_id'] ?? 0),
    'site_id' => (int)($_GET['site_id'] ?? 0),
    'period_start' => psr_valid_date($_GET['period_start'] ?? ''),
    'period_end' => psr_valid_date($_GET['period_end'] ?? ''),
    'org_id' => $orgId,
];
$systemLines = psr_fetch_system_lines($pdo, $orgId);
$effects = psr_active_effects($pdo, $orgId);
$wageRows = [];
foreach ($effects as $ef) {
    if ($ef['effect_kind'] === 'post_payment') {
        continue;
    }
    $wageRows[(int)$ef['row_id']][] = (int)$ef['finalized_id'];
    $detail = json_decode((string)($ef['detail_json'] ?? ''), true);
    if (is_array($detail)) {
        foreach ($detail as $fid) {
            if (is_int($fid) || ctype_digit((string)$fid)) {
                $wageRows[(int)$ef['row_id']][] = (int)$fid;
            }
        }
    }
}
$rows = $pdo->prepare('SELECT * FROM payroll_sheet_rows WHERE org_id = ? AND deleted_at IS NULL ORDER BY source_sheet, source_row, id');
$rows->execute([$orgId]);
$sheetRows = $rows->fetchAll(PDO::FETCH_ASSOC);
$approved = [];
$drafts = [];
foreach ($sheetRows as $row) {
    if (($row['row_kind'] ?? '') !== 'employee_record') {
        continue;
    }
    if (isset($wageRows[(int)$row['id']])) {
        $approved[] = [
            'id' => (int)$row['id'], 'org_id' => $orgId, 'user_id' => (int)$row['user_id'], 'site_id' => (int)$row['site_id'],
            'employee_name' => $row['employee_name'], 'store_name' => $row['store_name'],
            'period_start' => $row['period_start'], 'period_end' => $row['period_end'],
            'finalized_ids' => array_values(array_unique($wageRows[(int)$row['id']])),
            'gross' => $row['gross'], 'net' => $row['net'], 'net_known' => $row['net'] !== null,
            'paid' => 0.0, 'status' => $row['reported_status'],
        ];
    } else {
        $drafts[] = $row;
    }
}
$companies = [[
    'org_id' => $orgId,
    'org_name' => 'This company',
    'system_lines' => $systemLines,
    'approved_sheet' => $approved,
    'draft_sheet' => $drafts,
]];
foreach ($orgs as $org) {
    if ((int)$org['id'] === $orgId) {
        $companies[0]['org_name'] = (string)$org['org_name'];
    }
}
if ($isSuper) {
    $links = $pdo->prepare('SELECT * FROM payroll_sheet_identity_links WHERE org_id = ?');
    $links->execute([$orgId]);
    $seen = [];
    foreach ($links->fetchAll(PDO::FETCH_ASSOC) as $link) {
        $key = (int)$link['other_org_id'] . ':' . (int)$link['other_user_id'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $name = 'Company ' . (int)$link['other_org_id'];
        foreach ($orgs as $org) {
            if ((int)$org['id'] === (int)$link['other_org_id']) {
                $name = (string)$org['org_name'];
            }
        }
        $companies[] = [
            'org_id' => (int)$link['other_org_id'],
            'org_name' => $name,
            'system_lines' => psr_fetch_system_lines($pdo, (int)$link['other_org_id'], (int)$link['other_user_id']),
            'approved_sheet' => [],
            'draft_sheet' => [],
        ];
    }
}
$report = psr_companies_report($companies, $filter);
$events = $pdo->prepare('SELECT * FROM payroll_sheet_events WHERE org_id = ? ORDER BY id DESC LIMIT 200');
$events->execute([$orgId]);
$eventRows = $events->fetchAll(PDO::FETCH_ASSOC);
$plans = $pdo->prepare('SELECT * FROM payroll_sheet_plans WHERE org_id = ? ORDER BY plan_date DESC, id DESC');
$plans->execute([$orgId]);
$planRows = $plans->fetchAll(PDO::FETCH_ASSOC);
$planLines = [];
if ($planRows) {
    $pl = $pdo->prepare('SELECT * FROM payroll_sheet_plan_lines WHERE org_id = ? ORDER BY id ASC');
    $pl->execute([$orgId]);
    foreach ($pl->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $planLines[(int)$line['plan_id']][] = $line;
    }
}
$approvals = $pdo->prepare('SELECT * FROM payroll_sheet_approvals WHERE org_id = ? ORDER BY id DESC LIMIT 30');
$approvals->execute([$orgId]);
$approvalRows = $approvals->fetchAll(PDO::FETCH_ASSOC);
$staff = $pdo->prepare('SELECT id, Name FROM users WHERE org_id = ? ORDER BY Name ASC');
$staff->execute([$orgId]);
$staffRows = $staff->fetchAll(PDO::FETCH_ASSOC);
$sites = $pdo->prepare('SELECT SId, nick_name FROM sites WHERE org_id = ? ORDER BY nick_name ASC');
$sites->execute([$orgId]);
$siteRows = $sites->fetchAll(PDO::FETCH_ASSOC);
$edit = null;
if ((int)($_GET['edit'] ?? 0) > 0) {
    foreach ($sheetRows as $row) {
        if ((int)$row['id'] === (int)$_GET['edit']) {
            $edit = $row;
        }
    }
}
$revisions = [];
if ($edit) {
    $rv = $pdo->prepare('SELECT * FROM payroll_sheet_revisions WHERE org_id = ? AND target_type = ? AND target_id = ? ORDER BY id DESC LIMIT 15');
    $rv->execute([$orgId, 'row', (int)$edit['id']]);
    $revisions = $rv->fetchAll(PDO::FETCH_ASSOC);
}
$flatPlans = [];
foreach ($planLines as $list) {
    foreach ($list as $line) {
        $flatPlans[] = $line;
    }
}
$directory = [];
foreach ($staffRows as $person) {
    $directory[] = ['id' => (int)$person['id'], 'name' => (string)$person['Name']];
}
foreach ($systemLines as $ln) {
    $directory[] = ['id' => (int)$ln['user_id'], 'name' => (string)$ln['employee_name']];
}
$gridFilter = [
    'store' => trim((string)($_GET['store'] ?? '')),
    'person' => trim((string)($_GET['person'] ?? '')),
    'year' => trim((string)($_GET['year'] ?? '')),
    'period_start' => $filter['period_start'],
    'period_end' => $filter['period_end'],
    'source' => (string)($_GET['source'] ?? 'all'),
    'pay_status' => (string)($_GET['pay_status'] ?? 'all'),
    'unresolved' => !empty($_GET['unresolved']),
    'route' => (string)($_GET['route'] ?? 'all'),
    'active' => (string)($_GET['active'] ?? 'all'),
];
$gridAll = psr_build_review_grid($systemLines, $sheetRows, $flatPlans, $directory, $orgId);
$gridFiltered = psr_grid_filter($gridAll, $gridFilter);
$gridPage = psr_grid_page($gridFiltered, (int)($_GET['page'] ?? 1), (int)($_GET['per_page'] ?? 25));
$gridTotals = psr_grid_totals($gridFiltered);
$gridDiff = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_grid_save'])) {
    $byKey = [];
    foreach ($gridPage['rows'] as $grow) {
        $byKey[$grow['key']] = $grow;
    }
    $gridDiff = psr_grid_save_changes($_POST['g'] ?? [], $byKey);
    $_SESSION['psr_grid'] = ['org_id' => $orgId, 'diff' => $gridDiff];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_grid_save'])) {
    $saved = $_SESSION['psr_grid'] ?? null;
    try {
        if (!$saved || (int)$saved['org_id'] !== $orgId || empty($saved['diff']['ok'])) {
            throw new RuntimeException('Preview the spreadsheet changes again. Nothing was saved.');
        }
        psr_apply_grid_drafts($pdo, $orgId, $actorId, $saved['diff']['changes']);
        unset($_SESSION['psr_grid']);
        $_SESSION['psr_flash'] = 'Draft spreadsheet saved. Payroll wages and payments were not changed. Source Paid and Unpaid text was only edited where you typed a correction.';
        header('Location: payroll_sheet_review.php?org_id=' . $orgId);
        exit;
    } catch (Throwable $e) {
        $warnings[] = $e->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_plan_rows'])) {
    $planPreview = [];
    foreach ((array)($_POST['plan_ids'] ?? []) as $sheetId) {
        $sheetId = (int)$sheetId;
        $match = null;
        foreach ($gridPage['rows'] as $grow) {
            if ((int)$grow['sheet_row_id'] === $sheetId) {
                $match = $grow;
            }
        }
        if (!$match) {
            $planPreview[] = ['sheet_row_id' => $sheetId, 'ok' => false, 'note' => 'That row is not on this page.'];
            continue;
        }
        $planPreview[] = ['sheet_row_id' => $sheetId, 'name' => $match['employee_name'], 'plan' => psr_plan_approval_plan($match)];
    }
    $_SESSION['psr_plan_preview'] = ['org_id' => $orgId, 'rows' => $planPreview];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_plan_rows'])) {
    $saved = $_SESSION['psr_plan_preview'] ?? null;
    try {
        if (!$saved || (int)$saved['org_id'] !== $orgId) {
            throw new RuntimeException('Preview the proposal again. Nothing was approved.');
        }
        $ids = [];
        foreach ($saved['rows'] as $item) {
            if (empty($item['plan']['posts_payment']) && empty($item['plan']['creates_wage'])) {
                $q = $pdo->prepare('SELECT id FROM payroll_sheet_plan_lines WHERE org_id = ? AND row_id = ? ORDER BY id DESC LIMIT 1');
                $q->execute([$orgId, (int)$item['sheet_row_id']]);
                $pid = (int)$q->fetchColumn();
                if ($pid > 0) {
                    $ids[] = $pid;
                }
            }
        }
        $result = psr_approve_plan_lines($pdo, $orgId, $actorId, $ids);
        unset($_SESSION['psr_plan_preview']);
        $_SESSION['psr_flash'] = 'Proposal approval recorded for ' . (int)$result['approved'] . ' line(s). Already approved: ' . (int)$result['already'] . '. Payments posted: 0.';
        header('Location: payroll_sheet_review.php?org_id=' . $orgId);
        exit;
    } catch (Throwable $e) {
        $warnings[] = $e->getMessage();
    }
}
$suggestRows = [];
foreach ($gridFiltered as $grow) {
    $grow['scope_org_id'] = $orgId;
    $suggestRows[] = $grow;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['suggest_payments'])) {
    $suggested = psr_suggest_payments($suggestRows, $_POST['available_funds'] ?? '', (string)($_POST['suggest_policy'] ?? ''), $_POST['plan_date'] ?? '');
    if (empty($suggested['ok'])) {
        $warnings[] = $suggested['error'] !== '' ? $suggested['error'] : 'The suggestion was not created.';
        unset($_SESSION['psr_suggestion']);
    } else {
        $versions = psr_suggestion_versions($suggestRows);
        $_SESSION['psr_suggestion'] = [
            'org_id' => $orgId,
            'available_funds' => $suggested['available_funds'],
            'policy' => $suggested['policy'],
            'plan_date' => $suggested['plan_date'],
            'versions' => $versions,
            'fingerprint' => psr_suggestion_fingerprint($suggested, $versions),
            'suggestion' => $suggested,
        ];
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_suggestion'])) {
    unset($_SESSION['psr_suggestion']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_suggestion'])) {
    try {
        $stored = $_SESSION['psr_suggestion'] ?? null;
        if (!is_array($stored)) {
            throw new RuntimeException('Preview the suggestion again. Nothing was saved.');
        }
        $check = psr_revalidate_suggestion($stored, $suggestRows, $orgId);
        if (empty($check['ok'])) {
            throw new RuntimeException($check['error']);
        }
        $savedRound = psr_save_suggestion_round($pdo, $orgId, $actorId, $check['suggestion'], $suggestRows);
        unset($_SESSION['psr_suggestion']);
        $_SESSION['psr_flash'] = !empty($savedRound['already'])
            ? 'That dated suggestion was already saved. It was not applied again. Nobody was marked paid.'
            : 'Suggestion saved as a draft pay proposal for ' . (string)$check['suggestion']['plan_date'] . '. It is not approved, not imported, and not a payment.';
        header('Location: payroll_sheet_review.php?org_id=' . $orgId . '#plans');
        exit;
    } catch (Throwable $e) {
        $warnings[] = $e->getMessage();
    }
}
$planPreview = (isset($_SESSION['psr_plan_preview']['rows']) && (int)($_SESSION['psr_plan_preview']['org_id'] ?? 0) === $orgId)
    ? $_SESSION['psr_plan_preview']['rows']
    : [];
$suggestionPreview = (isset($_SESSION['psr_suggestion']['suggestion']) && (int)($_SESSION['psr_suggestion']['org_id'] ?? 0) === $orgId)
    ? $_SESSION['psr_suggestion']
    : null;
$suggestByKey = [];
if ($suggestionPreview) {
    foreach ($suggestionPreview['suggestion']['lines'] as $line) {
        $suggestByKey[(string)$line['key']] = $line;
    }
}
$payHeading = 'Pay proposal';
if ($suggestionPreview && !empty($suggestionPreview['plan_date'])) {
    $payHeading = 'Pay proposal for ' . $suggestionPreview['plan_date'];
}
$csrf = ps_csrf_token();
$byUser = [];
foreach ($report['companies'][0]['employees'] as $emp) {
    $byUser[(int)$emp['user_id']] = $emp;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sheet review</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { background:#f1f5f9; padding:90px 16px 40px; font-size:13px; color:#1e293b; }
        .sheet { background:#fff; border:1px solid #cbd5e1; border-radius:12px; padding:16px; margin-bottom:16px; }
        table.grid td, table.grid th { vertical-align:top; }
        table.grid th { background:#0f172a; color:#fff; font-size:11px; }
        .pending { color:#9a3412; font-weight:700; }
        .preview { background:#fff7ed; }
        .live { background:#f0fdf4; }
        .grid-wrap { max-height: 72vh; overflow: auto; border: 1px solid #cbd5e1; }
        table.paygrid { border-collapse: separate; border-spacing: 0; min-width: 1680px; }
        table.paygrid th { position: sticky; top: 0; z-index: 3; background: #0f172a; color: #fff; font-size: 11px; }
        table.paygrid td.ident, table.paygrid th.ident { position: sticky; left: 0; z-index: 2; background: #fff; }
        table.paygrid th.ident { z-index: 4; background: #0f172a; }
        td.today input { background: #fef3c7; border: 2px solid #d97706; font-weight: 800; }
        .src-paid { background: #dcfce7; }
        .src-stale { background: #ffedd5; }
        .src-conflict { background: #fee2e2; }
    </style>
</head>
<body>
<?= function_exists('generateMenu') ? generateMenu($pdo) : '' ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h4 class="font-weight-bold mb-1">Sheet review</h4>
            <p class="mb-0">Drafts stay drafts until you preview and confirm an approval. Proposals are not actual payments. Totals below are every period already in this company, not only the uploaded sheet.</p>
        </div>
        <a class="btn btn-outline-dark font-weight-bold" href="payroll_master_ledger.php?org_id=<?= (int)$orgId ?>">Back to Payroll Center</a>
    </div>
    <?php if ($flash !== ''): ?><div class="alert alert-info"><?= psr_h($flash) ?></div><?php endif; ?>
    <?php if ($warnings): ?><div class="alert alert-warning"><?php foreach ($warnings as $w): ?><div><?= nl2br(psr_h($w)) ?></div><?php endforeach; ?></div><?php endif; ?>

    <form method="GET" class="sheet">
        <div class="form-row">
            <?php if ($isSuper): ?>
            <div class="col-md-3 mb-2">
                <label>Company</label>
                <select name="org_id" class="form-control form-control-sm" onchange="this.form.submit()">
                    <?php foreach ($orgs as $org): ?>
                        <option value="<?= (int)$org['id'] ?>" <?= (int)$org['id'] === $orgId ? 'selected' : '' ?>><?= psr_h($org['org_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?><input type="hidden" name="org_id" value="<?= (int)$orgId ?>"><?php endif; ?>
            <div class="col-md-2 mb-2"><label>Employee id</label><input class="form-control form-control-sm" name="user_id" value="<?= (int)$filter['user_id'] ?: '' ?>"></div>
            <div class="col-md-2 mb-2"><label>Store id</label><input class="form-control form-control-sm" name="site_id" value="<?= (int)$filter['site_id'] ?: '' ?>"></div>
            <div class="col-md-2 mb-2"><label>Period from</label><input type="date" class="form-control form-control-sm" name="period_start" value="<?= psr_h((string)$filter['period_start']) ?>"></div>
            <div class="col-md-2 mb-2"><label>Period to</label><input type="date" class="form-control form-control-sm" name="period_end" value="<?= psr_h((string)$filter['period_end']) ?>"></div>
            <div class="col-md-2 mb-2"><label>Person</label><input class="form-control form-control-sm" name="person" value="<?= psr_h($gridFilter['person']) ?>"></div>
            <div class="col-md-2 mb-2"><label>Store</label><input class="form-control form-control-sm" name="store" value="<?= psr_h($gridFilter['store']) ?>"></div>
            <div class="col-md-1 mb-2"><label>Year</label><input class="form-control form-control-sm" name="year" value="<?= psr_h($gridFilter['year']) ?>"></div>
            <div class="col-md-2 mb-2"><label>Source</label><select class="form-control form-control-sm" name="source"><?php foreach (['all','system','sheet','combined'] as $opt): ?><option <?= $gridFilter['source']===$opt?'selected':'' ?>><?= psr_h($opt) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 mb-2"><label>Source pay status</label><select class="form-control form-control-sm" name="pay_status"><?php foreach (['all','paid','not_paid','unknown','system_paid','conflict'] as $opt): ?><option <?= $gridFilter['pay_status']===$opt?'selected':'' ?>><?= psr_h($opt) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 mb-2"><label>Route</label><select class="form-control form-control-sm" name="route"><?php foreach (['all','unset','cash','adp'] as $opt): ?><option <?= $gridFilter['route']===$opt?'selected':'' ?>><?= psr_h($opt) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 mb-2"><label>Staff</label><select class="form-control form-control-sm" name="active"><?php foreach (['all','active'] as $opt): ?><option <?= $gridFilter['active']===$opt?'selected':'' ?>><?= psr_h($opt) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 mb-2"><label>Unresolved</label><select class="form-control form-control-sm" name="unresolved"><option value="">All</option><option value="1" <?= !empty($gridFilter['unresolved'])?'selected':'' ?>>Needs review</option></select></div>
            <div class="col-md-1 mb-2"><label>&nbsp;</label><button class="btn btn-sm btn-primary btn-block">Filter</button></div>
        </div>
        <p class="mb-0 text-muted">Filters change this spreadsheet only. They do not certify a live unpaid balance. The company all-period total stays available under the details below.</p>
    </form>

    <form method="POST" id="gridForm" class="sheet">
        <input type="hidden" name="csrf_token" value="<?= psr_h($csrf) ?>">
        <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
            <div>
                <h5 class="mb-1">Pay spreadsheet</h5>
                <p class="mb-0">System lines and older sheet rows for this company. The pay proposal is a draft until you preview and confirm it. Confirming a proposal does not mark anyone paid. Source Paid and Unpaid stay as evidence. A blank imported date stays blank.</p>
            </div>
            <div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="selectVisible">Select visible</button>
                <button class="btn btn-sm btn-primary" name="preview_grid_save" value="1">Preview draft save</button>
                <button class="btn btn-sm btn-outline-dark" name="preview_approval" value="1">Preview obligation approval</button>
                <button class="btn btn-sm btn-outline-warning" name="preview_plan_rows" value="1">Preview proposal approval</button>
            </div>
        </div>
        <?php if ($gridDiff): ?>
            <div class="alert <?= !empty($gridDiff['ok']) ? 'alert-info' : 'alert-warning' ?>">
                <?php if (!empty($gridDiff['ok'])): ?>
                    <form method="POST" class="mb-0">
                        <input type="hidden" name="csrf_token" value="<?= psr_h($csrf) ?>">
                        <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
                        <?= count($gridDiff['changes']) ?> draft change(s). Payroll will not be written.
                        <button class="btn btn-sm btn-warning" name="confirm_grid_save" value="1">Confirm draft save</button>
                    </form>
                <?php else: foreach ($gridDiff['blocked'] as $block): ?>
                    <div><?= psr_h($block['key']) ?>: <?= psr_h($block['reason']) ?></div>
                <?php endforeach; endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($planPreview): ?>
            <div class="alert alert-warning">
                <?php foreach ($planPreview as $item): ?>
                    <div><?= psr_h($item['name'] ?? ('Row ' . ($item['sheet_row_id'] ?? ''))) ?>: <?= psr_h($item['plan']['note'] ?? ($item['note'] ?? '')) ?> Payment posted: no. Wage created: no.</div>
                <?php endforeach; ?>
                <form method="POST" class="mt-2 mb-0">
                    <input type="hidden" name="csrf_token" value="<?= psr_h($csrf) ?>">
                    <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
                    <button class="btn btn-sm btn-warning" name="confirm_plan_rows" value="1">Confirm proposal approval</button>
                </form>
            </div>
        <?php endif; ?>
        <p>
            Filtered rows <?= (int)$gridTotals['rows'] ?>.
            Known gross <?= $gridTotals['gross_known_sum'] === null ? 'Pending' : number_format((float)$gridTotals['gross_known_sum'], 2) ?>
            (<?= (int)$gridTotals['gross_missing'] ?> missing).
            Known net <?= $gridTotals['net_known_sum'] === null ? 'Pending' : number_format((float)$gridTotals['net_known_sum'], 2) ?>
            (<?= (int)$gridTotals['net_unknown'] ?> unknown).
            Confirmed paid <?= $gridTotals['paid_known_sum'] === null ? 'Pending' : number_format((float)$gridTotals['paid_known_sum'], 2) ?>.
            Known balance before the pay proposal <?= $gridTotals['balance_known_sum'] === null ? 'Pending' : number_format((float)$gridTotals['balance_known_sum'], 2) ?>
            (<?= (int)$gridTotals['balance_incomplete'] ?> incomplete).
            Source conflicts <?= (int)$gridTotals['source_conflicts'] ?>.
            Unresolved <?= (int)$gridTotals['unresolved'] ?>.
            This is the filtered view, not a certified company liability.
        </p>
        <div class="border rounded p-2 mb-2 bg-light">
            <div class="form-row align-items-end">
                <div class="col-md-2 mb-2">
                    <label>Pay date</label>
                    <input type="date" class="form-control form-control-sm" name="plan_date" value="<?= psr_h((string)($suggestionPreview['plan_date'] ?? '')) ?>">
                </div>
                <div class="col-md-2 mb-2">
                    <label>Available funds (CAD)</label>
                    <input class="form-control form-control-sm" name="available_funds" value="<?= $suggestionPreview ? psr_h((string)$suggestionPreview['available_funds']) : '' ?>" placeholder="0.00" inputmode="decimal">
                </div>
                <div class="col-md-4 mb-2">
                    <label>Suggestion policy</label>
                    <select class="form-control form-control-sm" name="suggest_policy">
                        <option value="oldest_first" <?= (($suggestionPreview['policy'] ?? 'oldest_first') === 'oldest_first') ? 'selected' : '' ?>>Oldest verified amount owing first</option>
                        <option value="equal_same_date" <?= (($suggestionPreview['policy'] ?? '') === 'equal_same_date') ? 'selected' : '' ?>>Equal split on the same period start</option>
                    </select>
                </div>
                <div class="col-md-4 mb-2">
                    <button class="btn btn-sm btn-outline-primary" name="suggest_payments" value="1">Suggest payments</button>
                    <?php if ($suggestionPreview): ?>
                        <button class="btn btn-sm btn-outline-secondary" name="cancel_suggestion" value="1">Cancel suggestion</button>
                    <?php endif; ?>
                </div>
            </div>
            <p class="mb-0 text-muted">Suggestions use the filtered rows, including other pages of this filter, and they use saved drafts. Typed amounts stay as they are until you preview a draft save or apply a suggestion. A suggestion is editable, cancelable, and not approved. It is not imported, not sent to ADP or a bank, and not marked paid. Cost reserves are funding set aside. They do not reduce the wage payable. A planned recovery is not cash you can assign, and a planned deposit of these same funds is not extra money.</p>
            <?php if ($suggestionPreview): $sug = $suggestionPreview['suggestion']; ?>
                <div class="alert alert-warning mt-2 mb-0">
                    <strong><?= psr_h($payHeading) ?></strong>
                    — <?= psr_h($sug['policy_label']) ?>
                    Available <?= number_format((float)$sug['available_funds'], 2) ?>.
                    Proposed base payout <?= number_format((float)$sug['total_wage'], 2) ?>.
                    Tax and employer cost reserve <?= $sug['total_reserve'] === null ? 'Incomplete' : number_format((float)$sug['total_reserve'], 2) ?>.
                    Total funding required <?= $sug['total_funding'] === null ? 'Incomplete' : number_format((float)$sug['total_funding'], 2) ?>.
                    <?php if ($sug['incomplete']): ?>Funding plan incomplete. Unknown employer or GST cost was not estimated, so this does not claim the available funds cover every cost.<?php else: ?>
                        Funds remaining <?= number_format((float)$sug['remaining_funds'], 2) ?>.
                        Shortfall <?= number_format((float)$sug['shortfall'], 2) ?>.
                        <?= !empty($sug['claims_complete_fit']) ? 'Known wage payouts and verified reserves fit in the available funds.' : 'The available funds do not cover every verified amount and reserve.' ?>
                    <?php endif; ?>
                    <div>Reserves do not reduce anyone's wage payable. Applying this writes a new dated draft only.</div>
                    <button class="btn btn-sm btn-warning mt-2" name="confirm_suggestion" value="1">Apply suggestion to draft</button>
                </div>
            <?php endif; ?>
        </div>
        <div class="grid-wrap">
        <table class="table table-sm table-bordered paygrid mb-0">
            <thead><tr>
                <th class="ident">Select</th>
                <th class="ident">Person / store</th>
                <th>Period</th>
                <th>Source status</th>
                <th>Reconciled view</th>
                <th>Gross</th>
                <th>Net</th>
                <th>Confirmed paid</th>
                <th>Balance before</th>
                <th><?= psr_h($payHeading) ?></th>
                <th>Balance after</th>
                <th>Route / basis</th>
                <th>GST</th>
                <th>Provenance</th>
            </tr></thead>
            <tbody>
            <?php $lastStore = null; foreach ($gridPage['rows'] as $grow):
                if ($grow['store_name'] !== $lastStore) {
                    $lastStore = $grow['store_name'];
                    echo '<tr><td colspan="14"><strong>' . psr_h($lastStore !== '' ? $lastStore : 'Store not set') . '</strong></td></tr>';
                }
                $rowClass = !empty($grow['source_conflict']) ? 'src-conflict' : (!empty($grow['freshness']['aug_oct']) ? 'src-stale' : (!empty($grow['source_status']) && $grow['source_status'] === 'paid' ? 'src-paid' : ''));
            ?>
                <tr class="<?= psr_h($rowClass) ?>">
                    <td class="ident">
                        <?php if ($grow['sheet_row_id']): ?>
                            <input type="checkbox" class="row-check" name="row_ids[]" value="<?= (int)$grow['sheet_row_id'] ?>" title="Obligation">
                            <input type="checkbox" name="plan_ids[]" value="<?= (int)$grow['sheet_row_id'] ?>" title="Proposal">
                            <label class="small"><input type="checkbox" name="g[<?= psr_h($grow['key']) ?>][delete]" value="1"> Remove draft</label>
                        <?php else: ?><span class="text-muted">System</span><?php endif; ?>
                        <input type="hidden" name="g[<?= psr_h($grow['key']) ?>][row_version]" value="<?= (int)$grow['row_version'] ?>">
                    </td>
                    <td class="ident">
                        <input class="form-control form-control-sm" name="g[<?= psr_h($grow['key']) ?>][employee_name]" value="<?= psr_h($grow['employee_name']) ?>">
                        <input class="form-control form-control-sm mt-1" name="g[<?= psr_h($grow['key']) ?>][store_name]" value="<?= psr_h($grow['store_name']) ?>">
                        <select class="form-control form-control-sm mt-1" name="g[<?= psr_h($grow['key']) ?>][user_id]"><option value="0">Not linked</option><?php foreach ($staffRows as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$grow['user_id']===(int)$p['id']?'selected':'' ?>><?= psr_h($p['Name']) ?></option><?php endforeach; ?></select>
                        <select class="form-control form-control-sm mt-1" name="g[<?= psr_h($grow['key']) ?>][site_id]"><option value="0">Store not linked</option><?php foreach ($siteRows as $s): ?><option value="<?= (int)$s['SId'] ?>" <?= (int)$grow['site_id']===(int)$s['SId']?'selected':'' ?>><?= psr_h($s['nick_name']) ?></option><?php endforeach; ?></select>
                        <?php if (!empty($grow['candidate'])): ?><div class="pending"><?= psr_h($grow['candidate']['reason']) ?></div><?php endif; ?>
                        <?php if ((int)($grow['active'] ?? 1) === 0): ?><div class="text-muted">Inactive</div><?php endif; ?>
                    </td>
                    <td>
                        <input type="date" class="form-control form-control-sm" name="g[<?= psr_h($grow['key']) ?>][period_start]" value="<?= psr_h($grow['period_start']) ?>">
                        <input type="date" class="form-control form-control-sm mt-1" name="g[<?= psr_h($grow['key']) ?>][period_end]" value="<?= psr_h($grow['period_end']) ?>">
                    </td>
                    <td>
                        <?= $grow['source_status'] === '' ? '—' : psr_h($grow['source_status']) ?>
                        <?php if (!empty($grow['source_conflict'])): ?><div class="pending">Source conflict</div><?php endif; ?>
                        <?php if (!empty($grow['freshness']['aug_oct'])): ?><div class="pending">Aug–Oct needs reconciliation</div><?php endif; ?>
                        <div class="text-muted"><?= psr_h($grow['freshness']['label']) ?></div>
                    </td>
                    <td><?= psr_h($grow['reconciled']['derived']) ?><div class="text-muted"><?= psr_h($grow['reconciled']['note']) ?></div></td>
                    <td><?php if ($grow['sheet_row_id']): ?><input class="form-control form-control-sm" name="g[<?= psr_h($grow['key']) ?>][gross]" value="<?= $grow['sheet_gross'] !== null ? psr_h($grow['sheet_gross']) : '' ?>" placeholder="Unknown"><?php else: ?><?= $grow['gross'] === null ? 'Pending' : number_format((float)$grow['gross'], 2) ?><?php endif; ?></td>
                    <td><?php if ($grow['sheet_row_id']): ?><input class="form-control form-control-sm" name="g[<?= psr_h($grow['key']) ?>][net]" value="<?= $grow['sheet_net'] !== null ? psr_h($grow['sheet_net']) : '' ?>" placeholder="Unknown"><?php else: ?><?= empty($grow['net_known']) ? 'Pending' : number_format((float)$grow['net'], 2) ?><?php endif; ?></td>
                    <td><?= $grow['paid_state'] === 'amount' ? number_format((float)$grow['paid'], 2) : ($grow['paid_state'] === 'none_confirmed' ? 'None confirmed' : 'Pending') ?>
                        <input class="form-control form-control-sm mt-1" name="g[<?= psr_h($grow['key']) ?>][actual_amount]" value="<?= $grow['actual_amount'] !== null ? psr_h($grow['actual_amount']) : '' ?>" placeholder="Draft actual amount">
                        <input type="date" class="form-control form-control-sm mt-1" name="g[<?= psr_h($grow['key']) ?>][actual_date]" value="<?= psr_h((string)$grow['actual_date']) ?>">
                        <input class="form-control form-control-sm mt-1" name="g[<?= psr_h($grow['key']) ?>][actual_method]" value="<?= psr_h((string)$grow['actual_method']) ?>" placeholder="Cash, Cheque, or E-Transfer">
                        <label class="small"><input type="checkbox" name="g[<?= psr_h($grow['key']) ?>][actual_confirmed]" value="1" <?= !empty($grow['actual_confirmed']) ? 'checked' : '' ?>> Confirm actual evidence</label>
                    </td>
                    <td><?= $grow['balance_before']['state'] === 'amount' ? number_format((float)$grow['balance_before']['amount'], 2) : 'Pending' ?><div class="text-muted"><?= psr_h($grow['balance_before']['reason'] ?? '') ?></div></td>
                    <td class="today">
                        <input class="form-control form-control-sm" name="g[<?= psr_h($grow['key']) ?>][today_pay]" value="<?= $grow['today_pay'] !== null ? psr_h($grow['today_pay']) : '' ?>" placeholder="Proposal">
                        <input type="date" class="form-control form-control-sm mt-1" name="g[<?= psr_h($grow['key']) ?>][pay_on]" value="<?= psr_h((string)($grow['pay_on'] ?? '')) ?>" title="Pay date for this row">
                        <?php if ($grow['today_effect'] === 'recovery'): ?><div class="pending">Recovery, not a payout and not available cash</div><?php endif; ?>
                        <?php $hint = $suggestByKey[$grow['key']] ?? null; if ($hint): ?>
                            <div class="pending"><?php if (!$hint['eligible']): ?>Not suggested. <?= psr_h($hint['reason']) ?><?php else: ?>Suggested preview <?= number_format((float)$hint['suggested'], 2) ?>. Reserve <?= number_format((float)($hint['cost_reserve'] ?? 0), 2) ?>. Wage payable stays <?= $hint['wage_payable'] === null ? 'Pending' : number_format((float)$hint['wage_payable'], 2) ?>.<?php if (!empty($hint['reason'])): ?> <?= psr_h($hint['reason']) ?><?php endif; ?><?php endif; ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= $grow['balance_after']['state'] === 'amount' ? number_format((float)$grow['balance_after']['amount'], 2) : 'Pending' ?><div class="text-muted"><?= psr_h($grow['balance_after']['reason'] ?? '') ?></div></td>
                    <td>
                        <select class="form-control form-control-sm" name="g[<?= psr_h($grow['key']) ?>][route]"><?php foreach (['unset','cash','adp'] as $opt): ?><option <?= $grow['route']===$opt?'selected':'' ?>><?= psr_h($opt) ?></option><?php endforeach; ?></select>
                        <select class="form-control form-control-sm mt-1" name="g[<?= psr_h($grow['key']) ?>][basis]"><?php foreach (['unset','net','gross'] as $opt): ?><option <?= $grow['basis']===$opt?'selected':'' ?>><?= psr_h($opt) ?></option><?php endforeach; ?></select>
                        <select class="form-control form-control-sm mt-1" name="g[<?= psr_h($grow['key']) ?>][party_class]"><?php foreach (['unanswered','employee','contractor'] as $opt): ?><option <?= $grow['party_class']===$opt?'selected':'' ?>><?= psr_h($opt) ?></option><?php endforeach; ?></select>
                    </td>
                    <td><?= $grow['gst']['state'] === 'amount' || $grow['gst']['state'] === 'unverified_estimate' ? number_format((float)$grow['gst']['amount'], 2) : psr_h($grow['gst']['state']) ?><div class="text-muted"><?= psr_h($grow['gst']['reason']) ?></div></td>
                    <td><?= psr_h($grow['provenance']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-2">
            <div>Page <?= (int)$gridPage['page'] ?> of <?= (int)$gridPage['pages'] ?> (<?= (int)$gridPage['total'] ?> filtered). Selection applies to this page only.</div>
            <div>
                <?php
                $qs = $_GET;
                $qs['org_id'] = $orgId;
                $prev = $qs; $prev['page'] = max(1, $gridPage['page'] - 1);
                $next = $qs; $next['page'] = min($gridPage['pages'], $gridPage['page'] + 1);
                ?>
                <a class="btn btn-sm btn-outline-secondary" href="?<?= psr_h(http_build_query($prev)) ?>">Previous</a>
                <a class="btn btn-sm btn-outline-secondary" href="?<?= psr_h(http_build_query($next)) ?>">Next</a>
                <button class="btn btn-sm btn-primary" name="preview_grid_save" value="1">Preview draft save</button>
            </div>
        </div>
    </form>
    <div class="sheet">
        <h6>Unallocated cash evidence</h6>
        <p class="mb-1">These are not applied to a person or period and are not certified payable.</p>
        <?php if (!$eventRows): ?><p class="text-muted mb-0">None on file.</p><?php endif; ?>
        <?php foreach ($eventRows as $ev): ?>
            <div><?= psr_h($ev['allocation_status']) ?> <?= $ev['signed_amount'] === null ? 'Pending' : number_format((float)$ev['signed_amount'], 2) ?> <?= psr_h((string)$ev['flags_json']) ?></div>
        <?php endforeach; ?>
    </div>

    <details class="sheet">
        <summary>Company all-period totals (not the spreadsheet above)</summary>
    <?php foreach ($report['companies'] as $company): ?>
    <div class="mt-3">
        <h5><?= psr_h($company['org_name']) ?> — All-period total</h5>
        <p><?= psr_h($company['filter_label']) ?> <?= psr_h($report['cross_company_note']) ?></p>
        <table class="table table-sm table-bordered">
            <thead><tr><th></th><th>Gross known</th><th>Gross unknown</th><th>Known net wages</th><th>Net unknown</th><th>Actual paid, unreversed</th><th>Remaining known balance</th><th>Paid without known net</th></tr></thead>
            <tbody>
                <tr class="live"><td>All-period total</td><td><?= psr_total_money($company['all_period'], 'gross_known') ?></td><td><?= (int)$company['all_period']['gross_unknown'] ?></td><td><?= psr_total_money($company['all_period'], 'net_known') ?></td><td><?= (int)$company['all_period']['net_unknown'] ?></td><td><?= psr_total_money($company['all_period'], 'paid_known') ?></td><td><?= psr_total_money($company['all_period'], 'remaining_known') ?></td><td><?= (int)$company['all_period']['paid_without_known_net'] ?></td></tr>
                <?php if ($company['filter_active']): ?>
                <tr><td>Filtered subset</td><td><?= psr_total_money($company['filtered'], 'gross_known') ?></td><td><?= (int)$company['filtered']['gross_unknown'] ?></td><td><?= psr_total_money($company['filtered'], 'net_known') ?></td><td><?= (int)$company['filtered']['net_unknown'] ?></td><td><?= psr_total_money($company['filtered'], 'paid_known') ?></td><td><?= psr_total_money($company['filtered'], 'remaining_known') ?></td><td><?= (int)$company['filtered']['paid_without_known_net'] ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <table class="table table-sm table-bordered grid">
            <thead><tr><th>Employee</th><th>All-period gross</th><th>All-period known net</th><th>All-period paid</th><th>All-period remaining</th><th>Unknown net / paid without net</th><th>Draft preview (not actual)</th><th>Drilldown</th></tr></thead>
            <tbody>
            <?php foreach ($company['employees'] as $emp): ?>
                <tr>
                    <td><?= psr_h($emp['name']) ?> <span class="text-muted">#<?= (int)$emp['user_id'] ?></span></td>
                    <td><?= psr_total_money($emp['all_period'], 'gross_known') ?></td>
                    <td><?= psr_total_money($emp['all_period'], 'net_known') ?></td>
                    <td><?= psr_total_money($emp['all_period'], 'paid_known') ?></td>
                    <td><?= psr_total_money($emp['all_period'], 'remaining_known') ?></td>
                    <td><?= (int)$emp['all_period']['net_unknown'] ?> unknown<?php if ($emp['all_period']['paid_without_known_net']): ?>, <?= (int)$emp['all_period']['paid_without_known_net'] ?> paid without a known net<?php endif; ?></td>
                    <td class="preview">Rows <?= (int)$emp['draft_preview']['rows'] ?>, proposed <?= number_format((float)$emp['draft_preview']['proposed_known'], 2) ?>, draft net unknown <?= (int)$emp['draft_preview']['net_unknown'] ?></td>
                    <td>
                        <?php foreach ($emp['drilldown'] as $line): ?>
                            <div><?= psr_h($line['source']) ?> #<?= (int)$line['id'] ?> <?= psr_h($line['store']) ?> <?= psr_h($line['period_start']) ?>–<?= psr_h($line['period_end']) ?>
                                gross <?= $line['gross'] === null ? 'Pending' : number_format((float)$line['gross'], 2) ?>,
                                net <?= empty($line['net_known']) ? 'Pending' : number_format((float)$line['net'], 2) ?>,
                                paid <?= $line['paid'] === null ? 'Pending' : number_format((float)$line['paid'], 2) ?>,
                                <?= psr_h($line['status']) ?><?= !empty($line['paid_without_known_net']) ? ', paid without known net' : '' ?>
                                <?= empty($line['in_filter']) ? '(outside filter)' : '' ?></div>
                        <?php endforeach; ?>
                        <?php foreach ($emp['dedup_notes'] as $note): ?><div class="text-muted"><?= psr_h($note['text']) ?></div><?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
    </details>

    <?php if ($isSuper): ?>
    <form method="POST" class="sheet">
        <input type="hidden" name="csrf_token" value="<?= psr_h($csrf) ?>">
        <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
        <strong>Show another company beside this total</strong>
        <p class="text-muted mb-2">Pick an existing person in that company. The totals are not added together, and a name is never matched automatically.</p>
        <div class="form-row">
            <div class="col-md-2"><input class="form-control form-control-sm" name="user_id" placeholder="User id here" required></div>
            <div class="col-md-2"><input class="form-control form-control-sm" name="other_org_id" placeholder="Other company id" required></div>
            <div class="col-md-2"><input class="form-control form-control-sm" name="other_user_id" placeholder="Other user id" required></div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary" name="link_identity" value="1">Link for side-by-side view</button></div>
        </div>
    </form>
    <?php endif; ?>

    <div class="sheet">
        <h5>Import a workbook or a proposal</h5>
        <p>Workbook CSV/JSON uses the normalized columns (gross, net, reported_status, flags, raw notes). A blank net stays unknown. Paid stays paid. Cash in the source does not create GST. Payments CSV stays unallocated. A proposal file is not payroll: columns employee, store, period_start, period_end, proposed_amount, amount_basis, route, note, plan_date, and GST columns only if the person is an explicit contractor.</p>
        <form method="POST" enctype="multipart/form-data" class="mb-3">
            <input type="hidden" name="csrf_token" value="<?= psr_h($csrf) ?>">
            <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
            <div class="form-row">
                <div class="col-md-4"><input type="file" name="workbook" accept=".csv,.json" required></div>
                <div class="col-md-4"><input type="file" name="payments" accept=".csv,.json"><span class="text-muted"> optional unallocated events</span></div>
                <div class="col-md-2"><button class="btn btn-sm btn-primary" name="stage_workbook" value="1">Stage workbook</button></div>
            </div>
        </form>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= psr_h($csrf) ?>">
            <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
            <input type="file" name="proposal" accept=".csv,.json" required>
            <button class="btn btn-sm btn-outline-primary" name="stage_proposal" value="1">Stage proposal</button>
        </form>
        <?php if ($stage): ?>
            <hr>
            <p><strong>Preview <?= psr_h($stage['kind']) ?></strong> — <?= count($stageRows) ?> row(s). Payroll has not changed. <?php if ($stageDups): ?><?= count($stageDups) ?> possible duplicate(s).<?php endif; ?></p>
            <div class="table-responsive"><table class="table table-sm table-bordered">
                <thead><tr><th>Row</th><th>Kind / name</th><th>Period</th><th>Gross</th><th>Net</th><th>Status</th><th>Flags</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($stageRows, 0, 80) as $srow): ?>
                    <tr>
                        <td><?= (int)($srow['source_row'] ?? 0) ?></td>
                        <td><?= psr_h($srow['row_kind'] ?? $srow['employee_name'] ?? '') ?> <?= psr_h($srow['employee_name'] ?? '') ?></td>
                        <td><?= psr_h((string)($srow['period_start'] ?? '')) ?> <?= psr_h((string)($srow['period_end'] ?? '')) ?></td>
                        <td><?= array_key_exists('gross', $srow) && $srow['gross'] !== null ? number_format((float)$srow['gross'], 2) : (isset($srow['proposed_amount']) && $srow['proposed_amount'] !== null ? number_format((float)$srow['proposed_amount'], 2) . ' proposed' : 'Pending') ?></td>
                        <td><?= array_key_exists('net', $srow) && $srow['net'] !== null ? number_format((float)$srow['net'], 2) : 'Pending' ?></td>
                        <td><?= psr_h($srow['reported_status'] ?? $srow['amount_basis'] ?? '') ?></td>
                        <td><?= psr_h(isset($srow['flags']) ? json_encode($srow['flags']) : '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= psr_h($csrf) ?>">
                <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
                <input type="hidden" name="stage_token" value="<?= psr_h($stageToken) ?>">
                <?php if ($stageDups): ?><label><input type="checkbox" name="import_duplicates" value="1"> Import the duplicate rows too</label><?php endif; ?>
                <button class="btn btn-warning font-weight-bold" name="commit_stage" value="1">Save staged rows as drafts</button>
            </form>
        <?php endif; ?>
    </div>

    <form method="POST" class="sheet" id="approve">
        <input type="hidden" name="csrf_token" value="<?= psr_h($csrf) ?>">
        <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
        <h5>Draft rows</h5>
        <p>This period’s planner is not the all-period employee total. Employer costs and GST never reduce the wage balance. Pending means the figure is unknown, not zero.</p>
        <div class="table-responsive"><table class="table table-sm table-bordered grid">
            <thead><tr>
                <th></th><th>Employee / store</th><th>Period</th><th>Gross</th><th>Net</th><th>Status</th>
                <th>This period outstanding</th><th>Proposed today</th><th>Route / basis</th>
                <th>Employer CPP / EI / other</th><th>GST 5%</th><th>Total employer funding today</th>
                <th>Preview balance</th><th>All-period remaining</th><th>Notes</th>
            </tr></thead>
            <tbody>
            <?php foreach ($sheetRows as $row):
                if (($row['row_kind'] ?? '') !== '' && $row['row_kind'] !== 'employee_record') {
                    continue;
                }
                $funding = psr_employer_funding($row);
                $gst = psr_gst($row);
                $emp = $byUser[(int)$row['user_id']] ?? null;
                $periodLines = [];
                foreach ($systemLines as $ln) {
                    if ((int)$ln['user_id'] === (int)$row['user_id'] && (int)$ln['site_id'] === (int)$row['site_id'] && psr_periods_overlap($ln, $row)) {
                        $periodLines[] = $ln;
                    }
                }
                $snap = psr_snapshot_lines($periodLines);
                $principalKnown = $snap['remaining'] !== null && $snap['count'] > 0;
                $balance = psr_balance_preview($row, $principalKnown, $snap['remaining']);
                ?>
                <tr>
                    <td><?php if (!isset($wageRows[(int)$row['id']])): ?><input type="checkbox" name="row_ids[]" value="<?= (int)$row['id'] ?>"><?php else: ?>Approved<?php endif; ?></td>
                    <td><?= psr_h($row['employee_name']) ?><br><span class="text-muted"><?= psr_h($row['store_name']) ?></span><br><a href="?org_id=<?= (int)$orgId ?>&edit=<?= (int)$row['id'] ?>">Edit draft</a></td>
                    <td><?= psr_h((string)$row['period_start']) ?><br><?= psr_h((string)$row['period_end']) ?><?php if (!$row['year_confirmed'] && $row['year_confidence'] !== 'explicit'): ?><div class="pending">Year uncertain</div><?php endif; ?></td>
                    <td><?= $row['gross'] === null ? 'Pending' : number_format((float)$row['gross'], 2) ?></td>
                    <td><?= $row['net'] === null ? 'Pending' : number_format((float)$row['net'], 2) ?></td>
                    <td><?= psr_h($row['reported_status']) ?></td>
                    <td><?= $principalKnown ? number_format((float)$snap['remaining'], 2) : 'Pending' ?></td>
                    <td class="preview"><?= $row['proposed_amount'] === null ? 'Pending' : number_format((float)$row['proposed_amount'], 2) ?></td>
                    <td><?= psr_h($row['pay_route']) ?> / <?= psr_h($row['amount_basis']) ?></td>
                    <td><?= psr_h($row['er_cpp_state']) ?> / <?= psr_h($row['er_ei_state']) ?> / <?= psr_h($row['er_other_state']) ?></td>
                    <td><?= $gst['state'] === 'amount' ? number_format((float)$gst['amount'], 2) : psr_h($gst['state']) ?><div class="text-muted"><?= psr_h($gst['reason']) ?></div></td>
                    <td><?= $funding['state'] === 'amount' ? number_format((float)$funding['amount'], 2) : 'Pending' ?></td>
                    <td class="preview"><?= $balance['preview_balance']['state'] === 'amount' ? number_format((float)$balance['preview_balance']['amount'], 2) : 'Pending' ?><div class="text-muted">Live <?= $balance['live_balance']['state'] === 'amount' ? number_format((float)$balance['live_balance']['amount'], 2) : 'Pending' ?></div></td>
                    <td><?= $emp ? psr_total_money($emp['all_period'], 'remaining_known') : 'Not linked' ?></td>
                    <td><?= psr_h((string)$row['draft_notes']) ?><div class="text-muted"><?= psr_h((string)$row['raw_notes']) ?></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <button class="btn btn-outline-primary" name="preview_approval" value="1">Preview approval</button>
    </form>

    <?php if ($previewPlans): $savedPreview = $_SESSION['psr_preview'] ?? null; ?>
    <div class="sheet">
        <h5>Approval preview</h5>
        <p>Confirming posts only these effects. Unchecked rows stay drafts. If any checked row is blocked, nothing is posted.</p>
        <?php foreach ($previewPlans as $plan): ?>
            <div class="mb-2">
                <strong><?= psr_h($plan['employee_name'] ?? ('Row ' . $plan['row_id'])) ?></strong>
                <?php if (empty($plan['ok'])): ?><span class="pending">Blocked</span><?php else: ?><span class="text-success">Ready</span><?php endif; ?>
                <ul>
                    <?php foreach ($plan['blocks'] as $block): ?><li><?= psr_h($block) ?></li><?php endforeach; ?>
                    <?php foreach ($plan['notes'] as $note): ?><li><?= psr_h($note) ?></li><?php endforeach; ?>
                    <li>Wage: <?= psr_h($plan['wage']['action'] ?? '') ?>. Payment: <?= psr_h($plan['payment']['action'] ?? '') ?> <?= isset($plan['payment']['amount']) && $plan['payment']['amount'] !== null ? number_format((float)$plan['payment']['amount'], 2) : '' ?></li>
                </ul>
            </div>
        <?php endforeach; ?>
        <?php if ($savedPreview): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= psr_h($csrf) ?>">
            <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
            <input type="hidden" name="idempotency_key" value="<?= psr_h($savedPreview['key']) ?>">
            <button class="btn btn-danger font-weight-bold" name="confirm_approval" value="1">Confirm approval and post these rows</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($edit): ?>
    <form method="POST" class="sheet">
        <input type="hidden" name="csrf_token" value="<?= psr_h($csrf) ?>">
        <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
        <input type="hidden" name="row_id" value="<?= (int)$edit['id'] ?>">
        <h5>Edit draft #<?= (int)$edit['id'] ?></h5>
        <p class="text-muted">Source notes stay on the row. This save does not change payroll. Leave an amount blank for unknown. Type 0 if the amount is actually zero.</p>
        <div class="form-row">
            <div class="col-md-3 mb-2"><label>Name</label><input class="form-control form-control-sm" name="employee_name" value="<?= psr_h($edit['employee_name']) ?>"></div>
            <div class="col-md-3 mb-2"><label>Store name</label><input class="form-control form-control-sm" name="store_name" value="<?= psr_h($edit['store_name']) ?>"></div>
            <div class="col-md-3 mb-2"><label>Existing employee</label><select class="form-control form-control-sm" name="user_id"><option value="0">Not linked</option><?php foreach ($staffRows as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$edit['user_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= psr_h($p['Name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3 mb-2"><label>Existing store</label><select class="form-control form-control-sm" name="site_id"><option value="0">Not linked</option><?php foreach ($siteRows as $s): ?><option value="<?= (int)$s['SId'] ?>" <?= (int)$edit['site_id'] === (int)$s['SId'] ? 'selected' : '' ?>><?= psr_h($s['nick_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 mb-2"><label>Period start</label><input type="date" class="form-control form-control-sm" name="period_start" value="<?= psr_h((string)$edit['period_start']) ?>"></div>
            <div class="col-md-2 mb-2"><label>Period end</label><input type="date" class="form-control form-control-sm" name="period_end" value="<?= psr_h((string)$edit['period_end']) ?>"></div>
            <div class="col-md-2 mb-2"><label>Gross</label><input class="form-control form-control-sm" name="gross" value="<?= $edit['gross'] === null ? '' : psr_h($edit['gross']) ?>"></div>
            <div class="col-md-2 mb-2"><label>Net</label><input class="form-control form-control-sm" name="net" value="<?= $edit['net'] === null ? '' : psr_h($edit['net']) ?>"></div>
            <div class="col-md-2 mb-2"><label>Status</label><select class="form-control form-control-sm" name="reported_status"><?php foreach (['unknown', 'paid', 'not_paid', 'partial'] as $st): ?><option <?= $edit['reported_status'] === $st ? 'selected' : '' ?>><?= psr_h($st) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 mb-2"><label>Year confirmed</label><select class="form-control form-control-sm" name="year_confirmed"><option value="0">No</option><option value="1" <?= !empty($edit['year_confirmed']) ? 'selected' : '' ?>>Yes</option></select></div>
            <div class="col-md-2 mb-2"><label>Who</label><select class="form-control form-control-sm" name="party_class"><?php foreach (['unanswered', 'employee', 'contractor'] as $st): ?><option <?= $edit['party_class'] === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 mb-2"><label>Route</label><select class="form-control form-control-sm" name="pay_route"><?php foreach (['unset', 'cash', 'adp'] as $st): ?><option <?= $edit['pay_route'] === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 mb-2"><label>Basis</label><select class="form-control form-control-sm" name="amount_basis"><?php foreach (['unset', 'gross', 'net'] as $st): ?><option <?= $edit['amount_basis'] === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 mb-2"><label>Proposed amount</label><input class="form-control form-control-sm" name="proposed_amount" value="<?= $edit['proposed_amount'] === null ? '' : psr_h($edit['proposed_amount']) ?>"></div>
            <?php foreach (['ee_deduction' => 'Employee deductions', 'er_cpp' => 'Employer CPP', 'er_ei' => 'Employer EI', 'er_other' => 'Other employer cost'] as $key => $label): ?>
            <div class="col-md-3 mb-2"><label><?= psr_h($label) ?></label>
                <select class="form-control form-control-sm" name="<?= psr_h($key) ?>_state"><?php foreach (['unknown', 'none', 'amount'] as $st): ?><option <?= $edit[$key . '_state'] === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach; ?></select>
                <input class="form-control form-control-sm mt-1" name="<?= psr_h($key) ?>_amount" value="<?= $edit[$key . '_amount'] === null ? '' : psr_h($edit[$key . '_amount']) ?>" placeholder="Amount only if chosen">
            </div>
            <?php endforeach; ?>
            <div class="col-md-3 mb-2"><label>Verified contractor GST</label><select class="form-control form-control-sm" name="gst_verified"><option value="0">No</option><option value="1" <?= !empty($edit['gst_verified']) ? 'selected' : '' ?>>Yes, taxable invoice</option></select><input class="form-control form-control-sm mt-1" name="gst_invoice_principal" value="<?= $edit['gst_invoice_principal'] === null ? '' : psr_h($edit['gst_invoice_principal']) ?>" placeholder="Invoice principal"></div>
            <div class="col-md-12 mb-2"><label>Draft note</label><textarea class="form-control form-control-sm" name="draft_notes" rows="2"><?= psr_h((string)$edit['draft_notes']) ?></textarea></div>
            <div class="col-md-12 mb-2"><label>Confirmed historical actual payment</label>
                <select class="form-control form-control-sm" name="actual_confirmed"><option value="0">No, this is not an actual payment</option><option value="1" <?= !empty($edit['actual_confirmed']) ? 'selected' : '' ?>>Yes, amount, date, and method are explicit</option></select>
            </div>
            <div class="col-md-2 mb-2"><label>Actual amount</label><input class="form-control form-control-sm" name="actual_amount" value="<?= $edit['actual_amount'] === null ? '' : psr_h($edit['actual_amount']) ?>"></div>
            <div class="col-md-2 mb-2"><label>Actual date</label><input type="date" class="form-control form-control-sm" name="actual_date" value="<?= psr_h((string)$edit['actual_date']) ?>"></div>
            <div class="col-md-2 mb-2"><label>Actual method</label><input class="form-control form-control-sm" name="actual_method" value="<?= psr_h((string)$edit['actual_method']) ?>" placeholder="Cash, Cheque, or E-Transfer"></div>
            <div class="col-md-2 mb-2"><label>Reference</label><input class="form-control form-control-sm" name="actual_reference" value="<?= psr_h((string)$edit['actual_reference']) ?>"></div>
        </div>
        <button class="btn btn-primary" name="save_draft" value="1">Save draft</button>
        <p class="mt-2 text-muted">Source status evidence and raw notes are unchanged: <?= psr_h((string)$edit['raw_notes']) ?></p>
        <?php if ($revisions): ?><h6>Revisions</h6><?php foreach ($revisions as $rev): ?><div><?= psr_h($rev['created_at']) ?> <?= psr_h($rev['action']) ?></div><?php endforeach; ?><?php endif; ?>
    </form>
    <?php endif; ?>

    <div class="sheet" id="plans">
        <h5>Dated proposal plans</h5>
        <p>Each saved suggestion is its own dated round. Amount available is the pool you type. Proposed lines are not actual payments, and a later round does not delete an earlier one.</p>
        <form method="POST" class="mb-3">
            <input type="hidden" name="csrf_token" value="<?= psr_h($csrf) ?>">
            <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
            <div class="form-row">
                <div class="col-md-2"><input type="date" class="form-control form-control-sm" name="plan_date" required></div>
                <div class="col-md-3"><input class="form-control form-control-sm" name="title" placeholder="Title"></div>
                <div class="col-md-2"><input class="form-control form-control-sm" name="available_amount" placeholder="Available, blank if unknown"></div>
                <div class="col-md-3"><input class="form-control form-control-sm" name="note" placeholder="Note"></div>
                <div class="col-md-2"><button class="btn btn-sm btn-outline-primary" name="save_plan" value="1">Save plan</button></div>
            </div>
        </form>
        <?php foreach ($planRows as $plan):
            $lines = $planLines[(int)$plan['id']] ?? [];
            $sum = 0.0; $sumKnown = true;
            foreach ($lines as $line) {
                if ($line['proposed_amount'] === null) { $sumKnown = false; } else { $sum = round($sum + (float)$line['proposed_amount'], 2); }
            }
            ?>
            <div class="border rounded p-2 mb-2">
                <strong><?= psr_h($plan['plan_date']) ?> <?= psr_h($plan['title']) ?></strong>
                — available <?= $plan['available_amount'] === null ? 'Pending' : number_format((float)$plan['available_amount'], 2) ?>,
                proposed <?= $sumKnown ? number_format($sum, 2) : 'Pending' ?>
                <?php if ($plan['available_amount'] !== null && $sumKnown): ?>, remainder <?= number_format((float)$plan['available_amount'] - $sum, 2) ?><?php endif; ?>
                <div class="text-muted"><?= psr_h((string)$plan['note']) ?></div>
                <?php foreach ($lines as $line): ?>
                    <div><?= psr_h($line['employee_name']) ?> <?= $line['proposed_amount'] === null ? 'Pending' : number_format((float)$line['proposed_amount'], 2) ?> <?= psr_h($line['pay_route']) ?> <?= psr_h($line['amount_basis']) ?> — proposal only. <?= psr_h((string)$line['note']) ?></div>
                <?php endforeach; ?>
                <form method="POST" class="form-row mt-2">
                    <input type="hidden" name="csrf_token" value="<?= psr_h($csrf) ?>">
                    <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
                    <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                    <div class="col-md-3"><input class="form-control form-control-sm" name="employee_name" placeholder="Employee"></div>
                    <div class="col-md-2"><input class="form-control form-control-sm" name="proposed_amount" placeholder="Proposed"></div>
                    <div class="col-md-2"><select class="form-control form-control-sm" name="amount_basis"><option>unset</option><option>gross</option><option>net</option></select></div>
                    <div class="col-md-2"><select class="form-control form-control-sm" name="pay_route"><option>unset</option><option>cash</option><option>adp</option></select></div>
                    <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary" name="save_plan_line" value="1">Add proposal</button></div>
                </form>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="sheet">
        <h5>Unallocated cash and advances</h5>
        <p>These stay unallocated. They are not applied to a period or marked as wages.</p>
        <?php if (!$eventRows): ?><p class="text-muted mb-0">None imported.</p><?php endif; ?>
        <?php foreach ($eventRows as $ev): ?>
            <div><?= psr_h($ev['event_key']) ?> <?= psr_h($ev['employee_context']) ?> <?= psr_h($ev['store_context']) ?> <?= $ev['signed_amount'] === null ? 'Pending' : number_format((float)$ev['signed_amount'], 2) ?> <?= psr_h($ev['allocation_status']) ?> <?= psr_h((string)$ev['flags_json']) ?></div>
        <?php endforeach; ?>
    </div>

    <div class="sheet">
        <h5>Approvals</h5>
        <?php foreach ($approvalRows as $ap): ?>
            <form method="POST" class="mb-1">
                <input type="hidden" name="csrf_token" value="<?= psr_h($csrf) ?>">
                <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
                <input type="hidden" name="approval_id" value="<?= (int)$ap['id'] ?>">
                #<?= (int)$ap['id'] ?> <?= psr_h($ap['created_at']) ?> <?= $ap['undone_at'] ? 'Undone ' . psr_h($ap['undone_at']) : '' ?>
                <?php if (!$ap['undone_at']): ?><button class="btn btn-sm btn-outline-danger" name="undo_approval" value="1">Undo</button><?php endif; ?>
            </form>
        <?php endforeach; ?>
    </div>

    <div class="sheet">
        <h5>Not calculated here</h5>
        <ul><?php foreach (psr_deferred_items() as $item): ?><li><?= psr_h($item) ?></li><?php endforeach; ?></ul>
    </div>
</div>
<script>
document.getElementById('selectVisible').addEventListener('click', function () {
    document.querySelectorAll('#gridForm .row-check').forEach(function (box) { box.checked = true; });
});
</script>
</body>
</html>
