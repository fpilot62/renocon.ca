<?php
/**
 * Path: /engine/production_usage_import.php
 * Read a Daily Activity Log Production PDF (Clearview Production Report)
 * and upsert sold quantities into Gem_inventory_usage for Usage.
 *
 * Each Production line is its own Usage row. Flavors are not added together
 * (Classic Boneless stays Classic Boneless — it is not mixed into Boneless Wings).
 * Combined "POS + Production" scans: only the Production Report page is used.
 */


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

function pui_site_org(PDO $pdo, int $rid): int {
    $st = $pdo->prepare('SELECT org_id FROM sites WHERE SId = ?');
    $st->execute([$rid]);
    $org = (int)$st->fetchColumn();
    return $org > 0 ? $org : 0;
}

function pui_norm_name(string $s): string {
    $s = strtolower($s);
    $s = preg_replace('/^[^a-z0-9]+/i', '', $s);
    $s = preg_replace('/^\d+[.)]\s*/', '', $s);
    $s = str_replace(['’', "'", '.', ',', ':', ';', '|', '/', '\\', '@'], ' ', $s);
    $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', trim($s));
    $s = preg_replace('/\b16\s*0\s*z\b/', '16oz', $s);
    $s = preg_replace('/\b16\s*0\s*2\b/', '16oz', $s);
    $s = preg_replace('/\b22\s*0\s*2\b/', '22oz', $s);
    $s = preg_replace('/\b32\s*0\s*2\b/', '32oz', $s);
    $s = preg_replace('/\b16\s*oz\b/', '16oz', $s);
    $s = preg_replace('/\b22\s*oz\b/', '22oz', $s);
    $s = preg_replace('/\b32\s*oz\b/', '32oz', $s);
    $s = preg_replace('/\b160z\b/', '16oz', $s);
    $s = preg_replace('/\b60z\b/', '6oz', $s);
    $s = preg_replace('/\b602\s*cup\b/', '6oz cup', $s);
    $s = preg_replace('/\bneh\b/', 'new', $s);
    $s = preg_replace('/\bnini\b/', 'mini', $s);
    $s = preg_replace('/\bhint wrap\b/', 'mini wrap', $s);
    $s = preg_replace('/\bhint trap\b/', 'mini wrap', $s);
    $s = preg_replace('/\bstravberry\b/', 'strawberry', $s);
    $s = preg_replace('/\bstranberry\b/', 'strawberry', $s);
    $s = preg_replace('/\bcrean\b/', 'cream', $s);
    $s = preg_replace('/\bhonestyle\b/', 'homestyle', $s);
    $s = preg_replace('/\bhonestyle\b/', 'homestyle', $s);
    $s = preg_replace('/\bhac n\b/', 'mac n', $s);
    $s = preg_replace('/\bnac n\b/', 'mac n', $s);
    $s = preg_replace('/\bhashed pot\b/', 'mashed potatoes', $s);
    $s = preg_replace('/\bmashed pot\b/', 'mashed potatoes', $s);
    $s = preg_replace('/\bmashed potat\w*\b/', 'mashed potatoes', $s);
    $s = preg_replace('/\bhing\b/', 'wing', $s);
    $s = preg_replace('/\bbbo\b/', 'bbq', $s);
    $s = preg_replace('/\bbq\b/', 'bbq', $s);
    $s = preg_replace('/\bpe\b/', 'pc', $s);
    $s = preg_replace('/\bpcs\b/', 'pc', $s);
    $s = preg_replace('/\bcups\b/', 'cup', $s);
    $s = preg_replace('/\bjon rings\b/', 'onion rings', $s);
    $s = preg_replace('/\bonio\b/', 'onion rings', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

function pui_qty(string $raw): float {
    $raw = trim(str_replace(['O', 'o'], '0', $raw));
    if (preg_match('/^(\d{1,7})[.,\s]+(\d{1,4})$/', $raw, $m)) {
        return (float)($m[1] . '.' . $m[2]);
    }
    $compact = str_replace([',', ' '], '', $raw);
    if (preg_match('/^(\d{1,7})0000$/', $compact, $m) && strlen($compact) >= 5) {
        return (float)$m[1];
    }
    return (float)$compact;
}

/** Production Report label → inventory item name (1:1, no roll-up). */
function pui_production_name_map(): array {
    return [
        'biscuit' => 'BISCUIT READY TO BAKE CL INTL',
        'chicken tenders' => 'CHICKEN BRST STRIPS SPICY/MILD HAL',
        'chicken bone in' => 'CHICKEN CUT 9 HALAL',
        'dark meat' => 'DARK MEAT-CHICKEN LEG BCK ATCH SPLT HAL',
        'chicken leg' => 'DARK MEAT-CHICKEN LEG BCK ATCH SPLT HAL',
        'shortening' => 'SHORTENING FRY MB PALM/CNLA NH',
        'shrimp' => 'SHRIMP BRD POPCRN',
        'onion rings' => 'ONION RING TEMPURA BATTERED',
        'onion ring' => 'ONION RING TEMPURA BATTERED',
        'fries' => 'POTATO FRY BATRD CRISPY CAJN',
        'chicken nuggets' => 'Nuggets',
        '16oz cup' => '16oz Cup',
        '160z cup' => '16oz Cup',
        '6oz cup' => '6oz Cup',
        '22oz cup' => '22oz Cups',
        '32oz cup' => '32oz Cup',
        'apple pie' => 'Apple Pie',
        'blknd chicken mini wrap' => 'Blknd Chicken Mini Wrap',
        'blackened chicken mini wrap' => 'Blknd Chicken Mini Wrap',
        'blknd chicken wrap' => 'Blknd Chicken Wrap',
        'blackened chicken wrap' => 'Blknd Chicken Wrap',
        'bottle bev' => 'Bottle Bev',
        'bottle beverage' => 'Bottle Bev',
        'bottle juice' => 'Bottle Juice',
        'buns' => 'Buns',
        'bun' => 'Buns',
        'ch classic lemonade' => 'CH Classic Lemonade',
        'ch strawberry lemonade' => 'CH Strawberry Lemonade',
        'cheese curds' => 'Cheese Curds',
        'chicken wrap' => 'Chicken Wrap',
        'coleslaw' => 'Coleslaw',
        'dipping sauce' => 'Dipping Sauce',
        'fz classic lemonade' => 'FZ Classic Lemonade',
        'fz strawberry lemonade' => 'FZ Strawberry Lemonade',
        'gravy' => 'Gravy',
        'homestyle mac n cheese' => 'Homestyle Mac n Cheese',
        'homestyle mac' => 'Homestyle Mac n Cheese',
        'honestyle hac n cheese' => 'Homestyle Mac n Cheese',
        'honestyle hac n cheese' => 'Homestyle Mac n Cheese',
        'homestyle nac n cheese' => 'Homestyle Mac n Cheese',
        'mac n cheese' => 'Mac n Cheese',
        'mashed potatoes' => 'Mashed Potatoes',
        'mini bun' => 'Mini Bun',
        'mint bun' => 'Mini Bun',
        'mini wrap' => 'Mini Wrap',
        'nini wrap' => 'Mini Wrap',
        'hint wrap' => 'Mini Wrap',
        'hint trap' => 'Mini Wrap',
        'strawberry cream pie' => 'Strawberry Cream Pie',
        'strawberry cream c pie' => 'Strawberry Cream Pie',
        'stravberry crean pie' => 'Strawberry Cream Pie',
        'stranberry cream pie' => 'Strawberry Cream Pie',
        'stranberry cream c pie' => 'Strawberry Cream Pie',
        'new chicken fillet' => 'NEW Chicken Fillet',
        'blackened chicken fillet' => 'Blackened Chicken Fillet',
        'classic boneless' => 'Classic Boneless pc',
        'garlic parm boneless' => 'Garlic Parm Boneless pc',
        'honey bbq boneless' => 'Honey BBQ Boneless pc',
        'honey garlic boneless' => 'Honey Garlic Boneless pc',
        'signature hot boneless' => 'Signature Hot Boneless pc',
        'garlic parm wing' => 'Garlic Parm Wing pc',
        'ghost pepper wing' => 'Ghost Pepper Wing pc',
        'honey bbq wing' => 'Honey BBQ Wing pc',
        'honey garlic wing' => 'Honey Garlic Wing pc',
        'signature hot wing' => 'Signature Hot Wing pc',
        'wing pc' => 'Wing pc',
    ];
}

/** Items that used to hold summed flavors — cleared after a split import. */
function pui_rollup_parent_names(): array {
    return [
        'CHICKEN BRST FILLET BNLS SKLS',
        'SPLIT WINGS',
        'Boneless Wings',
    ];
}

function pui_item_id_by_name(PDO $pdo, string $name): ?int {
    static $cache = [];
    $key = strtolower($name);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $st = $pdo->prepare('SELECT Id FROM Gem_inventory_items WHERE name = ? LIMIT 1');
    $st->execute([$name]);
    $id = $st->fetchColumn();
    $cache[$key] = $id !== false ? (int)$id : null;
    return $cache[$key];
}

function pui_aliases(PDO $pdo): array {
    $ids = [];
    foreach (pui_production_name_map() as $alias => $itemName) {
        $id = pui_item_id_by_name($pdo, $itemName);
        if ($id) {
            $ids[] = ['id' => $id, 'alias' => $alias, 'len' => strlen($alias), 'item' => $itemName];
        }
    }
    usort($ids, static function ($a, $b) {
        return $b['len'] <=> $a['len'];
    });
    return $ids;
}

function pui_match_item(string $name, array $aliases): ?int {
    $n = pui_norm_name($name);
    if ($n === '' || strlen($n) < 3) {
        return null;
    }
    foreach ($aliases as $row) {
        if ($n === $row['alias'] || strpos($n, $row['alias']) !== false) {
            return (int)$row['id'];
        }
    }
    $bestId = null;
    $best = 0.0;
    foreach ($aliases as $row) {
        similar_text($n, $row['alias'], $pct);
        if ($pct > $best) {
            $best = $pct;
            $bestId = (int)$row['id'];
        }
    }
    if ($bestId && $best >= 86) {
        return $bestId;
    }
    return null;
}

function pui_is_image_file(string $path): bool {
    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'tif', 'tiff', 'bmp'], true)) {
        return true;
    }
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return false;
    }
    $magic = (string)fread($fh, 12);
    fclose($fh);
    if (strncmp($magic, "\xFF\xD8\xFF", 3) === 0) {
        return true;
    }
    if (strncmp($magic, "\x89PNG", 4) === 0) {
        return true;
    }
    if (strncmp($magic, "RIFF", 4) === 0 && strpos($magic, "WEBP") !== false) {
        return true;
    }
    if (strncmp($magic, "GIF8", 4) === 0) {
        return true;
    }
    return false;
}

