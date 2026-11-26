# AvilonX PHP on online hosting

Any PHP hosting with **PHP 8.1+**, **MySQL 8 or MariaDB 10.4+** and HTTPS works
(e.g. Hostinger, Namecheap, cPanel hosts).

1. **Database:** in the hosting panel create a MySQL database and a user with all
   rights on it. Open phpMyAdmin → that database → **Import** →
   `database/avilonx.sql`.
2. **Files:** upload everything (except `tests/`) to `public_html` (or a
   subfolder) with the File Manager or FTP.
3. **Settings:** copy `api/config.sample.php` to `api/config.php` and fill in the
   database name, user, password and host from the panel, a long random
   `app_secret`, and `site_url` (e.g. `https://www.avilonx.site`).
   Better: set `storage_dir` to a folder **outside** `public_html`, e.g.
   `/home/USER/avilonx-uploads`.
4. **HTTPS:** turn on the free SSL certificate in the panel and force HTTPS.
   HTTPS is needed for the phone camera, phone NFC and installing the app.
5. **PHP settings** (panel → PHP options): `upload_max_filesize` and
   `post_max_size` 64M for clocking videos; extensions `pdo_mysql`, `curl`,
   `fileinfo` on.
6. Open the site: the first page asks for the Super Admin account.

## Forgot password emails

In `api/config.php` set `resend_api_key` (from resend.com, Sending access) and
`reset_from` (e.g. `AvilonX <noreply@avilonx.site>`, a domain verified in
Resend). Members, club administrators and the Super Admin then get reset links
by email, as in the Supabase version (one email per account, confirmed first).

## Security notes

- `api/lib`, `api/config.php`, `database/` and `uploads/` have `.htaccess` files
  that block direct access on Apache. On **nginx** hosting add the same rules
  (deny `/api/lib/`, `/api/config.php`, `/database/`, `/uploads/`).
- Keep PHP and the hosting panel up to date; use a strong Super Admin password.
