<?php
/**
 * Site bank profile and Payments Canada Standard 005 (1464-byte) payroll file.
 * RBC Express, TD commercial banking, and BMO Online Banking for Business
 * accept this same credit file. The originator id and data centre come from
 * the bank's setup letter and are stored on the site.
 */

function dd_bank_catalog(): array
{
    return [
        'rbc' => ['label' => 'RBC Royal Bank', 'institution' => '003'],
        'td' => ['label' => 'TD Canada Trust', 'institution' => '004'],
        'bmo' => ['label' => 'BMO Bank of Montreal', 'institution' => '001'],
        'other' => ['label' => 'Other Canadian bank', 'institution' => ''],
    ];
}

function dd_ensure_site_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $have = [];
    foreach ($pdo->query('SHOW COLUMNS FROM sites') as $c) {
        $have[$c['Field']] = true;
    }
    $add = [
        'dd_bank' => 'VARCHAR(20) NULL',
        'dd_institution' => 'VARCHAR(3) NULL',
        'dd_transit' => 'VARCHAR(5) NULL',
        'dd_account' => 'VARCHAR(12) NULL',
        'dd_originator_id' => 'VARCHAR(10) NULL',
        'dd_originator_short' => 'VARCHAR(15) NULL',
        'dd_originator_long' => 'VARCHAR(30) NULL',
        'dd_data_centre' => 'VARCHAR(5) NULL',
        'dd_file_seq' => 'INT NOT NULL DEFAULT 1',
    ];
    foreach ($add as $col => $def) {
        if (empty($have[$col])) {
            $pdo->exec('ALTER TABLE sites ADD COLUMN `' . $col . '` ' . $def);
        }
    }
    $done = true;
}

function dd_cpa_text(string $value, int $len): string
{
    $value = strtoupper($value);
    $map = [
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C', 'Ñ' => 'N', "'" => ' ', '’' => ' ', '-' => ' ',
    ];
    $value = strtr($value, $map);
    $value = preg_replace('/[^A-Z0-9 =_$.&*,]/', ' ', $value) ?? '';
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    return str_pad(substr($value, 0, $len), $len, ' ');
}

function dd_digits(string $value, int $len, bool $leftZero = true): string
{
    $value = preg_replace('/\D+/', '', $value) ?? '';
    if (strlen($value) > $len) {
        $value = substr($value, -$len);
    }
    return str_pad($value, $len, '0', $leftZero ? STR_PAD_LEFT : STR_PAD_RIGHT);
}

function dd_julian(string $ymd): string
{
    $dt = DateTime::createFromFormat('!Y-m-d', $ymd);
    if (!$dt) {
        $dt = new DateTime('today');
    }
    return '0' . $dt->format('y') . str_pad((string)((int)$dt->format('z') + 1), 3, '0', STR_PAD_LEFT);
}

/** @return array{error:string,values:array<string,mixed>} */
function dd_clean_site_bank(array $post): array
{
    $catalog = dd_bank_catalog();
    $bank = strtolower(trim((string)($post['dd_bank'] ?? '')));
    if (!isset($catalog[$bank])) {
        $bank = '';
    }
    $inst = preg_replace('/\D+/', '', (string)($post['dd_institution'] ?? '')) ?? '';
    if ($inst === '' && $bank !== '' && $catalog[$bank]['institution'] !== '') {
        $inst = $catalog[$bank]['institution'];
    }
    if ($inst !== '' && strlen($inst) <= 3) {
        $inst = str_pad($inst, 3, '0', STR_PAD_LEFT);
    }
    if ($inst !== '' && strlen($inst) !== 3) {
        return ['error' => 'Institution number must be 3 digits. RBC is 003, TD is 004, BMO is 001.', 'values' => []];
    }
    $transit = preg_replace('/\D+/', '', (string)($post['dd_transit'] ?? '')) ?? '';
    if ($transit !== '' && strlen($transit) <= 5) {
        $transit = str_pad($transit, 5, '0', STR_PAD_LEFT);
    }
    if ($transit !== '' && strlen($transit) !== 5) {
        return ['error' => 'Transit number must be 5 digits.', 'values' => []];
    }
    $account = preg_replace('/\D+/', '', (string)($post['dd_account'] ?? '')) ?? '';
    if ($account !== '' && (strlen($account) < 7 || strlen($account) > 12)) {
        return ['error' => 'The site account number must be 7 to 12 digits.', 'values' => []];
    }
    $originator = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($post['dd_originator_id'] ?? '')) ?? '');
    if ($originator !== '' && (strlen($originator) < 1 || strlen($originator) > 10)) {
        return ['error' => 'Originator number is at most 10 letters or digits, as printed on the bank setup letter.', 'values' => []];
    }
    $centre = preg_replace('/\D+/', '', (string)($post['dd_data_centre'] ?? '')) ?? '';
    if ($centre !== '' && strlen($centre) !== 5) {
        return ['error' => 'Data centre must be the 5-digit code from the bank setup letter.', 'values' => []];
    }
    $seqRaw = trim((string)($post['dd_file_seq'] ?? ''));
    $seq = ($seqRaw === '') ? 1 : (int)$seqRaw;
    if ($seq < 1 || $seq > 9999) {
        return ['error' => 'Next file number must be from 1 to 9999. The bank rejects a repeated number.', 'values' => []];
    }
    $short = trim((string)($post['dd_originator_short'] ?? ''));
    $long = trim((string)($post['dd_originator_long'] ?? ''));
    $blank = static function (string $v): ?string {
        return $v === '' ? null : $v;
    };
    return [
        'error' => '',
        'values' => [
            'dd_bank' => $blank($bank),
            'dd_institution' => $blank($inst),
            'dd_transit' => $blank($transit),
            'dd_account' => $blank($account),
            'dd_originator_id' => $blank($originator),
            'dd_originator_short' => $blank(substr($short, 0, 15)),
            'dd_originator_long' => $blank(substr($long, 0, 30)),
            'dd_data_centre' => $blank($centre),
            'dd_file_seq' => $seq,
        ],
    ];
}

