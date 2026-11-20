<?php
/**
 * Path: /engine/invoice_delivery_import.php
 * Read a vendor invoice (PDF or photo) and match lines to inventory items
 * so Food Cost + Inventory → Log Delivery can be filled.
 */

if (!function_exists('pui_extract_text')) {
    $pui = __DIR__ . '/production_usage_import.php';
    if (is_file($pui)) {
        require_once $pui;
    }
}


if (!function_exists('sip_run_cmd')) {
    /**
     * Run a shell command. Web PHP on this host disables exec/shell_exec/system
     * but allows proc_open — invoice OCR must use this or the upload 500s.
     */
    function sip_run_cmd(string $cmd, ?array &$out = null): int {
        $out = [];
        if (function_exists('exec')) {
            @exec($cmd, $out, $code);
            return (int)$code;
        }
        if (!function_exists('proc_open')) {
            return 127;
        }
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            return 127;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (is_resource($pipes[2])) {
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
        }
        $code = proc_close($proc);
        $stdout = (string)$stdout;
        $out = $stdout === '' ? [] : preg_split('/\R/', rtrim($stdout));
        return (int)$code;
    }
}

function idi_norm(string $s): string {
    if (function_exists('pui_norm_name')) {
        return pui_norm_name($s);
    }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

function idi_extract_text(string $path): string {
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'tif', 'tiff', 'bmp'], true)) {
        $out = [];
        sip_run_cmd('tesseract -l eng ' . escapeshellarg($path) . ' stdout --psm 6 2>/dev/null', $out);
        return trim(implode("\n", $out));
    }
    $out = [];
    sip_run_cmd('pdftotext -layout ' . escapeshellarg($path) . ' - 2>/dev/null', $out);
    $text = trim(implode("\n", $out));
    if (strlen($text) >= 80) {
        return $text;
    }
    if (function_exists('pui_extract_text')) {
        $ocr = trim(pui_extract_text($path));
        if (strlen($ocr) > strlen($text)) {
            return $ocr;
        }
    }
    return $text;
}

function idi_money(string $raw): float {
    $raw = str_replace([',', '$', ' '], '', $raw);
    if (!preg_match('/-?\d+(?:\.\d{1,4})?/', $raw, $m)) {
        return 0.0;
    }
    return round((float)$m[0], 2);
}

/** Case/box price: Sysco often prints a per-lb rate; extension ÷ qty is the box. */
function idi_invoice_case_rate(float $qty, float $rate, float $ext): float {
    if ($qty > 0 && $ext != 0.0) {
        $implied = round($ext / $qty, 2);
        if ($rate <= 0) {
            return $implied;
        }
        $den = max(abs($implied), abs($rate), 0.01);
        if (abs($implied - $rate) / $den > 0.12) {
            return $implied;
        }
    }
    return $rate;
}


function idi_sysco_pack(string $raw): array {
    $out = ['purchase_unit' => 'Box', 'count_unit' => 'Ea', 'pcs_in_box' => 0.0];
    if (preg_match('/\bN\s+(\d+)\s+(\d+(?:\.\d+)?)?\s*(KG|LB|OZ|CT|ML|#|GR)?/i', $raw, $m)) {
        $n = (float)$m[1];
        $sz = (float)($m[2] ?? 0);
        $u = strtoupper(trim((string)($m[3] ?? '')));
        if ($u === 'CT') {
            $out['pcs_in_box'] = ($n <= 2 && $sz >= 4) ? $sz : $n;
        } elseif ($u === 'OZ' && $sz > 0 && $sz <= 8) {
            $out['pcs_in_box'] = $n;
        } elseif ($u === 'ML' && $n >= 4) {
            $out['pcs_in_box'] = $n;
        }
    }
    return $out;
}

function idi_line_units(array $line): array {
    return [
        'purchase_unit' => trim((string)($line['purchase_unit'] ?? '')) ?: 'Box',
        'count_unit' => trim((string)($line['count_unit'] ?? '')) ?: 'Ea',
        'pcs_in_box' => (float)($line['pcs_in_box'] ?? 0),
    ];
}

function idi_site_org(PDO $pdo, int $rid): int {
    $st = $pdo->prepare('SELECT org_id FROM sites WHERE SId = ?');
    $st->execute([$rid]);
    $org = (int)$st->fetchColumn();
    return $org > 0 ? $org : (int)($_SESSION['org_id'] ?? 0);
}

