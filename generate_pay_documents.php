<?php
/**
 * Path: /generate_pay_documents.php
 * Generates a combined multi-page PDF of CHEQUE + PAYSTUB per employee for the
 * payroll_finalized line IDs selected (checkboxes) on payroll_master_ledger.php.
 *
 * Sources (single source of truth — no rate re-guessing):
 *   - Money + hours + STORED deductions: payroll_finalized (cpp, ei, income_tax, etc.)
 *   - Employee info: users (Name, bank_transit/institution/account, Email, address)
 *   - Employer info: sites (nick_name, corp_legal_name, street_address, paystub_* …)
 *
 * Stub style is per site: paystub_layout=bramalea | legacy (default).
 * Bramalea: Employee Paystub + Qty/Rate/Current/YTD. Legacy: earnings statement.
 *
 * Library: FPDF at /fpdf/fpdf.php (pure PHP, relative include for migration safety).
 *
 * CLI (verify only): php generate_pay_documents.php --site=121 --from=2026-07-13 --to=2026-07-26 --name=Avtar --out=/tmp/stub.pdf
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

$is_cli = (PHP_SAPI === 'cli');
if (!$is_cli && session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once __DIR__ . '/engine/attendance_integrity_lib.php';

$paydoc_org_id = 0;
$record_cheques = false;
if (!$is_cli) {
    if (!isset($_SESSION['user_id'])) { die("Access denied."); }
    require_once 'payroll_scope.php';
    require_once __DIR__ . '/engine/payroll_cheque_lib.php';
    ps_require_payroll_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        ps_require_csrf();
        $record_cheques = true;
    }
    $paydoc_org_id = (int)ps_selected_org($pdo);
}

$FPDF_PATH = __DIR__ . '/fpdf/fpdf.php';
if (!file_exists($FPDF_PATH)) {
    die("PDF library not found. Please upload FPDF to: " . htmlspecialchars($FPDF_PATH));
}
require_once $FPDF_PATH;

$valid_ymd = static function ($v): ?string {
    $v = trim((string)$v);
    if ($v === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
    $dt = \DateTime::createFromFormat('Y-m-d', $v);
    return ($dt && $dt->format('Y-m-d') === $v) ? $v : null;
};

function paydocs_site_cols(PDO $pdo): array {
    static $cols = null;
    if ($cols === null) {
        $cols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM sites') as $c) $cols[$c['Field']] = true;
    }
    return $cols;
}
function paydocs_fin_cols(PDO $pdo): array {
    static $cols = null;
    if ($cols === null) {
        $cols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM payroll_finalized') as $c) $cols[$c['Field']] = true;
    }
    return $cols;
}
function paydocs_hhmm($hours): string {
    $h = max(0, (float)$hours);
    $hh = (int)floor($h + 1e-9);
    $mm = (int)round(($h - $hh) * 60);
    if ($mm >= 60) { $hh++; $mm = 0; }
    if ($mm < 0) $mm = 0;
    return $hh . ':' . str_pad((string)$mm, 2, '0', STR_PAD_LEFT);
}
function paydocs_mdy($ymd): string {
    $ymd = (string)$ymd;
    $dt = \DateTime::createFromFormat('Y-m-d', $ymd);
    return ($dt && $dt->format('Y-m-d') === $ymd) ? $dt->format('m/d/Y') : $ymd;
}
function paydocs_postal($z): string {
    $z = strtoupper(preg_replace('/\s+/', '', (string)$z) ?? '');
    if (preg_match('/^([A-Z]\d[A-Z])(\d[A-Z]\d)$/', $z, $m)) return $m[1] . ' ' . $m[2];
    return (string)$z;
}
function paydocs_is_stat_worked(string $cat, string $label): bool {
    $t = strtolower($cat . ' ' . $label);
    return (bool)preg_match('/stat\s*day\s*worked|stat\s*worked|worked\s*(the\s*)?(stat|holiday)|holiday\s*premium/', $t);
}
function paydocs_is_public_holiday(string $cat, string $label): bool {
    if (paydocs_is_stat_worked($cat, $label)) return false;
    $t = strtolower($cat . ' ' . $label);
    return (bool)preg_match('/public\s*holiday|stat\s*holiday|statutory|\bph\b|stat\s*pay|holiday\s*pay/', $t);
}
function paydocs_is_bonus(string $cat, string $label): bool {
    $t = strtolower($cat . ' ' . $label);
    return (bool)preg_match('/bonus/', $t);
}
function paydocs_is_car_allowance(string $cat, string $label): bool {
    $t = strtolower($cat . ' ' . $label);
    return (bool)preg_match('/\b(?:car|auto|automobile|vehicle|motor\s+vehicle)\s+allowance\b/', $t);
}
/** Pull bonus / car allowance out of regular pay so each prints on its own stub line. */
function paydocs_peel_special(array &$bucket, string $cat, string $label, float $gross, float $bonusPay, float $regPay, bool $bonusAlreadySummed = false): void {
    if (!isset($bucket['car_allow'])) $bucket['car_allow'] = 0.0;
    if (paydocs_is_car_allowance($cat, $label)) {
        $bucket['car_allow'] += $gross;
        if ($regPay > 0.004) $bucket['reg_pay'] -= $regPay;
        return;
    }
    if (!$bonusAlreadySummed) $bucket['bonus'] += $bonusPay;
    if (paydocs_is_bonus($cat, $label) && $bonusPay < 0.005) {
        $bucket['bonus'] += $gross;
        if ($regPay > 0.004) $bucket['reg_pay'] -= $regPay;
    }
}
function paydocs_split_unit(string $street, string $unit): array {
    $street = trim($street);
    $unit = trim($unit);
    if ($unit === '' && preg_match('/^(.*?),\s*((?:Unit|Suite|Ste\.?|#)\s*.+)$/i', $street, $m)) {
        $street = trim($m[1]);
        $unit = trim($m[2]);
    }
    return [$street, $unit];
}

// ---- gather selected finalized line IDs (POST from ledger, GET, or CLI) ----
$line_ids = [];
$cli_out = null;
$req_period_start = $valid_ymd($_POST['period_start'] ?? $_GET['period_start'] ?? '');
$req_period_end   = $valid_ymd($_POST['period_end']   ?? $_GET['period_end']   ?? '');
$req_issue_date   = $valid_ymd($_POST['issue_date']   ?? $_GET['issue_date']   ?? '');

if ($is_cli) {
    $cli_site = 0; $cli_from = null; $cli_to = null; $cli_name = ''; $cli_ids = '';
    global $argv;
    foreach (($argv ?? []) as $a) {
        if (str_starts_with($a, '--out='))   $cli_out = substr($a, 6);
        if (str_starts_with($a, '--site='))  $cli_site = (int)substr($a, 7);
        if (str_starts_with($a, '--from='))  $cli_from = $valid_ymd(substr($a, 7));
        if (str_starts_with($a, '--to='))    $cli_to = $valid_ymd(substr($a, 5));
        if (str_starts_with($a, '--name='))  $cli_name = trim(substr($a, 7));
        if (str_starts_with($a, '--ids='))   $cli_ids = substr($a, 6);
        if (str_starts_with($a, '--issue=')) $req_issue_date = $valid_ymd(substr($a, 8));
    }
    if ($cli_ids !== '') {
        $line_ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $cli_ids)))));
    } elseif ($cli_site > 0 && $cli_from && $cli_to) {
        $req_period_start = $req_period_start ?: $cli_from;
        $req_period_end   = $req_period_end ?: $cli_to;
        $sqlCli = "SELECT f.id FROM payroll_finalized f
                   LEFT JOIN users u ON u.id = f.user_id
                   WHERE f.site_id = ? AND f.work_date BETWEEN ? AND ?
                     AND " . att_finalized_live_predicate($pdo, 'f');
        $paramsCli = [$cli_site, $cli_from, $cli_to];
        if ($cli_name !== '') {
            $sqlCli .= " AND u.Name LIKE ?";
            $paramsCli[] = '%' . $cli_name . '%';
        }
        $st = $pdo->prepare($sqlCli);
        $st->execute($paramsCli);
        $line_ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    if ($cli_from) { $req_period_start = $req_period_start ?: $cli_from; }
    if ($cli_to) { $req_period_end = $req_period_end ?: $cli_to; }
} else {
    if (!empty($_POST['doc_line_ids']))      { $line_ids = (array)$_POST['doc_line_ids']; }
    elseif (!empty($_GET['doc_line_ids']))   { $line_ids = explode(',', (string)$_GET['doc_line_ids']); }
}
$line_ids = array_values(array_unique(array_filter(array_map('intval', $line_ids))));
if (empty($line_ids)) { die("No rows were selected. Go back to the ledger, tick some shifts, and click Generate."); }

