<?php
/**
 * Questions seeded into help_qa. The migration inserts a row only when
 * (app, page_key, question) is not already there, so an edit in the panel
 * (edited_in_app = 1) is never overwritten.
 *
 * Hidden rows are not shown unless FAQ_T4127_LIVE or FAQ_QUICK_ENTRY_LIVE
 * is true. Those constants default to false.
 *
 * Run: php engine/help_qa_seed.php
 * Writes migrations/20261120_help_qa.sql and docs/FAQ_PAYROLL.md.
 */

function help_qa_seed_rows(): array
{
    $live = static function (string $page, string $question, string $answer, array $keywords = []): array {
        return [
            'page_key' => $page,
            'question' => $question,
            'answer' => $answer,
            'status' => 'live',
            'requires_feature' => null,
            'keywords' => $keywords,
        ];
    };
    $hidden = static function (string $page, string $question, string $answer, string $feature, array $keywords = []): array {
        return [
            'page_key' => $page,
            'question' => $question,
            'answer' => $answer,
            'status' => 'hidden',
            'requires_feature' => $feature,
            'keywords' => $keywords,
        ];
    };

    return [
        $live(
            'employee_hub.php',
            'Where do I add or change an employee?',
            'Employee Hub. Open a row to edit, or use Add staff. The save writes users.Name, email, user, pin, hourly_rate, official_rate, user_role, active, assigned_site_ids, primary_restaurant_id, Remarks, org_id, sin, given_name, surname, street_address, city, province, postal_code, dental_benefit_code, ei_exempt, wsib_exempt, vac_pay_exempt, employee_type, Tel_Mobile, and Tel. The form labels for the name and address are Given name, Surname, Street address, City, Province, Postal code, and SIN. An admin whose level is above 10 is kept on their own organization. A superadmin can change org_filter.',
            ['employee', 'sin', 'address', 'given name']
        ),
        $live(
            'employee_hub.php',
            'Who can see a SIN or bank account?',
            'The SIN is stored on users.sin (or SIN, social_insurance_number, social_insurance, sin_number, whichever column exists). Employee Hub shows the SIN in the edit form for an admin of that organization. The ROE screen never prints the full SIN; it masks it and reads the plain value only when it checks or exports an ROE. A platform superadmin needs that organization\'s active support grant before the ROE page will open, because the export carries SINs. Bank transit, institution, and account are users.bank_transit, users.bank_institution, and users.bank_account. They are used on the direct deposit file.',
            ['privacy', 'sin', 'bank']
        ),
        $live(
            'rate_change.php',
            'How do I change a pay rate?',
            'Rate Change records a new hourly rate, official rate, or transit rate with an effective date. Finalize uses the rate in effect on the shift\'s work date. Employee Hub also writes user_rate_history when the hourly rate or the official rate changes on save. The boxes on that form are Hourly rate and Official rate, plus Effective date.',
            ['pay rate', 'hourly', 'effective date']
        ),
        $live(
            'kiosk.php',
            'How does an employee clock in?',
            'On the kiosk the button is Clock In. It calls the punch action clock_in. If the organization blocks off-schedule punches, and the person is outside the schedule margin, the button is Clock In blocked. Otherwise an off-schedule punch can ask for a reason. There is no separate quick hours entry screen.',
            ['clock in', 'kiosk', 'hours']
        ),
        $live(
            'staff_audit.php',
            'How do hours become a pay line?',
            'On Staff Audit, set the filters and press Search. Status Type Pending is the work that is not finalized. Tick the shifts and press Finalize Selected. In the Bulk Pay Allocation Desk the dropdown is Overtime Policy. The choices are Per-shift daily cap, Work-site policy (the label says ON weekly 44h and SK daily 8h + weekly 40h, set in Edit Site), Weekly 44 hours (Ontario default), Weekly 40 hours, Daily 8h + Weekly 44h, and Daily 8h + Weekly 40h (Saskatchewan default). Then press Execute Pay Matrix Process. That writes payroll_finalized. The page does not pay the employee; Payroll Center does.',
            ['hours', 'finalize', 'overtime']
        ),
        $hidden(
            'staff_audit.php',
            'Is there a quick hours entry screen?',
            'No. This payroll app does not have a quick hours entry page. Hours come from Clock In on the kiosk, from Manual attendance, from Staff Audit, or from a CSV or an Excel paste on Bulk import.',
            'quick_entry',
            ['quick entry', 'hours']
        ),
        $live(
            'manual_attendance.php',
            'Can an admin enter a shift that was not clocked?',
            'Yes. Manual attendance is limited to payroll admins. The saved shift is marked Manually Injected Shift. The employee and the site have to belong to the same organization, and that organization has to be one the admin is allowed to manage.',
            ['manual attendance', 'hours']
        ),
        $live(
            'bulk_importer.php',
            'How do I import hours from Excel or a CSV?',
            'Bulk import is limited to admins (role 20 or stronger). Choose a CSV file, or paste rows from Excel or Google Sheets. Hours can be punches or RegularHours. If the headers do not match the template, use Match columns before import. The employee file uses StartDate. It does not take a vacation percent; vacation is worked out from the start date and the site province when pay is finalized.',
            ['csv', 'excel', 'import', 'hours']
        ),
        $live(
            'vacation_service_admin.php',
            'How is vacation pay worked out?',
            'The rate comes from the work site\'s province and from employee_company_service for that employee and that site. Saskatchewan is 3/52 until 10 years of service, then 4/52. Every other province uses 4% until 5 years and 6% after that, because the function only has a special case for SK. If vac_percent on employee_company_service is above 0, that percent is used instead. When the organization pays vacation on each cheque, the percent is added to the pay and is included in CPP, EI, and WCB. Otherwise it is accrued on the employer side and is not added to the cheque.',
            ['vacation', 'province']
        ),
        $live(
            'payroll_master_ledger.php',
            'How are CPP, EI, and income tax calculated on pay that is running today?',
            'Finalize stores the deductions. Payroll Center only reads them. The engine that is running in production multiplies the pay by the rates in payroll_settings: cpp_rate, ei_rate, fed_tax_rate, wcb_rate, and ei_employer_factor. If a rate is missing, that engine uses CPP 5.95%, EI 1.63%, income tax 15%, WCB 2%, and employer EI of 1.4 times the employee EI. Employer CPP matches the employee CPP. Set organizations.withhold_income_tax to 0 to store income tax as 0. users.employee_type of contract stores CPP and EI as 0. users.ei_exempt of 1 stores EI as 0 and leaves CPP. CPP for the period is stored once on payroll_period_stat. The shift rows keep cpp at 0 so the basic exemption is not taken on every shift. Changing a rate later does not rewrite rows already stored.',
            ['cpp', 'ei', 'tax', 'deductions']
        ),
        $hidden(
            'payroll_master_ledger.php',
            'How does the CRA T4127 engine calculate deductions?',
            'In this code, pe_line calls the T4127 calculator when it is given a database connection and a rate pack exists for the pay date. It calculates federal tax, provincial tax for British Columbia, Ontario, and Saskatchewan, CPP, CPP2, and EI from the edition for that pay date. Pay dates from 1 January 2026 through 30 June 2026 use the 122nd edition. Pay dates on and after 1 July 2026 use the 123rd edition, which changes British Columbia\'s lowest rate and basic reduction only. Employer CPP and CPP2 match the employee. Employer EI is 1.4 times the employee premium, kept to the yearly maximum. A province with no loaded table gets federal tax, CPP, and EI, and the result says provincial tax was not calculated. Quebec provincial tax and the QPP are not calculated. WSIB, WorkSafeBC, or Saskatchewan WCB uses sites.wsib_percent, where 1.23 means 1.23 percent. A blank percent keeps payroll_settings.wcb_rate. Ontario EHT is estimated on the line and the year figure is payroll_eht_report.php. That report does not file a return. This calculator is in the repository and is not what the live site runs until it is deployed. Do not file from numbers stored before a line has deduction_edition.',
            't4127',
            ['t4127', 'cpp', 'tax', 'bc', 'ontario', 'saskatchewan']
        ),
        $live(
            'payroll_master_ledger.php',
            'How do I print a pay stub or a cheque?',
            'On Payroll Center, tick the rows, then use Print cheques or Print paystubs. They are separate buttons. Email paystubs sends each person their own stub. Print cheques asks for blank paper or pre-printed stock. Pre-printed stock prints the date, name, amount, and memo and leaves the stub out. The amounts are the ones stored at finalize.',
            ['pay stub', 'cheque', 'paystub']
        ),
        $live(
            'payroll_master_ledger.php',
            'How do I make a direct deposit file?',
            'Tick the rows and press Direct deposit file. That posts to direct_deposit_file.php, which writes a Payments Canada Standard 005 file of 1464-byte records. It uses the site bank profile and the employee fields users.bank_institution, users.bank_transit, and users.bank_account. The row checkbox is labelled Direct deposit. The file is a download. This screen does not send it to the bank.',
            ['direct deposit', 'eft', 'bank']
        ),
        $live(
            'generate_stat_shifts.php',
            'How do I post statutory holiday pay from this screen?',
            'Preview does not post anything. For a pay period, set Period start and Period end and press Preview all holidays in period, then approve the ticked rows. For one holiday, press Preview this holiday, tick the rows, and press Approve & post selected. The holiday list for the organization is built from sites whose province is ON or SK. Ontario pay on this screen is regular wages plus vacation pay in the four work weeks before the holiday week, divided by 20. Saskatchewan is 5 percent of wages, excluding the overtime premium, in the 28 days before the holiday. A holiday that was already posted is skipped.',
            ['stat holiday', 'ontario', 'saskatchewan']
        ),
        $hidden(
            'generate_stat_shifts.php',
            'Does finalize post statutory holiday pay by itself?',
            'The code on this branch calls sh_after_finalize when hours are finalized, for a site in British Columbia, Ontario, or Saskatchewan. It posts one line per holiday in that pay period for an eligible employee, and calling it again does not add a second line for the same employee, site, and holiday. British Columbia uses 30 calendar days of employment and wages on at least 15 of the 30 days before the holiday. Ontario uses the four-work-week formula divided by 20. Saskatchewan uses 5 percent of wages in the 28 days before the holiday. This automatic post is part of the same undeployed change as the T4127 engine. Until that deploy, use Statutory Holiday Pay and press Approve.',
            't4127',
            ['stat holiday', 'british columbia', 'automatic']
        ),
        $live(
            'generate_t4.php',
            'What does the T4 page produce?',
            'Tax & T4 Center sums stored finalized payroll into a worksheet: Box 14 employment income, Box 22 income tax, Box 16 CPP, and Box 18 EI. It reads what was stored. It does not recalculate, and it does not file with CRA.',
            ['t4', 'tax']
        ),
        $live(
            'tax_hub.php',
            'Is the tax hub a CRA filing?',
            'No. Tax & Compliance Hub is the place to pick a year and a site and then open the T4 worksheet or the CRA pages. It does not file a return.',
            ['t4', 'cra']
        ),
        $live(
            'roe.php',
            'How do I create an ROE in this app?',
            'Open ROE (Record of Employment). Under Legal employers, set Pay period type, Known period end, Contact first, Contact last, Area, Phone, and Ext, then press Save. Under New ROE choose Employee, Legal employer (BN15), Reason (block 16), and optionally Last day for which paid, then press Create draft from pay lines. On the draft, set Block 14 expected recall, fix anything listed in red, and press Mark ready to download. Back on the list, tick the ready rows. Leave Issue = D: ROE Web saves as draft (review there), or choose Issue = S: ROE Web submits on upload. Press Download selected ready ROEs (.BLK). One business number downloads as a .BLK file. Several business numbers download as a zip with one .BLK each. Nothing is sent to Service Canada from this app.',
            ['roe', 'blk', 'reason']
        ),
        $live(
            'roe_edit.php',
            'What do I set on the ROE draft before I download it?',
            'The draft page is ROE # with Edit. Block 14 expected recall is U – Unknown, N – Not returning, or Y – Expected date of recall. Y needs Recall date (only for Y), and that date has to be after Block 11. Press Save draft, then Mark ready to download when the red list is empty. Download this ROE (.BLK) uses the same Issue = D or Issue = S choice. After ROE Web issues it, type the serial number and press Record as issued. An issued ROE is changed with Start an amendment (block 2 = the serial).',
            ['roe', 'recall', 'amend']
        ),
        $live(
            'roe_help.php',
            'Where is the written procedure for filing an ROE?',
            'How to file an ROE is roe_help.php. It is for payroll admins, same as the ROE screen. The employee picker on that page checks the columns this ROE code actually reads and links to Employee Hub to edit the person.',
            ['roe', 'help']
        ),
        $live(
            'manage_sites.php',
            'Which provinces does payroll use?',
            'The work site\'s province (sites.province) is what vacation and overtime look at. Edit Site lists every Canadian province, but the pay rules in code are narrower. If the site has no province rule, overtime falls back to Ontario: weekly over 44 hours at 1.5 times. A site can store its own ot_weekly_hours and ot_daily_hours. Vacation uses the Saskatchewan 3/52 and 4/52 rates only when the province is SK. Every other province gets 4% and then 6% at five years, unless a vacation override is stored. Statutory holiday dates in code exist for British Columbia, Ontario, and Saskatchewan. The holiday screen\'s organization list starts from ON and SK sites. Quebec tax and the QPP are not calculated.',
            ['province', 'ontario', 'saskatchewan', 'british columbia']
        ),
        $hidden(
            'manage_sites.php',
            'Which provinces does the T4127 tax engine support?',
            'The T4127 rate files loaded in this repository have tables for British Columbia, Ontario, and Saskatchewan, plus federal tax, CPP, and EI. A site in another province still gets federal tax, CPP, and EI, and the result says provincial tax was not calculated. Quebec and the territories are not in the tables. This engine is not what the live site runs until it is deployed.',
            't4127',
            ['t4127', 'province', 'tax']
        ),
        $live(
            'role_manager.php',
            'Who can see a menu item or a payroll page?',
            'Menu Manager stores Min. Role as UserRole. A person sees a link when that number is greater than or equal to their user_level, and the link is active. The role numbers are 10 Superadmin, 20 Admin, 25 Site Manager, 30 Supervisor, 35 Demo, 40 Staff, and 1000 Alarm. A smaller number can see everything a larger number can see. Payroll screens that call the payroll admin check refuse anyone whose user_level is 30 or higher. The Questions & answers panel uses the same names in visible_roles. Right now every seeded answer is limited to superadmin.',
            ['roles', 'permissions', 'admin']
        ),
        $live(
            'payroll_cra_payments.php',
            'Does the CRA page file a remittance?',
            'No. payroll_cra_payments.php is the CRA tax remittance register for the organization. The amounts come from payroll_masters.cra_deductions with cra_status Unpaid. Logging a payment stores date paid, amount, payment method, and bank reference on payroll_cra_payments, and marks matching payroll_masters as Paid. It does not send money to CRA.',
            ['cra', 'remittance']
        ),
        $live(
            'payroll_outstanding_remittance.php',
            'What is outstanding remittance?',
            'The page reads stored payroll_finalized columns. It shows what is still owed to employees, the CRA portion (income tax plus CPP and EI on both the employee and the employer side), and WCB on its own because WCB is not part of the CRA remittance. It does not recalculate and it does not file.',
            ['cra', 'remittance', 'wcb']
        ),
        $live(
            'manual_pay_entry.php',
            'When do I use Manual Pay / Adjustments?',
            'Use it for an amount that is not a clocked shift: Adjustment, Benefit, Bonus, Car allowance, Travel, Termination pay, or Other. Include in payroll Yes writes a payroll_finalized line and runs it through the same deduction engine as a finalized shift. Include in payroll No writes a payment voucher only and does not treat the amount as wages. Press Post entry. Normal hours still go through Staff Audit.',
            ['bonus', 'adjustment', 'manual pay']
        ),
        $live(
            'timeoff_requests.php',
            'Where do time-off requests go?',
            'timeoff_requests.php is the admin list of requests. Staff send one from submit_timeoff.php. Approving a request here does not by itself create a pay line.',
            ['time off', 'vacation request']
        ),
        $live(
            'submit_timeoff.php',
            'How does an employee ask for time off?',
            'submit_timeoff.php is the form a signed-in person uses to send a time-off request. It does not change their pay rate or their vacation balance.',
            ['time off']
        ),
        $live(
            'help_center.php',
            'Where is the written help?',
            'Learning Center (help_center.php) is the Help page. Each working screen also has About this page, Video + audio, and Listen, from the shared help panel. Questions & answers sits under that, and is visible to superadmins.',
            ['help']
        ),
        $live(
            'help_qa.php',
            'How do I edit the questions?',
            'Open any payroll page as a superadmin and expand Questions & answers. Add, Edit, Delete, Up, Down, or drag a question onto another one. A save sets edited_in_app, and the seed will not replace that question. This page searches every question. Export JSON and Import JSON use the help_qa column names. Import updates a row when app, page_key, and question match.',
            ['faq', 'help']
        ),
        $live(
            'manage_orgs.php',
            'What is an organization?',
            'An organization is the company boundary. Employees, sites, and payroll rows are tied to organizations.id. An admin is kept inside the organization on their session. A superadmin can switch organization from the menu.',
            ['organization']
        ),
        $live(
            'org_settings.php',
            'What do organization settings change?',
            'Org Settings is the organization\'s own name and subscription-style settings. It does not run payroll and it does not change stored pay lines.',
            ['organization']
        ),
        $live(
            'manage_users.php',
            'Is User Management the same as Employee Hub?',
            'User Management is an admin list of accounts. Rates, sites, SIN, and address used for pay and for the ROE are edited in Employee Hub. The menu hides the old User Management link and points people at Employee Hub.',
            ['employee', 'users']
        ),
        $live(
            'admin_dashboard.php',
            'What is the control panel for?',
            'The admin control panel is the high-level admin home, including creating an organization. Daily pay is done from Staff Audit and Payroll Center, not from this page.',
            ['admin']
        ),
        $live(
            'site_assignment.php',
            'Why would a site disappear after I switch organization?',
            'A site belongs to one organization (sites.org_id). Site Assignment is where that link is set. Employees also need the site in assigned_site_ids or as their primary site before it shows up for them.',
            ['site', 'organization']
        ),
        $live(
            'site_manager.php',
            'What does the geofence radius do?',
            'Site Accuracy & Geofence stores geo_radius_meters for a site. The kiosk uses the distance from the site coordinates against that radius when someone clocks in.',
            ['geofence', 'clock in']
        ),
        $live(
            'payroll_report.php',
            'Do the payroll reports recalculate deductions?',
            'payroll_report.php reads payroll_finalized, the same stored rows as the ledger, and splits hours into regular, overtime, and travel. It does not call the deduction engine again. Changing a rate in settings does not change these rows.',
            ['report', 'overtime']
        ),
        $live(
            'payroll_summary.php',
            'What is the payroll summary?',
            'It totals stored pay for the date range. Someone whose user_level is above 30 is sent away. It does not finalize shifts and it does not file anything.',
            ['report']
        ),
        $live(
            'payroll_reports.php',
            'What does the payroll report suite show?',
            'It is a drill-down of stored payroll_finalized rows: period, then site, then employee, then shift. You can switch the top levels between site and employee. It does not send a file to CRA or to Service Canada.',
            ['report']
        ),
        $live(
            'balance_reports.php',
            'What does a balance report show?',
            'It lists payroll_balance rows for the organization, with the employee name and the site nickname. It does not create a payment.',
            ['balance', 'pay']
        ),
        $live(
            'variance_report.php',
            'What is the variance report?',
            'It lists attendance punches whose worked time, after breaks, is more than the scheduled hours on Gem_Schedules by more than 0.1 hours. It does not change the punches or the pay.',
            ['variance', 'schedule', 'attendance']
        ),
        $live(
            'manager_dashboard.php',
            'What is the manager dashboard?',
            'The page title is Executive Payroll Audit. It is limited to user_level 29 or stronger. It lists attendance for the organization with clock times, hours, GPS distance, gross pay, and total liability. It does not finalize a shift and it does not replace Payroll Center.',
            ['dashboard', 'attendance']
        ),
        $live(
            'daily_report.php',
            'What is the admin daily report?',
            'Daily Report lists attendance rows: clock in, clock out, the employee name, official rate, vacation percent, and the site nickname. Filters are date, user, and site. It does not finalize a shift.',
            ['daily report']
        ),
        $live(
            'daily_report_view.php',
            'What is the daily report view?',
            'It records daily sales for a site and can attach a POS file (PDF or image) for the day, the week, or the month. Saving a day here does not finalize payroll.',
            ['daily report']
        ),
        $live(
            'business_hub.php',
            'What does Business Hub edit?',
            'Business Hub edits a site\'s nickname and address for the organization you select. The CRA payroll account used for an ROE is sites.payroll_account, edited with the site, not on this nickname form.',
            ['site', 'address']
        ),
        $live(
            'food_cost_view.php',
            'What is the food cost view?',
            'It is the food-cost report for a site: invoices and cost against sales. It does not pay staff.',
            ['food cost', 'inventory']
        ),
        $live(
            'inventory/index.php',
            'What is the inventory home?',
            'Inventory home is the site\'s stock starting point: deliveries, counts, and the links to items, orders, and usage. It is separate from payroll.',
            ['inventory']
        ),
        $live(
            'inventory/audit.php',
            'What does an inventory audit compare?',
            'Inventory audit is the count-to-count view for a site: last count, usage, and the latest count. It does not post payroll.',
            ['inventory', 'audit']
        ),
        $live(
            'inventory/physical_count.php',
            'How do I enter a physical count?',
            'Open Physical count for the site, enter the counted quantities, and save. That updates inventory quantities. It does not change employee hours.',
            ['inventory', 'count']
        ),
        $live(
            'inventory/orders.php',
            'What is an inventory order?',
            'Orders is the purchasing list for a site. Receiving an order updates stock. It is not a payroll order.',
            ['inventory', 'orders']
        ),
        $live(
            'inventory/usage.php',
            'What is inventory usage?',
            'Usage records how much of an item the site used. It is an inventory quantity, not hours worked.',
            ['inventory', 'usage']
        ),
        $live(
            'inventory/manage_items.php',
            'Where do I add an inventory item?',
            'Manage items is the item master for the organization: names and units used by orders, counts, and usage.',
            ['inventory', 'items']
        ),
        $live(
            'inventory/waste.php',
            'What is wastage?',
            'Wastage shows inventory loss recorded for the site. It does not reduce anyone\'s pay.',
            ['inventory', 'waste']
        ),
        $live(
            'inventory/inventory_report.php',
            'What is the inventory status report?',
            'It is a snapshot of on-hand quantities for the site. It reads the saved inventory figures.',
            ['inventory', 'report']
        ),
    ];
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && basename($argv[0]) === basename(__FILE__)) {
    $rows = help_qa_seed_rows();
    $sql = help_qa_seed_sql($rows);
    $md = help_qa_seed_markdown($rows);
    $root = dirname(__DIR__);
    file_put_contents($root . '/migrations/20261120_help_qa.sql', $sql);
    file_put_contents($root . '/docs/FAQ_PAYROLL.md', $md);
    $counts = [];
    $hidden = [];
    foreach ($rows as $row) {
        $counts[$row['page_key']] = ($counts[$row['page_key']] ?? 0) + 1;
        if ($row['status'] !== 'live' || $row['requires_feature']) {
            $hidden[] = $row['page_key'] . ' | ' . $row['requires_feature'] . ' | ' . $row['question'];
        }
    }
    echo 'rows ' . count($rows) . PHP_EOL;
    foreach ($counts as $page => $n) {
        echo $n . ' ' . $page . PHP_EOL;
    }
    echo "HIDDEN\n" . implode("\n", $hidden) . PHP_EOL;
}

