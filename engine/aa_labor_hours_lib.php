<?php
/** AA Group Labor Hours Detail xlsx parser + map + import helpers.
 * Marker: aa-labor-hours-20261027-datadump
 * Live only. Do not copy this file into GitHub.
 */

require_once __DIR__ . '/attendance_audit_lib.php';

function aalh_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** AA Group organizations.id (never Popeyes / other orgs). */
function aalh_aa_org_id(PDO $pdo): int
{
    try {
        $id = $pdo->query("SELECT id FROM organizations WHERE org_name = 'AA Group' LIMIT 1")->fetchColumn();
        if ($id) {
            return (int)$id;
        }
    } catch (Throwable $e) {
    }
    return 4;
}

/**
 * Super, or AA Group admin/manager (user_level <= 30). Hourly staff (40) cannot open.
 * Other orgs cannot import here even if they are managers.
 */
function aalh_can_access(int $userLevel, int $sessionOrg, int $aaOrg): bool
{
    if ($userLevel <= 10) {
        return true;
    }
    return $aaOrg > 0 && $sessionOrg === $aaOrg && $userLevel <= 30;
}

function aalh_ensure_batch_tables(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $chk = $pdo->query("SHOW TABLES LIKE 'aa_labor_hours_batches'");
    if ($chk && $chk->fetch()) {
        $ready = true;
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS aa_labor_hours_batches (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL DEFAULT 0,
            site_id INT NOT NULL DEFAULT 0,
            site_name VARCHAR(80) NOT NULL DEFAULT '',
            file_name VARCHAR(255) NOT NULL DEFAULT '',
            imported_by INT NOT NULL DEFAULT 0,
            imported_at DATETIME NOT NULL,
            undone_at DATETIME NULL,
            undone_by INT NOT NULL DEFAULT 0,
            row_count INT NOT NULL DEFAULT 0,
            KEY org_id (org_id),
            KEY imported_at (imported_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS aa_labor_hours_batch_rows (
            batch_id INT NOT NULL,
            attendance_id INT NOT NULL,
            PRIMARY KEY (batch_id, attendance_id),
            KEY attendance_id (attendance_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $ready = true;
}

function aalh_col_index(string $ref): int
{
    if (!preg_match('/^([A-Z]+)/i', $ref, $m)) {
        return 0;
    }
    $s = strtoupper($m[1]);
    $n = 0;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $n = $n * 26 + (ord($s[$i]) - 64);
    }
    return $n;
}

function aalh_excel_serial_ymd(float $serial): string
{
    $days = (int)floor($serial);
    $unix = ($days - 25569) * 86400;
    return gmdate('Y-m-d', $unix);
}

function aalh_inline_text(SimpleXMLElement $c): string
{
    $texts = [];
    foreach ($c->xpath('.//*[local-name()="t"]') as $t) {
        $texts[] = (string)$t;
    }
    return trim(implode('', $texts));
}

/** @return list<array<int,string>> row => col => text */
function aalh_xlsx_sheet_entries(ZipArchive $zip): array
{
    $entries = [];
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $idToPath = [];
    if ($rels !== false) {
        $rx = @simplexml_load_string($rels);
        if ($rx) {
            foreach ($rx->xpath('//*[local-name()="Relationship"]') as $rel) {
                $id = (string)$rel['Id'];
                $type = (string)$rel['Type'];
                $target = str_replace('\\', '/', (string)$rel['Target']);
                if ($id === '' || $target === '' || stripos($type, 'worksheet') === false) {
                    continue;
                }
                $target = ltrim($target, '/');
                if (strpos($target, 'xl/') !== 0) {
                    $target = 'xl/' . $target;
                }
                $idToPath[$id] = $target;
            }
        }
    }
    if ($wb !== false) {
        $wx = @simplexml_load_string($wb);
        if ($wx) {
            foreach ($wx->xpath('//*[local-name()="sheet"]') as $sh) {
                $name = (string)$sh['name'];
                $rid = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $path = $idToPath[$rid] ?? '';
                if ($path !== '' && $zip->locateName($path) !== false) {
                    $entries[] = ['name' => $name, 'path' => $path];
                }
            }
        }
    }
    if (!$entries) {
        foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet.xml'] as $fallback) {
            if ($zip->locateName($fallback) !== false) {
                $entries[] = ['name' => 'Sheet1', 'path' => $fallback];
            }
        }
    }
    return $entries;
}

/** @param list<string> $shared */
function aalh_grid_from_sheet_xml(string $sheet, array $shared): array
{
    $wx = @simplexml_load_string($sheet);
    if (!$wx) {
        return [];
    }
    $grid = [];
    foreach ($wx->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
        $ridx = (int)$row['r'];
        if ($ridx <= 0) {
            continue;
        }
        foreach ($row->xpath('./*[local-name()="c"]') as $c) {
            $ref = (string)$c['r'];
            $col = aalh_col_index($ref);
            if ($col < 1 || $col > 12) {
                continue;
            }
            $t = (string)$c['t'];
            $val = '';
            if ($t === 'inlineStr' || $c->is) {
                $val = aalh_inline_text($c);
            } elseif ($t === 's') {
                $si = (int)trim((string)$c->v);
                $val = (string)($shared[$si] ?? '');
            } else {
                $raw = trim((string)$c->v);
                if ($raw !== '' && is_numeric($raw)) {
                    $num = (float)$raw;
                    if ($col === 1 && $num > 20000) {
                        $val = aalh_excel_serial_ymd($num);
                    } else {
                        $val = (string)$raw;
                    }
                } else {
                    $val = $raw;
                }
            }
            $grid[$ridx][$col] = $val;
        }
    }
    ksort($grid);
    return $grid;
}

/** @return list<array<int,string>> row => col => text */
function aalh_xlsx_grid(string $path): array
{
    if (!is_file($path) || !class_exists('ZipArchive')) {
        throw new RuntimeException('Cannot read xlsx (ZipArchive missing or file not found).');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open xlsx.');
    }
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $sx = @simplexml_load_string($ssXml);
        if ($sx) {
            foreach ($sx->xpath('//*[local-name()="si"]') as $si) {
                $shared[] = aalh_inline_text($si);
            }
        }
    }
    $entries = aalh_xlsx_sheet_entries($zip);
    if (!$entries) {
        $zip->close();
        throw new RuntimeException('xlsx has no worksheet.');
    }
    $best = [];
    $bestRows = -1;
    foreach ($entries as $ent) {
        $xml = $zip->getFromName($ent['path']);
        if ($xml === false) {
            continue;
        }
        $grid = aalh_grid_from_sheet_xml($xml, $shared);
        if (aalh_is_data_dump($grid)) {
            $zip->close();
            return $grid;
        }
        $n = count($grid);
        if ($n > $bestRows) {
            $best = $grid;
            $bestRows = $n;
        }
    }
    $zip->close();
    return $best;
}

function aalh_norm_hhmm(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $raw, $m)) {
        $h = (int)$m[1];
        $i = (int)$m[2];
        if ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59) {
            return sprintf('%02d:%02d', $h, $i);
        }
    }
    // Data dump times are HH.MM with minutes after the dot: 09.15 = 09:15, 04.05 = 04:05.
    if (preg_match('/^(\d{1,2})\.(\d{2})$/', $raw, $m)) {
        $h = (int)$m[1];
        $i = (int)$m[2];
        if ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59) {
            return sprintf('%02d:%02d', $h, $i);
        }
    }
    return null;
}

