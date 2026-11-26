# Push notifications (PHP version)

Members, club administrators and staff can get notifications on their phone or
computer, in the browser and in the AvilonX Android app.

| Who | When |
| --- | --- |
| Member | One of their pigeons is clocked; the club opens a race |
| Club administrator, staff | Any pigeon in the club is clocked |
| Super Admin | A club sends a request from the landing page |

Each person turns them on per device: **Settings → Notifications** (members),
**Club settings → Notifications** (administrators), the **Overview** page (staff).
On iPhone this works only after "Add to Home Screen" (iOS 16.4 or newer).

## Setup

Nothing to set up: the PHP server makes its Web Push keys the first time someone
turns notifications on, and sends notifications itself (PHP's OpenSSL and curl,
no extra libraries). Optional in `api/config.php`: `push_contact`, e.g.
`mailto:you@gmail.com`.

**HTTPS is needed.** Browsers allow notifications only on `https://` sites (or
`localhost`). On XAMPP over the club Wi-Fi (`http://192.168.x.x`) the
Notifications switch says the browser cannot show them; put AvilonX on hosting
with HTTPS for notifications.

## How it works

Saving a clocking or opening a race queues the notifications in `push_outbox`.
After the page has its answer, PHP encrypts each one for every device of that
person (Web Push, aes128gcm, VAPID) and sends it. Devices the push service says
are gone are removed. A notification problem never stops a clocking from saving.