/** @return list<array{id:int,name:string,norm:string,cost:float}> */
function idi_catalog(PDO $pdo, int $rid): array {
    $st = $pdo->prepare(
        'SELECT id AS Id, Name, clearview_name, cost_pc
         FROM Gem_inventory_items
         WHERE IsActive = 1 AND (Restaurant_Id = 0 OR Restaurant_Id = ?)
         ORDER BY Name ASC'
    );
    $st->execute([$rid]);
    $out = [];
    foreach ($st as $r) {
        $name = trim((string)$r['Name']);
        $clear = trim((string)($r['clearview_name'] ?? ''));
        $out[] = [
            'id' => (int)($r['Id'] ?? $r['id'] ?? 0),
            'name' => $name,
            'norm' => idi_norm($name . ' ' . $clear),
            'cost' => (float)$r['cost_pc'],
        ];
    }
    return $out;
}

function idi_match_item(string $name, array $catalog): ?array {
    static $alias = [
        'chicken strip mild halal' => 'chicken brst strips',
        'chicken nugget' => 'nuggets',
        'potato fry batrd crispy cajn' => 'potato fry',
        'entree macaroni cheese dlx' => 'mac n cheese',
        'bun brioche style' => 'buns',
        'biscuit ready to bake cl intl' => 'biscuit ready to bake',
        'pie strawberry crm chs frd cln' => 'strawberry cream pie',
        'mix gravy' => 'gravy',
        'dressing coleslaw slurry' => 'coleslaw',
        'sauce honey mustard portioned' => 'dipping sauce',
        'soda coke classic' => 'bottle bev',
        'juice apple jtg' => 'bottle juice',
        'chicken brst fillet bnls skls' => 'chicken brst fillet',
        'chicken leg bck atch splt hal' => 'chicken leg bck',
        'chicken cut 9 halal' => 'chicken cut 9',
        'onion ring tempura battered' => 'onion ring',
        'shortening fry mb palm' => 'shortening fry',
    ];
    $n = idi_norm($name);
    if (isset($alias[$n])) {
        $n = idi_norm($alias[$n]);
    }
    if ($n === '' || strlen($n) < 3) {
        return null;
    }
    $best = null;
    $bestScore = 0.0;
    foreach ($catalog as $row) {
        $c = $row['norm'];
        if ($c === '') {
            continue;
        }
        if ($n === $c) {
            return $row;
        }
        if (strpos($c, $n) !== false || strpos($n, $c) !== false) {
            $score = 90.0 + min(9.0, strlen($n) / 4);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
            continue;
        }
        similar_text($n, $c, $pct);
        if ($pct > $bestScore) {
            $bestScore = $pct;
            $best = $row;
        }
    }
    if ($best && $bestScore >= 72) {
        return $best;
    }
    return null;
}

function idi_guess_vendor(string $text): string {
    $known = [
        'gordon food' => 'Gordon Food Service',
        'gfs' => 'GFS',
        'sysco' => 'Sysco',
        'us foods' => 'US Foods',
        'restaurant depot' => 'Restaurant Depot',
        'costco' => 'Costco',
        'sfeer' => 'Sfeer',
        'freshpoint' => 'FreshPoint',
        'produce' => '',
    ];
    $low = strtolower($text);
    foreach ($known as $needle => $label) {
        if ($label !== '' && strpos($low, $needle) !== false) {
            return $label;
        }
    }
    foreach (preg_split('/\R/', $text) as $i => $line) {
        if ($i > 12) {
            break;
        }
        $line = trim($line);
        if (strlen($line) < 3 || strlen($line) > 60) {
            continue;
        }
        if (preg_match('/invoice|statement|page |ship to|bill to|phone|tel|fax|date|total/i', $line)) {
            continue;
        }
        if (preg_match('/\d{5,}/', $line)) {
            continue;
        }
        return $line;
    }
    return '';
}

function idi_guess_date(string $text, string $fallback): string {
    if (preg_match('/\b(20\d{2})\s+(\d{2})\s+(\d{2})\b/', $text, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/(?:invoice\s*date|inv\s*date|date)[:\s]+(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/i', $text, $m)
        || preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $m)
        || preg_match('/\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\b/', $text, $m)
    ) {
        $raw = $m[1];
        $ts = strtotime(str_replace('-', '/', $raw));
        if ($ts) {
            return date('Y-m-d', $ts);
        }
    }
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $fallback) ? $fallback : date('Y-m-d');
}

function idi_guess_invoice_no(string $text): string {
    if (preg_match('/INVOICE:\s+(\d{8,14})/', $text, $m)) {
        return $m[1];
    }
    if (preg_match('/invoice\s*(?:number|no\.?|#)\s*[:#]?\s*([A-Z0-9\-]{4,20})/i', $text, $m)) {
        return strtoupper($m[1]);
    }
    if (preg_match('/\binv(?:oice)?\s*#\s*([A-Z0-9\-]{4,20})/i', $text, $m)) {
        return strtoupper($m[1]);
    }
    return '';
}

