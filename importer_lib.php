<?php
/**
 * Path: /importer_lib.php
 * Shared CSV import logic for bulk_importer.php (and CLI testing).
 * Consumes the published templates:
 *   employee_import_template.csv (CUSTOMER / public download):
 *     FullName,Email,Role,Tel_Mobile,SIN,StreetAddress,City,Province,PostalCode,
 *     StartDate,EndDate,HourlyRate,TransitRate,VacPayPercent,
 *     BankTransit,BankInstitution,BankAccount,PrimarySiteName,OtherSiteNames,
 *     EIExempt,WSIBExempt,VacPayExempt,DentalOffered,PayType
 *     — Role optional (name or RoleID); blank = staff (40). Org from session.
 *   employee_import_template_admin.csv (staff only):
 *     also Username,TemporaryPassword,PIN,Role,RoleID,Active,OrgID,PrimarySiteID,AssignedSiteIDs
 *   sites_import_template.csv (CUSTOMER):
 *     SiteName,BusinessLegalName,BN15,StreetAddress,City,Province,Timezone,
 *     PostalCode,WSIBNumber,WSIBPercent,Active,Notes
 *     — Timezone strongly recommended (IANA e.g. America/Toronto); Province
 *       used as fallback (ON→America/Toronto). Persists sites.timezone_id + Timezone.
 *   hours_import_template.csv (CUSTOMER):
 *     WorkDate,EmployeeEmail,EmployeeFullName,SiteName,
 *     ClockIn,ClockOut,RegularHours,OTHours,WageRate,Notes
 *     — provide either ClockIn+ClockOut OR RegularHours (not both required).
 *     — WorkDate/ClockIn/ClockOut interpreted in the site's timezone.
 *   hours_import_template_admin.csv:
 *     same + SiteID,OrgID for staff bulk ops
 *
 * Customer templates omit OrgID / credentials — importer defaults from session.
 * WSIB Number is NOT on the employee sheet (use Sites template).
 *
 * Both importers are all-or-nothing. Hours → attendance status '4'.
 * Vacation: StartDate drives tenure; VacPayPercent is an optional override.
 * Exemptions: EIExempt / WSIBExempt / VacPayExempt default No (not exempt).
 */

/** Canonical template header lines (for clipboard copy / docs). Public = customer. */
function pgcsv_template_header(string $mode): string {
    if ($mode === 'hours') {
        return 'WorkDate,EmployeeEmail,EmployeeFullName,SiteName,ClockIn,ClockOut,RegularHours,OTHours,WageRate,Notes';
    }
    if ($mode === 'hours_admin') {
        return 'WorkDate,EmployeeEmail,EmployeeFullName,SiteName,SiteID,OrgID,ClockIn,ClockOut,RegularHours,OTHours,WageRate,Notes';
    }
    if ($mode === 'sites') {
        return 'SiteName,BusinessLegalName,BN15,StreetAddress,City,Province,Timezone,PostalCode,WSIBNumber,WSIBPercent,Active,Notes';
    }
    if ($mode === 'employees_admin') {
        return 'EmployeeName,Email,Username,TemporaryPassword,PIN,Role,RoleID,HourlyRate,OfficialRate,StartDate,Active,OrgID,PrimarySiteID,AssignedSiteIDs,Tel_Mobile,SIN,StreetAddress,City,Province,PostalCode,TransitRate,VacPayPercent,BankTransit,BankInstitution,BankAccount,EIExempt,WSIBExempt,VacPayExempt,DentalOffered,PayType,Remarks';
    }
    return 'FullName,Email,Role,Tel_Mobile,SIN,StreetAddress,City,Province,PostalCode,StartDate,EndDate,HourlyRate,TransitRate,VacPayPercent,BankTransit,BankInstitution,BankAccount,PrimarySiteName,OtherSiteNames,EIExempt,WSIBExempt,VacPayExempt,DentalOffered,PayType';
}

/** Normalize a header label: case-insensitive, strip spaces/underscores/punctuation. */
function pgcsv_norm_key(string $h): string {
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
    return strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$h));
}

/**
 * Target fields for Match columns UI.
 * Each item: label (display), key (normalized), required (bool).
 * Hours: Email OR EmployeeName is enough at mapping time (both columns still emitted).
 */
function pgcsv_target_fields(string $mode): array {
    if ($mode === 'hours' || $mode === 'hours_admin') {
        return [
            ['label' => 'WorkDate',              'key' => 'date',         'required' => true],
            ['label' => 'EmployeeEmail',         'key' => 'email',        'required' => true],
            ['label' => 'EmployeeFullName',      'key' => 'employeename', 'required' => false],
            ['label' => 'SiteName',              'key' => 'sitename',     'required' => false],
            ['label' => 'ClockIn',               'key' => 'clockintime',  'required' => false],
            ['label' => 'ClockOut',              'key' => 'clockouttime', 'required' => false],
            ['label' => 'RegularHours',          'key' => 'hours',        'required' => false],
            ['label' => 'OTHours',               'key' => 'othours',      'required' => false],
            ['label' => 'WageRate',              'key' => 'wagerate',     'required' => false],
            ['label' => 'Notes',                 'key' => 'remarks',      'required' => false],
            ['label' => 'SiteID (admin)',        'key' => 'siteid',       'required' => false],
            ['label' => 'OrgID (admin)',         'key' => 'orgid',        'required' => false],
        ];
    }
    if ($mode === 'sites') {
        return [
            ['label' => 'SiteName',          'key' => 'sitename',          'required' => true],
            ['label' => 'BusinessLegalName', 'key' => 'businesslegalname', 'required' => false],
            ['label' => 'BN15',              'key' => 'bn15',              'required' => false],
            ['label' => 'StreetAddress',     'key' => 'streetaddress',     'required' => false],
            ['label' => 'City',              'key' => 'city',              'required' => false],
            ['label' => 'Province',          'key' => 'province',          'required' => false],
            // Strongly recommended; Province used as fallback when blank (see pgcsv_resolve_timezone).
            ['label' => 'Timezone',          'key' => 'timezone',          'required' => false],
            ['label' => 'PostalCode',        'key' => 'postalcode',        'required' => false],
            ['label' => 'WSIBNumber',        'key' => 'wsibnumber',        'required' => false],
            ['label' => 'WSIBPercent',       'key' => 'wsibpercent',       'required' => false],
            ['label' => 'Active',            'key' => 'active',            'required' => false],
            ['label' => 'Notes',             'key' => 'notes',             'required' => false],
        ];
    }
    // Customer-facing required fields. Credentials / Role / Org / Active are optional
    // (auto-filled on import). Site may be PrimarySiteName and/or PrimarySiteID.
    return [
        ['label' => 'FullName',          'key' => 'employeename',      'required' => true],
        ['label' => 'Email',             'key' => 'email',             'required' => true],
        ['label' => 'Role',              'key' => 'role',              'required' => false],
        ['label' => 'Tel_Mobile',        'key' => 'telmobile',         'required' => false],
        ['label' => 'SIN',               'key' => 'sin',               'required' => false],
        ['label' => 'StreetAddress',     'key' => 'streetaddress',     'required' => false],
        ['label' => 'City',              'key' => 'city',              'required' => false],
        ['label' => 'Province',          'key' => 'province',          'required' => false],
        ['label' => 'PostalCode',        'key' => 'postalcode',        'required' => false],
        ['label' => 'StartDate',         'key' => 'startdate',         'required' => true],
        ['label' => 'EndDate',           'key' => 'enddate',           'required' => false],
        ['label' => 'HourlyRate',        'key' => 'hourlyrate',        'required' => true],
        ['label' => 'TransitRate',       'key' => 'transitrate',       'required' => false],
        ['label' => 'VacPayPercent',     'key' => 'vacpaypercent',     'required' => false],
        ['label' => 'BankTransit',       'key' => 'banktransit',       'required' => false],
        ['label' => 'BankInstitution',   'key' => 'bankinstitution',   'required' => false],
        ['label' => 'BankAccount',       'key' => 'bankaccount',       'required' => false],
        ['label' => 'PrimarySiteName',   'key' => 'primarysitename',   'required' => false],
        ['label' => 'OtherSiteNames',    'key' => 'othersitenames',    'required' => false],
        ['label' => 'EIExempt',          'key' => 'eiexempt',          'required' => false],
        ['label' => 'WSIBExempt',        'key' => 'wsibexempt',        'required' => false],
        ['label' => 'VacPayExempt',      'key' => 'vacpayexempt',      'required' => false],
        ['label' => 'DentalOffered',     'key' => 'dentaloffered',     'required' => false],
        ['label' => 'PayType',           'key' => 'paytype',           'required' => false],
        // Admin / legacy (optional — auto-defaulted when blank)
        ['label' => 'Username',          'key' => 'username',          'required' => false],
        ['label' => 'TemporaryPassword', 'key' => 'temporarypassword', 'required' => false],
        ['label' => 'PIN',               'key' => 'pin',               'required' => false],
        ['label' => 'RoleID',            'key' => 'roleid',            'required' => false],
        ['label' => 'OfficialRate',      'key' => 'officialrate',      'required' => false],
        ['label' => 'Active',            'key' => 'active',            'required' => false],
        ['label' => 'OrgID',             'key' => 'orgid',             'required' => false],
        ['label' => 'PrimarySiteID',     'key' => 'primarysiteid',     'required' => false],
        ['label' => 'AssignedSiteIDs',   'key' => 'assignedsiteids',   'required' => false],
        ['label' => 'Remarks',           'key' => 'remarks',           'required' => false],
    ];
}

