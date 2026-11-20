<?php
/**
 * CRA T4 XML builder + XSD validation (2026 schema package).
 * Emits IFT-ready Submission: T619 + one Return/T4 per RP account.
 */
require_once __DIR__ . '/t4_lib.php';

if (!function_exists('t4_xml_schema_dir')) {

function t4_xml_schema_dir(): string {
    $candidates = [
        __DIR__ . '/cra_schemas/2026/SchemasEFV-Published - 202601',
        __DIR__ . '/cra_schemas/2026/xsd',
    ];
    foreach ($candidates as $dir) {
        if (is_file($dir . '/T619_T4.xsd')) {
            return $dir;
        }
    }
    return $candidates[0];
}

function t4_xml_money($amount, int $maxIntDigits = 10): ?string {
    $n = round((float)$amount, 2);
    if ($n < 0) {
        return null;
    }
    $s = number_format($n, 2, '.', '');
    [$int] = explode('.', $s);
    if (strlen(ltrim($int, '0') ?: '0') > $maxIntDigits) {
        return null;
    }
    return $s;
}

function t4_xml_clip(string $s, int $max): string {
    $s = trim($s);
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max);
    }
    return substr($s, 0, $max);
}

function t4_xml_clean_name(string $s): string {
    // Keep CRA name-acceptable chars; drop the rest.
    $s = str_replace(['&', '<', '>', '"', "'"], ['and', '', '', '', ''], $s);
    $s = preg_replace('/[^A-Za-z0-9éàâçèêëîïôùüûÉÀÂÇÈÊËÎÏÔÙÜÛ\'\.\- ]/u', '', $s);
    return trim(preg_replace('/\s+/', ' ', (string)$s));
}

function t4_xml_clean_addr(string $s): string {
    $s = str_replace(['&', '<', '>', '"'], ['and', '', '', ''], $s);
    $s = preg_replace('/[^A-Za-z0-9éàâçèêëîïôùüûÉÀÂÇÈÊËÎÏÔÙÜÛ\'\.\-\/#,\(\)\{\}\[\] ]/u', '', $s);
    return trim(preg_replace('/\s+/', ' ', (string)$s));
}

function t4_xml_phone_parts(string $raw): array {
    $d = preg_replace('/\D+/', '', $raw);
    if (strlen($d) === 11 && $d[0] === '1') {
        $d = substr($d, 1);
    }
    if (strlen($d) < 10) {
        return ['area' => '', 'local' => ''];
    }
    return [
        'area' => substr($d, 0, 3),
        'local' => substr($d, 3, 3) . '-' . substr($d, 6, 4),
    ];
}

function t4_xml_append(DOMDocument $dom, DOMElement $parent, string $name, $value = null, bool $optional = false): ?DOMElement {
    if ($optional) {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
    }
    $el = $dom->createElement($name);
    if ($value !== null && $value !== true) {
        $el->appendChild($dom->createTextNode((string)$value));
    }
    $parent->appendChild($el);
    return $el;
}

/**
 * @param array $groups from t4_group_by_rp (only non-blocking slips)
 * @param array $transmitter keys: channel, bn15, bn9, rep_id, name, contact_name, phone, email, lang, sbmt_ref_id, report_type
 */
