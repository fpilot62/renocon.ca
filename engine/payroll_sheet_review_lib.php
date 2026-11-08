<?php
/**
 * Path: /engine/payroll_sheet_review_lib.php
 * Draft review of an imported payroll sheet. Saving a draft does not write
 * payroll_finalized or payroll_payments. An explicit approval can.
 * Uploaded files belong outside the web root. This file ships no workbook.
 */

require_once __DIR__ . '/payroll_cheque_lib.php';
require_once __DIR__ . '/payroll_payment_status_lib.php';

function psr_deferred_items(): array
{
    return [
        'A combined report that reprints the whole sheet beside every payroll screen.',
        'A calculate-net checkbox. CPP, EI, and tax need a verified year, employee, and year-to-date figures, which this page does not estimate.',
        'Email or SMS.',
        'Bank files, ADP sends, cheque files, and any money transfer.',
        'GST on employee cash wages. A cash mark in the source does not create a contractor invoice.',
        'Splitting a known net into CPP, EI, and income tax.',
        'Treating the Raheel screenshot as if the picture itself had been read. Proposal files are uploaded separately.',
        'Allocating unallocated cash or advances onto a pay period.',
        'Filling in a payment date, method, or amount from a note.',
    ];
}

function psr_parse_amount($raw): array
{
    if ($raw === null) {
        return ['ok' => true, 'value' => null];
    }
    if (is_int($raw) || is_float($raw)) {
        return ['ok' => true, 'value' => round((float)$raw, 2)];
    }
    $t = trim((string)$raw);
    if ($t === '' || strcasecmp($t, 'null') === 0 || $t === '-') {
        return ['ok' => true, 'value' => null];
    }
    $t = str_replace([',', '$', ' '], '', $t);
    if (!is_numeric($t)) {
        return ['ok' => false, 'value' => null, 'error' => 'not a number'];
    }
    return ['ok' => true, 'value' => round((float)$t, 2)];
}

function psr_normalize_status($raw): string
{
    $t = strtolower(trim((string)$raw));
    $t = str_replace(['_', '-'], ' ', $t);
    $t = preg_replace('/\s+/', ' ', (string)$t);
    if ($t === '' || $t === 'unknown' || $t === 'null' || $t === '?' || $t === 'n/a') {
        return 'unknown';
    }
    if ($t === 'paid' || $t === 'explicit paid') {
        return 'paid';
    }
    if ($t === 'not paid' || $t === 'unpaid') {
        return 'not_paid';
    }
    if ($t === 'partial' || $t === 'partially paid' || $t === 'partial paid') {
        return 'partial';
    }
    return 'unknown';
}

function psr_tri($state, $amount): array
{
    $state = strtolower(trim((string)$state));
    if ($state === '' || $state === 'unknown' || $state === 'pending') {
        return ['state' => 'unknown', 'amount' => null];
    }
    if ($state === 'none' || $state === 'not applicable' || $state === 'na') {
        return ['state' => 'none', 'amount' => 0.0];
    }
    if ($state === 'amount') {
        $parsed = psr_parse_amount($amount);
        if (!$parsed['ok'] || $parsed['value'] === null) {
            return ['state' => 'unknown', 'amount' => null];
        }
        return ['state' => 'amount', 'amount' => $parsed['value']];
    }
    return ['state' => 'unknown', 'amount' => null];
}

function psr_gst(array $row): array
{
    $party = strtolower(trim((string)($row['party_class'] ?? 'unanswered')));
    $verified = !empty($row['gst_verified']);
    if ($party !== 'contractor' || !$verified) {
        if ($party === 'unanswered') {
            $reason = 'Contractor or employee is unanswered. A cash mark does not establish GST.';
        } elseif ($party === 'contractor') {
            $reason = 'GST waits for an explicit verified taxable contractor invoice.';
        } else {
            $reason = 'GST is not applied to employee or cash wages.';
        }
        return ['state' => 'not_applied', 'amount' => null, 'reason' => $reason];
    }
    $base = psr_parse_amount($row['gst_invoice_principal'] ?? null);
    if (!$base['ok'] || $base['value'] === null) {
        return ['state' => 'pending', 'amount' => null, 'reason' => 'Verified contractor invoice principal is missing, so GST is pending.'];
    }
    return [
        'state' => 'amount',
        'amount' => round($base['value'] * 0.05, 2),
        'reason' => '5% of the verified contractor invoice principal. It is not a wage and it does not reduce the wage balance.',
    ];
}

function psr_employer_funding(array $row): array
{
    $route = strtolower(trim((string)($row['pay_route'] ?? 'unset')));
    $basis = strtolower(trim((string)($row['amount_basis'] ?? 'unset')));
    $proposed = psr_parse_amount($row['proposed_amount'] ?? null);
    $reasons = [];
    if (!in_array($route, ['cash', 'adp'], true) || !in_array($basis, ['gross', 'net'], true) || !$proposed['ok'] || $proposed['value'] === null) {
        $reasons[] = 'Proposed amount, cash/ADP route, or gross/net basis is missing. No total was assumed.';
    }
    $ee = psr_tri($row['ee_deduction_state'] ?? 'unknown', $row['ee_deduction_amount'] ?? null);
    $parts = [
        'er_cpp' => psr_tri($row['er_cpp_state'] ?? 'unknown', $row['er_cpp_amount'] ?? null),
        'er_ei' => psr_tri($row['er_ei_state'] ?? 'unknown', $row['er_ei_amount'] ?? null),
        'er_other' => psr_tri($row['er_other_state'] ?? 'unknown', $row['er_other_amount'] ?? null),
    ];
    foreach ($parts as $name => $part) {
        if ($part['state'] === 'unknown') {
            $reasons[] = str_replace('_', ' ', $name) . ' is unknown, so the employer total stays pending.';
        }
    }
    if ($basis === 'net' && $ee['state'] === 'unknown') {
        $reasons[] = 'Employee deductions are unknown, so a net proposal cannot be turned into ADP gross funding.';
    }
    $gst = psr_gst($row);
    if ($gst['state'] === 'pending') {
        $reasons[] = $gst['reason'];
    }
    if ($reasons) {
        return ['state' => 'pending', 'amount' => null, 'reasons' => $reasons, 'gst' => $gst, 'ee' => $ee];
    }
    $wage = (float)$proposed['value'];
    if ($basis === 'net' && $ee['state'] === 'amount') {
        $wage = round($wage + (float)$ee['amount'], 2);
    }
    $extra = 0.0;
    foreach ($parts as $part) {
        if ($part['state'] === 'amount') {
            $extra = round($extra + (float)$part['amount'], 2);
        }
    }
    if ($gst['state'] === 'amount') {
        $extra = round($extra + (float)$gst['amount'], 2);
    }
    return [
        'state' => 'amount',
        'amount' => round($wage + $extra, 2),
        'wage_funding' => $wage,
        'reasons' => [],
        'gst' => $gst,
        'ee' => $ee,
        'note' => $basis === 'gross'
            ? 'Gross proposal already includes employee deductions, so those deductions were not added again.'
            : 'Net proposal plus verified employee deductions, then employer CPP/EI and other verified costs.',
    ];
}

function psr_balance_preview(array $row, $principalKnown, $principalAmount): array
{
    $live = ['state' => $principalKnown ? 'amount' : 'pending', 'amount' => $principalKnown ? round((float)$principalAmount, 2) : null];
    $basis = strtolower(trim((string)($row['amount_basis'] ?? 'unset')));
    $proposed = psr_parse_amount($row['proposed_amount'] ?? null);
    $ee = psr_tri($row['ee_deduction_state'] ?? 'unknown', $row['ee_deduction_amount'] ?? null);
    $gst = psr_gst($row);
    $reduction = ['state' => 'pending', 'amount' => null, 'reason' => 'Proposed amount or its gross/net basis is missing.'];
    if ($proposed['ok'] && $proposed['value'] !== null && $basis === 'net') {
        $reduction = ['state' => 'amount', 'amount' => $proposed['value'], 'reason' => 'Net proposal reduces the wage preview only.'];
    } elseif ($proposed['ok'] && $proposed['value'] !== null && $basis === 'gross') {
        if ($ee['state'] === 'unknown') {
            $reduction = ['state' => 'pending', 'amount' => null, 'reason' => 'Gross proposal needs the employee deduction total before it can change a net wage preview.'];
        } elseif ($ee['state'] === 'none') {
            $reduction = ['state' => 'amount', 'amount' => $proposed['value'], 'reason' => 'Gross equals net because employee deductions are explicitly none.'];
        } else {
            $netPortion = round((float)$proposed['value'] - (float)$ee['amount'], 2);
            if ($netPortion < -0.009) {
                $reduction = ['state' => 'pending', 'amount' => null, 'reason' => 'Employee deductions are larger than the gross proposal.'];
            } else {
                $reduction = ['state' => 'amount', 'amount' => $netPortion, 'reason' => 'Only the net portion of a gross proposal is in the wage preview.'];
            }
        }
    }
    $preview = ['state' => 'pending', 'amount' => null];
    if ($live['state'] === 'amount' && $reduction['state'] === 'amount') {
        $preview = ['state' => 'amount', 'amount' => round($live['amount'] - $reduction['amount'], 2)];
    }
    return [
        'live_balance' => $live,
        'preview_balance' => $preview,
        'wage_reduction' => $reduction,
        'gst_in_wage_balance' => 0,
        'employer_cost_in_wage_balance' => 0,
        'gst' => $gst,
        'note' => 'Employer CPP, EI, other costs, and GST do not reduce the employee wage balance. The preview is not payroll.',
    ];
}

