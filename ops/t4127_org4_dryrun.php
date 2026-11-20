<?php
/**
 * Read-only AA Group (org 4) comparison. Prints 10 employees from the latest
 * finalized period. Does not insert, update, or delete.
 *
 *   PAYROLL_APP=/home/renocon/public_html/payroll php ops/t4127_org4_dryrun.php
 */
$app = getenv('PAYROLL_APP') ?: '/home/renocon/public_html/payroll';
$lib = getenv('T4127_LIB') ?: (__DIR__ . '/../payroll_engine/source_deductions_lib.php');
if (!is_file($lib)) {
    fwrite(STDERR, "T4127 library not found at $lib\n");
    exit(1);
}
require $lib;
if (!is_dir(dirname($lib) . '/rates')) {
    fwrite(STDERR, "Rate packs are not beside the library at " . dirname($lib) . "/rates\n");
    exit(1);
}
require $app . '/db.php';
if (!isset($pdo) || !$pdo instanceof PDO) {
    fwrite(STDERR, "db.php did not provide a connection\n");
    exit(1);
}

function dry_cols(PDO $pdo, string $table): array {
    $out = [];
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`') as $c) {
            $out[strtolower((string)$c['Field'])] = true;
        }
    } catch (Throwable $e) {
    }
    return $out;
}

function dry_infer(array $site): array {
    $blob = strtolower(trim(implode(' ', [
        $site['nick_name'] ?? '', $site['city'] ?? '', $site['state'] ?? '',
        $site['province'] ?? '', $site['street_address'] ?? '', $site['zipcode'] ?? '',
    ])));
    if (preg_match('/\bs\d[a-z]\s?\d[a-z]\d\b/', $blob)) {
        return ['SK', 'postal code'];
    }
    if (preg_match('/\b[klmnp]\d[a-z]\s?\d[a-z]\d\b/', $blob)) {
        return ['ON', 'postal code'];
    }
    $sk = ['saskatchewan', 'saskatoon', 'regina', 'moose jaw', 'prince albert', 'yorkton', 'swift current', 'north battleford', 'estevan', 'weyburn'];
    $on = ['ontario', 'bramalea', 'ajax', 'barrie', 'toronto', 'mississauga', 'brampton', 'vaughan', 'markham', 'richmond hill', 'oakville', 'burlington', 'hamilton', 'london', 'ottawa', 'kitchener', 'waterloo', 'guelph', 'oshawa', 'pickering', 'whitby', 'newmarket', 'aurora', 'milton', 'bradford', 'innisfil', 'orillia', 'kingston', 'windsor', 'sudbury', 'niagara', 'cambridge', 'belleville'];
    foreach ($sk as $name) {
        if (strpos($blob, $name) !== false) {
            return ['SK', $name];
        }
    }
    foreach ($on as $name) {
        if (strpos($blob, $name) !== false) {
            return ['ON', $name];
        }
    }
    return ['', 'unmapped'];
}

$f = dry_cols($pdo, 'payroll_finalized');
$s = dry_cols($pdo, 'sites');
$cpp2 = isset($f['cpp2']) ? 'COALESCE(SUM(cpp2),0)' : '0';
$endCol = isset($f['pay_period_end']) ? 'pay_period_end' : 'work_date';
$end = $pdo->query(
    "SELECT MAX(f.$endCol) FROM payroll_finalized f
      INNER JOIN users u ON u.id=f.user_id AND u.org_id=4
      WHERE f.$endCol IS NOT NULL AND f.$endCol > '2000-01-01'"
)->fetchColumn();
if (!$end) {
    echo "No finalized rows for org 4.\n";
    exit(0);
}
$city = isset($s['city']) ? 's.city' : "''";
$state = isset($s['state']) ? 's.state' : "''";
$prov = isset($s['province']) ? 's.province' : "''";
$street = isset($s['street_address']) ? 's.street_address' : "''";
$zip = isset($s['zipcode']) ? 's.zipcode' : "''";
$nick = isset($s['nick_name']) ? 's.nick_name' : "CONCAT('Site ', s.SId)";
$periodExpr = isset($f['pay_period_end']) ? "COALESCE(NULLIF(f.pay_period_end,'0000-00-00'), f.work_date)" : 'f.work_date';
$sql = "SELECT f.user_id, u.Name AS employee, f.site_id, $nick AS nick_name, $city AS city, $state AS state,
               $prov AS province, $street AS street_address, $zip AS zipcode,
               $periodExpr AS period_end,
               ROUND(SUM(f.total_gross),2) gross, ROUND(SUM(f.cpp),2) cpp, ROUND(SUM(f.ei),2) ei,
               ROUND(SUM(f.income_tax),2) tax, ROUND($cpp2,2) cpp2
          FROM payroll_finalized f
          INNER JOIN users u ON u.id=f.user_id AND u.org_id=4
          LEFT JOIN sites s ON s.SId=f.site_id
         GROUP BY f.user_id, f.site_id, period_end, employee, nick_name, city, state, province, street_address, zipcode
         ORDER BY period_end DESC, gross DESC
         LIMIT 80";
$st = $pdo->query($sql);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$skRows = [];
$shown = [];
foreach ($rows as $row) {
    [$inf] = dry_infer($row);
    $key = $row['user_id'] . '|' . $row['site_id'];
    if ($inf === 'SK') {
        $skRows[$key] = $row;
    }
    $shown[$key] = $row;
}
$picked = array_values($skRows);
foreach ($shown as $key => $row) {
    if (!isset($skRows[$key])) {
        $picked[] = $row;
    }
    if (count($picked) >= 25) {
        break;
    }
}
$rows = $picked;
echo "Org 4 dry run. Latest period marker $end. Read only.\n";
echo str_pad('employee', 22) . str_pad('site', 16) . str_pad('stored prov', 12) . str_pad('inferred', 10) . str_pad('gross', 10) . str_pad('old cpp', 10) . str_pad('old ei', 10) . str_pad('old tax', 10) . str_pad('new cpp', 10) . str_pad('new ei', 10) . "new tax\n";
foreach ($rows as $row) {
    [$inf, $why] = dry_infer($row);
    $new = ['cpp' => '', 'ei' => '', 'tax' => ''];
    if ($inf !== '') {
        $ytd = ['pensionable' => 0, 'cpp' => 0, 'cpp2' => 0, 'ei' => 0];
        try {
            $ys = $pdo->prepare('SELECT COALESCE(SUM(pensionable),0) p, COALESCE(SUM(cpp),0) c, COALESCE(SUM(cpp2),0) c2, COALESCE(SUM(ei),0) e FROM payroll_period_stat WHERE user_id=? AND site_id=? AND period_end < ?');
            $ys->execute([(int)$row['user_id'], (int)$row['site_id'], substr((string)$row['period_end'], 0, 10)]);
            if ($yr = $ys->fetch(PDO::FETCH_ASSOC)) {
                $ytd = ['pensionable' => (float)$yr['p'], 'cpp' => (float)$yr['c'], 'cpp2' => (float)$yr['c2'], 'ei' => (float)$yr['e']];
            }
        } catch (Throwable $e) {
        }
        $payDate = substr((string)($row['period_end'] ?: $end), 0, 10);
        $calc = sd_calculate([
            'pay_date' => $payDate,
            'province' => $inf,
            'periods' => 26,
            'periodic_gross' => (float)$row['gross'],
            'ytd_pensionable' => $ytd['pensionable'],
            'ytd_cpp' => $ytd['cpp'],
            'ytd_cpp2' => $ytd['cpp2'],
            'ytd_ei' => $ytd['ei'],
        ]);
        if (!empty($calc['ok'])) {
            $new = [
                'cpp' => number_format((float)$calc['cpp'] + (float)$calc['cpp2'], 2),
                'ei' => number_format((float)$calc['ei'], 2),
                'tax' => number_format((float)$calc['income_tax'], 2),
            ];
        } else {
            $new = ['cpp' => 'ERR', 'ei' => 'ERR', 'tax' => (string)($calc['error'] ?? 'no pack')];
        }
    }
    echo str_pad(substr((string)$row['employee'], 0, 20), 22)
        . str_pad(substr((string)$row['nick_name'], 0, 14), 16)
        . str_pad(substr((string)($row['province'] ?: '-'), 0, 10), 12)
        . str_pad($inf === '' ? 'UNMAPPED' : $inf, 10)
        . str_pad(number_format((float)$row['gross'], 2), 10)
        . str_pad(number_format((float)$row['cpp'] + (float)$row['cpp2'], 2), 10)
        . str_pad(number_format((float)$row['ei'], 2), 10)
        . str_pad(number_format((float)$row['tax'], 2), 10)
        . str_pad((string)$new['cpp'], 10)
        . str_pad((string)$new['ei'], 10)
        . (string)$new['tax']
        . "  ($why)\n";
}
echo "Inferred province comes from the site city, name, address, or postal code. Org 4 rows were not changed.\n";
echo "New figures use 26 biweekly pays and the T4127 pack for that inferred province.\n";
