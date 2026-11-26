# How AvilonX PHP is built

```
Browser (pages/, assets/)                  PHP (api/)                        MySQL
 supabase.from('races').select()...  ──►  index.php?r=query      ──► query.php: access rules,
 supabase.rpc('submit_member_clock') ──►  index.php?r=rpc/<name> ──► rpc*.php   hooks.php: save rules
 supabase.auth.signInWithPassword    ──►  index.php?r=auth/login ──► core.php: PHP session
 supabase.storage.from(bucket)       ──►  index.php?r=storage/*  ──► storage.php → uploads/
 supabase.functions.invoke(...)      ──►  index.php?r=functions/avilonx-password-reset
Loft readers (ESP8266/ESP32)         ──►  index.php?r=rpc/device_rfid_scan (serial + key)
```

- **The website is unchanged** except `assets/js/supabase.js`, which offers the
  same calls as the Supabase client and sends them to the PHP API.
- **Access rules** (`api/lib/query.php`): the Supabase row-level security
  policies, per table, by role (`superadmin`, `admin`, `staff`, `member`) and
  club. Reads are filtered; a change is refused if the row before or after does
  not satisfy the rule. Secret columns (sticker codes, reader keys) are never sent.
- **Save rules** (`api/lib/hooks.php`): the database triggers of the Supabase
  version, run in the same order: bonded bird → entry, sticker rule, RFID tag
  registered / once per race / still active, tag expiry, loft kept with the entry,
  sticker registered and not clashing; race point or release moves recalculate
  distances; emails one per account; Change history for races, entries,
  clockings and birds.
- **Server functions** (`api/lib/rpc*.php`): one PHP function per Supabase
  database function, same names, arguments, answers and error codes
  (`22023` bad value, `23505` in use, `23514` rule, `42501` not allowed).
- **Times** are stored in UTC (`DATETIME(6)`); the API sends them in the same
  format as Supabase.
- **Sign-in**: usernames and bcrypt passwords (`auth_users`), PHP sessions
  (cookie `avilonx_session`, HttpOnly, SameSite=Lax). A password reset ends every
  session of the account (`session_version`).
- **Files**: `uploads/<bucket>/<club>/...`, same folder rules as the Supabase
  buckets; pages get signed links that expire.

## Tests

`bash tests/run.sh` (with `DB_USER`, `DB_PASS`) creates a fresh test database,
starts PHP's built-in server, runs `tests/api.cjs` (accounts, access rules
between roles and clubs, entries, clocking, readers, results, race point moves,
emails) and `tests/smoke.cjs` (signs in as each role in Chromium and opens every
page).
