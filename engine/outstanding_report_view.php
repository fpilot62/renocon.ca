<?php
/**
 * HTML for the outstanding report. Values are escaped here.
 * The menu HTML is the existing generator output.
 */

function orpt_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function orpt_page_open(string $menu, string $title): string
{
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . orpt_h($title) . '</title>'
        . '<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">'
        . '<style>'
        . 'body { font-family: \'Segoe UI\', sans-serif; background: #f1f5f9; padding: 90px 16px 40px; color: #1e293b; font-size: 13px; }'
        . '.sheet { background: #fff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 16px; margin-bottom: 16px; }'
        . 'table.ledger { width: 100%; }'
        . 'table.ledger th { background: #0f172a; color: #fff; font-size: 11px; text-transform: uppercase; white-space: nowrap; }'
        . 'table.ledger td, table.ledger th { vertical-align: middle; }'
        . '.money { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }'
        . '.sub td { background: #e2e8f0; font-weight: 800; }'
        . '.intro { flex: 1 1 640px; max-width: 920px; }'
        . '.export-btn { flex: 0 0 auto; }'
        . '.name-cell { white-space: nowrap; }'
        . '.emp-link { font-weight: 800; color: #1d4ed8; }'
        . '.emp-link.active { color: #0f172a; text-decoration: none; }'
        . '.periods { display: block; font-size: 11px; font-weight: 600; color: #64748b; }'
        . '.note { max-width: 280px; }'
        . '.since { white-space: nowrap; }'
        . '.demand-form .form-control { font-size: 13px; }'
        . '.cash-flag { font-weight: 800; color: #9a3412; }'
        . '</style></head><body>';
    $html .= $menu;
    $html .= '<div class="container-fluid">';
    return $html;
}

function orpt_page_close(): string
{
    return '</div></body></html>';
}

function orpt_choices_html(string $menu, array $orgs): string
{
    $html = orpt_page_open($menu, 'Outstanding report');
    $html .= '<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">'
        . '<div><h4 class="font-weight-bold mb-1">Outstanding report</h4>'
        . '<p class="text-muted mb-0">Choose an organization your support access covers. Wage rows stay hidden until you do.</p></div></div>';
    $html .= '<div class="sheet">';
    if (!$orgs) {
        $html .= '<p class="mb-0 text-muted">No support-access grant is on this account.</p>';
    } else {
        $html .= '<ul class="mb-0">';
        foreach ($orgs as $org) {
            $href = 'outstanding_report.php?org_id=' . (int)$org['id'];
            $html .= '<li><a href="' . orpt_h($href) . '">' . orpt_h($org['name']) . '</a></li>';
        }
        $html .= '</ul>';
    }
    $html .= '</div>';
    $html .= orpt_page_close();
    return $html;
}