function psr_valid_date($raw): ?string
{
    $raw = substr(trim((string)$raw), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $raw);
    if (!$dt || $dt->format('Y-m-d') !== $raw) {
        return null;
    }
    return $raw;
}

function psr_periods_overlap(array $a, array $b): bool
{
    $as = (string)($a['period_start'] ?? '');
    $ae = (string)($a['period_end'] ?? '');
    $bs = (string)($b['period_start'] ?? '');
    $be = (string)($b['period_end'] ?? '');
    if ($as === '' || $ae === '' || $bs === '' || $be === '') {
        return false;
    }
    return $as <= $be && $ae >= $bs;
}

function psr_year_block(array $row): ?string
{
    if (!empty($row['year_confirmed'])) {
        return null;
    }
    $confidence = strtolower(trim((string)($row['year_confidence'] ?? '')));
    $flags = $row['flags'] ?? [];
    if (is_string($flags)) {
        $decoded = json_decode($flags, true);
        $flags = is_array($decoded) ? $decoded : [];
    }
    $uncertain = in_array('year_requires_confirmation', $flags, true) || ($confidence !== '' && $confidence !== 'explicit');
    if ($uncertain) {
        return 'The year is uncertain. Confirm the year on the draft before approval. Nothing was posted.';
    }
    return null;
}

function psr_actual_method($raw): ?string
{
    $raw = trim((string)$raw);
    if (!in_array($raw, ['Cash', 'Cheque', 'E-Transfer'], true)) {
        return null;
    }
    if (pcr_payment_method($raw) !== $raw) {
        return null;
    }
    return $raw;
}

function psr_snapshot_lines(array $lines): array
{
    $gross = 0.0;
    $net = 0.0;
    $remaining = 0.0;
    $grossKnown = true;
    $netKnown = true;
    $remainingKnown = true;
    $ids = [];
    foreach ($lines as $ln) {
        $ids[] = (int)($ln['id'] ?? 0);
        if (!array_key_exists('gross', $ln) || $ln['gross'] === null) {
            $grossKnown = false;
        } else {
            $gross = round($gross + (float)$ln['gross'], 2);
        }
        if (empty($ln['net_known']) || $ln['net'] === null) {
            $netKnown = false;
            $remainingKnown = false;
        } else {
            $net = round($net + (float)$ln['net'], 2);
        }
        if (!array_key_exists('paid', $ln) || $ln['paid'] === null) {
            $remainingKnown = false;
        } elseif (!empty($ln['net_known']) && $ln['net'] !== null) {
            $remaining = round($remaining + (float)$ln['net'] - (float)$ln['paid'], 2);
        }
    }
    return [
        'ids' => $ids,
        'gross' => $grossKnown ? $gross : null,
        'net' => $netKnown ? $net : null,
        'remaining' => ($netKnown && $remainingKnown) ? $remaining : null,
        'count' => count($lines),
    ];
}

function psr_approval_plan(array $row, array $existingLines, array $activeKinds): array
{
    $blocks = [];
    $notes = [];
    $writes = [];
    $kind = (string)($row['row_kind'] ?? 'employee_record');
    if ($kind !== '' && $kind !== 'employee_record') {
        $blocks[] = 'Only an employee earnings row can be approved. Notes and unallocated cash stay unallocated.';
    }
    if ((int)($row['user_id'] ?? 0) <= 0 || (int)($row['site_id'] ?? 0) <= 0) {
        $blocks[] = 'Link this draft to an existing employee and store in this company before approval. A name is not created automatically.';
    }
    $start = psr_valid_date($row['period_start'] ?? '');
    $end = psr_valid_date($row['period_end'] ?? '');
    if ($start === null || $end === null || $start > $end) {
        $blocks[] = 'This row needs a real period start and end before it can be approved.';
    }
    $yearBlock = psr_year_block($row);
    if ($yearBlock) {
        $blocks[] = $yearBlock;
    }
    $gross = psr_parse_amount($row['gross'] ?? null);
    $net = psr_parse_amount($row['net'] ?? null);
    if (!$gross['ok']) {
        $blocks[] = 'Gross is not a number. It was not treated as zero.';
        $gross = ['ok' => false, 'value' => null];
    }
    if (!$net['ok']) {
        $blocks[] = 'Net is not a number. It was not treated as zero.';
        $net = ['ok' => false, 'value' => null];
    }
    $snap = psr_snapshot_lines($existingLines);
    $wage = ['action' => 'block', 'finalized_ids' => []];
    if (!$blocks) {
        if (in_array('wage', $activeKinds, true)) {
            $wage = ['action' => 'skip', 'finalized_ids' => $snap['ids']];
            $notes[] = 'Earnings were already posted for this row. They were not posted again.';
        } elseif ($snap['count'] > 0) {
            $grossAgrees = $gross['value'] === null || ($snap['gross'] !== null && abs($snap['gross'] - $gross['value']) < 0.02);
            $netAgrees = $net['value'] !== null && $snap['net'] !== null && abs($snap['net'] - $net['value']) < 0.02;
            if ($net['value'] === null) {
                $blocks[] = 'Net is unknown, so nothing monetary was posted. Gross was not used as net.';
            } elseif (!$netAgrees || !$grossAgrees) {
                $blocks[] = 'Existing payroll for this employee, store, and period does not match the sheet. Wages were not changed and the sheet amount was not added again.';
            } else {
                $wage = ['action' => 'link', 'finalized_ids' => $snap['ids']];
                $notes[] = 'No wage fields change. Existing lines already hold these earnings.';
            }
        } else {
            if ($gross['value'] === null) {
                $blocks[] = 'Gross is unknown. Net was not copied into gross.';
            } elseif ($net['value'] === null) {
                $blocks[] = 'Net is unknown, so nothing monetary was posted. Gross was not used as net.';
            } elseif ($net['value'] - $gross['value'] > 0.009) {
                $blocks[] = 'Net is larger than gross. Nothing was posted.';
            } elseif ($gross['value'] - $net['value'] > 0.009) {
                $blocks[] = 'The deduction split is not on the sheet. CPP, EI, and tax were not invented, and gross was not stored as net.';
            } else {
                $ee = psr_tri($row['ee_deduction_state'] ?? 'unknown', $row['ee_deduction_amount'] ?? null);
                $erCpp = psr_tri($row['er_cpp_state'] ?? 'unknown', $row['er_cpp_amount'] ?? null);
                $erEi = psr_tri($row['er_ei_state'] ?? 'unknown', $row['er_ei_amount'] ?? null);
                $erOther = psr_tri($row['er_other_state'] ?? 'unknown', $row['er_other_amount'] ?? null);
                if ($ee['state'] === 'unknown' || ($ee['state'] === 'amount' && (float)$ee['amount'] > 0.009)) {
                    $blocks[] = 'Net equals gross, so employee deductions must be explicitly none. They were not guessed.';
                } elseif ($erCpp['state'] === 'unknown' || $erEi['state'] === 'unknown' || $erOther['state'] === 'unknown') {
                    $blocks[] = 'Employer CPP, EI, or other cost is unknown. Payroll cannot store Pending, so this row was not posted as zero.';
                } elseif ($erOther['state'] === 'amount' && (float)$erOther['amount'] > 0.009) {
                    $blocks[] = 'Other employer cost has no payroll column that would not mislabel it. This row was not posted.';
                } else {
                    $wage = [
                        'action' => 'create',
                        'finalized_ids' => [],
                        'gross' => $gross['value'],
                        'net' => $net['value'],
                        'er_cpp' => $erCpp['state'] === 'amount' ? $erCpp['amount'] : 0.0,
                        'er_ei' => $erEi['state'] === 'amount' ? $erEi['amount'] : 0.0,
                    ];
                    $writes[] = 'create_line';
                    $notes[] = 'A new payroll line will store this gross. Net equals gross, so employee deductions are explicitly none.';
                }
            }
        }
    }
    $payment = ['action' => 'skip', 'amount' => null];
    $confirmed = !empty($row['actual_confirmed']);
    if ($confirmed && !$blocks) {
        $amount = psr_parse_amount($row['actual_amount'] ?? null);
        $date = psr_valid_date($row['actual_date'] ?? '');
        $method = psr_actual_method($row['actual_method'] ?? '');
        if ($net['value'] === null) {
            $blocks[] = 'Net is unknown, so nothing monetary was posted. Gross was not used as net.';
        } elseif (!$amount['ok'] || $amount['value'] === null || $date === null || $method === null) {
            $blocks[] = 'The confirmed actual payment needs an explicit amount, date, and method of Cash, Cheque, or E-Transfer. ADP and notes were not inferred.';
        } elseif (in_array('payment', $activeKinds, true)) {
            $payment = ['action' => 'skip', 'amount' => null];
            $notes[] = 'This actual payment was already posted. It was not posted again.';
        } else {
            $remaining = $wage['action'] === 'create' ? (float)$net['value'] : $snap['remaining'];
            if ($remaining === null) {
                $blocks[] = 'The remaining balance is not fully known, so the payment was not posted.';
            } elseif ((float)$amount['value'] - (float)$remaining > 0.009) {
                $blocks[] = 'The actual payment is more than the known remaining net. It was not reduced silently and was not posted.';
            } else {
                $payment = [
                    'action' => 'post',
                    'amount' => $amount['value'],
                    'date' => $date,
                    'method' => $method,
                    'reference' => substr(trim((string)($row['actual_reference'] ?? '')), 0, 64),
                ];
                $writes[] = 'post_payment';
                $notes[] = 'Only this confirmed actual payment will be recorded. The proposal is not a payment.';
            }
        }
    } elseif (!$confirmed) {
        $notes[] = 'No confirmed actual payment. A proposal, including cash or ADP, was not marked paid.';
    }
    if ($blocks) {
        $wage = ['action' => 'block', 'finalized_ids' => []];
        $payment = ['action' => 'block', 'amount' => null];
        $writes = [];
    }
    return [
        'ok' => !$blocks,
        'blocks' => $blocks,
        'notes' => $notes,
        'wage' => $wage,
        'payment' => $payment,
        'writes' => $writes,
        'status' => psr_normalize_status($row['reported_status'] ?? 'unknown'),
    ];
}