/** Alias (normalized) => target key. Mode-specific for Site vs PrimarySiteID. */
function pgcsv_header_aliases(string $mode): array {
    $common = [
        'name' => 'employeename', 'employee' => 'employeename', 'employeename' => 'employeename',
        'fullname' => 'employeename', 'fullname' => 'employeename', 'empname' => 'employeename',
        'worker' => 'employeename', 'staffname' => 'employeename',
        'email' => 'email', 'emailaddress' => 'email', 'mail' => 'email',
        'username' => 'username', 'user' => 'username', 'login' => 'username', 'userid' => 'username',
        'temporarypassword' => 'temporarypassword', 'password' => 'temporarypassword',
        'temppassword' => 'temporarypassword', 'temppass' => 'temporarypassword',
        'pin' => 'pin', 'kioskpin' => 'pin',
        'role' => 'role', 'rolename' => 'role', 'userrole' => 'role', 'user_role' => 'role',
        'roleid' => 'roleid',
        'hourlyrate' => 'hourlyrate', 'rate' => 'hourlyrate', 'hourly' => 'hourlyrate',
        'payrate' => 'hourlyrate', 'wage' => 'hourlyrate',
        'officialrate' => 'officialrate', 'official' => 'officialrate',
        'startdate' => 'startdate', 'hiredate' => 'startdate', 'hired' => 'startdate',
        'startdt' => 'startdate', 'commencement' => 'startdate', 'hire' => 'startdate',
        'enddate' => 'enddate', 'terminationdate' => 'enddate', 'termdate' => 'enddate',
        'active' => 'active', 'status' => 'active', 'enabled' => 'active', 'isactive' => 'active',
        'orgid' => 'orgid', 'org' => 'orgid', 'organization' => 'orgid', 'organisation' => 'orgid',
        'remarks' => 'remarks', 'notes' => 'remarks', 'note' => 'remarks',
        'comment' => 'remarks', 'comments' => 'remarks',
        'date' => 'date', 'workdate' => 'date', 'shiftdate' => 'date', 'day' => 'date',
        'hours' => 'hours', 'hrs' => 'hours', 'hour' => 'hours', 'duration' => 'hours',
        'clockintime' => 'clockintime', 'clockin' => 'clockintime', 'starttime' => 'clockintime',
        'timein' => 'clockintime', 'punchin' => 'clockintime',
        'telmobile' => 'telmobile', 'tel_mobile' => 'telmobile', 'phone' => 'telmobile',
        'mobile' => 'telmobile', 'cellphone' => 'telmobile', 'cell' => 'telmobile',
        'telephone' => 'telmobile', 'phonenumber' => 'telmobile',
        'sin' => 'sin', 'socialinsurance' => 'sin', 'sinnumber' => 'sin',
        'streetaddress' => 'streetaddress', 'street' => 'streetaddress', 'address' => 'streetaddress',
        'city' => 'city',
        'province' => 'province', 'prov' => 'province', 'state' => 'province',
        'postalcode' => 'postalcode', 'postal' => 'postalcode', 'zip' => 'postalcode', 'zipcode' => 'postalcode',
        'transitrate' => 'transitrate', 'travelrate' => 'transitrate',
        'vacpaypercent' => 'vacpaypercent', 'vacationpercent' => 'vacpaypercent',
        'vacpercent' => 'vacpaypercent', 'vacationpct' => 'vacpaypercent',
        'banktransit' => 'banktransit', 'transit' => 'banktransit', 'transitnumber' => 'banktransit',
        'bankinstitution' => 'bankinstitution', 'institution' => 'bankinstitution', 'banknumber' => 'bankinstitution',
        'bankaccount' => 'bankaccount', 'account' => 'bankaccount', 'accountnumber' => 'bankaccount',
        'eiexempt' => 'eiexempt', 'ei_exempt' => 'eiexempt', 'exemptei' => 'eiexempt',
        'wsibexempt' => 'wsibexempt', 'wcbexempt' => 'wsibexempt', 'wsib_exempt' => 'wsibexempt',
        'wcb_exempt' => 'wsibexempt', 'exemptwsib' => 'wsibexempt', 'exemptwcb' => 'wsibexempt',
        'vacpayexempt' => 'vacpayexempt', 'vac_pay_exempt' => 'vacpayexempt', 'vacationexempt' => 'vacpayexempt',
        'dentaloffered' => 'dentaloffered', 'dental' => 'dentaloffered', 'dentalplan' => 'dentaloffered',
        'paytype' => 'paytype', 'type' => 'paytype', 'compensationtype' => 'paytype',
        'primarysitename' => 'primarysitename', 'sitename' => 'primarysitename',
        'locationname' => 'primarysitename', 'primarylocation' => 'primarysitename',
        'othersitenames' => 'othersitenames', 'assignedsitenames' => 'othersitenames',
        'othersites' => 'othersitenames', 'additionalsites' => 'othersitenames',
        'workdate' => 'date', 'employeemail' => 'email', 'employeeemail' => 'email',
        'employeefullname' => 'employeename', 'regularhours' => 'hours', 'reghours' => 'hours',
        'othours' => 'othours', 'overtimehours' => 'othours',
        'wagerate' => 'wagerate', 'notes' => 'remarks',
        'clockintime' => 'clockintime', 'clockin' => 'clockintime', 'timein' => 'clockintime',
        'punchin' => 'clockintime', 'starttime' => 'clockintime',
        'clockouttime' => 'clockouttime', 'clockout' => 'clockouttime', 'timeout' => 'clockouttime',
        'punchout' => 'clockouttime', 'endtime' => 'clockouttime',
        'businesslegalname' => 'businesslegalname', 'legalname' => 'businesslegalname',
        'corplegalname' => 'businesslegalname', 'bn15' => 'bn15', 'payrollaccount' => 'bn15',
        'hst' => 'bn15', 'rpnumber' => 'bn15', 'wsibnumber' => 'wsibnumber',
        'wcbnumber' => 'wsibnumber', 'wsibpercent' => 'wsibpercent', 'wcbpercent' => 'wsibpercent',
        'wsibrate' => 'wsibpercent', 'wcbrate' => 'wsibpercent',
        'timezone' => 'timezone', 'timezoneid' => 'timezone', 'tz' => 'timezone',
        'tzid' => 'timezone', 'time_zone' => 'timezone', 'sitetimezone' => 'timezone',
    ];
    // Normalized keys already strip hyphens; keep explicit e-mail style covered via 'email'.
    if ($mode === 'hours' || $mode === 'hours_admin') {
        $common['site'] = 'sitename';
        $common['siteid'] = 'siteid';
        $common['location'] = 'sitename';
        $common['worksite'] = 'sitename';
        $common['sitename'] = 'sitename';
        $common['primarysiteid'] = 'siteid';
        $common['primarysite'] = 'sitename';
        $common['primarysitename'] = 'sitename';
        $common['payrate'] = 'wagerate';
        $common['ot'] = 'othours';
    } elseif ($mode === 'sites') {
        $common['site'] = 'sitename';
        $common['sitename'] = 'sitename';
        $common['nickname'] = 'sitename';
        $common['nick_name'] = 'sitename';
        $common['location'] = 'sitename';
        $common['status'] = 'active';
    } else {
        $common['site'] = 'primarysiteid';
        $common['siteid'] = 'primarysiteid';
        $common['location'] = 'primarysiteid';
        $common['primarysiteid'] = 'primarysiteid';
        $common['primarysite'] = 'primarysitename';
        $common['assignedsiteids'] = 'assignedsiteids';
        $common['assignedsites'] = 'assignedsiteids';
        $common['sites'] = 'assignedsiteids';
        $common['siteids'] = 'assignedsiteids';
    }
    return $common;
}

/**
 * Peek raw CSV/TSV text: original header labels + normalized keys + data row count.
 * Returns ['headers'=>[['raw'=>,'key'=>],...], 'delimiter'=>, 'data_rows'=>int, 'errors'=>[]].
 */
function pgcsv_peek(string $text): array {
    $out = ['headers' => [], 'delimiter' => ',', 'data_rows' => 0, 'errors' => []];
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $text = str_replace(["\r\n", "\r"], "\n", (string)$text);
    $text = trim($text, "\n");
    if ($text === '') {
        $out['errors'][] = "No data to map. Upload a CSV or paste header + rows.";
        return $out;
    }
    $lines = explode("\n", $text);
    while ($lines && trim(end($lines)) === '') {
        array_pop($lines);
    }
    if (!$lines) {
        $out['errors'][] = "No header row found.";
        return $out;
    }

    $start = 0;
    $delimiter = ',';
    for ($i = 0; $i < count($lines); $i++) {
        $probe = trim($lines[$i]);
        if ($probe === '') continue;
        $tab_count = substr_count($probe, "\t");
        $comma_count = substr_count($probe, ',');
        $delimiter = ($tab_count > 0 && $tab_count >= $comma_count) ? "\t" : ',';
        if (pgcsv_is_instruction_row($probe, $delimiter)) {
            $start = $i + 1;
            continue;
        }
        $start = $i;
        break;
    }
    $out['delimiter'] = $delimiter;
    if ($start >= count($lines)) {
        $out['errors'][] = "No usable header row (only NOTE/instruction lines).";
        return $out;
    }

    $header = str_getcsv($lines[$start], $delimiter);
    if (!$header || (count($header) === 1 && trim((string)$header[0]) === '')) {
        $out['errors'][] = "No usable header row.";
        return $out;
    }
    $seen = [];
    foreach ($header as $h) {
        $raw = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$h));
        $key = pgcsv_norm_key($raw);
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $out['headers'][] = ['raw' => $raw, 'key' => $key];
    }
    if (!$out['headers']) {
        $out['errors'][] = "No usable column headers.";
        return $out;
    }
    $data_rows = 0;
    for ($i = $start + 1; $i < count($lines); $i++) {
        if (trim($lines[$i]) === '') continue;
        if (pgcsv_is_instruction_row($lines[$i], $delimiter)) continue;
        $data = str_getcsv($lines[$i], $delimiter);
        if (count($data) === 1 && trim((string)$data[0]) === '') continue;
        $data_rows++;
    }
    $out['data_rows'] = $data_rows;
    if ($data_rows === 0) {
        $out['errors'][] = "No data rows found below the header (NOTE/EXAMPLE rows are ignored).";
    }
    return $out;
}

/** True when source already has every required template column under its canonical name. */
function pgcsv_headers_exact_match(array $source_keys, string $mode): bool {
    $have = array_flip($source_keys);
    if ($mode === 'hours') {
        // Customer hours: WorkDate + Email + Site + (punches OR RegularHours) — punches checked per row
        $has_date = isset($have['date']) || isset($have['workdate']);
        $has_email = isset($have['email']) || isset($have['employeeemail']) || isset($have['employeemail']);
        $has_site = isset($have['sitename']) || isset($have['siteid']);
        $has_hours = isset($have['hours']) || isset($have['regularhours']) || isset($have['reghours']);
        $has_in = isset($have['clockintime']) || isset($have['clockin']);
        $has_out = isset($have['clockouttime']) || isset($have['clockout']);
        return $has_date && $has_email && $has_site && ($has_hours || ($has_in && $has_out));
    }
    if ($mode === 'sites') {
        return isset($have['sitename']);
    }
    $need = [];
    foreach (pgcsv_target_fields($mode) as $f) {
        if ($f['required']) $need[] = $f['key'];
    }
    if ($mode === 'employees') {
        if (!isset($have['employeename']) && !isset($have['fullname'])) return false;
        if (!isset($have['primarysitename']) && !isset($have['primarysiteid'])) return false;
    }
    foreach ($need as $k) {
        if (!in_array($k, $source_keys, true)) {
            if ($mode === 'employees' && $k === 'employeename' && in_array('fullname', $source_keys, true)) {
                continue;
            }
            return false;
        }
    }
    return true;
}

