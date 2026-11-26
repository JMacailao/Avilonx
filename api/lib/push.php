<?php
// Push notifications (Web Push: RFC 8291 aes128gcm encryption, RFC 8292 VAPID),
// with PHP's OpenSSL only (no extra libraries). The same notifications as the
// Supabase version:
//   members: "PH-1 was clocked" for their own pigeons, "New race open";
//   club administrators and staff: every arrival in their club.
// Notifications are queued in push_outbox while saving, and sent after the
// answer has gone back to the page, so a clocking never waits for them.
declare(strict_types=1);

const PUSH_CONTACT_DEFAULT = 'mailto:admin@avilonx.site';

function b64url(string $bytes): string { return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); }
function unb64url(string $text): string { return (string)base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4)); }

// OpenSSL needs its config file to make keys; XAMPP on Windows does not always
// tell PHP where it is.
function openssl_args(array $args): array
{
    foreach ([getenv('OPENSSL_CONF') ?: '', 'C:\\xampp\\apache\\conf\\openssl.cnf', 'C:\\xampp\\php\\extras\\ssl\\openssl.cnf', 'C:\\xampp\\apache\\bin\\openssl.cnf'] as $file) {
        if ($file !== '' && is_file($file)) return $args + ['config' => $file];
    }
    return $args;
}
function new_p256_key()
{
    $key = openssl_pkey_new(openssl_args(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]));
    if (!$key) fail('This PHP cannot make notification keys (OpenSSL). Check that the openssl extension is on.', 'NOT_SET_UP', 503);
    return $key;
}
function p256_public_raw($key): string
{
    $d = openssl_pkey_get_details($key);
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}
// A browser's raw P-256 public key (65 bytes) as an OpenSSL key.
function p256_public_from_raw(string $raw)
{
    if (strlen($raw) !== 65 || $raw[0] !== "\x04") throw new RuntimeException('Bad browser key');
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $key = openssl_pkey_get_public($pem);
    if (!$key) throw new RuntimeException('Bad browser key');
    return $key;
}
// ECDSA signature from OpenSSL (DER) → the 64 bytes JWT ES256 needs.
function der_to_raw_signature(string $der): string
{
    $pos = 2; if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $pos++; $len = ord($der[$pos]); $pos++;
        $int = substr($der, $pos, $len); $pos += $len;
        $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
    }
    return $out;
}

// The site's Web Push keys, made the first time they are needed.
function push_keys(bool $create): ?array
{
    $row = row('SELECT vapid_public, vapid_private FROM push_settings WHERE id = 1');
    if ($row) return ['public' => $row['vapid_public'], 'private' => $row['vapid_private']];
    if (!$create) return null;
    $key = new_p256_key();
    openssl_pkey_export($key, $pem, null, openssl_args([]));
    q('INSERT IGNORE INTO push_settings (id, vapid_public, vapid_private) VALUES (1, ?, ?)', [b64url(p256_public_raw($key)), $pem]);
    $row = row('SELECT vapid_public, vapid_private FROM push_settings WHERE id = 1');   // another request may have saved first
    return ['public' => $row['vapid_public'], 'private' => $row['vapid_private']];
}