function idi_guess_total(string $text): float {
    if (preg_match_all('/TOTAL COMMANDE\s+\$\s*([0-9,]+\.\d{2})(-)?/', $text, $ms, PREG_SET_ORDER)) {
        $last = end($ms);
        $amt = idi_money($last[1]);
        if (!empty($last[2])) {
            $amt = -$amt;
        }
        if ($amt != 0.0) {
            return $amt;
        }
    }
    if (preg_match('/INV SUB-TOTAL BEFORE TAXES\s+([0-9,]+\.\d{2})(-)?/', $text, $m)) {
        $amt = idi_money($m[1]);
        return !empty($m[2]) ? -$amt : $amt;
    }
    if (preg_match('/(?:amount\s*due|invoice\s*total|total\s*due|balance\s*due|grand\s*total)[^\d$]{0,20}\$?\s*([0-9,]+\.\d{2})/i', $text, $m)) {
        return idi_money($m[1]);
    }
    $last = 0.0;
    foreach (preg_split('/\R/', $text) as $line) {
        if (preg_match('/\btotal\b/i', $line) && preg_match('/\$?\s*([0-9,]+\.\d{2})-?\s*$/', $line, $m)) {
            $last = idi_money($m[1]);
            if (substr(rtrim($line), -1) === '-') {
                $last = -$last;
            }
        }
    }
    return $last;
}

function idi_skip_line(string $line): bool {
    if ($line === '' || strlen($line) < 4) {
        return true;
    }
    return (bool)preg_match('/invoice|statement|page |ship to|bill to|sold to|phone|tel |fax |subtotal|gst|hst|pst|qst|hst\/gst|tax\b|amount due|balance|thank you|account #|customer #|po #|terms\b|due date|payment/i', $line);
}

function idi_sysco_name(string $raw): string {
    if (preg_match('/CREDIT FOR CHICKEN/i', $raw)) {
        return 'CREDIT FOR CHICKEN';
    }
    if (!preg_match('/(?:POPEYES|NONPROD|CAMPBEL|COCACOL|MINMAID|INTEPLS|CASCSEL|FUZE)\s+(.+?)\s+\d+(?:,\d{3})*\.\d{2}\s+\d+(?:,\d{3})*\.\d{2}\s*$/', $raw, $n)) {
        return '';
    }
    $name = trim(preg_replace('/\s+/', ' ', $n[1]));
    while (preg_match('/\s+(\d{3,}[A-Z0-9]*|[A-Z]{1,3}\d{2,}|G|GP|\d+\.\d{2})$/', $name, $sku)) {
        $name = trim(substr($name, 0, -strlen($sku[0])));
    }
    return trim((string)$name);
}

/**
 * @return list<array{name:string,qty:float,rate:float,ext:float}>
 */
function idi_parse_sysco_lines(string $text): array {
    $lines = [];
    foreach (preg_split('/\R/', $text) as $raw) {
        if (!preg_match('/^\s*(\d{6,10})\+?\s+(\d+(?:\.\d+)?)-?\s+(\d+(?:\.\d+)?)-?/', $raw, $m)) {
            continue;
        }
        $qty = (float)$m[3];
        if ($qty <= 0) {
            continue;
        }
        if (!preg_match('/(\d+(?:,\d{3})*\.\d{2})-?\s+(\d+(?:,\d{3})*\.\d{2})-?\s*$/', $raw, $p)) {
            continue;
        }
        $rate = idi_money($p[1]);
        $ext = idi_money($p[2]);
        $name = idi_sysco_name($raw);
        if ($name === '' || preg_match('/fuel|stewardship|credit reason/i', $name)) {
            continue;
        }
        $pack = idi_sysco_pack($raw);
        $lines[] = [
            'name' => $name,
            'qty' => $qty,
            'rate' => $rate,
            'ext' => $ext,
            'purchase_unit' => $pack['purchase_unit'],
            'count_unit' => $pack['count_unit'],
            'pcs_in_box' => $pack['pcs_in_box'],
        ];
    }
    return $lines;
}