function psr_plan_hash(array $plans): string
{
    $slim = [];
    foreach ($plans as $plan) {
        $slim[] = [
            'row_id' => (int)($plan['row_id'] ?? 0),
            'ok' => !empty($plan['ok']),
            'writes' => array_values($plan['writes'] ?? []),
            'wage' => $plan['wage']['action'] ?? '',
            'payment' => $plan['payment']['action'] ?? '',
            'amount' => $plan['payment']['amount'] ?? null,
        ];
    }
    return hash('sha256', json_encode($slim));
}

function psr_norm_name($value): string
{
    $value = strtolower(trim((string)$value));
    return preg_replace('/\s+/', ' ', $value);
}

function psr_duplicate_findings(array $existing, array $incoming): array
{
    $bySource = [];
    $byPeriod = [];
    foreach ($existing as $row) {
        $bySource[psr_norm_name($row['source_sheet'] ?? '') . '|' . (int)($row['source_row'] ?? 0)] = true;
        if (($row['row_kind'] ?? '') === 'employee_record') {
            $byPeriod[psr_norm_name($row['employee_name'] ?? '') . '|' . psr_norm_name($row['store_name'] ?? '') . '|' . ($row['period_start'] ?? '') . '|' . ($row['period_end'] ?? '')] = true;
        }
    }
    $out = [];
    foreach ($incoming as $row) {
        $sourceKey = psr_norm_name($row['source_sheet'] ?? '') . '|' . (int)($row['source_row'] ?? 0);
        $periodKey = psr_norm_name($row['employee_name'] ?? '') . '|' . psr_norm_name($row['store_name'] ?? '') . '|' . ($row['period_start'] ?? '') . '|' . ($row['period_end'] ?? '');
        if (!empty($bySource[$sourceKey])) {
            $out[] = ['source_row' => (int)($row['source_row'] ?? 0), 'reason' => 'same source row'];
        } elseif (($row['row_kind'] ?? '') === 'employee_record' && !empty($byPeriod[$periodKey]) && $periodKey !== '|||') {
            $out[] = ['source_row' => (int)($row['source_row'] ?? 0), 'reason' => 'same employee, store, and period'];
        }
    }
    return $out;
}

function psr_csv_records(string $text): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        return [];
    }
    $keys = [];
    foreach ($header as $i => $col) {
        $keys[$i] = strtolower(trim((string)$col));
    }
    $rows = [];
    while (($data = fgetcsv($fh)) !== false) {
        if ($data === [null] || $data === false) {
            continue;
        }
        $blank = true;
        $rec = [];
        foreach ($keys as $i => $key) {
            if ($key === '') {
                continue;
            }
            $val = $data[$i] ?? '';
            if (trim((string)$val) !== '') {
                $blank = false;
            }
            $rec[$key] = $val;
        }
        if (!$blank) {
            $rows[] = $rec;
        }
    }
    fclose($fh);
    return $rows;
}

function psr_json_list($raw): array
{
    if (is_array($raw)) {
        return $raw;
    }
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : [];
}

function psr_map_workbook_row(array $rec): array
{
    $gross = psr_parse_amount($rec['gross'] ?? null);
    $net = psr_parse_amount($rec['net'] ?? null);
    $flags = psr_json_list($rec['flags'] ?? '[]');
    if (!$gross['ok']) {
        $flags[] = 'amount_invalid';
        $gross['value'] = null;
    }
    if (!$net['ok']) {
        $flags[] = 'amount_invalid';
        $net['value'] = null;
    }
    $year = trim((string)($rec['period_year'] ?? ''));
    $month = trim((string)($rec['period_month'] ?? ''));
    $half = trim((string)($rec['period_half'] ?? ''));
    return [
        'source_sheet' => substr(trim((string)($rec['source_sheet'] ?? '')), 0, 160),
        'source_row' => (int)($rec['source_row'] ?? 0),
        'row_kind' => substr(trim((string)($rec['row_kind'] ?? '')), 0, 40),
        'employee_name' => substr(trim((string)($rec['employee'] ?? '')), 0, 160),
        'store_name' => substr(trim((string)($rec['store'] ?? '')), 0, 160),
        'period_start' => psr_valid_date($rec['period_start'] ?? ''),
        'period_end' => psr_valid_date($rec['period_end'] ?? ''),
        'period_month' => ($month !== '' && is_numeric($month)) ? (int)$month : null,
        'period_half' => ($half !== '' && is_numeric($half)) ? (int)$half : null,
        'period_year' => ($year !== '' && is_numeric($year)) ? (int)$year : null,
        'year_confidence' => substr(trim((string)($rec['year_confidence'] ?? '')), 0, 40),
        'period_confidence' => substr(trim((string)($rec['period_confidence'] ?? '')), 0, 80),
        'year_confirmed' => 0,
        'gross' => $gross['value'],
        'net' => $net['value'],
        'reported_status' => psr_normalize_status($rec['reported_status'] ?? ''),
        'status_evidence_json' => (string)($rec['status_evidence'] ?? ''),
        'raw_notes' => (string)($rec['raw_notes'] ?? ''),
        'draft_notes' => '',
        'flags' => array_values(array_unique($flags)),
        'party_class' => 'unanswered',
        'pay_route' => 'unset',
        'amount_basis' => 'unset',
        'proposed_amount' => null,
        'gst_verified' => 0,
        'actual_confirmed' => 0,
        'source_json' => json_encode($rec),
    ];
}

function psr_map_event_row(array $rec): array
{
    $amount = psr_parse_amount($rec['signed_amount_raw'] ?? null);
    $status = strtolower(trim((string)($rec['allocation_status'] ?? '')));
    if ($status === '') {
        $status = 'unallocated';
    }
    return [
        'event_key' => substr(trim((string)($rec['event_id'] ?? '')), 0, 80),
        'source_row' => ($rec['source_row'] ?? '') === '' ? null : (int)$rec['source_row'],
        'source_cell' => substr(trim((string)($rec['source_cell'] ?? '')), 0, 40),
        'employee_context' => substr(trim((string)($rec['employee_context'] ?? '')), 0, 160),
        'store_context' => substr(trim((string)($rec['store_context'] ?? '')), 0, 160),
        'signed_amount' => $amount['ok'] ? $amount['value'] : null,
        'allocation_status' => substr($status, 0, 40),
        'payment_date' => psr_valid_date($rec['payment_date'] ?? ''),
        'raw_notes' => (string)($rec['raw_notes'] ?? ''),
        'flags' => psr_json_list($rec['flags'] ?? '[]'),
    ];
}

function psr_map_proposal_row(array $rec): array
{
    $amount = psr_parse_amount($rec['proposed_amount'] ?? null);
    $basis = strtolower(trim((string)($rec['amount_basis'] ?? '')));
    if (!in_array($basis, ['gross', 'net'], true)) {
        $basis = 'unset';
    }
    $route = strtolower(trim((string)($rec['route'] ?? '')));
    if (!in_array($route, ['cash', 'adp'], true)) {
        $route = 'unset';
    }
    $party = strtolower(trim((string)($rec['party_class'] ?? '')));
    if (!in_array($party, ['employee', 'contractor'], true)) {
        $party = 'unanswered';
    }
    $gstVerified = in_array(strtolower(trim((string)($rec['gst_verified'] ?? ''))), ['1', 'yes', 'true'], true);
    if ($party !== 'contractor') {
        $gstVerified = false;
    }
    return [
        'employee_name' => substr(trim((string)($rec['employee'] ?? '')), 0, 160),
        'store_name' => substr(trim((string)($rec['store'] ?? '')), 0, 160),
        'period_start' => psr_valid_date($rec['period_start'] ?? ''),
        'period_end' => psr_valid_date($rec['period_end'] ?? ''),
        'proposed_amount' => $amount['ok'] ? $amount['value'] : null,
        'amount_basis' => $basis,
        'pay_route' => $route,
        'party_class' => $party,
        'gst_verified' => $gstVerified ? 1 : 0,
        'gst_invoice_principal' => $gstVerified ? (psr_parse_amount($rec['gst_invoice_principal'] ?? null)['value'] ?? null) : null,
        'note' => (string)($rec['note'] ?? ''),
        'plan_date' => psr_valid_date($rec['plan_date'] ?? '') ?: date('Y-m-d'),
    ];
}