/**
 * Auto-suggest mapping: target_key => source_key (or '').
 * Exact canonical match wins; then aliases. Each source used at most once.
 */
function pgcsv_suggest_mapping(array $source_headers, string $mode): array {
    $aliases = pgcsv_header_aliases($mode);
    $targets = pgcsv_target_fields($mode);
    $available = [];
    foreach ($source_headers as $h) {
        $available[$h['key']] = true;
    }
    $mapping = [];
    foreach ($targets as $f) {
        $mapping[$f['key']] = '';
    }
    // Pass 1: exact target key present in source
    foreach ($targets as $f) {
        $tk = $f['key'];
        if (isset($available[$tk])) {
            $mapping[$tk] = $tk;
            unset($available[$tk]);
        }
    }
    // Pass 2: aliases
    foreach ($targets as $f) {
        $tk = $f['key'];
        if ($mapping[$tk] !== '') continue;
        foreach ($available as $sk => $_) {
            if (($aliases[$sk] ?? null) === $tk) {
                $mapping[$tk] = $sk;
                unset($available[$sk]);
                break;
            }
        }
    }
    return $mapping;
}

/**
 * Validate user mapping before import.
 * $mapping: target_key => source_key (empty = not mapped).
 * Returns list of error strings (empty = ok).
 */
function pgcsv_validate_mapping(array $mapping, string $mode): array {
    $errors = [];
    $targets = pgcsv_target_fields($mode);
    $labels = [];
    foreach ($targets as $f) $labels[$f['key']] = $f['label'];

    foreach ($targets as $f) {
        if (!$f['required']) continue;
        if (($mapping[$f['key']] ?? '') === '') {
            $errors[] = "Required field not mapped: {$f['label']}.";
        }
    }
    if ($mode === 'hours') {
        // Soft-required at mapping: email + site + date. Hours vs punches validated per row.
        // Clear false "required" errors for RegularHours / ClockIn from the loop above.
        $errors = array_values(array_filter($errors, static function ($e) {
            return strpos($e, 'RegularHours') === false && strpos($e, 'ClockIn') === false && strpos($e, 'ClockOut') === false;
        }));
        if (($mapping['email'] ?? '') === '') {
            $errors[] = "Map EmployeeEmail (required — unique match for hours rows).";
        }
        if (($mapping['sitename'] ?? '') === '' && ($mapping['siteid'] ?? '') === '') {
            $errors[] = "Map SiteName (preferred) or SiteID so we know where the hours were worked.";
        }
        $has_hours_col = ($mapping['hours'] ?? '') !== '';
        $has_in = ($mapping['clockintime'] ?? '') !== '';
        $has_out = ($mapping['clockouttime'] ?? '') !== '';
        if (!$has_hours_col && !$has_in && !$has_out) {
            $errors[] = "Map either ClockIn+ClockOut (punch times) or RegularHours — provide one method per row.";
        }
    }
    if ($mode === 'sites') {
        if (($mapping['sitename'] ?? '') === '') {
            $errors[] = "Map SiteName (required).";
        }
    }
    if ($mode === 'employees') {
        $has_site_name = ($mapping['primarysitename'] ?? '') !== '';
        $has_site_id = ($mapping['primarysiteid'] ?? '') !== '';
        if (!$has_site_name && !$has_site_id) {
            $errors[] = "Map PrimarySiteName (preferred) or PrimarySiteID so we know the employee's home site.";
        }
    }
    // Duplicate source columns
    $used = [];
    foreach ($mapping as $tk => $sk) {
        if ($sk === '') continue;
        if (isset($used[$sk])) {
            $errors[] = "Source column is mapped twice ({$labels[$used[$sk]]} and {$labels[$tk]}). Each source column can only map to one field.";
        } else {
            $used[$sk] = $tk;
        }
    }
    return $errors;
}

/**
 * Remap parsed rows: target keys pulled from source keys per $mapping.
 * Always emits every template target key (empty string if unmapped) so require_headers passes.
 * For employees: if PrimarySiteID mapped but AssignedSiteIDs not, copy primary into assigned.
 */
function pgcsv_apply_mapping(array $rows, array $mapping, string $mode): array {
    $targets = pgcsv_target_fields($mode);
    $out = [];
    foreach ($rows as $r) {
        $nr = ['_line' => $r['_line'] ?? 0];
        foreach ($targets as $f) {
            $tk = $f['key'];
            $sk = $mapping[$tk] ?? '';
            $nr[$tk] = ($sk !== '' && isset($r[$sk])) ? $r[$sk] : '';
        }
        if ($mode === 'employees'
            && $nr['assignedsiteids'] === ''
            && $nr['primarysiteid'] !== '') {
            $nr['assignedsiteids'] = $nr['primarysiteid'];
        }
        $out[] = $nr;
    }
    return $out;
}

/**
 * True when a CSV line is an instruction / example row (not real data).
 * Skips NOTE: / # comments and EXAMPLE placeholder rows.
 */
function pgcsv_is_instruction_row(string $raw_line, string $delimiter = ','): bool {
    $raw_line = trim($raw_line);
    if ($raw_line === '') return true;
    $first = '';
    if ($delimiter === "\t") {
        $parts = explode("\t", $raw_line, 2);
        $first = trim((string)($parts[0] ?? ''));
    } else {
        $parts = str_getcsv($raw_line, $delimiter);
        $first = trim((string)($parts[0] ?? ''));
    }
    if ($first === '') return false;
    if ($first[0] === '#') return true;
    $u = strtoupper($first);
    if (strpos($u, 'NOTE:') === 0 || $u === 'NOTE') return true;
    if (strpos($u, 'EXAMPLE') === 0 || $u === 'EXAMPLE ROW') return true;
    if (strpos($u, 'INSTRUCTIONS') === 0) return true;
    return false;
}

/**
 * Parse CSV/TSV text into rows keyed by normalized header name.
 * Excel/Google Sheets paste is usually tab-separated; plain CSV uses commas.
 * Leading NOTE/# lines before the header are skipped; instruction/example data rows are skipped.
 */
function pgcsv_parse_text(string $text, array &$errors): array {
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $text = str_replace(["\r\n", "\r"], "\n", (string)$text);
    $text = trim($text, "\n");
    if ($text === '') {
        $errors[] = "Paste is empty. Copy the header row plus data rows from your spreadsheet.";
        return [];
    }

    $lines = explode("\n", $text);
    while ($lines && trim(end($lines)) === '') {
        array_pop($lines);
    }
    if (!$lines) {
        $errors[] = "Paste is empty or has no header row.";
        return [];
    }

    // Detect delimiter from first non-instruction line
    $start = 0;
    $delimiter = ',';
    for ($i = 0; $i < count($lines); $i++) {
        $probe = trim($lines[$i]);
        if ($probe === '') continue;
        $tab_count = substr_count($probe, "\t");
        $comma_count = substr_count($probe, ',');
        $delimiter = ($tab_count > 0 && $tab_count >= $comma_count) ? "\t" : ',';
        if (pgcsv_is_instruction_row($probe, $delimiter)) {
            $start = $i + 1;
            continue;
        }
        $start = $i;
        break;
    }
    if ($start >= count($lines)) {
        $errors[] = "Paste has no usable header row (only NOTE/instruction lines found).";
        return [];
    }

    $header_line = $lines[$start];
    $header = str_getcsv($header_line, $delimiter);
    if (!$header || (count($header) === 1 && trim((string)$header[0]) === '')) {
        $errors[] = "Paste has no usable header row.";
        return [];
    }

    $cols = [];
    foreach ($header as $i => $h) {
        $h = preg_replace('/^\xEF\xBB\xBF/', '', (string)$h);
        $cols[$i] = pgcsv_norm_key($h);
    }

    $rows = [];
    for ($li = $start + 1; $li < count($lines); $li++) {
        $raw = $lines[$li];
        if (trim($raw) === '') continue;
        if (pgcsv_is_instruction_row($raw, $delimiter)) continue;
        $data = str_getcsv($raw, $delimiter);
        if (count($data) === 1 && trim((string)$data[0]) === '') continue;
        // Also skip if first mapped cell is NOTE/EXAMPLE after normalize
        $first_val = isset($data[0]) ? trim((string)$data[0]) : '';
        $fu = strtoupper($first_val);
        if ($fu !== '' && (strpos($fu, 'NOTE:') === 0 || strpos($fu, 'EXAMPLE') === 0 || $first_val[0] === '#')) {
            continue;
        }
        $row = ['_line' => $li + 1];
        foreach ($cols as $i => $name) {
            if ($name === '') continue;
            $row[$name] = isset($data[$i]) ? trim((string)$data[$i]) : '';
        }
        $rows[] = $row;
    }
    if (!$rows) $errors[] = "No data rows found below the header (NOTE/EXAMPLE rows are ignored).";
    return $rows;
}

/** Read a CSV file into rows keyed by normalized header name. */
function pgcsv_read(string $path, array &$errors): array {
    $text = @file_get_contents($path);
    if ($text === false) {
        $errors[] = "Could not open uploaded file.";
        return [];
    }
    return pgcsv_parse_text($text, $errors);
}

/**
 * Import from raw text with optional column mapping (Match columns step).
 * $column_map: target_key => source_key.
 */
function pg_import_from_text(PDO $pdo, string $mode, string $text, array $sess, bool $dry_run, ?array $column_map = null): array {
    $errors = [];
    $rows = pgcsv_parse_text($text, $errors);
    if ($errors) return ['errors' => $errors, 'inserted' => 0, 'preview' => []];
    if ($column_map !== null) {
        $map_errs = pgcsv_validate_mapping($column_map, $mode);
        if ($map_errs) return ['errors' => $map_errs, 'inserted' => 0, 'preview' => []];
        $rows = pgcsv_apply_mapping($rows, $column_map, $mode);
    }
    if ($mode === 'hours') {
        return pg_import_hours_rows($pdo, $rows, $sess, $dry_run);
    }
    if ($mode === 'sites') {
        return pg_import_sites_rows($pdo, $rows, $sess, $dry_run);
    }
    return pg_import_employees_rows($pdo, $rows, $sess, $dry_run);
}

