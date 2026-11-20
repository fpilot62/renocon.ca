<?php
/**
 * Throwaway MariaDB/MySQL run of the live pay path.
 * Skips when no local server is reachable. Nothing here talks to production.
 *
 *   mysql -u payrolltest -ppayrolltest -e 'CREATE DATABASE IF NOT EXISTS payroll_t4127_throwaway'
 *   php tests/source_deductions_db_test.php
 */
$dsn = getenv('PAYROLL_TEST_DSN') ?: 'mysql:host=127.0.0.1;dbname=payroll_t4127_throwaway;charset=utf8mb4';
$user = getenv('PAYROLL_TEST_USER') ?: 'payrolltest';
$pass = getenv('PAYROLL_TEST_PASS') ?: 'payrolltest';

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    echo "skip  no local MySQL/MariaDB (" . $e->getMessage() . ")\n";
    exit(0);
}

require_once __DIR__ . '/../payroll_engine/payroll_engine.php';

$fail = 0;
function check(string $label, bool $cond): void {
    global $fail;
    if ($cond) {
        echo "ok  $label\n";
        return;
    }
    echo "FAIL $label\n";
    $fail++;
}

function column_count(PDO $pdo, string $table, string $column): int {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn();
}

$schema = <<<'SQL'
DROP TABLE IF EXISTS payroll_finalized;
DROP TABLE IF EXISTS payroll_period_stat;
DROP TABLE IF EXISTS payroll_deduction_openings;
DROP TABLE IF EXISTS payroll_rate_packs;
DROP TABLE IF EXISTS employee_company_service;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS sites;
DROP TABLE IF EXISTS organizations;
DROP TABLE IF EXISTS payroll_settings;
DROP TABLE IF EXISTS Gem_Settings;

CREATE TABLE organizations (
  id INT NOT NULL PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  withhold_income_tax TINYINT NOT NULL DEFAULT 1,
  vacation_payout_mode VARCHAR(32) NOT NULL DEFAULT 'accrue'
) ENGINE=InnoDB;

CREATE TABLE sites (
  SId INT NOT NULL PRIMARY KEY,
  org_id INT NOT NULL,
  nick_name VARCHAR(120) NOT NULL,
  province VARCHAR(32) NULL
) ENGINE=InnoDB;

