# Install AvilonX on XAMPP (Windows)

AvilonX PHP runs on any computer with **XAMPP** (Apache + PHP + MySQL/MariaDB).
Members and readers on the same Wi-Fi can use it at the computer's address, e.g.
`http://192.168.1.10/avilonx`.

## 1. Install XAMPP

1. Download XAMPP for Windows with **PHP 8.1 or newer** from apachefriends.org and
   install it (default folder `C:\xampp`).
2. Open the **XAMPP Control Panel** and press **Start** for **Apache** and **MySQL**.

## 2. Copy AvilonX

1. Download this repository (GitHub → **Code → Download ZIP**) and unzip it.
2. Copy the folder to `C:\xampp\htdocs\avilonx` (so that
   `C:\xampp\htdocs\avilonx\index.html` exists).

## 3. Create the database

1. Open **http://localhost/phpmyadmin**.
2. **New** (left) → Database name `avilonx`, collation **utf8mb4_general_ci** → **Create**.
3. Click the new `avilonx` database → **Import** → choose
   `C:\xampp\htdocs\avilonx\database\avilonx.sql` → **Import**. 22 tables appear.

## 4. Settings

1. In `C:\xampp\htdocs\avilonx\api`, copy `config.sample.php` to **`config.php`**.
2. Open `config.php` in Notepad and set:
   - `app_secret`: any long random text (40+ letters and numbers). Keep it secret.
   - `site_url`: `http://localhost/avilonx` (or the address members use, see step 6).
   - XAMPP's MySQL is user `root` with no password, which is already in the file.
     If you gave MySQL a password, put it in `db_pass`.
3. **Videos (optional):** in XAMPP Control Panel → Apache **Config** → `php.ini`, set
   `upload_max_filesize = 64M` and `post_max_size = 64M`, save, restart Apache.

## 5. First start

Open **http://localhost/avilonx**, then **Sign in to the clocking app**. The first
page asks you to **create the Super Admin** (username and password). Then:

1. Super Admin → **Clubs**: create a club and its administrator login.
2. Super Admin → **Register NFC** and **Register sticker** / **Generate sticker**.
3. Sign in as the club administrator: members, race points, races, bird bonding,
   race entries, as in the Supabase version.

## 6. Phones on the club Wi-Fi

1. On the XAMPP computer, find its address: Windows **cmd** → `ipconfig` →
   *IPv4 Address*, e.g. `192.168.1.10`. Give the computer a fixed address in the
   router if you can.
2. Allow Apache through the Windows Firewall when Windows asks (Private networks).
3. Phones open **http://192.168.1.10/avilonx**.

**Limits without HTTPS** (browsers allow these only on `https://` or `localhost`):
the phone **camera** (scanning sticker barcodes), **phone NFC** (Android Chrome),
**installing the app** and notifications. Typing the sticker code, loft readers and
everything else work. For the camera and NFC, put AvilonX on hosting with HTTPS
(docs/HOSTING.md).

## 7. Internet

AvilonX works without internet for clocking, but a few tools load libraries from
the internet the first time they are used: reading Excel files, the sticker
barcode printer, scanning loading forms (OCR) and the barcode camera scanner.

## 8. Updating AvilonX

Replace the files with the new version (keep `api/config.php` and `uploads`),
then in phpMyAdmin import `database/avilonx.sql` again into the same database:
it only adds what is new and keeps your data.

## 9. Backups

phpMyAdmin → `avilonx` → **Export** → **Go** saves the whole database as a `.sql`
file. Also copy the `uploads` folder (bird photos, videos, club logos). Do it
after every race day.

## When something is wrong

| What you see | What to check |
|---|---|
| "Finish database setup" on the sign-in page | MySQL started in XAMPP; `database/avilonx.sql` imported into a database named as in `config.php` |
| "Cannot connect to the database" | `db_name`, `db_user`, `db_pass` in `api/config.php` |
| Photos don't save | The `uploads` folder must be writable; `upload_max_filesize` in php.ini |
| Phones can't open the address | Same Wi-Fi; Windows Firewall allows Apache; the computer's IPv4 address |
| Any other error | `C:\xampp\apache\logs\error.log` (PHP errors are written there) |