function pgcsv_require_headers(array $rows, array $required, array &$errors): bool {
    if (!$rows) return false;
    $have = array_keys($rows[0]);
    $missing = array_diff($required, $have);
    if ($missing) {
        $errors[] = "Missing required column(s): " . implode(', ', $missing)
                  . ". Use Match columns to map your headers, or download the template and keep its header row intact.";
        return false;
    }
    return true;
}

/**
 * Normalize a flexible date string to YYYY-MM-DD.
 * Accepts ISO (YYYY-MM-DD), US M/D/YYYY (Excel often rewrites CSVs this way),
 * and YYYY/MM/DD. Returns null if unparseable.
 */
function pgcsv_normalize_date_string(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    // Strip optional time suffix from ISO-ish values
    if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T].*)?$/', $raw, $m)) {
        $candidate = $m[1];
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $candidate);
        $errs = DateTimeImmutable::getLastErrors();
        if ($dt && ($errs['warning_count'] ?? 0) === 0 && ($errs['error_count'] ?? 0) === 0 && $dt->format('Y-m-d') === $candidate) {
            return $candidate;
        }
        return null;
    }
    // Excel US date: M/D/YYYY or M/D/YY
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $raw, $m)) {
        $mo = (int)$m[1];
        $day = (int)$m[2];
        $y = (int)$m[3];
        if ($y < 100) {
            $y += ($y < 70) ? 2000 : 1900;
        }
        if (!checkdate($mo, $day, $y)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $y, $mo, $day);
    }
    // YYYY/MM/DD
    if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $raw, $m)) {
        $y = (int)$m[1];
        $mo = (int)$m[2];
        $day = (int)$m[3];
        if (!checkdate($mo, $day, $y)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $y, $mo, $day);
    }
    return null;
}

/**
 * Validate hire date: accepts YYYY-MM-DD or M/D/YYYY (Excel), normalizes to ISO,
 * real calendar day, not in the future.
 * Returns normalized Y-m-d string or null (and appends an error).
 */
function pgcsv_parse_start_date(string $raw, string $line_label, array &$errors): ?string {
    $raw = trim($raw);
    if ($raw === '') {
        $errors[] = "$line_label: StartDate is required (YYYY-MM-DD or M/D/YYYY). Vacation entitlement is derived from hire date + site province.";
        return null;
    }
    $normalized = pgcsv_normalize_date_string($raw);
    if ($normalized === null) {
        $errors[] = "$line_label: StartDate '$raw' must be YYYY-MM-DD (or M/D/YYYY).";
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $normalized);
    $errs = DateTimeImmutable::getLastErrors();
    if (!$dt || ($errs['warning_count'] ?? 0) > 0 || ($errs['error_count'] ?? 0) > 0 || $dt->format('Y-m-d') !== $normalized) {
        $errors[] = "$line_label: StartDate '$raw' is not a valid calendar date.";
        return null;
    }
    $today = new DateTimeImmutable('today');
    if ($dt > $today) {
        $errors[] = "$line_label: StartDate '$raw' cannot be in the future.";
        return null;
    }
    return $normalized;
}

/**
 * Import employees. $sess = ['user_level'=>int, 'org_id'=>int].
 * $source is a filesystem path, or raw paste text when $from_paste is true.
 * Returns ['errors'=>[], 'inserted'=>int, 'preview'=>[]].
 */
function pg_import_employees(PDO $pdo, string $source, array $sess, bool $dry_run, bool $from_paste = false): array {
    $errors = [];
    $rows = $from_paste ? pgcsv_parse_text($source, $errors) : pgcsv_read($source, $errors);
    if ($errors) return ['errors'=>$errors,'inserted'=>0,'preview'=>[]];
    return pg_import_employees_rows($pdo, $rows, $sess, $dry_run);
}

/**
 * Copy alias header keys onto canonical keys so customer FullName / Tel_Mobile
 * rows work without the Match columns step.
 */
function pgcsv_normalize_employee_aliases(array &$rows): void {
    $aliases = pgcsv_header_aliases('employees');
    foreach ($rows as &$r) {
        foreach ($aliases as $alias => $canon) {
            if ($alias === $canon) continue;
            if ((!isset($r[$canon]) || $r[$canon] === '') && isset($r[$alias]) && $r[$alias] !== '') {
                $r[$canon] = $r[$alias];
            }
        }
    }
    unset($r);
}

/** Last 4 digits of a phone number (for temporary password / PIN). */
function pgcsv_phone_last4(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === null || strlen($digits) < 4) return '';
    return substr($digits, -4);
}

/**
 * Map Role / RoleID cell to users.user_role.
 * Names (case-insensitive): superadmin→10, admin→20,
 * site manager|sitemanager|site_manager→25, supervisor→30, staff|user|users→40.
 * Also accepts numeric 10/20/25/30/40. Blank → 40 (staff).
 * Superadmin (10) only when importer is already a superadmin; otherwise downgrade to 40 + warning.
 *
 * @return int|null null when the value is invalid (error already recorded)
 */
function pgcsv_parse_role_value(string $raw, bool $is_super, string $line_label, array &$errors, array &$warnings): ?int {
    $raw = trim($raw);
    if ($raw === '') return 40;

    $norm = strtolower(preg_replace('/[\s_\-]+/', '', $raw) ?? $raw);
    $name_map = [
        'superadmin' => 10,
        'admin' => 20,
        'sitemanager' => 25,
        'supervisor' => 30,
        'staff' => 40,
        'user' => 40,
        'users' => 40,
    ];

    if (ctype_digit($raw) || (is_numeric($raw) && (string)(int)$raw === (string)$raw)) {
        $role = (int)$raw;
    } elseif (isset($name_map[$norm])) {
        $role = $name_map[$norm];
    } else {
        $errors[] = "$line_label: Role '$raw' is not recognized. Use admin, site manager, supervisor, staff (or RoleID 20/25/30/40). Blank = staff (40).";
        return null;
    }

    $allowed = [10, 20, 25, 30, 40];
    if (!in_array($role, $allowed, true)) {
        $errors[] = "$line_label: RoleID $role is not allowed on import (use 20/25/30/40; superadmin 10 only by an existing superadmin).";
        return null;
    }

    if ($role === 10 && !$is_super) {
        $warnings[] = "$line_label: Role superadmin (10) is not allowed for this importer — set to staff (40).";
        return 40;
    }
    return $role;
}

/** Parse Yes/No/1/0 style flags. Default $default when blank. */
function pgcsv_parse_boolish(string $raw, bool $default = false): bool {
    $v = strtolower(trim($raw));
    if ($v === '') return $default;
    if (in_array($v, ['1', 'y', 'yes', 'true', 't', 'on'], true)) return true;
    if (in_array($v, ['0', 'n', 'no', 'false', 'f', 'off'], true)) return false;
    return $default;
}

/**
 * Build org-scoped site name → SId index (nick_name, Store, corp_legal_name).
 * @return array<string,int> lowercase name => SId
 */
function pgcsv_site_name_index(PDO $pdo, int $org): array {
    if (!function_exists('sip_site_status_is_active')) {
        require_once __DIR__ . '/engine/site_status_lib.php';
    }
    $q = $pdo->prepare("SELECT SId, nick_name, Store, corp_legal_name, status FROM sites WHERE org_id = ?");
    $q->execute([$org]);
    $idx = [];
    foreach ($q as $s) {
        if (!sip_site_status_is_active($s['status'] ?? null)) {
            continue;
        }
        $sid = (int)$s['SId'];
        foreach (['nick_name', 'Store', 'corp_legal_name'] as $col) {
            $n = trim((string)($s[$col] ?? ''));
            if ($n === '') continue;
            $k = strtolower($n);
            if (!isset($idx[$k])) $idx[$k] = $sid;
        }
    }
    return $idx;
}

/** Resolve PrimarySiteName / OtherSiteNames text to site IDs within org. */
function pgcsv_resolve_site_name(string $name, array $name_idx, string $line_label, array &$errors): ?int {
    $name = trim($name);
    if ($name === '') return null;
    $k = strtolower($name);
    if (isset($name_idx[$k])) return $name_idx[$k];
    $errors[] = "$line_label: Site name '$name' was not found in your organization (or is Inactive). Use an active nickname from Manage Sites.";
    return null;
}

