<?php
/**
 * Path: /engine/mtd_allocate.php
 * Split a month-end total across days so the daily amounts add back to the same total.
 */

if (!function_exists('sip_days_through')) {
    /** @return list<string> Y-m-d from the 1st of the month through $endDate */
    function sip_days_through(string $endDate): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            return [];
        }
        $start = substr($endDate, 0, 8) . '01';
        $out = [];
        $ts = strtotime($start);
        $endTs = strtotime($endDate);
        if ($ts === false || $endTs === false || $ts > $endTs) {
            return [];
        }
        while ($ts <= $endTs) {
            $out[] = date('Y-m-d', $ts);
            $ts = strtotime('+1 day', $ts);
        }
        return $out;
    }
}

if (!function_exists('sip_allocate_even')) {
    /**
     * Split $total into $n amounts (2 decimals) that sum exactly to $total.
     * @return list<float>
     */
    function sip_allocate_even(float $total, int $n): array {
        if ($n <= 0) {
            return [];
        }
        $cents = (int)round($total * 100);
        $base = intdiv($cents, $n);
        $rem = $cents - ($base * $n);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = ($base + ($i < $rem ? 1 : 0)) / 100.0;
        }
        return $out;
    }
}

if (!function_exists('sip_allocate_sum')) {
    function sip_allocate_sum(array $parts): float {
        $cents = 0;
        foreach ($parts as $p) {
            $cents += (int)round(((float)$p) * 100);
        }
        return $cents / 100.0;
    }
}

if (!function_exists('sip_days_week_through')) {
    /** @return list<string> 7 days ending on $endDate (the week-ending day) */
    function sip_days_week_through(string $endDate): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            return [];
        }
        $endTs = strtotime($endDate);
        if ($endTs === false) {
            return [];
        }
        $ts = strtotime('-6 days', $endTs);
        $out = [];
        while ($ts <= $endTs) {
            $out[] = date('Y-m-d', $ts);
            $ts = strtotime('+1 day', $ts);
        }
        return $out;
    }
}

if (!function_exists('sip_days_for_period')) {
    /** @return list<string> */
    function sip_days_for_period(string $endDate, string $period): array {
        if ($period === 'weekly') {
            return sip_days_week_through($endDate);
        }
        if ($period === 'monthly') {
            return sip_days_through($endDate);
        }
        return [];
    }
}

if (!function_exists('sip_is_split_remark')) {
    function sip_is_split_remark($remarks): bool {
        return (bool)preg_match('/week_split|mtd_split/i', (string)$remarks);
    }
}

if (!function_exists('sip_row_holds_period_total')) {
    /** End-day dump: this row already has the period NET SALES sitting on one day. */
    function sip_row_holds_period_total($er, float $periodTotal): bool {
        if (!is_array($er)) {
            return false;
        }
        $sales = (float)($er['pos_sales'] ?? 0);
        return $periodTotal > 0.004 && abs($sales - $periodTotal) <= 0.05;
    }
}

if (!function_exists('sip_row_is_locked_daily')) {
    /**
     * Real individual daily: keep amount and daily POS file.
     * Not locked: empty, prior week_split/mtd_split, or end day that currently holds the period total.
     */
    function sip_row_is_locked_daily($er, bool $isEndDay, float $periodTotal): bool {
        if (!is_array($er)) {
            return false;
        }
        if (sip_is_split_remark($er['remarks'] ?? '')) {
            return false;
        }
        $sales = (float)($er['pos_sales'] ?? 0);
        if ($sales <= 0.004) {
            return false;
        }
        if ($isEndDay && sip_row_holds_period_total($er, $periodTotal)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('sip_partition_period_days')) {
    /**
     * @param list<string> $days
     * @param array<string, array> $byDate
     * @return array{locked: list<string>, fill: list<string>, locked_sum: float, locked_paid: float}
     */
    function sip_partition_period_days(array $days, array $byDate, string $endDate, float $periodTotal): array {
        $locked = [];
        $fill = [];
        $lockedSum = 0.0;
        $lockedPaid = 0.0;
        foreach ($days as $d) {
            $er = $byDate[$d] ?? null;
            $isEnd = ($d === $endDate);
            if (sip_row_is_locked_daily($er, $isEnd, $periodTotal)) {
                $locked[] = $d;
                $lockedSum += (float)$er['pos_sales'];
                $lockedPaid += (float)($er['paid_out'] ?? 0);
            } else {
                $fill[] = $d;
            }
        }
        return [
            'locked' => $locked,
            'fill' => $fill,
            'locked_sum' => round($lockedSum, 2),
            'locked_paid' => round($lockedPaid, 2),
        ];
    }
}

if (!function_exists('sip_period_verify')) {
    /**
     * Period POS / typed Sales vs the sum of daily rows in that window.
     * MATCH within $0.01; otherwise SHORT (days under) or OVER (days over).
     * @return array{period_total: float, days_sum: float, diff: float, status: string, label: string, text: string}
     */
    function sip_period_verify(float $periodTotal, float $daysSum, string $periodTitle = 'Weekly'): array {
        $periodTotal = round($periodTotal, 2);
        $daysSum = round($daysSum, 2);
        $diff = round($daysSum - $periodTotal, 2);
        if (abs($diff) <= 0.01) {
            $status = 'match';
            $label = 'MATCH';
        } elseif ($diff < 0) {
            $status = 'short';
            $label = 'SHORT $' . number_format(abs($diff), 2);
        } else {
            $status = 'over';
            $label = 'OVER $' . number_format($diff, 2);
        }
        $title = ($periodTitle !== '') ? $periodTitle : 'Period';
        if (abs($diff) <= 0.01) {
            $text = 'Daily sales $' . number_format($daysSum, 2) . ' match ' . $title . ' POS $' . number_format($periodTotal, 2) . '.';
        } elseif ($diff > 0) {
            $text = 'Daily sales $' . number_format($daysSum, 2) . ' are OVER ' . $title . ' POS $' . number_format($periodTotal, 2) . ' by $' . number_format($diff, 2);
        } else {
            $text = 'Daily sales $' . number_format($daysSum, 2) . ' are SHORT of ' . $title . ' POS $' . number_format($periodTotal, 2) . ' by $' . number_format(abs($diff), 2);
        }
        return [
            'period_total' => $periodTotal,
            'days_sum' => $daysSum,
            'diff' => $diff,
            'status' => $status,
            'label' => $label,
            'text' => $text,
        ];
    }
}