function idi_parse_lines(string $text): array {
    if (stripos($text, 'SYSCO') !== false || stripos($text, 'ITEM NO.') !== false) {
        $sysco = idi_parse_sysco_lines($text);
        if ($sysco) {
            return $sysco;
        }
    }
    $lines = [];
    foreach (preg_split('/\R/', $text) as $raw) {
        $line = trim(preg_replace('/[|\[\]]+/', ' ', $raw));
        $line = preg_replace('/\s+/', ' ', $line);
        if (idi_skip_line($line)) {
            continue;
        }
        // name  qty  rate  ext
        if (preg_match('/^(.{3,80}?)\s+(\d+(?:\.\d{1,3})?)\s+\$?([0-9,]+\.\d{2})\s+\$?([0-9,]+\.\d{2})\s*$/', $line, $m)) {
            $name = trim($m[1], " .-#");
            $name = preg_replace('/^[A-Z0-9]{4,14}\s+/', '', $name);
            $qty = (float)$m[2];
            $rate = idi_money($m[3]);
            $ext = idi_money($m[4]);
            if ($qty > 0 && $qty < 5000 && $name !== '') {
                $lines[] = ['name' => $name, 'qty' => $qty, 'rate' => $rate, 'ext' => $ext];
            }
            continue;
        }
        // name  qty x rate
        if (preg_match('/^(.{3,80}?)\s+(\d+(?:\.\d{1,3})?)\s*[xX@]\s*\$?([0-9,]+\.\d{2})/', $line, $m)) {
            $name = trim($m[1], " .-#");
            $qty = (float)$m[2];
            $rate = idi_money($m[3]);
            if ($qty > 0 && $name !== '') {
                $lines[] = ['name' => $name, 'qty' => $qty, 'rate' => $rate, 'ext' => round($qty * $rate, 2)];
            }
            continue;
        }
        // name .... $rate (no qty — treat qty 1 if a unit price looks real)
        if (preg_match('/^(.{6,80}?)\s+\$([0-9,]+\.\d{2})\s*$/', $line, $m)
            && !preg_match('/total|due|tax/i', $m[1])
        ) {
            $name = trim($m[1], " .-#");
            $rate = idi_money($m[2]);
            if ($rate > 0 && $rate < 2000 && $name !== '') {
                $lines[] = ['name' => $name, 'qty' => 1.0, 'rate' => $rate, 'ext' => $rate];
            }
        }
    }
    return $lines;
}

/**
 * @return array{
 *   ok:bool,vendor:string,date:string,invoice_no:string,amount:float,
 *   items:array<int,array{qty:float,rate:float,name:string}>,
 *   unmatched:list<array{name:string,qty:float,rate:float}>,
 *   matched:int,message:string,error?:string,text:string
 * }
 */
function idi_read_invoice(PDO $pdo, string $path, int $rid, string $fallbackDate = ''): array {
    $empty = [
        'ok' => false,
        'vendor' => '',
        'date' => $fallbackDate,
        'invoice_no' => '',
        'amount' => 0.0,
        'items' => [],
        'unmatched' => [],
        'matched' => 0,
        'message' => '',
        'text' => '',
    ];
    try {
        $text = idi_extract_text($path);
    } catch (Throwable $e) {
        error_log('idi_extract_text: ' . $e->getMessage());
        $text = '';
    }
    $empty['text'] = $text;
    if ($text === '') {
        $empty['error'] = 'Could not read this file. A clear PDF or photo works best.';
        return $empty;
    }
    $catalog = idi_catalog($pdo, $rid);
    $vendor = idi_guess_vendor($text);
    $date = idi_guess_date($text, $fallbackDate);
    $invNo = idi_guess_invoice_no($text);
    $amount = idi_guess_total($text);
    $items = [];
    $unmatched = [];
    foreach (idi_parse_lines($text) as $line) {
        $lineRate = idi_invoice_case_rate(
            (float)($line['qty'] ?? 0),
            (float)($line['rate'] ?? 0),
            (float)($line['ext'] ?? 0)
        );
        $line['rate'] = $lineRate;
        $hit = idi_match_item($line['name'], $catalog);
        if ($hit) {
            $id = (int)($hit['id'] ?? $hit['Id'] ?? 0);
            if ($id <= 0) {
                $unmatched[] = $line;
                continue;
            }
            $units = idi_line_units($line);
            if (!isset($items[$id])) {
                $items[$id] = [
                    'qty' => 0.0,
                    'rate' => $lineRate,
                    'name' => $hit['name'],
                    'purchase_unit' => $units['purchase_unit'],
                    'count_unit' => $units['count_unit'],
                    'pcs_in_box' => $units['pcs_in_box'],
                ];
            }
            $items[$id]['qty'] += $line['qty'];
            if ($lineRate > 0) {
                $items[$id]['rate'] = $lineRate;
            }
            if ($units['pcs_in_box'] > 0 && (float)($items[$id]['pcs_in_box'] ?? 0) <= 0) {
                $items[$id]['pcs_in_box'] = $units['pcs_in_box'];
            }
            if (empty($items[$id]['purchase_unit']) && $units['purchase_unit'] !== '') {
                $items[$id]['purchase_unit'] = $units['purchase_unit'];
            }
        } else {
            $unmatched[] = $line;
        }
    }
    $sumLines = 0.0;
    foreach ($items as $row) {
        $sumLines += $row['qty'] * $row['rate'];
    }
    if ($amount <= 0 && $sumLines > 0) {
        $amount = round($sumLines, 2);
    }
    $matched = count($items);
    $ok = $matched > 0 || $amount > 0 || $vendor !== '';
    $msg = $matched . ' item' . ($matched === 1 ? '' : 's') . ' matched to inventory';
    if ($unmatched) {
        $msg .= ', ' . count($unmatched) . ' line' . (count($unmatched) === 1 ? '' : 's') . ' not matched';
    }
    if ($matched === 0) {
        $msg = 'Invoice saved. No catalog names matched yet — check the file after this test upload and we will tune the reader.';
    }
    return [
        'ok' => $ok,
        'vendor' => $vendor,
        'date' => $date,
        'invoice_no' => $invNo,
        'amount' => $amount,
        'items' => $items,
        'unmatched' => $unmatched,
        'matched' => $matched,
        'message' => $msg,
        'text' => $text,
    ];
}

