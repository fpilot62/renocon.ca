<?php
/**
 * Path: /payroll_help.php
 * SHARED "About this page" help panel + guided tour bootstrap + Ask AI for payroll pages.
 *
 * USAGE (one line near the top of a page body):
 *   <?php require_once __DIR__.'/payroll_help.php'; echo payroll_help_panel('staff_audit.php'); ?>
 *
 * ALL help text and tour steps live in payroll_help_content() below — edit content
 * there only. The tour engine is /payroll_tour.js (+ /payroll_tour.css).
 * Ask AI UI is /payroll_ask.js; answers come from /payroll_ask_ai.php (retrieval + optional LLM).
 *
 * Design constraints:
 *   - Purely additive: no DB writes from help/tour, safe on every include.
 *   - Renders nothing if the page key is unknown or there is no logged-in session.
 *   - Collapsible (closed by default), print-hidden, admin-facing wording.
 *   - Statutory figures shown are the app's configurable defaults, not legal advice.
 */

if (!function_exists('payroll_help_content')) {

/**
 * Single source of truth for per-page help + tour steps.
 * Each entry: title, purpose, how (steps list), calc (list), notes (list),
 * tour (list of ['sel'=>css or null for centered, 'title'=>..., 'body'=>...]).
 */
function payroll_help_content(): array {
    $vac = 'Vacation %: work-site province + service start date, as of each pay line\'s work date — Ontario 4% (6% at 5+ years); Saskatchewan 3/52 ≈ 5.77% (4/52 ≈ 7.69% at 10+ years). A per-employee/site Override % (Vacation Service page) always wins. Statutory defaults only — not legal advice.';
    $ot  = 'Overtime defaults (site policy): Ontario weekly over 44 h; Saskatchewan daily over 8 h plus weekly over 40 h (no double counting). Weeks run Monday–Sunday; OT is paid at 1.5×.';
    $engine = 'Deductions are computed ONCE at finalize time by the payroll engine (pe_line) using the flat rates in Payroll Settings — defaults: CPP 5.95%, EI 1.63%, income tax 15%, WCB 2%; employer matches CPP 1:1 and pays EI × 1.4, plus WCB and vacation. Stored values are then read forever; changing a rate later never rewrites history.';
    $disclaimer = 'Statutory rates and formulas shown here are the app\'s configurable defaults for Ontario and Saskatchewan workflows — not legal advice. Confirm with your accountant or CRA before filing.';

    return [

    'payroll_master_ledger.php' => [
        'title'   => 'Payroll Center — Employee & Employer Report',
        'purpose' => 'The hub after Staff Audit finalize: every finalized shift with stored gross, employee deductions (tax / CPP / EI), employer contributions (CPP / EI / WCB / vacation), payments, balances — plus paystubs, cheques, T4, CRA remittance, Stat Pay Preview, and accounting exports. This page never recalculates history; it only reads values locked at finalize time.',
        'how'     => [
            'Set Start/End Range, optionally Employee and Location, View Category, Pay Period Cycle, and Balance Scope, then press Update Matrix. Nothing reloads until you press that button.',
            'Balance Scope is the #1 reason the report looks “blank”: Outstanding shows only rows still owed money; Zero Balance shows settled rows; All Rows (default) keeps paid staff visible. If you just paid everyone and the grid emptied, switch back to All Rows and Update Matrix.',
            'Read the summary table first (org totals + site subtotals + Individual / Site Batch document links), then use Shift Execution Detail below.',
            'In the matrix: click a site row to expand employees; click an employee to expand individual shifts. Tick checkboxes at site, employee, or shift level for batch actions.',
            'EE Deductions column = Tax + CPP + EI withheld, with Net payable (Total to Employee). ER Contributions = CPP_ER + EI_ER + WCB + Vac (employer cost on top of gross). Total to CRA = employee tax/CPP/EI + employer CPP/EI only — WCB and vacation are NOT remitted to CRA.',
            'Execution Desk: Pay Net on one shift, or Pay Employee Period for the whole window; Print cheques and Print paystubs are separate files for ticked rows; Email paystubs sends each stub to the employee; Pay Selected Shifts for batches. Site Batch / Individual open document packs from the summary table.',
            'Header links: Stat Pay Preview, Tax & T4, CRA Detail, QuickBooks Hours CSV, ADP Hours CSV, Configure mapping, QuickBooks Accounting Journal.',
            'Use About this page for the written guide, Start Tour for a step-by-step spotlight walkthrough, or Ask AI if you have a specific question.',
        ],
        'calc'    => [
            'All money columns are read from values stored at finalize time — this page never recalculates history.',
            $engine,
            $vac,
            $ot,
            'Total to CRA = EE tax + EE CPP + EE EI + ER CPP + ER EI. WCB and vacation appear in ER columns but are excluded from CRA remittance totals.',
        ],
        'notes'   => [
            'Everything is scoped to your organization; other orgs\' data is never shown or exported.',
            'Send Back returns a shift to Staff Audit (Step 1) and removes it from payroll so you can re-audit and re-finalize.',
            $disclaimer,
        ],
        'tour'    => [
            ['sel'=>null, 'title'=>'Welcome to Payroll Center', 'body'=>'This is your master Employee & Employer report after shifts are finalized in Staff Audit. Every dollar you see here was calculated once at finalize time and stored — changing rates later will not rewrite these rows. This tour walks the real workflow: filters → matrix hierarchy → EE/ER columns → pay documents → exports. Use Start Tour anytime to replay.'],
            ['sel'=>'#pgHelpPanel', 'title'=>'About this page & Start Tour', 'body'=>'Open About this page for the full written guide (purpose, how-to, calculations, notes). Start Tour replays this spotlight walkthrough. Ask AI (floating button, bottom-right) answers questions grounded in this help — it will not run payroll actions or invent other orgs\' data.'],
            ['sel'=>'input[name="from_date"]', 'title'=>'Start Range', 'body'=>'Set the beginning of the reporting window. Pair it with End Range. Pay Period Cycle (semi-monthly, bi-weekly, monthly) snaps how period bands and totals are grouped after you Update Matrix.'],
            ['sel'=>'input[name="to_date"]', 'title'=>'End Range', 'body'=>'Set the end of the window. Only finalized shifts with work dates inside Start–End appear after Update Matrix.'],
            ['sel'=>'select[name="user_id"]', 'title'=>'Employee filter', 'body'=>'Limit the matrix to one employee, or leave All Roster Staff. Useful when reconciling one person\'s pay period without scrolling the whole org.'],
            ['sel'=>'select[name="site_id"]', 'title'=>'Location (site) filter', 'body'=>'Limit to one work site. Sites also decide the province used for vacation % and overtime rules when those shifts were finalized.'],
            ['sel'=>'select[name="view_category"]', 'title'=>'View Category', 'body'=>'Narrow which kinds of pay lines appear in the matrix (regular / category views). Combine with date and Balance Scope, then Update Matrix.'],
            ['sel'=>'select[name="period_mode"]', 'title'=>'Pay Period Cycle', 'body'=>'Choose how the period band groups totals: semi-monthly, bi-weekly, or monthly. This does not change stored line amounts — only how the report bands them.'],
            ['sel'=>'select[name="balance_scope"]', 'title'=>'Balance Scope (why the report goes blank)', 'body'=>'All Rows = everything in the window (default — paid staff stay visible). Outstanding = only rows with Net Remaining > $0. Zero Balance = fully settled rows. If the table looks empty after you paid people, set this back to All Rows and press Update Matrix.'],
            ['sel'=>'.control-panel-card button[type="submit"]', 'title'=>'Update Matrix', 'body'=>'Apply all filters above. Nothing on the page reloads until you press this. After changing Balance Scope or dates, always Update Matrix before assuming data is missing.'],
            ['sel'=>'.summary-table', 'title'=>'Org & site totals (top summary)', 'body'=>'Read this first: organization totals, then each site subtotal. Columns include Gross, Total to Employee (Net), employer costs, and Total to CRA. Site Batch prints documents for everyone at that site; Individual prints one employee\'s pack.'],
            ['sel'=>'.summary-table th.not-cra, .summary-table .not-cra', 'title'=>'WCB & Vacation are not CRA', 'body'=>'WCB and Vacation sit in employer-cost columns but are marked “not CRA”. They are company costs — do not include them when remitting source deductions to CRA.'],
            ['sel'=>'#ledgerCoreMatrixTable', 'title'=>'Site → Employee → Shift hierarchy', 'body'=>'Shift Execution Detail is a three-level tree. Click a site row (building icon) to expand employees; click an employee to expand individual shifts. Site checkboxes select everyone under that site for batch pay/docs.'],
            ['sel'=>'#ledgerCoreMatrixTable thead th:nth-child(3)', 'title'=>'Gross', 'body'=>'Gross pay for the row (site / employee / shift). Comes from hours × rates (and OT/stat) locked at finalize — not recalculated live.'],
            ['sel'=>'#ledgerCoreMatrixTable thead th:nth-child(4)', 'title'=>'EE deductions → Total to Employee', 'body'=>'Employee withholdings: Income Tax, CPP, and EI. The sub-line shows Net payable — that is Total to Employee (what you owe the worker after deductions).'],
            ['sel'=>'#ledgerCoreMatrixTable thead th:nth-child(5)', 'title'=>'ER contributions vs Total to CRA', 'body'=>'Employer side: CPP_ER (1:1 match), EI_ER (× 1.4), WCB, and Vacation. Total employer cost = Gross + these ER amounts. Total to CRA uses only Tax + CPP + EI on both EE and ER sides — never WCB or Vac.'],
            ['sel'=>'#ledgerCoreMatrixTable thead th:nth-child(6)', 'title'=>'Paid', 'body'=>'How much of the net has already been paid via Pay Net, Pay Employee Period, or Pay Selected. Compare to Net Remaining next door.'],
            ['sel'=>'#ledgerCoreMatrixTable thead th:nth-child(7)', 'title'=>'Net Remaining', 'body'=>'Still owed to the employee. Green ≈ settled; red ≈ outstanding. Balance Scope Outstanding filters to these unpaid rows.'],
            ['sel'=>'#ledgerCoreMatrixTable thead th:nth-child(8)', 'title'=>'Execution Desk — pay one or many', 'body'=>'On expanded shifts: Pay Net settles one line. On an employee row: Pay Employee Period pays that person for the whole visible window. Tick rows, then use Pay Selected Shifts in the header for batches.'],
            ['sel'=>'#genChequesBtn', 'title'=>'Print cheques', 'body'=>'Prints a cheque file only, for the ticked rows. Choose blank paper or pre-printed stock first. Pre-printed stock puts the date, name, amount, and memo on the cheque and leaves the paystub out. Use Print paystubs for the stub file, or Email paystubs to send each person their own stub.'],
            ['sel'=>'#paySelBtn', 'title'=>'Pay Selected Shifts', 'body'=>'Batch-settles every ticked shift using the chosen payment method (e.g. E-Transfer). Confirm Net Remaining first — this records payment against stored nets; it does not re-run the tax engine.'],
            ['sel'=>'a[href^="manual_pay_entry.php"]', 'title'=>'Manual Pay / Adjustments', 'body'=>'Open this for bonuses, car allowance, termination pay, travel lumps, wage adjustments, or off-payroll reimbursements. Include = Yes puts the amount on the payroll cheque as its own line (Bonus or Car allowance) and on the T4. Include = No creates a payment voucher only (not employment income). Use Start Tour on that page for the full how-to.'],
            ['sel'=>'a[href^="generate_stat_shifts.php"]', 'title'=>'Stat Pay Preview', 'body'=>'Opens statutory holiday pay preview/approve. Nothing posts until you Approve on that page. Use when a holiday falls in your period and eligible staff need ON/SK formula-based pay.'],
            ['sel'=>'a[href^="generate_t4.php"]', 'title'=>'Tax & T4', 'body'=>'Year-end T4 worksheets from these same stored finalized values (Box 14 income, 22 tax, 16 CPP, 18 EI). Open when preparing annual slips — not for day-to-day pay runs.'],
            ['sel'=>'a[href^="payroll_outstanding_remittance.php"]', 'title'=>'CRA Detail', 'body'=>'Drill into what is owed to CRA for the window (employee deductions + employer CPP/EI). Pair with the CRA Remittance Register to log bank settlements.'],
            ['sel'=>'a[href*="format=quickbooks"]', 'title'=>'QuickBooks Hours CSV', 'body'=>'Hours-only export (Employee, Date, Duration, Payroll Item, Notes) for QuickBooks Time imports. Does not create journal entries — use Accounting Journal for that.'],
            ['sel'=>'a[href*="format=adp"]', 'title'=>'ADP Hours CSV', 'body'=>'Hours export in ADP layout: Employee ID/Name, Pay Code, Hours, Rate, Work Date, Period Start/End, Site — for ADP or similar importers.'],
            ['sel'=>'a[href*="qb_export_setup"]', 'title'=>'Configure mapping', 'body'=>'One-time QuickBooks account mapping (wages, net pay, CPP/EI/tax payable, optional WCB/vacation). Set this up before first Accounting Journal export so entries land on your chart of accounts.'],
            ['sel'=>'button[name="export_qb_matrix"]', 'title'=>'QuickBooks Accounting Journal', 'body'=>'Exports the currently loaded matrix rows as balanced double-entry journal entries (CSV or IIF). Uses your Configure mapping profile. Always balances: omitting an optional account drops a balanced pair.'],
            ['sel'=>null, 'title'=>'You\'re ready', 'body'=>'Typical run: Update Matrix (All Rows) → expand & review → tick → Pay Selected / docs → CRA/T4/exports as needed. If the grid is empty, check Balance Scope. Replay with Start Tour; Ask AI for anything not covered.'],
        ],
    ],

    'staff_audit.php' => [
        'title'   => 'Staff Audit — Review & Finalize Shifts (Step 1)',
        'purpose' => 'Audit raw attendance punches (selfies, GPS, breaks), then finalize selected shifts into payroll. Finalizing runs the payroll engine and writes locked pay lines to payroll_finalized — those lines then appear in Payroll Center.',
        'how'     => [
            'Filter by date, staff, site and Status Type (keep Pending for work still to finalize) and press Search.',
            'Audit each row: punches, breaks, duration, selfie/GPS evidence. Use row tools to fix breaks or duplicate a shift if needed.',
            'Tick the shifts to pay (header checkbox selects all visible), then press Finalize Selected.',
            'In the Bulk Pay Allocation Desk choose Overtime Policy: per-shift daily cap, work-site provincial policy (ON weekly 44 h / SK daily 8 h + weekly 40 h), weekly 44/40 only, or daily 8 h + weekly caps.',
            'Review the regular/OT split preview in the modal, then Execute Pay Matrix Process. Finalized rows leave Pending and show up in Payroll Center — not back on this Pending list.',
        ],
        'calc'    => [
            $ot,
            'Weekly policies group the selected shifts into Monday–Sunday weeks before splitting regular vs OT hours; combined policies never double-count the same hour. OT pays 1.5×.',
            $engine,
            $vac,
            $disclaimer,
        ],
        'notes'   => [
            'Only shifts inside your organization are listed and processable.',
            'A finalized shift can be sent back from Payroll Center ("Send Back") if it needs re-auditing; that returns it to Pending here.',
        ],
        'tour'    => [
            ['sel'=>null, 'title'=>'Staff Audit — Step 1 of payroll', 'body'=>'This is where attendance becomes pay. You audit punches, choose an overtime policy, preview regular vs OT, then finalize. After Execute, rows are locked in payroll_finalized and appear in Payroll Center. Nothing is owed to staff until that happens.'],
            ['sel'=>'#pgHelpPanel', 'title'=>'Help, Tour & Ask AI', 'body'=>'About this page has the written workflow. Start Tour replays these steps. Ask AI can explain OT policies or where finalized rows go next.'],
            ['sel'=>'.filter-section form, form.filter-form, .filter-section', 'title'=>'Filters & Search', 'body'=>'Set date range, staff, site, and Status Type, then Search. Narrowing the list before you select prevents finalizing the wrong people\'s shifts.'],
            ['sel'=>'select[name="status_type"]', 'title'=>'Status Type', 'body'=>'Pending = awaiting finalize (your normal daily view). Finalized = already in payroll (read-only here). All = both. Keep Pending while processing a pay run.'],
            ['sel'=>'#auditTable', 'title'=>'Audit table', 'body'=>'Each row is one shift: selfies, site, clock-in/out, breaks, and duration. Open the selfie/GPS evidence, fix breaks with the row tools, or duplicate a shift if a day was split incorrectly — then tick it for finalize.'],
            ['sel'=>'#masterCheck', 'title'=>'Select shifts', 'body'=>'Tick individual rows, or use this header checkbox to select every shift currently listed. Only ticked rows go into the Bulk Pay Allocation Desk.'],
            ['sel'=>'button[onclick^="bulkFinalize"], button.btn-green', 'title'=>'Finalize Selected', 'body'=>'Opens the Bulk Pay Allocation Desk for the ticked shifts. If nothing is ticked, select rows first. This does not pay money yet — it prepares the OT/policy step.'],
            ['sel'=>'#otModeSelector', 'title'=>'Overtime Policy dropdown', 'body'=>'Choose how regular vs OT is split before money is calculated. Work-site policy uses the site\'s province (ON: weekly over 44 h; SK: daily over 8 h plus weekly over 40 h, no double count). Or force weekly 44/40, daily 8 + weekly caps, or a per-shift daily cap. OT is 1.5×. Statutory defaults — not legal advice.'],
            ['sel'=>'#otModeExplainer', 'title'=>'Policy explainer', 'body'=>'Read this line after changing the dropdown — it restates what the selected policy will do to the selected shifts before you execute.'],
            ['sel'=>'#formulaDropdownSelector, #dailyCapInputWrapper', 'title'=>'Preview regular / OT', 'body'=>'Use formula/cap options when shown so the modal can preview how hours will split. Confirm regular vs OT looks right for the week before you commit — after Execute, amounts are locked until Send Back.'],
            ['sel'=>'button[value="execute_bulk_pay_matrix"], button.modal-btn-save', 'title'=>'Execute Pay Matrix Process', 'body'=>'Runs the payroll engine per line: CPP, EI, income tax, employer match, WCB, and province-aware vacation. Writes locked rows to payroll_finalized. Then open Payroll Center to pay, print stubs, or export.'],
            ['sel'=>null, 'title'=>'Where rows go next', 'body'=>'After a successful Execute, these shifts leave Pending and show under Payroll Center for the same org/dates. To undo, use Send Back on Payroll Center — that returns the shift here for re-audit. Start Tour anytime; Ask AI if a policy choice is unclear.'],
        ],
    ],

    'generate_stat_shifts.php' => [
        'title'   => 'Statutory Holiday Pay — Preview & Approve',
        'purpose' => 'Calculates statutory holiday pay per eligible employee using ON or SK formulas, shows the lookback base, and posts approved amounts as finalized pay lines. Never fabricates attendance shifts and never posts the same holiday twice without your click.',
        'how'     => [
            'Pay period (recommended): set Period start/end (and optional site), press Preview all holidays in period. Everyone starts ticked. Untick a person to exclude them, then Approve & post ticked only.',
            'Or single holiday: pick the holiday date (known holidays auto-fill the name) and optionally one work site, then press Preview this holiday.',
            'Review each row: lookback window, formula base (regular wages / vacation pay), computed stat pay and equivalent hours.',
            'Rows flagged "Worked holiday" need a separate premium-pay review; already-posted rows show Posted and cannot be selected again.',
            'For one holiday only: tick the rows to pay and press Approve & post selected. Nothing posts until that click; there is no auto-post on Preview.',
        ],
        'calc'    => [
            'Ontario: (regular wages + vacation pay earned in the 4 full work weeks before the holiday\'s week) ÷ 20.',
            'Saskatchewan: 5% of wages (excluding overtime premium) earned in the 28 days before the holiday.',
            'Deductions on the stat amount come from the same payroll engine as regular pay; province comes from the work site.',
            'Duplicate protection: each (employee, site, holiday) is recorded in statutory_pay_items — re-posting is blocked.',
            'Period batch posts every calendar holiday in from→to for every eligible employee×site that still has no statutory_pay_items row.',
            $disclaimer,
        ],
        'notes'   => [
            'Only ON and SK sites in your organization are evaluated. Equivalent hours are display-only (amount ÷ hourly rate).',
            'Posted lines appear in Payroll Center like other finalized pay.',
            'Eligible means recent finalized earnings in the lookback with a computed amount > $0 — not every roster name.',
        ],
        'tour'    => [
            ['sel'=>null, 'title'=>'Stat Pay — preview then approve', 'body'=>'Statutory holiday pay is preview-first. Use a pay period to preview every holiday at once, or work one holiday. Approve is a separate deliberate click — nothing auto-posts.'],
            ['sel'=>'#pgHelpPanel', 'title'=>'Help & Ask AI', 'body'=>'About this page documents the ON and SK formulas. Ask AI if you need which formula applies — answers include the statutory-defaults disclaimer.'],
            ['sel'=>'#statPeriodForm', 'title'=>'Pay period batch', 'body'=>'Set Period start and end for your pay run. Optionally limit to one site. This is the fastest path when a period contains one or more holidays.'],
            ['sel'=>'#statPreviewPeriodBtn', 'title'=>'Preview all holidays in period', 'body'=>'Lists every ON/SK holiday in the window and every eligible employee with lookback base and computed amount. Review before posting.'],
            ['sel'=>'#statPeriodPreview', 'title'=>'Period results', 'body'=>'Grouped by holiday. Ready = will post. Posted = already done (skipped). Worked holiday = still posts public-holiday pay; premium for hours worked that day is reviewed separately.'],
            ['sel'=>'#statPostPeriodBtn', 'title'=>'Approve & post ALL eligible', 'body'=>'One confirm posts every Ready line for every holiday in the period. Duplicates and $0 rows stay skipped. Then open Payroll Center.'],
            ['sel'=>'input[name="holiday_date"]', 'title'=>'Single holiday (optional)', 'body'=>'Use when you only want one holiday. Pick date/name, Preview this holiday, tick rows, Approve & post selected.'],
            ['sel'=>null, 'title'=>'Formulas at a glance', 'body'=>'ON: (4 weeks regular wages + vacation pay) ÷ 20. SK: 5% of wages (ex-OT premium) in the prior 28 days. Statutory defaults — not legal advice.'],
        ],
    ],

    'generate_t4.php' => [
        'title'   => 'Tax & T4 Center',
        'purpose' => 'Annual T4 worksheets per employee, summed from stored finalized payroll — Box 14 employment income, Box 22 income tax, Box 16 CPP, Box 18 EI. Worksheet aid only; confirm CRA rules before filing.',
        'how'     => [
            'Pick the tax year and optionally one site (when sites are separate legal employers), then press Load.',
            'Review Box 14 / 22 / 16 / 18 per employee. Open T4 for one printable worksheet; Print annual batch for everyone listed.',
            'Confirm CRA box eligibility, annual maximums, and the legal employer account before you file — this screen does not submit to CRA.',
        ],
        'calc'    => [
            'Boxes are YTD sums over payroll_finalized for the chosen year: Box 14 = total gross, Box 22 = income tax withheld, Box 16 = employee CPP, Box 18 = employee EI.',
            'Employer name/address come from the work site (each site can be treated as its own legal employer); province of employment comes from the site.',
            $disclaimer,
        ],
        'notes'   => [
            'Worksheet only — not an efile submission.',
            'Scoped to your organization.',
        ],
        'tour'    => [
            ['sel'=>null, 'title'=>'Tax & T4 Center', 'body'=>'Year-end T4 worksheets built only from stored finalized payroll — never from estimated attendance hours. Use this after the calendar year\'s pay is complete and reviewed in Payroll Center.'],
            ['sel'=>'#pgHelpPanel', 'title'=>'Help & Ask AI', 'body'=>'About this page explains each box briefly. Ask AI for “what is Box 14” style questions — answers stay grounded in this map and note that filing rules need your accountant/CRA.'],
            ['sel'=>'input[name="year"]', 'title'=>'Tax year', 'body'=>'Choose the calendar year to sum. All finalized lines whose work date falls in this year are included for your org (and optional site filter).'],
            ['sel'=>'select[name="site_id"]', 'title'=>'Site filter', 'body'=>'Optionally limit to one site when sites are separate legal employers. Otherwise leave All to see every employee in the org for that year.'],
            ['sel'=>'button[type="submit"], form button.btn-primary', 'title'=>'Load', 'body'=>'Refresh the employee totals for the year/site you selected. Always Load after changing year before printing.'],
            ['sel'=>'table.table', 'title'=>'Employee totals & box meanings', 'body'=>'Box 14 = employment income (gross). Box 22 = income tax deducted. Box 16 = CPP contributions (employee). Box 18 = EI premiums (employee). Open T4 prints one employee\'s worksheet.'],
            ['sel'=>'a[href*="batch=1"]', 'title'=>'Print annual batch', 'body'=>'Prints a T4 worksheet for every listed employee in one print job. Review on-screen totals first; this does not transmit slips to CRA.'],
            ['sel'=>null, 'title'=>'Before filing', 'body'=>'Confirm CRA box eligibility, CPP/EI maximums, adjustments, and the correct RP account. This app prepares worksheets from stored pay — your accountant files. Statutory defaults — not legal advice.'],
        ],
    ],

    'payroll_cra_payments.php' => [
        'title'   => 'CRA Source Deductions Remittance Register',
        'purpose' => 'Tracks what you owe CRA for a period (employee tax + CPP + EI plus employer CPP/EI matching) against the lump-sum remittances you actually sent, and logs each payment for audit.',
        'how'     => [
            'Set the payroll period range and press Bring Payroll Tax Balances to refresh liabilities, paid, and outstanding.',
            'Read the three summary cards: liabilities generated, remittances paid, and outstanding balance for the window.',
            'To log a payment: bank settlement date, amount (pre-filled with outstanding), channel, bank reference, memo — then Submit Government Settlement.',
            'The history grid lists every logged remittance in the window. Logging records money you already sent; it does not move money from a bank.',
        ],
        'calc'    => [
            'Period Tax Liabilities = sum of CRA deductions on payroll masters in the range (employee income tax + CPP + EI, plus employer CPP match and EI × 1.4).',
            'Logged payments settle unpaid periods oldest-first; a period is marked Paid once the pool covers its full amount.',
            'WCB and vacation are employer costs and are NOT part of the CRA balance.',
            $disclaimer,
        ],
        'notes'   => [
            'Scoped to your organization.',
            'Pair with Payroll Center → CRA Detail for a drill-down of the same window.',
        ],
        'tour'    => [
            ['sel'=>null, 'title'=>'CRA Remittance Register', 'body'=>'Use this to match CRA source-deduction liabilities to the remittances you sent to CRA. It does not withdraw money — it logs settlements you already made at the bank or via My Payment.'],
            ['sel'=>'#pgHelpPanel', 'title'=>'Help & Ask AI', 'body'=>'About this page explains what is in the CRA balance. Ask AI if you are unsure why WCB is missing — it is intentionally excluded.'],
            ['sel'=>'input[name="from_date"]', 'title'=>'Period From', 'body'=>'Start of the payroll period window you are remitting for. Align with your CRA remitter frequency (monthly, quarterly, etc.).'],
            ['sel'=>'input[name="to_date"]', 'title'=>'Period To', 'body'=>'End of the window. Then press Bring Payroll Tax Balances — cards and history refresh for this range only.'],
            ['sel'=>'button[name="bring_balances"], button[type="submit"].btn-primary, form button[type="submit"]', 'title'=>'Bring Payroll Tax Balances', 'body'=>'Recalculates liabilities generated, remittances already logged, and outstanding for the range. Always refresh after changing dates before recording a payment.'],
            ['sel'=>'.summary-card, .summary-cards, .card', 'title'=>'Liabilities / Paid / Outstanding', 'body'=>'Liabilities = EE tax+CPP+EI + ER CPP+EI created by payroll in the window. Paid = remittances you logged. Outstanding = still to remit. WCB and vacation never appear here.'],
            ['sel'=>'input[name="amount_paid"]', 'title'=>'Record a remittance', 'body'=>'Amount is pre-filled with outstanding. Enter the bank settlement date, payment channel, and bank/trace reference so your audit trail matches CRA statements.'],
            ['sel'=>'button[name="log_cra_payment"]', 'title'=>'Submit Government Settlement', 'body'=>'Logs the payment and applies it to unpaid periods oldest-first. Periods flip to Paid when fully covered. Re-check history below after submit.'],
            ['sel'=>'.table-cra, table.table', 'title'=>'Remittance history', 'body'=>'Every remittance logged in the window: date, method, reference, memo, amount. Use this when reconciling to CRA My Business Account.'],
        ],
    ],

    'bulk_importer.php' => [
        'title'   => 'Bulk CSV Import — Employees & Hours',
        'purpose' => 'Imports new employees, then historical hours, from CSV templates. Validation is all-or-nothing: if any row fails, nothing is imported. Employees must exist before hours can match.',
        'how'     => [
            'Download the Employee and Hours templates plus the Field Guide; replace example rows; save as UTF-8 CSV.',
            'Import Employees FIRST: creates login, PIN, org/site assignment, pay rates, and writes StartDate into the vacation service record.',
            'Keep Validate only (dry run) ticked on the first pass — preview errors without saving. Untick and re-run to commit.',
            'Then import Hours: creates attendance rows (synthetic clock-in/out) matched by Email first, then exact Name, within your OrgID.',
            'After hours import, open Staff Audit, review punches, and Finalize — import alone does not run the payroll engine.',
        ],
        'calc'    => [
            'Employee StartDate drives vacation entitlement: ON 4%/6% at 5 y; SK 3/52 / 4/52 at 10 y — by work-site province as of each pay line\'s work date.',
            'Hours rows never create employees; matching is Email → exact Name, always within your OrgID.',
            $disclaimer,
        ],
        'notes'   => [
            'Import order matters: employees first, hours second.',
            'An explicit vacation override % can still be set per employee/site in Vacation Service.',
        ],
        'tour'    => [
            ['sel'=>null, 'title'=>'Bulk CSV Import', 'body'=>'Two imports, always in order: (1) Employees, (2) Hours. Every file is validated first — one bad row cancels the whole import. Hours never create people; they only match existing employees in your org.'],
            ['sel'=>'#pgHelpPanel', 'title'=>'Help & Ask AI', 'body'=>'About this page covers StartDate and matching rules. Ask AI if a dry-run error message is unclear.'],
            ['sel'=>'.template-box', 'title'=>'Download templates', 'body'=>'Get the Employee CSV, Hours CSV, and Field Guide. Delete fictional example rows and fill real data. Save as CSV UTF-8 before uploading.'],
            ['sel'=>'.vac-note', 'title'=>'StartDate (not a vacation %)', 'body'=>'The employee template asks for StartDate. Vacation % is derived later from tenure + site province (or an Override on Vacation Service). Putting a % in the wrong column will not set entitlement correctly.'],
            ['sel'=>'input[name="import_mode"]', 'title'=>'Import type: Employees first', 'body'=>'Choose Employees to create logins, site assignments, rates, and vacation service StartDate. Only after that succeeds should you switch to Hours.'],
            ['sel'=>'input[name="import_mode"][value="hours"], select[name="import_mode"]', 'title'=>'Then Hours', 'body'=>'Hours creates attendance punches for Staff Audit. Matching: Email first, then exact Name, always inside your OrgID. Unmatched rows fail validation — fix the employee list and re-run.'],
            ['sel'=>'.upload-area, input[type="file"]', 'title'=>'Upload CSV', 'body'=>'Choose your filled file. Large files are fine; validation still runs on every row before any write.'],
            ['sel'=>'input[name="dry_run"]', 'title'=>'Validate only (dry run)', 'body'=>'Leave ticked for a safe preview of creates/updates/errors with zero database writes. When the report is clean, untick dry run and process again to commit.'],
            ['sel'=>'button[name="process_import"]', 'title'=>'Run import', 'body'=>'All-or-nothing: any validation failure saves nothing. After a successful Hours import, go to Staff Audit → Finalize Selected — that is when CPP/EI/tax/vacation are calculated.'],
            ['sel'=>null, 'title'=>'Next stop: Staff Audit', 'body'=>'Imported hours are attendance only. Payroll Center will stay empty for those rows until you finalize. Start Tour again if you need the order reminder.'],
        ],
    ],

    'vacation_service_admin.php' => [
        'title'   => 'Vacation Service Dates (per site)',
        'purpose' => 'Sets each employee\'s hire/start date per work site — the input that drives provincial statutory vacation defaults — with optional explicit override rates above the minimum.',
        'how'     => [
            'Auto-capture fills every missing start date from the employee\'s first shift at each site (never overwrites manually-set dates).',
            'Add/update a single record: employee + site + start date; leave Override % at 0 to use the statutory default.',
            'Bulk set: tick rows, pick a date, Apply to checked (this DOES overwrite, including manual dates).',
            'Orange rows still need a date — until set, finalize falls back to the province base rate.',
        ],
        'calc'    => [
            'Current rate: ON 4% under 5 years, 6% at 5+; SK 3/52 (~5.77%) under 10 years, 4/52 (~7.69%) at 10+ — by the SITE\'s province, evaluated per pay line\'s work date.',
            'Override % > 0 always wins over the statutory default (use only for contracts above the minimum).',
            'Each site is treated as a separate legal employer, so service is tracked per employee-site pair.',
            $disclaimer,
        ],
        'notes'   => [
            'Employee CSV import writes StartDate into these records automatically (source IMPORT).',
            'Changing a start date affects future finalize calculations; it does not rewrite already-finalized lines in Payroll Center.',
        ],
        'tour'    => [
            ['sel'=>null, 'title'=>'Vacation Service Dates', 'body'=>'Start dates per employee per site drive the vacation % applied at finalize. Wrong or missing dates mean the wrong statutory rate. Overrides force a contract rate above the minimum.'],
            ['sel'=>'#pgHelpPanel', 'title'=>'Help & Ask AI', 'body'=>'About this page lists ON/SK brackets. Ask AI for “why is this person 6%” — answers use start date + province + override rules.'],
            ['sel'=>'.btn-cap', 'title'=>'Auto-capture', 'body'=>'Fills every missing start date from each employee\'s first shift at that site. Safe anytime — dates you set manually are never overwritten by Auto-capture.'],
            ['sel'=>'input[name="start_date"], input[name="service_start"]', 'title'=>'Start date', 'body'=>'Hire/service start for that employee at that site. Tenure for the statutory bracket is measured from this date as of each pay line\'s work date.'],
            ['sel'=>'input[name="vac_percent"]', 'title'=>'Override %', 'body'=>'Leave 0 to use provincial statutory default. Enter a percent > 0 only when the employment contract pays more than the minimum — override always wins.'],
            ['sel'=>'.bulk-bar', 'title'=>'Bulk set', 'body'=>'Tick rows in the table, pick a date, Apply to checked. Caution: bulk DOES overwrite manual dates — unlike Auto-capture.'],
            ['sel'=>'#svcTable', 'title'=>'Service status table', 'body'=>'Every employee/site pair that has worked: start date, source (AUTO / IMPORT / MANUAL), years of service, current rate, and whether a date is still needed (orange). Click headers to sort.'],
            ['sel'=>null, 'title'=>'Province reminder', 'body'=>'Ontario: 4% then 6% at 5 years. Saskatchewan: 3/52 then 4/52 at 10 years. Statutory defaults — not legal advice. After fixing dates, new finalizes pick up the rate; old Payroll Center lines stay as stored.'],
        ],
    ],

    'qb_export_setup.php' => [
        'title'   => 'QuickBooks Export Setup',
        'purpose' => 'One-time mapping between this app\'s payroll components and your QuickBooks chart of accounts, so Payroll Center can export balanced journal entries (QuickBooks Online CSV or Desktop IIF).',
        'how'     => [
            'Optionally upload a sample export from your QuickBooks so the app learns your column layout (CSV) or confirms IIF.',
            'Map each payroll component (gross wages, net pay/bank, CPP/EI/tax payable, employer tax expense, WCB, vacation) to your account names. Leave optional pairs blank to exclude them.',
            'Save the profile, then run QuickBooks Accounting Journal from Payroll Center on the loaded matrix.',
            'Also available from Payroll Center without this page: QuickBooks Hours CSV and ADP Hours CSV (hours files, not journals).',
        ],
        'calc'    => [
            'Every exported entry balances: Dr gross wages (+ employer CPP/EI, WCB, vacation if mapped) = Cr CPP/EI/tax payable, WCB/vacation payable, and net pay. Omitting an optional account drops a balanced pair.',
            'Exports use stored finalized values grouped per employee per pay period.',
        ],
        'notes'   => [
            'Scoped to your organization.',
            'Re-save mapping if you rename accounts in QuickBooks.',
        ],
        'tour'    => [
            ['sel'=>null, 'title'=>'QuickBooks Export Setup', 'body'=>'Map payroll components to your QuickBooks accounts once. After that, Payroll Center → QuickBooks Accounting Journal exports balanced entries in QBO CSV or Desktop IIF. Hours CSV exports do not need this mapping.'],
            ['sel'=>'#pgHelpPanel', 'title'=>'Help & Ask AI', 'body'=>'About this page lists required vs optional accounts. Ask AI which export button to use for time vs journals.'],
            ['sel'=>'input[type="file"]', 'title'=>'Upload sample template', 'body'=>'Upload a sample journal or export from your QuickBooks. The app detects QBO CSV columns or IIF so your downloads match what QuickBooks expects to import.'],
            ['sel'=>'select[name="format_type"], input[name="format_type"]', 'title'=>'Format', 'body'=>'Choose QuickBooks Online CSV vs Desktop IIF if prompted. Match the product you actually use — wrong format is the most common import failure.'],
            ['sel'=>'form', 'title'=>'Account mapping', 'body'=>'Type your exact QuickBooks account names for wages expense, net payroll clearing/bank, tax/CPP/EI payable, and optional employer-side WCB/vacation. Blank optional fields are omitted as balanced pairs — journals still balance.'],
            ['sel'=>'button[type="submit"], button[name="save_profile"]', 'title'=>'Save profile', 'body'=>'Stores the mapping for your organization only. Then open Payroll Center, Update Matrix for the period, and click QuickBooks Accounting Journal.'],
            ['sel'=>null, 'title'=>'Preview & export next', 'body'=>'There is no money movement here — only mapping. Preview happens when you export from Payroll Center. Re-open this page anytime to adjust accounts. Start Tour to replay.'],
        ],
    ],

    'kiosk.php' => [
        'title'   => 'Clock-In Kiosk — Punch Terminal',
        'purpose' => 'Staff time clock: choose a work site, capture a selfie + GPS, then Clock In / Out, Transfer, or Break. Punches become attendance rows that supervisors audit in Staff Audit and finalize into Payroll Center.',
        'how'     => [
            'Sign in first at Login (login_kiosk.php) with username or PIN + password — the kiosk itself does not show a PIN pad; your session identity comes from that login.',
            'Select the work site from the dropdown (required when off-clock). Changing site reloads the page with that site_id.',
            'Allow camera and location when the browser asks — punches need a selfie frame and lat/lng.',
            'Off clock: press Clock In. Working: Clock Out, Transfer Out, or Break. On break: Resume Work. In transit: pick the new site and Arrive (Transfer In).',
            'If the site requires a policy override reason (geofence/schedule variance), fill the orange reason box before punching.',
            'Common blocks: geofence_blocked (too far from site), schedule_blocked (too early/late vs schedule). Fix location or wait for the scheduled window, or use Manual Attendance (supervisor) if policy allows.',
        ],
        'calc'    => [
            'Open attendance is detected from the latest row with an empty clock_out. Break mode looks for an open breaks row. Transfer/transit uses status codes on the attendance record.',
            'Site timezone (sites.Timezone) drives the kiosk clock display; punches store GPS and image for Staff Audit evidence.',
        ],
        'notes'   => [
            'Kiosk punches are attendance only — pay is calculated later at Staff Audit → Finalize.',
            'Scoped to sites your login can access (org + assigned sites).',
        ],
        'tour'    => [
            ['sel'=>null, 'title'=>'Clock-In Kiosk', 'body'=>'This is the staff punch terminal. You must already be logged in (username or PIN via Login). Here you pick a site, allow camera/GPS, and Clock In or Out. Punches flow to Staff Audit — not straight into pay.'],
            ['sel'=>'#pgHelpPanel', 'title'=>'Help, Tour & Ask AI', 'body'=>'About this page explains modes and error banners. Start Tour speaks each step (Mute if needed). Ask AI for geofence or schedule lock questions.'],
            ['sel'=>'#site_id', 'title'=>'Select work site', 'body'=>'Choose where you are working. Required when you are off the clock. While Working or on Break the site is locked to the open shift. In Transit, pick the destination site before Arrive.'],
            ['sel'=>'#video', 'title'=>'Camera selfie', 'body'=>'The live camera preview is captured as evidence on each punch. Allow camera permission; without it the punch cannot complete.'],
            ['sel'=>'#punchForm', 'title'=>'Punch actions', 'body'=>'Buttons change with your mode: Clock In (off clock), Clock Out / Transfer Out / Break (working), Resume Work (break), Arrive / Transfer In (transit). Each action posts selfie + GPS to process_punch.'],
            ['sel'=>'#flag_reason', 'title'=>'Override reason (when shown)', 'body'=>'If geofence or schedule policy flags a variance, type why you are punching anyway. The field is required before the punch will submit.'],
            ['sel'=>'.alert-danger, .sandbox-indicator', 'title'=>'Common errors', 'body'=>'Geofence blocked = device too far from the site radius. Schedule blocked = punch outside the scheduled window. Move closer, wait for the shift window, or ask a supervisor to use Manual Attendance if appropriate.'],
            ['sel'=>null, 'title'=>'After you punch', 'body'=>'Your open shift appears for supervisors under Clock In → Staff Audit / Clockin view. They finalize into Payroll Center. Replay this tour anytime with Start Tour.'],
        ],
    ],

    'scheduler_view.php' => [
        'title'   => 'Scheduler Pro — Weekly Shift Board',
        'purpose' => 'Plan who works when at each site: drag staff onto day columns, edit role/times, copy or clear weeks, print or email schedules. Scheduled shifts feed schedule enforcement on the kiosk and give supervisors a plan to compare against attendance.',
        'how'     => [
            'Pick the site in the SCHEDULER PRO header dropdown (rid). Use the week chevrons to move weeks.',
            'Drag a person from Available Staff onto a day column to create a shift; drag existing shift cards to move them.',
            'Click a shift card to Edit (role, start, end) and Save Changes — writes via save_shift.php.',
            'COPY WEEK duplicates last week; CLEAR WEEK deletes this week\'s shifts (confirm first). PRINT / EMAIL ALL distribute the plan.',
            'Scheduled times can block early kiosk punches when schedule enforcement is on. Actual hours still come from punches → Staff Audit → Payroll.',
        ],
        'calc'    => [
            'Week cost estimates use shift duration × hourly rate × burden — planning only, not finalized payroll.',
            'External-site shifts may show as background context so you do not double-book people across restaurants.',
        ],
        'notes'   => [
            'Org/site scoped. Saving the schedule does not create payroll lines by itself.',
        ],
        'tour'    => [
            ['sel'=>null, 'title'=>'Scheduler Pro', 'body'=>'Build the weekly roster for one site at a time. Drag staff onto days, edit times, then print or email. The schedule guides who should punch; pay still comes from audited attendance.'],
            ['sel'=>'#pgHelpPanel', 'title'=>'Help & voice tour', 'body'=>'Start Tour walks site → staff → canvas → edit → week tools. Mute on the tip if you do not want narration.'],
            ['sel'=>'#activeSiteHeaderDropdownId', 'title'=>'Site selector', 'body'=>'Switch the active restaurant/site. The board reloads for that rid — schedules are per site, not one global grid.'],
            ['sel'=>'.nav-header', 'title'=>'Week tools', 'body'=>'COPY WEEK pulls last week\'s shifts forward. CLEAR WEEK wipes the current week after confirm. PRINT SCHEDULE and EMAIL ALL share the plan with the team.'],
            ['sel'=>'.staff-tag, #sidebar-staff-1, h4', 'title'=>'Available Staff', 'body'=>'People you can schedule. Drag a staff tag onto a day column to create a shift card for that date.'],
            ['sel'=>'#masterActiveSchedulerCanvasArea', 'title'=>'Week canvas', 'body'=>'One column per day. Drop staff here or drag existing shift cards between days. Click a card to open Edit Shift (role + start/end).'],
            ['sel'=>'#editModal', 'title'=>'Edit & save a shift', 'body'=>'Set role, start time, and end time, then Save Changes. Deletes and copies use the card tools or week actions — confirm destructive clears.'],
            ['sel'=>null, 'title'=>'How this ties to payroll', 'body'=>'Schedule = plan. Kiosk punches = actual. Staff Audit compares and finalizes actuals into Payroll Center. Start Tour anytime to replay.'],
        ],
    ],

    'manual_attendance.php' => [
        'title'   => 'Manual Attendance — Inject a Shift',
        'purpose' => 'Supervisors create an attendance row by hand (clock in/out, breaks, site, notes) when a punch was missed or the kiosk could not be used. Rows still need Staff Audit finalize for pay.',
        'how'     => [
            'Choose employee (target_user_id) and site (target_site_id).',
            'Enter clock in and clock out; add break intervals if needed.',
            'Add manager notes explaining why the shift was manual; save/submit.',
            'Open Staff Audit to review evidence/notes and Finalize into payroll.',
        ],
        'calc'    => ['Creates attendance like a kiosk punch — payroll engine runs only at finalize.',],
        'notes'   => ['Org-scoped. Prefer kiosk punches when possible for selfie/GPS evidence.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Manual Attendance', 'body'=>'Use when someone forgot to punch or the kiosk blocked them. You inject clock-in/out times; pay still requires Staff Audit → Finalize.'],
            ['sel'=>'select[name="target_user_id"], [name="target_user_id"]', 'title'=>'Employee', 'body'=>'Pick who worked. Only people in your organization appear.'],
            ['sel'=>'[name="target_site_id"]', 'title'=>'Site', 'body'=>'Work site for the shift — drives province rules later at finalize.'],
            ['sel'=>'#masterShiftClockIn, [name="clock_in"]', 'title'=>'Clock in / out', 'body'=>'Enter the real start and end times. Add breaks in the breaks section if unpaid breaks apply.'],
            ['sel'=>'[name="manager_notes"]', 'title'=>'Manager notes', 'body'=>'Document why this was manual (missed punch, geofence issue, etc.) for the audit trail.'],
            ['sel'=>null, 'title'=>'Next: Staff Audit', 'body'=>'After save, find the row under Pending in Staff Audit, then Finalize. Start Tour to replay.'],
        ],
    ],

    'timeoff_requests.php' => [
        'title'   => 'Time Off Management',
        'purpose' => 'Supervisors review and act on employee time-off requests for the org/site.',
        'how'     => ['Filter by org/site if shown.', 'Approve or deny pending requests.', 'Approved time off informs scheduling capacity; it does not auto-create payroll lines.',],
        'calc'    => ['Request dates are stored as time-off records — not attendance punches.',],
        'notes'   => ['Employees submit via Submit Timeoff. Org-scoped.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Time Off Management', 'body'=>'Approve or deny staff requests here. This is not a punch screen — it blocks scheduling capacity when approved.'],
            ['sel'=>'[name="site_id"]', 'title'=>'Site filter', 'body'=>'Limit the list to one site when managing multiple locations.'],
            ['sel'=>'table, .table', 'title'=>'Request list', 'body'=>'Review each request\'s dates and reason, then approve or deny per your process.'],
            ['sel'=>null, 'title'=>'Related pages', 'body'=>'Staff use Submit Timeoff. Schedulers should avoid booking approved days. Start Tour anytime.'],
        ],
    ],

    'submit_timeoff.php' => [
        'title'   => 'Request Time Off',
        'purpose' => 'Employees request days off (site, dates, reason). Supervisors decide on Time Off Management.',
        'how'     => ['Select site.', 'Set start and end dates.', 'Enter a reason and submit.', 'Wait for supervisor approval on Time Off Management.',],
        'calc'    => ['Creates a request record only — no pay impact until other processes apply.',],
        'notes'   => ['Use your normal login. Org/site scoped.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Request Time Off', 'body'=>'Ask for days away from work. Your supervisor approves on Time Off Management — this form alone does not change the schedule.'],
            ['sel'=>'#site_id', 'title'=>'Site', 'body'=>'Which location the request applies to.'],
            ['sel'=>'#start_date', 'title'=>'Start date', 'body'=>'First day you need off.'],
            ['sel'=>'#end_date', 'title'=>'End date', 'body'=>'Last day off (inclusive).'],
            ['sel'=>'#reason', 'title'=>'Reason', 'body'=>'Short explanation for your supervisor.'],
            ['sel'=>'button[type="submit"], form button', 'title'=>'Submit', 'body'=>'Send the request. Check with your supervisor if you do not see a decision.'],
        ],
    ],

    'employee_hub.php' => [
        'title'   => 'Employee Hub',
        'purpose' => 'Create and maintain employees: login, PIN, role, pay rate, org/site allocation, active flag. Foundation for kiosk login, scheduling, and payroll.',
        'how'     => [
            'Search or filter the hub table; open a row to edit.',
            'Set user, PIN, role, hourly rate, rate effective date, primary/allocated sites, and active status.',
            'Dated wage changes (effective date + recorded timestamp) also live on Rate Change.',
            'Use bulk tools when updating many people at once.',
            'New hires also need Vacation Service start dates (or CSV Import) before finalize uses the right vacation %.',
        ],
        'calc'    => ['PIN/username are used at login_kiosk. Rates feed finalize gross. Sites drive province OT/vacation rules.',],
        'notes'   => ['Org-scoped. Wrong site assignment is a common reason people “vanish” from a site schedule.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Employee Hub', 'body'=>'Your people directory: logins, PINs, rates, and site assignments. Get this right before scheduling or importing hours.'],
            ['sel'=>'#hubSearch, #orgFilterSelect', 'title'=>'Search & org filter', 'body'=>'Find someone quickly. Org filter (when shown) scopes which company\'s roster you are editing — switching org changes who appears.'],
            ['sel'=>'#hubTable', 'title'=>'Employee table', 'body'=>'Click a row to edit name, login, PIN, role, rate, and site allocation. Inactive users cannot punch.'],
            ['sel'=>'#f_pin, #f_rate', 'title'=>'PIN & rate', 'body'=>'PIN (with password) unlocks the kiosk login. Hourly rate is used when shifts are finalized into pay.'],
            ['sel'=>'#f_allocated, #bulkBar', 'title'=>'Sites & bulk', 'body'=>'Allocated sites control where someone can be scheduled and which sites they see. Bulk bar updates many selected users at once.'],
            ['sel'=>null, 'title'=>'Next steps', 'body'=>'After adding people: set Vacation Service dates, schedule them, then they punch on the kiosk. Start Tour to replay.'],
        ],
    ],


    'rate_change.php' => [
        'title'   => 'Rate Change',
        'purpose' => 'Admin template to record a new hourly / official / transit rate with an effective date and an automatic recorded timestamp. Finalize uses the rate in effect on the shift work date.',
        'how'     => [
            'Open Employees → Rate Change (or the link on Employee Hub).',
            'One employee: pick the person, enter the new hourly rate, set Effective date, optional previous rate if the profile already shows the new number, then Save.',
            'Many employees: filter by site or current hourly, tick names, enter the new rate and effective date, Apply to selected.',
            'SK min wage 1 Oct 2026 example: previous $15.35 effective 2025-10-01, new $15.70 effective 2026-10-01. September shifts stay $15.35; Oct 1 onward $15.70.',
        ],
        'calc'    => [
            'Recorded timestamp = when you click Save (cannot be typed).',
            'Effective date = first work day the new rate applies. A shift on the day before still uses the previous rate.',
            'If effective date is today or earlier, the Employee Hub current rate is updated too.',
        ],
        'notes'   => [
            'Already-finalized shifts keep the rate locked on the pay line. Only new finalize uses the dated history.',
            'Admins (role 25 and above privilege) only.',
        ],
        'tour'    => [
            ['sel'=>null, 'title'=>'Rate Change', 'body'=>'Use this page when a wage changes on a known date — for example Saskatchewan minimum wage on 1 October. Each save stores the rate, the effective date, and the time you saved it.'],
            ['sel'=>'select[name="user_id"], input[name="hourly_rate"]', 'title'=>'Employee & new rate', 'body'=>'Pick the person and type the new hourly rate. Official and transit can stay blank to keep the current values.'],
            ['sel'=>'input[name="effective_date"]', 'title'=>'Effective date', 'body'=>'First work day the new rate applies. A 30 September shift will not pick up a 1 October rate.'],
            ['sel'=>'input[name="previous_hourly"]', 'title'=>'Previous rate', 'body'=>'If the profile already shows the new rate, enter the old rate and its effective date so earlier shifts still calculate correctly.'],
            ['sel'=>'button.btn, button[type="submit"]', 'title'=>'Save', 'body'=>'Writes the history row and stamps recorded time automatically. Open Rate history below to confirm.'],
        ],
    ],

    'manage_sites.php' => [
        'title'   => 'Manage Sites',
        'purpose' => 'Create and edit work sites (nickname, address, country/province, timezone, org). Province and timezone drive OT, vacation, and kiosk clock display.',
        'how'     => ['Select organization.', 'Enter nick name, address, country/state, timezone.', 'Save to create or update.', 'Then set geofence radius in Site Accuracy & Geofence if used.',],
        'calc'    => ['Province on the site selects ON vs SK statutory defaults at finalize. Timezone affects kiosk local time.',],
        'notes'   => ['Sites belong to an org — switching org in filters shows different sites, not “missing” data.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Manage Sites', 'body'=>'Each location is a site under an organization. Province and timezone here quietly control vacation/OT rules and kiosk time.'],
            ['sel'=>'[name="org_id"]', 'title'=>'Organization', 'body'=>'Which company owns this site. If the list looks empty, check you are on the right org.'],
            ['sel'=>'[name="nick_name"]', 'title'=>'Site nickname', 'body'=>'Short name shown in scheduler, kiosk, and payroll filters.'],
            ['sel'=>'#countrySelect, [name="state"]', 'title'=>'Country / province', 'body'=>'Set the work province carefully — finalize uses it for OT and vacation statutory defaults (not legal advice).'],
            ['sel'=>'[name="timezone_id"]', 'title'=>'Timezone', 'body'=>'Kiosk and reports use this timezone for the site\'s local clock.'],
            ['sel'=>'[name="create_site"], button[type="submit"]', 'title'=>'Save site', 'body'=>'Creates/updates the site. Next: assign employees and optionally set geofence radius.'],
        ],
    ],

    'manage_orgs.php' => [
        'title'   => 'Organization Control Hub',
        'purpose' => 'Admin: create/update organizations (name, status, max users/sites). Org isolation means each company only sees its own employees, sites, and payroll.',
        'how'     => ['Add or edit org name and limits.', 'Set status active/inactive.', 'Switching org elsewhere in the app changes the entire data scope — data did not vanish.',],
        'calc'    => ['All payroll, attendance, and schedules are filtered by org_id.',],
        'notes'   => ['Super-admin tooling. Be careful changing org assignment of live sites.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Organizations', 'body'=>'Companies in the system. Everything (employees, sites, payroll) is org-scoped — if something “disappeared”, check which org you are viewing.'],
            ['sel'=>'[name="org_name"]', 'title'=>'Org name', 'body'=>'Display name for the company.'],
            ['sel'=>'[name="max_users"], [name="max_sites"]', 'title'=>'Limits', 'body'=>'Subscription-style caps on users and sites for this org.'],
            ['sel'=>'[name="save_org"], button[type="submit"]', 'title'=>'Save', 'body'=>'Writes the org record. Then manage sites and employees under that org.'],
        ],
    ],

    'payroll_report.php' => [
        'title'   => 'Payroll Report',
        'purpose' => 'Generate a dated payroll report for an org/site window — companion view to Payroll Center for printable/export-style review.',
        'how'     => ['Set start and end dates.', 'Optionally filter org/site.', 'Press generate/load.', 'Use Payroll Center for paying and documents.',],
        'calc'    => ['Reads finalized / payroll data for the range — confirm against Payroll Center if totals differ by filter.',],
        'notes'   => ['Org-scoped.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Payroll Report', 'body'=>'A dated report of pay activity. For day-to-day pay runs, prefer Payroll Center; use this for range reporting.'],
            ['sel'=>'[name="start_date"]', 'title'=>'Start date', 'body'=>'Beginning of the report window.'],
            ['sel'=>'[name="end_date"]', 'title'=>'End date', 'body'=>'End of the report window.'],
            ['sel'=>'[name="site_id"]', 'title'=>'Site filter', 'body'=>'Optional location limit.'],
            ['sel'=>'[name="generate"], button[type="submit"]', 'title'=>'Generate', 'body'=>'Build the report for the selected range.'],
        ],
    ],

    'payroll_summary.php' => [
        'title'   => 'Daily Payroll Summary',
        'purpose' => 'Summary of payroll activity between two dates — quick totals view.',
        'how'     => ['Set start/end dates and refresh.', 'Drill to Payroll Center for payment actions.',],
        'calc'    => ['Aggregates stored payroll figures for the window.',],
        'notes'   => ['Org-scoped.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Payroll Summary', 'body'=>'High-level totals for a date range. Pay and stubs still happen in Payroll Center.'],
            ['sel'=>'[name="start_date"]', 'title'=>'Start', 'body'=>'Range start.'],
            ['sel'=>'[name="end_date"]', 'title'=>'End', 'body'=>'Range end.'],
            ['sel'=>'button[type="submit"], form', 'title'=>'Apply', 'body'=>'Refresh the summary for those dates.'],
        ],
    ],

    'payroll_outstanding_remittance.php' => [
        'title'   => 'Outstanding Pay & CRA Remittance Detail',
        'purpose' => 'Drill into outstanding employee pay and CRA-related amounts for a window — opened from Payroll Center → CRA Detail.',
        'how'     => ['Set from/to dates, optional employee/site/scope.', 'Review outstanding nets and CRA components.', 'Log remittances on CRA Remittance Register; pay staff from Payroll Center.',],
        'calc'    => ['CRA pieces = tax + CPP + EI (EE+ER). WCB/vacation excluded from CRA.', $disclaimer,],
        'notes'   => ['Org-scoped.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Outstanding & CRA Detail', 'body'=>'See what is still owed to employees and what CRA source deductions look like for the window. Paying staff and logging CRA remittances are separate actions elsewhere.'],
            ['sel'=>'[name="from_date"]', 'title'=>'From date', 'body'=>'Start of the detail window.'],
            ['sel'=>'[name="to_date"]', 'title'=>'To date', 'body'=>'End of the window.'],
            ['sel'=>'[name="scope"], [name="user_id"], [name="site_id"]', 'title'=>'Filters', 'body'=>'Narrow by scope, employee, or site — same idea as Payroll Center filters.'],
            ['sel'=>null, 'title'=>'Next actions', 'body'=>'Pay staff in Payroll Center. Log CRA bank settlements in CRA Remittance. Start Tour to replay.'],
        ],
    ],

    'payroll_reports.php' => [
        'title'   => 'Consolidated Master Payroll Suite Ledger',
        'purpose' => 'Configurable payroll ledger/report (period mode, deductions, employer columns) for analysis across sites.',
        'how'     => ['Set from/to and period mode.', 'Toggle include deductions / employer columns.', 'Choose report type and site; run the report.',],
        'calc'    => ['Uses stored finalized values; toggles only change what columns are shown.',],
        'notes'   => ['Org-scoped. For paying, use Payroll Center.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Payroll Suite Ledger', 'body'=>'A flexible report over finalized pay. Turn columns on/off, then expand sites like Payroll Center.'],
            ['sel'=>'[name="from_date"], [name="to_date"]', 'title'=>'Date range', 'body'=>'Window for the ledger.'],
            ['sel'=>'[name="period_mode"], [name="report_type"]', 'title'=>'Period & type', 'body'=>'How rows band and which report flavor to load.'],
            ['sel'=>'[name="inc_deductions"], [name="inc_employer"]', 'title'=>'Column toggles', 'body'=>'Show or hide EE deductions and ER cost columns.'],
            ['sel'=>'[name="site_id"]', 'title'=>'Site', 'body'=>'Optional site filter before running.'],
        ],
    ],

    'balance_reports.php' => [
        'title'   => 'Balance Report',
        'purpose' => 'Report balances owed / settled over a date range by site.',
        'how'     => ['Choose from/to and site.', 'Run the report.', 'Settle remaining nets in Payroll Center.',],
        'calc'    => ['Compares paid vs net payable from stored payroll lines.',],
        'notes'   => ['Org-scoped.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Balance Report', 'body'=>'See who still has unpaid net balances in a range.'],
            ['sel'=>'[name="from_date"]', 'title'=>'From', 'body'=>'Start date.'],
            ['sel'=>'[name="to_date"]', 'title'=>'To', 'body'=>'End date.'],
            ['sel'=>'[name="site_id"]', 'title'=>'Site', 'body'=>'Optional location filter.'],
            ['sel'=>'button[type="submit"], form', 'title'=>'Run', 'body'=>'Load balances, then pay from Payroll Center if needed.'],
        ],
    ],

    'variance_report.php' => [
        'title'   => 'Labor Variance Dashboard',
        'purpose' => 'Compare scheduled vs actual labor (and related variance) for a site/employee/date window.',
        'how'     => ['Set dates, site, optional employee.', 'Review variance results.', 'Fix schedules or coach punches as needed.',],
        'calc'    => ['Variance = difference between scheduled plan and punched/finalized hours depending on report design.',],
        'notes'   => ['Org-scoped.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Labor Variance', 'body'=>'Find gaps between what you scheduled and what actually punched.'],
            ['sel'=>'[name="from_date"], [name="to_date"]', 'title'=>'Dates', 'body'=>'Variance window.'],
            ['sel'=>'[name="site_id"]', 'title'=>'Site', 'body'=>'Location to analyze.'],
            ['sel'=>'[name="employee_id"]', 'title'=>'Employee', 'body'=>'Optional single-person filter.'],
            ['sel'=>'button[type="submit"], form', 'title'=>'Run', 'body'=>'Load the dashboard for those filters.'],
        ],
    ],

    'manager_dashboard.php' => [
        'title'   => 'Executive Payroll Audit',
        'purpose' => 'Manager attendance/payroll audit overview (Reports → Attendance Audit). Review issues before finalize.',
        'how'     => ['Scan the audit list for exceptions.', 'Open detail rows as needed.', 'Send people to Staff Audit to correct and finalize.',],
        'calc'    => ['Surfaces attendance/payroll exceptions for management review.',],
        'notes'   => ['Org-scoped.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Executive Payroll Audit', 'body'=>'Manager view of attendance/payroll issues. Use it to spot problems, then fix in Staff Audit / Manual Attendance.'],
            ['sel'=>'table, .table', 'title'=>'Audit list', 'body'=>'Rows needing attention — expand details when available.'],
            ['sel'=>null, 'title'=>'Next', 'body'=>'Correct punches, then Finalize in Staff Audit so Payroll Center stays accurate.'],
        ],
    ],

    'daily_report_view.php' => [
        'title'   => 'Daily Activity Log',
        'purpose' => 'Daily sales and labor by site. Upload a POS (or production) file for any print date. Mark it Daily, Weekly, or Monthly — the software splits the figure across that period and labels the row.',
        'how'     => [
            'Pick organization, site, and date range. Press Filter.',
            'Click the POS/production file (or double-click Sales). A side panel opens with the image/PDF and a Sales box — do not open a new tab.',
            'Type Sales (and Paid out if needed) in the side panel or in the grid, then Save. The page reloads so MTD Sales and Labor % update.',
            'When you upload a week-end or month-end report: set Report = Weekly or Monthly, pick the print date, upload. Weekly = last 7 days ending on that date. Monthly = the 1st through that date.',
            'Weekly rows show a Weekly tag; monthly rows show Monthly. Daily figures are balanced/split so labor % is not stuck on one huge day.',
            'Ghost days (file on disk, no saved row) still have a typable Sales box — Save creates the row.',
        ],
        'calc'    => [
            'Labor % = labor $ ÷ sales $ for the day, and MTD uses saved sales in the month.',
            'Weekly upload splits/balances the POS (or production) total across the 7 days ending on the print date (week_split).',
            'Monthly upload splits/balances from the 1st through the print date (mtd_split).',
            'If a day already has a number, the upload adjusts so the period totals the report.',
        ],
        'notes'   => [
            'Upload weekly and monthly reports when you have them — daily labor % gets better once the period is balanced.',
            'Video + audio on this page walks the filters, side preview, and Weekly/Monthly upload.',
            'Operations menu. Org/site scoped.',
        ],
        'tour'    => [
            ['sel'=>null, 'title'=>'Daily Activity Log', 'body'=>'Sales and labor for the site. Click a POS file for the side preview. Weekly and Monthly uploads split the total across the period.'],
            ['sel'=>'[name="rid"], [name="start"], [name="end"]', 'title'=>'Filters', 'body'=>'Site and date range. Filter before you edit.'],
            ['sel'=>'#dal_period, [name="dal_period"], select[name="period"]', 'title'=>'Daily, Weekly, Monthly', 'body'=>'Daily = that date only. Weekly = 7 days ending on the date. Monthly = 1st through the date. The row is tagged Weekly or Monthly.'],
            ['sel'=>'#reportTable, #tableBody, table', 'title'=>'Sales grid', 'body'=>'Type Sales here or in the side panel. Save reloads MTD and Labor %. Days with a file but no row are still typable.'],
            ['sel'=>'#dalPosPreview, a.file-view', 'title'=>'Side preview', 'body'=>'POS image or PDF stays on the right. Type Sales and Save. Labor % updates after reload.'],
        ],
    ],


    'food_cost_view.php' => [
        'title'   => 'Food Cost — invoices',
        'purpose' => 'Upload supplier invoices and keep Amount $ next to the PDF/photo. Same side preview as Daily Activity Log.',
        'how'     => [
            'Pick site and date range, then press Filter.',
            'Upload an invoice (PDF or photo) with the date. The row is created for that invoice.',
            'Click the file name (or the amount). A side panel opens with the invoice and an Amount $ box.',
            'Type the invoice total in Amount $ and Save. Do not open a new tab for the file.',
            'Use Edit / Delete on a row only when you are correcting a mistake.',
        ],
        'calc'    => [
            'Amount is the invoice total stored for that site and invoice date.',
            'Food-cost reports read these saved amounts — they do not re-read the PDF.',
        ],
        'notes'   => [
            'Operations menu. Org/site scoped.',
            'Same side-panel idea as Daily Activity Log POS files.',
        ],
        'tour'    => [
            ['sel'=>null, 'title'=>'Food Cost', 'body'=>'This page stores supplier invoices and the Amount $ you type. Labor % lives on Daily Activity Log, not here.'],
            ['sel'=>'.filter-bar, [name="rid"], [name="start"], [name="end"]', 'title'=>'Site and dates', 'body'=>'Choose the restaurant and range, then Filter.'],
            ['sel'=>'#invUploadForm, [name="invoice_file"]', 'title'=>'Upload invoice', 'body'=>'Attach the PDF or photo and the invoice date, then upload.'],
            ['sel'=>'.rpt-table, table', 'title'=>'Invoice rows', 'body'=>'Click the file (or amount) to open the side preview. Type Amount $ and Save.'],
            ['sel'=>'#fcInvPreview, #fcPrevAmt', 'title'=>'Side preview', 'body'=>'Invoice stays on the right. Amount $ is typable. Save writes the row.'],
        ],
    ],

    'foodcost_view.php' => [
        'title'   => 'Food Cost — invoices',
        'purpose' => 'Same Food Cost page (short URL). Upload invoices and type Amount $ in the side preview.',
        'how'     => [
            'Pick site and dates, Filter.',
            'Upload the invoice file.',
            'Click the file to open the side panel and type Amount $.',
        ],
        'calc'    => ['Amount is the saved invoice total for that site/date.',],
        'notes'   => ['Alias of food_cost_view.php.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Food Cost', 'body'=>'Same invoice list as Food Cost. Click a file for the side preview and Amount $.'],
            ['sel'=>'.filter-bar, [name="rid"]', 'title'=>'Filters', 'body'=>'Site and date range.'],
            ['sel'=>'#invUploadForm', 'title'=>'Upload', 'body'=>'PDF or photo plus invoice date.'],
        ],
    ],

    'inventory/index.php' => [
        'title'   => 'Log Delivery — invoices',
        'purpose' => 'Receive a supplier invoice into on-hand stock (qty × rate). This is not Usage.',
        'how'     => [
            'Confirm the site in the top selector.',
            'Upload the invoice (Sysco or similar) or type invoice # / vendor / date.',
            'Enter qty and rate on each line. Green/matched rows come from the invoice file.',
            'Press Save. Those units become Recv on Audit.',
            'To record what the kitchen used, open Usage — not this page.',
        ],
        'calc'    => [
            'Line value = qty × rate. Saved on the invoice date for this site.',
            'Audit Recv (B) sums these receipts between last count and the audit date.',
        ],
        'notes'   => [
            'Inventory menu. Latest invoice date you see (e.g. Sep 29) is a delivery date, not a usage date.',
        ],
        'tour'    => [
            ['sel'=>null, 'title'=>'Log Delivery', 'body'=>'This page is incoming invoices. Usage is a different page — kitchen consumption.'],
            ['sel'=>'.upload-bar, [name="invoice_file"], [name="vendor"]', 'title'=>'Invoice header', 'body'=>'Upload the file or type vendor, invoice #, and date.'],
            ['sel'=>'#itemsTable, #itemSearch', 'title'=>'Lines', 'body'=>'Qty and rate per item. Search to find a row. Save posts the receipt.'],
            ['sel'=>'.btn-save-fixed, button.btn-success', 'title'=>'Save delivery', 'body'=>'Save writes received stock. Then Physical Count or Audit can see Recv.'],
        ],
    ],

    'inventory/usage.php' => [
        'title'   => 'Usage — Daily Consumption',
        'purpose' => 'Record what left the shelf. The date list shows only days that already have a saved usage entry.',
        'how'     => [
            'Open Usage. The date box is a list of entry dates only — not a free calendar.',
            'It opens on the latest saved date for this site (example: Vernon Sep 29).',
            'Pick another date in that list to review an older entry.',
            'Type used qty on each item and Save.',
            'New day: save at least one qty; that date then appears in the list.',
        ],
        'calc'    => [
            'Used qty is stored per item on usage_date for this site.',
            'Audit Used (C) sums usage_date after Last count through the audit date.',
        ],
        'notes'   => [
            'Dates with no saved row do not appear — that is on purpose for audit.',
            'Delivery invoices live on Log Delivery, not here.',
        ],
        'tour'    => [
            ['sel'=>null, 'title'=>'Usage', 'body'=>'Kitchen consumption only. The date list is days you already saved — it defaults to the newest.'],
            ['sel'=>'#usageDatePicker', 'title'=>'Entry dates', 'body'=>'Only dates with a saved usage row. Change date to load that day.'],
            ['sel'=>'#usageTable, #itemSearch', 'title'=>'Used qty', 'body'=>'Type what left the shelf. Search to jump to an item.'],
            ['sel'=>'.btn-save-fixed, #usageForm button', 'title'=>'Save usage', 'body'=>'Save posts the day. Audit then includes these units after the last count.'],
        ],
    ],

    'inventory/audit.php' => [
        'title'   => 'Audit & Variance',
        'purpose' => 'Compare last physical count, receipts, usage since that count, and the latest count. Window is count-to-count, not a fixed calendar week.',
        'how'     => [
            'Last count (opening) is the most recent physical count before today. Change it only if you need an older baseline.',
            'Audit / latest count is today, or pick a count date if you already counted.',
            'Press Recalculate after you change either date.',
            'Read the banner: Last count (A), usage days after that count, Current Physical on the audit date.',
            'If Current Physical is blank, finish Physical Count on that date, then come back.',
            'Print discrepancy sheet when you are walking the store.',
        ],
        'calc'    => [
            'Last count (A) = physical qty saved on the opening count date.',
            'Recv (B) = invoice qty received from last count date through audit date.',
            'Used (C) = usage after last count through audit date (usage_date > last count AND usage_date <= audit date).',
            'Theo Balance = (A + B) − C.',
            'Variance = Current Physical − Theo. Blank physical means count is still to finish.',
        ],
        'notes'   => [
            'If last count was a week ago, Used is those in-between days — not only today.',
            'Weekly/monthly POS on Daily Activity Log does not change this stock math.',
        ],
        'tour'    => [
            ['sel'=>null, 'title'=>'Audit', 'body'=>'This is last count, plus receipts, minus usage after that count, versus the latest count.'],
            ['sel'=>'#auditFilterForm, select[name="start_date"]', 'title'=>'Last count', 'body'=>'Opening stock. Auto-picks the last physical count for the site.'],
            ['sel'=>'select[name="end_date"]', 'title'=>'Audit date', 'body'=>'Today, or a day you already counted. Recalculate after you change it.'],
            ['sel'=>'#auditWindow, .audit-window', 'title'=>'Window', 'body'=>'Shows last count, how many usage days are included, and whether a count exists on the audit date.'],
            ['sel'=>'#auditTable, table.table', 'title'=>'Variance grid', 'body'=>'Theo = (Last count + Recv) − Used. Current Physical is the count on the audit date.'],
        ],
    ],

    'inventory/physical_count.php' => [
        'title'   => 'Physical Stock Count',
        'purpose' => 'Walk the store and save on-hand qty. That date becomes Last count (and later Current Physical) on Audit.',
        'how'     => [
            'Set Count date to the day you are counting (usually today).',
            'Enter on-hand qty for each item. Use the unit on the row.',
            'Save. This writes the physical count for that date.',
            'Open Audit — Last count or Current Physical will show this date.',
        ],
        'calc'    => [
            'Qty is the counted on-hand for count_date / audit_date at this site.',
            'Audit reads this table for opening and current physical — it does not guess.',
        ],
        'notes'   => ['Do a count before you trust variance. Blank Current Physical means no count on the audit date.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Physical count', 'body'=>'This is the clipboard count. Audit cannot show Current Physical until you save here.'],
            ['sel'=>'[name="count_date"], [name="audit_date"]', 'title'=>'Count date', 'body'=>'The day you counted. Save under this date.'],
            ['sel'=>'#countTable, #mainCountForm', 'title'=>'On-hand qty', 'body'=>'Type what is on the shelf. Save posts the count.'],
        ],
    ],

    'inventory/manage_items.php' => [
        'title'   => 'Master Inventory Items',
        'purpose' => 'Item names, units, and cost used on Delivery, Usage, Count, and Audit.',
        'how'     => [
            'Confirm site/org.',
            'Edit name, purchase unit, count unit, pieces per box, or cost.',
            'Press Save on that row. Green rows came from an invoice.',
        ],
        'calc'    => ['Cost and units here feed invoice matching and reports. Qty math still lives on Delivery / Usage / Count.',],
        'notes'   => ['Spellings here should match the invoice so upload can map lines.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Master items', 'body'=>'Catalogue for this site. Fix the name or unit here if invoices do not match.'],
            ['sel'=>'table, .card', 'title'=>'Item rows', 'body'=>'Change name, units, or cost, then Save on that row.'],
        ],
    ],

    'inventory/orders.php' => [
        'title'   => 'Projected Orders',
        'purpose' => 'Suggest a purchase from recent usage after the last count.',
        'how'     => [
            'Pick the count date and how many days to cover.',
            'Review suggested qty and the dollar forecast.',
            'Adjust the order qty and save/submit as your process requires.',
        ],
        'calc'    => ['Forecast uses usage after the selected count, scaled to the days you asked for.',],
        'notes'   => ['Suggestion only — confirm with the invoice and on-hand count.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Orders', 'body'=>'Projected purchase from usage after a count. Not a Sysco send-to-vendor screen unless you submit.'],
            ['sel'=>'form, [name="count_date"], [name="project_days"]', 'title'=>'Window', 'body'=>'Count date and days to cover. Recalculate after you change them.'],
            ['sel'=>'table.table', 'title'=>'Suggested lines', 'body'=>'Edit qty if the suggestion is high or low, then save.'],
        ],
    ],

    'inventory/waste.php' => [
        'title'   => 'Wastage Analysis',
        'purpose' => 'Show inventory loss (waste) for the selected site.',
        'how'     => ['Open the page for the site.', 'Read the month total and the item table.',],
        'calc'    => ['Loss is taken from recorded waste / variance for the site month.',],
        'notes'   => ['Use Audit + Usage if you need the count-to-count story, not only waste.'],
        'tour'    => [
            ['sel'=>null, 'title'=>'Wastage', 'body'=>'Dollar loss for this site. Pair with Audit when you are investigating a gap.'],
            ['sel'=>'table.table, .report-card', 'title'=>'Loss table', 'body'=>'Items that booked waste or a write-off.'],
        ],
    ],

    'inventory/inventory_report.php' => [
        'title'   => 'Inventory Status Report',
        'purpose' => 'On-hand / status snapshot for the site.',
        'how'     => ['Open the report for the site.', 'Print or export if you need a clipboard sheet.',],
        'calc'    => ['Reads saved inventory figures — it does not recast Audit columns.',],
        'notes'   => ['For last-count vs usage vs latest count, use Audit.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Status report', 'body'=>'Snapshot of inventory status. Variance walk is on Audit.'],
            ['sel'=>'table.table', 'title'=>'Status table', 'body'=>'Item status for this site.'],
        ],
    ],

    'daily_report.php' => [
        'title'   => 'Admin Payroll Master (Daily Report)',
        'purpose' => 'Admin filter view over daily payroll/attendance by date, site, user.',
        'how'     => ['Filter by date, site, user.', 'Review results; act in Staff Audit or Payroll Center as needed.',],
        'calc'    => ['Listing/filter over operational payroll data.',],
        'notes'   => ['Admin-oriented.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Daily Report filters', 'body'=>'Quick admin slice of activity by day/site/user.'],
            ['sel'=>'[name="f_date"]', 'title'=>'Date', 'body'=>'Day to inspect.'],
            ['sel'=>'[name="f_site"]', 'title'=>'Site', 'body'=>'Location filter.'],
            ['sel'=>'[name="f_user"]', 'title'=>'User', 'body'=>'Employee filter.'],
        ],
    ],

    'help_center.php' => [
        'title'   => 'Learning Center',
        'purpose' => 'Static tutorial hub (staff / supervisor / manager sections). Page-level About + Start Tour on each operational screen go deeper with live spotlights and Ask AI.',
        'how'     => ['Open Overview / Staff / Manager sections via the page anchors.', 'For hands-on help, open the real screen (Kiosk, Scheduler, Payroll Center) and click Start Tour.',],
        'calc'    => [],
        'notes'   => ['Prefer in-page Start Tour for up-to-date UI guidance — content is maintained in payroll_help.php.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Learning Center', 'body'=>'Written tutorials live here. For interactive help with voice, open any main screen and use Start Tour + Ask AI.'],
            ['sel'=>'#overview, #staff, #manager', 'title'=>'Sections', 'body'=>'Jump between audience sections. Then practice on the real Kiosk, Scheduler, or Payroll pages.'],
        ],
    ],

    'business_hub.php' => [
        'title'   => 'Business Hub',
        'purpose' => 'Manage organizations/sites nicknames and addresses in one business-facing hub.',
        'how'     => ['Select org.', 'Edit site nick name / address.', 'Save site.',],
        'calc'    => [],
        'notes'   => ['Related to Manage Sites / Manage Orgs.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Business Hub', 'body'=>'Maintain org/site directory details used across scheduling and payroll filters.'],
            ['sel'=>'#siteForm, [name="nick_name"]', 'title'=>'Site form', 'body'=>'Update the display name and address for a site.'],
            ['sel'=>'[name="save_site"]', 'title'=>'Save', 'body'=>'Write changes for the selected site/org.'],
        ],
    ],

    'tax_hub.php' => [
        'title'   => 'Tax & Compliance Hub',
        'purpose' => 'Jumping-off point for tax-year / site compliance views — pairs with Tax & T4 and CRA pages.',
        'how'     => ['Choose year and site.', 'Continue to T4 or CRA tools for filing prep.',],
        'calc'    => [$disclaimer,],
        'notes'   => ['Worksheet/prep only — not CRA efile.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Tax & Compliance Hub', 'body'=>'Pick year/site context, then use Tax & T4 or CRA Remittance for the real worksheets and payment log.'],
            ['sel'=>'[name="year"]', 'title'=>'Year', 'body'=>'Tax year to focus on.'],
            ['sel'=>'[name="site_id"]', 'title'=>'Site', 'body'=>'Optional employer/site scope.'],
        ],
    ],

    'manage_users.php' => [
        'title'   => 'User Management',
        'purpose' => 'Admin list of users — companion to Employee Hub for account administration.',
        'how'     => ['Browse the user table.', 'Edit accounts as needed.', 'Prefer Employee Hub for rates/sites/PINs used in payroll.',],
        'calc'    => [],
        'notes'   => ['Org-scoped where applicable.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'User Management', 'body'=>'Account list for administrators. Day-to-day payroll fields are often easier in Employee Hub.'],
            ['sel'=>'#userTable', 'title'=>'Users table', 'body'=>'Find and open a user to adjust access.'],
        ],
    ],

    'admin_dashboard.php' => [
        'title'   => 'Control Panel',
        'purpose' => 'Admin landing: high-level controls including creating organizations.',
        'how'     => ['Review control panel cards/links.', 'Create org when onboarding a company.', 'Use Menu Manager / Modules from Admin menu for deeper config.',],
        'calc'    => [],
        'notes'   => ['High privilege.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Admin Control Panel', 'body'=>'Top-level admin home. Most daily payroll work happens under the Payroll and Clock In menus — this page is for system control.'],
            ['sel'=>'#newOrg, [name="org_name"]', 'title'=>'New organization', 'body'=>'Create a company shell, then add sites and employees under it.'],
        ],
    ],

    'site_assignment.php' => [
        'title'   => 'Site Assignment Manager',
        'purpose' => 'Assign which sites belong to which organization / update site–org links so users and payroll scope stay correct.',
        'how'     => ['Select org and site.', 'Update assignment or create site under org.', 'Confirm employees allocated to those sites in Employee Hub.',],
        'calc'    => [],
        'notes'   => ['Wrong assignment causes “missing” sites when org filter changes.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Site Assignment', 'body'=>'Connect sites to the right organization. If a site vanished after an org switch, check assignments here.'],
            ['sel'=>'[name="org_id"], [name="site_id"]', 'title'=>'Org & site', 'body'=>'Choose which site maps to which org.'],
            ['sel'=>'[name="update_assignment"]', 'title'=>'Update', 'body'=>'Save the mapping.'],
        ],
    ],

    'role_manager.php' => [
        'title'   => 'Role Manager',
        'purpose' => 'Define user roles (name, color, landing page) that control menu visibility via UserRole thresholds.',
        'how'     => ['Edit role name/color/landing page.', 'Save role.', 'Assign roles to users in Employee Hub / User Management.',],
        'calc'    => ['Menu items show when the user\'s role level meets UserRole on menu_manager.',],
        'notes'   => ['Changing roles changes which tutorials/pages people can open.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Role Manager', 'body'=>'Roles gate the Payroll menu. Lower role numbers usually mean more access (admin).'],
            ['sel'=>'[name="role_name"]', 'title'=>'Role name', 'body'=>'Label shown for the role.'],
            ['sel'=>'[name="landing_page"]', 'title'=>'Landing page', 'body'=>'Where users with this role land after login.'],
            ['sel'=>'[name="save_role"]', 'title'=>'Save', 'body'=>'Store the role definition.'],
        ],
    ],

    'org_settings.php' => [
        'title'   => 'Org Settings',
        'purpose' => 'Subscription & usage style settings for the current organization.',
        'how'     => ['Review usage.', 'Update org name / settings fields.', 'Save.',],
        'calc'    => [],
        'notes'   => ['Org-scoped.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Org Settings', 'body'=>'Settings for the active organization — not a payroll run screen.'],
            ['sel'=>'[name="org_name"]', 'title'=>'Org name', 'body'=>'Update how the company is labeled.'],
            ['sel'=>'[name="update_org"]', 'title'=>'Save', 'body'=>'Write org settings.'],
        ],
    ],

    'site_manager.php' => [
        'title'   => 'Site Accuracy & Geofence',
        'purpose' => 'Set geofence radius (meters) per site so the kiosk can block or warn on out-of-bounds punches.',
        'how'     => ['Select site.', 'Set geo_radius_meters.', 'Save.', 'Test on kiosk — too-tight radius causes geofence_blocked errors.',],
        'calc'    => ['Distance from site coordinates vs radius decides allow / warn / block on punch.',],
        'notes'   => ['Pair with Manage Sites address/map pin.',],
        'tour'    => [
            ['sel'=>null, 'title'=>'Geofence settings', 'body'=>'Control how close a phone must be to punch. Too strict = staff get Access Denied on the kiosk.'],
            ['sel'=>'[name="site_id"]', 'title'=>'Site', 'body'=>'Which location\'s fence you are editing.'],
            ['sel'=>'[name="geo_radius_meters"]', 'title'=>'Radius (meters)', 'body'=>'Allowed distance from the site point. Increase if legitimate punches are blocked outdoors/parking.'],
            ['sel'=>'[name="update_radius"]', 'title'=>'Save radius', 'body'=>'Apply and have a staff member test Clock In on site.'],
        ],
    ],

    'manual_pay_entry.php' => [
        'title'   => 'Manual Pay / Adjustments',
        'purpose' => 'Post one-off amounts that are not a normal clocked shift: bonuses, car allowance, termination pay, travel lumps, wage adjustments, or off-payroll reimbursements. Include in payroll = Yes creates a locked payroll line (paystub / T4). Bonus and car allowance print as their own earnings lines on the payroll cheque. Include = No creates a payment voucher only (not employment income).',
        'how'     => [
            'Open from Payroll Center → Manual Pay / Adjustments (or Start Tour on this page).',
            'Choose Date, Site, and Employee for the organization you are working in.',
            'Enter the Amount (must be greater than zero).',
            'Choose a Type: Adjustment (deducted from pay), Benefit (added to pay), Bonus, Car allowance, Travel, Termination pay, or Other.',
            'Type the Reason. It is blank until you fill it, and that wording prints on the paystub.',
            'For Adjustment or Benefit, choose Bi-weekly or Monthly and the number of periods. Each period is posted on its own date.',
            'Set Include in payroll: Yes = wages → tax/CPP/EI engine → payroll_finalized → appears on paystub, ledger, and T4. No = not wages (e.g. expense reimbursement) → payment voucher only; not on paystub or T4 box 14.',
            'Add Remarks if helpful (cheque #, “missed OT week of…”, etc.).',
            'Click Add another line to enter a second amount for the same date, site, and employee. Each line has its own reason, amount, and Include choice. Remove drops a line you do not want.',
            'Click Post entry. For Include = Yes, open Payroll Center for that date/employee and use Print cheques or Print paystubs. For Include = No, click Voucher on the row to print.',
            'Use Void to reverse a mistake (payroll lines posted with Yes are deleted from payroll_finalized).',
            'Do not use this page for normal hours — use Staff Audit → Finalize instead.',
        ],
        'calc'    => [
            'Include = Yes: amount is treated as gross; pe_line / pe_line_for computes CPP, EI, income tax, employer match, WCB, and vacation at post time. Values are stored on payroll_finalized and never recalculated by the ledger.',
            'Include = No: no deductions are computed; the full amount is recorded on the voucher register only.',
            'Pay period end follows the same semi-monthly rule as finalize (1–15 → 15th; 16–EOM → month-end).',
        ],
        'notes'   => [
            'Wrong Include choice affects CRA remittance and T4 — when unsure, treat wage-like money as Yes and ask your accountant.',
            'Typical Yes: bonus, termination/severance, taxable travel allowance, fixing underpaid wages.',
            'Typical No: expense reimbursement with receipts, logging a payment already made outside the system.',
            'Org-scoped; other organizations\' employees and sites never appear.',
            'Statutory defaults / engine rates are not legal advice — confirm with your accountant or CRA before filing.',
        ],
        'tour'    => [
            ['sel'=>null, 'title'=>'Manual Pay / Adjustments', 'body'=>'Use this page when money is not a normal clocked shift. Two paths: Include = Yes (wages on paystub/T4) or Include = No (payment voucher only). Start Tour walks each field.'],
            ['sel'=>'#pgHelpPanel', 'title'=>'About this page & Start Tour', 'body'=>'About this page has the full customer how-to. Start Tour replays these spotlights. Ask AI answers from the same guide.'],
            ['sel'=>'#mpeDate', 'title'=>'Date', 'body'=>'Work / payment date for this entry. For Include = Yes it becomes the payroll work_date and drives the pay-period end (semi-monthly).'],
            ['sel'=>'#mpeSite', 'title'=>'Site', 'body'=>'Legal employer site (RP). Province on the site drives vacation and tax jurisdiction for Include = Yes lines.'],
            ['sel'=>'#mpeEmployee', 'title'=>'Employee', 'body'=>'Who receives the amount. Must belong to the same organization as the site.'],
            ['sel'=>'#mpeAmount', 'title'=>'Amount', 'body'=>'Gross dollars for Include = Yes (before deductions). Full payment amount for Include = No vouchers.'],
            ['sel'=>'#mpeType', 'title'=>'Type', 'body'=>'Adjustment is deducted from pay. Benefit is added. Bonus, car allowance, travel, termination, and other are one-time amounts. Adjustment and Benefit show Frequency and Number of periods.'],
            ['sel'=>'#mpeReason', 'title'=>'Reason', 'body'=>'Type the reason yourself. The box starts blank. That wording is what prints on the paystub, for example Till shortage or Phone allowance.'],
            ['sel'=>'#mpeFrequency', 'title'=>'Frequency and periods', 'body'=>'Shown for Adjustment and Benefit. Bi-weekly posts every 14 days. Monthly posts on the same date each month. Number of periods is how many times it repeats, starting with the date on the form.'],
            ['sel'=>'#mpeInclude', 'title'=>'Include in payroll?', 'body'=>'Yes = employment income → deductions → paystub and T4. No = reimbursement / non-wage → printable voucher only, not on stub or T4. When unsure on wage-like money, choose Yes.'],
            ['sel'=>'#mpeRemarks', 'title'=>'Remarks', 'body'=>'Optional notes (cheque #, period reference). Stored on the entry and on the payroll line comments when Include = Yes.'],
            ['sel'=>'#mpeAddLine', 'title'=>'Add another line', 'body'=>'Adds a second amount for the same date, site, and employee. Each line keeps its own reason and Include choice. Remove drops a line before you post.'],
            ['sel'=>'#mpeSubmit', 'title'=>'Post entry', 'body'=>'Saves every line. Yes posts to payroll_finalized; No stays on the voucher register. Then use Ledger / Voucher links on the recent list.'],
            ['sel'=>'#mpeListCard', 'title'=>'Recent entries', 'body'=>'Review posted rows. Voucher prints off-payroll payments. Ledger jumps to Payroll Center for wage lines. Void reverses a mistake.'],
            ['sel'=>null, 'title'=>'Demo tip for presentations', 'body'=>'Show a Bonus and a Car allowance with Include = Yes, then open Payroll Center → Print paystubs. Each prints as its own earnings line. A reasonable per-km car allowance uses Include = No and the voucher — it is not on the cheque or the T4.'],
        ],
    ],

    ];
}

