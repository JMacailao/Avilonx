# Keeping AvilonX PHP up to date with Mayma-Clocking

The website (pages/, assets/, index.html) comes from **JMacailao/Mayma-Clocking**
(the Supabase version). Each update there is brought here too.

**Last synced Mayma-Clocking commit:** `0814d62`

To sync: take every file changed on Mayma-Clocking `main` since that commit,
copy it here, then keep these PHP differences:

| File | Difference in the PHP version |
|---|---|
| `assets/js/supabase.js` | Connects to the PHP API (never copy the Supabase one over it) |
| `assets/js/app.js`, `assets/js/i18n.js` | "Server connected" instead of "Supabase connected" |
| `assets/js/shared.js` | "Could not connect to the AvilonX server" |
| `pages/login/page.js` | Database setup message mentions phpMyAdmin / api/config.php |
| `pages/superadmin/setup/page.js` | "About this database" instead of the SQL Editor steps |
| `index.html` | "Secure sign-in" instead of "Supabase secured" (if shown) |
| `tools/rfid-reader/*/*.ino` | Send to the AvilonX server address (setting + page box, http or https, `api/index.php?r=rpc/...`) instead of Supabase; redirect / wrong-address taps kept. Port reader changes by hand |
| `docs/RFID-READER.md` | Extra section "PHP version: the AvilonX server address" |

Database changes on Mayma-Clocking (`*.sql`) are ported by hand: new columns
or tables go in `database/avilonx.sql` (plus an ALTER for existing databases),
new or changed database functions in `api/lib/rpc*.php`, trigger changes in
`api/lib/hooks.php`. Then run `bash tests/run.sh`.