$eft_suppress = [];
if (!empty($_POST['eft_suppress']))    { $eft_suppress = (array)$_POST['eft_suppress']; }
elseif (!empty($_GET['eft_suppress'])) { $eft_suppress = explode(',', (string)$_GET['eft_suppress']); }
$eft_suppress = array_flip(array_map('intval', $eft_suppress));

$cheque_mode = ($_POST['cheque_mode'] ?? $_GET['cheque_mode'] ?? 'blank');
$cheque_mode = ($cheque_mode === 'preprinted') ? 'preprinted' : 'blank';

$PRE = [
    'date'   => ['x' => 150, 'y' => 24, 'w' => 50],
    'payee'  => ['x' => 28,  'y' => 38, 'w' => 120],
    'amount' => ['x' => 165, 'y' => 37, 'w' => 38],
    'words'  => ['x' => 18,  'y' => 49, 'w' => 180],
    'memo'   => ['x' => 22,  'y' => 70, 'w' => 90],
];

$sCols = paydocs_site_cols($pdo);
$fCols = paydocs_fin_cols($pdo);
$extraSite = '';
if (!empty($sCols['corp_legal_name'])) $extraSite .= ", s.corp_legal_name AS corp_legal_name";
if (!empty($sCols['unit_suite'])) $extraSite .= ", s.unit_suite AS site_unit";
if (!empty($sCols['logo_url'])) $extraSite .= ", s.logo_url AS logo_url";
if (!empty($sCols['paystub_layout'])) $extraSite .= ", s.paystub_layout AS paystub_layout";
if (!empty($sCols['paystub_pay_method'])) $extraSite .= ", s.paystub_pay_method AS paystub_pay_method";
if (!empty($sCols['paystub_ytd_calendar'])) $extraSite .= ", s.paystub_ytd_calendar AS paystub_ytd_calendar";
if (!empty($sCols['paystub_show_zero_stat'])) $extraSite .= ", s.paystub_show_zero_stat AS paystub_show_zero_stat";
$extraFin = '';
if (!empty($fCols['reg_pay'])) $extraFin .= ", f.reg_pay";
if (!empty($fCols['bonus_pay'])) $extraFin .= ", f.bonus_pay";

$in = implode(',', array_fill(0, count($line_ids), '?'));
$sql = "SELECT
            f.id AS line_id, f.user_id, f.site_id, f.work_date, f.pay_period_end,
            f.reg_hours, f.overtime_hours, f.travel_hours, f.hourly_rate,
            f.total_gross, f.cpp AS ee_cpp, f.ei AS ee_ei, f.income_tax AS ee_tax,
            f.cpp_er AS er_cpp, f.ei_er AS er_ei, f.wcb AS er_wcb, f.vac AS er_vac,
            f.paid_amount, f.payment_status, f.category, f.display_label
            $extraFin,
            u.Name AS emp_name, u.Email AS emp_email,
            u.bank_transit, u.bank_institution, u.bank_account,
            u.street_address AS emp_street, u.city AS emp_city, u.province AS emp_prov, u.postal_code AS emp_postal,
            s.nick_name AS site_name, s.street_address AS site_addr, s.city AS site_city,
            s.state AS site_state, s.province AS site_prov, s.zipcode AS site_zip
            $extraSite
        FROM payroll_finalized f
        LEFT JOIN users u ON f.user_id = u.id
        LEFT JOIN sites s ON f.site_id = s.Sid
        WHERE f.id IN ($in)" . ($is_cli ? '' : ' AND u.org_id = ?') . "
          AND " . att_finalized_live_predicate($pdo, 'f') . "
        ORDER BY s.nick_name ASC, u.Name ASC, f.work_date ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($is_cli ? $line_ids : array_merge($line_ids, [$paydoc_org_id]));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($rows)) { die("Selected rows not found."); }

$vac_paid_mode = false;
try {
    $orgGuess = (int)(!$is_cli ? $paydoc_org_id : (int)($_SESSION['org_id'] ?? 0));
    if ($orgGuess <= 0 && !empty($rows[0]['site_id'])) {
        $oq = $pdo->prepare("SELECT org_id FROM sites WHERE SId=? LIMIT 1");
        $oq->execute([(int)$rows[0]['site_id']]);
        $orgGuess = (int)($oq->fetchColumn() ?: 0);
    }
    if ($orgGuess > 0) {
        $vm = $pdo->prepare("SELECT COALESCE(vacation_payout_mode,'accrue') FROM organizations WHERE id=? LIMIT 1");
        $vm->execute([$orgGuess]);
        $vac_paid_mode = (strtolower(trim((string)$vm->fetchColumn())) === 'pay_each_period');
    }
} catch (Throwable $e) {
    $vac_paid_mode = false;
}