/**
 * Flat corpus for Ask AI retrieval (same source as About panels).
 */
function payroll_help_ask_corpus(): array {
    $out = [];
    foreach (payroll_help_content() as $page => $c) {
        $chunks = [];
        $chunks[] = $c['title'] ?? '';
        $chunks[] = $c['purpose'] ?? '';
        foreach (['how', 'calc', 'notes'] as $k) {
            if (!empty($c[$k]) && is_array($c[$k])) {
                $chunks = array_merge($chunks, $c[$k]);
            }
        }
        if (!empty($c['tour']) && is_array($c['tour'])) {
            foreach ($c['tour'] as $step) {
                $chunks[] = ($step['title'] ?? '') . '. ' . ($step['body'] ?? '');
            }
        }
        $out[] = [
            'page'  => $page,
            'title' => $c['title'] ?? $page,
            'text'  => trim(implode("\n", array_filter($chunks))),
        ];
    }
    return $out;
}


function payroll_help_resolve_key(?string $pageKey = null): string {
    if (is_string($pageKey) && trim($pageKey) !== '') {
        return trim($pageKey);
    }
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('#/(inventory/[^/]+\.php)$#', $script, $m)) {
        return $m[1];
    }
    $base = basename($script);
    if ($base === 'foodcost_view.php') {
        return 'food_cost_view.php';
    }
    return $base;
}

