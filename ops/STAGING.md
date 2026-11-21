# Staging payroll

`staging.payroll.ecommi.com` is a copy of the live payroll app. Try a change there before it goes on `payroll.ecommi.com`. Live code stays at `/home/renocon/public_html/payroll` and the live database stays `admin_payroll`. The server clock is UTC.

The staging docroot is `/home/renocon/staging_payroll`, outside `public_html`. The staging database is `admin_payroll_staging`. Its user is `payroll_staging` and can use only that database.

## Commands

Run these as root on the payroll server. Unpack this commit somewhere that is not the live docroot, for example `/root/payroll-src`, and run the script from there. The script reads live and writes only the staging copy.

```bash
bash /root/payroll-src/ops/setup_staging_v1.sh check
bash /root/payroll-src/ops/setup_staging_v1.sh go
bash /root/payroll-src/ops/setup_staging_v1.sh refresh
bash /root/payroll-src/ops/setup_staging_v1.sh remove
```

`rollback` is the same as `remove`.

Do not use `bash -x`. That trace would print the database password.

`check` only reads. It reports the live and staging paths, whether the databases exist, free disk space, and whether `staging.payroll.ecommi.com` resolves to this server.

`go` creates the copy. It refuses to start if the staging docroot is already there. It stops if less than 1 GiB is free.

`refresh` copies live code again and reloads a scrubbed database. The staging password stays the same.

`remove` deletes the staging subdomain, the staging docroot, `admin_payroll_staging`, and the `payroll_staging` user. It does not drop `admin_payroll` and it does not delete `/home/renocon/public_html/payroll`.

A failed `go` or `refresh` deletes the partial staging copy so a half-loaded database is not left behind. Live is not part of that cleanup.

## What go does

1. Copies `/home/renocon/public_html/payroll` to `/home/renocon/staging_payroll`.
2. Copies the staging guard files from the tree that contains this script onto that copy, so the copy blocks outbound mail even if live does not have those files yet. Live files are not written.
3. Points the copy's `db.php` at `admin_payroll_staging`. The live password is removed from the copy. The connection reads `/home/renocon/private/payroll-staging.env`.
4. Streams `mysqldump --single-transaction` of `admin_payroll` into `admin_payroll_staging`. Live is not locked for the dump, and the dump is not saved to disk.
5. Scrubs the staging database. On `users`, each email becomes `staff+<id>@staging.invalid`. Phone numbers, SIN, bank account, and bank transit are blanked. Site direct-deposit account and transit numbers are blanked too. `transit_rate` is a pay rate and is left as it is. Push tokens are cleared. Uploaded direct-deposit images under the staging docroot are deleted.
6. Installs a red STAGING banner on PHP pages, `noindex`, and HTTP basic auth.
7. Adds the subdomain with `uapi --user=renocon SubDomain addsubdomain` (`disallowdot=0`, document root `/staging_payroll`) and requests AutoSSL.

The basic-auth user is `staging`. The password is only in `/root/payroll-staging/basic-auth.txt` (mode 600). The database password is only in `/root/payroll-staging/db-password` and in the staging config `/home/renocon/private/payroll-staging.env` (mode 600, owner `renocon` when that account exists). Neither password is printed.

The staging copy sets `STAGING=1`. While that is set, the app does not send mail, SMS, push notifications, or a bank file, and it does not download a stored direct-deposit image. On live, without `STAGING=1` and without a `.staging` marker, those paths behave as they do today. A `STAGING=1` line in the live env file is ignored while that file still names `admin_payroll`.

T4 and ROE screens can still build a download. The SIN columns in the staging database are blank, so those files are not a filing copy.

## DNS Abdul adds

`ecommi.com` is served by DigitalOcean (`ns1.digitalocean.com`, `ns2.digitalocean.com`, `ns3.digitalocean.com`), not by this cPanel server. `payroll.ecommi.com` already points at `159.89.125.184`. `staging.payroll.ecommi.com` has no record yet.

Add this A record in DigitalOcean before AutoSSL can issue a certificate:

```text
staging.payroll.ecommi.com.  A  159.89.125.184
```

`check` looks again from the server. If the zone is later hosted on the server, the script says so instead of naming DigitalOcean.

AutoSSL's HTTP challenge is allowed through basic auth at `/.well-known/acme-challenge/`. The certificate is requested during `go` and will succeed after the A record resolves to this server. Run `check` again to confirm `dns_matches=yes`.

If cPanel refuses a document root outside `public_html`, turn off "Restrict document roots to public_html" in WHM Tweak Settings. `go` removes a subdomain whose document root is not `/home/renocon/staging_payroll`.

## Deploy to staging first

Deploy scripts that read `PAYROLL_APP` and `PAYROLL_DB` take `TARGET=staging` or `TARGET=live`. The default inside those scripts is live. `ops/deploy_to_staging.sh` defaults to staging so a wrapper run does not touch live.

```bash
bash /root/payroll-src/ops/deploy_to_staging.sh check
bash /root/payroll-src/ops/deploy_to_staging.sh /root/payroll-src/ops/deploy_stat_approval_v1.sh check
TARGET=staging bash /root/payroll-src/ops/deploy_stat_approval_v1.sh dry
TARGET=live bash /root/payroll-src/ops/deploy_stat_approval_v1.sh check
```

A script that sets `APP=/home/renocon/public_html/payroll` itself, and does not read `PAYROLL_APP`, is refused when the target is staging. Older scripts in that shape (`deploy12.sh`, `deploy13.sh`, `deploy14.sh`, `deploy_t4127.sh`) stay live-only.

When `TARGET=staging`, smoke checks use `https://staging.payroll.ecommi.com/`. Basic auth returns 401, which the smoke check already accepts. The `payroll.renocon.ca` redirect check runs only for `TARGET=live`.

## What was tested here

The server was not used. Local checks were:

- `php tests/staging_guard_test.php` for the banner, mail and SMS block, scrub, and database retarget.
- `bash tests/staging_setup_test.sh` for `check`, `go`, `refresh`, and `remove` against a fake live tree, with stand-ins for `mysql`, `mysqldump`, `uapi`, and `dig`.
- `php tests/privacy_lib_test.php` and `php alarm/api/cron_notify.php --selftest`.

`check` in that fixture reports the DigitalOcean A record above and does not create a docroot. `go` does not print either password, does not rewrite the live `db.php`, and the database stand-in rejects a write aimed at `admin_payroll`.