function t4_xml_build(array $groups, int $year, array $transmitter): array {
    $errors = [];
    $channel = $transmitter['channel'] ?? 'myba';
    $bn15 = t4_normalize_bn15($transmitter['bn15'] ?? '');
    $bn9 = preg_replace('/\D+/', '', (string)($transmitter['bn9'] ?? ''));
    $repId = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($transmitter['rep_id'] ?? '')));
    $lang = strtoupper((string)($transmitter['lang'] ?? 'E')) === 'F' ? 'F' : 'E';
    $reportType = strtoupper((string)($transmitter['report_type'] ?? 'O'));
    if (!in_array($reportType, ['O', 'A', 'C'], true)) {
        $reportType = 'O';
    }
    $sbmt = preg_replace('/[^A-Za-z0-9]/', '', (string)($transmitter['sbmt_ref_id'] ?? ''));
    if ($sbmt === '') {
        $sbmt = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }
    $sbmt = substr($sbmt, 0, 8);

    $phone = t4_xml_phone_parts((string)($transmitter['phone'] ?? ''));
    $contactName = t4_xml_clean_name((string)($transmitter['contact_name'] ?? ''));
    $email = trim((string)($transmitter['email'] ?? ''));
    $trName = t4_xml_clean_name((string)($transmitter['name'] ?? ''));

    if ($contactName === '') {
        $errors[] = 'Transmitter contact name is required';
    }
    if ($phone['area'] === '' || $phone['local'] === '') {
        $errors[] = 'Transmitter phone must be a 10-digit North American number';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 60) {
        $errors[] = 'Transmitter contact email is required (max 60 chars)';
    }
    if ($trName === '') {
        $errors[] = 'Transmitter name is required';
    }

    $hasBn15 = t4_is_valid_bn15($bn15);
    $hasBn9 = (bool)preg_match('/^\d{9}$/', $bn9);
    $hasRep = $repId !== '' && strlen($repId) <= 7;

    if ($channel === 'rac') {
        if (!$hasRep) {
            $errors[] = 'RAC filing requires Transmitter RepID';
        }
        // Avoid empty TransmitterAccountNumber tags — include bn15 if provided, else first RP.
        if (!$hasBn15 && !$hasBn9) {
            foreach ($groups as $g) {
                if (t4_is_valid_bn15($g['payroll_account'] ?? '')) {
                    $bn15 = $g['payroll_account'];
                    $hasBn15 = true;
                    break;
                }
            }
        }
    } elseif ($channel === 'myba_bn9') {
        if (!$hasBn9) {
            $errors[] = 'MyBA BN9 transmitter account is required (9 digits)';
        }
    } else { // myba / wac bn15
        if (!$hasBn15) {
            $errors[] = 'Transmitter BN15 (#########RP####) is required for MyBA/WAC upload';
        }
    }

    $usable = [];
    foreach ($groups as $g) {
        $slips = [];
        foreach ($g['slips'] as $s) {
            if (!empty($s['issues'])) {
                $errors[] = 'Blocked slip: ' . ($s['Name'] ?? '?') . ' @ ' . ($g['site_name'] ?? '');
                continue;
            }
            $slips[] = $s;
        }
        if ($slips) {
            $g['slips'] = $slips;
            $usable[] = $g;
        }
    }
    if (!$usable) {
        $errors[] = 'No complete slips to export';
    }
    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'xml' => '', 'schema_errors' => []];
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;
    $submission = $dom->createElement('Submission');
    $dom->appendChild($submission);

    $t619 = $dom->createElement('T619');
    $submission->appendChild($t619);

    $acct = $dom->createElement('TransmitterAccountNumber');
    $t619->appendChild($acct);
    if ($hasBn15) {
        t4_xml_append($dom, $acct, 'bn15', $bn15);
    } elseif ($hasBn9) {
        t4_xml_append($dom, $acct, 'bn9', $bn9);
    }

    if ($hasRep) {
        $rep = $dom->createElement('TransmitterRepID');
        $t619->appendChild($rep);
        t4_xml_append($dom, $rep, 'RepID', $repId);
    }

    t4_xml_append($dom, $t619, 'sbmt_ref_id', $sbmt);
    t4_xml_append($dom, $t619, 'summ_cnt', (string)count($usable));
    t4_xml_append($dom, $t619, 'lang_cd', $lang);

    $tn = $dom->createElement('TransmitterName');
    $t619->appendChild($tn);
    t4_xml_append($dom, $tn, 'l1_nm', t4_xml_clip($trName, 35));

    t4_xml_append($dom, $t619, 'TransmitterCountryCode', 'CAN');

    $cntc = $dom->createElement('CNTC');
    $t619->appendChild($cntc);
    t4_xml_append($dom, $cntc, 'cntc_nm', t4_xml_clip($contactName, 35));
    t4_xml_append($dom, $cntc, 'cntc_area_cd', $phone['area']);
    t4_xml_append($dom, $cntc, 'cntc_phn_nbr', $phone['local']);
    t4_xml_append($dom, $cntc, 'cntc_email_area', t4_xml_clip($email, 60));

    foreach ($usable as $g) {
        $return = $dom->createElement('Return');
        $submission->appendChild($return);
        $t4 = $dom->createElement('T4');
        $return->appendChild($t4);

        $totInc = 0.0;
        $totCpp = 0.0;
        $totCpp2 = 0.0;
        $totEi = 0.0;
        $totTax = 0.0;
        $totErCpp = 0.0;
        $totErCpp2 = 0.0;
        $totErEi = 0.0;

        foreach ($g['slips'] as $s) {
            $slip = $dom->createElement('T4Slip');
            $t4->appendChild($slip);

            $nm = $dom->createElement('EMPE_NM');
            $slip->appendChild($nm);
            t4_xml_append($dom, $nm, 'snm', t4_xml_clip(t4_xml_clean_name($s['surname']), 20));
            $given = t4_xml_clip(t4_xml_clean_name($s['given_name']), 12);
            t4_xml_append($dom, $nm, 'gvn_nm', $given, true);

            $addr = $dom->createElement('EMPE_ADDR');
            $slip->appendChild($addr);
            t4_xml_append($dom, $addr, 'addr_l1_txt', t4_xml_clip(t4_xml_clean_addr($s['street_address']), 30), true);
            t4_xml_append($dom, $addr, 'cty_nm', t4_xml_clip(t4_xml_clean_addr($s['city']), 28), true);
            t4_xml_append($dom, $addr, 'prov_cd', t4_normalize_province($s['home_province']), true);
            t4_xml_append($dom, $addr, 'cntry_cd', 'CAN', true);
            t4_xml_append($dom, $addr, 'pstl_cd', t4_normalize_postal($s['postal_code']), true);

            t4_xml_append($dom, $slip, 'sin', t4_digits_only($s['sin']));
            t4_xml_append($dom, $slip, 'bn', $g['payroll_account']);
            t4_xml_append($dom, $slip, 'cpp_qpp_xmpt_cd', '0');
            t4_xml_append($dom, $slip, 'ei_xmpt_cd', '0');
            t4_xml_append($dom, $slip, 'rpt_tcd', $reportType === 'C' ? 'C' : ($reportType === 'A' ? 'A' : 'O'));
            t4_xml_append($dom, $slip, 'empt_prov_cd', t4_normalize_province($s['employment_province']));
            t4_xml_append($dom, $slip, 'empr_dntl_ben_rpt_cd', (string)(int)$s['dental_benefit_code']);

            $amt = $dom->createElement('T4_AMT');
            $slip->appendChild($amt);
            t4_xml_append($dom, $amt, 'empt_incamt', t4_xml_money($s['employment_income'], 8), true);
            t4_xml_append($dom, $amt, 'cpp_cntrb_amt', ((float)$s['cpp'] > 0 ? t4_xml_money($s['cpp'], 4) : null), true);
            t4_xml_append($dom, $amt, 'cppe_cntrb_amt', ((float)$s['cpp2'] > 0 ? t4_xml_money($s['cpp2'], 4) : null), true);
            t4_xml_append($dom, $amt, 'empe_eip_amt', ((float)$s['ei'] > 0 ? t4_xml_money($s['ei'], 4) : null), true);
            t4_xml_append($dom, $amt, 'itx_ddct_amt', ((float)$s['income_tax'] > 0 ? t4_xml_money($s['income_tax'], 8) : null), true);
            t4_xml_append($dom, $amt, 'ei_insu_ern_amt', t4_xml_money($s['box24'], 5));
            t4_xml_append($dom, $amt, 'cpp_qpp_ern_amt', t4_xml_money($s['box26'], 7));

            $totInc += (float)$s['employment_income'];
            $totCpp += (float)$s['cpp'];
            $totCpp2 += (float)$s['cpp2'];
            $totEi += (float)$s['ei'];
            $totTax += (float)$s['income_tax'];
            $totErCpp += (float)$s['cpp_er'];
            $totErEi += (float)$s['ei_er'];
        }

        $sum = $dom->createElement('T4Summary');
        $t4->appendChild($sum);
        t4_xml_append($dom, $sum, 'bn', $g['payroll_account']);

        $emprNm = $dom->createElement('EMPR_NM');
        $sum->appendChild($emprNm);
        t4_xml_append($dom, $emprNm, 'l1_nm', t4_xml_clip(t4_xml_clean_name($g['employer_name'] ?: $g['site_name']), 30));

        $emprAddr = $dom->createElement('EMPR_ADDR');
        $sum->appendChild($emprAddr);
        $first = $g['slips'][0];
        t4_xml_append($dom, $emprAddr, 'addr_l1_txt', t4_xml_clip(t4_xml_clean_addr($first['employer_addr']), 30), true);
        t4_xml_append($dom, $emprAddr, 'cty_nm', t4_xml_clip(t4_xml_clean_addr($first['employer_city']), 28), true);
        t4_xml_append($dom, $emprAddr, 'prov_cd', t4_normalize_province($g['employment_province']), true);
        t4_xml_append($dom, $emprAddr, 'cntry_cd', 'CAN', true);
        t4_xml_append($dom, $emprAddr, 'pstl_cd', t4_normalize_postal($first['employer_postal']), true);

        $sc = $dom->createElement('CNTC');
        $sum->appendChild($sc);
        t4_xml_append($dom, $sc, 'cntc_nm', t4_xml_clip($contactName, 22));
        t4_xml_append($dom, $sc, 'cntc_area_cd', $phone['area']);
        t4_xml_append($dom, $sc, 'cntc_phn_nbr', $phone['local']);

        t4_xml_append($dom, $sum, 'tx_yr', (string)$year);
        t4_xml_append($dom, $sum, 'slp_cnt', (string)count($g['slips']));
        t4_xml_append($dom, $sum, 'rpt_tcd', $reportType === 'A' ? 'A' : 'O');

        $tamt = $dom->createElement('T4_TAMT');
        $sum->appendChild($tamt);
        t4_xml_append($dom, $tamt, 'tot_empt_incamt', t4_xml_money($totInc, 11), true);
        t4_xml_append($dom, $tamt, 'tot_empe_cpp_amt', ($totCpp > 0 ? t4_xml_money($totCpp, 9) : null), true);
        t4_xml_append($dom, $tamt, 'tot_empe_cppe_amt', ($totCpp2 > 0 ? t4_xml_money($totCpp2, 9) : null), true);
        t4_xml_append($dom, $tamt, 'tot_empe_eip_amt', ($totEi > 0 ? t4_xml_money($totEi, 9) : null), true);
        t4_xml_append($dom, $tamt, 'tot_itx_ddct_amt', ($totTax > 0 ? t4_xml_money($totTax, 11) : null), true);
        t4_xml_append($dom, $tamt, 'tot_empr_cpp_amt', ($totErCpp > 0 ? t4_xml_money($totErCpp, 9) : null), true);
        t4_xml_append($dom, $tamt, 'tot_empr_cppe_amt', ($totErCpp2 > 0 ? t4_xml_money($totErCpp2, 9) : null), true);
        t4_xml_append($dom, $tamt, 'tot_empr_eip_amt', ($totErEi > 0 ? t4_xml_money($totErEi, 9) : null), true);
    }

    $xml = $dom->saveXML();
    $schema = t4_xml_validate($xml);
    return [
        'ok' => $schema['ok'],
        'errors' => $schema['ok'] ? [] : ['XSD validation failed — see schema errors'],
        'schema_errors' => $schema['errors'],
        'xml' => $xml,
        'sbmt_ref_id' => $sbmt,
        'summary_count' => count($usable),
        'slip_count' => array_sum(array_map(static fn($g) => count($g['slips']), $usable)),
    ];
}

function t4_xml_validate(string $xml): array {
    $schemaPath = t4_xml_schema_dir() . '/T619_T4.xsd';
    if (!is_file($schemaPath)) {
        return ['ok' => false, 'errors' => ['CRA schema T619_T4.xsd not found at ' . $schemaPath]];
    }

    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $dom = new DOMDocument();
    if (!$dom->loadXML($xml)) {
        $errs = array_map(static fn($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return ['ok' => false, 'errors' => $errs ?: ['XML parse failed']];
    }

    $ok = @$dom->schemaValidate($schemaPath);
    $errs = [];
    foreach (libxml_get_errors() as $e) {
        $errs[] = trim($e->message) . ' (line ' . $e->line . ')';
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return ['ok' => (bool)$ok, 'errors' => $errs];
}

} // function_exists
