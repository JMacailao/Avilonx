# Keeping AvilonX PHP up to date with Mayma-Clocking

The website (pages/, assets/, index.html) comes from **JMacailao/Mayma-Clocking**
(the Supabase version). Each update there is brought here too.

**Last synced Mayma-Clocking commit:** `e30062d`

To sync: take every file changed on Mayma-Clocking `main` since that commit,
copy it here, then keep these PHP differences:

| File | Difference in the PHP version |
|---|---|
| `assets/js/supabase.js` | Connects to the PHP API (never copy the Supabase one over it) |
| `assets/js/config.js` | Points `SUPABASE_URL` at `api/index.php?r=`, so code calling `${SUPABASE_URL}/rest/v1/rpc/<name>` reaches the PHP API (never copy the Supabase one) |
| `assets/js/shared.js` | "Could not connect to the AvilonX server" |
| `pages/login/page.js` | Database setup message mentions phpMyAdmin / api/config.php |
| `pages/superadmin/setup/page.js` | "About this database" instead of the SQL Editor steps |
| `index.html` | "Secure sign-in" instead of "Supabase secured" (if shown) |
| `tools/rfid-reader/*/*.ino` | Send to the AvilonX server address (setting + page box, http or https, `api/index.php?r=rpc/...`) instead of Supabase; redirect / wrong-address taps kept. Port reader changes by hand |
| `docs/RFID-READER.md` | Extra section "PHP version: the AvilonX server address" |

Not copied: `.github/workflows` (Supabase backups and uptime), `supabase/`, the Supabase
tests (`tests/browser`, `tests/sql`, `tests/unit`), `*.sql`, `tools/build-update-sql.sh`,
`docs/DATABASE.md`; `docs/PUSH.md` and `docs/BACKUP.md` have PHP versions here.

Database changes on Mayma-Clocking (`*.sql`) are ported by hand: new columns
or tables go in `database/avilonx.sql` (plus an ALTER for existing databases),
new or changed database functions in `api/lib/rpc*.php`, trigger changes in
`api/lib/hooks.php`. Then run `bash tests/run.sh`.
