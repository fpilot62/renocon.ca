# Payroll privacy and security

This note covers the payroll app served at payroll.renocon.ca and payroll.ecommi.com (database `admin_payroll`). It is an operational checklist for PIPEDA and the British Columbia Personal Information Protection Act (PIPA). It is not a legal opinion. Confirm with counsel which statute applies before a breach or a new collection.

PIPEDA applies to federal works and to personal information that crosses provincial or national borders in the course of commercial activity. A private-sector employer that operates only in British Columbia is usually governed by PIPA, which is the provincial statute the federal government has recognized as substantially similar. The safeguards below are written so the same controls satisfy the overlap: limit collection and use, protect the data with reasonable safeguards, and be able to explain a breach.

## Data inventory

| Data | Where it lives | Who should see it |
| --- | --- | --- |
| Employee name, email, phone, address | `users` | Organization admins. A platform superadmin only during support access. |
| Social insurance number | `users`, when a SIN column exists (`sin`, `SIN`, `social_insurance`, `social_insurance_number`, or `sin_number`) | Organization admins, masked. Full value only where a filing truly needs it. Not in this repository's screens. |
| Bank transit, institution, and account | `users.bank_transit`, `users.bank_institution`, `users.bank_account` | Masked on the pay stub. The full number is placed on the cheque MICR line only, because the cheque will not clear without it. |
| Hours, gross, deductions, payments | `attendance`, `payroll_finalized`, `payroll_payments`, `payroll_masters`, `admin_outstanding_wages` | Organization admins. Supervisors (role level 21–29) still open the ledger for their own organization, which is the existing role split. They do not see bank numbers or SINs. |
| Sales used for food orders | `daily_report`, read by Order Amounts | Platform superadmin, and only during support access. |
| Sheet-review workbooks | Files stored outside the web root by sheet review | Organization admins, or a superadmin during support access. |
| Clock photos | `attendance` selfie columns, served by `audit_selfie.php` (not in this repository) | Organization admins on Staff Audit. The JSON feed is gated the same way. |
| Support grants and access log | `privacy_support_grants`, `privacy_access_log` | Organization admins see their own grants. Platform superadmins see grant metadata and organization counts, not the underlying payroll. |
| Database password and data key | Private env file outside the web root | Server operator only. Not in git. |

Role numbers already used by the app: 10 and below is platform superadmin, 11–20 is organization admin, 21–29 is a supervisor on the pages that already allowed that level, 30 and above is staff and is kept out of these payroll screens.

Platform superadmin does not see pay, bank info, SINs, employee records, reports, or attachments unless an organization admin grants support access. Without a grant the superadmin sees organization names and headcount only. The grant is 1 to 72 hours, is stored, is written to the access log, emails the organization owner when an address is on file, and shows a banner on every payroll page in this repository while it is active. A platform superadmin cannot grant access to themselves.

## Retention proposal

Do not delete payroll history as part of this change. The Canada Revenue Agency expects payroll records to be kept for six years from the end of the last tax year they relate to. British Columbia Employment Standards also expects wage records to be kept. Keep the longer of those periods, then delete or de-identify.

Proposed schedule, to adopt in writing before anything is deleted:

| Record | Proposed keep period |
| --- | --- |
| Wage, deduction, payment, T4, and ROE support | 6 years after the end of the tax year, or longer if a statute or an open dispute requires it |
| Attendance punches tied to a pay period | Same as the wage record |
| Clock photos | 12 months after the pay period is closed, unless a dispute is open |
| Bank account and SIN | For as long as the person is paid, then delete or de-identify with the employee file after the tax retention period |
| Access log and support grants | 24 months |
| Breach file | At least 24 months after the day of the determination, and longer if a regulator asks |

Nothing in this repository deletes those rows automatically.

## Breach response

Treat a breach as confirmed when personal information in this app is lost, accessed, or disclosed without authority. Examples: a database password left in a file that was exposed, a superadmin account reading payroll without a grant, a stolen laptop with an unencrypted export, or ransomware on the server.

1. Contain it. Revoke support grants, rotate the database password and `PAYROLL_DATA_KEY` only after a backup of the ciphertext (rotating the key without a backup makes bank numbers and SINs unreadable), and take the affected account offline.
2. Record the facts in a file kept for at least 24 months: what happened, when it was discovered, the kind of information, the number of people, the cause, and what was done. PIPEDA requires an organization to keep a record of every breach of security safeguards for 24 months, including breaches that are not reported.
3. Decide whether there is a real risk of significant harm. Bank account numbers and SINs normally meet that test. A lost list of first names with no identifiers often does not. Write down the reasons either way.
4. If there is a real risk of significant harm, notify as soon as feasible:
   - The Office of the Privacy Commissioner of Canada, using the PIPEDA breach report, when PIPEDA applies.
   - The Office of the Information and Privacy Commissioner for British Columbia, when PIPA applies.
   - If both may apply, report to both and say so. Do not wait for a perfect legal conclusion while the risk is clear.
   - Each affected person, in plain language: what happened, what information was involved, what you are doing, what they can do (contact the bank, watch accounts, contact the OPC or OIPC), and a person they can reach.
5. Do not include the SIN or the full bank account in the notice.
6. Keep the notices, the mailing list, and the regulator file with the breach record.

## What this repository now enforces