function vapid_header(string $endpoint, array $keys): string
{
    $parts = parse_url($endpoint);
    $aud = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $head = b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $body = b64url(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => config('push_contact') ?: PUSH_CONTACT_DEFAULT], JSON_UNESCAPED_SLASHES));
    if (!openssl_sign("$head.$body", $der, $keys['private'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('Could not sign');
    return "vapid t=$head.$body." . b64url(der_to_raw_signature($der)) . ", k={$keys['public']}";
}

// Encrypts the message for one browser (aes128gcm).
function push_encrypt(string $payload, string $p256dh, string $authSecret): string
{
    $uaPublic = unb64url($p256dh); $auth = unb64url($authSecret);
    $local = new_p256_key();
    $asPublic = p256_public_raw($local);
    $shared = openssl_pkey_derive(p256_public_from_raw($uaPublic), $local, 32);
    if ($shared === false) throw new RuntimeException('Key agreement failed');
    $ikm = substr(hash_hmac('sha256', "WebPush: info\0" . $uaPublic . $asPublic . "\x01", hash_hmac('sha256', $shared, $auth, true), true), 0, 32);
    $salt = random_bytes(16);
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
    $sealed = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($sealed === false) throw new RuntimeException('Encryption failed');
    return $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $sealed . $tag;
}

// ---- Queueing --------------------------------------------------------------------------
$GLOBALS['push_pending'] = [];
function push_queue(string $userId, string $title, string $body, string $url = '', string $tag = ''): void
{
    if (!val('SELECT 1 FROM push_subscriptions WHERE user_id = ? LIMIT 1', [$userId])) return;
    q('INSERT INTO push_outbox (user_id, title, body, url, tag) VALUES (?, ?, ?, ?, ?)',
        [$userId, mb_substr($title, 0, 120), mb_substr($body, 0, 400), mb_substr($url, 0, 300), mb_substr($tag, 0, 60)]);
    $GLOBALS['push_pending'][] = (int)db()->lastInsertId();
}
// A pigeon is clocked: its owner, and the club's administrators and staff.
function push_on_clock(array $clock): void
{
    try {
        $e = row('SELECT * FROM race_entries WHERE id = ?', [$clock['race_entry_id']]);
        if (!$e) return;
        $r = row('SELECT * FROM races WHERE id = ?', [$e['race_id']]);
        $m = row('SELECT * FROM members WHERE id = ?', [$e['member_id']]);
        $ring = $e['pigeon_ring'] ?? $clock['pigeon_ring'] ?? 'A pigeon';
        $detail = ph_time($clock['received_at_utc'], 'h:i:s A');
        if ($r['release_at_utc'] !== null) {
            $s = seconds_between($r['release_at_utc'], $clock['received_at_utc']);
            if ($s > 0) $detail .= ' · ' . number_format((float)$e['distance_m'] * 60 / $s, 3) . ' m/min';
        }
        foreach (rows("SELECT id FROM app_profiles WHERE member_id = ? AND role = 'member' AND is_active = 1", [$m['id']]) as $p) {
            push_queue($p['id'], "🕊 $ring was clocked", "{$r['name']} · $detail" . ((int)$r['requires_show_up'] ? "\nBring it to the club for Show up before the race is closed." : ''),
                'pages/member/history/index.html', "clock-{$clock['id']}");
        }
        foreach (rows("SELECT id FROM app_profiles WHERE club_id = ? AND role IN ('admin', 'staff') AND is_active = 1", [$r['club_id']]) as $p) {
            push_queue($p['id'], "🕊 $ring · " . ($m['full_name'] ?? 'Member'), "{$r['name']} · $detail", 'pages/admin/raceday/index.html', "clock-{$clock['id']}");
        }
    } catch (Throwable $e) {
        error_log('AvilonX push not queued: ' . $e->getMessage());   // never stops a clocking
    }
}
// A race opens: every member of the club.
function push_on_race_open(array $race): void
{
    try {
        $when = $race['release_at_utc'] !== null ? ' · ' . ph_time($race['release_at_utc'], 'M d, h:i A') : '';
        foreach (rows("SELECT id FROM app_profiles WHERE club_id = ? AND role = 'member' AND is_active = 1", [$race['club_id']]) as $p) {
            push_queue($p['id'], "New race open: {$race['name']}", "Release point: {$race['release_location']}$when", 'pages/member/dashboard/index.html', "race-{$race['id']}");
        }
    } catch (Throwable $e) {
        error_log('AvilonX push not queued: ' . $e->getMessage());
    }
}

// ---- Sending (after the answer went back to the page) ---------------------------------------
function push_has_pending(): bool { return !empty($GLOBALS['push_pending']); }
function push_flush_pending(): void
{
    $ids = $GLOBALS['push_pending']; $GLOBALS['push_pending'] = [];
    foreach ($ids as $id) {
        try { push_send((int)$id); } catch (Throwable $e) { error_log('AvilonX push: ' . $e->getMessage()); }
    }
}
function push_send(int $id): array
{
    $o = row('SELECT * FROM push_outbox WHERE id = ? AND sent_at IS NULL AND created_at > UTC_TIMESTAMP(6) - INTERVAL 1 DAY', [$id]);
    if (!$o) return ['skipped' => true];
    $keys = push_keys(true);
    $site = rtrim((string)config('site_url'), '/');
    $payload = json_encode(['title' => $o['title'], 'body' => $o['body'], 'url' => $o['url'] !== '' ? "$site/{$o['url']}" : $site, 'tag' => $o['tag']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $gone = []; $failed = [];
    foreach (rows('SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?', [$o['user_id']]) as $sub) {
        try {
            $ch = curl_init($sub['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Authorization: ' . vapid_header($sub['endpoint'], $keys), 'Content-Encoding: aes128gcm',
                    'Content-Type: application/octet-stream', 'TTL: 86400', 'Urgency: high'],
                CURLOPT_POSTFIELDS => push_encrypt($payload, $sub['p256dh'], $sub['auth']),
            ]);
            curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($status === 404 || $status === 410) $gone[] = $sub['endpoint'];
            elseif ($status >= 400 || $status === 0) $failed[] = $status ?: $err;
        } catch (Throwable $e) {
            $failed[] = $e->getMessage();
        }
    }
    foreach ($gone as $endpoint) q('DELETE FROM push_subscriptions WHERE endpoint_hash = SHA2(?, 256)', [$endpoint]);
    q('UPDATE push_outbox SET sent_at = ?, error = ? WHERE id = ?', [now_utc(), $failed ? mb_substr('Push service: ' . implode(', ', $failed), 0, 300) : null, $id]);
    q('DELETE FROM push_outbox WHERE created_at < UTC_TIMESTAMP(6) - INTERVAL 30 DAY');
    return ['sent' => count(rows('SELECT 1 FROM push_subscriptions WHERE user_id = ?', [$o['user_id']])) - count($failed), 'gone' => count($gone), 'failed' => count($failed)];
}

// ---- Functions for the pages ---------------------------------------------------------------
function push_functions(): array
{
    return [
        'push_public_key' => fn($a) => push_keys(false)['public'] ?? null,
        'push_subscribe' => function ($a) {
            $p = require_signed_in();
            $endpoint = trim((string)($a['p_endpoint'] ?? '')); $p256dh = trim((string)($a['p_p256dh'] ?? '')); $auth = trim((string)($a['p_auth'] ?? ''));
            if (!str_starts_with($endpoint, 'https://') || strlen($endpoint) > 1000 || strlen($p256dh) < 40 || strlen($p256dh) > 200 || strlen($auth) < 10 || strlen($auth) > 100) {
                fail('This notification subscription is not valid.');
            }
            q('INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, device) VALUES (?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), device = VALUES(device), created_at = UTC_TIMESTAMP(6)',
                [$p['id'], $endpoint, $p256dh, $auth, mb_substr((string)($a['p_device'] ?? ''), 0, 120)]);
            return null;
        },
        'push_unsubscribe' => function ($a) {
            $p = require_signed_in();
            q('DELETE FROM push_subscriptions WHERE endpoint_hash = SHA2(?, 256) AND user_id = ?', [trim((string)($a['p_endpoint'] ?? '')), $p['id']]);
            return null;
        },
        'push_status' => function ($a) {
            $p = profile();
            $uid = $p['id'] ?? '';
            return [
                'this_device' => isset($a['p_endpoint']) && (bool)val('SELECT 1 FROM push_subscriptions WHERE endpoint_hash = SHA2(?, 256) AND user_id = ?', [(string)$a['p_endpoint'], $uid]),
                'devices' => (int)val('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$uid]),
            ];
        },
        'push_test' => function ($a) {
            $p = require_signed_in();
            push_keys(true);
            push_queue($p['id'], 'AvilonX notifications are on', 'You will get clocking and race notifications on this device.', 'index.html', 'test');
            return ['ok' => true];
        },
    ];
}
// The "avilonx-push" function: { action: "keys" } answers the public key (made if needed).
function function_push(array $body): array
{
    if (($body['action'] ?? '') === 'keys') return ['ok' => true, 'public' => push_keys(true)['public']];
    fail('Unknown action');
}