function dd_load_site_bank(PDO $pdo, int $siteId): ?array
{
    dd_ensure_site_columns($pdo);
    $st = $pdo->prepare('SELECT SId, nick_name, corp_legal_name, dd_bank, dd_institution, dd_transit, dd_account, dd_originator_id, dd_originator_short, dd_originator_long, dd_data_centre, dd_file_seq FROM sites WHERE SId = ?');
    $st->execute([$siteId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $catalog = dd_bank_catalog();
    $bank = strtolower(trim((string)($row['dd_bank'] ?? '')));
    if (!isset($catalog[$bank])) {
        $bank = '';
    }
    $inst = preg_replace('/\D+/', '', (string)($row['dd_institution'] ?? '')) ?? '';
    if ($inst !== '' && strlen($inst) <= 3) {
        $inst = str_pad($inst, 3, '0', STR_PAD_LEFT);
    }
    $transit = preg_replace('/\D+/', '', (string)($row['dd_transit'] ?? '')) ?? '';
    if ($transit !== '' && strlen($transit) <= 5) {
        $transit = str_pad($transit, 5, '0', STR_PAD_LEFT);
    }
    $originator = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($row['dd_originator_id'] ?? '')) ?? '');
    $legal = trim((string)($row['corp_legal_name'] ?? ''));
    $nick = trim((string)($row['nick_name'] ?? ''));
    $short = trim((string)($row['dd_originator_short'] ?? ''));
    $long = trim((string)($row['dd_originator_long'] ?? ''));
    if ($short === '') {
        $short = $nick !== '' ? $nick : $legal;
    }
    if ($long === '') {
        $long = $legal !== '' ? $legal : $nick;
    }
    return [
        'site_id' => (int)$row['SId'],
        'name' => $nick !== '' ? $nick : ('Site ' . (int)$row['SId']),
        'bank' => $bank,
        'bank_label' => $bank !== '' ? $catalog[$bank]['label'] : '',
        'institution' => $inst,
        'transit' => $transit,
        'account' => preg_replace('/\D+/', '', (string)($row['dd_account'] ?? '')) ?? '',
        'originator_id' => $originator,
        'short_name' => $short,
        'long_name' => $long,
        'data_centre' => preg_replace('/\D+/', '', (string)($row['dd_data_centre'] ?? '')) ?? '',
        'file_seq' => max(1, (int)($row['dd_file_seq'] ?? 1)),
    ];
}

function dd_profile_gaps(array $profile): array
{
    $gaps = [];
    if ($profile['bank'] === '') {
        $gaps[] = 'bank';
    }
    if (strlen($profile['institution']) !== 3) {
        $gaps[] = 'institution number';
    }
    if (strlen($profile['transit']) !== 5) {
        $gaps[] = 'transit number';
    }
    $acct = strlen($profile['account']);
    if ($acct < 7 || $acct > 12) {
        $gaps[] = 'account number';
    }
    if ($profile['originator_id'] === '' || strlen($profile['originator_id']) > 10) {
        $gaps[] = 'originator number';
    }
    if (strlen($profile['data_centre']) !== 5) {
        $gaps[] = 'data centre';
    }
    return $gaps;
}