/**
 * Same catalog item from another invoice: add qty. Rate is qty-weighted.
 *
 * @param array<int,array{qty:float,rate:float,name?:string}> $base
 * @param array<int,array{qty:float,rate:float,name?:string}> $add
 * @return array<int,array{qty:float,rate:float,name:string}>
 */
function idi_merge_item_maps(array $base, array $add): array {
    foreach ($add as $id => $row) {
        $id = (int)$id;
        if ($id <= 0 || !is_array($row)) {
            continue;
        }
        $addQty = (float)($row['qty'] ?? 0);
        $addRate = (float)($row['rate'] ?? 0);
        $addName = (string)($row['name'] ?? '');
        if (!isset($base[$id])) {
            $base[$id] = ['qty' => $addQty, 'rate' => $addRate, 'name' => $addName];
            continue;
        }
        $q1 = (float)$base[$id]['qty'];
        $r1 = (float)$base[$id]['rate'];
        $sum = $q1 + $addQty;
        $base[$id]['qty'] = $sum;
        if ($sum > 0) {
            $base[$id]['rate'] = round(($q1 * $r1 + $addQty * $addRate) / $sum, 2);
        } elseif ($addRate > 0) {
            $base[$id]['rate'] = $addRate;
        }
        if (($base[$id]['name'] ?? '') === '' && $addName !== '') {
            $base[$id]['name'] = $addName;
        }
    }
    return $base;
}

/**
 * Read every Food Cost PDF for this restaurant + date and merge similar items.
 *
 * @return array{
 *   items:array<int,array{qty:float,rate:float,name:string}>,
 *   unmatched:list<array{name:string,qty:float,rate:float}>,
 *   amount:float,vendor:string,invoice_no:string,date:string,
 *   fcids:list<int>,invoice_nos:list<string>,count:int,message:string
 * }
 */
