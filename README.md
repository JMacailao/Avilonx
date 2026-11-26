# AvilonX Clocking — PHP + MySQL version

Pigeon race clocking for clubs: members, bird bonding with RFID tags and
stickers, race schedules and entries, clocking (sticker codes, phone NFC, loft
RFID readers, club clocking), show-up verification, results and public results.

This is the **PHP + MySQL/MariaDB** version of AvilonX (the original runs on
Supabase). It runs on **XAMPP** on a club computer or on any **PHP hosting**.

- Install on XAMPP: [docs/INSTALL-XAMPP.md](docs/INSTALL-XAMPP.md)
- Put it online: [docs/HOSTING.md](docs/HOSTING.md)
- How it is built: [docs/ARCHITECTURE-PHP.md](docs/ARCHITECTURE-PHP.md)

## What's in it

| Folder | What |
|---|---|
| `index.html`, `pages/`, `assets/` | The website (the same pages as the Supabase version) |
| `assets/js/supabase.js` | The website's connection to the PHP API (same calls as before) |
| `api/` | The PHP API: sign-in, access rules, all server functions, file uploads |
| `database/avilonx.sql` | The MySQL database (import once in phpMyAdmin) |
| `uploads/` | Bird photos, clocking videos, club logos (not in Git) |
| `tests/` | API checks and a browser test of every page (`bash tests/run.sh`) |

## Status

Working: first start, Super Admin (clubs, RFID tags, stickers, loft readers),
club administrator and staff (members, team accounts, race points, races, bird
bonding with photos, race entries, remarks, clocking, show-up, results, race day,
change history, club settings and logo), members (races, clocking by sticker
code and NFC, receipts and videos, pigeons, results, settings), public results,
forgot password by email, one device per account.

Not yet:
- **Telegram** results for loft reader owners.
- **Loft reader sketches** pointing to the PHP server (the server side is ready:
  `api/index.php?r=rpc/device_rfid_scan` and `device_rfid_heartbeat`).
- **Moving data** from the Supabase version.
- Live updates are replaced by the pages refreshing every few seconds.
