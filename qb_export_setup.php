<?php
/**
 * Path: /quickbooks/qb_export_lib.php
 * PURPOSE: Shared QuickBooks export engine.
 *   - Parse an uploaded sample template to learn a customer's columns/format.
 *   - Turn finalized payroll into BALANCED double-entry journal lines.
 *   - Write those lines as either a QuickBooks Online journal CSV (matching the
 *     customer's uploaded column layout) or a QuickBooks Desktop .IIF file.
 *
 * Used by qb_export_setup.php (parse + preview) and payroll_master_ledger.php (export).
 *
 * IMPORTANT: every journal entry this produces nets debits = credits. The optional
 * pieces (employer taxes, WCB, vacation) are always added as balanced pairs, so omitting
 * an account simply drops a balanced pair and the entry still balances.
 *
 * The accounting model per pay group (one employee, one pay period):
 *   Dr Gross Wages                       = gross
 *   Dr Employer CPP+EI Expense           = er_cpp + er_ei      (only if mapped)
 *   Dr WCB Expense                       = wcb                 (only if WCB pair mapped)
 *   Dr Vacation Expense                  = vac                 (only if Vacation pair mapped)
 *      Cr CPP Payable                    = ee_cpp (+ er_cpp if employer side on)
 *      Cr EI Payable                     = ee_ei  (+ er_ei  if employer side on)
 *      Cr Income Tax Payable             = ee_tax
 *      Cr WCB Payable                    = wcb
 *      Cr Vacation Payable               = vac
 *      Cr Net Pay / Bank                 = gross - ee_cpp - ee_ei - ee_tax
 */

/** Payroll components a customer maps to their own chart-of-accounts names. */
function qbx_components(): array {
    return [
        'wages_expense'       => 'Gross wages — expense account (Debit)',
        'net_pay_bank'        => 'Net pay — bank or payroll clearing account (Credit)',
        'cpp_payable'         => 'CPP payable — liability (Credit)',
        'ei_payable'          => 'EI payable — liability (Credit)',
        'tax_payable'         => 'Income tax payable — liability (Credit)',
        'payroll_tax_expense' => 'Employer CPP + EI — expense account (Debit). Leave blank to exclude the employer side entirely.',
        'wcb_expense'         => 'WCB / WSIB — expense account (Debit). Optional; pair with the payable below.',
        'wcb_payable'         => 'WCB / WSIB payable — liability (Credit). Optional.',
        'vacation_expense'    => 'Vacation — expense account (Debit). Optional; pair with the payable below.',
        'vacation_payable'    => 'Vacation payable — liability (Credit). Optional.',
    ];
}

/** Column roles for a CSV template: which of the customer's columns means what. */
function qbx_csv_roles(): array {
    return [
        'journal_no'   => 'Journal / entry number',
        'journal_date' => 'Date',
        'account'      => 'Account name',
        'debit'        => 'Debit amount',
        'credit'       => 'Credit amount',
        'description'  => 'Description / memo',
        'name'         => 'Name (employee or payee)',
        'class'        => 'Class / location (optional)',
    ];
}

/** Detect delimiter from a header line (comma, tab, or semicolon). */
function qbx_detect_delimiter(string $line): string {
    $counts = [',' => substr_count($line, ','), "\t" => substr_count($line, "\t"), ';' => substr_count($line, ';')];
    arsort($counts);
    $best = array_key_first($counts);
    return $counts[$best] > 0 ? $best : ',';
}

/**
 * Parse an uploaded template file. Returns:
 *   ['format_type' => 'qbo_csv'|'iif', 'delimiter' => ',', 'headers' => [...], 'note' => '']
 */
function qbx_parse_template(string $path, string $filename): array {
    $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $text = file_get_contents($path);
    if ($text === false) {
        return ['format_type' => 'qbo_csv', 'delimiter' => ',', 'headers' => [], 'note' => 'Could not read the uploaded file.'];
    }
    // strip BOM
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $lines = preg_split('/\r\n|\r|\n/', $text);

    // IIF detection: extension .iif or a leading !TRNS / !HDR / !ACCNT marker
    $first_nonblank = '';
    foreach ($lines as $l) { if (trim($l) !== '') { $first_nonblank = $l; break; } }
    $looks_iif = ($ext === 'iif') || (strpos($first_nonblank, '!') === 0);

    if ($looks_iif) {
        return ['format_type' => 'iif', 'delimiter' => "\t", 'headers' => [],
                'note' => 'Detected QuickBooks Desktop IIF. Column layout is fixed by the IIF spec; only the account mapping below is needed.'];
    }

    // CSV: header is the first non-blank line
    $delim   = qbx_detect_delimiter($first_nonblank);
    $headers = $first_nonblank !== '' ? str_getcsv($first_nonblank, $delim) : [];
    $headers = array_values(array_filter(array_map('trim', $headers), fn($h) => $h !== ''));

    return ['format_type' => 'qbo_csv', 'delimiter' => $delim, 'headers' => $headers,
            'note' => 'Detected a CSV journal template. Map each role to one of its columns below.'];
}