function idi_merge_same_date_invoices(PDO $pdo, string $root, int $rid, string $date, array $seed = []): array {
    $items = [];
    $unmatched = [];
    $amount = 0.0;
    $fcids = [];
    $invNos = [];
    $vendors = [];
    $seenRel = [];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    $root = rtrim($root, '/');
    try {
        $st = $pdo->prepare(
            'SELECT FCId, InvoiceDate, Vendor, Amount, InvoiceNumber, Invoice
             FROM FoodCost
             WHERE RId = ? AND InvoiceDate = ? AND Invoice IS NOT NULL AND Invoice <> ""
             ORDER BY FCId ASC'
        );
        $st->execute([$rid, $date]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    foreach ($rows as $row) {
        $fcid = (int)$row['FCId'];
        if ($fcid > 0 && !in_array($fcid, $fcids, true)) {
            $fcids[] = $fcid;
        }
        if (!empty($row['InvoiceNumber']) && !in_array((string)$row['InvoiceNumber'], $invNos, true)) {
            $invNos[] = (string)$row['InvoiceNumber'];
        }
        if (!empty($row['Vendor']) && !in_array((string)$row['Vendor'], $vendors, true)) {
            $vendors[] = (string)$row['Vendor'];
        }
        $rel = ltrim(str_replace('\\', '/', (string)$row['Invoice']), '/');
        if ($rel === '' || isset($seenRel[$rel])) {
            $amount += (float)$row['Amount'];
            continue;
        }
        $seenRel[$rel] = $fcid;
        $abs = $root . '/' . $rel;
        if (!is_file($abs)) {
            $amount += (float)$row['Amount'];
            continue;
        }
        try {
            $read = idi_read_invoice($pdo, $abs, $rid, $date);
        } catch (Throwable $e) {
            error_log('idi_merge_same_date_invoices: ' . $e->getMessage());
            $amount += (float)$row['Amount'];
            continue;
        }
        $items = idi_merge_item_maps($items, $read['items'] ?? []);
        if (!empty($read['unmatched']) && is_array($read['unmatched'])) {
            $unmatched = array_merge($unmatched, $read['unmatched']);
        }
        $amount += (float)($read['amount'] ?? $row['Amount']);
        if (!empty($read['invoice_no']) && !in_array((string)$read['invoice_no'], $invNos, true)) {
            $invNos[] = (string)$read['invoice_no'];
        }
    }

    $seedRel = ltrim(str_replace('\\', '/', (string)($seed['file'] ?? '')), '/');
    $seedAlready = $seedRel !== '' && isset($seenRel[$seedRel]);
    if (!$seedAlready && !empty($seed['items']) && is_array($seed['items'])) {
        $items = idi_merge_item_maps($items, $seed['items']);
        if (!empty($seed['unmatched']) && is_array($seed['unmatched'])) {
            $unmatched = array_merge($unmatched, $seed['unmatched']);
        }
        $amount += (float)($seed['amount'] ?? 0);
        if (!empty($seed['invoice_no']) && !in_array((string)$seed['invoice_no'], $invNos, true)) {
            $invNos[] = (string)$seed['invoice_no'];
        }
        if (!empty($seed['vendor']) && !in_array((string)$seed['vendor'], $vendors, true)) {
            $vendors[] = (string)$seed['vendor'];
        }
        $sid = (int)($seed['fcid'] ?? 0);
        if ($sid > 0 && !in_array($sid, $fcids, true)) {
            $fcids[] = $sid;
        }
    }

    $nInv = count($fcids);
    if (!$seedAlready && !empty($seed['items']) && $seedRel !== '' && !isset($seenRel[$seedRel])) {
        $nInv = count($fcids) + ($sid ?? 0 ? 0 : 1);
        if ($nInv < 1) {
            $nInv = 1;
        }
    }
    if ($nInv < 1 && ($items || $rows)) {
        $nInv = max(1, count($rows));
    }

    $matched = count($items);
    $msg = $matched . ' item' . ($matched === 1 ? '' : 's') . ' matched to inventory';
    if ($nInv > 1) {
        $msg = $nInv . ' invoices on ' . $date . ' filled together · ' . $msg . ' (same items added)';
    }
    if ($unmatched) {
        $msg .= ', ' . count($unmatched) . ' line' . (count($unmatched) === 1 ? '' : 's') . ' not matched';
    }
    if ($matched === 0) {
        $msg = ($nInv > 1 ? $nInv . ' invoices on ' . $date . '. ' : '')
            . 'No catalog names matched yet — check the files and we will tune the reader.';
    }

    return [
        'items' => $items,
        'unmatched' => $unmatched,
        'amount' => $amount,
        'vendor' => $vendors[0] ?? (string)($seed['vendor'] ?? ''),
        'invoice_no' => implode(' + ', $invNos),
        'date' => $date,
        'fcids' => $fcids,
        'invoice_nos' => $invNos,
        'count' => $nInv,
        'message' => $msg,
    ];
}

function idi_apply_same_date_prefill(PDO $pdo, string $root, int $rid, array $prefill): array {
    $date = (string)($prefill['date'] ?? '');
    $merged = idi_merge_same_date_invoices($pdo, $root, $rid, $date, $prefill);
    $clickedInv = (string)($prefill['invoice_num'] ?? '');
    $prefill['items'] = $merged['items'];
    $prefill['unmatched'] = $merged['unmatched'];
    $prefill['message'] = $merged['message'];
    $prefill['fcids'] = $merged['fcids'];
    $prefill['same_date_invoices'] = $merged['invoice_nos'];
    $prefill['amount'] = $merged['amount'];
    if (($prefill['vendor'] ?? '') === '') {
        $prefill['vendor'] = $merged['vendor'];
    }
    if ($clickedInv === '' && !empty($merged['invoice_nos'][0])) {
        $prefill['invoice_num'] = $merged['invoice_nos'][0];
    }
    return $prefill;
}

/**
 * Write invoice case prices onto master items (Cost/Box + Cost/Pc).
 * Unmatched invoice names are added so they can be edited on manage_items.
 *
 * @param array<int,array{qty?:float,rate:float,name?:string}> $items
 * @param list<array{name:string,qty?:float,rate?:float,ext?:float}> $unmatched
 * @return array{updated:int,added:int,ids:list<int>}
 */
function idi_apply_prices_to_catalog(PDO $pdo, int $orgId, array $items, array $unmatched = []): array {
    $updated = 0;
    $added = 0;
    $ids = [];
    if ($orgId <= 0) {
        $orgId = 1;
    }
    $get = $pdo->prepare('SELECT id, pcs_in_box, purchase_unit, count_unit, Unit FROM Gem_inventory_items WHERE id = ?');
    $upd = $pdo->prepare(
        'UPDATE Gem_inventory_items
         SET cost_box = ?, cost_pc = ?, purchase_unit = ?, count_unit = ?, Unit = ?, pcs_in_box = ?
         WHERE id = ?'
    );
    $write = static function (array $cur, float $rate, array $units) use ($upd, &$updated, &$ids): void {
        $id = (int)$cur['id'];
        $pcs = (float)$cur['pcs_in_box'];
        $newPcs = (float)($units['pcs_in_box'] ?? 0);
        if ($pcs <= 1 && $newPcs > 1) {
            $pcs = $newPcs;
        }
        if ($pcs <= 0) {
            $pcs = 1.0;
        }
        $pUnit = trim((string)($units['purchase_unit'] ?? '')) ?: (trim((string)($cur['purchase_unit'] ?? '')) ?: 'Box');
        $cUnit = trim((string)($units['count_unit'] ?? '')) ?: (trim((string)($cur['count_unit'] ?? '')) ?: 'Ea');
        $unit = trim((string)($cur['Unit'] ?? '')) ?: $pUnit;
        $costPc = $pcs > 0 ? round($rate / $pcs, 4) : $rate;
        $upd->execute([round($rate, 2), $costPc, $pUnit, $cUnit, $unit, $pcs, $id]);
        $updated++;
        $ids[] = $id;
    };
    foreach ($items as $id => $row) {
        $id = (int)$id;
        $rate = (float)($row['rate'] ?? 0);
        if ($id <= 0 || $rate <= 0) {
            continue;
        }
        $get->execute([$id]);
        $cur = $get->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            continue;
        }
        $write($cur, $rate, idi_line_units($row));
    }
    $exists = $pdo->prepare(
        'SELECT id, pcs_in_box, purchase_unit, count_unit, Unit FROM Gem_inventory_items
         WHERE org_id = ? AND IsActive = 1 AND (LOWER(name) = LOWER(?) OR LOWER(clearview_name) = LOWER(?))
         LIMIT 1'
    );
    $ins = $pdo->prepare(
        'INSERT INTO Gem_inventory_items
            (org_id, site_id, Restaurant_Id, name, purchase_unit, count_unit, Unit, pcs_in_box, cost_box, cost_pc, category, IsActive)
         VALUES (?, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    foreach ($unmatched as $u) {
        $name = trim((string)($u['name'] ?? ''));
        $qty = (float)($u['qty'] ?? 0);
        $rate = idi_invoice_case_rate($qty, (float)($u['rate'] ?? 0), (float)($u['ext'] ?? 0));
        if ($name === '' || $rate <= 0 || preg_match('/credit|fuel|stewardship/i', $name)) {
            continue;
        }
        $units = idi_line_units($u);
        $exists->execute([$orgId, $name, $name]);
        $cur = $exists->fetch(PDO::FETCH_ASSOC);
        if ($cur) {
            $write($cur, $rate, $units);
            continue;
        }
        $pcs = $units['pcs_in_box'] > 1 ? $units['pcs_in_box'] : 1.0;
        $pUnit = $units['purchase_unit'] ?: 'Box';
        $cUnit = $units['count_unit'] ?: 'Ea';
        $ins->execute([$orgId, $name, $pUnit, $cUnit, $pUnit, $pcs, round($rate, 2), round($rate / $pcs, 4), 'Food']);
        $nid = (int)$pdo->lastInsertId();
        if ($nid > 0) {
            $added++;
            $ids[] = $nid;
        }
    }
    return ['updated' => $updated, 'added' => $added, 'ids' => $ids];
}

function idi_apply_invoice_for_site(PDO $pdo, int $rid, array $items, array $unmatched = []): array {
    $orgId = idi_site_org($pdo, $rid);
    if ($orgId <= 0) {
        $orgId = 1;
    }
    return idi_apply_prices_to_catalog($pdo, $orgId, $items, $unmatched);
}

/** @return list<string> */
function idi_split_invoice_chunks(string $text): array {
    $pages = preg_split("/\f/", $text);
    $chunks = [];
    foreach ($pages as $page) {
        $page = trim((string)$page);
        if ($page === '') {
            continue;
        }
        $parts = preg_split('/(?=INVOICE:\s+\d{6,})/', $page);
        if (!$parts) {
            $chunks[] = $page;
            continue;
        }
        foreach ($parts as $p) {
            $p = trim((string)$p);
            if (strlen($p) < 40) {
                continue;
            }
            $chunks[] = $p;
        }
    }
    return $chunks ?: [trim($text)];
}

/**
 * One PDF of many invoices → one FoodCost row per invoice date / invoice #.
 *
 * @return array{ok:bool,count:int,message:string,error?:string,dates:list<string>}
 */
function idi_import_combined_invoices(PDO $pdo, string $path, string $rel, int $rid, string $fallbackDate): array {
    $text = '';
    try {
        $text = idi_extract_text($path);
    } catch (Throwable $e) {
        $text = '';
    }
    if ($text === '') {
        return ['ok' => false, 'count' => 0, 'message' => '', 'error' => 'Could not read the combined invoice PDF.', 'dates' => []];
    }
    $chunks = idi_split_invoice_chunks($text);
    $ins = $pdo->prepare(
        'INSERT INTO FoodCost (InvoiceDate, RId, Vendor, Amount, InvoiceNumber, Invoice)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $exists = $pdo->prepare(
        'SELECT FCId FROM FoodCost WHERE RId = ? AND InvoiceNumber = ? AND InvoiceDate = ? LIMIT 1'
    );
    $created = 0;
    $skipped = 0;
    $dates = [];
    $seen = [];
    foreach ($chunks as $chunk) {
        $amount = idi_guess_total($chunk);
        $invNo = idi_guess_invoice_no($chunk);
        $invDate = idi_guess_date($chunk, $fallbackDate);
        $vendor = idi_guess_vendor($chunk);
        if ($amount == 0.0 && $invNo === '') {
            continue;
        }
        if ($vendor === '') {
            $vendor = 'Unknown vendor';
        }
        if ($invNo === '') {
            $invNo = 'COMBINED-' . substr(md5($chunk), 0, 8);
        }
        $key = $invDate . '|' . $invNo;
        if (isset($seen[$key])) {
            $skipped++;
            continue;
        }
        $seen[$key] = true;
        $exists->execute([$rid, $invNo, $invDate]);
        if ($exists->fetchColumn()) {
            $skipped++;
            continue;
        }
        $ins->execute([$invDate, $rid, substr($vendor, 0, 255), $amount, substr($invNo, 0, 50), $rel]);
        $created++;
        $dates[$invDate] = true;
        try {
            $read = [
                'items' => [],
                'unmatched' => [],
            ];
            $catalog = idi_catalog($pdo, $rid);
            foreach (idi_parse_lines($chunk) as $line) {
                $line['rate'] = idi_invoice_case_rate(
                    (float)($line['qty'] ?? 0),
                    (float)($line['rate'] ?? 0),
                    (float)($line['ext'] ?? 0)
                );
                $hit = idi_match_item($line['name'], $catalog);
                if ($hit) {
                    $id = (int)($hit['id'] ?? $hit['Id'] ?? 0);
                    if ($id > 0) {
                        $read['items'][$id] = [
                            'qty' => (float)($line['qty'] ?? 0),
                            'rate' => (float)$line['rate'],
                            'name' => (string)($hit['name'] ?? $line['name']),
                        ];
                        continue;
                    }
                }
                $read['unmatched'][] = $line;
            }
            idi_apply_invoice_for_site($pdo, $rid, $read['items'], $read['unmatched']);
        } catch (Throwable $e) {
            error_log('combined invoice items: ' . $e->getMessage());
        }
    }
    if ($created < 1) {
        return [
            'ok' => false,
            'count' => 0,
            'message' => '',
            'error' => $skipped > 0
                ? 'Those invoices are already on file for this site.'
                : 'No invoices with a date and amount could be read from this PDF.',
            'dates' => [],
        ];
    }
    $dateList = array_keys($dates);
    sort($dateList);
    return [
        'ok' => true,
        'count' => $created,
        'message' => 'Combined PDF: ' . $created . ' invoice' . ($created === 1 ? '' : 's') . ' posted by invoice date'
            . ($skipped ? (' (' . $skipped . ' already on file)') : '') . '.',
        'dates' => $dateList,
    ];
}

function idi_store_upload(string $root, int $rid, string $date, string $tmp, string $origName): array {
    $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        return ['ok' => false, 'error' => 'Use a PDF or photo (png/jpg).'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }
    $ym = substr($date, 0, 7);
    $dir = rtrim($root, '/') . '/' . $rid . '/' . $ym;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['ok' => false, 'error' => 'Could not create invoice folder.'];
    }
    $safe = $date . '_invoice_' . date('His') . '.' . $ext;
    $dest = $dir . '/' . $safe;
    $moved = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dest) : @rename($tmp, $dest);
    if (!$moved) {
        return ['ok' => false, 'error' => 'Could not save the upload.'];
    }
    @chmod($dest, 0664);
    return ['ok' => true, 'dest' => $dest, 'rel' => $rid . '/' . $ym . '/' . $safe];
}