function psr_directory_is_safe(string $dir, string $appRoot): bool
{
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    $app = rtrim(str_replace('\\', '/', $appRoot), '/');
    if ($dir === '' || $app === '' || strpos($dir, '..') !== false) {
        return false;
    }
    $dirPrefix = $dir . '/';
    $appPrefix = $app . '/';
    if (strpos($dirPrefix, $appPrefix) === 0) {
        return false;
    }
    if (preg_match('#/public_html(?:/|$)#', $dir)) {
        return false;
    }
    return true;
}

function psr_app_root(): string
{
    return dirname(__DIR__);
}

function psr_private_root(): string
{
    $env = getenv('PAYROLL_PRIVATE_DIR');
    if (is_string($env) && trim($env) !== '') {
        $dir = rtrim($env, '/\\');
    } else {
        $dir = dirname(psr_app_root(), 2) . '/payroll_private/sheet_review';
    }
    if (!psr_directory_is_safe($dir, psr_app_root())) {
        throw new RuntimeException('Sheet uploads must stay outside the web app directory.');
    }
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('The private upload directory could not be created.');
    }
    return $dir;
}

function psr_may_view_org(bool $super, int $homeOrg, int $requested): bool
{
    if ($requested <= 0) {
        return false;
    }
    if ($requested === $homeOrg) {
        return true;
    }
    return $super;
}

function psr_line_in_filter(array $line, array $filter): bool
{
    if (!empty($filter['user_id']) && (int)$line['user_id'] !== (int)$filter['user_id']) {
        return false;
    }
    if (!empty($filter['site_id']) && (int)$line['site_id'] !== (int)$filter['site_id']) {
        return false;
    }
    if (!empty($filter['period_start']) && !empty($filter['period_end'])) {
        return psr_periods_overlap($line, $filter);
    }
    return true;
}

function psr_blank_total(): array
{
    return [
        'gross_known' => 0.0,
        'gross_unknown' => 0,
        'net_known' => 0.0,
        'net_unknown' => 0,
        'paid_known' => 0.0,
        'paid_unknown' => 0,
        'remaining_known' => null,
        'remaining_lines' => 0,
        'paid_without_known_net' => 0,
        'line_count' => 0,
    ];
}

function psr_add_line_to_total(array $total, array $line): array
{
    $total['line_count']++;
    if (!array_key_exists('gross', $line) || $line['gross'] === null) {
        $total['gross_unknown']++;
    } else {
        $total['gross_known'] = round($total['gross_known'] + (float)$line['gross'], 2);
    }
    $netKnown = !empty($line['net_known']) && array_key_exists('net', $line) && $line['net'] !== null;
    $paidKnown = array_key_exists('paid', $line) && $line['paid'] !== null;
    $status = psr_normalize_status($line['status'] ?? 'unknown');
    if (!$netKnown) {
        $total['net_unknown']++;
        if ($status === 'paid' || ($paidKnown && (float)$line['paid'] > 0.009)) {
            $total['paid_without_known_net']++;
        }
    } else {
        $total['net_known'] = round($total['net_known'] + (float)$line['net'], 2);
    }
    if (!$paidKnown) {
        $total['paid_unknown']++;
    } else {
        $total['paid_known'] = round($total['paid_known'] + (float)$line['paid'], 2);
    }
    if ($netKnown && $paidKnown) {
        if ($total['remaining_known'] === null) {
            $total['remaining_known'] = 0.0;
        }
        $total['remaining_known'] = round($total['remaining_known'] + (float)$line['net'] - (float)$line['paid'], 2);
        $total['remaining_lines']++;
    }
    return $total;
}

function psr_countable_lines(array $systemLines, array $approvedSheet, int $orgId): array
{
    $kept = [];
    $ids = [];
    foreach ($systemLines as $ln) {
        if ((int)($ln['org_id'] ?? 0) !== $orgId || (int)($ln['user_id'] ?? 0) <= 0) {
            continue;
        }
        $ids[(int)$ln['id']] = true;
        $ln['source'] = $ln['source'] ?? 'payroll';
        $kept[] = $ln;
    }
    $notes = [];
    foreach ($approvedSheet as $sh) {
        if ((int)($sh['org_id'] ?? 0) !== $orgId || (int)($sh['user_id'] ?? 0) <= 0) {
            continue;
        }
        $covered = false;
        foreach ($sh['finalized_ids'] ?? [] as $fid) {
            if (!empty($ids[(int)$fid])) {
                $covered = true;
                break;
            }
        }
        if (!$covered) {
            foreach ($kept as $ln) {
                if ((int)$ln['user_id'] === (int)$sh['user_id'] && (int)$ln['site_id'] === (int)$sh['site_id'] && psr_periods_overlap($ln, $sh)) {
                    $covered = true;
                    break;
                }
            }
        }
        if ($covered) {
            $notes[] = [
                'user_id' => (int)$sh['user_id'],
                'text' => 'Approved sheet row ' . (int)($sh['id'] ?? 0) . ' matches payroll and is not counted twice.',
                'period_start' => (string)($sh['period_start'] ?? ''),
                'period_end' => (string)($sh['period_end'] ?? ''),
            ];
            continue;
        }
        $sh['source'] = 'approved_sheet';
        $sh['id'] = (int)($sh['id'] ?? 0);
        $kept[] = $sh;
    }
    return ['lines' => $kept, 'dedup_notes' => $notes];
}

function psr_draft_preview(array $drafts, int $orgId, int $userId): array
{
    $out = ['rows' => 0, 'gross_known' => 0.0, 'gross_unknown' => 0, 'net_known' => 0.0, 'net_unknown' => 0, 'proposed_known' => 0.0, 'proposed_unknown' => 0];
    foreach ($drafts as $row) {
        if ((int)($row['org_id'] ?? 0) !== $orgId || (int)($row['user_id'] ?? 0) !== $userId) {
            continue;
        }
        $out['rows']++;
        $gross = psr_parse_amount($row['gross'] ?? null);
        $net = psr_parse_amount($row['net'] ?? null);
        $proposed = psr_parse_amount($row['proposed_amount'] ?? null);
        if ($gross['ok'] && $gross['value'] !== null) {
            $out['gross_known'] = round($out['gross_known'] + $gross['value'], 2);
        } else {
            $out['gross_unknown']++;
        }
        if ($net['ok'] && $net['value'] !== null) {
            $out['net_known'] = round($out['net_known'] + $net['value'], 2);
        } else {
            $out['net_unknown']++;
        }
        if ($proposed['ok'] && $proposed['value'] !== null) {
            $out['proposed_known'] = round($out['proposed_known'] + $proposed['value'], 2);
        } else {
            $out['proposed_unknown']++;
        }
    }
    return $out;
}

function psr_company_report(array $company, array $filter = []): array
{
    $orgId = (int)($company['org_id'] ?? 0);
    $counted = psr_countable_lines($company['system_lines'] ?? [], $company['approved_sheet'] ?? [], $orgId);
    $all = psr_blank_total();
    $filtered = psr_blank_total();
    $byUser = [];
    foreach ($counted['lines'] as $ln) {
        $uid = (int)$ln['user_id'];
        if (!isset($byUser[$uid])) {
            $byUser[$uid] = [
                'user_id' => $uid,
                'name' => (string)($ln['employee_name'] ?? ('Employee ' . $uid)),
                'all_period' => psr_blank_total(),
                'filtered' => psr_blank_total(),
                'drilldown' => [],
            ];
        }
        $byUser[$uid]['all_period'] = psr_add_line_to_total($byUser[$uid]['all_period'], $ln);
        $all = psr_add_line_to_total($all, $ln);
        $inFilter = psr_line_in_filter($ln, $filter);
        if ($inFilter) {
            $byUser[$uid]['filtered'] = psr_add_line_to_total($byUser[$uid]['filtered'], $ln);
            $filtered = psr_add_line_to_total($filtered, $ln);
        }
        $byUser[$uid]['drilldown'][] = [
            'source' => (string)($ln['source'] ?? 'payroll'),
            'id' => (int)($ln['id'] ?? 0),
            'store' => (string)($ln['store_name'] ?? ''),
            'site_id' => (int)($ln['site_id'] ?? 0),
            'period_start' => (string)($ln['period_start'] ?? ''),
            'period_end' => (string)($ln['period_end'] ?? ''),
            'gross' => $ln['gross'] ?? null,
            'net' => !empty($ln['net_known']) ? ($ln['net'] ?? null) : null,
            'net_known' => !empty($ln['net_known']),
            'paid' => $ln['paid'] ?? null,
            'status' => psr_normalize_status($ln['status'] ?? 'unknown'),
            'in_filter' => $inFilter,
            'paid_without_known_net' => empty($ln['net_known']) && psr_normalize_status($ln['status'] ?? '') === 'paid',
        ];
    }
    foreach ($byUser as $uid => $emp) {
        $byUser[$uid]['draft_preview'] = psr_draft_preview($company['draft_sheet'] ?? [], $orgId, $uid);
        $byUser[$uid]['dedup_notes'] = array_values(array_filter(
            $counted['dedup_notes'],
            static function ($note) use ($uid) {
                return (int)$note['user_id'] === $uid;
            }
        ));
    }
    if (!empty($filter['user_id'])) {
        $only = (int)$filter['user_id'];
        $byUser = isset($byUser[$only]) ? [$only => $byUser[$only]] : [];
    }
    $filterActive = !empty($filter['user_id']) || !empty($filter['site_id']) || (!empty($filter['period_start']) && !empty($filter['period_end']));
    return [
        'org_id' => $orgId,
        'org_name' => (string)($company['org_name'] ?? ('Company ' . $orgId)),
        'all_period' => $all,
        'filtered' => $filtered,
        'filter_active' => $filterActive,
        'filter_label' => $filterActive
            ? 'Filtered subset. This is not the all-period total.'
            : 'No filter. The figures below are the all-period total for this company.',
        'employees' => array_values($byUser),
    ];
}