function dd_originator_field(string $originator): string
{
    $originator = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $originator) ?? '');
    if ($originator !== '' && ctype_digit($originator)) {
        return str_pad(substr($originator, 0, 10), 10, '0', STR_PAD_LEFT);
    }
    return str_pad(substr($originator, 0, 10), 10, ' ');
}

/**
 * @param array<int,array{name:string,institution:string,transit:string,account:string,amount:string,reference:string}> $payments
 */
function dd_cpa005_build(array $profile, array $payments, string $dueDate, int $fileSeq): string
{
    $fileSeq = max(1, min(9999, $fileSeq));
    $creation = dd_digits((string)$fileSeq, 4);
    $originator = dd_originator_field((string)$profile['originator_id']);
    $control = $originator . $creation;
    $julianCreate = dd_julian(date('Y-m-d'));
    $julianDue = dd_julian($dueDate);
    $centre = dd_digits((string)$profile['data_centre'], 5);
    $returnInst = '0' . dd_digits((string)$profile['institution'], 3) . dd_digits((string)$profile['transit'], 5);
    $returnAcct = str_pad(substr(preg_replace('/\D+/', '', (string)$profile['account']) ?? '', 0, 12), 12, ' ');
    $short = dd_cpa_text((string)$profile['short_name'], 15);
    $long = dd_cpa_text((string)$profile['long_name'], 30);

    $header = 'A'
        . '000000001'
        . $originator
        . $creation
        . $julianCreate
        . $centre
        . str_repeat(' ', 20)
        . 'CAD'
        . str_repeat(' ', 1406);

    $segments = [];
    foreach ($payments as $pay) {
        $cents = (int)round(((float)$pay['amount']) * 100);
        if ($cents < 0) {
            $cents = 0;
        }
        $inst = '0' . dd_digits((string)$pay['institution'], 3) . dd_digits((string)$pay['transit'], 5);
        $acct = str_pad(substr(preg_replace('/\D+/', '', (string)$pay['account']) ?? '', 0, 12), 12, ' ');
        $segments[] = '200'
            . dd_digits((string)$cents, 10)
            . $julianDue
            . $inst
            . $acct
            . str_repeat('0', 25)
            . $short
            . dd_cpa_text((string)$pay['name'], 30)
            . $long
            . str_repeat(' ', 10)
            . dd_cpa_text((string)($pay['reference'] ?? ''), 19)
            . $returnInst
            . $returnAcct
            . str_repeat(' ', 39)
            . str_repeat('0', 11);
    }

    $records = [$header];
    $recordNo = 1;
    $chunks = $segments === [] ? [] : array_chunk($segments, 6);
    foreach ($chunks as $chunk) {
        $recordNo++;
        $body = '';
        for ($i = 0; $i < 6; $i++) {
            $body .= $chunk[$i] ?? str_repeat(' ', 240);
        }
        $records[] = 'C' . dd_digits((string)$recordNo, 9) . $control . $body;
    }
    $recordNo++;
    $totalCents = 0;
    foreach ($payments as $pay) {
        $totalCents += (int)round(((float)$pay['amount']) * 100);
    }
    if ($totalCents < 0) {
        $totalCents = 0;
    }
    $trailer = 'Z'
        . dd_digits((string)$recordNo, 9)
        . $control
        . str_repeat('0', 14)
        . str_repeat('0', 8)
        . dd_digits((string)$totalCents, 14)
        . dd_digits((string)count($payments), 8)
        . str_repeat(' ', 1396);
    $records[] = $trailer;

    $out = '';
    foreach ($records as $rec) {
        if (strlen($rec) !== 1464) {
            throw new RuntimeException('Direct deposit record is ' . strlen($rec) . ' characters.');
        }
        $out .= $rec . "\r\n";
    }
    return $out;
}

function dd_take_file_seq(PDO $pdo, int $siteId, int $expected): int
{
    $next = $expected >= 9999 ? 1 : $expected + 1;
    $st = $pdo->prepare('UPDATE sites SET dd_file_seq = ? WHERE SId = ? AND dd_file_seq = ?');
    $st->execute([$next, $siteId, $expected]);
    if ($st->rowCount() === 1) {
        return $expected;
    }
    $cur = $pdo->prepare('SELECT dd_file_seq FROM sites WHERE SId = ?');
    $cur->execute([$siteId]);
    $fresh = max(1, (int)$cur->fetchColumn());
    $next = $fresh >= 9999 ? 1 : $fresh + 1;
    $st->execute([$next, $siteId, $fresh]);
    return $fresh;
}