/** Build balanced journal lines for one pay group. Each line: account, debit, credit, name, memo. */
function qbx_journal_lines(array $g, array $amap): array {
    $get = fn($k) => isset($amap[$k]) ? trim((string)$amap[$k]) : '';
    $ee_cpp = (float)$g['ee_cpp']; $ee_ei = (float)$g['ee_ei']; $ee_tax = (float)$g['ee_tax'];
    $er_cpp = (float)$g['er_cpp']; $er_ei = (float)$g['er_ei'];
    $wcb    = (float)$g['wcb'];    $vac   = (float)$g['vac'];
    $gross  = (float)$g['gross'];
    $net    = max(0, $gross - $ee_cpp - $ee_ei - $ee_tax);

    $employer_on = ($get('payroll_tax_expense') !== '');
    $wcb_on      = ($get('wcb_expense') !== '' && $get('wcb_payable') !== '' && $wcb > 0.005);
    $vac_on      = ($get('vacation_expense') !== '' && $get('vacation_payable') !== '' && $vac > 0.005);

    $cpp_cr = $ee_cpp + ($employer_on ? $er_cpp : 0);
    $ei_cr  = $ee_ei  + ($employer_on ? $er_ei  : 0);

    $name = $g['name'] ?? '';
    $memo = $g['memo'] ?? '';
    $mk = fn($acct, $dr, $cr) => ['account' => $acct, 'debit' => round($dr, 2), 'credit' => round($cr, 2), 'name' => $name, 'memo' => $memo];

    $lines = [];
    if ($gross  > 0.005) $lines[] = $mk($get('wages_expense'), $gross, 0);
    if ($cpp_cr > 0.005) $lines[] = $mk($get('cpp_payable'), 0, $cpp_cr);
    if ($ei_cr  > 0.005) $lines[] = $mk($get('ei_payable'),  0, $ei_cr);
    if ($ee_tax > 0.005) $lines[] = $mk($get('tax_payable'), 0, $ee_tax);
    if ($net    > 0.005) $lines[] = $mk($get('net_pay_bank'), 0, $net);
    if ($employer_on && ($er_cpp + $er_ei) > 0.005) $lines[] = $mk($get('payroll_tax_expense'), $er_cpp + $er_ei, 0);
    if ($wcb_on) { $lines[] = $mk($get('wcb_expense'), $wcb, 0); $lines[] = $mk($get('wcb_payable'), 0, $wcb); }
    if ($vac_on) { $lines[] = $mk($get('vacation_expense'), $vac, 0); $lines[] = $mk($get('vacation_payable'), 0, $vac); }

    return $lines;
}

/** Sum of (debit - credit) across lines; should be ~0 for a balanced entry. */
function qbx_imbalance(array $lines): float {
    $s = 0.0; foreach ($lines as $l) { $s += (float)$l['debit'] - (float)$l['credit']; }
    return round($s, 2);
}

/**
 * Build the export file.
 *   $groups: list of ['name','date'(Y-m-d),'memo','journal_no', + component amounts]
 *   $profile: ['format_type','delimiter','headers'(JSON or array),'column_map'(JSON/array),'account_map'(JSON/array)]
 * Returns ['filename','mime','content','warnings'=>[]].
 */