function orpt_report_html(string $menu, int $orgId, string $orgName, array $report, ?array $demand = null): string
{
    $store = (string)$report['store'];
    $employee = (string)$report['employee'];
    $exportHref = orpt_query($orgId, $store, $employee, true);
    $clearHref = orpt_query($orgId, $store, '', false);
    $activeName = '';
    foreach ($report['employees'] as $emp) {
        if ($employee !== '' && $emp['key'] === $employee) {
            $activeName = (string)$emp['name'];
        }
    }
    $html = orpt_page_open($menu, 'Outstanding report');
    $html .= '<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">'
        . '<div class="intro"><h4 class="font-weight-bold mb-1">Outstanding report</h4>'
        . '<p class="text-muted mb-0">' . orpt_h($orgName) . '. Pending lines only. Paid, disputed, and duplicate lines are left out. '
        . 'A payroll line costs gross plus vacation pay plus employer contributions. An invoiced line costs net plus 5% GST. '
        . 'CPP, EI, and WCB come from the note when it names them. Otherwise the employer deductions figure is used. '
        . 'This page does not save anything and does not mark anyone paid.</p></div>'
        . '<a class="btn btn-outline-dark font-weight-bold export-btn" href="' . orpt_h($exportHref) . '">Download Excel</a>'
        . '</div>';

    $html .= '<form method="GET" class="sheet" action="outstanding_report.php">'
        . '<input type="hidden" name="org_id" value="' . (int)$orgId . '">'
        . '<div class="form-row align-items-end">'
        . '<div class="col-md-4 mb-2"><label class="small font-weight-bold text-uppercase text-muted">Store</label>'
        . '<select name="store" class="form-control form-control-sm font-weight-bold" onchange="this.form.submit()">'
        . '<option value="">All stores</option>';
    foreach ($report['stores'] as $opt) {
        $selected = $opt['key'] === $store ? ' selected' : '';
        $html .= '<option value="' . orpt_h($opt['key']) . '"' . $selected . '>' . orpt_h($opt['name']) . '</option>';
    }
    $html .= '</select></div>'
        . '<div class="col-md-2 mb-2"><button class="btn btn-sm btn-primary" type="submit">Show</button></div>'
        . '</div></form>';

    if (!$report['employees']) {
        $html .= '<div class="sheet"><p class="mb-0 text-muted">No pending wages for this view.</p></div>';
        $html .= orpt_page_close();
        return $html;
    }

    $html .= '<div class="sheet table-responsive"><table class="table table-sm ledger mb-0">'
        . '<thead><tr>';
    foreach (orpt_summary_headers() as $heading) {
        $class = in_array($heading, ['Gross outstanding', 'Net owed', 'Employer contributions', 'Total cost'], true) ? ' class="money"' : '';
        $html .= '<th' . $class . '>' . orpt_h($heading) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($report['employees'] as $emp) {
        $href = orpt_query($orgId, $store, (string)$emp['key'], false) . '#detail';
        $active = $employee !== '' && $emp['key'] === $employee;
        $linkClass = 'emp-link' . ($active ? ' active' : '');
        $periodWord = (int)$emp['periods'] === 1 ? '1 period' : ((int)$emp['periods'] . ' periods');
        $since = $emp['since'] === '' ? '—' : $emp['since'];
        $notes = (string)$emp['notes'];
        $short = strlen($notes) > 180 ? (substr($notes, 0, 177) . '...') : $notes;
        $html .= '<tr>'
            . '<td class="name-cell"><a class="' . $linkClass . '" href="' . orpt_h($href) . '">' . orpt_h($emp['name']) . '</a>'
            . '<span class="periods">' . orpt_h($periodWord) . '</span></td>'
            . '<td>' . orpt_h($emp['stores']) . '</td>'
            . '<td class="money">' . orpt_h($emp['gross_text']) . '</td>'
            . '<td class="money">' . orpt_h($emp['net_text']) . '</td>'
            . '<td class="money">' . orpt_h($emp['employer_text']) . '</td>'
            . '<td class="money">' . orpt_h($emp['total_text']) . '</td>'
            . '<td class="since">' . orpt_h($since) . '</td>'
            . '<td class="note" title="' . orpt_h($notes) . '">' . orpt_h($short) . '</td>'
            . '</tr>';
    }
    $total = $report['totals_row'];
    $html .= '<tr class="sub">';
    foreach ($total as $i => $cell) {
        $class = ($i >= 2 && $i <= 5) ? ' class="money"' : '';
        $shown = ($i === 6 && $cell === '') ? '—' : $cell;
        $html .= '<td' . $class . '>' . orpt_h($shown) . '</td>';
    }
    $html .= '</tr></tbody></table></div>';

    $detailTitle = $activeName !== '' ? ('Periods for ' . $activeName) : 'Periods';
    $html .= '<div class="sheet table-responsive" id="detail">'
        . '<div class="d-flex justify-content-between align-items-center mb-2">'
        . '<h5 class="font-weight-bold mb-0">' . orpt_h($detailTitle) . '</h5>';
    if ($employee !== '') {
        $html .= '<a href="' . orpt_h($clearHref) . '#detail">All employees</a>';
    }
    $html .= '</div><table class="table table-sm ledger mb-0"><thead><tr>'
        . '<th>Employee</th><th>Store</th><th>Pay period</th><th>Period start</th><th>Period end</th>'
        . '<th class="money">Gross</th><th class="money">Vacation pay</th><th class="money">Net</th>'
        . '<th class="money">Employer contributions</th><th class="money">Total cost</th><th>Notes</th>'
        . '</tr></thead><tbody>';
    foreach ($report['detail'] as $line) {
        $html .= '<tr>'
            . '<td class="name-cell">' . orpt_h($line['employee']) . '</td>'
            . '<td>' . orpt_h($line['store']) . '</td>'
            . '<td>' . orpt_h($line['pay_period']) . '</td>'
            . '<td class="since">' . orpt_h($line['period_start'] === '' ? '—' : $line['period_start']) . '</td>'
            . '<td class="since">' . orpt_h($line['period_end'] === '' ? '—' : $line['period_end']) . '</td>'
            . '<td class="money">' . orpt_h(orpt_money($line['gross'])) . '</td>'
            . '<td class="money">' . orpt_h($line['vacation'] === null ? '—' : orpt_money($line['vacation'])) . '</td>'
            . '<td class="money">' . orpt_h($line['net'] === null ? '—' : orpt_money($line['net'])) . '</td>'
            . '<td class="money">' . orpt_h($line['employer']) . '</td>'
            . '<td class="money">' . orpt_h($line['total'] === null ? '—' : orpt_money($line['total'])) . '</td>'
            . '<td class="note">' . orpt_h($line['notes']) . '</td>'
            . '</tr>';
    }
    $html .= '</tbody></table></div>';
    if ($demand !== null) {
        $html .= orpt_demand_html($orgId, $store, $employee, $demand);
    }
    $html .= orpt_page_close();
    return $html;
}

function orpt_demand_href(int $orgId, string $store, string $employee, int $demandId): string
{
    $url = orpt_query($orgId, $store, $employee, false);
    if ($demandId > 0) {
        $url .= '&demand=' . $demandId;
    }
    return $url . '#immediate-demand';
}

function orpt_demand_html(int $orgId, string $store, string $employee, array $demand): string
{
    $edit = $demand['edit'] ?? orpt_demand_form_values(null);
    $editing = (int)($edit['id'] ?? 0) > 0;
    $csrf = (string)($demand['csrf'] ?? '');
    $html = '<div class="sheet" id="immediate-demand">'
        . '<h5 class="font-weight-bold mb-1">Immediate demand</h5>'
        . '<p class="text-muted">People who need pay right away. Employer contribution is CPP + EI + WCB, and it stays blank for cash or contractor rows. Total burden is gross plus vacation pay plus that contribution. This list is separate from the ledger above. Saving here does not mark anyone paid.</p>';
    $flash = trim((string)($demand['flash'] ?? ''));
    $error = trim((string)($demand['error'] ?? ''));
    if ($flash !== '') {
        $html .= '<div class="alert alert-info">' . orpt_h($flash) . '</div>';
    }
    if ($error !== '') {
        $html .= '<div class="alert alert-warning">' . orpt_h($error) . '</div>';
    }
    if (empty($demand['ready'])) {
        $html .= '<p class="mb-0 text-muted">The immediate demand list is not ready yet. The outstanding report above was not changed.</p></div>';
        return $html;
    }
    $html .= '<form method="POST" action="' . orpt_h(orpt_demand_href($orgId, $store, $employee, 0)) . '" class="demand-form mb-3">'
        . '<input type="hidden" name="csrf_token" value="' . orpt_h($csrf) . '">'
        . '<input type="hidden" name="org_id" value="' . (int)$orgId . '">'
        . '<input type="hidden" name="store" value="' . orpt_h($store) . '">'
        . '<input type="hidden" name="employee" value="' . orpt_h($employee) . '">'
        . '<input type="hidden" name="demand_action" value="save">'
        . '<input type="hidden" name="demand_id" value="' . (int)$edit['id'] . '">'
        . '<div class="form-row">'
        . '<div class="col-md-4 mb-2"><label class="small font-weight-bold text-uppercase text-muted">Outstanding period</label>'
        . '<select name="wage_id" id="demand-wage" class="form-control form-control-sm">'
        . '<option value="">None</option>';
    $wageSeen = false;
    foreach ($demand['wages'] as $wage) {
        if ((int)$wage['id'] === (int)$edit['wage_id']) {
            $wageSeen = true;
        }
        $selected = (int)$wage['id'] === (int)$edit['wage_id'] ? ' selected' : '';
        $html .= '<option value="' . (int)$wage['id'] . '"' . $selected
            . ' data-name="' . orpt_h($wage['employee_name']) . '"'
            . ' data-store="' . orpt_h($wage['store_name']) . '"'
            . ' data-period="' . orpt_h($wage['pay_period']) . '"'
            . ' data-gross="' . orpt_h($wage['gross']) . '"'
            . ' data-vacation="' . orpt_h($wage['vacation_pay']) . '"'
            . ' data-employer="' . orpt_h($wage['employer_contribution']) . '"'
            . ' data-net="' . orpt_h($wage['net_cash']) . '">'
            . orpt_h($wage['label']) . '</option>';
    }
    if ((int)$edit['wage_id'] > 0 && !$wageSeen) {
        $html .= '<option value="' . (int)$edit['wage_id'] . '" selected>Linked ledger row ' . (int)$edit['wage_id'] . '</option>';
    }
    $cashChecked = (int)$edit['cash_flag'] === 1 ? ' checked' : '';
    $html .= '</select></div>'
        . '<div class="col-md-4 mb-2"><label class="small font-weight-bold text-uppercase text-muted">Name</label>'
        . '<input class="form-control form-control-sm" id="demand-name" name="employee_name" required maxlength="160" value="' . orpt_h($edit['employee_name']) . '"></div>'
        . '<div class="col-md-4 mb-2"><label class="small font-weight-bold text-uppercase text-muted">Store</label>'
        . '<input class="form-control form-control-sm" id="demand-store" name="store_name" maxlength="160" value="' . orpt_h($edit['store_name']) . '"></div>'
        . '<div class="col-md-4 mb-2"><label class="small font-weight-bold text-uppercase text-muted">Pay period</label>'
        . '<input class="form-control form-control-sm" id="demand-period" name="pay_period" maxlength="160" value="' . orpt_h($edit['pay_period']) . '"></div>'
        . '<div class="col-md-2 mb-2"><label class="small font-weight-bold text-uppercase text-muted">Gross</label>'
        . '<input class="form-control form-control-sm" id="demand-gross" name="gross" inputmode="decimal" required value="' . orpt_h($edit['gross']) . '"></div>'
        . '<div class="col-md-2 mb-2"><label class="small font-weight-bold text-uppercase text-muted">Vacation pay</label>'
        . '<input class="form-control form-control-sm" id="demand-vacation" name="vacation_pay" inputmode="decimal" value="' . orpt_h($edit['vacation_pay']) . '"></div>'
        . '<div class="col-md-2 mb-2"><label class="small font-weight-bold text-uppercase text-muted">Employer CPP+EI+WCB</label>'
        . '<input class="form-control form-control-sm" id="demand-employer" name="employer_contribution" inputmode="decimal" value="' . orpt_h($edit['employer_contribution']) . '"' . ((int)$edit['cash_flag'] === 1 ? ' disabled' : '') . '></div>'
        . '<div class="col-md-2 mb-2"><label class="small font-weight-bold text-uppercase text-muted">Net cash</label>'
        . '<input class="form-control form-control-sm" id="demand-net" name="net_cash" inputmode="decimal" value="' . orpt_h($edit['net_cash']) . '"></div>'
        . '<div class="col-md-2 mb-2"><label class="d-block small font-weight-bold text-uppercase text-muted">Cash</label>'
        . '<label class="mb-0"><input type="checkbox" id="demand-cash" name="cash_flag" value="1"' . $cashChecked . '> Cash / contractor</label></div>'
        . '<div class="col-md-8 mb-2"><label class="small font-weight-bold text-uppercase text-muted">Notes</label>'
        . '<textarea class="form-control form-control-sm" name="notes" maxlength="8000" rows="2">' . orpt_h($edit['notes']) . '</textarea></div>'
        . '<div class="col-md-4 mb-2 d-flex align-items-end">'
        . '<button class="btn btn-sm btn-primary mr-2" type="submit">' . ($editing ? 'Save changes' : 'Add') . '</button>';
    if ($editing) {
        $html .= '<a class="btn btn-sm btn-outline-secondary" href="' . orpt_h(orpt_demand_href($orgId, $store, $employee, 0)) . '">Cancel</a>';
    }
    $html .= '</div></div></form>';

    $rows = $demand['rows'] ?? [];
    if (!$rows) {
        $html .= '<p class="mb-0 text-muted">No one is on the immediate demand list.</p></div>';
        $html .= orpt_demand_script();
        return $html;
    }
    $html .= '<div class="table-responsive"><table class="table table-sm ledger mb-0"><thead><tr>'
        . '<th>Name</th><th>Store</th><th>Pay period</th><th class="money">Gross</th><th class="money">Vacation pay</th>'
        . '<th class="money">Employer contribution</th><th class="money">Total burden</th><th class="money">Net cash</th>'
        . '<th>Cash</th><th>Notes</th><th></th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $link = (int)$row['wage_id'] > 0 ? ('<span class="periods">Ledger row ' . (int)$row['wage_id'] . '</span>') : '';
        $notes = (string)$row['notes'];
        $short = strlen($notes) > 180 ? (substr($notes, 0, 177) . '...') : $notes;
        $html .= '<tr>'
            . '<td class="name-cell">' . orpt_h($row['name']) . '</td>'
            . '<td>' . orpt_h($row['store']) . '</td>'
            . '<td>' . orpt_h($row['pay_period']) . $link . '</td>'
            . '<td class="money">' . orpt_h($row['gross_text']) . '</td>'
            . '<td class="money">' . orpt_h($row['vacation_text']) . '</td>'
            . '<td class="money">' . orpt_h($row['employer_text']) . '</td>'
            . '<td class="money">' . orpt_h($row['burden_text']) . '</td>'
            . '<td class="money">' . orpt_h($row['net_text']) . '</td>'
            . '<td class="cash-flag">' . orpt_h($row['cash_text']) . '</td>'
            . '<td class="note" title="' . orpt_h($notes) . '">' . orpt_h($short) . '</td>'
            . '<td class="since">'
            . '<a href="' . orpt_h(orpt_demand_href($orgId, $store, $employee, (int)$row['id'])) . '">Edit</a>'
            . '<form method="POST" action="' . orpt_h(orpt_demand_href($orgId, $store, $employee, 0)) . '" class="d-inline" onsubmit="return confirm(\'Remove this immediate demand row?\');">'
            . '<input type="hidden" name="csrf_token" value="' . orpt_h($csrf) . '">'
            . '<input type="hidden" name="org_id" value="' . (int)$orgId . '">'
            . '<input type="hidden" name="store" value="' . orpt_h($store) . '">'
            . '<input type="hidden" name="employee" value="' . orpt_h($employee) . '">'
            . '<input type="hidden" name="demand_action" value="remove">'
            . '<input type="hidden" name="demand_id" value="' . (int)$row['id'] . '">'
            . '<button class="btn btn-link btn-sm text-danger p-0 ml-2" type="submit">Remove</button>'
            . '</form></td></tr>';
    }
    $totals = $demand['totals'];
    $html .= '<tr class="sub">'
        . '<td>Total</td>'
        . '<td>' . orpt_h($totals['people_text']) . '</td>'
        . '<td></td>'
        . '<td class="money">' . orpt_h($totals['gross_text']) . '</td>'
        . '<td class="money">' . orpt_h($totals['vacation_text']) . '</td>'
        . '<td class="money">' . orpt_h($totals['employer_text']) . '</td>'
        . '<td class="money">' . orpt_h($totals['burden_text']) . '</td>'
        . '<td class="money">' . orpt_h($totals['net_text']) . '</td>'
        . '<td></td><td></td><td></td>'
        . '</tr></tbody></table></div></div>';
    $html .= orpt_demand_script();
    return $html;
}

