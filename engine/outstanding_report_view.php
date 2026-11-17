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

function orpt_report_html(string $menu, int $orgId, string $orgName, array $report): string
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
    $html .= orpt_page_close();
    return $html;
}