function pui_ocr_image(string $imgPath): string {
    $src = $imgPath;
    $tmpPng = '';
    $ext = strtolower((string)pathinfo($imgPath, PATHINFO_EXTENSION));
    if (in_array($ext, ['webp', 'gif', 'bmp'], true)) {
        $tmpPng = sys_get_temp_dir() . '/pui_img_' . bin2hex(random_bytes(4)) . '.png';
        $conv = [];
        $code = sip_run_cmd('convert ' . escapeshellarg($imgPath) . ' ' . escapeshellarg($tmpPng) . ' 2>/dev/null', $conv);
        if ($code === 0 && is_file($tmpPng)) {
            $src = $tmpPng;
        }
    }
    $lineOut = [];
    sip_run_cmd('tesseract -l eng ' . escapeshellarg($src) . ' stdout --psm 6 2>/dev/null', $lineOut);
    if ($tmpPng !== '' && is_file($tmpPng)) {
        @unlink($tmpPng);
    }
    return trim(implode("\n", $lineOut));
}

function pui_extract_text(string $pdfPath): string {
    if (!is_file($pdfPath) || !is_readable($pdfPath)) {
        return '';
    }
    if (pui_is_image_file($pdfPath)) {
        return pui_ocr_image($pdfPath);
    }
    $pdf = escapeshellarg($pdfPath);
    $out = [];
    sip_run_cmd('pdftotext -layout ' . $pdf . ' - 2>/dev/null', $out);
    $text = trim(implode("\n", $out));
    if (pui_looks_like_production($text)) {
        return $text;
    }
    $tmp = sys_get_temp_dir() . '/pui_' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0700, true);
    sip_run_cmd('pdfimages -j ' . $pdf . ' ' . escapeshellarg($tmp . '/p') . ' 2>/dev/null');
    $pages = glob($tmp . '/p*') ?: [];
    sort($pages);
    $ocr = [];
    foreach ($pages as $img) {
        $ocr[] = pui_ocr_image($img);
        @unlink($img);
    }
    @rmdir($tmp);
    $joined = trim(implode("\n", $ocr));
    return $joined !== '' ? $joined : $text;
}