function orpt_demand_script(): string
{
    return '<script>'
        . '(function () {'
        . 'var sel = document.getElementById("demand-wage");'
        . 'var cash = document.getElementById("demand-cash");'
        . 'var employer = document.getElementById("demand-employer");'
        . 'function fill() {'
        . 'if (!sel) return;'
        . 'var opt = sel.options[sel.selectedIndex];'
        . 'if (!opt || !opt.getAttribute("data-name")) return;'
        . 'function set(id, attr) { var el = document.getElementById(id); if (el) el.value = opt.getAttribute(attr) || ""; }'
        . 'set("demand-name", "data-name");'
        . 'set("demand-store", "data-store");'
        . 'set("demand-period", "data-period");'
        . 'set("demand-gross", "data-gross");'
        . 'set("demand-vacation", "data-vacation");'
        . 'set("demand-employer", "data-employer");'
        . 'set("demand-net", "data-net");'
        . 'syncCash();'
        . '}'
        . 'function syncCash() {'
        . 'if (!cash || !employer) return;'
        . 'employer.disabled = cash.checked;'
        . 'if (cash.checked) employer.value = "";'
        . '}'
        . 'if (sel) sel.addEventListener("change", fill);'
        . 'if (cash) cash.addEventListener("change", syncCash);'
        . 'syncCash();'
        . '})();'
        . '</script>';
}
