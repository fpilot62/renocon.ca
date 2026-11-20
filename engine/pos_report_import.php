<?php
/**
 * Path: /engine/pos_report_import.php
 * Read a Popeyes POS Day Report (and optional Paid Out slip) and return
 * NET SALES + Net Paid Out so Daily Activity Log can fill those columns.
 */
require_once __DIR__ . '/production_usage_import.php';

function pri_money_token(string $raw): ?float {
    $raw = trim(str_replace([',', ' ', "\xc2\xa0"], '', $raw));
    $raw = str_replace(['O', 'o'], '0', $raw);
    if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $raw)) {
        return null;
    }
    $n = round((float)$raw, 2);
    if (abs($n) > 9999999) {
        return null;
    }
    return $n;
}

function pri_has_net_sales(string $text): bool {
    return (bool)preg_match('/net\s*s[ao0]l/i', $text);
}

function pri_extract_text(string $path): string {
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $head = (string)@file_get_contents($path, false, null, 0, 16);
    $isPdf = strncmp($head, '%PDF', 4) === 0;
    if (!$isPdf) {
        $out = [];
        sip_run_cmd('tesseract -l eng ' . escapeshellarg($path) . ' stdout --psm 6 2>/dev/null', $out);
        return trim(implode("\n", $out));
    }
    $out = [];
    sip_run_cmd('pdftotext -layout ' . escapeshellarg($path) . ' - 2>/dev/null', $out);
    $text = trim(implode("\n", $out));
    if (pri_has_net_sales($text) && strlen($text) >= 80) {
        return $text;
    }
    $tmp = sys_get_temp_dir() . '/pri_' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0700, true);
    sip_run_cmd('pdfimages -j -f 1 -l 2 ' . escapeshellarg($path) . ' ' . escapeshellarg($tmp . '/p') . ' 2>/dev/null');
    $pages = glob($tmp . '/p*') ?: [];
    sort($pages);
    $ocr = [];
    $used = 0;
    foreach ($pages as $img) {
        if (!is_file($img) || filesize($img) < 20000) {
            @unlink($img);
            continue;
        }
        $lineOut = [];
        sip_run_cmd('tesseract -l eng ' . escapeshellarg($img) . ' stdout --psm 6 2>/dev/null', $lineOut);
        $ocr[] = implode("\n", $lineOut);
        @unlink($img);
        $used++;
        if ($used >= 2) {
            break;
        }
    }
    foreach (glob($tmp . '/p*') ?: [] as $left) {
        @unlink($left);
    }
    @rmdir($tmp);
    $joined = trim(implode("\n", $ocr));
    return strlen($joined) > strlen($text) ? $joined : $text;
}

function pri_find_amount(string $text, array $labelRegexes): ?float {
    $lines = preg_split('/\R/', $text);
    $n = count($lines);
    foreach ($labelRegexes as $re) {
        for ($i = 0; $i < $n; $i++) {
            if (!preg_match($re, $lines[$i])) {
                continue;
            }
            if (preg_match_all('/\$?\s*(-?[0-9]{1,3}(?:,[0-9]{3})+\.[0-9]{2}|-?[0-9]+\.[0-9]{2})/', $lines[$i], $mm)) {
                $amt = pri_money_token((string)end($mm[1]));
                if ($amt !== null) {
                    return $amt;
                }
            }
            if ($i > 0 && preg_match('/^\s*\$?\s*(-?[0-9][0-9,]*(?:\.[0-9]{2})?)\s*$/', $lines[$i - 1], $m)) {
                $amt = pri_money_token($m[1]);
                if ($amt !== null) {
                    return $amt;
                }
            }
            if ($i + 1 < $n && preg_match('/^\s*\$?\s*(-?[0-9][0-9,]*(?:\.[0-9]{2})?)\s*$/', $lines[$i + 1], $m)) {
                $amt = pri_money_token($m[1]);
                if ($amt !== null) {
                    return $amt;
                }
            }
        }
    }
    return null;
}

function pri_line_money_tokens(string $line): array {
    if (!preg_match_all('/\$?\s*(-?[0-9]{1,3}(?:,[0-9]{3})+\.[0-9]{2}|-?[0-9]+\.[0-9]{2})/', $line, $mm)) {
        return [];
    }
    $out = [];
    foreach ($mm[1] as $raw) {
        $amt = pri_money_token((string)$raw);
        if ($amt !== null) {
            $out[] = $amt;
        }
    }
    return $out;
}

