<?php
/**
 * Shared helpers for the pay-ledger matrix: stat-pay filter and expand/collapse.
 */

function ledger_stat_visible(float $amount): bool {
    return $amount > 0.009;
}

function ledger_province_code(?string $raw): string {
    $p = strtoupper(trim((string)$raw));
    $map = [
        'ONTARIO' => 'ON', 'SASK' => 'SK', 'SASKATCHEWAN' => 'SK',
        'ALBERTA' => 'AB', 'BRITISH COLUMBIA' => 'BC', 'QUEBEC' => 'QC',
        'MANITOBA' => 'MB', 'NEW BRUNSWICK' => 'NB', 'NOVA SCOTIA' => 'NS',
        'PRINCE EDWARD ISLAND' => 'PE', 'NEWFOUNDLAND' => 'NL', 'NEWFOUNDLAND AND LABRADOR' => 'NL',
        'YUKON' => 'YT', 'NORTHWEST TERRITORIES' => 'NT', 'NUNAVUT' => 'NU',
    ];
    if (isset($map[$p])) {
        $p = $map[$p];
    } elseif (function_exists('sh_normalize_province')) {
        $p = sh_normalize_province($raw);
    }
    return preg_match('/^[A-Z]{2}$/', $p) ? $p : '';
}

/** @return array{codes:list<string>,unmapped:bool} */
function ledger_province_options(array $sites): array {
    $codes = [];
    $unmapped = false;
    foreach ($sites as $site) {
        $code = ledger_province_code($site['province'] ?? '');
        if ($code === '') {
            $unmapped = true;
            continue;
        }
        $codes[$code] = true;
    }
    $list = array_keys($codes);
    sort($list);
    return ['codes' => $list, 'unmapped' => $unmapped];
}

function ledger_site_in_province(?string $raw, string $filter): bool {
    if ($filter === '' || strcasecmp($filter, 'all') === 0) {
        return true;
    }
    $code = ledger_province_code($raw);
    if (strcasecmp($filter, 'unmapped') === 0) {
        return $code === '';
    }
    return $code === strtoupper($filter);
}

function ledger_province_param(string $raw, array $options): string {
    $raw = strtolower(trim($raw));
    if ($raw === '' || $raw === 'all') {
        return 'all';
    }
    if ($raw === 'unmapped') {
        return 'unmapped';
    }
    $code = strtoupper($raw);
    return in_array($code, $options['codes'], true) ? $code : 'all';
}

function ledger_row_is_stat_alloc(?string $category, ?string $payType): bool {
    return strcasecmp((string)$category, 'Statutory Holiday') === 0
        || strcasecmp((string)$payType, 'Statutory') === 0;
}

function ledger_stat_summary(array $sites): array {
    $employees = 0;
    $total = 0.0;
    foreach ($sites as $site) {
        foreach ($site['emps'] as $emp) {
            if (empty($emp['has_stat'])) {
                continue;
            }
            $employees++;
            $total += (float)($emp['t_stat_alloc'] ?? 0);
        }
    }
    return ['employees' => $employees, 'stat_total' => round($total, 2)];
}

/**
 * Drop employees with no statutory line, then sites with nobody left.
 * Site and period totals lose those employees' amounts.
 *
 * @return array{0:array,1:array}
 */
function ledger_strip_non_stat(array $sites, array $period): array {
    $keys = [
        'gross' => 't_gross', 'net' => 't_net', 'paid' => 't_paid', 'rem' => 't_rem',
        'reg_h' => 't_reg_h', 'ot_h' => 't_ot_h', 'travel_h' => 't_travel_h',
        'stat_h' => 't_stat_h', 'stat_cost' => 't_stat_cost', 'await' => 't_await',
        'ee' => 't_ee', 'ee_tax' => 't_ee_tax', 'ee_cpp' => 't_ee_cpp', 'ee_ei' => 't_ee_ei',
        'er' => 't_er', 'er_cpp' => 't_er_cpp', 'er_ei' => 't_er_ei', 'er_wcb' => 't_er_wcb', 'er_vac' => 't_er_vac',
    ];
    foreach ($sites as $sname => $site) {
        foreach ($site['emps'] as $uid => $emp) {
            if (!empty($emp['has_stat'])) {
                continue;
            }
            foreach ($keys as $pk => $ek) {
                $sites[$sname][$ek] = (float)($sites[$sname][$ek] ?? 0) - (float)($emp[$ek] ?? 0);
                $period[$pk] = (float)($period[$pk] ?? 0) - (float)($emp[$ek] ?? 0);
            }
            unset($sites[$sname]['emps'][$uid]);
        }
        if (empty($sites[$sname]['emps'])) {
            unset($sites[$sname]);
        }
    }
    return [$sites, $period];
}

function ledger_expand_controls(): string {
    return '<button type="button" class="ledger-expand-btn" onclick="ledgerExpandAll()">Expand all</button>'
        . '<button type="button" class="ledger-expand-btn" onclick="ledgerCollapseAll()">Collapse all</button>';
}

function ledger_expand_script(): string {
    return <<<'JS'
function ledgerExpandKey(){ return 'ledgerMatrixExpand:' + location.pathname; }
function ledgerApplyExpand(open){
    var table = document.getElementById('ledgerCoreMatrixTable');
    if (!table) return;
    var rows = table.querySelectorAll('tr.lvl-emp, tr.lvl-shift');
    var chevs = table.querySelectorAll('.chev');
    var i = 0;
    var size = 200;
    function chunk(){
        var end = Math.min(i + size, rows.length);
        for (; i < end; i++){
            if (open) rows[i].classList.remove('hidden');
            else rows[i].classList.add('hidden');
        }
        if (i < rows.length){ requestAnimationFrame(chunk); return; }
        for (var c = 0; c < chevs.length; c++){
            if (open) chevs[c].classList.add('open');
            else chevs[c].classList.remove('open');
        }
    }
    requestAnimationFrame(chunk);
}
function ledgerExpandAll(){
    ledgerApplyExpand(true);
    try { localStorage.setItem(ledgerExpandKey(), 'open'); } catch (e) {}
}
function ledgerCollapseAll(){
    ledgerApplyExpand(false);
    try { localStorage.setItem(ledgerExpandKey(), 'closed'); } catch (e) {}
}
document.addEventListener('DOMContentLoaded', function(){
    var saved = '';
    try { saved = localStorage.getItem(ledgerExpandKey()) || ''; } catch (e) {}
    if (saved === 'open') ledgerApplyExpand(true);
});
JS;
}