function pui_looks_like_production(string $text): bool {
    return (bool)preg_match('/production\s+report/i', $text)
        || ((bool)preg_match('/\bbiscuit\b/i', $text) && (bool)preg_match('/\bfries\b/i', $text));
}

function pui_production_slice(string $text): string {
    if (preg_match('/production\s+report/i', $text, $m, PREG_OFFSET_CAPTURE)) {
        return substr($text, (int)$m[0][1]);
    }
    return $text;
}

function pui_parse_mdy(string $raw): ?string {
    $raw = str_replace(')', '1', $raw);
    $raw = preg_replace('/\s+/', '', $raw);
    if (!preg_match('/\b(0?\d|1[0-2])[-.\/](0?\d|[12]\d|3[01])[-.\/](20\d{2})\b/', (string)$raw, $m)) {
        return null;
    }
    $y = (int)$m[3];
    $mo = (int)$m[1];
    $d = (int)$m[2];
    /* OCR often turns 2026 into 2096 */
    if ($y >= 2090 && $y <= 2099) {
        $y = 2020 + ($y - 2090);
    }
    if ($y < 2020 || $y > 2032 || $mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

/** @return list<string> */
function pui_production_blocks(string $text): array {
    $slice = pui_production_slice($text);
    $parts = preg_split('/production\s+report/i', $slice);
    if (!$parts || count($parts) < 2) {
        return [$slice];
    }
    $blocks = [];
    for ($i = 1, $n = count($parts); $i < $n; $i++) {
        $blocks[] = $parts[$i];
    }
    return $blocks ?: [$slice];
}

/** @return array{begin:?string,end:?string} */
function pui_block_window(string $block): array {
    $begin = null;
    $end = null;
    $dateBit = '([0-9)]{1,2}\s*[-.\/]\s*[0-9)]{1,2}\s*[-.\/]\s*20\d{2})';
    if (preg_match('/begin[:\s]*' . $dateBit . '/i', $block, $m)) {
        $begin = pui_parse_mdy($m[1]);
    }
    if (preg_match('/end[:\s]*' . $dateBit . '/i', $block, $m)) {
        $end = pui_parse_mdy($m[1]);
    }
    return ['begin' => $begin, 'end' => $end];
}

function pui_window_hours(?string $begin, ?string $end): ?float {
    if (!$begin || !$end) {
        return null;
    }
    $b = strtotime($begin . ' 03:30:00');
    $e = strtotime($end . ' 03:30:00');
    if (!$b || !$e || $e < $b) {
        return null;
    }
    return ($e - $b) / 3600.0;
}

function pui_block_matches_date(array $win, string $usageDate): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $usageDate)) {
        return true;
    }
    $hours = pui_window_hours($win['begin'], $win['end']);
    if ($hours !== null && $hours > 36) {
        return false;
    }
    if ($win['begin']) {
        return $usageDate === $win['begin'];
    }
    if ($win['end']) {
        $prev = date('Y-m-d', strtotime($win['end'] . ' -1 day'));
        return $usageDate === $win['end'] || $usageDate === $prev;
    }
    return true;
}

