# Source deductions (CRA T4127)

The payroll engine in `payroll_engine/` calculates federal and provincial tax, CPP, CPP2, EI, Ontario EHT, and WSIB/WCB for a pay date. It replaces the old flat percentage on `payroll_settings` (income tax was gross times `fed_tax_rate`, often 15%). Do not file or remit from numbers that were stored before a line carries a `deduction_edition`.

Pay dates from January 1, 2026 through June 30, 2026 use the 122nd edition. Pay dates on and after July 1, 2026 use the 123rd edition. In 2026 the July change is British Columbia only: the Option 1 lowest rate becomes 6.14% and the basic reduction used for the rest of the year is $805. Ontario, Saskatchewan, CPP, and EI do not change in July 2026.

The Payroll Deductions Online Calculator could not be called from the environment that built this. The test file `tests/source_deductions_test.php` compares the engine with amounts worked from the T4127 formulas, including the bonus example printed in chapter 4 of the 122nd edition. Those amounts match to the cent. The guide allows rounding tax to the nearest cent or the nearest five cents. This engine rounds to the cent. A calculator that rounds to the nickel can differ by a few cents, which is inside the $0.05 check.

## What a pay uses

- Province of employment is the site's `province` (`BC`, `ON`, `SK`, or the full name). One organization can have stores in more than one province. Each line uses its own store.
- Pay periods per year come from the organization's period (weekly 52 or 53, bi-weekly 26 or 27, semi-monthly 24, monthly 12). The count is the number of period-end dates in that calendar year.
- A blank federal TD1 uses the income-tested basic personal amount (BPAF). A blank provincial TD1 uses that province's basic amount. A number already entered is used as the total claim, including a claim of zero. `td1_tax_exempt` is claim code E: tax is zero, except the Ontario Health Premium, which still applies.
- Vacation paid every period (the organization `vacation_payout_mode` is `pay_each_period`) is part of that period's pensionable, insurable, and taxable pay. Vacation that is only accrued is recorded and is not deducted until it is paid. A one-time vacation payout or bonus is factor B and uses the T4127 bonus method.
- CPP has the $3,500 basic exemption prorated by the pay periods in the year, an annual maximum, and CPP2 between the year's maximum pensionable earnings and the second ceiling. Employer CPP and CPP2 match the employee. EI stops at the maximum insurable earnings. Employer EI is 1.4 times the employee premium.
- WSIB (Ontario), WorkSafeBC, and the Saskatchewan WCB use the site's `wsib_percent` and the province's maximum assessable earnings. `1.23` means 1.23 percent. The rate is never taken from the statute, because it belongs to the employer. `users.wsib_exempt` skips the person. A blank percent stores a zero premium.
- Ontario EHT uses the statutory tiers and this organization's exemption share. The annual report is `payroll_eht_report.php`. It does not file the return. The per-line EHT is the extra tax caused by that pay given what is already stored. The report, not the sum of the line estimates, is the figure to use at year end.

Quebec provincial tax and the QPP are not calculated. A province without a loaded table gets federal tax, CPP, and EI, and the result says provincial tax was not calculated.

## How to update rates in January and July

Rates live in `payroll_engine/rates/YYYY-MM-DD.php` and, after seeding, in `payroll_rate_packs`. The calculator does not contain the dollar amounts.

1. Read the new T4127 edition (122nd was January 1, 2026; 123rd was July 1, 2026). A July edition often changes only one province and tells you to keep the January edition for everything it does not reprint.
2. Add a file `payroll_engine/rates/YYYY-MM-DD.php` whose `effective_from` is the first pay date the edition applies to. Copy only the factors that changed. A later file replaces a province; it does not have to repeat CPP or the other provinces.
3. Put the CRA table values in the file: brackets and constants, basic amounts, the Canada employment amount, CPP and EI ceilings and rates, the basic-exemption table, and, when they change, the EHT tiers or the WSIB/WCB maximum assessable earnings. Do not put an employer's WSIB or WCB rate in the file.
4. Add a case to `tests/source_deductions_test.php` for a pay date on the new edition and for a pay date the day before. Run `php tests/source_deductions_test.php`.
5. On the server, after a database backup, run `php payroll_engine/seed_rates.php`. It inserts a pack only when that `effective_from` is not already there. It does not overwrite a pack you have edited in the table, and it does not change payroll rows.
6. The first payroll whose pay date is on or after `effective_from` picks the new pack. A June period that is paid in July uses the July pack if the pay date entered on the manual pay screen is the July date.