function psr_companies_report(array $companies, array $filter = []): array
{
    $out = [];
    foreach ($companies as $company) {
        $out[] = psr_company_report($company, (int)($company['org_id'] ?? 0) === (int)($filter['org_id'] ?? $company['org_id'] ?? 0) ? $filter : []);
    }
    return [
        'companies' => $out,
        'cross_company_sum' => null,
        'cross_company_note' => 'Totals are not added across companies. Each company is a separate employer.',
    ];
}

function psr_db_amount($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    return round((float)$value, 2);
}

function psr_system_line(array $r, int $orgId): array
{
    $gross = array_key_exists('total_gross', $r) ? psr_db_amount($r['total_gross']) : null;
    $cpp = array_key_exists('cpp', $r) ? psr_db_amount($r['cpp']) : null;
    $ei = array_key_exists('ei', $r) ? psr_db_amount($r['ei']) : null;
    $tax = array_key_exists('income_tax', $r) ? psr_db_amount($r['income_tax']) : null;
    $netKnown = $gross !== null && $cpp !== null && $ei !== null && $tax !== null;
    $start = psr_valid_date($r['pay_period'] ?? '') ?: psr_valid_date($r['work_date'] ?? '');
    $end = psr_valid_date($r['pay_period_end'] ?? '') ?: $start;
    return [
        'source' => 'payroll',
        'id' => (int)($r['id'] ?? 0),
        'org_id' => $orgId,
        'user_id' => (int)($r['user_id'] ?? 0),
        'site_id' => (int)($r['site_id'] ?? 0),
        'employee_name' => (string)($r['employee_name'] ?? ''),
        'store_name' => (string)($r['store_name'] ?? ''),
        'period_start' => $start,
        'period_end' => $end,
        'work_date' => (string)($r['work_date'] ?? ''),
        'gross' => $gross,
        'net' => $netKnown ? round($gross - $cpp - $ei - $tax, 2) : null,
        'net_known' => $netKnown,
        'paid' => array_key_exists('paid_amount', $r) ? psr_db_amount($r['paid_amount']) : null,
        'status' => psr_normalize_status($r['payment_status'] ?? ''),
    ];
}

function psr_ensure(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $sql = file_get_contents(dirname(__DIR__) . '/migrations/20261108_payroll_sheet_review.sql');
    if ($sql === false) {
        throw new RuntimeException('The sheet review migration file is missing.');
    }
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (preg_split('/;/', (string)$sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
    $ready = true;
}

function psr_ensure_menu(PDO $pdo): void
{
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM menu_manager')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }
    if (!$cols) {
        return;
    }
    $have = array_flip($cols);
    $exists = $pdo->query("SELECT MenuID FROM menu_manager WHERE LinkURL LIKE '%payroll_sheet_review.php%' LIMIT 1");
    if ($exists && $exists->fetchColumn()) {
        return;
    }
    $parentId = 0;
    $module = '';
    $sib = $pdo->query("SELECT * FROM menu_manager WHERE LinkURL LIKE '%payroll_master_ledger.php%' LIMIT 1");
    $sibRow = $sib ? $sib->fetch(PDO::FETCH_ASSOC) : false;
    if ($sibRow) {
        $parentId = (int)($sibRow['ParentID'] ?? 0);
        if (isset($sibRow['Module'])) {
            $module = (string)$sibRow['Module'];
        }
    }
    $data = [
        'MenuTitle' => 'Sheet review',
        'LinkURL' => 'payroll_sheet_review.php',
        'ParentID' => $parentId,
        'IsActive' => 1,
        'UserRole' => 20,
        'Module' => $module,
    ];
    $fields = [];
    $holders = [];
    $vals = [];
    foreach ($data as $col => $val) {
        if (!isset($have[$col])) {
            continue;
        }
        $fields[] = '`' . str_replace('`', '', $col) . '`';
        $holders[] = '?';
        $vals[] = $val;
    }
    if ($fields) {
        $pdo->prepare('INSERT INTO menu_manager (' . implode(',', $fields) . ') VALUES (' . implode(',', $holders) . ')')->execute($vals);
    }
}

function psr_fetch_system_lines(PDO $pdo, int $orgId, ?int $userId = null): array
{
    $sql = "SELECT f.id, f.user_id, f.site_id, f.work_date, f.pay_period, f.pay_period_end,
                   f.total_gross, f.cpp, f.ei, f.income_tax, f.paid_amount, f.payment_status,
                   u.Name AS employee_name, s.nick_name AS store_name
            FROM payroll_finalized f
            INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
            INNER JOIN sites s ON s.SId = f.site_id AND s.org_id = u.org_id";
    $params = [$orgId];
    if ($userId) {
        $sql .= ' WHERE f.user_id = ?';
        $params[] = $userId;
    }
    $sql .= ' ORDER BY u.Name ASC, f.work_date ASC, f.id ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[] = psr_system_line($row, $orgId);
    }
    return $out;
}

function psr_store_upload(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The upload did not arrive.');
    }
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
        throw new RuntimeException('The file is larger than 8 MB.');
    }
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'json'], true)) {
        throw new RuntimeException('Upload a CSV or JSON file.');
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = psr_private_root() . '/' . $name;
    if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
        if (!rename((string)$file['tmp_name'], $dest)) {
            throw new RuntimeException('The file could not be stored privately.');
        }
    }
    chmod($dest, 0600);
    return $name;
}

function psr_read_private(string $storedName): string
{
    if (!preg_match('/^[a-f0-9]{32}\.(csv|json)$/', $storedName)) {
        throw new RuntimeException('That stored file is not valid.');
    }
    $path = psr_private_root() . '/' . $storedName;
    $text = file_get_contents($path);
    if ($text === false) {
        throw new RuntimeException('The stored upload could not be read.');
    }
    return $text;
}

function psr_parse_upload(string $text, string $kind): array
{
    $trim = ltrim($text);
    $records = [];
    if ($trim !== '' && $trim[0] === '{') {
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('The JSON could not be read.');
        }
        if ($kind === 'events') {
            $records = $decoded['events'] ?? $decoded;
        } elseif ($kind === 'proposal') {
            $records = $decoded['proposals'] ?? $decoded;
        } else {
            $records = $decoded['rows'] ?? $decoded;
        }
        if (!is_array($records)) {
            throw new RuntimeException('The JSON did not contain a list of rows.');
        }
    } elseif ($trim !== '' && $trim[0] === '[') {
        $records = json_decode($text, true);
        if (!is_array($records)) {
            throw new RuntimeException('The JSON could not be read.');
        }
    } else {
        $records = psr_csv_records($text);
    }
    $rows = [];
    foreach ($records as $rec) {
        if (!is_array($rec)) {
            continue;
        }
        $lower = [];
        foreach ($rec as $key => $val) {
            $lower[strtolower((string)$key)] = $val;
        }
        if ($kind === 'events') {
            $rows[] = psr_map_event_row($lower);
        } elseif ($kind === 'proposal') {
            $rows[] = psr_map_proposal_row($lower);
        } else {
            $rows[] = psr_map_workbook_row($lower);
        }
    }
    return $rows;
}

function psr_insert_revision(PDO $pdo, int $orgId, string $type, int $id, string $action, int $actor, $before, $after): void
{
    $pdo->prepare(
        'INSERT INTO payroll_sheet_revisions (org_id, target_type, target_id, action, actor_id, before_json, after_json, created_at)
         VALUES (?,?,?,?,?,?,?,NOW())'
    )->execute([$orgId, $type, $id, $action, $actor, json_encode($before), json_encode($after)]);
}