function qbx_build_export(array $groups, array $profile): array {
    $fmt   = $profile['format_type'] ?? 'qbo_csv';
    $amap  = is_array($profile['account_map'] ?? null) ? $profile['account_map'] : (json_decode($profile['account_map'] ?? '[]', true) ?: []);
    $cmap  = is_array($profile['column_map'] ?? null)  ? $profile['column_map']  : (json_decode($profile['column_map'] ?? '[]', true)  ?: []);
    $heads = is_array($profile['headers'] ?? null)     ? $profile['headers']     : (json_decode($profile['template_headers'] ?? '[]', true) ?: []);

    $warnings = [];
    foreach ($groups as $i => &$g) {
        $g['journal_no'] = $g['journal_no'] ?? ('PR-' . date('Ymd', strtotime($g['date'] ?? 'now')) . '-' . ($i + 1));
        $lines = qbx_journal_lines($g, $amap);
        if (qbx_imbalance($lines) !== 0.0) {
            $warnings[] = "Entry for {$g['name']} ({$g['date']}) is out of balance by " . qbx_imbalance($lines) . " — check the mapped accounts.";
        }
        $g['_lines'] = $lines;
    }
    unset($g);

    if ($fmt === 'iif') {
        return ['filename' => 'QuickBooks_Payroll_' . date('Ymd') . '.iif', 'mime' => 'application/octet-stream',
                'content' => qbx_write_iif($groups), 'warnings' => $warnings];
    }
    return ['filename' => 'QuickBooks_Payroll_' . date('Ymd') . '.csv', 'mime' => 'text/csv; charset=utf-8',
            'content' => qbx_write_qbo_csv($groups, $heads, $cmap), 'warnings' => $warnings];
}

/** QBO journal CSV, written to match the customer's uploaded column layout. */
function qbx_write_qbo_csv(array $groups, array $headers, array $cmap): string {
    // role => header name, from cmap (role => header). Reverse for header => role.
    $header_for_role = $cmap; // role => header
    $sep_debit  = $header_for_role['debit']  ?? '';
    $sep_credit = $header_for_role['credit'] ?? '';
    $single_amount = ($sep_debit !== '' && $sep_credit === ''); // only a debit/amount column exists

    $fh = fopen('php://temp', 'r+');
    if (!empty($headers)) { fputcsv($fh, $headers); }

    foreach ($groups as $g) {
        foreach ($g['_lines'] as $ln) {
            // value per role
            $vals = [
                'journal_no'   => $g['journal_no'],
                'journal_date' => date('Y-m-d', strtotime($g['date'] ?? 'now')),
                'account'      => $ln['account'],
                'debit'        => $ln['debit'] > 0 ? number_format($ln['debit'], 2, '.', '') : '',
                'credit'       => $ln['credit'] > 0 ? number_format($ln['credit'], 2, '.', '') : '',
                'description'  => $ln['memo'],
                'name'         => $ln['name'],
                'class'        => $g['class'] ?? '',
            ];
            if ($single_amount) {
                $signed = $ln['debit'] - $ln['credit'];
                $vals['debit'] = number_format($signed, 2, '.', '');
            }
            // emit one cell per template header, in their order
            $row = [];
            foreach ($headers as $h) {
                $role = array_search($h, $header_for_role, true); // header -> role
                $row[] = ($role !== false && isset($vals[$role])) ? $vals[$role] : '';
            }
            // if template had no headers (shouldn't happen for CSV), fall back to a sane default
            if (empty($headers)) { $row = [$vals['journal_no'], $vals['journal_date'], $vals['account'], $vals['debit'], $vals['credit'], $vals['description'], $vals['name']]; }
            fputcsv($fh, $row);
        }
    }
    rewind($fh);
    $out = stream_get_contents($fh);
    fclose($fh);
    return $out;
}

/** QuickBooks Desktop IIF general-journal blocks (tab-delimited; Dr positive, Cr negative). */
function qbx_write_iif(array $groups): string {
    $clean = fn($s) => str_replace(["\t", "\r", "\n"], ' ', (string)$s);
    $rows = [];
    $rows[] = "!TRNS\tTRNSTYPE\tDATE\tACCNT\tNAME\tAMOUNT\tMEMO";
    $rows[] = "!SPL\tTRNSTYPE\tDATE\tACCNT\tNAME\tAMOUNT\tMEMO";
    $rows[] = "!ENDTRNS";

    foreach ($groups as $g) {
        $date = date('m/d/Y', strtotime($g['date'] ?? 'now'));
        $lines = $g['_lines'];
        $first = true;
        foreach ($lines as $ln) {
            $amt = number_format($ln['debit'] - $ln['credit'], 2, '.', ''); // Dr +, Cr -
            $tag = $first ? 'TRNS' : 'SPL';
            $rows[] = implode("\t", [$tag, 'GENERAL JOURNAL', $date, $clean($ln['account']), $clean($ln['name']), $amt, $clean($ln['memo'])]);
            $first = false;
        }
        $rows[] = 'ENDTRNS';
    }
    return implode("\r\n", $rows) . "\r\n";
}