/** @return array<int,float> item_id => qty */
function pui_parse_qty_lines(string $block, array $aliases): array {
    $qtyByItem = [];
    foreach (preg_split('/\R/', $block) as $line) {
        $line = trim(preg_replace('/[|\[\]]+/', ' ', $line));
        if ($line === '' || preg_match('/production report|ingredient|louisiana|popeyes|store:|begin |end /i', $line)) {
            continue;
        }
        if (preg_match('/\$|net sales|debit card|mastercard|visa|doordash|uber|gratuity|refund|avg /i', $line)) {
            continue;
        }
        if (!preg_match('/^(.{3,80}?)\s+(\d{1,6}(?:[.,\s]\d{1,4})?)\s+(each|pieces|ounces|pcs|pc)\b/i', $line, $m)) {
            continue;
        }
        $itemId = pui_match_item($m[1], $aliases);
        if ($itemId === null) {
            continue;
        }
        $qty = pui_qty($m[2]);
        if ($qty <= 0) {
            continue;
        }
        if (!isset($qtyByItem[$itemId])) {
            $qtyByItem[$itemId] = 0.0;
        }
        $qtyByItem[$itemId] += $qty;
    }
    return $qtyByItem;
}

/**
 * @return array<int,float> item_id => qty
 */
function pui_parse_quantities(string $text, PDO $pdo, string $usageDate = ''): array {
    $aliases = pui_aliases($pdo);
    $blocks = pui_production_blocks($text);
    $matched = [];
    foreach ($blocks as $block) {
        $win = pui_block_window($block);
        if ($usageDate !== '' && !pui_block_matches_date($win, $usageDate)) {
            continue;
        }
        foreach (pui_parse_qty_lines($block, $aliases) as $id => $qty) {
            if (!isset($matched[$id])) {
                $matched[$id] = 0.0;
            }
            $matched[$id] += $qty;
        }
    }
    if ($matched || $usageDate === '') {
        return $matched;
    }
    return [];
}

