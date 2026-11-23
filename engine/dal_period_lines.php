<?php
/**
 * Path: /engine/dal_period_lines.php
 * Daily Activity Log: Daily, Weekly and Monthly POS are SEPARATE lines.
 * - A Weekly line holds the 7-day total from the Weekly POS.
 * - A Monthly line holds the 1st-through-day (MTD) total from the Monthly POS.
 * - Typing Sales $ on a Weekly/Monthly line never needs a Daily POS or daily figures.
 * - Totals and MTD sums count Daily lines only, so period lines are never double-counted.
 * - Daily-vs-period comparisons are warnings only; they never block a save.
 */

if (!function_exists('dal_line_period')) {
    function dal_line_period($remarks): string {
        $rmk = (string)$remarks;
        if (preg_match('/week_split/i', $rmk)) {
            return 'weekly';
        }
        if (preg_match('/mtd_split/i', $rmk)) {
            return 'monthly';
        }
        return 'daily';
    }
}

if (!function_exists('dal_line_title')) {
    function dal_line_title(string $period): string {
        if ($period === 'weekly') {
            return 'Weekly';
        }
        if ($period === 'monthly') {
            return 'Monthly';
        }
        return 'Daily';
    }
}

if (!function_exists('dal_sales_edit_needs_daily_pos')) {
    /** Only a Daily line needs a Daily POS file before Sales $ can be typed. */
    function dal_sales_edit_needs_daily_pos(string $period): bool {
        return $period !== 'weekly' && $period !== 'monthly';
    }
}

if (!function_exists('dal_recheck_hint')) {
    /** Red hint under an unread Sales $ box. Period lines ask for their own total, never daily sales. */
    function dal_recheck_hint(string $period): string {
        if ($period === 'monthly') {
            return 'Type Monthly total $ (1st–day) — POS unread';
        }
        if ($period === 'weekly') {
            return 'Type Weekly total $ — POS unread';
        }
        return 'Type Sales $ — POS unread';
    }
}

if (!function_exists('dal_recheck_title')) {
    function dal_recheck_title(string $period, bool $unread): string {
        if ($period === 'monthly') {
            return $unread ? 'Monthly POS did not read. Type the Monthly total (1st through this day) here. No daily sales needed.'
                : 'Monthly total (1st through this day). Double-click to see the POS picture.';
        }
        if ($period === 'weekly') {
            return $unread ? 'Weekly POS did not read. Type the Weekly total (7 days through this day) here. No daily sales needed.'
                : 'Weekly total (7 days through this day). Double-click to see the POS picture.';
        }
        return $unread ? 'POS did not read Sales $. Type it here.' : 'Type NET SALES. Double-click to see the POS picture.';
    }
}

if (!function_exists('dal_need_sales_note')) {
    /** @param list<string> $periods periods of the lines whose Sales $ did not read */
    function dal_need_sales_note(array $periods): string {
        $parts = [];
        foreach (array_values(array_unique($periods)) as $p) {
            if ($p === 'monthly') {
                $parts[] = 'Monthly POS did not read. Type the Monthly total in red on the Monthly line.';
            } elseif ($p === 'weekly') {
                $parts[] = 'Weekly POS did not read. Type the Weekly total in red on the Weekly line.';
            } else {
                $parts[] = 'POS did not read Sales $. Type it in red on that row.';
            }
        }
        return implode(' ', $parts);
    }
}

if (!function_exists('dal_daily_lines_sum')) {
    /** Sum of DAILY lines only (Weekly/Monthly lines excluded) for a site between two dates. */
    function dal_daily_lines_sum(PDO $pdo, $rid, string $from, string $to): float {
        $st = $pdo->prepare("SELECT COALESCE(SUM(pos_sales),0) FROM daily_report WHERE restaurant_id = ? AND report_date BETWEEN ? AND ? AND (remarks IS NULL OR (remarks NOT LIKE '%week_split%' AND remarks NOT LIKE '%mtd_split%'))");
        $st->execute([(int)$rid, $from, $to]);
        return round((float)$st->fetchColumn(), 2);
    }
}

if (!function_exists('dal_line_mtd_display')) {
    /**
     * MTD Sales shown on a line. A Monthly line IS the month-to-date total, so it shows its own amount
     * (falls back to the daily MTD when the Monthly total is still empty). Daily and Weekly lines show
     * the daily-lines MTD. Never adds a period line on top of daily lines.
     */
    function dal_line_mtd_display(string $period, float $lineSales, float $dailyMtd): float {
        if ($period === 'monthly' && $lineSales > 0.004) {
            return round($lineSales, 2);
        }
        return round($dailyMtd, 2);
    }
}

if (!function_exists('dal_mark_sales_typed')) {
    /**
     * Mark the POS file's sidecar: Sales $ on this line was typed by hand (POS unread or override).
     * The POS file itself is kept. A new upload writes a fresh sidecar, which clears the mark.
     */
    function dal_mark_sales_typed(string $posPath, float $amount, int $userId = 0): bool {
        if ($posPath === '' || !is_file($posPath)) {
            return false;
        }
        $metaFile = preg_replace('/\.[^.]+$/', '', $posPath) . '.json';
        $meta = [];
        if (is_file($metaFile)) {
            $j = json_decode((string)@file_get_contents($metaFile), true);
            if (is_array($j)) {
                $meta = $j;
            }
        }
        $meta['sales_typed'] = true;
        $meta['sales_typed_amount'] = round($amount, 2);
        $meta['sales_typed_by'] = $userId;
        $meta['sales_typed_at'] = date('c');
        return @file_put_contents($metaFile, json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false;
    }
}

if (!function_exists('dal_typed_tag_html')) {
    /** Small "Typed $X" tag shown next to View POS when the line's Sales $ was entered by hand. */
    function dal_typed_tag_html(?array $found): string {
        if (!$found || empty($found['sales_typed'])) {
            return '';
        }
        $amt = isset($found['sales_typed_amount']) && $found['sales_typed_amount'] !== null
            ? ' $' . number_format((float)$found['sales_typed_amount'], 2) : '';
        $when = (string)($found['sales_typed_at'] ?? '');
        $title = 'Sales $ entered by hand (POS file kept)' . ($when !== '' ? ' on ' . $when : '');
        return ' <span class="dal-typed-tag" title="' . htmlspecialchars($title) . '">Typed' . htmlspecialchars($amt) . '</span>';
    }
}