To correct a number without a code change, insert or replace the JSON in `payroll_rate_packs` for that `effective_from`. A database row for a date replaces the built-in file for that same date.

## What the deployer has to enter

Run `migrations/20261120_source_deductions.sql` on the payroll database after a backup. It adds columns and three tables. It does not delete or rewrite wage rows. Then run `php payroll_engine/seed_rates.php`.

Before the first pay of the year:

- Confirm each site's province (`BC`, `ON`, or `SK`).
- Enter each site's WSIB or WCB rate in `sites.wsib_percent` as a percent (`1.23` for 1.23%). Leave it empty only if you accept a zero premium until the rate is known.
- Mark workers who are exempt from WSIB/WCB, CPP, or EI.
- Enter TD1 totals for anyone whose claim is not the basic amount. Leave the columns null to use the basic amount. Set `td1_tax_exempt` only for claim code E.
- For an Ontario employer, confirm `eht_eligible`, `eht_exemption_amount` (the full $1,000,000, or this organization's share when employers are associated), and `eht_group_over_cap` (1 when the associated group's Ontario payroll is over $5,000,000).
- If part of the year's CPP, EI, or pensionable earnings was withheld in another system and is not already in `payroll_finalized`, add one `payroll_deduction_openings` row for that person and year. Do not also put those amounts in `payroll_finalized`, or the year-to-date will be counted twice.

`manual_pay_entry.php` previews and saves an unpaid line. `generate_t4.php` reads stored boxes and ROE insurable earnings. `payroll_eht_report.php` is the Ontario EHT annual figure. None of these pages marks a payment or files a return.

The pay stub and the ledger read the stored CPP, EI, and tax. CPP2 is included in the net. Period CPP on the stub still comes from `pe_read_period_stats` when a period total has been saved, so the basic exemption is not applied once per line.

Sheet review is unchanged. It posts a line only when the sheet says employee deductions are none and the net equals the gross. It does not invent tax.

## Assumptions

- Option 1 (annualize this period), not Option 2 (cumulative averaging). Option 1 is the method for a regular restaurant pay. The tax credit for CPP and EI uses the greater of the annualized period and the year-to-date, and uses the yearly maximum once the maximum is reached. That follows the note in the T4127 glossary. In the period someone hits the CPP maximum, annualizing that period's small contribution would understate the credit, so the credit steps up to the maximum. The taxable-income deduction for additional CPP (factor F5) is still this period's amount times the number of pays, which is what the formula says, and it can make that one cheque's tax move.
- The pay date, not the period start, chooses the edition.
- Default claims use the BPAF formula, which phases out above the fourth federal bracket. PDOC claim code 1 uses the flat maximum basic amount even at a high income. Enter `16452` as the federal total if you want that claim-code result for a high earner in 2026.
- Employer EI is 1.4 times the employee premium, kept aligned to the year-to-date so the last pay lands on the published employer maximum.
- Historical lines with no `pensionable_earnings` or `insurable_earnings` count `total_gross` toward the year-to-date ceilings. Vacation stored only in `vac` is not added again for those old lines.
- Commission employees, TD1X, Quebec, and the territories are not in the tables. Add a province by putting a `provinces` entry in a rate file (brackets, basic amount, and any reduction, surtax, or health premium). No change to the calculator is required for a province that uses only brackets and a basic amount.

## Other branches

`engine/orgscope_guard.php` and an ROE XML builder are not on this branch. The new pages load the org-scope guard when that file is present, and they still use the privacy and organization checks that are on main. `pe_t4_roe()` returns insurable earnings and hours by line for an ROE builder to read. It does not write XML.

The quick-hours patch calls `pe_line` once per earnings type and then `pe_refresh_period_stat`. After that refresh, the stored period total is the T4127 result. A preview that calls `pe_line` twice before anything is saved still sees each call as its own period. Pass `period_gross_before` on the second call, or preview with one combined gross, if that screen is merged later.