/** Core employee import from already-parsed (and optionally remapped) rows. */
function pg_import_employees_rows(PDO $pdo, array $rows, array $sess, bool $dry_run): array {
    $errors = [];
    $warnings = [];
    pgcsv_normalize_employee_aliases($rows);

    // Soft defaults before AssignedSiteIDs copy
    foreach ($rows as &$r) {
        if ((!isset($r['assignedsiteids']) || $r['assignedsiteids'] === '')
            && isset($r['primarysiteid']) && $r['primarysiteid'] !== '') {
            $r['assignedsiteids'] = $r['primarysiteid'];
        }
    }
    unset($r);

    // Customer template: name + email + phone + rate + start + (site name or id)
    $req = ['employeename', 'email', 'hourlyrate', 'startdate'];
    if (!pgcsv_require_headers($rows, $req, $errors)) {
        if ($rows && isset($rows[0]['vacationpercent']) && !isset($rows[0]['startdate'])) {
            array_unshift($errors, "VacationPercent is no longer accepted as the only vacation column — include StartDate (YYYY-MM-DD). VacPayPercent remains an optional override.");
        }
        return ['errors'=>$errors,'inserted'=>0,'preview'=>[]];
    }

    $is_super = ((int)$sess['user_level'] <= 10);
    $sess_org = (int)$sess['org_id'];

    $valid_roles = $pdo->query("SELECT role_id FROM roles")->fetchAll(PDO::FETCH_COLUMN);
    $valid_roles = array_map('intval', $valid_roles);

    $orgs = [];
    foreach ($pdo->query("SELECT id, max_users FROM organizations") as $o) $orgs[(int)$o['id']] = (int)$o['max_users'];
    $sites = [];
    $sites_inactive = [];
    if (!function_exists('sip_site_status_is_active')) {
        require_once __DIR__ . '/engine/site_status_lib.php';
    }
    foreach ($pdo->query("SELECT SId, org_id, status FROM sites") as $s) {
        $sites[(int)$s['SId']] = (int)$s['org_id'];
        if (!sip_site_status_is_active($s['status'] ?? null)) {
            $sites_inactive[(int)$s['SId']] = true;
        }
    }

    // Cache name indexes per org as we see them
    $name_indexes = [];

    $batch_emails = []; $batch_users = [];
    $to_insert = []; $preview = [];
    $org_new_counts = [];

    foreach ($rows as $r) {
        $L = "Line {$r['_line']}";
        $name  = trim((string)($r['employeename'] ?? ''));
        $email = strtolower(trim((string)($r['email'] ?? '')));
        $phone = trim((string)($r['telmobile'] ?? ''));

        if ($name === '')  { $errors[] = "$L: FullName / EmployeeName is required."; continue; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = "$L: Email '{$r['email']}' is not a valid address."; continue; }

        // Org: CSV or session
        $org_raw = trim((string)($r['orgid'] ?? ''));
        $org = $org_raw !== '' ? (int)$org_raw : $sess_org;
        if (!isset($orgs[$org])) { $errors[] = "$L: OrgID $org does not exist."; continue; }
        if (!$is_super && $org !== $sess_org) { $errors[] = "$L: OrgID $org does not match your organization ($sess_org)."; continue; }

        if (!isset($name_indexes[$org])) {
            $name_indexes[$org] = pgcsv_site_name_index($pdo, $org);
        }
        $name_idx = $name_indexes[$org];

        // Primary site: ID and/or name
        $primary = 0;
        $primary_id_raw = trim((string)($r['primarysiteid'] ?? ''));
        $primary_name = trim((string)($r['primarysitename'] ?? ''));
        if ($primary_id_raw !== '') {
            $primary = (int)$primary_id_raw;
        } elseif ($primary_name !== '') {
            $resolved = pgcsv_resolve_site_name($primary_name, $name_idx, $L, $errors);
            if ($resolved === null) continue;
            $primary = $resolved;
        } else {
            $errors[] = "$L: PrimarySiteName (or PrimarySiteID) is required.";
            continue;
        }
        if (!isset($sites[$primary]) || $sites[$primary] !== $org) {
            $errors[] = "$L: Primary site $primary does not belong to OrgID $org.";
            continue;
        }
        if (!empty($sites_inactive[$primary])) {
            $errors[] = "$L: Primary site $primary is Inactive. Reactivate it on Manage Sites or pick an active site.";
            continue;
        }

        // Assigned sites from IDs and/or OtherSiteNames
        $assigned = [];
        $assigned_raw = trim((string)($r['assignedsiteids'] ?? ''));
        if ($assigned_raw !== '') {
            $assigned = array_filter(array_map('trim', preg_split('/[|,]/', $assigned_raw)), 'strlen');
            $assigned = array_values(array_unique(array_map('intval', $assigned)));
        }
        $other_names = trim((string)($r['othersitenames'] ?? ''));
        if ($other_names !== '') {
            $parts = array_filter(array_map('trim', preg_split('/[|,]/', $other_names)), 'strlen');
            $bad = false;
            foreach ($parts as $pn) {
                $sid = pgcsv_resolve_site_name($pn, $name_idx, $L, $errors);
                if ($sid === null) { $bad = true; continue; }
                $assigned[] = $sid;
            }
            if ($bad) continue;
        }
        $assigned = array_values(array_unique(array_map('intval', $assigned)));
        if (!in_array($primary, $assigned, true)) $assigned[] = $primary;
        $bad = false;
        foreach ($assigned as $sid) {
            if (!isset($sites[$sid]) || $sites[$sid] !== $org) {
                $errors[] = "$L: Assigned site $sid does not belong to OrgID $org.";
                $bad = true;
            } elseif (!empty($sites_inactive[$sid])) {
                $errors[] = "$L: Assigned site $sid is Inactive. Reactivate it on Manage Sites or pick an active site.";
                $bad = true;
            }
        }
        if ($bad) continue;

        // Credentials: admin CSV or derive from email + phone
        $uname = trim((string)($r['username'] ?? ''));
        if ($uname === '') $uname = $email;
        $pass = trim((string)($r['temporarypassword'] ?? ''));
        $pin  = trim((string)($r['pin'] ?? ''));
        $last4 = pgcsv_phone_last4($phone);
        if ($pass === '' || $pin === '') {
            if ($last4 === '') {
                $errors[] = "$L: Tel_Mobile is required (last 4 digits become temporary password and PIN), or provide TemporaryPassword and PIN.";
                continue;
            }
            if ($pass === '') $pass = $last4;
            if ($pin === '') $pin = $last4;
        }

        // Prefer Role name column; fall back to RoleID. Blank = staff (40).
        $role_raw = trim((string)($r['role'] ?? ''));
        if ($role_raw === '') $role_raw = trim((string)($r['roleid'] ?? ''));
        $role = pgcsv_parse_role_value($role_raw, $is_super, $L, $errors, $warnings);
        if ($role === null) continue;
        if (!in_array($role, $valid_roles, true)) {
            $errors[] = "$L: RoleID $role does not exist in roles table (staff = 40).";
            continue;
        }

        if (!is_numeric($r['hourlyrate']) || (float)$r['hourlyrate'] <= 0) {
            $errors[] = "$L: HourlyRate '{$r['hourlyrate']}' must be a positive number.";
            continue;
        }
        $rate = round((float)$r['hourlyrate'], 2);
        $official = ($r['officialrate'] ?? '') !== '' ? round((float)$r['officialrate'], 2) : $rate;
        $transit = ($r['transitrate'] ?? '') !== '' ? round((float)$r['transitrate'], 2) : 0.00;

        $active_raw = trim((string)($r['active'] ?? ''));
        if ($active_raw === '') {
            $active = 1;
        } elseif (!in_array($active_raw, ['0', '1'], true)) {
            $errors[] = "$L: Active must be 1 or 0.";
            continue;
        } else {
            $active = (int)$active_raw;
        }

        $start_date = pgcsv_parse_start_date($r['startdate'] ?? '', $L, $errors);
        if ($start_date === null) continue;

        $end_date = null;
        $end_raw = trim((string)($r['enddate'] ?? ''));
        if ($end_raw !== '') {
            $end_norm = pgcsv_normalize_date_string($end_raw);
            if ($end_norm === null) {
                $errors[] = "$L: EndDate '$end_raw' must be YYYY-MM-DD or M/D/YYYY (employment end only — not a rate change).";
                continue;
            }
            $end_date = $end_norm;
        }

        $vac_override = 0.00;
        $vac_raw = trim((string)($r['vacpaypercent'] ?? ''));
        if ($vac_raw !== '') {
            if (!is_numeric($vac_raw) || (float)$vac_raw < 0 || (float)$vac_raw > 100) {
                $errors[] = "$L: VacPayPercent '$vac_raw' must be a number from 0 to 100.";
                continue;
            }
            $vac_override = round((float)$vac_raw, 2);
        }

        $sin = trim((string)($r['sin'] ?? ''));
        $street = trim((string)($r['streetaddress'] ?? ''));
        $city = trim((string)($r['city'] ?? ''));
        $province = strtoupper(trim((string)($r['province'] ?? '')));
        if ($province !== '' && strlen($province) > 2) $province = substr($province, 0, 2);
        $postal = strtoupper(trim((string)($r['postalcode'] ?? '')));
        $bank_t = trim((string)($r['banktransit'] ?? ''));
        $bank_i = trim((string)($r['bankinstitution'] ?? ''));
        $bank_a = trim((string)($r['bankaccount'] ?? ''));

        $ei_exempt = pgcsv_parse_boolish((string)($r['eiexempt'] ?? ''), false);
        $wsib_exempt = pgcsv_parse_boolish((string)($r['wsibexempt'] ?? ''), false);
        $vac_pay_exempt = pgcsv_parse_boolish((string)($r['vacpayexempt'] ?? ''), false);
        $dental = pgcsv_parse_boolish((string)($r['dentaloffered'] ?? ''), false);
        $pay_type = trim((string)($r['paytype'] ?? ''));
        if ($pay_type === '') $pay_type = 'Hourly';

        $remarks = trim((string)($r['remarks'] ?? ''));
        // Dental still tagged in Remarks for older screens; exemption flags are real columns.
        if ($dental) {
            $tag = '[DentalOffered=Yes]';
            $remarks = $remarks === '' ? $tag : ($remarks . ' ' . $tag);
        }

        // Uniqueness within the file
        $ekey = "$org|$email";
        if (isset($batch_emails[$ekey])) { $errors[] = "$L: Email $email appears more than once for OrgID $org in this file."; continue; }
        if (isset($batch_users[strtolower($uname)])) { $errors[] = "$L: Username '$uname' appears more than once in this file."; continue; }
        $batch_emails[$ekey] = true; $batch_users[strtolower($uname)] = true;

        $q = $pdo->prepare("SELECT id FROM users WHERE LOWER(TRIM(email)) = ? AND org_id = ? LIMIT 1");
        $q->execute([$email, $org]);
        if ($q->fetch()) { $errors[] = "$L: Email $email already exists in OrgID $org."; continue; }
        $q = $pdo->prepare("SELECT id FROM users WHERE user = ? LIMIT 1");
        $q->execute([$uname]);
        if ($q->fetch()) { $errors[] = "$L: Username '$uname' is already taken (usernames are global)."; continue; }

        $org_new_counts[$org] = ($org_new_counts[$org] ?? 0) + 1;

        $to_insert[] = [
            'Name'=>$name, 'user'=>$uname, 'email'=>$email,
            'password'=>password_hash($pass, PASSWORD_DEFAULT), 'pin'=>$pin,
            'user_role'=>$role, 'org_id'=>$org, 'hourly_rate'=>$rate,
            'official_rate'=>$official, 'transit_rate'=>$transit,
            'vac_pay_percent'=>$vac_override, 'active'=>$active,
            'primary_site_id'=>$primary, 'assigned_site_ids'=>implode(',', $assigned),
            'Remarks'=>$remarks === '' ? '' : $remarks,
            'StartDate'=>$start_date,
            'EndDate'=>$end_date,
            'Tel_Mobile'=>$phone !== '' ? $phone : null,
            'sin'=>$sin !== '' ? $sin : null,
            'street_address'=>$street !== '' ? $street : null,
            'city'=>$city !== '' ? $city : null,
            'province'=>$province !== '' ? $province : null,
            'postal_code'=>$postal !== '' ? $postal : null,
            'bank_transit'=>$bank_t !== '' ? $bank_t : null,
            'bank_institution'=>$bank_i !== '' ? $bank_i : null,
            'bank_account'=>$bank_a !== '' ? $bank_a : null,
            'Type'=>$pay_type,
            'ei_exempt'=>$ei_exempt ? 1 : 0,
            'wsib_exempt'=>$wsib_exempt ? 1 : 0,
            'vac_pay_exempt'=>$vac_pay_exempt ? 1 : 0,
            '_assigned'=>$assigned,
            '_start_date'=>$start_date,
            '_vac_override'=>$vac_override,
        ];
        $site_label = $primary_name !== '' ? $primary_name : ("#$primary");
        $preview[] = [
            'line'=>$r['_line'],
            'label'=>"$name <$email>",
            'detail'=>"Org $org, site $site_label ($primary), role $role, \$$rate/hr, StartDate $start_date; login=$uname; PIN/password from phone last-4 when not supplied",
        ];
    }

    foreach ($org_new_counts as $org => $adding) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ?");
        $q->execute([$org]);
        $current = (int)$q->fetchColumn();
        if (($current + $adding) > $orgs[$org]) {
            $errors[] = "OrgID $org: import adds $adding user(s) but the plan limit is {$orgs[$org]} and $current already exist.";
        }
    }

    if ($errors) return ['errors'=>$errors,'inserted'=>0,'preview'=>$preview];
    if ($warnings) {
        foreach ($warnings as $w) {
            $preview[] = ['line' => 0, 'label' => 'Warning', 'detail' => $w];
        }
    }
    if ($dry_run) return ['errors'=>[],'inserted'=>0,'preview'=>$preview];

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            "INSERT INTO users (Name, user, email, password, pin, user_role, org_id, hourly_rate, official_rate,
                                transit_rate, vac_pay_percent, active, primary_site_id, primary_restaurant_id,
                                assigned_site_ids, Remarks, StartDate, EndDate, Tel_Mobile, sin, street_address,
                                city, province, postal_code, bank_transit, bank_institution, bank_account, Type,
                                ei_exempt, wsib_exempt, vac_pay_exempt)
             VALUES (:Name, :user, :email, :password, :pin, :user_role, :org_id, :hourly_rate, :official_rate,
                     :transit_rate, :vac_pay_percent, :active, :primary_site_id, :primary_restaurant_id,
                     :assigned_site_ids, :Remarks, :StartDate, :EndDate, :Tel_Mobile, :sin, :street_address,
                     :city, :province, :postal_code, :bank_transit, :bank_institution, :bank_account, :Type,
                     :ei_exempt, :wsib_exempt, :vac_pay_exempt)");
        $svc = $pdo->prepare(
            "INSERT INTO employee_company_service (user_id, site_id, start_date, vac_percent, source, note)
             VALUES (?, ?, ?, ?, 'import', 'CSV StartDate')
             ON DUPLICATE KEY UPDATE start_date=VALUES(start_date), vac_percent=VALUES(vac_percent), source='import', note=VALUES(note)"
        );
        foreach ($to_insert as $u) {
            $assigned = $u['_assigned'];
            $start_date = $u['_start_date'];
            $vac_override = $u['_vac_override'];
            unset($u['_assigned'], $u['_start_date'], $u['_vac_override']);
            $u['primary_restaurant_id'] = $u['primary_site_id'];
            $ins->execute($u);
            $uid = (int)$pdo->lastInsertId();
            foreach ($assigned as $sid) {
                $svc->execute([$uid, (int)$sid, $start_date, $vac_override]);
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['errors'=>["Database error, nothing was imported: " . $e->getMessage()],'inserted'=>0,'preview'=>$preview];
    }
    return ['errors'=>[],'inserted'=>count($to_insert),'preview'=>$preview];
}