function aalh_is_data_dump(array $grid): bool
{
    $date = strtolower(trim((string)($grid[1][1] ?? '')));
    $loc = strtolower(trim((string)($grid[1][2] ?? '')));
    $emp = strtolower(trim((string)($grid[1][3] ?? '')));
    return $date === 'date' && $loc === 'loc id' && $emp === 'emp id';
}

function aalh_parse_dump_date(string $raw): string
{
    $raw = trim($raw);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return $raw;
    }
    if (preg_match('/^(\d{1,2})-([A-Za-z]{3})-(\d{4})$/', $raw, $dm)) {
        $ts = strtotime($dm[1] . ' ' . $dm[2] . ' ' . $dm[3]);
        return $ts ? date('Y-m-d', $ts) : '';
    }
    if (preg_match('/^(\d{1,2})-([A-Za-z]{3})-(\d{2})$/', $raw, $dm)) {
        $ts = strtotime($dm[1] . ' ' . $dm[2] . ' 20' . $dm[3]);
        return $ts ? date('Y-m-d', $ts) : '';
    }
    return '';
}

/**
 * Flat customer dump: Date, Loc ID, Emp ID, Wage Rate, Start Time, End Time, Breaks, Hours, Note.
 * No employee names and no site name. One Loc ID per file.
 * @return array{title:string,site_name:string,loc_id:string,period:string,employees:array,shifts:array,errors:array}
 */
function aalh_parse_data_dump(array $grid): array
{
    $employees = [];
    $empIndex = [];
    $shifts = [];
    $errors = [];
    $locs = [];
    $dates = [];
    foreach ($grid as $ridx => $cols) {
        if ($ridx === 1) {
            continue;
        }
        $dateRaw = trim((string)($cols[1] ?? ''));
        $loc = trim((string)($cols[2] ?? ''));
        $emp = trim((string)($cols[3] ?? ''));
        $rateRaw = trim((string)($cols[4] ?? ''));
        $startRaw = trim((string)($cols[5] ?? ''));
        $endRaw = trim((string)($cols[6] ?? ''));
        $breaksRaw = trim((string)($cols[7] ?? ''));
        $hoursRaw = trim((string)($cols[8] ?? ''));
        $note = trim((string)($cols[9] ?? ''));
        if ($dateRaw === '' && $emp === '') {
            continue;
        }
        $date = aalh_parse_dump_date($dateRaw);
        $start = aalh_norm_hhmm($startRaw);
        $end = aalh_norm_hhmm($endRaw);
        if ($date === '' || $start === null || $end === null || !preg_match('/^\d+$/', $emp)) {
            $errors[] = "Row $ridx: could not read date, Loc ID, Emp ID, start, or end.";
            continue;
        }
        $locs[$loc] = true;
        $dates[] = $date;
        if (!isset($empIndex[$emp])) {
            $employees[] = [
                'name' => '',
                'emp_no' => $emp,
                'row' => $ridx,
                'shifts' => 0,
            ];
            $empIndex[$emp] = count($employees) - 1;
        }
        $employees[$empIndex[$emp]]['shifts']++;
        $sheetRate = is_numeric($rateRaw) ? round((float)$rateRaw, 2) : null;
        $shifts[] = [
            'key' => $emp . '|' . $date . '|' . $start,
            'emp_no' => $emp,
            'name' => '',
            'date' => $date,
            'start' => $start,
            'end' => $end,
            'overnight' => ($end < $start),
            'breaks' => is_numeric($breaksRaw) ? (int)$breaksRaw : 0,
            'hours' => is_numeric($hoursRaw) ? round((float)$hoursRaw, 2) : null,
            'note' => $note,
            'sheet_rate' => $sheetRate,
            'xlsx_row' => $ridx,
        ];
    }
    $locId = '';
    if (count($locs) > 1) {
        $errors[] = 'File has more than one Loc ID (' . implode(', ', array_keys($locs)) . '). Import one location per file.';
    } elseif (count($locs) === 1) {
        $locId = (string)array_key_first($locs);
    }
    sort($dates);
    $period = $dates ? ($dates[0] . ' – ' . $dates[count($dates) - 1]) : '';
    return [
        'title' => 'Data Dump',
        'site_name' => '',
        'loc_id' => $locId,
        'period' => $period,
        'employees' => $employees,
        'shifts' => $shifts,
        'errors' => $errors,
    ];
}

/**
 * Parse Labor Hours Detail layout into employees + shifts.
 * @return array{title:string,site_name:string,period:string,employees:array,shifts:array,errors:array}
 */
