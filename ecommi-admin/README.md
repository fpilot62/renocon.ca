# Ecommi administration hub

Internal admin for apps, records/tracks, marketing prices, and a superadmin password vault.

This is PHP + SQLite. Deploy the contents of this folder to the **ecommi.com** document root on the DigitalOcean droplet (`159.89.125.184`). Do not deploy it to the public Renocon marketing site.

## Local preview

```bash
php -S 127.0.0.1:4174 -t ecommi-admin
```

Open `http://127.0.0.1:4174`. The first visit creates the only superadmin account.

## DigitalOcean

1. Copy these files into the Apache vhost for `ecommi.com` (that host currently returns 502).
2. Ensure PHP has `pdo_sqlite` and `openssl`.
3. Make `data/` writable by the web user (`chmod 700 data`).
4. Create the first superadmin in the browser.
5. For `Password0`, in HostGator phpMyAdmin export the table as **CSV**, then import it on **Password vault** (superadmin only).

`data/admin.sqlite` and `data/vault.key` stay on the server. They are gitignored.

## Roles

- **superadmin** — all pages including the vault
- Later admin users can be added in the database with role `admin` if you want prices/records without vault access