function pui_window_label(array $windows): string {
    foreach ($windows as $win) {
        if (!empty($win['begin']) && !empty($win['end'])) {
            return $win['begin'] . ' to ' . $win['end'];
        }
    }
    return '';
}

/**
 * @return array{ok:bool,written:int,items:array,message:string,error?:string}
 */
function pui_upsert_usage(PDO $pdo, int $rid, string $usageDate, array $qtyByItem): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $usageDate)) {
        return ['ok' => false, 'written' => 0, 'items' => [], 'message' => '', 'error' => 'Bad usage date.'];
    }
    if (!$qtyByItem) {
        return ['ok' => false, 'written' => 0, 'items' => [], 'message' => '', 'error' => 'No production lines found.'];
    }
    $org = pui_site_org($pdo, $rid);
    if ($org <= 0 || $rid <= 0) {
        return ['ok' => false, 'written' => 0, 'items' => [], 'message' => '', 'error' => 'Unknown site.'];
    }

    $find = $pdo->prepare(
        'SELECT id, waste_qty FROM Gem_inventory_usage
         WHERE site_id = ? AND item_id = ? AND usage_date = ?
         ORDER BY id ASC'
    );
    $upd = $pdo->prepare(
        'UPDATE Gem_inventory_usage
         SET org_id = ?, sold_qty = ?, waste_qty = ?, actual_qty_pcs = ?
         WHERE id = ?'
    );
    $ins = $pdo->prepare(
        'INSERT INTO Gem_inventory_usage
            (org_id, site_id, item_id, sold_qty, waste_qty, actual_qty_pcs, usage_date)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $del = $pdo->prepare('DELETE FROM Gem_inventory_usage WHERE id = ?');

    $written = 0;
    $pdo->beginTransaction();
    try {
        foreach ($qtyByItem as $itemId => $sold) {
            $itemId = (int)$itemId;
            $sold = round((float)$sold, 2);
            $find->execute([$rid, $itemId, $usageDate]);
            $rows = $find->fetchAll(PDO::FETCH_ASSOC);
            $waste = 0.0;
            if ($rows) {
                $waste = (float)$rows[0]['waste_qty'];
                $keepId = (int)$rows[0]['id'];
                $upd->execute([$org, $sold, $waste, $sold + $waste, $keepId]);
                for ($i = 1, $n = count($rows); $i < $n; $i++) {
                    $del->execute([(int)$rows[$i]['id']]);
                }
            } else {
                $ins->execute([$org, $rid, $itemId, $sold, 0, $sold, $usageDate]);
            }
            $written++;
        }

        // Old combined rows (Fillet / Split Wings / Boneless Wings) must not
        // keep the summed flavors after a 1:1 import.
        foreach (pui_rollup_parent_names() as $parentName) {
            $pid = pui_item_id_by_name($pdo, $parentName);
            if (!$pid || isset($qtyByItem[$pid])) {
                continue;
            }
            $find->execute([$rid, $pid, $usageDate]);
            foreach ($find->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $del->execute([(int)$row['id']]);
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'written' => 0, 'items' => $qtyByItem, 'message' => '', 'error' => $e->getMessage()];
    }

    return [
        'ok' => true,
        'written' => $written,
        'items' => $qtyByItem,
        'message' => $written . ' consumption item' . ($written === 1 ? '' : 's') . ' saved to Usage for ' . $usageDate . '.',
    ];
}


function pui_add_unique_names(PDO $pdo, int $rid, string $text, string $usageDate = ''): int {
    $orgId = 0;
    try {
        $st = $pdo->prepare('SELECT org_id FROM sites WHERE SId = ?');
        $st->execute([$rid]);
        $orgId = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        $orgId = 0;
    }
    if ($orgId <= 0) {
        return 0;
    }
    $aliases = pui_aliases($pdo);
    $catalog = $pdo->prepare(
        'SELECT id, name, clearview_name FROM Gem_inventory_items WHERE org_id = ? AND IsActive = 1'
    );
    $catalog->execute([$orgId]);
    $rows = $catalog->fetchAll(PDO::FETCH_ASSOC);
    $exists = $pdo->prepare(
        'SELECT id FROM Gem_inventory_items
         WHERE org_id = ? AND IsActive = 1 AND (LOWER(name) = LOWER(?) OR LOWER(COALESCE(clearview_name, \'\')) = LOWER(?))
         LIMIT 1'
    );
    $ins = $pdo->prepare(
        'INSERT INTO Gem_inventory_items
            (org_id, site_id, Restaurant_Id, name, clearview_name, purchase_unit, count_unit, Unit, pcs_in_box, cost_box, cost_pc, category, IsActive)
         VALUES (?, 0, 0, ?, ?, ?, ?, ?, 1, 0, 0, ?, 1)'
    );
    $added = 0;
    $seen = [];
    $blocks = pui_production_blocks($text);
    $chunks = $blocks ?: [$text];
    foreach ($chunks as $block) {
        if ($usageDate !== '') {
            $win = pui_block_window($block);
            if (!pui_block_matches_date($win, $usageDate) && $blocks) {
                continue;
            }
        }
        foreach (preg_split('/\R/', $block) as $line) {
            $line = trim(preg_replace('/[|\[\]]+/', ' ', $line));
            if (!preg_match('/^(.{3,80}?)\s+(\d{1,6}(?:[.,\s]\d{1,4})?)\s+(each|pieces|ounces|pcs|pc)\b/i', $line, $m)) {
                continue;
            }
            $name = trim($m[1]);
            if ($name === '' || pui_match_item($name, $aliases) !== null) {
                continue;
            }
            $key = strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $a = pui_norm_name($name);
            if ($a === '' || strlen($a) < 4 || preg_match('/^(supp|equip|frozen|paper|disp|chemical|canned|dry)\\b/', $a)) {
                continue;
            }
            $dup = false;
            foreach ($rows as $r) {
                $b = pui_norm_name(trim((string)($r['clearview_name'] ?: $r['name'])));
                if ($b === '') {
                    continue;
                }
                if ($a === $b) {
                    $dup = true;
                    break;
                }
                similar_text($a, $b, $pct);
                if ($pct >= 80) {
                    $dup = true;
                    break;
                }
            }
            if ($dup) {
                continue;
            }
            $exists->execute([$orgId, $name, $name]);
            if ((int)$exists->fetchColumn() > 0) {
                continue;
            }
            $unitRaw = strtolower($m[3]);
            $cUnit = (strpos($unitRaw, 'ounce') !== false) ? 'Oz' : 'Ea';
            $ins->execute([$orgId, $name, $name, 'Box', $cUnit, $cUnit, 'Food']);
            $added++;
        }
    }
    return $added;
}

function pui_import_production_pdf(PDO $pdo, string $pdfPath, int $rid, string $usageDate): array {
    $text = pui_extract_text($pdfPath);
    if ($text === '') {
        return ['ok' => false, 'written' => 0, 'items' => [], 'message' => '', 'error' => 'Could not read the production file (JPG/PNG photos are OCR’d; use a clear Production Report page).'];
    }
    if (!pui_looks_like_production($text)) {
        return ['ok' => false, 'written' => 0, 'items' => [], 'message' => '', 'error' => 'No Production Report found in this file. POS / sales pages are not copied into Usage.'];
    }
    $addedNames = pui_add_unique_names($pdo, $rid, $text, $usageDate);
    $qty = pui_parse_quantities($text, $pdo, $usageDate);
    if (!$qty) {
        $windows = [];
        foreach (pui_production_blocks($text) as $block) {
            $windows[] = pui_block_window($block);
        }
        $begins = [];
        foreach ($windows as $w) {
            if (!empty($w['begin'])) {
                $begins[(string)$w['begin']] = true;
            }
        }
        $beginList = array_keys($begins);
        if (count($beginList) === 1 && $beginList[0] !== $usageDate) {
            $real = $beginList[0];
            $addedNames = pui_add_unique_names($pdo, $rid, $text, $real);
            $qtyReal = pui_parse_quantities($text, $pdo, $real);
            if ($qtyReal) {
                $res = pui_upsert_usage($pdo, $rid, $real, $qtyReal);
                $note = 'This Production Begin date is ' . $real . ', not ' . $usageDate . '. Saved to Usage for ' . $real . '. Upload the ' . $usageDate . ' Production Report for this day.';
                $res['message'] = trim($note . ' ' . (string)($res['message'] ?? ''));
                $res['usage_date'] = $real;
                if ($addedNames > 0) {
                    $res['message'] = trim(($res['message'] ?? '') . ' Added ' . $addedNames . ' new Master Item' . ($addedNames === 1 ? '' : 's') . ' from Production.');
                }
                return $res;
            }
        }
        $span = pui_window_label($windows);
        if (count($beginList) === 1 && $beginList[0] !== $usageDate) {
            return [
                'ok' => false,
                'written' => 0,
                'items' => [],
                'message' => '',
                'error' => 'This Production Report is for ' . $beginList[0] . ', not ' . $usageDate . '. Open Usage on ' . $beginList[0] . ', or upload the one-day Production for ' . $usageDate . '.',
            ];
        }
        if ($span !== '') {
            return [
                'ok' => false,
                'written' => 0,
                'items' => [],
                'message' => '',
                'error' => 'This Production Report is ' . $span . ' (more than one day). Upload the one-day Production Report for ' . $usageDate . ' only.',
            ];
        }
        return ['ok' => false, 'written' => 0, 'items' => [], 'message' => '', 'error' => 'Production page found, but no ingredient quantities could be read.'];
    }
    $res = pui_upsert_usage($pdo, $rid, $usageDate, $qty);
    if ($addedNames > 0) {
        $res['message'] = trim(($res['message'] ?? '') . ' Added ' . $addedNames . ' new Master Item' . ($addedNames === 1 ? '' : 's') . ' from Production.');
    }
    return $res;
}

/**
 * Month-end Production: import each daily Begin-date block, or split each
 * item quantity across 1st..$endDate so daily qtys add back to the MTD total.
 *
 * @return array{ok:bool,written:int,days:int,message:string,error?:string}
 */
function pui_import_production_days(PDO $pdo, string $pdfPath, int $rid, array $days, string $label = 'Monthly'): array {
    require_once __DIR__ . '/mtd_allocate.php';
    if (!$days) {
        return ['ok' => false, 'written' => 0, 'days' => 0, 'message' => '', 'error' => 'Pick a day to split the ' . $label . ' report.'];
    }
    $text = pui_extract_text($pdfPath);
    if ($text === '') {
        return ['ok' => false, 'written' => 0, 'days' => 0, 'message' => '', 'error' => 'Could not read the Production PDF.'];
    }
    if (!pui_looks_like_production($text)) {
        return ['ok' => false, 'written' => 0, 'days' => 0, 'message' => '', 'error' => 'No Production Report found in this file.'];
    }
    pui_add_unique_names($pdo, $rid, $text, '');
    $aliases = pui_aliases($pdo);
    $byDay = [];
    foreach (pui_production_blocks($text) as $block) {
        $win = pui_block_window($block);
        $hours = pui_window_hours($win['begin'], $win['end']);
        $day = $win['begin'];
        if ($hours !== null && $hours > 36) {
            $day = null;
        }
        if ($day && in_array($day, $days, true)) {
            $byDay[$day][] = $block;
        }
    }
    $writtenItems = 0;
    $writtenDays = 0;
    if (count($byDay) >= 2) {
        foreach ($byDay as $day => $blocks) {
            $qty = [];
            foreach ($blocks as $block) {
                foreach (pui_parse_qty_lines($block, $aliases) as $id => $q) {
                    $qty[$id] = ($qty[$id] ?? 0) + $q;
                }
            }
            if (!$qty) {
                continue;
            }
            $imp = pui_upsert_usage($pdo, $rid, $day, $qty);
            if (!empty($imp['ok'])) {
                $writtenDays++;
                $writtenItems += (int)$imp['written'];
            }
        }
        if ($writtenDays < 1) {
            return ['ok' => false, 'written' => 0, 'days' => 0, 'message' => '', 'error' => 'Production dates were found, but no ingredient quantities could be read.'];
        }
        return [
            'ok' => true,
            'written' => $writtenItems,
            'days' => $writtenDays,
            'message' => 'Production split by report date: ' . $writtenDays . ' days, ' . $writtenItems . ' item rows. Daily quantities add back to the ' . $label . ' report.',
        ];
    }
    $qtyAll = pui_parse_quantities($text, $pdo, '');
    if (!$qtyAll) {
        return ['ok' => false, 'written' => 0, 'days' => 0, 'message' => '', 'error' => 'Production page found, but no ingredient quantities could be read.'];
    }
    $n = count($days);
    $perDay = [];
    foreach ($qtyAll as $id => $totalQty) {
        $parts = sip_allocate_even((float)$totalQty, $n);
        foreach ($days as $i => $day) {
            if (!isset($perDay[$day])) {
                $perDay[$day] = [];
            }
            $perDay[$day][$id] = $parts[$i];
        }
    }
    foreach ($perDay as $day => $qty) {
        $imp = pui_upsert_usage($pdo, $rid, $day, $qty);
        if (!empty($imp['ok'])) {
            $writtenDays++;
            $writtenItems += (int)$imp['written'];
        }
    }
    return [
        'ok' => true,
        'written' => $writtenItems,
        'days' => $writtenDays,
        'message' => $label . ' Production split evenly across ' . $writtenDays . ' days. Item totals match the ' . $label . ' report.',
    ];
}

function pui_import_production_month(PDO $pdo, string $pdfPath, int $rid, string $endDate): array {
    require_once __DIR__ . '/mtd_allocate.php';
    return pui_import_production_days($pdo, $pdfPath, $rid, sip_days_through($endDate), 'Monthly');
}

function pui_import_production_week(PDO $pdo, string $pdfPath, int $rid, string $endDate): array {
    require_once __DIR__ . '/mtd_allocate.php';
    return pui_import_production_days($pdo, $pdfPath, $rid, sip_days_week_through($endDate), 'Weekly');
}

function pui_find_production_pdf(string $root, int $rid, string $date): ?string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $rid <= 0) {
        return null;
    }
    $dir = rtrim($root, '/') . '/' . $rid . '/' . substr($date, 0, 7);
    $base = $dir . '/' . $date . '_production_report';
    foreach (['pdf', 'png', 'jpg', 'jpeg'] as $ext) {
        $p = $base . '.' . $ext;
        if (is_file($p)) {
            return $p;
        }
    }
    return null;
}

/**
 * Re-read saved DAL production files (PDF or photo) into Usage.
 * php engine/production_usage_import.php --reimport [--rid=6]
 *
 * @return array{ok:int,fail:int,skip:int,files:int}
 */
function pui_reimport_saved_files(PDO $pdo, string $uploadRoot, int $onlyRid = 0): array {
    $root = rtrim($uploadRoot, '/');
    $ok = 0;
    $fail = 0;
    $skip = 0;
    $files = 0;
    $dirs = $onlyRid > 0 ? [ $root . '/' . $onlyRid ] : (glob($root . '/[0-9]*') ?: []);
    foreach ($dirs as $siteDir) {
        if (!is_dir($siteDir)) {
            continue;
        }
        $rid = (int)basename($siteDir);
        if ($rid <= 0) {
            continue;
        }
        foreach (glob($siteDir . '/20[0-9][0-9]-[0-9][0-9]') ?: [] as $monthDir) {
            foreach (glob($monthDir . '/*_production_report.*') ?: [] as $path) {
                $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
                if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
                    continue;
                }
                $base = basename($path);
                if (!preg_match('/^(20\d{2}-\d{2}-\d{2})_production_report\./', $base, $m)) {
                    $skip++;
                    continue;
                }
                $files++;
                $date = $m[1];
                $imp = pui_import_production_pdf($pdo, $path, $rid, $date);
                if (!empty($imp['ok'])) {
                    $ok++;
                    fwrite(STDOUT, "OK rid={$rid} {$date} written=" . (int)($imp['written'] ?? 0) . " " . trim((string)($imp['message'] ?? '')) . "\n");
                } else {
                    $fail++;
                    fwrite(STDOUT, "FAIL rid={$rid} {$date} " . (string)($imp['error'] ?? 'error') . "\n");
                }
            }
        }
    }
    return ['ok' => $ok, 'fail' => $fail, 'skip' => $skip, 'files' => $files];
}

if (PHP_SAPI === 'cli' && isset($argv[1]) && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'production_usage_import.php') {
    if ($argv[1] === '--reimport') {
        require_once dirname(__DIR__) . '/db.php';
        $only = 0;
        foreach ($argv as $a) {
            if (preg_match('/^--rid=(\d+)$/', $a, $m)) {
                $only = (int)$m[1];
            }
        }
        $root = dirname(__DIR__) . '/uploads/daily_reports';
        $sum = pui_reimport_saved_files($pdo, $root, $only);
        fwrite(STDOUT, 'reimport files=' . $sum['files'] . ' ok=' . $sum['ok'] . ' fail=' . $sum['fail'] . ' skip=' . $sum['skip'] . "\n");
        exit(0);
    }
    require_once dirname(__DIR__) . '/db.php';
    $path = $argv[1];
    $text = pui_extract_text($path);
    $qty = pui_parse_quantities($text, $pdo);
    $names = $pdo->query('SELECT Id, name, clearview_name FROM Gem_inventory_items')->fetchAll(PDO::FETCH_ASSOC);
    $byId = [];
    foreach ($names as $r) {
        $byId[(int)$r['Id']] = $r;
    }
    echo "lines=" . count($qty) . "\n";
    ksort($qty);
    foreach ($qty as $id => $q) {
        $n = $byId[$id]['clearview_name'] ?: ($byId[$id]['name'] ?? '');
        echo $id . "\t" . $n . "\t" . $q . "\n";
    }
}