1. Staff Audit requires a logged-in admin (role level 20 or lower) and refuses a request with no organization. A platform superadmin must hold a live support grant for the one organization on the screen.
2. The same server-side check is on the JSON feed, delete, undo, manual attendance, order amounts, outstanding wages, the ledger, payment status, sheet review, and pay-document generation. Queries that used to run with no organization filter now add `1=0` when no organization is in scope. Deletes always stay inside one organization.
3. Platform superadmin business access is the support grant described above. The check is in PHP on each request, not only in the menu.
4. Bank transit, institution, account, and SIN columns are encrypted with AES-256-GCM when `ops/encrypt_sensitive_columns.php --apply` is run. The key is `PAYROLL_DATA_KEY` from the private env file. Screens and the pay stub show a mask. The cheque MICR line is the exception, and generating those documents is logged as an export. Until the script is run, existing plaintext still works and is still masked on the stub.
5. Database settings are read from outside the web root. `config/payroll.env.example` has placeholders only. `config/`, `ops/`, and `migrations/` carry an Apache deny rule so a leftover file is not served.
6. Views, exports, grants, revokes, and denials are written to `privacy_access_log` when that table exists. The log line stores the actor, organization, action, and page. It does not store a SIN, a bank account, or a wage amount.
7. Payroll pages set the session cookie to HttpOnly, Secure, and SameSite=Lax, regenerate the session id once, end the session after 30 idle minutes or 8 hours, require HTTPS, and require a CSRF token on the forms that previously had none. Pages that already called `ps_require_csrf()` still do.

Payroll calculations are unchanged. This does not mark anyone paid.

## Migration steps before deploy

Deploy is a separate decision. Take a database backup first.

1. Create the private directory outside the site, for this server layout: `/home/renocon/private`. `chmod 700` the directory and `chmod 600` the file.
2. Copy `config/payroll.env.example` to `/home/renocon/private/payroll.env`. Put the rotated database password in `DB_PASS`. Generate the data key with `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"` and put it in `PAYROLL_DATA_KEY`. Keep a copy of the key somewhere offline. Losing it makes encrypted bank numbers and SINs unreadable.
3. If PHP does not see `HTTPS=on` because a proxy terminates TLS, set `PAYROLL_TRUST_PROXY=1` in that file. Do not set `PAYROLL_ALLOW_HTTP` on the server.
4. Run `migrations/20261117_privacy_safeguards.sql` on `admin_payroll`. It adds two tables and does not change wage rows.
5. Confirm each organization has an admin (role level 11–20) with an email in `users.Email`, or an `owner_email` / `contact_email` on `organizations`. That person grants support access. The platform superadmin account cannot grant it to itself.
6. Confirm `mail()` on the server can deliver. If it cannot, the grant is still saved and `notify_note` says the email was not accepted. Send the owner a message yourself.
7. After the new PHP is on the server and a backup is stored, run `php ops/encrypt_sensitive_columns.php` and read the counts. Then run `php ops/encrypt_sensitive_columns.php --apply`. The dry run changes nothing. The apply widens a short bank or SIN column to `VARCHAR(512)` when the ciphertext would not fit, then encrypts plaintext values. It does not print the values.
8. Remove any `config/payroll.env` left inside the site directory after the private file works.
9. Sign in as an organization admin and confirm payroll pages still open. Sign in as the platform superadmin and confirm those pages show counts until a grant exists, then show the banner.

## Secrets found

This git history does not contain a database password or an API key. `config/payroll.env.example` contains only the placeholders `replace-with-rotated-password` and `replace-with-random-key`.

Rotate the database password anyway. `daily_sync.php` says an earlier server copy of that file stored the password in source, and `ops/deploy12.sh` still reads the live `db.php` on the server to take a backup. `db.php` is not in this repository. Treat that file as a committed-on-the-server secret: move its credentials into `/home/renocon/private/payroll.env`, point `db.php` at that file, and rotate the password.

## What remains manual

These files are referenced by the app and are not in this repository, so this change cannot gate them: `db.php`, `payroll_scope.php`, `login_kiosk.php`, `audit_selfie.php`, `finalize_shift.php`, `edit_shift.php`, `edit_user.php`, `bulk_allocation_desk.php`, `staff_audit_exceptions.php`, `generate_t4.php`, `generate_stat_shifts.php`, `manual_pay_entry.php`, `daily_report_view.php`, `rate_change.php`, and `payroll_outstanding_remittance.php`. Any of those that read payroll, bank, SIN, or photos need the same `privacy_require_business()` call. `audit_selfie.php` is the highest priority because it serves clock photos.

Also still manual:

- A public privacy notice and a record of the purpose for collecting the SIN and bank account.
- Employee access requests and correction requests (PIPEDA and PIPA both give people that right).
- A named privacy contact.
- Confirming whether a given organization is under PIPEDA, PIPA, or both.
- Delivering breach notices and filing with the OPC and OIPC.
- Vendor and hosting agreements, including who at the host can read the database.
- Backup encryption. A database dump contains the ciphertext and, if taken before the encrypt script, the old plaintext.
- Making sure `login_kiosk.php` does not keep a session alive past the idle limit on pages this repository does not own.
- Training anyone who uses the platform superadmin account to use an organization-admin account for day-to-day payroll, and the superadmin account only after a grant.