$groups = [];
foreach ($rows as $r) {
    $key = $r['site_id'] . '|' . $r['user_id'];
    $emp_street = trim((string)($r['emp_street'] ?? ''));
    $emp_cityline = trim(($r['emp_city'] ?? '') . ', ' . ($r['emp_prov'] ?? '') . ' ' . paydocs_postal($r['emp_postal'] ?? ''), ', ');
    $emp_addr_one = trim($emp_street . ', ' . $emp_cityline, ', ');
    $site_addr_one = trim(($r['site_addr'] ?? '') . ', ' . ($r['site_city'] ?? '') . ' ' . ($r['site_prov'] ?: ($r['site_state'] ?? '')) . ' ' . paydocs_postal($r['site_zip'] ?? ''), ', ');
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'emp_name'   => $r['emp_name'] ?: ('User #' . $r['user_id']),
            'user_id'    => (int)$r['user_id'],
            'site_id'    => (int)$r['site_id'],
            'emp_email'  => $r['emp_email'] ?? '',
            'emp_street' => $emp_street,
            'emp_cityline' => $emp_cityline,
            'emp_addr'   => $emp_addr_one,
            'transit'    => $r['bank_transit'] ?: '',
            'institution'=> $r['bank_institution'] ?: '',
            'account'    => $r['bank_account'] ?: '',
            'site_name'  => $r['site_name'] ?: 'Employer',
            'corp_legal' => trim((string)($r['corp_legal_name'] ?? '')),
            'site_street'=> trim((string)($r['site_addr'] ?? '')),
            'site_unit'  => trim((string)($r['site_unit'] ?? '')),
            'site_city'  => trim((string)($r['site_city'] ?? '')),
            'site_prov'  => trim((string)($r['site_prov'] ?: ($r['site_state'] ?? ''))),
            'site_zip'   => trim((string)($r['site_zip'] ?? '')),
            'site_addr'  => $site_addr_one,
            'logo_url'   => trim((string)($r['logo_url'] ?? '')),
            'paystub_layout' => strtolower(trim((string)($r['paystub_layout'] ?? 'legacy'))) === 'bramalea' ? 'bramalea' : 'legacy',
            'pay_method' => strtolower(trim((string)($r['paystub_pay_method'] ?? 'cheque'))) === 'debit' ? 'debit' : 'cheque',
            'ytd_calendar' => !isset($r['paystub_ytd_calendar']) || (int)$r['paystub_ytd_calendar'] === 1,
            'show_zero_stat' => (int)($r['paystub_show_zero_stat'] ?? 0) === 1,
            'period_end' => $r['pay_period_end'],
            'reg_h'=>0,'ot_h'=>0,'trav_h'=>0,
            'reg_pay'=>0,'bonus'=>0,'car_allow'=>0,'ph_pay'=>0,'stat_worked'=>0,
            'gross'=>0,'ee_cpp'=>0,'ee_ei'=>0,'ee_tax'=>0,
            'er_cpp'=>0,'er_ei'=>0,'er_wcb'=>0,'er_vac'=>0,
            'vac_earn'=>0,
            'paid'=>0, 'lines'=>[],
            'wstart'=>$r['work_date'], 'wend'=>$r['work_date'],
        ];
    }
    $g =& $groups[$key];
    $cat = (string)($r['category'] ?? '');
    $lab = (string)($r['display_label'] ?? '');
    $g['reg_h']  += (float)$r['reg_hours'];
    $g['ot_h']   += (float)$r['overtime_hours'];
    $g['trav_h'] += (float)$r['travel_hours'];
    $g['gross']  += (float)$r['total_gross'];
    $g['ee_cpp'] += (float)$r['ee_cpp'];
    $g['ee_ei']  += (float)$r['ee_ei'];
    $g['ee_tax'] += (float)$r['ee_tax'];
    $g['er_cpp'] += (float)$r['er_cpp'];
    $g['er_ei']  += (float)$r['er_ei'];
    $g['er_wcb'] += (float)$r['er_wcb'];
    $g['er_vac'] += (float)$r['er_vac'];
    $g['vac_earn'] += (float)$r['er_vac'];
    $g['paid']   += (float)$r['paid_amount'];
    $g['reg_pay'] += (float)($r['reg_pay'] ?? 0);
    if (paydocs_is_public_holiday($cat, $lab)) $g['ph_pay'] += (float)$r['total_gross'];
    if (paydocs_is_stat_worked($cat, $lab)) $g['stat_worked'] += (float)$r['total_gross'];
    paydocs_peel_special(
        $g, $cat, $lab,
        (float)$r['total_gross'],
        (float)($r['bonus_pay'] ?? 0),
        (float)($r['reg_pay'] ?? 0)
    );
    if ($r['work_date'] < $g['wstart']) $g['wstart'] = $r['work_date'];
    if ($r['work_date'] > $g['wend'])   $g['wend']   = $r['work_date'];
    $g['lines'][] = $r;
    unset($g);
}

foreach ($groups as &$g) {
    if (function_exists('pe_read_period_stats') && !empty($g['user_id']) && !empty($g['wstart'])) {
        $site = (int)($g['lines'][0]['site_id'] ?? 0);
        $stats = pe_read_period_stats($pdo, $g['wstart'], $g['wend'] ?: $g['wstart'], [(int)$g['user_id']], $site ? [$site] : []);
        $k = ((int)$g['user_id']) . '|' . $site;
        if (isset($stats[$k])) {
            $g['ee_cpp'] = (float)$stats[$k]['cpp'];
            $g['er_cpp'] = (float)$stats[$k]['cpp_er'];
        }
    }
    $pstart = $req_period_start ?: $g['wstart'];
    $pend   = $req_period_end ?: ($g['wend'] ?: ($g['period_end'] ?: $g['wstart']));
    if ($req_period_start && $req_period_end && $req_period_start > $req_period_end) {
        $tmp = $pstart; $pstart = $pend; $pend = $tmp;
    }
    $g['disp_start'] = $pstart;
    $g['disp_end']   = $pend;
    $issueBase = $req_period_end ?: $pend;
    $g['issue_date'] = $req_issue_date ?: date('Y-m-d', strtotime($issueBase . ' +5 days'));
}
unset($g);

function money($v){ return '$' . number_format((float)$v, 2); }
function num_to_words_dollars($amount){
    $whole = floor($amount);
    $cents = (int)round(($amount - $whole) * 100);
    $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $conv = function($n) use (&$conv,$ones,$tens){
        if ($n < 20) return $ones[$n];
        if ($n < 100) return trim($tens[intval($n/10)] . ' ' . $ones[$n%10]);
        if ($n < 1000) return trim($ones[intval($n/100)] . ' Hundred ' . $conv($n%100));
        if ($n < 1000000) return trim($conv(intval($n/1000)) . ' Thousand ' . $conv($n%1000));
        return trim($conv(intval($n/1000000)) . ' Million ' . $conv($n%1000000));
    };
    $words = $whole > 0 ? $conv((int)$whole) : 'Zero';
    return strtoupper($words . ' DOLLARS AND ' . str_pad($cents,2,'0',STR_PAD_LEFT) . '/100');
}