function aalh_parse_labor_hours(string $path): array
{
    $grid = aalh_xlsx_grid($path);
    if (aalh_is_data_dump($grid)) {
        return aalh_parse_data_dump($grid);
    }
    $title = trim((string)($grid[1][1] ?? ''));
    $site = '';
    $period = '';
    foreach ($grid as $ridx => $cols) {
        $a = trim((string)($cols[1] ?? ''));
        if ($site === '' && $ridx > 1 && $a !== '' && $a !== 'Labor Hours Detail' && !preg_match('/\((\d+)\)/', $a) && stripos($a, 'total hours') === false && strcasecmp($a, 'date') !== 0 && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $a)) {
            if (!preg_match('/\d{1,2}-[A-Za-z]{3}-\d{2}/', $a)) {
                $site = $a;
                continue;
            }
        }
        if ($period === '' && preg_match('/\d{1,2}-[A-Za-z]{3}-\d{2}/', $a)) {
            $period = preg_replace('/\s+/', ' ', $a);
        }
        if ($site !== '' && $period !== '') {
            break;
        }
    }

    $employees = [];
    $shifts = [];
    $errors = [];
    $cur = null;
    $expectHeader = false;
    foreach ($grid as $ridx => $cols) {
        $a = trim((string)($cols[1] ?? ''));
        $c = trim((string)($cols[3] ?? ''));
        $d = trim((string)($cols[4] ?? ''));
        $e = trim((string)($cols[5] ?? ''));
        $f = trim((string)($cols[6] ?? ''));
        $g = trim((string)($cols[7] ?? ''));
        if ($a === '' && $c === '') {
            continue;
        }
        if (preg_match('/^(.+?)\s*\*\s*-\s*\((\d+)\)\s*$/', $a, $m) || preg_match('/^(.+?)\s*-\s*\((\d+)\)\s*$/', $a, $m)) {
            $cur = [
                'name' => trim($m[1]),
                'emp_no' => $m[2],
                'row' => $ridx,
                'shifts' => 0,
            ];
            $employees[] = $cur;
            $expectHeader = true;
            continue;
        }
        if (strcasecmp($a, 'Date') === 0) {
            $expectHeader = false;
            continue;
        }
        if (stripos($a, 'Total Hours') === 0) {
            $cur = null;
            continue;
        }
        if ($cur === null) {
            continue;
        }
        $date = $a;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            if (preg_match('/^(\d{1,2})-([A-Za-z]{3})-(\d{2})$/', $date, $dm)) {
                $ts = strtotime($dm[1] . ' ' . $dm[2] . ' 20' . $dm[3]);
                $date = $ts ? date('Y-m-d', $ts) : '';
            } else {
                $date = '';
            }
        }
        $start = aalh_norm_hhmm($c);
        $end = aalh_norm_hhmm($d);
        if ($date === '' || $start === null || $end === null) {
            if ($a !== '' || $c !== '') {
                $errors[] = "Row $ridx for {$cur['name']} (#{$cur['emp_no']}): could not read date/start/end.";
            }
            continue;
        }
        $overnight = ($end < $start);
        $hours = is_numeric($f) ? round((float)$f, 2) : null;
        $breaks = is_numeric($e) ? (int)$e : 0;
        $rec = [
            'key' => $cur['emp_no'] . '|' . $date . '|' . $start,
            'emp_no' => $cur['emp_no'],
            'name' => $cur['name'],
            'date' => $date,
            'start' => $start,
            'end' => $end,
            'overnight' => $overnight,
            'breaks' => $breaks,
            'hours' => $hours,
            'note' => $g,
            'xlsx_row' => $ridx,
        ];
        $shifts[] = $rec;
        $employees[count($employees) - 1]['shifts']++;
    }

    return [
        'title' => $title,
        'site_name' => $site,
        'period' => $period,
        'employees' => $employees,
        'shifts' => $shifts,
        'errors' => $errors,
    ];
}

function aalh_clock_span(string $date, string $start, string $end): array
{
    $in = $date . ' ' . $start . ':00';
    $outDate = $date;
    if ($end < $start) {
        $outDate = date('Y-m-d', strtotime($date . ' +1 day'));
    }
    $out = $outDate . ' ' . $end . ':00';
    return [$in, $out];
}

/** Map customer emp # to portal user. Never guess by name. */
function aalh_map_employee(PDO $pdo, int $orgId, string $empNo): ?array
{
    $empNo = trim($empNo);
    if ($empNo === '' || !preg_match('/^\d+$/', $empNo)) {
        return null;
    }
    $hits = [];
    $q = $pdo->prepare(
        "SELECT id, Name, user, hourly_rate, official_rate, primary_site_id, assigned_site_ids
         FROM users
         WHERE org_id = ?
           AND (
                user LIKE ?
                OR user = ?
                OR Name LIKE ?
                OR Remarks LIKE ?
                OR Remarks LIKE ?
           )"
    );
    $q->execute([
        $orgId,
        'emp' . $empNo . '@%',
        'emp' . $empNo,
        '%#' . $empNo . '%',
        '%emp #' . $empNo . '%',
        '%src_id=' . $empNo . '%',
    ]);
    foreach ($q as $r) {
        $hits[(int)$r['id']] = $r;
    }
    if (count($hits) !== 1) {
        return null;
    }
    return reset($hits) ?: null;
}

function aalh_sheet_display_name(string $name, string $empNo): string
{
    $name = trim(preg_replace('/\s+\*\s*$/', '', trim($name)));
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '') {
        $name = 'Employee';
    }
    if ($empNo !== '' && !preg_match('/#' . preg_quote($empNo, '/') . '\b/', $name)) {
        $name .= ' #' . $empNo;
    }
    if (strlen($name) > 50) {
        $name = substr($name, 0, 50);
    }
    return $name;
}

