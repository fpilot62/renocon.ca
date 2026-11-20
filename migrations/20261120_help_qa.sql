-- Questions & answers for payroll pages.
-- Safe to run more than once. Inserts a question only when (app, page_key, question)
-- is not already stored, so a question edited in the app is left alone.
-- Run on the payroll database after a backup. This file does not deploy by itself.
-- Generated from engine/help_qa_seed.php. Hidden rows stay hidden until
-- FAQ_T4127_LIVE or FAQ_QUICK_ENTRY_LIVE is turned on in PHP.

CREATE TABLE IF NOT EXISTS help_qa (
  id INT AUTO_INCREMENT PRIMARY KEY,
  app VARCHAR(32) NOT NULL DEFAULT 'payroll',
  page_key VARCHAR(191) NOT NULL,
  question VARCHAR(500) NOT NULL,
  answer TEXT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  status ENUM('live','hidden') NOT NULL DEFAULT 'live',
  requires_feature VARCHAR(64) NULL,
  visible_roles VARCHAR(255) NOT NULL DEFAULT '["superadmin"]',
  keywords TEXT NULL,
  org_id INT NULL,
  updated_by VARCHAR(191) NULL,
  edited_in_app TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_app_page (app, page_key, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'employee_hub.php', 'Where do I add or change an employee?', 'Employee Hub. Open a row to edit, or use Add staff. The save writes users.Name, email, user, pin, hourly_rate, official_rate, user_role, active, assigned_site_ids, primary_restaurant_id, Remarks, org_id, sin, given_name, surname, street_address, city, province, postal_code, dental_benefit_code, ei_exempt, wsib_exempt, vac_pay_exempt, employee_type, Tel_Mobile, and Tel. The form labels for the name and address are Given name, Surname, Street address, City, Province, Postal code, and SIN. An admin whose level is above 10 is kept on their own organization. A superadmin can change org_filter.', 10, 'live', NULL, '["superadmin"]', '["employee","sin","address","given name"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'employee_hub.php' AND question = 'Where do I add or change an employee?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'employee_hub.php', 'Who can see a SIN or bank account?', 'The SIN is stored on users.sin (or SIN, social_insurance_number, social_insurance, sin_number, whichever column exists). Employee Hub shows the SIN in the edit form for an admin of that organization. The ROE screen never prints the full SIN; it masks it and reads the plain value only when it checks or exports an ROE. A platform superadmin needs that organization''s active support grant before the ROE page will open, because the export carries SINs. Bank transit, institution, and account are users.bank_transit, users.bank_institution, and users.bank_account. They are used on the direct deposit file.', 20, 'live', NULL, '["superadmin"]', '["privacy","sin","bank"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'employee_hub.php' AND question = 'Who can see a SIN or bank account?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'rate_change.php', 'How do I change a pay rate?', 'Rate Change records a new hourly rate, official rate, or transit rate with an effective date. Finalize uses the rate in effect on the shift''s work date. Employee Hub also writes user_rate_history when the hourly rate or the official rate changes on save. The boxes on that form are Hourly rate and Official rate, plus Effective date.', 10, 'live', NULL, '["superadmin"]', '["pay rate","hourly","effective date"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'rate_change.php' AND question = 'How do I change a pay rate?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'kiosk.php', 'How does an employee clock in?', 'On the kiosk the button is Clock In. It calls the punch action clock_in. If the organization blocks off-schedule punches, and the person is outside the schedule margin, the button is Clock In blocked. Otherwise an off-schedule punch can ask for a reason. There is no separate quick hours entry screen.', 10, 'live', NULL, '["superadmin"]', '["clock in","kiosk","hours"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'kiosk.php' AND question = 'How does an employee clock in?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'staff_audit.php', 'How do hours become a pay line?', 'On Staff Audit, set the filters and press Search. Status Type Pending is the work that is not finalized. Tick the shifts and press Finalize Selected. In the Bulk Pay Allocation Desk the dropdown is Overtime Policy. The choices are Per-shift daily cap, Work-site policy (the label says ON weekly 44h and SK daily 8h + weekly 40h, set in Edit Site), Weekly 44 hours (Ontario default), Weekly 40 hours, Daily 8h + Weekly 44h, and Daily 8h + Weekly 40h (Saskatchewan default). Then press Execute Pay Matrix Process. That writes payroll_finalized. The page does not pay the employee; Payroll Center does.', 10, 'live', NULL, '["superadmin"]', '["hours","finalize","overtime"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'staff_audit.php' AND question = 'How do hours become a pay line?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'staff_audit.php', 'Is there a quick hours entry screen?', 'No. This payroll app does not have a quick hours entry page. Hours come from Clock In on the kiosk, from Manual attendance, from Staff Audit, or from a CSV or an Excel paste on Bulk import.', 20, 'hidden', 'quick_entry', '["superadmin"]', '["quick entry","hours"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'staff_audit.php' AND question = 'Is there a quick hours entry screen?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'manual_attendance.php', 'Can an admin enter a shift that was not clocked?', 'Yes. Manual attendance is limited to payroll admins. The saved shift is marked Manually Injected Shift. The employee and the site have to belong to the same organization, and that organization has to be one the admin is allowed to manage.', 10, 'live', NULL, '["superadmin"]', '["manual attendance","hours"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'manual_attendance.php' AND question = 'Can an admin enter a shift that was not clocked?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'bulk_importer.php', 'How do I import hours from Excel or a CSV?', 'Bulk import is limited to admins (role 20 or stronger). Choose a CSV file, or paste rows from Excel or Google Sheets. Hours can be punches or RegularHours. If the headers do not match the template, use Match columns before import. The employee file uses StartDate. It does not take a vacation percent; vacation is worked out from the start date and the site province when pay is finalized.', 10, 'live', NULL, '["superadmin"]', '["csv","excel","import","hours"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'bulk_importer.php' AND question = 'How do I import hours from Excel or a CSV?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'vacation_service_admin.php', 'How is vacation pay worked out?', 'The rate comes from the work site''s province and from employee_company_service for that employee and that site. Saskatchewan is 3/52 until 10 years of service, then 4/52. Every other province uses 4% until 5 years and 6% after that, because the function only has a special case for SK. If vac_percent on employee_company_service is above 0, that percent is used instead. When the organization pays vacation on each cheque, the percent is added to the pay and is included in CPP, EI, and WCB. Otherwise it is accrued on the employer side and is not added to the cheque.', 10, 'live', NULL, '["superadmin"]', '["vacation","province"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'vacation_service_admin.php' AND question = 'How is vacation pay worked out?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'payroll_master_ledger.php', 'How are CPP, EI, and income tax calculated on pay that is running today?', 'Finalize stores the deductions. Payroll Center only reads them. The engine that is running in production multiplies the pay by the rates in payroll_settings: cpp_rate, ei_rate, fed_tax_rate, wcb_rate, and ei_employer_factor. If a rate is missing, that engine uses CPP 5.95%, EI 1.63%, income tax 15%, WCB 2%, and employer EI of 1.4 times the employee EI. Employer CPP matches the employee CPP. Set organizations.withhold_income_tax to 0 to store income tax as 0. users.employee_type of contract stores CPP and EI as 0. users.ei_exempt of 1 stores EI as 0 and leaves CPP. CPP for the period is stored once on payroll_period_stat. The shift rows keep cpp at 0 so the basic exemption is not taken on every shift. Changing a rate later does not rewrite rows already stored.', 10, 'live', NULL, '["superadmin"]', '["cpp","ei","tax","deductions"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'payroll_master_ledger.php' AND question = 'How are CPP, EI, and income tax calculated on pay that is running today?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'payroll_master_ledger.php', 'How does the CRA T4127 engine calculate deductions?', 'In this code, pe_line calls the T4127 calculator when it is given a database connection and a rate pack exists for the pay date. It calculates federal tax, provincial tax for British Columbia, Ontario, and Saskatchewan, CPP, CPP2, and EI from the edition for that pay date. Pay dates from 1 January 2026 through 30 June 2026 use the 122nd edition. Pay dates on and after 1 July 2026 use the 123rd edition, which changes British Columbia''s lowest rate and basic reduction only. Employer CPP and CPP2 match the employee. Employer EI is 1.4 times the employee premium, kept to the yearly maximum. A province with no loaded table gets federal tax, CPP, and EI, and the result says provincial tax was not calculated. Quebec provincial tax and the QPP are not calculated. WSIB, WorkSafeBC, or Saskatchewan WCB uses sites.wsib_percent, where 1.23 means 1.23 percent. A blank percent keeps payroll_settings.wcb_rate. Ontario EHT is estimated on the line and the year figure is payroll_eht_report.php. That report does not file a return. This calculator is in the repository and is not what the live site runs until it is deployed. Do not file from numbers stored before a line has deduction_edition.', 20, 'hidden', 't4127', '["superadmin"]', '["t4127","cpp","tax","bc","ontario","saskatchewan"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'payroll_master_ledger.php' AND question = 'How does the CRA T4127 engine calculate deductions?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'payroll_master_ledger.php', 'How do I print a pay stub or a cheque?', 'On Payroll Center, tick the rows, then use Print cheques or Print paystubs. They are separate buttons. Email paystubs sends each person their own stub. Print cheques asks for blank paper or pre-printed stock. Pre-printed stock prints the date, name, amount, and memo and leaves the stub out. The amounts are the ones stored at finalize.', 30, 'live', NULL, '["superadmin"]', '["pay stub","cheque","paystub"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'payroll_master_ledger.php' AND question = 'How do I print a pay stub or a cheque?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'payroll_master_ledger.php', 'How do I make a direct deposit file?', 'Tick the rows and press Direct deposit file. That posts to direct_deposit_file.php, which writes a Payments Canada Standard 005 file of 1464-byte records. It uses the site bank profile and the employee fields users.bank_institution, users.bank_transit, and users.bank_account. The row checkbox is labelled Direct deposit. The file is a download. This screen does not send it to the bank.', 40, 'live', NULL, '["superadmin"]', '["direct deposit","eft","bank"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'payroll_master_ledger.php' AND question = 'How do I make a direct deposit file?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'generate_stat_shifts.php', 'How do I post statutory holiday pay from this screen?', 'Preview does not post anything. For a pay period, set Period start and Period end and press Preview all holidays in period, then approve the ticked rows. For one holiday, press Preview this holiday, tick the rows, and press Approve & post selected. The holiday list for the organization is built from sites whose province is ON or SK. Ontario pay on this screen is regular wages plus vacation pay in the four work weeks before the holiday week, divided by 20. Saskatchewan is 5 percent of wages, excluding the overtime premium, in the 28 days before the holiday. A holiday that was already posted is skipped.', 10, 'live', NULL, '["superadmin"]', '["stat holiday","ontario","saskatchewan"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'generate_stat_shifts.php' AND question = 'How do I post statutory holiday pay from this screen?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'generate_stat_shifts.php', 'Does finalize post statutory holiday pay by itself?', 'The code on this branch calls sh_after_finalize when hours are finalized, for a site in British Columbia, Ontario, or Saskatchewan. It posts one line per holiday in that pay period for an eligible employee, and calling it again does not add a second line for the same employee, site, and holiday. British Columbia uses 30 calendar days of employment and wages on at least 15 of the 30 days before the holiday. Ontario uses the four-work-week formula divided by 20. Saskatchewan uses 5 percent of wages in the 28 days before the holiday. This automatic post is part of the same undeployed change as the T4127 engine. Until that deploy, use Statutory Holiday Pay and press Approve.', 20, 'hidden', 't4127', '["superadmin"]', '["stat holiday","british columbia","automatic"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'generate_stat_shifts.php' AND question = 'Does finalize post statutory holiday pay by itself?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'generate_t4.php', 'What does the T4 page produce?', 'Tax & T4 Center sums stored finalized payroll into a worksheet: Box 14 employment income, Box 22 income tax, Box 16 CPP, and Box 18 EI. It reads what was stored. It does not recalculate, and it does not file with CRA.', 10, 'live', NULL, '["superadmin"]', '["t4","tax"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'generate_t4.php' AND question = 'What does the T4 page produce?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'tax_hub.php', 'Is the tax hub a CRA filing?', 'No. Tax & Compliance Hub is the place to pick a year and a site and then open the T4 worksheet or the CRA pages. It does not file a return.', 10, 'live', NULL, '["superadmin"]', '["t4","cra"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'tax_hub.php' AND question = 'Is the tax hub a CRA filing?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'roe.php', 'How do I create an ROE in this app?', 'Open ROE (Record of Employment). Under Legal employers, set Pay period type, Known period end, Contact first, Contact last, Area, Phone, and Ext, then press Save. Under New ROE choose Employee, Legal employer (BN15), Reason (block 16), and optionally Last day for which paid, then press Create draft from pay lines. On the draft, set Block 14 expected recall, fix anything listed in red, and press Mark ready to download. Back on the list, tick the ready rows. Leave Issue = D: ROE Web saves as draft (review there), or choose Issue = S: ROE Web submits on upload. Press Download selected ready ROEs (.BLK). One business number downloads as a .BLK file. Several business numbers download as a zip with one .BLK each. Nothing is sent to Service Canada from this app.', 10, 'live', NULL, '["superadmin"]', '["roe","blk","reason"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'roe.php' AND question = 'How do I create an ROE in this app?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'roe_edit.php', 'What do I set on the ROE draft before I download it?', 'The draft page is ROE # with Edit. Block 14 expected recall is U – Unknown, N – Not returning, or Y – Expected date of recall. Y needs Recall date (only for Y), and that date has to be after Block 11. Press Save draft, then Mark ready to download when the red list is empty. Download this ROE (.BLK) uses the same Issue = D or Issue = S choice. After ROE Web issues it, type the serial number and press Record as issued. An issued ROE is changed with Start an amendment (block 2 = the serial).', 10, 'live', NULL, '["superadmin"]', '["roe","recall","amend"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'roe_edit.php' AND question = 'What do I set on the ROE draft before I download it?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'roe_help.php', 'Where is the written procedure for filing an ROE?', 'How to file an ROE is roe_help.php. It is for payroll admins, same as the ROE screen. The employee picker on that page checks the columns this ROE code actually reads and links to Employee Hub to edit the person.', 10, 'live', NULL, '["superadmin"]', '["roe","help"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'roe_help.php' AND question = 'Where is the written procedure for filing an ROE?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'manage_sites.php', 'Which provinces does payroll use?', 'The work site''s province (sites.province) is what vacation and overtime look at. Edit Site lists every Canadian province, but the pay rules in code are narrower. If the site has no province rule, overtime falls back to Ontario: weekly over 44 hours at 1.5 times. A site can store its own ot_weekly_hours and ot_daily_hours. Vacation uses the Saskatchewan 3/52 and 4/52 rates only when the province is SK. Every other province gets 4% and then 6% at five years, unless a vacation override is stored. Statutory holiday dates in code exist for British Columbia, Ontario, and Saskatchewan. The holiday screen''s organization list starts from ON and SK sites. Quebec tax and the QPP are not calculated.', 10, 'live', NULL, '["superadmin"]', '["province","ontario","saskatchewan","british columbia"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'manage_sites.php' AND question = 'Which provinces does payroll use?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'manage_sites.php', 'Which provinces does the T4127 tax engine support?', 'The T4127 rate files loaded in this repository have tables for British Columbia, Ontario, and Saskatchewan, plus federal tax, CPP, and EI. A site in another province still gets federal tax, CPP, and EI, and the result says provincial tax was not calculated. Quebec and the territories are not in the tables. This engine is not what the live site runs until it is deployed.', 20, 'hidden', 't4127', '["superadmin"]', '["t4127","province","tax"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'manage_sites.php' AND question = 'Which provinces does the T4127 tax engine support?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'role_manager.php', 'Who can see a menu item or a payroll page?', 'Menu Manager stores Min. Role as UserRole. A person sees a link when that number is greater than or equal to their user_level, and the link is active. The role numbers are 10 Superadmin, 20 Admin, 25 Site Manager, 30 Supervisor, 35 Demo, 40 Staff, and 1000 Alarm. A smaller number can see everything a larger number can see. Payroll screens that call the payroll admin check refuse anyone whose user_level is 30 or higher. The Questions & answers panel uses the same names in visible_roles. Right now every seeded answer is limited to superadmin.', 10, 'live', NULL, '["superadmin"]', '["roles","permissions","admin"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'role_manager.php' AND question = 'Who can see a menu item or a payroll page?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'payroll_cra_payments.php', 'Does the CRA page file a remittance?', 'No. payroll_cra_payments.php is the CRA tax remittance register for the organization. The amounts come from payroll_masters.cra_deductions with cra_status Unpaid. Logging a payment stores date paid, amount, payment method, and bank reference on payroll_cra_payments, and marks matching payroll_masters as Paid. It does not send money to CRA.', 10, 'live', NULL, '["superadmin"]', '["cra","remittance"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'payroll_cra_payments.php' AND question = 'Does the CRA page file a remittance?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'payroll_outstanding_remittance.php', 'What is outstanding remittance?', 'The page reads stored payroll_finalized columns. It shows what is still owed to employees, the CRA portion (income tax plus CPP and EI on both the employee and the employer side), and WCB on its own because WCB is not part of the CRA remittance. It does not recalculate and it does not file.', 10, 'live', NULL, '["superadmin"]', '["cra","remittance","wcb"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'payroll_outstanding_remittance.php' AND question = 'What is outstanding remittance?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'manual_pay_entry.php', 'When do I use Manual Pay / Adjustments?', 'Use it for an amount that is not a clocked shift: Adjustment, Benefit, Bonus, Car allowance, Travel, Termination pay, or Other. Include in payroll Yes writes a payroll_finalized line and runs it through the same deduction engine as a finalized shift. Include in payroll No writes a payment voucher only and does not treat the amount as wages. Press Post entry. Normal hours still go through Staff Audit.', 10, 'live', NULL, '["superadmin"]', '["bonus","adjustment","manual pay"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'manual_pay_entry.php' AND question = 'When do I use Manual Pay / Adjustments?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'timeoff_requests.php', 'Where do time-off requests go?', 'timeoff_requests.php is the admin list of requests. Staff send one from submit_timeoff.php. Approving a request here does not by itself create a pay line.', 10, 'live', NULL, '["superadmin"]', '["time off","vacation request"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'timeoff_requests.php' AND question = 'Where do time-off requests go?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'submit_timeoff.php', 'How does an employee ask for time off?', 'submit_timeoff.php is the form a signed-in person uses to send a time-off request. It does not change their pay rate or their vacation balance.', 10, 'live', NULL, '["superadmin"]', '["time off"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'submit_timeoff.php' AND question = 'How does an employee ask for time off?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'help_center.php', 'Where is the written help?', 'Learning Center (help_center.php) is the Help page. Each working screen also has About this page, Video + audio, and Listen, from the shared help panel. Questions & answers sits under that, and is visible to superadmins.', 10, 'live', NULL, '["superadmin"]', '["help"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'help_center.php' AND question = 'Where is the written help?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'help_qa.php', 'How do I edit the questions?', 'Open any payroll page as a superadmin and expand Questions & answers. Add, Edit, Delete, Up, Down, or drag a question onto another one. A save sets edited_in_app, and the seed will not replace that question. This page searches every question. Export JSON and Import JSON use the help_qa column names. Import updates a row when app, page_key, and question match.', 10, 'live', NULL, '["superadmin"]', '["faq","help"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'help_qa.php' AND question = 'How do I edit the questions?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'manage_orgs.php', 'What is an organization?', 'An organization is the company boundary. Employees, sites, and payroll rows are tied to organizations.id. An admin is kept inside the organization on their session. A superadmin can switch organization from the menu.', 10, 'live', NULL, '["superadmin"]', '["organization"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'manage_orgs.php' AND question = 'What is an organization?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'org_settings.php', 'What do organization settings change?', 'Org Settings is the organization''s own name and subscription-style settings. It does not run payroll and it does not change stored pay lines.', 10, 'live', NULL, '["superadmin"]', '["organization"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'org_settings.php' AND question = 'What do organization settings change?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'manage_users.php', 'Is User Management the same as Employee Hub?', 'User Management is an admin list of accounts. Rates, sites, SIN, and address used for pay and for the ROE are edited in Employee Hub. The menu hides the old User Management link and points people at Employee Hub.', 10, 'live', NULL, '["superadmin"]', '["employee","users"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'manage_users.php' AND question = 'Is User Management the same as Employee Hub?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'admin_dashboard.php', 'What is the control panel for?', 'The admin control panel is the high-level admin home, including creating an organization. Daily pay is done from Staff Audit and Payroll Center, not from this page.', 10, 'live', NULL, '["superadmin"]', '["admin"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'admin_dashboard.php' AND question = 'What is the control panel for?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'site_assignment.php', 'Why would a site disappear after I switch organization?', 'A site belongs to one organization (sites.org_id). Site Assignment is where that link is set. Employees also need the site in assigned_site_ids or as their primary site before it shows up for them.', 10, 'live', NULL, '["superadmin"]', '["site","organization"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'site_assignment.php' AND question = 'Why would a site disappear after I switch organization?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'site_manager.php', 'What does the geofence radius do?', 'Site Accuracy & Geofence stores geo_radius_meters for a site. The kiosk uses the distance from the site coordinates against that radius when someone clocks in.', 10, 'live', NULL, '["superadmin"]', '["geofence","clock in"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'site_manager.php' AND question = 'What does the geofence radius do?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'payroll_report.php', 'Do the payroll reports recalculate deductions?', 'payroll_report.php reads payroll_finalized, the same stored rows as the ledger, and splits hours into regular, overtime, and travel. It does not call the deduction engine again. Changing a rate in settings does not change these rows.', 10, 'live', NULL, '["superadmin"]', '["report","overtime"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'payroll_report.php' AND question = 'Do the payroll reports recalculate deductions?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'payroll_summary.php', 'What is the payroll summary?', 'It totals stored pay for the date range. Someone whose user_level is above 30 is sent away. It does not finalize shifts and it does not file anything.', 10, 'live', NULL, '["superadmin"]', '["report"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'payroll_summary.php' AND question = 'What is the payroll summary?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'payroll_reports.php', 'What does the payroll report suite show?', 'It is a drill-down of stored payroll_finalized rows: period, then site, then employee, then shift. You can switch the top levels between site and employee. It does not send a file to CRA or to Service Canada.', 10, 'live', NULL, '["superadmin"]', '["report"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'payroll_reports.php' AND question = 'What does the payroll report suite show?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'balance_reports.php', 'What does a balance report show?', 'It lists payroll_balance rows for the organization, with the employee name and the site nickname. It does not create a payment.', 10, 'live', NULL, '["superadmin"]', '["balance","pay"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'balance_reports.php' AND question = 'What does a balance report show?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'variance_report.php', 'What is the variance report?', 'It lists attendance punches whose worked time, after breaks, is more than the scheduled hours on Gem_Schedules by more than 0.1 hours. It does not change the punches or the pay.', 10, 'live', NULL, '["superadmin"]', '["variance","schedule","attendance"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'variance_report.php' AND question = 'What is the variance report?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'manager_dashboard.php', 'What is the manager dashboard?', 'The page title is Executive Payroll Audit. It is limited to user_level 29 or stronger. It lists attendance for the organization with clock times, hours, GPS distance, gross pay, and total liability. It does not finalize a shift and it does not replace Payroll Center.', 10, 'live', NULL, '["superadmin"]', '["dashboard","attendance"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'manager_dashboard.php' AND question = 'What is the manager dashboard?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'daily_report.php', 'What is the admin daily report?', 'Daily Report lists attendance rows: clock in, clock out, the employee name, official rate, vacation percent, and the site nickname. Filters are date, user, and site. It does not finalize a shift.', 10, 'live', NULL, '["superadmin"]', '["daily report"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'daily_report.php' AND question = 'What is the admin daily report?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'daily_report_view.php', 'What is the daily report view?', 'It records daily sales for a site and can attach a POS file (PDF or image) for the day, the week, or the month. Saving a day here does not finalize payroll.', 10, 'live', NULL, '["superadmin"]', '["daily report"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'daily_report_view.php' AND question = 'What is the daily report view?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'business_hub.php', 'What does Business Hub edit?', 'Business Hub edits a site''s nickname and address for the organization you select. The CRA payroll account used for an ROE is sites.payroll_account, edited with the site, not on this nickname form.', 10, 'live', NULL, '["superadmin"]', '["site","address"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'business_hub.php' AND question = 'What does Business Hub edit?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'food_cost_view.php', 'What is the food cost view?', 'It is the food-cost report for a site: invoices and cost against sales. It does not pay staff.', 10, 'live', NULL, '["superadmin"]', '["food cost","inventory"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'food_cost_view.php' AND question = 'What is the food cost view?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'inventory/index.php', 'What is the inventory home?', 'Inventory home is the site''s stock starting point: deliveries, counts, and the links to items, orders, and usage. It is separate from payroll.', 10, 'live', NULL, '["superadmin"]', '["inventory"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'inventory/index.php' AND question = 'What is the inventory home?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'inventory/audit.php', 'What does an inventory audit compare?', 'Inventory audit is the count-to-count view for a site: last count, usage, and the latest count. It does not post payroll.', 10, 'live', NULL, '["superadmin"]', '["inventory","audit"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'inventory/audit.php' AND question = 'What does an inventory audit compare?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'inventory/physical_count.php', 'How do I enter a physical count?', 'Open Physical count for the site, enter the counted quantities, and save. That updates inventory quantities. It does not change employee hours.', 10, 'live', NULL, '["superadmin"]', '["inventory","count"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'inventory/physical_count.php' AND question = 'How do I enter a physical count?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'inventory/orders.php', 'What is an inventory order?', 'Orders is the purchasing list for a site. Receiving an order updates stock. It is not a payroll order.', 10, 'live', NULL, '["superadmin"]', '["inventory","orders"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'inventory/orders.php' AND question = 'What is an inventory order?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'inventory/usage.php', 'What is inventory usage?', 'Usage records how much of an item the site used. It is an inventory quantity, not hours worked.', 10, 'live', NULL, '["superadmin"]', '["inventory","usage"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'inventory/usage.php' AND question = 'What is inventory usage?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'inventory/manage_items.php', 'Where do I add an inventory item?', 'Manage items is the item master for the organization: names and units used by orders, counts, and usage.', 10, 'live', NULL, '["superadmin"]', '["inventory","items"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'inventory/manage_items.php' AND question = 'Where do I add an inventory item?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'inventory/waste.php', 'What is wastage?', 'Wastage shows inventory loss recorded for the site. It does not reduce anyone''s pay.', 10, 'live', NULL, '["superadmin"]', '["inventory","waste"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'inventory/waste.php' AND question = 'What is wastage?');

INSERT INTO help_qa (app, page_key, question, answer, sort_order, status, requires_feature, visible_roles, keywords, org_id, updated_by, edited_in_app)
SELECT 'payroll', 'inventory/inventory_report.php', 'What is the inventory status report?', 'It is a snapshot of on-hand quantities for the site. It reads the saved inventory figures.', 10, 'live', NULL, '["superadmin"]', '["inventory","report"]', NULL, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM help_qa WHERE app = 'payroll' AND page_key = 'inventory/inventory_report.php' AND question = 'What is the inventory status report?');