/** @return list<string> */
function dd_cpa005_self_check(): array
{
    $errs = [];
    $profile = [
        'originator_id' => '12345',
        'data_centre' => '00310',
        'institution' => '003',
        'transit' => '00011',
        'account' => '987654321',
        'short_name' => 'ACME',
        'long_name' => "O'Brien Foods",
    ];
    $pays = [
        ['name' => 'Ava Singh', 'institution' => '004', 'transit' => '12345', 'account' => '1234567', 'amount' => '100.00', 'reference' => 'E10'],
        ['name' => 'Ben Roy', 'institution' => '001', 'transit' => '00999', 'account' => '7654321', 'amount' => '50.50', 'reference' => 'E11'],
    ];
    $file = dd_cpa005_build($profile, $pays, '2026-11-15', 7);
    $lines = preg_split("/\r\n/", rtrim($file, "\r\n")) ?: [];
    if (count($lines) !== 3) {
        $errs[] = 'expected 3 records, got ' . count($lines);
    }
    foreach ($lines as $i => $line) {
        if (strlen($line) !== 1464) {
            $errs[] = 'record ' . $i . ' length ' . strlen($line);
        }
    }
    if (($lines[0][0] ?? '') !== 'A' || ($lines[1][0] ?? '') !== 'C' || ($lines[2][0] ?? '') !== 'Z') {
        $errs[] = 'record types ' . ($lines[0][0] ?? '') . ($lines[1][0] ?? '') . ($lines[2][0] ?? '');
    }
    if (substr($lines[0] ?? '', 10, 10) !== '0000012345' || substr($lines[0] ?? '', 20, 4) !== '0007') {
        $errs[] = 'header originator/file number mismatch';
    }
    if (substr($lines[0] ?? '', 30, 5) !== '00310' || substr($lines[0] ?? '', 55, 3) !== 'CAD') {
        $errs[] = 'header data centre or currency mismatch';
    }
    if (dd_julian('2026-11-15') !== '026319' || dd_julian('2026-01-01') !== '026001') {
        $errs[] = 'julian mismatch ' . dd_julian('2026-11-15');
    }
    $seg = substr($lines[1] ?? '', 24, 240);
    if (substr($seg, 0, 3) !== '200' || substr($seg, 3, 10) !== '0000010000') {
        $errs[] = 'first segment type/amount mismatch';
    }
    if (substr($seg, 19, 9) !== '000412345' || substr($seg, 28, 12) !== '1234567     ') {
        $errs[] = 'payee routing mismatch ' . substr($seg, 19, 21);
    }
    if (substr($seg, 80, 9) !== 'AVA SINGH') {
        $errs[] = 'payee name mismatch';
    }
    if (strpos($seg, 'OBRIEN FOODS') === false && strpos($seg, 'O BRIEN FOODS') === false) {
        $errs[] = 'long name was not sanitized';
    }
    if (trim(substr($lines[1] ?? '', 24 + (240 * 2))) !== '') {
        $errs[] = 'unused segments were not blank';
    }
    if (substr($lines[2] ?? '', 46, 14) !== '00000000015050' || substr($lines[2] ?? '', 60, 8) !== '00000002') {
        $errs[] = 'trailer totals mismatch';
    }
    $many = [];
    for ($n = 0; $n < 7; $n++) {
        $many[] = ['name' => 'P' . $n, 'institution' => '003', 'transit' => '11111', 'account' => '1234567', 'amount' => '1.00', 'reference' => 'E' . $n];
    }
    $wide = dd_cpa005_build($profile, $many, '2026-11-15', 1);
    $wideLines = preg_split("/\r\n/", rtrim($wide, "\r\n")) ?: [];
    if (count($wideLines) !== 4 || ($wideLines[3][0] ?? '') !== 'Z' || substr($wideLines[3], 1, 9) !== '000000004') {
        $errs[] = 'seven payments should be A, two C records, then Z as record 4';
    }
    if (substr($wideLines[3] ?? '', 46, 14) !== '00000000000700' || substr($wideLines[3] ?? '', 60, 8) !== '00000007') {
        $errs[] = 'seven-payment trailer mismatch';
    }
    return $errs;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && @realpath($argv[0]) === @realpath(__FILE__)) {
    $errs = dd_cpa005_self_check();
    if ($errs) {
        fwrite(STDERR, implode("\n", $errs) . "\n");
        exit(1);
    }
    echo "cpa005 ok\n";
}