function aalh_unique_pin(PDO $pdo): string
{
    for ($i = 0; $i < 40; $i++) {
        $pin = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $st = $pdo->prepare('SELECT id FROM users WHERE pin = ? LIMIT 1');
        $st->execute([$pin]);
        if (!$st->fetchColumn()) {
            return $pin;
        }
    }
    return str_pad((string)random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
}

/**
 * Create an AA hourly at the sheet site. Never merge a different emp # / other site.
 * @return array user row
 */
function aalh_create_aa_hourly(PDO $pdo, int $orgId, int $siteId, string $siteName, string $empNo, string $sheetName, array $details = []): array
{
    $existing = aalh_map_employee($pdo, $orgId, $empNo);
    if ($existing) {
        return $existing;
    }
    $login = 'emp' . $empNo . '@aagroup.import.invalid';
    $dup = $pdo->prepare('SELECT id, Name, user, hourly_rate, official_rate, primary_site_id, assigned_site_ids FROM users WHERE org_id = ? AND user = ? LIMIT 1');
    $dup->execute([$orgId, $login]);
    $hit = $dup->fetch(PDO::FETCH_ASSOC);
    if ($hit) {
        return $hit;
    }
    $givenName = trim((string)($details['name'] ?? ''));
    $display = aalh_sheet_display_name($givenName !== '' ? $givenName : $sheetName, $empNo);
    $rate = round((float)($details['hourly_rate'] ?? 0), 2);
    if ($rate <= 0) {
        $rate = 17.60;
    }
    $official = round((float)($details['official_rate'] ?? 0), 2);
    if ($official <= 0) {
        $official = $rate;
    }
    $email = trim((string)($details['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = $login;
    }
    $pin = aalh_unique_pin($pdo);
    $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $remarks = 'Added from AA Group Labor Hours Detail; customer emp #' . $empNo . '; ' . $siteName;
    $ins = $pdo->prepare(
        "INSERT INTO users
            (user, password, pin, email, active, Name, Type, employee_type, user_role,
             primary_site_id, assigned_site_ids, hourly_rate, official_rate, vac_pay_percent,
             org_id, Remarks)
         VALUES (?, ?, ?, ?, 1, ?, 'Hourly', 'hourly', 40, ?, ?, ?, ?, 4.00, ?, ?)"
    );
    $ins->execute([
        $login,
        $hash,
        $pin,
        $email,
        $display,
        $siteId,
        (string)$siteId,
        $rate,
        $official,
        $orgId,
        $remarks,
    ]);
    $id = (int)$pdo->lastInsertId();
    $mobile = preg_replace('/\D+/', '', (string)($details['mobile'] ?? ''));
    if ($mobile !== '') {
        $pdo->prepare('UPDATE users SET Tel_Mobile = ? WHERE id = ?')->execute([$mobile, $id]);
    }
    $st = $pdo->prepare('SELECT id, Name, user, hourly_rate, official_rate, primary_site_id, assigned_site_ids FROM users WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Created employee #' . $empNo . ' but could not reload the user.');
    }
    return $row;
}

function aalh_ensure_profile_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $have = [];
    foreach ($pdo->query('SHOW COLUMNS FROM users') as $col) {
        $have[(string)$col['Field']] = true;
    }
    $add = [
        'date_of_birth' => 'DATE NULL',
        'gender' => 'VARCHAR(20) NULL',
        'job_position' => 'VARCHAR(80) NULL',
        'payment_method' => 'VARCHAR(20) NULL',
        'bank_name' => 'VARCHAR(80) NULL',
        'salary_amount' => 'DECIMAL(10,2) NULL',
    ];
    foreach ($add as $name => $def) {
        if (empty($have[$name])) {
            $pdo->exec('ALTER TABLE users ADD COLUMN `' . $name . '` ' . $def);
        }
    }
}

/**
 * @return array{error:string,salaried:bool,data:array<string,mixed>}
 */
function aalh_new_employee_input(array $post): array
{
    $payType = strtolower(trim((string)($post['pay_type'] ?? 'hourly')));
    $salaried = in_array($payType, ['salaried', 'salary', 'monthly'], true);
    $methods = [
        'cheque' => 'cheque',
        'check' => 'cheque',
        'cash' => 'cash',
        'etransfer' => 'etransfer',
        'e-transfer' => 'etransfer',
        'direct_deposit' => 'direct_deposit',
        'direct deposit' => 'direct_deposit',
    ];
    $method = $methods[strtolower(trim((string)($post['payment_method'] ?? '')))] ?? '';
    $sin = preg_replace('/\D+/', '', (string)($post['sin'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string)($post['phone'] ?? ''));
    $postal = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($post['postal_code'] ?? '')));
    $gender = strtolower(trim((string)($post['gender'] ?? '')));
    if (!in_array($gender, ['male', 'female', 'another'], true)) {
        $gender = '';
    }
    $provinces = ['BC', 'ON', 'SK', 'AB', 'MB', 'NB', 'NL', 'NS', 'PE', 'QC', 'NT', 'NU', 'YT'];
    $province = strtoupper(trim((string)($post['province'] ?? '')));
    $dob = trim((string)($post['dob'] ?? ''));
    $joining = trim((string)($post['joining_date'] ?? ''));
    $email = trim((string)($post['email'] ?? ''));
    $name = trim(preg_replace('/\s+/', ' ', (string)($post['name'] ?? '')));
    $empNo = preg_replace('/\D+/', '', (string)($post['emp_no'] ?? ''));
    $position = trim((string)($post['position'] ?? ''));
    $street = trim((string)($post['street_address'] ?? ''));
    $city = trim((string)($post['city'] ?? ''));
    $wage = round((float)($post['hourly_rate'] ?? 0), 2);
    $salary = round((float)($post['salary_amount'] ?? 0), 2);
    $bankName = trim((string)($post['bank_name'] ?? ''));
    $inst = preg_replace('/\D+/', '', (string)($post['bank_institution'] ?? ''));
    $transit = preg_replace('/\D+/', '', (string)($post['bank_transit'] ?? ''));
    $account = preg_replace('/\D+/', '', (string)($post['bank_account'] ?? ''));
    $err = '';
    if ($empNo === '') {
        $err = 'Employee number is required.';
    } elseif ($name === '') {
        $err = 'Name is required.';
    } elseif (strlen($sin) !== 9) {
        $err = 'SIN must be 9 digits.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
        $err = 'Date of birth is required.';
    } elseif ($gender === '') {
        $err = 'Gender is required.';
    } elseif (strlen($phone) < 10) {
        $err = 'Phone number is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Email address is required.';
    } elseif ($position === '') {
        $err = 'Position is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $joining)) {
        $err = 'Joining date is required.';
    } elseif (!$salaried && $wage <= 0) {
        $err = 'Wage rate is required for an hourly employee.';
    } elseif ($salaried && $salary <= 0) {
        $err = 'Salary each pay period is required for a salaried employee.';
    } elseif ($street === '' || $city === '' || !in_array($province, $provinces, true) || !preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $postal)) {
        $err = 'Home address needs a street, city, province, and postal code.';
    } elseif ($method === '') {
        $err = 'Payment method is required.';
    } elseif ($method === 'direct_deposit' && ($bankName === '' || strlen($inst) !== 3 || strlen($transit) !== 5 || strlen($account) < 7 || strlen($account) > 12)) {
        $err = 'Direct deposit needs the bank name, a 3-digit institution number, a 5-digit transit number, and the account number.';
    }
    $parts = $name === '' ? [] : preg_split('/\s+/', $name);
    $surname = $parts ? (string)array_pop($parts) : '';
    $given = trim(implode(' ', $parts));
    if ($given === '') {
        $given = $surname;
    }
    return [
        'error' => $err,
        'salaried' => $salaried,
        'data' => [
            'emp_no' => $empNo,
            'name' => $name,
            'given_name' => substr($given, 0, 100),
            'surname' => substr($surname, 0, 100),
            'sin' => $sin,
            'dob' => $dob,
            'gender' => $gender,
            'phone' => $phone,
            'email' => $email,
            'position' => substr($position, 0, 80),
            'joining' => $joining,
            'wage' => $salaried ? 0.0 : $wage,
            'salary' => $salaried ? $salary : null,
            'street' => substr($street, 0, 255),
            'city' => substr($city, 0, 100),
            'province' => $province,
            'postal' => $postal,
            'method' => $method,
            'bank_name' => $method === 'direct_deposit' ? substr($bankName, 0, 80) : null,
            'institution' => $method === 'direct_deposit' ? $inst : null,
            'transit' => $method === 'direct_deposit' ? $transit : null,
            'account' => $method === 'direct_deposit' ? $account : null,
        ],
    ];
}