function paydocs_ytd(PDO $pdo, array $g, bool $vac_paid_mode): array {
    $uid = (int)$g['user_id'];
    $sid = (int)$g['site_id'];
    // Year-to-date runs through the pay period on the cheque. The cheque date
    // is often later and must not pull a newer year-to-date snapshot.
    $asof = (string)($g['disp_end'] ?? '');
    if ($asof === '') $asof = (string)($g['issue_date'] ?? '');
    $year = (int)substr((string)($g['disp_end'] ?? '') ?: (string)($g['issue_date'] ?? ''), 0, 4);
    if ($year < 1990) $year = (int)date('Y');
    $from = $g['ytd_calendar'] ? sprintf('%04d-01-01', $year) : (string)$g['disp_start'];
    $out = [
        'reg_h'=>0.0,'ot_h'=>0.0,'trav_h'=>0.0,'reg_pay'=>0.0,'vac'=>0.0,'bonus'=>0.0,'car_allow'=>0.0,
        'ph'=>0.0,'stat'=>0.0,'gross'=>0.0,'cpp'=>0.0,'ei'=>0.0,'tax'=>0.0,'paid'=>0.0,
        'sick_avail'=>0.0,'vac_avail'=>0.0,'vac_used'=>0.0,
    ];
    $fCols = paydocs_fin_cols($pdo);
    $regPaySel = !empty($fCols['reg_pay']) ? 'COALESCE(SUM(reg_pay),0)' : '0';
    $bonusSel = !empty($fCols['bonus_pay']) ? 'COALESCE(SUM(bonus_pay),0)' : '0';
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(reg_hours),0) rh, COALESCE(SUM(overtime_hours),0) ot, COALESCE(SUM(travel_hours),0) th,
                $regPaySel rp, COALESCE(SUM(vac),0) vac, $bonusSel bonus,
                COALESCE(SUM(total_gross),0) g, COALESCE(SUM(cpp),0) cpp, COALESCE(SUM(ei),0) ei,
                COALESCE(SUM(income_tax),0) tax, COALESCE(SUM(paid_amount),0) paid
           FROM payroll_finalized
          WHERE user_id=? AND site_id=? AND work_date BETWEEN ? AND ?
            AND " . att_finalized_live_predicate($pdo, 'payroll_finalized')
    );
    $st->execute([$uid, $sid, $from, $asof]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $out['reg_h'] = (float)$r['rh']; $out['ot_h'] = (float)$r['ot']; $out['trav_h'] = (float)$r['th'];
    $out['reg_pay'] = (float)$r['rp']; $out['vac'] = (float)$r['vac']; $out['bonus'] = (float)$r['bonus'];
    $out['gross'] = (float)$r['g']; $out['cpp'] = (float)$r['cpp']; $out['ei'] = (float)$r['ei'];
    $out['tax'] = (float)$r['tax']; $out['paid'] = (float)$r['paid'];
    $out['vac_used'] = $vac_paid_mode ? $out['vac'] : 0.0;

    $st2 = $pdo->prepare(
        "SELECT category, display_label, COALESCE(SUM(total_gross),0) g, COALESCE(SUM(bonus_pay),0) b, COALESCE(SUM(reg_pay),0) rp
           FROM payroll_finalized
          WHERE user_id=? AND site_id=? AND work_date BETWEEN ? AND ?
            AND " . att_finalized_live_predicate($pdo, 'payroll_finalized') . "
          GROUP BY category, display_label"
    );
    try {
        $st2->execute([$uid, $sid, $from, $asof]);
        foreach ($st2 as $row) {
            $cat = (string)($row['category'] ?? '');
            $lab = (string)($row['display_label'] ?? '');
            if (paydocs_is_public_holiday($cat, $lab)) $out['ph'] += (float)$row['g'];
            if (paydocs_is_stat_worked($cat, $lab)) $out['stat'] += (float)$row['g'];
            paydocs_peel_special($out, $cat, $lab, (float)$row['g'], (float)$row['b'], (float)$row['rp'], true);
        }
    } catch (Throwable $e) {
        $st2 = $pdo->prepare(
            "SELECT category, display_label, COALESCE(SUM(total_gross),0) g, COALESCE(SUM(reg_pay),0) rp
               FROM payroll_finalized
              WHERE user_id=? AND site_id=? AND work_date BETWEEN ? AND ?
                AND " . att_finalized_live_predicate($pdo, 'payroll_finalized') . "
              GROUP BY category, display_label"
        );
        $st2->execute([$uid, $sid, $from, $asof]);
        foreach ($st2 as $row) {
            $cat = (string)($row['category'] ?? '');
            $lab = (string)($row['display_label'] ?? '');
            if (paydocs_is_public_holiday($cat, $lab)) $out['ph'] += (float)$row['g'];
            if (paydocs_is_stat_worked($cat, $lab)) $out['stat'] += (float)$row['g'];
            paydocs_peel_special($out, $cat, $lab, (float)$row['g'], 0.0, (float)$row['rp'], true);
        }
    }

    if ($out['reg_pay'] < 0.005) {
        $out['reg_pay'] = max(0, $out['gross'] - $out['vac'] - $out['ph'] - $out['stat'] - $out['bonus'] - (float)$out['car_allow']);
    }

    if (!$vac_paid_mode) {
        try {
            $b = $pdo->prepare(
                "SELECT COALESCE(SUM(vacation_accrued),0) FROM payroll_balance
                  WHERE user_id=? AND site_id=? AND work_date BETWEEN ? AND ?"
            );
            $b->execute([$uid, $sid, $from, $asof]);
            $out['vac_avail'] = (float)$b->fetchColumn();
        } catch (Throwable $e) {
            $out['vac_avail'] = 0.0;
        }
    }
    return paydocs_apply_stub_ytd($pdo, $g, $out, $asof);
}