function pri_paid_out_kind(string $line): string {
    if (preg_match('/gratuity\s+pa(?:id\s*outs?|j)/i', $line)) {
        return 'gratuity';
    }
    if (preg_match('/refund\s+paid\s*outs?/i', $line)) {
        return 'refund';
    }
    if (preg_match('/avg(?:erage)?\s+paid\s*outs?/i', $line)) {
        return 'avg';
    }
    if (preg_match('/net\s+paid\s*outs?/i', $line)) {
        return 'net';
    }
    if (preg_match('/paid\s*outs?/i', $line)) {
        return 'paid';
    }
    return '';
}

function pri_parse_pos_paid_out(string $text): ?float {
    $lines = preg_split('/\R/', $text);
    $n = count($lines);
    $paid = [];
    $net = [];
    $avg = [];
    $otherTender = '/\b(visa|mastercard|amex|american\s+express|debit|doordash|uber|skip|digital|popeyes\s+delivery)\b/i';
    for ($i = 0; $i < $n; $i++) {
        $line = $lines[$i];
        $kind = pri_paid_out_kind($line);
        if ($kind === '') {
            continue;
        }
        $amts = pri_line_money_tokens($line);
        if (count($amts) > 1 || preg_match($otherTender, $line) || preg_match('/^\s*[:.]?\s*\d+\s+\$/', $line) || preg_match('/paid\s*outs?\s*0\b/i', $line)) {
            $amts = [];
        }
        if (!$amts && $i + 1 < $n) {
            $nxt = $lines[$i + 1];
            $nxtKind = pri_paid_out_kind($nxt);
            if ($kind === 'paid' && $nxtKind === 'gratuity') {
                $amts = pri_line_money_tokens($nxt);
            } elseif (preg_match('/net\s+s[ao0]les|mastercard|visa|doordash|uber|skip/i', $nxt)) {
                $amts = [];
            } elseif ($nxtKind === '' && preg_match('/^\s*\$?\s*-?[0-9,]+\.\d{2}\s*$/', $nxt)) {
                $amts = pri_line_money_tokens($nxt);
            }
        }
        if (!$amts) {
            continue;
        }
        $amt = $amts[0];
        if ($kind === 'paid') {
            $paid[] = $amt;
        } elseif ($kind === 'net') {
            $net[] = $amt;
        } elseif ($kind === 'avg') {
            $avg[] = $amt;
        }
    }
    if ($paid) {
        return $paid[0];
    }
    if ($avg) {
        return $avg[0];
    }
    if (count($net) > 1) {
        return $net[count($net) - 1];
    }
    if ($net) {
        return $net[0];
    }
    return pri_find_amount($text, [
        '/\bnet\s+paid\s*outs?\b/i',
        '/\bpaid\s*outs?\b/i',
    ]);
}

function pri_parse_pos(string $text): array {
    $sales = pri_find_amount($text, [
        '/\bnet\s+sales\b/i',
        '/\bnet\s*s[ao0]les\b/i',
        '/\bnet\s+sale\b/i',
    ]);
    $paid = pri_parse_pos_paid_out($text);
    if ($sales !== null && $paid !== null && abs((float)$paid - (float)$sales) <= 0.05) {
        $paid = null;
    }
    if ($paid !== null && $paid > 1500) {
        $paid = pri_find_amount($text, ['/\bnet\s+paid\s*outs?\b/i', '/\bpaid\s*outs?\b/i']);
        if ($paid !== null && $paid > 1500) {
            $paid = null;
        }
    }
    return [
        'net_sales' => $sales,
        'paid_out' => $paid,
    ];
}

function pri_parse_paidout_slip(string $text): ?float {
    return pri_find_amount($text, [
        '/\bnet\s+paid\s*outs?\b/i',
        '/\bpaid\s*outs?\b/i',
        '/\btotal\b/i',
    ]);
}

function pri_sidecar_path(string $path): string {
    return preg_replace('/\.[^.]+$/', '', $path) . '.json';
}