CREATE TABLE users (
  id INT NOT NULL PRIMARY KEY,
  org_id INT NOT NULL,
  Name VARCHAR(120) NOT NULL,
  employee_type VARCHAR(32) NOT NULL DEFAULT 'hourly',
  ei_exempt TINYINT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE Gem_Settings (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  org_id INT NOT NULL,
  setting_key VARCHAR(80) NOT NULL,
  setting_value VARCHAR(80) NOT NULL,
  UNIQUE KEY uq_gem (org_id, setting_key)
) ENGINE=InnoDB;

CREATE TABLE payroll_settings (
  id INT NOT NULL PRIMARY KEY,
  cpp_rate DECIMAL(10,4) NOT NULL,
  ei_rate DECIMAL(10,4) NOT NULL,
  fed_tax_rate DECIMAL(10,4) NOT NULL,
  wcb_rate DECIMAL(10,4) NOT NULL,
  ei_employer_factor DECIMAL(10,4) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE payroll_finalized (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  site_id INT NOT NULL,
  work_date DATE NOT NULL,
  total_gross DECIMAL(12,2) NOT NULL DEFAULT 0,
  vac DECIMAL(12,2) NOT NULL DEFAULT 0,
  cpp DECIMAL(12,2) NOT NULL DEFAULT 0,
  cpp_er DECIMAL(12,2) NOT NULL DEFAULT 0,
  ei DECIMAL(12,2) NOT NULL DEFAULT 0,
  ei_er DECIMAL(12,2) NOT NULL DEFAULT 0,
  income_tax DECIMAL(12,2) NOT NULL DEFAULT 0,
  wcb DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE payroll_period_stat (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  org_id INT NOT NULL,
  site_id INT NOT NULL,
  user_id INT NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  pensionable DECIMAL(12,2) NOT NULL DEFAULT 0,
  cpp_exempt DECIMAL(12,2) NOT NULL DEFAULT 0,
  cpp DECIMAL(12,2) NOT NULL DEFAULT 0,
  cpp_er DECIMAL(12,2) NOT NULL DEFAULT 0,
  ei DECIMAL(12,2) NOT NULL DEFAULT 0,
  income_tax DECIMAL(12,2) NOT NULL DEFAULT 0,
  vac DECIMAL(12,2) NOT NULL DEFAULT 0,
  gross DECIMAL(12,2) NOT NULL DEFAULT 0,
  net DECIMAL(12,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_period (user_id, site_id, period_start, period_end)
) ENGINE=InnoDB;
SQL;

$pdo->exec('SET NAMES utf8mb4');
foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
    $pdo->exec($stmt);
}

function apply_migration(string $pass): void {
    $file = __DIR__ . '/../migrations/20261120_source_deductions.sql';
    $cmd = 'mysql --batch --user=' . escapeshellarg($GLOBALS['user'])
        . ' --database=payroll_t4127_throwaway < ' . escapeshellarg($file);
    $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $spec, $pipes, null, ['MYSQL_PWD' => $pass]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        throw new RuntimeException(trim($err . "\n" . $out) ?: 'migration failed');
    }
}

apply_migration($pass);
check('first migration adds payroll_finalized.cpp2', column_count($pdo, 'payroll_finalized', 'cpp2') === 1);
check('first migration adds payroll_period_stat.cpp2', column_count($pdo, 'payroll_period_stat', 'cpp2') === 1);
apply_migration($pass);
check('second migration does not duplicate payroll_finalized.cpp2', column_count($pdo, 'payroll_finalized', 'cpp2') === 1);
check('second migration does not duplicate payroll_period_stat.cpp2', column_count($pdo, 'payroll_period_stat', 'cpp2') === 1);
check('second migration does not duplicate sites.wsib_percent', column_count($pdo, 'sites', 'wsib_percent') === 1);
check('second migration does not duplicate users.td1_federal_total', column_count($pdo, 'users', 'td1_federal_total') === 1);

$pdo->exec("INSERT INTO organizations (id, name) VALUES (1, 'Throwaway Kitchens')");
$pdo->exec("INSERT INTO sites (SId, org_id, nick_name, province, wsib_percent) VALUES
    (1, 1, 'Vancouver', 'BC', 1.5500),
    (2, 1, 'Toronto', 'ON', 1.2000),
    (3, 1, 'Regina', 'SK', 1.1000)");
$pdo->exec("INSERT INTO users (id, org_id, Name) VALUES (10, 1, 'BC Cook'), (20, 1, 'ON Cook'), (30, 1, 'SK Cook')");
$pdo->exec("INSERT INTO payroll_settings (id, cpp_rate, ei_rate, fed_tax_rate, wcb_rate, ei_employer_factor)
            VALUES (1, 0.0595, 0.0163, 0.1500, 0.0200, 1.4000)");
$pdo->exec("INSERT INTO Gem_Settings (org_id, setting_key, setting_value) VALUES
    (1, 'pay_period_mode', 'semi_monthly'),
    (1, 'pay_periods_per_year', '24')");

function finalize_period(PDO $pdo, int $userId, int $siteId, string $day, float $gross, int $shifts): array {
    $each = round($gross / $shifts, 2);
    $vacRate = pe_vacation_rate($pdo, $userId, $siteId, $day);
    $ins = $pdo->prepare('INSERT INTO payroll_finalized (user_id, site_id, work_date, total_gross, vac) VALUES (?,?,?,?,?)');
    $left = $gross;
    for ($i = 0; $i < $shifts; $i++) {
        $piece = ($i === $shifts - 1) ? round($left, 2) : $each;
        $left = round($left - $piece, 2);
        $ins->execute([$userId, $siteId, $day, $piece, round($piece * $vacRate, 2)]);
    }
    $stat = pe_refresh_period_stat($pdo, $userId, $siteId, $day);
    if (!is_array($stat)) {
        throw new RuntimeException('refresh returned nothing for ' . $day);
    }
    return $stat;
}

$bcDays = [
    '2026-01-05', '2026-01-20', '2026-02-05', '2026-02-20',
    '2026-03-05', '2026-03-20', '2026-04-05', '2026-04-20',
    '2026-05-05', '2026-05-20', '2026-06-05', '2026-06-20',
    '2026-07-05', '2026-07-20', '2026-08-05', '2026-08-20',
];

echo "\nBC semi-monthly, two shifts in the first period, then one line of \$5,000\n";
echo str_pad('period end', 12) . str_pad('gross', 10) . str_pad('CPP', 10) . str_pad('CPP2', 10) . str_pad('EI', 10) . str_pad('tax', 10) . "net\n";

$bc = [];
$sumCpp = 0.0;
$sumCpp2 = 0.0;
$sumEi = 0.0;
foreach ($bcDays as $i => $day) {
    $stat = finalize_period($pdo, 10, 1, $day, 5000.00, $i === 0 ? 2 : 1);
    $bc[] = $stat;
    $sumCpp = round($sumCpp + $stat['cpp'], 2);
    $sumCpp2 = round($sumCpp2 + $stat['cpp2'], 2);
    $sumEi = round($sumEi + $stat['ei'], 2);
    $formula = round($stat['gross'] - $stat['cpp'] - $stat['cpp2'] - $stat['ei'] - $stat['income_tax'], 2);
    echo str_pad($stat['period_end'], 12)
        . str_pad(number_format($stat['gross'], 2), 10)
        . str_pad(number_format($stat['cpp'], 2), 10)
        . str_pad(number_format($stat['cpp2'], 2), 10)
        . str_pad(number_format($stat['ei'], 2), 10)
        . str_pad(number_format($stat['income_tax'], 2), 10)
        . number_format($stat['net'], 2) . "\n";
    check($stat['period_end'] . ' stub net = gross - CPP - CPP2 - EI - tax', abs($stat['net'] - $formula) < 0.001);
}

$first = $bc[0];
$lines = $pdo->query("SELECT cpp, cpp2, ei, income_tax, total_gross FROM payroll_finalized WHERE user_id=10 AND site_id=1 AND work_date='2026-01-05'")->fetchAll(PDO::FETCH_ASSOC);
$lineCpp = 0.0;
$lineCpp2 = 0.0;
foreach ($lines as $ln) {
    $lineCpp += (float)$ln['cpp'];
    $lineCpp2 += (float)$ln['cpp2'];
}
$twoExemptions = round(2 * (2500 - 145.83) * 0.0595, 2);
$oneExemption = round((5000 - 145.83) * 0.0595, 2);
echo "\nFirst period lines: " . count($lines) . ", line CPP sum " . number_format($lineCpp, 2) . ", line CPP2 sum " . number_format($lineCpp2, 2) . "\n";
echo "Period CPP " . number_format($first['cpp'], 2) . " (one exemption reference $oneExemption, two-exemption reference $twoExemptions)\n";
echo "CPP exempt stored " . number_format($first['cpp_exempt'], 2) . "\n";
echo "Year CPP $sumCpp  CPP2 $sumCpp2  EI $sumEi\n";
echo "Edition " . $first['edition'] . "\n";

check('two shifts were finalized in the first period', count($lines) === 2);
check('shift rows do not each take the basic exemption', abs($lineCpp) < 0.001);
check('period CPP uses one basic exemption', abs($first['cpp'] - $oneExemption) <= 0.05 && abs($first['cpp'] - $twoExemptions) > 0.50);
check('stored exemption is the semi-monthly basic exemption', abs($first['cpp_exempt'] - 145.83) < 0.001);
check('CPP2 on the lines adds up to the period CPP2', abs($lineCpp2 - $first['cpp2']) < 0.001);
check('CPP reaches the 2026 maximum', abs($sumCpp - 4230.45) <= 0.05);
check('EI reaches the 2026 maximum and stops', abs($sumEi - 1123.07) <= 0.05);
check('a later period charges no EI', abs($bc[count($bc) - 1]['ei']) < 0.001);
check('a later period charges no base CPP', abs($bc[count($bc) - 1]['cpp']) < 0.001);
check('CPP2 is charged after the YMPE and stays under the maximum', $sumCpp2 > 0 && $sumCpp2 <= 416.00);
check('CPP2 is not charged in the first period', abs($first['cpp2']) < 0.001);

$on = finalize_period($pdo, 20, 2, '2026-01-05', 5000.00, 1);
$sk = finalize_period($pdo, 30, 3, '2026-01-05', 5000.00, 1);
echo "\nSame org, one \$5,000 January period\n";
echo str_pad('province', 10) . str_pad('CPP', 10) . str_pad('EI', 10) . str_pad('tax', 10) . "net\n";
foreach ([['BC', $first], ['ON', $on], ['SK', $sk]] as [$name, $row]) {
    echo str_pad($name, 10)
        . str_pad(number_format($row['cpp'], 2), 10)
        . str_pad(number_format($row['ei'], 2), 10)
        . str_pad(number_format($row['income_tax'], 2), 10)
        . number_format($row['net'], 2) . "\n";
}
check('Ontario tax differs from British Columbia', abs($on['income_tax'] - $first['income_tax']) > 0.05);
check('Saskatchewan tax differs from British Columbia', abs($sk['income_tax'] - $first['income_tax']) > 0.05);
check('CPP and EI match across provinces for the same pay', abs($on['cpp'] - $first['cpp']) < 0.001 && abs($sk['ei'] - $first['ei']) < 0.001);

if ($fail > 0) {
    echo $fail . " failed\n";
    exit(1);
}
echo "all passed\n";
