<?php
/**
 * Bank file for employees paid by direct deposit.
 * Uses the site's bank profile (RBC, TD, BMO, or another Canadian bank)
 * and writes a Payments Canada Standard 005 file of 1464-byte records.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'payroll_scope.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
require_once __DIR__ . '/engine/direct_deposit_lib.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Access denied.');
}
ps_require_payroll_admin();
ps_require_csrf();
$orgId = ps_selected_org($pdo);

$lineIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['doc_line_ids'] ?? [])))));
$eftUsers = array_flip(array_map('intval', (array)($_POST['eft_user'] ?? [])));
$issue = trim((string)($_POST['issue_date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue)) {
    $issue = date('Y-m-d');
}
$periodStart = trim((string)($_POST['period_start'] ?? ''));
$periodEnd = trim((string)($_POST['period_end'] ?? ''));
if ($lineIds === []) {
    exit('Tick the shifts first, then create the direct deposit file.');
}

$in = implode(',', array_fill(0, count($lineIds), '?'));
$sql = "SELECT f.id AS line_id, f.user_id, f.site_id, f.work_date, f.total_gross,
               f.cpp AS ee_cpp, f.ei AS ee_ei, f.income_tax AS ee_tax, f.vac AS er_vac,
               f.paid_amount, u.Name AS emp_name, u.payment_method, u.employee_type,
               COALESCE(u.salary_amount, 0) AS salary_amount,
               u.bank_name, u.bank_institution, u.bank_transit, u.bank_account
        FROM payroll_finalized f
        INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
        WHERE f.id IN ($in)";
$st = $pdo->prepare($sql);
$st->execute(array_merge([$orgId], $lineIds));
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    exit('Those rows are not in this organization.');
}

$vacPaid = false;
try {
    $vm = $pdo->prepare("SELECT COALESCE(vacation_payout_mode,'accrue') FROM organizations WHERE id=? LIMIT 1");
    $vm->execute([$orgId]);
    $vacPaid = (strtolower(trim((string)$vm->fetchColumn())) === 'pay_each_period');
} catch (Throwable $e) {
    $vacPaid = false;
}

$groups = [];
foreach ($rows as $r) {
    $key = (int)$r['user_id'] . '|' . (int)$r['site_id'];
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'user_id' => (int)$r['user_id'],
            'site_id' => (int)$r['site_id'],
            'name' => (string)$r['emp_name'],
            'method' => (string)($r['payment_method'] ?? ''),
            'type' => (string)($r['employee_type'] ?? 'hourly'),
            'salary' => round((float)($r['salary_amount'] ?? 0), 2),
            'bank_name' => trim((string)($r['bank_name'] ?? '')),
            'institution' => preg_replace('/\D+/', '', (string)($r['bank_institution'] ?? '')),
            'transit' => preg_replace('/\D+/', '', (string)($r['bank_transit'] ?? '')),
            'account' => preg_replace('/\D+/', '', (string)($r['bank_account'] ?? '')),
            'gross' => 0.0,
            'cpp' => 0.0,
            'ei' => 0.0,
            'tax' => 0.0,
            'vac' => 0.0,
            'paid' => 0.0,
            'end' => (string)$r['work_date'],
        ];
    }
    $g =& $groups[$key];
    $g['gross'] += (float)$r['total_gross'];
    $g['cpp'] += (float)$r['ee_cpp'];
    $g['ei'] += (float)$r['ee_ei'];
    $g['tax'] += (float)$r['ee_tax'];
    $g['vac'] += (float)$r['er_vac'];
    $g['paid'] += (float)$r['paid_amount'];
    if ((string)$r['work_date'] > $g['end']) {
        $g['end'] = (string)$r['work_date'];
    }
    unset($g);
}

if (function_exists('pe_read_period_stats') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)) {
    $stats = pe_read_period_stats($pdo, $periodStart, $periodEnd);
    foreach ($groups as &$g) {
        $k = $g['user_id'] . '|' . $g['site_id'];
        if (!isset($stats[$k])) {
            continue;
        }
        $cpp = round((float)$stats[$k]['cpp'], 2);
        if ($cpp > 0.004) {
            $g['cpp'] += $cpp;
        }
    }
    unset($g);
}

$ready = [];
$problems = [];
foreach ($groups as $g) {
    $marked = (($g['method'] === 'direct_deposit') || isset($eftUsers[$g['user_id']]));
    if (!$marked) {
        continue;
    }
    $type = function_exists('pe_normalize_employee_type') ? pe_normalize_employee_type($g['type']) : 'hourly';
    $gross = $g['gross'];
    $cpp = $g['cpp'];
    $ei = $g['ei'];
    $tax = $g['tax'];
    $vac = $g['vac'];
    if ($type === 'monthly' && $g['salary'] > 0 && function_exists('pe_line_for')) {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd) ? $periodEnd : $g['end'];
        $line = pe_line_for($pdo, $g['user_id'], $g['site_id'], $day, $g['salary']);
        $gross = (float)$line['pay_gross'];
        $cpp = (float)$line['cpp'];
        $ei = (float)$line['ei'];
        $tax = (float)$line['income_tax'];
        $vac = (float)$line['vac'];
    }
    $net = $gross + ($vacPaid ? $vac : 0) - $cpp - $ei - $tax;
    $amount = round(max(0, $net - $g['paid']), 2);
    $inst = $g['institution'];
    $transit = $g['transit'];
    $account = $g['account'];
    if (strlen($inst) > 0 && strlen($inst) <= 3) {
        $inst = str_pad($inst, 3, '0', STR_PAD_LEFT);
    }
    if (strlen($transit) > 0 && strlen($transit) <= 5) {
        $transit = str_pad($transit, 5, '0', STR_PAD_LEFT);
    }
    $missing = [];
    if ($g['bank_name'] === '') {
        $missing[] = 'bank name';
    }
    if (strlen($inst) !== 3) {
        $missing[] = 'institution number';
    }
    if (strlen($transit) !== 5) {
        $missing[] = 'transit number';
    }
    if (strlen($account) < 7 || strlen($account) > 12) {
        $missing[] = 'account number';
    }
    if ($amount < 0.01) {
        $missing[] = 'nothing left to deposit for this period';
    }
    if ($missing) {
        $problems[] = ['name' => $g['name'], 'why' => implode(', ', $missing)];
        continue;
    }
    $ready[] = [
        'site_id' => $g['site_id'],
        'name' => $g['name'],
        'institution' => $inst,
        'transit' => $transit,
        'account' => $account,
        'amount' => number_format($amount, 2, '.', ''),
        'reference' => 'E' . $g['user_id'],
        'bank_name' => $g['bank_name'],
    ];
}

$bySite = [];
foreach ($ready as $row) {
    $bySite[$row['site_id']][] = $row;
}
$siteFiles = [];
foreach ($bySite as $siteId => $rowsForSite) {
    $profile = dd_load_site_bank($pdo, (int)$siteId);
    if ($profile === null) {
        $problems[] = ['name' => 'Site ' . (int)$siteId, 'why' => 'the site record is missing'];
        continue;
    }
    $gaps = dd_profile_gaps($profile);
    if ($gaps) {
        $problems[] = [
            'name' => $profile['name'],
            'why' => 'site bank profile is missing ' . implode(', ', $gaps) . '. Open the site and fill in the direct deposit section.',
            'site_id' => (int)$siteId,
        ];
        continue;
    }
    $siteFiles[] = ['profile' => $profile, 'rows' => $rowsForSite];
}

$onlySite = (int)($_POST['site_id'] ?? 0);

$sendFile = static function (array $bundle) use ($pdo, $issue): void {
    require_once __DIR__ . '/engine/staging.php';
    if (payroll_staging_outbound_blocked('eft')) {
        http_response_code(403);
        exit('Staging does not create or send bank files.');
    }
    $profile = $bundle['profile'];
    $seq = dd_take_file_seq($pdo, (int)$profile['site_id'], (int)$profile['file_seq']);
    $body = dd_cpa005_build($profile, $bundle['rows'], $issue, $seq);
    $slug = $profile['bank'] !== '' ? $profile['bank'] : 'eft';
    $filename = $slug . '-direct-deposit-' . $issue . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT) . '.txt';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $body;
};

if ($onlySite > 0) {
    foreach ($siteFiles as $bundle) {
        if ((int)$bundle['profile']['site_id'] === $onlySite) {
            $sendFile($bundle);
            exit;
        }
    }
}

if (count($siteFiles) === 1 && !$problems) {
    $sendFile($siteFiles[0]);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo '<div style="font-family:sans-serif;max-width:760px;margin:24px auto;line-height:1.45;">';
echo '<h2>Direct deposit file</h2>';
if (!$ready && !$problems) {
    echo '<p>None of the ticked people are marked for direct deposit. Tick Direct deposit beside the employee, or set their payment method to Direct deposit, then create the file again.</p></div>';
    exit;
}
if (!$siteFiles && $problems) {
    echo '<p>No bank file was created.</p>';
} else {
    echo '<p>' . count($siteFiles) . ' site file(s) can be downloaded. Each file is the Payments Canada payroll format used by RBC, TD, and BMO. Upload it as an EFT or CPA-005 file. The bank, institution, and originator number are taken from that site.</p>';
}
if ($problems) {
    echo '<ul>';
    foreach ($problems as $p) {
        echo '<li><strong>' . htmlspecialchars($p['name']) . '</strong> — ' . htmlspecialchars($p['why']);
        if (!empty($p['site_id'])) {
            echo ' <a href="edit_site.php?id=' . (int)$p['site_id'] . '">Edit site</a>';
        }
        echo '</li>';
    }
    echo '</ul>';
}
foreach ($siteFiles as $bundle) {
    $profile = $bundle['profile'];
    $label = $profile['bank_label'] !== '' ? $profile['bank_label'] : 'Bank';
    echo '<form method="POST" style="margin:12px 0;">';
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(ps_csrf_token()) . '">';
    echo '<input type="hidden" name="org_id" value="' . (int)$orgId . '">';
    echo '<input type="hidden" name="site_id" value="' . (int)$profile['site_id'] . '">';
    echo '<input type="hidden" name="issue_date" value="' . htmlspecialchars($issue) . '">';
    echo '<input type="hidden" name="period_start" value="' . htmlspecialchars($periodStart) . '">';
    echo '<input type="hidden" name="period_end" value="' . htmlspecialchars($periodEnd) . '">';
    foreach ($lineIds as $id) {
        echo '<input type="hidden" name="doc_line_ids[]" value="' . (int)$id . '">';
    }
    foreach (array_keys($eftUsers) as $uid) {
        echo '<input type="hidden" name="eft_user[]" value="' . (int)$uid . '">';
    }
    echo '<button type="submit">Download ' . htmlspecialchars($label) . ' file for ' . htmlspecialchars($profile['name']) . ' (' . count($bundle['rows']) . ')</button>';
    echo '</form>';
}
echo '</div>';