function pri_write_unclear_meta(string $path, array $read): void {
    $metaFile = pri_sidecar_path($path);
    $meta = [];
    if (is_file($metaFile)) {
        $j = json_decode((string)@file_get_contents($metaFile), true);
        if (is_array($j)) {
            $meta = $j;
        }
    }
    $meta['unclear'] = !empty($read['unclear']);
    $meta['unclear_reason'] = (string)($read['unclear_reason'] ?? '');
    if (array_key_exists('net_sales', $read)) {
        $meta['net_sales_read'] = $read['net_sales'];
    }
    if (array_key_exists('paid_out', $read)) {
        $meta['paid_out_read'] = $read['paid_out'];
    }
    @file_put_contents($metaFile, json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function pri_image_quality(string $img): array {
    $out = [];
    sip_run_cmd('identify -format "%w %h" ' . escapeshellarg($img) . ' 2>/dev/null', $out);
    $dim = trim(implode(' ', $out));
    $w = 0;
    $h = 0;
    if (preg_match('/(\d+)\s+(\d+)/', $dim, $m)) {
        $w = (int)$m[1];
        $h = (int)$m[2];
    }
    $lapOut = [];
    sip_run_cmd(
        'convert ' . escapeshellarg($img) . ' -colorspace Gray -resize ' . escapeshellarg('800x800>') . ' -morphology Convolve Laplacian:0 -format "%[fx:standard_deviation]" info: 2>/dev/null',
        $lapOut
    );
    $lap = (float)trim(implode('', $lapOut));
    $minSide = min($w, $h);
    $unclear = false;
    $reason = '';
    if ($w > 0 && $minSide < 500) {
        $unclear = true;
        $reason = 'POS photo is too small';
    } elseif ($lap > 0 && $lap < 0.12) {
        $unclear = true;
        $reason = 'POS photo is not clear';
    }
    return ['width' => $w, 'height' => $h, 'laplacian' => $lap, 'unclear' => $unclear, 'reason' => $reason];
}

function pri_file_image_quality(string $path): array {
    if (!is_file($path) || !is_readable($path)) {
        return ['unclear' => true, 'reason' => 'POS file is missing'];
    }
    $head = (string)@file_get_contents($path, false, null, 0, 16);
    $isPdf = strncmp($head, '%PDF', 4) === 0;
    if (!$isPdf) {
        return pri_image_quality($path);
    }
    $tmp = sys_get_temp_dir() . '/priq_' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0700, true);
    sip_run_cmd('pdfimages -j -f 1 -l 1 ' . escapeshellarg($path) . ' ' . escapeshellarg($tmp . '/p') . ' 2>/dev/null');
    $pages = glob($tmp . '/p*') ?: [];
    sort($pages);
    $best = ['unclear' => false, 'reason' => ''];
    foreach ($pages as $img) {
        if (!is_file($img) || filesize($img) < 20000) {
            @unlink($img);
            continue;
        }
        $best = pri_image_quality($img);
        @unlink($img);
        break;
    }
    foreach (glob($tmp . '/p*') ?: [] as $left) {
        @unlink($left);
    }
    @rmdir($tmp);
    return $best;
}

function pri_read_pos_file(string $path): array {
    $text = pri_extract_text($path);
    $parsed = pri_parse_pos($text);
    $quality = pri_file_image_quality($path);
    $unclear = false;
    $reason = '';
    if ($parsed['net_sales'] === null || !pri_has_net_sales($text)) {
        $unclear = true;
        $reason = 'NET SALES could not be read';
    }
    if (!empty($quality['unclear'])) {
        $unclear = true;
        $reason = $quality['reason'] ?: 'POS photo is not clear';
    }
    $parsed['ok'] = $parsed['net_sales'] !== null && !$unclear;
    $parsed['text_len'] = strlen($text);
    $parsed['unclear'] = $unclear;
    $parsed['unclear_reason'] = $reason;
    if ($unclear) {
        $parsed['message'] = 'POS photo is not clear — flagged for admin. Upload a sharper Day Report.';
    } elseif ($parsed['ok']) {
        $parsed['message'] = 'Sales $' . number_format((float)$parsed['net_sales'], 2) . ' from POS Report.';
    } else {
        $parsed['message'] = 'POS file saved, but NET SALES could not be read. Upload a clearer Day Report.';
    }
    pri_write_unclear_meta($path, $parsed);
    return $parsed;
}