function help_qa_sql_string(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function help_qa_seed_sql(array $rows): string
{
    $order = [];
    $lines = [];
    $lines[] = '-- Questions & answers for payroll pages.';
    $lines[] = '-- Safe to run more than once. Inserts a question only when (app, page_key, question)';
    $lines[] = '-- is not already stored, so a question edited in the app is left alone.';
    $lines[] = '-- Run on the payroll database after a backup. This file does not deploy by itself.';
    $lines[] = '-- Generated from engine/help_qa_seed.php. Hidden rows stay hidden until';
    $lines[] = '-- FAQ_T4127_LIVE or FAQ_QUICK_ENTRY_LIVE is turned on in PHP.';
    $lines[] = '';
    $lines[] = 'CREATE TABLE IF NOT EXISTS help_qa (';
    $lines[] = '  id INT AUTO_INCREMENT PRIMARY KEY,';
    $lines[] = "  app VARCHAR(32) NOT NULL DEFAULT 'payroll',";
    $lines[] = '  page_key VARCHAR(191) NOT NULL,';
    $lines[] = '  question VARCHAR(500) NOT NULL,';
    $lines[] = '  answer TEXT NOT NULL,';
    $lines[] = '  sort_order INT NOT NULL DEFAULT 0,';
    $lines[] = "  status ENUM('live','hidden') NOT NULL DEFAULT 'live',";
    $lines[] = '  requires_feature VARCHAR(64) NULL,';
    $lines[] = '  visible_roles VARCHAR(255) NOT NULL DEFAULT \'["superadmin"]\',';
    $lines[] = '  keywords TEXT NULL,';
    $lines[] = '  org_id INT NULL,';
    $lines[] = '  updated_by VARCHAR(191) NULL,';
    $lines[] = '  edited_in_app TINYINT NOT NULL DEFAULT 0,';
    $lines[] = '  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,';
    $lines[] = '  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,';
    $lines[] = '  KEY idx_app_page (app, page_key, sort_order)';
    $lines[] = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';
    $lines[] = '';
    foreach ($rows as $row) {
        $page = $row['page_key'];
        $order[$page] = ($order[$page] ?? 0) + 10;
        $keywords = $row['keywords'] ? json_encode(array_values($row['keywords']), JSON_UNESCAPED_UNICODE) : null;
        $feature = $row['requires_feature'];
        $lines[] = 'INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)';
        $lines[] = 'SELECT \'payroll\', ' . help_qa_sql_string($page) . ', ' . help_qa_sql_string($row['question']) . ', ' . help_qa_sql_string($row['answer']) . ', '
            . (int)$order[$page] . ', ' . help_qa_sql_string($row['status']) . ', '
            . ($feature ? help_qa_sql_string($feature) : 'NULL') . ', '
            . help_qa_sql_string('["superadmin"]') . ', '
            . ($keywords !== null ? help_qa_sql_string($keywords) : 'NULL') . ', NULL, NULL, 0';
        $lines[] = 'WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = \'payroll\' AND page_key = ' . help_qa_sql_string($page) . ' AND question = ' . help_qa_sql_string($row['question']) . ');';
        $lines[] = '';
    }
    return implode("\n", $lines) . "\n";
}

function help_qa_seed_markdown(array $rows): string
{
    $byPage = [];
    foreach ($rows as $row) {
        $byPage[$row['page_key']][] = $row;
    }
    $out = [];
    $out[] = '# Payroll questions and answers';
    $out[] = '';
    $out[] = 'Generated from `engine/help_qa_seed.php` for Marketing to merge with the ERP and Bookkeeping sections. The same rows are in `migrations/20261120_help_qa.sql`.';
    $out[] = '';
    $out[] = 'Every answer describes the payroll code on this branch. Rows marked hidden are not shown in the app unless the matching feature constant is turned on. `FAQ_T4127_LIVE` and `FAQ_QUICK_ENTRY_LIVE` default to false. The T4127 engine and automatic statutory holiday pay are in this repository and are not what the live site is running until that deploy. There is no quick hours entry screen.';
    $out[] = '';
    $out[] = 'In the app, a superadmin opens Questions & answers on a page. The searchable list is `help_qa.php`.';
    $out[] = '';
    foreach ($byPage as $page => $items) {
        $out[] = '## ' . $page;
        $out[] = '';
        foreach ($items as $row) {
            $flags = [];
            if ($row['status'] !== 'live') {
                $flags[] = 'status: ' . $row['status'];
            }
            if ($row['requires_feature']) {
                $flags[] = 'requires_feature: ' . $row['requires_feature'];
            }
            $out[] = '### ' . $row['question'];
            $out[] = '';
            if ($flags) {
                $out[] = '(' . implode('; ', $flags) . ')';
                $out[] = '';
            }
            $out[] = $row['answer'];
            $out[] = '';
        }
    }
    return implode("\n", $out);
}