function psr_commit_workbook(PDO $pdo, int $orgId, int $actor, string $storedName, string $original, array $rows, bool $skipDuplicates): int
{
    psr_ensure($pdo);
    $existing = $pdo->prepare('SELECT source_sheet, source_row, row_kind, employee_name, store_name, period_start, period_end FROM payroll_sheet_rows WHERE org_id = ?');
    $existing->execute([$orgId]);
    $have = $existing->fetchAll(PDO::FETCH_ASSOC);
    $dups = psr_duplicate_findings($have, $rows);
    $dupRows = [];
    foreach ($dups as $dup) {
        $dupRows[(int)$dup['source_row']] = true;
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO payroll_sheet_imports (org_id, kind, original_name, stored_name, byte_size, created_by, created_at, loaded_at, row_count)
             VALUES (?,?,?,?,?,?,NOW(),NOW(),?)'
        )->execute([$orgId, 'workbook', substr($original, 0, 255), $storedName, 0, $actor, count($rows)]);
        $importId = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare(
            'INSERT INTO payroll_sheet_rows
                (org_id, import_id, source_sheet, source_row, row_kind, source_json, employee_name, store_name,
                 period_start, period_end, period_month, period_half, period_year, year_confidence, period_confidence,
                 year_confirmed, gross, net, reported_status, status_evidence_json, raw_notes, draft_notes, flags_json,
                 party_class, pay_route, amount_basis, proposed_amount, gst_verified, actual_confirmed, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
        );
        $kept = 0;
        foreach ($rows as $row) {
            if ($skipDuplicates && !empty($dupRows[(int)$row['source_row']])) {
                continue;
            }
            $ins->execute([
                $orgId, $importId, $row['source_sheet'], $row['source_row'], $row['row_kind'], $row['source_json'],
                $row['employee_name'], $row['store_name'], $row['period_start'], $row['period_end'], $row['period_month'],
                $row['period_half'], $row['period_year'], $row['year_confidence'], $row['period_confidence'], 0,
                $row['gross'], $row['net'], $row['reported_status'], $row['status_evidence_json'], $row['raw_notes'],
                '', json_encode($row['flags']), 'unanswered', 'unset', 'unset', null, 0, 0,
            ]);
            $kept++;
            psr_insert_revision($pdo, $orgId, 'row', (int)$pdo->lastInsertId(), 'import', $actor, null, ['gross' => $row['gross'], 'net' => $row['net'], 'status' => $row['reported_status']]);
        }
        $pdo->prepare('UPDATE payroll_sheet_imports SET row_count = ? WHERE id = ? AND org_id = ?')->execute([$kept, $importId, $orgId]);
        $pdo->commit();
        return $importId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function psr_commit_events(PDO $pdo, int $orgId, int $actor, array $events): int
{
    psr_ensure($pdo);
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO payroll_sheet_imports (org_id, kind, original_name, stored_name, byte_size, created_by, created_at, loaded_at, event_count)
             VALUES (?,?,?,?,0,?,NOW(),NOW(),?)'
        )->execute([$orgId, 'events', 'payments', '', $actor, count($events)]);
        $importId = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare(
            'INSERT INTO payroll_sheet_events
                (org_id, import_id, event_key, source_row, source_cell, employee_context, store_context, signed_amount, allocation_status, payment_date, raw_notes, flags_json, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
        );
        foreach ($events as $ev) {
            $ins->execute([
                $orgId, $importId, $ev['event_key'], $ev['source_row'], $ev['source_cell'], $ev['employee_context'],
                $ev['store_context'], $ev['signed_amount'], $ev['allocation_status'] ?: 'unallocated', $ev['payment_date'],
                $ev['raw_notes'], json_encode($ev['flags']),
            ]);
        }
        $pdo->commit();
        return $importId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function psr_draft_input(array $post): array
{
    $gross = psr_parse_amount($post['gross'] ?? null);
    $net = psr_parse_amount($post['net'] ?? null);
    $proposed = psr_parse_amount($post['proposed_amount'] ?? null);
    $actual = psr_parse_amount($post['actual_amount'] ?? null);
    $gstBase = psr_parse_amount($post['gst_invoice_principal'] ?? null);
    foreach (['gross' => $gross, 'net' => $net, 'proposed_amount' => $proposed, 'actual_amount' => $actual, 'gst_invoice_principal' => $gstBase] as $name => $parsed) {
        if (!$parsed['ok']) {
            throw new RuntimeException($name . ' is not a number. Blank means unknown, and 0 must be typed as 0.');
        }
    }
    $party = (string)($post['party_class'] ?? 'unanswered');
    if (!in_array($party, ['unanswered', 'employee', 'contractor'], true)) {
        $party = 'unanswered';
    }
    $route = (string)($post['pay_route'] ?? 'unset');
    if (!in_array($route, ['unset', 'cash', 'adp'], true)) {
        $route = 'unset';
    }
    $basis = (string)($post['amount_basis'] ?? 'unset');
    if (!in_array($basis, ['unset', 'gross', 'net'], true)) {
        $basis = 'unset';
    }
    $gst = ($party === 'contractor' && !empty($post['gst_verified'])) ? 1 : 0;
    $method = trim((string)($post['actual_method'] ?? ''));
    if ($method !== '' && psr_actual_method($method) === null && !in_array($method, ['ADP', 'EFT'], true)) {
        $method = '';
    }
    return [
        'employee_name' => substr(trim((string)($post['employee_name'] ?? '')), 0, 160),
        'store_name' => substr(trim((string)($post['store_name'] ?? '')), 0, 160),
        'user_id' => (int)($post['user_id'] ?? 0) ?: null,
        'site_id' => (int)($post['site_id'] ?? 0) ?: null,
        'period_start' => psr_valid_date($post['period_start'] ?? ''),
        'period_end' => psr_valid_date($post['period_end'] ?? ''),
        'year_confirmed' => !empty($post['year_confirmed']) ? 1 : 0,
        'gross' => $gross['value'],
        'net' => $net['value'],
        'reported_status' => psr_normalize_status($post['reported_status'] ?? 'unknown'),
        'draft_notes' => substr((string)($post['draft_notes'] ?? ''), 0, 5000),
        'party_class' => $party,
        'pay_route' => $route,
        'amount_basis' => $basis,
        'proposed_amount' => $proposed['value'],
        'ee_deduction_state' => psr_tri($post['ee_deduction_state'] ?? 'unknown', $post['ee_deduction_amount'] ?? null)['state'],
        'ee_deduction_amount' => psr_tri($post['ee_deduction_state'] ?? 'unknown', $post['ee_deduction_amount'] ?? null)['amount'],
        'er_cpp_state' => psr_tri($post['er_cpp_state'] ?? 'unknown', $post['er_cpp_amount'] ?? null)['state'],
        'er_cpp_amount' => psr_tri($post['er_cpp_state'] ?? 'unknown', $post['er_cpp_amount'] ?? null)['amount'],
        'er_ei_state' => psr_tri($post['er_ei_state'] ?? 'unknown', $post['er_ei_amount'] ?? null)['state'],
        'er_ei_amount' => psr_tri($post['er_ei_state'] ?? 'unknown', $post['er_ei_amount'] ?? null)['amount'],
        'er_other_state' => psr_tri($post['er_other_state'] ?? 'unknown', $post['er_other_amount'] ?? null)['state'],
        'er_other_amount' => psr_tri($post['er_other_state'] ?? 'unknown', $post['er_other_amount'] ?? null)['amount'],
        'gst_verified' => $gst,
        'gst_invoice_principal' => $gst ? $gstBase['value'] : null,
        'actual_confirmed' => !empty($post['actual_confirmed']) ? 1 : 0,
        'actual_amount' => $actual['value'],
        'actual_date' => psr_valid_date($post['actual_date'] ?? ''),
        'actual_method' => $method !== '' ? $method : null,
        'actual_reference' => substr(trim((string)($post['actual_reference'] ?? '')), 0, 64),
    ];
}

function psr_save_draft_row(PDO $pdo, int $orgId, int $rowId, int $actor, array $post): void
{
    psr_ensure($pdo);
    $cur = $pdo->prepare('SELECT * FROM payroll_sheet_rows WHERE id = ? AND org_id = ?');
    $cur->execute([$rowId, $orgId]);
    $before = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$before) {
        throw new RuntimeException('That draft is not in this company.');
    }
    if (!empty($post['user_id'])) {
        $u = $pdo->prepare('SELECT id FROM users WHERE id = ? AND org_id = ?');
        $u->execute([(int)$post['user_id'], $orgId]);
        if (!$u->fetchColumn()) {
            throw new RuntimeException('That employee is not in this company.');
        }
    }
    if (!empty($post['site_id'])) {
        $s = $pdo->prepare('SELECT SId FROM sites WHERE SId = ? AND org_id = ?');
        $s->execute([(int)$post['site_id'], $orgId]);
        if (!$s->fetchColumn()) {
            throw new RuntimeException('That store is not in this company.');
        }
    }
    $next = psr_draft_input($post);
    $pdo->prepare(
        'UPDATE payroll_sheet_rows SET employee_name=?, store_name=?, user_id=?, site_id=?, period_start=?, period_end=?,
            year_confirmed=?, gross=?, net=?, reported_status=?, draft_notes=?, party_class=?, pay_route=?, amount_basis=?,
            proposed_amount=?, ee_deduction_state=?, ee_deduction_amount=?, er_cpp_state=?, er_cpp_amount=?, er_ei_state=?,
            er_ei_amount=?, er_other_state=?, er_other_amount=?, gst_verified=?, gst_invoice_principal=?, actual_confirmed=?,
            actual_amount=?, actual_date=?, actual_method=?, actual_reference=?, updated_at=NOW()
         WHERE id=? AND org_id=?'
    )->execute([
        $next['employee_name'], $next['store_name'], $next['user_id'], $next['site_id'], $next['period_start'], $next['period_end'],
        $next['year_confirmed'], $next['gross'], $next['net'], $next['reported_status'], $next['draft_notes'], $next['party_class'],
        $next['pay_route'], $next['amount_basis'], $next['proposed_amount'], $next['ee_deduction_state'], $next['ee_deduction_amount'],
        $next['er_cpp_state'], $next['er_cpp_amount'], $next['er_ei_state'], $next['er_ei_amount'], $next['er_other_state'],
        $next['er_other_amount'], $next['gst_verified'], $next['gst_invoice_principal'], $next['actual_confirmed'],
        $next['actual_amount'], $next['actual_date'], $next['actual_method'], $next['actual_reference'], $rowId, $orgId,
    ]);
    psr_insert_revision($pdo, $orgId, 'row', $rowId, 'save_draft', $actor, $before, $next);
}

function psr_row_to_plan_input(array $row): array
{
    $row['flags'] = psr_json_list($row['flags_json'] ?? $row['flags'] ?? []);
    return $row;
}

function psr_active_effects(PDO $pdo, int $orgId): array
{
    $st = $pdo->prepare(
        'SELECT e.* FROM payroll_sheet_approval_effects e
         INNER JOIN payroll_sheet_approvals a ON a.id = e.approval_id AND a.org_id = e.org_id
         WHERE e.org_id = ? AND e.undone_at IS NULL AND a.undone_at IS NULL'
    );
    $st->execute([$orgId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function psr_build_plans(PDO $pdo, int $orgId, array $rowIds): array
{
    $plans = [];
    $effects = psr_active_effects($pdo, $orgId);
    $byRow = [];
    foreach ($effects as $ef) {
        $byRow[(int)$ef['row_id']][] = $ef['effect_kind'] === 'post_payment' ? 'payment' : 'wage';
    }
    foreach ($rowIds as $rowId) {
        $st = $pdo->prepare('SELECT * FROM payroll_sheet_rows WHERE id = ? AND org_id = ?');
        $st->execute([(int)$rowId, $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $plans[] = ['row_id' => (int)$rowId, 'ok' => false, 'blocks' => ['That row is not in this company.'], 'notes' => [], 'wage' => ['action' => 'block'], 'payment' => ['action' => 'block', 'amount' => null], 'writes' => []];
            continue;
        }
        $lines = [];
        if ((int)$row['user_id'] > 0 && (int)$row['site_id'] > 0) {
            foreach (psr_fetch_system_lines($pdo, $orgId, (int)$row['user_id']) as $ln) {
                if ((int)$ln['site_id'] === (int)$row['site_id'] && psr_periods_overlap($ln, $row)) {
                    $lines[] = $ln;
                }
            }
        }
        $plan = psr_approval_plan(psr_row_to_plan_input($row), $lines, $byRow[(int)$rowId] ?? []);
        $plan['row_id'] = (int)$row['id'];
        $plan['employee_name'] = (string)$row['employee_name'];
        $plans[] = $plan;
    }
    return $plans;
}

function psr_apply_approval(PDO $pdo, int $orgId, int $actor, array $rowIds, string $idempotencyKey): array
{
    psr_ensure($pdo);
    pcr_ensure($pdo);
    $idempotencyKey = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $idempotencyKey), 0, 64);
    if ($idempotencyKey === '') {
        throw new RuntimeException('Approval is missing its confirmation key.');
    }
    $prior = $pdo->prepare('SELECT * FROM payroll_sheet_approvals WHERE org_id = ? AND idempotency_key = ?');
    $prior->execute([$orgId, $idempotencyKey]);
    if ($old = $prior->fetch(PDO::FETCH_ASSOC)) {
        return ['approval_id' => (int)$old['id'], 'repeated' => true, 'result' => json_decode((string)$old['result_json'], true) ?: []];
    }
    $plans = psr_build_plans($pdo, $orgId, $rowIds);
    $blocks = [];
    foreach ($plans as $plan) {
        if (empty($plan['ok'])) {
            $blocks[] = ($plan['employee_name'] ?? ('Row ' . $plan['row_id'])) . ': ' . implode(' ', $plan['blocks']);
        }
    }
    if ($blocks) {
        throw new RuntimeException("Nothing was posted.\n" . implode("\n", $blocks));
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO payroll_sheet_approvals (org_id, idempotency_key, created_by, created_at, preview_json, result_json)
             VALUES (?,?,?,NOW(),?,?)'
        )->execute([$orgId, $idempotencyKey, $actor, json_encode($plans), '{}']);
        $approvalId = (int)$pdo->lastInsertId();
        $result = ['created' => 0, 'linked' => 0, 'payments' => 0, 'amount' => 0.0];
        foreach ($plans as $plan) {
            if (!$plan['writes'] && ($plan['wage']['action'] ?? '') !== 'link') {
                continue;
            }
            $rowSt = $pdo->prepare('SELECT * FROM payroll_sheet_rows WHERE id = ? AND org_id = ? FOR UPDATE');
            $rowSt->execute([(int)$plan['row_id'], $orgId]);
            $row = $rowSt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('A selected row left this company during approval.');
            }
            $who = $pdo->prepare('SELECT id FROM users WHERE id = ? AND org_id = ?');
            $who->execute([(int)$row['user_id'], $orgId]);
            $store = $pdo->prepare('SELECT SId FROM sites WHERE SId = ? AND org_id = ?');
            $store->execute([(int)$row['site_id'], $orgId]);
            if (!$who->fetchColumn() || !$store->fetchColumn()) {
                throw new RuntimeException('The employee or store is not in this company. Nothing was posted.');
            }
            $finalizedId = 0;
            if (($plan['wage']['action'] ?? '') === 'create') {
                $finalizedId = pstat_insert_known($pdo, 'payroll_finalized', [
                    'user_id' => (int)$row['user_id'],
                    'site_id' => (int)$row['site_id'],
                    'work_date' => $row['period_end'],
                    'pay_period' => $row['period_start'],
                    'pay_period_end' => $row['period_end'],
                    'total_gross' => $plan['wage']['gross'],
                    'paid_amount' => 0,
                    'cpp' => 0,
                    'ei' => 0,
                    'income_tax' => 0,
                    'cpp_er' => $plan['wage']['er_cpp'],
                    'ei_er' => $plan['wage']['er_ei'],
                    'wcb' => 0,
                    'vac' => 0,
                    'reg_hours' => 0,
                    'overtime_hours' => 0,
                    'travel_hours' => 0,
                    'hourly_rate' => 0,
                    'status' => 'pending',
                    'payment_status' => 'Unpaid',
                    'display_label' => 'Sheet review',
                    'category' => 'Manual',
                    'pay_type' => 'Manual',
                    'comments' => 'sheet-review',
                    'Remarks' => '',
                    'remarks' => '',
                ]);
                $pdo->prepare(
                    'INSERT INTO payroll_sheet_approval_effects
                        (approval_id, org_id, row_id, effect_kind, finalized_id, before_json, after_json, active_key)
                     VALUES (?,?,?,?,?,?,?,?)'
                )->execute([
                    $approvalId, $orgId, (int)$row['id'], 'create_line', $finalizedId, null,
                    json_encode(['gross' => $plan['wage']['gross'], 'net' => $plan['wage']['net']]),
                    $row['id'] . '|wage',
                ]);
                $result['created']++;
            } elseif (($plan['wage']['action'] ?? '') === 'link') {
                $ids = $plan['wage']['finalized_ids'];
                $finalizedId = (int)($ids[0] ?? 0);
                $pdo->prepare(
                    'INSERT INTO payroll_sheet_approval_effects
                        (approval_id, org_id, row_id, effect_kind, finalized_id, before_json, after_json, active_key, detail_json)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $approvalId, $orgId, (int)$row['id'], 'link_line', $finalizedId,
                    json_encode(['finalized_ids' => $ids]), json_encode(['wage_fields_changed' => false]),
                    $row['id'] . '|wage', json_encode($ids),
                ]);
                $result['linked']++;
            }
            if (($plan['payment']['action'] ?? '') === 'post') {
                $ids = array_map('intval', $plan['wage']['finalized_ids'] ?? []);
                if ($finalizedId > 0) {
                    $ids[] = $finalizedId;
                }
                $targets = [];
                if (($plan['wage']['action'] ?? '') === 'create') {
                    $targets[] = ['id' => $finalizedId, 'remaining' => (float)$plan['wage']['net'], 'work_date' => (string)$row['period_end']];
                } else {
                    foreach (psr_fetch_system_lines($pdo, $orgId, (int)$row['user_id']) as $ln) {
                        if (in_array((int)$ln['id'], $ids, true) && $ln['net_known'] && $ln['paid'] !== null) {
                            $targets[] = ['id' => (int)$ln['id'], 'remaining' => round((float)$ln['net'] - (float)$ln['paid'], 2), 'work_date' => (string)$ln['work_date']];
                        }
                    }
                }
                $alloc = pstat_allocate((float)$plan['payment']['amount'], $targets);
                $sum = 0.0;
                foreach ($alloc as $bit) {
                    $sum += $bit['amount'];
                }
                if (abs(round($sum, 2) - (float)$plan['payment']['amount']) > 0.02) {
                    throw new RuntimeException('The actual payment could not be placed on the known remaining net. Nothing was posted.');
                }
                $paymentIds = [];
                foreach ($alloc as $bit) {
                    $payId = null;
                    $ref = 'SR' . $approvalId . 'R' . $row['id'] . 'L' . $bit['id'];
                    pcr_apply_payment($pdo, (int)$bit['id'], (float)$bit['amount'], (string)$plan['payment']['method'], $orgId, $payId, $ref, null, (string)$plan['payment']['date']);
                    if (!$payId) {
                        throw new RuntimeException('The payment was not stored. Nothing else from this approval was kept.');
                    }
                    $paymentIds[] = ['payment_id' => (int)$payId, 'finalized_id' => (int)$bit['id']];
                    $result['amount'] = round($result['amount'] + (float)$bit['amount'], 2);
                    $result['payments']++;
                }
                $pdo->prepare(
                    'INSERT INTO payroll_sheet_approval_effects
                        (approval_id, org_id, row_id, effect_kind, finalized_id, payment_id, detail_json, before_json, after_json, active_key)
                     VALUES (?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $approvalId, $orgId, (int)$row['id'], 'post_payment', $finalizedId, $paymentIds[0]['payment_id'] ?? null,
                    json_encode($paymentIds), null,
                    json_encode(['amount' => $plan['payment']['amount'], 'method' => $plan['payment']['method'], 'date' => $plan['payment']['date']]),
                    $row['id'] . '|payment',
                ]);
                $result['payment_pairs'] = $paymentIds;
            }
        }
        $pdo->prepare('UPDATE payroll_sheet_approvals SET result_json = ? WHERE id = ? AND org_id = ?')->execute([json_encode($result), $approvalId, $orgId]);
        $pdo->commit();
        return ['approval_id' => $approvalId, 'repeated' => false, 'result' => $result];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (stripos($e->getMessage(), 'Duplicate') !== false) {
            $prior->execute([$orgId, $idempotencyKey]);
            if ($old = $prior->fetch(PDO::FETCH_ASSOC)) {
                return ['approval_id' => (int)$old['id'], 'repeated' => true, 'result' => json_decode((string)$old['result_json'], true) ?: []];
            }
        }
        throw $e;
    }
}

function psr_undo_approval(PDO $pdo, int $orgId, int $actor, int $approvalId): array
{
    psr_ensure($pdo);
    pcr_ensure($pdo);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM payroll_sheet_approvals WHERE id = ? AND org_id = ? FOR UPDATE');
        $st->execute([$approvalId, $orgId]);
        $approval = $st->fetch(PDO::FETCH_ASSOC);
        if (!$approval) {
            throw new RuntimeException('That approval is not in this company.');
        }
        if (!empty($approval['undone_at'])) {
            $pdo->commit();
            return ['already' => true];
        }
        $ef = $pdo->prepare('SELECT * FROM payroll_sheet_approval_effects WHERE approval_id = ? AND org_id = ? AND undone_at IS NULL');
        $ef->execute([$approvalId, $orgId]);
        $effects = $ef->fetchAll(PDO::FETCH_ASSOC);
        foreach ($effects as $effect) {
            if ($effect['effect_kind'] !== 'post_payment') {
                continue;
            }
            $ids = json_decode((string)$effect['detail_json'], true);
            if (!is_array($ids) || !$ids) {
                $ids = [['payment_id' => (int)$effect['payment_id'], 'finalized_id' => (int)$effect['finalized_id']]];
            }
            foreach ($ids as $item) {
                if (is_array($item)) {
                    pcr_reverse_finalized_payment($pdo, (int)$item['payment_id'], (int)$item['finalized_id'], $orgId, $actor);
                } else {
                    pcr_reverse_finalized_payment($pdo, (int)$item, (int)$effect['finalized_id'], $orgId, $actor);
                }
            }
        }
        foreach ($effects as $effect) {
            if ($effect['effect_kind'] !== 'create_line') {
                continue;
            }
            $fid = (int)$effect['finalized_id'];
            $q = $pdo->prepare(
                'SELECT f.* FROM payroll_finalized f
                 INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
                 WHERE f.id = ? LIMIT 1'
            );
            $q->execute([$orgId, $fid]);
            $line = $q->fetch(PDO::FETCH_ASSOC);
            $safe = $line
                && (string)($line['comments'] ?? '') === 'sheet-review'
                && (float)($line['paid_amount'] ?? 0) <= 0.02
                && empty($line['attendance_id']);
            if ($safe) {
                $pay = $pdo->prepare('SELECT COUNT(*) FROM payroll_payments p INNER JOIN payroll_finalized f ON f.payroll_id = p.payroll_id WHERE f.id = ? AND p.reversed_at IS NULL');
                try {
                    $pay->execute([$fid]);
                    if ((int)$pay->fetchColumn() > 0) {
                        $safe = false;
                    }
                } catch (Throwable $e) {
                    $safe = false;
                }
            }
            if ($safe) {
                $pdo->prepare('DELETE FROM payroll_finalized WHERE id = ?')->execute([$fid]);
            }
        }
        $pdo->prepare('UPDATE payroll_sheet_approval_effects SET undone_at = NOW(), active_key = NULL WHERE approval_id = ? AND org_id = ?')->execute([$approvalId, $orgId]);
        $pdo->prepare('UPDATE payroll_sheet_approvals SET undone_at = NOW(), undone_by = ? WHERE id = ? AND org_id = ?')->execute([$actor, $approvalId, $orgId]);
        $pdo->commit();
        return ['already' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function psr_save_plan(PDO $pdo, int $orgId, int $actor, array $post): int
{
    psr_ensure($pdo);
    $date = psr_valid_date($post['plan_date'] ?? '');
    if ($date === null) {
        throw new RuntimeException('A plan needs a date.');
    }
    $available = psr_parse_amount($post['available_amount'] ?? null);
    if (!$available['ok']) {
        throw new RuntimeException('Amount available must be blank or a number. It was not treated as zero.');
    }
    $pdo->prepare(
        'INSERT INTO payroll_sheet_plans (org_id, plan_date, title, available_amount, note, created_by, created_at)
         VALUES (?,?,?,?,?,?,NOW())'
    )->execute([
        $orgId, $date, substr(trim((string)($post['title'] ?? '')), 0, 160), $available['value'],
        substr((string)($post['note'] ?? ''), 0, 2000), $actor,
    ]);
    $id = (int)$pdo->lastInsertId();
    psr_insert_revision($pdo, $orgId, 'plan', $id, 'create_plan', $actor, null, ['plan_date' => $date, 'available_amount' => $available['value']]);
    return $id;
}

function psr_save_plan_line(PDO $pdo, int $orgId, int $actor, int $planId, array $post): void
{
    psr_ensure($pdo);
    $plan = $pdo->prepare('SELECT id FROM payroll_sheet_plans WHERE id = ? AND org_id = ?');
    $plan->execute([$planId, $orgId]);
    if (!$plan->fetchColumn()) {
        throw new RuntimeException('That plan is not in this company.');
    }
    $amount = psr_parse_amount($post['proposed_amount'] ?? null);
    if (!$amount['ok']) {
        throw new RuntimeException('The proposed amount is not a number.');
    }
    $basis = (string)($post['amount_basis'] ?? 'unset');
    if (!in_array($basis, ['unset', 'gross', 'net'], true)) {
        $basis = 'unset';
    }
    $route = (string)($post['pay_route'] ?? 'unset');
    if (!in_array($route, ['unset', 'cash', 'adp'], true)) {
        $route = 'unset';
    }
    $party = (string)($post['party_class'] ?? 'unanswered');
    if (!in_array($party, ['unanswered', 'employee', 'contractor'], true)) {
        $party = 'unanswered';
    }
    $gst = ($party === 'contractor' && !empty($post['gst_verified'])) ? 1 : 0;
    $gstBase = $gst ? (psr_parse_amount($post['gst_invoice_principal'] ?? null)['value'] ?? null) : null;
    $pdo->prepare(
        'INSERT INTO payroll_sheet_plan_lines
            (org_id, plan_id, employee_name, proposed_amount, amount_basis, pay_route, party_class, gst_verified, gst_invoice_principal, note, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,NOW())'
    )->execute([
        $orgId, $planId, substr(trim((string)($post['employee_name'] ?? '')), 0, 160), $amount['value'],
        $basis, $route, $party, $gst, $gstBase, substr((string)($post['note'] ?? ''), 0, 2000),
    ]);
    psr_insert_revision($pdo, $orgId, 'plan', $planId, 'add_proposal', $actor, null, [
        'employee_name' => (string)($post['employee_name'] ?? ''),
        'proposed_amount' => $amount['value'],
        'amount_basis' => $basis,
        'pay_route' => $route,
    ]);
}

function psr_link_identity(PDO $pdo, int $orgId, int $userId, int $otherOrg, int $otherUser, int $actor, bool $super): void
{
    if (!$super || $otherOrg === $orgId) {
        throw new RuntimeException('A separate company can be shown only by a superadmin, and it is not added into this total.');
    }
    $a = $pdo->prepare('SELECT id FROM users WHERE id = ? AND org_id = ?');
    $a->execute([$userId, $orgId]);
    $b = $pdo->prepare('SELECT id FROM users WHERE id = ? AND org_id = ?');
    $b->execute([$otherUser, $otherOrg]);
    if (!$a->fetchColumn() || !$b->fetchColumn()) {
        throw new RuntimeException('Both people must already exist in their own companies. No one is created or matched by name.');
    }
    psr_ensure($pdo);
    $pdo->prepare(
        'INSERT IGNORE INTO payroll_sheet_identity_links (org_id, user_id, other_org_id, other_user_id, created_by, created_at)
         VALUES (?,?,?,?,?,NOW())'
    )->execute([$orgId, $userId, $otherOrg, $otherUser, $actor]);
}
