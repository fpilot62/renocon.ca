# Statutory holiday pay

When a shift or manual hours entry is finalized, the pay period is checked for a statutory holiday at that site. A stat-pay line is posted for each eligible employee. Running the finalize again does not add a second line. The line is keyed on the employee, the site, and the holiday date.

The amount is passed through `pe_line`, the same way as any other earnings, so CPP, CPP2, EI, and tax follow the T4127 engine. The separate Stat Pay Preview screen still works. It is no longer required.

The holiday dates for 2026 and 2027 are in `payroll_engine/stat_holidays.php`. A weekend date is moved only when the statute itself moves it. Christmas Day in 2027 stays on December 25 in British Columbia and Saskatchewan.

## British Columbia

Source: Employment Standards Act, sections 44 and 45, and the province's statutory holiday list. <https://www2.gov.bc.ca/gov/content/employment-business/employment-standards-advice/employment-standards/statutory-holidays> and <https://www2.gov.bc.ca/gov/content/employment-business/employment-standards-advice/employment-standards/statutory-holidays/calculate-statutory-holiday-pay>

An employee is eligible after 30 calendar days of employment, and only if they worked or earned wages on at least 15 of the 30 calendar days before the holiday. Pay is the wages earned in those 30 days, including vacation pay and excluding overtime, divided by the number of days worked.

## Ontario

Source: Employment Standards Act, 2000, section 24, and the public-holiday guide. <https://www.ontario.ca/document/your-guide-employment-standards-act-0/public-holidays>

Pay is regular wages plus vacation pay payable in the four work weeks before the work week of the holiday, divided by 20. The work week used here is Sunday through Saturday, which is the default when the employer has not chosen another week. An employee who missed their last shift before the holiday, recorded on the attendance row as missed, absent, or no-show, is not eligible.

## Saskatchewan

Source: The Saskatchewan Employment Act, section 2-32, and the province's public-holiday page. <https://www.saskatchewan.ca/business/employment-standards/public-statutory-holidays/paying-employees-for-public-holidays>

Pay is 5 percent of wages in the 28 days before the holiday, including vacation pay for vacation taken in those four weeks, and excluding overtime. An employee who was not employed during those four weeks is not paid for that holiday.
