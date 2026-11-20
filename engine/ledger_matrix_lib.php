<?php
/**
 * Shared helpers for the pay-ledger matrix: stat-pay filter and expand/collapse.
 */

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
        'stat_h' => 't_stat_h', 'stat_cost' => 't_stat_cost',
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