function aalh_save_new_employee_profile(PDO $pdo, int $userId, array $data, bool $salaried): void
{
    aalh_ensure_profile_columns($pdo);
    $display = aalh_sheet_display_name((string)$data['name'], (string)$data['emp_no']);
    $pdo->prepare(
        'UPDATE users SET
            Name = ?, given_name = ?, surname = ?, sin = ?, date_of_birth = ?, gender = ?,
            Tel_Mobile = ?, email = ?, job_position = ?, StartDate = ?,
            employee_type = ?, hourly_rate = ?, official_rate = ?, salary_amount = ?,
            street_address = ?, city = ?, province = ?, postal_code = ?,
            payment_method = ?, bank_name = ?, bank_institution = ?, bank_transit = ?, bank_account = ?
         WHERE id = ?'
    )->execute([
        $display,
        $data['given_name'],
        $data['surname'],
        $data['sin'],
        $data['dob'],
        $data['gender'],
        $data['phone'],
        $data['email'],
        $data['position'],
        $data['joining'],
        $salaried ? 'monthly' : 'hourly',
        $data['wage'],
        $data['wage'],
        $data['salary'],
        $data['street'],
        $data['city'],
        $data['province'],
        $data['postal'],
        $data['method'],
        $data['bank_name'],
        $data['institution'],
        $data['transit'],
        $data['account'],
        $userId,
    ]);
}