function payroll_help_listen_text(array $c): string {
    $parts = [];
    if (!empty($c['title'])) { $parts[] = (string)$c['title']; }
    if (!empty($c['purpose'])) { $parts[] = (string)$c['purpose']; }
    if (!empty($c['how']) && is_array($c['how'])) {
        $parts[] = 'How to use.';
        $n = 1;
        foreach ($c['how'] as $step) {
            $parts[] = 'Step ' . $n . '. ' . $step;
            $n++;
        }
    }
    if (!empty($c['calc']) && is_array($c['calc'])) {
        $parts[] = 'How it is calculated.';
        foreach ($c['calc'] as $line) { $parts[] = (string)$line; }
    }
    if (!empty($c['notes']) && is_array($c['notes'])) {
        $parts[] = 'Notes.';
        foreach ($c['notes'] as $line) { $parts[] = (string)$line; }
    }
    return trim((string)preg_replace('/\s+/', ' ', implode(' ', $parts)));
}

/**
 * Render the collapsible help panel + video/audio tour + Listen + Ask AI.
 * Returns '' when the key is unknown or no user session exists.
 */
function payroll_help_panel(?string $pageKey = null): string {
    static $emitted = false;
    if ($emitted) return '';                         // once per page
    if (!isset($_SESSION['user_id'])) return '';     // logged-in users only

    $pageKey = payroll_help_resolve_key($pageKey);
    $all = payroll_help_content();
    if (!isset($all[$pageKey]) && strpos($pageKey, '/') !== false) {
        $base = basename($pageKey);
        if (isset($all[$base])) {
            $pageKey = $base;
        }
    }
    if (!isset($all[$pageKey])) return '';
    $c = $all[$pageKey];
    $emitted = true;

    $h = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $list = static function (array $items) use ($h) {
        $out = '';
        foreach ($items as $i) { $out .= '<li>' . $h($i) . '</li>'; }
        return $out;
    };

    $tourJson = json_encode([
        'page'       => $pageKey,
        'pageTitle'  => (string)($c['title'] ?? ''),
        'user'       => (string)($_SESSION['user_id'] ?? ''),
        'steps'      => array_values($c['tour'] ?? []),
        'listenText' => payroll_help_listen_text($c),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $ver = '10'; // bump to bust browser cache after editing payroll_tour.js/css/ask

    $html  = '<link rel="stylesheet" href="/payroll_tour.css?v=' . $ver . '">';
    $html .= '<div class="pg-help-panel" id="pgHelpPanel">';
    $html .= '<div class="pg-help-bar">';
    $html .= '<button type="button" class="pg-help-toggle" aria-expanded="false" aria-controls="pgHelpBody" onclick="pgHelpToggle(this)">';
    $html .= '<span class="pg-help-ico" aria-hidden="true">&#9432;</span> About this page <span class="pg-help-chev" aria-hidden="true">&#9662;</span></button>';
    $html .= '<button type="button" class="pg-tour-start" id="pgTourStartBtn" aria-label="Play video and audio tour of this page" onclick="window.pgTourStart&&window.pgTourStart()">&#9658; Video + audio</button>';
    $html .= '<button type="button" class="pg-tour-listen" id="pgTourListenBtn" aria-label="Listen to this page guide" onclick="window.pgTourListen&&window.pgTourListen()">&#128266; Listen</button><label class="pg-voice-wrap" for="pgTourVoice"><span class="pg-voice-lbl">Voice</span><select id="pgTourVoice" class="pg-voice-select" aria-label="Select voice model"><option value="">Browser default</option></select></label>';
    $html .= '<button type="button" class="pg-ask-open" id="pgAskOpenBtn" aria-label="Ask AI help assistant" onclick="window.pgAskOpen&&window.pgAskOpen()">&#9733; Ask AI</button>';
    $html .= '<a class="pg-qa-open" href="ms-quick-assist://" title="Opens Windows Quick Assist. Enter the 6-digit code we give you, then choose Allow.">Quick Assist</a>';
    $html .= '</div>';
    $html .= '<div class="pg-help-body" id="pgHelpBody" hidden>';
    $html .= '<h5>' . $h($c['title']) . '</h5>';
    $html .= '<p class="pg-help-purpose">' . $h($c['purpose']) . '</p>';
    if (!empty($c['how']))  { $html .= '<h6>How to use</h6><ol>' . $list($c['how']) . '</ol>'; }
    if (!empty($c['calc'])) { $html .= '<h6>How it\'s calculated / data source</h6><ul>' . $list($c['calc']) . '</ul>'; }
    if (!empty($c['notes'])){ $html .= '<h6>Notes &amp; scope</h6><ul>' . $list($c['notes']) . '</ul>'; }
    $html .= '<p class="pg-help-edit">Video + audio spotlights the real buttons and speaks each step. Listen reads this written guide. Ask AI uses the same map.</p>';
    $html .= '<p class="pg-help-edit"><strong>Windows Quick Assist.</strong> Click Quick Assist on this bar. Windows asks to open the app. Enter the 6-digit code we give you, then choose <strong>Allow</strong> to share your screen.</p>';
    $html .= '</div></div>';
    $html .= '<script>function pgHelpToggle(b){var p=document.getElementById("pgHelpBody");var open=p.hasAttribute("hidden");if(open){p.removeAttribute("hidden");}else{p.setAttribute("hidden","");}b.setAttribute("aria-expanded",open?"true":"false");b.classList.toggle("pg-open",open);}</script>';
    $html .= '<script>window.PG_TOUR_CONFIG = ' . $tourJson . ';</script>';
    $html .= '<script>window.PG_ASK_CONFIG = ' . json_encode([
        'page' => $pageKey,
        'endpoint' => '/payroll_ask_ai.php',
    ], JSON_UNESCAPED_SLASHES) . ';</script>';
    $html .= '<script src="/payroll_tour.js?v=' . $ver . '" defer></script>';
    $html .= '<script src="/payroll_ask.js?v=' . $ver . '" defer></script>';
    return $html;
}


} // function_exists guard