function paydocs_apply_stub_ytd(PDO $pdo, array $g, array $out, string $asof): array
{
    $uid = (int)$g['user_id'];
    $sid = (int)$g['site_id'];
    $year = (int)substr((string)($g['disp_end'] ?? '') ?: (string)($g['issue_date'] ?? ''), 0, 4);
    if ($year < 1990) $year = (int)date('Y');
    try {
        $st = $pdo->prepare(
            "SELECT as_of_date, ytd_gross, ytd_net, ytd_reg, ytd_vac, ytd_ph, ytd_stat, ytd_bonus,
                    ytd_cpp, ytd_ei, ytd_tax
               FROM payroll_ytd_opening
              WHERE user_id=? AND site_id=? AND year=? AND as_of_date<=?
              ORDER BY as_of_date DESC LIMIT 1"
        );
        $st->execute([$uid, $sid, $year, $asof]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $out;
    }
    if (!$o) return $out;

    $snap = (string)$o['as_of_date'];
    // Opening gross already includes vacation. The stub adds vacation on top,
    // the same way the current column does, so keep vacation out of this gross.
    $out['gross'] = (float)$o['ytd_gross'] - (float)$o['ytd_vac'];
    $out['reg_pay'] = (float)$o['ytd_reg'];
    $out['vac'] = (float)$o['ytd_vac'];
    $out['ph'] = (float)$o['ytd_ph'];
    $out['stat'] = (float)$o['ytd_stat'];
    $out['bonus'] = (float)$o['ytd_bonus'];
    $out['car_allow'] = 0.0;
    $out['cpp'] = (float)$o['ytd_cpp'];
    $out['ei'] = (float)$o['ytd_ei'];
    $out['tax'] = (float)$o['ytd_tax'];
    $out['paid'] = (float)$o['ytd_net'];
    $out['vac_used'] = (float)$o['ytd_vac'];

    try {
        $st2 = $pdo->prepare(
            "SELECT COALESCE(SUM(reg_pay),0) rp, COALESCE(SUM(vac),0) vac,
                    COALESCE(SUM(bonus_pay),0) bonus, COALESCE(SUM(total_gross),0) g,
                    COALESCE(SUM(cpp),0) cpp, COALESCE(SUM(ei),0) ei,
                    COALESCE(SUM(income_tax),0) tax, COALESCE(SUM(paid_amount),0) paid
               FROM payroll_finalized
              WHERE user_id=? AND site_id=? AND work_date > ? AND work_date <= ?
                AND " . att_finalized_live_predicate($pdo, 'payroll_finalized')
        );
        $st2->execute([$uid, $sid, $snap, $asof]);
        $add = $st2->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['reg_pay'] += (float)$add['rp'];
        $out['vac'] += (float)$add['vac'];
        $out['bonus'] += (float)$add['bonus'];
        $out['gross'] += (float)$add['g'];
        $out['cpp'] += (float)$add['cpp'];
        $out['ei'] += (float)$add['ei'];
        $out['tax'] += (float)$add['tax'];
        $out['paid'] += (float)$add['paid'];
        $out['vac_used'] = $out['vac'];

        $st3 = $pdo->prepare(
            "SELECT category, display_label, COALESCE(SUM(total_gross),0) g,
                    COALESCE(SUM(bonus_pay),0) b, COALESCE(SUM(reg_pay),0) rp
               FROM payroll_finalized
              WHERE user_id=? AND site_id=? AND work_date > ? AND work_date <= ?
                AND " . att_finalized_live_predicate($pdo, 'payroll_finalized') . "
              GROUP BY category, display_label"
        );
        $st3->execute([$uid, $sid, $snap, $asof]);
        foreach ($st3 as $row) {
            $cat = (string)($row['category'] ?? '');
            $lab = (string)($row['display_label'] ?? '');
            if (function_exists('paydocs_is_public_holiday') && paydocs_is_public_holiday($cat, $lab)) {
                $out['ph'] += (float)$row['g'];
            }
            if (function_exists('paydocs_is_stat_worked') && paydocs_is_stat_worked($cat, $lab)) {
                $out['stat'] += (float)$row['g'];
            }
            if (function_exists('paydocs_peel_special')) {
                paydocs_peel_special($out, $cat, $lab, (float)$row['g'], (float)$row['b'], (float)$row['rp'], true);
            }
        }
    } catch (Throwable $e) {
        // keep snapshot only
    }
    return $out;
}

function paydocs_employer_name(array $g): string {
    $legal = trim((string)($g['corp_legal'] ?? ''));
    $nick = trim((string)($g['site_name'] ?? ''));
    if ($legal !== '') {
        if ($nick !== '' && stripos($legal, $nick) === false) return $legal . '- ' . $nick;
        return $legal;
    }
    return $nick !== '' ? $nick : 'Employer';
}

function paydocs_draw_bramalea(FPDF $pdf, array $g, array $ytd, bool $vac_paid_mode, int $chq_no): void {
    $L = 15; $W = 185;
    $employer = paydocs_employer_name($g);
    [$stStreet, $stUnit] = paydocs_split_unit((string)$g['site_street'], (string)$g['site_unit']);
    $stCity = trim($g['site_city'] . ', ' . $g['site_prov'] . ' ' . paydocs_postal($g['site_zip']), ', ');

    $logo = (string)($g['logo_url'] ?? '');
    $logoPath = '';
    if ($logo !== '' && !preg_match('#^https?://#i', $logo)) {
        $cand = $logo;
        if ($logo[0] !== '/' && !preg_match('#^[A-Za-z]:\\\\#', $logo)) $cand = __DIR__ . '/' . ltrim($logo, '/');
        if (is_file($cand)) $logoPath = $cand;
    }

    $pdf->SetTextColor(20, 20, 20);
    $y = 12;
    if ($logoPath !== '') {
        try { $pdf->Image($logoPath, 160, 10, 40); } catch (Throwable $e) { /* skip bad image */ }
    }
    $pdf->SetXY($L, $y);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell($W, 5, $employer, 0, 2, 'L');
    $pdf->SetFont('Arial', '', 9);
    if ($stStreet !== '') $pdf->Cell($W, 4.2, $stStreet, 0, 2, 'L');
    if ($stUnit !== '') $pdf->Cell($W, 4.2, $stUnit, 0, 2, 'L');
    if ($stCity !== '') $pdf->Cell($W, 4.2, $stCity, 0, 2, 'L');

    $pdf->Ln(2);
    $pdf->SetX($L);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell($W, 5, $g['emp_name'], 0, 2, 'L');
    $pdf->SetFont('Arial', '', 9);
    if ($g['emp_street'] !== '') $pdf->Cell($W, 4.2, $g['emp_street'], 0, 2, 'L');
    if ($g['emp_cityline'] !== '') $pdf->Cell($W, 4.2, $g['emp_cityline'], 0, 2, 'L');

    $y = $pdf->GetY() + 4;
    $chqLabel = ($g['pay_method'] === 'debit') ? 'Debit' : ($chq_no > 0 ? ('#' . $chq_no) : 'Stub');
    $pdf->SetFillColor(230, 230, 230);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetXY($L, $y);
    $pdf->Cell(42, 7, 'Employee Paystub', 1, 0, 'L', true);
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(48, 7, 'Cheque number: ' . $chqLabel, 1, 0, 'L', true);
    $pdf->Cell(55, 7, 'Pay Period: ' . paydocs_mdy($g['disp_start']) . ' - ' . paydocs_mdy($g['disp_end']), 1, 0, 'L', true);
    $pdf->Cell(40, 7, 'Cheque Date: ' . paydocs_mdy($g['issue_date']), 1, 1, 'L', true);

    $vac_curr = $vac_paid_mode ? (float)$g['vac_earn'] : 0.0;
    $reg_curr = (float)$g['reg_pay'];
    if ($reg_curr < 0.005) {
        $reg_curr = max(0, (float)$g['gross'] - $vac_curr - (float)$g['ph_pay'] - (float)$g['stat_worked'] - (float)$g['bonus'] - (float)($g['car_allow'] ?? 0));
    }
    $rate = (float)($g['lines'][0]['hourly_rate'] ?? 0);
    if ($g['reg_h'] > 0.009 && $reg_curr > 0.009) $rate = $reg_curr / $g['reg_h'];
    $hours_all = (float)$g['reg_h'] + (float)$g['ot_h'] + (float)$g['trav_h'];
    $ded_curr = (float)$g['ee_cpp'] + (float)$g['ee_ei'] + (float)$g['ee_tax'];
    $vac_ytd = $vac_paid_mode ? (float)$ytd['vac'] : 0.0;
    $net_curr = max(0, (float)$g['gross'] + $vac_curr - $ded_curr);
    $ded_ytd = (float)$ytd['cpp'] + (float)$ytd['ei'] + (float)$ytd['tax'];
    $net_ytd = max(0, (float)$ytd['gross'] + $vac_ytd - $ded_ytd);
    $reg_ytd = (float)$ytd['reg_pay'];

    $y = $pdf->GetY() + 3;
    $pdf->SetXY($L, $y);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(30, 5, paydocs_hhmm($hours_all), 0, 0, 'L');
    $pdf->Cell(50, 5, number_format((float)$g['gross'], 2), 0, 0, 'R');
    $pdf->Cell(50, 5, number_format((float)$ytd['gross'], 2), 0, 1, 'R');
    $pdf->SetX($L);
    $pdf->Cell(30, 5, '', 0, 0, 'L');
    $pdf->Cell(50, 5, '-' . number_format($ded_curr, 2), 0, 0, 'R');
    $pdf->Cell(50, 5, '-' . number_format($ded_ytd, 2), 0, 1, 'R');
    $pdf->SetX($L);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(30, 6, 'Net Pay', 0, 0, 'L');
    $pdf->Cell(50, 6, number_format($net_curr, 2), 0, 0, 'R');
    $pdf->Cell(50, 6, number_format($net_ytd, 2), 0, 1, 'R');

    $y = $pdf->GetY() + 4;
    $pdf->SetXY($L, $y);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(245, 245, 245);
    $pdf->Cell(70, 6, 'Earnings and Hours', 1, 0, 'L', true);
    $pdf->Cell(22, 6, 'Qty', 1, 0, 'C', true);
    $pdf->Cell(22, 6, 'Rate', 1, 0, 'C', true);
    $pdf->Cell(35, 6, 'Current', 1, 0, 'R', true);
    $pdf->Cell(36, 6, 'YTD Amount', 1, 1, 'R', true);
    $pdf->SetFont('Arial', '', 8);

    $earn = function (string $label, $qty, $rateV, $curr, $ytdA, bool $force = false) use ($pdf, $L) {
        if (!$force && abs((float)$curr) < 0.005 && abs((float)$ytdA) < 0.005) return;
        $pdf->SetX($L);
        $pdf->Cell(70, 5.5, $label, 1, 0, 'L');
        $pdf->Cell(22, 5.5, $qty !== null && $qty !== '' ? (string)$qty : '', 1, 0, 'C');
        $pdf->Cell(22, 5.5, $rateV !== null && $rateV !== '' ? (string)$rateV : '', 1, 0, 'C');
        $pdf->Cell(35, 5.5, $curr !== null ? number_format((float)$curr, 2) : '', 1, 0, 'R');
        $pdf->Cell(36, 5.5, $ytdA !== null ? number_format((float)$ytdA, 2) : '', 1, 1, 'R');
    };

    $earn('Regular Pay', paydocs_hhmm($g['reg_h']), number_format($rate, 2), $reg_curr, $reg_ytd, true);
    if ($g['ot_h'] > 0.009) {
        $earn('Overtime', paydocs_hhmm($g['ot_h']), number_format($rate * 1.5, 2), $g['ot_h'] * $rate * 1.5, $ytd['ot_h'] * $rate * 1.5);
    }
    if ($g['trav_h'] > 0.009) {
        $earn('Travel', paydocs_hhmm($g['trav_h']), number_format($rate, 2), $g['trav_h'] * $rate, $ytd['trav_h'] * $rate);
    }
    if ($vac_paid_mode || $vac_curr > 0.009 || $vac_ytd > 0.009) {
        $earn('VacPay-Paid Out', '', '', $vac_curr, $vac_ytd, true);
    }
    $earn('Public Holiday', '', '', (float)$g['ph_pay'], (float)$ytd['ph'], !empty($g['show_zero_stat']));
    if ((float)$g['bonus'] > 0.009 || (float)$ytd['bonus'] > 0.009) {
        $earn('Bonus', '', '', (float)$g['bonus'], (float)$ytd['bonus']);
    }
    if ((float)($g['car_allow'] ?? 0) > 0.009 || (float)($ytd['car_allow'] ?? 0) > 0.009) {
        $earn('Car allowance', '', '', (float)$g['car_allow'], (float)($ytd['car_allow'] ?? 0));
    }
    $earn('Stat Day Worked', '', '', (float)$g['stat_worked'], (float)$ytd['stat'], !empty($g['show_zero_stat']));

    $y = $pdf->GetY() + 4;
    $pdf->SetXY($L, $y);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(70, 6, 'Withholdings', 1, 0, 'L', true);
    $pdf->Cell(57, 6, 'Current', 1, 0, 'R', true);
    $pdf->Cell(58, 6, 'YTD Amount', 1, 1, 'R', true);
    $pdf->SetFont('Arial', '', 8);
    $with = function (string $label, $curr, $ytdA) use ($pdf, $L) {
        $fmt = static function ($v): string {
            $v = (float)$v;
            if (abs($v) < 0.005) return number_format(0, 2);
            return '-' . number_format($v, 2);
        };
        $pdf->SetX($L);
        $pdf->Cell(70, 5.5, $label, 1, 0, 'L');
        $pdf->Cell(57, 5.5, $fmt($curr), 1, 0, 'R');
        $pdf->Cell(58, 5.5, $fmt($ytdA), 1, 1, 'R');
    };
    $with('CPP - Employee', $g['ee_cpp'], $ytd['cpp']);
    $with('EI - Employee', $g['ee_ei'], $ytd['ei']);
    $with('Federal Income Tax', $g['ee_tax'], $ytd['tax']);

    $y = $pdf->GetY() + 4;
    $pdf->SetXY($L, $y);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(70, 6, 'Sick Hours and Vacation Pay', 1, 0, 'L', true);
    $pdf->Cell(38, 6, 'Earned', 1, 0, 'C', true);
    $pdf->Cell(38, 6, 'YTD Used', 1, 0, 'C', true);
    $pdf->Cell(39, 6, 'Available', 1, 1, 'C', true);
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetX($L);
    $pdf->Cell(70, 5.5, 'Sick (hrs)', 1, 0, 'L');
    $pdf->Cell(38, 5.5, '0:00', 1, 0, 'C');
    $pdf->Cell(38, 5.5, '0:00', 1, 0, 'C');
    $pdf->Cell(39, 5.5, '0:00', 1, 1, 'C');
    $pdf->SetX($L);
    $pdf->Cell(70, 5.5, 'Vacation ($)', 1, 0, 'L');
    $pdf->Cell(38, 5.5, number_format($vac_paid_mode ? $vac_curr : (float)$g['er_vac'], 2), 1, 0, 'C');
    $pdf->Cell(38, 5.5, number_format((float)$ytd['vac_used'], 2), 1, 0, 'C');
    $pdf->Cell(39, 5.5, number_format((float)$ytd['vac_avail'], 2), 1, 1, 'C');

    $y = $pdf->GetY() + 6;
    $pdf->SetXY($L, $y);
    $pdf->SetFillColor(20, 20, 20);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(70, 8, '  NET PAY', 0, 0, 'L', true);
    $pdf->Cell(57, 8, number_format($net_curr, 2), 0, 0, 'R', true);
    $pdf->Cell(58, 8, number_format($net_ytd, 2), 0, 1, 'R', true);
    $pdf->SetTextColor(0, 0, 0);

    $pdf->Ln(3);
    $pdf->SetX($L);
    $pdf->SetFont('Arial', 'I', 8);
    $er_vac_show = $vac_paid_mode ? 0.0 : (float)$g['er_vac'];
    $er_total = (float)$g['er_cpp'] + (float)$g['er_ei'] + (float)$g['er_wcb'] + $er_vac_show;
    $pdf->MultiCell($W, 4, 'Employer contributions (not deducted from pay): CPP ' . money($g['er_cpp']) . ' | EI ' . money($g['er_ei']) . ' | WCB ' . money($g['er_wcb']) . ' | Vacation ' . money($er_vac_show) . '  =  ' . money($er_total), 0, 'L');
}

function paydocs_draw_legacy(FPDF $pdf, array $g, bool $vac_paid_mode, float $remain): void {
    $pdf->SetFillColor(15,23,42);
    $pdf->SetTextColor(255,255,255);
    $pdf->SetXY(0, 12);
    $pdf->SetFont('Arial','B',14);
    $pdf->Cell(215.9, 10, '  ' . strtoupper(substr($g['site_name'],0,40)) . '  -  EARNINGS STATEMENT', 0, 1, 'L', true);
    $pdf->SetTextColor(0,0,0);

    $pdf->SetXY(15, 28);
    $pdf->SetFont('Arial','',9);
    $pdf->Cell(90, 5, 'Pay Period: ' . $g['disp_start'] . ' to ' . $g['disp_end'], 0, 0, 'L');
    $pdf->Cell(90, 5, 'Issued: ' . $g['issue_date'], 0, 1, 'R');

    $pdf->SetXY(15, 38);
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell(90, 6, 'EMPLOYEE', 'B', 2, 'L');
    $pdf->SetFont('Arial','',9);
    $pdf->Cell(90, 5, $g['emp_name'], 0, 2, 'L');
    if ($g['emp_addr']) $pdf->Cell(90, 5, substr($g['emp_addr'],0,55), 0, 2, 'L');
    if ($g['emp_email']) $pdf->Cell(90, 5, $g['emp_email'], 0, 2, 'L');
    $acct_tail = $g['account'] !== '' ? substr($g['account'], -3) : '---';
    $pdf->Cell(90, 5, 'Deposit acct ****' . $acct_tail, 0, 2, 'L');

    $pdf->SetXY(115, 38);
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell(85, 6, 'EMPLOYER', 'B', 2, 'L');
    $pdf->SetX(115);
    $pdf->SetFont('Arial','',9);
    $pdf->Cell(85, 5, $g['site_name'], 0, 2, 'L');
    $pdf->SetX(115);
    if ($g['site_addr']) { $pdf->Cell(85, 5, substr($g['site_addr'],0,55), 0, 2, 'L'); $pdf->SetX(115); }

    $y = 75;
    $pdf->SetXY(15, $y);
    $pdf->SetFont('Arial','B',9);
    $pdf->SetFillColor(51,65,85); $pdf->SetTextColor(255,255,255);
    $pdf->Cell(95, 7, ' EARNINGS', 0, 0, 'L', true);
    $pdf->Cell(30, 7, 'HOURS', 0, 0, 'R', true);
    $pdf->Cell(60, 7, 'AMOUNT', 0, 1, 'R', true);
    $pdf->SetTextColor(0,0,0);
    $pdf->SetFont('Arial','',9);
    $rate = $g['lines'][0]['hourly_rate'] ?? 0;
    $reg_amt = $g['reg_h'] * $rate;
    $row_line = function($label,$hrs,$amt) use ($pdf){
        $pdf->SetX(15);
        $pdf->Cell(95, 6, '  ' . $label, 'B', 0, 'L');
        $pdf->Cell(30, 6, $hrs !== null ? number_format($hrs,2) : '', 'B', 0, 'R');
        $pdf->Cell(60, 6, money($amt), 'B', 1, 'R');
    };
    if ($g['reg_h']  > 0) $row_line('Regular',  $g['reg_h'],  $reg_amt);
    if ($g['ot_h']   > 0) $row_line('Overtime', $g['ot_h'],   $g['ot_h']  * $rate * 1.5);
    if ($g['trav_h'] > 0) $row_line('Travel',   $g['trav_h'], $g['trav_h']* $rate);
    if (!empty($vac_paid_mode) && $g['vac_earn'] > 0.009) {
        $row_line('Vacation', null, $g['vac_earn']);
    }
    if ((float)($g['bonus'] ?? 0) > 0.009) $row_line('Bonus', null, $g['bonus']);
    if ((float)($g['car_allow'] ?? 0) > 0.009) $row_line('Car allowance', null, $g['car_allow']);
    $pdf->SetX(15);
    $pdf->SetFont('Arial','B',9);
    $pdf->Cell(125, 7, '  GROSS PAY', 0, 0, 'L');
    $pdf->Cell(60, 7, money($g['gross']), 0, 1, 'R');

    $pdf->Ln(2);
    $pdf->SetX(15);
    $pdf->SetFont('Arial','B',9);
    $pdf->SetFillColor(51,65,85); $pdf->SetTextColor(255,255,255);
    $pdf->Cell(125, 7, ' STATUTORY DEDUCTIONS (withheld)', 0, 0, 'L', true);
    $pdf->Cell(60, 7, 'AMOUNT', 0, 1, 'R', true);
    $pdf->SetTextColor(0,0,0);
    $pdf->SetFont('Arial','',9);
    $ded_line = function($label,$amt) use ($pdf){
        $pdf->SetX(15);
        $pdf->Cell(125, 6, '  ' . $label, 'B', 0, 'L');
        $pdf->Cell(60, 6, '-' . money($amt), 'B', 1, 'R');
    };
    $ded_line('Income Tax', $g['ee_tax']);
    $ded_line('CPP',        $g['ee_cpp']);
    $ded_line('EI',         $g['ee_ei']);
    $total_ded = $g['ee_tax'] + $g['ee_cpp'] + $g['ee_ei'];
    $pdf->SetX(15);
    $pdf->SetFont('Arial','B',9);
    $pdf->Cell(125, 7, '  TOTAL DEDUCTIONS', 0, 0, 'L');
    $pdf->Cell(60, 7, '-' . money($total_ded), 0, 1, 'R');

    $net = max(0, $g['gross'] + ((!empty($vac_paid_mode) ? (float)$g['vac_earn'] : 0)) - $total_ded);
    $pdf->Ln(3);
    $pdf->SetX(15);
    $pdf->SetFillColor(15,23,42); $pdf->SetTextColor(255,255,255);
    $pdf->SetFont('Arial','B',11);
    $pdf->Cell(125, 10, '  NET PAY', 0, 0, 'L', true);
    $pdf->Cell(60, 10, money($net), 0, 1, 'R', true);
    $pdf->SetTextColor(0,0,0);

    $pdf->Ln(2);
    $pdf->SetX(15);
    $pdf->SetFont('Arial','',9);
    $pdf->Cell(125, 6, '  Amount disbursed', 0, 0, 'L');
    $pdf->Cell(60, 6, money($g['paid']), 0, 1, 'R');
    $pdf->SetX(15);
    $pdf->Cell(125, 6, '  Remaining balance', 0, 0, 'L');
    $pdf->Cell(60, 6, money($remain), 0, 1, 'R');

    $pdf->Ln(4);
    $pdf->SetX(15);
    $pdf->SetFont('Arial','I',8);
    $er_vac_show = !empty($vac_paid_mode) ? 0.0 : $g['er_vac'];
    $er_total = $g['er_cpp'] + $g['er_ei'] + $g['er_wcb'] + $er_vac_show;
    $pdf->MultiCell(185, 4, 'Employer contributions (not deducted from pay): CPP ' . money($g['er_cpp']) . ' | EI ' . money($g['er_ei']) . ' | WCB ' . money($g['er_wcb']) . ' | Vacation ' . money($er_vac_show) . '  =  ' . money($er_total), 0, 'L');
}

$pdf = new FPDF('P', 'mm', 'Letter');
$pdf->SetAutoPageBreak(false);
$chq_no = (int)(date('ymd') . '0');
$register_next = 0;
$mark_paid = false;
$issue_nonce = '';
if ($record_cheques) {
    $mark_paid = (($_POST['mark_paid'] ?? '') === '1');
    $issue_nonce = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_POST['issue_nonce'] ?? '')), 0, 64);
    $requestedStart = (int)($_POST['cheque_start'] ?? 0);
    if ($requestedStart < 1 || $requestedStart > 2000000000) {
        $requestedStart = pcr_next_number($pdo, $paydoc_org_id, $req_issue_date ?: date('Y-m-d'));
    }
    $register_next = $requestedStart;
}