function aalh_resolve_site(PDO $pdo, int $orgId, string $siteName): ?array
{
    $siteName = trim($siteName);
    if ($siteName === '') {
        return null;
    }
    $st = $pdo->prepare('SELECT SId, nick_name, org_id, timezone_id, Timezone FROM sites WHERE org_id = ? AND nick_name = ?');
    $st->execute([$orgId, $siteName]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Customer Loc ID. sites.store_number first, then the known AA loc that was not on the site row. */
function aalh_resolve_loc(PDO $pdo, int $orgId, string $locId): ?array
{
    $locId = trim($locId);
    if ($locId === '') {
        return null;
    }
    $st = $pdo->prepare('SELECT SId, nick_name, org_id, timezone_id, Timezone FROM sites WHERE org_id = ? AND store_number = ? LIMIT 1');
    $st->execute([$orgId, $locId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }
    $known = ['1038' => 'Bramalea'];
    if (isset($known[$locId])) {
        return aalh_resolve_site($pdo, $orgId, $known[$locId]);
    }
    return null;
}

function aalh_profile_compare_rate(array $user): float
{
    $hourly = (float)($user['hourly_rate'] ?? 0);
    if ($hourly > 0.009) {
        return $hourly;
    }
    return (float)($user['official_rate'] ?? 0);
}

function aalh_rate_flag_text(float $sheet, float $profile): string
{
    return 'Rate flag: file rate ' . number_format($sheet, 2) . ' differs from profile rate ' . number_format($profile, 2) . '.';
}

function aalh_existing_punch(PDO $pdo, int $userId, string $date, string $startHhmm): ?int
{
    $st = $pdo->prepare(
        "SELECT id FROM attendance
         WHERE user_id = ?
           AND DATE(clock_in) = ?
           AND DATE_FORMAT(clock_in, '%H:%i') = ?
         LIMIT 1"
    );
    $st->execute([$userId, $date, $startHhmm]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

function aalh_preview_rows(PDO $pdo, int $orgId, array $parsed): array
{
    $site = aalh_resolve_site($pdo, $orgId, (string)($parsed['site_name'] ?? ''));
    if (!$site && !empty($parsed['loc_id'])) {
        $site = aalh_resolve_loc($pdo, $orgId, (string)$parsed['loc_id']);
    }
    $siteId = $site ? (int)$site['SId'] : 0;
    $mapCache = [];
    $unmatched = [];
    $rows = [];
    foreach ($parsed['employees'] as $emp) {
        $no = (string)$emp['emp_no'];
        if (!array_key_exists($no, $mapCache)) {
            $mapCache[$no] = aalh_map_employee($pdo, $orgId, $no);
            if (!$mapCache[$no]) {
                $unmatched[] = [
                    'emp_no' => $no,
                    'name' => $emp['name'],
                    'shifts' => (int)$emp['shifts'],
                ];
            }
        }
    }
    foreach ($parsed['shifts'] as $sh) {
        $user = $mapCache[$sh['emp_no']] ?? null;
        [$clockIn, $clockOut] = aalh_clock_span($sh['date'], $sh['start'], $sh['end']);
        $dupId = null;
        if ($user) {
            $dupId = aalh_existing_punch($pdo, (int)$user['id'], $sh['date'], $sh['start']);
        }
        $rawHrs = null;
        if ($clockIn && $clockOut) {
            $rawHrs = round((strtotime($clockOut) - strtotime($clockIn)) / 3600, 2);
        }
        $profileRate = $user ? aalh_profile_compare_rate($user) : 0.0;
        $sheetRate = (isset($sh['sheet_rate']) && $sh['sheet_rate'] !== null && $sh['sheet_rate'] !== '') ? (float)$sh['sheet_rate'] : null;
        $useRate = $sheetRate !== null ? $sheetRate : $profileRate;
        $rateFlag = $user && $sheetRate !== null && abs($sheetRate - $profileRate) > 0.009;
        $rows[] = array_merge($sh, [
            'user_id' => $user ? (int)$user['id'] : 0,
            'mapped_name' => $user ? (string)$user['Name'] : '',
            'mapped_user' => $user ? (string)$user['user'] : '',
            'hourly_rate' => $useRate,
            'official_rate' => $useRate,
            'profile_rate' => $profileRate,
            'rate_flag' => $rateFlag,
            'rate_flag_text' => $rateFlag ? aalh_rate_flag_text((float)$sheetRate, $profileRate) : '',
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
            'raw_span_hours' => $rawHrs,
            'dup_id' => $dupId,
            'will_add' => !$user,
            'status' => !$user ? 'will_add' : ($dupId ? 'duplicate' : 'new'),
        ]);
    }
    return [
        'site_id' => $siteId,
        'site_name' => $site ? (string)$site['nick_name'] : (string)($parsed['site_name'] ?? ''),
        'site_row' => $site,
        'rows' => $rows,
        'unmatched' => $unmatched,
        'map_cache' => $mapCache,
    ];
}

function aalh_break_windows(string $clockIn, string $clockOut, int $n): array
{
    $out = [];
    $n = max(0, min(6, $n));
    if ($n < 1) {
        return $out;
    }
    $start = strtotime($clockIn);
    $end = strtotime($clockOut);
    if (!$start || !$end || $end <= $start) {
        return $out;
    }
    $span = $end - $start;
    for ($i = 0; $i < $n; $i++) {
        $offset = (int)round($span * (0.35 + $i * 0.2));
        $bs = $start + $offset;
        $be = $bs + 1800;
        if ($be >= $end) {
            $be = $end - 60;
            $bs = $be - 1800;
        }
        if ($bs <= $start) {
            $bs = $start + 60;
            $be = $bs + 1800;
        }
        if ($be > $start && $be < $end && $bs > $start) {
            $out[] = [date('Y-m-d H:i:s', $bs), date('Y-m-d H:i:s', $be)];
        }
    }
    return $out;
}

/**
 * Create missing AA hourly users, then insert mapped non-duplicate punches.
 * @return array{inserted:int,skipped_dup:int,ids:array,batch_id:int,created_users:int}
 */
function aalh_import_rows(PDO $pdo, int $orgId, int $siteId, array $rows, int $byUserId, string $fileName = '', string $siteName = ''): array
{
    $aaOrg = aalh_aa_org_id($pdo);
    if ($orgId !== $aaOrg) {
        throw new RuntimeException('This upload is locked to AA Group. It cannot write punches to another organization.');
    }
    $siteChk = $pdo->prepare('SELECT org_id, nick_name FROM sites WHERE SId = ?');
    $siteChk->execute([$siteId]);
    $siteRow = $siteChk->fetch(PDO::FETCH_ASSOC);
    $siteOrg = (int)($siteRow['org_id'] ?? 0);
    if ($siteId <= 0 || $siteOrg !== $aaOrg) {
        throw new RuntimeException('Site is not an AA Group restaurant.');
    }
    if ($siteName === '') {
        $siteName = (string)($siteRow['nick_name'] ?? '');
    }
    aalh_ensure_batch_tables($pdo);
    $bat = $pdo->prepare(
        'INSERT INTO aa_labor_hours_batches (org_id, site_id, site_name, file_name, imported_by, imported_at, row_count)
         VALUES (?, ?, ?, ?, ?, NOW(), 0)'
    );
    $bat->execute([$orgId, $siteId, $siteName, $fileName, $byUserId]);
    $batchId = (int)$pdo->lastInsertId();

    $createdUsers = 0;
    $createdByEmp = [];
    foreach ($rows as $r) {
        $st = (string)($r['status'] ?? '');
        $empNo = (string)($r['emp_no'] ?? '');
        if (($st === 'will_add' || $st === 'unmatched' || empty($r['user_id'])) && $empNo !== '' && !isset($createdByEmp[$empNo])) {
            $existed = aalh_map_employee($pdo, $orgId, $empNo);
            $sheetRate = (isset($r['sheet_rate']) && $r['sheet_rate'] !== null && $r['sheet_rate'] !== '') ? round((float)$r['sheet_rate'], 2) : 0.0;
            $u = aalh_create_aa_hourly($pdo, $orgId, $siteId, $siteName, $empNo, (string)($r['name'] ?? ''), [
                'hourly_rate' => $sheetRate,
                'official_rate' => $sheetRate,
            ]);
            $createdByEmp[$empNo] = $u;
            if (!$existed) {
                $createdUsers++;
                if ($sheetRate > 0 && function_exists('pe_record_user_rate')) {
                    pe_record_user_rate(
                        $pdo,
                        (int)$u['id'],
                        $sheetRate,
                        $sheetRate,
                        null,
                        (string)($r['date'] ?? date('Y-m-d')),
                        $byUserId,
                        'Added from the labor hours file.',
                        'labor_import'
                    );
                }
            }
        }
    }
    foreach ($rows as &$r) {
        $empNo = (string)($r['emp_no'] ?? '');
        if ($empNo !== '' && isset($createdByEmp[$empNo])) {
            $u = $createdByEmp[$empNo];
            $r['user_id'] = (int)$u['id'];
            $r['mapped_name'] = (string)$u['Name'];
            $r['mapped_user'] = (string)$u['user'];
            $sheetRate = (isset($r['sheet_rate']) && $r['sheet_rate'] !== null && $r['sheet_rate'] !== '') ? (float)$r['sheet_rate'] : null;
            if ($sheetRate !== null) {
                $r['hourly_rate'] = $sheetRate;
                $r['official_rate'] = $sheetRate;
                $prof = aalh_profile_compare_rate($u);
                $r['profile_rate'] = $prof;
                if (abs($sheetRate - $prof) > 0.009) {
                    $r['rate_flag'] = true;
                    $r['rate_flag_text'] = aalh_rate_flag_text($sheetRate, $prof);
                }
            } else {
                $r['hourly_rate'] = (float)$u['hourly_rate'];
                $r['official_rate'] = (float)$u['official_rate'];
            }
            if (($r['status'] ?? '') === 'will_add' || ($r['status'] ?? '') === 'unmatched') {
                $r['status'] = 'new';
            }
        }
    }
    unset($r);

    aalh_ensure_profile_columns($pdo);
    $salariedUsers = [];
    $salQ = $pdo->prepare('SELECT employee_type, COALESCE(salary_amount, 0) AS salary_amount FROM users WHERE id = ?');
    foreach ($rows as &$r) {
        $uid = (int)($r['user_id'] ?? 0);
        if ($uid <= 0) {
            continue;
        }
        if (!array_key_exists($uid, $salariedUsers)) {
            $salQ->execute([$uid]);
            $info = $salQ->fetch(PDO::FETCH_ASSOC) ?: [];
            $type = function_exists('pe_normalize_employee_type')
                ? pe_normalize_employee_type($info['employee_type'] ?? 'hourly')
                : 'hourly';
            $salariedUsers[$uid] = ($type === 'monthly' && (float)($info['salary_amount'] ?? 0) > 0);
        }
        if (!$salariedUsers[$uid]) {
            continue;
        }
        $r['hourly_rate'] = 0;
        $r['official_rate'] = 0;
        $r['rate_flag'] = false;
        $r['rate_flag_text'] = '';
        $r['salaried'] = true;
        $note = trim((string)($r['note'] ?? ''));
        $r['note'] = trim($note . ' Salaried. Hours are recorded and are not paid by the hour.');
    }
    unset($r);

    $ins = $pdo->prepare(
        "INSERT INTO attendance
            (org_id, site_id, user_id, status, clock_in, clock_out, approved_hours,
             hourly_rate, official_rate, payout_status, is_finalized, is_processed,
             transfer_status, Remarks, manager_notes, is_flagged, flag_reason)
         VALUES (?, ?, ?, '4', ?, ?, ?, ?, ?, 0, 0, 0, 'none', ?, ?, ?, ?)"
    );
    $brk = $pdo->prepare('INSERT INTO breaks (shift_id, break_start, break_end) VALUES (?, ?, ?)');
    $link = $pdo->prepare('INSERT INTO aa_labor_hours_batch_rows (batch_id, attendance_id) VALUES (?, ?)');
    $inserted = 0;
    $skipped = 0;
    $ids = [];
    $stamp = date('Y-m-d H:i');
    foreach ($rows as $r) {
        if (($r['status'] ?? '') !== 'new' || empty($r['user_id'])) {
            if (($r['status'] ?? '') === 'duplicate') {
                $skipped++;
            }
            continue;
        }
        $dup = aalh_existing_punch($pdo, (int)$r['user_id'], $r['date'], $r['start']);
        if ($dup) {
            $skipped++;
            continue;
        }
        $hours = $r['hours'] !== null ? (float)$r['hours'] : null;
        $note = trim((string)($r['note'] ?? ''));
        $flagText = !empty($r['rate_flag']) ? trim((string)($r['rate_flag_text'] ?? '')) : '';
        $remarks = 'AA Labor Hours import ' . $stamp
            . ' | hours=' . ($hours === null ? '' : number_format($hours, 2))
            . ' | punches ' . $r['start'] . '-' . $r['end']
            . ($r['overnight'] ? ' overnight+1' : '')
            . ' | breaks=' . (int)$r['breaks']
            . ' | src_id=' . $r['emp_no']
            . ' | rate=' . number_format((float)$r['hourly_rate'], 2)
            . ($flagText !== '' ? ' | ' . $flagText : '')
            . ($note !== '' ? ' | note=' . $note : '')
            . ' | by=' . $byUserId
            . ' | batch=' . $batchId;
        $ins->execute([
            $orgId,
            $siteId,
            (int)$r['user_id'],
            $r['clock_in'],
            $r['clock_out'],
            $hours,
            (float)$r['hourly_rate'],
            (float)$r['official_rate'],
            $remarks,
            'Imported from AA hours file; not finalized; batch=' . $batchId
                . ($flagText !== '' ? '; ' . $flagText : '')
                . ($note !== '' ? '; ' . $note : ''),
            $flagText !== '' ? 1 : 0,
            $flagText !== '' ? $flagText : null,
        ]);
        $aid = (int)$pdo->lastInsertId();
        aa_log($pdo, 'create', $aid, null, null, 'AA labor hours import');
        $ids[] = $aid;
        $link->execute([$batchId, $aid]);
        foreach (aalh_break_windows($r['clock_in'], $r['clock_out'], (int)$r['breaks']) as $w) {
            $brk->execute([$aid, $w[0], $w[1]]);
        }
        $inserted++;
    }
    $pdo->prepare('UPDATE aa_labor_hours_batches SET row_count = ? WHERE id = ?')->execute([$inserted, $batchId]);
    if ($inserted < 1) {
        $pdo->prepare('DELETE FROM aa_labor_hours_batches WHERE id = ?')->execute([$batchId]);
        $batchId = 0;
    }
    return ['inserted' => $inserted, 'skipped_dup' => $skipped, 'ids' => $ids, 'batch_id' => $batchId, 'created_users' => $createdUsers];
}

function aalh_list_batches(PDO $pdo, int $orgId, int $limit = 8): array
{
    aalh_ensure_batch_tables($pdo);
    $st = $pdo->prepare(
        'SELECT b.*, u.Name AS imported_by_name
         FROM aa_labor_hours_batches b
         LEFT JOIN users u ON u.id = b.imported_by
         WHERE b.org_id = ?
         ORDER BY b.id DESC
         LIMIT ' . (int)$limit
    );
    $st->execute([$orgId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $cnt = $pdo->prepare(
        'SELECT COUNT(*) FROM aa_labor_hours_batch_rows r
         JOIN attendance a ON a.id = r.attendance_id
         WHERE r.batch_id = ?'
    );
    $paid = $pdo->prepare(
        'SELECT COUNT(*) FROM aa_labor_hours_batch_rows r
         JOIN attendance a ON a.id = r.attendance_id
         WHERE r.batch_id = ? AND (a.payout_status = 1 OR a.is_finalized = 1)'
    );
    foreach ($rows as &$r) {
        $cnt->execute([(int)$r['id']]);
        $r['still_there'] = (int)$cnt->fetchColumn();
        $paid->execute([(int)$r['id']]);
        $r['paid_or_final'] = (int)$paid->fetchColumn();
        $r['can_undo'] = empty($r['undone_at']) && $r['still_there'] > 0;
    }
    unset($r);
    return $rows;
}

/**
 * Remove only punches from this import batch. Skip finalized/paid. Never touch staff-clocked rows.
 * @return array{deleted:int,skipped_paid:int,missing:int}
 */
function aalh_undo_batch(PDO $pdo, int $batchId, int $aaOrg, int $byUserId): array
{
    aalh_ensure_batch_tables($pdo);
    $st = $pdo->prepare('SELECT * FROM aa_labor_hours_batches WHERE id = ? AND org_id = ?');
    $st->execute([$batchId, $aaOrg]);
    $batch = $st->fetch(PDO::FETCH_ASSOC);
    if (!$batch) {
        throw new RuntimeException('Import batch not found for AA Group.');
    }
    if (!empty($batch['undone_at'])) {
        throw new RuntimeException('That import was already undone.');
    }
    $ids = $pdo->prepare('SELECT attendance_id FROM aa_labor_hours_batch_rows WHERE batch_id = ?');
    $ids->execute([$batchId]);
    $attIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
    $load = $pdo->prepare('SELECT id, org_id, payout_status, is_finalized, Remarks FROM attendance WHERE id = ?');
    $delBrk = $pdo->prepare('DELETE FROM breaks WHERE shift_id = ?');
    $delAtt = $pdo->prepare('DELETE FROM attendance WHERE id = ? AND org_id = ? AND payout_status = 0 AND COALESCE(is_finalized,0) = 0');
    $deleted = 0;
    $skippedPaid = 0;
    $missing = 0;
    foreach ($attIds as $aid) {
        $load->execute([$aid]);
        $row = $load->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $missing++;
            continue;
        }
        if ((int)$row['org_id'] !== $aaOrg) {
            continue;
        }
        $remarks = (string)$row['Remarks'];
        if (strpos($remarks, 'AA Labor Hours Detail import') === false || strpos($remarks, 'batch=' . $batchId) === false) {
            continue;
        }
        if ((int)$row['payout_status'] === 1 || (int)$row['is_finalized'] === 1) {
            $skippedPaid++;
            continue;
        }
        $aaBefore = aa_snapshot($pdo, (int)$aid);
        $delBrk->execute([$aid]);
        $delAtt->execute([$aid, $aaOrg]);
        if ($delAtt->rowCount() > 0) {
            aa_log($pdo, 'delete', (int)$aid, $aaBefore, null, 'AA labor hours undo');
        }
        if ($delAtt->rowCount() > 0) {
            $deleted++;
        }
    }
    $pdo->prepare('UPDATE aa_labor_hours_batches SET undone_at = NOW(), undone_by = ? WHERE id = ? AND org_id = ?')
        ->execute([$byUserId, $batchId, $aaOrg]);
    return ['deleted' => $deleted, 'skipped_paid' => $skippedPaid, 'missing' => $missing];
}

function aalh_ensure_menu(PDO $pdo): void
{
    $chk = $pdo->query("SELECT MenuID FROM menu_manager WHERE LinkURL = '/aa_labor_hours_import.php' LIMIT 1");
    if ($chk && $chk->fetch()) {
        return;
    }
    $pdo->exec(
        "INSERT INTO menu_manager (MenuTitle, ParentID, LinkURL, description, SortOrder, IsActive, UserRole, MenuIcon)
         VALUES ('AA Labor Hours', 12, '/aa_labor_hours_import.php',
                 'AA Group Labor Hours Detail upload (super menu; AA admin/manager use the link)', 6, 1, 10, 'fa-file-excel')"
    );
}