/**
 * Import hours as attendance rows (status '4').
 * Either/or per row:
 *   - ClockIn + ClockOut → hours from punch span (source of truth; warn if RegularHours differs)
 *   - No punches → RegularHours required (OTHours optional)
 *   - ClockOut without ClockIn → error
 * Synthetic clock_in/out stored so Staff Audit / finalize still work.
 */
function pg_import_hours(PDO $pdo, string $source, array $sess, bool $dry_run, bool $from_paste = false): array {
    $errors = [];
    $rows = $from_paste ? pgcsv_parse_text($source, $errors) : pgcsv_read($source, $errors);
    if ($errors) return ['errors'=>$errors,'inserted'=>0,'preview'=>[]];
    return pg_import_hours_rows($pdo, $rows, $sess, $dry_run);
}

/** Parse HH:MM or HH:MM:SS; returns "HH:MM:SS" or null. */
function pgcsv_parse_clock_time(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;
    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $raw, $m)) {
        $sec = isset($m[3]) ? $m[3] : '00';
        return sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], (int)$sec);
    }
    return null;
}

/** Canadian province / territory → IANA timezone (SK = Regina, no DST). */
function pgcsv_province_timezone(string $prov): ?string {
    static $map = [
        'BC' => 'America/Vancouver',
        'AB' => 'America/Edmonton',
        'SK' => 'America/Regina',
        'MB' => 'America/Winnipeg',
        'ON' => 'America/Toronto',
        'QC' => 'America/Toronto',
        'NB' => 'America/Moncton',
        'NS' => 'America/Halifax',
        'PE' => 'America/Halifax',
        'NL' => 'America/St_Johns',
        'YT' => 'America/Whitehorse',
        'NT' => 'America/Yellowknife',
        'NU' => 'America/Iqaluit',
    ];
    $p = strtoupper(trim($prov));
    return $map[$p] ?? null;
}

/**
 * Resolve a site timezone from CSV Timezone (or short label) with Province fallback.
 * Returns ['tz'=>string|null, 'source'=>'explicit'|'province'|null, 'error'=>string|null].
 */
function pgcsv_resolve_timezone(string $raw, string $province = ''): array {
    $raw = trim($raw);
    if ($raw !== '') {
        $key = strtolower(preg_replace('/[\s_\-]+/', '', $raw));
        $aliases = [
            'americatoronto' => 'America/Toronto', 'toronto' => 'America/Toronto',
            'eastern' => 'America/Toronto', 'et' => 'America/Toronto',
            'est' => 'America/Toronto', 'edt' => 'America/Toronto',
            'americavancouver' => 'America/Vancouver', 'vancouver' => 'America/Vancouver',
            'pacific' => 'America/Vancouver', 'pt' => 'America/Vancouver',
            'pst' => 'America/Vancouver', 'pdt' => 'America/Vancouver',
            'americaedmonton' => 'America/Edmonton', 'edmonton' => 'America/Edmonton',
            'calgary' => 'America/Edmonton', 'mountain' => 'America/Edmonton',
            'mt' => 'America/Edmonton', 'mst' => 'America/Edmonton', 'mdt' => 'America/Edmonton',
            'americawinnipeg' => 'America/Winnipeg', 'winnipeg' => 'America/Winnipeg',
            'central' => 'America/Winnipeg', 'ct' => 'America/Winnipeg',
            'cst' => 'America/Winnipeg', 'cdt' => 'America/Winnipeg',
            'americaregina' => 'America/Regina', 'regina' => 'America/Regina',
            'saskatchewan' => 'America/Regina',
            'americahalifax' => 'America/Halifax', 'halifax' => 'America/Halifax',
            'atlantic' => 'America/Halifax', 'at' => 'America/Halifax',
            'ast' => 'America/Halifax', 'adt' => 'America/Halifax',
            'americamoncton' => 'America/Moncton', 'moncton' => 'America/Moncton',
            'americastjohns' => 'America/St_Johns', 'stjohns' => 'America/St_Johns',
            'newfoundland' => 'America/St_Johns', 'nlt' => 'America/St_Johns',
            'asiakarachi' => 'Asia/Karachi', 'karachi' => 'Asia/Karachi',
            'islamabad' => 'Asia/Karachi', 'pkt' => 'Asia/Karachi',
            'asiadubai' => 'Asia/Dubai', 'dubai' => 'Asia/Dubai', 'uae' => 'Asia/Dubai',
        ];
        $tz = $aliases[$key] ?? $raw;
        try {
            new DateTimeZone($tz);
            return ['tz' => $tz, 'source' => 'explicit', 'error' => null];
        } catch (Exception $e) {
            return ['tz' => null, 'source' => null, 'error' => "Timezone '$raw' is not recognized (use e.g. America/Toronto)."];
        }
    }
    $from_prov = pgcsv_province_timezone($province);
    if ($from_prov !== null) {
        return ['tz' => $from_prov, 'source' => 'province', 'error' => null];
    }
    return [
        'tz' => null,
        'source' => null,
        'error' => 'Timezone is required (e.g. America/Toronto), or set Province so it can be inferred (ON→America/Toronto).',
    ];
}

/**
 * Build clock_in / clock_out wall times in the site timezone.
 * Returns ['clock_in'=>Y-m-d H:i:s, 'clock_out'=>Y-m-d H:i:s, 'hours'=>float] or null.
 */
function pgcsv_site_local_span(string $work_date, string $tin, string $tout, string $tz_name): ?array {
    try {
        $tz = new DateTimeZone($tz_name);
    } catch (Exception $e) {
        return null;
    }
    $in = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $work_date . ' ' . $tin, $tz);
    $out = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $work_date . ' ' . $tout, $tz);
    if (!$in || !$out) return null;
    if ($out <= $in) {
        $out = $out->modify('+1 day');
    }
    $hours = round(($out->getTimestamp() - $in->getTimestamp()) / 3600, 2);
    return [
        'clock_in' => $in->format('Y-m-d H:i:s'),
        'clock_out' => $out->format('Y-m-d H:i:s'),
        'hours' => $hours,
    ];
}