foreach ($groups as $g) {
    $net    = max(0, $g['gross'] + ($vac_paid_mode ? (float)$g['vac_earn'] : 0) - $g['ee_cpp'] - $g['ee_ei'] - $g['ee_tax']);
    $remain = max(0, $net - $g['paid']);
    $is_eft = isset($eft_suppress[$g['user_id']]);
    if ($is_eft) {
        $g['pay_method'] = 'debit';
    }
    if ($record_cheques) {
        $cheque_amt = pcr_cheque_face_amount(
            (float)$g['gross'], (float)$g['ee_cpp'], (float)$g['ee_ei'], (float)$g['ee_tax'],
            (float)$g['paid'], $vac_paid_mode, (float)$g['vac_earn']
        );
        $issue_this = !$is_eft && $cheque_amt > 0.009;
        if ($issue_this) {
            $linePayload = [];
            foreach ($g['lines'] as $ln) {
                $linePayload[] = [
                    'finalized_id' => (int)$ln['line_id'],
                    'remaining' => pcr_line_remaining(
                        (float)$ln['total_gross'], (float)$ln['ee_cpp'], (float)$ln['ee_ei'],
                        (float)$ln['ee_tax'], (float)$ln['paid_amount']
                    ),
                ];
            }
            $issued = pcr_issue_cheque($pdo, [
                'org_id' => $paydoc_org_id,
                'user_id' => (int)$g['user_id'],
                'site_id' => (int)$g['site_id'],
                'cheque_number' => $register_next,
                'cheque_date' => $g['issue_date'],
                'amount' => $cheque_amt,
                'period_start' => $g['disp_start'],
                'period_end' => $g['disp_end'],
                'employee_name' => $g['emp_name'],
                'issue_nonce' => $issue_nonce,
                'created_by' => (int)($_SESSION['user_id'] ?? 0),
            ], $linePayload);
            $chq_no = (int)$issued['number'];
            $register_next = $chq_no + 1;
            if ($mark_paid && ($issued['status'] ?? '') !== 'void') {
                $paidNow = pcr_mark_cheque_paid($pdo, (int)$issued['id'], $paydoc_org_id, (int)($_SESSION['user_id'] ?? 0));
                $g['paid'] += (float)$paidNow['amount'];
                $remain = max(0, $net - $g['paid']);
            }
        } else {
            $chq_no = 0;
        }
    } else {
        $chq_no++;
        $cheque_amt = $g['paid'] > 0.01 ? $g['paid'] : $net;
        $issue_this = !$is_eft;
    }

    if ($issue_this) {
        $pdf->AddPage();

        if ($cheque_mode === 'preprinted') {
            $pdf->SetTextColor(0,0,0);
            $pdf->SetFont('Arial','B',11);
            $pdf->SetXY($PRE['date']['x'],  $PRE['date']['y']);   $pdf->Cell($PRE['date']['w'],  6, $g['issue_date'], 0, 0, 'C');
            $pdf->SetFont('Arial','B',12);
            $pdf->SetXY($PRE['payee']['x'], $PRE['payee']['y']);  $pdf->Cell($PRE['payee']['w'], 6, $g['emp_name'], 0, 0, 'L');
            $pdf->SetXY($PRE['amount']['x'],$PRE['amount']['y']); $pdf->Cell($PRE['amount']['w'],6, number_format($cheque_amt,2), 0, 0, 'R');
            $pdf->SetFont('Arial','B',10);
            $w = num_to_words_dollars($cheque_amt);
            $w .= ' ' . str_repeat('*', max(0, 80 - strlen($w)));
            $pdf->SetXY($PRE['words']['x'], $PRE['words']['y']);  $pdf->Cell($PRE['words']['w'], 6, $w, 0, 0, 'L');
            $pdf->SetFont('Arial','',9);
            $pdf->SetXY($PRE['memo']['x'],  $PRE['memo']['y']);   $pdf->Cell($PRE['memo']['w'],  6, 'Payroll ' . $g['disp_start'] . ' to ' . $g['disp_end'], 0, 0, 'L');

        } else {
            $L = 12; $R = 203; $W = $R - $L;
            $top = 16; $boxH = 88;

            $pdf->SetDrawColor(120,130,150);
            $pdf->SetLineWidth(0.5);
            $pdf->Rect($L, $top, $W, $boxH);
            $pdf->SetLineWidth(0.2);
            $pdf->Rect($L+1.2, $top+1.2, $W-2.4, $boxH-2.4);
            $pdf->SetLineWidth(0.2);

            $pdf->SetFillColor(238,242,248);
            $pdf->Rect($L+1.2, $top+1.2, $W-2.4, 20, 'F');

            $pdf->SetTextColor(20,30,55);
            $pdf->SetXY($L+6, $top+5);
            $pdf->SetFont('Arial','B',15);
            $chqEmployer = ($g['paystub_layout'] === 'bramalea') ? paydocs_employer_name($g) : $g['site_name'];
            $pdf->Cell(120, 7, strtoupper(substr($chqEmployer,0,38)), 0, 2, 'L');
            $pdf->SetFont('Arial','',8.5);
            $pdf->SetTextColor(70,80,100);
            if ($g['site_addr']) $pdf->Cell(120, 4.5, substr($g['site_addr'],0,64), 0, 2, 'L');
            $pdf->SetFont('Arial','I',8);
            $pdf->Cell(120, 4.5, 'Corporate Payroll Account', 0, 0, 'L');

            $pdf->SetTextColor(20,30,55);
            $pdf->SetFont('Arial','B',13);
            $pdf->SetXY($R-58, $top+5);
            $pdf->Cell(52, 6, 'No. ' . $chq_no, 0, 2, 'R');
            $pdf->SetFont('Arial','',7);
            $pdf->SetTextColor(90,100,120);
            $pdf->SetXY($R-58, $top+13);
            $pdf->Cell(52, 4, 'DATE', 0, 2, 'R');
            $pdf->SetDrawColor(120,130,150);
            $pdf->SetXY($R-40, $top+13.5);
            $pdf->SetFont('Arial','B',11);
            $pdf->SetTextColor(0,0,0);
            $pdf->Cell(34, 7, $g['issue_date'], 1, 0, 'C');

            $payY = $top + 32;
            $pdf->SetXY($L+6, $payY);
            $pdf->SetFont('Arial','B',8.5);
            $pdf->SetTextColor(90,100,120);
            $pdf->Cell(40, 6, 'PAY TO THE', 0, 2, 'L');
            $pdf->SetXY($L+6, $payY+4);
            $pdf->Cell(40, 6, 'ORDER OF', 0, 0, 'L');
            $pdf->SetTextColor(0,0,0);
            $pdf->SetFont('Arial','B',13);
            $pdf->SetXY($L+30, $payY);
            $pdf->Cell(120, 9, $g['emp_name'], 'B', 0, 'L');
            $pdf->SetFillColor(245,247,250);
            $pdf->SetXY($R-44, $payY);
            $pdf->SetFont('Arial','B',13);
            $pdf->Cell(38, 9, '$ ' . number_format($cheque_amt,2), 1, 0, 'R', true);

            $wordsY = $payY + 14;
            $pdf->SetXY($L+6, $wordsY);
            $pdf->SetFont('Arial','B',11);
            $w = num_to_words_dollars($cheque_amt);
            $w .= '  ' . str_repeat('-', max(0, 78 - strlen($w)));
            $pdf->Cell($W-12, 8, $w, 'B', 0, 'L');

            $sigY = $top + 66;
            $pdf->SetXY($L+6, $sigY+4);
            $pdf->SetFont('Arial','',8.5);
            $pdf->SetTextColor(70,80,100);
            $pdf->Cell(90, 5, 'MEMO  ', 0, 0, 'L');
            $pdf->SetTextColor(0,0,0);
            $pdf->Text($L+22, $sigY+7.5, 'Payroll ' . $g['disp_start'] . ' to ' . $g['disp_end']);
            $pdf->SetDrawColor(120,130,150);
            $pdf->Line($R-66, $sigY+6, $R-6, $sigY+6);
            $pdf->SetFont('Arial','',7.5);
            $pdf->SetTextColor(90,100,120);
            $pdf->SetXY($R-66, $sigY+6.5);
            $pdf->Cell(60, 4, 'AUTHORIZED SIGNATURE', 0, 0, 'C');

            $pdf->SetTextColor(0,0,0);
            $pdf->SetXY($L+10, $top+$boxH-9);
            $pdf->SetFont('Courier','',13);
            $micr = "C" . str_pad($chq_no,6,'0',STR_PAD_LEFT) . "C   "
                  . "A" . preg_replace('/\D/','',$g['transit'] ?: '00000') . "A "
                  . (preg_replace('/\D/','',$g['institution'] ?: '000')) . " "
                  . (preg_replace('/\D/','',$g['account'] ?: '0000000'));
            $pdf->Cell($W-20, 6, $micr, 0, 0, 'L');

            $pdf->SetTextColor(0,0,0);
        }
    }

    $pdf->AddPage();
    if ($g['paystub_layout'] === 'bramalea') {
        $ytd = paydocs_ytd($pdo, $g, $vac_paid_mode);
        paydocs_draw_bramalea($pdf, $g, $ytd, $vac_paid_mode, $chq_no);
    } else {
        paydocs_draw_legacy($pdf, $g, $vac_paid_mode, $remain);
    }
}

$fname = 'pay_documents_' . date('Ymd_His') . '.pdf';
if ($is_cli && $cli_out) {
    $pdf->Output('F', $cli_out);
    fwrite(STDERR, "wrote $cli_out pages=" . $pdf->PageNo() . " employees=" . count($groups) . "\n");
} else {
    $pdf->Output('I', $fname);
}
exit;