/** Core hours import — customer SiteName + session org; admin may pass SiteID/OrgID. */
function pg_import_hours_rows(PDO $pdo, array $rows, array $sess, bool $dry_run): array {
    $errors = [];
    $warnings = [];
    pgcsv_normalize_hours_aliases($rows);

    if (!$rows) {
        return ['errors' => ['No hours rows to import.'], 'inserted' => 0, 'preview' => []];
    }

    $is_super = ((int)$sess['user_level'] <= 10);
    $sess_org = (int)$sess['org_id'];

    $sites = [];
    if (!function_exists('sip_site_status_is_active')) {
        require_once __DIR__ . '/engine/site_status_lib.php';
    }
    foreach ($pdo->query("SELECT SId, org_id, timezone_id, Timezone, status FROM sites") as $s) {
        $tz = trim((string)($s['timezone_id'] ?? ''));
        if ($tz === '') $tz = trim((string)($s['Timezone'] ?? ''));
        if ($tz === '') $tz = 'America/Toronto';
        $sites[(int)$s['SId']] = [
            'org_id' => (int)$s['org_id'],
            'tz' => $tz,
            'active' => sip_site_status_is_active($s['status'] ?? null),
        ];
    }
    $name_indexes = [];

    $to_insert = [];
    $rate_updates = [];
    $preview = [];
    $batch_punches = [];

    foreach ($rows as $r) {
        $L = "Line {$r['_line']}";

        $org_raw = trim((string)($r['orgid'] ?? ''));
        $org = $org_raw !== '' ? (int)$org_raw : $sess_org;
        if ($org <= 0) {
            $errors[] = "$L: OrgID is missing (customer sheets use your logged-in organization).";
            continue;
        }
        if (!$is_super && $org !== $sess_org) {
            $errors[] = "$L: OrgID $org does not match your organization ($sess_org).";
            continue;
        }

        $work_date_raw = trim((string)($r['date'] ?? ''));
        $work_date = pgcsv_normalize_date_string($work_date_raw);
        if ($work_date === null || !strtotime($work_date)) {
            $errors[] = "$L: WorkDate '$work_date_raw' must be YYYY-MM-DD or M/D/YYYY.";
            continue;
        }

        // Resolve site: SiteName preferred, else SiteID
        $site = 0;
        $site_id_raw = trim((string)($r['siteid'] ?? ''));
        $site_name = trim((string)($r['sitename'] ?? ''));
        if ($site_name !== '') {
            if (!isset($name_indexes[$org])) {
                $name_indexes[$org] = pgcsv_site_name_index($pdo, $org);
            }
            $resolved = pgcsv_resolve_site_name($site_name, $name_indexes[$org], $L, $errors);
            if ($resolved === null) continue;
            $site = $resolved;
        } elseif ($site_id_raw !== '') {
            $site = (int)$site_id_raw;
        } else {
            $errors[] = "$L: SiteName is required (or SiteID for admin sheets).";
            continue;
        }
        if (!isset($sites[$site]) || $sites[$site]['org_id'] !== $org) {
            $errors[] = "$L: Site #$site does not belong to OrgID $org.";
            continue;
        }
        if (empty($sites[$site]['active'])) {
            $errors[] = "$L: Site #$site is Inactive. Reactivate it on Manage Sites or import hours to an active site.";
            continue;
        }
        $site_tz = $sites[$site]['tz'];

        $email = strtolower(trim((string)($r['email'] ?? '')));
        $ename = trim((string)($r['employeename'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "$L: EmployeeEmail is required and must be a valid email.";
            continue;
        }

        $user = null;
        $how = 'email';
        $q = $pdo->prepare("SELECT id, Name, hourly_rate FROM users WHERE LOWER(TRIM(email)) = ? AND org_id = ?");
        $q->execute([$email, $org]);
        $m = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($m) > 1) {
            $errors[] = "$L: Email $email matches multiple employees in OrgID $org.";
            continue;
        }
        if (count($m) === 1) {
            $user = $m[0];
        } elseif ($ename !== '') {
            $q = $pdo->prepare("SELECT id, Name, hourly_rate FROM users WHERE TRIM(Name) = ? AND org_id = ?");
            $q->execute([$ename, $org]);
            $m = $q->fetchAll(PDO::FETCH_ASSOC);
            if (count($m) > 1) {
                $errors[] = "$L: Name '$ename' matches multiple employees; use EmployeeEmail.";
                continue;
            }
            if (count($m) === 1) {
                $user = $m[0];
                $how = 'name';
            }
        }
        if (!$user) {
            $errors[] = "$L: No employee found for $email" . ($ename !== '' ? " / '$ename'" : '') . " in OrgID $org. Import employees first.";
            continue;
        }

        $in_raw = trim((string)($r['clockintime'] ?? ''));
        $out_raw = trim((string)($r['clockouttime'] ?? ''));
        $reg_raw = trim((string)($r['hours'] ?? ''));
        $ot_raw = trim((string)($r['othours'] ?? ''));

        if ($out_raw !== '' && $in_raw === '') {
            $errors[] = "$L: ClockOut is set without ClockIn. Provide both punch times, or leave both blank and use RegularHours.";
            continue;
        }

        $mode_punch = ($in_raw !== '' && $out_raw !== '');
        $mode_hours = ($reg_raw !== '');

        if (!$mode_punch && !$mode_hours) {
            $errors[] = "$L: Provide ClockIn+ClockOut OR RegularHours (see NOTE in the template).";
            continue;
        }

        $ot_h = 0.0;
        if ($ot_raw !== '') {
            if (!is_numeric($ot_raw) || (float)$ot_raw < 0 || (float)$ot_raw > 24) {
                $errors[] = "$L: OTHours '$ot_raw' must be a number from 0 to 24.";
                continue;
            }
            $ot_h = round((float)$ot_raw, 2);
        }

        $source = 'hours';
        $warn = '';
        if ($mode_punch) {
            $tin = pgcsv_parse_clock_time($in_raw);
            $tout = pgcsv_parse_clock_time($out_raw);
            if ($tin === null) {
                $errors[] = "$L: ClockIn '$in_raw' must be HH:MM (24-hour).";
                continue;
            }
            if ($tout === null) {
                $errors[] = "$L: ClockOut '$out_raw' must be HH:MM (24-hour).";
                continue;
            }
            // Interpret WorkDate + punches in the site timezone (not server TZ).
            $span = pgcsv_site_local_span($work_date, $tin, $tout, $site_tz);
            if ($span === null) {
                $errors[] = "$L: Could not parse ClockIn/ClockOut against WorkDate in site timezone $site_tz.";
                continue;
            }
            $clock_in = $span['clock_in'];
            $clock_out = $span['clock_out'];
            $hours = $span['hours'];
            if ($hours <= 0 || $hours > 24) {
                $errors[] = "$L: Punch span computes to {$hours}h — must be between 0 and 24.";
                continue;
            }
            $source = 'punches';
            if ($mode_hours) {
                $declared = round((float)$reg_raw + $ot_h, 2);
                if (abs($declared - $hours) > 0.05) {
                    $warn = "Punches used ({$hours}h); sheet RegularHours+OT={$declared}h ignored.";
                    $warnings[] = "$L: $warn";
                }
            }
            // OT from sheet is informational when punches drive total
            $reg_h = $hours;
            $ot_note = $ot_h > 0 ? " OTHours(sheet)={$ot_h}" : '';
        } else {
            if (!is_numeric($reg_raw) || (float)$reg_raw <= 0 || (float)$reg_raw > 24) {
                $errors[] = "$L: RegularHours '$reg_raw' must be a positive number up to 24.";
                continue;
            }
            $reg_h = round((float)$reg_raw, 2);
            $hours = round($reg_h + $ot_h, 2);
            if ($hours > 24) {
                $errors[] = "$L: RegularHours+OTHours = {$hours} exceeds 24.";
                continue;
            }
            // Synthetic span starting 09:00 site-local (no customer clock times)
            $secs = (int)round($hours * 3600);
            try {
                $tz_obj = new DateTimeZone($site_tz);
                $in_dt = new DateTimeImmutable($work_date . ' 09:00:00', $tz_obj);
                $out_dt = $in_dt->modify('+' . $secs . ' seconds');
                $clock_in = $in_dt->format('Y-m-d H:i:s');
                $clock_out = $out_dt->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                $clock_in = $work_date . ' 09:00:00';
                $clock_out = date('Y-m-d H:i:s', strtotime($clock_in) + $secs);
            }
            $ot_note = $ot_h > 0 ? " OT={$ot_h}" : '';
        }

        $wage_raw = trim((string)($r['wagerate'] ?? ''));
        $att_rate = 0.0;
        $rate_note = '';
        if ($wage_raw !== '') {
            if (!is_numeric($wage_raw) || (float)$wage_raw <= 0) {
                $errors[] = "$L: WageRate '$wage_raw' must be a positive number (or blank to use system rate).";
                continue;
            }
            $att_rate = round((float)$wage_raw, 2);
            $sys = (float)($user['hourly_rate'] ?? 0);
            if ($sys > 0 && abs($sys - $att_rate) > 0.009) {
                $rate_note = " WageRate {$att_rate} vs system {$sys}";
                $rate_updates[(int)$user['id']] = $att_rate;
            }
        }

        $pkey = $user['id'] . '|' . $clock_in;
        if (isset($batch_punches[$pkey])) {
            $errors[] = "$L: Duplicate row in this file (same employee and start {$clock_in}).";
            continue;
        }
        $batch_punches[$pkey] = true;
        $q = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND clock_in = ? LIMIT 1");
        $q->execute([$user['id'], $clock_in]);
        if ($q->fetch()) {
            $errors[] = "$L: An attendance record already exists for {$user['Name']} at $clock_in.";
            continue;
        }

        $notes = trim((string)($r['remarks'] ?? ''));
        $remarks = 'CSV Hours Import ' . date('Y-m-d H:i') . " | source={$source} total={$hours}h";
        if ($source === 'hours') {
            $remarks .= " reg={$reg_h}{$ot_note}";
        } else {
            $remarks .= $ot_note;
        }
        if ($warn !== '') $remarks .= ' | ' . $warn;
        if ($rate_note !== '') $remarks .= ' |' . $rate_note;
        if ($notes !== '') $remarks .= ' | ' . $notes;

        $to_insert[] = [
            (int)$user['id'], $site, $org, $clock_in, $clock_out, $remarks, $att_rate,
        ];
        $preview[] = [
            'line' => $r['_line'],
            'label' => $user['Name'] . " (match: $how)",
            'detail' => ($source === 'punches' ? "punches $clock_in → $clock_out" : "hours-only")
                . " = {$hours}h at site $site ($site_tz)"
                . ($warn !== '' ? " [$warn]" : ''),
        ];
    }

    if ($errors) return ['errors'=>$errors,'inserted'=>0,'preview'=>$preview];
    if ($dry_run) {
        if ($warnings) {
            foreach ($warnings as $w) {
                $preview[] = ['line' => 0, 'label' => 'Warning', 'detail' => $w];
            }
        }
        return ['errors'=>[],'inserted'=>0,'preview'=>$preview];
    }

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            "INSERT INTO attendance (user_id, site_id, org_id, clock_in, clock_out, status, Remarks, payout_status, hourly_rate)
             VALUES (?, ?, ?, ?, ?, '4', ?, 0, ?)"
        );
        foreach ($to_insert as $row) {
            $ins->execute($row);
        }
        if ($rate_updates) {
            $upd = $pdo->prepare("UPDATE users SET hourly_rate = ? WHERE id = ?");
            foreach ($rate_updates as $uid => $rate) {
                $upd->execute([$rate, $uid]);
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['errors'=>["Database error, nothing was imported: " . $e->getMessage()],'inserted'=>0,'preview'=>$preview];
    }
    return ['errors'=>[],'inserted'=>count($to_insert),'preview'=>$preview];
}

/** Copy customer hour aliases onto canonical keys when present. */
function pgcsv_normalize_hours_aliases(array &$rows): void {
    foreach ($rows as &$r) {
        if ((!isset($r['date']) || $r['date'] === '') && isset($r['workdate']) && $r['workdate'] !== '') {
            $r['date'] = $r['workdate'];
        }
        if ((!isset($r['email']) || $r['email'] === '') && isset($r['employeeemail']) && $r['employeeemail'] !== '') {
            $r['email'] = $r['employeeemail'];
        }
        if ((!isset($r['employeename']) || $r['employeename'] === '') && isset($r['employeefullname']) && $r['employeefullname'] !== '') {
            $r['employeename'] = $r['employeefullname'];
        }
        if ((!isset($r['hours']) || $r['hours'] === '') && isset($r['regularhours']) && $r['regularhours'] !== '') {
            $r['hours'] = $r['regularhours'];
        }
        if ((!isset($r['clockintime']) || $r['clockintime'] === '') && isset($r['clockin']) && $r['clockin'] !== '') {
            $r['clockintime'] = $r['clockin'];
        }
        if ((!isset($r['clockouttime']) || $r['clockouttime'] === '') && isset($r['clockout']) && $r['clockout'] !== '') {
            $r['clockouttime'] = $r['clockout'];
        }
        if ((!isset($r['remarks']) || $r['remarks'] === '') && isset($r['notes']) && $r['notes'] !== '') {
            $r['remarks'] = $r['notes'];
        }
    }
    unset($r);
}

/**
 * Import / upsert sites (customer sheet — OrgID = session).
 * Matches existing site by nick_name within org; updates WSIB + identity fields.
 */
function pg_import_sites_rows(PDO $pdo, array $rows, array $sess, bool $dry_run): array {
    $errors = [];
    $preview = [];
    $to_upsert = [];
    $is_super = ((int)$sess['user_level'] <= 10);
    $sess_org = (int)$sess['org_id'];

    foreach ($rows as $r) {
        $L = "Line {$r['_line']}";
        $name = trim((string)($r['sitename'] ?? ''));
        if ($name === '') {
            $errors[] = "$L: SiteName is required.";
            continue;
        }
        $org = $sess_org;
        if ($org <= 0) {
            $errors[] = "$L: No organization on your session.";
            continue;
        }

        $legal = trim((string)($r['businesslegalname'] ?? ''));
        $bn15 = strtoupper(preg_replace('/[\s\-]/', '', (string)($r['bn15'] ?? '')));
        if ($bn15 !== '' && !preg_match('/^\d{9}RP\d{4}$/', $bn15)) {
            $errors[] = "$L: BN15 '$bn15' must look like 123456789RP0001 (or leave blank).";
            continue;
        }
        $street = trim((string)($r['streetaddress'] ?? ''));
        $city = trim((string)($r['city'] ?? ''));
        $prov = strtoupper(trim((string)($r['province'] ?? '')));
        if ($prov !== '' && strlen($prov) > 2) $prov = substr($prov, 0, 2);
        $tz_raw = trim((string)($r['timezone'] ?? $r['timezoneid'] ?? ''));
        $tz_res = pgcsv_resolve_timezone($tz_raw, $prov);
        $postal = strtoupper(trim((string)($r['postalcode'] ?? '')));
        $wsib_n = trim((string)($r['wsibnumber'] ?? ''));
        $wsib_p_raw = trim((string)($r['wsibpercent'] ?? ''));
        $wsib_p = null;
        if ($wsib_p_raw !== '') {
            if (!is_numeric($wsib_p_raw) || (float)$wsib_p_raw < 0 || (float)$wsib_p_raw > 100) {
                $errors[] = "$L: WSIBPercent '$wsib_p_raw' must be 0–100.";
                continue;
            }
            $wsib_p = round((float)$wsib_p_raw, 3);
        }
        $active_raw = trim((string)($r['active'] ?? ''));
        $status = 'Active';
        if ($active_raw !== '') {
            $on = pgcsv_parse_boolish($active_raw, true);
            $status = $on ? 'Active' : 'Inactive';
        }
        $notes = trim((string)($r['notes'] ?? ''));

        $q = $pdo->prepare("SELECT SId, timezone_id, Timezone FROM sites WHERE org_id = ? AND LOWER(TRIM(nick_name)) = ? LIMIT 1");
        $q->execute([$org, strtolower($name)]);
        $existing_row = $q->fetch(PDO::FETCH_ASSOC);
        $existing = $existing_row ? (int)$existing_row['SId'] : 0;
        $action = $existing ? 'update' : 'insert';

        // New sites require Timezone (or Province fallback). Updates may keep existing TZ when blank.
        $tz = $tz_res['tz'];
        $set_tz = true;
        if ($tz_res['error'] !== null) {
            if ($action === 'insert') {
                $errors[] = "$L: " . $tz_res['error'];
                continue;
            }
            $set_tz = false;
            $exist_tz = trim((string)($existing_row['timezone_id'] ?? ''));
            if ($exist_tz === '') $exist_tz = trim((string)($existing_row['Timezone'] ?? ''));
            $tz = $exist_tz !== '' ? $exist_tz : 'America/Toronto';
        }

        $to_upsert[] = [
            'action' => $action,
            'SId' => $existing,
            'nick_name' => $name,
            'corp_legal_name' => $legal !== '' ? $legal : null,
            'payroll_account' => $bn15 !== '' ? $bn15 : null,
            'street_address' => $street !== '' ? $street : null,
            'city' => $city !== '' ? $city : null,
            'province' => $prov !== '' ? $prov : null,
            'state' => $prov !== '' ? $prov : null,
            'timezone_id' => $tz,
            'set_tz' => $set_tz,
            'zipcode' => $postal !== '' ? $postal : null,
            'wsib_number' => $wsib_n !== '' ? $wsib_n : null,
            'wsib_percent' => $wsib_p,
            'status' => $status,
            'org_id' => $org,
            'notes' => $notes,
        ];
        $tz_note = !$set_tz ? ' (unchanged)' : ($tz_res['source'] === 'province' ? " (from Province $prov)" : '');
        $preview[] = [
            'line' => $r['_line'],
            'label' => $name,
            'detail' => strtoupper($action) . " org $org; TZ $tz{$tz_note}"
                . ($wsib_n !== '' ? "; WSIB# $wsib_n" : '')
                . ($wsib_p !== null ? "; WSIB% $wsib_p" : '')
                . ($bn15 !== '' ? "; BN15 $bn15" : ''),
        ];
    }

    if ($errors) return ['errors'=>$errors,'inserted'=>0,'preview'=>$preview];
    if ($dry_run) return ['errors'=>[],'inserted'=>0,'preview'=>$preview];

    $pdo->beginTransaction();
    try {
        // Persist both timezone_id (forms) and Timezone (kiosk / finalize_shift).
        $ins = $pdo->prepare(
            "INSERT INTO sites (nick_name, corp_legal_name, payroll_account, street_address, city, province, state, zipcode,
                                wsib_number, wsib_percent, status, org_id, timezone_id, Timezone, country, module)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'CA', 'clocking')"
        );
        $upd = $pdo->prepare(
            "UPDATE sites SET corp_legal_name=COALESCE(?, corp_legal_name), payroll_account=COALESCE(?, payroll_account),
                    street_address=COALESCE(?, street_address), city=COALESCE(?, city),
                    province=COALESCE(?, province), state=COALESCE(?, state), zipcode=COALESCE(?, zipcode),
                    wsib_number=COALESCE(?, wsib_number), wsib_percent=COALESCE(?, wsib_percent),
                    status=COALESCE(?, status)
             WHERE SId=? AND org_id=?"
        );
        $upd_tz = $pdo->prepare(
            "UPDATE sites SET corp_legal_name=COALESCE(?, corp_legal_name), payroll_account=COALESCE(?, payroll_account),
                    street_address=COALESCE(?, street_address), city=COALESCE(?, city),
                    province=COALESCE(?, province), state=COALESCE(?, state), zipcode=COALESCE(?, zipcode),
                    wsib_number=COALESCE(?, wsib_number), wsib_percent=COALESCE(?, wsib_percent),
                    status=COALESCE(?, status), timezone_id=?, Timezone=?
             WHERE SId=? AND org_id=?"
        );
        $n = 0;
        foreach ($to_upsert as $s) {
            if ($s['action'] === 'insert') {
                $ins->execute([
                    $s['nick_name'], $s['corp_legal_name'], $s['payroll_account'], $s['street_address'],
                    $s['city'], $s['province'], $s['state'], $s['zipcode'],
                    $s['wsib_number'], $s['wsib_percent'], $s['status'], $s['org_id'],
                    $s['timezone_id'], $s['timezone_id'],
                ]);
            } elseif ($s['set_tz']) {
                $upd_tz->execute([
                    $s['corp_legal_name'], $s['payroll_account'], $s['street_address'], $s['city'],
                    $s['province'], $s['state'], $s['zipcode'], $s['wsib_number'], $s['wsib_percent'],
                    $s['status'], $s['timezone_id'], $s['timezone_id'], $s['SId'], $s['org_id'],
                ]);
            } else {
                $upd->execute([
                    $s['corp_legal_name'], $s['payroll_account'], $s['street_address'], $s['city'],
                    $s['province'], $s['state'], $s['zipcode'], $s['wsib_number'], $s['wsib_percent'],
                    $s['status'], $s['SId'], $s['org_id'],
                ]);
            }
            $n++;
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['errors'=>["Database error, nothing was imported: " . $e->getMessage()],'inserted'=>0,'preview'=>$preview];
    }
    return ['errors'=>[],'inserted'=>$n,'preview'=>$preview];
}
